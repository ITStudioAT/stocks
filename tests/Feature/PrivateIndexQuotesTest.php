<?php

namespace Tests\Feature;

use App\Jobs\RefreshPrivateIndexQuotes;
use App\Models\AppConfig;
use App\Models\IndexWatchItem;
use App\Models\User;
use App\Services\PrivateIndexQuotes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PrivateIndexQuotesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-07 12:00:00', 'UTC'));
        Http::preventStrayRequests();
    }

    private function atx(): IndexWatchItem
    {
        return IndexWatchItem::factory()->create([
            'symbol' => 'ATX', 'name' => 'Austrian Traded Index in EUR', 'isin' => 'AT0000999982',
            'exchange' => 'INDX', 'instrument_type' => 'INDEX', 'currency' => 'EUR',
            'latest_price' => 80, 'latest_price_as_of' => '2026-11-01 12:00:00',
        ]);
    }

    private function response(string $currency = 'EUR'): array
    {
        return ['chart' => ['result' => [[
            'meta' => ['symbol' => '^ATX', 'currency' => $currency, 'instrumentType' => 'INDEX',
                'exchangeName' => 'VIE', 'exchangeTimezoneName' => 'Europe/Vienna',
                'regularMarketPrice' => 101, 'regularMarketTime' => Carbon::parse('2026-11-06 16:30:00', 'UTC')->timestamp],
            'timestamp' => [Carbon::parse('2026-11-05 08:00:00', 'UTC')->timestamp, Carbon::parse('2026-11-06 08:00:00', 'UTC')->timestamp],
            'indicators' => ['quote' => [['close' => [100, 101]]]],
        ]]]];
    }

    public function test_private_quote_is_visible_to_admin_and_never_written_into_public_quote(): void
    {
        $item = $this->atx();
        Http::fake(['query1.finance.yahoo.com/*' => Http::response($this->response())]);
        app(PrivateIndexQuotes::class)->refresh();
        $stored = AppConfig::query()->where('key', 'private_index.yahoo.'.$item->id)->firstOrFail()->value;
        $this->assertSame('101.00000000', $stored['price']);
        $this->assertSame('100.00000000', $stored['previous_close']);
        $this->assertSame('1.000000', $stored['change_percent']);
        $this->assertSame('80.00000000', $item->fresh()->latest_price);

        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin'));
        $this->actingAs($user)->getJson('/admin/index-watch-items')->assertOk()
            ->assertJsonPath('indexes.0.latest_price', '101.00000000')
            ->assertJsonPath('indexes.0.latest_price_source', 'Yahoo Finance')
            ->assertJsonPath('indexes.0.latest_price_fetched_at', $stored['fetched_at'])
            ->assertJsonPath('indexes.0.private_quote', true);
        auth()->logout();
        $public = $this->getJson('/indices')->assertOk()->json();
        $this->assertStringNotContainsString('101.00000000', json_encode($public));
        $this->assertStringNotContainsString('Yahoo Finance', json_encode($public));
        Http::assertSentCount(1);
        app(PrivateIndexQuotes::class)->refresh();
        Http::assertSentCount(1);
    }

    public function test_wrong_identity_does_not_replace_stored_data_and_rate_limit_stops_calls(): void
    {
        $item = $this->atx();
        Http::fake(['query1.finance.yahoo.com/*' => Http::sequence()->push($this->response('USD'))->push([], 429)]);
        app(PrivateIndexQuotes::class)->refresh();
        $this->assertDatabaseMissing('app_configs', ['key' => 'private_index.yahoo.'.$item->id]);
        app(PrivateIndexQuotes::class)->refresh(true);
        $this->assertTrue(Cache::has('free-intraday.cooldown.yahoo'));
        Http::assertSentCount(2);
    }

    public function test_admin_can_queue_refresh_without_fetching_during_request(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        Http::fake();
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin'));
        $this->actingAs($user)->postJson('/admin/index-watch-items/refresh-free', ['force' => true])->assertOk();
        Queue::assertPushed(RefreshPrivateIndexQuotes::class, fn ($job): bool => $job->force === true);
        Http::assertNothingSent();
    }

    public function test_refresh_refuses_synchronous_queue(): void
    {
        config(['queue.default' => 'sync']);
        Queue::fake();
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin'));
        $this->actingAs($user)->postJson('/admin/index-watch-items/refresh-free')->assertStatus(503);
        Queue::assertNotPushed(RefreshPrivateIndexQuotes::class);
    }

    public function test_older_provider_quote_does_not_replace_a_newer_quote(): void
    {
        $item = $this->atx();
        $older = $this->response();
        $older['chart']['result'][0]['meta']['regularMarketPrice'] = 90;
        $older['chart']['result'][0]['meta']['regularMarketTime'] = Carbon::parse('2026-11-05 16:30:00', 'UTC')->timestamp;
        Http::fake(['query1.finance.yahoo.com/*' => Http::sequence()->push($this->response())->push($older)]);
        $service = app(PrivateIndexQuotes::class);
        $service->refresh();
        $service->refresh(true);
        $saved = AppConfig::query()->where('key', 'private_index.yahoo.'.$item->id)->firstOrFail()->value;
        $this->assertSame('101.00000000', $saved['price']);
        $this->assertSame('2026-11-06T16:30:00+00:00', $saved['as_of']);
    }
}
