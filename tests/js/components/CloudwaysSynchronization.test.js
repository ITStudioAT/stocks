import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, expect, it, vi } from 'vitest';
import CloudwaysSynchronization from '../../../resources/js/CloudwaysSynchronization.vue';

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

it('queues the reviewed synchronization after confirmation and keeps progress visible when leaving Data', async () => {
    const comparison = { total_tables: 2, identical_tables: 1, different_tables: 1, tables: [
        { name: 'depots', status: 'different', cloudways_rows: 4, local_rows: 3, row_differences: { changed: 2, source_only: 1, local_only: 0 } },
        { name: 'stocks', status: 'identical', cloudways_rows: 5, local_rows: 5 },
    ] };
    const fetchMock = vi.fn((url, options) => {
        let run = null;
        if (options?.method === 'POST') {
            run = JSON.parse(options.body).action === 'check'
                ? { status: 'finished', action: 'check', comparison }
                : { status: 'queued', action: 'sync', comparison, progress: null };
        }
        return Promise.resolve({ ok: true, json: () => Promise.resolve({ run }) });
    });
    vi.stubGlobal('fetch', fetchMock);
    const wrapper = mount(CloudwaysSynchronization, {
        props: { showCard: true },
        global: { stubs: {
            VCard: { template: '<div><slot /></div>' },
            VCardText: { template: '<div><slot /></div>' },
            VCardTitle: { template: '<div><slot /></div>' },
            VCardActions: { template: '<div><slot /></div>' },
            VDialog: { template: '<div><slot /></div>' },
            VBtn: { emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' },
            VAlert: { template: '<div><slot /></div>' },
            VSpacer: true,
            VProgressLinear: true,
        } },
    });
    await flushPromises();
    const button = (label) => wrapper.findAll('button').find((element) => element.text() === label);
    await button('Prüfen').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('2 geändert · 1 nur Cloudways · 0 nur lokal');
    expect(fetchMock).toHaveBeenCalledWith('/admin/cloudways/operation', expect.objectContaining({ method: 'POST', body: JSON.stringify({ action: 'check' }) }));
    await button('Neu synchronisieren').trigger('click');
    expect(fetchMock).toHaveBeenCalledTimes(2);
    await button('Synchronisieren').trigger('click');
    await flushPromises();
    expect(fetchMock).toHaveBeenCalledWith('/admin/cloudways/operation', expect.objectContaining({ method: 'POST', body: JSON.stringify({ action: 'sync' }) }));
    await wrapper.setProps({ showCard: false });
    expect(wrapper.text()).toContain('wartet in der Queue');
    wrapper.unmount();
});
