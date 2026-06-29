import { flushPromises, mount } from '@vue/test-utils';
import { createPinia } from 'pinia';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../../../resources/js/App.vue';
import { createStocksVuetify } from '../../../resources/js/plugins/vuetify';

const mountedWrappers = new Set();

function mountApp() {
    const wrapper = mount(App, {
        attachTo: document.body,
        global: {
            plugins: [
                createPinia(),
                createStocksVuetify(),
            ],
        },
    });

    mountedWrappers.add(wrapper);

    return wrapper;
}

afterEach(() => {
    mountedWrappers.forEach((wrapper) => {
        wrapper.unmount();
    });
    mountedWrappers.clear();
    document.body.innerHTML = '';
});

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

function ndjsonResponse(events) {
    const payload = `${events.map(event => JSON.stringify(event)).join('\n')}\n`;

    return {
        ok: true,
        body: new ReadableStream({
            start(controller) {
                controller.enqueue(new TextEncoder().encode(payload));
                controller.close();
            },
        }),
        json: () => Promise.reject(new Error('Expected stream reader to be used.')),
    };
}

function failedJsonResponse(data = {}, options = {}) {
    return {
        ok: false,
        status: options.status ?? 500,
        json: () => Promise.resolve(data),
    };
}

function weekdayIntradayCandles(startDate, endDate) {
    const candles = [];
    const currentDate = new Date(`${startDate}T09:00:00Z`);
    const latestDate = new Date(`${endDate}T09:00:00Z`);

    while (currentDate.getTime() <= latestDate.getTime()) {
        const day = currentDate.getUTCDay();

        if (day !== 0 && day !== 6) {
            candles.push({
                id: 5000 + candles.length,
                trading_date: currentDate.toISOString().slice(0, 10),
                price: (100 + candles.length).toFixed(8),
                currency: 'EUR',
                as_of: currentDate.toISOString(),
            });
        }

        currentDate.setUTCDate(currentDate.getUTCDate() + 1);
    }

    return candles;
}

function trendSignalDailyPrices() {
    const prices = [];
    const currentDate = new Date('2026-04-01T00:00:00Z');
    let price = 100;

    Array.from({ length: 46 }).forEach((_, index) => {
        const isPullback = index > 0 && index % 8 === 0;
        const change = isPullback ? -0.65 : 1.15;
        price += index === 0 ? 0 : change;

        prices.push({
            trading_date: currentDate.toISOString().slice(0, 10),
            price: price.toFixed(6),
            currency: 'EUR',
            volume: index === 44 ? 5400 : 2200 + index * 20,
        });

        currentDate.setUTCDate(currentDate.getUTCDate() + 1);
    });

    return prices;
}

function streakRecommendationDailyPrices() {
    return [
        { trading_date: '2026-06-01', price: '100.000000', currency: 'EUR' },
        { trading_date: '2026-06-02', price: '99.000000', currency: 'EUR' },
        { trading_date: '2026-06-03', price: '98.010000', currency: 'EUR' },
        { trading_date: '2026-06-04', price: '97.029900', currency: 'EUR' },
        { trading_date: '2026-06-05', price: '96.059601', currency: 'EUR' },
        { trading_date: '2026-06-06', price: '95.099005', currency: 'EUR' },
        { trading_date: '2026-06-07', price: '97.000985', currency: 'EUR' },
        { trading_date: '2026-06-08', price: '100.008016', currency: 'EUR' },
        { trading_date: '2026-06-09', price: '99.007936', currency: 'EUR' },
        { trading_date: '2026-06-10', price: '98.017857', currency: 'EUR' },
        { trading_date: '2026-06-11', price: '97.037678', currency: 'EUR' },
        { trading_date: '2026-06-12', price: '96.067301', currency: 'EUR' },
        { trading_date: '2026-06-13', price: '95.106628', currency: 'EUR' },
        { trading_date: '2026-06-14', price: '97.008761', currency: 'EUR' },
        { trading_date: '2026-06-15', price: '100.016033', currency: 'EUR' },
    ];
}

function openStreakRecommendationDailyPrices() {
    return [
        { trading_date: '2026-05-27', price: '100.000000', currency: 'EUR' },
        { trading_date: '2026-05-28', price: '99.000000', currency: 'EUR' },
        { trading_date: '2026-05-29', price: '98.010000', currency: 'EUR' },
        { trading_date: '2026-05-30', price: '97.029900', currency: 'EUR' },
        { trading_date: '2026-06-01', price: '97.029900', currency: 'EUR' },
        { trading_date: '2026-06-08', price: '97.029900', currency: 'EUR' },
        { trading_date: '2026-06-15', price: '97.029900', currency: 'EUR' },
    ];
}

function overlappingStreakRecommendationDailyPrices() {
    return [
        { trading_date: '2026-07-01', price: '100.000000', currency: 'EUR' },
        { trading_date: '2026-07-02', price: '99.000000', currency: 'EUR' },
        { trading_date: '2026-07-03', price: '98.010000', currency: 'EUR' },
        { trading_date: '2026-07-04', price: '97.029900', currency: 'EUR' },
        { trading_date: '2026-07-05', price: '98.000199', currency: 'EUR' },
        { trading_date: '2026-07-06', price: '97.020197', currency: 'EUR' },
        { trading_date: '2026-07-07', price: '96.049995', currency: 'EUR' },
        { trading_date: '2026-07-08', price: '95.089495', currency: 'EUR' },
        { trading_date: '2026-07-09', price: '99.843970', currency: 'EUR' },
    ];
}

function tripleStreakRecommendationDailyPrices() {
    return [
        { trading_date: '2026-08-01', price: '100.000000', currency: 'EUR' },
        { trading_date: '2026-08-02', price: '99.000000', currency: 'EUR' },
        { trading_date: '2026-08-03', price: '98.010000', currency: 'EUR' },
        { trading_date: '2026-08-04', price: '97.029900', currency: 'EUR' },
        { trading_date: '2026-08-05', price: '98.000199', currency: 'EUR' },
        { trading_date: '2026-08-06', price: '97.020197', currency: 'EUR' },
        { trading_date: '2026-08-07', price: '96.049995', currency: 'EUR' },
        { trading_date: '2026-08-08', price: '95.089495', currency: 'EUR' },
        { trading_date: '2026-08-09', price: '95.564942', currency: 'EUR' },
        { trading_date: '2026-08-10', price: '94.609292', currency: 'EUR' },
        { trading_date: '2026-08-11', price: '93.663199', currency: 'EUR' },
        { trading_date: '2026-08-12', price: '92.726567', currency: 'EUR' },
    ];
}

