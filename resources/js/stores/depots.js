import { acceptHMRUpdate, defineStore } from 'pinia';
import { request } from './auth';

async function waitForIntradayRefresh(id, kind, isCurrent = () => true) {
    for (let attempt = 0; attempt < 150; attempt += 1) {
        await new Promise(resolve => window.setTimeout(resolve, 2000));
        if (!isCurrent()) {
            return false;
        }
        const data = await request(`/admin/watchlist/holdings/${id}/intraday-refresh/${kind}`);
        if (data.refresh?.status === 'failed' || data.refresh?.status === 'unavailable') {
            throw new Error(data.refresh.error || 'Intraday update failed.');
        }
        if (!['queued', 'running'].includes(data.refresh?.status)) {
            if (data.refresh?.status !== 'finished') {
                throw new Error('Intraday update expired. Please try again.');
            }
            return true;
        }
    }

    throw new Error('Intraday update is still pending. Please try again later.');
}

export const useDepotStore = defineStore('depots', {
    state: () => ({
        activeDepot: null,
        depots: [],
        holdings: [],
        holdingsRequestId: 0,
        watchlistStockChart: null,
        intradayRequestVersion: 0,
        indexWatchItems: [],
        indexEodhdSync: null,
        indexEodhdSyncSettings: null,
        depotHoldings: [],
        depotValuations: {},
        depotPerformanceSeries: [],
        dashboardDailyPerformance: null,
        dashboardPerformanceSums: [],
        dashboardPerformanceRequestId: 0,
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
        holdingIntradayCandles: {},
        holdingIntradayCandlesLoading: {},
        holdingIntradayCandlesErrors: {},
        uiPreferences: {
            depot_price_source: 'latest',
        },
        stockSearchResults: [],
        stockSearchSource: 'free',
        stockSearchNotice: '',
        stockSearchCatalogDate: null,
        stockSearchStatus: '',
        stockSearchRequestId: 0,
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
        stockSearchLoading: false,
        error: '',
        holdingsError: '',
        transactionsError: '',
        dashboardDailyPerformanceError: '',
        depotStockPeriodError: '',
        exchangeTradingTimesError: '',
        queueStatusError: '',
        stockSearchError: '',
    }),
    actions: {
        async loadActiveDepot() {
            this.loading = true;
            this.error = '';

            try {
                const data = await request('/admin/depots/active');
                this.activeDepot = data.depot;
                this.appVersion = data.app_version ?? this.appVersion;
                this.priceRefreshSettings = data.price_refresh_settings ?? this.priceRefreshSettings;
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
            const requestId = ++this.dashboardPerformanceRequestId;
            this.dashboardDailyPerformanceLoading = true;
            this.dashboardDailyPerformanceError = '';

            try {
                const data = await request('/admin/dashboard/performance');
                if (requestId === this.dashboardPerformanceRequestId) {
                    this.dashboardDailyPerformance = data.days ?? [];
                    this.dashboardPerformanceSums = data.sums ?? [];
                }

                return data;
            } catch (error) {
                if (requestId === this.dashboardPerformanceRequestId) {
                    this.dashboardDailyPerformanceError = error.message;
                }
                throw error;
            } finally {
                if (requestId === this.dashboardPerformanceRequestId) {
                    this.dashboardDailyPerformanceLoading = false;
                }
            }
        },
        async repairDashboardPerformance(target, onProgress) {
            const path = '/admin/dashboard/performance/repair';
            let result = await request(path, { method: 'POST', body: JSON.stringify(target) });
            const statusTimeout = 10 * 60 * 1000;
            const maxTimerDelay = 2 ** 31 - 1;
            let statusDeadline = Date.now() + statusTimeout;
            while (['queued', 'running', 'waiting'].includes(result.status)) {
                const waiting = result.status === 'waiting';
                const now = Date.now();
                const retryAt = waiting ? Date.parse(result.retry_at) : NaN;
                if (retryAt > now) {
                    statusDeadline = Math.max(statusDeadline, retryAt + statusTimeout);
                }
                if (!result.run_id || now >= statusDeadline) {
                    throw new Error('Die Reparatur ist noch nicht abgeschlossen.');
                }
                onProgress?.(waiting ? result.message : 'Reparatur läuft …');
                const nextCheckIn = retryAt > now ? retryAt - now : (waiting ? 5000 : 2000);
                await new Promise(resolve => window.setTimeout(resolve, Math.min(nextCheckIn, maxTimerDelay)));
                result = await request(`${path}/${encodeURIComponent(result.run_id)}`);
            }

            if (Array.isArray(result.days) && Array.isArray(result.sums)) {
                this.dashboardPerformanceRequestId += 1;
                this.dashboardDailyPerformance = result.days;
                this.dashboardPerformanceSums = result.sums;
                this.dashboardDailyPerformanceLoading = false;
                this.dashboardDailyPerformanceError = '';
            }

            return result;
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
            const requestId = ++this.holdingsRequestId;
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

            if (Number.isInteger(options.historyRowLimit) && options.historyRowLimit > 0) {
                query.set('history_row_limit', String(Math.min(options.historyRowLimit, 1000)));
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
                    ? { method: 'POST', deduplicate: true }
                    : {};
                const data = await request(`${endpoint}?${query.toString()}`, requestOptions);
                if (requestId !== this.holdingsRequestId) {
                    return;
                }
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
                if (options.captureStockChart) {
                    this.watchlistStockChart = {
                        stockId: options.chartStockId,
                        range: options.chartRange,
                        holding: this.holdings.find((holding) => holding.id === options.chartStockId) ?? null,
                    };
                }
                this.holdingsPagination = data.meta ?? this.holdingsPagination;
                const pendingHoldings = this.holdings.filter(holding => ['queued', 'running'].includes(holding.intraday_refresh?.status));
                if (pendingHoldings.length > 0) {
                    const isCurrent = () => requestId === this.holdingsRequestId;
                    Promise.all(pendingHoldings.map(holding => waitForIntradayRefresh(holding.id, 'samples', isCurrent)))
                        .then(() => {
                            if (isCurrent()) {
                                return this.loadWatchlistHoldings(page, { ...options, silent: true });
                            }
                        })
                        .catch(error => {
                            if (isCurrent()) {
                                this.holdingsError = error.message;
                                this.holdings = this.holdings.map(holding => ({ ...holding, historical_prices_fetching: false }));
                            }
                        });
                }
                const failedRefresh = this.holdings.find(holding => holding.intraday_refresh?.error);
                if (failedRefresh) {
                    this.holdingsError = failedRefresh.intraday_refresh.error;
                }
            } catch (error) {
                if (requestId === this.holdingsRequestId) {
                    this.holdingsError = error.message;
                }
                throw error;
            } finally {
                if (requestId === this.holdingsRequestId) {
                    this.holdingsLoading = false;
                }
            }
        },
        cancelIntradayRefreshes() {
            this.intradayRequestVersion += 1;
            this.holdingsRequestId += 1;
            this.holdingsLoading = false;
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
                }

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            } finally {
                this.holdingsLoading = false;
            }
        },
        async loadHoldingDailyHistory(id) {
            const data = await request(`/admin/watchlist/holdings/${id}/daily-history`, { method: 'POST' });
            if (['complete', 'partial'].includes(data.status)) {
                await this.loadWatchlistHoldings(1, { allHoldings: true, includeCharts: true, chartStockId: id, chartRange: 'year' });
            }
            return data;
        },
        async reloadHoldingPrice(id) {
            const data = await request(`/admin/watchlist/holdings/${id}/price/reload`, { method: 'POST' });
            if (data.holding) {
                this.holdings = this.holdings.map(holding => holding.id === id ? { ...holding, ...data.holding } : holding);
            }

            return data;
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
            const requestVersion = this.intradayRequestVersion;

            try {
                const query = range ? `?range=${encodeURIComponent(range)}` : '';
                let data = await request(`/admin/index-watch-items/${id}/prices/ensure${query}`, {
                    method: 'POST', deduplicate: true,
                });
                for (let attempt = 0; ['queued', 'running'].includes(data.refresh?.status); attempt += 1) {
                    if (attempt >= 150) {
                        throw new Error('Index update is still pending. Please try again later.');
                    }
                    await new Promise(resolve => window.setTimeout(resolve, 2000));
                    if (requestVersion !== this.intradayRequestVersion) {
                        return data;
                    }
                    data = await request(`/admin/index-watch-items/${id}/prices/refresh${query}`);
                    if (data.refresh?.status === 'idle') {
                        throw new Error('Index update expired. Please try again.');
                    }
                }

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
        async loadExpandedHoldingIntradayCandles(id) {
            const requestVersion = this.intradayRequestVersion;
            this.holdingIntradayCandlesLoading = {
                ...this.holdingIntradayCandlesLoading,
                [id]: true,
            };
            this.holdingIntradayCandlesErrors = {
                ...this.holdingIntradayCandlesErrors,
                [id]: '',
            };

            try {
                let data = await request(`/admin/watchlist/holdings/${id}/intraday-candles`, {
                    method: 'POST', deduplicate: true,
                });
                this.holdingIntradayCandles = { ...this.holdingIntradayCandles, [id]: data };
                if (['queued', 'running'].includes(data.refresh?.status)) {
                    const completed = await waitForIntradayRefresh(id, 'detail', () => this.intradayRequestVersion === requestVersion);
                    if (!completed) {
                        return data;
                    }
                    data = await request(`/admin/watchlist/holdings/${id}/intraday-candles`, { method: 'POST', deduplicate: true });
                }
                if (data.refresh?.error) {
                    throw new Error(data.refresh.error);
                }

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
        async updateCashLedgerTransaction(id, payload) {
            this.transactionsLoading = true;
            this.transactionsError = '';

            try {
                const data = await request(`/admin/depot-transactions/${id}`, {
                    method: 'PATCH',
                    body: JSON.stringify(payload),
                });
                this.activeDepot = data.depot ?? this.activeDepot;
                this.depotHoldings = data.depot_holdings ?? this.depotHoldings;
                this.depotValuations = data.depot_valuations ?? this.depotValuations;
                this.depotPerformanceSeries = data.depot_performance_series ?? this.depotPerformanceSeries;
                this.transactions = data.transactions ?? this.transactions.map((transaction) => {
                    return transaction.id === id ? data.transaction : transaction;
                });

                return data;
            } finally {
                this.transactionsLoading = false;
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
            const requestId = ++this.stockSearchRequestId;
            const source = this.stockSearchSource;
            this.stockSearchLoading = true;
            this.stockSearchError = '';
            this.stockSearchNotice = '';
            this.stockSearchCatalogDate = null;
            this.stockSearchStatus = '';
            this.stockSearchResults = [];

            try {
                const data = await request('/admin/stocks/search', {
                    method: 'POST',
                    body: JSON.stringify({ query, source }),
                });
                if (requestId !== this.stockSearchRequestId || source !== this.stockSearchSource) {
                    return;
                }
                this.stockSearchResults = data.results;
                this.stockSearchNotice = data.message ?? '';
                this.stockSearchCatalogDate = data.catalog_as_of ?? null;
                this.stockSearchStatus = data.status ?? '';
                this.eodhdApiUsage = data.eodhd_api_usage ?? this.eodhdApiUsage;
            } catch (error) {
                if (requestId !== this.stockSearchRequestId || source !== this.stockSearchSource) {
                    return;
                }
                this.stockSearchError = error.message;
                throw error;
            } finally {
                if (requestId === this.stockSearchRequestId) {
                    this.stockSearchLoading = false;
                }
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

if (import.meta.hot) {
    import.meta.hot.accept(acceptHMRUpdate(useDepotStore, import.meta.hot));
}
