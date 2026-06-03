import { flushPromises, mount } from '@vue/test-utils';
import { createPinia } from 'pinia';
import { describe, expect, it, vi } from 'vitest';
import App from '../../../resources/js/App.vue';
import { createStocksVuetify } from '../../../resources/js/plugins/vuetify';

function mountApp() {
    return mount(App, {
        attachTo: document.body,
        global: {
            plugins: [
                createPinia(),
                createStocksVuetify(),
            ],
        },
    });
}

function jsonResponse(data) {
    return {
        ok: true,
        json: () => Promise.resolve(data),
    };
}

function priceRefreshSettings(overrides = {}) {
    return {
        trading_interval_minutes: 20,
        closed_interval_minutes: 60,
        last_refreshed_at: '2026-06-02T12:20:00+00:00',
        next_refresh_at: '2026-06-02T12:40:00+00:00',
        status: 'waiting',
        status_label: 'waiting',
        is_trading_time: true,
        current_interval_minutes: 20,
        ...overrides,
    };
}

describe('App', () => {
    it('renders the admin login screen without loading authenticated data', () => {
        window.history.pushState({}, '', '/admin/login');
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();

        expect(wrapper.text()).toContain('Stocks admin');
        expect(wrapper.text()).toContain('Sign in to manage your workspace.');
        expect(wrapper.text()).toContain('Password');
        expect(wrapper.text()).toContain('Code');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('renders the depots menu section with paginated depot data', async () => {
        window.history.pushState({}, '', '/admin/menu/depots');
        const fetchMock = vi.fn((path) => {
            if (path === '/admin/me') {
                return Promise.resolve(jsonResponse({
                    user: {
                        id: 1,
                        name: 'Admin User',
                        email: 'admin@example.com',
                        roles: ['admin'],
                    },
                }));
            }

            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({
                    depots: [
                        {
                            id: 1,
                            name: 'Long term depot',
                            account_balance: '12345.67',
                            is_active: true,
                        },
                        {
                            id: 2,
                            name: 'Trading depot',
                            account_balance: '250.50',
                            is_active: false,
                        },
                    ],
                    meta: {
                        current_page: 1,
                        last_page: 2,
                        per_page: 10,
                        total: 11,
                        from: 1,
                        to: 10,
                    },
                }));
            }

            if (path === '/admin/depots/active') {
                return Promise.resolve(jsonResponse({
                    depot: {
                        id: 1,
                        name: 'Long term depot',
                        account_balance: '12345.67',
                        is_active: true,
                    },
                    price_refresh_settings: priceRefreshSettings(),
                }));
            }

            if (path === '/admin/active-depot/holdings?page=1') {
                return Promise.resolve(jsonResponse({
                    depot: {
                        id: 1,
                        name: 'Long term depot',
                        account_balance: '12345.67',
                        is_active: true,
                    },
                    holdings: [
                        {
                            id: 1,
                            symbol: 'AAPL',
                            name: 'Apple',
                            isin: 'US0378331005',
                            wkn: '865985',
                            exchange: 'NASDAQ',
                            currency: 'USD',
                            latest_price: '306.320010',
                            latest_price_status: 'fresh',
                            price_status: 'fresh',
                            latest_price_fetched_at: '2026-06-02T12:00:00+00:00',
                            latest_price_source: 'Tradegate Exchange',
                            latest_price_source_url: 'https://www.tradegatebsx.com/orderbuch.php?isin=US0378331005',
                            latest_price_as_of: '2026-06-02 13:59:00',
                            trading_times: 'Monday-Friday 08:00-22:00 Europe/Berlin',
                            venue: 'Tradegate',
                            price_type: 'indicative_mid',
                            price_spread_pct: '0.050000',
                            validation_errors: [],
                        },
                        {
                            id: 2,
                            symbol: 'EXXX',
                            name: 'iShares ATX UCITS ETF (DE)',
                            isin: 'DE000A0D8Q23',
                            wkn: 'A0D8Q2',
                            exchange: 'XETR',
                            currency: 'EUR',
                            latest_price: null,
                            latest_price_status: 'missing',
                            price_status: 'unavailable_now',
                            latest_price_fetched_at: null,
                            latest_price_source: null,
                            latest_price_source_url: null,
                            latest_price_as_of: null,
                            trading_times: null,
                            venue: null,
                            price_type: null,
                            price_spread_pct: null,
                            validation_errors: [],
                        },
                        {
                            id: 3,
                            symbol: 'LEER',
                            name: 'Amundi MSCI Eastern Europe Ex Russia UCITS ETF Acc',
                            isin: 'LU1681043912',
                            wkn: 'A2H58Q',
                            exchange: 'Borsa Italiana',
                            currency: 'EUR',
                            latest_price: null,
                            latest_price_status: 'stale',
                            price_status: 'stale',
                            latest_price_fetched_at: '2026-06-03T04:18:46+00:00',
                            latest_price_source: 'Borsa Italiana',
                            latest_price_source_url: 'https://www.borsaitaliana.it/example',
                            latest_price_as_of: null,
                            trading_times: 'Monday-Friday 09:00-17:30 Europe/Rome',
                            venue: null,
                            price_type: null,
                            price_spread_pct: null,
                            validation_errors: [],
                        },
                    ],
                    meta: {
                        current_page: 1,
                        last_page: 1,
                        per_page: 10,
                        total: 3,
                        from: 1,
                        to: 3,
                    },
                }));
            }

            if (path === '/admin/depots/2/activate') {
                return Promise.resolve(jsonResponse({
                    message: 'Depot activated.',
                    depot: {
                        id: 2,
                        name: 'Trading depot',
                        account_balance: '250.50',
                        is_active: true,
                    },
                }));
            }

            return Promise.reject(new Error(`Unexpected request: ${path}`));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();
        await flushPromises();

        expect(wrapper.text()).toContain('Depots');
        expect(wrapper.text()).toContain('New depot');
        expect(wrapper.text()).toContain('Long term depot');
        expect(wrapper.text()).toContain('12,345.67');
        expect(wrapper.text()).toContain('Active');
        expect(wrapper.text()).toContain('Inactive');
        expect(fetchMock).toHaveBeenCalledWith('/admin/depots?page=1', expect.any(Object));

        const newDepotButton = wrapper.findAll('button').find((button) => button.text().includes('New depot'));
        await newDepotButton.trigger('click');
        await flushPromises();

        expect(document.body.textContent).toContain('Create depot');

        const editButtons = wrapper.findAll('[aria-label="Edit depot"]');
        await editButtons[0].trigger('click');
        await flushPromises();

        expect(document.body.textContent).toContain('Edit depot');

        const activateButtons = wrapper.findAll('[aria-label="Make depot active"]');
        await activateButtons[1].trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/depots/2/activate', expect.objectContaining({
            method: 'PATCH',
        }));
    });

    it('shows only the active depot on the dashboard', async () => {
        window.history.pushState({}, '', '/admin/dashboard');
        const fetchMock = vi.fn((path) => {
            if (path === '/admin/me') {
                return Promise.resolve(jsonResponse({
                    user: {
                        id: 1,
                        name: 'Admin User',
                        email: 'admin@example.com',
                        roles: ['admin'],
                    },
                }));
            }

            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({
                    depots: [
                        {
                            id: 1,
                            name: 'Long term depot',
                            account_balance: '12345.67',
                            is_active: true,
                        },
                        {
                            id: 2,
                            name: 'Trading depot',
                            account_balance: '250.50',
                            is_active: false,
                        },
                    ],
                    meta: {
                        current_page: 1,
                        last_page: 1,
                        per_page: 10,
                        total: 2,
                        from: 1,
                        to: 2,
                    },
                }));
            }

            if (path === '/admin/depots/active') {
                return Promise.resolve(jsonResponse({
                    depot: {
                        id: 1,
                        name: 'Long term depot',
                        account_balance: '12345.67',
                        is_active: true,
                    },
                }));
            }

            if (path === '/admin/active-depot/holdings?page=1') {
                return Promise.resolve(jsonResponse({
                    depot: {
                        id: 1,
                        name: 'Long term depot',
                        account_balance: '12345.67',
                        is_active: true,
                    },
                    holdings: [
                        {
                            id: 1,
                            symbol: 'AAPL',
                            name: 'Apple',
                            isin: 'US0378331005',
                            wkn: '865985',
                            exchange: 'NASDAQ',
                            currency: 'EUR',
                            latest_price: '306.320010',
                            latest_price_status: 'fresh',
                            price_status: 'fresh',
                            latest_price_fetched_at: '2026-06-02T12:00:00+00:00',
                            latest_price_source: 'Tradegate Exchange',
                            latest_price_source_url: 'https://www.tradegatebsx.com/orderbuch.php?isin=US0378331005',
                            latest_price_as_of: '2026-06-02 13:59:00',
                            trading_times: 'Monday-Friday 08:00-22:00 Europe/Berlin',
                            venue: 'Tradegate',
                            price_type: 'indicative_mid',
                            price_spread_pct: '0.050000',
                            validation_errors: [],
                        },
                        {
                            id: 2,
                            symbol: 'EXXX',
                            name: 'iShares ATX UCITS ETF (DE)',
                            isin: 'DE000A0D8Q23',
                            wkn: 'A0D8Q2',
                            exchange: 'XETR',
                            currency: 'EUR',
                            latest_price: null,
                            latest_price_status: 'missing',
                            price_status: 'unavailable_now',
                            latest_price_fetched_at: null,
                            latest_price_source: null,
                            latest_price_source_url: null,
                            latest_price_as_of: null,
                            trading_times: null,
                            venue: null,
                            price_type: null,
                            price_spread_pct: null,
                            validation_errors: [],
                        },
                        {
                            id: 3,
                            symbol: 'LEER',
                            name: 'Amundi MSCI Eastern Europe Ex Russia UCITS ETF Acc',
                            isin: 'LU1681043912',
                            wkn: 'A2H58Q',
                            exchange: 'Borsa Italiana',
                            currency: 'EUR',
                            latest_price: null,
                            latest_price_status: 'stale',
                            price_status: 'stale',
                            latest_price_fetched_at: '2026-06-03T04:18:46+00:00',
                            latest_price_source: 'Borsa Italiana',
                            latest_price_source_url: 'https://www.borsaitaliana.it/example',
                            latest_price_as_of: null,
                            trading_times: 'Monday-Friday 09:00-17:30 Europe/Rome',
                            venue: null,
                            price_type: null,
                            price_spread_pct: null,
                            validation_errors: [],
                        },
                    ],
                    meta: {
                        current_page: 1,
                        last_page: 1,
                        per_page: 10,
                        total: 3,
                        from: 1,
                        to: 3,
                    },
                    price_refresh_settings: priceRefreshSettings(),
                }));
            }

            if (path === '/admin/price-refresh-settings') {
                return Promise.resolve(jsonResponse({
                    message: 'Price refresh schedule updated.',
                    price_refresh_settings: priceRefreshSettings({
                        trading_interval_minutes: 15,
                        closed_interval_minutes: 45,
                        next_refresh_at: '2026-06-02T12:35:00+00:00',
                        current_interval_minutes: 15,
                    }),
                }));
            }

            if (path === '/admin/active-depot/holdings') {
                return Promise.resolve(jsonResponse({
                    message: 'Stock added.',
                    holding: {
                        id: 2,
                        symbol: 'MSFT',
                        name: 'Microsoft',
                        exchange: 'NASDAQ',
                        currency: 'USD',
                        latest_price: '415.250000',
                        latest_price_status: 'fresh',
                        price_status: 'fresh',
                        latest_price_fetched_at: '2026-06-02T12:10:00+00:00',
                        latest_price_source: 'Tradegate Exchange',
                        latest_price_source_url: 'https://www.tradegatebsx.com/orderbuch.php?isin=US5949181045',
                        latest_price_as_of: '2026-06-02 14:09:00',
                        trading_times: 'Monday-Friday 08:00-22:00 Europe/Berlin',
                        venue: 'Tradegate',
                        price_type: 'indicative_mid',
                        price_spread_pct: '0.050000',
                        validation_errors: [],
                    },
                }));
            }

            if (path === '/admin/active-depot/holdings/refresh-prices') {
                return Promise.resolve(jsonResponse({
                    message: '2 stock prices queued for refresh.',
                    refresh: {
                        refresh_id: 'refresh-1',
                        status: 'queued',
                        processed: 0,
                        total: 2,
                        step: '0/2',
                        message: '2 stock prices queued for refresh.',
                        current: null,
                        started_at: '2026-06-02T12:15:00+00:00',
                        finished_at: null,
                        error: null,
                    },
                }));
            }

            if (path === '/admin/active-depot/holdings/refresh-prices/refresh-1') {
                return Promise.resolve(jsonResponse({
                    message: '2 stock prices refreshed.',
                    refresh: {
                        refresh_id: 'refresh-1',
                        status: 'running',
                        processed: 2,
                        total: 2,
                        step: '2/2',
                        message: '2 stock prices refreshed.',
                        current: null,
                        started_at: '2026-06-02T12:15:00+00:00',
                        finished_at: '2026-06-02T12:20:00+00:00',
                        error: null,
                    },
                }));
            }

            if (path === '/admin/active-depot/holdings/1') {
                return Promise.resolve(jsonResponse({
                    message: 'Stock deleted.',
                }));
            }

            if (path === '/admin/stocks/search?query=Microsoft') {
                return Promise.resolve(jsonResponse({
                    results: [
                        {
                            symbol: 'MSFT',
                            name: 'Microsoft Corporation',
                            isin: 'US5949181045',
                            exchange: 'NASDAQ',
                            mic_code: 'XNAS',
                            instrument_type: 'Common Stock',
                            country: 'United States',
                            currency: 'USD',
                        },
                    ],
                }));
            }

            return Promise.reject(new Error(`Unexpected request: ${path}`));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();
        await flushPromises();

        const dashboardHeaders = wrapper.findAll('thead th').map((header) => header.text());

        expect(dashboardHeaders).toEqual([
            'Symbol',
            'Name',
            'Latest price',
            'Source time',
            'Trading times',
            'Source',
            'Actions',
        ]);
        expect(wrapper.text()).toContain('Long term depot');
        expect(wrapper.text()).toContain('AAPL');
        expect(wrapper.text()).toContain('Apple');
        expect(wrapper.text()).toContain('US0378331005');
        expect(wrapper.text()).toContain('865985');
        expect(wrapper.text()).toContain('306.32001');
        expect(wrapper.text()).not.toContain('306.32001 EUR');
        expect(wrapper.text()).toContain('02.06.2026, 13:59');
        expect(wrapper.text()).toContain('Monday-Friday 08:00-22:00 Europe/Berlin');
        expect(wrapper.text()).toContain('Tradegate Exchange');
        expect(wrapper.text()).toContain('EXXX');
        expect(wrapper.text()).toContain('DE000A0D8Q23');
        expect(wrapper.text()).toContain('A0D8Q2');
        expect(wrapper.text()).toContain('LEER');
        expect(wrapper.text()).not.toContain('43.37 EUR');
        expect(wrapper.text()).not.toContain('Trading depot');
        expect(wrapper.text()).not.toContain('250.50');
        expect(wrapper.text()).toContain('Last: 02.06.2026, 14:20');
        expect(wrapper.text()).toContain('Next: 02.06.2026, 14:40');
        expect(wrapper.text()).toContain('waiting');
        expect(wrapper.text()).not.toContain('Automatic price refresh');

        wrapper.vm.navigateSection('dashboard-admin');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/dashboard-admin');
        expect(wrapper.text()).toContain('Dashboard Admin');
        expect(wrapper.text()).toContain('Automatic price refresh');
        expect(wrapper.text()).toContain('Current interval: 20 min');

        const scheduleInputs = wrapper.find('#price-refresh-schedule-form').findAll('input');
        await scheduleInputs[0].setValue('15');
        await scheduleInputs[1].setValue('45');
        await wrapper.find('#price-refresh-schedule-form').trigger('submit');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/price-refresh-settings', expect.objectContaining({
            method: 'PATCH',
            body: JSON.stringify({
                trading_interval_minutes: 15,
                closed_interval_minutes: 45,
            }),
        }));
        expect(wrapper.text()).toContain('Price refresh schedule updated.');
        expect(wrapper.text()).toContain('Current interval: 15 min');

        wrapper.vm.navigateSection('dashboard');
        await flushPromises();

        const refreshPricesButton = wrapper.findAll('button').find((button) => button.text().includes('Refresh prices'));
        await refreshPricesButton.trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/active-depot/holdings/refresh-prices', expect.objectContaining({
            method: 'POST',
        }));
        expect(fetchMock).toHaveBeenCalledWith('/admin/active-depot/holdings/refresh-prices/refresh-1', expect.any(Object));
        expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/active-depot/holdings?page=1')).toHaveLength(2);
        expect(wrapper.text()).not.toContain('2 stock prices refreshed.');
        expect(wrapper.text()).not.toContain('Price refresh: 2/2');

        wrapper.vm.holdingMessage = '2 stock prices refreshed.';
        wrapper.vm.priceRefresh = {
            refresh_id: 'refresh-1',
            status: 'running',
            processed: 2,
            total: 2,
            step: '2/2',
            message: '2 stock prices refreshed.',
            current: null,
            started_at: '2026-06-02T12:15:00+00:00',
            finished_at: '2026-06-02T12:20:00+00:00',
            error: null,
        };
        await flushPromises();

        expect(wrapper.text()).not.toContain('2 stock prices refreshed.');
        expect(wrapper.text()).not.toContain('Price refresh: 2/2');

        const addStockButton = wrapper.findAll('button').find((button) => button.text().includes('Add stock'));
        await addStockButton.trigger('click');
        await flushPromises();

        expect(document.body.textContent).toContain('Add stock');

        const holdingSearchInput = document.body.querySelector('#holding-search-form input');
        expect(document.activeElement).toBe(holdingSearchInput);

        holdingSearchInput.value = 'Microsoft';
        holdingSearchInput.dispatchEvent(new Event('input', { bubbles: true }));
        holdingSearchInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
        await flushPromises();

        expect(document.body.textContent).toContain('Microsoft Corporation');
        expect(document.body.textContent).toContain('US5949181045');
        expect(document.body.textContent).toContain('NASDAQ');

        const addButton = Array.from(document.body.querySelectorAll('button'))
            .filter((button) => button.textContent.trim() === 'Add')
            .at(-1);
        addButton.click();
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/active-depot/holdings', expect.objectContaining({
            method: 'POST',
            body: JSON.stringify({
                symbol: 'MSFT',
                name: 'Microsoft Corporation',
                isin: 'US5949181045',
                exchange: 'NASDAQ',
                mic_code: 'XNAS',
                instrument_type: 'Common Stock',
                country: 'United States',
                currency: 'USD',
            }),
        }));

        await addStockButton.trigger('click');
        await flushPromises();

        expect(wrapper.vm.isHoldingDialogOpen).toBe(true);

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
        await flushPromises();

        expect(wrapper.vm.isHoldingDialogOpen).toBe(false);

        const deleteStockButton = wrapper.find('[aria-label="Delete stock"]');
        await deleteStockButton.trigger('click');
        await flushPromises();

        expect(document.body.textContent).toContain('Confirm delete');
        expect(document.body.textContent).toContain('Delete Apple?');

        const deleteButton = Array.from(document.body.querySelectorAll('button'))
            .filter((button) => button.textContent.trim() === 'Confirm')
            .at(-1);
        deleteButton.click();
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/active-depot/holdings/1', expect.objectContaining({
            method: 'DELETE',
        }));
    });

    it('shows Admin group with horizontal Users and Roles submenu chips for super_admin', async () => {
        window.history.pushState({}, '', '/admin/menu/users');
        const fetchMock = vi.fn((path) => {
            if (path === '/admin/me') {
                return Promise.resolve(jsonResponse({
                    user: {
                        id: 1,
                        name: 'Super Admin',
                        email: 'super@example.com',
                        roles: ['super_admin'],
                    },
                }));
            }

            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({ depots: [], meta: { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null } }));
            }

            if (path === '/admin/depots/active') {
                return Promise.resolve(jsonResponse({ depot: null }));
            }

            if (path === '/admin/users?page=1') {
                return Promise.resolve(jsonResponse({
                    users: [{ id: 1, name: 'Alice Smith', email: 'alice@example.com', roles: ['admin'] }],
                    roles: ['admin', 'super_admin'],
                    meta: { current_page: 1, last_page: 1, per_page: 10, total: 1, from: 1, to: 1 },
                }));
            }

            if (path === '/admin/roles?page=1') {
                return Promise.resolve(jsonResponse({
                    roles: [{ id: 1, name: 'admin', users_count: 2 }],
                    meta: { current_page: 1, last_page: 1, per_page: 10, total: 1, from: 1, to: 1 },
                }));
            }

            return Promise.reject(new Error(`Unexpected request: ${path}`));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();
        await flushPromises();

        expect(wrapper.text()).toContain('Admin');
        expect(wrapper.text()).toContain('Users');
        expect(wrapper.text()).toContain('Roles');

        const tabs = wrapper.findAll('.v-tab');
        const tabLabels = tabs.map((t) => t.text());
        expect(tabLabels.some((l) => l.includes('Users'))).toBe(true);
        expect(tabLabels.some((l) => l.includes('Roles'))).toBe(true);

        const rolesTab = tabs.find((t) => t.text().includes('Roles'));
        await rolesTab.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/roles');
        expect(wrapper.text()).toContain('admin');
    });
});
