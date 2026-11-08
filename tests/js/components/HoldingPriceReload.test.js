import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import HoldingPriceReload from '../../../resources/js/HoldingPriceReload.vue';
import { useDepotStore } from '../../../resources/js/stores/depots';
import { createStocksVuetify } from '../../../resources/js/plugins/vuetify';

describe('holding price reload', () => {
    let pinia;
    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
    });

    function mountReload(holding = {}) {
        return mount(HoldingPriceReload, {
            props: { holding: { id: 34, symbol: 'UN9', price_reload_available: true, ...holding } },
            global: { plugins: [pinia, createStocksVuetify()] },
        });
    }

    it.each([
        ['updated', 'Newer source data loaded.'],
        ['unchanged', 'No newer source data available.'],
        ['incomplete', 'Free source checked with incomplete coverage.'],
        ['error', 'The free source could not be checked.'],
    ])('shows the %s result and check time', async (status, message) => {
        const reload = vi.spyOn(useDepotStore(), 'reloadHoldingPrice').mockResolvedValue({
            status, message, checked_at: '2026-11-04T10:01:00+01:00', warnings: ['Source delay is 15 minutes.'],
        });
        const wrapper = mountReload();
        await wrapper.get('[aria-label="Reload UN9 price"]').trigger('click');
        await flushPromises();
        expect(reload).toHaveBeenCalledExactlyOnceWith(34);
        expect(wrapper.get('[role="status"]').text()).toContain(message);
        expect(wrapper.text()).toContain('10:01:00 Vienna');
        expect(wrapper.text()).toContain('Source delay is 15 minutes.');
        expect(wrapper.get('[role="status"]').classes().includes('text-error')).toBe(status === 'error');
        wrapper.unmount();
    });

    it('disables repeated clicks while loading and allows retry after a request error', async () => {
        let rejectRequest;
        const reload = vi.spyOn(useDepotStore(), 'reloadHoldingPrice').mockImplementationOnce(() => new Promise((resolve, reject) => {
            rejectRequest = reject;
        })).mockResolvedValueOnce({ status: 'unchanged', message: 'No newer source data available.' });
        const wrapper = mountReload();
        const button = wrapper.get('[aria-label="Reload UN9 price"]');
        await button.trigger('click');
        expect(button.attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Loading');
        await button.trigger('click');
        expect(reload).toHaveBeenCalledTimes(1);
        rejectRequest(new Error('The source is unavailable.'));
        await flushPromises();
        expect(wrapper.get('[role="status"]').text()).toContain('The source is unavailable.');
        expect(button.attributes('disabled')).toBeUndefined();
        await button.trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('No newer source data available.');
        expect(wrapper.text()).not.toContain('The source is unavailable.');
        wrapper.unmount();
    });

    it('uses daily history as the single action for a missing previous trading day', async () => {
        const history = vi.spyOn(useDepotStore(), 'loadHoldingDailyHistory').mockResolvedValue({
            status: 'partial', message: 'Daily history loaded; gaps remain visible.',
        });
        const wrapper = mountReload({ start_price: null, latest_price: '12.20', end_price_24: null,
            end_price_24_date: '2026-11-03', daily_history_available: true });
        expect(wrapper.findAll('button')).toHaveLength(1);
        await wrapper.get('[aria-label="Load UN9 daily history"]').trigger('click');
        await flushPromises();
        expect(history).toHaveBeenCalledExactlyOnceWith(34);
        expect(wrapper.text()).toContain('gaps remain visible');
        wrapper.unmount();
    });

    it('offers one reload when both visible prices are missing', () => {
        const wrapper = mountReload({ start_price: null, latest_price: null, end_price_24: null,
            end_price_24_date: '2026-11-03', daily_history_available: true });
        expect(wrapper.findAll('button')).toHaveLength(1);
        expect(wrapper.find('[aria-label="Reload UN9 price"]').exists()).toBe(true);
        wrapper.unmount();
    });

    it.each([
        { start_price: 0, latest_price: '0.000000', end_price_24: 0, daily_history_available: true },
        { start_price: null, latest_price: '12.20', end_price_24: '11.00', daily_history_available: true },
        { price_reload_available: false, daily_history_available: false },
        { start_price: '12.00', latest_price: '12.20', end_price_24: null, price_reload_available: false, daily_history_available: true },
    ])('does not offer an action without a fillable gap (%j)', (holding) => {
        const wrapper = mountReload(holding);
        expect(wrapper.find('button').exists()).toBe(false);
        wrapper.unmount();
    });

    it('identifies the displayed time of a candle as an interval end', () => {
        const wrapper = mountReload({ start_price: 12, end_price_24: 11, latest_price: 13,
            latest_price_as_of: '2026-11-04T09:05:00+01:00', latest_price_time_basis: 'interval_end', latest_price_interval: '5m' });
        expect(wrapper.text()).toContain('5m OHLC · time shown is interval end.');
        expect(wrapper.find('button').exists()).toBe(false);
        wrapper.unmount();
    });
});
