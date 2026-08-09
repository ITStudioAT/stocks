<?php

namespace App\Services;

use App\Console\Commands\DispatchDueEndOfDayDataUpdates;
use App\Console\Commands\DispatchDueIndexDataUpdates;
use App\Console\Commands\DispatchDueIntradayCandleBackfills;
use App\Console\Commands\DispatchDuePriceRefreshes;
use App\Console\Commands\DispatchDueV2IndexRealtimeSyncs;
use App\Http\Controllers\AdminDataController;
use App\Http\Controllers\AdminDepotHoldingController;
use App\Http\Controllers\AdminIndexWatchItemController;
use App\Http\Controllers\AdminPriceRefreshSettingsController;
use App\Http\Controllers\AdminStockHistoricalPriceController;
use App\Http\Controllers\AdminStockSearchController;
use App\Jobs\BackfillMissingStockHoldingIntradayCandles;
use App\Jobs\RefreshDepotHoldingPrices;
use App\Jobs\ReloadEodhdExchanges;
use App\Jobs\ReloadStockHoldingIntradayData;
use App\Jobs\SyncV2IndexRealtimeData;

class EodhdMethodInformation
{
    /**
     * @return array<int, array{
     *     key: string,
     *     title: string,
     *     access: string,
     *     description: string,
     *     triggers: array<int, string>,
     *     execution: array{mode: string, scheduled: bool, queue: ?string, description: string},
     *     laravel_methods: array<int, array{layer: string, class: class-string, method: string}>,
     *     tables: array<int, string>,
     *     note: ?string,
     * }>
     */
    public function methods(): array
    {
        return [
            [
                'key' => 'symbol-discovery',
                'title' => 'Symbolsuche für Aktien und Indizes',
                'access' => 'search/{query} + exchange-symbol-list/INDX',
                'description' => 'Löst Suchbegriffe, ISIN, WKN, Valor und Index-Symbole gegen EODHD auf.',
                'triggers' => [
                    'HTTP POST /admin/stocks/search',
                    'Ausgelöst beim Suchen nach einer neuen Aktie oder einem neuen Index.',
                ],
                'execution' => $this->execution(
                    'synchronous',
                    false,
                    null,
                    'Läuft vollständig im aktuellen HTTP-Request; keine Queue und kein Scheduler.',
                ),
                'laravel_methods' => [
                    $this->method('Controller', AdminStockSearchController::class, 'index'),
                    $this->method('Service', StockSearchQueryResolver::class, 'resolveCandidates'),
                    $this->method('Service intern', StockSearchQueryResolver::class, 'resolveFreshCandidates'),
                    $this->method('Index-Fallback', StockSearchQueryResolver::class, 'resolveIndexCandidates'),
                    $this->method('EODHD client', EodhdApiClient::class, 'get'),
                ],
                'tables' => ['stock_holdings', 'index_watch_items'],
                'note' => 'Die Suche selbst schreibt nicht in die Datenbank. Erst das anschließende Hinzufügen speichert den ausgewählten Kandidaten.',
            ],
            [
                'key' => 'exchange-metadata',
                'title' => 'Börsen, Handelszeiten und Feiertage',
                'access' => 'exchanges-list/ + v2/exchange-details/{code}',
                'description' => 'Importiert die unterstützten Börsen und ergänzt Detaildaten für Handelszeiten und Feiertage.',
                'triggers' => [
                    'HTTP POST /admin/data/exchanges/reload',
                    'Manuelle Aktion unter Data → Exchanges.',
                ],
                'execution' => $this->execution(
                    'queued',
                    false,
                    $this->queueName(),
                    'Der Controller erzeugt einen Import-Lauf und dispatcht einen Queue-Job.',
                ),
                'laravel_methods' => [
                    $this->method('Controller', AdminDataController::class, 'reload'),
                    $this->method('Queue job', ReloadEodhdExchanges::class, 'handle'),
                    $this->method('Service', EodhdExchangeDataImporter::class, 'import'),
                    $this->method('Service', EodhdExchangeDataImporter::class, 'fetchExchangeList'),
                    $this->method('EODHD client', EodhdApiClient::class, 'get'),
                ],
                'tables' => ['eodhd_exchanges'],
                'note' => null,
            ],
            [
                'key' => 'stock-realtime',
                'title' => 'Aktien-Livekurse',
                'access' => 'real-time/{symbol} + s={additional-symbols}',
                'description' => 'Lädt Aktienkurse in Batches, validiert sie und aktualisiert den ausgewählten Live-Kurs je Holding.',
                'triggers' => [
                    'Scheduler jede Minute: price-refresh:dispatch-due',
                    'HTTP POST /admin/watchlist/holdings/refresh-prices',
                    'HTTP POST /admin/data/realtime/sync',
                ],
                'execution' => $this->execution(
                    'mixed',
                    true,
                    $this->queueName(),
                    'Scheduler und Watchlist-Refresh laufen über die Queue; Data → Live Data → Sync läuft synchron.',
                ),
                'laravel_methods' => [
                    $this->method('Console command', DispatchDuePriceRefreshes::class, 'handle'),
                    $this->method('Scheduler', PriceRefreshScheduler::class, 'dispatchDueRefreshes'),
                    $this->method('Controller', AdminDepotHoldingController::class, 'refreshPrices'),
                    $this->method('Controller', AdminDataController::class, 'syncRealtime'),
                    $this->method('Dispatcher', DepotHoldingPriceRefreshDispatcher::class, 'dispatch'),
                    $this->method('Queue job', RefreshDepotHoldingPrices::class, 'handle'),
                    $this->method('Service', EodhdBatchRealtimePriceService::class, 'syncAll'),
                    $this->method('Service intern', EodhdBatchRealtimePriceService::class, 'syncBatch'),
                    $this->method('EODHD client', EodhdApiClient::class, 'get'),
                ],
                'tables' => ['stock_realtime_prices', 'stock_holdings'],
                'note' => 'Der Scheduler prüft jede Minute, führt den Zugriff aber nur aus, wenn der konfigurierte nächste Refresh fällig ist.',
            ],
            [
                'key' => 'stock-historical-coverage',
                'title' => 'Historische Aktienabdeckung',
                'access' => 'eod/{symbol.exchange}',
                'description' => 'Schließt historische Tageslücken für die langfristige Aktienabdeckung und schreibt OHLC- sowie kanonische Schlusskurse.',
                'triggers' => [
                    'HTTP POST /admin/watchlist/holdings/historical-prices/ensure',
                    'Manuelle Ensure-Aktion, wenn historische Abdeckung fehlt.',
                ],
                'execution' => $this->execution(
                    'synchronous',
                    false,
                    null,
                    'Request-getriebener synchroner Import; keine Queue und kein eigener Scheduler.',
                ),
                'laravel_methods' => [
                    $this->method('Controller', AdminStockHistoricalPriceController::class, 'ensure'),
                    $this->method('Service', EodhdHistoricalDataService::class, 'syncAll'),
                    $this->method('Service', EodhdHistoricalDataService::class, 'syncHolding'),
                    $this->method('Service intern', EodhdHistoricalDataService::class, 'storePrices'),
                    $this->method('EODHD client', EodhdApiClient::class, 'get'),
                ],
                'tables' => ['stock_holding_daily_prices', 'stock_prices'],
                'note' => 'Dieser Pfad ist nicht der geplante End-of-Day-Lauf; er dient der historischen Abdeckung.',
            ],
            [
                'key' => 'stock-end-of-day',
                'title' => 'Aktien-End-of-Day',
                'access' => 'eod/{symbol.exchange}',
                'description' => 'Ermittelt fehlende abgeschlossene Handelstage und speichert kanonische EOD-Schlusskurse.',
                'triggers' => [
                    'Scheduler jede Minute: end-of-day-data:dispatch-due',
                    'HTTP POST /admin/data/end-of-day/sync',
                    'HTTP POST /admin/data/repair/end-of-day',
                    'HTTP POST /admin/data/repair/end-of-day/{holding}',
                ],
                'execution' => $this->execution(
                    'synchronous',
                    true,
                    null,
                    'Scheduler-Command und manuelle Aktionen rufen den Service direkt und synchron auf.',
                ),
                'laravel_methods' => [
                    $this->method('Console command', DispatchDueEndOfDayDataUpdates::class, 'handle'),
                    $this->method('Scheduler', EndOfDayDataUpdateScheduler::class, 'dispatchDue'),
                    $this->method('Scheduler', EndOfDayDataUpdateScheduler::class, 'dispatchNow'),
                    $this->method('Controller', AdminDataController::class, 'syncEndOfDay'),
                    $this->method('Repair service', StockEndOfDayRepairService::class, 'repair'),
                    $this->method('Service', EodhdEndOfDayDataService::class, 'syncLatestMissing'),
                    $this->method('Service', EodhdEndOfDayDataService::class, 'syncHolding'),
                    $this->method('Service intern', EodhdEndOfDayDataService::class, 'fetchAndStoreRange'),
                    $this->method('EODHD client', EodhdApiClient::class, 'get'),
                ],
                'tables' => ['stock_prices'],
                'note' => 'Der Laravel Scheduler prüft jede Minute; die konfigurierte Tageszeit und Missing-Data-Retry-Logik entscheiden, ob EODHD wirklich aufgerufen wird.',
            ],
            [
                'key' => 'stock-intraday',
                'title' => 'Aktien-Intraday-Kerzen',
                'access' => 'intraday/{symbol.exchange}',
                'description' => 'Lädt 5-Minuten-Kerzen für Backfill, aktuelle Lücken, Reparatur und Detailansichten.',
                'triggers' => [
                    'Scheduler jede Minute: intraday-candles:dispatch-due',
                    'HTTP POST /admin/data/historical/sync',
                    'HTTP POST /admin/data/intraday/reload',
                    'HTTP POST /admin/intraday-backfill/run',
                    'HTTP POST /admin/data/repair/historical-data',
                    'HTTP POST /admin/data/repair/historical-data/{holding}',
                    'On demand über Watchlist-Intraday-Endpunkte.',
                ],
                'execution' => $this->execution(
                    'mixed',
                    true,
                    $this->queueName(),
                    'Automatischer Backfill und kompletter Reload sind queued; latest-missing Sync, Repair und On-demand-Ansichten laufen synchron.',
                ),
                'laravel_methods' => [
                    $this->method('Console command', DispatchDueIntradayCandleBackfills::class, 'handle'),
                    $this->method('Scheduler', IntradayCandleBackfillScheduler::class, 'dispatchDue'),
                    $this->method('Queue job', BackfillMissingStockHoldingIntradayCandles::class, 'handle'),
                    $this->method('Queue job', ReloadStockHoldingIntradayData::class, 'handle'),
                    $this->method('Controller', AdminDataController::class, 'syncHistorical'),
                    $this->method('Controller', AdminDataController::class, 'reloadIntraday'),
                    $this->method('Controller', AdminPriceRefreshSettingsController::class, 'runIntradayBackfill'),
                    $this->method('Controller', AdminDepotHoldingController::class, 'intradayCandles'),
                    $this->method('Service', StockHoldingIntradayDataReloader::class, 'importMissingYear'),
                    $this->method('Service', StockHoldingIntradayDataReloader::class, 'importLatestMissing'),
                    $this->method('Service intern', StockHoldingIntradayDataReloader::class, 'fetchIntradayRecords'),
                    $this->method('Repair service', StockHistoricalIntradayCandleRepairService::class, 'repair'),
                    $this->method('On-demand service', EodhdMarketData::class, 'ensureIntradaySamples'),
                    $this->method('EODHD client', EodhdApiClient::class, 'get'),
                ],
                'tables' => ['stock_holding_intraday_candles'],
                'note' => 'Mehrere Laravel-Pfade nutzen denselben EODHD-Endpunkt, unterscheiden sich aber bei Umfang und Ausführung.',
            ],
            [
                'key' => 'index-realtime',
                'title' => 'Index-Livekurse',
                'access' => 'real-time/{index-symbol}',
                'description' => 'Aktualisiert den Live-Wert beobachteter Indizes und den Tagesdatensatz des jeweiligen Index.',
                'triggers' => [
                    'Scheduler every minute: indices:v2-realtime:dispatch-due',
                    'HTTP POST /admin/data/indices/sync',
                    'HTTP POST /admin/index-watch-items',
                    'HTTP POST /admin/index-watch-items/{indexWatchItem}/prices/ensure',
                ],
                'execution' => $this->execution(
                    'mixed',
                    true,
                    'default',
                    'Der V2-Zeitplan stellt Live-Aktualisierungen in die Standard-Queue; manuelle, Add- und Ensure-Aktionen laufen synchron.',
                ),
                'laravel_methods' => [
                    $this->method('Command', DispatchDueV2IndexRealtimeSyncs::class, 'handle'),
                    $this->method('Job', SyncV2IndexRealtimeData::class, 'handle'),
                    $this->method('Service', V2IndexRealtimeScheduler::class, 'dispatchDue'),
                    $this->method('Controller', AdminDataController::class, 'syncIndices'),
                    $this->method('Controller', AdminIndexWatchItemController::class, 'store'),
                    $this->method('Controller', AdminIndexWatchItemController::class, 'ensurePrices'),
                    $this->method('Service', IndexWatchItemPriceRefresher::class, 'refreshAll'),
                    $this->method('Service', IndexWatchItemPriceRefresher::class, 'refresh'),
                    $this->method('Service', IndexWatchItemPriceRefresher::class, 'ensureRecentPrices'),
                    $this->method('EODHD client', EodhdApiClient::class, 'get'),
                ],
                'tables' => ['index_watch_items', 'index_watch_item_prices', 'index_watch_item_realtime_prices'],
                'note' => 'Der automatische Index-Live-Job pausiert, solange eine V2-EOD-/Intraday-Synchronisierung läuft.',
            ],
            [
                'key' => 'index-historical',
                'title' => 'Historische Indexkurse',
                'access' => 'eod/{index-symbol}',
                'description' => 'Lädt die jüngsten historischen Tageskurse für alle beobachteten Indizes.',
                'triggers' => [
                    'Scheduler jede Minute: indices-data:dispatch-due',
                    'HTTP POST /admin/data/indices/historical/sync',
                    'HTTP POST /admin/index-watch-items/{indexWatchItem}/prices/ensure',
                ],
                'execution' => $this->execution(
                    'synchronous',
                    true,
                    null,
                    'Der wöchentliche Scheduler und die manuellen Aktionen rufen den Import synchron auf.',
                ),
                'laravel_methods' => [
                    $this->method('Console command', DispatchDueIndexDataUpdates::class, 'handle'),
                    $this->method('Scheduler', IndexDataUpdateScheduler::class, 'dispatchDue'),
                    $this->method('Scheduler', IndexDataUpdateScheduler::class, 'dispatchNow'),
                    $this->method('Controller', AdminDataController::class, 'syncIndexHistorical'),
                    $this->method('Controller', AdminIndexWatchItemController::class, 'ensurePrices'),
                    $this->method('Service', IndexWatchItemPriceRefresher::class, 'syncHistoricalDailyPricesForAll'),
                    $this->method('Service intern', IndexWatchItemPriceRefresher::class, 'storeHistoricalDailyPrices'),
                    $this->method('EODHD client', EodhdApiClient::class, 'get'),
                ],
                'tables' => ['index_watch_item_prices', 'index_watch_items'],
                'note' => 'Der Scheduler wird jede Minute geprüft, der eigentliche Import ist standardmäßig wöchentlich fällig.',
            ],
        ];
    }

    /**
     * @return array{mode: string, scheduled: bool, queue: ?string, description: string}
     */
    private function execution(string $mode, bool $scheduled, ?string $queue, string $description): array
    {
        return [
            'mode' => $mode,
            'scheduled' => $scheduled,
            'queue' => $queue,
            'description' => $description,
        ];
    }

    /**
     * @param  class-string  $class
     * @return array{layer: string, class: class-string, method: string}
     */
    private function method(string $layer, string $class, string $method): array
    {
        return [
            'layer' => $layer,
            'class' => $class,
            'method' => $method,
        ];
    }

    private function queueName(): string
    {
        $connection = (string) config('queue.default');

        return (string) config("queue.connections.{$connection}.queue", 'default');
    }
}
