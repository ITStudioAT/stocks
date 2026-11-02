import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import FreeIntradayCoverage from '../../../resources/js/FreeIntradayCoverage.vue';
import { createStocksVuetify } from '../../../resources/js/plugins/vuetify';

describe('free intraday coverage', () => {
    it('separates no trading from missing files and exposes excluded legacy rows', () => {
        const wrapper = mount(FreeIntradayCoverage, {
            props: { report: {
                checked_at: '2026-11-02T16:00:00Z', pending_files: 1, excluded_legacy_rows: 5,
                instruments: [
                    { symbol: 'CZX', isin: 'BGCZPX003174', currency: 'EUR', status: 'no_trades', trade_at: null },
                    { symbol: 'SXRY', isin: 'IE00B53L4X51', currency: 'EUR', status: 'incomplete', error: 'HTTP 503' },
                ],
                days: { '2026-11-02': { from: '2026-11-02T08:00:00Z', until: '2026-11-02T15:00:00Z',
                    processed_files: 2, expected_files: 3, complete: false, missing_files: ['missing.json.gz'], file_errors: { 'missing.json.gz': 'HTTP 503' } } },
            } },
            global: { plugins: [createStocksVuetify()] },
        });
        expect(wrapper.text()).toContain('Dateien geprüft · kein Handel');
        expect(wrapper.text()).toContain('Abruf unvollständig');
        expect(wrapper.text()).toContain('Ausgeschlossene Altreihen: 5');
        expect(wrapper.text()).toContain('missing.json.gz');
        expect(wrapper.text()).toContain('HTTP 503');
        expect(wrapper.text()).toContain('2/3 Dateien');
        wrapper.unmount();
    });
});
