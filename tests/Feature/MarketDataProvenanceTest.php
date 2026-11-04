<?php

namespace Tests\Feature;

use App\Http\Middleware\AddMarketDataProvenance;
use App\Models\IndexWatchItemPrice;
use App\Services\MarketDataProvenance;
use Illuminate\Http\Request;
use Tests\TestCase;

class MarketDataProvenanceTest extends TestCase
{
    public function test_each_point_retains_its_origin_and_aggregate_uses_only_the_two_markers(): void
    {
        $payload = ['latest_price_source' => 'Yahoo Finance · delay unknown', 'daily_prices' => [
            ['price' => '10', 'source_key' => 'eodhd_eod'],
            ['price' => '11', 'source_key' => 'yahoo_intraday'],
            ['price' => '12', 'source_key' => 'stored_instrument'],
        ]];
        $decorated = MarketDataProvenance::decorate($payload);
        $this->assertSame(['free'], $decorated['latest_price_source_markers']);
        $this->assertSame(['EODHD'], $decorated['daily_prices'][0]['source_markers']);
        $this->assertSame(['free'], $decorated['daily_prices'][1]['source_markers']);
        $this->assertSame([], $decorated['daily_prices'][2]['source_markers']);
        $this->assertSame(['free', 'EODHD'], $decorated['data_source_markers']);
        $this->assertSame($decorated, MarketDataProvenance::decorate($decorated));
        $this->assertSame('10', $decorated['daily_prices'][0]['price']);
    }

    public function test_storage_or_an_attempted_provider_does_not_prove_free_or_eodhd_origin(): void
    {
        $this->assertSame([], MarketDataProvenance::markers('database', 'cache', 'stored_instrument', 'verified_instrument'));
        $this->assertArrayNotHasKey('source_markers', MarketDataProvenance::decorate(['provider' => 'EODHD', 'status' => 'not_configured']));
        $this->assertSame([], MarketDataProvenance::forRecord(['source_url' => 'https://eodhd.com.evil.example/path']));
    }

    public function test_json_middleware_keeps_status_headers_and_point_specific_sources(): void
    {
        $response = (new AddMarketDataProvenance)->handle(Request::create('/example'), fn () => response()->json([
            'rows' => [['source_name' => 'EODHD intraday'], ['source_key' => 'xetra_posttrade']],
        ], 202)->header('X-Test', 'preserved'));

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('preserved', $response->headers->get('X-Test'));
        $this->assertSame(['EODHD'], $response->getData(true)['rows'][0]['source_markers']);
        $this->assertSame(['free'], $response->getData(true)['rows'][1]['source_markers']);
    }

    public function test_legacy_index_payloads_keep_eodhd_origin_and_unknown_rows_stay_unknown(): void
    {
        $this->assertSame(['EODHD'], MarketDataProvenance::forIndexPrice(new IndexWatchItemPrice(['raw_payload' => [
            'date' => '2026-11-03', 'close' => 100, 'adjusted_close' => 100,
        ]])));
        $this->assertSame([], MarketDataProvenance::forIndexPrice(new IndexWatchItemPrice(['raw_payload' => ['close' => 100]])));
        $this->assertSame(['free'], MarketDataProvenance::forIndexPrice(new IndexWatchItemPrice(['raw_payload' => [
            '_source' => 'free', 'date' => '2026-11-03', 'close' => 100, 'adjusted_close' => 100,
        ]])));
        $this->assertSame(['free'], MarketDataProvenance::forRecord(['source_key' => 'yahoo_intraday', 'source_name' => 'EODHD old label']));
    }
}
