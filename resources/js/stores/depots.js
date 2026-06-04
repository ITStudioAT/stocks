import { defineStore } from 'pinia';
import { request } from './auth';

export const useDepotStore = defineStore('depots', {
    state: () => ({
        activeDepot: null,
        depots: [],
        holdings: [],
        transactions: [],
        exchangeTradingTimes: [],
        priceRefresh: null,
        priceRefreshSettings: null,
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
        stockSearchLoading: false,
        error: '',
        holdingsError: '',
        transactionsError: '',
        exchangeTradingTimesError: '',
        stockSearchError: '',
    }),
    actions: {
        async loadActiveDepot() {
            this.loading = true;
            this.error = '';

            try {
                const data = await request('/admin/depots/active');
                this.activeDepot = data.depot;
                this.priceRefreshSettings = data.price_refresh_settings;

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
        async loadWatchlistExchangeTradingTimes() {
            this.exchangeTradingTimesLoading = true;
            this.exchangeTradingTimesError = '';

            try {
                const data = await request('/admin/watchlist/exchange-trading-times');
                this.exchangeTradingTimes = data.exchange_trading_times ?? [];

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
                return await request('/admin/watchlist/holdings', {
                    method: 'POST',
                    body: JSON.stringify(payload),
                });
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
                this.priceRefreshSettings = {
                    ...(this.priceRefreshSettings ?? {}),
                    status: 'updating',
                    status_label: 'Updating prices',
                };

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
        async updatePriceRefreshSettings(payload) {
            this.holdingsError = '';

            try {
                const data = await request('/admin/price-refresh-settings', {
                    method: 'PATCH',
                    body: JSON.stringify(payload),
                });
                this.priceRefreshSettings = data.price_refresh_settings;
                this.priceRefresh = data.refresh ?? this.priceRefresh;

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
                this.priceRefresh = data.refresh;

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
                this.transactions = data.transactions;
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
                this.transactions = [data.transaction, ...this.transactions];

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            } finally {
                this.holdingsLoading = false;
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
