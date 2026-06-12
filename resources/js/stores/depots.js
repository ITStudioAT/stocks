import { defineStore } from 'pinia';
import { request } from './auth';

export const useDepotStore = defineStore('depots', {
    state: () => ({
        activeDepot: null,
        depots: [],
        holdings: [],
        indexWatchItems: [],
        depotHoldings: [],
        transactions: [],
        exchangeTradingTimes: [],
        appVersion: null,
        eodhdApiUsage: null,
        priceRefresh: null,
        priceRefreshSettings: null,
        indexPriceRefreshSettings: null,
        intradayBackfillSettings: null,
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
        dataExchanges: [],
        dataExchangeRefresh: null,
        dataIntradayStocks: [],
        dataIntradaySelectedStockId: null,
        dataIntradayDays: [],
        dataIntradayRefresh: null,
        stockHistoricalPriceCoverage: null,
        stockHistoricalPriceRefresh: null,
        analyzeIntradayCandles: null,
        analyzeIntradayCandlesRequestedId: null,
        holdingIntradayCandles: {},
        holdingIntradayCandlesLoading: {},
        holdingIntradayCandlesErrors: {},
        uiPreferences: {
            depot_price_source: 'latest',
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
        exchangeTradingTimesLoading: false,
        queueStatusLoading: false,
        testOptionsLoading: false,
        testTickersLoading: false,
        testExchangesLoading: false,
        dataExchangesLoading: false,
        dataExchangeReloadLoading: false,
        dataIntradayLoading: false,
        dataIntradayReloadLoading: false,
        stockSearchLoading: false,
        analyzeIntradayCandlesLoading: false,
        error: '',
        holdingsError: '',
        transactionsError: '',
        exchangeTradingTimesError: '',
        queueStatusError: '',
        testOptionsError: '',
        testTickersError: '',
        testExchangesError: '',
        dataExchangesError: '',
        dataIntradayError: '',
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

            if (!isSilent) {
                this.holdingsLoading = true;
            }

            this.holdingsError = '';

            try {
                const data = await request(`/admin/watchlist/holdings?page=${page}`);
                this.activeDepot = data.depot ?? this.activeDepot;
                this.priceRefreshSettings = data.price_refresh_settings;
                this.indexPriceRefreshSettings = data.index_price_refresh_settings ?? this.indexPriceRefreshSettings;
                this.intradayBackfillSettings = data.intraday_backfill_settings ?? this.intradayBackfillSettings;
                this.intradayBackfillRefresh = data.intraday_backfill_refresh ?? this.intradayBackfillRefresh;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;
                this.uiPreferences = data.ui_preferences ?? this.uiPreferences;
                this.holdings = data.holdings;
                this.holdingsPagination = data.meta;
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
                const data = await request('/admin/tests/tickers');
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
        async loadTestExchanges() {
            this.testExchangesLoading = true;
            this.testExchangesError = '';

            try {
                const data = await request('/admin/tests/exchanges');
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
        async ensureIndexWatchItemPrices(id) {
            this.holdingsError = '';

            try {
                const data = await request(`/admin/index-watch-items/${id}/prices/ensure`, {
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
        async ensureStockHistoricalPrices() {
            this.holdingsError = '';

            try {
                const data = await request('/admin/watchlist/holdings/historical-prices/ensure', {
                    method: 'POST',
                });
                this.stockHistoricalPriceCoverage = data.coverage;
                this.stockHistoricalPriceRefresh = data.refresh;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            }
        },
        async loadStockHistoricalPriceRefresh(refreshId) {
            this.holdingsError = '';

            try {
                const data = await request(`/admin/watchlist/holdings/historical-prices/${refreshId}`);
                this.stockHistoricalPriceCoverage = data.coverage;
                this.stockHistoricalPriceRefresh = data.refresh;
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            }
        },
        clearStockHistoricalPriceRefresh() {
            this.stockHistoricalPriceRefresh = null;
        },
        async loadHoldingIntradayCandles(id) {
            this.analyzeIntradayCandlesRequestedId = id;
            this.analyzeIntradayCandlesLoading = true;
            this.analyzeIntradayCandlesError = '';

            try {
                const data = await request(`/admin/watchlist/holdings/${id}/intraday-candles`);

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
                const data = await request(`/admin/watchlist/holdings/${id}/intraday-candles`);

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
                this.transactions = data.transactions;
                this.uiPreferences = data.ui_preferences ?? this.uiPreferences;
            } catch (error) {
                this.transactionsError = error.message;
                throw error;
            } finally {
                this.transactionsLoading = false;
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
                this.depotHoldings = data.depot_holdings ?? this.depotHoldings;
                this.transactions = [data.transaction, ...this.transactions];

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            } finally {
                this.holdingsLoading = false;
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
                const data = await request(`/admin/stocks/search?query=${encodeURIComponent(query)}`);
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
