<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HttpMethodSecurityTest extends TestCase
{
    public function test_state_changing_and_provider_backed_endpoints_reject_get_requests(): void
    {
        $urls = [
            '/admin/logout',
            '/admin/cloudways/check',
            '/admin/dashboard/ai/stocks/1/researches',
            '/admin/tests/tickers',
            '/admin/tests/exchanges',
            '/admin/tests/stocks/1/intraday',
            '/admin/watchlist/holdings/charts?include_charts=1',
            '/admin/watchlist/holdings/1/intraday-candles',
            '/admin/stocks/search?query=Apple',
        ];

        foreach ($urls as $url) {
            $this->getJson($url)->assertMethodNotAllowed();
        }
    }

    public function test_provider_backed_and_heavy_routes_use_the_costly_operation_limiter(): void
    {
        $routeNames = [
            'admin.active-depot.holdings.refresh-prices',
            'admin.cloudways.show',
            'admin.cloudways.sync',
            'admin.dashboard.ai.stockResearches.store',
            'admin.data.endOfDay.sync',
            'admin.data.exchanges.reload',
            'admin.data.historical.sync',
            'admin.data.indices.historical.sync',
            'admin.data.indices.sync',
            'admin.data.intraday.reload',
            'admin.data.realtime.sync',
            'admin.index-watch-items.prices.ensure',
            'admin.index-watch-items.store',
            'admin.intraday-backfill.run',
            'admin.stocks.search',
            'admin.tests.exchanges',
            'admin.tests.stocks.intraday',
            'admin.tests.tickers',
            'admin.v2.indices.eodhdSync.store',
            'admin.v2.indices.realtimeSync.store',
            'admin.v2.stocks.eodhdSync.store',
            'admin.watchlist.holdings.historical-prices.ensure',
            'admin.watchlist.holdings.charts',
            'admin.watchlist.holdings.intraday-candles',
            'admin.watchlist.holdings.refresh-prices',
        ];

        foreach ($routeNames as $routeName) {
            $middleware = Route::getRoutes()->getByName($routeName)?->gatherMiddleware() ?? [];

            $this->assertContains(
                'throttle:admin.costly-operation',
                $middleware,
                "{$routeName} is missing the costly-operation limiter.",
            );
        }
    }
}
