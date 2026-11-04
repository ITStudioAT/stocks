<?php

namespace Tests\Feature;

use App\Models\IndexWatchItem;
use App\Models\StockHolding;
use App\Models\User;
use App\Services\FreeInstrumentSearch;
use App\Services\XetraInstrumentCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FreeInstrumentSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->travelTo(Carbon::parse('2026-11-04 12:00', 'Europe/Vienna'));
        config(['services.eodhd.key' => 'test-token']);
        Http::preventStrayRequests();
    }

    public function test_default_search_returns_official_venue_and_currency_without_using_eodhd(): void
    {
        Http::fake([XetraInstrumentCatalog::Url => Http::response($this->catalog())]);
        $this->actingAs($this->admin())->postJson('/admin/stocks/search', ['query' => 'AT0000821103'])
            ->assertOk()->assertJsonPath('source', 'free')->assertJsonPath('status', 'available')
            ->assertJsonPath('catalog_as_of', '2026-11-04')->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.symbol', 'UN9')->assertJsonPath('results.0.wkn', '928900')
            ->assertJsonPath('results.0.exchange', 'XETRA')->assertJsonPath('results.0.mic_code', 'XETR')
            ->assertJsonPath('results.0.currency', 'EUR')->assertJsonPath('results.0.instrument_type', 'Common Stock')
            ->assertJsonPath('results.0.source_key', 'xetra_instruments');
        $this->postJson('/admin/stocks/search', ['query' => '928900'])->assertOk()->assertJsonPath('results.0.symbol', 'UN9');
        $this->postJson('/admin/stocks/search', ['query' => 'copper'])->assertOk()->assertJsonPath('results.0.instrument_type', 'ETF');
        Http::assertSentCount(1);
        $this->assertNull(Cache::get('eodhd-api-usage:day:2026-11-04'));
    }

    public function test_search_uses_eodhd_if_free_sources_have_no_suitable_result(): void
    {
        config(['services.eodhd.enabled' => true]);
        Http::fake([
            XetraInstrumentCatalog::Url => Http::response('', 503),
            'eodhd.com/api/search/*' => Http::response([['Code' => 'NEWCO', 'Name' => 'New Company', 'Exchange' => 'US', 'Currency' => 'USD', 'Type' => 'Common Stock']]),
        ]);
        $this->actingAs($this->admin())->postJson('/admin/stocks/search', ['query' => 'Newco'])
            ->assertOk()->assertJsonPath('source', 'EODHD')->assertJsonPath('results.0.symbol', 'NEWCO')
            ->assertJsonPath('results.0.source_markers', ['EODHD']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'eodhd.com/api/search/'));
        $this->assertSame(XetraInstrumentCatalog::Url, Http::recorded()[0][0]->url());
    }

    public function test_market_and_currency_variants_are_not_merged_and_existing_names_are_not_changed(): void
    {
        $holding = StockHolding::factory()->create(['symbol' => 'UN9', 'name' => 'My saved label', 'isin' => 'AT0000821103', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR']);
        $body = $this->catalog()."Active;Active;UNIQA USD;AT0000821103;000928900;UN9U;XETR;CS;USD\n"
            ."Active;Active;Other venue;AT0000821103;000928900;UN9F;XFRA;CS;EUR\n";
        Http::fake([XetraInstrumentCatalog::Url => Http::response($body)]);
        $result = app(FreeInstrumentSearch::class)->search('AT0000821103');
        $this->assertCount(2, $result['results']);
        $this->assertSame(['EUR', 'USD'], array_column($result['results'], 'currency'));
        $this->assertSame('My saved label', $holding->fresh()->name);
    }

    public function test_saved_us_instrument_and_exact_index_are_searchable_when_catalogue_is_unavailable(): void
    {
        StockHolding::factory()->create(['symbol' => 'ARGT', 'isin' => 'US37950E2596', 'exchange' => 'US', 'currency' => 'USD']);
        IndexWatchItem::factory()->create(['symbol' => 'ATX', 'isin' => 'AT0000999982', 'exchange' => 'INDX', 'instrument_type' => 'INDEX', 'currency' => 'EUR']);
        Http::fake([XetraInstrumentCatalog::Url => Http::response('', 503)]);
        $this->actingAs($this->admin())->postJson('/admin/stocks/search', ['query' => 'ARGT'])
            ->assertOk()->assertJsonPath('status', 'unavailable')->assertJsonPath('results.0.currency', 'USD')
            ->assertJsonPath('results.0.source_key', 'stored_instrument');
        $this->postJson('/admin/stocks/search', ['query' => 'ATX.INDX'])->assertOk()
            ->assertJsonPath('results.0.symbol', 'ATX')->assertJsonPath('results.0.instrument_type', 'INDEX');
        Http::assertSentCount(1);
    }

    public function test_exact_saved_symbol_is_prioritized_before_limiting_partial_matches(): void
    {
        StockHolding::factory()->count(55)->create(['name' => 'Various DE instruments', 'exchange' => 'US']);
        StockHolding::factory()->create(['symbol' => 'DE', 'name' => 'Exact symbol', 'exchange' => 'US']);
        Http::fake([XetraInstrumentCatalog::Url => Http::response('', 503)]);
        $result = app(FreeInstrumentSearch::class)->search('DE');
        $this->assertSame('DE', $result['results'][0]['symbol']);
    }

    public function test_rate_limit_has_a_cooldown_when_eodhd_is_disabled(): void
    {
        config(['services.eodhd.enabled' => false]);
        Http::fake([XetraInstrumentCatalog::Url => Http::response('', 429)]);
        $this->actingAs($this->admin())->postJson('/admin/stocks/search', ['query' => 'Unknown'])
            ->assertOk()->assertJsonPath('status', 'unavailable')->assertJsonCount(0, 'results');
        $this->postJson('/admin/stocks/search', ['query' => 'Another'])->assertOk()->assertJsonCount(0, 'results');
        Http::assertSentCount(1);
    }

    public function test_the_official_file_can_redirect_to_its_updated_content_hash(): void
    {
        $current = 'https://www.cashmarket.deutsche-boerse.com/resource/blob/1528/'.str_repeat('a', 32).'/data/t7-xetr-allTradableInstruments.csv';
        Http::fake([
            XetraInstrumentCatalog::Url => Http::response('', 302, ['Location' => $current]),
            $current => Http::response($this->catalog()),
        ]);
        $this->assertSame('UN9', app(FreeInstrumentSearch::class)->search('UN9')['results'][0]['symbol']);
        Http::assertSentCount(2);
    }

    public function test_redirects_cannot_change_origin_or_select_another_resource(): void
    {
        Http::fake([XetraInstrumentCatalog::Url => Http::response('', 302, ['Location' => 'https://eodhd.com/api/search/UN9'])]);
        $this->assertSame('unavailable', app(FreeInstrumentSearch::class)->search('UN9')['status']);
        Http::assertSentCount(1);
    }

    public function test_redirect_loops_are_bounded(): void
    {
        Http::fake([XetraInstrumentCatalog::Url => Http::response('', 302, ['Location' => XetraInstrumentCatalog::Url])]);
        $this->assertSame('unavailable', app(FreeInstrumentSearch::class)->search('UN9')['status']);
        Http::assertSentCount(3);
    }

    public function test_stale_reference_data_remains_dated_after_a_failed_refresh_and_expires(): void
    {
        Http::fake([XetraInstrumentCatalog::Url => Http::sequence()->push($this->catalog())->push('', 503)->push('', 503)]);
        $this->assertSame('available', app(FreeInstrumentSearch::class)->search('UN9')['status']);
        $this->travel(7)->hours();
        $result = app(FreeInstrumentSearch::class)->search('UN9');
        $this->assertSame('stale', $result['status']);
        $this->assertSame('2026-11-04', $result['catalog_as_of']);
        $this->assertSame('UN9', $result['results'][0]['symbol']);
        $this->travel(8)->days();
        $result = app(FreeInstrumentSearch::class)->search('UN9');
        $this->assertSame('unavailable', $result['status']);
        $this->assertSame([], $result['results']);
        Http::assertSentCount(3);
    }

    #[DataProvider('invalidCatalogues')]
    public function test_invalid_catalogues_are_not_used(string $body): void
    {
        Http::fake([XetraInstrumentCatalog::Url => Http::response($body)]);
        $result = app(FreeInstrumentSearch::class)->search('UN9');
        $this->assertSame('unavailable', $result['status']);
        $this->assertSame([], $result['results']);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{string}> */
    public static function invalidCatalogues(): array
    {
        $body = "Market:;XETR\nDate Last Update:;04.11.2026\nProduct Status;Instrument Status;Instrument;ISIN;WKN;Mnemonic;MIC Code;Instrument Type;Currency\nActive;Active;UNIQA;AT0000821103;000928900;UN9;XETR;CS;EUR\n";

        return [
            'wrong market' => [str_replace('Market:;XETR', 'Market:;XFRA', $body)],
            'old data' => [str_replace('04.11.2026', '01.09.2026', $body)],
            'future data' => [str_replace('04.11.2026', '05.11.2026', $body)],
            'wrong columns' => [str_replace(';Currency', ';Unknown', $body)],
            'inactive instrument' => [str_replace('Active;Active;', 'Active;Inactive;', $body)],
            'wrong venue' => [str_replace(';XETR;CS;', ';XFRA;CS;', $body)],
            'invalid currency' => [str_replace(';EUR', ';EURO', $body)],
            'html error' => ['<html>Unavailable</html>'],
            'oversized response' => [str_repeat('x', 8388609)],
        ];
    }

    public function test_reference_names_are_corrected_and_verified_valor_aliases_still_work(): void
    {
        Http::fake([XetraInstrumentCatalog::Url => Http::response($this->catalog()."Active;Active;AMUNDI OLD NAME;FR0010930644;000A2HO28;AMEE;XETR;ETF;EUR\n")]);
        $result = app(FreeInstrumentSearch::class)->search('FR0010930644');
        $this->assertSame('Amundi Global Hydrogen UCITS ETF Acc', $result['results'][0]['name']);
        $result = app(FreeInstrumentSearch::class)->search('45209801');
        $this->assertSame('LU1900066462', $result['results'][0]['isin']);
        $this->assertSame('verified_instrument', $result['results'][0]['source_key']);
    }

    public function test_free_search_works_with_eodhd_disabled_and_legacy_search_requires_explicit_selection(): void
    {
        config(['services.eodhd.enabled' => false]);
        Http::fake([XetraInstrumentCatalog::Url => Http::response($this->catalog())]);
        $this->actingAs($this->admin())->postJson('/admin/stocks/search', ['query' => 'UN9'])
            ->assertOk()->assertJsonPath('results.0.symbol', 'UN9');
        $this->postJson('/admin/stocks/search', ['query' => 'UN9', 'source' => 'eodhd'])->assertUnprocessable();
        Http::assertSentCount(1);
    }

    public function test_guest_and_invalid_source_do_not_access_any_provider(): void
    {
        Http::fake();
        $this->postJson('/admin/stocks/search', ['query' => 'UN9'])->assertUnauthorized();
        $this->actingAs($this->admin())->postJson('/admin/stocks/search', ['query' => 'UN9', 'source' => 'auto'])
            ->assertUnprocessable()->assertJsonValidationErrors('source');
        Http::assertNothingSent();
    }

    private function catalog(): string
    {
        return "Market:;XETR\nDate Last Update:;04.11.2026\nProduct Status;Instrument Status;Instrument;ISIN;WKN;Mnemonic;MIC Code;Instrument Type;Currency\nActive;Active;UNIQA INSURANCE;AT0000821103;000928900;UN9;XETR;CS;EUR\nActive;Active;ISHARES COPPER MINERS;IE00063FT9K6;000A3D8NG;CEBS;XETR;ETF;EUR\n";
    }

    private function admin(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