function hiddenPreAnalysisBuyDailyPrices() {
    const prices = [];
    const currentDate = new Date('2026-01-01T00:00:00Z');
    let price = 100;

    Array.from({ length: 205 }).forEach((_, index) => {
        if (index > 0 && index <= 3) {
            price *= 0.99;
        }

        prices.push({
            trading_date: currentDate.toISOString().slice(0, 10),
            price: price.toFixed(6),
            currency: 'EUR',
        });

        currentDate.setUTCDate(currentDate.getUTCDate() + 1);
    });

    return prices;
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

function intradayBackfillSettings(overrides = {}) {
    return {
        daily_time: '18:30',
        interval_minutes: 15,
        timezone: 'Europe/Vienna',
        last_dispatched_at: null,
        last_dispatched_on: null,
        next_refresh_at: '2026-06-12T18:30:00+02:00',
        status: 'waiting',
        status_label: 'waiting',
        ...overrides,
    };
}

function endOfDayDataUpdateSettings(overrides = {}) {
    return {
        daily_time: '17:45',
        interval_minutes: 20,
        timezone: 'Europe/Vienna',
        last_dispatched_at: null,
        last_dispatched_on: null,
        next_refresh_at: '2026-06-12T17:45:00+02:00',
        status: 'waiting',
        status_label: 'waiting',
        ...overrides,
    };
}

function indexDataUpdateSettings(overrides = {}) {
    return {
        weekday: 1,
        weekday_label: 'Monday',
        daily_time: '02:00',
        timezone: 'Europe/Vienna',
        last_dispatched_at: null,
        last_dispatched_on: null,
        next_refresh_at: '2026-06-15T02:00:00+02:00',
        status: 'waiting',
        status_label: 'waiting',
        table_name: 'index_watch_item_prices',
        table_row_count: 3,
        latest_table_update_at: null,
        ...overrides,
    };
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

function displayDateFromKey(dateKey) {
    const [year, month, day] = dateKey.split('-').map((part) => Number(part));

    return new Intl.DateTimeFormat('de-AT', {
        timeZone: 'UTC',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(new Date(Date.UTC(year, month - 1, day)));
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

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
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
                            recent_prices: [
                                {
                                    id: 101,
                                    price: '305.000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-18T16:30:00+00:00',
                                },
                                {
                                    id: 102,
                                    price: '306.320010',
                                    currency: 'EUR',
                                    as_of: '2026-06-19T16:30:00+00:00',
                                },
                            ],
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
        expect(actionLabels).toEqual(['Add stock', 'Reload']);
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

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
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
                            latest_price_as_of: '2026-06-03T15:35:00+00:00',
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
        expect(headers.some((header) => header.includes('Last day'))).toBe(true);
        expect(headers.some((header) => header.includes('End price'))).toBe(false);
        expect(headers).not.toContain('Source time');

        const firstRowCells = watchListTable.find('.stock-holding-row').findAll('td');
        expect(firstRowCells[0].text()).toContain('Apple');
        expect(firstRowCells[0].text()).toContain('Pieces: 2');
        expect(firstRowCells[0].text()).not.toContain('US0378331005');
        expect(firstRowCells[0].text()).not.toContain('WKN: 865985');
        expect(firstRowCells[1].text()).toContain('306.32');
        expect(firstRowCells[1].text()).toContain('03.06. 17:35');
        expect(wrapper.get('.mobile-stock-price-row').text()).toContain('03.06. 17:35');

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
        expect(appleCells[1].text()).toContain('03.06. 17:35');
        expect(appleCells[1].text()).toContain('+2.28% · 299.50');
        expect(appleCells[1].find('[aria-label="Day indicator: Price increased"]').exists()).toBe(true);
        expect(appleCells[1].find('.recent-price-trend-dot--day').classes()).toContain('recent-price-trend-dot-up');
        expect(microsoftCells[1].text()).toBe('-');
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

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
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
        expect(wrapper.text()).not.toContain('Cloudways');
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
        const clipboardWriteText = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: {
                writeText: clipboardWriteText,
            },
        });
        let currentUiPreferences = {
            depot_price_source: 'latest',
            analyze_trend_row_limit: 200,
            analyze_trend_excluded_holding_ids: [],
            analyze_trend_trade_amounts: [7000, 5000, 3000],
            analyze_trend_max_invest_amount: 0,
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
                    depot,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                    ui_preferences: currentUiPreferences,
                }));
            }

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
                return Promise.resolve(jsonResponse({
                    depot,
                    holdings: [
                        {
                            id: 1,
                            symbol: 'AAPL',
                            name: 'Apple',
                            isin: 'US0378331005',
                            currency: 'EUR',
                            latest_price: '306.320010',
                            position_pieces: '2.00000000',
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
                            intraday_candles: Array.from({ length: 300 }, (_, index) => ({
                                id: index + 1,
                                trading_date: '2026-06-04',
                                price: index === 0
                                    ? '290.00000000'
                                    : (index === 299 ? '306.32001000' : (295 + index * 0.02).toFixed(8)),
                                currency: 'EUR',
                                as_of: new Date(Date.UTC(2026, 5, 4, 9, index * 5)).toISOString(),
                            })),
                        },
                        {
                            id: 2,
                            symbol: 'MSFT',
                            name: 'Microsoft',
                            currency: 'USD',
                            latest_price: null,
                            position_pieces: '0.00000000',
                            end_price: '429.950000',
                            daily_prices: [],
                        },
                        {
                            id: 3,
                            symbol: 'SMALL',
                            name: 'Small Price Fund',
                            currency: 'EUR',
                            latest_price: '44.220000',
                            daily_prices: [],
                        },
                        {
                            id: 4,
                            symbol: 'TINY',
                            name: 'Tiny Price Fund',
                            currency: 'EUR',
                            latest_price: '2.680000',
                            daily_prices: [],
                        },
                        {
                            id: 5,
                            symbol: 'MONTH',
                            name: 'Month Marker Fund',
                            currency: 'EUR',
                            latest_price: '365.000000',
                            daily_prices: [],
                            intraday_candles: weekdayIntradayCandles('2025-06-15', '2026-06-14'),
                        },
                        {
                            id: 6,
                            symbol: 'DAY',
                            name: 'Intraday Filter Fund',
                            currency: 'EUR',
                            latest_price: '306.320010',
                            recent_prices: [
                                {
                                    id: 600,
                                    price: '303.80000000',
                                    currency: 'EUR',
                                    as_of: new Date(Date.UTC(2026, 5, 4, 10, 55)).toISOString(),
                                    source_name: 'EODHD real-time',
                                    price_type: 'last',
                                },
                                {
                                    id: 601,
                                    price: '304.50000000',
                                    currency: 'EUR',
                                    as_of: new Date(Date.UTC(2026, 5, 5, 9, 50)).toISOString(),
                                    source_name: 'EODHD real-time',
                                    price_type: 'last',
                                },
                                {
                                    id: 602,
                                    price: '305.20000000',
                                    currency: 'EUR',
                                    as_of: new Date(Date.UTC(2026, 5, 5, 10, 10)).toISOString(),
                                    source_name: 'EODHD real-time',
                                    price_type: 'last',
                                },
                            ],
                            daily_prices: [],
                            intraday_candles: [
                                ...Array.from({ length: 12 }, (_, index) => ({
                                    id: index + 1,
                                    trading_date: '2026-06-04',
                                    price: (290 + index * 0.1).toFixed(8),
                                    currency: 'EUR',
                                    as_of: new Date(Date.UTC(2026, 5, 4, 10, index * 5)).toISOString(),
                                })),
                                ...Array.from({ length: 20 }, (_, index) => ({
                                    id: index + 13,
                                    trading_date: '2026-06-05',
                                    price: index === 19 ? '306.32001000' : (305 + index * 0.1).toFixed(8),
                                    currency: 'EUR',
                                    as_of: new Date(Date.UTC(2026, 5, 5, 10, index * 5)).toISOString(),
                                })),
                            ],
                        },
                        {
                            id: 7,
                            symbol: 'TREND',
                            name: 'Trend Signal Fund',
                            currency: 'EUR',
                            latest_price: '146.000000',
                            depot_transactions: [
                                {
                                    id: 701,
                                    type: 'buy',
                                    pieces: '10.00000000',
                                    total_amount: '900.00',
                                    currency: 'USD',
                                    booked_at: '2026-03-15T00:00:00+00:00',
                                },
                            ],
                            daily_prices: trendSignalDailyPrices(),
                        },
                        {
                            id: 8,
                            symbol: 'BUY',
                            name: 'Buy Streak Fund',
                            isin: 'IE00BUY00008',
                            currency: 'EUR',
                            latest_price: '95.000000',
                            depot_transactions: [
                                {
                                    id: 801,
                                    type: 'buy',
                                    pieces: '70.00000000',
                                    total_amount: '6792.64',
                                    currency: 'EUR',
                                    booked_at: '2026-06-11T00:00:00+00:00',
                                },
                                {
                                    id: 802,
                                    type: 'sell',
                                    pieces: '70.00000000',
                                    total_amount: '7001.12',
                                    currency: 'EUR',
                                    booked_at: '2026-06-15T00:00:00+00:00',
                                },
                            ],
                            daily_prices: streakRecommendationDailyPrices(),
                        },
                        {
                            id: 11,
                            symbol: 'OPEN',
                            name: 'Open Streak Fund',
                            currency: 'EUR',
                            latest_price: '95.000000',
                            daily_prices: openStreakRecommendationDailyPrices(),
                        },
                        {
                            id: 9,
                            symbol: 'OVER',
                            name: 'Overlap Streak Fund',
                            currency: 'EUR',
                            latest_price: '99.843970',
                            daily_prices: overlappingStreakRecommendationDailyPrices(),
                        },
                        {
                            id: 12,
                            symbol: 'TRIPLE',
                            name: 'Triple Streak Fund',
                            currency: 'EUR',
                            latest_price: '92.726567',
                            daily_prices: tripleStreakRecommendationDailyPrices(),
                        },
                        {
                            id: 10,
                            symbol: 'HIDDEN',
                            name: 'Hidden Pre Analysis Fund',
                            currency: 'EUR',
                            latest_price: '97.029900',
                            daily_prices: hiddenPreAnalysisBuyDailyPrices(),
                        },
                    ],
                    meta: pagination,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                    ui_preferences: currentUiPreferences,
                }));
            }

            if (path === '/admin/ui-preferences' && options.method === 'PATCH') {
                const payload = JSON.parse(options.body);

                if (Object.hasOwn(payload, 'analyze_trend_row_limit')) {
                    currentUiPreferences = {
                        ...currentUiPreferences,
                        analyze_trend_row_limit: payload.analyze_trend_row_limit,
                    };
                }

                if (Object.hasOwn(payload, 'analyze_trend_excluded_holding_ids')) {
                    currentUiPreferences = {
                        ...currentUiPreferences,
                        analyze_trend_excluded_holding_ids: payload.analyze_trend_excluded_holding_ids,
                    };
                }

                if (Object.hasOwn(payload, 'analyze_trend_trade_amounts')) {
                    currentUiPreferences = {
                        ...currentUiPreferences,
                        analyze_trend_trade_amounts: payload.analyze_trend_trade_amounts,
                    };
                }

                if (Object.hasOwn(payload, 'analyze_trend_max_invest_amount')) {
                    currentUiPreferences = {
                        ...currentUiPreferences,
                        analyze_trend_max_invest_amount: payload.analyze_trend_max_invest_amount,
                    };
                }

                return Promise.resolve(jsonResponse({
                    message: 'UI preferences updated.',
                    ui_preferences: currentUiPreferences,
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
                                    timestamp: 1780643400,
                                    gmtoffset: 0,
                                    datetime: '2026-06-05 07:10:00',
                                    open: '469.25000000',
                                    high: '469.50000000',
                                    low: '469.00000000',
                                    close: '469.25000000',
                                    volume: 1000,
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

            if (path === '/admin/watchlist/holdings/historical-prices/coverage') {
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
                    index_data_update_settings: indexDataUpdateSettings(),
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
        expect(window.location.search).toBe('?stock=1');
        expect(wrapper.find('[aria-label="Analyze detail stocks"]').find('.analyze-holding-card--all').exists()).toBe(false);
        expect(wrapper.text()).toContain('Charts');
        expect(wrapper.text()).toContain('Intraday');
        expect(wrapper.text()).toContain('Detail');
        expect(wrapper.text()).toContain('Trend');
        expect(wrapper.text()).toContain('Tests');
        const analyzeTabsText = wrapper.findAll('.v-tab').map((tab) => tab.text()).join(' ');
        expect(analyzeTabsText.indexOf('Charts')).toBeLessThan(analyzeTabsText.indexOf('Intraday'));
        expect(analyzeTabsText.indexOf('Intraday')).toBeLessThan(analyzeTabsText.indexOf('Detail'));
        expect(analyzeTabsText.indexOf('Detail')).toBeLessThan(analyzeTabsText.indexOf('Trend'));
        expect(analyzeTabsText.indexOf('Trend')).toBeLessThan(analyzeTabsText.indexOf('Tests'));

        const trendTab = wrapper.findAll('.v-tab')
            .find((tab) => tab.text().includes('Trend'));
        await trendTab.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/analyze/trend');
        expect(window.location.search).toBe('?stock=1');
        expect(fetchMock.mock.calls.some(([path]) => (
            path === '/admin/watchlist/holdings?page=1&include_charts=1&all_chart_holdings=1&chart_range=1y'
        ))).toBe(true);
        const analyzeTrend = wrapper.find('[aria-label="Analyze trend"]');
        const trendStockMenu = wrapper.find('[aria-label="Analyze trend stocks"]');
        expect(analyzeTrend.exists()).toBe(true);
        expect(analyzeTrend.find('.analyze-detail-title').text()).toBe('Trend');
        expect(analyzeTrend.find('.analyze-selected-stock-name').text()).toBe('Apple');
        const appleIsinCopyButton = analyzeTrend.find('[aria-label="Copy ISIN US0378331005"]');
        expect(appleIsinCopyButton.exists()).toBe(true);

        await appleIsinCopyButton.trigger('click');
        await flushPromises();

        expect(clipboardWriteText).toHaveBeenCalledWith('US0378331005');
        expect(analyzeTrend.find('[aria-label="Copy ISIN US0378331005"]').classes())
            .toContain('analyze-selected-stock-isin-copy--copied');
        expect(trendStockMenu.find('.analyze-holding-card--all').exists()).toBe(false);
        expect(trendStockMenu.text()).toContain('Apple');
        expect(trendStockMenu.text()).toContain('306.32 EUR');
        expect(trendStockMenu.text()).toContain('Pieces: 2');
        expect(trendStockMenu.text()).toContain('Microsoft');
        expect(trendStockMenu.text()).toContain('429.95 USD');
        expect(trendStockMenu.text()).toContain('Pieces: 0');
        expect(trendStockMenu.text()).toContain('Trend Signal Fund');
        expect(analyzeTrend.text()).toContain('Rows');
        expect(analyzeTrend.text()).toContain('2');
        expect(analyzeTrend.findAll('.analyze-trend-summary-label').map((label) => label.text())).toEqual([
            'Rows',
            'Total +/- over all checked stocks',
            'All amount',
            'Max invest at same time',
            'Actual +/- amount',
        ]);
        expect(analyzeTrend.find('.analyze-trend-invest-info').text())
            .toBe('Invest amounts: 1st 7,000 EUR | 2nd 5,000 EUR | 3rd+ 3,000 EUR');
        expect(analyzeTrend.find('.analyze-trend-max-invest-info').text()).toBe('Max invest: 0 EUR');
        expect(analyzeTrend.find('.analyze-trend-optimization-info').text())
            .toBe('Optimal profit: 0.00 EUR with 1st 0 EUR | 2nd 0 EUR | 3rd+ 0 EUR');
        expect(analyzeTrend.find('.analyze-trend-summary-detail').exists()).toBe(false);
        expect(analyzeTrend.find('.analyze-trend-table').findAll('th').map((heading) => heading.text())).toEqual([
            'Date',
            'Price',
            'Day %',
            '-Streak',
            'Rec',
            'DEP',
            'Evoluation',
            'Next %',
            'Wins',
            'CAP',
            'Total',
        ]);
        expect(analyzeTrend.text()).not.toContain('Trend line');
        expect(analyzeTrend.text()).not.toContain('Up/Down');
        expect(analyzeTrend.text()).not.toContain('Volume');
        expect(analyzeTrend.text()).not.toContain('Reason');
        expect(analyzeTrend.text()).not.toContain('Day +/-');
        expect(analyzeTrend.text()).not.toContain('Right?');
        const firstTrendRowCells = analyzeTrend.find('.analyze-trend-table tbody tr').findAll('td');
        expect(firstTrendRowCells).toHaveLength(11);
        expect(firstTrendRowCells[3].text()).toBe('');
        expect(firstTrendRowCells[4].text()).toBe('');
        expect(firstTrendRowCells[5].text()).toBe('');
        expect(firstTrendRowCells[6].text()).toBe('');
        expect(firstTrendRowCells[8].text()).toBe('');
        expect(firstTrendRowCells[9].text()).toBe('');
        expect(firstTrendRowCells[10].text()).toBe('');
        expect(analyzeTrend.text()).toContain('04.06.2026');
        expect(analyzeTrend.text()).toContain('+1.60%');
        expect(analyzeTrend.text()).not.toContain('Sell');
        expect(analyzeTrend.text()).not.toContain('0/0');
        expect(analyzeTrend.text()).not.toContain('No clear pattern');
        expect(analyzeTrend.text()).not.toContain('See');
        expect(analyzeTrend.text()).not.toContain('Wrong');

        const trendSignalCard = trendStockMenu.findAll('.analyze-holding-card')
            .find((button) => button.text().includes('Trend Signal Fund'));
        await trendSignalCard.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/analyze/trend');
        expect(window.location.search).toBe('?stock=7');
        expect(trendSignalCard.attributes('aria-pressed')).toBe('true');
        expect(analyzeTrend.text()).toMatch(/1 \(-0\.\d{2}%\)/);
        expect(analyzeTrend.text()).not.toContain('Sell');
        const trendSignalDepotChange = analyzeTrend.find('.analyze-trend-dep-change');
        expect(trendSignalDepotChange.exists()).toBe(true);
        expect(trendSignalDepotChange.text()).toContain('USD');
        expect(trendSignalDepotChange.text()).not.toContain('EUR');

        const buyStreakCard = trendStockMenu.findAll('.analyze-holding-card')
            .find((button) => button.text().includes('Buy Streak Fund'));
        await buyStreakCard.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/analyze/trend');
        expect(window.location.search).toBe('?stock=8');
        expect(buyStreakCard.attributes('aria-pressed')).toBe('true');
        expect(analyzeTrend.find('.analyze-selected-stock-name').text()).toBe('Buy Streak Fund');
        expect(analyzeTrend.find('[aria-label="Copy ISIN IE00BUY00008"]').exists()).toBe(true);
        const buyStreakRows = analyzeTrend.findAll('.analyze-trend-table tbody tr');
        expect(buyStreakRows).toHaveLength(14);
        expect(buyStreakRows[0].findAll('td')[3].text()).toBe('');
        expect(buyStreakRows[0].findAll('td')[4].text()).toBe('SELL 1');
        expect(buyStreakRows[0].findAll('td')[5].text()).toBe('SELL+208.48 EUR');
        expect(buyStreakRows[0].findAll('td')[5].find('.analyze-trend-rec--sell').exists()).toBe(true);
        expect(buyStreakRows[0].findAll('td')[5].find('.analyze-trend-dep-change').text()).toBe('+208.48 EUR');
        expect(buyStreakRows[0].findAll('td')[5].find('.analyze-trend-dep-change').classes()).toContain('text-success');
        expect(buyStreakRows[0].findAll('td')[6].text()).toBe('1: +3.10%');
        expect(buyStreakRows[0].findAll('td')[8].text()).toBe('217.00 EUR');
        expect(buyStreakRows[0].findAll('td')[9].text()).toBe('');
        expect(buyStreakRows[0].findAll('td')[10].text()).toBe('434.00 EUR');
        const totalTrendSummaryCard = analyzeTrend.findAll('.analyze-trend-summary-item')[1];
        expect(totalTrendSummaryCard.text()).toContain('894.00 EUR');
        expect(totalTrendSummaryCard.text()).not.toContain('%');
        const maxInvestTrendSummaryCard = analyzeTrend.findAll('.analyze-trend-summary-item')[3];
        expect(maxInvestTrendSummaryCard.text()).toContain('22,000.00 EUR');
        expect(maxInvestTrendSummaryCard.text()).toContain('12.08.2026');
        expect(buyStreakCard.text()).toContain('+434.00 EUR');
        expect(buyStreakCard.text()).toContain('Rank: 2/12');
        const overlapStreakCard = trendStockMenu.findAll('.analyze-holding-card')
            .find((button) => button.text().includes('Overlap Streak Fund'));
        expect(overlapStreakCard.text()).toContain('+460.00 EUR');
        expect(overlapStreakCard.text()).toContain('Rank: 1/12');
        const overlapStreakIncludeToggle = trendStockMenu.find('[aria-label="Exclude Overlap Streak Fund from All amount"]');
        expect(overlapStreakIncludeToggle.exists()).toBe(true);

        await overlapStreakIncludeToggle.trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/ui-preferences', expect.objectContaining({
            method: 'PATCH',
            body: JSON.stringify({
                analyze_trend_excluded_holding_ids: [9],
            }),
        }));
        expect(analyzeTrend.findAll('.analyze-trend-summary-item')[1].text()).toContain('434.00 EUR');
        const excludedOverlapStreakCard = trendStockMenu.findAll('.analyze-holding-card')
            .find((button) => button.text().includes('Overlap Streak Fund'));
        expect(excludedOverlapStreakCard.text()).not.toContain('+460.00 EUR');
        expect(excludedOverlapStreakCard.text()).not.toContain('Rank:');
        const overlapStreakIncludeAgainToggle = trendStockMenu.find('[aria-label="Include Overlap Streak Fund in All amount"]');
        expect(overlapStreakIncludeAgainToggle.exists()).toBe(true);

        await overlapStreakIncludeAgainToggle.trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/ui-preferences', expect.objectContaining({
            method: 'PATCH',
            body: JSON.stringify({
                analyze_trend_excluded_holding_ids: [],
            }),
        }));
        expect(analyzeTrend.findAll('.analyze-trend-summary-item')[1].text()).toContain('894.00 EUR');
        expect(buyStreakRows[1].findAll('td')[3].text()).toBe('');
        expect(buyStreakRows[1].findAll('td')[4].text()).toBe('');
        expect(buyStreakRows[1].findAll('td')[5].text()).toBe('-2.03 EUR');
        expect(buyStreakRows[1].findAll('td')[5].find('.analyze-trend-dep-change').classes()).toContain('text-error');
        expect(buyStreakRows[1].findAll('td')[6].text()).toBe('1: 0.00%');
        expect(buyStreakRows[1].findAll('td')[9].text()).toContain('0.00 EUR');
        expect(buyStreakRows[1].findAll('td')[9].find('.analyze-trend-cap-amount').text()).toBe('7,000.00 EUR');
        const openStreakIncludeToggle = trendStockMenu.find('[aria-label="Exclude Open Streak Fund from All amount"]');
        expect(openStreakIncludeToggle.exists()).toBe(true);

        await openStreakIncludeToggle.trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/ui-preferences', expect.objectContaining({
            method: 'PATCH',
            body: JSON.stringify({
                analyze_trend_excluded_holding_ids: [11],
            }),
        }));
        expect(window.location.search).toBe('?stock=8');
        const buyStreakRowsAfterExclude = analyzeTrend.findAll('.analyze-trend-table tbody tr');
        expect(buyStreakRowsAfterExclude[1].findAll('td')[5].text()).toBe('-2.03 EUR');
        const openStreakIncludeAgainToggle = trendStockMenu.find('[aria-label="Include Open Streak Fund in All amount"]');
        expect(openStreakIncludeAgainToggle.exists()).toBe(true);

        await openStreakIncludeAgainToggle.trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/ui-preferences', expect.objectContaining({
            method: 'PATCH',
            body: JSON.stringify({
                analyze_trend_excluded_holding_ids: [],
            }),
        }));
        expect(analyzeTrend.findAll('.analyze-trend-table tbody tr')[1].findAll('td')[5].text()).toBe('-2.03 EUR');
        expect(buyStreakRows[2].findAll('td')[3].text()).toBe('5 (-5.00%)');
        expect(buyStreakRows[2].findAll('td')[4].text()).toBe('');
        expect(buyStreakRows[2].findAll('td')[5].text()).toBe('-135.18 EUR');
        expect(buyStreakRows[2].findAll('td')[6].text()).toBe('1: -2.00%');
        expect(buyStreakRows[2].findAll('td')[9].text()).toContain('-140.00 EUR');
        expect(buyStreakRows[2].findAll('td')[9].find('.analyze-trend-cap-amount').text()).toBe('7,000.00 EUR');
        expect(buyStreakRows[3].findAll('td')[3].text()).toBe('4 (-4.00%)');
        expect(buyStreakRows[3].findAll('td')[4].text()).toBe('');
        expect(buyStreakRows[3].findAll('td')[5].text()).toBe('-67.93 EUR');
        expect(buyStreakRows[3].findAll('td')[6].text()).toBe('1: -1.00%');
        expect(buyStreakRows[3].findAll('td')[9].text()).toContain('-70.00 EUR');
        expect(buyStreakRows[3].findAll('td')[9].find('.analyze-trend-cap-amount').text()).toBe('7,000.00 EUR');
        expect(buyStreakRows[4].findAll('td')[3].text()).toBe('3 (-3.00%)');
        expect(buyStreakRows[4].findAll('td')[4].text()).toBe('BUY 1');
        expect(buyStreakRows[4].findAll('td')[5].text()).toBe('BUY');
        expect(buyStreakRows[4].findAll('td')[5].find('.analyze-trend-rec--buy').exists()).toBe(true);
        expect(buyStreakRows[4].findAll('td')[6].text()).toBe('');
        expect(buyStreakRows[4].findAll('td')[9].text()).toContain('0.00 EUR');
        expect(buyStreakRows[4].findAll('td')[9].find('.analyze-trend-cap-amount').text()).toBe('7,000.00 EUR');
        expect(buyStreakRows[4].find('.analyze-trend-rec--buy').exists()).toBe(true);
        expect(buyStreakRows[4].text()).not.toContain('BUY 2');
        expect(buyStreakRows[5].findAll('td')[3].text()).toBe('2 (-2.00%)');
        expect(buyStreakRows[5].findAll('td')[4].text()).toBe('');
        expect(buyStreakRows[6].findAll('td')[3].text()).toBe('1 (-1.00%)');
        expect(buyStreakRows[6].findAll('td')[4].text()).toBe('');
        expect(buyStreakRows[7].findAll('td')[4].text()).toBe('SELL 1');
        expect(buyStreakRows[7].findAll('td')[5].text()).toBe('');
        expect(buyStreakRows[7].findAll('td')[6].text()).toBe('1: +3.10%');
        expect(buyStreakRows[7].findAll('td')[8].text()).toBe('217.00 EUR');
        expect(buyStreakRows[7].findAll('td')[9].text()).toBe('');
        expect(buyStreakRows[7].findAll('td')[10].text()).toBe('217.00 EUR');
        expect(buyStreakRows[11].findAll('td')[3].text()).toBe('3 (-3.00%)');
        expect(buyStreakRows[11].findAll('td')[4].text()).toBe('BUY 1');
        expect(buyStreakRows[11].text()).not.toContain('BUY 2');

        const selectedOverlapStreakCard = trendStockMenu.findAll('.analyze-holding-card')
            .find((button) => button.text().includes('Overlap Streak Fund'));
        await selectedOverlapStreakCard.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/analyze/trend');
        expect(window.location.search).toBe('?stock=9');
        expect(selectedOverlapStreakCard.attributes('aria-pressed')).toBe('true');
        const overlapRows = analyzeTrend.findAll('.analyze-trend-table tbody tr');
        expect(overlapRows).toHaveLength(8);
        expect(overlapRows[1].findAll('td')[4].text()).toBe('BUY 2');
        expect(overlapRows[1].findAll('td')[5].text()).toBe('');
        expect(overlapRows[1].findAll('td')[6].text()).toBe('1: -2.00%');
        expect(overlapRows[1].findAll('td')[9].text()).toContain('-140.00 EUR');
        expect(overlapRows[1].findAll('td')[9].find('.analyze-trend-cap-amount').text()).toBe('12,000.00 EUR');
        expect(overlapRows[0].findAll('td')[4].text()).toContain('SELL 1');
        expect(overlapRows[0].findAll('td')[4].text()).toContain('SELL 2');
        expect(overlapRows[0].findAll('td')[5].text()).toBe('');
        expect(overlapRows[0].findAll('td')[8].text()).toBe('460.00 EUR');
        expect(overlapRows[0].findAll('td')[9].text()).toBe('');
        expect(overlapRows[0].findAll('td')[10].text()).toBe('460.00 EUR');

        const tripleStreakCard = trendStockMenu.findAll('.analyze-holding-card')
            .find((button) => button.text().includes('Triple Streak Fund'));
        await tripleStreakCard.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/analyze/trend');
        expect(window.location.search).toBe('?stock=12');
        expect(tripleStreakCard.attributes('aria-pressed')).toBe('true');
        const tripleRows = analyzeTrend.findAll('.analyze-trend-table tbody tr');
        expect(tripleRows[0].findAll('td')[4].text()).toBe('BUY 3');
        expect(tripleRows[0].findAll('td')[9].find('.analyze-trend-cap-amount').text()).toBe('15,000.00 EUR');

        const hiddenPreAnalysisCard = trendStockMenu.findAll('.analyze-holding-card')
            .find((button) => button.text().includes('Hidden Pre Analysis Fund'));
        await hiddenPreAnalysisCard.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/analyze/trend');
        expect(window.location.search).toBe('?stock=10');
        expect(hiddenPreAnalysisCard.attributes('aria-pressed')).toBe('true');
        const hiddenPreAnalysisRows = analyzeTrend.findAll('.analyze-trend-table tbody tr');
        expect(hiddenPreAnalysisRows).toHaveLength(200);
        const hiddenPreAnalysisFirstRowCells = hiddenPreAnalysisRows[199].findAll('td');
        expect(hiddenPreAnalysisFirstRowCells[4].text()).toBe('');
        expect(hiddenPreAnalysisFirstRowCells[5].text()).toBe('');
        expect(hiddenPreAnalysisFirstRowCells[6].text()).toBe('');
        expect(hiddenPreAnalysisFirstRowCells[9].text()).toBe('');

        await analyzeTrend.find('.analyze-trend-summary-item--button').trigger('click');
        await flushPromises();

        const rowsDialog = document.body.querySelector('.analyze-trend-rows-dialog');
        expect(rowsDialog).not.toBeNull();
        expect(rowsDialog.textContent).toContain('Edit rows');
        const rowsInput = rowsDialog.querySelector('input[type="number"]');
        expect(rowsInput).not.toBeNull();

        rowsInput.value = '50';
        rowsInput.dispatchEvent(new Event('input', { bubbles: true }));
        const saveRowsButton = Array.from(rowsDialog.querySelectorAll('button'))
            .find((button) => button.textContent.trim() === 'Save');
        expect(saveRowsButton).not.toBeNull();
        saveRowsButton.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/ui-preferences', expect.objectContaining({
            method: 'PATCH',
            body: JSON.stringify({
                analyze_trend_row_limit: 50,
            }),
        }));
        expect(analyzeTrend.findAll('.analyze-trend-table tbody tr')).toHaveLength(50);

        await analyzeTrend.find('.analyze-trend-invest-info').trigger('click');
        await flushPromises();

        const investDialog = document.body.querySelector('.analyze-trend-invest-dialog');
        expect(investDialog).not.toBeNull();
        expect(investDialog.textContent).toContain('Edit invest amounts');
        const investInputs = investDialog.querySelectorAll('input[type="number"]');
        expect(investInputs).toHaveLength(3);

        investInputs[0].value = '8000';
        investInputs[0].dispatchEvent(new Event('input', { bubbles: true }));
        investInputs[1].value = '0';
        investInputs[1].dispatchEvent(new Event('input', { bubbles: true }));
        investInputs[2].value = '0';
        investInputs[2].dispatchEvent(new Event('input', { bubbles: true }));
        const saveInvestButton = Array.from(investDialog.querySelectorAll('button'))
            .find((button) => button.textContent.trim() === 'Save');
        expect(saveInvestButton).not.toBeNull();
        saveInvestButton.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/ui-preferences', expect.objectContaining({
            method: 'PATCH',
            body: JSON.stringify({
                analyze_trend_trade_amounts: [8000, 0, 0],
            }),
        }));
        expect(analyzeTrend.find('.analyze-trend-invest-info').text())
            .toContain('Invest amounts: 1st 8,000 EUR | 2nd 0 EUR | 3rd+ 0 EUR');

        await analyzeTrend.find('.analyze-trend-max-invest-info').trigger('click');
        await flushPromises();

        const maxInvestDialog = document.body.querySelector('.analyze-trend-max-invest-dialog');
        expect(maxInvestDialog).not.toBeNull();
        expect(maxInvestDialog.textContent).toContain('Edit max invest');
        const maxInvestInput = maxInvestDialog.querySelector('input[type="number"]');
        expect(maxInvestInput).not.toBeNull();

        maxInvestInput.value = '25000';
        maxInvestInput.dispatchEvent(new Event('input', { bubbles: true }));
        const saveMaxInvestButton = Array.from(maxInvestDialog.querySelectorAll('button'))
            .find((button) => button.textContent.trim() === 'Save');
        expect(saveMaxInvestButton).not.toBeNull();
        saveMaxInvestButton.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/ui-preferences', expect.objectContaining({
            method: 'PATCH',
            body: JSON.stringify({
                analyze_trend_max_invest_amount: 25000,
            }),
        }));
        expect(analyzeTrend.find('.analyze-trend-max-invest-info').text()).toContain('Max invest: 25,000 EUR');
        expect(analyzeTrend.find('.analyze-trend-optimization-info').text()).toContain('Optimal profit:');
        expect(analyzeTrend.find('.analyze-trend-optimization-info').text()).toContain('1st');
        expect(analyzeTrend.find('.analyze-trend-optimization-info').text()).toContain('2nd');
        expect(analyzeTrend.find('.analyze-trend-optimization-info').text()).toContain('3rd+');

        const microsoftTrendCard = trendStockMenu.findAll('.analyze-holding-card')
            .find((button) => button.text().includes('Microsoft'));
        await microsoftTrendCard.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/analyze/trend');
        expect(window.location.search).toBe('?stock=2');
        expect(microsoftTrendCard.attributes('aria-pressed')).toBe('true');
        expect(analyzeTrend.text()).toContain('No daily prices stored for this stock.');

        const appleTrendCard = trendStockMenu.findAll('.analyze-holding-card')
            .find((button) => button.text().includes('Apple'));
        await appleTrendCard.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/analyze/trend');
        expect(window.location.search).toBe('?stock=1');
        expect(appleTrendCard.attributes('aria-pressed')).toBe('true');
        expect(analyzeTrend.text()).toContain('04.06.2026');

        const intradayTab = wrapper.findAll('.v-tab')
            .find((tab) => tab.text().includes('Intraday'));
        await intradayTab.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/analyze/intraday');
        expect(window.location.search).toBe('?stock=1');
        expect(wrapper.find('[aria-label="Analyze intraday stocks"]').find('.analyze-holding-card--all').exists()).toBe(false);
        expect(wrapper.find('[aria-label="Analyze intraday"]').text()).toContain('Apple');
        expect(wrapper.find('[aria-label="Analyze intraday"]').text()).not.toContain('No stock selected.');

        const intradayFilterCard = wrapper.find('[aria-label="Analyze intraday stocks"]').findAll('.analyze-holding-card')
            .find((button) => button.text().includes('Intraday Filter Fund'));
        await intradayFilterCard.trigger('click');
        await flushPromises();

        const analyzeIntraday = wrapper.find('[aria-label="Analyze intraday"]');
        expect(window.location.pathname).toBe('/admin/menu/analyze/intraday');
        expect(window.location.search).toBe('?stock=6');
        expect(analyzeIntraday.text()).toContain('Intraday prices');
        expect(analyzeIntraday.findAll('.analyze-intraday-card')).toHaveLength(2);
        expect(analyzeIntraday.find('[aria-label="Analyze real-time prices"]').text()).toContain('Real-time');
        expect(analyzeIntraday.find('[aria-label="Analyze stored intraday prices"]').text()).toContain('Intraday');
        expect(analyzeIntraday.text()).toContain('Date');
        expect(analyzeIntraday.text()).toContain('Time');
        expect(analyzeIntraday.text()).toContain('Price');
        expect(analyzeIntraday.text()).toContain('Prev %');
        expect(analyzeIntraday.text()).toContain('First %');
        expect(analyzeIntraday.text()).not.toContain('Timestamp');
        expect(analyzeIntraday.text()).not.toContain('Currency');
        expect(analyzeIntraday.text()).not.toContain('Source');
        expect(analyzeIntraday.text()).toContain('05.06.2026');
        expect(analyzeIntraday.text()).toContain('04.06.2026');
        const realTimeCard = analyzeIntraday.find('[aria-label="Analyze real-time prices"]');
        const intradayCard = analyzeIntraday.find('[aria-label="Analyze stored intraday prices"]');
        expect(realTimeCard.findAll('tbody tr')).toHaveLength(3);
        expect(realTimeCard.find('tbody tr').findAll('td').map((cell) => cell.text())).toEqual([
            '04.06.2026',
            '12:55',
            '303.80',
            '-',
            '0.00%',
        ]);
        expect(realTimeCard.findAll('tbody tr')[1].findAll('td').map((cell) => cell.text())).toEqual([
            '05.06.2026',
            '11:50',
            '304.50',
            '+0.23%',
            '+0.23%',
        ]);
        expect(intradayCard.findAll('tbody tr')).toHaveLength(21);
        expect(intradayCard.find('tbody tr').findAll('td').map((cell) => cell.text())).toEqual([
            '04.06.2026',
            '12:55',
            '291.10',
            '-',
            '0.00%',
        ]);
        expect(intradayCard.findAll('tbody tr')[1].findAll('td').map((cell) => cell.text())).toEqual([
            '05.06.2026',
            '12:00',
            '305.00',
            '+4.77%',
            '+4.77%',
        ]);
        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/watchlist/holdings/1/intraday-candles')).toBe(true);

        const intradayAppleCard = wrapper.find('[aria-label="Analyze intraday stocks"]').findAll('.analyze-holding-card')
            .find((button) => button.text().includes('Apple'));
        await intradayAppleCard.trigger('click');
        await flushPromises();

        const overviewTab = wrapper.findAll('.v-tab')
            .find((tab) => tab.text().includes('Charts'));
        await overviewTab.trigger('click');
        await flushPromises();
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/analyze/overview');
        expect(window.location.search).toBe('?stock=1');
        const analyzeOverview = wrapper.find('[aria-label="Analyze overview"]');
        expect(analyzeOverview.exists()).toBe(true);
        expect(analyzeOverview.find('.analyze-detail-title').text()).toBe('Charts');
        expect(analyzeOverview.find('.analyze-holding-card--all').exists()).toBe(false);
        expect(analyzeOverview.text()).toContain('Apple');
        expect(analyzeOverview.text()).toContain('306.32 EUR');
        expect(analyzeOverview.text()).toContain('Pieces: 2');
        expect(analyzeOverview.text()).toContain('Microsoft');
        expect(analyzeOverview.text()).toContain('429.95 USD');
        expect(analyzeOverview.text()).toContain('Pieces: 0');
        expect(analyzeOverview.text()).toContain('Small Price Fund');
        expect(analyzeOverview.text()).toContain('44.220 EUR');
        expect(analyzeOverview.text()).toContain('Tiny Price Fund');
        expect(analyzeOverview.text()).toContain('2.6800 EUR');
        expect(analyzeOverview.text()).not.toContain('INDEX');
        expect(analyzeOverview.text()).not.toContain('History');
        expect(analyzeOverview.text()).not.toContain('historical price records loaded/updated.');
        expect(analyzeOverview.text()).not.toContain('Checking historical prices');
        expect(analyzeOverview.text()).not.toContain('2/2');

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
        expect(analyzeOverview.text()).toContain('today-1');

        const oneYearRangeButton = analyzeOverview.findAll('.analyze-range-button')
            .find((button) => button.text() === '1 year');
        const sixMonthRangeButton = analyzeOverview.findAll('.analyze-range-button')
            .find((button) => button.text() === '6 months');
        const threeMonthRangeButton = analyzeOverview.findAll('.analyze-range-button')
            .find((button) => button.text() === '3 months');
        const oneMonthRangeButton = analyzeOverview.findAll('.analyze-range-button')
            .find((button) => button.text() === '1 month');
        const oneWeekRangeButton = analyzeOverview.findAll('.analyze-range-button')
            .find((button) => button.text() === '1 week');
        const todayRangeButton = analyzeOverview.findAll('.analyze-range-button')
            .find((button) => button.text() === 'today');
        const todayMinusOneRangeButton = analyzeOverview.findAll('.analyze-range-button')
            .find((button) => button.text() === 'today-1');
        const previousChartWindowButton = analyzeOverview.find('[aria-label="Previous chart window"]');
        const nextChartWindowButton = analyzeOverview.find('[aria-label="Next chart window"]');

        expect(oneYearRangeButton.attributes('aria-pressed')).toBe('true');
        expect(previousChartWindowButton.attributes('disabled')).toBe('');
        expect(nextChartWindowButton.attributes('disabled')).toBe('');
        expect(analyzeOverview.text()).toContain('365-day capture');
        await oneMonthRangeButton.trigger('click');
        await flushPromises();

        expect(oneMonthRangeButton.attributes('aria-pressed')).toBe('true');
        expect(oneYearRangeButton.attributes('aria-pressed')).toBe('false');
        expect(analyzeOverview.text()).toContain('31-day capture');
        expect(analyzeOverview.find('.analyze-sparkline').exists()).toBe(true);
        expect(analyzeOverview.find('.analyze-sparkline').attributes('viewBox')).toBe('0 0 1440 600');
        expect(analyzeOverview.findAll('.analyze-sparkline-label').length).toBeGreaterThanOrEqual(4);
        expect(analyzeOverview.findAll('.analyze-sparkline-y-label').map((label) => label.text())).toEqual([
            '307.14',
            '302.65',
            '298.16',
            '293.67',
            '289.18',
        ]);
        expect(analyzeOverview.findAll('.analyze-sparkline-dot')).toHaveLength(0);
        expect(analyzeOverview.find('.analyze-sparkline-trend-line').exists()).toBe(true);
        expect(Number(analyzeOverview.find('.analyze-sparkline-trend-line').attributes('x2'))).toBeGreaterThan(
            Number(analyzeOverview.find('.analyze-sparkline-trend-line').attributes('x1')),
        );
        expect(analyzeOverview.findAll('.analyze-sparkline-endpoint-label')).toHaveLength(2);
        expect(analyzeOverview.find('.analyze-sparkline-endpoint-label--start').text()).toBe('Start 290.00');
        expect(analyzeOverview.find('.analyze-sparkline-endpoint-label--latest').text()).toBe('End 306.32 +5.63%');
        expect(Number(analyzeOverview.find('.analyze-sparkline-endpoint-label--start').attributes('y'))).toBeGreaterThan(500);
        expect(Number(analyzeOverview.find('.analyze-sparkline-endpoint-label--latest').attributes('y'))).toBeLessThan(30);
        expect(analyzeOverview.find('.analyze-sparkline-extremum--high').exists()).toBe(true);
        expect(analyzeOverview.find('.analyze-sparkline-extremum--low').exists()).toBe(true);
        expect(analyzeOverview.findAll('.analyze-sparkline-extremum-ring')).toHaveLength(2);
        expect(analyzeOverview.findAll('.analyze-sparkline-extremum-dot')).toHaveLength(2);
        expect(analyzeOverview.find('.analyze-sparkline-extremum--high .analyze-sparkline-extremum-label').text()).toBe('306.32');
        expect(analyzeOverview.find('.analyze-sparkline-extremum--low .analyze-sparkline-extremum-label').text()).toBe('290.00');

        await oneWeekRangeButton.trigger('click');
        await flushPromises();

        expect(oneWeekRangeButton.attributes('aria-pressed')).toBe('true');
        expect(analyzeOverview.text()).toContain('7-day capture');
        expect(analyzeOverview.findAll('.analyze-sparkline-dot')).toHaveLength(0);
        expect(analyzeOverview.find('.analyze-sparkline-line').attributes('d').match(/[ML]/g)).toHaveLength(256);
        expect(analyzeOverview.find('.analyze-sparkline-endpoint-label--start').text()).toBe('Start 305.00');
        expect(analyzeOverview.find('.analyze-sparkline-endpoint-label--latest').text()).toBe('End 306.90 +0.62%');

        await todayRangeButton.trigger('click');
        await flushPromises();

        expect(todayRangeButton.attributes('aria-pressed')).toBe('true');
        expect(analyzeOverview.text()).toContain('05.06.26, 11:55');
        expect(analyzeOverview.text()).toContain('306.32');
        expect(analyzeOverview.text()).toContain('all prices captured');
        expect(analyzeOverview.findAll('.analyze-sparkline-dot')).toHaveLength(144);
        const todayLabels = analyzeOverview.findAll('.analyze-sparkline-month-label').map((label) => label.text());
        const todayDateRangeLabels = analyzeOverview.findAll('.analyze-sparkline-date-range-label');
        expect(todayLabels).toEqual([
            '01:00',
            '02:00',
            '03:00',
            '04:00',
            '05:00',
            '06:00',
            '07:00',
            '08:00',
            '09:00',
            '10:00',
            '11:00',
        ]);
        expect(todayDateRangeLabels.map((label) => label.text())).toEqual(['00:00', '11:55']);
        expect(analyzeOverview.findAll('.analyze-sparkline-month-line')).toHaveLength(11);
        expect(analyzeOverview.findAll('.analyze-sparkline-grid-line--vertical')).toHaveLength(0);
        expect(analyzeOverview.find('.analyze-sparkline-previous-close-line').exists()).toBe(false);

        await todayMinusOneRangeButton.trigger('click');
        await flushPromises();

        expect(todayMinusOneRangeButton.attributes('aria-pressed')).toBe('true');
        expect(analyzeOverview.text()).toContain('with previous close');
        expect(analyzeOverview.findAll('.analyze-sparkline-dot')).toHaveLength(145);
        expect(analyzeOverview.find('.analyze-sparkline-previous-close-line').exists()).toBe(false);
        expect(analyzeOverview.find('.analyze-sparkline-previous-close-label').exists()).toBe(false);
        const todayMinusOneStartLabel = analyzeOverview.find('.analyze-sparkline-endpoint-label--start').text();
        expect(todayMinusOneStartLabel).toContain('Prev 298.10');
        expect(todayMinusOneStartLabel).toContain('Start 298.12 +0.01%');
        expect(analyzeOverview.find('.analyze-sparkline-endpoint-label--today-start').exists()).toBe(false);
        expect(analyzeOverview.find('.analyze-sparkline-endpoint-label-change--up').text()).toBe('Start 298.12 +0.01%');
        expect(analyzeOverview.findAll('.analyze-sparkline-date-range-label').map((label) => label.text())).toEqual(['00:00', '11:55']);

        const highMarkerLine = analyzeOverview.find('.analyze-sparkline-extremum--high .analyze-sparkline-extremum-line');
        const lowMarkerLine = analyzeOverview.find('.analyze-sparkline-extremum--low .analyze-sparkline-extremum-line');
        const highMarkerLabel = analyzeOverview.find('.analyze-sparkline-extremum--high .analyze-sparkline-extremum-label');
        const lowMarkerLabel = analyzeOverview.find('.analyze-sparkline-extremum--low .analyze-sparkline-extremum-label');

        expect(highMarkerLine.attributes('y1')).not.toBe(highMarkerLine.attributes('y2'));
        expect(lowMarkerLine.exists()).toBe(true);
        expect(lowMarkerLabel.text()).toBe('298.10');
        expect(['start', 'end']).toContain(highMarkerLabel.attributes('text-anchor'));

        await oneYearRangeButton.trigger('click');
        await flushPromises();

        expect(analyzeOverview.findAll('.analyze-sparkline-dot')).toHaveLength(0);

        const monthMarkerCard = analyzeOverview.findAll('.analyze-holding-card')
            .find((button) => button.text().includes('Month Marker Fund'));
        await monthMarkerCard.trigger('click');
        await flushPromises();

        const monthLabels = analyzeOverview.findAll('.analyze-sparkline-month-label').map((label) => label.text());
        const dateRangeLabels = analyzeOverview.findAll('.analyze-sparkline-date-range-label');
        const monthPriceLabels = analyzeOverview.findAll('.analyze-sparkline-month-price-label');
        expect(analyzeOverview.findAll('.analyze-sparkline-month-line')).toHaveLength(12);
        expect(monthPriceLabels).toHaveLength(12);
        expect(monthPriceLabels.every((label) => label.text().length > 0)).toBe(true);
        expect(monthLabels).toEqual([
            '01.07.',
            '01.08.',
            '01.09.',
            '01.10.',
            '31.10.',
            '01.12.',
            '01.01.',
            '02.02.',
            '02.03.',
            '01.04.',
            '01.05.',
            '01.06.',
        ]);
        expect(dateRangeLabels.map((label) => label.text())).toEqual(['16.06.', '12.06.']);
        expect(dateRangeLabels[0].attributes('text-anchor')).toBe('start');
        expect(dateRangeLabels[1].attributes('text-anchor')).toBe('end');
        expect(analyzeOverview.find('.analyze-sparkline-line').attributes('d').match(/[ML]/g)).toHaveLength(256);
        expect(analyzeOverview.findAll('.analyze-sparkline-grid-line--vertical')).toHaveLength(0);

        await sixMonthRangeButton.trigger('click');
        await flushPromises();

        const sixMonthLabels = analyzeOverview.findAll('.analyze-sparkline-month-label').map((label) => label.text());
        const sixMonthDateRangeLabels = analyzeOverview.findAll('.analyze-sparkline-date-range-label');
        const sixMonthPriceLabels = analyzeOverview.findAll('.analyze-sparkline-month-price-label');
        expect(sixMonthRangeButton.attributes('aria-pressed')).toBe('true');
        expect(analyzeOverview.findAll('.analyze-sparkline-month-line')).toHaveLength(12);
        expect(sixMonthPriceLabels).toHaveLength(12);
        expect(sixMonthPriceLabels.every((label) => label.text().length > 0)).toBe(true);
        expect(sixMonthLabels).toEqual([
            '15.12.',
            '01.01.',
            '15.01.',
            '02.02.',
            '16.02.',
            '02.03.',
            '16.03.',
            '01.04.',
            '15.04.',
            '01.05.',
            '15.05.',
            '01.06.',
        ]);
        expect(sixMonthDateRangeLabels.map((label) => label.text())).toEqual(['11.12.', '12.06.']);
        expect(sixMonthDateRangeLabels[0].attributes('text-anchor')).toBe('start');
        expect(sixMonthDateRangeLabels[1].attributes('text-anchor')).toBe('end');
        expect(analyzeOverview.find('.analyze-sparkline-line').attributes('d').match(/[ML]/g)).toHaveLength(256);
        expect(analyzeOverview.findAll('.analyze-sparkline-grid-line--vertical')).toHaveLength(0);
        expect(previousChartWindowButton.attributes('disabled')).toBeUndefined();
        expect(nextChartWindowButton.attributes('disabled')).toBe('');

        await previousChartWindowButton.trigger('click');
        await flushPromises();

        const previousSixMonthDateRangeLabels = analyzeOverview.findAll('.analyze-sparkline-date-range-label');
        expect(previousSixMonthDateRangeLabels.map((label) => label.text())).toEqual(['16.06.', '10.12.']);
        expect(previousChartWindowButton.attributes('disabled')).toBe('');
        expect(nextChartWindowButton.attributes('disabled')).toBeUndefined();

        await nextChartWindowButton.trigger('click');
        await flushPromises();

        expect(analyzeOverview.findAll('.analyze-sparkline-date-range-label').map((label) => label.text())).toEqual(['11.12.', '12.06.']);
        expect(previousChartWindowButton.attributes('disabled')).toBeUndefined();
        expect(nextChartWindowButton.attributes('disabled')).toBe('');

        await threeMonthRangeButton.trigger('click');
        await flushPromises();

        const threeMonthLabels = analyzeOverview.findAll('.analyze-sparkline-month-label').map((label) => label.text());
        const threeMonthDateRangeLabels = analyzeOverview.findAll('.analyze-sparkline-date-range-label');
        const threeMonthPriceLabels = analyzeOverview.findAll('.analyze-sparkline-month-price-label');
        expect(threeMonthRangeButton.attributes('aria-pressed')).toBe('true');
        expect(analyzeOverview.findAll('.analyze-sparkline-dot')).toHaveLength(0);
        expect(analyzeOverview.findAll('.analyze-sparkline-month-line')).toHaveLength(9);
        expect(threeMonthPriceLabels).toHaveLength(9);
        expect(threeMonthPriceLabels.every((label) => label.text().length > 0)).toBe(true);
        expect(threeMonthLabels).toEqual([
            '20.03.',
            '01.04.',
            '10.04.',
            '20.04.',
            '01.05.',
            '11.05.',
            '20.05.',
            '01.06.',
            '10.06.',
        ]);
        expect(threeMonthDateRangeLabels.map((label) => label.text())).toEqual(['12.03.', '12.06.']);
        expect(threeMonthDateRangeLabels[0].attributes('text-anchor')).toBe('start');
        expect(threeMonthDateRangeLabels[1].attributes('text-anchor')).toBe('end');
        expect(analyzeOverview.find('.analyze-sparkline-line').attributes('d').match(/[ML]/g)).toHaveLength(256);
        const threeMonthMarkerPositions = analyzeOverview.findAll('.analyze-sparkline-month-line')
            .map((line) => Number(line.attributes('x1')));
        const threeMonthMarkerDistances = threeMonthMarkerPositions.slice(1)
            .map((position, index) => position - threeMonthMarkerPositions[index]);
        const threeMonthEndGap = Number(threeMonthDateRangeLabels[1].attributes('x')) - threeMonthMarkerPositions.at(-1);
        expect(Math.abs(threeMonthEndGap - (threeMonthMarkerDistances.at(-1) * (2 / 9)))).toBeLessThan(0.01);
        expect(analyzeOverview.findAll('.analyze-sparkline-grid-line--vertical')).toHaveLength(0);

        await oneMonthRangeButton.trigger('click');
        await flushPromises();

        const oneMonthLabels = analyzeOverview.findAll('.analyze-sparkline-month-label').map((label) => label.text());
        const oneMonthDateRangeLabels = analyzeOverview.findAll('.analyze-sparkline-date-range-label');
        const oneMonthPriceLabels = analyzeOverview.findAll('.analyze-sparkline-month-price-label');
        expect(oneMonthRangeButton.attributes('aria-pressed')).toBe('true');
        expect(analyzeOverview.findAll('.analyze-sparkline-dot')).toHaveLength(0);
        expect(analyzeOverview.findAll('.analyze-sparkline-month-line')).toHaveLength(10);
        expect(oneMonthLabels).toEqual([
            '15.05.',
            '18.05.',
            '21.05.',
            '25.05.',
            '27.05.',
            '29.05.',
            '02.06.',
            '05.06.',
            '08.06.',
            '11.06.',
        ]);
        expect(oneMonthDateRangeLabels.map((label) => label.text())).toEqual(['12.05.', '12.06.']);
        expect(oneMonthDateRangeLabels[0].attributes('text-anchor')).toBe('start');
        expect(oneMonthDateRangeLabels[1].attributes('text-anchor')).toBe('end');
        expect(oneMonthPriceLabels).toHaveLength(10);
        expect(oneMonthPriceLabels.every((label) => label.text().length > 0)).toBe(true);
        expect(analyzeOverview.find('.analyze-sparkline-line').attributes('d').match(/[ML]/g)).toHaveLength(256);
        const oneMonthMarkerPositions = analyzeOverview.findAll('.analyze-sparkline-month-line')
            .map((line) => Number(line.attributes('x1')));
        const oneMonthMarkerDistances = oneMonthMarkerPositions.slice(1)
            .map((position, index) => position - oneMonthMarkerPositions[index]);
        const firstOneMonthMarkerDistance = oneMonthMarkerDistances[0];
        expect(oneMonthMarkerDistances.slice(0, -1).every((distance) => Math.abs(distance - firstOneMonthMarkerDistance) < 0.01)).toBe(true);
        const oneMonthEndGap = Number(oneMonthDateRangeLabels[1].attributes('x')) - oneMonthMarkerPositions.at(-1);
        expect(Math.abs(oneMonthEndGap - (firstOneMonthMarkerDistance / 3))).toBeLessThan(0.01);
        expect(analyzeOverview.findAll('.analyze-sparkline-grid-line--vertical')).toHaveLength(0);

        await oneWeekRangeButton.trigger('click');
        await flushPromises();

        const oneWeekLabels = analyzeOverview.findAll('.analyze-sparkline-month-label').map((label) => label.text());
        const oneWeekDateRangeLabels = analyzeOverview.findAll('.analyze-sparkline-date-range-label');
        const oneWeekPriceLabels = analyzeOverview.findAll('.analyze-sparkline-month-price-label');
        expect(oneWeekRangeButton.attributes('aria-pressed')).toBe('true');
        expect(analyzeOverview.findAll('.analyze-sparkline-dot')).toHaveLength(0);
        expect(analyzeOverview.findAll('.analyze-sparkline-month-line')).toHaveLength(4);
        expect(oneWeekLabels).toEqual([
            '08.06.',
            '09.06.',
            '10.06.',
            '11.06.',
        ]);
        expect(oneWeekDateRangeLabels.map((label) => label.text())).toEqual(['05.06.', '12.06.']);
        expect(oneWeekPriceLabels).toHaveLength(4);
        expect(oneWeekPriceLabels.every((label) => label.text().length > 0)).toBe(true);
        expect(analyzeOverview.find('.analyze-sparkline-line').attributes('d').match(/[ML]/g)).toHaveLength(256);
        const oneWeekMarkerPositions = analyzeOverview.findAll('.analyze-sparkline-month-line')
            .map((line) => Number(line.attributes('x1')));
        const oneWeekMarkerDistances = oneWeekMarkerPositions.slice(1)
            .map((position, index) => position - oneWeekMarkerPositions[index]);
        const firstOneWeekMarkerDistance = oneWeekMarkerDistances[0];
        expect(oneWeekMarkerDistances.every((distance) => Math.abs(distance - firstOneWeekMarkerDistance) < 0.01)).toBe(true);
        const oneWeekEndGap = Number(oneWeekDateRangeLabels[1].attributes('x')) - oneWeekMarkerPositions.at(-1);
        expect(Math.abs(oneWeekEndGap - firstOneWeekMarkerDistance)).toBeLessThan(0.01);
        expect(analyzeOverview.findAll('.analyze-sparkline-grid-line--vertical')).toHaveLength(0);

        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/watchlist/holdings/historical-prices/ensure')).toBe(false);

        wrapper.unmount();
    }, 15000);

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

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
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
                                { trading_date: '2026-06-02', price: '465.150000', currency: 'EUR' },
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
                                    timestamp: 1780643400,
                                    gmtoffset: 0,
                                    datetime: '2026-06-05 07:10:00',
                                    open: '469.25000000',
                                    high: '469.50000000',
                                    low: '469.00000000',
                                    close: '469.25000000',
                                    volume: 1000,
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

            if (path === '/admin/watchlist/holdings/historical-prices/coverage') {
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

        expect(window.location.pathname).toBe('/admin/menu/analyze/overview');
        expect(window.location.search).toBe('?stock=1');
        expect(appleCard.attributes('aria-pressed')).toBe('true');
        expect(analyzeOverview.find('.analyze-holding-card--all').exists()).toBe(false);
        expect(analyzeOverview.text()).toContain('1 year');

        const detailTab = wrapper.findAll('.v-tab')
            .find((tab) => tab.text().includes('Detail'));
        await detailTab.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/analyze/detail');
        expect(window.location.search).toBe('?stock=1');
        expect(wrapper.find('[aria-label="Analyze detail"] .analyze-detail-title').text()).toBe('Details');
        expect(wrapper.find('[aria-label="Analyze detail"]').text()).toContain('Apple');
        expect(wrapper.find('[aria-label="Analyze detail"]').text()).toContain('Intraday 05.06.2026');
        expect(wrapper.find('[aria-label="Analyze detail"]').text()).toContain('Intraday 04.06.2026');
        expect(wrapper.find('[aria-label="Analyze detail"]').text()).toContain('Intraday 03.06.2026');
        expect(wrapper.find('[aria-label="Analyze detail"]').text()).not.toContain('- 5m');
        expect(wrapper.find('[aria-label="Analyze detail"]').text()).not.toContain('470.15000000');
        expect(wrapper.find('[aria-label="Analyze detail"]').text()).not.toContain('472.75000000');
        expect(wrapper.find('[aria-label="Analyze detail"]').text()).not.toContain('469.15000000');

        const firstIntradayDaySummary = wrapper.find('.analyze-detail-day-summary');
        expect(firstIntradayDaySummary.text()).toContain('Last day -> Latest');
        expect(firstIntradayDaySummary.text()).toContain('Last day -> First');
        expect(firstIntradayDaySummary.text()).toContain('Start -> 12:00');
        expect(firstIntradayDaySummary.text()).toContain('12:00 -> End');
        expect(firstIntradayDaySummary.text().indexOf('Last day -> First')).toBeLessThan(
            firstIntradayDaySummary.text().indexOf('Last day -> Latest'),
        );
        expect(firstIntradayDaySummary.text().indexOf('Last day -> Latest')).toBeLessThan(
            firstIntradayDaySummary.text().indexOf('Start -> 12:00'),
        );
        expect(firstIntradayDaySummary.text().indexOf('Start -> 12:00')).toBeLessThan(
            firstIntradayDaySummary.text().indexOf('12:00 -> End'),
        );
        expect(firstIntradayDaySummary.text()).toContain('470.15');
        expect(firstIntradayDaySummary.text()).toContain('469.15');
        expect(firstIntradayDaySummary.text()).toContain('472.75');
        expect(firstIntradayDaySummary.text()).toContain('471.75');
        expect(firstIntradayDaySummary.text()).toContain('+2.6000');
        expect(firstIntradayDaySummary.text()).toContain('+1.0000');
        expect(firstIntradayDaySummary.text()).toContain('-1.0000');
        expect(firstIntradayDaySummary.text()).toContain('+0.21%');
        expect(firstIntradayDaySummary.text()).toContain('+0.55%');
        expect(firstIntradayDaySummary.text()).toContain('-0.21%');
        expect(firstIntradayDaySummary.text()).not.toContain('3d start');
        expect(firstIntradayDaySummary.text()).not.toContain('Lowest');
        expect(firstIntradayDaySummary.text()).not.toContain('Highest');
        expect(firstIntradayDaySummary.text()).not.toContain('Ups');
        expect(firstIntradayDaySummary.text()).not.toContain('Downs');
        expect(firstIntradayDaySummary.text()).not.toContain('12:00+');
        expect(firstIntradayDaySummary.findAll('.analyze-detail-day-summary-item.is-compact')).toHaveLength(0);
        expect(firstIntradayDaySummary.findAll('.analyze-detail-day-summary-item.is-comparison')).toHaveLength(4);
        expect(firstIntradayDaySummary.findAll('.analyze-detail-day-summary-change.is-up')).toHaveLength(6);
        expect(firstIntradayDaySummary.findAll('.analyze-detail-day-summary-change.is-down')).toHaveLength(2);
        expect(firstIntradayDaySummary.text()).not.toContain('460.00');
        expect(firstIntradayDaySummary.text()).not.toContain('480.00');
        expect(firstIntradayDaySummary.text()).not.toContain('473.25');

        const firstIntradaySummaryCards = firstIntradayDaySummary.findAll('.analyze-detail-day-summary-item.is-comparison');
        expect(firstIntradaySummaryCards[0].find('.analyze-detail-day-summary-move-ratio').exists()).toBe(false);
        expect(firstIntradaySummaryCards[1].find('.analyze-detail-day-summary-move-ratio').text()).toContain('Up 33%');
        expect(firstIntradaySummaryCards[1].find('.analyze-detail-day-summary-move-ratio').text()).toContain('Down 67%');
        expect(firstIntradaySummaryCards[2].find('.analyze-detail-day-summary-move-ratio').text()).toContain('Up 50%');
        expect(firstIntradaySummaryCards[2].find('.analyze-detail-day-summary-move-ratio').text()).toContain('Down 50%');
        expect(firstIntradaySummaryCards[3].find('.analyze-detail-day-summary-move-ratio').text()).toContain('Up 0%');
        expect(firstIntradaySummaryCards[3].find('.analyze-detail-day-summary-move-ratio').text()).toContain('Down 100%');

        const firstHourlySummary = wrapper.find('.analyze-detail-hourly-summary');
        expect(firstHourlySummary.text()).toContain('Hourly');
        expect(firstHourlySummary.text()).toContain('Europe/Vienna');
        expect(firstHourlySummary.findAll('.analyze-detail-hourly-summary-card')).toHaveLength(3);
        expect(firstHourlySummary.find('.analyze-detail-hourly-summary-meta').text()).toContain('09:00');
        expect(firstHourlySummary.find('.analyze-detail-hourly-summary-meta').text()).toContain('Vol 26,690');
        expect(firstHourlySummary.text()).toContain('09:00');
        expect(firstHourlySummary.text()).toContain('469.55');
        expect(firstHourlySummary.text()).toContain('Vol 26,690');
        expect(firstHourlySummary.text()).toContain('12:00');
        expect(firstHourlySummary.text()).toContain('472.75');
        expect(firstHourlySummary.text()).toContain('Vol 14,345');
        expect(firstHourlySummary.text()).toContain('15:00');
        expect(firstHourlySummary.text()).toContain('471.75');
        expect(firstHourlySummary.text()).toContain('Vol 15,345');
        expect(firstHourlySummary.findAll('.analyze-detail-hourly-summary-arrow.is-up')).toHaveLength(2);
        expect(firstHourlySummary.findAll('.analyze-detail-hourly-summary-arrow.is-down')).toHaveLength(1);

        const thirdIntradayDaySummary = wrapper.findAll('.analyze-detail-day-summary')[2];
        expect(wrapper.find('[aria-label="Analyze detail"]').findAll('.analyze-detail-day-summary-item.is-comparison')).toHaveLength(8);
        expect(wrapper.findAll('.analyze-detail-day-summary')[1].text()).toContain('Last day -> Latest');
        expect(wrapper.findAll('.analyze-detail-day-summary')[1].text()).toContain('Last day -> First');
        expect(wrapper.findAll('.analyze-detail-day-summary')[1].text()).toContain('+3.0000');
        expect(thirdIntradayDaySummary.text()).toContain('Last day -> Latest');
        expect(thirdIntradayDaySummary.text()).toContain('Last day -> First');
        expect(thirdIntradayDaySummary.text()).toContain('+1.0000');
        expect(thirdIntradayDaySummary.text()).toContain('466.15');
        expect(thirdIntradayDaySummary.text()).toContain('+0.21%');
        expect(thirdIntradayDaySummary.text()).not.toContain('Lowest');
        expect(thirdIntradayDaySummary.text()).not.toContain('Highest');

        const firstIntradayDayHeader = wrapper.find('.data-intraday-day-header');
        expect(firstIntradayDayHeader.attributes('aria-expanded')).toBe('false');
        await firstIntradayDayHeader.trigger('click');
        await wrapper.vm.$nextTick();

        expect(firstIntradayDayHeader.attributes('aria-expanded')).toBe('true');
        expect(wrapper.find('[aria-label="Analyze detail"]').text()).toContain('470.15000000');

        const detailStockMenu = wrapper.find('[aria-label="Analyze detail stocks"]');
        expect(detailStockMenu.exists()).toBe(true);
        expect(detailStockMenu.find('.analyze-holding-card--all').exists()).toBe(false);
        expect(detailStockMenu.text()).toContain('Apple');
        expect(detailStockMenu.text()).toContain('306.32 EUR');
        expect(detailStockMenu.text()).toContain('Microsoft');
        expect(detailStockMenu.text()).toContain('Nvidia');
        expect(detailStockMenu.findAll('.analyze-holding-card')
            .find((button) => button.text().includes('Apple')).attributes('aria-pressed')).toBe('true');

        const nvidiaButton = detailStockMenu.findAll('button')
            .find((button) => button.text().includes('Nvidia'));
        await nvidiaButton.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/analyze/detail');
        expect(window.location.search).toBe('?stock=3');
        expect(wrapper.find('[aria-label="Analyze detail"]').text()).toContain('Nvidia');
        expect(nvidiaButton.attributes('aria-pressed')).toBe('true');
        expect(wrapper.find('[aria-label="Analyze detail"]').text()).not.toContain('924.15000000');
        expect(fetchMock).toHaveBeenCalledWith('/admin/watchlist/holdings/3/intraday-candles', expect.anything());
    });

    it('selects the first stock when Analyze intraday opens with stock all', async () => {
        window.history.pushState({}, '', '/admin/menu/analyze/intraday?stock=all');
        const pagination = { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null };
        const depot = { id: 1, name: 'Main depot', account_balance: '1000.00', is_active: true };
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

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
                return Promise.resolve(jsonResponse({
                    depot,
                    holdings: [
                        {
                            id: 1,
                            symbol: 'AMES',
                            name: 'Amundi IBEX 35',
                            currency: 'EUR',
                            latest_price: '481.500000',
                            recent_prices: [
                                {
                                    id: 10,
                                    price: '481.50000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-12T15:36:00+00:00',
                                    source_name: 'EODHD real-time',
                                    price_type: 'last',
                                },
                            ],
                            intraday_candles: [],
                        },
                        {
                            id: 2,
                            symbol: 'LEER',
                            name: 'Amundi Eastern Europe',
                            currency: 'EUR',
                            latest_price: '44.215000',
                            recent_prices: [],
                            intraday_candles: [],
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

            return Promise.resolve(jsonResponse({}));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();
        await flushPromises();

        const analyzeIntraday = wrapper.find('[aria-label="Analyze intraday"]');

        expect(window.location.pathname).toBe('/admin/menu/analyze/intraday');
        expect(window.location.search).toBe('?stock=1');
        expect(analyzeIntraday.text()).not.toContain('No stock selected.');
        expect(analyzeIntraday.text()).toContain('Amundi IBEX 35');
        expect(analyzeIntraday.findAll('.analyze-holding-card')
            .find((button) => button.text().includes('Amundi IBEX 35')).attributes('aria-pressed')).toBe('true');
    });

    it('renders the Tests menu page with an unpaginated stock select', async () => {
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
            currency: index === 0 ? 'USD' : 'EUR',
            exchange: index === 0 ? 'NASDAQ' : 'XETRA',
            latest_price: String(100 + index),
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

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
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

            if (path.startsWith('/admin/tests/stocks/') && path.endsWith('/intraday')) {
                const stockId = Number(path.match(/\/admin\/tests\/stocks\/(\d+)\/intraday/)?.[1]);
                const stock = stocks.find((item) => item.id === stockId);
                const close = stockId === 12 ? '112.34000000' : '101.23000000';

                return Promise.resolve(jsonResponse({
                    stock,
                    day: {
                        title: 'Intraday 25.06.2026 - 5m',
                        trading_date: '2026-06-25',
                        interval: '5m',
                        overview: null,
                        rows: [
                            {
                                id: stockId,
                                stock_holding_id: stockId,
                                trading_date: '2026-06-25',
                                interval: '5m',
                                as_of: '2026-06-25 07:00:00',
                                timestamp: 1782363600,
                                gmtoffset: 0,
                                datetime: '2026-06-25 07:00:00',
                                open: '100.10000000',
                                high: '102.20000000',
                                low: '99.90000000',
                                close,
                                volume: 1200,
                                currency: stock?.currency ?? null,
                                source_key: 'eodhd_intraday',
                                source_name: 'EODHD intraday',
                                source_url: `https://eodhd.com/api/intraday/${stock?.symbol}.XETRA?fmt=json&interval=5m`,
                                raw_payload: {
                                    close,
                                    volume: 1200,
                                },
                                created_at: '2026-06-25 12:00:01',
                                updated_at: '2026-06-25 12:00:01',
                            },
                        ],
                    },
                    refresh: {
                        status: 'finished',
                        stored_count: 1,
                        message: '1 intraday candles loaded/updated.',
                    },
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
        await flushPromises();

        const testsPage = wrapper.find('[aria-label="Analyze tests"]');
        expect(testsPage.exists()).toBe(true);
        expect(testsPage.text()).toContain('Stocks');
        expect(testsPage.text()).not.toContain('Indices');
        expect(testsPage.text()).not.toContain('Tickers');
        expect(testsPage.find('[aria-label="Test indices"]').exists()).toBe(false);
        expect(testsPage.find('[aria-label="Test stocks"]').exists()).toBe(true);
        expect(testsPage.find('[aria-label="Ticker result"]').exists()).toBe(false);
        expect(window.location.pathname).toBe('/admin/menu/analyze/tests');
        expect(wrapper.find('.dashboard-navigation-drawer').text()).not.toContain('Tests');
        expect(wrapper.findAll('.v-tab').map((tab) => tab.text()).some((label) => label.includes('Tests'))).toBe(true);
        expect(wrapper.vm.testOptions.stocks).toHaveLength(12);
        expect(fetchMock).toHaveBeenCalledWith('/admin/tests/options', expect.any(Object));
        expect(fetchMock).toHaveBeenCalledWith('/admin/tests/stocks/1/intraday', expect.any(Object));
        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/tests/options?page=1')).toBe(false);
        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/tests/tickers')).toBe(false);
        expect(fetchMock.mock.calls.some(([path]) => String(path).includes('/websocket'))).toBe(false);
        expect(testsPage.find('[aria-label="Selected test stock"]').exists()).toBe(true);
        expect(testsPage.find('[aria-label="Selected test stock"]').text()).toContain('Stock 1');
        expect(testsPage.find('[aria-label="Selected test stock"]').text()).toContain('2026-06-25');
        expect(testsPage.find('[aria-label="Selected test stock"]').text()).toContain('101.23');
        expect(testsPage.find('[aria-label="Selected test stock"]').text()).toContain('eodhd_intraday');
        expect(testsPage.find('[aria-label="Selected test stock"]').text()).toContain('EODHD intraday');
        expect(testsPage.find('[aria-label="Selected test stock"]').text()).toContain('https://eodhd.com/api/intraday/S1.XETRA');
        expect(testsPage.find('[aria-label="Selected test stock"]').text()).toContain('"volume":1200');
        expect(testsPage.find('[aria-label="Selected test stock"]').text()).toContain('1 intraday candles loaded/updated.');

        const stockChips = testsPage.find('[aria-label="Test stocks"]').findAll('button');
        expect(stockChips).toHaveLength(12);
        expect(stockChips[11].text()).toContain('S12');
        expect(stockChips[11].text()).toContain('Stock 12');

        await stockChips[11].trigger('click');
        await flushPromises();

        expect(wrapper.vm.selectedTestStockId).toBe(12);
        expect(stockChips[11].attributes('aria-pressed')).toBe('true');
        expect(fetchMock).toHaveBeenCalledWith('/admin/tests/stocks/12/intraday', expect.any(Object));
        expect(testsPage.find('[aria-label="Selected test stock"]').text()).toContain('Stock 12');
        expect(testsPage.find('[aria-label="Selected test stock"]').text()).toContain('112.34');
    });

    it('renders a Today chart with stored EODHD intraday candle rows', async () => {
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

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
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
                                { id: 1, price: '999.25000000', currency: 'EUR', as_of: '2026-06-05T10:30:00+00:00' },
                            ],
                            intraday_candles: [
                                {
                                    id: 1,
                                    trading_date: '2026-06-05',
                                    price: '307.25000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-05T10:30:00+00:00',
                                },
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

            if (path === '/admin/watchlist/holdings/historical-prices/coverage') {
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
        expect(wrapper.find('.analyze-sparkline-meta').text()).toContain('05.06.26, 12:30');
        expect(wrapper.find('.analyze-sparkline-endpoint-label--start').text()).toBe('Start 307.25');
        expect(wrapper.find('.analyze-sparkline-endpoint-label--latest').text()).toBe('End 307.25 0.00%');
        expect(wrapper.text()).not.toContain('No EODHD intraday prices available for this session.');
    });

    it('uses real-time rows when intraday chart data is insufficient', async () => {
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

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
                return Promise.resolve(jsonResponse({
                    depot,
                    holdings: [
                        {
                            id: 1,
                            symbol: 'DAY',
                            name: 'Live Fallback Fund',
                            currency: 'EUR',
                            latest_price: '2.668000',
                            daily_prices: [],
                            intraday_prices: [],
                            recent_prices: [
                                {
                                    id: 1,
                                    price: '2.66300000',
                                    currency: 'EUR',
                                    as_of: '2026-06-12T07:05:00+00:00',
                                    source_name: 'EODHD real-time',
                                    price_type: 'last',
                                },
                                {
                                    id: 2,
                                    price: '99.00000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-12T07:07:00+00:00',
                                    source_name: 'Manual',
                                    price_type: 'last',
                                },
                                {
                                    id: 3,
                                    price: '2.66800000',
                                    currency: 'EUR',
                                    as_of: '2026-06-12T07:10:00+00:00',
                                    source_name: 'EODHD Real-Time',
                                    price_type: 'last',
                                },
                            ],
                            intraday_candles: [
                                {
                                    id: 1,
                                    trading_date: '2026-06-12',
                                    price: '2.65000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-12T07:00:00+00:00',
                                },
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

            if (path === '/admin/watchlist/holdings/historical-prices/coverage') {
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
        const todayRangeButton = analyzeOverview.findAll('.analyze-range-button')
            .find((button) => button.text() === 'today');
        const oneWeekRangeButton = analyzeOverview.findAll('.analyze-range-button')
            .find((button) => button.text() === '1 week');

        expect(analyzeOverview.find('.analyze-sparkline').exists()).toBe(true);
        expect(analyzeOverview.find('.analyze-sparkline-endpoint-label--start').text()).toBe('Start 2.6630');
        expect(analyzeOverview.find('.analyze-sparkline-endpoint-label--latest').text()).toBe('End 2.6680 +0.19%');

        await todayRangeButton.trigger('click');
        await flushPromises();

        expect(analyzeOverview.find('.analyze-sparkline').exists()).toBe(true);
        expect(analyzeOverview.findAll('.analyze-sparkline-dot')).toHaveLength(2);
        expect(analyzeOverview.find('.analyze-sparkline-meta').text()).toContain('12.06.26, 09:05');
        expect(analyzeOverview.find('.analyze-sparkline-meta').text()).toContain('12.06.26, 09:10');
        expect(analyzeOverview.find('.analyze-sparkline-endpoint-label--start').text()).toBe('Start 2.6630');
        expect(analyzeOverview.find('.analyze-sparkline-endpoint-label--latest').text()).toBe('End 2.6680 +0.19%');
        expect(analyzeOverview.text()).not.toContain('99.0000');

        await oneWeekRangeButton.trigger('click');
        await flushPromises();

        expect(analyzeOverview.find('.analyze-sparkline').exists()).toBe(true);
        expect(analyzeOverview.findAll('.analyze-sparkline-dot')).toHaveLength(0);
        expect(analyzeOverview.find('.analyze-sparkline-endpoint-label--start').text()).toBe('Start 2.6630');
        expect(analyzeOverview.find('.analyze-sparkline-endpoint-label--latest').text()).toBe('End 2.6680 +0.19%');
    });

    it('prefers live real-time rows for the Today chart when current-day live data exists', async () => {
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

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
                return Promise.resolve(jsonResponse({
                    depot,
                    holdings: [
                        {
                            id: 1,
                            symbol: 'DAY',
                            name: 'Stored Intraday Fund',
                            currency: 'EUR',
                            latest_price: '2.663000',
                            daily_prices: [],
                            intraday_prices: [
                                {
                                    id: 11,
                                    price: '3.00000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-12T07:05:00+00:00',
                                    source_name: 'EODHD intraday',
                                    price_type: 'intraday',
                                },
                                {
                                    id: 12,
                                    price: '3.10000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-12T07:10:00+00:00',
                                    source_name: 'EODHD intraday',
                                    price_type: 'intraday',
                                },
                            ],
                            recent_prices: [
                                {
                                    id: 0,
                                    price: '2.50000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-11T15:30:00+00:00',
                                    source_name: 'EODHD Real-Time',
                                    price_type: 'last',
                                },
                                {
                                    id: 1,
                                    price: '2.66300000',
                                    currency: 'EUR',
                                    as_of: '2026-06-12T07:05:00+00:00',
                                    source_name: 'EODHD real-time',
                                    price_type: 'last',
                                },
                            ],
                            intraday_candles: [
                                {
                                    id: 1,
                                    trading_date: '2026-06-12',
                                    price: '2.65000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-12T07:00:00+00:00',
                                },
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

        const analyzeOverview = wrapper.find('[aria-label="Analyze overview"]');
        const todayRangeButton = analyzeOverview.findAll('.analyze-range-button')
            .find((button) => button.text() === 'today');
        const todayMinusOneRangeButton = analyzeOverview.findAll('.analyze-range-button')
            .find((button) => button.text() === 'today-1');
        await todayRangeButton.trigger('click');
        await flushPromises();

        expect(wrapper.findAll('.analyze-sparkline-dot')).toHaveLength(1);
        expect(wrapper.find('.analyze-sparkline-meta').text()).toContain('12.06.26, 09:05');
        expect(wrapper.find('.analyze-sparkline-meta').text()).not.toContain('12.06.26, 09:10');
        expect(wrapper.find('.analyze-sparkline-endpoint-label--start').text()).toBe('Start 2.6630');
        expect(wrapper.find('.analyze-sparkline-endpoint-label--latest').text()).toBe('End 2.6630 0.00%');
        expect(wrapper.find('.analyze-sparkline-meta').text()).not.toContain('3.1000');

        await todayMinusOneRangeButton.trigger('click');
        await flushPromises();

        const todayMinusOneStartLabel = wrapper.find('.analyze-sparkline-endpoint-label--start').text();

        expect(wrapper.findAll('.analyze-sparkline-dot')).toHaveLength(2);
        expect(todayMinusOneStartLabel).toContain('Prev 2.5000');
        expect(todayMinusOneStartLabel).toContain('Start 2.6630 +6.52%');
        expect(wrapper.find('.analyze-sparkline-endpoint-label--latest').text()).toContain('Start 2.6630 +6.52%');
        expect(wrapper.find('.analyze-sparkline-meta').text()).not.toContain('3.1000');
    });

    it('groups the Today chart by Vienna calendar date for UTC timestamps', async () => {
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

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
                return Promise.resolve(jsonResponse({
                    depot,
                    holdings: [
                        {
                            id: 1,
                            symbol: 'NIGHT',
                            name: 'After Midnight Fund',
                            currency: 'EUR',
                            latest_price: '3.100000',
                            daily_prices: [],
                            intraday_prices: [],
                            recent_prices: [],
                            intraday_candles: [
                                {
                                    id: 1,
                                    trading_date: '2026-06-12',
                                    price: '2.00000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-12T21:30:00+00:00',
                                },
                                {
                                    id: 2,
                                    trading_date: '2026-06-13',
                                    price: '3.00000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-12T22:05:00+00:00',
                                },
                                {
                                    id: 3,
                                    trading_date: '2026-06-13',
                                    price: '3.10000000',
                                    currency: 'EUR',
                                    as_of: '2026-06-12T22:10:00+00:00',
                                },
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

        const sparklineMeta = wrapper.find('.analyze-sparkline-meta').text();

        expect(wrapper.findAll('.analyze-sparkline-dot')).toHaveLength(2);
        expect(sparklineMeta).toContain('13.06.26, 00:05');
        expect(sparklineMeta).toContain('13.06.26, 00:10');
        expect(sparklineMeta).not.toContain('12.06.26, 23:30');
        expect(wrapper.find('.analyze-sparkline-endpoint-label--start').text()).toBe('Start 3.0000');
        expect(wrapper.find('.analyze-sparkline-endpoint-label--latest').text()).toBe('End 3.1000 +3.33%');
    });

    it('does not fetch historical stock prices on the Analyze overview', async () => {
        window.history.pushState({}, '', '/admin/menu/analyze/overview');
        const pagination = { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null };
        const depot = { id: 1, name: 'Main depot', account_balance: '1000.00', is_active: true };
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

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
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
                    coverage: {},
                    refresh: {},
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

        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/watchlist/holdings/historical-prices/ensure')).toBe(false);
        expect(analyzeOverview.text()).not.toContain('Checking historical prices');
        expect(analyzeOverview.text()).not.toContain('historical price records loaded/updated.');
        expect(analyzeOverview.find('.analyze-history-status').exists()).toBe(false);

        wrapper.unmount();
    });

    it('does not show historical stock price result messages on the Analyze overview', async () => {
        window.history.pushState({}, '', '/admin/menu/analyze/overview');
        const pagination = { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null };
        const depot = { id: 1, name: 'Main depot', account_balance: '1000.00', is_active: true };
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

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
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
                    message: 'Historical stock price fetching finished with missing data.',
                    coverage: {},
                    refresh: {},
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
        expect(analyzeOverview.text()).not.toContain('historical price records loaded/updated.');
        expect(analyzeOverview.text()).not.toContain('2 missing');
        expect(analyzeOverview.text()).not.toContain('2/2');
        expect(analyzeOverview.find('.analyze-history-status').exists()).toBe(false);
        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/watchlist/holdings/historical-prices/ensure')).toBe(false);

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
        const nextHolidayDate = '2099-12-25';
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

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
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
                            latest_price: '101.000000',
                            start_price: '100.000000',
                            end_price: '100.000000',
                            end_price_24: '100.000000',
                            end_price_48: '100.000000',
                            latest_price_trend: 'up',
                            latest_price_change_pct: '1.00',
                            latest_price_tick_trend: 'up',
                            latest_price_status: 'fresh',
                            price_status: 'fresh',
                            latest_price_fetched_at: null,
                            latest_price_source: 'EODHD intraday',
                            latest_price_source_url: 'https://example.com/flat',
                            latest_price_as_of: '2026-06-03T12:00:00+00:00',
                            trading_times: 'Monday-Friday 09:00-17:30 Europe/Berlin',
                            venue: null,
                            price_type: 'intraday',
                            price_spread_pct: null,
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
                        per_page: 5,
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
                            holidays: [currentBerlinDate, nextHolidayDate],
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
                        status: 'finished',
                        processed: 2,
                        total: 2,
                        step: '2/2',
                        message: 'EODHD sync: 2 record(s) created, 2 record(s) updated.',
                        current: null,
                        started_at: '2026-06-02T12:15:00+00:00',
                        finished_at: '2026-06-02T12:20:00+00:00',
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
                    message: 'EODHD sync: 2 record(s) created, 2 record(s) updated.',
                    refresh: {
                        refresh_id: 'refresh-1',
                        status: 'finished',
                        processed: 2,
                        total: 2,
                        step: '2/2',
                        message: 'EODHD sync: 2 record(s) created, 2 record(s) updated.',
                        current: null,
                        started_at: '2026-06-02T12:15:00+00:00',
                        finished_at: '2026-06-02T12:20:00+00:00',
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

            if (path === '/admin/watchlist/holdings/1/intraday-candles') {
                return Promise.resolve(jsonResponse({
                    holding: { id: 1 },
                    intraday: {
                        title: 'Intraday 05.06.2026 - 5m',
                        trading_date: '2026-06-05',
                        interval: '5m',
                        rows: [
                            {
                                timestamp: 1780642800,
                                gmtoffset: 0,
                                datetime: '2026-06-05 07:00:00',
                                open: '305.55000000',
                                high: '305.55000000',
                                low: '305.55000000',
                                close: '305.55000000',
                                volume: 12,
                            },
                            {
                                timestamp: 1780643100,
                                gmtoffset: 0,
                                datetime: '2026-06-05 07:05:00',
                                open: '306.32001000',
                                high: '306.32001000',
                                low: '306.32001000',
                                close: '306.32001000',
                                volume: 18,
                            },
                            {
                                timestamp: 1780673400,
                                gmtoffset: 0,
                                datetime: '2026-06-05 15:30:00',
                                open: '304.00000000',
                                high: '304.00000000',
                                low: '304.00000000',
                                close: '304.00000000',
                                volume: null,
                            },
                        ],
                    },
                    intraday_days: [],
                }));
            }

            if (path === '/admin/watchlist/holdings/4/intraday-candles') {
                return Promise.resolve(jsonResponse({
                    holding: { id: 4 },
                    intraday: {
                        title: 'Intraday 05.06.2026 - 5m',
                        trading_date: '2026-06-05',
                        interval: '5m',
                        rows: [],
                    },
                    intraday_days: [],
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
        expect(dashboardHeaders[4]).toContain('Last day');
        expect(dashboardHeaders[4]).toContain('04.06.2026');
        expect(dashboardHeaders[4]).not.toContain('Yesterday');
        expect(dashboardHeaders[5]).toBe('Source time');
        expect(dashboardHeaders[6]).toBe('Actions');
        expect(wrapper.find('thead th.watch-list-content-cell').exists()).toBe(true);
        expect(wrapper.find('thead th.watch-list-source-time-cell').exists()).toBe(true);
        expect(wrapper.find('thead th.watch-list-actions-cell').exists()).toBe(true);
        expect(wrapper.get('.app-bar-row').text()).not.toContain('Stocks Last:');
        expect(wrapper.find('.dashboard-status-card').exists()).toBe(false);
        expect(fetchMock).toHaveBeenCalledWith('/admin/watchlist/holdings?page=1&all=1', expect.any(Object));
        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/queue/status')).toBe(false);
        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/watchlist/exchange-trading-times')).toBe(false);
        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/price-refresh-settings')).toBe(false);
        expect(wrapper.find('[aria-label="Minify dashboard menu"]').exists()).toBe(true);
        const watchListSection = wrapper.get('.watch-list-section');
        expect(watchListSection.text()).toContain('Stocks');
        expect(watchListSection.find('.watch-list-section-title-row .watch-list-live-badge').exists()).toBe(false);
        expect(watchListSection.text()).toContain('5');
        expect(wrapper.find('.v-pagination').exists()).toBe(false);
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
        expect(wrapper.text()).toContain('306.32');
        expect(wrapper.text()).not.toContain('306.32 EUR');
        expect(wrapper.text()).toContain('300.10');
        expect(wrapper.text()).toContain('299.50');
        expect(wrapper.text()).toContain('+2.28%');
        expect(wrapper.text()).toContain('DOWN');
        expect(wrapper.text()).toContain('FLAT');
        expect(wrapper.text()).toContain('100');

        const holdingRows = wrapper.findAll('tbody tr');
        const upPriceValue = holdingRows[0].findAll('td')[2].find('.latest-price-value');
        const downPriceValue = holdingRows[1].findAll('td')[2].find('.latest-price-value');
        const intradayPriceValue = holdingRows[2].findAll('td')[2].find('.latest-price-value');
        const upStartPriceTick = holdingRows[0].findAll('td')[3].find('[aria-label="Start price higher than last day price"]');
        const downStartPriceTick = holdingRows[1].findAll('td')[3].find('[aria-label="Start price lower than last day price"]');
        expect(holdingRows[0].findAll('td')[0].text()).toContain('Exchange: NASDAQ');
        expect(holdingRows[0].findAll('td')[0].text()).toContain('Pieces: 0');
        expect(holdingRows[0].findAll('td')[1].text()).not.toContain('Exchange: NASDAQ');
        expect(holdingRows[0].findAll('td')[1].text()).not.toContain('Pieces: 0');
        expect(holdingRows[0].findAll('td')[1].text()).toContain('US0378331005 · WKN: 865985');
        expect(holdingRows[0].findAll('td')[5].text()).toContain('03.06.2026, 17:35');
        expect(holdingRows[0].findAll('td')[5].text()).not.toContain('Tradegate Exchange');
        expect(holdingRows[0].findAll('td')[5].classes()).toContain('watch-list-source-time-cell');
        expect(holdingRows[0].findAll('td')[6].classes()).toContain('watch-list-actions-cell');
        expect(holdingRows[0].findAll('td')[2].classes()).not.toContain('bg-success');
        expect(holdingRows[1].findAll('td')[2].classes()).not.toContain('bg-error');
        expect(upPriceValue.classes()).toContain('bg-success');
        expect(upPriceValue.classes()).toContain('text-white');
        expect(upPriceValue.get('.watch-list-live-badge').text()).toBe('LIVE');
        expect(downPriceValue.classes()).not.toContain('bg-success');
        expect(downPriceValue.classes()).not.toContain('bg-error');
        expect(downPriceValue.text()).toBe('-');
        expect(downPriceValue.find('.watch-list-live-badge').exists()).toBe(false);
        expect(intradayPriceValue.classes()).toContain('text-success');
        expect(intradayPriceValue.classes()).not.toContain('bg-success');
        expect(intradayPriceValue.classes()).not.toContain('text-white');
        expect(intradayPriceValue.find('.watch-list-live-badge').exists()).toBe(false);
        expect(upPriceValue.text()).toContain('+2.28%');
        expect(upPriceValue.find('.latest-price-tick').exists()).toBe(false);
        expect(downPriceValue.text()).not.toContain('+5.98%');
        expect(upStartPriceTick.exists()).toBe(true);
        expect(upStartPriceTick.text()).toBe('↑');
        expect(upStartPriceTick.classes()).toContain('text-success');
        expect(downStartPriceTick.exists()).toBe(true);
        expect(downStartPriceTick.text()).toBe('↓');
        expect(downStartPriceTick.classes()).toContain('text-error');
        expect(holdingRows[2].findAll('td')[3].find('.latest-price-tick').exists()).toBe(false);
        expect(holdingRows[0].findAll('td')[4].text()).not.toContain('%');
        expect(wrapper.html()).toContain('latest-price-tick');
        expect(wrapper.text()).toContain('↑');
        const recentPriceTrendDots = holdingRows[0].findAll('.recent-price-trend-dot');
        expect(recentPriceTrendDots).toHaveLength(10);
        expect(recentPriceTrendDots[0].classes()).toContain('recent-price-trend-dot-flat');
        expect(recentPriceTrendDots[1].classes()).toContain('recent-price-trend-dot-down');
        expect(recentPriceTrendDots[2].classes()).toContain('recent-price-trend-dot-up');

        expect(wrapper.text()).not.toContain('305.55');

        await holdingRows[0].trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('305.55');
        expect(fetchMock).toHaveBeenCalledWith('/admin/watchlist/holdings/1/intraday-candles', expect.anything());
        const intradayTable = wrapper.find('.holding-intraday-table');
        expect(wrapper.find('.holding-intraday-detail-header').text()).toContain('Intraday 05.06.2026');
        expect(wrapper.find('.holding-intraday-detail-header').text()).not.toContain('- 5m');
        expect(wrapper.find('.holding-intraday-detail-header').text()).toContain('3 rows');
        expect(intradayTable.text()).toContain('05.06.2026, 09:00');
        expect(intradayTable.text()).toContain('05.06.2026, 09:05');
        expect(intradayTable.text()).toContain('05.06.2026, 17:30');
        expect(intradayTable.text()).toContain('306.32001000');
        expect(wrapper.find('.recent-price-strip').exists()).toBe(false);

        await wrapper.find('.stock-holding-row').trigger('click');
        await flushPromises();

        expect(wrapper.text()).not.toContain('305.55');

        const closedMarketHoldingRow = wrapper.findAll('.stock-holding-row')[1];
        await closedMarketHoldingRow.trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/watchlist/holdings/4/intraday-candles', expect.anything());
        expect(wrapper.text()).toContain('No EODHD intraday prices available for this session.');

        await closedMarketHoldingRow.trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('03.06.2026, 17:35');
        expect(wrapper.text()).not.toContain('Monday-Friday 08:00-22:00 Europe/Berlin');
        expect(wrapper.text()).not.toContain('Exchange trading times');
        expect(wrapper.text()).not.toContain('XETRA Stock Exchange');
        expect(wrapper.text()).not.toContain('Next trading:');
        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/watchlist/exchange-trading-times')).toBe(false);
        expect(wrapper.text()).not.toContain('07:00-15:30 UTC');
        expect(wrapper.text()).not.toContain('Tradegate Exchange');
        expect(wrapper.text()).toContain('EXXX');
        expect(wrapper.text()).toContain('DE000A0D8Q23');
        expect(wrapper.text()).toContain('A0D8Q2');
        expect(wrapper.text()).toContain('LEER');
        expect(wrapper.text()).not.toContain('43.37 EUR');
        expect(wrapper.text()).not.toContain('Trading depot');
        expect(wrapper.text()).not.toContain('250.50');
        expect(wrapper.text()).not.toContain('Admin User');
        expect(wrapper.text()).not.toContain('Stocks Last: 02.06.2026, 14:20');
        expect(wrapper.text()).not.toContain('Indices Last: 02.06.2026, 15:00');
        expect(wrapper.text()).not.toContain('fetching historical data');
        expect(wrapper.text()).not.toContain('Automatic price refresh');

        wrapper.vm.navigateSection('updates');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/dashboard');
        expect(wrapper.find('.dashboard-status-card').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Automatic price refresh');
        expect(wrapper.find('#price-refresh-schedule-form').exists()).toBe(false);
        expect(wrapper.find('#index-price-refresh-schedule-form').exists()).toBe(false);

        wrapper.vm.navigateSection('dashboard');
        await flushPromises();

        const refreshPricesButton = wrapper.findAll('button').find((button) => button.text().includes('Refresh prices'));
        expect(refreshPricesButton).toBeUndefined();
        expect(fetchMock.mock.calls.some(([path, options]) => (
            path === '/admin/watchlist/holdings/refresh-prices' && options?.method === 'POST'
        ))).toBe(false);
        expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/watchlist/holdings?page=1&all=1')).toHaveLength(1);
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
        expect(indexWatchStrip.text()).toContain('6,116.53');
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
        expect(document.body.textContent).toContain('6,116.53');
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
        expect(Number(document.body.querySelector('.index-price-chart-endpoint-label--latest').getAttribute('y'))).toBeLessThan(70);
        expect(document.body.textContent).toContain('6,116.53');
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

    it('reloads the dashboard info without starting update requests', async () => {
        window.history.pushState({}, '', '/admin/dashboard');
        const emptyPagination = {
            current_page: 1,
            last_page: 1,
            per_page: 10,
            total: 0,
            from: null,
            to: null,
        };
        const depot = {
            id: 1,
            name: 'Long term depot',
            account_balance: '12345.67',
            is_active: true,
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
                    depot,
                    app_version: '0.1.5',
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            if (path === '/admin/watchlist/holdings?page=1&all=1') {
                return Promise.resolve(jsonResponse({
                    depot,
                    holdings: [],
                    meta: emptyPagination,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({
                    depots: [depot],
                    meta: {
                        ...emptyPagination,
                        per_page: 10,
                        total: 1,
                        from: 1,
                        to: 1,
                    },
                }));
            }

            if (path === '/admin/index-watch-items') {
                return Promise.resolve(jsonResponse({
                    indexes: [],
                }));
            }

            return Promise.reject(new Error(`Unexpected request: ${path}`));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();
        await flushPromises();

        const reloadButton = wrapper.findAll('.dashboard-action-button')
            .find((button) => button.text().includes('Reload'));
        await reloadButton.trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/depots/active')).toHaveLength(2);
        expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/watchlist/holdings?page=1&all=1')).toHaveLength(2);
        expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/depots?page=1')).toHaveLength(2);
        expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/index-watch-items')).toHaveLength(2);
        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/queue/status')).toBe(false);
        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/watchlist/exchange-trading-times')).toBe(false);
        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/price-refresh-settings')).toBe(false);
        expect(fetchMock.mock.calls.some(([path]) => String(path).includes('/sync'))).toBe(false);
    });

    it('automatically reloads the dashboard info every minute without starting update requests', async () => {
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
        const depot = {
            id: 1,
            name: 'Long term depot',
            account_balance: '12345.67',
            is_active: true,
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
                    depot,
                    app_version: '0.1.5',
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            if (path === '/admin/watchlist/holdings?page=1&all=1') {
                return Promise.resolve(jsonResponse({
                    depot,
                    holdings: [],
                    meta: emptyPagination,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({
                    depots: [depot],
                    meta: {
                        ...emptyPagination,
                        per_page: 10,
                        total: 1,
                        from: 1,
                        to: 1,
                    },
                }));
            }

            if (path === '/admin/index-watch-items') {
                return Promise.resolve(jsonResponse({
                    indexes: [],
                }));
            }

            return Promise.reject(new Error(`Unexpected request: ${path}`));
        });
        vi.stubGlobal('fetch', fetchMock);

        try {
            const wrapper = mountApp();
            await flushPromises();

            expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/depots/active')).toHaveLength(1);
            expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/watchlist/holdings?page=1&all=1')).toHaveLength(1);
            expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/depots?page=1')).toHaveLength(1);
            expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/index-watch-items')).toHaveLength(1);

            await vi.advanceTimersByTimeAsync(60000);
            await flushPromises();

            expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/depots/active')).toHaveLength(2);
            expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/watchlist/holdings?page=1&all=1')).toHaveLength(2);
            expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/depots?page=1')).toHaveLength(2);
            expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/index-watch-items')).toHaveLength(2);
            expect(fetchMock.mock.calls.some(([path]) => path === '/admin/queue/status')).toBe(false);
            expect(fetchMock.mock.calls.some(([path]) => path === '/admin/price-refresh-settings')).toBe(false);
            expect(fetchMock.mock.calls.some(([path]) => String(path).includes('/sync'))).toBe(false);

            wrapper.unmount();
        } finally {
            vi.useRealTimers();
        }
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
            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
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

    it('does not load Shanghai exchange trading sessions on the dashboard', async () => {
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
                if (path.startsWith('/admin/watchlist/holdings?page=1')) {
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

            expect(fetchMock.mock.calls.some(([path]) => path === '/admin/watchlist/exchange-trading-times')).toBe(false);
            expect(wrapper.text()).not.toContain('Shanghai Stock Exchange');
            expect(wrapper.text()).not.toContain('09:30-11:30, 13:00-15:00');
            expect(wrapper.text()).not.toContain('Next trading: 05.06.2026, 13:00');
        } finally {
            vi.useRealTimers();
        }
    });

    it('does not render legacy update schedule forms on the removed updates page', async () => {
        window.history.pushState({}, '', '/admin/menu/updates');
        const fetchMock = vi.fn((path) => {
            if (path === '/admin/me') {
                return Promise.resolve(jsonResponse({ user: { id: 1, name: 'Admin', email: 'a@b.com', roles: ['admin'] } }));
            }
            if (path === '/admin/depots/active') {
                return Promise.resolve(jsonResponse({
                    depot: null,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }
            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
                return Promise.resolve(jsonResponse({
                    depot: null,
                    holdings: [],
                    meta: emptyPagination,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            return Promise.resolve(jsonResponse({}));
        });
        global.fetch = fetchMock;
        const wrapper = mountApp();
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/dashboard');
        expect(wrapper.text()).not.toContain('Updates');
        expect(wrapper.find('#price-refresh-schedule-form').exists()).toBe(false);
        expect(wrapper.find('#index-price-refresh-schedule-form').exists()).toBe(false);
    });

    it('allows scheduling and immediately queuing the intraday 5m missing backfill', async () => {
        vi.useFakeTimers();
        window.history.pushState({}, '', '/admin/menu/updates');
        const depot = { id: 1, name: 'Main depot', account_balance: '1000.00', is_active: true };
        const pagination = { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null };
        const refresh = {
            refresh_id: 'intraday-missing-test',
            status: 'queued',
            processed: 0,
            total: 5,
            step: '0/5',
            message: 'Missing intraday backfill queued.',
            current: null,
            started_at: '2026-06-12T10:00:00+00:00',
            finished_at: null,
            date_from: '2025-06-13',
            date_to: '2026-06-12',
            stored_count: 0,
            success_count: 0,
            failed_count: 0,
            error: null,
        };
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
            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
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
            if (path === '/admin/intraday-backfill-settings' && options?.method === 'PATCH') {
                return Promise.resolve(jsonResponse({
                    message: 'Intraday backfill schedule updated.',
                    intraday_backfill_settings: intradayBackfillSettings({ daily_time: '21:15' }),
                }));
            }
            if (path === '/admin/intraday-backfill/run' && options?.method === 'POST') {
                return Promise.resolve(jsonResponse({
                    message: 'Missing intraday backfill queued.',
                    intraday_backfill_settings: intradayBackfillSettings({
                        daily_time: '21:15',
                        status: 'updating',
                        status_label: 'Backfilling intraday data',
                    }),
                    intraday_backfill_refresh: refresh,
                }));
            }
            if (path === '/admin/intraday-backfill/intraday-missing-test') {
                return Promise.resolve(jsonResponse({
                    intraday_backfill_settings: intradayBackfillSettings({ daily_time: '21:15' }),
                    intraday_backfill_refresh: {
                        ...refresh,
                        status: 'finished',
                        processed: 5,
                        step: '5/5',
                        finished_at: '2026-06-12T10:02:00+00:00',
                        stored_count: 25,
                    },
                }));
            }
            if (path === '/admin/price-refresh-settings') {
                return Promise.resolve(jsonResponse({
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                    intraday_backfill_settings: intradayBackfillSettings(),
                    intraday_backfill_refresh: null,
                }));
            }

            return Promise.resolve(jsonResponse({}));
        });
        global.fetch = fetchMock;

        try {
            const wrapper = mountApp();
            await flushPromises();

            expect(wrapper.find('#intraday-backfill-schedule-form').exists()).toBe(false);
        } finally {
            vi.useRealTimers();
        }
    });

    it('does not show the removed stock price schedule form on the legacy updates path', async () => {
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
            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
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

        expect(wrapper.find('#price-refresh-schedule-form').exists()).toBe(false);
    });

    it('does not submit the removed stock price schedule form on the legacy updates path', async () => {
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
            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
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

        expect(wrapper.find('#price-refresh-schedule-form').exists()).toBe(false);
        expect(fetchMock).not.toHaveBeenCalledWith('/admin/price-refresh-settings', expect.objectContaining({
            method: 'PATCH',
        }));
    });

    it('shows Data as a main dashboard item and Admin group with Users, Roles, and Cloudways submenu chips for super_admin', async () => {
        window.history.pushState({}, '', '/admin/menu/users');
        localStorage.removeItem('data_intraday_refresh_info_dismissed');
        let mockedIndexDataUpdateSettings = indexDataUpdateSettings();
        const fetchMock = vi.fn((path, options = {}) => {
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
                return Promise.resolve(jsonResponse({
                    depot: null,
                    index_data_update_settings: mockedIndexDataUpdateSettings,
                }));
            }

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
                return Promise.resolve(jsonResponse({
                    depot: null,
                    holdings: [],
                    meta: { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null },
                    price_refresh_settings: priceRefreshSettings(),
                    index_data_update_settings: mockedIndexDataUpdateSettings,
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
                            updated_at: '2026-06-07T13:45:00+00:00',
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
                    index_data_update_settings: indexDataUpdateSettings({
                        latest_table_update_at: '2026-06-17T02:01:00+02:00',
                    }),
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

            if (path === '/admin/watchlist/holdings/historical-prices/coverage') {
                return Promise.resolve(jsonResponse({
                    message: 'Historical stock prices are available.',
                    coverage: {
                        date_from: '2025-06-05',
                        date_to: '2026-06-05',
                        required_to: '2026-06-04',
                        is_complete: true,
                        total_count: 1,
                        available_count: 1,
                        missing_count: 0,
                        end_of_day_outdated_stocks: [
                            {
                                id: 10,
                                label: 'EOD - End Of Day Lagging',
                                db_last_date: '2026-06-04',
                            },
                        ],
                        holdings: [
                            {
                                id: 7,
                                symbol: 'AMES',
                                name: 'Amundi IBEX 35 UCITS ETF',
                                isin: 'LU1681043599',
                                exchange: 'XETRA',
                                currency: 'EUR',
                                instrument_type: 'ETF',
                                country: 'Germany',
                                latest_realtime_date: '2026-06-05T12:00:00+00:00',
                                latest_realtime_day_first_record_at: '2026-06-05T08:00:00+00:00',
                                latest_realtime_day_last_record_at: '2026-06-05T12:00:00+00:00',
                                latest_realtime_day_record_count: 3,
                                latest_realtime_table_row_count: 5,
                                previous_realtime_date: '2026-06-04T12:00:00+00:00',
                                previous_realtime_day_first_record_at: '2026-06-04T08:00:00+00:00',
                                previous_realtime_day_last_record_at: '2026-06-04T12:00:00+00:00',
                                previous_realtime_day_record_count: 2,
                                end_of_day_first_date: '2026-06-03',
                                end_of_day_expected_last_date: '2026-06-12',
                                end_of_day_last_date: '2026-06-04',
                                end_of_day_row_count: 2,
                                end_of_day_table_row_count: 2,
                                is_available: true,
                                stored_count: 2,
                                stored_required_count: 2,
                                expected_required_count: 2,
                                first_date: '2025-06-05',
                                latest_date: '2026-06-04',
                            },
                        ],
                    },
                    refresh: null,
                    index_data_update_settings: mockedIndexDataUpdateSettings,
                }));
            }

            if (path === '/admin/watchlist/holdings/7/intraday-candles/coverage') {
                return Promise.resolve(jsonResponse({
                    coverage: {
                        first_date: '2026-06-03',
                        expected_last_date: '2026-06-12',
                        last_date: '2026-06-05',
                        oldest_last_date: '2026-06-04',
                        outdated_stocks: [
                            {
                                id: 9,
                                label: 'LAG - Lagging Holding',
                                db_last_date: '2026-06-04',
                            },
                        ],
                        row_count: 2,
                        trading_day_count: 2,
                        table_row_count: 2,
                    },
                }));
            }

            if (path === '/admin/watchlist/holdings/7/intraday-candles/latest-days') {
                return Promise.resolve(jsonResponse({
                    holding: {
                        id: 7,
                        symbol: 'AMES',
                        name: 'Amundi IBEX 35 UCITS ETF',
                        currency: 'EUR',
                    },
                    dates: ['2026-06-03', '2026-06-04', '2026-06-05'],
                    entries: [
                        {
                            id: 803,
                            trading_date: '2026-06-05',
                            price: '10.20000000',
                            currency: 'EUR',
                            as_of: '2026-06-05T08:00:00+00:00',
                        },
                        {
                            id: 802,
                            trading_date: '2026-06-04',
                            price: '10.25000000',
                            currency: 'EUR',
                            as_of: '2026-06-04T08:00:00+00:00',
                        },
                        {
                            id: 801,
                            trading_date: '2026-06-03',
                            price: '10.10000000',
                            currency: 'EUR',
                            as_of: '2026-06-03T08:00:00+00:00',
                        },
                    ],
                }));
            }

            if (path === '/admin/watchlist/holdings/7/realtime-prices/latest') {
                return Promise.resolve(jsonResponse({
                    holding: {
                        id: 7,
                        symbol: 'AMES',
                        name: 'Amundi IBEX 35 UCITS ETF',
                        currency: 'EUR',
                    },
                    date: '2026-06-05',
                    entries: [
                        {
                            id: 701,
                            price: '10.15000000',
                            currency: 'EUR',
                            as_of: '2026-06-05T08:00:00+00:00',
                            source_name: 'EODHD real-time',
                            price_type: 'last',
                            venue: 'Tradegate',
                        },
                        {
                            id: 702,
                            price: '10.25000000',
                            currency: 'EUR',
                            as_of: '2026-06-05T12:00:00+00:00',
                            source_name: 'EODHD real-time',
                            price_type: 'last',
                            venue: 'XETRA',
                        },
                        {
                            id: 703,
                            price: '10.20000000',
                            currency: 'EUR',
                            as_of: '2026-06-05T13:00:00+00:00',
                            source_name: 'EODHD real-time',
                            price_type: 'last',
                            venue: 'XETRA',
                        },
                    ],
                }));
            }

            if (path === '/admin/data/realtime/latest?stock=7') {
                return Promise.resolve(jsonResponse({
                    date: '2026-06-05',
                    row_count: 3,
                    entries: [
                        {
                            id: 701,
                            stock_holding_id: 7,
                            holding_symbol: 'AMES',
                            holding_name: 'Amundi IBEX 35 UCITS ETF',
                            holding_exchange: 'XETRA',
                            instrument_key: 'isin:LU1681043599',
                            quote_hash: 'hash-701',
                            source_key: 'eodhd_realtime',
                            source_name: 'EODHD real-time',
                            source_quality: 'market_data_vendor',
                            venue: 'Tradegate',
                            mic: 'TGAT',
                            isin: 'LU1681043599',
                            wkn: 'A2H58J',
                            symbol: 'AMES',
                            currency: 'EUR',
                            bid: '10.10000000',
                            ask: '10.20000000',
                            last: '10.15000000',
                            close: null,
                            price: '10.15000000',
                            price_type: 'last',
                            spread_pct: '0.980000',
                            as_of: '2026-06-05T08:00:00+00:00',
                            fetched_at: '2026-06-05T08:00:02+00:00',
                            freshness_status: 'fresh',
                            validation_status: 'valid',
                        },
                        {
                            id: 702,
                            stock_holding_id: 7,
                            holding_symbol: 'AMES',
                            holding_name: 'Amundi IBEX 35 UCITS ETF',
                            holding_exchange: 'XETRA',
                            instrument_key: 'isin:LU1681043599',
                            quote_hash: 'hash-702',
                            source_key: 'eodhd_realtime',
                            source_name: 'EODHD real-time',
                            source_quality: 'market_data_vendor',
                            venue: 'XETRA',
                            mic: 'XETR',
                            isin: 'LU1681043599',
                            wkn: 'A2H58J',
                            symbol: 'AMES',
                            currency: 'EUR',
                            bid: '10.20000000',
                            ask: '10.30000000',
                            last: '10.25000000',
                            close: null,
                            price: '10.25000000',
                            price_type: 'last',
                            spread_pct: '0.970000',
                            as_of: '2026-06-05T12:00:00+00:00',
                            fetched_at: '2026-06-05T12:00:03+00:00',
                            freshness_status: 'fresh',
                            validation_status: 'valid',
                        },
                        {
                            id: 703,
                            stock_holding_id: 7,
                            holding_symbol: 'AMES',
                            holding_name: 'Amundi IBEX 35 UCITS ETF',
                            holding_exchange: 'XETRA',
                            instrument_key: 'isin:LU1681043599',
                            quote_hash: 'hash-703',
                            source_key: 'eodhd_realtime',
                            source_name: 'EODHD real-time',
                            source_quality: 'market_data_vendor',
                            venue: 'XETRA',
                            mic: 'XETR',
                            isin: 'LU1681043599',
                            wkn: 'A2H58J',
                            symbol: 'AMES',
                            currency: 'EUR',
                            bid: '10.15000000',
                            ask: '10.25000000',
                            last: '10.20000000',
                            close: null,
                            price: '10.20000000',
                            price_type: 'last',
                            spread_pct: '0.980000',
                            as_of: '2026-06-05T13:00:00+00:00',
                            fetched_at: '2026-06-05T13:00:04+00:00',
                            freshness_status: 'fresh',
                            validation_status: 'valid',
                        },
                    ],
                }));
            }

            if (path === '/admin/watchlist/holdings/7/end-of-day-prices/latest-days') {
                return Promise.resolve(jsonResponse({
                    holding: {
                        id: 7,
                        symbol: 'AMES',
                        name: 'Amundi IBEX 35 UCITS ETF',
                        currency: 'EUR',
                    },
                    entries: [
                        {
                            id: 901,
                            price: '10.30000000',
                            currency: 'EUR',
                            as_of: '2026-06-05T21:59:59+00:00',
                        },
                        {
                            id: 902,
                            price: '10.15000000',
                            currency: 'EUR',
                            as_of: '2026-06-04T21:59:59+00:00',
                        },
                        {
                            id: 903,
                            price: '10.20000000',
                            currency: 'EUR',
                            as_of: '2026-06-03T21:59:59+00:00',
                        },
                    ],
                }));
            }

            if (path === '/admin/price-refresh-settings' && options?.method === 'PATCH') {
                return Promise.resolve(jsonResponse({
                    message: 'Price refresh schedule updated.',
                    price_refresh_settings: priceRefreshSettings({
                        trading_interval_minutes: 15,
                        trading_start_time: '09:15',
                        trading_end_time: '17:30',
                    }),
                    refresh: null,
                }));
            }

            if (path === '/admin/price-refresh-settings') {
                return Promise.resolve(jsonResponse({
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                    intraday_backfill_settings: intradayBackfillSettings(),
                    end_of_day_data_update_settings: endOfDayDataUpdateSettings(),
                    index_data_update_settings: mockedIndexDataUpdateSettings,
                    refresh: null,
                    intraday_backfill_refresh: null,
                }));
            }

            if (path === '/admin/intraday-backfill-settings' && options?.method === 'PATCH') {
                return Promise.resolve(jsonResponse({
                    message: 'Intraday backfill schedule updated.',
                    intraday_backfill_settings: intradayBackfillSettings({
                        daily_time: '20:45',
                        interval_minutes: 30,
                        next_refresh_at: '2026-06-12T20:45:00+02:00',
                    }),
                }));
            }

            if (path === '/admin/end-of-day-data-update-settings' && options?.method === 'PATCH') {
                return Promise.resolve(jsonResponse({
                    message: 'End-of-day data update schedule updated.',
                    end_of_day_data_update_settings: endOfDayDataUpdateSettings({
                        daily_time: '19:20',
                        interval_minutes: 45,
                        next_refresh_at: '2026-06-12T19:20:00+02:00',
                    }),
                }));
            }

            if (path === '/admin/index-data-update-settings' && options?.method === 'PATCH') {
                mockedIndexDataUpdateSettings = indexDataUpdateSettings({
                    weekday: 3,
                    weekday_label: 'Wednesday',
                    next_refresh_at: '2026-06-17T02:00:00+02:00',
                });

                return Promise.resolve(jsonResponse({
                    message: 'Indices data update schedule updated.',
                    index_data_update_settings: mockedIndexDataUpdateSettings,
                }));
            }

            if (path === '/admin/data/realtime/sync' && options?.method === 'POST') {
                return Promise.resolve(jsonResponse({
                    message: 'EODHD sync: 1 record(s) created, 1 record(s) updated.',
                    requested_count: 1,
                    stored_count: 1,
                    unchanged_count: 0,
                    updated_count: 1,
                    failed_count: 0,
                    errors: [],
                    price_refresh_settings: priceRefreshSettings({
                        trading_interval_minutes: 15,
                        trading_start_time: '09:15',
                        trading_end_time: '17:30',
                        last_refreshed_at: '2026-06-02T13:00:00+00:00',
                        next_refresh_at: '2026-06-02T13:15:00+00:00',
                        current_interval_minutes: 15,
                    }),
                }));
            }

            if (path === '/admin/data/historical/sync' && options?.method === 'POST') {
                return Promise.resolve(jsonResponse({
                    message: 'EODHD historical sync: 1 record(s) created.',
                    requested_count: 1,
                    stored_count: 1,
                    skipped_count: 0,
                    failed_count: 0,
                    target_date: '2026-06-12',
                    errors: [],
                    intraday_backfill_settings: intradayBackfillSettings({
                        daily_time: '20:45',
                        interval_minutes: 30,
                        last_dispatched_at: '2026-06-12T18:31:00+02:00',
                        last_dispatched_on: '2026-06-12',
                        next_refresh_at: '2026-06-15T20:45:00+02:00',
                    }),
                }));
            }

            if (path === '/admin/data/end-of-day/sync' && options?.method === 'POST') {
                return Promise.resolve(jsonResponse({
                    message: 'EODHD end-of-day sync: 1 record(s) created.',
                    requested_count: 1,
                    stored_count: 1,
                    skipped_count: 0,
                    failed_count: 0,
                    date_from: '2025-06-12',
                    date_to: '2026-06-12',
                    errors: [],
                    end_of_day_data_update_settings: endOfDayDataUpdateSettings({
                        daily_time: '19:20',
                        interval_minutes: 45,
                        last_dispatched_at: '2026-06-12T19:21:00+02:00',
                        last_dispatched_on: '2026-06-12',
                        next_refresh_at: '2026-06-15T19:20:00+02:00',
                    }),
                }));
            }

            if (path === '/admin/data/indices/sync' && options?.method === 'POST') {
                mockedIndexDataUpdateSettings = indexDataUpdateSettings({
                    weekday: 3,
                    weekday_label: 'Wednesday',
                    last_dispatched_at: '2026-06-17T02:01:00+02:00',
                    last_dispatched_on: '2026-06-17',
                    next_refresh_at: '2026-06-24T02:00:00+02:00',
                    table_row_count: 4,
                    latest_table_update_at: '2026-06-17T02:01:00+02:00',
                });

                return Promise.resolve(jsonResponse({
                    message: 'EODHD indices sync: 1 index(es) refreshed.',
                    requested_count: 1,
                    refreshed_count: 1,
                    failed_count: 0,
                    index_data_update_settings: mockedIndexDataUpdateSettings,
                }));
            }

            if (path === '/admin/cloudways/sync' && options?.method === 'POST') {
                return Promise.resolve(ndjsonResponse([
                    {
                        type: 'table',
                        table: {
                            name: 'users',
                            rows: 1,
                            columns: 9,
                            status: 'imported',
                            message: 'Imported users: 1 row(s), 9 column(s).',
                        },
                    },
                    {
                        type: 'table',
                        table: {
                            name: 'depots',
                            rows: 14,
                            columns: 6,
                            status: 'imported',
                            message: 'Imported depots: 14 row(s), 6 column(s).',
                        },
                    },
                    {
                        type: 'finished',
                        message: 'Synced 2 table(s) and 15 row(s) from Cloudways.',
                        sync: {
                            synced_tables: 2,
                            total_tables: 3,
                            rows: 15,
                            synced_at: '2026-06-13T12:00:00+00:00',
                            skipped_tables: ['remote_only_items'],
                            skipped_table_details: [
                                {
                                    name: 'remote_only_items',
                                    status: 'skipped',
                                    reason: 'missing_local_table',
                                    message: 'Skipped remote_only_items: no matching local table.',
                                    missing_required_columns: [],
                                },
                            ],
                            tables: [
                                {
                                    name: 'users',
                                    rows: 1,
                                    columns: 9,
                                    status: 'imported',
                                    message: 'Imported users: 1 row(s), 9 column(s).',
                                },
                                {
                                    name: 'depots',
                                    rows: 14,
                                    columns: 6,
                                    status: 'imported',
                                    message: 'Imported depots: 14 row(s), 6 column(s).',
                                },
                            ],
                        },
                    },
                ]));
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
        expect(wrapper.text()).not.toContain('Updates');
        expect(wrapper.text()).toContain('Cloudways');
        expect(wrapper.find('.dashboard-navigation-drawer').text()).toContain('Data');

        const tabs = wrapper.findAll('.v-tab');
        const tabLabels = tabs.map((t) => t.text());
        expect(tabLabels.some((l) => l.includes('Users'))).toBe(true);
        expect(tabLabels.some((l) => l.includes('Roles'))).toBe(true);
        expect(tabLabels.some((l) => l.includes('Data'))).toBe(false);
        expect(tabLabels.some((l) => l.includes('Updates'))).toBe(false);
        expect(tabLabels.some((l) => l.includes('Cloudways'))).toBe(true);

        const rolesTab = wrapper.findAll('.v-tab').find((t) => t.text().includes('Roles'));
        await rolesTab.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/roles');
        expect(wrapper.text()).toContain('admin');

        const cloudwaysTab = wrapper.findAll('.v-tab').find((t) => t.text().includes('Cloudways'));
        await cloudwaysTab.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/cloudways');
        expect(wrapper.text()).toContain('Replace local table rows with matching Cloudways table rows.');

        const syncButton = wrapper.findAll('button').find((button) => button.text().includes('Sync'));
        await syncButton.trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/cloudways/sync', expect.objectContaining({
            method: 'POST',
            headers: expect.objectContaining({
                Accept: 'application/x-ndjson',
            }),
        }));
        expect(wrapper.text()).toContain('Synced 2 table(s) and 15 row(s) from Cloudways.');
        expect(wrapper.text()).toContain('2 table(s)');
        expect(wrapper.text()).toContain('15 row(s)');
        expect(wrapper.text()).toContain('users');
        expect(wrapper.text()).toContain('depots');
        expect(wrapper.text()).toContain('Imported users: 1 row(s), 9 column(s).');
        expect(wrapper.text()).toContain('Imported depots: 14 row(s), 6 column(s).');
        expect(wrapper.text()).toContain('Skipped remote_only_items: no matching local table.');

        const dataMenuItem = wrapper.find('.dashboard-navigation-drawer')
            .findAll('.v-list-item')
            .find((item) => item.text().includes('Data'));
        await dataMenuItem.trigger('click');
        await flushPromises();

        const liveDataTab = wrapper.findAll('.v-tab').find((tab) => tab.text().includes('Live Data'));
        expect(liveDataTab).toBeTruthy();
        await liveDataTab.trigger('click');
        await flushPromises();

        const liveDataSection = wrapper.find('[aria-label="Live data"]');
        expect(window.location.pathname).toBe('/admin/menu/data/live-data');
        expect(liveDataSection.exists()).toBe(true);
        expect(liveDataSection.find('[aria-label="Live Data stocks"]').text()).toContain('AMES');
        expect(liveDataSection.text()).toContain('Amundi IBEX 35 UCITS ETF');
        expect(liveDataSection.text()).toContain('Symbol: AMES');
        expect(liveDataSection.text()).toContain('Exchange: XETRA');
        expect(liveDataSection.text()).toContain('Currency: EUR');
        expect(liveDataSection.text()).toContain('Latest entries');
        expect(liveDataSection.text()).toContain('Selected stock_realtime_prices rows from last date: 05.06.2026');
        expect(liveDataSection.text()).toContain('3 row(s)');
        expect(liveDataSection.text()).toContain('10.150 EUR');
        const liveDataLatestEntriesTable = liveDataSection.find('.test-live-data-latest-entries-table');
        expect(liveDataLatestEntriesTable.findAll('thead th').map((header) => header.text())).toEqual([
            'Time',
            'Price',
        ]);
        expect(liveDataLatestEntriesTable.text()).toContain('10.250 EUR');
        expect(liveDataLatestEntriesTable.text()).toContain('10.200 EUR');
        expect(liveDataLatestEntriesTable.text()).not.toContain('Amundi IBEX 35 UCITS ETF');
        expect(liveDataLatestEntriesTable.text()).not.toContain('Leerink');
        expect(liveDataLatestEntriesTable.text()).not.toContain('20.750 EUR');
        expect(liveDataLatestEntriesTable.text()).not.toContain('Tradegate');
        expect(liveDataLatestEntriesTable.text()).not.toContain('EODHD real-time');
        expect(liveDataLatestEntriesTable.text()).not.toContain('fresh / valid');
        expect(liveDataLatestEntriesTable.text()).not.toContain('last');
        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/data/realtime/latest?stock=7')).toBe(true);
        expect(liveDataLatestEntriesTable.findAll('.test-live-data-latest-price-arrow--up')).toHaveLength(1);
        expect(liveDataLatestEntriesTable.findAll('.test-live-data-latest-price-arrow--down')).toHaveLength(1);

        const historicalDataTab = wrapper.findAll('.v-tab').find((tab) => tab.text().includes('Historical Data'));
        expect(historicalDataTab).toBeTruthy();
        await historicalDataTab.trigger('click');
        await flushPromises();

        const historicalDataSection = wrapper.find('[aria-label="Historical data"]');
        expect(window.location.pathname).toBe('/admin/menu/data/historical-data');
        expect(historicalDataSection.exists()).toBe(true);
        expect(historicalDataSection.find('[aria-label="Historical Data stocks"]').text()).toContain('AMES');
        expect(historicalDataSection.text()).toContain('Amundi IBEX 35 UCITS ETF');
        expect(historicalDataSection.text()).toContain('Symbol: AMES');
        expect(historicalDataSection.text()).toContain('Last seven days');
        const historicalLatestEntriesTable = historicalDataSection.find('.test-historical-data-latest-entries-table');
        const historicalLatestEntriesText = historicalLatestEntriesTable.text();
        expect(historicalLatestEntriesText.indexOf('05.06.2026, 10:00')).toBeLessThan(
            historicalLatestEntriesText.indexOf('04.06.2026, 10:00'),
        );
        expect(historicalLatestEntriesText.indexOf('04.06.2026, 10:00')).toBeLessThan(
            historicalLatestEntriesText.indexOf('03.06.2026, 10:00'),
        );
        expect(historicalLatestEntriesTable.text()).toContain('10.100 EUR');
        expect(historicalLatestEntriesTable.text()).toContain('10.250 EUR');
        expect(historicalLatestEntriesTable.text()).toContain('10.200 EUR');
        expect(historicalLatestEntriesTable.findAll('.test-live-data-latest-price-arrow--up')).toHaveLength(1);
        expect(historicalLatestEntriesTable.findAll('.test-live-data-latest-price-arrow--down')).toHaveLength(1);

        const eodDataTab = wrapper.findAll('.v-tab').find((tab) => tab.text().includes('EOD-Data'));
        expect(eodDataTab).toBeTruthy();
        await eodDataTab.trigger('click');
        await flushPromises();

        const eodDataSection = wrapper.find('[aria-label="EOD data"]');
        expect(window.location.pathname).toBe('/admin/menu/data/eod-data');
        expect(eodDataSection.exists()).toBe(true);
        expect(eodDataSection.find('[aria-label="EOD-Data stocks"]').text()).toContain('AMES');
        expect(eodDataSection.text()).toContain('Amundi IBEX 35 UCITS ETF');
        expect(eodDataSection.text()).toContain('Symbol: AMES');
        expect(eodDataSection.text()).toContain('Last 30 days');
        const eodLatestEntriesTable = eodDataSection.find('.test-eod-data-latest-entries-table');
        const eodLatestEntriesText = eodLatestEntriesTable.text();
        expect(eodLatestEntriesText.indexOf('05.06.2026')).toBeLessThan(
            eodLatestEntriesText.indexOf('04.06.2026'),
        );
        expect(eodLatestEntriesText.indexOf('04.06.2026')).toBeLessThan(
            eodLatestEntriesText.indexOf('03.06.2026'),
        );
        expect(eodLatestEntriesTable.text()).toContain('10.300 EUR');
        expect(eodLatestEntriesTable.text()).toContain('10.150 EUR');
        expect(eodLatestEntriesTable.text()).toContain('10.200 EUR');
        expect(eodLatestEntriesTable.findAll('.test-live-data-latest-price-arrow--up')).toHaveLength(1);
        expect(eodLatestEntriesTable.findAll('.test-live-data-latest-price-arrow--down')).toHaveLength(1);

        const overviewTab = wrapper.findAll('.v-tab').find((tab) => tab.text().includes('Overview'));
        await overviewTab.trigger('click');
        await flushPromises();

        const dataOverview = wrapper.find('[aria-label="Data overview"]');
        wrapper.vm.liveDataStatusNow = Date.parse('2026-06-02T12:30:00Z');
        await wrapper.vm.$nextTick();

        expect(window.location.pathname).toBe('/admin/menu/data/overview');
        expect(dataOverview.text()).toContain('Live-Daten:');
        expect(dataOverview.text()).toContain('Latest update');
        expect(dataOverview.text()).toContain('Next update');
        expect(dataOverview.text()).toContain('02.06.2026, 14:20');
        expect(dataOverview.text()).toContain('02.06.2026, 14:40');
        expect(dataOverview.text()).toContain('Mo-Fr start 5 min before trading until 10 min after trading · 20 min');
        const liveDataCard = dataOverview.find('.test-selected-stock-card--live');
        expect(liveDataCard.exists()).toBe(true);
        expect(liveDataCard.findAll('.test-intraday-summary-card--update').map((card) => card.text())).toEqual([
            'Latest update02.06.2026, 14:20',
            'Next update02.06.2026, 14:40',
        ]);
        const liveDataWaitingStatusDot = dataOverview.find('[aria-label="Live data update status: waiting"]');
        expect(liveDataWaitingStatusDot.exists()).toBe(true);
        expect(liveDataWaitingStatusDot.classes()).toContain('test-live-data-update-status-dot--waiting');

        wrapper.vm.liveDataStatusNow = Date.parse('2026-06-02T12:41:00Z');
        await wrapper.vm.$nextTick();

        const liveDataDueStatusDot = dataOverview.find('[aria-label="Live data update status: due"]');
        expect(liveDataDueStatusDot.exists()).toBe(true);
        expect(liveDataDueStatusDot.classes()).toContain('test-live-data-update-status-dot--due');
        wrapper.vm.liveDataStatusNow = Date.parse('2026-06-02T12:30:00Z');
        await wrapper.vm.$nextTick();

        wrapper.vm.priceRefreshSettings.status = 'updating';
        await wrapper.vm.$nextTick();

        const liveDataSettingsUpdatingStatusDot = dataOverview.find('[aria-label="Live data update status: updating"]');
        expect(liveDataSettingsUpdatingStatusDot.exists()).toBe(true);
        expect(liveDataSettingsUpdatingStatusDot.classes()).toContain('test-live-data-update-status-dot--updating');
        wrapper.vm.priceRefreshSettings.status = 'waiting';
        await wrapper.vm.$nextTick();

        wrapper.vm.priceRefresh = {
            refresh_id: 'automatic-live-data-refresh',
            status: 'queued',
            processed: 0,
            total: 1,
            step: '0/1',
            message: '1 stock realtime price sync queued.',
            current: null,
            started_at: '2026-06-02T12:42:00+00:00',
            finished_at: null,
            error: null,
        };
        await wrapper.vm.$nextTick();

        const automaticLiveDataUpdatingStatusDot = dataOverview.find('[aria-label="Live data update status: updating"]');
        expect(automaticLiveDataUpdatingStatusDot.exists()).toBe(true);
        expect(automaticLiveDataUpdatingStatusDot.classes()).toContain('test-live-data-update-status-dot--updating');
        const coverageRequestsBeforeAutomaticRefresh = fetchMock.mock.calls
            .filter(([path]) => path === '/admin/watchlist/holdings/historical-prices/coverage').length;
        const intradayCoverageRequestsBeforeAutomaticRefresh = fetchMock.mock.calls
            .filter(([path]) => path === '/admin/watchlist/holdings/7/intraday-candles/coverage').length;
        await wrapper.vm.pollPriceRefreshSettings();
        await flushPromises();

        const automaticLiveDataWaitingStatusDot = dataOverview.find('[aria-label="Live data update status: waiting"]');
        expect(automaticLiveDataWaitingStatusDot.exists()).toBe(true);
        expect(automaticLiveDataWaitingStatusDot.classes()).toContain('test-live-data-update-status-dot--waiting');
        expect(fetchMock.mock.calls
            .filter(([path]) => path === '/admin/watchlist/holdings/historical-prices/coverage')).toHaveLength(
            coverageRequestsBeforeAutomaticRefresh + 1,
        );
        expect(fetchMock.mock.calls
            .filter(([path]) => path === '/admin/watchlist/holdings/7/intraday-candles/coverage')).toHaveLength(
            intradayCoverageRequestsBeforeAutomaticRefresh + 1,
        );

        const editLiveDataUpdatesButton = dataOverview.find('[aria-label="Edit live data updates"]');
        expect(editLiveDataUpdatesButton.exists()).toBe(true);
        await editLiveDataUpdatesButton.trigger('click');
        await flushPromises();

        expect(document.body.textContent).toContain('Edit live data updates');
        expect(document.body.textContent).toContain('Mo-Fr');
        expect(document.body.textContent).toContain('Start-time (Vienna)');
        expect(document.body.textContent).toContain('End-time (Vienna)');
        expect(document.body.textContent).toContain('Intervall');

        const liveDataUpdateTimeInputs = Array.from(document.body.querySelectorAll('input[type="time"]'));
        liveDataUpdateTimeInputs[0].value = '09:15';
        liveDataUpdateTimeInputs[0].dispatchEvent(new Event('input', { bubbles: true }));
        liveDataUpdateTimeInputs[1].value = '17:30';
        liveDataUpdateTimeInputs[1].dispatchEvent(new Event('input', { bubbles: true }));

        const liveDataUpdateIntervalInput = document.body.querySelector('input[type="number"]');
        liveDataUpdateIntervalInput.value = '15';
        liveDataUpdateIntervalInput.dispatchEvent(new Event('input', { bubbles: true }));

        const saveLiveDataUpdatesDialogButton = Array.from(document.body.querySelectorAll('button'))
            .find((button) => button.textContent.includes('Save'));
        saveLiveDataUpdatesDialogButton.click();
        await flushPromises();

        const priceRefreshSettingsCall = fetchMock.mock.calls.find(([path, options]) => (
            path === '/admin/price-refresh-settings' && options?.method === 'PATCH'
        ));
        expect(JSON.parse(priceRefreshSettingsCall[1].body)).toEqual({
            trading_interval_minutes: 15,
            trading_starts_before_minutes: 5,
            trading_ends_after_minutes: 10,
            trading_start_time: '09:15',
            trading_end_time: '17:30',
            closed_refresh_enabled: true,
            closed_interval_minutes: 60,
        });
        await wrapper.vm.$nextTick();

        expect(dataOverview.text()).toContain('Mo-Fr 09:15-17:30 · 15 min');
        const eodhdSyncButton = dataOverview.find('[aria-label="Sync EODHD realtime data"]');
        expect(eodhdSyncButton.exists()).toBe(true);
        await eodhdSyncButton.trigger('click');

        const liveDataManualSyncStatusDot = dataOverview.find('[aria-label="Live data update status: updating"]');
        expect(liveDataManualSyncStatusDot.exists()).toBe(true);
        expect(liveDataManualSyncStatusDot.classes()).toContain('test-live-data-update-status-dot--updating');

        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/data/realtime/sync', expect.objectContaining({
            method: 'POST',
        }));
        const liveDataFinishedSyncStatusDot = dataOverview.find('[aria-label="Live data update status: waiting"]');
        expect(liveDataFinishedSyncStatusDot.exists()).toBe(true);
        expect(liveDataFinishedSyncStatusDot.classes()).toContain('test-live-data-update-status-dot--waiting');
        expect(dataOverview.text()).toContain('EODHD sync: 1 record(s) created, 1 record(s) updated.');
        const eodhdSyncAlertCloseButton = dataOverview.find('[aria-label="Close EODHD sync message"]');
        expect(eodhdSyncAlertCloseButton.exists()).toBe(true);
        await eodhdSyncAlertCloseButton.trigger('click');
        await flushPromises();

        expect(dataOverview.text()).not.toContain('EODHD sync: 1 record(s) created, 1 record(s) updated.');
        expect(liveDataCard.findAll('.test-intraday-summary-card--update').map((card) => card.text())).toEqual([
            'Latest update02.06.2026, 15:00',
            'Next update02.06.2026, 15:15',
        ]);
        expect(liveDataCard.findAll('.test-intraday-summary-next-row').map((row) => row.text())).toEqual([
            'Last date05.06.2026',
            'Day before04.06.2026',
        ]);
        expect(fetchMock).toHaveBeenCalledWith('/admin/watchlist/holdings/historical-prices/coverage', expect.any(Object));
        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/watchlist/holdings/historical-prices/ensure')).toBe(false);
        expect(fetchMock).toHaveBeenCalledWith('/admin/watchlist/holdings/7/intraday-candles/coverage', expect.any(Object));
        const historicalDataCard = dataOverview.find('.test-selected-stock-card--historical');
        expect(historicalDataCard.exists()).toBe(true);
        expect(historicalDataCard.text()).toContain('Historical Data');
        expect(historicalDataCard.text()).toContain('Mo-Fr 18:30 · 15 min');
        expect(historicalDataCard.text()).toContain('Expected last date12.06.2026');
        expect(historicalDataCard.text()).toContain('Last date04.06.2026');
        expect(historicalDataCard.text()).toContain('Responsible stocks');
        expect(historicalDataCard.text()).toContain('LAG - Lagging Holding');
        expect(historicalDataCard.text()).toContain('DB last date 04.06.2026 · Expected 12.06.2026');
        expect(historicalDataCard.findAll('.test-intraday-summary-card--update').map((card) => card.text())).toEqual([
            'Latest updateNever',
            'Next update12.06.2026, 18:30',
        ]);
        const endOfDayDataCard = dataOverview.find('.test-selected-stock-card--end-of-day');
        expect(endOfDayDataCard.exists()).toBe(true);
        expect(endOfDayDataCard.text()).toContain('End-Of-Day-Data');
        expect(endOfDayDataCard.text()).toContain('Mo-Fr 17:45 · 20 min');
        expect(endOfDayDataCard.text()).toContain('Affected table: stock_prices');
        expect(endOfDayDataCard.text()).toContain('Expected last date12.06.2026');
        expect(endOfDayDataCard.text()).toContain('Last date04.06.2026');
        expect(endOfDayDataCard.text()).toContain('Responsible stocks');
        expect(endOfDayDataCard.text()).toContain('EOD - End Of Day Lagging');
        expect(endOfDayDataCard.text()).toContain('DB last date 04.06.2026 · Expected 12.06.2026');
        expect(endOfDayDataCard.findAll('.test-intraday-summary-card--update').map((card) => card.text())).toEqual([
            'Latest updateNever',
            'Next update12.06.2026, 17:45',
        ]);
        expect(endOfDayDataCard.findAll('.test-intraday-summary-next-row').map((row) => row.text())).toEqual([
            'First date03.06.2026',
        ]);
        expect(endOfDayDataCard.classes()).toContain('test-selected-stock-card--end-of-day');
        const endOfDayDataWaitingStatusDot = endOfDayDataCard.find('[aria-label="End-of-day data update status: waiting"]');
        expect(endOfDayDataWaitingStatusDot.exists()).toBe(true);
        expect(endOfDayDataWaitingStatusDot.classes()).toContain('test-live-data-update-status-dot--waiting');
        const editEndOfDayDataUpdatesButton = endOfDayDataCard.find('[aria-label="Edit end-of-day data updates"]');
        expect(editEndOfDayDataUpdatesButton.exists()).toBe(true);
        const eodhdEndOfDayDataButton = endOfDayDataCard.find('[aria-label="Sync EODHD end-of-day data"]');
        expect(eodhdEndOfDayDataButton.exists()).toBe(true);
        const indicesDataCard = dataOverview.find('.test-selected-stock-card--indices');
        expect(indicesDataCard.exists()).toBe(true);
        expect(indicesDataCard.text()).toContain('Indices-Date');
        expect(indicesDataCard.text()).toContain('Affected table: index_watch_item_prices');
        expect(indicesDataCard.text()).toContain('Total rows: 3');
        expect(indicesDataCard.text()).toContain('Monday 02:00 · Vienna');
        expect(indicesDataCard.findAll('.test-intraday-summary-card--update').map((card) => card.text())).toEqual([
            'Latest updateNever',
            'Next update15.06.2026, 02:00',
        ]);
        const indicesDataWaitingStatusDot = indicesDataCard.find('[aria-label="Indices data update status: waiting"]');
        expect(indicesDataWaitingStatusDot.exists()).toBe(true);
        expect(indicesDataWaitingStatusDot.classes()).toContain('test-live-data-update-status-dot--waiting');
        const editIndicesDataUpdatesButton = indicesDataCard.find('[aria-label="Edit indices data updates"]');
        expect(editIndicesDataUpdatesButton.exists()).toBe(true);
        const eodhdIndicesDataButton = indicesDataCard.find('[aria-label="Sync EODHD indices data"]');
        expect(eodhdIndicesDataButton.exists()).toBe(true);
        const historicalDataWaitingStatusDot = historicalDataCard.find('[aria-label="Historical data update status: waiting"]');
        expect(historicalDataWaitingStatusDot.exists()).toBe(true);
        expect(historicalDataWaitingStatusDot.classes()).toContain('test-live-data-update-status-dot--waiting');

        wrapper.vm.liveDataStatusNow = Date.parse('2026-06-12T16:31:00Z');
        await wrapper.vm.$nextTick();

        const historicalDataDueStatusDot = historicalDataCard.find('[aria-label="Historical data update status: due"]');
        expect(historicalDataDueStatusDot.exists()).toBe(true);
        expect(historicalDataDueStatusDot.classes()).toContain('test-live-data-update-status-dot--due');
        const endOfDayDataDueStatusDot = endOfDayDataCard.find('[aria-label="End-of-day data update status: due"]');
        expect(endOfDayDataDueStatusDot.exists()).toBe(true);
        expect(endOfDayDataDueStatusDot.classes()).toContain('test-live-data-update-status-dot--due');
        wrapper.vm.liveDataStatusNow = Date.parse('2026-06-02T12:30:00Z');
        await wrapper.vm.$nextTick();

        wrapper.vm.intradayBackfillSettings.status = 'updating';
        await wrapper.vm.$nextTick();

        const historicalDataSettingsUpdatingStatusDot = historicalDataCard.find('[aria-label="Historical data update status: updating"]');
        expect(historicalDataSettingsUpdatingStatusDot.exists()).toBe(true);
        expect(historicalDataSettingsUpdatingStatusDot.classes()).toContain('test-live-data-update-status-dot--updating');
        const endOfDayDataSettingsWaitingStatusDot = endOfDayDataCard.find('[aria-label="End-of-day data update status: waiting"]');
        expect(endOfDayDataSettingsWaitingStatusDot.exists()).toBe(true);
        expect(endOfDayDataSettingsWaitingStatusDot.classes()).toContain('test-live-data-update-status-dot--waiting');
        wrapper.vm.intradayBackfillSettings.status = 'waiting';
        await wrapper.vm.$nextTick();

        const syncHistoricalDataButton = historicalDataCard.find('[aria-label="Sync EODHD historical data"]');
        expect(syncHistoricalDataButton.exists()).toBe(true);
        expect(syncHistoricalDataButton.attributes('disabled')).toBeUndefined();
        const editHistoricalDataUpdatesButton = historicalDataCard.find('[aria-label="Edit historical data updates"]');
        expect(editHistoricalDataUpdatesButton.exists()).toBe(true);
        await editHistoricalDataUpdatesButton.trigger('click');
        await flushPromises();

        expect(document.body.textContent).toContain('Edit historical data updates');
        expect(document.body.textContent).toContain('Mo-Fr');
        expect(document.body.textContent).toContain('Start-time');
        expect(document.body.textContent).toContain('Intervall');
        expect(document.body.textContent).toContain('For later: start at the start-time to get new data');
        expect(document.body.textContent).toContain('retry every intervall minutes');
        expect(Array.from(document.body.querySelectorAll('button'))
            .some((button) => button.textContent.includes('Save'))).toBe(true);

        const historicalDataUpdateDialog = document.body.querySelector('.test-historical-data-update-dialog');
        expect(historicalDataUpdateDialog).not.toBeNull();

        const historicalDataUpdateTimeInput = historicalDataUpdateDialog.querySelector('input[type="time"]');
        historicalDataUpdateTimeInput.value = '20:45';
        historicalDataUpdateTimeInput.dispatchEvent(new Event('input', { bubbles: true }));
        const historicalDataUpdateIntervalInput = historicalDataUpdateDialog.querySelector('input[type="number"]');
        historicalDataUpdateIntervalInput.value = '30';
        historicalDataUpdateIntervalInput.dispatchEvent(new Event('input', { bubbles: true }));
        const saveHistoricalDataUpdatesDialogButton = Array.from(historicalDataUpdateDialog.querySelectorAll('button'))
            .find((button) => button.textContent.includes('Save'));
        saveHistoricalDataUpdatesDialogButton.click();
        await flushPromises();

        const historicalDataSettingsCall = fetchMock.mock.calls.find(([path, options]) => (
            path === '/admin/intraday-backfill-settings' && options?.method === 'PATCH'
        ));
        expect(JSON.parse(historicalDataSettingsCall[1].body)).toEqual({
            daily_time: '20:45',
            interval_minutes: 30,
        });
        expect(historicalDataCard.findAll('.test-intraday-summary-card--update').map((card) => card.text())).toEqual([
            'Latest updateNever',
            'Next update12.06.2026, 20:45',
        ]);
        expect(endOfDayDataCard.findAll('.test-intraday-summary-card--update').map((card) => card.text())).toEqual([
            'Latest updateNever',
            'Next update12.06.2026, 17:45',
        ]);

        await editEndOfDayDataUpdatesButton.trigger('click');
        await flushPromises();

        expect(document.body.textContent).toContain('Edit end-of-day data updates');
        const endOfDayDataUpdateDialog = document.body.querySelector('.test-end-of-day-data-update-dialog');
        expect(endOfDayDataUpdateDialog).not.toBeNull();

        const endOfDayDataUpdateTimeInput = endOfDayDataUpdateDialog.querySelector('input[type="time"]');
        expect(endOfDayDataUpdateTimeInput.value).toBe('17:45');
        endOfDayDataUpdateTimeInput.value = '19:20';
        endOfDayDataUpdateTimeInput.dispatchEvent(new Event('input', { bubbles: true }));
        const endOfDayDataUpdateIntervalInput = endOfDayDataUpdateDialog.querySelector('input[type="number"]');
        expect(endOfDayDataUpdateIntervalInput.value).toBe('20');
        endOfDayDataUpdateIntervalInput.value = '45';
        endOfDayDataUpdateIntervalInput.dispatchEvent(new Event('input', { bubbles: true }));
        const saveEndOfDayDataUpdatesDialogButton = Array.from(endOfDayDataUpdateDialog.querySelectorAll('button'))
            .find((button) => button.textContent.includes('Save'));
        saveEndOfDayDataUpdatesDialogButton.click();
        await flushPromises();

        const endOfDayDataSettingsCall = fetchMock.mock.calls.find(([path, options]) => (
            path === '/admin/end-of-day-data-update-settings' && options?.method === 'PATCH'
        ));
        expect(JSON.parse(endOfDayDataSettingsCall[1].body)).toEqual({
            daily_time: '19:20',
            interval_minutes: 45,
        });
        expect(endOfDayDataCard.findAll('.test-intraday-summary-card--update').map((card) => card.text())).toEqual([
            'Latest updateNever',
            'Next update12.06.2026, 19:20',
        ]);
        await editIndicesDataUpdatesButton.trigger('click');
        await flushPromises();

        expect(document.body.textContent).toContain('Edit indices data updates');
        expect(document.body.textContent).toContain('Once per week at 02:00 Europe/Vienna');
        const indexDataUpdateDialog = document.body.querySelector('.test-index-data-update-dialog');
        expect(indexDataUpdateDialog).not.toBeNull();

        wrapper.vm.indexDataUpdateScheduleForm.weekday = 3;
        await wrapper.vm.$nextTick();
        const saveIndexDataUpdatesDialogButton = Array.from(indexDataUpdateDialog.querySelectorAll('button'))
            .find((button) => button.textContent.includes('Save'));
        saveIndexDataUpdatesDialogButton.click();
        await flushPromises();

        const indexDataSettingsCall = fetchMock.mock.calls.find(([path, options]) => (
            path === '/admin/index-data-update-settings' && options?.method === 'PATCH'
        ));
        expect(JSON.parse(indexDataSettingsCall[1].body)).toEqual({
            weekday: 3,
        });
        expect(indicesDataCard.text()).toContain('Wednesday 02:00 · Vienna');
        expect(indicesDataCard.findAll('.test-intraday-summary-card--update').map((card) => card.text())).toEqual([
            'Latest updateNever',
            'Next update17.06.2026, 02:00',
        ]);
        await eodhdIndicesDataButton.trigger('click');

        const indicesDataManualSyncStatusDot = indicesDataCard.find('[aria-label="Indices data update status: updating"]');
        expect(indicesDataManualSyncStatusDot.exists()).toBe(true);
        expect(indicesDataManualSyncStatusDot.classes()).toContain('test-live-data-update-status-dot--updating');

        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/data/indices/sync', expect.objectContaining({
            method: 'POST',
        }));
        expect(dataOverview.text()).toContain('EODHD indices sync: 1 index(es) refreshed.');
        expect(indicesDataCard.findAll('.test-intraday-summary-card--update').map((card) => card.text())).toEqual([
            'Latest update17.06.2026, 02:01',
            'Next update24.06.2026, 02:00',
        ]);
        expect(indicesDataCard.text()).toContain('Total rows: 4');
        const indicesDataSyncAlertCloseButton = dataOverview.find('[aria-label="Close indices data sync message"]');
        expect(indicesDataSyncAlertCloseButton.exists()).toBe(true);
        await indicesDataSyncAlertCloseButton.trigger('click');
        await flushPromises();

        expect(dataOverview.text()).not.toContain('EODHD indices sync: 1 index(es) refreshed.');
        await eodhdEndOfDayDataButton.trigger('click');

        const endOfDayDataManualSyncStatusDot = endOfDayDataCard.find('[aria-label="End-of-day data update status: updating"]');
        expect(endOfDayDataManualSyncStatusDot.exists()).toBe(true);
        expect(endOfDayDataManualSyncStatusDot.classes()).toContain('test-live-data-update-status-dot--updating');

        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/data/end-of-day/sync', expect.objectContaining({
            method: 'POST',
        }));
        expect(dataOverview.text()).toContain('EODHD end-of-day sync: 1 record(s) created.');
        expect(endOfDayDataCard.findAll('.test-intraday-summary-card--update').map((card) => card.text())).toEqual([
            'Latest update12.06.2026, 19:21',
            'Next update15.06.2026, 19:20',
        ]);
        const endOfDayDataSyncAlertCloseButton = dataOverview.find('[aria-label="Close end-of-day data sync message"]');
        expect(endOfDayDataSyncAlertCloseButton.exists()).toBe(true);
        await endOfDayDataSyncAlertCloseButton.trigger('click');
        await flushPromises();

        expect(dataOverview.text()).not.toContain('EODHD end-of-day sync: 1 record(s) created.');
        await syncHistoricalDataButton.trigger('click');

        const historicalDataManualSyncStatusDot = historicalDataCard.find('[aria-label="Historical data update status: updating"]');
        expect(historicalDataManualSyncStatusDot.exists()).toBe(true);
        expect(historicalDataManualSyncStatusDot.classes()).toContain('test-live-data-update-status-dot--updating');

        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/data/historical/sync', expect.objectContaining({
            method: 'POST',
        }));
        expect(dataOverview.text()).toContain('EODHD historical sync: 1 record(s) created.');
        expect(historicalDataCard.findAll('.test-intraday-summary-card--update').map((card) => card.text())).toEqual([
            'Latest update12.06.2026, 18:31',
            'Next update15.06.2026, 20:45',
        ]);
        expect(endOfDayDataCard.findAll('.test-intraday-summary-card--update').map((card) => card.text())).toEqual([
            'Latest update12.06.2026, 19:21',
            'Next update15.06.2026, 19:20',
        ]);
        const historicalDataFinishedSyncStatusDot = historicalDataCard.find('[aria-label="Historical data update status: waiting"]');
        expect(historicalDataFinishedSyncStatusDot.exists()).toBe(true);
        expect(historicalDataFinishedSyncStatusDot.classes()).toContain('test-live-data-update-status-dot--waiting');
        const historicalSyncAlertCloseButton = dataOverview.find('[aria-label="Close historical data sync message"]');
        expect(historicalSyncAlertCloseButton.exists()).toBe(true);
        await historicalSyncAlertCloseButton.trigger('click');
        await flushPromises();

        expect(dataOverview.text()).not.toContain('EODHD historical sync: 1 record(s) created.');

        const exchangesTab = wrapper.findAll('.v-tab').find((t) => t.text().includes('Exchanges'));
        await exchangesTab.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/data/exchanges');
        expect(wrapper.find('[aria-label="Data exchanges"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Exchanges');
        expect(wrapper.text()).toContain('Last updated:');
        expect(wrapper.text()).toContain('17.06.2026');
        expect(wrapper.text()).toContain('02:01');
        expect(wrapper.text()).toContain('Buenos Aires Exchange · BA');
        expect(wrapper.text()).toContain('Reload Exchanges');
        expect(wrapper.text()).toContain('Exchange reload finished with missing details.');
        expect(fetchMock).toHaveBeenCalledWith('/admin/data/exchanges', expect.any(Object));

        const reloadExchangesButton = wrapper.findAll('button').find((button) => button.text().includes('Reload Exchanges'));
        await reloadExchangesButton.trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/data/exchanges')).toHaveLength(2);
        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/data/exchanges/reload')).toBe(false);

        wrapper.vm.dismissDataExchangeRefresh();
        await wrapper.vm.$nextTick();

        expect(sessionStorage.getItem('exchange_refresh_dismissed_id')).toBe('exchanges-test');
        expect(wrapper.text()).not.toContain('Exchange reload finished with missing details.');
        localStorage.removeItem('data_intraday_refresh_info_dismissed');
    });

    it('refreshes Historical Data repair info after repairing from the data repair page', async () => {
        window.history.pushState({}, '', '/admin/menu/data/repair');
        const repairSummaries = [
            {
                end_of_day: {
                    minimum_date: '2025-06-26',
                    actual_date: '2025-06-26',
                    total_stocks_count: 1,
                    covered_stocks_count: 1,
                    missing_stocks_count: 0,
                    missing_stocks: [],
                },
                historical_data: {
                    minimum_date: '2025-06-26',
                    actual_minimum_date: null,
                    last_trading_day: '2026-06-25',
                    actual_last_trading_day: null,
                    total_stocks_count: 1,
                    covered_stocks_count: 0,
                    missing_stocks_count: 1,
                    missing_stocks: [
                        {
                            id: 7,
                            label: 'AMES - Amundi IBEX 35',
                            db_minimum_date: '2025-06-16',
                            db_last_trading_day: '2026-06-24',
                            missing_ranges: [{ from: '2026-06-25', to: '2026-06-25' }],
                        },
                    ],
                },
            },
            {
                end_of_day: {
                    minimum_date: '2025-06-26',
                    actual_date: '2025-06-26',
                    total_stocks_count: 1,
                    covered_stocks_count: 1,
                    missing_stocks_count: 0,
                    missing_stocks: [],
                },
                historical_data: {
                    minimum_date: '2025-06-26',
                    actual_minimum_date: '2025-06-26',
                    last_trading_day: '2026-06-25',
                    actual_last_trading_day: '2026-06-25',
                    total_stocks_count: 1,
                    covered_stocks_count: 1,
                    missing_stocks_count: 0,
                    missing_stocks: [],
                },
            },
        ];
        let repairSummaryRequestCount = 0;
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
                    intraday_backfill_settings: intradayBackfillSettings(),
                }));
            }

            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({
                    depots: [],
                    meta: { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null },
                }));
            }

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
                return Promise.resolve(jsonResponse({
                    depot: null,
                    holdings: [],
                    meta: { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null },
                    price_refresh_settings: priceRefreshSettings(),
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

            if (path === '/admin/data/repair') {
                const summary = repairSummaries[Math.min(repairSummaryRequestCount, repairSummaries.length - 1)];
                repairSummaryRequestCount += 1;

                return Promise.resolve(jsonResponse(summary));
            }

            if (path === '/admin/data/repair/historical-data/7' && options?.method === 'POST') {
                return Promise.resolve(jsonResponse({
                    stock: { id: 7, label: 'AMES - Amundi IBEX 35' },
                    stored_candles_count: 42,
                }));
            }

            return Promise.reject(new Error(`Unexpected request: ${path}`));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/data/repair');
        expect(wrapper.text()).toContain('Historical Data');
        expect(wrapper.text()).toMatch(/Missing 1y\+\s*1/);
        expect(wrapper.text()).toMatch(/Covered\s*0/);
        expect(wrapper.text()).toMatch(/DB minimum date\s*-/);
        expect(wrapper.text()).toContain('Missing data');
        expect(wrapper.text()).toContain('AMES - Amundi IBEX 35');
        expect(wrapper.text()).toContain('Required 2025-06-26 - 2026-06-25');
        expect(wrapper.text()).toContain('DB 2025-06-16 - 2026-06-24');
        expect(wrapper.text()).toContain('Missing 2026-06-25 - 2026-06-25');

        const repairButtons = wrapper.findAll('button').filter((button) => button.text().includes('Repair'));
        expect(repairButtons.length).toBeGreaterThanOrEqual(2);
        await repairButtons.at(-1).trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/data/repair')).toHaveLength(2);
        expect(fetchMock).toHaveBeenCalledWith('/admin/data/repair/historical-data/7', expect.objectContaining({
            method: 'POST',
        }));
        expect(window.location.pathname).toBe('/admin/menu/data/repair');
        expect(wrapper.text()).toMatch(/Missing 1y\+\s*0/);
        expect(wrapper.text()).toMatch(/Covered\s*1/);
        expect(wrapper.text()).toMatch(/DB minimum date\s*2025-06-26/);
        expect(wrapper.text()).toMatch(/DB Last trading day\s*2026-06-25/);
    });

    it('does not show the watch-list PDF export on the dashboard', async () => {
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

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
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
        expect(exportButton).toBeUndefined();
        expect(openMock).not.toHaveBeenCalled();
    });

    it('books depot cash transaction from the depot page', async () => {
        window.history.pushState({}, '', '/admin/menu/depot');
        const depot = {
            id: 1,
            name: 'Main depot',
            account_balance: '1000.00',
            is_active: true,
        };
        let cashTransactionBooked = false;
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
                return Promise.resolve(jsonResponse(cashTransactionBooked
                    ? {
                        depot_holdings: [],
                        depot_valuations: {
                            latest: {
                                stock_balance: '0.00',
                                cash_balance: '1250.00',
                                account_balance: '1250.00',
                                year_start_balance: '1250.00',
                                current_balance: '1250.00',
                                balance_change_amount: '0.00',
                                balance_change_percent: '0.00',
                                taxable_stock_gain_amount: '0.00',
                                month_start_balance: '1250.00',
                                month_change_amount: '0.00',
                                month_change_percent: '0.00',
                                one_week_start_balance: '1250.00',
                                one_week_change_amount: '0.00',
                                one_week_change_percent: '0.00',
                            },
                            flatex: {
                                stock_balance: '0.00',
                                cash_balance: '1250.00',
                                account_balance: '1250.00',
                                year_start_balance: '1250.00',
                                current_balance: '1250.00',
                                balance_change_amount: '0.00',
                                balance_change_percent: '0.00',
                                taxable_stock_gain_amount: '0.00',
                                month_start_balance: '1250.00',
                                month_change_amount: '0.00',
                                month_change_percent: '0.00',
                                one_week_start_balance: '1250.00',
                                one_week_change_amount: '0.00',
                                one_week_change_percent: '0.00',
                            },
                        },
                        depot_performance_series: [
                            {
                                date: '2026-01-01',
                                stock_balance: '0.00',
                                cash_balance: '1250.00',
                                account_balance: '1250.00',
                            },
                        ],
                        transactions: [
                            {
                                id: 1,
                                type: 'deposit',
                                stock_holding_id: null,
                                stock_label: null,
                                stock_isin: null,
                                pieces: null,
                                total_amount: '250.00',
                                unit_price: null,
                                cash_delta: '250.00',
                                balance_after: '1250.00',
                                booked_at: '2026-06-05T00:00:00+00:00',
                                note: null,
                            },
                        ],
                    }
                    : { transactions: [] }));
            }

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
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
                cashTransactionBooked = true;

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

        expect(document.body.textContent).toContain('Related stock / ISIN');

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
                stock_holding_id: null,
                total_amount: 250,
                booked_at: '2026-06-05',
                note: null,
            }),
        }));
        expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/depot-transactions')).toHaveLength(2);
        expect(wrapper.text()).toContain('1,250.00 EUR');
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

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
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
                stock_isin: 'US0378331005',
                pieces: '2.00000000',
                total_amount: '350.00',
                unit_price: '175.00000000',
                cash_delta: '-350.00',
                balance_after: '650.00',
                booked_at: '2026-06-04T10:00:00+00:00',
                note: 'Broker buy confirmation',
            },
            {
                id: 1,
                type: 'deposit',
                stock_holding_id: null,
                stock_label: null,
                stock_isin: null,
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
                isin: 'US0378331005',
                currency: 'USD',
                latest_price: '191.500000',
                previous_day_price: '189.00000000',
                previous_day_price_date: '2026-06-03',
                previous_day_change_percent: '1.32',
                flatex_price: '180.000000',
                year_start_price: '175.00000000',
                latest_price_fetched_at: '2026-06-04T10:00:00+00:00',
                latest_price_status: 'fresh',
                position_pieces: '2.00000000',
            },
        ];
        const depotValuations = {
            latest: {
                stock_balance: '383.00',
                cash_balance: '650.00',
                account_balance: '1033.00',
                year_start_balance: '1000.00',
                current_balance: '1033.00',
                balance_change_amount: '33.00',
                balance_change_percent: '3.30',
                taxable_stock_gain_amount: '33.00',
                balance_change_with_broker_bonus_amount: '33.00',
                balance_change_with_broker_bonus_percent: '3.30',
                opening_balance: '0.00',
                total_deposits: '1000.00',
                total_withdrawals: '0.00',
                dividend_amount: '0.00',
                interest_amount: '0.00',
                fee_amount: '0.00',
                tax_amount: '0.00',
                broker_bonus_amount: '0.00',
                month_start_balance: '1000.00',
                month_change_amount: '33.00',
                month_change_percent: '3.30',
                one_week_start_balance: '990.00',
                one_week_change_amount: '43.00',
                one_week_change_percent: '4.34',
            },
            flatex: {
                stock_balance: '360.00',
                cash_balance: '650.00',
                account_balance: '1010.00',
                year_start_balance: '1000.00',
                current_balance: '1010.00',
                balance_change_amount: '10.00',
                balance_change_percent: '1.00',
                taxable_stock_gain_amount: '10.00',
                balance_change_with_broker_bonus_amount: '10.00',
                balance_change_with_broker_bonus_percent: '1.00',
                opening_balance: '0.00',
                total_deposits: '1000.00',
                total_withdrawals: '0.00',
                dividend_amount: '0.00',
                interest_amount: '0.00',
                fee_amount: '0.00',
                tax_amount: '0.00',
                broker_bonus_amount: '0.00',
                month_start_balance: '1000.00',
                month_change_amount: '10.00',
                month_change_percent: '1.00',
                one_week_start_balance: '995.00',
                one_week_change_amount: '15.00',
                one_week_change_percent: '1.51',
            },
        };
        const depotPerformanceSeries = [
            {
                date: '2026-01-01',
                stock_balance: '0.00',
                cash_balance: '1000.00',
                account_balance: '1000.00',
            },
            {
                date: '2026-06-04',
                stock_balance: '350.00',
                cash_balance: '650.00',
                account_balance: '1000.00',
            },
            {
                date: '2026-06-14',
                stock_balance: '383.00',
                cash_balance: '650.00',
                account_balance: '1033.00',
            },
        ];
        let currentDepotHoldings = depotHoldings;
        let currentDepotValuations = depotValuations;
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
                return Promise.resolve(jsonResponse({
                    depot_holdings: currentDepotHoldings,
                    depot_valuations: currentDepotValuations,
                    depot_performance_series: depotPerformanceSeries,
                    transactions,
                }));
            }

            if (path === '/admin/depot-transactions/2/date' && options.method === 'PATCH') {
                const updatedTransaction = {
                    ...transactions[0],
                    booked_at: '2026-06-03T00:00:00+00:00',
                };

                return Promise.resolve(jsonResponse({
                    message: 'Transaction date updated.',
                    depot_holdings: depotHoldings,
                    depot_valuations: depotValuations,
                    depot_performance_series: depotPerformanceSeries,
                    transaction: updatedTransaction,
                    transactions: [updatedTransaction, transactions[1]],
                }));
            }

            if (path === '/admin/ui-preferences' && options.method === 'PATCH') {
                return Promise.resolve(jsonResponse({
                    message: 'UI preferences updated.',
                    ui_preferences: JSON.parse(options.body),
                }));
            }

            if (path === '/admin/watchlist/holdings/1/flatex-price' && options.method === 'PATCH') {
                currentDepotHoldings = [
                    {
                        ...depotHoldings[0],
                        flatex_price: '180.250000',
                    },
                ];
                currentDepotValuations = {
                    ...depotValuations,
                    flatex: {
                        stock_balance: '360.50',
                        cash_balance: '650.00',
                        account_balance: '1010.50',
                        year_start_balance: '1000.00',
                        current_balance: '1010.50',
                        balance_change_amount: '10.50',
                        balance_change_percent: '1.05',
                        taxable_stock_gain_amount: '10.50',
                        balance_change_with_broker_bonus_amount: '10.50',
                        balance_change_with_broker_bonus_percent: '1.05',
                        opening_balance: '0.00',
                        total_deposits: '1000.00',
                        total_withdrawals: '0.00',
                        dividend_amount: '0.00',
                        interest_amount: '0.00',
                        fee_amount: '0.00',
                        tax_amount: '0.00',
                        broker_bonus_amount: '0.00',
                        month_start_balance: '1000.00',
                        month_change_amount: '10.50',
                        month_change_percent: '1.05',
                        one_week_start_balance: '995.00',
                        one_week_change_amount: '15.50',
                        one_week_change_percent: '1.56',
                    },
                };

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

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
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
        expect(wrapper.text()).not.toContain('Kest - 27,5%');
        expect(wrapper.text()).toContain('Corrected balance (-27,5%)');
        expect(wrapper.text()).toContain('1,023.92 EUR');
        expect(wrapper.text()).toContain('Corrected +/-');
        expect(wrapper.text()).toContain('+2.39% · +23.92 EUR');
        expect(wrapper.text()).toContain(`Balance ${sessionHeaderDate(7).slice(0, 6)}`);
        expect(wrapper.text()).toContain('990.00 EUR');
        expect(wrapper.text()).toContain('1 week');
        expect(wrapper.text()).toContain('+4.34% · +43.00 EUR');
        expect(wrapper.text()).toContain(`Balance 01.${sessionHeaderDate(0).slice(3, 6)}`);
        expect(wrapper.text()).toContain('Month');
        expect(wrapper.text()).toContain('+3.30% · +33.00 EUR');
        expect(wrapper.findAll('.depot-balance-card')).toHaveLength(4);
        expect(wrapper.find('.depot-balance-card tbody td:nth-child(2)').classes()).toContain('text-right');
        expect(wrapper.find('.depot-cashflow-card').exists()).toBe(false);
        expect(wrapper.text()).toContain('Depot performance');
        expect(wrapper.text()).toContain('01.01 to now');
        expect(wrapper.text()).toContain('01.01.2026 1,000.00 EUR');
        expect(wrapper.text()).toContain('14.06.2026 1,033.00 EUR');
        expect(wrapper.text()).toContain('+3.30% · +33.00 EUR');
        expect(wrapper.text()).toContain('1.1.2026');
        expect(wrapper.text()).toContain('1.2.2026');
        expect(wrapper.text()).toContain('1.3.2026');
        expect(wrapper.text()).toContain('1.12.2026');
        expect(wrapper.text()).toContain('31.12.2026');
        expect(wrapper.text()).toContain('High 1,033.00 EUR');
        expect(wrapper.text()).toContain('Low 1,000.00 EUR');
        expect(wrapper.find('.depot-performance-card').exists()).toBe(true);
        expect(wrapper.find('.depot-performance-chart').exists()).toBe(true);
        expect(wrapper.find('.depot-performance-chart').attributes('viewBox')).toBe('0 0 1800 520');
        expect(wrapper.find('.depot-performance-chart-line').attributes('points')).toContain(',');
        expect(wrapper.find('.depot-performance-chart-extremum-point--high').exists()).toBe(true);
        expect(wrapper.find('.depot-performance-chart-extremum-point--low').exists()).toBe(true);
        expect(wrapper.findAll('.depot-performance-card .index-price-chart-y-label')).toHaveLength(5);
        expect(wrapper.findAll('.depot-performance-card .index-price-chart-x-label')).toHaveLength(13);
        expect(wrapper.text()).toContain('Symbol');
        expect(wrapper.text()).toContain('Name');
        expect(wrapper.text()).toContain('Amount');
        expect(wrapper.text()).toContain('Value');
        expect(wrapper.text()).toContain('Latest price');
        expect(wrapper.text()).toContain('Prev Day');
        expect(wrapper.text()).toContain('+/- %');
        expect(wrapper.text()).toContain('Flatex price');
        expect(wrapper.text()).toContain('1.1/Buy');
        expect(wrapper.text()).toContain('Change');
        expect(wrapper.text()).toContain('+/- EUR');
        expect(wrapper.text()).toContain('Actions');
        expect(wrapper.text()).toContain('US0378331005');
        const rightAlignedDepotHeaders = wrapper
            .findAll('th.text-right')
            .map((header) => header.text());
        expect(rightAlignedDepotHeaders).toContain('Latest price');
        expect(rightAlignedDepotHeaders).toContain('Prev Day');
        expect(rightAlignedDepotHeaders).toContain('+/- %');
        expect(rightAlignedDepotHeaders).toContain('Value');
        expect(rightAlignedDepotHeaders).toContain('Flatex price');
        expect(rightAlignedDepotHeaders).toContain('1.1/Buy');
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
        expect(wrapper.text()).toContain('189.00 USD');
        expect(wrapper.text()).toContain('+1.32%');
        expect(wrapper.text()).toContain('180.00 USD');
        expect(wrapper.text()).toContain('175.00 USD');
        expect(wrapper.text()).toContain('↑');
        expect(wrapper.text()).toContain('+9.43%');
        expect(wrapper.text()).toContain('+33.00');
        const mobileDepotStockCards = wrapper.findAll('.mobile-depot-stock-card');
        expect(mobileDepotStockCards).toHaveLength(1);
        expect(mobileDepotStockCards[0].find('.mobile-depot-stock-name').text()).toContain('Apple Inc.');
        expect(mobileDepotStockCards[0].find('.mobile-depot-stock-isin').text()).toBe('US0378331005');
        expect(mobileDepotStockCards[0].text()).toContain('2');
        expect(mobileDepotStockCards[0].text()).toContain('191.50 USD');
        expect(mobileDepotStockCards[0].text()).toContain('Prev Day 189.00 USD');
        expect(mobileDepotStockCards[0].text()).toContain('+1.32%');
        expect(mobileDepotStockCards[0].text()).toContain('383.00 USD');
        expect(mobileDepotStockCards[0].text()).toContain('↑ +9.43%');
        expect(mobileDepotStockCards[0].text()).toContain('+33.00');
        expect(mobileDepotStockCards[0].text()).not.toContain('AAPL');
        expect(mobileDepotStockCards[0].find('.mobile-depot-stock-row--prices').text()).toContain('191.50 USD');
        expect(mobileDepotStockCards[0].findAll('.mobile-depot-stock-row')).toHaveLength(3);
        expect(mobileDepotStockCards[0].findAll('.mobile-depot-stock-actions .v-btn')).toHaveLength(2);
        setViewportSize(844, 390);
        await flushPromises();

        const compactDepotHeaders = wrapper
            .find('.desktop-depot-stocks-table')
            .findAll('th')
            .map((header) => header.text());
        expect(compactDepotHeaders).not.toContain('Symbol');
        expect(compactDepotHeaders).not.toContain('1.1/Buy');
        expect(compactDepotHeaders).toContain('Name');
        expect(compactDepotHeaders).toContain('Amount');
        expect(compactDepotHeaders).toContain('Value');
        expect(compactDepotHeaders).toContain('Latest price');
        expect(compactDepotHeaders).toContain('Prev Day');
        expect(compactDepotHeaders).toContain('+/- %');
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
        expect(wrapper.find('.cash-ledger-section').exists()).toBe(true);
        const cashLedgerHeaders = wrapper
            .find('.desktop-cash-ledger-table')
            .findAll('th')
            .map((header) => header.text());
        expect(cashLedgerHeaders).toContain('Date');
        expect(cashLedgerHeaders).toContain('Type');
        expect(cashLedgerHeaders).toContain('Stock');
        expect(cashLedgerHeaders).toContain('Pieces');
        expect(cashLedgerHeaders).not.toContain('Amount');
        expect(cashLedgerHeaders).toContain('Cash effect');
        expect(cashLedgerHeaders).toContain('Balance');
        expect(wrapper.find('.desktop-cash-ledger-table tbody tr').text()).toContain('US0378331005');
        expect(wrapper.find('.desktop-cash-ledger-table tbody tr').text()).toContain('Broker buy confirmation');
        expect(wrapper.find('.desktop-cash-ledger-table tbody').text()).toContain('Initial funding');
        const cashLedgerRows = wrapper.findAll('.desktop-cash-ledger-table tbody tr');
        expect(cashLedgerRows[1].findAll('td')[2].text()).toBe('Initial funding');
        const mobileCashLedgerCards = wrapper.findAll('.mobile-cash-ledger-card');
        expect(mobileCashLedgerCards).toHaveLength(2);
        expect(mobileCashLedgerCards[0].text()).toContain('Buy');
        expect(mobileCashLedgerCards[0].find('.mobile-cash-ledger-row').text()).toContain('04.06.2026');
        expect(mobileCashLedgerCards[0].find('.mobile-cash-ledger-row').text()).not.toContain('12:00');
        expect(mobileCashLedgerCards[0].find('.mobile-cash-ledger-row').text()).not.toContain('350.00');
        expect(mobileCashLedgerCards[0].find('.mobile-cash-ledger-stock').text()).toContain('Apple Inc.');
        expect(mobileCashLedgerCards[0].find('.cash-ledger-stock-isin').text()).toBe('US0378331005');
        expect(mobileCashLedgerCards[0].find('.cash-ledger-note').text()).toBe('Broker buy confirmation');
        expect(mobileCashLedgerCards[0].text()).toContain('-350.00');
        expect(mobileCashLedgerCards[0].text()).toContain('650.00');
        expect(mobileCashLedgerCards[1].text()).toContain('Deposit');
        expect(mobileCashLedgerCards[1].find('.mobile-cash-ledger-row').text()).toContain('04.06.2026');
        expect(mobileCashLedgerCards[1].find('.mobile-cash-ledger-row').text()).not.toContain('11:00');
        expect(mobileCashLedgerCards[1].find('.mobile-cash-ledger-row').text()).not.toContain('1,000.00');
        expect(mobileCashLedgerCards[1].find('.mobile-cash-ledger-stock').exists()).toBe(false);
        expect(mobileCashLedgerCards[1].find('.cash-ledger-note').text()).toBe('Initial funding');
        expect(mobileCashLedgerCards[1].text()).toContain('+1,000.00');
        const desktopDateInput = wrapper.find('#transaction-date-desktop-2');
        desktopDateInput.element.value = '2026-06-03';
        await desktopDateInput.trigger('change');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/depot-transactions/2/date', expect.objectContaining({
            method: 'PATCH',
            body: JSON.stringify({ booked_at: '2026-06-03' }),
        }));
        expect(wrapper.find('.mobile-cash-ledger-card').text()).toContain('03.06.2026');

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
        expect(wrapper.text()).toContain('1,007.25 EUR');
        expect(wrapper.text()).toContain('+0.73% · +7.25 EUR');
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
        expect(wrapper.text()).toContain('1,007.61 EUR');
        expect(wrapper.text()).toContain('+0.76% · +7.61 EUR');
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

    it('shows the cash ledger below the performance chart and paginates it by twenty rows', async () => {
        window.history.pushState({}, '', '/admin/menu/depot');
        const depot = {
            id: 1,
            name: 'Main depot',
            account_balance: '1000.00',
            is_active: true,
        };
        const depotValuation = {
            stock_balance: '0.00',
            cash_balance: '1000.00',
            account_balance: '1000.00',
            year_start_balance: '1000.00',
            current_balance: '1000.00',
            balance_change_amount: '0.00',
            balance_change_percent: '0.00',
            taxable_stock_gain_amount: '0.00',
            month_start_balance: '1000.00',
            month_change_amount: '0.00',
            month_change_percent: '0.00',
            one_week_start_balance: '1000.00',
            one_week_change_amount: '0.00',
            one_week_change_percent: '0.00',
        };
        const ledgerTransactions = Array.from({ length: 21 }, (_, transactionIndex) => ({
            id: transactionIndex + 1,
            type: 'deposit',
            stock_holding_id: null,
            stock_label: null,
            stock_isin: null,
            pieces: null,
            total_amount: '10.00',
            unit_price: null,
            cash_delta: '10.00',
            balance_after: String(1000 + transactionIndex * 10),
            booked_at: `2026-06-${String(21 - transactionIndex).padStart(2, '0')}T00:00:00+00:00`,
            note: `Ledger note ${String(transactionIndex + 1).padStart(2, '0')}`,
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
                }));
            }

            if (path === '/admin/depot-transactions') {
                return Promise.resolve(jsonResponse({
                    depot_holdings: [],
                    depot_valuations: {
                        latest: depotValuation,
                        flatex: depotValuation,
                    },
                    depot_performance_series: [
                        {
                            date: '2026-01-01',
                            stock_balance: '0.00',
                            cash_balance: '1000.00',
                            account_balance: '1000.00',
                        },
                    ],
                    transactions: ledgerTransactions,
                }));
            }

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
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

        const performanceCard = wrapper.find('.depot-performance-card');
        const cashLedgerSection = wrapper.find('.cash-ledger-section');
        const cashLedgerRows = wrapper.findAll('.desktop-cash-ledger-table tbody tr');
        const firstPageLedgerText = wrapper.find('.desktop-cash-ledger-table tbody').text();

        expect(performanceCard.exists()).toBe(true);
        expect(cashLedgerSection.exists()).toBe(true);
        expect(performanceCard.element.compareDocumentPosition(cashLedgerSection.element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(cashLedgerRows).toHaveLength(20);
        expect(firstPageLedgerText).toContain('Ledger note 20');
        expect(firstPageLedgerText).not.toContain('Ledger note 21');
        expect(wrapper.find('.cash-ledger-pagination').exists()).toBe(true);
    });

    it('does not tax cash-only depot balance gains', async () => {
        window.history.pushState({}, '', '/admin/menu/depot');
        const depot = {
            id: 1,
            name: 'Main depot',
            account_balance: '84664.35',
            is_active: true,
        };
        const depotValuations = {
            latest: {
                stock_balance: '0.00',
                cash_balance: '84664.35',
                account_balance: '84664.35',
                year_start_balance: '84664.35',
                current_balance: '84664.35',
                balance_change_amount: '0.00',
                balance_change_percent: '0.00',
                taxable_stock_gain_amount: '0.00',
                month_start_balance: '84664.35',
                month_change_amount: '0.00',
                month_change_percent: '0.00',
                one_week_start_balance: '84664.35',
                one_week_change_amount: '0.00',
                one_week_change_percent: '0.00',
            },
            flatex: {
                stock_balance: '0.00',
                cash_balance: '84664.35',
                account_balance: '84664.35',
                year_start_balance: '84664.35',
                current_balance: '84664.35',
                balance_change_amount: '0.00',
                balance_change_percent: '0.00',
                taxable_stock_gain_amount: '0.00',
                month_start_balance: '84664.35',
                month_change_amount: '0.00',
                month_change_percent: '0.00',
                one_week_start_balance: '84664.35',
                one_week_change_amount: '0.00',
                one_week_change_percent: '0.00',
            },
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
                    depot,
                    price_refresh_settings: priceRefreshSettings(),
                }));
            }

            if (path === '/admin/depot-transactions') {
                return Promise.resolve(jsonResponse({
                    depot_holdings: [],
                    depot_valuations: depotValuations,
                    depot_performance_series: [
                        {
                            date: '2026-01-01',
                            stock_balance: '0.00',
                            cash_balance: '83236.56',
                            account_balance: '83236.56',
                        },
                        {
                            date: '2026-06-15',
                            stock_balance: '0.00',
                            cash_balance: '84664.35',
                            account_balance: '84664.35',
                        },
                    ],
                    transactions: [],
                }));
            }

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
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

        expect(wrapper.text()).toContain('Balance 01.01.');
        expect(wrapper.text()).toContain('84,664.35 EUR');
        expect(wrapper.text()).toContain('0.00% · 0.00 EUR');
        expect(wrapper.text()).toContain('Corrected balance (-27,5%)');
        expect(wrapper.text()).toContain('Corrected +/-');
        expect(wrapper.text()).not.toContain('84,271.71 EUR');
        expect(wrapper.text()).not.toContain('+1.24% · +1,035.15 EUR');
    });

    it('does not show historical fetching state in the empty dashboard update cards', async () => {
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

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
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

            expect(wrapper.find('.dashboard-status-card').exists()).toBe(false);
            expect(wrapper.text()).not.toContain('fetching historical data');

            await vi.advanceTimersByTimeAsync(5000);
            await flushPromises();

            expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/watchlist/holdings?page=1&all=1')).toHaveLength(1);
            expect(fetchMock.mock.calls.some(([path]) => path === '/admin/price-refresh-settings')).toBe(false);
            expect(fetchMock.mock.calls.some(([path]) => path === '/admin/queue/status')).toBe(false);
            expect(fetchMock.mock.calls.some(([path]) => path === '/admin/watchlist/exchange-trading-times')).toBe(false);
            expect(wrapper.text()).not.toContain('fetching historical data');

            wrapper.unmount();
        } finally {
            vi.useRealTimers();
        }
    });

    it('does not poll update status from the dashboard', async () => {
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
            status: 'finished',
            processed: 1,
            total: 1,
            step: '1/1',
            message: 'EODHD sync: 1 record(s) created, 1 record(s) updated.',
            current: null,
            started_at: '2026-06-02T12:21:00+00:00',
            finished_at: '2026-06-02T12:21:01+00:00',
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

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
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
                        status: 'waiting',
                        status_label: 'waiting',
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

            expect(wrapper.find('.dashboard-status-card').exists()).toBe(false);

            await vi.advanceTimersByTimeAsync(5000);
            await flushPromises();

            expect(fetchMock.mock.calls.some(([path]) => path === '/admin/price-refresh-settings')).toBe(false);
            expect(fetchMock.mock.calls.some(([path]) => path === '/admin/queue/status')).toBe(false);
            expect(fetchMock.mock.calls.some(([path]) => path === '/admin/watchlist/exchange-trading-times')).toBe(false);
            expect(fetchMock).not.toHaveBeenCalledWith(
                '/admin/watchlist/holdings/refresh-prices/scheduled-refresh-1',
                expect.any(Object),
            );

            wrapper.unmount();
        } finally {
            vi.useRealTimers();
        }
    });

    it('does not load queue status when the watch-list request fails on the dashboard', async () => {
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

            if (path === '/admin/queue/status') {
                return Promise.resolve(jsonResponse(queueStatusResponse()));
            }

            if (path === '/admin/depots/active') {
                return Promise.resolve(jsonResponse({
                    depot: null,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            if (path === '/admin/watchlist/holdings?page=1&all=1') {
                return Promise.resolve(failedJsonResponse({
                    message: 'The request failed.',
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
                    meta: {
                        current_page: 1,
                        last_page: 1,
                        per_page: 10,
                        total: 0,
                        from: null,
                        to: null,
                    },
                }));
            }

            return Promise.reject(new Error(`Unexpected request: ${path}`));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();
        await flushPromises();

        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/queue/status')).toBe(false);
        expect(wrapper.find('.dashboard-status-card').exists()).toBe(false);
        expect(wrapper.text()).toContain('The request failed.');
    });

    it('does not show the clear queue action on the dashboard', async () => {
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

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
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

        expect(wrapper.find('.dashboard-status-card').exists()).toBe(false);

        const clearQueueButton = wrapper.findAll('button')
            .find((button) => button.text().includes('Clear queue'));
        expect(clearQueueButton).toBeUndefined();
        expect(fetchMock.mock.calls.some(([path, options]) => (
            path === '/admin/queue/clear' && options?.method === 'POST'
        ))).toBe(false);
    });

    it('does not show index update status on the dashboard', async () => {
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

            if (path.startsWith('/admin/watchlist/holdings?page=1')) {
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

            expect(wrapper.find('.dashboard-status-card').exists()).toBe(false);
            expect(wrapper.get('.app-bar-row').text()).not.toContain('Stocks Last:');
            expect(wrapper.text()).not.toContain('Stocks Last:');
            expect(wrapper.text()).not.toContain('Indices Last:');
            expect(wrapper.text()).not.toContain('Updating prices');
            expect(fetchMock.mock.calls.some(([path]) => path === '/admin/price-refresh-settings')).toBe(false);
            expect(fetchMock.mock.calls.some(([path]) => path === '/admin/queue/status')).toBe(false);
            expect(fetchMock.mock.calls.some(([path]) => path === '/admin/watchlist/exchange-trading-times')).toBe(false);

            wrapper.unmount();
        } finally {
            vi.useRealTimers();
        }
    });
});
