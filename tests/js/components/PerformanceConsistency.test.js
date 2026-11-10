import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, expect, it, vi } from 'vitest';
import PerformanceConsistency from '../../../resources/js/PerformanceConsistency.vue';
import performanceConsistencySource from '../../../resources/js/PerformanceConsistency.vue?raw';

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

it('shows all daily-history entries without a nested scrolling area', async () => {
    const dailyHistory = Array.from({ length: 25 }, (_, index) => ({
        holding_id: index + 1, label: `Aktie ${index + 1}`, status: 'missing',
        expected_count: 254, missing_count: 1, from: '2025-11-10', to: '2026-11-10',
    }));
    const run = { status: 'finished', step: 'Prüfung abgeschlossen', checked: 0, missing: 0, incorrect: 0, unavailable: 0, daily_history: dailyHistory };
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ run }) })));
    const wrapper = mount(PerformanceConsistency, {
        props: { showCard: true },
        global: { stubs: { VCard: { template: '<div><slot /></div>' }, VCardText: { template: '<div><slot /></div>' }, VCardActions: { template: '<div><slot /></div>' }, VBtn: true, VAlert: true, VIcon: true, VProgressLinear: true } },
    });
    await flushPromises();

    const list = wrapper.get('.consistency-issues');
    expect(list.findAll('.consistency-issue')).toHaveLength(25);
    expect(list.text()).toContain('Aktie 25');
    expect(list.classes()).not.toContain('consistency-issues--scrollable');
    const listStyles = performanceConsistencySource.match(/\.consistency-issues\s*\{([^}]*)\}/)?.[1] ?? '';
    expect(listStyles).not.toMatch(/max-height|overflow/);
    wrapper.unmount();
});
