import { defineStore } from 'pinia';
import { request } from './auth';

export const useDepotStore = defineStore('depots', {
    state: () => ({
        activeDepot: null,
        depots: [],
        holdings: [],
        indexWatchItems: [],
        indexEodhdSync: null,
        stockEodhdSync: null,
        indexEodhdSyncSettings: null,
        depotHoldings: [],
        depotValuations: {},
        depotPerformanceSeries: [],
        dashboardDailyPerformance: null,
        dashboardPerformanceSums: [],
        depotStockPeriodStocks: [],
        depotStockPeriod: null,
        depotStockPeriodLabel: '',
        depotStockPeriodYear: null,
        transactions: [],
        exchangeTradingTimes: [],
        appVersion: null,
        eodhdApiUsage: null,
        priceRefresh: null,
        priceRefreshSettings: null,
        indexPriceRefreshSettings: null,
        intradayBackfillSettings: null,
        endOfDayDataUpdateSettings: null,
        indexDataUpdateSettings: null,
        intradayBackfillRefresh: null,
        queueStatus: null,
        testOptions: {
            indices: [],
            stocks: [],
        },
        testTickers: [],
        testTickerExchangeCode: 'XETRA',
        testExchanges: [],
        testExchangeDetails: {},
        testExchangeDetailErrors: {},
        testIntraday: null,
        dataExchanges: [],
        dataExchangeRefresh: null,
        dataIntradayStocks: [],
        dataIntradaySelectedStockId: null,
        dataIntradayDays: [],
        dataIntradayRefresh: null,
        dataRepairSummary: null,
        stockHistoricalPriceCoverage: null,
        analyzeIntradayCandles: null,
        analyzeIntradayCandlesRequestedId: null,
        holdingIntradayCandles: {},
        holdingIntradayCandlesLoading: {},
        holdingIntradayCandlesErrors: {},
        uiPreferences: {
            depot_price_source: 'latest',
            analyze_trend_row_limit: 200,
            analyze_trend_excluded_holding_ids: [],
            analyze_trend_trade_amounts: [7000, 5000, 3000],
            analyze_trend_max_invest_amount: 0,
            analyze_trend_virtual_buy_amount: 7000,
            analyze_trend_streak_buy_thresholds: [-4, -3, -2, -1, 0],
            analyze_trend_streak_sell_threshold: 3,
        },
        stockSearchResults: [],
        pagination: {
            current_page: 1,
            last_page: 1,
            per_page: 10,
            total: 0,
            from: null,
            to: null,
        },
        holdingsPagination: {
            current_page: 1,
            last_page: 1,
            per_page: 10,
            total: 0,
            from: null,
            to: null,
        },
        loading: false,
        holdingsLoading: false,
        transactionsLoading: false,
        dashboardDailyPerformanceLoading: false,
        depotStockPeriodLoading: false,
        exchangeTradingTimesLoading: false,
        queueStatusLoading: false,
        testOptionsLoading: false,
        testTickersLoading: false,
        testExchangesLoading: false,
        testIntradayLoading: false,
        dataExchangesLoading: false,
        dataExchangeReloadLoading: false,
        dataIntradayLoading: false,
        dataIntradayReloadLoading: false,
        dataRepairLoading: false,
        stockSearchLoading: false,
        analyzeIntradayCandlesLoading: false,
        error: '',
        holdingsError: '',
        transactionsError: '',
        dashboardDailyPerformanceError: '',
        depotStockPeriodError: '',
        exchangeTradingTimesError: '',
        queueStatusError: '',
        testOptionsError: '',
        testTickersError: '',
        testExchangesError: '',
        testIntradayError: '',
        dataExchangesError: '',
        dataIntradayError: '',
        dataRepairError: '',
        stockSearchError: '',
        analyzeIntradayCandlesError: '',
    }),
    actions: {
        async loadActiveDepot() {
            this.loading = true;
            this.error = '';

            try {
                const data = await request('/admin/depots/active');
                this.activeDepot = data.depot;
                this.appVersion = data.app_version ?? this.appVersion;
                this.priceRefreshSettings = data.price_refresh_settings;
                this.indexPriceRefreshSettings = data.index_price_refresh_settings ?? this.indexPriceRefreshSettings;
                this.intradayBackfillSettings = data.intraday_backfill_settings ?? this.intradayBackfillSettings;
                this.endOfDayDataUpdateSettings = data.end_of_day_data_update_settings ?? this.endOfDayDataUpdateSettings;
                this.indexDataUpdateSettings = data.index_data_update_settings ?? this.indexDataUpdateSettings;
                this.intradayBackfillRefresh = data.intraday_backfill_refresh ?? this.intradayBackfillRefresh;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;
                this.uiPreferences = data.ui_preferences ?? this.uiPreferences;

            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },
        async loadDashboardDailyPerformance() {
            this.dashboardDailyPerformanceLoading = true;
            this.dashboardDailyPerformanceError = '';

            try {
                const data = await request('/admin/dashboard/performance');
                this.dashboardDailyPerformance = data.days ?? [];
                this.dashboardPerformanceSums = data.sums ?? [];

                return data;
            } catch (error) {
                this.dashboardDailyPerformanceError = error.message;
                throw error;
            } finally {
                this.dashboardDailyPerformanceLoading = false;
            }
        },
        async loadDepots(page = 1) {
            this.loading = true;
            this.error = '';

            try {
                const data = await request(`/admin/depots?page=${page}`);
                this.depots = data.depots;
                this.pagination = data.meta;
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },
        async loadWatchlistHoldings(page = 1, options = {}) {
            const isSilent = options.silent === true;
            const query = new URLSearchParams({
                page: String(page),
            });

            if (options.includeCharts === true) {
                query.set('include_charts', '1');
            }

            if (options.allChartHoldings === true) {
                query.set('all_chart_holdings', '1');
            }

            if (options.allHoldings === true) {
                query.set('all', '1');
            }

            if (Number.isInteger(options.chartStockId) && options.chartStockId > 0) {
                query.set('chart_stock_id', String(options.chartStockId));
            }

            if (typeof options.chartRange === 'string' && options.chartRange !== '') {
                query.set('chart_range', options.chartRange);
            }

            if (!isSilent) {
                this.holdingsLoading = true;
            }

            this.holdingsError = '';

            try {
                const endpoint = options.includeCharts === true
                    ? '/admin/watchlist/holdings/charts'
                    : '/admin/watchlist/holdings';
                const requestOptions = options.includeCharts === true
                    ? { method: 'POST' }
                    : {};
                const data = await request(`${endpoint}?${query.toString()}`, requestOptions);
                this.activeDepot = data.depot ?? this.activeDepot;
                this.priceRefreshSettings = data.price_refresh_settings;
                this.indexPriceRefreshSettings = data.index_price_refresh_settings ?? this.indexPriceRefreshSettings;
                this.intradayBackfillSettings = data.intraday_backfill_settings ?? this.intradayBackfillSettings;
                this.endOfDayDataUpdateSettings = data.end_of_day_data_update_settings ?? this.endOfDayDataUpdateSettings;
                this.indexDataUpdateSettings = data.index_data_update_settings ?? this.indexDataUpdateSettings;
                this.intradayBackfillRefresh = data.intraday_backfill_refresh ?? this.intradayBackfillRefresh;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;
                this.uiPreferences = data.ui_preferences ?? this.uiPreferences;
                this.holdings = data.holdings ?? [];
                this.holdingsPagination = data.meta ?? this.holdingsPagination;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            } finally {
                if (!isSilent) {
                    this.holdingsLoading = false;
                }
            }
        },
        async loadActiveDepotHoldings(page = 1) {
            return await this.loadWatchlistHoldings(page);
        },
        async loadQueueStatus() {
            this.queueStatusLoading = true;
            this.queueStatusError = '';

            try {
                const data = await request('/admin/queue/status');
                this.queueStatus = data.queue ?? null;

                return data;
            } catch (error) {
                this.queueStatusError = error.message;
                throw error;
            } finally {
                this.queueStatusLoading = false;
            }
        },
        async clearQueue() {
            this.queueStatusLoading = true;
            this.queueStatusError = '';

            try {
                const data = await request('/admin/queue/clear', {
                    method: 'POST',
                });
                this.queueStatus = data.queue ?? null;

                return data;
            } catch (error) {
                this.queueStatusError = error.message;
                throw error;
            } finally {
                this.queueStatusLoading = false;
            }
        },
        async loadTestOptions() {
            this.testOptionsLoading = true;
            this.testOptionsError = '';

            try {
                const data = await request('/admin/tests/options');
                this.testOptions = {
                    indices: data.indices ?? [],
                    stocks: data.stocks ?? [],
                };

                return data;
            } catch (error) {
                this.testOptionsError = error.message;
                throw error;
            } finally {
                this.testOptionsLoading = false;
            }
        },
        async loadTestTickers() {
            this.testTickersLoading = true;
            this.testTickersError = '';

            try {
                const data = await request('/admin/tests/tickers', {
                    method: 'POST',
                });
                this.testTickerExchangeCode = data.exchange_code ?? 'XETRA';
                this.testTickers = data.tickers ?? [];
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.testTickersError = error.message;
                throw error;
            } finally {
                this.testTickersLoading = false;
            }
        },
        async loadTestIntraday(stockId) {
            this.testIntradayLoading = true;
            this.testIntradayError = '';

            try {
                const data = await request(`/admin/tests/stocks/${stockId}/intraday`, {
                    method: 'POST',
                });
                this.testIntraday = data;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.testIntradayError = error.message;
                throw error;
            } finally {
                this.testIntradayLoading = false;
            }
        },
        async loadTestExchanges() {
            this.testExchangesLoading = true;
            this.testExchangesError = '';

            try {
                const data = await request('/admin/tests/exchanges', {
                    method: 'POST',
                });
                this.testExchanges = data.exchanges ?? [];
                this.testExchangeDetails = data.exchange_details ?? {};
                this.testExchangeDetailErrors = data.exchange_detail_errors ?? {};
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.testExchangesError = error.message;
                throw error;
            } finally {
                this.testExchangesLoading = false;
            }
        },
        async loadDataExchanges() {
            this.dataExchangesLoading = true;
            this.dataExchangesError = '';

            try {
                const data = await request('/admin/data/exchanges');
                this.dataExchanges = data.exchanges ?? [];
                this.dataExchangeRefresh = data.refresh ?? this.dataExchangeRefresh;
                this.indexDataUpdateSettings = data.index_data_update_settings ?? this.indexDataUpdateSettings;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.dataExchangesError = error.message;
                throw error;
            } finally {
                this.dataExchangesLoading = false;
            }
        },
        async reloadDataExchanges() {
            this.dataExchangeReloadLoading = true;
            this.dataExchangesError = '';

            try {
                const data = await request('/admin/data/exchanges/reload', {
                    method: 'POST',
                });
                this.dataExchanges = data.exchanges ?? this.dataExchanges;
                this.dataExchangeRefresh = data.refresh ?? null;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.dataExchangesError = error.message;
                throw error;
            } finally {
                this.dataExchangeReloadLoading = false;
            }
        },
        async loadDataExchangeRefresh(refreshId) {
            this.dataExchangesError = '';

            try {
                const data = await request(`/admin/data/exchanges/reload/${refreshId}`);
                this.dataExchanges = data.exchanges ?? this.dataExchanges;
                this.dataExchangeRefresh = data.refresh ?? null;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.dataExchangesError = error.message;
                throw error;
            }
        },
        async loadDataIntraday(stockId = null) {
            this.dataIntradayLoading = true;
            this.dataIntradayError = '';

            try {
                const query = stockId ? `?stock=${encodeURIComponent(String(stockId))}` : '';
                const data = await request(`/admin/data/intraday${query}`);
                this.dataIntradayStocks = data.stocks ?? [];
                this.dataIntradaySelectedStockId = data.selected_stock_id ?? null;
                this.dataIntradayDays = data.days ?? [];
                this.dataIntradayRefresh = data.refresh ?? null;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.dataIntradayError = error.message;
                throw error;
            } finally {
                this.dataIntradayLoading = false;
            }
        },
        async reloadDataIntraday(stockId) {
            this.dataIntradayReloadLoading = true;
            this.dataIntradayError = '';

            try {
                const data = await request('/admin/data/intraday/reload', {
                    method: 'POST',
                    body: JSON.stringify({ selected_stock_id: stockId }),
                });
                this.dataIntradayStocks = data.stocks ?? this.dataIntradayStocks;
                this.dataIntradaySelectedStockId = data.selected_stock_id ?? stockId;
                this.dataIntradayDays = data.days ?? [];
                this.dataIntradayRefresh = data.refresh ?? null;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.dataIntradayError = error.message;
                throw error;
            } finally {
                this.dataIntradayReloadLoading = false;
            }
        },
        async syncDataRealtime() {
            this.holdingsError = '';

            try {
                const data = await request('/admin/data/realtime/sync', {
                    method: 'POST',
                });
                this.priceRefreshSettings = data.price_refresh_settings ?? this.priceRefreshSettings;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            }
        },
        async syncDataHistorical() {
            this.holdingsError = '';

            try {
                const data = await request('/admin/data/historical/sync', {
                    method: 'POST',
                });
                this.intradayBackfillSettings = data.intraday_backfill_settings ?? this.intradayBackfillSettings;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            }
        },
        async loadDataIntradayRefresh(refreshId, stockId = null) {
            this.dataIntradayError = '';

            try {
                const query = stockId ? `?stock=${encodeURIComponent(String(stockId))}` : '';
                const data = await request(`/admin/data/intraday/reload/${refreshId}${query}`);
                this.dataIntradayStocks = data.stocks ?? this.dataIntradayStocks;
                this.dataIntradaySelectedStockId = data.selected_stock_id ?? this.dataIntradaySelectedStockId;
                this.dataIntradayDays = data.days ?? this.dataIntradayDays;
                this.dataIntradayRefresh = data.refresh ?? null;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.dataIntradayError = error.message;
                throw error;
            }
        },
        async loadDataRepair() {
            this.dataRepairLoading = true;
            this.dataRepairError = '';

            try {
                const data = await request('/admin/data/repair');
                this.dataRepairSummary = data;

                return data;
            } catch (error) {
                this.dataRepairError = error.message;
                throw error;
            } finally {
                this.dataRepairLoading = false;
            }
        },
        async repairEndOfDayData() {
            this.dataRepairLoading = true;
            this.dataRepairError = '';

            try {
                const data = await request('/admin/data/repair/end-of-day', {
                    method: 'POST',
                });
                this.dataRepairSummary = {
                    ...(this.dataRepairSummary ?? {}),
                    ...(data.repair ?? {}),
                };
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.dataRepairError = error.message;
                throw error;
            } finally {
                this.dataRepairLoading = false;
            }
        },
        async repairEndOfDayStock(stockId) {
            this.dataRepairError = '';

            try {
                const data = await request(`/admin/data/repair/end-of-day/${stockId}`, {
                    method: 'POST',
                });
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.dataRepairError = error.message;
                throw error;
            }
        },
        async repairHistoricalData() {
            this.dataRepairLoading = true;
            this.dataRepairError = '';

            try {
                const data = await request('/admin/data/repair/historical-data', {
                    method: 'POST',
                });
                this.dataRepairSummary = {
                    ...(this.dataRepairSummary ?? {}),
                    ...(data.repair ?? {}),
                };
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.dataRepairError = error.message;
                throw error;
            } finally {
                this.dataRepairLoading = false;
            }
        },
        async repairHistoricalDataStock(stockId) {
            this.dataRepairError = '';

            try {
                const data = await request(`/admin/data/repair/historical-data/${stockId}`, {
                    method: 'POST',
                });
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.dataRepairError = error.message;
                throw error;
            }
        },
        async loadIndexWatchItems() {
            this.holdingsError = '';

            try {
                const data = await request('/admin/index-watch-items');
                this.indexWatchItems = data.indexes ?? [];

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            }
        },
        async loadWatchlistExchangeTradingTimes() {
            this.exchangeTradingTimesLoading = true;
            this.exchangeTradingTimesError = '';

            try {
                const data = await request('/admin/watchlist/exchange-trading-times');
                this.exchangeTradingTimes = data.exchange_trading_times ?? [];
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.exchangeTradingTimes = [];
                this.exchangeTradingTimesError = error.message;

                return { exchange_trading_times: [] };
            } finally {
                this.exchangeTradingTimesLoading = false;
            }
        },
        async loadActiveDepotExchangeTradingTimes() {
            return await this.loadWatchlistExchangeTradingTimes();
        },
        async createWatchlistHolding(payload) {
            this.holdingsLoading = true;
            this.holdingsError = '';

            try {
                const data = await request('/admin/watchlist/holdings', {
                    method: 'POST',
                    body: JSON.stringify(payload),
                });
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            } finally {
                this.holdingsLoading = false;
            }
        },
        async createActiveDepotHolding(payload) {
            return await this.createWatchlistHolding(payload);
        },
        async updateWatchlistHolding(id, payload) {
            this.holdingsLoading = true;
            this.holdingsError = '';

            try {
                const data = await request(`/admin/watchlist/holdings/${id}`, {
                    method: 'PATCH',
                    body: JSON.stringify(payload),
                });

                if (data.holding) {
                    this.holdings = this.holdings.map((holding) => holding.id === id ? data.holding : holding);
                    this.depotHoldings = this.depotHoldings.map((holding) => holding.id === id ? {
                        ...holding,
                        ...data.holding,
                    } : holding);
                }

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            } finally {
                this.holdingsLoading = false;
            }
        },
        async createIndexWatchItem(payload) {
            this.holdingsLoading = true;
            this.holdingsError = '';

            try {
                const data = await request('/admin/index-watch-items', {
                    method: 'POST',
                    body: JSON.stringify(payload),
                });
                this.indexWatchItems = [
                    ...this.indexWatchItems,
                    data.index,
                ].filter((item) => item);

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            } finally {
                this.holdingsLoading = false;
            }
        },
        async ensureIndexWatchItemPrices(id, range = null) {
            this.holdingsError = '';

            try {
                const query = range ? `?range=${encodeURIComponent(range)}` : '';
                const data = await request(`/admin/index-watch-items/${id}/prices/ensure${query}`, {
                    method: 'POST',
                });

                if (data.index) {
                    this.indexWatchItems = this.indexWatchItems.map((item) => {
                        if (item.id !== data.index.id) {
                            return item;
                        }

                        return data.index;
                    });
                }

                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            }
        },
        async deleteIndexWatchItem(id) {
            this.holdingsLoading = true;
            this.holdingsError = '';

            try {
                const data = await request(`/admin/index-watch-items/${id}`, {
                    method: 'DELETE',
                });
                this.indexWatchItems = this.indexWatchItems.filter((item) => item.id !== id);

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            } finally {
                this.holdingsLoading = false;
            }
        },
        async startIndexEodhdSync() {
            this.holdingsError = '';

            try {
                const data = await request('/admin/v2/indices/eodhd-sync', { method: 'POST' });
                this.indexEodhdSync = data.refresh ?? null;
                this.indexEodhdSyncSettings = data.index_eodhd_sync_settings ?? this.indexEodhdSyncSettings;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            }
        },
        async loadIndexEodhdSync(refreshId) {
            const data = await request(`/admin/v2/indices/eodhd-sync/${refreshId}`);
            this.indexEodhdSync = data.refresh ?? null;
            this.indexEodhdSyncSettings = data.index_eodhd_sync_settings ?? this.indexEodhdSyncSettings;
            this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

            return data;
        },
        clearIndexEodhdSync() {
            this.indexEodhdSync = null;
        },
        async loadLatestStockEodhdSync() {
            const data = await request('/admin/v2/stocks/eodhd-sync');
            this.stockEodhdSync = data.refresh ?? null;
            this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

            return data;
        },
        async startStockEodhdSync() {
            this.holdingsError = '';

            try {
                const data = await request('/admin/v2/stocks/eodhd-sync', { method: 'POST' });
                this.stockEodhdSync = data.refresh ?? null;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            }
        },
        async loadStockEodhdSync(refreshId) {
            const data = await request(`/admin/v2/stocks/eodhd-sync/${refreshId}`);
            this.stockEodhdSync = data.refresh ?? null;
            this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

            return data;
        },
        clearStockEodhdSync() {
            this.stockEodhdSync = null;
        },
        async loadIndexEodhdSyncSettings() {
            const data = await request('/admin/v2/indices/eodhd-sync-settings');
            this.indexEodhdSyncSettings = data.index_eodhd_sync_settings ?? null;

            return data;
        },
        async dispatchDueIndexRealtimeSync() {
            const data = await request('/admin/v2/indices/realtime-sync', {
                method: 'POST',
            });
            this.indexEodhdSyncSettings = data.index_eodhd_sync_settings ?? this.indexEodhdSyncSettings;

            return data;
        },
        async updateIndexEodhdSyncSettings(payload) {
            const data = await request('/admin/v2/indices/eodhd-sync-settings', {
                method: 'PATCH',
                body: JSON.stringify(payload),
            });
            this.indexEodhdSyncSettings = data.index_eodhd_sync_settings ?? this.indexEodhdSyncSettings;

            return data;
        },
        async deleteWatchlistHolding(id) {
            this.holdingsLoading = true;
            this.holdingsError = '';

            try {
                return await request(`/admin/watchlist/holdings/${id}`, {
                    method: 'DELETE',
                });
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            } finally {
                this.holdingsLoading = false;
            }
        },
        async deleteActiveDepotHolding(id) {
            return await this.deleteWatchlistHolding(id);
        },
        async updateHoldingFlatexPrice(id, flatexPrice) {
            this.transactionsError = '';

            try {
                const data = await request(`/admin/watchlist/holdings/${id}/flatex-price`, {
                    method: 'PATCH',
                    body: JSON.stringify({ flatex_price: flatexPrice }),
                });
                const updatedHoldings = data.holdings ?? [data.holding];
                const flatexPriceByHoldingId = new Map(
                    updatedHoldings.map((holding) => [holding.id, holding.flatex_price]),
                );

                this.depotHoldings = this.depotHoldings.map((holding) => {
                    if (!flatexPriceByHoldingId.has(holding.id)) {
                        return holding;
                    }

                    return {
                        ...holding,
                        flatex_price: flatexPriceByHoldingId.get(holding.id),
                    };
                });
                this.holdings = this.holdings.map((holding) => {
                    if (!flatexPriceByHoldingId.has(holding.id)) {
                        return holding;
                    }

                    return {
                        ...holding,
                        flatex_price: flatexPriceByHoldingId.get(holding.id),
                    };
                });
                await this.loadTransactions();

                return data;
            } catch (error) {
                this.transactionsError = error.message;
                throw error;
            }
        },
        async refreshWatchlistPrices() {
            this.holdingsLoading = true;
            this.holdingsError = '';

            try {
                const data = await request('/admin/watchlist/holdings/refresh-prices', {
                    method: 'POST',
                });
                this.priceRefresh = data.refresh;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;
                this.priceRefreshSettings = data.price_refresh_settings ?? {
                    ...(this.priceRefreshSettings ?? {}),
                    status: 'updating',
                    status_label: 'Updating prices',
                };
                this.indexPriceRefreshSettings = data.index_price_refresh_settings ?? this.indexPriceRefreshSettings;

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            } finally {
                this.holdingsLoading = false;
            }
        },
        async refreshActiveDepotHoldingPrices() {
            return await this.refreshWatchlistPrices();
        },
        async loadWatchlistPriceRefresh(refreshId) {
            this.holdingsError = '';

            try {
                const data = await request(`/admin/watchlist/holdings/refresh-prices/${refreshId}`);
                this.priceRefresh = data.refresh;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                if (error.status === 404 && error.message === 'Price refresh not found.') {
                    this.priceRefresh = null;

                    return {
                        message: '',
                        refresh: null,
                        stale: true,
                    };
                }

                this.holdingsError = error.message;
                throw error;
            }
        },
        async loadActiveDepotHoldingPriceRefresh(refreshId) {
            return await this.loadWatchlistPriceRefresh(refreshId);
        },
        clearPriceRefresh() {
            this.priceRefresh = null;
        },
        async loadStockHistoricalPriceCoverage() {
            this.holdingsError = '';

            try {
                const data = await request('/admin/watchlist/holdings/historical-prices/coverage');
                this.stockHistoricalPriceCoverage = data.coverage;
                this.indexDataUpdateSettings = data.index_data_update_settings ?? this.indexDataUpdateSettings;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            }
        },
        async ensureStockHistoricalPrices() {
            this.holdingsError = '';

            try {
                const data = await request('/admin/watchlist/holdings/historical-prices/ensure', {
                    method: 'POST',
                });
                this.stockHistoricalPriceCoverage = data.coverage;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            }
        },
        async loadHoldingIntradayCandles(id) {
            this.analyzeIntradayCandlesRequestedId = id;
            this.analyzeIntradayCandlesLoading = true;
            this.analyzeIntradayCandlesError = '';

            try {
                const data = await request(`/admin/watchlist/holdings/${id}/intraday-candles`, {
                    method: 'POST',
                });

                if (this.analyzeIntradayCandlesRequestedId === id) {
                    this.analyzeIntradayCandles = data;
                    this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;
                }

                return data;
            } catch (error) {
                if (this.analyzeIntradayCandlesRequestedId === id) {
                    this.analyzeIntradayCandlesError = error.message;
                }

                throw error;
            } finally {
                if (this.analyzeIntradayCandlesRequestedId === id) {
                    this.analyzeIntradayCandlesLoading = false;
                }
            }
        },
        async loadExpandedHoldingIntradayCandles(id) {
            this.holdingIntradayCandlesLoading = {
                ...this.holdingIntradayCandlesLoading,
                [id]: true,
            };
            this.holdingIntradayCandlesErrors = {
                ...this.holdingIntradayCandlesErrors,
                [id]: '',
            };

            try {
                const data = await request(`/admin/watchlist/holdings/${id}/intraday-candles`, {
                    method: 'POST',
                });

                this.holdingIntradayCandles = {
                    ...this.holdingIntradayCandles,
                    [id]: data,
                };
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.holdingIntradayCandlesErrors = {
                    ...this.holdingIntradayCandlesErrors,
                    [id]: error.message,
                };

                throw error;
            } finally {
                this.holdingIntradayCandlesLoading = {
                    ...this.holdingIntradayCandlesLoading,
                    [id]: false,
                };
            }
        },
        clearHoldingIntradayCandles() {
            this.analyzeIntradayCandlesRequestedId = null;
            this.analyzeIntradayCandles = null;
            this.analyzeIntradayCandlesError = '';
        },
        async updatePriceRefreshSettings(payload) {
            this.holdingsError = '';

            try {
                const data = await request('/admin/price-refresh-settings', {
                    method: 'PATCH',
                    body: JSON.stringify(payload),
                });
                this.priceRefreshSettings = data.price_refresh_settings;
                this.indexPriceRefreshSettings = data.index_price_refresh_settings ?? this.indexPriceRefreshSettings;
                this.intradayBackfillSettings = data.intraday_backfill_settings ?? this.intradayBackfillSettings;
                this.endOfDayDataUpdateSettings = data.end_of_day_data_update_settings ?? this.endOfDayDataUpdateSettings;
                this.indexDataUpdateSettings = data.index_data_update_settings ?? this.indexDataUpdateSettings;
                this.priceRefresh = data.refresh ?? this.priceRefresh;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            }
        },
        async loadPriceRefreshSettings() {
            this.holdingsError = '';

            try {
                const data = await request('/admin/price-refresh-settings');
                this.priceRefreshSettings = data.price_refresh_settings;
                this.indexPriceRefreshSettings = data.index_price_refresh_settings ?? this.indexPriceRefreshSettings;
                this.intradayBackfillSettings = data.intraday_backfill_settings ?? this.intradayBackfillSettings;
                this.endOfDayDataUpdateSettings = data.end_of_day_data_update_settings ?? this.endOfDayDataUpdateSettings;
                this.indexDataUpdateSettings = data.index_data_update_settings ?? this.indexDataUpdateSettings;
                this.intradayBackfillRefresh = data.intraday_backfill_refresh ?? this.intradayBackfillRefresh;
                this.priceRefresh = data.refresh;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            }
        },
        async updateIndexPriceRefreshSettings(payload) {
            this.holdingsError = '';

            try {
                const data = await request('/admin/index-price-refresh-settings', {
                    method: 'PATCH',
                    body: JSON.stringify(payload),
                });
                this.indexPriceRefreshSettings = data.index_price_refresh_settings;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            }
        },
        async updateIntradayBackfillSettings(payload) {
            this.holdingsError = '';

            try {
                const data = await request('/admin/intraday-backfill-settings', {
                    method: 'PATCH',
                    body: JSON.stringify(payload),
                });
                this.intradayBackfillSettings = data.intraday_backfill_settings;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            }
        },
        async updateEndOfDayDataUpdateSettings(payload) {
            this.holdingsError = '';

            try {
                const data = await request('/admin/end-of-day-data-update-settings', {
                    method: 'PATCH',
                    body: JSON.stringify(payload),
                });
                this.endOfDayDataUpdateSettings = data.end_of_day_data_update_settings;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            }
        },
        async updateIndexDataUpdateSettings(payload) {
            this.holdingsError = '';

            try {
                const data = await request('/admin/index-data-update-settings', {
                    method: 'PATCH',
                    body: JSON.stringify(payload),
                });
                this.indexDataUpdateSettings = data.index_data_update_settings;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            }
        },
        async syncDataEndOfDay() {
            this.holdingsError = '';

            try {
                const data = await request('/admin/data/end-of-day/sync', {
                    method: 'POST',
                });
                this.endOfDayDataUpdateSettings = data.end_of_day_data_update_settings ?? this.endOfDayDataUpdateSettings;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            }
        },
        async syncDataIndices() {
            this.holdingsError = '';

            try {
                const data = await request('/admin/data/indices/sync', {
                    method: 'POST',
                });
                this.indexPriceRefreshSettings = data.index_price_refresh_settings ?? this.indexPriceRefreshSettings;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;
                await this.loadIndexWatchItems();

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            }
        },
        async syncDataIndexHistorical() {
            this.holdingsError = '';

            try {
                const data = await request('/admin/data/indices/historical/sync', {
                    method: 'POST',
                });
                this.indexDataUpdateSettings = data.index_data_update_settings ?? this.indexDataUpdateSettings;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;
                await this.loadIndexWatchItems();

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            }
        },
        async runIntradayBackfillNow() {
            this.holdingsError = '';

            try {
                const data = await request('/admin/intraday-backfill/run', {
                    method: 'POST',
                });
                this.intradayBackfillSettings = data.intraday_backfill_settings ?? this.intradayBackfillSettings;
                this.intradayBackfillRefresh = data.intraday_backfill_refresh ?? null;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            }
        },
        async loadIntradayBackfillRefresh(refreshId) {
            this.holdingsError = '';

            try {
                const data = await request(`/admin/intraday-backfill/${refreshId}`);
                this.intradayBackfillSettings = data.intraday_backfill_settings ?? this.intradayBackfillSettings;
                this.intradayBackfillRefresh = data.intraday_backfill_refresh ?? null;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            }
        },
        async loadTransactions() {
            this.transactionsLoading = true;
            this.transactionsError = '';

            try {
                const data = await request('/admin/depot-transactions');
                this.depotHoldings = data.depot_holdings ?? [];
                this.depotValuations = data.depot_valuations ?? {};
                this.depotPerformanceSeries = data.depot_performance_series ?? [];
                this.transactions = data.transactions;
                this.uiPreferences = data.ui_preferences ?? this.uiPreferences;
            } catch (error) {
                this.transactionsError = error.message;
                throw error;
            } finally {
                this.transactionsLoading = false;
            }
        },
        async loadDepotStockPeriod(period) {
            this.depotStockPeriodLoading = true;
            this.depotStockPeriodError = '';

            try {
                const data = await request(`/admin/depot-stocks/${encodeURIComponent(period)}`);
                this.depotStockPeriodStocks = data.stocks ?? [];
                this.depotStockPeriod = data.period ?? period;
                this.depotStockPeriodLabel = data.label ?? '';
                this.depotStockPeriodYear = data.year ?? null;

                return data;
            } catch (error) {
                this.depotStockPeriodError = error.message;
                throw error;
            } finally {
                this.depotStockPeriodLoading = false;
            }
        },
        async bookCashTransaction(payload) {
            this.holdingsLoading = true;
            this.holdingsError = '';

            try {
                const data = await request('/admin/depot-transactions/cash', {
                    method: 'POST',
                    body: JSON.stringify(payload),
                });
                this.activeDepot = data.depot;
                await this.loadTransactions();

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            } finally {
                this.holdingsLoading = false;
            }
        },
        async updateTransactionDate(id, bookedAt) {
            this.transactionsLoading = true;
            this.transactionsError = '';

            try {
                const data = await request(`/admin/depot-transactions/${id}/date`, {
                    method: 'PATCH',
                    body: JSON.stringify({ booked_at: bookedAt }),
                });
                this.depotHoldings = data.depot_holdings ?? this.depotHoldings;
                this.depotValuations = data.depot_valuations ?? this.depotValuations;
                this.depotPerformanceSeries = data.depot_performance_series ?? this.depotPerformanceSeries;
                this.transactions = data.transactions ?? this.transactions.map((transaction) => {
                    return transaction.id === id ? data.transaction : transaction;
                });

                return data;
            } catch (error) {
                this.transactionsError = error.message;
                throw error;
            } finally {
                this.transactionsLoading = false;
            }
        },
        async updateUiPreferences(payload) {
            this.error = '';

            try {
                const data = await request('/admin/ui-preferences', {
                    method: 'PATCH',
                    body: JSON.stringify(payload),
                });
                this.uiPreferences = data.ui_preferences;

                return data;
            } catch (error) {
                this.error = error.message;
                throw error;
            }
        },
        async bookStockTransaction(payload) {
            this.holdingsLoading = true;
            this.holdingsError = '';

            try {
                const data = await request('/admin/depot-transactions/stocks', {
                    method: 'POST',
                    body: JSON.stringify(payload),
                });
                this.activeDepot = data.depot;
                this.depotHoldings = data.depot_holdings ?? this.depotHoldings;
                this.depotValuations = data.depot_valuations ?? this.depotValuations;
                this.depotPerformanceSeries = data.depot_performance_series ?? this.depotPerformanceSeries;
                this.transactions = [data.transaction, ...this.transactions];

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            } finally {
                this.holdingsLoading = false;
            }
        },
        async searchStocks(query) {
            this.stockSearchLoading = true;
            this.stockSearchError = '';

            try {
                const data = await request('/admin/stocks/search', {
                    method: 'POST',
                    body: JSON.stringify({ query }),
                });
                this.stockSearchResults = data.results;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;
            } catch (error) {
                this.stockSearchError = error.message;
                throw error;
            } finally {
                this.stockSearchLoading = false;
            }
        },
        async createDepot(payload) {
            this.loading = true;
            this.error = '';

            try {
                return await request('/admin/depots', {
                    method: 'POST',
                    body: JSON.stringify(payload),
                });
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },
        async updateDepot(id, payload) {
            this.loading = true;
            this.error = '';

            try {
                return await request(`/admin/depots/${id}`, {
                    method: 'PATCH',
                    body: JSON.stringify(payload),
                });
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },
        async activateDepot(id) {
            this.loading = true;
            this.error = '';

            try {
                return await request(`/admin/depots/${id}/activate`, {
                    method: 'PATCH',
                });
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },
    },
});
