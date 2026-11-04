import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import InstrumentSearchSource from '../../../resources/js/InstrumentSearchSource.vue';
import { createStocksVuetify } from '../../../resources/js/plugins/vuetify';

describe('instrument search source', () => {
    it('starts with the free source and makes no provider request when shown', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(InstrumentSearchSource, { global: { plugins: [createStocksVuetify()] } });
        expect(wrapper.text()).toContain('Free search');
        expect(wrapper.text()).toContain('Missing free results stay unavailable; EODHD is disabled');
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
        expect(fetchMock).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('shows stale catalogue dates and resets a legacy paid source selection', () => {
        const wrapper = mount(InstrumentSearchSource, {
            props: { modelValue: 'eodhd', notice: 'Cached catalogue', status: 'stale', catalogDate: '2026-11-04' },
            global: { plugins: [createStocksVuetify()] },
        });
        expect(wrapper.emitted('update:modelValue')).toEqual([['free']]);
        expect(wrapper.find('.v-select').exists()).toBe(false);
        expect(wrapper.text()).toContain('Catalogue date: 2026-11-04');
        expect(wrapper.find('.v-alert').classes()).toContain('text-warning');
        wrapper.unmount();
    });
});
