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

    function mountReload() {
        return mount(HoldingPriceReload, {
            props: { holding: { id: 34, symbol: 'UN9' } },
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
});
