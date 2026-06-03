import { defineStore } from 'pinia';
import { request } from './auth';

export const useDepotStore = defineStore('depots', {
    state: () => ({
        activeDepot: null,
        depots: [],
        holdings: [],
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
        stockSearchLoading: false,
        error: '',
        holdingsError: '',
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

                if (!this.activeDepot) {
                    this.holdings = [];
                    this.holdingsPagination = {
                        current_page: 1,
                        last_page: 1,
                        per_page: 10,
                        total: 0,
                        from: null,
                        to: null,
                    };
                }
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
        async loadActiveDepotHoldings(page = 1) {
            this.holdingsLoading = true;
            this.holdingsError = '';

            try {
                const data = await request(`/admin/active-depot/holdings?page=${page}`);
                this.activeDepot = data.depot;
                this.priceRefreshSettings = data.price_refresh_settings;
                this.holdings = data.holdings;
                this.holdingsPagination = data.meta;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            } finally {
                this.holdingsLoading = false;
            }
        },
        async createActiveDepotHolding(payload) {
            this.holdingsLoading = true;
            this.holdingsError = '';

            try {
                return await request('/admin/active-depot/holdings', {
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
        async deleteActiveDepotHolding(id) {
            this.holdingsLoading = true;
            this.holdingsError = '';

            try {
                return await request(`/admin/active-depot/holdings/${id}`, {
                    method: 'DELETE',
                });
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            } finally {
                this.holdingsLoading = false;
            }
        },
        async refreshActiveDepotHoldingPrices() {
            this.holdingsLoading = true;
            this.holdingsError = '';

            try {
                const data = await request('/admin/active-depot/holdings/refresh-prices', {
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
        async loadActiveDepotHoldingPriceRefresh(refreshId) {
            this.holdingsError = '';

            try {
                const data = await request(`/admin/active-depot/holdings/refresh-prices/${refreshId}`);
                this.priceRefresh = data.refresh;

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
            }
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

                return data;
            } catch (error) {
                this.holdingsError = error.message;
                throw error;
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
