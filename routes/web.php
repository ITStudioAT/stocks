<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminDataController;
use App\Http\Controllers\AdminDepotController;
use App\Http\Controllers\AdminDepotHoldingController;
use App\Http\Controllers\AdminDepotTransactionController;
use App\Http\Controllers\AdminIndexWatchItemController;
use App\Http\Controllers\AdminPriceRefreshSettingsController;
use App\Http\Controllers\AdminProfileController;
use App\Http\Controllers\AdminQueueStatusController;
use App\Http\Controllers\AdminRoleController;
use App\Http\Controllers\AdminStockHistoricalPriceController;
use App\Http\Controllers\AdminStockSearchController;
use App\Http\Controllers\AdminTestsController;
use App\Http\Controllers\AdminUiPreferencesController;
use App\Http\Controllers\AdminUserController;
use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\IndexWatchItem;
use App\Models\StockHolding;
use Illuminate\Support\Facades\Route;

Route::view('/', 'homepage')->name('homepage');

Route::get('/indices', function () {
    return response()->json([
        'indexes' => IndexWatchItem::query()
            ->orderBy('symbol')
            ->get()
            ->map(fn (IndexWatchItem $item) => [
                'symbol' => $item->symbol,
                'name' => $item->name,
                'country' => $item->country,
                'currency' => $item->currency,
                'latest_price' => $item->latest_price !== null
                    ? number_format((float) $item->latest_price, 4, '.', '')
                    : null,
                'latest_price_change_pct' => $item->latest_price_change_pct !== null
                    ? number_format((float) $item->latest_price_change_pct, 2, '.', '')
                    : null,
            ]),
    ]);
})->name('indices');

Route::get('/depot-sum-sign', function () {
    $depot = Depot::query()->where('is_active', true)->first();

    if (! $depot) {
        return response()->json(['sign' => 0]);
    }

    $transactionsByHoldingId = DepotTransaction::query()
        ->where('depot_id', $depot->id)
        ->whereNotNull('stock_holding_id')
        ->whereIn('type', ['buy', 'sell'])
        ->get(['stock_holding_id', 'type', 'pieces', 'total_amount', 'booked_at'])
        ->groupBy('stock_holding_id');

    $positionPiecesByHoldingId = $transactionsByHoldingId
        ->map(fn ($transactions): float => $transactions->reduce(
            fn (float $sum, DepotTransaction $t): float => $t->type === 'buy'
                ? $sum + (float) $t->pieces
                : $sum - (float) $t->pieces,
            0.0,
        ))
        ->filter(fn (float $pieces): bool => $pieces > 0);

    if ($positionPiecesByHoldingId->isEmpty()) {
        return response()->json(['sign' => 0]);
    }

    $yearStart = now()->startOfYear();
    $yearStartPriceByHoldingId = $transactionsByHoldingId->map(function ($transactions) use ($yearStart): ?float {
        $buys = $transactions->filter(
            fn (DepotTransaction $t): bool => $t->type === 'buy' && $t->booked_at?->greaterThanOrEqualTo($yearStart),
        );
        $pieces = $buys->sum(fn (DepotTransaction $t): float => (float) $t->pieces);

        if ($pieces <= 0) {
            return null;
        }

        return $buys->sum(fn (DepotTransaction $t): float => (float) $t->total_amount) / $pieces;
    });

    $holdings = StockHolding::query()
        ->with('latestStockPrice')
        ->whereKey($positionPiecesByHoldingId->keys()->all())
        ->get();

    $sum = $holdings->reduce(function (float $total, StockHolding $holding) use ($positionPiecesByHoldingId, $yearStartPriceByHoldingId): float {
        $yearStartPrice = $yearStartPriceByHoldingId->get($holding->id);

        if ($yearStartPrice === null) {
            return $total;
        }

        $latestPrice = (float) ($holding->latestStockPrice?->price ?? $holding->latest_price ?? 0);

        if ($latestPrice === 0.0) {
            return $total;
        }

        return $total + ($latestPrice - $yearStartPrice) * $positionPiecesByHoldingId->get($holding->id, 0.0);
    }, 0.0);

    return response()->json(['sign' => $sum > 0 ? 1 : ($sum < 0 ? -1 : 0)]);
})->name('depot-sum-sign');

Route::view('/admin/login', 'app')->name('admin.login');

Route::post('/admin/login-code', [AdminAuthController::class, 'requestCode'])
    ->middleware('guest')
    ->name('admin.login-code');

Route::post('/admin/verify-code', [AdminAuthController::class, 'verifyCode'])
    ->middleware('guest')
    ->name('admin.verify-code');

Route::post('/admin/password-login', [AdminAuthController::class, 'passwordLogin'])
    ->middleware('guest')
    ->name('admin.password-login');

