<?php

namespace App\Services;

use App\Models\EodhdExchange;
use App\Models\IndexWatchItem;
use App\Models\IndexWatchItemPrice;
use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockPrice;
use App\Models\StockRealtimePrice;
use Illuminate\Support\Facades\Schema;

class DatabaseTableInformation
{
    public function __construct(
        private PriceRefreshScheduler $priceRefreshScheduler,
        private V2IndexRealtimeScheduler $indexPriceRefreshSettings,
        private IntradayCandleBackfillScheduler $intradayCandleBackfillScheduler,
        private EndOfDayDataUpdateScheduler $endOfDayDataUpdateScheduler,
        private IndexDataUpdateScheduler $indexDataUpdateScheduler,
    ) {}

    /**
     * @return array<int, array{
     *     name: string,
     *     category: string,
     *     purpose: string,
     *     purpose_de: string,
     *     eodhd: array{mode: string, endpoint: string, access: string, documentation: array<int, array{label: string, url: string}>},
     *     cadence: string,
     *     queue: array{used: bool, name: ?string, job: ?string, mode: string},
     * }>
     */
    public function tables(): array
    {
        return collect($this->catalog())
            ->filter(fn (array $information, string $tableName): bool => Schema::hasTable($tableName))
            ->sortKeys()
            ->map(fn (array $information, string $tableName): array => [
                'name' => $tableName,
                ...$information,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, array{
     *     category: string,
     *     purpose: string,
     *     purpose_de: string,
     *     eodhd: array{mode: string, endpoint: string, access: string, documentation: array<int, array{label: string, url: string}>},
     *     cadence: string,
     *     queue: array{used: bool, name: ?string, job: ?string, mode: string},
     * }>
     */
    private function catalog(): array
    {
        $priceRefresh = $this->priceRefreshScheduler->payload();
        $indexPriceRefresh = $this->indexPriceRefreshSettings->payload();
        $intradayBackfill = $this->intradayCandleBackfillScheduler->payload();
        $endOfDayUpdate = $this->endOfDayDataUpdateScheduler->payload();
        $indexDataUpdate = $this->indexDataUpdateScheduler->payload();

        $queuedPriceRefresh = $this->queued(
            'App\\Jobs\\RefreshDepotHoldingPrices',
            'Automatic refreshes use the default queue; the Data-page manual realtime sync runs synchronously.',
        );
        $queuedIntradayRefresh = $this->queued(
            'App\\Jobs\\BackfillMissingStockHoldingIntradayCandles / App\\Jobs\\ReloadStockHoldingIntradayData',
            'Automatic backfills and manual reloads use the default queue; latest-missing historical sync runs synchronously.',
        );
        $queuedExchangeReload = $this->queued(
            'App\\Jobs\\ReloadEodhdExchanges',
            'Manual exchange reloads run on the default queue.',
        );
        $queuedIndexRealtimeRefresh = $this->queued(
            'App\\Jobs\\SyncV2IndexRealtimeData',
            'Automatic V2 realtime refreshes use the default queue; manual/add/ensure actions run synchronously.',
        );
        $synchronous = $this->synchronous();

        $priceCadence = $this->marketCadence($priceRefresh);
        $configuredIndexPriceCadence = $this->marketCadence($indexPriceRefresh);
        $intradayCadence = $this->dailyRetryCadence($intradayBackfill);
        $endOfDayCadence = $this->dailyRetryCadence($endOfDayUpdate);
        $indexHistoricalCadence = "{$indexDataUpdate['weekday_label']} at {$indexDataUpdate['daily_time']} ({$indexDataUpdate['timezone']})";

        return [
            (new EodhdExchange)->getTable() => $this->direct(
                'Market reference data',
                'Exchanges, trading hours, holidays, currencies, and MIC codes used by stocks and indices.',
                'Börsen, Handelszeiten, Feiertage, Währungen und MIC-Codes für Aktien und Indizes.',
                'exchanges-list/ + v2/exchange-details/{code}',
                [
                    ['label' => 'Exchange list', 'url' => 'https://eodhd.com/financial-apis/exchanges-api-list-of-tickers-and-trading-hours'],
                    ['label' => 'Exchange details', 'url' => 'https://eodhd.com/financial-apis/exchanges-api-trading-hours-and-stock-market-holidays'],
                ],
                'Manual reload from Data → Exchanges.',
                $queuedExchangeReload,
            ),
            (new IndexWatchItemPrice)->getTable() => $this->direct(
                'Index historical data',
                'Historical daily prices for watched indices.',
                'Historische Tageskurse für beobachtete Indizes.',
                'eod/{index-symbol}',
                [
                    ['label' => 'EOD prices', 'url' => 'https://eodhd.com/financial-apis/api-for-historical-data-and-volumes'],
                    ['label' => 'Live prices', 'url' => 'https://eodhd.com/financial-apis/live-ohlcv-stocks-api'],
                ],
                "Historical update: {$indexHistoricalCadence}; realtime values also update during manual/add/ensure actions.",
                $synchronous,
            ),
            (new IndexWatchItem)->getTable() => $this->direct(
                'Index live data',
                'Watched index metadata and latest live values.',
                'Metadaten und aktuelle Live-Werte für beobachtete Indizes.',
                'search/{query}, exchange-symbol-list/INDX, real-time/{index-symbol}',
                [
                    ['label' => 'Symbol search', 'url' => 'https://eodhd.com/financial-apis/search-api-for-stocks-etfs-mutual-funds'],
                    ['label' => 'Index symbol list', 'url' => 'https://eodhd.com/financial-apis/covered-tickers-eodhd'],
                    ['label' => 'Live prices', 'url' => 'https://eodhd.com/financial-apis/live-ohlcv-stocks-api'],
                ],
                "Automatic V2 live cadence: {$configuredIndexPriceCadence}; manual add/sync/ensure is also available.",
                $queuedIndexRealtimeRefresh,
            ),
            (new StockHoldingDailyPrice)->getTable() => $this->direct(
                'Stock historical data',
                'Normalized daily OHLC prices per stock holding.',
                'Normalisierte tägliche OHLC-Kurse je Aktienposition.',
                'eod/{symbol.exchange}',
                [
                    ['label' => 'EOD prices', 'url' => 'https://eodhd.com/financial-apis/api-for-historical-data-and-volumes'],
                ],
                'On demand when historical coverage is requested; no automatic schedule.',
                $this->synchronous('Synchronous request-driven historical fetch.'),
            ),
            (new StockHoldingIntradayCandle)->getTable() => $this->direct(
                'Stock historical data',
                'Five-minute and historical intraday candles per stock holding.',
                'Fünf-Minuten- und historische Intraday-Kerzen je Aktienposition.',
                'intraday/{symbol.exchange}',
                [
                    ['label' => 'Intraday prices', 'url' => 'https://eodhd.com/financial-apis/intraday-historical-data-api'],
                ],
                $intradayCadence,
                $queuedIntradayRefresh,
            ),
            (new StockHolding)->getTable() => $this->direct(
                'Stock master data',
                'Tracked stocks, identifiers, venues, and latest selected price references.',
                'Verfolgte Aktien, Kennungen, Handelsplätze und zuletzt ausgewählte Kursreferenzen.',
                'search/{query} and stored results from downstream EODHD price APIs',
                [
                    ['label' => 'Symbol search', 'url' => 'https://eodhd.com/financial-apis/search-api-for-stocks-etfs-mutual-funds'],
                    ['label' => 'EOD prices', 'url' => 'https://eodhd.com/financial-apis/api-for-historical-data-and-volumes'],
                    ['label' => 'Live prices', 'url' => 'https://eodhd.com/financial-apis/live-ohlcv-stocks-api'],
                ],
                "Metadata when added; price references: {$priceCadence}.",
                $queuedPriceRefresh,
            ),
            (new StockPrice)->getTable() => $this->direct(
                'Stock end-of-day data',
                'Canonical historical and end-of-day stock prices.',
                'Kanonische historische Aktienkurse und Schlusskurse.',
                'eod/{symbol.exchange}',
                [
                    ['label' => 'EOD prices', 'url' => 'https://eodhd.com/financial-apis/api-for-historical-data-and-volumes'],
                ],
                $endOfDayCadence,
                $synchronous,
            ),
            (new StockRealtimePrice)->getTable() => $this->direct(
                'Stock live data',
                'Canonical live stock prices and raw EODHD payloads.',
                'Kanonische Live-Aktienkurse und unverarbeitete EODHD-Antworten.',
                'real-time/{symbol} with additional symbols batched by the s parameter',
                [
                    ['label' => 'Live prices', 'url' => 'https://eodhd.com/financial-apis/live-ohlcv-stocks-api'],
                ],
                $priceCadence,
                $queuedPriceRefresh,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function marketCadence(array $settings): string
    {
        $closedCadence = $settings['closed_refresh_enabled']
            ? "every {$settings['closed_interval_minutes']} min when closed"
            : 'disabled when closed';

        return "Every {$settings['trading_interval_minutes']} min during trading; {$closedCadence}";
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function dailyRetryCadence(array $settings): string
    {
        return "Weekdays at {$settings['daily_time']} ({$settings['timezone']}); retry every {$settings['interval_minutes']} min while data is missing";
    }

    /**
     * @return array{used: bool, name: string, job: string, mode: string}
     */
    private function queued(string $job, string $mode): array
    {
        $connection = (string) config('queue.default');

        return [
            'used' => true,
            'name' => (string) config("queue.connections.{$connection}.queue", 'default'),
            'job' => $job,
            'mode' => $mode,
        ];
    }

    /**
     * @return array{used: bool, name: null, job: null, mode: string}
     */
    private function synchronous(string $mode = 'Synchronous scheduled command or manual action.'): array
    {
        return [
            'used' => false,
            'name' => null,
            'job' => null,
            'mode' => $mode,
        ];
    }

    /**
     * @param  array{used: bool, name: ?string, job: ?string, mode: string}  $queue
     * @param  array<int, array{label: string, url: string}>  $documentation
     * @return array{category: string, purpose: string, purpose_de: string, eodhd: array{mode: string, endpoint: string, access: string, documentation: array<int, array{label: string, url: string}>}, cadence: string, queue: array{used: bool, name: ?string, job: ?string, mode: string}}
     */
    private function direct(string $category, string $purpose, string $purposeGerman, string $endpoint, array $documentation, string $cadence, array $queue): array
    {
        return [
            'category' => $category,
            'purpose' => $purpose,
            'purpose_de' => $purposeGerman,
            'eodhd' => [
                'mode' => 'direct',
                'endpoint' => $endpoint,
                'access' => 'This table is populated directly from EODHD responses.',
                'documentation' => $documentation,
            ],
            'cadence' => $cadence,
            'queue' => $queue,
        ];
    }
}
