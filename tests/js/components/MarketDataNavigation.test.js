import { flushPromises, mount } from '@vue/test-utils';
import { createPinia } from 'pinia';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../../../resources/js/App.vue';
import Homepage from '../../../resources/js/Homepage.vue';
import { createStocksVuetify } from '../../../resources/js/plugins/vuetify';
import { provenanceResponse } from './marketDataFixtures';

let wrapper;

afterEach(() => {
    wrapper?.unmount();
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

async function renderPage(path, width = 1440, transformResponse = (response) => response) {
    Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: width });
    Object.defineProperty(window.visualViewport, 'width', { configurable: true, writable: true, value: width });
    window.history.replaceState({}, '', path);
    vi.stubGlobal('fetch', vi.fn((requestPath) => Promise.resolve({
        ok: true, json: () => Promise.resolve(transformResponse(structuredClone(provenanceResponse(requestPath)), requestPath)),
    })));
    wrapper = mount(App, { attachTo: document.body, global: { plugins: [createPinia(), createStocksVuetify()] } });
    await flushPromises();
    await flushPromises();

    return wrapper;
}

function markers(element) {
    return element.findAll('.data-source-marker').map((marker) => marker.text());
}

describe('visible market data provenance across navigation', () => {
    it.each([1440, 390])('restores the cashflow-adjusted curve and shows actual account balances separately at width %i', async (width) => {
        await renderPage('/admin/menu/depot/overview', width, (response, path) => {
            if (path === '/admin/depot-transactions') {
                response.depot_performance_series = [
                    { date: '2026-01-01', account_balance: '1000', performance_balance: '1300', net_external_cash_flow: '0', source_markers: ['EODHD'] },
                    { date: '2026-11-01', account_balance: '1000', performance_balance: '1300', net_external_cash_flow: '0', source_markers: ['EODHD'] },
                    { date: '2026-11-02', account_balance: '1500', performance_balance: '1300', net_external_cash_flow: '500', source_markers: ['EODHD'] },
                    { date: '2026-11-03', account_balance: '1300', performance_balance: '1300', net_external_cash_flow: '300', source_markers: ['EODHD'] },
                    { date: '2026-11-04', account_balance: '1350', performance_balance: '1350', net_external_cash_flow: '300', source_markers: ['free'] },
                ];
            }

            return response;
        });
        const card = wrapper.get('.depot-performance-card');
        expect(card.text()).toContain('01.01.2026 1,300.00 EUR');
        expect(card.text()).toContain('04.11.2026 1,350.00 EUR');
        expect(card.get('[aria-label="Actual depot account balances"]').text()).toContain('01.01.2026 1,000.00 EUR');
        expect(card.text()).toContain('+3.85% · +50.00 EUR');
        expect(markers(card.get('.font-weight-bold'))).toEqual(['EODHD']);
        expect(card.find('.index-price-chart-trend-line').exists()).toBe(false);
        expect(card.find('[aria-label="Depot performance calculation"]').exists()).toBe(false);
        expect(card.text()).toContain('High 1,350.00 EUR');
        expect(card.text()).toContain('Low 1,300.00 EUR');
        const points = card.findAll('.depot-performance-source-point');
        expect(new Set(points.slice(0, 4).map((point) => point.attributes('cy'))).size).toBe(1);
        expect(points[4].attributes('cy')).not.toBe(points[0].attributes('cy'));
    });

    it.each([500, -200])('does not draw a gain or loss for an external cashflow of %i', async (cashflow) => {
        await renderPage('/admin/menu/depot/overview', 1440, (response, path) => {
            if (path === '/admin/depot-transactions') {
                response.depot_performance_series = [
                    { date: '2026-01-01', account_balance: '1000', net_external_cash_flow: '0', source_markers: [] },
                    { date: '2026-01-02', account_balance: String(1000 + cashflow), net_external_cash_flow: String(cashflow), source_markers: [] },
                ];
            }

            return response;
        });
        const card = wrapper.get('.depot-performance-card');
        expect(card.get('.font-weight-bold').text()).toBe('0.00% · 0.00 EUR');
        const coordinates = card.get('.depot-performance-chart-line').attributes('points').split(' ');
        expect(coordinates[0].split(',')[1]).toBe(coordinates[1].split(',')[1]);
    });

    it.each(['opening', 'latest'])('does not substitute another observed endpoint when the %s valuation is missing', async (missing) => {
        await renderPage('/admin/menu/depot/overview', 1440, (response, path) => {
            if (path === '/admin/depot-transactions') {
                response.depot_performance_series = [
                    { date: '2026-01-01', account_balance: missing === 'opening' ? null : '1000', source_markers: ['EODHD'] },
                    { date: '2026-11-03', account_balance: '1100', source_markers: ['free'] },
                    { date: '2026-11-04', account_balance: missing === 'latest' ? null : '1200', source_markers: ['free'] },
                ];
            }

            return response;
        });
        const card = wrapper.get('.depot-performance-card');
        expect(card.get('.font-weight-bold').text()).toBe('- · - EUR');
        expect(markers(card.get('.font-weight-bold'))).toEqual([]);
        expect(card.get('[aria-label="Missing depot performance baseline"]').exists()).toBe(true);
    });

    it.each([1440, 390])('keeps missing references empty and breaks performance lines at gaps at width %i', async (width) => {
        await renderPage('/admin/menu/depot/overview', width, (response, path) => {
            if (path === '/admin/depot-transactions') {
                Object.assign(response.depot_holdings[0], {
                    previous_day_price: null, previous_day_change_percent: null, day_change_amount_eur: null,
                    previous_day_price_source_markers: [], previous_day_change_percent_source_markers: [],
                    day_change_amount_eur_source_markers: [],
                });
                for (const valuation of Object.values(response.depot_valuations)) {
                    Object.assign(valuation, {
                        previous_day_balance: null, day_change_amount_eur: null, previous_day_change_percent: null,
                        previous_day_balance_source_markers: [], day_change_amount_eur_source_markers: [],
                    });
                }
                response.depot_performance_series = [
                    { date: '2026-11-01', account_balance: '1000', source_markers: ['free'] },
                    { date: '2026-11-02', account_balance: null, source_markers: [] },
                    { date: '2026-11-03', account_balance: '1112', source_markers: ['free'] },
                ];
            }

            return response;
        });

        expect(wrapper.text()).toContain('- EUR');
        expect(wrapper.text()).not.toContain('NaN');
        expect(wrapper.get('.depot-performance-chart').findAll('.depot-performance-source-point')).toHaveLength(2);
        expect(wrapper.get('.depot-performance-chart').findAll('.depot-performance-chart-line')).toHaveLength(2);
    });

    it('offers the individual free reload when prices are missing', async () => {
        await renderPage('/admin/menu/stocks', 1440, (response, path) => {
            if (path.startsWith('/admin/watchlist/holdings') && response.holdings) {
                Object.assign(response.holdings[0], { latest_price: null, price_reload_available: true });
            }

            return response;
        });
        const watchList = wrapper.get('.desktop-watch-list-table');
        expect(watchList.get('[aria-label="Reload DEMO price"]').attributes('disabled')).toBeUndefined();
        expect(watchList.text()).not.toContain('EODHD Sync');
    });

    it.each([320, 1440])('prioritizes the depot day change at width %i', async (width) => {
        const longName = 'International Sustainable Developed Markets Small-Cap Dividend Opportunities Fund';
        const quoteLabels = ['Latest Price', 'Previous Day', 'Day +/- €'];
        await renderPage('/admin/menu/depot/overview', width, (response, path) => {
            if (path === '/admin/depot-transactions') {
                const [holding] = response.depot_holdings;
                response.depot_holdings = [
                    {
                        ...holding, name: longName, latest_price: '12.40', previous_day_price: '1000',
                        previous_day_change_percent: '-98.76', day_change_amount_eur: '-1975.20',
                    },
                    { ...holding, id: 2, latest_price: '54', previous_day_change_percent: '0', day_change_amount_eur: '0' },
                    {
                        ...holding, id: 3, previous_day_price: null, previous_day_change_percent: null,
                        day_change_amount_eur: null, day_change_amount_eur_source_markers: [],
                    },
                    {
                        ...holding, id: 4, latest_price: '193', previous_day_price: '187.30',
                        day_reference_type: 'buy', day_buy_price: '194.85833333',
                        previous_day_change_percent: '-0.95', day_change_amount_eur: '-55.75', position_pieces: '30',
                        day_change_amount_eur_source_markers: ['free'],
                    },
                ];
            }

            return response;
        });

        const desktop = wrapper.get('.desktop-depot-stocks-table');
        expect(desktop.findAll('thead th').map((header) => header.text())).toEqual([
            'Symbol', 'Name', 'Day +/- %', ...quoteLabels, 'Amount', 'Value', 'Buy / 1.1. €', 'Change %', 'Buy / 1.1. +/- €', 'Actions',
        ]);
        const percentages = ['-98.76%', '0.00%', '-', '-0.95%'];
        expect(desktop.findAll('.depot-stock-day-change').map((change) => change.text())).toEqual(percentages);
        const amounts = ['-1,975.20 EODHD', '0.00 EODHD', '-', '-55.75'];
        const rows = desktop.findAll('tbody tr');
        rows.forEach((row, index) => {
            expect(row.findAll('td')[5].text()).toBe(amounts[index]);
        });
        const firstCells = rows[0].findAll('td');
        expect(firstCells[3].text()).toBe('12.400 EUR');
        expect(firstCells[7].text()).toBe('24.800 EUR');
        expect(firstCells[8].text()).toBe('50.000');
        expect(firstCells[10].text()).toBe('-75.20');
        expect(rows[3].findAll('td')[4].text()).toContain('194.86 EUR');
        expect(rows[3].findAll('td')[4].text()).toContain('Buy Price');
        const cards = wrapper.findAll('.mobile-depot-stock-card');
        const heading = cards[0].get('.mobile-depot-stock-heading');
        expect(heading.get('.mobile-depot-stock-name').text()).toContain(`DEMO · ${longName}`);
        expect(heading.get('.depot-stock-day-change').classes()).toContain('text-error');
        expect(cards[0].text().match(/-98\.76%/g)).toHaveLength(1);
        expect(cards.map((card) => card.get('.depot-stock-day-change').text())).toEqual(percentages);
        cards.forEach((card, index) => {
            const quotes = card.get('.mobile-depot-stock-quotes');
            expect(quotes.findAll('dt').map((label) => label.text()))
                .toEqual(index === 3 ? ['Latest Price', 'Buy Price', 'Day +/- €'] : quoteLabels);
            expect(quotes.findAll('dd')[2].text()).toBe(amounts[index]);
        });
        const position = cards[0].get('.mobile-depot-stock-position');
        expect(position.findAll('dt').map((label) => label.text()))
            .toEqual(['Amount', 'Value', 'Buy / 1.1. €', 'Since Buy / 1.1. (%)', 'Since Buy / 1.1. (€)']);
        expect(position.findAll('dd').map((value) => value.text()))
            .toEqual(['2', '24.800 EUR', '50.000', '↓ -75.20%', '-75.20']);
        expect(cards[3].get('.mobile-depot-stock-day').text()).toContain('vs. Buy');
        expect(cards[3].findAll('.mobile-depot-stock-quotes dd')[1].text()).toBe('194.86 EUR');

        const drawer = wrapper.get('.dashboard-navigation-drawer');
        const menuLabels = width < 600
            ? drawer.findAll('.dashboard-compact-menu-item').map((item) => item.attributes('aria-label'))
            : drawer.findAll('.v-list-item-title').map((item) => item.text());
        expect(menuLabels.slice(0, 2)).toEqual(['Dashboard', 'Depot']);
    });

    it.each([1440, 320])('renders depot values, the signed performance and sources at width %i', async (width) => {
        const gain = width === 1440;
        await renderPage('/admin/menu/depot/overview', width, (response, path) => {
            if (path === '/admin/depot-transactions') {
                Object.assign(response.depot_valuations.latest.account_overview, {
                    stock_balance: '987654.32', cash_balance: '246913.57', account_balance: '1234567.89',
                    previous_day_balance: '1234500.00', change_amount: gain ? '67.89' : '-12345.67',
                    change_amount_source_markers: ['free', 'EODHD'],
                });
                response.depot_valuations.latest.completed_periods = {
                    week: {
                        opening_date: '2026-12-27', closing_date: '2027-01-03',
                        opening_balance: '1000.00', closing_balance: '960.00', change_amount: '-40.00',
                        change_percent: '-4.00', source_markers: ['EODHD'],
                    },
                    month: {
                        opening_date: '2026-11-30', start_date: '2026-12-01', closing_date: '2026-12-31',
                        opening_balance: '900.00', closing_balance: gain ? '1000.00' : null,
                        change_amount: gain ? '0.00' : null, change_percent: gain ? '0.00' : null, source_markers: ['EODHD'],
                    },
                };
            }

            return response;
        });
        const rows = wrapper.get('.depot-balance-summary').findAll('.depot-balance-card');
        expect(rows.map((row) => row.get('h2').text()))
            .toEqual(['Depot', 'Aktuelle Woche', 'Vorwoche', 'Aktueller Monat', 'Vormonat', 'Aktuelles Jahr']);
        rows.forEach((row) => {
            expect(row.findAll('.depot-balance-current, .depot-balance-reference, .depot-balance-result')
                .map((metric) => metric.classes().find((name) => name !== 'depot-balance-performance')))
                .toEqual(['depot-balance-reference', 'depot-balance-current', 'depot-balance-result']);
        });
        const [day, week, lastWeek, month, lastMonth, year] = rows;
        expect(day.findAll('dt').map((label) => label.text()))
            .toEqual(['Depot Balance', 'Cash Balance', 'Account Yesterday', 'Account Balance', 'Performance +/-']);
        expect(day.get('.depot-balance-current dd').text()).toBe('1,234,567.89 EUR');
        expect(day.get('.depot-balance-reference dd').attributes('title')).toBe('03.11.2026');
        const performance = day.get('.depot-balance-performance dd');
        expect(performance.text()).toContain(gain ? '+67.89 EUR' : '-12,345.67 EUR');
        expect(performance.classes()).toContain(gain ? 'text-success' : 'text-error');
        expect(performance.find('.data-source-marker').exists()).toBe(false);
        expect(wrapper.get('.depot-balance-summary').text()).not.toContain('EODHD');
        expect(year.findAll('.depot-balance-adjustment dt').map((label) => label.text()))
            .toEqual(['Stand nach KESt-Schätzung', 'Ergebnis nach KESt-Schätzung']);
        expect(week.get('.depot-balance-reference dd').text()).toBe('1,100.00 EUR');
        expect(week.get('.depot-balance-result dd').text()).toBe('+1.09% · +12.00 EUR');
        expect(month.get('.depot-balance-reference dd').text()).toBe('1,100.00 EUR');
        expect(month.get('.depot-balance-result dd').text()).toBe('+1.09% · +12.00 EUR');
        expect(lastWeek.findAll('dt').map((label) => label.text())).toEqual(['Balance 27.12.', 'Balance 03.01.', 'Performance +/-']);
        expect(lastWeek.findAll('dt').slice(0, 2).map((label) => label.attributes('title'))).toEqual(['27.12.2026', '03.01.2027']);
        expect(lastWeek.findAll('dd').map((value) => value.text())).toEqual(['1,000.00 EUR', '960.00 EUR', '-4.00% · -40.00 EUR']);
        expect(lastWeek.get('.depot-balance-result dd').classes()).toContain('text-error');
        expect(lastMonth.findAll('dt').map((label) => label.text())).toEqual(['Balance 01.12.', 'Balance 31.12.', 'Performance +/-']);
        expect(lastMonth.get('.depot-balance-reference dt').attributes('title')).toBe('30.11.2026');
        expect(lastMonth.findAll('dd').map((value) => value.text()))
            .toEqual(['900.00 EUR', gain ? '1,000.00 EUR' : '-', gain ? '0.00% · 0.00 EUR' : '-']);
        expect(lastMonth.get('.depot-balance-result dd').classes()).toContain('text-medium-emphasis');

        const desktop = wrapper.get('.desktop-depot-stocks-table tbody tr');
        const cells = desktop.findAll('td');
        const priceCell = cells.find((cell) => cell.text().includes('56.00'));
        const previousCell = cells.find((cell) => cell.text().includes('54.00'));
        expect(priceCell.find('.data-source-markers').exists()).toBe(false);
        expect(markers(previousCell)).toEqual(['EODHD']);
        expect(markers(desktop)).not.toContain('free');
        expect(markers(wrapper.get('.mobile-depot-stock-card'))).toEqual(['EODHD', 'EODHD', 'EODHD', 'EODHD']);
        expect(cells[5].text()).toBe('+4.00 EODHD');
        expect(markers(cells[5])).toEqual(['EODHD']);
        expect(wrapper.find('.mobile-depot-price-source').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Flatex price');
        const cashRow = wrapper.get('[data-account-metric="cash_balance"]');
        expect(markers(cashRow)).toEqual([]);
        const chart = wrapper.get('.depot-performance-chart');
        expect(chart.findAll('title').some((title) => title.text().includes('EODHD'))).toBe(true);
        expect(chart.text()).not.toContain('free');
        expect(chart.findAll('title').some((title) => title.text().startsWith('·'))).toBe(false);
        expect(chart.find('span').exists()).toBe(false);
        expect(chart.get('polyline').element.compareDocumentPosition(chart.get('.depot-performance-source-point').element)
            & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        const balanceRow = wrapper.get('[data-account-metric="stock_balance"]');
        expect(markers(balanceRow)).toEqual([]);
        expect(balanceRow.text()).toContain('987,654.32 EUR');
    });

    it.each([
        ['0.00', '2,000.00 EUR', '+11.11% · +200.00 EUR'],
        [null, '-', '-'],
    ])('distinguishes a taxable stock gain of %s from an unavailable estimate', async (taxableStockGain, balance, result) => {
        await renderPage('/admin/menu/depot/overview', 390, (response, path) => {
            if (path === '/admin/depot-transactions') {
                Object.assign(response.depot_valuations.latest, {
                    year_start_balance: '0.00', year_net_capital: '1800.00', current_balance: '2000.00',
                    balance_change_amount: '200.00', balance_change_percent: '11.11',
                    taxable_stock_gain_amount: taxableStockGain,
                });
            }

            return response;
        });

        const year = wrapper.findAll('.depot-balance-card').at(-1);
        expect(year.get('.depot-balance-reference dd').text()).toBe('1,800.00 EUR');
        expect(year.get('.depot-balance-result dd').text()).toBe('+11.11% · +200.00 EUR');
        expect(year.findAll('.depot-balance-adjustment dd').map((value) => value.text())).toEqual([balance, result]);
    });

    it.each([
        ['0.00', '0.00 EUR'],
        [null, '-'],
    ])('preserves a year comparison basis of %s', async (netCapital, displayed) => {
        await renderPage('/admin/menu/depot/overview', 390, (response, path) => {
            if (path === '/admin/depot-transactions') {
                Object.assign(response.depot_valuations.latest, {
                    year_start_balance: '1200.00', year_net_capital: netCapital,
                    balance_change_amount: null, balance_change_percent: null, taxable_stock_gain_amount: null,
                });
            }

            return response;
        });

        const year = wrapper.findAll('.depot-balance-card').at(-1);
        expect(year.get('.depot-balance-reference dt').text()).toBe('Basis 01.01. bereinigt');
        expect(year.get('.depot-balance-reference dd').text()).toBe(displayed);
        expect(year.get('.depot-balance-result dd').text()).toBe('-');
    });

    it.each([
        ['2026-10-31T23:30:00Z', '01.11.', '31.10.'],
        ['2026-12-31T23:30:00Z', '01.01.', '31.12.'],
    ])('labels the month by its Vienna start date at %s', async (now, firstDay, valuationDay) => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date(now));
        await renderPage('/admin/menu/depot/overview', 390);

        const month = wrapper.findAll('.depot-balance-card')[3];
        const reference = month.get('.depot-balance-reference');
        expect(reference.get('dt').text()).toBe(`Balance ${firstDay}`);
        expect(reference.get('dt').attributes('title')).toBe(`Stand am Vormonatsende ${valuationDay}`);
        expect(reference.get('dd').text()).toBe('1,100.00 EUR');
    });

    it.each([1440, 390])('renders period prices and leaves traded volume manual at width %i', async (width) => {
        await renderPage('/admin/menu/depot/all-stocks/actual-year', width);
        const cells = wrapper.get('.desktop-depot-year-stocks-table tbody tr').findAll('td');
        expect(markers(cells[1])).toEqual(['EODHD']);
        expect(markers(cells[2])).toEqual([]);
        expect(markers(cells[3])).toEqual([]);
        expect(markers(cells[5])).toEqual(['EODHD']);
        expect(markers(wrapper.get('.mobile-depot-year-stock-card'))).not.toContain('free');
        expect(markers(wrapper.get('.mobile-depot-year-stock-card'))).toContain('EODHD');
    });

    it.each([
        ['/admin/menu/stocks', '.watch-list-section', 'EODHD'],
        ['/admin/menu/indices', '.index-watch-card', 'EODHD'],
        ['/admin/menu/dashboard/overview', '.dashboard-performance-day-card', 'free'],
    ])('renders provenance in %s', async (path, selector, expected) => {
        await renderPage(path);
        expect(markers(wrapper.get(selector))).toContain(expected);
        expect(wrapper.find('svg .data-source-markers').exists()).toBe(false);
    });

    it('renders the public index ticker using the same marker component', async () => {
        vi.stubGlobal('fetch', vi.fn((path) => Promise.resolve({ ok: true, json: () => Promise.resolve(provenanceResponse(path)) })));
        wrapper = mount(Homepage, { attachTo: document.body });
        await flushPromises();
        expect(markers(wrapper.get('.ticker-item'))).toEqual(['EODHD']);
    });
});
