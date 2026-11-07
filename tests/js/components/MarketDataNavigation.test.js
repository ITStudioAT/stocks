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
        expect(markers(card.get('.font-weight-bold'))).toEqual(['free', 'EODHD']);
        expect(card.find('.index-price-chart-trend-line').exists()).toBe(false);
        expect(card.get('[aria-label="Depot performance calculation"]').text()).toContain('Deposits and withdrawals do not create gains');
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
                    previous_day_price: null, previous_day_change_percent: null,
                    previous_day_price_source_markers: [], previous_day_change_percent_source_markers: [],
                });
                for (const valuation of Object.values(response.depot_valuations)) {
                    Object.assign(valuation, {
                        previous_day_balance: null, previous_day_change_amount: null, previous_day_change_percent: null,
                        previous_day_balance_source_markers: [], previous_day_change_amount_source_markers: [],
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

    it('disables the paid sync action while preserving the individual free reload', async () => {
        await renderPage('/admin/menu/stocks');
        const sync = wrapper.findAll('button').find((button) => button.text() === 'EODHD Sync');

        expect(sync.attributes('disabled')).toBeDefined();
    });

    it.each([1440, 390])('renders current, previous and calculated depot prices at width %i', async (width) => {
        await renderPage('/admin/menu/depot/overview', width);
        const desktop = wrapper.get('.desktop-depot-stocks-table tbody tr');
        const cells = desktop.findAll('td');
        const priceCell = cells.find((cell) => cell.text().includes('56.00'));
        const previousCell = cells.find((cell) => cell.text().includes('54.00'));
        expect(markers(priceCell)).toEqual(['free']);
        expect(markers(previousCell)).toEqual(['EODHD']);
        expect(markers(wrapper.get('.mobile-depot-stock-card'))).toContain('free');
        expect(markers(wrapper.get('.mobile-depot-stock-card'))).toContain('EODHD');
        expect(markers(wrapper.get('.flatex-price-button'))).toEqual([]);
        const cashRow = wrapper.findAll('.depot-balance-card tr').find((row) => row.text().includes('Cash balance'));
        expect(markers(cashRow)).toEqual([]);
        const chart = wrapper.get('.depot-performance-chart');
        expect(chart.findAll('title').some((title) => title.text().includes('EODHD'))).toBe(true);
        expect(chart.findAll('title').some((title) => title.text().includes('free'))).toBe(true);
        expect(chart.find('span').exists()).toBe(false);
        expect(chart.get('polyline').element.compareDocumentPosition(chart.get('.depot-performance-source-point').element)
            & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        const balanceRow = wrapper.findAll('.depot-balance-card tr').find((row) => row.find('td').text() === 'Depot balance');
        expect(markers(balanceRow)).toEqual(['free']);
        const flatexButton = wrapper.findAll('button').find((button) => button.text() === 'Flatex price');
        await flatexButton.trigger('click');
        await flushPromises();
        expect(markers(balanceRow)).toEqual([]);
    });

    it.each([1440, 390])('renders period prices and leaves traded volume manual at width %i', async (width) => {
        await renderPage('/admin/menu/depot/all-stocks/actual-year', width);
        const cells = wrapper.get('.desktop-depot-year-stocks-table tbody tr').findAll('td');
        expect(markers(cells[1])).toEqual(['EODHD']);
        expect(markers(cells[2])).toEqual(['free']);
        expect(markers(cells[3])).toEqual([]);
        expect(markers(cells[5])).toEqual(['free', 'EODHD']);
        expect(markers(wrapper.get('.mobile-depot-year-stock-card'))).toContain('free');
        expect(markers(wrapper.get('.mobile-depot-year-stock-card'))).toContain('EODHD');
    });

    it.each([
        ['/admin/menu/stocks', '.watch-list-section', 'free'],
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
