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

it('keeps affected titles accessible in a collapsed detail section', async () => {
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

    const list = wrapper.get('#daily-heading').element.closest('section').querySelector('details');
    expect(list.open).toBe(false);
    expect(list.querySelectorAll('.consistency-item')).toHaveLength(25);
    expect(list.textContent).toContain('Aktie 25');
    wrapper.unmount();
});

it('separates missing days, new prices and paused sources from depot periods', async () => {
    const run = { status: 'finished', repair: true, checked: 8, missing: 1, incorrect: 0, unavailable: 1, repaired: 1,
        daily_history: [
            { holding_id: 1, label: 'Teilweise', status: 'missing', expected_count: 5, initial_missing_count: 3, missing_count: 1, filled_count: 2, missing_dates: ['2026-11-05'], repair_status: 'partial' },
            { holding_id: 2, label: 'Pausiert', status: 'missing', expected_count: 5, initial_missing_count: 1, missing_count: 1, filled_count: 0, repair_status: 'failed', pause_reason: 'provider' },
            { holding_id: 3, label: 'Vollständig', status: 'complete', expected_count: 5, initial_missing_count: 0, missing_count: 0, filled_count: 0 },
        ] };
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ run }) })));
    const wrapper = mount(PerformanceConsistency, { props: { showCard: true }, global: { stubs: {
        VCard: { template: '<div><slot /></div>' }, VCardText: { template: '<div><slot /></div>' }, VBtn: true, VAlert: true, VProgressLinear: true,
    } } });
    await flushPromises();
    const daily = wrapper.get('#daily-heading').element.closest('section');
    expect(daily.querySelector('.consistency-metrics').textContent).toContain('4zuvor fehlende Tage');
    expect(daily.querySelector('.consistency-metrics').textContent).toContain('2Kurse nachgeladen');
    expect(daily.querySelector('.consistency-metrics').textContent).toContain('2noch fehlende Tage');
    expect(daily.textContent).toContain('Anbieter pausiert den Abruf');
    expect(daily.textContent).toContain('Teilweise nachgeladen');
    expect(daily.textContent).toContain('05.11.2026');
    expect(daily.textContent).toContain('1 weitere vollständige Titel');
    expect(wrapper.get('#period-heading').element.closest('section').textContent).toContain('8 geprüft');
    wrapper.unmount();
});

it('shows actual title progress while a fetch is running', async () => {
    const run = { status: 'running', repair: true, phase: 'daily', history_total: 9, history_checked: 4,
        checked: 0, step: 'EXV9: Fehlende Tageskurse werden nachgeladen', daily_history: [] };
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ run }) })));
    const wrapper = mount(PerformanceConsistency, { props: { showCard: true }, global: { stubs: {
        VCard: { template: '<div><slot /></div>' }, VCardText: { template: '<div><slot /></div>' }, VBtn: true, VAlert: true, VProgressLinear: true,
    } } });
    await flushPromises();
    expect(wrapper.text()).toContain('EXV9: Fehlende Tageskurse werden nachgeladen');
    expect(wrapper.text()).toContain('4 von 9 Titeln geprüft');
    expect(wrapper.text()).not.toContain('%');
    wrapper.unmount();
});
