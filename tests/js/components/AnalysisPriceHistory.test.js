import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import AnalysisPriceHistory from '../../../resources/js/AnalysisPriceHistory.vue';
import analysisPriceHistorySource from '../../../resources/js/AnalysisPriceHistory.vue?raw';
import { createStocksVuetify } from '../../../resources/js/plugins/vuetify';

function renderHistory(history) {
    return mount(AnalysisPriceHistory, {
        props: { holding: { id: 1, symbol: 'TEST' }, history },
        global: { plugins: [createStocksVuetify()] },
    });
}

function price(index, value, currency = 'EUR') {
    const date = new Date(Date.UTC(2026, 5, 1) - index * 86400000).toISOString().slice(0, 10);

    return { trading_date: date, price: String(value), currency, source_markers: ['EODHD'] };
}

describe('AnalysisPriceHistory', () => {
    it('shows the latest intraday price independently of daily trend history', () => {
        const wrapper = mount(AnalysisPriceHistory, {
            props: { holding: { id: 1 }, history: { prices: [] }, intraday: { price: '125', currency: 'EUR', as_of: '2026-06-05T10:00:00Z', source_markers: ['EODHD'] } },
            global: { plugins: [createStocksVuetify()] },
        });

        expect(wrapper.text()).toContain('Latest stored intraday: 125.');
        expect(wrapper.get('time').attributes('datetime')).toBe('2026-06-05T10:00:00Z');
        expect(wrapper.text()).toContain('No stored historical prices available.');
        wrapper.unmount();
    });

    it('includes today’s intraday price in the trend and replaces a daily price for the same date', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 5, 1, 12));
        try {
            const wrapper = renderHistory({ prices: [price(0, 120), price(1, 110), price(2, 100)] });
            await wrapper.setProps({ intraday: { price: '105', currency: 'EUR', as_of: new Date(2026, 5, 1, 10).toISOString(), source_markers: ['EODHD'] } });
            const rows = wrapper.findAll('tbody tr');
            expect(rows).toHaveLength(3);
            expect(rows[0].text()).toContain('105.');
            expect(rows[0].findAll('td')[2].text()).toContain('-5.0000 EUR');
            expect(rows[0].findAll('td')[4].text()).toBe('-4.55%');
            expect(rows[0].findAll('td')[5].text()).toBe('1');
            wrapper.unmount();
        } finally {
            vi.useRealTimers();
        }
    });

    it('adds a current intraday day but excludes stale and future quotes', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 5, 2, 12));
        try {
            const wrapper = renderHistory({ prices: [price(0, 110), price(1, 100)] });
            await wrapper.setProps({ intraday: { price: '121', currency: 'EUR', as_of: new Date(2026, 5, 2, 10).toISOString() } });
            expect(wrapper.findAll('tbody tr')).toHaveLength(3);
            expect(wrapper.findAll('tbody tr')[0].findAll('td')[4].text()).toBe('+21.00%');
            expect(wrapper.findAll('tbody tr')[0].findAll('td')[5].text()).toBe('2');

            await wrapper.setProps({ intraday: { price: '121', currency: 'EUR', as_of: new Date(2026, 5, 1, 10).toISOString() } });
            expect(wrapper.findAll('tbody tr')).toHaveLength(2);
            await wrapper.setProps({ intraday: { price: '121', currency: 'EUR', as_of: new Date(2026, 5, 2, 13).toISOString() } });
            expect(wrapper.findAll('tbody tr')).toHaveLength(2);
            wrapper.unmount();
        } finally {
            vi.useRealTimers();
        }
    });

    it('shows the latest 100 values with changes against the previous available price', () => {
        const prices = Array.from({ length: 101 }, (_, index) => price(index, 200 - index));
        prices[0].price = '10';
        prices[1].price = '8';
        prices[2].price = '10';
        prices[3].price = '10';
        prices[4].price = '0';
        const wrapper = renderHistory({ prices });
        const rows = wrapper.findAll('tbody tr');

        expect(rows).toHaveLength(100);
        expect(analysisPriceHistorySource).toMatch(/\.analysis-price-history \.analysis-price-history-table\s*\{[^}]*min-width:\s*0;[^}]*table-layout:\s*auto;[^}]*width:\s*auto;/);
        expect(analysisPriceHistorySource).toMatch(/\.analysis-price-history-scroll\s*\{[^}]*overflow-x:\s*auto;/);
        expect(analysisPriceHistorySource).not.toMatch(/max-height\s*:/);
        expect(rows[0].text()).toContain('01.06.2026');
        expect(rows[0].text()).toContain('+2.0000 EUR');
        expect(rows[0].text()).toContain('+25.00%');
        expect(rows[0].findAll('td')[2].classes()).toContain('text-success');
        expect(rows[1].text()).toContain('-2.0000 EUR');
        expect(rows[1].text()).toContain('-20.00%');
        expect(rows[1].findAll('td')[2].classes()).toContain('text-error');
        expect(rows[2].text()).toContain('0.00%');
        expect(rows[2].findAll('td')[2].classes()).toContain('text-medium-emphasis');
        expect(rows[3].findAll('td')[2].text()).toContain('+10.000 EUR');
        expect(rows[3].findAll('td')[3].text()).toBe('–');
        expect(rows[99].findAll('td')[2].text()).toContain('+1.0000 EUR');
        expect(rows[99].findAll('td')[3].text()).toBe('+1.00%');
        expect(wrapper.find('svg').exists()).toBe(false);
        wrapper.unmount();
    });

    it('compounds from the actual starting price and retains a trend across unchanged days', () => {
        const values = [118.5921, 107.811, 98.01, 98.01, 108.9, 121, 121, 110, 100];
        const wrapper = renderHistory({ prices: values.map((value, index) => price(index, value)) });
        const rows = wrapper.findAll('tbody tr');
        const trends = rows.map((row) => row.findAll('td')[4].text());

        expect(trends).toEqual(['+21.00%', '+10.00%', '-19.00%', '-19.00%', '-10.00%', '+21.00%', '+21.00%', '+10.00%', '–']);
        expect(rows[2].findAll('td')[4].classes()).toContain('text-error');
        expect(rows[5].findAll('td')[4].classes()).toContain('text-success');
        expect(rows[2].findAll('td')[3].text()).toBe('0.00%');
        expect(rows.map((row) => row.findAll('td')[5].text())).toEqual(['2', '1', '3', '2', '1', '3', '2', '1', '–']);
        wrapper.unmount();
    });

    it('uses a stored anchor before the visible 100 prices', () => {
        const prices = Array.from({ length: 101 }, (_, index) => price(index, 200 - index));
        const wrapper = renderHistory({ prices, trend_anchor: price(110, 90), trend_prior_count: 10 });
        const rows = wrapper.findAll('tbody tr');

        expect(rows).toHaveLength(100);
        expect(rows[99].findAll('td')[4].text()).toBe('+12.22%');
        expect(rows[0].findAll('td')[4].text()).toBe('+122.22%');
        expect(rows[99].findAll('td')[5].text()).toBe('11');
        expect(rows[0].findAll('td')[5].text()).toBe('110');
        wrapper.unmount();
    });

    it('retains the older trend and increases its count when the oldest visible price is unchanged', () => {
        const prices = Array.from({ length: 101 }, (_, index) => price(index, 200 - index));
        prices[99].price = prices[100].price;
        const wrapper = renderHistory({ prices, trend_anchor: price(110, 90), trend_prior_count: 10 });
        const oldestVisible = wrapper.findAll('tbody tr')[99].findAll('td');

        expect(oldestVisible[3].text()).toBe('0.00%');
        expect(oldestVisible[4].text()).toBe('+11.11%');
        expect(oldestVisible[5].text()).toBe('11');
        wrapper.unmount();
    });

    it('does not divide by zero and restarts when a valid comparison becomes available', () => {
        const wrapper = renderHistory({ prices: [price(0, 20), price(1, 10), price(2, 0), price(3, 0)] });
        const trends = wrapper.findAll('tbody tr').map((row) => row.findAll('td')[4].text());

        expect(trends).toEqual(['–', '–', '–', '–']);
        expect(wrapper.findAll('tbody tr').map((row) => row.findAll('td')[5].text())).toEqual(['2', '1', '–', '–']);
        wrapper.unmount();

        const mixed = renderHistory({ prices: [price(0, 20, 'EUR'), price(1, 10, 'EUR'), price(2, 8, 'USD')] });
        const mixedTrends = mixed.findAll('tbody tr').map((row) => row.findAll('td')[4].text());
        expect(mixedTrends).toEqual(['+100.00%', '–', '–']);
        expect(mixed.findAll('tbody tr').map((row) => row.findAll('td')[5].text())).toEqual(['1', '–', '–']);
        mixed.unmount();
    });

    it('leaves unavailable and cross-currency comparisons empty', () => {
        const wrapper = renderHistory({ prices: [price(0, 12, 'USD'), price(1, 10, 'EUR')] });
        const rows = wrapper.findAll('tbody tr');

        expect(rows[0].findAll('td')[2].text()).toBe('–');
        expect(rows[0].findAll('td')[3].text()).toBe('–');
        expect(rows[1].findAll('td')[2].text()).toBe('–');
        expect(rows[1].findAll('td')[3].text()).toBe('–');
        expect(rows[0].findAll('td')[4].text()).toBe('–');
        expect(rows[1].findAll('td')[4].text()).toBe('–');
        wrapper.unmount();
    });

    it('emits close for a history click and keeps retry as a separate action', async () => {
        const wrapper = renderHistory({ prices: [price(0, 12), price(1, 10)] });
        await wrapper.get('tbody td time').trigger('click');
        expect(wrapper.emitted('close')).toHaveLength(1);
        await wrapper.setProps({ history: { error: 'History unavailable.' } });
        await wrapper.get('.v-alert button').trigger('click');
        expect(wrapper.emitted('retry')).toHaveLength(1);
        expect(wrapper.emitted('close')).toHaveLength(1);
        wrapper.unmount();
    });

    it('shows loading, empty and retryable error states', async () => {
        const wrapper = renderHistory({ loading: true });
        expect(wrapper.get('[role="status"]').text()).toContain('Loading');
        await wrapper.setProps({ history: { prices: [] } });
        expect(wrapper.text()).toContain('No stored historical prices available.');
        await wrapper.setProps({ history: { error: 'History unavailable.' } });
        expect(wrapper.text()).toContain('History unavailable.');
        await wrapper.get('button').trigger('click');
        expect(wrapper.emitted('retry')).toHaveLength(1);
        wrapper.unmount();
    });
});
