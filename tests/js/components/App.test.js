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
        table_name: 'index_watch_items',
        table_row_count: 11,
        latest_table_update_at: '2026-06-12T12:15:00+02:00',
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

function previousWeekEndDate() {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Europe/Vienna',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        weekday: 'short',
    }).formatToParts(new Date());
    const dateParts = Object.fromEntries(parts.map((part) => [part.type, part.value]));
    const daysSinceMonday = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].indexOf(dateParts.weekday);
    const viennaDate = new Date(Date.UTC(
        Number(dateParts.year),
        Number(dateParts.month) - 1,
        Number(dateParts.day) - daysSinceMonday - 1,
    ));

    return new Intl.DateTimeFormat('de-AT', {
        timeZone: 'UTC',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(viennaDate);
}

function previousMonthEndDate() {
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
        0,
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

function indexMonthPrices(direction = 'up') {
    const baseDate = new Date(Date.UTC(2026, 5, 7));

    return Array.from({ length: 30 }, (_, index) => {
        const tradingDate = new Date(baseDate);
        tradingDate.setUTCDate(baseDate.getUTCDate() - index);
        const actualPrice = direction === 'up' ? 6116.5298 - index : 6087.5298 + index;

        return {
            trading_date: tradingDate.toISOString().slice(0, 10),
            start_price: (actualPrice - 5).toFixed(6),
            actual_price: actualPrice.toFixed(6),
            last_price: (actualPrice - 2).toFixed(6),
        };
    });
}

const stocksWatchlistHoldingsPath = '/admin/watchlist/holdings?page=1&all=1';

function isWatchlistHoldingsRequest(path) {
    return path.startsWith('/admin/watchlist/holdings?page=1')
        || path.startsWith('/admin/watchlist/holdings/charts?page=1');
}

function trendDailyPrices(direction = 'up') {
    const baseDate = new Date(Date.UTC(2026, 4, 1));

    return Array.from({ length: 40 }, (_, index) => {
        const tradingDate = new Date(baseDate);
        tradingDate.setUTCDate(baseDate.getUTCDate() + index);
        const price = direction === 'up' ? 100 + index : 140 - index;

        return {
            trading_date: tradingDate.toISOString().slice(0, 10),
            price: price.toFixed(6),
            volume: 1000 + index,
            currency: 'EUR',
        };
    });
}

describe('App', () => {
    it('retains current and historical origins in the stock chart', () => {
        window.history.pushState({}, '', '/admin/login');
        vi.stubGlobal('fetch', vi.fn());
        const wrapper = mountApp();
        const holding = {
            end_price_24: '50',
            end_price_24_date: '2026-10-29',
            end_price_24_source_markers: ['EODHD'],
            recent_prices: [
                { as_of: '2026-10-30T10:00:00Z', price: '56', source_markers: ['free'] },
                { as_of: '2026-10-30T10:30:00Z', price: '57', source_markers: ['free'] },
            ],
        };

        const prices = wrapper.vm.stockChartPrices(holding, 'intraday');

        expect(prices.map((point) => point.actual_price)).toEqual(['57', '56', '50']);
        expect(prices.map((point) => point.source_markers)).toEqual([['free'], ['free'], ['EODHD']]);
        expect(prices.slice(0, 2).map((point) => point.as_of)).toEqual([
            '2026-10-30T10:30:00Z',
            '2026-10-30T10:00:00Z',
        ]);
        expect(wrapper.vm.latestPriceIsLive({ latest_price: '79.18', price_type: 'regular_market_last' })).toBe(false);
    });

    it('opens the overview for a retired Dashboard Tests bookmark', async () => {
        window.history.pushState({}, '', '/admin/menu/dashboard/tests');
        const fetchMock = vi.fn(path => Promise.resolve(jsonResponse(path === '/admin/me'
            ? { user: { id: 1, name: 'Admin', roles: ['admin'] } } : {})));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountApp();
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/dashboard');
        expect(wrapper.find('.dashboard-version-page').exists()).toBe(true);
        expect(wrapper.find('[aria-label="Dashboard Tests"]').exists()).toBe(false);
        expect(fetchMock.mock.calls.some(([path]) => path.startsWith('/admin/dashboard/tests'))).toBe(false);
    });

    it.each(['/admin/profile', '/admin/menu/indices'])('loads only visible page data when opening %s', async path => {
        window.history.pushState({}, '', path);
        const fetchMock = vi.fn((requestPath) => Promise.resolve(jsonResponse(requestPath === '/admin/me'
            ? { user: { id: 1, name: 'Admin', roles: ['super_admin', 'admin'] } }
            : {})));
        vi.stubGlobal('fetch', fetchMock);
        mountApp();
        await flushPromises();
        const requestedPaths = fetchMock.mock.calls.map(([requestPath]) => requestPath);
        expect(requestedPaths.some(requestPath => requestPath.startsWith('/admin/watchlist/holdings'))).toBe(false);
        expect(requestedPaths.some(requestPath => /^\/admin\/(users|roles|depots)(\?|$)/.test(requestPath))).toBe(false);
        if (path === '/admin/profile') {
            expect(requestedPaths).not.toContain('/admin/index-watch-items');
        } else {
            expect(requestedPaths.filter(requestPath => requestPath === '/admin/index-watch-items')).toHaveLength(1);
        }
    });

    it('renders the admin login screen without loading authenticated data', () => {
        window.history.pushState({}, '', '/admin/login');
        const fetchMock = vi.fn();
        const warnings = vi.spyOn(console, 'warn');
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();

        expect(wrapper.text()).toContain('GKStocks admin');
        expect(wrapper.text()).toContain('Sign in to manage your workspace.');
        expect(wrapper.text()).toContain('Password');
        expect(wrapper.text()).toContain('Code');
        expect(wrapper.findAllComponents({ name: 'VTextField' })).toHaveLength(2);
        expect(wrapper.findComponent({ name: 'VBtn' }).exists()).toBe(true);
        expect(warnings.mock.calls.flat().join(' ')).not.toContain('Failed to resolve component');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('renders the one-time OTP password setup without a current-password field', async () => {
        window.history.pushState({}, '', '/admin/profile');
        vi.stubGlobal('fetch', vi.fn((path) => {
            if (path === '/admin/me') {
                return Promise.resolve(jsonResponse({
                    user: {
                        name: 'Bootstrap Administrator',
                        first_name: 'Bootstrap',
                        last_name: 'Administrator',
                        email: 'bootstrap@example.com',
                        roles: ['admin', 'super_admin'],
                        can_initialize_password: true,
                    },
                }));
            }

            return Promise.resolve(jsonResponse({}));
        }));

        const wrapper = mountApp();
        await flushPromises();

        expect(wrapper.text()).toContain('Setup required');
        expect(wrapper.text()).toContain('Set a password while this emailed-code session is still recent.');

        const setPasswordButton = wrapper.findAll('button')
            .find((button) => button.text().includes('Set password'));
        await setPasswordButton.trigger('click');
        await flushPromises();

        expect(document.body.textContent).toContain('This one-time setup is available only briefly');
        expect(document.body.querySelector('input[autocomplete="current-password"]')).toBeNull();
        expect(document.body.querySelectorAll('input[autocomplete="new-password"]')).toHaveLength(2);
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
                    app_version: '0.7.2',
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            if (path === '/admin/dashboard/version') {
                return Promise.resolve(jsonResponse({
                    current_version: '0.7.2',
                    versions: [
                        { key: 'laravel', label: 'Laravel', version: '13.24.0' },
                        { key: 'php', label: 'PHP', version: '8.3.33' },
                        { key: 'vue', label: 'Vue', version: '3.5.41' },
                    ],
                }));
            }

            if (path === '/admin/dashboard/performance') {
                return Promise.resolve(jsonResponse({ days: [] }));
            }

            if (isWatchlistHoldingsRequest(path)) {
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
                            latest_price: '429.950000',
                            end_price: '429.950000',
                            end_price_24: '420.000000',
                            latest_price_change_pct: '2.37',
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
        expect(wrapper.findAll('.dashboard-compact-menu-item')).toHaveLength(8);
        expect(wrapper.find('.dashboard-compact-menu-item--active').exists()).toBe(true);
        expect(wrapper.find('[aria-label="Enhance dashboard menu"]').exists()).toBe(true);
        expect(wrapper.find('.dashboard-navigation-drawer').text()).not.toContain('Stocks');
        expect(wrapper.find('[aria-label="Indices"]').exists()).toBe(true);
        expect(wrapper.find('.dashboard-status-card').exists()).toBe(false);

        const versionCard = wrapper.get('[aria-label="Programmversion"]');
        expect(versionCard.text()).toContain('Aktuelle Version');
        expect(versionCard.get('[data-testid="app-version"]').text()).toBe('v0.7.2');
        expect(versionCard.text()).toContain('Mehr anzeigen');
        expect(versionCard.find('#dashboard-version-details').isVisible()).toBe(false);
        expect(wrapper.find('.watch-list-section').exists()).toBe(false);
        expect(wrapper.find('.dashboard-actions').exists()).toBe(false);

        await versionCard.get('.dashboard-version-toggle').trigger('click');

        expect(versionCard.text()).toContain('Weniger anzeigen');
        expect(versionCard.find('#dashboard-version-details').isVisible()).toBe(true);
        expect(versionCard.text()).toContain('Laravel');
        expect(versionCard.text()).toContain('13.24.0');
        expect(versionCard.text()).toContain('PHP');
        expect(versionCard.text()).toContain('8.3.33');
        expect(versionCard.text()).toContain('Vue');
        expect(versionCard.text()).toContain('3.5.41');
    });

    it('compacts the watch-list table below desktop width', async () => {
        window.history.pushState({}, '', '/admin/menu/stocks');
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

            if (isWatchlistHoldingsRequest(path)) {
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
        expect(wrapper.get('.mobile-watch-list').find('[aria-label="Reload AAPL price"]').exists()).toBe(true);
        expect(firstRowCells[1].find('[aria-label="Reload AAPL price"]').exists()).toBe(true);

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
        expect(microsoftCells[1].text()).toContain('-');
        expect(microsoftCells[1].find('[aria-label="Reload MSFT price"]').exists()).toBe(true);

        fetchMock.mockImplementationOnce((path, options) => {
            expect(path).toBe('/admin/watchlist/holdings/1/price/reload');
            expect(options.method).toBe('POST');
            return Promise.resolve(jsonResponse({
                status: 'updated', message: 'Newer source data loaded.',
                holding: { id: 1, latest_price: '307.320010', latest_price_as_of: '2026-06-03T15:36:00+00:00' },
            }));
        });
        await wrapper.get('.mobile-watch-list').get('[aria-label="Reload AAPL price"]').trigger('click');
        await flushPromises();
        expect(wrapper.get('.mobile-stock-price-row').text()).toContain('307.32');
        expect(wrapper.get('.mobile-stock-price-row').text()).toContain('03.06. 17:36');
        expect(wrapper.get('.mobile-watch-list').text()).toContain('Newer source data loaded.');
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

            if (isWatchlistHoldingsRequest(path)) {
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

    it.each([
        '/admin/menu/analyze',
        '/admin/menu/analyze/trend-v2?stock=1',
        '/admin/menu/analyze/research/simulation?stock=all',
        '/admin/menu/analyze/overview?stock=1',
        '/admin/menu/analyze/tests',
    ])('opens the dashboard for the retired Analyze page %s', async (path) => {
        window.history.pushState({}, '', path);
        const fetchMock = vi.fn((requestPath) => Promise.resolve(jsonResponse(requestPath === '/admin/me'
            ? { user: { id: 1, name: 'Admin', roles: ['admin'] } } : {})));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountApp();
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/dashboard');
        expect(window.location.search).toBe('');
        expect(wrapper.find('[aria-label="Dashboard"]').exists()).toBe(true);
        expect(wrapper.vm.menuItems.map((item) => item.key)).not.toContain('analyze');
        expect(fetchMock.mock.calls.map(([url]) => url).sort()).toEqual([
            '/admin/dashboard/performance',
            '/admin/dashboard/version',
            '/admin/data/performance-consistency',
            '/admin/depots/active',
            '/admin/me',
        ]);
    });

    it('does not queue paid index updates when a stored refresh time passes', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-08-06T10:00:00+02:00'));
        window.history.pushState({}, '', '/admin/menu/indices');

        const pagination = { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null };
        let realtimeDispatches = 0;
        let indexLoads = 0;
        let currentSettings = {
            times: ['02:00'],
            timezone: 'Europe/Vienna',
            status: 'waiting',
            realtime: {
                timezone: 'Europe/Vienna',
                latest_update_at: '2026-08-06T09:40:00+02:00',
                next_refresh_at: '2026-08-06T10:00:02+02:00',
                status: 'scheduled',
                status_label: 'Scheduled',
            },
        };
        const fetchMock = vi.fn((path, options = {}) => {
            if (path === '/admin/me') {
                return Promise.resolve(jsonResponse({
                    user: { id: 1, name: 'Admin User', email: 'admin@example.com', roles: ['admin'] },
                }));
            }

            if (path === '/admin/depots/active') {
                return Promise.resolve(jsonResponse({
                    depot: null,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            if (isWatchlistHoldingsRequest(path)) {
                return Promise.resolve(jsonResponse({
                    depot: null,
                    holdings: [],
                    meta: pagination,
                    price_refresh_settings: priceRefreshSettings(),
                    index_price_refresh_settings: indexPriceRefreshSettings(),
                }));
            }

            if (path === '/admin/index-watch-items') {
                indexLoads += 1;

                return Promise.resolve(jsonResponse({ indexes: [] }));
            }

            if (path === '/admin/index-watch-items/refresh-free') {
                return Promise.resolve(jsonResponse({ status: 'idle' }));
            }

            if (path === '/admin/v2/indices/eodhd-sync-settings') {
                if (realtimeDispatches > 0 && currentSettings.realtime.status === 'updating') {
                    currentSettings = {
                        ...currentSettings,
                        realtime: {
                            ...currentSettings.realtime,
                            latest_update_at: '2026-08-06T10:00:04+02:00',
                            status: 'scheduled',
                            status_label: 'Scheduled',
                        },
                    };
                }

                return Promise.resolve(jsonResponse({ index_eodhd_sync_settings: currentSettings }));
            }

            if (path === '/admin/v2/indices/realtime-sync' && options.method === 'POST') {
                realtimeDispatches += 1;
                currentSettings = {
                    ...currentSettings,
                    realtime: {
                        ...currentSettings.realtime,
                        next_refresh_at: '2026-08-06T10:05:02+02:00',
                        status: 'updating',
                        status_label: 'Updating now',
                    },
                };

                return Promise.resolve(jsonResponse({
                    queued: true,
                    index_eodhd_sync_settings: currentSettings,
                }));
            }

            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({ depots: [], meta: pagination }));
            }

            return Promise.reject(new Error(`Unexpected request: ${path}`));
        });
        vi.stubGlobal('fetch', fetchMock);

        try {
            const wrapper = mountApp();
            await flushPromises();

            await vi.advanceTimersByTimeAsync(1000);
            await flushPromises();
            expect(realtimeDispatches).toBe(0);

            await vi.advanceTimersByTimeAsync(2000);
            await flushPromises();
            expect(realtimeDispatches).toBe(0);
            expect(fetchMock).not.toHaveBeenCalledWith('/admin/v2/indices/realtime-sync', expect.objectContaining({
                method: 'POST',
            }));
            expect(wrapper.find('[aria-label="Automatic v2 index updates"]').exists()).toBe(false);

            await vi.advanceTimersByTimeAsync(5000);
            await flushPromises();
            expect(realtimeDispatches).toBe(0);
            expect(indexLoads).toBe(1);


            wrapper.unmount();
        } finally {
            vi.useRealTimers();
        }
    });

    it('keeps index actions disabled while a queued refresh is running across menu changes', async () => {
        vi.useFakeTimers();
        window.history.pushState({}, '', '/admin/menu/indices');
        let status = 'idle';
        const fetchMock = vi.fn((path, options) => {
            if (path === '/admin/me') {
                return Promise.resolve(jsonResponse({ user: { id: 1, roles: ['admin'] } }));
            }
            if (path === '/admin/index-watch-items/refresh-free') {
                if (options?.method === 'POST') status = 'queued';
                return Promise.resolve(jsonResponse({ status }));
            }
            return Promise.resolve(jsonResponse({}));
        });
        vi.stubGlobal('fetch', fetchMock);
        const button = (wrapper, label) => wrapper.findAll('button').find(candidate => candidate.text() === label);
        const labels = ['Kurse aktualisieren', 'Index hinzufügen'];

        try {
            const wrapper = mountApp();
            await flushPromises();
            for (const label of labels) expect(button(wrapper, label).element.disabled).toBe(false);
            await button(wrapper, labels[0]).trigger('click');
            await flushPromises();
            for (const label of labels) expect(button(wrapper, label).element.disabled).toBe(true);
            expect(wrapper.text()).toContain('Kursaktualisierung wartet in der Queue.');

            wrapper.vm.navigateSection('dashboard');
            await flushPromises();
            status = 'running';
            wrapper.vm.navigateSection('indices');
            await flushPromises();
            for (const label of labels) expect(button(wrapper, label).element.disabled).toBe(true);
            expect(wrapper.text()).toContain('Kursaktualisierung läuft im Hintergrund.');

            status = 'idle';
            await vi.advanceTimersByTimeAsync(3000);
            await flushPromises();
            for (const label of labels) expect(button(wrapper, label).element.disabled).toBe(false);
            expect(wrapper.text()).not.toContain('Kursaktualisierung läuft im Hintergrund.');
        } finally {
            vi.useRealTimers();
        }
    });

    it('shows the dedicated Stocks and Indices dashboard pages', async () => {
        localStorage.removeItem('stock_eodhd_sync_dismissed_refresh_id');
        window.history.pushState({}, '', '/admin/menu/stocks');
        let currentPriceRefreshSettings = priceRefreshSettings();
        let indexChartDirection = 'up';
        let appleSubtitle = 'Core technology holding';
        let currentStockEodhdSync = null;
        const currentIndexPriceRefreshSettings = indexPriceRefreshSettings({
            last_refreshed_at: '2026-06-02T13:00:00+00:00',
            next_refresh_at: '2026-06-02T13:30:00+00:00',
        });
        let currentIndexEodhdSyncSettings = {
            times: ['02:00', '18:30'],
            timezone: 'Europe/Vienna',
            last_dispatched_at: '2026-08-05T02:00:00+02:00',
            next_update_at: '2026-08-05T18:30:00+02:00',
            latest_update_at: '2026-08-05T09:45:00+02:00',
            status: 'waiting',
            status_label: 'Waiting',
            realtime: {
                trading_interval_minutes: 30,
                trading_starts_before_minutes: 15,
                trading_ends_after_minutes: 20,
                closed_refresh_enabled: false,
                closed_interval_minutes: 90,
                timezone: 'Europe/Vienna',
                latest_update_at: '2026-08-05T10:00:00+02:00',
                next_refresh_at: '2026-08-05T10:30:00+02:00',
                status: 'scheduled',
                status_label: 'Scheduled',
                status_detail: 'No job is running; the next refresh starts at the time shown below.',
                is_trading_time: false,
                last_error: null,
            },
        };
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

            if (isWatchlistHoldingsRequest(path)) {
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
                            subtitle: appleSubtitle,
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
                            daily_prices: trendDailyPrices('up'),
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
                            daily_prices: trendDailyPrices('down'),
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
                            trading_times: null,
                            venue: null,
                            price_type: 'intraday',
                            price_spread_pct: null,
                            daily_prices: streakRecommendationDailyPrices().slice(0, 4),
                            recent_prices: [],
                            validation_errors: [],
                        },
                        {
                            id: 2,
                            symbol: 'EXXX',
                            name: 'iShares ATX UCITS ETF (DE)',
                            isin: 'DE000A0D8Q23',
                            wkn: 'A0D8Q2',
                            exchange: 'US',
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
                        {
                            id: 8,
                            symbol: 'BUY',
                            name: 'Buy Streak Fund',
                            isin: 'IE00BUY00008',
                            wkn: 'BUY001',
                            exchange: 'XETR',
                            currency: 'EUR',
                            latest_price: '95.000000',
                            start_price: '95.000000',
                            end_price: '100.016033',
                            end_price_24: '97.008761',
                            end_price_48: '95.106628',
                            latest_price_trend: null,
                            latest_price_change_pct: null,
                            latest_price_tick_trend: null,
                            latest_price_status: 'fresh',
                            price_status: 'fresh',
                            latest_price_fetched_at: '2026-06-15T16:00:00+00:00',
                            latest_price_source: 'Tradegate Exchange',
                            latest_price_source_url: 'https://example.com/buy',
                            latest_price_as_of: '2026-06-15T16:00:00+00:00',
                            trading_times: 'Monday-Friday 09:00-17:30 Europe/Berlin',
                            venue: 'Tradegate',
                            price_type: 'last',
                            price_spread_pct: null,
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
                            recent_prices: [],
                            validation_errors: [],
                        },
                    ],
                    meta: {
                        current_page: 1,
                        last_page: 1,
                        per_page: 5,
                        total: 6,
                        from: 1,
                        to: 6,
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
                const payload = JSON.parse(options.body);
                currentPriceRefreshSettings = priceRefreshSettings({
                    ...payload,
                    next_refresh_at: '2026-06-02T12:35:00+00:00',
                    current_interval_minutes: payload.trading_interval_minutes,
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
                const payload = JSON.parse(options.body);

                return Promise.resolve(jsonResponse({
                    message: 'Stock added to watch-list.',
                    holding: {
                        id: 2,
                        symbol: 'MSFT',
                        name: 'Microsoft',
                        subtitle: payload.subtitle,
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

            if (path === '/admin/index-watch-items/refresh-free') {
                return Promise.resolve(jsonResponse({ status: 'idle' }));
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
                        trading_times: 'Monday-Friday 09:00-17:30 Europe/Berlin',
                        recent_prices: [],
                    },
                }));
            }

            if (path === '/admin/v2/indices/eodhd-sync-settings' && options?.method === 'PATCH') {
                const payload = JSON.parse(options.body);

                if (payload.times) {
                    currentIndexEodhdSyncSettings = {
                        ...currentIndexEodhdSyncSettings,
                        times: payload.times,
                        next_update_at: '2026-08-05T21:15:00+02:00',
                    };
                }

                if (payload.realtime) {
                    currentIndexEodhdSyncSettings = {
                        ...currentIndexEodhdSyncSettings,
                        realtime: {
                            ...currentIndexEodhdSyncSettings.realtime,
                            ...payload.realtime,
                            next_refresh_at: '2026-08-05T10:05:00+02:00',
                        },
                    };
                }

                return Promise.resolve(jsonResponse({
                    message: payload.realtime
                        ? 'Automatic index realtime schedule saved.'
                        : 'Automatic index update times saved.',
                    index_eodhd_sync_settings: currentIndexEodhdSyncSettings,
                }));
            }

            if (path === '/admin/v2/indices/eodhd-sync-settings') {
                return Promise.resolve(jsonResponse({
                    index_eodhd_sync_settings: currentIndexEodhdSyncSettings,
                }));
            }

            if (path === '/admin/v2/stocks/eodhd-sync' && options?.method === 'POST') {
                currentStockEodhdSync = {
                    refresh_id: 'stock-eodhd-test',
                    status: 'queued',
                    stage: 'eod',
                    date_from: '2025-08-06',
                    date_to: '2026-08-06',
                    current: 'Synchronizing missing EOD data...',
                    steps: [
                        { key: 'eod', label: 'EOD-Daten', status: 'running', message: 'Synchronizing EOD data.' },
                        { key: 'intraday', label: 'Intraday-Daten', status: 'pending', message: 'Waiting for EOD data.' },
                    ],
                    progress: { completed: 0, total: 7, percent: 0 },
                    eod: { stored_count: 0, failed_count: 0 },
                    intraday: { stored_count: 0, failed_count: 0 },
                    message: 'Stock EODHD sync queued.',
                    error: null,
                };

                return Promise.resolve(jsonResponse({ refresh: currentStockEodhdSync }));
            }

            if (path === '/admin/v2/stocks/eodhd-sync' && (!options?.method || options.method === 'GET')) {
                return Promise.resolve(jsonResponse({ refresh: currentStockEodhdSync }));
            }

            if (path === '/admin/v2/stocks/eodhd-sync/stock-eodhd-test') {
                currentStockEodhdSync = {
                    ...currentStockEodhdSync,
                    status: 'finished',
                    stage: 'intraday',
                    current: null,
                    steps: [
                        { key: 'eod', label: 'EOD-Daten', status: 'finished', message: '40 EOD records loaded/updated.' },
                        { key: 'intraday', label: 'Intraday-Daten', status: 'finished', message: '900 candles loaded/updated.' },
                    ],
                    progress: { completed: 7, total: 7, percent: 100 },
                    eod: { stored_count: 40, failed_count: 0 },
                    intraday: { stored_count: 900, failed_count: 0 },
                    message: 'Stock EODHD sync finished.',
                };

                return Promise.resolve(jsonResponse({ refresh: currentStockEodhdSync }));
            }

            if (path === '/admin/v2/indices/eodhd-sync' && options?.method === 'POST') {
                return Promise.resolve(jsonResponse({
                    message: 'Index EODHD sync queued.',
                    refresh: {
                        refresh_id: 'index-eodhd-test',
                        status: 'queued',
                        stage: 'check_indices',
                        processed: 0,
                        total: 0,
                        current: null,
                        date_from: '2025-08-05',
                        date_to: '2026-08-04',
                        steps: [
                            { key: 'check_indices', label: 'Check all indices', status: 'pending', message: null },
                            { key: 'check_eod', label: 'Check EOD data', status: 'pending', message: null },
                            { key: 'sync_eod', label: 'Sync missing EOD data', status: 'pending', message: null },
                            { key: 'check_intraday', label: 'Check intraday data', status: 'pending', message: null },
                            { key: 'sync_intraday', label: 'Sync missing intraday data', status: 'pending', message: null },
                            { key: 'summary', label: 'Create summary', status: 'pending', message: null },
                        ],
                        index_progress: [
                            {
                                id: 1,
                                symbol: 'ATX',
                                name: 'Austrian Traded Index',
                                eod_check: { status: 'finished', message: '250 available, 0 missing.' },
                                eod_sync: { status: 'finished', message: '0 records synced.' },
                                intraday_check: { status: 'finished', message: 'No intraday data available.' },
                                intraday_sync: {
                                    status: 'not_found',
                                    message: 'EODHD returned HTTP 404 after 3 attempts; this does not prove that the index has no intraday data.',
                                },
                                intraday_blocks: [],
                            },
                            {
                                id: 2,
                                symbol: 'GDAXI',
                                name: 'DAX Index',
                                eod_check: { status: 'finished', message: '250 available, 0 missing.' },
                                eod_sync: { status: 'finished', message: '0 records synced.' },
                                intraday_check: { status: 'running', message: 'Intraday data is being checked.' },
                                intraday_sync: { status: 'pending', message: null },
                                intraday_blocks: [],
                            },
                        ],
                        progress: {
                            completed: 6,
                            total: 8,
                            successful: 5,
                            deferred: 0,
                            issues: 1,
                            percent: 75,
                            estimated_remaining_seconds: 75,
                        },
                        summary: null,
                        error: null,
                    },
                    index_eodhd_sync_settings: {
                        ...currentIndexEodhdSyncSettings,
                        status: 'updating',
                        status_label: 'Updating indices',
                    },
                }, 202));
            }

            if (path === '/admin/v2/indices/eodhd-sync/index-eodhd-test') {
                currentIndexEodhdSyncSettings = {
                    ...currentIndexEodhdSyncSettings,
                    latest_update_at: '2026-08-05T10:30:00+02:00',
                    status: 'waiting',
                    status_label: 'Waiting',
                };

                return Promise.resolve(jsonResponse({
                    refresh: {
                        refresh_id: 'index-eodhd-test',
                        status: 'partial',
                        stage: 'summary',
                        processed: 1,
                        total: 1,
                        current: null,
                        date_from: '2025-08-05',
                        date_to: '2026-08-04',
                        steps: [
                            { key: 'check_indices', label: 'Check all indices', status: 'finished', message: '1 index found.' },
                            { key: 'check_eod', label: 'Check EOD data', status: 'finished', message: '2 missing EOD records found.' },
                            { key: 'sync_eod', label: 'Sync missing EOD data', status: 'finished', message: '2 EOD records synced.' },
                            { key: 'check_intraday', label: 'Check intraday data', status: 'finished', message: '0 missing intraday candles found.' },
                            { key: 'sync_intraday', label: 'Sync missing intraday data', status: 'finished', message: '0 intraday candles synced.' },
                            { key: 'summary', label: 'Create summary', status: 'finished', message: 'Synchronization summary ready.' },
                        ],
                        index_progress: [
                            {
                                id: 1,
                                symbol: 'ATX',
                                name: 'Austrian Traded Index',
                                eod_check: { status: 'finished', message: '250 available, 2 missing.' },
                                eod_sync: { status: 'finished', message: '2 records synced.' },
                                intraday_check: { status: 'finished', message: '4 missing periods found.' },
                                intraday_sync: { status: 'timeout', message: 'EODHD intraday timed out after 3 attempts.' },
                                intraday_blocks: [
                                    {
                                        position: 1,
                                        total: 4,
                                        date_from: '2026-07-01',
                                        date_to: '2026-07-30',
                                        status: 'timeout',
                                        attempts: 3,
                                        records: 0,
                                        synced: 0,
                                        missing_dates: ['2026-07-01'],
                                        message: 'EODHD intraday timed out after 3 attempts.',
                                    },
                                ],
                            },
                            {
                                id: 2,
                                symbol: 'ATG',
                                name: 'Athens General Composite',
                                eod_check: { status: 'finished', message: '246 available, 0 missing.' },
                                eod_sync: { status: 'finished', message: '0 records synced.' },
                                intraday_check: { status: 'finished', message: '12 missing periods found.' },
                                intraday_sync: { status: 'no_data', message: 'EODHD returned HTTP 200 but no intraday data.' },
                                intraday_blocks: [
                                    {
                                        position: 1,
                                        total: 12,
                                        date_from: '2026-07-01',
                                        date_to: '2026-07-30',
                                        status: 'no_data',
                                        attempts: 3,
                                        records: 0,
                                        synced: 0,
                                        missing_dates: ['2026-07-01'],
                                        message: '1 date still has no EODHD data.',
                                    },
                                ],
                            },
                            {
                                id: 3,
                                symbol: 'BROKEN',
                                name: 'Broken Index',
                                eod_check: { status: 'failed', message: 'EOD check failed.' },
                                eod_sync: { status: 'skipped', message: 'Not run because the EOD check failed.' },
                                intraday_check: { status: 'finished', message: 'No missing periods.' },
                                intraday_sync: { status: 'finished', message: 'No missing periods to sync.' },
                                intraday_blocks: [],
                            },
                        ],
                        progress: {
                            completed: 12,
                            total: 12,
                            successful: 8,
                            deferred: 0,
                            issues: 4,
                            percent: 100,
                            estimated_remaining_seconds: 0,
                        },
                        summary: {
                            eod: { available: 5, already_present: 3, missing: 2, synced: 2, new_rows: 2 },
                            intraday: {
                                available: 0,
                                already_present: 0,
                                missing: 12,
                                synced: 0,
                                new_candles: 0,
                                stored_candles: 10000,
                                no_data_indices: 1,
                                partial_indices: 0,
                                unsupported_indices: 0,
                                access_denied_indices: 0,
                                deferred_dates: 1,
                            },
                            indices: [
                                {
                                    id: 1,
                                    symbol: 'ATX',
                                    eod: { synced: 2, status: 'finished' },
                                    intraday: {
                                        synced: 0,
                                        new_candles: 0,
                                        stored_candles: 10000,
                                        status: 'timeout',
                                        expected_dates: 250,
                                        verified_dates: 246,
                                        deferred_dates: 1,
                                    },
                                },
                                {
                                    id: 2,
                                    symbol: 'ATG',
                                    eod: { synced: 0, status: 'finished' },
                                    intraday: {
                                        synced: 0,
                                        new_candles: 0,
                                        stored_candles: 0,
                                        status: 'no_data',
                                        expected_dates: 246,
                                        verified_dates: 0,
                                        deferred_dates: 0,
                                    },
                                },
                            ],
                            errors: [
                                { symbol: 'ATX', stage: 'intraday', status: 'timeout', message: 'Timed out after 3 attempts.' },
                            ],
                        },
                        error: null,
                    },
                    index_eodhd_sync_settings: currentIndexEodhdSyncSettings,
                }));
            }

            if (path.startsWith('/admin/index-watch-items/1/prices/ensure?range=') && options?.method === 'POST') {
                const range = new URL(path, 'http://localhost').searchParams.get('range');

                return Promise.resolve(jsonResponse({
                    message: 'Index prices loaded.',
                    range,
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
                        trading_times: 'Monday-Friday 09:00:00-17:30:00 Europe/Berlin',
                        latest_price: '6116.529800',
                        last_price: '6096.169900',
                        latest_price_change_pct: '0.33',
                        recent_prices: range === 'intraday'
                            ? indexMonthPrices(indexChartDirection).map((price, index) => index === 0
                                ? { ...price, actual_price_as_of: '2026-06-07T13:45:00+02:00' }
                                : price)
                            : indexMonthPrices(indexChartDirection),
                        realtime_prices: range === 'intraday'
                            ? [
                                { trading_date: '2026-06-07', price: '6116.529800', as_of: '2026-06-07T13:45:00+02:00' },
                                { trading_date: '2026-06-07', price: '6098.400000', as_of: '2026-06-07T11:00:00+02:00' },
                                { trading_date: '2026-06-07', price: '6080.100000', as_of: '2026-06-07T09:20:00+02:00' },
                            ]
                            : [],
                    },
                }));
            }

            if (path === '/admin/index-watch-items/1' && options?.method === 'DELETE') {
                return Promise.resolve(jsonResponse({
                    message: 'Index removed.',
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

            if (path === '/admin/watchlist/holdings/1' && options?.method === 'PATCH') {
                const payload = JSON.parse(options.body);
                appleSubtitle = payload.subtitle;

                return Promise.resolve(jsonResponse({
                    message: 'Stock updated.',
                    holding: {
                        id: 1,
                        ...payload,
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

            if (
                path === '/admin/stocks/search'
                && options?.method === 'POST'
                && JSON.parse(options.body).query === 'DAX'
            ) {
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

            if (
                path === '/admin/stocks/search'
                && options?.method === 'POST'
                && JSON.parse(options.body).query === 'Microsoft'
            ) {
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
        wrapper.vm.liveDataStatusNow = Date.parse('2026-08-06T18:49:00+02:00');
        await wrapper.vm.$nextTick();

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
        expect(wrapper.get('td.watch-list-source-time-cell').find('[aria-label^="Reload "]').exists()).toBe(true);
        expect(wrapper.find('thead th.watch-list-actions-cell').exists()).toBe(true);
        expect(wrapper.get('.app-bar-row').text()).not.toContain('Stocks Last:');
        expect(wrapper.find('.dashboard-status-card').exists()).toBe(false);
        expect(wrapper.find('[aria-label="Indices"]').exists()).toBe(false);
        expect(wrapper.find('.index-watch-strip').exists()).toBe(false);
        expect(fetchMock).toHaveBeenCalledWith(stocksWatchlistHoldingsPath, expect.any(Object));
        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/queue/status')).toBe(false);
        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/watchlist/exchange-trading-times')).toBe(false);
        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/price-refresh-settings')).toBe(false);
        expect(wrapper.find('[aria-label="Minify dashboard menu"]').exists()).toBe(true);
        const watchListSection = wrapper.get('.watch-list-section');
        expect(watchListSection.text()).toContain('Stocks');
        expect(watchListSection.find('.watch-list-section-title-row .watch-list-live-badge').exists()).toBe(false);
        expect(watchListSection.text()).toContain('6');
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
        const stockSubtitles = wrapper.findAll('.stock-subtitle');
        expect(stockSubtitles.length).toBeGreaterThan(0);
        expect(stockSubtitles.every((subtitle) => subtitle.classes().includes('text-info'))).toBe(true);
        expect(stockSubtitles.every((subtitle) => !subtitle.classes().includes('text-primary'))).toBe(true);
        expect(stockSubtitles.every((subtitle) => subtitle.classes().includes('font-weight-medium'))).toBe(true);
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

        const holdingRows = wrapper.findAll('tbody .stock-holding-row');
        const upPriceValue = holdingRows[0].findAll('td')[2].find('.latest-price-value');
        const downPriceValue = holdingRows[1].findAll('td')[2].find('.latest-price-value');
        const intradayPriceValue = holdingRows[2].findAll('td')[2].find('.latest-price-value');
        expect(holdingRows[0].findAll('td')[0].text()).toContain('Exchange: NASDAQ');
        expect(holdingRows[0].findAll('td')[0].text()).toContain('Pieces: 0');
        expect(holdingRows[0].findAll('td')[1].text()).not.toContain('Exchange: NASDAQ');
        expect(holdingRows[0].findAll('td')[1].text()).not.toContain('Pieces: 0');
        expect(holdingRows[0].findAll('td')[1].text()).toContain('US0378331005 · WKN: 865985');
        expect(holdingRows[0].findAll('td')[5].text()).toContain('03.06.2026, 17:35');
        expect(wrapper.find('.stock-trend-signal').exists()).toBe(false);
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
        expect(holdingRows[0].findAll('td')[2].find('.latest-price-tick').exists()).toBe(false);
        expect(holdingRows[1].findAll('td')[2].find('.latest-price-tick').exists()).toBe(false);
        expect(holdingRows[2].findAll('td')[2].find('.latest-price-tick').exists()).toBe(false);
        expect(holdingRows[0].findAll('td')[3].text()).not.toContain('%');
        const recentPriceTrendDots = holdingRows[0].findAll('.recent-price-trend-dot');
        expect(recentPriceTrendDots).toHaveLength(10);
        expect(recentPriceTrendDots[0].classes()).toContain('recent-price-trend-dot-flat');
        expect(recentPriceTrendDots[1].classes()).toContain('recent-price-trend-dot-down');
        expect(recentPriceTrendDots[2].classes()).toContain('recent-price-trend-dot-up');

        expect(wrapper.text()).not.toContain('305.55');

        await holdingRows[0].trigger('click');
        await flushPromises();

        expect(wrapper.vm.activeItemRefreshIntervalMilliseconds).toBe(60_000);
        expect(wrapper.vm.activeItemRefreshTimer).not.toBeNull();
        const stockRefreshRequestCount = fetchMock.mock.calls
            .filter(([path]) => isWatchlistHoldingsRequest(path)).length;
        await wrapper.vm.refreshActiveItemPage();
        await flushPromises();
        expect(fetchMock.mock.calls
            .filter(([path]) => isWatchlistHoldingsRequest(path))).toHaveLength(stockRefreshRequestCount + 1);

        expect(wrapper.text()).not.toContain('305.55');
        expect(fetchMock).not.toHaveBeenCalledWith('/admin/watchlist/holdings/1/intraday-candles', expect.anything());
        expect(wrapper.find('.holding-intraday-detail').exists()).toBe(false);
        expect(wrapper.find('.holding-intraday-chart').exists()).toBe(false);
        expect(wrapper.find('.holding-intraday-table').exists()).toBe(false);
        expect(wrapper.find('.recent-price-strip').exists()).toBe(false);

        await wrapper.find('.stock-holding-row').trigger('click');
        await flushPromises();

        expect(wrapper.vm.activeItemRefreshTimer).toBeNull();
        expect(wrapper.text()).not.toContain('305.55');

        const closedMarketHoldingRow = wrapper.findAll('.stock-holding-row')[1];
        await closedMarketHoldingRow.trigger('click');
        await flushPromises();

        expect(fetchMock).not.toHaveBeenCalledWith('/admin/watchlist/holdings/4/intraday-candles', expect.anything());
        expect(wrapper.text()).not.toContain('No EODHD intraday prices available for this session.');

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
        expect(fetchMock.mock.calls.filter(([path]) => path === stocksWatchlistHoldingsPath)).toHaveLength(1);
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

        wrapper.vm.navigateSection('stocks');
        await flushPromises();

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

        const addSubtitleInput = document.body.querySelector('input[maxlength="255"]');
        addSubtitleInput.value = 'Cloud platform leader';
        addSubtitleInput.dispatchEvent(new Event('input', { bubbles: true }));

        expect(document.body.textContent).toContain('Microsoft Corporation');
        expect(document.body.textContent).toContain('US5949181045');
        expect(document.body.textContent).toContain('NASDAQ');
        expect(document.body.textContent).toContain('NASDAQ / XNAS / USD');
        const freeSearchCall = fetchMock.mock.calls.find(([path]) => path === '/admin/stocks/search');
        expect(JSON.parse(freeSearchCall[1].body).source).toBe('free');

        const addButton = Array.from(document.body.querySelectorAll('button'))
            .filter((button) => button.textContent.trim() === 'Add')
            .at(-1);
        addButton.click();
        await flushPromises();

        const createHoldingCall = fetchMock.mock.calls.find(([path, options]) => (
            path === '/admin/watchlist/holdings' && options?.method === 'POST'
        ));
        expect(JSON.parse(createHoldingCall[1].body)).toEqual({
            symbol: 'MSFT',
            name: 'Microsoft Corporation',
            isin: 'US5949181045',
            exchange: 'NASDAQ',
            mic_code: 'XNAS',
            instrument_type: 'Common Stock',
            country: 'United States',
            currency: 'USD',
            subtitle: 'Cloud platform leader',
        });

        const topLevelMenuKeys = wrapper.vm.menuItems.map((item) => item.key);
        expect(topLevelMenuKeys).toEqual([
            'dashboard',
            'indices',
            'stocks',
            'depot',
            'data',
            'infos',
            'admin',
            'profile',
        ]);

        currentPriceRefreshSettings = priceRefreshSettings({
            closed_refresh_enabled: false,
            is_trading_time: true,
            last_refreshed_at: '2026-08-06T16:49:00+00:00',
            next_refresh_at: '2026-08-06T16:59:00+00:00',
        });
        wrapper.vm.liveDataStatusNow = Date.parse('2026-08-06T18:49:00+02:00');
        wrapper.vm.navigateSection('stocks');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/stocks');
        const stocksDashboard = wrapper.get('[aria-label="Stocks dashboard"]');
        expect(stocksDashboard.text()).toContain('View and manage all saved stocks.');
        const stocksDashboardButtons = stocksDashboard.findAll('button');
        const stockEodhdSyncButton = stocksDashboardButtons.find((button) => button.text() === 'EODHD Sync');
        const stocksAddButton = stocksDashboardButtons.find((button) => button.text() === 'Add stock');
        const stocksEditButton = stocksDashboardButtons.find((button) => button.text() === 'Edit stock');
        const stocksDeleteButton = stocksDashboardButtons.find((button) => button.text() === 'Delete stock');
        expect(stockEodhdSyncButton.attributes('disabled')).toBeDefined();
        expect(stocksAddButton.exists()).toBe(true);
        expect(stocksEditButton).toBeUndefined();
        expect(stocksDeleteButton.exists()).toBe(true);
        expect(stocksDeleteButton.attributes('disabled')).toBeDefined();
        expect(fetchMock).toHaveBeenCalledWith(stocksWatchlistHoldingsPath, expect.any(Object));
        expect(fetchMock).toHaveBeenCalledWith('/admin/v2/stocks/eodhd-sync', expect.any(Object));
        expect(wrapper.get('.watch-list-section').text()).toContain('Apple');
        expect(wrapper.get('.watch-list-section').text()).toContain('Core technology holding');
        expect(wrapper.find('[aria-label="Buy stock"]').exists()).toBe(true);
        expect(wrapper.find('[aria-label="Sell stock"]').exists()).toBe(true);

        await stockEodhdSyncButton.trigger('click');
        await flushPromises();

        expect(fetchMock).not.toHaveBeenCalledWith('/admin/v2/stocks/eodhd-sync', expect.objectContaining({ method: 'POST' }));

        const stocksTable = wrapper.get('.desktop-watch-list-table');
        expect(stocksTable.findAll('.watch-list-live-badge')).toHaveLength(1);
        const stockRows = stocksTable.findAll('tbody tr.stock-holding-row');
        const appleStockRow = stockRows[0];
        const closedMarketStockRow = stockRows[1];
        const usStockRow = stockRows[3];
        const buyStockRow = stockRows[5];
        expect(appleStockRow.get('[aria-label="AAPL live update schedule status"]').text()).toBe('Scheduled');
        expect(appleStockRow.get('[aria-label="AAPL live update activity status"]').text()).toBe('Active');
        expect(appleStockRow.get('[aria-label="AAPL live update activity status"] .live-update-status-dot').exists()).toBe(true);
        expect(appleStockRow.get('.latest-price-value').classes()).toContain('bg-success');
        expect(appleStockRow.find('.stock-holding-refresh-schedule').exists()).toBe(false);
        expect(closedMarketStockRow.get('[aria-label="DOWN live update schedule status"]').text()).toBe('Waiting');
        expect(closedMarketStockRow.get('[aria-label="DOWN live update activity status"]').text()).toBe('Inactive');
        expect(closedMarketStockRow.get('[aria-label="DOWN live update activity status"]').classes()).toContain('text-error');
        wrapper.vm.holdings.find((holding) => holding.id === 4).latest_price = '190.000000';
        wrapper.vm.holdings.find((holding) => holding.id === 4).latest_price_trend = 'down';
        await wrapper.vm.$nextTick();
        expect(closedMarketStockRow.get('.latest-price-value').classes()).not.toContain('bg-error');
        expect(closedMarketStockRow.get('.latest-price-value').classes()).not.toContain('text-white');
        expect(closedMarketStockRow.get('.latest-price-value').classes()).toContain('text-error');
        wrapper.vm.holdings.find((holding) => holding.id === 4).latest_price_trend = 'up';
        await wrapper.vm.$nextTick();
        expect(closedMarketStockRow.get('.latest-price-value').classes()).toContain('text-success');
        expect(closedMarketStockRow.get('.latest-price-value').classes()).not.toContain('text-error');
        expect(buyStockRow.find('.watch-list-live-badge').exists()).toBe(false);
        expect(usStockRow.get('[aria-label="EXXX live update activity status"]').text()).toBe('Active');
        expect(stocksTable.findAll('thead th').map((heading) => heading.text())).toContain('Actions');
        expect(appleStockRow.find('[aria-label="Edit stock"]').exists()).toBe(true);
        expect(wrapper.find('.mobile-stock-actions [aria-label="Edit stock"]').exists()).toBe(true);
        expect(stocksTable.find('[aria-label="Delete stock"]').exists()).toBe(false);
        expect(wrapper.find('.mobile-stock-actions [aria-label="Delete"]').exists()).toBe(false);
        expect(appleStockRow.attributes('aria-selected')).toBe('false');
        expect(appleStockRow.classes()).not.toContain('stock-holding-row--selected');

        await appleStockRow.trigger('click');
        await flushPromises();

        expect(appleStockRow.attributes('aria-selected')).toBe('true');
        expect(appleStockRow.classes()).toContain('stock-holding-row--selected');
        expect(stocksDeleteButton.attributes('disabled')).toBeUndefined();
        expect(fetchMock).toHaveBeenCalledWith(
            '/admin/watchlist/holdings/charts?page=1&include_charts=1&all=1&chart_stock_id=1&chart_range=today',
            expect.any(Object),
        );

        await appleStockRow.get('[aria-label="Edit stock"]').trigger('click');
        await flushPromises();

        const editDialog = Array.from(document.body.querySelectorAll('.v-overlay'))
            .find((dialog) => dialog.textContent.includes('Edit stock'));
        const editSubtitleLabel = Array.from(editDialog.querySelectorAll('label'))
            .find((label) => label.textContent.trim() === 'Subtitle');
        const editSubtitleInput = editDialog.querySelector(`#${editSubtitleLabel.getAttribute('for')}`);
        editSubtitleInput.value = 'Growth position';
        editSubtitleInput.dispatchEvent(new Event('input', { bubbles: true }));
        const saveEditButton = Array.from(editDialog.querySelectorAll('button'))
            .find((button) => button.textContent.trim() === 'Save');
        saveEditButton.click();
        await flushPromises();

        const updateCall = fetchMock.mock.calls.find(([path, options]) => (
            path === '/admin/watchlist/holdings/1' && options?.method === 'PATCH'
        ));
        expect(JSON.parse(updateCall[1].body)).toMatchObject({
            symbol: 'AAPL',
            name: 'Apple',
            subtitle: 'Growth position',
            isin: 'US0378331005',
            wkn: '865985',
            exchange: 'NASDAQ',
            currency: 'EUR',
        });
        expect(wrapper.get('.watch-list-section').text()).toContain('Growth position');

        const stockPriceCard = wrapper.get('.stock-price-inline-card');
        const appleStockChartRow = appleStockRow.element.nextElementSibling;
        expect(appleStockChartRow).not.toBeNull();
        expect(appleStockChartRow.classList.contains('stock-price-chart-row')).toBe(true);
        expect(appleStockChartRow.style.display).not.toBe('none');
        expect(appleStockChartRow.querySelector('.stock-price-inline-card')).not.toBeNull();
        expect(stockPriceCard.text()).toContain('Apple');
        expect(stockPriceCard.text()).toContain('Actual price');
        expect(stockPriceCard.text()).toContain('Evolution');
        expect(stockPriceCard.text()).toContain("Previous trading day's close to latest available price.");
        expect(stockPriceCard.findAll('.stock-price-range-tabs .v-tab').map((tab) => tab.text())).toEqual([
            'Intraday',
            '1 week',
            '1 month',
            '6 month',
            '1 year',
        ]);
        expect(stockPriceCard.find('.stock-price-range-tabs .v-tab--selected').text()).toBe('Intraday');
        expect(stockPriceCard.find('.stock-price-chart-line').exists()).toBe(true);
        expect(stockPriceCard.get('.stock-price-chart-line').classes()).toContain('stock-price-chart-line--positive');
        expect(stockPriceCard.findAll('.stock-price-chart-point').length).toBeGreaterThan(2);
        expect(stockPriceCard.findAll('.stock-price-chart-x-label').at(0).text()).toBe('04.06');
        expect(stockPriceCard.get('.index-price-chart-endpoint-label--start').text()).toContain('Previous close 299.50');

        const oneWeekStockTab = stockPriceCard.findAll('.stock-price-range-tabs .v-tab')
            .find((tab) => tab.text() === '1 week');
        await oneWeekStockTab.trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/admin/watchlist/holdings/charts?page=1&include_charts=1&all=1&chart_stock_id=1&chart_range=1w',
            expect.any(Object),
        );
        expect(wrapper.get('.stock-price-range-tabs .v-tab--selected').text()).toBe('1 week');

        const sixMonthStockTab = wrapper.findAll('.stock-price-range-tabs .v-tab')
            .find((tab) => tab.text() === '6 month');
        await sixMonthStockTab.trigger('click');
        await flushPromises();

        expect(wrapper.get('.stock-price-range-tabs .v-tab--selected').text()).toBe('6 month');
        expect(wrapper.find('.stock-price-chart-line').exists()).toBe(true);
        expect(wrapper.findAll('.stock-price-chart-point')).toHaveLength(0);

        const oneYearStockTab = wrapper.findAll('.stock-price-range-tabs .v-tab')
            .find((tab) => tab.text() === '1 year');
        await oneYearStockTab.trigger('click');
        await flushPromises();

        expect(wrapper.get('.stock-price-range-tabs .v-tab--selected').text()).toBe('1 year');
        expect(wrapper.find('.stock-price-chart-line').exists()).toBe(true);
        expect(wrapper.get('.stock-price-chart-line').classes()).toContain('stock-price-chart-line--positive');
        expect(wrapper.findAll('.stock-price-chart-point')).toHaveLength(0);

        await appleStockRow.trigger('click');
        await flushPromises();

        expect(appleStockRow.attributes('aria-selected')).toBe('false');
        expect(appleStockRow.classes()).not.toContain('stock-holding-row--selected');
        expect(stocksDeleteButton.attributes('disabled')).toBeDefined();
        expect(wrapper.find('.stock-price-inline-card').exists()).toBe(false);
        expect(appleStockRow.element.nextElementSibling?.style.display).toBe('none');

        await closedMarketStockRow.trigger('click');
        await flushPromises();

        const closedMarketOneYearTab = wrapper.findAll('.stock-price-range-tabs .v-tab')
            .find((tab) => tab.text() === '1 year');
        await closedMarketOneYearTab.trigger('click');
        await flushPromises();

        expect(wrapper.get('.stock-price-chart-line').classes()).toContain('stock-price-chart-line--negative');

        await closedMarketStockRow.trigger('click');
        await flushPromises();

        await appleStockRow.trigger('click');
        await flushPromises();

        const automaticStockUpdates = wrapper.get('[aria-label="Automatic free stock updates"]');
        const stockRealtimeSchedule = automaticStockUpdates.get('[aria-label="Stock realtime schedule"]');
        expect(stockRealtimeSchedule.get('.text-body-2.font-weight-bold').text()).toBe('Automatic free updates');
        expect(stockRealtimeSchedule.text()).toContain('start 5 min before trading until 10 min after trading · 20 min · closed: off');
        const stockRefreshExchangeWindows = stockRealtimeSchedule.get('.stock-refresh-exchange-windows');
        expect(stockRefreshExchangeWindows.text()).toContain(
            'US (America/New_York): trading 15:30–22:00 Europe/Vienna'
                + ' → refresh 15:25–22:10 Europe/Vienna.',
        );
        expect(stockRefreshExchangeWindows.text()).toContain(
            'XETR (Europe/Berlin): trading 09:00–17:30 Europe/Vienna'
                + ' → refresh 08:55–17:40 Europe/Vienna.',
        );
        expect(stockRefreshExchangeWindows.text()).not.toContain('Tradegate');
        expect(automaticStockUpdates.find('[aria-label="Stock live update schedule status"]').exists()).toBe(false);
        expect(automaticStockUpdates.find('[aria-label="Stock live update activity status"]').exists()).toBe(false);
        wrapper.vm.liveDataStatusNow = Date.parse('2026-03-20T12:00:00+01:00');
        await wrapper.vm.$nextTick();
        expect(stockRefreshExchangeWindows.text()).toContain(
            'US (America/New_York): trading 14:30–21:00 Europe/Vienna'
                + ' → refresh 14:25–21:10 Europe/Vienna.',
        );
        expect(usStockRow.get('[aria-label="EXXX live update schedule status"]').text()).toBe('Waiting');
        expect(usStockRow.get('[aria-label="EXXX live update activity status"]').text()).toBe('Inactive');
        wrapper.vm.liveDataStatusNow = Date.parse('2026-08-06T18:49:00+02:00');
        await wrapper.vm.$nextTick();
        expect(usStockRow.get('[aria-label="EXXX live update schedule status"]').text()).toBe('Scheduled');
        expect(usStockRow.get('[aria-label="EXXX live update activity status"]').text()).toBe('Active');
        expect(stockRealtimeSchedule.text()).not.toContain('No job is running; the next refresh starts at the time shown below.');
        expect(stockRealtimeSchedule.text()).not.toContain(
            'Updates are inactive outside the configured trading window.',
        );
        expect(stockRealtimeSchedule.text()).toContain('Latest 06.08.2026, 18:49');
        expect(stockRealtimeSchedule.text()).toContain('Next 06.08.2026, 18:59');
        expect(stockRealtimeSchedule.text()).toContain('Europe/Vienna');
        const editStockLivePeriodButton = stockRealtimeSchedule.get('button');
        expect(editStockLivePeriodButton.text()).toBe('Edit live period');
        expect(editStockLivePeriodButton.classes()).toContain('v-btn--variant-text');

        await editStockLivePeriodButton.trigger('click');
        await flushPromises();

        const stockLiveScheduleDialog = document.body.querySelector('.stock-live-schedule-dialog');
        expect(stockLiveScheduleDialog.textContent).toContain('Edit live/realtime update period');
        expect(stockLiveScheduleDialog.textContent).toContain(
            'Refreshes each stock from free sources around its stored trading hours.',
        );
        expect(stockLiveScheduleDialog.textContent).toContain('Every (minutes)');
        expect(stockLiveScheduleDialog.textContent).toContain('Start before (minutes)');
        expect(stockLiveScheduleDialog.textContent).toContain('End after (minutes)');
        expect(stockLiveScheduleDialog.textContent).toContain('Also refresh while markets are closed');
        wrapper.vm.liveDataUpdateScheduleForm.trading_interval_minutes = 12;
        wrapper.vm.liveDataUpdateScheduleForm.trading_starts_before_minutes = 45;
        wrapper.vm.liveDataUpdateScheduleForm.trading_ends_after_minutes = 30;
        wrapper.vm.liveDataUpdateScheduleForm.closed_refresh_enabled = true;
        wrapper.vm.liveDataUpdateScheduleForm.closed_interval_minutes = 90;
        await wrapper.vm.$nextTick();

        const saveStockLiveScheduleButton = Array.from(stockLiveScheduleDialog.querySelectorAll('button'))
            .find((button) => button.textContent.trim() === 'Save live schedule');
        saveStockLiveScheduleButton.click();
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/price-refresh-settings', expect.objectContaining({
            method: 'PATCH',
            body: JSON.stringify({
                trading_interval_minutes: 12,
                trading_starts_before_minutes: 45,
                trading_ends_after_minutes: 30,
                trading_start_time: null,
                trading_end_time: null,
                closed_refresh_enabled: true,
                closed_interval_minutes: 90,
            }),
        }));
        expect(stockRealtimeSchedule.text()).toContain(
            'start 45 min before trading until 30 min after trading · 12 min · closed: every 90 min',
        );
        expect(stockRefreshExchangeWindows.text()).toContain(
            'NASDAQ (Europe/Berlin): trading 08:00–22:00 Europe/Vienna'
                + ' → refresh 07:15–22:30 Europe/Vienna.',
        );

        await stocksAddButton.trigger('click');
        await flushPromises();
        expect(wrapper.vm.isHoldingDialogOpen).toBe(true);
        wrapper.vm.abortHoldingDialog();
        await flushPromises();

        await stocksDeleteButton.trigger('click');
        await flushPromises();
        expect(wrapper.vm.isDeleteHoldingDialogOpen).toBe(true);
        expect(document.body.textContent).toContain('Delete Apple · Growth position?');
        wrapper.vm.abortDeleteHoldingDialog();
        await flushPromises();

        wrapper.vm.navigateSection('indices');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/indices');
        expect(wrapper.find('[aria-label="Indices"]').exists()).toBe(true);
        expect(wrapper.find('.watch-list-section').exists()).toBe(false);
        expect(wrapper.find('[aria-label="Automatic v2 index updates"]').exists()).toBe(false);
        expect(wrapper.findAll('button').some((button) => button.text() === 'EODHD Sync')).toBe(false);


        const addIndexButton = wrapper.findAll('button').find((button) => button.text() === 'Index hinzufügen');
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
        expect(wrapper.vm.indexMessage).toBe('Index added.');
        expect(fetchMock).toHaveBeenCalledWith('/admin/index-watch-items', expect.any(Object));

        const indexWatchStrip = wrapper.find('.index-watch-strip');
        expect(indexWatchStrip.text()).toContain('DAX');
        expect(indexWatchStrip.text()).toContain('Germany');
        expect(indexWatchStrip.text()).toContain('DAX Index');
        expect(indexWatchStrip.text()).toContain('+0.33%');
        expect(indexWatchStrip.text()).toContain('6,116.53');
        expect(wrapper.findAll('button').some((button) => button.text() === 'Kurse aktualisieren')).toBe(true);
        wrapper.vm.indexWatchItems[0].private_quote = true;
        wrapper.vm.indexWatchItems[0].source_markers = ['EODHD'];
        wrapper.vm.indexWatchItems[0].latest_price_source_markers = ['free'];
        wrapper.vm.indexWatchItems[0].latest_price_as_of = '2026-08-06T15:30:00Z';
        wrapper.vm.indexWatchItems[0].latest_price_fetched_at = '2026-08-06T15:45:00Z';
        await wrapper.vm.$nextTick();
        const quoteMeta = indexWatchStrip.get('.index-watch-card-quote-meta');
        expect(quoteMeta.text()).toContain('Yahoo Finance');
        expect(quoteMeta.text()).toContain('ggf. verzögert');
        expect(quoteMeta.text()).toContain('Kurszeit');
        expect(quoteMeta.text()).toContain('Abgerufen');
        expect(quoteMeta.findAll('time').map(time => time.attributes('datetime'))).toEqual([
            '2026-08-06T15:30:00Z', '2026-08-06T15:45:00Z',
        ]);
        expect(indexWatchStrip.text()).not.toContain('EODHD');
        expect(indexWatchStrip.text()).not.toContain('free');
        wrapper.vm.indexEodhdSyncSettings.realtime.closed_refresh_enabled = false;
        wrapper.vm.liveDataStatusNow = Date.parse('2026-08-06T18:40:00+02:00');
        wrapper.vm.indexWatchItems.push({
            id: 2,
            symbol: 'DJI',
            name: 'Dow Jones Industrial Average',
            exchange: 'INDX',
            country: 'USA',
            trading_times: 'Monday-Friday 09:30-16:00 America/New_York',
            latest_price: '44500.00',
            latest_price_change_pct: '0.20',
            recent_prices: [],
        });
        wrapper.vm.indexWatchItems.push({
            id: 3,
            symbol: 'UTCX',
            name: 'UTC Index',
            exchange: 'INDX',
            country: 'Global',
            trading_times: 'Monday-Friday 00:00:00-23:59:00 UTC',
            latest_price: '100.00',
            latest_price_change_pct: '0.00',
            recent_prices: [],
        });
        await wrapper.vm.$nextTick();

        const daxIndexCard = wrapper.findAll('.index-watch-card')
            .find((card) => card.text().includes('DAX Index'));
        const djiIndexCard = wrapper.findAll('.index-watch-card')
            .find((card) => card.text().includes('Dow Jones Industrial Average'));
        expect(daxIndexCard.get('[aria-label="DAX trading time in Europe/Vienna"]').text()).toContain('Next 07.08.');
        expect(daxIndexCard.get('[aria-label="DAX trading time in Europe/Vienna"]').text()).toContain('09:00–17:30');
        expect(djiIndexCard.get('[aria-label="DJI trading time in Europe/Vienna"]').text()).toContain('Current');
        expect(djiIndexCard.get('[aria-label="DJI trading time in Europe/Vienna"]').text()).toContain('15:30–22:00');
        wrapper.vm.indexWatchItems.splice(
            wrapper.vm.indexWatchItems.findIndex((indexItem) => indexItem.id === 2),
            2,
        );
        await wrapper.vm.$nextTick();
        expect(wrapper.find('.index-watch-card').text()).not.toContain('2026-06-07');
        expect(wrapper.find('.index-watch-card').text()).not.toContain('DE0008469008');
        expect(wrapper.find('.index-add-tile').exists()).toBe(false);
        expect(wrapper.find('[aria-label="Index DAX entfernen"]').exists()).toBe(false);

        const deleteIndexAction = wrapper.findAll('button')
            .find((button) => button.text() === 'Index löschen');
        expect(deleteIndexAction.attributes('disabled')).toBeDefined();

        await wrapper.find('.index-watch-card').trigger('click');
        await flushPromises();

        expect(wrapper.vm.activeItemRefreshTimer).not.toBeNull();

        expect(fetchMock).toHaveBeenCalledWith('/admin/index-watch-items/1/prices/ensure?range=intraday', expect.objectContaining({
            method: 'POST',
        }));
        expect(wrapper.find('.index-price-inline-card').exists()).toBe(true);
        expect(wrapper.findAll('.index-price-range-tabs .v-tab').map((tab) => tab.text())).toEqual([
            'Intraday',
            '1 week',
            '1 month',
            '6 month',
            '1 year',
        ]);
        expect(wrapper.find('.index-price-range-tabs .v-tab--selected').text()).toBe('Intraday');
        expect(wrapper.find('.index-price-inline-card').text()).toContain('Actual price');
        expect(wrapper.find('.index-price-inline-card').text()).toContain('Evolution');
        expect(wrapper.find('.index-price-inline-card').text()).toContain("Previous trading day's close to latest available price.");
        expect(wrapper.find('.index-price-chart-date-range').text()).toBe('07.06.2026');
        expect(wrapper.find('.index-price-chart-date-range').classes()).toContain('text-h6');
        expect(wrapper.get('.index-price-chart-line').classes()).toContain('index-price-chart-line--positive');
        expect(wrapper.findAll('.index-price-chart-point')).toHaveLength(4);
        expect(wrapper.findAll('.index-price-chart-x-label').map((label) => label.text())).toEqual([
            'Previous close',
            '09:20',
            '11:00',
            '13:45',
        ]);
        expect(wrapper.get('.index-price-chart-endpoint-label--start').text()).toContain('Previous close 6,096.17');

        const intradayRequestCount = fetchMock.mock.calls
            .filter(([path]) => path === '/admin/index-watch-items/1/prices/ensure?range=intraday').length;
        await wrapper.find('.index-watch-card').trigger('click');
        await flushPromises();

        expect(wrapper.vm.selectedIndexWatchItem).toBeNull();
        expect(wrapper.find('.index-watch-card').attributes('aria-pressed')).toBe('false');
        expect(wrapper.find('.index-price-inline-card').exists()).toBe(false);
        expect(deleteIndexAction.attributes('disabled')).toBeDefined();
        expect(fetchMock.mock.calls
            .filter(([path]) => path === '/admin/index-watch-items/1/prices/ensure?range=intraday')).toHaveLength(intradayRequestCount);

        await wrapper.find('.index-watch-card').trigger('click');
        await flushPromises();

        const oneMonthTab = wrapper.findAll('.index-price-range-tabs .v-tab')
            .find((tab) => tab.text() === '1 month');
        await oneMonthTab.trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/index-watch-items/1/prices/ensure?range=1m', expect.objectContaining({
            method: 'POST',
        }));
        expect(wrapper.find('.index-price-range-tabs .v-tab--selected').text()).toBe('1 month');
        expect(wrapper.find('.index-price-chart-date-range').text()).toBe('09.05.2026 – 07.06.2026');
        expect(wrapper.find('.index-price-chart-line').exists()).toBe(true);
        expect(wrapper.get('.index-price-chart-line').classes()).toContain('index-price-chart-line--positive');
        expect(wrapper.find('.index-price-chart-trend-line').exists()).toBe(true);
        expect(Number(wrapper.find('.index-price-chart-trend-line').attributes('x2'))).toBeGreaterThan(
            Number(wrapper.find('.index-price-chart-trend-line').attributes('x1')),
        );
        expect(wrapper.findAll('.index-price-chart-point')).toHaveLength(30);
        expect(wrapper.findAll('.index-price-chart-grid-line')).toHaveLength(12);
        expect(wrapper.findAll('.index-price-chart-y-label')).toHaveLength(5);
        expect(wrapper.findAll('.index-price-chart-x-label')).toHaveLength(7);
        expect(wrapper.findAll('.index-price-chart-endpoint-label')).toHaveLength(2);
        expect(wrapper.find('.index-price-chart-endpoint-label--start').text()).toMatch(/^Start /);
        expect(wrapper.find('.index-price-chart-endpoint-label--latest').text()).toMatch(/^End /);
        expect(Number(wrapper.find('.index-price-chart-endpoint-label--start').attributes('y'))).toBeGreaterThan(220);
        expect(Number(wrapper.find('.index-price-chart-endpoint-label--latest').attributes('y'))).toBeLessThan(70);
        expect(wrapper.find('.index-price-inline-card').text()).toContain('6,116.53');
        expect(wrapper.find('.index-price-inline-card').text()).toContain('09.05');

        const sixMonthTab = wrapper.findAll('.index-price-range-tabs .v-tab')
            .find((tab) => tab.text() === '6 month');
        await sixMonthTab.trigger('click');
        await flushPromises();

        expect(wrapper.find('.index-price-range-tabs .v-tab--selected').text()).toBe('6 month');
        expect(wrapper.find('.index-price-chart-line').exists()).toBe(true);
        expect(wrapper.findAll('.index-price-chart-point')).toHaveLength(0);

        const oneYearTab = wrapper.findAll('.index-price-range-tabs .v-tab')
            .find((tab) => tab.text() === '1 year');
        indexChartDirection = 'down';
        await oneYearTab.trigger('click');
        await flushPromises();

        expect(wrapper.find('.index-price-range-tabs .v-tab--selected').text()).toBe('1 year');
        expect(wrapper.find('.index-price-chart-line').exists()).toBe(true);
        expect(wrapper.get('.index-price-chart-line').classes()).toContain('index-price-chart-line--negative');
        expect(wrapper.findAll('.index-price-chart-point')).toHaveLength(0);
        expect(deleteIndexAction.attributes('disabled')).toBeUndefined();

        await deleteIndexAction.trigger('click');
        await flushPromises();

        expect(wrapper.vm.isDeleteIndexDialogOpen).toBe(true);
        expect(document.body.textContent).toContain('Index löschen');
        expect(document.body.textContent).toContain('DAX Index wirklich löschen?');

        const removeIndexButton = Array.from(document.body.querySelectorAll('button'))
            .find((button) => button.textContent.trim() === 'Löschen');
        removeIndexButton.click();
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/index-watch-items/1', expect.objectContaining({
            method: 'DELETE',
        }));
        expect(wrapper.vm.indexMessage).toBe('Index removed.');
        expect(wrapper.findAll('.index-watch-card')).toHaveLength(0);

        wrapper.vm.navigateSection('stocks');
        await flushPromises();

        const reopenedAddStockButton = wrapper.findAll('button').find((button) => button.text().includes('Add stock'));
        await reopenedAddStockButton.trigger('click');
        await flushPromises();

        expect(wrapper.vm.isHoldingDialogOpen).toBe(true);

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
        await flushPromises();

        expect(wrapper.vm.isHoldingDialogOpen).toBe(false);

        const deleteStockButton = wrapper.findAll('button').find((button) => button.text() === 'Delete stock');
        await deleteStockButton.trigger('click');
        await flushPromises();

        expect(document.body.textContent).toContain('Confirm delete');
        expect(document.body.textContent).toContain('Delete Apple · Growth position?');

        const deleteButton = Array.from(document.body.querySelectorAll('button'))
            .filter((button) => button.textContent.trim() === 'Confirm')
            .at(-1);
        deleteButton.click();
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/watchlist/holdings/1', expect.objectContaining({
            method: 'DELETE',
        }));

        wrapper.unmount();
        window.history.pushState({}, '', '/admin/menu/stocks');

        const reloadedWrapper = mountApp();
        await flushPromises();

        expect(reloadedWrapper.get('[aria-label="Stocks dashboard"]')
            .find('[aria-label="Stock EODHD synchronization status"]').exists()).toBe(false);
    }, 20000);

    it('loads and manually reloads the new dashboard without a market-data sync action', async () => {
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

            if (path === '/admin/dashboard/version') {
                return Promise.resolve(jsonResponse({
                    current_version: '0.1.5',
                    versions: [],
                }));
            }

            if (path === '/admin/dashboard/performance') {
                return Promise.resolve(jsonResponse({
                    days: [
                        {
                            date: '2026-08-03',
                            is_live: false,
                            change_amount: '-12.30',
                            change_percent: '-0.04',
                        },
                        {
                            date: '2026-08-04',
                            is_live: false,
                            change_amount: '42.10',
                            change_percent: '0.14',
                        },
                        {
                            date: '2026-08-05',
                            is_live: false,
                            change_amount: '0.00',
                            change_percent: '0.00',
                        },
                        {
                            date: '2026-08-06',
                            is_live: false,
                            change_amount: '-80.15',
                            change_percent: '-0.27',
                        },
                        {
                            date: '2026-08-07',
                            is_live: true,
                            change_amount: '125.40',
                            change_percent: '0.42',
                        },
                    ],
                    sums: [
                        {
                            period: 'week',
                            change_amount: '75.05',
                            change_percent: '0.25',
                        },
                        {
                            period: 'last_week',
                            change_amount: '-20.10',
                            change_percent: '-0.07',
                        },
                        {
                            period: 'month',
                            change_amount: '-140.20',
                            change_percent: '-0.47',
                        },
                        {
                            period: 'last_month',
                            change_amount: '940.25',
                            change_percent: '3.13',
                        },
                        {
                            period: 'year',
                            change_amount: '3240.80',
                            change_percent: '10.80',
                        },
                    ],
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

        expect(wrapper.find('[aria-label="Dashboard sections"]').exists()).toBe(false);
        expect(wrapper.find('[aria-label="Free market data policy"]').exists()).toBe(false);
        expect(wrapper.find('.dashboard-action-button').exists()).toBe(false);
        expect(wrapper.find('.watch-list-section').exists()).toBe(false);
        expect(wrapper.get('.dashboard-version-page').element.firstElementChild.classList)
            .toContain('dashboard-version-card');
        expect(wrapper.findAll('.dashboard-performance-day-card')).toHaveLength(5);
        expect(wrapper.findAll('.dashboard-performance-day-card')[0].text()).toContain('Montag');
        expect(wrapper.findAll('.dashboard-performance-day-card')[0].text()).toContain('03.08.2026');
        expect(wrapper.findAll('.dashboard-performance-day-card')[1].text()).toContain('Dienstag');
        expect(wrapper.findAll('.dashboard-performance-day-card')[2].text()).toContain('Mittwoch');
        expect(wrapper.findAll('.dashboard-performance-day-card')[2].text()).toContain('0.00 EUR');
        expect(wrapper.findAll('.dashboard-performance-day-card')[3].text()).toContain('Donnerstag');
        expect(wrapper.findAll('.dashboard-performance-day-card')[3].text()).toContain('-80.15 EUR');
        expect(wrapper.findAll('.dashboard-performance-day-card')[3].text()).toContain('-0.27%');
        expect(wrapper.findAll('.dashboard-performance-day-card')[4].text()).toContain('Freitag · Live');
        expect(wrapper.findAll('.dashboard-performance-day-card')[4].text()).toContain('07.08.2026');
        expect(wrapper.findAll('.dashboard-performance-day-card')[4].text()).toContain('+125.40 EUR');
        expect(wrapper.findAll('.dashboard-performance-day-card')[4].text()).toContain('+0.42%');
        expect(wrapper.findAll('.dashboard-performance-sum-card')).toHaveLength(5);
        expect(wrapper.findAll('.dashboard-performance-sum-card')[0].text()).toContain('Week');
        expect(wrapper.findAll('.dashboard-performance-sum-card')[0].text()).toContain('+75.05 EUR');
        expect(wrapper.findAll('.dashboard-performance-sum-card')[0].text()).toContain('+0.25%');
        expect(wrapper.findAll('.dashboard-performance-sum-card')[1].text()).toContain('Last Week');
        expect(wrapper.findAll('.dashboard-performance-sum-card')[1].text()).toContain('-20.10 EUR');
        expect(wrapper.findAll('.dashboard-performance-sum-card')[1].text()).toContain('-0.07%');
        expect(wrapper.findAll('.dashboard-performance-sum-card')[2].text()).toContain('Month');
        expect(wrapper.findAll('.dashboard-performance-sum-card')[2].text()).toContain('-140.20 EUR');
        expect(wrapper.findAll('.dashboard-performance-sum-card')[2].text()).toContain('-0.47%');
        expect(wrapper.findAll('.dashboard-performance-sum-card')[3].text()).toContain('Last Month');
        expect(wrapper.findAll('.dashboard-performance-sum-card')[3].text()).toContain('+940.25 EUR');
        expect(wrapper.findAll('.dashboard-performance-sum-card')[3].text()).toContain('+3.13%');
        expect(wrapper.findAll('.dashboard-performance-sum-card')[4].text()).toContain('Year');
        expect(wrapper.findAll('.dashboard-performance-sum-card')[4].text()).toContain('+3,240.80 EUR');
        expect(wrapper.findAll('.dashboard-performance-sum-card')[4].text()).toContain('+10.80%');
        expect(wrapper.find('[aria-label="Stocks with Trend v2 signals"]').exists()).toBe(false);
        expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/dashboard/version')).toHaveLength(1);
        expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/dashboard/performance')).toHaveLength(1);
        expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/depots/active')).toHaveLength(1);
        expect(fetchMock.mock.calls.some(([path]) => path.startsWith('/admin/watchlist/holdings'))).toBe(false);
        expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/depots?page=1')).toHaveLength(0);
        expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/index-watch-items')).toHaveLength(0);
        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/queue/status')).toBe(false);
        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/watchlist/exchange-trading-times')).toBe(false);
        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/price-refresh-settings')).toBe(false);
        expect(fetchMock.mock.calls.some(([path]) => String(path).includes('/sync'))).toBe(false);

        const reloadButton = wrapper.get('[aria-label="Reload dashboard data"]');
        expect(reloadButton.text()).toContain('Reload');

        await reloadButton.trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/dashboard/version')).toHaveLength(2);
        expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/dashboard/performance')).toHaveLength(2);
        expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/depots/active')).toHaveLength(2);
        expect(fetchMock.mock.calls.some(([path]) => path.startsWith('/admin/watchlist/holdings'))).toBe(false);
        expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/depots?page=1')).toHaveLength(0);
        expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/index-watch-items')).toHaveLength(0);
        expect(fetchMock.mock.calls.some(([path]) => String(path).includes('/sync'))).toBe(false);

    });

    it('repairs only eligible dashboard cards and polls only after a click', async () => {
        vi.useFakeTimers();
        window.history.pushState({}, '', '/admin/dashboard');

        const repairPath = '/admin/dashboard/performance/repair';
        const days = [
            { date: '2026-11-02', change_amount: null, change_percent: null, repairable: true },
            { date: '2026-11-03', change_amount: '0.00', change_percent: null, repairable: false },
            { date: '2026-11-04', change_amount: null, change_percent: null, repairable: false },
        ];
        const sums = [{ period: 'last_month', change_amount: null, change_percent: null, repairable: true }];
        let mondayStatusChecks = 0;
        const fetchMock = vi.fn((path, options) => {
            if (path === '/admin/me') {
                return Promise.resolve(jsonResponse({ user: { id: 1, name: 'Admin', roles: ['admin'] } }));
            }
            if (path === '/admin/dashboard/performance') {
                return Promise.resolve(jsonResponse({ days, sums }));
            }
            if (path === repairPath) {
                const target = JSON.parse(options.body);
                if (target.date) {
                    return Promise.resolve(jsonResponse({ status: 'queued', run_id: 'monday-run' }));
                }
                return Promise.resolve(jsonResponse({
                    status: 'repaired',
                    message: 'Depotentwicklung neu berechnet.',
                    days,
                    sums: [{ ...sums[0], change_amount: '10.00', change_percent: '1.00', repairable: false }],
                }));
            }
            if (path === `${repairPath}/monday-run`) {
                if (++mondayStatusChecks === 1) {
                    return Promise.resolve(jsonResponse({
                        status: 'waiting',
                        run_id: 'monday-run',
                        retry_at: new Date(Date.now() + 30 * 60 * 1000).toISOString(),
                        message: 'Yahoo Finance hat den Kursabruf pausiert. Die Reparatur wird automatisch fortgesetzt.',
                    }));
                }
                return Promise.resolve(jsonResponse({
                    status: 'unavailable',
                    message: 'Für diese Karte fehlen weiterhin verwendbare Kurse aus kostenlosen Quellen.',
                    days,
                    sums,
                }));
            }
            return Promise.resolve(jsonResponse({}));
        });
        vi.stubGlobal('fetch', fetchMock);

        try {
            const wrapper = mountApp();
            await flushPromises();

            expect(wrapper.findAll('.dashboard-performance-repair')).toHaveLength(2);
            expect(wrapper.get('[aria-label="Repair Montag"]').text()).toBe('Repair');
            expect(wrapper.find('[aria-label="Repair Dienstag"]').exists()).toBe(false);
            expect(wrapper.find('[aria-label="Repair Mittwoch"]').exists()).toBe(false);
            expect(fetchMock.mock.calls.some(([path]) => path.startsWith(repairPath))).toBe(false);

            await wrapper.get('[aria-label="Repair Montag"]').trigger('click');
            await flushPromises();
            expect(wrapper.get('[aria-label="Repair Montag"]').classes()).toContain('v-btn--loading');
            expect(wrapper.get('[aria-label="Repair Last Month"]').attributes('disabled')).toBeDefined();
            await wrapper.get('[aria-label="Repair Last Month"]').trigger('click');
            expect(fetchMock.mock.calls.filter(([path]) => path === repairPath)).toHaveLength(1);
            expect(fetchMock).toHaveBeenCalledWith(repairPath, expect.objectContaining({
                method: 'POST',
                body: JSON.stringify({ date: '2026-11-02' }),
            }));

            await vi.advanceTimersByTimeAsync(2000);
            await flushPromises();
            expect(fetchMock.mock.calls.filter(([path]) => path === `${repairPath}/monday-run`)).toHaveLength(1);
            expect(wrapper.get('.dashboard-performance-repair-feedback').text()).toContain('Die Reparatur wird automatisch fortgesetzt');
            expect(wrapper.get('[aria-label="Repair Montag"]').classes()).toContain('v-btn--loading');

            await vi.advanceTimersByTimeAsync(29 * 60 * 1000);
            expect(fetchMock.mock.calls.filter(([path]) => path === `${repairPath}/monday-run`)).toHaveLength(1);
            await vi.advanceTimersByTimeAsync(60 * 1000);
            await flushPromises();
            expect(fetchMock.mock.calls.filter(([path]) => path === `${repairPath}/monday-run`)).toHaveLength(2);
            expect(wrapper.get('.dashboard-performance-repair-feedback').text()).toContain('fehlen weiterhin verwendbare Kurse');
            expect(wrapper.get('[aria-label="Repair Montag"]').attributes('disabled')).toBeUndefined();

            await wrapper.get('[aria-label="Repair Last Month"]').trigger('click');
            await flushPromises();
            expect(fetchMock).toHaveBeenCalledWith(repairPath, expect.objectContaining({
                method: 'POST',
                body: JSON.stringify({ period: 'last_month' }),
            }));
            expect(wrapper.find('[aria-label="Repair Last Month"]').exists()).toBe(false);
            expect(wrapper.findAll('.dashboard-performance-sum-card')[3].text()).toContain('+10.00 EUR');
            expect(wrapper.get('.dashboard-performance-repair-feedback').text()).toContain('Depotentwicklung neu berechnet.');
        } finally {
            vi.useRealTimers();
        }
    });

    it('opens the overview for a retired Dashboard KI bookmark without loading research', async () => {
        window.history.pushState({}, '', '/admin/menu/dashboard/ki');
        const fetchMock = vi.fn(path => Promise.resolve(jsonResponse(path === '/admin/me'
            ? { user: { id: 1, name: 'Admin', roles: ['admin'] } } : {})));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountApp();
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/dashboard');
        expect(wrapper.find('.dashboard-version-page').exists()).toBe(true);
        expect(wrapper.find('[aria-label="Dashboard KI"]').exists()).toBe(false);
        expect(fetchMock.mock.calls.some(([path]) => path.startsWith('/admin/dashboard/ai'))).toBe(false);
    });

    it('does not automatically reload the new dashboard', async () => {
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

            if (path === '/admin/dashboard/version') {
                return Promise.resolve(jsonResponse({
                    current_version: '0.1.5',
                    versions: [],
                }));
            }

            if (path === '/admin/dashboard/performance') {
                return Promise.resolve(jsonResponse({ days: [] }));
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
            expect(fetchMock.mock.calls.some(([path]) => path.startsWith('/admin/watchlist/holdings'))).toBe(false);
            expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/depots?page=1')).toHaveLength(0);
            expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/index-watch-items')).toHaveLength(0);

            await vi.advanceTimersByTimeAsync(60000);
            await flushPromises();

            expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/dashboard/version')).toHaveLength(1);
            expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/dashboard/performance')).toHaveLength(1);
            expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/depots/active')).toHaveLength(1);
            expect(fetchMock.mock.calls.some(([path]) => path.startsWith('/admin/watchlist/holdings'))).toBe(false);
            expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/depots?page=1')).toHaveLength(0);
            expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/index-watch-items')).toHaveLength(0);
            expect(fetchMock.mock.calls.some(([path]) => path === '/admin/queue/status')).toBe(false);
            expect(fetchMock.mock.calls.some(([path]) => path === '/admin/price-refresh-settings')).toBe(false);
            expect(fetchMock.mock.calls.some(([path]) => String(path).includes('/sync'))).toBe(false);

            wrapper.unmount();
        } finally {
            vi.useRealTimers();
        }
    });

    it('keeps stock deletion only in the page actions and disables it for holdings with position pieces', async () => {
        window.history.pushState({}, '', '/admin/menu/stocks');
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
            if (isWatchlistHoldingsRequest(path)) {
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

        expect(wrapper.find('[aria-label="Delete stock"]').exists()).toBe(false);
        expect(wrapper.find('.mobile-stock-actions [aria-label="Delete"]').exists()).toBe(false);

        const deleteStockButton = wrapper.findAll('button').find((button) => button.text() === 'Delete stock');
        expect(deleteStockButton.attributes('disabled')).toBeDefined();

        await wrapper.findAll('.stock-holding-row')[0].trigger('click');
        await flushPromises();
        expect(deleteStockButton.attributes('disabled')).toBeDefined();

        await wrapper.findAll('.stock-holding-row')[1].trigger('click');
        await flushPromises();
        expect(deleteStockButton.attributes('disabled')).toBeUndefined();
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
                if (isWatchlistHoldingsRequest(path)) {
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
            if (isWatchlistHoldingsRequest(path)) {
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
            if (isWatchlistHoldingsRequest(path)) {
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
            if (isWatchlistHoldingsRequest(path)) {
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
            if (isWatchlistHoldingsRequest(path)) {
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

    it.each([
        '/admin/menu/data',
        '/admin/menu/data/indices/live-data',
        '/admin/menu/data/stocks/intraday-data',
        '/admin/menu/data/health',
        '/admin/menu/data/repair',
    ])('shows the Data overview for %s', async (path) => {
        window.history.pushState({}, '', path);
        const fetchMock = vi.fn((requestPath) => Promise.resolve(jsonResponse(requestPath === '/admin/me'
            ? { user: { id: 1, name: 'Admin', roles: ['super_admin', 'admin'], cloudways_available: true } } : {})));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountApp();
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/data/overview');
        const data = wrapper.get('section[aria-label="Data"]');
        expect(data.findAll('[role="tab"]').map((tab) => tab.text())).toEqual(['Overview', 'Cloudways']);
        expect(wrapper.find('[aria-label="Datenkonsistenz"]').exists()).toBe(true);
        expect(fetchMock.mock.calls.map(([url]) => url).sort()).toEqual(['/admin/cloudways/operation', '/admin/cloudways/status', '/admin/data/performance-consistency', '/admin/depots/active', '/admin/me']);
    });

    it('shows which daily-history gaps were filled and which lack a verified free source', async () => {
        window.history.pushState({}, '', '/admin/menu/data/overview');
        const run = {
            status: 'finished', repair: true, step: 'Prüfung abgeschlossen',
            checked: 0, missing: 0, incorrect: 0, unavailable: 0,
            daily_history: [
                { holding_id: 1, label: 'Aktie A', status: 'missing', expected_count: 250, missing_count: 3,
                    filled_count: 10, from: '2025-11-06', to: '2026-11-06' },
                { holding_id: 2, label: 'Aktie B', status: 'unsupported', expected_count: null },
            ],
        };
        vi.stubGlobal('fetch', vi.fn((path) => {
            if (path === '/admin/me') {
                return Promise.resolve(jsonResponse({ user: { id: 1, roles: ['admin'] } }));
            }
            return Promise.resolve(jsonResponse(path === '/admin/data/performance-consistency' ? { run } : {}));
        }));
        const wrapper = mountApp();
        await flushPromises();

        const report = wrapper.get('[aria-label="Datenkonsistenz"]');
        expect(report.text()).toContain('3 von 250 Handelstagen fehlen');
        expect(report.text()).toContain('10 ergänzt');
        expect(report.text()).toContain('Keine verifizierte Gratisquelle');
        expect(report.text()).toContain('Prüfung mit Hinweisen');
    });

    it.each(['/admin/menu/data/cloudways', '/admin/menu/cloudways'])('opens Cloudways from %s', async (path) => {
        window.history.pushState({}, '', path);
        vi.stubGlobal('fetch', vi.fn((requestPath) => Promise.resolve(jsonResponse(requestPath === '/admin/me'
            ? { user: { id: 1, roles: ['super_admin'], cloudways_available: true } } : {}))));
        const wrapper = mountApp();
        await flushPromises();
        expect(window.location.pathname).toBe('/admin/menu/data/cloudways');
        expect(wrapper.get('[aria-label="Cloudways Synchronisation"]').isVisible()).toBe(true);
        expect(wrapper.find('[aria-label="Datenkonsistenz"]').exists()).toBe(false);
    });

    it('hides Cloudways online and redirects its old link to Data overview', async () => {
        window.history.pushState({}, '', '/admin/menu/data/cloudways');
        const fetchMock = vi.fn((path) => Promise.resolve(jsonResponse(path === '/admin/me'
            ? { user: { id: 1, roles: ['super_admin'], cloudways_available: false } } : {})));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountApp();
        await flushPromises();
        expect(window.location.pathname).toBe('/admin/menu/data/overview');
        expect(wrapper.get('section[aria-label="Data"]').findAll('[role="tab"]').map((tab) => tab.text())).toEqual(['Overview']);
        expect(wrapper.find('[aria-label="Cloudways Synchronisation"]').exists()).toBe(false);
        expect(fetchMock.mock.calls.some(([path]) => path.startsWith('/admin/cloudways/'))).toBe(false);
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

            if (isWatchlistHoldingsRequest(path)) {
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

            if (path === '/admin/dashboard/performance') {
                return Promise.resolve(jsonResponse({ days: [] }));
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
        window.history.pushState({}, '', '/admin/menu/depot/cash');
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

            if (path === '/admin/depot-stocks/actual-year') {
                return Promise.resolve(jsonResponse({
                    period: 'actual-year',
                    label: 'Actual Year',
                    year: 2026,
                    stocks: [
                        {
                            id: 7,
                            symbol: 'AMES',
                            name: 'Amundi IBEX 35',
                            subtitle: 'Accumulating',
                            isin: 'LU1681043599',
                            currency: 'EUR',
                            opening_pieces: '0.00000000',
                            bought_pieces: '10.00000000',
                            sold_pieces: '4.00000000',
                            position_pieces: '6.00000000',
                            traded_volume: '140.00',
                            traded_volume_pieces: '14.00000000',
                            average_buy_or_year_start_price: '10.00000000',
                            average_sell_or_current_price: '11.50000000',
                            change_percent: '15.00',
                            change_amount: '15.00',
                        },
                    ],
                }));
            }

            if (path === '/admin/depot-stocks/last-year') {
                return Promise.resolve(jsonResponse({
                    period: 'last-year',
                    label: 'Last Year',
                    year: 2025,
                    stocks: [
                        {
                            id: 8,
                            symbol: 'AAPL',
                            name: 'Apple',
                            subtitle: 'Technology',
                            currency: 'EUR',
                            position_pieces: '0.00000000',
                            traded_volume: '980.00',
                            traded_volume_pieces: '9.00000000',
                            average_buy_or_year_start_price: '100.00000000',
                            average_sell_or_current_price: '105.00000000',
                            change_percent: '5.00',
                            change_amount: '25.00',
                        },
                    ],
                }));
            }

            if (path === '/admin/depot-stocks/4-ever') {
                return Promise.resolve(jsonResponse({
                    period: '4-ever',
                    label: '4-Ever',
                    year: null,
                    stocks: [
                        {
                            id: 7,
                            symbol: 'AMES',
                            name: 'Amundi IBEX 35',
                            subtitle: 'Accumulating',
                            currency: 'EUR',
                            position_pieces: '6.00000000',
                            traded_volume: '1540.00',
                            traded_volume_pieces: '18.00000000',
                            average_buy_or_year_start_price: '10.00000000',
                            average_sell_or_current_price: '12.00000000',
                            change_percent: '20.00',
                            change_amount: '20.00',
                        },
                    ],
                }));
            }

            if (isWatchlistHoldingsRequest(path)) {
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

        expect(window.location.pathname).toBe('/admin/menu/depot/cash');
        expect(wrapper.text()).toContain('Overview');
        expect(wrapper.text()).toContain('Cash');
        expect(wrapper.text()).toContain('All Stocks');
        expect(wrapper.find('.depot-balance-card').exists()).toBe(false);
        expect(wrapper.vm.activeItemRefreshIntervalMilliseconds).toBe(60_000);
        expect(wrapper.vm.activeItemRefreshTimer).not.toBeNull();

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
        expect(wrapper.text()).toContain('1,250.00');

        const activeDepotRefreshRequestCount = fetchMock.mock.calls
            .filter(([path]) => path === '/admin/depots/active').length;
        await wrapper.vm.refreshActiveItemPage();
        await flushPromises();

        expect(fetchMock.mock.calls
            .filter(([path]) => path === '/admin/depots/active')).toHaveLength(activeDepotRefreshRequestCount + 1);
        expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/depot-transactions')).toHaveLength(3);

        const allStocksTab = wrapper.findAll('[role="tab"]').find((tab) => tab.text().includes('All Stocks'));
        await allStocksTab.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/depot/all-stocks/actual-year');
        expect(wrapper.text()).toContain('Actual Year');
        expect(wrapper.text()).toContain('Last Year');
        expect(wrapper.text()).toContain('4-Ever');
        const actualYearStocks = wrapper.get('[aria-label="Depot stock period performance"]');
        expect(actualYearStocks.text()).toContain('Traded stocks 2026');
        expect(actualYearStocks.text()).toContain('AMES · Amundi IBEX 35');
        expect(actualYearStocks.text()).toContain('Accumulating');
        expect(actualYearStocks.text()).toContain('6.0000 in stock');
        expect(actualYearStocks.text()).toContain('Ø Buy / 1.1.');
        expect(actualYearStocks.text()).toContain('Ø Sell / in stock');
        expect(actualYearStocks.text()).toContain('Traded volume');
        expect(actualYearStocks.text()).toContain('140.00 EUR');
        expect(actualYearStocks.text()).toContain('14.000 pieces');
        expect(actualYearStocks.text()).toContain('+15.00%');
        expect(actualYearStocks.text()).toContain('+15.00');
        expect(fetchMock).toHaveBeenCalledWith('/admin/depot-stocks/actual-year', expect.anything());
        expect(wrapper.find('.depot-balance-card').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('More to come.');
        expect(wrapper.findAll('button').some((button) => button.text().includes('Add cash'))).toBe(false);
        expect(wrapper.findAll('button').some((button) => button.text().includes('Buy stock'))).toBe(false);
        expect(wrapper.findAll('button').some((button) => button.text().includes('Sell stock'))).toBe(false);
        expect(wrapper.vm.activeItemRefreshTimer).toBeNull();

        const lastYearTab = wrapper.findAll('[role="tab"]').find((tab) => tab.text().includes('Last Year'));
        await lastYearTab.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/depot/all-stocks/last-year');
        const lastYearStocks = wrapper.get('[aria-label="Depot stock period performance"]');
        expect(lastYearStocks.text()).toContain('Traded stocks 2025');
        expect(lastYearStocks.text()).toContain('AAPL · Apple');
        expect(lastYearStocks.text()).toContain('980.00 EUR');
        expect(lastYearStocks.text()).toContain('9.0000 pieces');
        expect(fetchMock).toHaveBeenCalledWith('/admin/depot-stocks/last-year', expect.anything());

        const fourEverTab = wrapper.findAll('[role="tab"]').find((tab) => tab.text().includes('4-Ever'));
        await fourEverTab.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/depot/all-stocks/4-ever');
        const fourEverStocks = wrapper.get('[aria-label="Depot stock period performance"]');
        expect(fourEverStocks.text()).toContain('All traded stocks');
        expect(fourEverStocks.text()).toContain('Ø Buy');
        expect(fourEverStocks.text()).toContain('1,540.00 EUR');
        expect(fourEverStocks.text()).toContain('18.000 pieces');
        expect(fetchMock).toHaveBeenCalledWith('/admin/depot-stocks/4-ever', expect.anything());

        wrapper.unmount();
        mountedWrappers.delete(wrapper);
        document.body.innerHTML = '';

        const refreshedWrapper = mountApp();
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/depot/all-stocks/4-ever');
        expect(refreshedWrapper.text()).toContain('Actual Year');
        expect(refreshedWrapper.text()).toContain('Last Year');
        expect(refreshedWrapper.text()).toContain('4-Ever');
        expect(refreshedWrapper.get('[aria-label="Depot stock period performance"]').text()).toContain('All traded stocks');
        expect(refreshedWrapper.find('.depot-balance-card').exists()).toBe(false);
        expect(refreshedWrapper.text()).not.toContain('More to come.');
        expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/depot-transactions')).toHaveLength(3);
    });

    it('books stock transaction from the Stocks page', async () => {
        window.history.pushState({}, '', '/admin/menu/stocks');
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

            if (isWatchlistHoldingsRequest(path)) {
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

    it('moves the cash ledger from Depot Overview to Cash', async () => {
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
                subtitle: 'Technology holding',
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
                previous_day_balance: '1020.00',
                previous_day_external_cash_flow_amount: '10.00',
                previous_day_change_amount: '3.00',
                previous_day_change_percent: '0.29',
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
                previous_day_balance: '1020.00',
                previous_day_external_cash_flow_amount: '10.00',
                previous_day_change_amount: '-20.00',
                previous_day_change_percent: '-1.94',
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
                        previous_day_balance: '1020.00',
                        previous_day_external_cash_flow_amount: '10.00',
                        previous_day_change_amount: '-19.50',
                        previous_day_change_percent: '-1.89',
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

            if (isWatchlistHoldingsRequest(path)) {
                return Promise.resolve(jsonResponse({
                    depot,
                    holdings: [
                        {
                            id: 1,
                            symbol: 'AAPL',
                            name: 'Apple Inc.',
                            currency: 'USD',
                            daily_prices: streakRecommendationDailyPrices(),
                        },
                    ],
                    meta: { current_page: 1, last_page: 1, per_page: 10, total: 1, from: 1, to: 1 },
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

        const transactionRequestIndex = fetchMock.mock.calls
            .findIndex(([path]) => path === '/admin/depot-transactions');
        const holdingsRequestIndex = fetchMock.mock.calls
            .findIndex(([path]) => isWatchlistHoldingsRequest(path));
        expect(transactionRequestIndex).toBeGreaterThanOrEqual(0);
        expect(holdingsRequestIndex).toBeGreaterThan(transactionRequestIndex);

        expect(wrapper.text()).toContain('Depot stocks');
        expect(wrapper.text()).toContain('Depot');
        expect(wrapper.text()).toContain('Depot balance');
        expect(wrapper.text()).toContain('383.00 EUR');
        expect(wrapper.text()).toContain('Cash balance');
        expect(wrapper.text()).toContain('650.00 EUR');
        expect(wrapper.text()).toContain('Account balance');
        expect(wrapper.text()).toContain('1,033.00 EUR');
        expect(wrapper.find('.depot-account-yesterday-row').text()).toContain('Account Yesterday');
        expect(wrapper.find('.depot-account-yesterday-row').text()).toContain('1,020.00 EUR');
        expect(wrapper.find('.depot-account-external-cash-flow-row').text()).toContain('External cash flow today');
        expect(wrapper.find('.depot-account-external-cash-flow-row').text()).toContain('+10.00 EUR');
        expect(wrapper.find('.depot-account-yesterday-change-row').text()).toContain('Performance +/-');
        expect(wrapper.find('.depot-account-yesterday-change-row').text()).toContain('+0.29% · +3.00 EUR');
        expect(wrapper.find('.depot-account-yesterday-change-row .text-success').exists()).toBe(true);
        expect(wrapper.text()).not.toContain('Status');
        expect(wrapper.text()).not.toContain('Active');
        expect(wrapper.text()).toContain('Aktuelles Jahr');
        expect(wrapper.text()).toContain('Balance 01.01.');
        expect(wrapper.text()).toContain('1,000.00 EUR');
        expect(wrapper.text()).toContain(`Balance ${sessionHeaderDate(0).slice(0, 6)}`);
        expect(wrapper.text()).toContain('+3.30% · +33.00 EUR');
        expect(wrapper.text()).not.toContain('Kest - 27,5%');
        expect(wrapper.text()).toContain('Corrected balance (-27,5%)');
        expect(wrapper.text()).toContain('1,023.92 EUR');
        expect(wrapper.text()).toContain('Corrected +/-');
        expect(wrapper.text()).toContain('+2.39% · +23.92 EUR');
        expect(wrapper.text()).toContain('Aktuelle Woche');
        expect(wrapper.text()).toContain(`Balance ${previousWeekEndDate().slice(0, 6)}`);
        expect(wrapper.text()).toContain('990.00 EUR');
        expect(wrapper.text()).toContain('1 week');
        expect(wrapper.text()).toContain('+4.34% · +43.00 EUR');
        expect(wrapper.text()).toContain('Aktueller Monat');
        expect(wrapper.text()).toContain(`Balance ${previousMonthEndDate().slice(0, 6)}`);
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
        expect(wrapper.text()).not.toContain('BUY/SELL');
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
        const desktopDepotStockTable = wrapper.get('.desktop-depot-stocks-table');
        const desktopDepotHeaders = desktopDepotStockTable.findAll('thead th').map((header) => header.text());
        expect(desktopDepotHeaders.slice(0, 4)).toEqual(['Symbol', 'Name', 'Amount', 'Value']);
        expect(desktopDepotStockTable.find('tbody .stock-trend-signal').exists()).toBe(false);
        const desktopDepotStockSubtitle = wrapper.get('.desktop-depot-stocks-table .stock-subtitle');
        expect(desktopDepotStockSubtitle.text()).toBe('Technology holding');
        expect(desktopDepotStockSubtitle.classes()).toContain('text-info');
        expect(desktopDepotStockSubtitle.classes()).toContain('font-weight-medium');
        const mobileDepotStockCards = wrapper.findAll('.mobile-depot-stock-card');
        expect(mobileDepotStockCards).toHaveLength(1);
        expect(mobileDepotStockCards[0].find('.mobile-depot-stock-name').text()).toContain('Apple Inc.');
        expect(mobileDepotStockCards[0].get('.stock-subtitle').text()).toBe('Technology holding');
        expect(mobileDepotStockCards[0].get('.stock-subtitle').classes()).toContain('text-info');
        expect(mobileDepotStockCards[0].get('.stock-subtitle').classes()).toContain('font-weight-medium');
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
        expect(compactDepotHeaders).not.toContain('BUY/SELL');
        expect(compactDepotHeaders).toContain('Amount');
        expect(compactDepotHeaders).toContain('Value');
        expect(compactDepotHeaders).toContain('Latest price');
        expect(compactDepotHeaders).toContain('Prev Day');
        expect(compactDepotHeaders).toContain('+/- %');
        expect(compactDepotHeaders).toContain('Flatex price');
        expect(compactDepotHeaders).toContain('Change');
        expect(compactDepotHeaders).toContain('+/- EUR');
        expect(compactDepotHeaders).toContain('Actions');
        expect(wrapper.text()).toContain('Sum');
        expect(wrapper.find('.cash-ledger-section').exists()).toBe(false);

        const cashTab = wrapper.findAll('[role="tab"]').find((tab) => tab.text() === 'Cash');
        await cashTab.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/depot/cash');
        expect(wrapper.find('.depot-balance-card').exists()).toBe(false);
        expect(wrapper.find('.depot-performance-card').exists()).toBe(false);
        expect(wrapper.find('.cash-balance-card').text()).toContain('Current cash balance');
        expect(wrapper.find('.cash-balance-card').text()).toContain('650.00 EUR');
        expect(wrapper.find('.cash-ledger-section').exists()).toBe(true);
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

        const overviewTab = wrapper.findAll('[role="tab"]').find((tab) => tab.text() === 'Overview');
        await overviewTab.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/depot/overview');
        expect(wrapper.find('.cash-ledger-section').exists()).toBe(false);
        expect(wrapper.find('.depot-balance-card').exists()).toBe(true);

        const restoredLatestPriceHeaderButton = wrapper.findAll('button').find((button) => button.text() === 'Latest price');
        const restoredFlatexPriceHeaderButton = wrapper.findAll('button').find((button) => button.text() === 'Flatex price');
        await restoredFlatexPriceHeaderButton.trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/admin/ui-preferences', expect.objectContaining({
            method: 'PATCH',
            body: JSON.stringify({ depot_price_source: 'flatex' }),
        }));
        expect(restoredLatestPriceHeaderButton.classes()).not.toContain('depot-price-source-button--active');
        expect(restoredFlatexPriceHeaderButton.classes()).toContain('depot-price-source-button--active');
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

    it('shows the cash ledger on the Cash page and paginates it by twenty rows', async () => {
        window.history.pushState({}, '', '/admin/menu/depot/cash');
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

            if (isWatchlistHoldingsRequest(path)) {
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

        const cashLedgerSection = wrapper.find('.cash-ledger-section');
        const cashLedgerRows = wrapper.findAll('.desktop-cash-ledger-table tbody tr');
        const firstPageLedgerText = wrapper.find('.desktop-cash-ledger-table tbody').text();

        expect(window.location.pathname).toBe('/admin/menu/depot/cash');
        expect(wrapper.find('.depot-performance-card').exists()).toBe(false);
        expect(cashLedgerSection.exists()).toBe(true);
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

            if (isWatchlistHoldingsRequest(path)) {
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

            if (isWatchlistHoldingsRequest(path)) {
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

            if (path === '/admin/dashboard/performance') {
                return Promise.resolve(jsonResponse({ days: [] }));
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

            expect(fetchMock.mock.calls.some(([path]) => path.startsWith('/admin/watchlist/holdings'))).toBe(false);
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

            if (isWatchlistHoldingsRequest(path)) {
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

            if (path === '/admin/dashboard/performance') {
                return Promise.resolve(jsonResponse({ days: [] }));
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

    it('does not load queue status or the watch-list on the dashboard', async () => {
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

            if (path === '/admin/dashboard/performance') {
                return Promise.resolve(jsonResponse({ days: [] }));
            }

            return Promise.reject(new Error(`Unexpected request: ${path}`));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();
        await flushPromises();

        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/queue/status')).toBe(false);
        expect(wrapper.find('.dashboard-status-card').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('The request failed.');
        expect(wrapper.find('[aria-label="Programmversion"]').exists()).toBe(true);
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

            if (isWatchlistHoldingsRequest(path)) {
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

            if (path === '/admin/dashboard/performance') {
                return Promise.resolve(jsonResponse({ days: [] }));
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

            if (isWatchlistHoldingsRequest(path)) {
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

            if (path === '/admin/dashboard/performance') {
                return Promise.resolve(jsonResponse({ days: [] }));
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

    it('shows EODHD table and Laravel method information on the Infos subpages', async () => {
        window.history.pushState({}, '', '/admin/menu/infos/eodhd');

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

            if (isWatchlistHoldingsRequest(path)) {
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

            if (path === '/admin/depots?page=1') {
                return Promise.resolve(jsonResponse({
                    depots: [],
                    meta: emptyPagination,
                }));
            }

            if (path === '/admin/infos') {
                return Promise.resolve(jsonResponse({
                    tables: [
                        {
                            name: 'stock_realtime_prices',
                            category: 'Market data',
                            purpose: 'Canonical live stock prices.',
                            purpose_de: 'Kanonische Live-Aktienkurse.',
                            eodhd: {
                                mode: 'direct',
                                endpoint: 'real-time/{symbol}',
                                access: 'Direct EODHD responses.',
                                documentation: [
                                    {
                                        label: 'Live prices',
                                        url: 'https://eodhd.com/financial-apis/live-ohlcv-stocks-api',
                                    },
                                ],
                            },
                            cadence: 'Every 20 min during trading',
                            queue: {
                                used: true,
                                name: 'default',
                                job: 'App\\Jobs\\RefreshDepotHoldingPrices',
                                mode: 'Dispatched by the scheduler.',
                            },
                        },
                        {
                            name: 'stock_prices',
                            category: 'Market data',
                            purpose: 'Canonical end-of-day prices.',
                            purpose_de: 'Kanonische Schlusskurse.',
                            eodhd: {
                                mode: 'direct',
                                endpoint: 'eod/{symbol.exchange}',
                                access: 'Direct EODHD responses.',
                                documentation: [
                                    {
                                        label: 'EOD prices',
                                        url: 'https://eodhd.com/financial-apis/api-for-historical-data-and-volumes',
                                    },
                                ],
                            },
                            cadence: 'Weekdays at 18:30',
                            queue: {
                                used: false,
                                name: null,
                                job: null,
                                mode: 'Synchronous scheduled command or manual action.',
                            },
                        },
                        {
                            name: 'index_watch_items',
                            category: 'Index live data',
                            purpose: 'Watched index metadata and latest live values.',
                            purpose_de: 'Metadaten und aktuelle Live-Werte für beobachtete Indizes.',
                            eodhd: {
                                mode: 'direct',
                                endpoint: 'real-time/{index-symbol}',
                                access: 'Direct EODHD responses.',
                                documentation: [
                                    {
                                        label: 'Live prices',
                                        url: 'https://eodhd.com/financial-apis/live-ohlcv-stocks-api',
                                    },
                                ],
                            },
                            cadence: 'Every 20 min during trading',
                            queue: {
                                used: false,
                                name: null,
                                job: null,
                                mode: 'Synchronous scheduled command or manual action.',
                            },
                        },
                    ],
                    methods: [
                        {
                            key: 'stock-realtime',
                            title: 'Aktien-Livekurse',
                            access: 'real-time/{symbol} + s={additional-symbols}',
                            description: 'Lädt Aktienkurse in Batches und aktualisiert die Holdings.',
                            triggers: [
                                'Scheduler jede Minute: price-refresh:dispatch-due',
                                'HTTP POST /admin/data/realtime/sync',
                            ],
                            execution: {
                                mode: 'mixed',
                                scheduled: true,
                                queue: 'default',
                                description: 'Automatisch queued; manuell synchron.',
                            },
                            laravel_methods: [
                                {
                                    layer: 'Service',
                                    class: 'App\\Services\\EodhdBatchRealtimePriceService',
                                    method: 'syncAll',
                                },
                                {
                                    layer: 'EODHD client',
                                    class: 'App\\Services\\EodhdApiClient',
                                    method: 'get',
                                },
                            ],
                            tables: ['stock_realtime_prices', 'stock_holdings'],
                            note: 'Der Scheduler prüft jede Minute, ruft EODHD aber nur bei Fälligkeit auf.',
                        },
                        {
                            key: 'index-realtime',
                            title: 'Index-Livekurse',
                            access: 'real-time/{index-symbol}',
                            description: 'Aktualisiert beobachtete Indizes.',
                            triggers: ['HTTP POST /admin/data/indices/sync'],
                            execution: {
                                mode: 'synchronous',
                                scheduled: false,
                                queue: null,
                                description: 'Manuell und synchron.',
                            },
                            laravel_methods: [
                                {
                                    layer: 'Service',
                                    class: 'App\\Services\\IndexWatchItemPriceRefresher',
                                    method: 'refreshAll',
                                },
                            ],
                            tables: ['index_watch_items', 'index_watch_item_prices'],
                            note: 'Kein automatischer Index-Live-Job ist registriert.',
                        },
                    ],
                }));
            }

            return Promise.reject(new Error(`Unexpected request: ${path}`));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountApp();
        await flushPromises();

        const infoPage = wrapper.get('[aria-label="Infos"]');
        expect(window.location.pathname).toBe('/admin/menu/infos/eodhd');
        expect(infoPage.text()).toContain('EODHD');
        expect(infoPage.text()).toContain('Methoden');
        expect(infoPage.find('[aria-label="Infos EODHD"]').exists()).toBe(true);
        expect(infoPage.text()).toContain('Stock & index data flows');
        expect(infoPage.text()).toContain('Active GKStocks market-data tables');
        expect(infoPage.text()).toContain('Purpose / Zweck');
        expect(infoPage.text()).toContain('Kanonische Live-Aktienkurse.');
        const documentationLinks = infoPage.findAll('a.info-documentation-link');
        expect(documentationLinks).toHaveLength(3);
        expect(documentationLinks[0].attributes('href')).toBe('https://eodhd.com/financial-apis/live-ohlcv-stocks-api');
        expect(documentationLinks[0].attributes('target')).toBe('_blank');
        expect(documentationLinks[0].attributes('rel')).toBe('noopener noreferrer');
        expect(infoPage.text()).toContain('stock_realtime_prices');
        expect(infoPage.text()).toContain('real-time/{symbol}');
        expect(infoPage.text()).toContain('Queued · default');
        expect(infoPage.text()).toContain('Synchronous');
        expect(infoPage.findAll('tbody tr')).toHaveLength(3);
        expect(fetchMock.mock.calls.some(([path]) => path === '/admin/infos')).toBe(true);

        const infoRequestCount = fetchMock.mock.calls.filter(([path]) => path === '/admin/infos').length;
        const methodsTab = infoPage.findAll('[role="tab"]').find((tab) => tab.text().includes('Methoden'));
        await methodsTab.trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/admin/menu/infos/methoden');
        expect(infoPage.find('[aria-label="Infos Methoden"]').exists()).toBe(true);
        expect(infoPage.find('[aria-label="Infos EODHD"]').exists()).toBe(false);
        expect(infoPage.text()).toContain('EODHD in Laravel');
        expect(infoPage.text()).toContain('Aktien-Livekurse');
        expect(infoPage.text()).toContain('EodhdBatchRealtimePriceService::syncAll()');
        expect(infoPage.text()).toContain('Scheduler jede Minute: price-refresh:dispatch-due');
        expect(infoPage.text()).toContain('stock_realtime_prices');
        expect(infoPage.text()).toContain('Kein automatischer Index-Live-Job ist registriert.');
        expect(infoPage.findAll('.info-method-card')).toHaveLength(2);
        expect(fetchMock.mock.calls.filter(([path]) => path === '/admin/infos')).toHaveLength(infoRequestCount);
    });
});
