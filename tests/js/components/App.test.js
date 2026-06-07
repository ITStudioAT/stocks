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

function setViewportSize(width, height = 768) {
    Object.defineProperty(window, 'innerWidth', {
        configurable: true,
        writable: true,
        value: width,
    });
    Object.defineProperty(window.visualViewport, 'width', {
        configurable: true,
        writable: true,
        value: width,
    });
    Object.defineProperty(window, 'innerHeight', {
        configurable: true,
        writable: true,
        value: height,
    });
    Object.defineProperty(window.visualViewport, 'height', {
        configurable: true,
        writable: true,
        value: height,
    });
    window.dispatchEvent(new Event('resize'));
}

function setViewportWidth(width) {
    setViewportSize(width);
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

function indexPriceRefreshSettings(overrides = {}) {
    return priceRefreshSettings({
        trading_interval_minutes: 30,
        trading_starts_before_minutes: 15,
        trading_ends_after_minutes: 20,
        closed_refresh_enabled: false,
        closed_interval_minutes: 90,
        current_interval_minutes: 30,
        ...overrides,
    });
}

function queueStatusResponse(overrides = {}) {
    return {
        queue: {
            status: 'ok',
            connection: 'sync',
            name: 'default',
            pending: 0,
            reserved: 0,
            failed: 0,
            retry_after: 2100,
            max_job_timeout: 1800,
            issues: [],
            ...overrides,
        },
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

function localDateKey(timeZone) {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(new Date());
    const dateParts = Object.fromEntries(parts.map((part) => [part.type, part.value]));

    return [dateParts.year, dateParts.month, dateParts.day].join('-');
}

function indexMonthPrices() {
    const baseDate = new Date(Date.UTC(2026, 5, 7));

    return Array.from({ length: 30 }, (_, index) => {
        const tradingDate = new Date(baseDate);
        tradingDate.setUTCDate(baseDate.getUTCDate() - index);
        const actualPrice = 6116.5298 - index;

        return {
            trading_date: tradingDate.toISOString().slice(0, 10),
            start_price: (actualPrice - 5).toFixed(6),
            actual_price: actualPrice.toFixed(6),
            last_price: (actualPrice - 2).toFixed(6),
        };
    });
}

describe('App', () => {
    it('renders the admin login screen without loading authenticated data', () => {
        window.history.pushState({}, '', '/admin/login');
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();

        expect(wrapper.text()).toContain('GKStocks admin');
        expect(wrapper.text()).toContain('Sign in to manage your workspace.');
        expect(wrapper.text()).toContain('Password');
        expect(wrapper.text()).toContain('Code');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('starts the dashboard menu compact on handset width', async () => {
        window.history.pushState({}, '', '/admin/dashboard');
        setViewportWidth(390);

        const emptyPagination = {
            current_page: 1,
            last_page: 1,
            per_page: 10,
            total: 0,
            from: null,
            to: null,
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
                    index_price_refresh_settings: indexPriceRefreshSettings(),
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
                            currency: 'EUR',
                            latest_price: '306.320010',
                            end_price: '305.900000',
                            end_price_24: '299.500000',
                            latest_price_trend: 'up',
                            latest_price_change_pct: '2.28',
                            latest_price_status: 'fresh',
                            recent_prices: [],
                            position_pieces: '2.00000000',
                        },
                        {
                            id: 2,
                            symbol: 'MSFT',
                            name: 'Microsoft',
                            currency: 'USD',
                            latest_price: null,
                            end_price: '429.950000',
                            end_price_24: '420.000000',
                            latest_price_status: 'closed_market',
                            recent_prices: [],
                            position_pieces: '0.00000000',
                        },
                    ],
                    meta: emptyPagination,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            if (path === '/admin/index-watch-items') {
                return Promise.resolve(jsonResponse({ indexes: [] }));
            }

            if (path === '/admin/watchlist/exchange-trading-times') {
                return Promise.resolve(jsonResponse({
                    exchange_trading_times: [
                        {
                            code: 'XETRA',
                            name: 'XETRA Stock Exchange',
                            operating_mic: 'XETR',
                            timezone: 'Europe/Berlin',
                            is_open: false,
                            open: '09:00:00',
                            close: '17:30:00',
                            working_days: 'Mon,Tue,Wed,Thu,Fri',
                            sessions: [
                                { open: '09:00:00', close: '17:30:00' },
                            ],
                            holidays: [],
                            error: null,
                        },
                    ],
                }));
            }

            if (path === '/admin/queue/status') {
                return Promise.resolve(jsonResponse(queueStatusResponse()));
            }

            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({
                    depots: [],
                    meta: emptyPagination,
                }));
            }

            return Promise.reject(new Error(`Unexpected request: ${path}`));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();
        await flushPromises();

        expect(wrapper.find('.dashboard-navigation-drawer').classes()).toContain('dashboard-navigation-drawer--compact');
        expect(wrapper.findComponent({ name: 'VNavigationDrawer' }).props('width')).toBe(64);
        expect(wrapper.findAll('.dashboard-compact-menu-item')).toHaveLength(6);
        expect(wrapper.find('.dashboard-compact-menu-item--active').exists()).toBe(true);
        expect(wrapper.find('[aria-label="Enhance dashboard menu"]').exists()).toBe(true);
        expect(wrapper.find('.dashboard-navigation-drawer').text()).not.toContain('Stocks');
        expect(wrapper.find('.dashboard-status-card').exists()).toBe(false);

        const dashboardHeading = wrapper.get('.dashboard-heading');
        expect(dashboardHeading.text()).toContain('Watch-list');
        expect(dashboardHeading.find('.dashboard-actions').exists()).toBe(true);
        const actionLabels = dashboardHeading.findAll('.dashboard-action-button').map((button) => button.text());
        expect(actionLabels).toEqual([
            'Clear queue',
            'Export PDF',
            'Refresh prices',
            'Add stock',
        ]);
        const mobileCards = wrapper.findAll('.mobile-stock-card');
        expect(mobileCards).toHaveLength(2);
        expect(mobileCards[0].text()).toContain('Apple');
        expect(mobileCards[0].text()).toContain('306.32');
        expect(mobileCards[0].text()).toContain('+2.28% · 299.50');
        expect(mobileCards[0].text()).not.toContain('Add');
        expect(mobileCards[0].text()).not.toContain('Withdraw');
        expect(mobileCards[0].text()).not.toContain('Delete');
        expect(mobileCards[0].findAll('.mobile-stock-actions button').map((button) => button.attributes('aria-label'))).toEqual([
            'Add',
            'Withdraw',
            'Delete',
        ]);
        expect(mobileCards[1].text()).toContain('Microsoft');
        expect(mobileCards[1].text()).toContain('429.95 USD');
        expect(mobileCards[1].text()).toContain('+2.37% · 420.00 USD');
        expect(wrapper.text()).not.toContain('EODHD exchange details');
        expect(wrapper.text()).not.toContain('Exchange trading times');
        expect(wrapper.text()).not.toContain('XETRA Stock Exchange');
    });

    it('compacts the watch-list table below desktop width', async () => {
        window.history.pushState({}, '', '/admin/dashboard');
        setViewportSize(1000, 768);

        const emptyPagination = {
            current_page: 1,
            last_page: 1,
            per_page: 10,
            total: 1,
            from: 1,
            to: 1,
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
                    index_price_refresh_settings: indexPriceRefreshSettings(),
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
                            end_price: '305.900000',
                            end_price_24: '299.500000',
                            latest_price_trend: 'up',
                            latest_price_change_pct: '2.28',
                            latest_price_status: 'fresh',
                            recent_prices: [],
                            position_pieces: '2.00000000',
                        },
                        {
                            id: 2,
                            symbol: 'MSFT',
                            name: 'Microsoft',
                            isin: 'US5949181045',
                            wkn: '870747',
                            exchange: 'NASDAQ',
                            currency: 'USD',
                            latest_price: null,
                            end_price: '429.950000',
                            end_price_24: '420.000000',
                            latest_price_status: 'closed_market',
                            recent_prices: [],
                            position_pieces: '0.00000000',
                        },
                    ],
                    meta: emptyPagination,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            if (path === '/admin/index-watch-items') {
                return Promise.resolve(jsonResponse({ indexes: [] }));
            }

            if (path === '/admin/watchlist/exchange-trading-times') {
                return Promise.resolve(jsonResponse({ exchange_trading_times: [] }));
            }

            if (path === '/admin/queue/status') {
                return Promise.resolve(jsonResponse(queueStatusResponse()));
            }

            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({
                    depots: [],
                    meta: emptyPagination,
                }));
            }

            return Promise.reject(new Error(`Unexpected request: ${path}`));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();
        await flushPromises();

        const watchListTable = wrapper.get('.desktop-watch-list-table');
        const headers = watchListTable.findAll('thead th').map((header) => header.text());
        expect(headers).not.toContain('Symbol');
        expect(headers[0]).toBe('Name');
        expect(headers).toContain('Latest price');
        expect(headers.some((header) => header.includes('End price'))).toBe(true);
        expect(headers).not.toContain('Source time');

        const firstRowCells = watchListTable.find('.stock-holding-row').findAll('td');
        expect(firstRowCells[0].text()).toContain('Apple');
        expect(firstRowCells[0].text()).toContain('Pieces: 2');
        expect(firstRowCells[0].text()).not.toContain('US0378331005');
        expect(firstRowCells[0].text()).not.toContain('WKN: 865985');

        setViewportSize(852, 393);
        await flushPromises();

        const landscapeHeaders = watchListTable.findAll('thead th').map((header) => header.text());
        expect(landscapeHeaders).toContain('Price');
        expect(landscapeHeaders).not.toContain('Latest price');
        expect(landscapeHeaders).not.toContain('End price');
        expect(landscapeHeaders).not.toContain('Source time');

        const landscapeRows = watchListTable.findAll('.stock-holding-row');
        const appleCells = landscapeRows[0].findAll('td');
        const microsoftCells = landscapeRows[1].findAll('td');
        expect(appleCells[1].text()).toContain('306.32');
        expect(appleCells[1].text()).toContain('+2.28% · 299.50');
        expect(microsoftCells[1].text()).toContain('429.95 USD');
        expect(microsoftCells[1].text()).toContain('+2.37% · 420.00 USD');
    });

    it('renders the depots menu section with paginated depot data', async () => {
        window.history.pushState({}, '', '/admin/menu/depots');
        const fetchMock = vi.fn((path, options = {}) => {
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
                            current_account_balance: '82830.36',
                            is_active: true,
                        },
                        {
                            id: 2,
                            name: 'Trading depot',
                            account_balance: '250.50',
                            current_account_balance: '250.50',
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
                            recent_prices_are_fallback: false,
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

            if (path === '/admin/index-watch-items') {
                return Promise.resolve(jsonResponse({ indexes: [] }));
            }

            return Promise.reject(new Error(`Unexpected request: ${path}`));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();
        await flushPromises();

        expect(wrapper.text()).toContain('Depots');
        expect(wrapper.text()).toContain('New depot');
        expect(wrapper.text()).toContain('Long term depot');
        expect(wrapper.text()).toContain('82,830.36 EUR');
        expect(wrapper.text()).not.toContain('12,345.67');
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

    it('shows the Analyze menu page with URL-backed subpages', async () => {
        window.history.pushState({}, '', '/admin/menu/analyze/detail');
        const pagination = { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null };
        const depot = { id: 1, name: 'Main depot', account_balance: '1000.00', is_active: true };
        const fetchMock = vi.fn((path, options = {}) => {
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
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            if (path === '/admin/watchlist/holdings?page=1') {
                return Promise.resolve(jsonResponse({
                    depot,
                    holdings: [
                        {
                            id: 1,
                            symbol: 'AAPL',
                            name: 'Apple',
                            currency: 'EUR',
                            latest_price: '306.320010',
                            daily_prices: [
                                { trading_date: '2026-05-10', price: '290.000000', currency: 'EUR' },
                                { trading_date: '2026-05-20', price: '301.500000', currency: 'EUR' },
                                { trading_date: '2026-06-04', price: '306.320010', currency: 'EUR' },
                            ],
                            intraday_prices: Array.from({ length: 20 }, (_, index) => ({
                                id: index + 1,
                                price: index === 6 ? '307.25000000' : (305 + index * 0.1).toFixed(8),
                                currency: 'EUR',
                                as_of: new Date(Date.UTC(2026, 5, 5, 10, index * 5)).toISOString(),
                            })),
                        },
                        {
                            id: 2,
                            symbol: 'MSFT',
                            name: 'Microsoft',
                            currency: 'USD',
                            latest_price: null,
                            end_price: '429.950000',
                            daily_prices: [],
                        },
                    ],
                    meta: pagination,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            if (path === '/admin/watchlist/exchange-trading-times') {
                return Promise.resolve(jsonResponse({ exchange_trading_times: [] }));
            }

            if (path === '/admin/watchlist/holdings/1/intraday-candles') {
                return Promise.resolve(jsonResponse({
                    holding: {
                        id: 1,
                        symbol: 'AAPL',
                        name: 'Apple',
                        currency: 'EUR',
                    },
                    intraday: {
                        title: 'Intraday 05.06.2026 - 5m',
                        trading_date: '2026-06-05',
                        interval: '5m',
                        rows: [
                            {
                                id: 1,
                                timestamp: 1780642800,
                                gmtoffset: 0,
                                datetime: '2026-06-05 07:00:00',
                                open: '470.10000000',
                                high: '471.50000000',
                                low: '469.90000000',
                                close: '470.15000000',
                                volume: 12345,
                                currency: 'EUR',
                            },
                        ],
                    },
                    intraday_days: [
                        {
                            title: 'Intraday 05.06.2026 - 5m',
                            trading_date: '2026-06-05',
                            interval: '5m',
                            rows: [
                                {
                                    timestamp: 1780642800,
                                    gmtoffset: 0,
                                    datetime: '2026-06-05 07:00:00',
                                    open: '470.10000000',
                                    high: '480.00000000',
                                    low: '460.00000000',
                                    close: '470.15000000',
                                    volume: 12345,
                                },
                                {
                                    timestamp: 1780643100,
                                    gmtoffset: 0,
                                    datetime: '2026-06-05 07:05:00',
                                    open: '470.15000000',
                                    high: '472.00000000',
                                    low: '469.00000000',
                                    close: '469.25000000',
                                    volume: 13345,
                                },
                                {
                                    timestamp: 1780653900,
                                    gmtoffset: 0,
                                    datetime: '2026-06-05 10:05:00',
                                    open: '469.25000000',
                                    high: '473.25000000',
                                    low: '468.50000000',
                                    close: '472.75000000',
                                    volume: 14345,
                                },
                                {
                                    timestamp: 1780664400,
                                    gmtoffset: 0,
                                    datetime: '2026-06-05 13:00:00',
                                    open: '472.75000000',
                                    high: '473.00000000',
                                    low: '471.50000000',
                                    close: '471.75000000',
                                    volume: 15345,
                                },
                            ],
                        },
                        {
                            title: 'Intraday 04.06.2026 - 5m',
                            trading_date: '2026-06-04',
                            interval: '5m',
                            rows: [
                                {
                                    timestamp: 1780556400,
                                    gmtoffset: 0,
                                    datetime: '2026-06-04 07:00:00',
                                    open: '468.10000000',
                                    high: '469.50000000',
                                    low: '467.90000000',
                                    close: '469.15000000',
                                    volume: 11345,
                                },
                            ],
                        },
                        {
                            title: 'Intraday 03.06.2026 - 5m',
                            trading_date: '2026-06-03',
                            interval: '5m',
                            rows: [
                                {
                                    timestamp: 1780470000,
                                    gmtoffset: 0,
                                    datetime: '2026-06-03 07:00:00',
                                    open: '466.10000000',
                                    high: '467.50000000',
                                    low: '465.90000000',
                                    close: '467.15000000',
                                    volume: 10345,
                                },
                                {
                                    timestamp: 1780470300,
                                    gmtoffset: 0,
                                    datetime: '2026-06-03 07:05:00',
                                    open: '467.15000000',
                                    high: '467.20000000',
                                    low: '466.10000000',
                                    close: '466.15000000',
                                    volume: 11345,
                                },
                            ],
                        },
                    ],
                }));
            }

            if (path === '/admin/watchlist/holdings/historical-prices/ensure' && options?.method === 'POST') {
                return Promise.resolve(jsonResponse({
                    message: 'Historical stock prices are available.',
                    coverage: {
                        date_from: '2025-06-05',
                        date_to: '2026-06-05',
                        required_to: '2026-06-04',
                        is_complete: true,
                        total_count: 2,
                        available_count: 2,
                        missing_count: 0,
                        holdings: [],
                    },
                    refresh: null,
                }));
            }

            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({ depots: [depot], meta: pagination }));
            }

            return Promise.resolve(jsonResponse({}));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();
        await flushPromises();

        const drawerText = wrapper.find('.dashboard-navigation-drawer').text();
        expect(drawerText.indexOf('Dashboard')).toBeLessThan(drawerText.indexOf('Analyze'));
        expect(drawerText.indexOf('Analyze')).toBeLessThan(drawerText.indexOf('Depot'));
        expect(wrapper.find('[aria-label="Analyze detail"]').exists()).toBe(true);
        expect(window.location.pathname).toBe('/admin/menu/analyze/detail');
        expect(window.location.search).toBe('?stock=all');
        expect(wrapper.find('[aria-label="Analyze detail"]').text()).toContain('ALL');
        expect(wrapper.text()).toContain('Overview');
        expect(wrapper.text()).toContain('Detail');
        expect(wrapper.text()).toContain('Tests');

        const overviewTab = wrapper.findAll('.v-tab')
            .find((tab) => tab.text().includes('Overview'));
        await overviewTab.trigger('click');
        await flushPromises();
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/analyze/overview');
        expect(window.location.search).toBe('?stock=all');
        const analyzeOverview = wrapper.find('[aria-label="Analyze overview"]');
        expect(analyzeOverview.exists()).toBe(true);
        expect(analyzeOverview.text()).toContain('ALL');
        expect(analyzeOverview.text()).toContain('Apple');
        expect(analyzeOverview.text()).toContain('306.32 EUR');
        expect(analyzeOverview.text()).toContain('Microsoft');
        expect(analyzeOverview.text()).toContain('429.95 USD');
        expect(analyzeOverview.text()).not.toContain('INDEX');
        expect(analyzeOverview.text()).not.toContain('History');
        expect(analyzeOverview.text()).toContain('0 historical price records loaded/updated.');
        expect(analyzeOverview.text()).not.toContain('2/2');
        expect(analyzeOverview.text()).not.toContain('6 months');

        const appleCard = analyzeOverview.findAll('.analyze-holding-card')
            .find((button) => button.text().includes('Apple'));
        await appleCard.trigger('click');
        await flushPromises();

        expect(window.location.search).toBe('?stock=1');
        expect(analyzeOverview.text()).toContain('1 year');
        expect(analyzeOverview.text()).toContain('6 months');
        expect(analyzeOverview.text()).toContain('3 months');
        expect(analyzeOverview.text()).toContain('1 month');
        expect(analyzeOverview.text()).toContain('1 week');
        expect(analyzeOverview.text()).toContain('today');

        const oneYearRangeButton = analyzeOverview.findAll('.analyze-range-button')
            .find((button) => button.text() === '1 year');
        const oneMonthRangeButton = analyzeOverview.findAll('.analyze-range-button')
            .find((button) => button.text() === '1 month');
        const todayRangeButton = analyzeOverview.findAll('.analyze-range-button')
            .find((button) => button.text() === 'today');

        expect(oneYearRangeButton.attributes('aria-pressed')).toBe('true');
        await oneMonthRangeButton.trigger('click');
        await flushPromises();

        expect(oneMonthRangeButton.attributes('aria-pressed')).toBe('true');
        expect(oneYearRangeButton.attributes('aria-pressed')).toBe('false');
        expect(analyzeOverview.find('.analyze-sparkline').exists()).toBe(true);
        expect(analyzeOverview.find('.analyze-sparkline').attributes('viewBox')).toBe('0 0 1440 600');
        expect(analyzeOverview.findAll('.analyze-sparkline-label').length).toBeGreaterThanOrEqual(4);
        expect(analyzeOverview.findAll('.analyze-sparkline-y-label').map((label) => label.text())).toEqual([
            '307.136011',
            '302.648008',
            '298.160005',
            '293.672002',
            '289.184',
        ]);
        expect(analyzeOverview.findAll('.analyze-sparkline-dot')).toHaveLength(3);
        expect(analyzeOverview.find('.analyze-sparkline-trend-line').exists()).toBe(true);
        expect(Number(analyzeOverview.find('.analyze-sparkline-trend-line').attributes('x2'))).toBeGreaterThan(
            Number(analyzeOverview.find('.analyze-sparkline-trend-line').attributes('x1')),
        );
        expect(analyzeOverview.findAll('.analyze-sparkline-endpoint-label')).toHaveLength(2);
        expect(analyzeOverview.find('.analyze-sparkline-endpoint-label--start').text()).toBe('Start 290.00');
        expect(analyzeOverview.find('.analyze-sparkline-endpoint-label--latest').text()).toBe('End 306.32001');
        expect(Number(analyzeOverview.find('.analyze-sparkline-endpoint-label--start').attributes('y'))).toBeGreaterThan(500);
        expect(Number(analyzeOverview.find('.analyze-sparkline-endpoint-label--latest').attributes('y'))).toBeLessThan(30);
        expect(analyzeOverview.find('.analyze-sparkline-extremum--high').exists()).toBe(true);
        expect(analyzeOverview.find('.analyze-sparkline-extremum--low').exists()).toBe(true);
        expect(analyzeOverview.findAll('.analyze-sparkline-extremum-ring')).toHaveLength(2);
        expect(analyzeOverview.findAll('.analyze-sparkline-extremum-dot')).toHaveLength(2);
        expect(analyzeOverview.find('.analyze-sparkline-extremum--high .analyze-sparkline-extremum-label').text()).toBe('306.32001');
        expect(analyzeOverview.find('.analyze-sparkline-extremum--low .analyze-sparkline-extremum-label').text()).toBe('290.00');

        await todayRangeButton.trigger('click');
        await flushPromises();

        expect(todayRangeButton.attributes('aria-pressed')).toBe('true');
        expect(analyzeOverview.text()).toContain('13:35');
        expect(analyzeOverview.text()).toContain('307.25');
        expect(analyzeOverview.findAll('.analyze-sparkline-dot')).toHaveLength(20);

        const highMarkerLine = analyzeOverview.find('.analyze-sparkline-extremum--high .analyze-sparkline-extremum-line');
        const lowMarkerLine = analyzeOverview.find('.analyze-sparkline-extremum--low .analyze-sparkline-extremum-line');
        const highMarkerLabel = analyzeOverview.find('.analyze-sparkline-extremum--high .analyze-sparkline-extremum-label');
        const lowMarkerLabel = analyzeOverview.find('.analyze-sparkline-extremum--low .analyze-sparkline-extremum-label');

        expect(highMarkerLine.attributes('y1')).not.toBe(highMarkerLine.attributes('y2'));
        expect(lowMarkerLine.attributes('y1')).not.toBe(lowMarkerLine.attributes('y2'));
        expect(['start', 'end']).toContain(highMarkerLabel.attributes('text-anchor'));
        expect(['start', 'end']).toContain(lowMarkerLabel.attributes('text-anchor'));

        await oneYearRangeButton.trigger('click');
        await flushPromises();

        expect(analyzeOverview.findAll('.analyze-sparkline-dot')).toHaveLength(0);

        const allCard = analyzeOverview.findAll('.analyze-holding-card')
            .find((button) => button.text().includes('ALL'));
        await allCard.trigger('click');
        await flushPromises();

        expect(window.location.search).toBe('?stock=all');
        expect(analyzeOverview.text()).not.toContain('6 months');
        expect(fetchMock).toHaveBeenCalledWith('/admin/watchlist/holdings/historical-prices/ensure', expect.objectContaining({
            method: 'POST',
        }));
    });

    it('restores the selected Analyze overview stock from the URL', async () => {
        window.history.pushState({}, '', '/admin/menu/analyze/overview?stock=1');
        const pagination = { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null };
        const depot = { id: 1, name: 'Main depot', account_balance: '1000.00', is_active: true };
        const fetchMock = vi.fn((path, options = {}) => {
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
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            if (path === '/admin/watchlist/holdings?page=1') {
                return Promise.resolve(jsonResponse({
                    depot,
                    holdings: [
                        {
                            id: 1,
                            symbol: 'AAPL',
                            name: 'Apple',
                            currency: 'EUR',
                            latest_price: '306.320010',
                            daily_prices: [
                                { trading_date: '2026-06-04', price: '306.320010', currency: 'EUR' },
                            ],
                        },
                        {
                            id: 2,
                            symbol: 'MSFT',
                            name: 'Microsoft',
                            currency: 'USD',
                            latest_price: '429.950000',
                            daily_prices: [],
                        },
                        {
                            id: 3,
                            symbol: 'NVDA',
                            name: 'Nvidia',
                            currency: 'USD',
                            latest_price: '920.000000',
                            daily_prices: [],
                        },
                    ],
                    meta: pagination,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            if (path === '/admin/watchlist/exchange-trading-times') {
                return Promise.resolve(jsonResponse({ exchange_trading_times: [] }));
            }

            if (path === '/admin/watchlist/holdings/1/intraday-candles') {
                return Promise.resolve(jsonResponse({
                    holding: {
                        id: 1,
                        symbol: 'AAPL',
                        name: 'Apple',
                        currency: 'EUR',
                    },
                    intraday: {
                        title: 'Intraday 05.06.2026 - 5m',
                        trading_date: '2026-06-05',
                        interval: '5m',
                        rows: [
                            {
                                timestamp: 1780642800,
                                gmtoffset: 0,
                                datetime: '2026-06-05 07:00:00',
                                open: '470.10000000',
                                high: '471.50000000',
                                low: '469.90000000',
                                close: '470.15000000',
                                volume: 12345,
                            },
                        ],
                    },
                    intraday_days: [
                        {
                            title: 'Intraday 05.06.2026 - 5m',
                            trading_date: '2026-06-05',
                            interval: '5m',
                            rows: [
                                {
                                    timestamp: 1780642800,
                                    gmtoffset: 0,
                                    datetime: '2026-06-05 07:00:00',
                                    open: '470.10000000',
                                    high: '480.00000000',
                                    low: '460.00000000',
                                    close: '470.15000000',
                                    volume: 12345,
                                },
                                {
                                    timestamp: 1780643100,
                                    gmtoffset: 0,
                                    datetime: '2026-06-05 07:05:00',
                                    open: '470.15000000',
                                    high: '472.00000000',
                                    low: '469.00000000',
                                    close: '469.25000000',
                                    volume: 13345,
                                },
                                {
                                    timestamp: 1780653900,
                                    gmtoffset: 0,
                                    datetime: '2026-06-05 10:05:00',
                                    open: '469.25000000',
                                    high: '473.25000000',
                                    low: '468.50000000',
                                    close: '472.75000000',
                                    volume: 14345,
                                },
                                {
                                    timestamp: 1780664400,
                                    gmtoffset: 0,
                                    datetime: '2026-06-05 13:00:00',
                                    open: '472.75000000',
                                    high: '473.00000000',
                                    low: '471.50000000',
                                    close: '471.75000000',
                                    volume: 15345,
                                },
                            ],
                        },
                        {
                            title: 'Intraday 04.06.2026 - 5m',
                            trading_date: '2026-06-04',
                            interval: '5m',
                            rows: [
                                {
                                    timestamp: 1780556400,
                                    gmtoffset: 0,
                                    datetime: '2026-06-04 07:00:00',
                                    open: '468.10000000',
                                    high: '469.50000000',
                                    low: '467.90000000',
                                    close: '469.15000000',
                                    volume: 11345,
                                },
                            ],
                        },
                        {
                            title: 'Intraday 03.06.2026 - 5m',
                            trading_date: '2026-06-03',
                            interval: '5m',
                            rows: [
                                {
                                    timestamp: 1780470000,
                                    gmtoffset: 0,
                                    datetime: '2026-06-03 07:00:00',
                                    open: '466.10000000',
                                    high: '467.50000000',
                                    low: '465.90000000',
                                    close: '467.15000000',
                                    volume: 10345,
                                },
                                {
                                    timestamp: 1780470300,
                                    gmtoffset: 0,
                                    datetime: '2026-06-03 07:05:00',
                                    open: '467.15000000',
                                    high: '467.20000000',
                                    low: '466.10000000',
                                    close: '466.15000000',
                                    volume: 11345,
                                },
                            ],
                        },
                    ],
                }));
            }

            if (path === '/admin/watchlist/holdings/3/intraday-candles') {
                return Promise.resolve(jsonResponse({
                    holding: {
                        id: 3,
                        symbol: 'NVDA',
                        name: 'Nvidia',
                        currency: 'USD',
                    },
                    intraday: {
                        title: 'Intraday 05.06.2026 - 5m',
                        trading_date: '2026-06-05',
                        interval: '5m',
                        rows: [
                            {
                                timestamp: 1780643100,
                                gmtoffset: 0,
                                datetime: '2026-06-05 07:05:00',
                                open: '920.10000000',
                                high: '925.50000000',
                                low: '919.90000000',
                                close: '924.15000000',
                                volume: 98765,
                            },
                        ],
                    },
                }));
            }

            if (path === '/admin/watchlist/holdings/historical-prices/ensure' && options?.method === 'POST') {
                return Promise.resolve(jsonResponse({
                    message: 'Historical stock prices are available.',
                    coverage: null,
                    refresh: null,
                }));
            }

            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({ depots: [depot], meta: pagination }));
            }

            return Promise.resolve(jsonResponse({}));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();
        await flushPromises();

        const analyzeOverview = wrapper.find('[aria-label="Analyze overview"]');
        const appleCard = analyzeOverview.findAll('.analyze-holding-card')
            .find((button) => button.text().includes('Apple'));
        const allCard = analyzeOverview.findAll('.analyze-holding-card')
            .find((button) => button.text().includes('ALL'));

        expect(window.location.pathname).toBe('/admin/menu/analyze/overview');
        expect(window.location.search).toBe('?stock=1');
        expect(appleCard.attributes('aria-pressed')).toBe('true');
        expect(allCard.attributes('aria-pressed')).toBe('false');
        expect(analyzeOverview.text()).toContain('1 year');

        const detailTab = wrapper.findAll('.v-tab')
            .find((tab) => tab.text().includes('Detail'));
        await detailTab.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/analyze/detail');
        expect(window.location.search).toBe('?stock=1');
        expect(wrapper.find('[aria-label="Analyze detail"]').text()).toContain('Apple');
        expect(wrapper.find('[aria-label="Analyze detail"]').text()).toContain('Intraday 05.06.2026 - 5m');
        expect(wrapper.find('[aria-label="Analyze detail"]').text()).toContain('Intraday 04.06.2026 - 5m');
        expect(wrapper.find('[aria-label="Analyze detail"]').text()).toContain('Intraday 03.06.2026 - 5m');
        expect(wrapper.find('[aria-label="Analyze detail"]').text()).not.toContain('470.15000000');
        expect(wrapper.find('[aria-label="Analyze detail"]').text()).not.toContain('472.75000000');
        expect(wrapper.find('[aria-label="Analyze detail"]').text()).not.toContain('469.15000000');

        const firstIntradayDaySummary = wrapper.find('.analyze-detail-day-summary');
        expect(firstIntradayDaySummary.text()).toContain('First');
        expect(firstIntradayDaySummary.text()).toContain('Lowest');
        expect(firstIntradayDaySummary.text()).toContain('Highest');
        expect(firstIntradayDaySummary.text()).toContain('Ups');
        expect(firstIntradayDaySummary.text()).toContain('Downs');
        expect(firstIntradayDaySummary.text()).toContain('First -> 12:00+');
        expect(firstIntradayDaySummary.text()).toContain('12:00+ -> End');
        expect(firstIntradayDaySummary.text()).toContain('Last');
        expect(firstIntradayDaySummary.text()).toContain('470.15');
        expect(firstIntradayDaySummary.text()).toContain('469.25');
        expect(firstIntradayDaySummary.text()).toContain('472.75');
        expect(firstIntradayDaySummary.text()).toContain('Ups1');
        expect(firstIntradayDaySummary.text()).toContain('Downs2');
        expect(firstIntradayDaySummary.text()).toContain('+0.21%');
        expect(firstIntradayDaySummary.text()).toContain('+0.55%');
        expect(firstIntradayDaySummary.text()).toContain('-0.21%');
        expect(firstIntradayDaySummary.text()).not.toContain('3d start');
        expect(firstIntradayDaySummary.findAll('.analyze-detail-day-summary-item.is-compact')).toHaveLength(2);
        expect(firstIntradayDaySummary.findAll('.analyze-detail-day-summary-change.is-up')).toHaveLength(2);
        expect(firstIntradayDaySummary.text()).not.toContain('460.00');
        expect(firstIntradayDaySummary.text()).not.toContain('480.00');
        expect(firstIntradayDaySummary.text()).not.toContain('473.25');

        const thirdIntradayDaySummary = wrapper.findAll('.analyze-detail-day-summary')[2];
        expect(thirdIntradayDaySummary.text()).toContain('Last');
        expect(thirdIntradayDaySummary.text()).toContain('466.15');
        expect(thirdIntradayDaySummary.text()).toContain('-0.21%');

        const firstIntradayDayHeader = wrapper.find('.data-intraday-day-header');
        expect(firstIntradayDayHeader.attributes('aria-expanded')).toBe('false');
        await firstIntradayDayHeader.trigger('click');
        await wrapper.vm.$nextTick();

        expect(firstIntradayDayHeader.attributes('aria-expanded')).toBe('true');
        expect(wrapper.find('[aria-label="Analyze detail"]').text()).toContain('470.15000000');

        const detailStockMenu = wrapper.find('[aria-label="Analyze detail stocks"]');
        expect(detailStockMenu.exists()).toBe(true);
        expect(detailStockMenu.text()).toContain('Apple');
        expect(detailStockMenu.text()).toContain('Microsoft');
        expect(detailStockMenu.text()).toContain('Nvidia');

        const nvidiaButton = detailStockMenu.findAll('button')
            .find((button) => button.text().includes('Nvidia'));
        await nvidiaButton.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/analyze/detail');
        expect(window.location.search).toBe('?stock=3');
        expect(wrapper.find('[aria-label="Analyze detail"]').text()).toContain('Nvidia');
        expect(wrapper.find('[aria-label="Analyze detail"]').text()).not.toContain('924.15000000');
        expect(fetchMock).toHaveBeenCalledWith('/admin/watchlist/holdings/3/intraday-candles', expect.anything());
    });

    it('renders the Tests menu page with unpaginated index and stock selects', async () => {
        window.history.pushState({}, '', '/admin/menu/analyze/tests?stock=all');
        const pagination = { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null };
        const depot = { id: 1, name: 'Main depot', account_balance: '1000.00', is_active: true };
        const indices = [
            { id: 1, symbol: 'ATX', name: 'Austrian Traded Index' },
            { id: 2, symbol: 'DAX', name: 'DAX Index' },
        ];
        const stocks = Array.from({ length: 12 }, (_, index) => ({
            id: index + 1,
            symbol: `S${index + 1}`,
            name: `Stock ${index + 1}`,
        }));
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
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            if (path === '/admin/watchlist/holdings?page=1') {
                return Promise.resolve(jsonResponse({
                    depot,
                    holdings: [],
                    meta: pagination,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            if (path === '/admin/index-watch-items') {
                return Promise.resolve(jsonResponse({ indexes: [] }));
            }

            if (path === '/admin/watchlist/exchange-trading-times') {
                return Promise.resolve(jsonResponse({ exchange_trading_times: [] }));
            }

            if (path === '/admin/tests/options') {
                return Promise.resolve(jsonResponse({ indices, stocks }));
            }

            if (path === '/admin/tests/tickers') {
                return Promise.resolve(jsonResponse({
                    exchange_code: 'XETRA',
                    tickers: [
                        {
                            Code: 'AMES',
                            Name: 'Amundi IBEX 35 UCITS ETF Acc',
                            Exchange: 'XETRA',
                            Type: 'ETF',
                            Currency: 'EUR',
                            Isin: 'LU1681043599',
                        },
                        {
                            Code: 'LEER',
                            Name: 'Amundi MSCI Eastern Europe',
                            Exchange: 'XETRA',
                            Type: 'ETF',
                            Currency: 'EUR',
                            Isin: 'LU1681043912',
                        },
                    ],
                }));
            }

            if (path === '/admin/tests/exchanges') {
                return Promise.resolve(jsonResponse({
                    exchanges: [
                        {
                            Code: 'XETRA',
                            Name: 'XETRA',
                            Country: 'Germany',
                            Currency: 'EUR',
                            Timezone: 'Europe/Berlin',
                            exchange_detail_code: 'XETR',
                            OperatingMIC: 'XETR',
                        },
                        {
                            Code: 'NASDAQ',
                            Name: 'NASDAQ',
                            Country: 'USA',
                            Currency: 'USD',
                            Timezone: 'America/New_York',
                            exchange_detail_code: 'XNAS',
                            OperatingMIC: 'XNAS',
                        },
                    ],
                    exchange_details: {
                        XETR: {
                            Code: 'XETR',
                            Name: 'XETRA details',
                            TradingHours: '09:00-17:30',
                        },
                        XNAS: {
                            Code: 'XNAS',
                            Name: 'NASDAQ details',
                            TradingHours: '09:30-16:00',
                        },
                    },
                }));
            }

            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({ depots: [depot], meta: pagination }));
            }

            return Promise.reject(new Error(`Unexpected request: ${path}`));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();
        await flushPromises();

        const testsPage = wrapper.find('[aria-label="Analyze tests"]');
        expect(testsPage.exists()).toBe(true);
        expect(testsPage.text()).toContain('Indices');
        expect(testsPage.text()).toContain('Stocks');
        expect(testsPage.find('[aria-label="Test indices"]').exists()).toBe(true);
        expect(testsPage.find('[aria-label="Test stocks"]').exists()).toBe(true);
        expect(window.location.pathname).toBe('/admin/menu/analyze/tests');
        expect(wrapper.find('.dashboard-navigation-drawer').text()).not.toContain('Tests');
        expect(wrapper.findAll('.v-tab').map((tab) => tab.text()).some((label) => label.includes('Tests'))).toBe(true);
        expect(wrapper.vm.testOptions.indices).toHaveLength(2);
        expect(wrapper.vm.testOptions.stocks).toHaveLength(12);
        expect(fetchMock).toHaveBeenCalledWith('/admin/tests/options', expect.any(Object));
        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/tests/options?page=1')).toBe(false);

        const indexChips = testsPage.find('[aria-label="Test indices"]').findAll('button');
        const stockChips = testsPage.find('[aria-label="Test stocks"]').findAll('button');
        expect(indexChips).toHaveLength(2);
        expect(stockChips).toHaveLength(12);
        expect(indexChips[0].text()).toContain('ATX');
        expect(indexChips[0].text()).toContain('Austrian Traded Index');
        expect(stockChips[11].text()).toContain('S12');
        expect(stockChips[11].text()).toContain('Stock 12');

        await indexChips[0].trigger('click');
        await stockChips[11].trigger('click');

        expect(wrapper.vm.selectedTestIndexId).toBe(1);
        expect(wrapper.vm.selectedTestStockId).toBe(12);
        expect(indexChips[0].attributes('aria-pressed')).toBe('true');
        expect(stockChips[11].attributes('aria-pressed')).toBe('true');

        const testTabs = wrapper.findAll('.v-tab').filter((tab) => ['Tickers', 'Exchanges'].includes(tab.text()));
        expect(testTabs).toHaveLength(2);
        expect(wrapper.vm.selectedTestTab).toBe('tickers');
        expect(testsPage.text()).toContain('Exchange: XETRA');
        expect(testsPage.find('[aria-label="Ticker result"]').classes()).toContain('tests-ticker-panel');

        const loadTickersButton = testsPage.findAll('button').find((button) => button.text().includes('Load'));
        await loadTickersButton.trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/tests/tickers', expect.any(Object));
        expect(wrapper.vm.testTickers).toHaveLength(2);
        expect(testsPage.text()).toContain('AMES');
        expect(testsPage.text()).toContain('Amundi IBEX 35 UCITS ETF Acc');
        expect(testsPage.text()).toContain('LU1681043599');

        await testTabs[1].trigger('click');
        await flushPromises();

        expect(wrapper.vm.selectedTestTab).toBe('exchanges');
        expect(wrapper.find('[aria-label="Exchange result"]').classes()).toContain('tests-ticker-panel');

        const exchangeLoadButton = wrapper.find('[aria-label="Exchange result"]')
            .findAll('button')
            .find((button) => button.text().includes('Load'));
        await exchangeLoadButton.trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/tests/exchanges', expect.any(Object));
        expect(wrapper.vm.testExchanges).toHaveLength(2);
        expect(wrapper.vm.testExchangeDetails.XNAS.Name).toBe('NASDAQ details');
        expect(wrapper.find('[aria-label="Exchange result"]').text()).toContain('XETRA');
        expect(wrapper.find('[aria-label="Exchange result"]').text()).toContain('Germany');
        expect(wrapper.find('[aria-label="Exchange result"]').text()).toContain('Europe/Berlin');
        expect(wrapper.find('[aria-label="Exchange result"]').text()).toContain('NASDAQ details');
        expect(wrapper.find('[aria-label="Exchange result"]').text()).toContain('09:30-16:00');
    });

    it('renders a Today chart with the actual stored EODHD intraday rows', async () => {
        window.history.pushState({}, '', '/admin/menu/analyze/overview?stock=1');
        const pagination = { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null };
        const depot = { id: 1, name: 'Main depot', account_balance: '1000.00', is_active: true };
        const fetchMock = vi.fn((path, options = {}) => {
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
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            if (path === '/admin/watchlist/holdings?page=1') {
                return Promise.resolve(jsonResponse({
                    depot,
                    holdings: [
                        {
                            id: 1,
                            symbol: 'AAPL',
                            name: 'Apple',
                            currency: 'EUR',
                            latest_price: '307.250000',
                            daily_prices: [
                                { trading_date: '2026-06-05', price: '307.250000', currency: 'EUR' },
                            ],
                            intraday_prices: [
                                { id: 1, price: '307.25000000', currency: 'EUR', as_of: '2026-06-05T10:30:00+00:00' },
                            ],
                        },
                    ],
                    meta: pagination,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            if (path === '/admin/watchlist/exchange-trading-times') {
                return Promise.resolve(jsonResponse({ exchange_trading_times: [] }));
            }

            if (path === '/admin/watchlist/holdings/historical-prices/ensure' && options?.method === 'POST') {
                return Promise.resolve(jsonResponse({
                    message: 'Historical stock prices are available.',
                    coverage: null,
                    refresh: null,
                }));
            }

            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({ depots: [depot], meta: pagination }));
            }

            return Promise.resolve(jsonResponse({}));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();
        await flushPromises();

        const todayRangeButton = wrapper.find('[aria-label="Analyze overview"]').findAll('.analyze-range-button')
            .find((button) => button.text() === 'today');
        await todayRangeButton.trigger('click');
        await flushPromises();

        const linePath = wrapper.find('.analyze-sparkline-line').attributes('d');
        const areaPath = wrapper.find('.analyze-sparkline-area').attributes('d');

        expect(linePath).toContain(' L ');
        expect(areaPath).toBe('');
        expect(wrapper.find('.analyze-sparkline-trend-line').exists()).toBe(false);
        expect(wrapper.findAll('.analyze-sparkline-dot')).toHaveLength(1);
        expect(wrapper.find('.analyze-sparkline-endpoint-label--start').text()).toBe('Start 307.25');
        expect(wrapper.find('.analyze-sparkline-endpoint-label--latest').text()).toBe('End 307.25');
        expect(wrapper.text()).not.toContain('No EODHD intraday prices available for this session.');
    });

    it('shows historical stock price fetch progress on the Analyze overview', async () => {
        window.history.pushState({}, '', '/admin/menu/analyze/overview');
        const pagination = { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null };
        const depot = { id: 1, name: 'Main depot', account_balance: '1000.00', is_active: true };
        const runningHistoryRefresh = {
            refresh_id: 'history-1',
            status: 'running',
            processed: 1,
            total: 2,
            step: '1/2',
            message: 'Fetching historical stock prices (1/2)...',
            current: 'AAPL Apple',
            started_at: '2026-06-05T12:00:00+00:00',
            finished_at: null,
            error: null,
        };
        const historyCoverage = {
            date_from: '2025-06-05',
            date_to: '2026-06-05',
            required_to: '2026-06-04',
            is_complete: false,
            total_count: 2,
            available_count: 1,
            missing_count: 1,
            holdings: [],
        };
        const fetchMock = vi.fn((path, options = {}) => {
            if (path === '/admin/me') {
                return Promise.resolve(jsonResponse({
                    user: { id: 1, name: 'Admin User', email: 'admin@example.com', roles: ['admin'] },
                }));
            }

            if (path === '/admin/depots/active') {
                return Promise.resolve(jsonResponse({
                    depot,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            if (path === '/admin/watchlist/holdings?page=1') {
                return Promise.resolve(jsonResponse({
                    depot,
                    holdings: [
                        { id: 1, symbol: 'AAPL', name: 'Apple', currency: 'USD', latest_price: '190.000000' },
                        { id: 2, symbol: 'MSFT', name: 'Microsoft', currency: 'USD', latest_price: '430.120000' },
                    ],
                    meta: pagination,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            if (path === '/admin/watchlist/holdings/historical-prices/ensure' && options.method === 'POST') {
                return Promise.resolve(jsonResponse({
                    message: 'Historical stock prices are being fetched.',
                    coverage: historyCoverage,
                    refresh: runningHistoryRefresh,
                }));
            }

            if (path === '/admin/watchlist/holdings/historical-prices/history-1') {
                return Promise.resolve(jsonResponse({
                    message: runningHistoryRefresh.message,
                    coverage: historyCoverage,
                    refresh: runningHistoryRefresh,
                }));
            }

            if (path === '/admin/watchlist/exchange-trading-times') {
                return Promise.resolve(jsonResponse({ exchange_trading_times: [] }));
            }

            if (path === '/admin/index-watch-items') {
                return Promise.resolve(jsonResponse({ indexes: [] }));
            }

            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({ depots: [depot], meta: pagination }));
            }

            return Promise.resolve(jsonResponse({}));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();
        await flushPromises();
        await flushPromises();

        const analyzeOverview = wrapper.find('[aria-label="Analyze overview"]');

        expect(analyzeOverview.text()).toContain('Checking historical prices');
        expect(analyzeOverview.text()).toContain('1/2');
        expect(analyzeOverview.text()).toContain('AAPL Apple');
        expect(analyzeOverview.find('.v-progress-linear').exists()).toBe(true);

        wrapper.unmount();
    });

    it('shows loaded historical stock price records after a finished partial update', async () => {
        window.history.pushState({}, '', '/admin/menu/analyze/overview');
        const pagination = { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null };
        const depot = { id: 1, name: 'Main depot', account_balance: '1000.00', is_active: true };
        const partialHistoryRefresh = {
            refresh_id: 'history-1',
            status: 'partial',
            processed: 2,
            total: 2,
            step: '2/2',
            message: 'Historical stock price fetching finished with missing data.',
            current: null,
            started_at: '2026-06-05T12:00:00+00:00',
            finished_at: '2026-06-05T12:05:00+00:00',
            error: null,
            success_count: 0,
            unavailable_count: 2,
            failed_count: 0,
            stored_count: 0,
        };
        const historyCoverage = {
            date_from: '2025-06-05',
            date_to: '2026-06-05',
            required_to: '2026-06-04',
            is_complete: false,
            total_count: 2,
            available_count: 0,
            missing_count: 2,
            holdings: [],
        };
        const fetchMock = vi.fn((path, options = {}) => {
            if (path === '/admin/me') {
                return Promise.resolve(jsonResponse({
                    user: { id: 1, name: 'Admin User', email: 'admin@example.com', roles: ['admin'] },
                }));
            }

            if (path === '/admin/depots/active') {
                return Promise.resolve(jsonResponse({
                    depot,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            if (path === '/admin/watchlist/holdings?page=1') {
                return Promise.resolve(jsonResponse({
                    depot,
                    holdings: [
                        { id: 1, symbol: 'AAPL', name: 'Apple', currency: 'USD', latest_price: '190.000000', daily_prices: [] },
                        { id: 2, symbol: 'MSFT', name: 'Microsoft', currency: 'USD', latest_price: '430.120000', daily_prices: [] },
                    ],
                    meta: pagination,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            if (path === '/admin/watchlist/holdings/historical-prices/ensure' && options.method === 'POST') {
                return Promise.resolve(jsonResponse({
                    message: partialHistoryRefresh.message,
                    coverage: historyCoverage,
                    refresh: partialHistoryRefresh,
                }));
            }

            if (path === '/admin/watchlist/exchange-trading-times') {
                return Promise.resolve(jsonResponse({ exchange_trading_times: [] }));
            }

            if (path === '/admin/index-watch-items') {
                return Promise.resolve(jsonResponse({ indexes: [] }));
            }

            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({ depots: [depot], meta: pagination }));
            }

            return Promise.resolve(jsonResponse({}));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();
        await flushPromises();
        await flushPromises();

        const analyzeOverview = wrapper.find('[aria-label="Analyze overview"]');

        expect(analyzeOverview.text()).not.toContain('History');
        expect(analyzeOverview.text()).toContain('0 historical price records loaded/updated.');
        expect(analyzeOverview.text()).not.toContain('2 missing');
        expect(analyzeOverview.text()).not.toContain('2/2');
        expect(analyzeOverview.find('.v-progress-linear').exists()).toBe(false);

        wrapper.unmount();
    });

    it('shows the watch-list stocks on the dashboard', async () => {
        window.history.pushState({}, '', '/admin/dashboard');
        let currentPriceRefreshSettings = priceRefreshSettings();
        const currentIndexPriceRefreshSettings = indexPriceRefreshSettings({
            last_refreshed_at: '2026-06-02T13:00:00+00:00',
            next_refresh_at: '2026-06-02T13:30:00+00:00',
        });
        const currentBerlinDate = localDateKey('Europe/Berlin');
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
                    app_version: '0.1.5',
                    price_refresh_settings: currentPriceRefreshSettings,
                    index_price_refresh_settings: currentIndexPriceRefreshSettings,
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
                            end_price_24: '299.500000',
                            end_price_48: '298.750000',
                            start_price_date: '2026-06-05',
                            end_price_date: '2026-06-05',
                            end_price_24_date: '2026-06-04',
                            end_price_48_date: '2026-06-03',
                            historical_prices_fetching: true,
                            latest_price_trend: 'up',
                            latest_price_change_pct: '2.28',
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
                            latest_price: null,
                            start_price: '183.000000',
                            end_price: '195.000000',
                            end_price_24: '184.000000',
                            end_price_48: '185.000000',
                            latest_price_trend: null,
                            latest_price_change_pct: null,
                            latest_price_tick_trend: null,
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
                            recent_prices_are_fallback: true,
                            recent_prices: [
                                {
                                    id: 30,
                                    price: '194.00000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-02T14:30:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                                {
                                    id: 31,
                                    price: '195.00000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-02T15:30:00+00:00',
                                    source_name: 'Tradegate Exchange',
                                    price_type: 'calculated_median',
                                },
                            ],
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
                            end_price_24: '100.000000',
                            end_price_48: '100.000000',
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
                    index_price_refresh_settings: currentIndexPriceRefreshSettings,
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
                            sessions: [
                                { open: '09:00:00', close: '17:30:00' },
                            ],
                            holidays: [currentBerlinDate],
                            error: null,
                        },
                    ],
                }));
            }

            if (path === '/admin/queue/status') {
                return Promise.resolve(jsonResponse({
                    queue: {
                        status: 'ok',
                        connection: 'sync',
                        name: 'default',
                        pending: 0,
                        reserved: 0,
                        failed: 0,
                        retry_after: 2100,
                        max_job_timeout: 1800,
                        issues: [],
                    },
                }));
            }

            if (path === '/admin/price-refresh-settings' && options?.method === 'PATCH') {
                currentPriceRefreshSettings = priceRefreshSettings({
                    trading_interval_minutes: 15,
                    trading_starts_before_minutes: 8,
                    trading_ends_after_minutes: 12,
                    closed_refresh_enabled: false,
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

            if (path === '/admin/index-watch-items' && (!options?.method || options.method === 'GET')) {
                return Promise.resolve(jsonResponse({
                    indexes: [],
                }));
            }

            if (path === '/admin/index-watch-items' && options?.method === 'POST') {
                return Promise.resolve(jsonResponse({
                    message: 'Index added.',
                    index: {
                        id: 1,
                        symbol: 'DAX',
                        name: 'DAX Index',
                        isin: 'DE0008469008',
                        exchange: 'XETRA',
                        mic_code: 'XETR',
                        instrument_type: 'INDEX',
                        country: 'Germany',
                        currency: 'EUR',
                        latest_price: '6116.529800',
                        last_price: '6096.169900',
                        latest_price_change_pct: '0.33',
                        recent_prices: [],
                    },
                }));
            }

            if (path === '/admin/index-watch-items/1/prices/ensure' && options?.method === 'POST') {
                return Promise.resolve(jsonResponse({
                    message: 'Index prices loaded.',
                    index: {
                        id: 1,
                        symbol: 'DAX',
                        name: 'DAX Index',
                        isin: 'DE0008469008',
                        exchange: 'XETRA',
                        mic_code: 'XETR',
                        instrument_type: 'INDEX',
                        country: 'Germany',
                        currency: 'EUR',
                        latest_price: '6116.529800',
                        last_price: '6096.169900',
                        latest_price_change_pct: '0.33',
                        recent_prices: indexMonthPrices(),
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

            if (path === '/admin/stocks/search?query=DAX') {
                return Promise.resolve(jsonResponse({
                    results: [
                        {
                            symbol: 'DAX',
                            name: 'DAX Index',
                            isin: 'DE0008469008',
                            exchange: 'XETRA',
                            mic_code: 'XETR',
                            instrument_type: 'INDEX',
                            country: 'Germany',
                            currency: 'EUR',
                        },
                    ],
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
        expect(dashboardHeaders[0]).toBe('Symbol');
        expect(dashboardHeaders[1]).toBe('Name');
        expect(dashboardHeaders[2]).toBe('Latest price');
        expect(dashboardHeaders[3]).toContain('Start price');
        expect(dashboardHeaders[3]).toContain('05.06.2026');
        expect(dashboardHeaders[4]).toContain('End price');
        expect(dashboardHeaders[4]).toContain('05.06.2026');
        expect(dashboardHeaders[4]).not.toContain('Yesterday');
        expect(dashboardHeaders[5]).toContain('End 24');
        expect(dashboardHeaders[5]).toContain('04.06.2026');
        expect(dashboardHeaders[5]).not.toContain('Yesterday');
        expect(dashboardHeaders[6]).toContain('End 48');
        expect(dashboardHeaders[6]).toContain('03.06.2026');
        expect(dashboardHeaders[6]).not.toContain('Day before yesterday');
        expect(dashboardHeaders[7]).toBe('Source time');
        expect(dashboardHeaders[8]).toBe('Actions');
        const dashboardStatusText = wrapper.get('.dashboard-status-card').text();
        expect(wrapper.get('.app-bar-row').text()).not.toContain('Stocks Last:');
        expect(dashboardStatusText).toContain('Stocks Last:');
        expect(dashboardStatusText).toContain('Indices Last:');
        expect(dashboardStatusText).not.toContain('EODHD API');
        expect(dashboardStatusText).not.toContain('Hour 988 / 1,000 Used 12');
        expect(dashboardStatusText).not.toContain('Day 98,805 / 100,000 Used 1,195');
        expect(dashboardStatusText).toContain('fetching historical data');
        expect(dashboardStatusText.match(/waiting/g)).toHaveLength(1);
        expect(wrapper.find('[aria-label="Minify dashboard menu"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Watch-list');
        expect(wrapper.text()).not.toContain('Free calls remaining');
        expect(wrapper.find('.dashboard-navigation-drawer').classes()).not.toContain('dashboard-navigation-drawer--compact');
        expect(wrapper.find('.dashboard-navigation-drawer').text()).toContain('GKStocks');
        expect(wrapper.find('.dashboard-navigation-drawer').text()).toContain('0.1.5');
        expect(wrapper.find('.dashboard-brand-version').exists()).toBe(true);
        expect(wrapper.find('.dashboard-navigation-drawer').text()).not.toContain('Admin User');
        await wrapper.find('[aria-label="Minify dashboard menu"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('.dashboard-navigation-drawer').classes()).toContain('dashboard-navigation-drawer--compact');
        expect(wrapper.find('.dashboard-navigation-drawer').text()).not.toContain('GKStocks');
        expect(wrapper.find('[aria-label="Enhance dashboard menu"]').exists()).toBe(true);
        await wrapper.find('[aria-label="Enhance dashboard menu"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('.dashboard-navigation-drawer').classes()).not.toContain('dashboard-navigation-drawer--compact');
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
        expect(wrapper.text()).toContain('+2.14%');
        expect(wrapper.text()).toContain('DOWN');
        expect(wrapper.text()).toContain('195');
        expect(wrapper.text()).toContain('FLAT');
        expect(wrapper.text()).toContain('100');

        const holdingRows = wrapper.findAll('tbody tr');
        const upPriceValue = holdingRows[0].findAll('td')[2].find('.latest-price-value');
        const downPriceValue = holdingRows[1].findAll('td')[2].find('.latest-price-value');
        const upEndPriceCell = holdingRows[0].findAll('td')[4];
        const downEndPriceCell = holdingRows[1].findAll('td')[4];
        const upEndPriceValue = upEndPriceCell.find('.latest-price-value');
        const downEndPriceValue = downEndPriceCell.find('.latest-price-value');
        const upStartPriceTick = holdingRows[0].findAll('td')[3].find('[aria-label="Start price higher than End 24 price"]');
        const downStartPriceTick = holdingRows[1].findAll('td')[3].find('[aria-label="Start price lower than End 24 price"]');
        expect(holdingRows[0].findAll('td')[0].text()).toContain('Exchange: NASDAQ');
        expect(holdingRows[0].findAll('td')[0].text()).toContain('Pieces: 0');
        expect(holdingRows[0].findAll('td')[1].text()).not.toContain('Exchange: NASDAQ');
        expect(holdingRows[0].findAll('td')[1].text()).not.toContain('Pieces: 0');
        expect(holdingRows[0].findAll('td')[1].text()).toContain('US0378331005 · WKN: 865985');
        expect(holdingRows[0].findAll('td')[2].classes()).not.toContain('bg-success');
        expect(holdingRows[1].findAll('td')[2].classes()).not.toContain('bg-error');
        expect(upPriceValue.classes()).toContain('bg-success');
        expect(upPriceValue.classes()).toContain('text-white');
        expect(downPriceValue.classes()).not.toContain('bg-success');
        expect(downPriceValue.classes()).not.toContain('bg-error');
        expect(downPriceValue.text()).toBe('-');
        expect(upPriceValue.text()).toContain('+2.28%');
        expect(downPriceValue.text()).not.toContain('+5.98%');
        expect(upEndPriceCell.text()).toContain('+2.14%');
        expect(upEndPriceCell.classes()).not.toContain('bg-success');
        expect(upEndPriceValue.classes()).toContain('bg-success');
        expect(upEndPriceValue.classes()).toContain('text-white');
        expect(downEndPriceCell.text()).toContain('+5.98%');
        expect(downEndPriceCell.classes()).not.toContain('bg-success');
        expect(downEndPriceValue.classes()).toContain('bg-success');
        expect(downEndPriceValue.classes()).toContain('text-white');
        expect(upStartPriceTick.exists()).toBe(true);
        expect(upStartPriceTick.text()).toBe('↑');
        expect(upStartPriceTick.classes()).toContain('text-success');
        expect(downStartPriceTick.exists()).toBe(true);
        expect(downStartPriceTick.text()).toBe('↓');
        expect(downStartPriceTick.classes()).toContain('text-error');
        expect(holdingRows[2].findAll('td')[3].find('.latest-price-tick').exists()).toBe(false);
        const upEnd24Tick = holdingRows[0].findAll('td')[5].find('[aria-label="End 24 price higher than End 48 price"]');
        const downEnd24Tick = holdingRows[1].findAll('td')[5].find('[aria-label="End 24 price lower than End 48 price"]');
        expect(upEnd24Tick.exists()).toBe(true);
        expect(upEnd24Tick.text()).toBe('↑');
        expect(upEnd24Tick.classes()).toContain('text-success');
        expect(downEnd24Tick.exists()).toBe(true);
        expect(downEnd24Tick.text()).toBe('↓');
        expect(downEnd24Tick.classes()).toContain('text-error');
        expect(holdingRows[2].findAll('td')[5].find('.latest-price-tick').exists()).toBe(false);
        expect(holdingRows[0].findAll('td')[5].text()).not.toContain('%');
        expect(holdingRows[0].findAll('td')[6].text()).not.toContain('%');
        expect(wrapper.html()).toContain('latest-price-tick');
        expect(wrapper.text()).toContain('↑');
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

        const closedMarketHoldingRow = wrapper.findAll('.stock-holding-row')[1];
        await closedMarketHoldingRow.trigger('click');
        await flushPromises();

        const fallbackRecentPriceStrip = wrapper.find('.recent-price-strip');
        expect(fallbackRecentPriceStrip.text()).toContain('No stored prices in the last 24 hours.');
        expect(fallbackRecentPriceStrip.text()).toContain('Showing values from 02.06.2026.');
        expect(fallbackRecentPriceStrip.text()).toContain('194');
        expect(fallbackRecentPriceStrip.text()).toContain('195');

        await closedMarketHoldingRow.trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('03.06.2026, 17:35');
        expect(wrapper.text()).not.toContain('Monday-Friday 08:00-22:00 Europe/Berlin');
        expect(wrapper.text()).toContain('Exchange trading times');
        expect(wrapper.text()).toContain('XETRA');
        expect(wrapper.text()).toContain('XETRA Stock Exchange');
        expect(wrapper.text()).toContain('09:00-17:30');
        const exchangeDetailsTable = wrapper.findAll('table')[1];
        expect(exchangeDetailsTable.findAll('thead th').map((header) => header.text())).toContain('Next trading');
        const exchangeDetailsCells = exchangeDetailsTable.find('tbody tr').findAll('td');
        expect(exchangeDetailsCells[0].text()).toContain('XETRA');
        expect(exchangeDetailsCells[0].text()).toContain('XETRA Stock Exchange');
        expect(exchangeDetailsCells[0].text()).not.toContain('Europe/Berlin');
        expect(exchangeDetailsCells[1].text()).toContain('XETR');
        expect(exchangeDetailsCells[1].text()).toContain('Europe/Berlin');
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
        expect(dashboardStatusText).not.toContain('Admin User');
        expect(wrapper.text()).toContain('Stocks Last: 02.06.2026, 14:20');
        expect(wrapper.text()).toContain('Next: 02.06.2026, 14:40');
        expect(wrapper.text()).toContain('Indices Last: 02.06.2026, 15:00');
        expect(wrapper.text()).toContain('Next: 02.06.2026, 15:30');
        expect(wrapper.text()).toContain('fetching historical data');
        expect(wrapper.text()).not.toContain('Automatic price refresh');

        wrapper.vm.navigateSection('updates');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/updates');
        expect(wrapper.find('.dashboard-status-card').exists()).toBe(false);
        expect(wrapper.text()).toContain('Updates');
        expect(wrapper.text()).toContain('Automatic price refresh');
        expect(wrapper.text()).toContain('Automatic index price refresh');
        expect(wrapper.text()).toContain('Current interval: 20 min');
        expect(wrapper.text()).toContain('Edit');
        expect(wrapper.text()).toContain('During trading');
        expect(wrapper.text()).toContain('Start before trading');
        expect(wrapper.text()).toContain('End after trading');
        expect(wrapper.text()).toContain('Outside trading');
        expect(wrapper.text()).toContain('Outside trading interval');

        expect(wrapper.find('#price-refresh-schedule-form').findAll('input')).toHaveLength(0);
        expect(wrapper.find('#index-price-refresh-schedule-form').findAll('input')).toHaveLength(0);

        const editScheduleButton = wrapper.find('#price-refresh-schedule-form').findAll('button').find((button) => button.text().includes('Edit'));
        const editIndexScheduleButton = wrapper.find('#index-price-refresh-schedule-form').findAll('button').find((button) => button.text().includes('Edit'));
        expect(editScheduleButton.attributes('disabled')).toBeUndefined();
        expect(editIndexScheduleButton.attributes('disabled')).toBeUndefined();
        await editScheduleButton.trigger('click');
        await flushPromises();

        const scheduleInputs = wrapper.find('#price-refresh-schedule-form').findAll('input[type="number"]');
        expect(scheduleInputs).toHaveLength(4);
        expect(wrapper.find('#index-price-refresh-schedule-form').findAll('input[type="number"]')).toHaveLength(0);
        expect(editIndexScheduleButton.attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Save');

        await scheduleInputs[0].setValue('15');
        await scheduleInputs[1].setValue('8');
        await scheduleInputs[2].setValue('12');
        await scheduleInputs[3].setValue('45');
        wrapper.vm.priceRefreshScheduleForm.closed_refresh_enabled = false;
        await wrapper.vm.$nextTick();
        await wrapper.find('#price-refresh-schedule-form').trigger('submit');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/price-refresh-settings', expect.objectContaining({
            method: 'PATCH',
            body: JSON.stringify({
                trading_interval_minutes: 15,
                trading_starts_before_minutes: 8,
                trading_ends_after_minutes: 12,
                closed_refresh_enabled: false,
                closed_interval_minutes: 45,
            }),
        }));
        expect(fetchMock).toHaveBeenCalledWith('/admin/watchlist/holdings/refresh-prices/settings-refresh-1', expect.any(Object));
        expect(wrapper.text()).toContain('Price refresh schedule updated.');
        expect(wrapper.text()).toContain('Current interval: 15 min');
        expect(wrapper.find('#price-refresh-schedule-form').text()).toContain('Off');
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

        const addIndexButton = wrapper.findAll('button').find((button) => button.text().includes('INDEX'));
        await addIndexButton.trigger('click');
        await flushPromises();

        expect(document.body.textContent).toContain('Add index');

        const indexSearchInput = document.body.querySelector('#index-search-form input');
        expect(document.activeElement).toBe(indexSearchInput);

        indexSearchInput.value = 'DAX';
        indexSearchInput.dispatchEvent(new Event('input', { bubbles: true }));
        document.body.querySelector('#index-search-form').dispatchEvent(new Event('submit', {
            bubbles: true,
            cancelable: true,
        }));
        await flushPromises();

        expect(document.body.textContent).toContain('DAX Index');
        expect(document.body.textContent).toContain('DE0008469008');

        const addIndexResultButton = Array.from(document.body.querySelectorAll('button'))
            .filter((button) => button.textContent.trim() === 'Add')
            .at(-1);
        addIndexResultButton.click();
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/index-watch-items', expect.objectContaining({
            method: 'POST',
            body: JSON.stringify({
                symbol: 'DAX',
                name: 'DAX Index',
                isin: 'DE0008469008',
                exchange: 'XETRA',
                mic_code: 'XETR',
                instrument_type: 'INDEX',
                country: 'Germany',
                currency: 'EUR',
            }),
        }));
        expect(wrapper.vm.holdingMessage).toBe('Index added.');
        expect(fetchMock).toHaveBeenCalledWith('/admin/index-watch-items', expect.any(Object));

        const indexWatchStrip = wrapper.find('.index-watch-strip');
        expect(indexWatchStrip.text()).toContain('DAX');
        expect(indexWatchStrip.text()).toContain('Germany');
        expect(indexWatchStrip.text()).toContain('DAX Index');
        expect(indexWatchStrip.text()).toContain('+0.33%');
        expect(indexWatchStrip.text()).toContain('6,116.5298');
        expect(wrapper.find('.index-watch-card').text()).not.toContain('2026-06-07');
        expect(wrapper.find('.index-watch-card').text()).not.toContain('DE0008469008');
        expect(indexWatchStrip.text().indexOf('DAX')).toBeLessThan(indexWatchStrip.text().indexOf('+INDEX'));

        await wrapper.find('.index-watch-card').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/index-watch-items/1/prices/ensure', expect.objectContaining({
            method: 'POST',
        }));
        expect(document.body.textContent).toContain('Actual price');
        expect(document.body.textContent).toContain('Last');
        expect(document.body.textContent).toContain('Evolution');
        expect(document.body.textContent).toContain('07.06.2026');
        expect(document.body.textContent).toContain('09.05.2026');
        expect(document.body.textContent).toContain('6,116.5298');
        expect(document.body.querySelectorAll('.index-price-history-table tbody tr')).toHaveLength(30);
        expect(Array.from(document.body.querySelectorAll('.index-price-history-table th')).map((heading) => heading.textContent.trim())).toEqual([
            'Date',
            'Start',
            'Last',
        ]);
        expect(document.body.querySelector('.index-price-chart-line')).not.toBeNull();
        expect(document.body.querySelector('.index-price-chart-trend-line')).not.toBeNull();
        expect(Number(document.body.querySelector('.index-price-chart-trend-line').getAttribute('x2'))).toBeGreaterThan(
            Number(document.body.querySelector('.index-price-chart-trend-line').getAttribute('x1')),
        );
        expect(document.body.querySelectorAll('.index-price-chart-point')).toHaveLength(30);
        expect(document.body.querySelectorAll('.index-price-chart-grid-line')).toHaveLength(12);
        expect(document.body.querySelectorAll('.index-price-chart-y-label')).toHaveLength(5);
        expect(document.body.querySelectorAll('.index-price-chart-x-label')).toHaveLength(7);
        expect(document.body.querySelectorAll('.index-price-chart-endpoint-label')).toHaveLength(2);
        expect(document.body.querySelector('.index-price-chart-endpoint-label--start').textContent.trim()).toMatch(/^Start /);
        expect(document.body.querySelector('.index-price-chart-endpoint-label--latest').textContent.trim()).toMatch(/^End /);
        expect(Number(document.body.querySelector('.index-price-chart-endpoint-label--start').getAttribute('y'))).toBeGreaterThan(220);
        expect(Number(document.body.querySelector('.index-price-chart-endpoint-label--latest').getAttribute('y'))).toBeLessThan(10);
        expect(document.body.textContent).toContain('6,116.5298');
        expect(document.body.textContent).toContain('09.05');

        const closeIndexPriceButton = Array.from(document.body.querySelectorAll('button'))
            .find((button) => button.textContent.trim() === 'Close');
        closeIndexPriceButton.click();
        await flushPromises();

        expect(wrapper.vm.isIndexPriceDialogOpen).toBe(false);

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

    it('disables the delete button for holdings with position pieces', async () => {
        window.history.pushState({}, '', '/admin/dashboard');
        const pagination = { current_page: 1, last_page: 1, per_page: 10, total: 2, from: 1, to: 2 };
        const depot = { id: 1, name: 'Main depot', account_balance: '1000.00', is_active: true };
        const holdingWithPieces = {
            id: 1, symbol: 'AAPL', name: 'Apple', isin: 'US0378331005', wkn: '865985',
            exchange: 'NASDAQ', currency: 'EUR', latest_price: '100.000000',
            start_price: null, end_price: null, end_price_24: null, end_price_48: null,
            historical_prices_fetching: false, position_pieces: '3.00000000',
            latest_price_status: 'fresh', price_status: 'fresh',
            latest_price_fetched_at: '2026-06-02T12:00:00+00:00',
            latest_price_source: null, latest_price_source_url: null,
            latest_price_as_of: '2026-06-03T15:35:00+00:00',
            trading_times: null, venue: null, price_type: null,
            price_spread_pct: null, recent_prices: [], validation_errors: [],
        };
        const holdingWithoutPieces = {
            ...holdingWithPieces,
            id: 2, symbol: 'MSFT', name: 'Microsoft', isin: 'US5949181045',
            position_pieces: '0.00000000',
        };
        const fetchMock = vi.fn((path) => {
            if (path === '/admin/me') {
                return Promise.resolve(jsonResponse({ user: { id: 1, name: 'Admin', email: 'a@b.com', roles: ['admin'] } }));
            }
            if (path === '/admin/depots/active') {
                return Promise.resolve(jsonResponse({ depot, price_refresh_settings: priceRefreshSettings() }));
            }
            if (path === '/admin/watchlist/holdings?page=1') {
                return Promise.resolve(jsonResponse({
                    depot, holdings: [holdingWithPieces, holdingWithoutPieces],
                    meta: pagination, price_refresh_settings: priceRefreshSettings(),
                }));
            }
            if (path === '/admin/watchlist/exchange-trading-times') {
                return Promise.resolve(jsonResponse({ exchange_trading_times: [] }));
            }
            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({ depots: [depot], meta: pagination }));
            }
            return Promise.resolve(jsonResponse({}));
        });
        global.fetch = fetchMock;
        const wrapper = mountApp();
        await flushPromises();

        const deleteButtons = wrapper.findAll('[aria-label="Delete stock"]');
        expect(deleteButtons).toHaveLength(2);
        expect(deleteButtons[0].attributes('disabled')).toBeDefined();
        expect(deleteButtons[1].attributes('disabled')).toBeUndefined();
    });

    it('shows the next Shanghai trading session during the lunch break', async () => {
        window.history.pushState({}, '', '/admin/dashboard');
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-06-05T04:06:00Z'));

        try {
            const pagination = { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null };
            const depot = { id: 1, name: 'Main depot', account_balance: '1000.00', is_active: true };
            const fetchMock = vi.fn((path) => {
                if (path === '/admin/me') {
                    return Promise.resolve(jsonResponse({
                        user: { id: 1, name: 'Admin', email: 'a@b.com', roles: ['admin'] },
                    }));
                }
                if (path === '/admin/depots/active') {
                    return Promise.resolve(jsonResponse({
                        depot,
                        price_refresh_settings: priceRefreshSettings(),
                        index_price_refresh_settings: indexPriceRefreshSettings(),
                    }));
                }
                if (path === '/admin/watchlist/holdings?page=1') {
                    return Promise.resolve(jsonResponse({
                        depot,
                        holdings: [],
                        meta: pagination,
                        price_refresh_settings: priceRefreshSettings(),
                        index_price_refresh_settings: indexPriceRefreshSettings(),
                    }));
                }
                if (path === '/admin/watchlist/exchange-trading-times') {
                    return Promise.resolve(jsonResponse({
                        exchange_trading_times: [
                            {
                                code: 'SHG',
                                name: 'Shanghai Stock Exchange',
                                operating_mic: 'XSHG',
                                country: 'China',
                                currency: 'CNY',
                                timezone: 'Asia/Shanghai',
                                is_open: false,
                                open: '09:30:00',
                                close: '15:00:00',
                                lunch_begin: '11:30:00',
                                lunch_end: '13:00:00',
                                open_utc: '01:30:00',
                                close_utc: '07:00:00',
                                working_days: 'Mon,Tue,Wed,Thu,Fri',
                                sessions: [
                                    { open: '09:30:00', close: '11:30:00' },
                                    { open: '13:00:00', close: '15:00:00' },
                                ],
                                holidays: [],
                                error: null,
                            },
                        ],
                    }));
                }
                if (path === '/admin/depots?page=1') {
                    return Promise.resolve(jsonResponse({ depots: [depot], meta: pagination }));
                }

                return Promise.resolve(jsonResponse({}));
            });
            global.fetch = fetchMock;

            const wrapper = mountApp();
            await flushPromises();

            const exchangeRow = wrapper.findAll('tbody tr')
                .find((row) => row.text().includes('SHG'));

            expect(exchangeRow.text()).toContain('Shanghai Stock Exchange');
            expect(exchangeRow.text()).toContain('09:30-11:30, 13:00-15:00');
            expect(exchangeRow.text()).toContain('Next trading: 05.06.2026, 13:00');
            expect(exchangeRow.text()).toContain('Closed');
        } finally {
            vi.useRealTimers();
        }
    });

    it('allows editing the index price refresh schedule via the index card Edit button', async () => {
        window.history.pushState({}, '', '/admin/menu/updates');
        const depot = { id: 1, name: 'Main depot', account_balance: '1000.00', is_active: true };
        const pagination = { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null };
        const fetchMock = vi.fn((path, options = {}) => {
            if (path === '/admin/me') {
                return Promise.resolve(jsonResponse({ user: { id: 1, name: 'Admin', email: 'a@b.com', roles: ['admin'] } }));
            }
            if (path === '/admin/depots/active') {
                return Promise.resolve(jsonResponse({
                    depot,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }
            if (path === '/admin/watchlist/holdings?page=1') {
                return Promise.resolve(jsonResponse({
                    depot,
                    holdings: [],
                    meta: pagination,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }
            if (path === '/admin/watchlist/exchange-trading-times') {
                return Promise.resolve(jsonResponse({ exchange_trading_times: [] }));
            }
            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({ depots: [depot], meta: pagination }));
            }
            if (path === '/admin/price-refresh-settings' && options?.method === 'PATCH') {
                return Promise.resolve(jsonResponse({
                    message: 'Price refresh schedule updated.',
                    price_refresh_settings: priceRefreshSettings({ trading_interval_minutes: 30 }),
                }));
            }
            if (path === '/admin/index-price-refresh-settings' && options?.method === 'PATCH') {
                return Promise.resolve(jsonResponse({
                    message: 'Index price refresh schedule updated.',
                    index_price_refresh_settings: indexPriceRefreshSettings({ trading_interval_minutes: 35 }),
                }));
            }
            if (path === '/admin/price-refresh-settings') {
                return Promise.resolve(jsonResponse({
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }
            return Promise.resolve(jsonResponse({}));
        });
        global.fetch = fetchMock;
        const wrapper = mountApp();
        await flushPromises();

        expect(wrapper.find('#price-refresh-schedule-form').text()).toContain('20 min');
        expect(wrapper.find('#index-price-refresh-schedule-form').text()).toContain('30 min');
        expect(wrapper.find('#index-price-refresh-schedule-form').text()).toContain('15 min');
        expect(wrapper.find('#index-price-refresh-schedule-form').text()).toContain('20 min');
        expect(wrapper.find('#index-price-refresh-schedule-form').text()).toContain('Off');
        expect(wrapper.find('#index-price-refresh-schedule-form').text()).toContain('90 min');
        expect(wrapper.find('#index-price-refresh-schedule-form').findAll('input')).toHaveLength(0);

        const stockEditButton = wrapper.find('#price-refresh-schedule-form').findAll('button').find((button) => button.text().includes('Edit'));
        const indexEditButton = wrapper.find('#index-price-refresh-schedule-form').findAll('button').find((button) => button.text().includes('Edit'));
        expect(stockEditButton.attributes('disabled')).toBeUndefined();
        expect(indexEditButton.attributes('disabled')).toBeUndefined();
        await indexEditButton.trigger('click');
        await flushPromises();

        expect(wrapper.find('#index-price-refresh-schedule-form').findAll('input[type="number"]')).toHaveLength(4);
        expect(stockEditButton.attributes('disabled')).toBeDefined();

        await wrapper.find('#index-price-refresh-schedule-form').trigger('submit');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/index-price-refresh-settings', expect.objectContaining({
            method: 'PATCH',
            body: JSON.stringify({
                trading_interval_minutes: 30,
                trading_starts_before_minutes: 15,
                trading_ends_after_minutes: 20,
                closed_refresh_enabled: false,
                closed_interval_minutes: 90,
            }),
        }));
        expect(fetchMock).not.toHaveBeenCalledWith('/admin/price-refresh-settings', expect.objectContaining({ method: 'PATCH' }));
        expect(wrapper.text()).toContain('Index price refresh schedule updated.');
        expect(wrapper.find('#index-price-refresh-schedule-form').findAll('input')).toHaveLength(0);
    });

    it('does not reset schedule draft values while settings polling refreshes', async () => {
        window.history.pushState({}, '', '/admin/menu/updates');
        const depot = { id: 1, name: 'Main depot', account_balance: '1000.00', is_active: true };
        const pagination = { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null };
        const fetchMock = vi.fn((path) => {
            if (path === '/admin/me') {
                return Promise.resolve(jsonResponse({ user: { id: 1, name: 'Admin', email: 'a@b.com', roles: ['admin'] } }));
            }
            if (path === '/admin/depots/active') {
                return Promise.resolve(jsonResponse({
                    depot,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }
            if (path === '/admin/watchlist/holdings?page=1') {
                return Promise.resolve(jsonResponse({
                    depot,
                    holdings: [],
                    meta: pagination,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }
            if (path === '/admin/watchlist/exchange-trading-times') {
                return Promise.resolve(jsonResponse({ exchange_trading_times: [] }));
            }
            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({ depots: [depot], meta: pagination }));
            }
            if (path === '/admin/price-refresh-settings') {
                return Promise.resolve(jsonResponse({
                    price_refresh_settings: priceRefreshSettings({ trading_interval_minutes: 55 }),
                    index_price_refresh_settings: indexPriceRefreshSettings({ trading_interval_minutes: 65 }),
                }));
            }

            return Promise.resolve(jsonResponse({}));
        });
        global.fetch = fetchMock;
        const wrapper = mountApp();
        await flushPromises();

        const stockEditButton = wrapper.find('#price-refresh-schedule-form').findAll('button').find((button) => button.text().includes('Edit'));
        await stockEditButton.trigger('click');
        await flushPromises();

        const stockScheduleInputs = wrapper.find('#price-refresh-schedule-form').findAll('input[type="number"]');
        await stockScheduleInputs[0].setValue('10');

        await wrapper.vm.pollPriceRefreshSettings();
        await flushPromises();

        expect(wrapper.find('#price-refresh-schedule-form').findAll('input[type="number"]')[0].element.value).toBe('10');
        expect(wrapper.vm.priceRefreshSettings.trading_interval_minutes).toBe(55);
    });

    it('keeps outside trading off after saving the stock price refresh schedule', async () => {
        window.history.pushState({}, '', '/admin/menu/updates');
        const depot = { id: 1, name: 'Main depot', account_balance: '1000.00', is_active: true };
        const pagination = { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null };
        const savedPriceRefreshSettings = priceRefreshSettings({ closed_refresh_enabled: false });
        const fetchMock = vi.fn((path, options = {}) => {
            if (path === '/admin/me') {
                return Promise.resolve(jsonResponse({ user: { id: 1, name: 'Admin', email: 'a@b.com', roles: ['admin'] } }));
            }
            if (path === '/admin/depots/active') {
                return Promise.resolve(jsonResponse({
                    depot,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }
            if (path === '/admin/watchlist/holdings?page=1') {
                return Promise.resolve(jsonResponse({
                    depot,
                    holdings: [],
                    meta: pagination,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }
            if (path === '/admin/watchlist/exchange-trading-times') {
                return Promise.resolve(jsonResponse({ exchange_trading_times: [] }));
            }
            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({ depots: [depot], meta: pagination }));
            }
            if (path === '/admin/price-refresh-settings' && options?.method === 'PATCH') {
                return Promise.resolve(jsonResponse({
                    message: 'Price refresh schedule updated.',
                    price_refresh_settings: savedPriceRefreshSettings,
                }));
            }

            return Promise.resolve(jsonResponse({}));
        });
        global.fetch = fetchMock;
        const wrapper = mountApp();
        await flushPromises();

        const stockEditButton = wrapper.find('#price-refresh-schedule-form').findAll('button').find((button) => button.text().includes('Edit'));
        await stockEditButton.trigger('click');
        await flushPromises();

        wrapper.vm.priceRefreshScheduleForm.closed_refresh_enabled = false;
        await wrapper.vm.$nextTick();
        await wrapper.find('#price-refresh-schedule-form').trigger('submit');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/price-refresh-settings', expect.objectContaining({
            method: 'PATCH',
            body: expect.stringContaining('"closed_refresh_enabled":false'),
        }));
        expect(wrapper.find('#price-refresh-schedule-form').text()).toContain('Off');

        const editAgainButton = wrapper.find('#price-refresh-schedule-form').findAll('button').find((button) => button.text().includes('Edit'));
        await editAgainButton.trigger('click');
        await flushPromises();

        expect(wrapper.vm.priceRefreshScheduleForm.closed_refresh_enabled).toBe(false);
    });

    it('shows Data as a main dashboard item and Admin group with Users, Roles, and Updates submenu chips for super_admin', async () => {
        window.history.pushState({}, '', '/admin/menu/users');
        localStorage.removeItem('data_intraday_refresh_info_dismissed');
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

            if (path === '/admin/index-watch-items') {
                return Promise.resolve(jsonResponse({ indexes: [] }));
            }

            if (path === '/admin/data/exchanges') {
                return Promise.resolve(jsonResponse({
                    exchanges: [
                        {
                            code: 'BA',
                            detail_code: 'XBUE',
                            name: 'Buenos Aires Exchange',
                            country: 'Argentina',
                            currency: 'ARS',
                            timezone: 'America/Argentina/Buenos_Aires',
                            trading_hours: {},
                            holidays: {},
                            synced_at: '2026-06-06T12:30:00+00:00',
                        },
                    ],
                    refresh: {
                        refresh_id: 'exchanges-test',
                        status: 'partial',
                        processed: 72,
                        total: 72,
                        step: '72/72',
                        message: 'Exchange reload finished with missing details.',
                        current: null,
                    },
                }));
            }

            if (path === '/admin/data/intraday') {
                return Promise.resolve(jsonResponse({
                    stocks: [
                        { id: 7, symbol: 'AMES', name: 'Amundi IBEX 35 UCITS ETF' },
                    ],
                    selected_stock_id: 7,
                    days: [
                        {
                            title: 'Intraday 05.06.2026 - 5m',
                            trading_date: '2026-06-05',
                            interval: '5m',
                            rows: [
                                {
                                    timestamp: 1780646400,
                                    gmtoffset: 0,
                                    datetime: '2026-06-05 08:00:00',
                                    open: '10.10000000',
                                    high: '10.20000000',
                                    low: '10.00000000',
                                    close: '10.15000000',
                                    volume: 1200,
                                },
                            ],
                        },
                    ],
                    refresh: {
                        refresh_id: 'intraday-test',
                        status: 'finished',
                        message: '1 intraday candles loaded/updated.',
                        stored_count: 1,
                        finished_at: '2026-06-06T12:40:00+00:00',
                    },
                }));
            }

            if (path === '/admin/data/intraday/reload') {
                return Promise.resolve(jsonResponse({
                    stocks: [
                        { id: 7, symbol: 'AMES', name: 'Amundi IBEX 35 UCITS ETF' },
                    ],
                    selected_stock_id: 7,
                    days: [
                        {
                            title: 'Intraday 05.06.2026 - 5m',
                            trading_date: '2026-06-05',
                            interval: '5m',
                            rows: [
                                {
                                    timestamp: 1780646400,
                                    gmtoffset: 0,
                                    datetime: '2026-06-05 08:00:00',
                                    open: '10.10000000',
                                    high: '10.20000000',
                                    low: '10.00000000',
                                    close: '10.15000000',
                                    volume: 1200,
                                },
                            ],
                        },
                    ],
                    refresh: {
                        refresh_id: 'intraday-test',
                        status: 'finished',
                        message: '1 intraday candles loaded/updated.',
                        stored_count: 1,
                        finished_at: '2026-06-06T12:40:00+00:00',
                    },
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
        expect(wrapper.text()).toContain('Data');
        expect(wrapper.text()).toContain('Updates');
        expect(wrapper.find('.dashboard-navigation-drawer').text()).toContain('Data');

        const tabs = wrapper.findAll('.v-tab');
        const tabLabels = tabs.map((t) => t.text());
        expect(tabLabels.some((l) => l.includes('Users'))).toBe(true);
        expect(tabLabels.some((l) => l.includes('Roles'))).toBe(true);
        expect(tabLabels.some((l) => l.includes('Data'))).toBe(false);
        expect(tabLabels.some((l) => l.includes('Updates'))).toBe(true);

        const rolesTab = wrapper.findAll('.v-tab').find((t) => t.text().includes('Roles'));
        await rolesTab.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/roles');
        expect(wrapper.text()).toContain('admin');

        const dataMenuItem = wrapper.find('.dashboard-navigation-drawer')
            .findAll('.v-list-item')
            .find((item) => item.text().includes('Data'));
        await dataMenuItem.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/data/exchanges');
        expect(wrapper.find('[aria-label="Data exchanges"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Exchanges');
        expect(wrapper.text()).toContain('Last updated:');
        expect(wrapper.text()).toContain('06.06.2026');
        expect(wrapper.text()).toContain('14:30');
        expect(wrapper.text()).toContain('Buenos Aires Exchange · BA');
        expect(wrapper.text()).toContain('Exchange reload finished with missing details.');
        expect(fetchMock).toHaveBeenCalledWith('/admin/data/exchanges', expect.any(Object));

        wrapper.vm.dismissDataExchangeRefresh();
        await wrapper.vm.$nextTick();

        expect(sessionStorage.getItem('exchange_refresh_dismissed_id')).toBe('exchanges-test');
        expect(wrapper.text()).not.toContain('Exchange reload finished with missing details.');

        const intradayTab = wrapper.findAll('.v-tab').find((t) => t.text().includes('Intraday'));
        await intradayTab.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/data/intraday');
        expect(wrapper.find('[aria-label="Data intraday"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Amundi IBEX 35 UCITS ETF');
        expect(wrapper.text()).toContain('Intraday 05.06.2026 - 5m');
        expect(wrapper.text()).not.toContain('10.15000000');
        expect(wrapper.text()).toContain('1 intraday candles loaded/updated.');
        expect(fetchMock).toHaveBeenCalledWith('/admin/data/intraday', expect.any(Object));

        const intradayDayHeader = wrapper.find('.data-intraday-day-header');
        expect(intradayDayHeader.attributes('aria-expanded')).toBe('false');
        await intradayDayHeader.trigger('click');
        await wrapper.vm.$nextTick();

        expect(intradayDayHeader.attributes('aria-expanded')).toBe('true');
        expect(wrapper.text()).toContain('10.15000000');

        await intradayDayHeader.trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).not.toContain('10.15000000');

        wrapper.vm.dismissDataIntradayRefresh();
        await wrapper.vm.$nextTick();

        expect(localStorage.getItem('data_intraday_refresh_info_dismissed')).toBe('1');
        expect(wrapper.text()).not.toContain('1 intraday candles loaded/updated.');

        const reloadButton = wrapper.findAll('button').find((button) => button.text().includes('Reload Intraday'));
        await reloadButton.trigger('click');
        await flushPromises();

        const reloadCall = fetchMock.mock.calls.find(([path]) => path === '/admin/data/intraday/reload');
        expect(reloadCall[1].body).toBe(JSON.stringify({ selected_stock_id: 7 }));
        localStorage.removeItem('data_intraday_refresh_info_dismissed');
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
                    index_price_refresh_settings: indexPriceRefreshSettings(),
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

            if (path === '/admin/index-watch-items') {
                return Promise.resolve(jsonResponse({ indexes: [] }));
            }

            if (path === '/admin/queue/status') {
                return Promise.resolve(jsonResponse(queueStatusResponse()));
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
                    ui_preferences: {
                        depot_price_source: 'latest',
                    },
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

            if (path === '/admin/queue/status') {
                return Promise.resolve(jsonResponse({
                    queue: {
                        ok: true,
                        status: 'ok',
                    },
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

            if (path === '/admin/index-watch-items') {
                return Promise.resolve(jsonResponse({ indexes: [] }));
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
        const cashDateInput = document.body.querySelector('#cash-transaction-form input[type="date"]');
        cashDateInput.value = '2026-06-05';
        cashDateInput.dispatchEvent(new Event('input', { bubbles: true }));
        document.body.querySelector('#cash-transaction-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/depot-transactions/cash', expect.objectContaining({
            method: 'POST',
            body: JSON.stringify({
                type: 'deposit',
                total_amount: 250,
                booked_at: '2026-06-05',
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
            end_price_24: null,
            end_price_48: null,
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

            if (path === '/admin/index-watch-items') {
                return Promise.resolve(jsonResponse({ indexes: [] }));
            }

            if (path === '/admin/queue/status') {
                return Promise.resolve(jsonResponse({
                    queue: {
                        ok: true,
                        status: 'ok',
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
        const stockDateInput = document.body.querySelector('#stock-transaction-form input[type="date"]');
        stockDateInput.value = '2026-06-05';
        stockDateInput.dispatchEvent(new Event('input', { bubbles: true }));
        document.body.querySelector('#stock-transaction-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/depot-transactions/stocks', expect.objectContaining({
            method: 'POST',
            body: JSON.stringify({
                type: 'buy',
                stock_holding_id: 1,
                pieces: 3,
                total_amount: 300,
                booked_at: '2026-06-05',
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
        const depotHoldings = [
            {
                id: 1,
                symbol: 'AAPL',
                name: 'Apple Inc.',
                currency: 'USD',
                latest_price: '191.500000',
                flatex_price: '180.000000',
                year_start_price: '175.00000000',
                latest_price_fetched_at: '2026-06-04T10:00:00+00:00',
                latest_price_status: 'fresh',
                position_pieces: '2.00000000',
            },
        ];
        const fetchMock = vi.fn((path, options = {}) => {
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
                return Promise.resolve(jsonResponse({ depot_holdings: depotHoldings, transactions }));
            }

            if (path === '/admin/ui-preferences' && options.method === 'PATCH') {
                return Promise.resolve(jsonResponse({
                    message: 'UI preferences updated.',
                    ui_preferences: JSON.parse(options.body),
                }));
            }

            if (path === '/admin/watchlist/holdings/1/flatex-price' && options.method === 'PATCH') {
                return Promise.resolve(jsonResponse({
                    message: 'Flatex price updated.',
                    holding: {
                        id: 1,
                        flatex_price: '180.250000',
                    },
                    holdings: [
                        {
                            id: 1,
                            flatex_price: '180.250000',
                        },
                    ],
                }));
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

            if (path === '/admin/index-watch-items') {
                return Promise.resolve(jsonResponse({ indexes: [] }));
            }

            return Promise.reject(new Error(`Unexpected request: ${path}`));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();
        await flushPromises();

        expect(wrapper.text()).toContain('Depot stocks');
        expect(wrapper.text()).toContain('Depot balance');
        expect(wrapper.text()).toContain('383.00 EUR');
        expect(wrapper.text()).toContain('Cash balance');
        expect(wrapper.text()).toContain('650.00 EUR');
        expect(wrapper.text()).toContain('Account balance');
        expect(wrapper.text()).toContain('1,033.00 EUR');
        expect(wrapper.text()).toContain('Balance 01.01.');
        expect(wrapper.text()).toContain('1,000.00 EUR');
        expect(wrapper.text()).toContain(`Balance ${sessionHeaderDate(0).slice(0, 6)}`);
        expect(wrapper.text()).toContain('+3.30% · +33.00 EUR');
        expect(wrapper.findAll('.depot-balance-card')).toHaveLength(2);
        expect(wrapper.find('.depot-balance-card tbody td:nth-child(2)').classes()).toContain('text-right');
        expect(wrapper.text()).toContain('Symbol');
        expect(wrapper.text()).toContain('Name');
        expect(wrapper.text()).toContain('Amount');
        expect(wrapper.text()).toContain('Value');
        expect(wrapper.text()).toContain('Latest price');
        expect(wrapper.text()).toContain('Flatex price');
        expect(wrapper.text()).toContain('1.1.');
        expect(wrapper.text()).toContain('Change');
        expect(wrapper.text()).toContain('+/- EUR');
        expect(wrapper.text()).toContain('Actions');
        const rightAlignedDepotHeaders = wrapper
            .findAll('th.text-right')
            .map((header) => header.text());
        expect(rightAlignedDepotHeaders).toContain('Latest price');
        expect(rightAlignedDepotHeaders).toContain('Value');
        expect(rightAlignedDepotHeaders).toContain('Flatex price');
        expect(rightAlignedDepotHeaders).toContain('1.1.');
        expect(rightAlignedDepotHeaders).toContain('Change');
        expect(rightAlignedDepotHeaders).toContain('+/- EUR');
        const latestPriceHeaderButton = wrapper.findAll('button').find((button) => button.text() === 'Latest price');
        const flatexPriceHeaderButton = wrapper.findAll('button').find((button) => button.text() === 'Flatex price');
        expect(latestPriceHeaderButton).toBeTruthy();
        expect(flatexPriceHeaderButton).toBeTruthy();
        expect(latestPriceHeaderButton.classes()).toContain('depot-price-source-button--active');
        expect(flatexPriceHeaderButton.classes()).not.toContain('depot-price-source-button--active');
        expect(wrapper.text()).toContain('AAPL');
        expect(wrapper.text()).toContain('2');
        expect(wrapper.text()).toContain('383.00 USD');
        expect(wrapper.text()).toContain('191.50 USD');
        expect(wrapper.text()).toContain('180.00 USD');
        expect(wrapper.text()).toContain('175.00 USD');
        expect(wrapper.text()).toContain('↑');
        expect(wrapper.text()).toContain('+9.43%');
        expect(wrapper.text()).toContain('+33.00');
        const mobileDepotStockCards = wrapper.findAll('.mobile-depot-stock-card');
        expect(mobileDepotStockCards).toHaveLength(1);
        expect(mobileDepotStockCards[0].find('.mobile-depot-stock-name').text()).toBe('Apple Inc.');
        expect(mobileDepotStockCards[0].text()).toContain('2');
        expect(mobileDepotStockCards[0].text()).toContain('191.50 USD');
        expect(mobileDepotStockCards[0].text()).toContain('383.00 USD');
        expect(mobileDepotStockCards[0].text()).toContain('↑ +9.43%');
        expect(mobileDepotStockCards[0].text()).toContain('+33.00');
        expect(mobileDepotStockCards[0].text()).not.toContain('AAPL');
        expect(mobileDepotStockCards[0].find('.mobile-depot-stock-row--prices').text()).toContain('191.50 USD');
        expect(mobileDepotStockCards[0].findAll('.mobile-depot-stock-row')).toHaveLength(2);
        expect(mobileDepotStockCards[0].findAll('.mobile-depot-stock-actions .v-btn')).toHaveLength(2);
        setViewportSize(844, 390);
        await flushPromises();

        const compactDepotHeaders = wrapper
            .find('.desktop-depot-stocks-table')
            .findAll('th')
            .map((header) => header.text());
        expect(compactDepotHeaders).not.toContain('Symbol');
        expect(compactDepotHeaders).not.toContain('1.1.');
        expect(compactDepotHeaders).toContain('Name');
        expect(compactDepotHeaders).toContain('Amount');
        expect(compactDepotHeaders).toContain('Value');
        expect(compactDepotHeaders).toContain('Latest price');
        expect(compactDepotHeaders).toContain('Flatex price');
        expect(compactDepotHeaders).toContain('Change');
        expect(compactDepotHeaders).toContain('+/- EUR');
        expect(compactDepotHeaders).toContain('Actions');
        const compactCashLedgerHeaders = wrapper
            .find('.desktop-cash-ledger-table')
            .findAll('th')
            .map((header) => header.text());
        expect(compactCashLedgerHeaders).toContain('Date');
        expect(compactCashLedgerHeaders).toContain('Type');
        expect(compactCashLedgerHeaders).toContain('Stock');
        expect(compactCashLedgerHeaders).toContain('Pieces');
        expect(compactCashLedgerHeaders).not.toContain('Amount');
        expect(compactCashLedgerHeaders).toContain('Cash effect');
        expect(compactCashLedgerHeaders).toContain('Balance');
        expect(wrapper.text()).toContain('Sum');
        expect(wrapper.text()).toContain('Cash ledger');
        expect(wrapper.text()).toContain('Buy');
        expect(wrapper.text()).toContain('Apple Inc.');
        expect(wrapper.text()).toContain('Add cash');
        expect(wrapper.text()).toContain('+1,000.00');
        expect(wrapper.text()).toContain('-350.00');
        const mobileCashLedgerCards = wrapper.findAll('.mobile-cash-ledger-card');
        expect(mobileCashLedgerCards).toHaveLength(2);
        expect(mobileCashLedgerCards[0].text()).toContain('Buy');
        expect(mobileCashLedgerCards[0].find('.mobile-cash-ledger-row').text()).toContain('04/06/2026, 12:00');
        expect(mobileCashLedgerCards[0].find('.mobile-cash-ledger-row').text()).not.toContain('350.00');
        expect(mobileCashLedgerCards[0].find('.mobile-cash-ledger-stock').text()).toBe('Apple Inc.');
        expect(mobileCashLedgerCards[0].text()).toContain('-350.00');
        expect(mobileCashLedgerCards[0].text()).toContain('650.00');
        expect(mobileCashLedgerCards[1].text()).toContain('Add cash');
        expect(mobileCashLedgerCards[1].find('.mobile-cash-ledger-row').text()).toContain('04/06/2026, 11:00');
        expect(mobileCashLedgerCards[1].find('.mobile-cash-ledger-row').text()).not.toContain('1,000.00');
        expect(mobileCashLedgerCards[1].find('.mobile-cash-ledger-stock').exists()).toBe(false);
        expect(mobileCashLedgerCards[1].text()).toContain('+1,000.00');

        await flatexPriceHeaderButton.trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/ui-preferences', expect.objectContaining({
            method: 'PATCH',
            body: JSON.stringify({ depot_price_source: 'flatex' }),
        }));
        expect(latestPriceHeaderButton.classes()).not.toContain('depot-price-source-button--active');
        expect(flatexPriceHeaderButton.classes()).toContain('depot-price-source-button--active');
        expect(wrapper.text()).toContain('360.00 USD');
        expect(wrapper.text()).toContain('1,010.00 EUR');
        expect(wrapper.text()).toContain('+1.00% · +10.00 EUR');
        expect(wrapper.text()).toContain('+2.86%');
        expect(wrapper.text()).toContain('+10.00');

        const flatexPriceButton = wrapper.findAll('button').find((button) => button.text() === '180.00 USD');
        expect(flatexPriceButton).toBeTruthy();

        await flatexPriceButton.trigger('click');
        await flushPromises();

        const flatexPriceInput = wrapper.find('input.flatex-price-input');
        expect(flatexPriceInput.exists()).toBe(true);

        await flatexPriceInput.setValue('180.25');
        await flatexPriceInput.trigger('keydown.enter');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/watchlist/holdings/1/flatex-price', expect.objectContaining({
            method: 'PATCH',
            body: JSON.stringify({ flatex_price: 180.25 }),
        }));
        expect(wrapper.text()).toContain('180.25 USD');
        expect(wrapper.text()).toContain('360.50 USD');
        expect(wrapper.text()).toContain('1,010.50 EUR');
        expect(wrapper.text()).toContain('+1.05% · +10.50 EUR');
        expect(wrapper.text()).toContain('+3.00%');

        const updatedFlatexPriceButton = wrapper.findAll('button').find((button) => button.text() === '180.25 USD');
        expect(updatedFlatexPriceButton).toBeTruthy();

        await updatedFlatexPriceButton.trigger('click');
        await flushPromises();

        const abortedFlatexPriceInput = wrapper.find('input.flatex-price-input');
        await abortedFlatexPriceInput.setValue('170.25');
        await abortedFlatexPriceInput.trigger('keydown.esc');
        await flushPromises();

        expect(wrapper.text()).toContain('180.25 USD');
        expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/watchlist/holdings/1/flatex-price')).toHaveLength(1);
    });

    it('clears the historical fetching dashboard status after the queued job finishes', async () => {
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
            end_price_24: null,
            end_price_48: null,
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
                    index_price_refresh_settings: indexPriceRefreshSettings(),
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
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                    refresh: null,
                }));
            }

            if (path === '/admin/index-watch-items') {
                return Promise.resolve(jsonResponse({ indexes: [] }));
            }

            if (path === '/admin/queue/status') {
                return Promise.resolve(jsonResponse(queueStatusResponse()));
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
            expect(wrapper.get('.dashboard-status-card').text().match(/waiting/g)).toHaveLength(2);
            expect(wrapper.text()).not.toContain('fetching historical data');

            wrapper.unmount();
        } finally {
            vi.useRealTimers();
        }
    });

    it('updates the dashboard status when a scheduled queue refresh starts', async () => {
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

            if (path === '/admin/index-watch-items') {
                return Promise.resolve(jsonResponse({ indexes: [] }));
            }

            if (path === '/admin/queue/status') {
                return Promise.resolve(jsonResponse(queueStatusResponse()));
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

    it('clears the queue from the dashboard action button', async () => {
        window.history.pushState({}, '', '/admin/dashboard');

        const emptyPagination = {
            current_page: 1,
            last_page: 1,
            per_page: 10,
            total: 0,
            from: null,
            to: null,
        };
        const fetchMock = vi.fn((path, options = {}) => {
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
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            if (path === '/admin/watchlist/holdings?page=1') {
                return Promise.resolve(jsonResponse({
                    depot: null,
                    holdings: [],
                    meta: emptyPagination,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            if (path === '/admin/index-watch-items') {
                return Promise.resolve(jsonResponse({ indexes: [] }));
            }

            if (path === '/admin/watchlist/exchange-trading-times') {
                return Promise.resolve(jsonResponse({ exchange_trading_times: [] }));
            }

            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({
                    depots: [],
                    meta: emptyPagination,
                }));
            }

            if (path === '/admin/queue/status') {
                return Promise.resolve(jsonResponse(queueStatusResponse({
                    status: 'check',
                    reserved: 1,
                })));
            }

            if (path === '/admin/queue/clear' && options.method === 'POST') {
                return Promise.resolve(jsonResponse({
                    message: 'Cleared 1 queued job(s) and 0 failed job record(s).',
                    cleared_jobs: 1,
                    cleared_failed_jobs: 0,
                    queue: queueStatusResponse().queue,
                }));
            }

            return Promise.reject(new Error(`Unexpected request: ${path}`));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();
        await flushPromises();

        expect(wrapper.get('.dashboard-status-card').text()).toContain('Queue check');
        expect(wrapper.get('.dashboard-status-card').text()).toContain('R 1');

        const clearQueueButton = wrapper.findAll('button')
            .find((button) => button.text().includes('Clear queue'));
        expect(clearQueueButton).toBeTruthy();
        await clearQueueButton.trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/queue/clear', expect.objectContaining({
            method: 'POST',
        }));
        expect(wrapper.get('.dashboard-status-card').text()).toContain('Queue OK');
        expect(wrapper.get('.dashboard-status-card').text()).toContain('R 0');
        const disabledClearQueueButton = wrapper.findAll('button')
            .find((button) => button.text().includes('Clear queue'));
        expect(disabledClearQueueButton.attributes()).toHaveProperty('disabled');
    });

    it('shows the index dashboard status as waiting when the next index refresh is due but not running', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-06-02T12:41:00+00:00'));
        window.history.pushState({}, '', '/admin/dashboard');

        const emptyPagination = {
            current_page: 1,
            last_page: 1,
            per_page: 10,
            total: 0,
            from: null,
            to: null,
        };
        const dueIndexPriceRefreshSettings = indexPriceRefreshSettings({
            last_refreshed_at: '2026-06-02T12:20:00+00:00',
            next_refresh_at: '2026-06-02T12:40:00+00:00',
            status: 'waiting',
            status_label: 'waiting',
        });
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
                    index_price_refresh_settings: dueIndexPriceRefreshSettings,
                }));
            }

            if (path === '/admin/watchlist/holdings?page=1') {
                return Promise.resolve(jsonResponse({
                    depot: null,
                    holdings: [],
                    meta: emptyPagination,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: dueIndexPriceRefreshSettings,
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
                    index_price_refresh_settings: dueIndexPriceRefreshSettings,
                    refresh: null,
                }));
            }

            if (path === '/admin/index-watch-items') {
                return Promise.resolve(jsonResponse({ indexes: [] }));
            }

            if (path === '/admin/watchlist/exchange-trading-times') {
                return Promise.resolve(jsonResponse({ exchange_trading_times: [] }));
            }

            if (path === '/admin/queue/status') {
                return Promise.resolve(jsonResponse(queueStatusResponse()));
            }

            return Promise.reject(new Error(`Unexpected request: ${path}`));
        });
        vi.stubGlobal('fetch', fetchMock);

        try {
            const wrapper = mountApp();
            await flushPromises();

            const dashboardStatusText = wrapper.get('.dashboard-status-card').text();

            expect(wrapper.get('.app-bar-row').text()).not.toContain('Stocks Last:');
            expect(dashboardStatusText).toContain('Stocks Last:');
            expect(dashboardStatusText).toContain('waiting');
            expect(dashboardStatusText).toContain('Indices Last:');
            expect(dashboardStatusText).not.toContain('Updating prices');

            wrapper.unmount();
        } finally {
            vi.useRealTimers();
        }
    });
});