Route::middleware(['auth', 'role:admin|super_admin'])->group(function (): void {
    Route::view('/admin', 'app')->name('admin.dashboard');
    Route::view('/admin/dashboard', 'app')->name('admin.dashboard.view');
    Route::view('/admin/profile', 'app')->name('admin.profile.view');
    Route::view('/admin/menu/roles', 'app')
        ->middleware('role:super_admin')
        ->name('admin.menu.roles');
    Route::view('/admin/menu/depots', 'app')->name('admin.menu.depots');
    Route::view('/admin/menu/{adminSection}/{adminSubSection?}', 'app')
        ->where([
            'adminSection' => '[A-Za-z0-9_-]+',
            'adminSubSection' => '[A-Za-z0-9_-]+',
        ])
        ->name('admin.menu');
    Route::get('/admin/me', [AdminAuthController::class, 'me'])->name('admin.me');
    Route::get('/admin/depots', [AdminDepotController::class, 'index'])->name('admin.depots.index');
    Route::get('/admin/depots/active', [AdminDepotController::class, 'active'])->name('admin.depots.active');
    Route::get('/admin/price-refresh-settings', [AdminPriceRefreshSettingsController::class, 'show'])->name('admin.price-refresh-settings.show');
    Route::patch('/admin/price-refresh-settings', [AdminPriceRefreshSettingsController::class, 'update'])->name('admin.price-refresh-settings.update');
    Route::patch('/admin/index-price-refresh-settings', [AdminPriceRefreshSettingsController::class, 'updateIndex'])->name('admin.index-price-refresh-settings.update');
    Route::patch('/admin/intraday-backfill-settings', [AdminPriceRefreshSettingsController::class, 'updateIntradayBackfill'])->name('admin.intraday-backfill-settings.update');
    Route::post('/admin/intraday-backfill/run', [AdminPriceRefreshSettingsController::class, 'runIntradayBackfill'])->name('admin.intraday-backfill.run');
    Route::get('/admin/intraday-backfill/{refreshId}', [AdminPriceRefreshSettingsController::class, 'intradayBackfillStatus'])->name('admin.intraday-backfill.status');
    Route::get('/admin/ui-preferences', [AdminUiPreferencesController::class, 'show'])->name('admin.ui-preferences.show');
    Route::patch('/admin/ui-preferences', [AdminUiPreferencesController::class, 'update'])->name('admin.ui-preferences.update');
    Route::post('/admin/depots', [AdminDepotController::class, 'store'])->name('admin.depots.store');
    Route::patch('/admin/depots/{depot}', [AdminDepotController::class, 'update'])->name('admin.depots.update');
    Route::patch('/admin/depots/{depot}/activate', [AdminDepotController::class, 'activate'])->name('admin.depots.activate');
    Route::get('/admin/depot-transactions', [AdminDepotTransactionController::class, 'index'])->name('admin.depot-transactions.index');
    Route::patch('/admin/depot-transactions/{depotTransaction}/date', [AdminDepotTransactionController::class, 'updateDate'])->name('admin.depot-transactions.date.update');
    Route::post('/admin/depot-transactions/cash', [AdminDepotTransactionController::class, 'storeCash'])->name('admin.depot-transactions.cash.store');
    Route::post('/admin/depot-transactions/stocks', [AdminDepotTransactionController::class, 'storeStock'])->name('admin.depot-transactions.stocks.store');
    Route::get('/admin/watchlist/holdings', [AdminDepotHoldingController::class, 'index'])->name('admin.watchlist.holdings.index');
    Route::get('/admin/watchlist/holdings/{holding}/intraday-candles', [AdminDepotHoldingController::class, 'intradayCandles'])->name('admin.watchlist.holdings.intraday-candles');
    Route::get('/admin/watchlist/exchange-trading-times', [AdminDepotHoldingController::class, 'exchangeTradingTimes'])->name('admin.watchlist.exchange-trading-times');
    Route::get('/admin/watchlist/holdings/pdf', [AdminDepotHoldingController::class, 'exportPdf'])->name('admin.watchlist.holdings.pdf');
    Route::get('/admin/index-watch-items', [AdminIndexWatchItemController::class, 'index'])->name('admin.index-watch-items.index');
    Route::post('/admin/index-watch-items/{indexWatchItem}/prices/ensure', [AdminIndexWatchItemController::class, 'ensurePrices'])->name('admin.index-watch-items.prices.ensure');
    Route::post('/admin/watchlist/holdings', [AdminDepotHoldingController::class, 'store'])->name('admin.watchlist.holdings.store');
    Route::post('/admin/index-watch-items', [AdminIndexWatchItemController::class, 'store'])->name('admin.index-watch-items.store');
    Route::post('/admin/watchlist/holdings/refresh-prices', [AdminDepotHoldingController::class, 'refreshPrices'])->name('admin.watchlist.holdings.refresh-prices');
    Route::get('/admin/watchlist/holdings/refresh-prices/{refreshId}', [AdminDepotHoldingController::class, 'refreshPriceStatus'])->name('admin.watchlist.holdings.refresh-prices.status');
    Route::get('/admin/queue/status', [AdminQueueStatusController::class, 'show'])->name('admin.queue.status');
    Route::post('/admin/queue/clear', [AdminQueueStatusController::class, 'clear'])->name('admin.queue.clear');
    Route::get('/admin/tests/options', [AdminTestsController::class, 'options'])->name('admin.tests.options');
    Route::get('/admin/tests/tickers', [AdminTestsController::class, 'tickers'])->name('admin.tests.tickers');
    Route::get('/admin/tests/exchanges', [AdminTestsController::class, 'exchanges'])->name('admin.tests.exchanges');
    Route::get('/admin/data/exchanges', [AdminDataController::class, 'exchanges'])->name('admin.data.exchanges');
    Route::post('/admin/data/exchanges/reload', [AdminDataController::class, 'reload'])->name('admin.data.exchanges.reload');
    Route::get('/admin/data/exchanges/reload/{refreshId}', [AdminDataController::class, 'reloadStatus'])->name('admin.data.exchanges.reload.status');
    Route::get('/admin/data/intraday', [AdminDataController::class, 'intraday'])->name('admin.data.intraday');
    Route::post('/admin/data/intraday/reload', [AdminDataController::class, 'reloadIntraday'])->name('admin.data.intraday.reload');
    Route::get('/admin/data/intraday/reload/{refreshId}', [AdminDataController::class, 'reloadIntradayStatus'])->name('admin.data.intraday.reload.status');
    Route::post('/admin/watchlist/holdings/historical-prices/ensure', [AdminStockHistoricalPriceController::class, 'ensure'])->name('admin.watchlist.holdings.historical-prices.ensure');
    Route::get('/admin/watchlist/holdings/historical-prices/{refreshId}', [AdminStockHistoricalPriceController::class, 'status'])->name('admin.watchlist.holdings.historical-prices.status');
    Route::patch('/admin/watchlist/holdings/{holding}/flatex-price', [AdminDepotHoldingController::class, 'updateFlatexPrice'])->name('admin.watchlist.holdings.flatex-price');
    Route::delete('/admin/watchlist/holdings/{holding}', [AdminDepotHoldingController::class, 'destroy'])->name('admin.watchlist.holdings.destroy');
    Route::get('/admin/active-depot/holdings', [AdminDepotHoldingController::class, 'index'])->name('admin.active-depot.holdings.index');
    Route::get('/admin/active-depot/exchange-trading-times', [AdminDepotHoldingController::class, 'exchangeTradingTimes'])->name('admin.active-depot.exchange-trading-times');
    Route::get('/admin/active-depot/holdings/pdf', [AdminDepotHoldingController::class, 'exportPdf'])->name('admin.active-depot.holdings.pdf');
    Route::post('/admin/active-depot/holdings', [AdminDepotHoldingController::class, 'store'])->name('admin.active-depot.holdings.store');
    Route::post('/admin/active-depot/holdings/refresh-prices', [AdminDepotHoldingController::class, 'refreshPrices'])->name('admin.active-depot.holdings.refresh-prices');
    Route::get('/admin/active-depot/holdings/refresh-prices/{refreshId}', [AdminDepotHoldingController::class, 'refreshPriceStatus'])->name('admin.active-depot.holdings.refresh-prices.status');
    Route::patch('/admin/active-depot/holdings/{holding}/flatex-price', [AdminDepotHoldingController::class, 'updateFlatexPrice'])->name('admin.active-depot.holdings.flatex-price');
    Route::delete('/admin/active-depot/holdings/{holding}', [AdminDepotHoldingController::class, 'destroy'])->name('admin.active-depot.holdings.destroy');
    Route::get('/admin/stocks/search', [AdminStockSearchController::class, 'index'])->name('admin.stocks.search');
    Route::patch('/admin/profile/name', [AdminProfileController::class, 'updateName'])->name('admin.profile.name');
    Route::patch('/admin/profile/password', [AdminProfileController::class, 'updatePassword'])->name('admin.profile.password');
    Route::match(['GET', 'POST'], '/admin/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

    Route::middleware('role:super_admin')->group(function (): void {
        Route::get('/admin/users', [AdminUserController::class, 'index'])->name('admin.users.index');
        Route::post('/admin/users', [AdminUserController::class, 'store'])->name('admin.users.store');
        Route::patch('/admin/users/{user}', [AdminUserController::class, 'update'])->name('admin.users.update');
        Route::delete('/admin/users/{user}', [AdminUserController::class, 'destroy'])->name('admin.users.destroy');
        Route::get('/admin/roles', [AdminRoleController::class, 'index'])->name('admin.roles.index');
        Route::post('/admin/roles', [AdminRoleController::class, 'store'])->name('admin.roles.store');
        Route::patch('/admin/roles/{role}', [AdminRoleController::class, 'update'])->name('admin.roles.update');
        Route::delete('/admin/roles/{role}', [AdminRoleController::class, 'destroy'])->name('admin.roles.destroy');
    });
});
