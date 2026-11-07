import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, expect, it, vi } from 'vitest';
import PerformanceConsistency from '../../../resources/js/PerformanceConsistency.vue';

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

it('starts a queue rebuild and keeps the progress visible when leaving Data', async () => {
    const run = { status: 'running', step: 'Depot: Wochen werden geprüft', checked: 2, missing: 1, incorrect: 0, unavailable: 0, repaired: 1, steps: [] };
    const fetchMock = vi.fn((url, options) => Promise.resolve({ ok: true, json: () => Promise.resolve({ run: options?.method === 'POST' ? run : null }) }));
    vi.stubGlobal('fetch', fetchMock);
    const wrapper = mount(PerformanceConsistency, {
        props: { showCard: true },
        global: { stubs: { VCard: { template: '<div><slot /></div>' }, VCardTitle: { template: '<div><slot /></div>' }, VCardText: { template: '<div><slot /></div>' }, VCardActions: { template: '<div><slot /></div>' }, VBtn: { template: '<button @click="$emit(\'click\')"><slot /></button>' }, VAlert: { template: '<div><slot /></div>' }, VIcon: true, VProgressLinear: true } },
    });
    await flushPromises();
    await wrapper.findAll('button')[1].trigger('click');
    await flushPromises();
    expect(fetchMock).toHaveBeenCalledWith('/admin/data/performance-consistency', expect.objectContaining({ method: 'POST', body: JSON.stringify({ repair: true }) }));
    expect(wrapper.text()).toContain('Depot: Wochen werden geprüft');
    await wrapper.setProps({ showCard: false });
    expect(wrapper.text()).toContain('Depot: Wochen werden geprüft');
    wrapper.unmount();
});
