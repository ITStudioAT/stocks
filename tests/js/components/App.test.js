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
        trading_starts_before_minutes: 5,
        trading_ends_after_minutes: 10,
        closed_refresh_enabled: true,
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

function sessionHeaderDate(daysAgo) {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Europe/Vienna',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(new Date());
    const dateParts = Object.fromEntries(parts.map((part) => [part.type, part.value]));
    const viennaDate = new Date(Date.UTC(
        Number(dateParts.year),
        Number(dateParts.month) - 1,
        Number(dateParts.day) - daysAgo,
    ));

    return new Intl.DateTimeFormat('de-AT', {
        timeZone: 'UTC',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(viennaDate);
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

            if (path === '/admin/watchlist/holdings?page=1') {
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
                            latest_price_as_of: '2026-06-03T15:35:00+00:00',
                            trading_times: 'Monday-Friday 08:00-22:00 Europe/Berlin',
                            venue: 'Tradegate',
                            price_type: 'indicative_mid',
                            price_spread_pct: '0.050000',
                            recent_prices: [
                                {
                                    id: 10,
                                    price: '305.55000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-03T15:10:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                                {
                                    id: 11,
                                    price: '306.32001000',
                                    currency: 'EUR',
                                    as_of: '2026-06-03T15:35:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                                {
                                    id: 12,
                                    price: '306.32001000',
                                    currency: 'EUR',
                                    as_of: '2026-06-03T15:45:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                                {
                                    id: 13,
                                    price: '304.00000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-03T15:50:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                                {
                                    id: 14,
                                    price: '304.50000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-03T15:55:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                                {
                                    id: 15,
                                    price: '304.50000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-03T16:00:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                                {
                                    id: 16,
                                    price: '303.00000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-03T16:05:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                                {
                                    id: 17,
                                    price: '305.00000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-03T16:10:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                                {
                                    id: 18,
                                    price: '306.00000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-03T16:15:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                                {
                                    id: 19,
                                    price: '306.00000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-03T16:20:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                                {
                                    id: 20,
                                    price: '302.00000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-03T16:25:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                                {
                                    id: 21,
                                    price: '303.00000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-03T16:30:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                            ],
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
                            recent_prices: [],
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

        const html = wrapper.html();
        const tabsIndex = html.indexOf('v-tabs');
        const depotsHeadingIndex = html.indexOf('New depot');
        expect(tabsIndex).toBeGreaterThanOrEqual(0);
        expect(tabsIndex).toBeLessThan(depotsHeadingIndex);

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

    it('shows the watch-list stocks on the dashboard', async () => {
        window.history.pushState({}, '', '/admin/dashboard');
        let currentPriceRefreshSettings = priceRefreshSettings();
        const fetchMock = vi.fn((path, options) => {
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

            if (path === '/admin/watchlist/holdings?page=1') {
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
                            start_price: '300.100000',
                            end_price: '305.900000',
                            start_price_24: '299.500000',
                            start_price_48: '298.750000',
                            historical_prices_fetching: true,
                            latest_price_trend: 'up',
                            latest_price_change_pct: '0.14',
                            latest_price_tick_trend: 'up',
                            latest_price_status: 'fresh',
                            price_status: 'fresh',
                            latest_price_fetched_at: '2026-06-02T12:00:00+00:00',
                            latest_price_source: 'Tradegate Exchange',
                            latest_price_source_url: 'https://www.tradegatebsx.com/orderbuch.php?isin=US0378331005',
                            latest_price_as_of: '2026-06-03T15:35:00+00:00',
                            trading_times: 'Monday-Friday 08:00-22:00 Europe/Berlin',
                            venue: 'Tradegate',
                            price_type: 'indicative_mid',
                            price_spread_pct: '0.050000',
                            recent_prices: [
                                {
                                    id: 10,
                                    price: '305.55000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-03T15:10:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                                {
                                    id: 11,
                                    price: '306.32001000',
                                    currency: 'EUR',
                                    as_of: '2026-06-03T15:35:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                                {
                                    id: 12,
                                    price: '306.32001000',
                                    currency: 'EUR',
                                    as_of: '2026-06-03T15:45:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                                {
                                    id: 13,
                                    price: '304.00000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-03T15:50:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                                {
                                    id: 14,
                                    price: '304.50000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-03T15:55:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                                {
                                    id: 15,
                                    price: '304.50000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-03T16:00:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                                {
                                    id: 16,
                                    price: '303.00000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-03T16:05:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                                {
                                    id: 17,
                                    price: '305.00000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-03T16:10:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                                {
                                    id: 18,
                                    price: '306.00000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-03T16:15:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                                {
                                    id: 19,
                                    price: '306.00000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-03T16:20:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                                {
                                    id: 20,
                                    price: '302.00000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-03T16:25:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                                {
                                    id: 21,
                                    price: '303.00000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-03T16:30:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                            ],
                            validation_errors: [],
                        },
                        {
                            id: 4,
                            symbol: 'DOWN',
                            name: 'Closed Market Fund',
                            isin: 'LU0000000004',
                            wkn: 'DOWN01',
                            exchange: 'XETR',
                            currency: 'EUR',
                            latest_price: '190.000000',
                            start_price: '185.000000',
                            end_price: '195.000000',
                            start_price_24: '184.000000',
                            start_price_48: '183.000000',
                            latest_price_trend: 'down',
                            latest_price_change_pct: '-2.56',
                            latest_price_tick_trend: 'down',
                            latest_price_status: 'closed_market',
                            price_status: 'closed_market',
                            latest_price_fetched_at: '2026-06-03T18:10:00+00:00',
                            latest_price_source: 'Tradegate Exchange',
                            latest_price_source_url: 'https://example.com/down',
                            latest_price_as_of: '2026-06-03T18:00:00+00:00',
                            trading_times: 'Monday-Friday 09:00-17:30 Europe/Berlin',
                            venue: 'Tradegate',
                            price_type: 'last',
                            price_spread_pct: '0.020000',
                            recent_prices: [],
                            validation_errors: [],
                        },
                        {
                            id: 5,
                            symbol: 'FLAT',
                            name: 'Flat Price Fund',
                            isin: 'LU0000000005',
                            wkn: 'FLAT01',
                            exchange: 'XETR',
                            currency: 'EUR',
                            latest_price: '100.000000',
                            start_price: '100.000000',
                            end_price: '100.000000',
                            start_price_24: '100.000000',
                            start_price_48: '100.000000',
                            latest_price_trend: 'flat',
                            latest_price_change_pct: '0.00',
                            latest_price_tick_trend: 'flat',
                            latest_price_status: 'fresh',
                            price_status: 'fresh',
                            latest_price_fetched_at: '2026-06-03T12:10:00+00:00',
                            latest_price_source: 'Tradegate Exchange',
                            latest_price_source_url: 'https://example.com/flat',
                            latest_price_as_of: '2026-06-03T12:00:00+00:00',
                            trading_times: 'Monday-Friday 09:00-17:30 Europe/Berlin',
                            venue: 'Tradegate',
                            price_type: 'last',
                            price_spread_pct: '0.020000',
                            recent_prices: [],
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
                            recent_prices: [],
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
                        total: 5,
                        from: 1,
                        to: 5,
                    },
                    price_refresh_settings: currentPriceRefreshSettings,
                    eodhd_api_usage: {
                        hour: {
                            used: 12,
                            limit: 1000,
                            remaining: 988,
                            reset_at: '2026-06-03T13:00:00+02:00',
                        },
                        day: {
                            used: 1195,
                            limit: 100000,
                            remaining: 98805,
                            reset_at: '2026-06-04T00:00:00+02:00',
                        },
                    },
                }));
            }

            if (path === '/admin/watchlist/exchange-trading-times') {
                return Promise.resolve(jsonResponse({
                    exchange_trading_times: [
                        {
                            code: 'XETRA',
                            name: 'XETRA Stock Exchange',
                            operating_mic: 'XETR',
                            country: 'Germany',
                            currency: 'EUR',
                            timezone: 'Europe/Berlin',
                            is_open: false,
                            open: '09:00:00',
                            close: '17:30:00',
                            open_utc: '07:00:00',
                            close_utc: '15:30:00',
                            working_days: 'Mon,Tue,Wed,Thu,Fri',
                            error: null,
                        },
                    ],
                }));
            }

            if (path === '/admin/price-refresh-settings' && options?.method === 'PATCH') {
                currentPriceRefreshSettings = priceRefreshSettings({
                    trading_interval_minutes: 15,
                    trading_starts_before_minutes: 8,
                    trading_ends_after_minutes: 12,
                    closed_refresh_enabled: true,
                    closed_interval_minutes: 45,
                    next_refresh_at: '2026-06-02T12:35:00+00:00',
                    current_interval_minutes: 15,
                });

                return Promise.resolve(jsonResponse({
                    message: 'Price refresh schedule updated.',
                    price_refresh_settings: currentPriceRefreshSettings,
                    refresh: {
                        refresh_id: 'settings-refresh-1',
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

            if (path === '/admin/price-refresh-settings') {
                return Promise.resolve(jsonResponse({
                    price_refresh_settings: currentPriceRefreshSettings,
                }));
            }

            if (path === '/admin/watchlist/holdings') {
                return Promise.resolve(jsonResponse({
                    message: 'Stock added to watch-list.',
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
                        latest_price_tick_trend: null,
                        validation_errors: [],
                    },
                }));
            }

            if (path === '/admin/watchlist/holdings/refresh-prices') {
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

            if (path === '/admin/watchlist/holdings/refresh-prices/refresh-1') {
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

            if (path === '/admin/watchlist/holdings/refresh-prices/settings-refresh-1') {
                return Promise.resolve(jsonResponse({
                    message: '2 stock prices refreshed.',
                    refresh: {
                        refresh_id: 'settings-refresh-1',
                        status: 'finished',
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

            if (path === '/admin/watchlist/holdings/1') {
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

        const dashboardHeaders = wrapper.find('table').findAll('thead th').map((header) => header.text());
        const yesterday = sessionHeaderDate(1);
        const dayBeforeYesterday = sessionHeaderDate(2);

        expect(dashboardHeaders[0]).toBe('Symbol');
        expect(dashboardHeaders[1]).toBe('Name');
        expect(dashboardHeaders[2]).toBe('Latest price');
        expect(dashboardHeaders[3]).toBe('Start price');
        expect(dashboardHeaders[4]).toContain('End price');
        expect(dashboardHeaders[4]).toContain(yesterday);
        expect(dashboardHeaders[4]).not.toContain('Yesterday');
        expect(dashboardHeaders[5]).toContain('Start 24');
        expect(dashboardHeaders[5]).toContain(yesterday);
        expect(dashboardHeaders[5]).not.toContain('Yesterday');
        expect(dashboardHeaders[6]).toContain('Start 48');
        expect(dashboardHeaders[6]).toContain(dayBeforeYesterday);
        expect(dashboardHeaders[6]).not.toContain('Day before yesterday');
        expect(dashboardHeaders[7]).toBe('Source time');
        expect(dashboardHeaders[8]).toBe('Actions');
        expect(wrapper.text()).toContain('Watch-list');
        expect(wrapper.text()).toContain('EODHD API');
        expect(wrapper.text()).toContain('988 / 1,000');
        expect(wrapper.text()).toContain('98,805 / 100,000');
        expect(wrapper.text()).toContain('Depot');
        expect(wrapper.text()).toContain('Long term depot');
        expect(wrapper.text()).toContain('AAPL');
        expect(wrapper.text()).toContain('Apple');
        expect(wrapper.text()).toContain('US0378331005');
        expect(wrapper.text()).toContain('865985');
        expect(wrapper.text()).toContain('US0378331005 · WKN: 865985');
        expect(wrapper.text()).toContain('306.32001');
        expect(wrapper.text()).not.toContain('306.32001 EUR');
        expect(wrapper.text()).toContain('300.1');
        expect(wrapper.text()).toContain('305.9');
        expect(wrapper.text()).toContain('299.5');
        expect(wrapper.text()).toContain('298.75');
        expect(wrapper.text()).toContain('+2.28%');
        expect(wrapper.text()).toContain('+2.53%');
        expect(wrapper.text()).toContain('DOWN');
        expect(wrapper.text()).toContain('190');
        expect(wrapper.text()).toContain('FLAT');
        expect(wrapper.text()).toContain('100');

        const holdingRows = wrapper.findAll('tbody tr');
        const upPriceValue = holdingRows[0].findAll('td')[2].find('.latest-price-value');
        const downPriceValue = holdingRows[1].findAll('td')[2].find('.latest-price-value');
        expect(holdingRows[0].findAll('td')[2].classes()).not.toContain('bg-success');
        expect(holdingRows[1].findAll('td')[2].classes()).not.toContain('bg-error');
        expect(upPriceValue.classes()).toContain('bg-success');
        expect(upPriceValue.classes()).toContain('text-white');
        expect(downPriceValue.classes()).toContain('bg-error');
        expect(downPriceValue.classes()).toContain('text-white');
        expect(upPriceValue.text()).toContain('+0.14%');
        expect(downPriceValue.text()).toContain('-2.56%');
        expect(wrapper.html()).toContain('latest-price-tick');
        expect(wrapper.text()).toContain('↑');
        expect(wrapper.text()).toContain('↓');
        expect(wrapper.text()).toContain('=');
        const recentPriceTrendDots = holdingRows[0].findAll('.recent-price-trend-dot');
        expect(recentPriceTrendDots).toHaveLength(10);
        expect(recentPriceTrendDots[0].classes()).toContain('recent-price-trend-dot-flat');
        expect(recentPriceTrendDots[1].classes()).toContain('recent-price-trend-dot-down');
        expect(recentPriceTrendDots[2].classes()).toContain('recent-price-trend-dot-up');

        expect(wrapper.text()).not.toContain('305.55');

        await holdingRows[0].trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('305.55');
        const recentPriceStrip = wrapper.find('.recent-price-strip');
        expect(recentPriceStrip.text()).toContain('17:10');
        expect(recentPriceStrip.text()).not.toContain('03.06.2026');
        const expandedRecentPriceItems = recentPriceStrip.findAll('.recent-price-item');
        expect(expandedRecentPriceItems[0].text()).toContain('305.55');
        expect(expandedRecentPriceItems[0].text()).toContain('17:10');
        expect(expandedRecentPriceItems[1].text()).toContain('306.32001');
        expect(expandedRecentPriceItems[1].text()).toContain('17:35');
        expect(expandedRecentPriceItems[2].text()).toContain('306.32001');
        expect(expandedRecentPriceItems[2].text()).toContain('17:45');
        expect(expandedRecentPriceItems[3].text()).toContain('304');
        expect(expandedRecentPriceItems[3].text()).toContain('17:50');
        expect(expandedRecentPriceItems[0].find('.recent-price-trend').text()).toBe('=');
        expect(expandedRecentPriceItems[0].find('.recent-price-trend').classes()).toContain('text-medium-emphasis');
        expect(expandedRecentPriceItems[1].find('.recent-price-trend').text()).toBe('↑');
        expect(expandedRecentPriceItems[1].find('.recent-price-trend').classes()).toContain('text-success');
        expect(expandedRecentPriceItems[1].text().indexOf('17:35')).toBeLessThan(
            expandedRecentPriceItems[1].text().indexOf('↑'),
        );
        expect(expandedRecentPriceItems[2].find('.recent-price-trend').text()).toBe('=');
        expect(expandedRecentPriceItems[3].find('.recent-price-trend').text()).toBe('↓');
        expect(expandedRecentPriceItems[3].find('.recent-price-trend').classes()).toContain('text-error');

        await wrapper.find('.stock-holding-row').trigger('click');
        await flushPromises();

        expect(wrapper.text()).not.toContain('305.55');
        expect(wrapper.text()).toContain('03.06.2026, 17:35');
        expect(wrapper.text()).not.toContain('Monday-Friday 08:00-22:00 Europe/Berlin');
        expect(wrapper.text()).toContain('Exchange trading times');
        expect(wrapper.text()).toContain('XETRA');
        expect(wrapper.text()).toContain('XETRA Stock Exchange');
        expect(wrapper.text()).toContain('09:00-17:30');
        expect(wrapper.findAll('table')[1].findAll('thead th').map((header) => header.text())).toContain('Next trading');
        expect(wrapper.text()).toContain('Next trading:');
        expect(wrapper.text()).toContain('Mon,Tue,Wed,Thu,Fri');
        expect(wrapper.text()).toContain('Closed');
        expect(wrapper.text()).not.toContain('07:00-15:30 UTC');
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
        expect(wrapper.text()).toContain('fetching historical data');
        expect(wrapper.text()).not.toContain('Automatic price refresh');

        wrapper.vm.navigateSection('updates');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/updates');
        expect(wrapper.text()).toContain('Updates');
        expect(wrapper.text()).toContain('Automatic price refresh');
        expect(wrapper.text()).toContain('Current interval: 20 min');
        expect(wrapper.text()).toContain('Edit');
        expect(wrapper.text()).toContain('During trading');
        expect(wrapper.text()).toContain('Start before trading');
        expect(wrapper.text()).toContain('End after trading');
        expect(wrapper.text()).toContain('Outside trading');
        expect(wrapper.text()).toContain('Outside trading interval');

        expect(wrapper.find('#price-refresh-schedule-form').findAll('input')).toHaveLength(0);

        const editScheduleButton = wrapper.findAll('button').find((button) => button.text().includes('Edit'));
        await editScheduleButton.trigger('click');
        await flushPromises();

        const scheduleInputs = wrapper.find('#price-refresh-schedule-form').findAll('input[type="number"]');
        expect(scheduleInputs).toHaveLength(4);
        expect(wrapper.text()).toContain('Save');

        await scheduleInputs[0].setValue('15');
        await scheduleInputs[1].setValue('8');
        await scheduleInputs[2].setValue('12');
        await scheduleInputs[3].setValue('45');
        await wrapper.find('#price-refresh-schedule-form').trigger('submit');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/price-refresh-settings', expect.objectContaining({
            method: 'PATCH',
            body: JSON.stringify({
                trading_interval_minutes: 15,
                trading_starts_before_minutes: 8,
                trading_ends_after_minutes: 12,
                closed_refresh_enabled: true,
                closed_interval_minutes: 45,
            }),
        }));
        expect(fetchMock).toHaveBeenCalledWith('/admin/watchlist/holdings/refresh-prices/settings-refresh-1', expect.any(Object));
        expect(wrapper.text()).toContain('Price refresh schedule updated.');
        expect(wrapper.text()).toContain('Current interval: 15 min');
        expect(wrapper.text()).toContain('Edit');

        wrapper.vm.navigateSection('dashboard');
        await flushPromises();

        const refreshPricesButton = wrapper.findAll('button').find((button) => button.text().includes('Refresh prices'));
        await refreshPricesButton.trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/watchlist/holdings/refresh-prices', expect.objectContaining({
            method: 'POST',
        }));
        expect(fetchMock).toHaveBeenCalledWith('/admin/watchlist/holdings/refresh-prices/refresh-1', expect.any(Object));
        expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/watchlist/holdings?page=1')).toHaveLength(3);
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

        expect(fetchMock).toHaveBeenCalledWith('/admin/watchlist/holdings', expect.objectContaining({
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

        expect(fetchMock).toHaveBeenCalledWith('/admin/watchlist/holdings/1', expect.objectContaining({
            method: 'DELETE',
        }));
    });

    it('shows Admin group with horizontal Users, Roles, and Updates submenu chips for super_admin', async () => {
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

            if (path === '/admin/watchlist/holdings?page=1') {
                return Promise.resolve(jsonResponse({
                    depot: null,
                    holdings: [],
                    meta: { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null },
                    price_refresh_settings: priceRefreshSettings(),
                }));
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
        expect(wrapper.text()).toContain('Updates');

        const tabs = wrapper.findAll('.v-tab');
        const tabLabels = tabs.map((t) => t.text());
        expect(tabLabels.some((l) => l.includes('Users'))).toBe(true);
        expect(tabLabels.some((l) => l.includes('Roles'))).toBe(true);
        expect(tabLabels.some((l) => l.includes('Updates'))).toBe(true);

        const rolesTab = tabs.find((t) => t.text().includes('Roles'));
        await rolesTab.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/roles');
        expect(wrapper.text()).toContain('admin');
    });

    it('opens the watch-list PDF export in a new tab', async () => {
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

            if (path === '/admin/depots/active') {
                return Promise.resolve(jsonResponse({
                    depot: null,
                    price_refresh_settings: priceRefreshSettings(),
                }));
            }

            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({
                    depots: [],
                    meta: { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null },
                }));
            }

            if (path === '/admin/watchlist/holdings?page=1') {
                return Promise.resolve(jsonResponse({
                    depot: null,
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
                            recent_prices: [],
                            validation_errors: [],
                        },
                    ],
                    meta: { current_page: 1, last_page: 1, per_page: 10, total: 1, from: 1, to: 1 },
                    price_refresh_settings: priceRefreshSettings(),
                }));
            }

            return Promise.reject(new Error(`Unexpected request: ${path}`));
        });
        vi.stubGlobal('fetch', fetchMock);
        const openMock = vi.fn();
        vi.stubGlobal('open', openMock);

        const wrapper = mountApp();
        await flushPromises();

        const exportButton = wrapper.findAll('button').find((button) => button.text().includes('Export PDF'));
        expect(exportButton).toBeTruthy();

        await exportButton.trigger('click');
        await flushPromises();

        expect(openMock).toHaveBeenCalledWith('/admin/watchlist/holdings/pdf', '_blank', 'noopener');
    });

    it('books depot cash transaction from the depot page', async () => {
        window.history.pushState({}, '', '/admin/menu/depot');
        const depot = {
            id: 1,
            name: 'Main depot',
            account_balance: '1000.00',
            is_active: true,
        };
        const fetchMock = vi.fn((path, options) => {
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

            if (path === '/admin/depots/active') {
                return Promise.resolve(jsonResponse({
                    depot,
                    price_refresh_settings: priceRefreshSettings(),
                }));
            }

            if (path === '/admin/depot-transactions') {
                return Promise.resolve(jsonResponse({ transactions: [] }));
            }

            if (path === '/admin/watchlist/holdings?page=1') {
                return Promise.resolve(jsonResponse({
                    depot,
                    holdings: [],
                    meta: { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null },
                    price_refresh_settings: priceRefreshSettings(),
                }));
            }

            if (path === '/admin/watchlist/exchange-trading-times') {
                return Promise.resolve(jsonResponse({ exchange_trading_times: [] }));
            }

            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({
                    depots: [depot],
                    meta: { current_page: 1, last_page: 1, per_page: 10, total: 1, from: 1, to: 1 },
                }));
            }

            if (path === '/admin/depot-transactions/cash' && options?.method === 'POST') {
                return Promise.resolve(jsonResponse({
                    message: 'Cash transaction booked.',
                    depot: {
                        ...depot,
                        account_balance: '1250.00',
                    },
                    transaction: {
                        id: 1,
                        type: 'deposit',
                    },
                }));
            }

            return Promise.reject(new Error(`Unexpected request: ${path}`));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();
        await flushPromises();

        const addCashButton = wrapper.findAll('button').find((button) => button.text().includes('Add cash'));
        await addCashButton.trigger('click');
        await flushPromises();

        const cashAmountInput = document.body.querySelector('#cash-transaction-form input[type="number"]');
        cashAmountInput.value = '250';
        cashAmountInput.dispatchEvent(new Event('input', { bubbles: true }));
        document.body.querySelector('#cash-transaction-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/depot-transactions/cash', expect.objectContaining({
            method: 'POST',
            body: JSON.stringify({
                type: 'deposit',
                total_amount: 250,
                note: null,
            }),
        }));
    });

    it('books stock transaction from the dashboard', async () => {
        window.history.pushState({}, '', '/admin/dashboard');
        const emptyPagination = {
            current_page: 1,
            last_page: 1,
            per_page: 10,
            total: 1,
            from: 1,
            to: 1,
        };
        const depot = {
            id: 1,
            name: 'Main depot',
            account_balance: '1000.00',
            is_active: true,
        };
        const holding = {
            id: 1,
            symbol: 'AAPL',
            name: 'Apple',
            isin: 'US0378331005',
            wkn: '865985',
            exchange: 'NASDAQ',
            currency: 'EUR',
            latest_price: '100.000000',
            start_price: '100.000000',
            end_price: '100.000000',
            start_price_24: null,
            start_price_48: null,
            historical_prices_fetching: false,
            position_pieces: '0.00000000',
            latest_price_status: 'fresh',
            price_status: 'fresh',
            latest_price_fetched_at: '2026-06-02T12:00:00+00:00',
            latest_price_source: 'Tradegate Exchange',
            latest_price_source_url: null,
            latest_price_as_of: '2026-06-03T15:35:00+00:00',
            trading_times: 'Monday-Friday 08:00-22:00 Europe/Berlin',
            venue: 'Tradegate',
            price_type: 'indicative_mid',
            price_spread_pct: null,
            recent_prices: [],
            validation_errors: [],
        };
        const fetchMock = vi.fn((path, options) => {
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

            if (path === '/admin/depots/active') {
                return Promise.resolve(jsonResponse({
                    depot,
                    price_refresh_settings: priceRefreshSettings(),
                }));
            }

            if (path === '/admin/watchlist/holdings?page=1') {
                return Promise.resolve(jsonResponse({
                    depot,
                    holdings: [holding],
                    meta: emptyPagination,
                    price_refresh_settings: priceRefreshSettings(),
                }));
            }

            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({
                    depots: [depot],
                    meta: emptyPagination,
                }));
            }

            if (path === '/admin/depot-transactions/stocks' && options?.method === 'POST') {
                return Promise.resolve(jsonResponse({
                    message: 'Stock transaction booked.',
                    depot: {
                        ...depot,
                        account_balance: '750.00',
                    },
                    transaction: {
                        id: 2,
                        type: 'buy',
                    },
                }));
            }

            return Promise.reject(new Error(`Unexpected request: ${path}`));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();
        await flushPromises();

        const buyStockButton = wrapper.find('[aria-label="Buy stock"]');
        await buyStockButton.trigger('click');
        await flushPromises();

        const stockInputs = document.body.querySelectorAll('#stock-transaction-form input[type="number"]');
        stockInputs[0].value = '3';
        stockInputs[0].dispatchEvent(new Event('input', { bubbles: true }));
        stockInputs[1].value = '300';
        stockInputs[1].dispatchEvent(new Event('input', { bubbles: true }));
        document.body.querySelector('#stock-transaction-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/depot-transactions/stocks', expect.objectContaining({
            method: 'POST',
            body: JSON.stringify({
                type: 'buy',
                stock_holding_id: 1,
                pieces: 3,
                total_amount: 300,
                note: null,
            }),
        }));
    });

    it('shows the cash ledger table on the depot page', async () => {
        window.history.pushState({}, '', '/admin/menu/depot');
        const depot = {
            id: 1,
            name: 'Main depot',
            account_balance: '650.00',
            is_active: true,
        };
        const transactions = [
            {
                id: 2,
                type: 'buy',
                stock_holding_id: 1,
                stock_label: 'Apple Inc.',
                pieces: '2.00000000',
                total_amount: '350.00',
                unit_price: '175.00000000',
                cash_delta: '-350.00',
                balance_after: '650.00',
                booked_at: '2026-06-04T10:00:00+00:00',
                note: null,
            },
            {
                id: 1,
                type: 'deposit',
                stock_holding_id: null,
                stock_label: null,
                pieces: null,
                total_amount: '1000.00',
                unit_price: null,
                cash_delta: '1000.00',
                balance_after: '1000.00',
                booked_at: '2026-06-04T09:00:00+00:00',
                note: 'Initial funding',
            },
        ];
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

            if (path === '/admin/depots/active') {
                return Promise.resolve(jsonResponse({
                    depot,
                    price_refresh_settings: priceRefreshSettings(),
                }));
            }

            if (path === '/admin/depot-transactions') {
                return Promise.resolve(jsonResponse({ transactions }));
            }

            if (path === '/admin/watchlist/holdings?page=1') {
                return Promise.resolve(jsonResponse({
                    depot,
                    holdings: [],
                    meta: { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null },
                    price_refresh_settings: priceRefreshSettings(),
                }));
            }

            if (path === '/admin/watchlist/exchange-trading-times') {
                return Promise.resolve(jsonResponse({ exchange_trading_times: [] }));
            }

            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({
                    depots: [depot],
                    meta: { current_page: 1, last_page: 1, per_page: 10, total: 1, from: 1, to: 1 },
                }));
            }

            return Promise.reject(new Error(`Unexpected request: ${path}`));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();
        await flushPromises();

        expect(wrapper.text()).toContain('Cash ledger');
        expect(wrapper.text()).toContain('buy');
        expect(wrapper.text()).toContain('Apple Inc.');
        expect(wrapper.text()).toContain('deposit');
        expect(wrapper.text()).toContain('+1,000.00');
        expect(wrapper.text()).toContain('-350.00');
    });

    it('clears the historical fetching app bar status after the queued job finishes', async () => {
        vi.useFakeTimers();
        window.history.pushState({}, '', '/admin/dashboard');

        const emptyPagination = {
            current_page: 1,
            last_page: 1,
            per_page: 10,
            total: 1,
            from: 1,
            to: 1,
        };
        let holdingsLoaded = 0;
        const holding = {
            id: 1,
            symbol: 'AAPL',
            name: 'Apple',
            isin: 'US0378331005',
            wkn: '865985',
            exchange: 'NASDAQ',
            currency: 'EUR',
            latest_price: '100.000000',
            start_price: '100.000000',
            end_price: '100.000000',
            start_price_24: null,
            start_price_48: null,
            latest_price_status: 'fresh',
            price_status: 'fresh',
            latest_price_fetched_at: '2026-06-02T12:00:00+00:00',
            latest_price_source: 'Tradegate Exchange',
            latest_price_source_url: null,
            latest_price_as_of: '2026-06-03T15:35:00+00:00',
            trading_times: 'Monday-Friday 08:00-22:00 Europe/Berlin',
            venue: 'Tradegate',
            price_type: 'indicative_mid',
            price_spread_pct: null,
            recent_prices: [],
            validation_errors: [],
        };
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

            if (path === '/admin/depots/active') {
                return Promise.resolve(jsonResponse({
                    depot: null,
                    price_refresh_settings: priceRefreshSettings(),
                }));
            }

            if (path === '/admin/watchlist/holdings?page=1') {
                holdingsLoaded += 1;

                return Promise.resolve(jsonResponse({
                    depot: null,
                    holdings: [
                        {
                            ...holding,
                            historical_prices_fetching: holdingsLoaded === 1,
                            position_pieces: '0.00000000',
                        },
                    ],
                    meta: emptyPagination,
                    price_refresh_settings: priceRefreshSettings(),
                }));
            }

            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({
                    depots: [],
                    meta: emptyPagination,
                }));
            }

            if (path === '/admin/price-refresh-settings') {
                return Promise.resolve(jsonResponse({
                    price_refresh_settings: priceRefreshSettings(),
                    refresh: null,
                }));
            }

            return Promise.reject(new Error(`Unexpected request: ${path}`));
        });
        vi.stubGlobal('fetch', fetchMock);

        try {
            const wrapper = mountApp();
            await flushPromises();

            expect(wrapper.text()).toContain('fetching historical data');

            await vi.advanceTimersByTimeAsync(5000);
            await flushPromises();

            expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/watchlist/holdings?page=1')).toHaveLength(2);
            expect(wrapper.text()).toContain('waiting');
            expect(wrapper.text()).not.toContain('fetching historical data');

            wrapper.unmount();
        } finally {
            vi.useRealTimers();
        }
    });

    it('updates the app bar status when a scheduled queue refresh starts', async () => {
        vi.useFakeTimers();
        window.history.pushState({}, '', '/admin/dashboard');

        const emptyPagination = {
            current_page: 1,
            last_page: 1,
            per_page: 10,
            total: 0,
            from: null,
            to: null,
        };
        const scheduledRefresh = {
            refresh_id: 'scheduled-refresh-1',
            status: 'queued',
            processed: 0,
            total: 1,
            step: '0/1',
            message: '1 stock price queued for refresh.',
            current: null,
            started_at: '2026-06-02T12:21:00+00:00',
            finished_at: null,
            error: null,
        };
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

            if (path === '/admin/depots/active') {
                return Promise.resolve(jsonResponse({
                    depot: null,
                    price_refresh_settings: priceRefreshSettings(),
                }));
            }

            if (path === '/admin/watchlist/holdings?page=1') {
                return Promise.resolve(jsonResponse({
                    depot: null,
                    holdings: [],
                    meta: emptyPagination,
                    price_refresh_settings: priceRefreshSettings(),
                }));
            }

            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({
                    depots: [],
                    meta: emptyPagination,
                }));
            }

            if (path === '/admin/price-refresh-settings') {
                return Promise.resolve(jsonResponse({
                    price_refresh_settings: priceRefreshSettings({
                        status: 'updating',
                        status_label: 'Updating prices',
                    }),
                    refresh: scheduledRefresh,
                }));
            }

            if (path === '/admin/watchlist/holdings/refresh-prices/scheduled-refresh-1') {
                return Promise.resolve(jsonResponse({
                    message: 'Refreshing stock prices...',
                    refresh: {
                        ...scheduledRefresh,
                        status: 'running',
                        message: 'Refreshing stock prices...',
                    },
                }));
            }

            return Promise.reject(new Error(`Unexpected request: ${path}`));
        });
        vi.stubGlobal('fetch', fetchMock);

        try {
            const wrapper = mountApp();
            await flushPromises();

            expect(wrapper.text()).toContain('waiting');

            await vi.advanceTimersByTimeAsync(5000);
            await flushPromises();

            expect(fetchMock).toHaveBeenCalledWith('/admin/price-refresh-settings', expect.any(Object));
            expect(fetchMock).toHaveBeenCalledWith(
                '/admin/watchlist/holdings/refresh-prices/scheduled-refresh-1',
                expect.any(Object),
            );
            expect(wrapper.text()).toContain('Updating prices');

            wrapper.unmount();
        } finally {
            vi.useRealTimers();
        }
    });
});
