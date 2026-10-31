import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import DashboardMarketDataTests from '../../../resources/js/DashboardMarketDataTests.vue';
import { createStocksVuetify } from '../../../resources/js/plugins/vuetify';

let wrapper;

function mountTests() {
    wrapper = mount(DashboardMarketDataTests, { global: { plugins: [createStocksVuetify()] } });
    return wrapper;
}

function jsonResponse(data, ok = true, status = 200) {
    return { ok, status, json: async () => data };
}

function result(overrides = {}) {
    return {
        checked_at: '2026-10-30T22:00:00Z', source: 'Deutsche Börse · Xetra Post-Trade',
        source_code: 'DETR', next_round: null, search_before: '2026-10-30T22:00:00+00:00', coverage: [],
        catalog_generated_at: '2026-10-30T21:59:00Z', newest_catalog_segment_at: '2026-10-30T21:44:00Z',
        latest_observed_trade_at: '2026-10-29T16:30:00Z', observed_age_minutes: 1770,
        files: [{ name: 'DETR-posttrade-2026-10-30T16_30.json.gz', segment_at: '2026-10-30T16:30:00Z', records: 100 }],
        files_selected: 1, records_checked: 100, records_ignored: 0, matched_instruments: 1, duration_ms: 500,
        warnings: [], instrument_limit_reached: false,
        instruments: [
            { isin: 'FR0010655746', name: 'Amundi IBEX', symbol: 'AMES', exchange: 'XETRA', mic_code: 'XETR', currency: 'EUR', state: 'found', price: '123.45', trade_at: '2026-10-29T16:30:00Z', trade_venue: 'XETA' },
            { isin: 'IE00B3RBWM25', name: 'Vanguard All-World', symbol: 'VGWL', exchange: 'XETRA', mic_code: 'XETR', currency: 'EUR', state: 'not_found', price: null, trade_at: null, trade_venue: null },
        ],
        ...overrides,
    };
}

function skippedUs() {
    const payload = result();
    return result({ source_code: 'YAHOO', files: [], files_selected: 0, matched_instruments: 0,
        instruments: payload.instruments.map(instrument => ({ ...instrument, state: 'not_applicable', price: null })) });
}

afterEach(() => {
    wrapper?.unmount();
    vi.useRealTimers();
});

describe('DashboardMarketDataTests', () => {
    it('waits for an explicit click and shows loading without duplicate downloads or polling', async () => {
        let resolveResponse;
        const fetchMock = vi.fn().mockImplementationOnce(() => new Promise(resolve => { resolveResponse = resolve; }))
            .mockResolvedValue(jsonResponse({ test: result() }));
        vi.stubGlobal('fetch', fetchMock);
        mountTests();
        await flushPromises();
        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('Noch kein Test ausgeführt');
        await wrapper.get('button').trigger('click');
        await wrapper.get('button').trigger('click');
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(wrapper.text()).toContain('werden gelesen');
        expect(wrapper.get('button').attributes('disabled')).toBeDefined();
        resolveResponse(jsonResponse({ test: result() }));
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledWith('/admin/dashboard/tests/deutsche-boerse', expect.objectContaining({ method: 'POST' }));
        expect(wrapper.text()).toContain('15 Minuten');
        expect(wrapper.text()).toContain('Momentaufnahme');
        expect(wrapper.text()).toContain('1770 Minuten alt');
        expect(wrapper.text()).toContain('29.10.26');
        expect(wrapper.text()).toContain('XETA');
        const missingRow = wrapper.findAll('tbody tr')[1];
        expect(missingRow.text()).toContain('Kein Trade in den geprüften Segmenten');
        expect(missingRow.text()).not.toContain('0,00');
        vi.useFakeTimers();
        await vi.advanceTimersByTimeAsync(60000);
        expect(fetchMock).toHaveBeenCalledTimes(4);
    });

    it('reports empty and partial data without presenting a new price or a current timestamp', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ test: result({
            status: 'partial', files: [], files_selected: 1, records_checked: 0, matched_instruments: 0,
            latest_observed_trade_at: null, observed_age_minutes: null,
            instruments: [], warnings: ['Download-Weiterleitung wurde blockiert.'],
        }) })));
        mountTests();
        await wrapper.get('button').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('Kein Dateisegment konnte geprüft werden');
        expect(wrapper.text()).toContain('Kein Datenzeitpunkt vorhanden');
        expect(wrapper.text()).toContain('Download-Weiterleitung wurde blockiert');
        expect(wrapper.text()).toContain('Keine Instrumente mit ISIN');
    });

    it('shows a source error and permits an explicit retry', async () => {
        const fetchMock = vi.fn().mockResolvedValueOnce(jsonResponse({ message: 'Quelle derzeit nicht erreichbar.' }, false, 503))
            .mockResolvedValue(jsonResponse({ test: result() }));
        vi.stubGlobal('fetch', fetchMock);
        mountTests();
        await wrapper.get('button').trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('Quelle derzeit nicht erreichbar');
        expect(wrapper.find('[aria-label="Deutsche Börse Testergebnis"]').exists()).toBe(false);
        expect(wrapper.get('button').attributes('disabled')).toBeUndefined();
        await wrapper.get('button').trigger('click');
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(5);
        expect(wrapper.find('[aria-label="Deutsche Börse Testergebnis"]').exists()).toBe(true);
    });

    it('explains the manual request rate limit', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({}, false, 429)));
        mountTests();
        await wrapper.get('button').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('Abruflimit erreicht');
    });

    it('shows stored price, source, currency and comparison reasons beside the exchange trade', async () => {
        const payload = result();
        payload.instruments[0].stored_quote = { price: '123.45000000', currency: 'EUR', as_of: '2026-10-29T16:29:00Z',
            source: 'EODHD real-time', mic: 'XETR', venue: 'XETRA', price_type: 'last' };
        payload.instruments[0].comparison = { label: 'Wertgleich · nicht direkt vergleichbar',
            reasons: ['Unterschiedliche oder fehlende Datenzeitpunkte', 'Unterschiedliche oder unbekannte Handelsplatz-MICs'] };
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ test: payload })));
        mountTests();
        await wrapper.get('button').trigger('click');
        await flushPromises();
        const row = wrapper.findAll('tbody tr')[0];
        expect(row.findAll('td')).toHaveLength(4);
        expect(row.text()).toContain('EODHD real-time');
        expect(row.text()).toContain('EUR');
        expect(row.text()).toContain('Wertgleich · nicht direkt vergleichbar');
        expect(row.text()).toContain('Handelsplatz-MICs');
    });

    it('automatically falls back only for missing instruments and preserves the actual source and venue', async () => {
        const initial = result();
        const fallback = result({ source_code: 'DGAT', coverage: [{ source: 'Tradegate', source_code: 'DGAT', round: 0, files: 1, selected: 1, status: 'ok' }],
            instruments: [{ ...initial.instruments[1], state: 'found', price: '163.14', trade_source: 'Tradegate', trade_venue: 'XGAT' }] });
        const fetchMock = vi.fn().mockResolvedValueOnce(jsonResponse({ test: skippedUs() }))
            .mockResolvedValueOnce(jsonResponse({ test: initial })).mockResolvedValueOnce(jsonResponse({ test: fallback }));
        vi.stubGlobal('fetch', fetchMock);
        mountTests();
        await wrapper.get('button').trigger('click');
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(3);
        expect(JSON.parse(fetchMock.mock.calls[2][1].body)).toEqual({ batch: 1, before: initial.search_before, remaining_isins: ['IE00B3RBWM25'] });
        expect(wrapper.findAll('tbody tr')[0].text()).toContain('123.45');
        expect(wrapper.findAll('tbody tr')[1].text()).toContain('Tradegate · MIC XGAT');
        expect(wrapper.text()).toContain('Für alle geprüften Instrumente wurde ein Kurs gefunden');
    });

    it('has no source selection and aborts a pending request when leaving the view', async () => {
        const fetchMock = vi.fn(() => new Promise(() => {}));
        vi.stubGlobal('fetch', fetchMock);
        mountTests();
        expect(wrapper.find('select').exists()).toBe(false);
        await wrapper.get('button').trigger('click');
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ batch: 9, before: null });
        const signal = fetchMock.mock.calls[0][1].signal;
        expect(signal.aborted).toBe(false);
        wrapper.unmount();
        wrapper = null;
        expect(signal.aborted).toBe(true);
    });

    it('continues past a failed source to Frankfurt and shows honest failed coverage', async () => {
        const initial = result();
        const missing = { ...initial.instruments[0], state: 'not_found', price: null };
        const failed = result({ instruments: [missing], files: [], source_failed: true,
            coverage: [{ source: 'Xetra', source_code: 'DETR', round: 0, files: 0, selected: 0, status: 'failed' }],
            warnings: ['Xetra: Quelle nicht erreichbar.'] });
        const found = { ...missing, state: 'found', price: '124', trade_source: 'Frankfurt', trade_venue: 'FRAB' };
        const fetchMock = vi.fn().mockResolvedValueOnce(jsonResponse({ test: skippedUs() }))
            .mockResolvedValueOnce(jsonResponse({ test: failed }))
            .mockResolvedValueOnce(jsonResponse({ test: result({ instruments: [missing] }) }))
            .mockResolvedValueOnce(jsonResponse({ test: result({ instruments: [found] }) }));
        vi.stubGlobal('fetch', fetchMock);
        mountTests();
        await wrapper.get('button').trigger('click');
        await flushPromises();
        expect(fetchMock.mock.calls.map(([, options]) => JSON.parse(options.body).batch)).toEqual([9, 0, 1, 2]);
        expect(wrapper.text()).toContain('Quellenfehler – keine Abdeckung');
        expect(wrapper.text()).toContain('Frankfurt · MIC FRAB');
        expect(wrapper.text()).toContain('Xetra: Quelle nicht erreichbar');
    });

    it('automatically checks older batches and stops after ten bounded batches without polling', async () => {
        const initial = result();
        const missing = { ...initial.instruments[1] };
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ test: result({ instruments: [missing], next_round: 1 }) }));
        vi.stubGlobal('fetch', fetchMock);
        mountTests();
        await wrapper.get('button').trigger('click');
        await flushPromises();
        expect(fetchMock.mock.calls.map(([, options]) => JSON.parse(options.body).batch)).toEqual([9, 0, 1, 2, 3, 4, 5, 6, 7, 8]);
        expect(wrapper.text()).toContain('Begrenzte Suche abgeschlossen');
        vi.useFakeTimers();
        await vi.advanceTimersByTimeAsync(60000);
        expect(fetchMock).toHaveBeenCalledTimes(10);
    });

    it('aborts at the total time budget and keeps the already received partial result', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn().mockResolvedValueOnce(jsonResponse({ test: result() }))
            .mockImplementation((path, options) => new Promise((resolve, reject) => {
                options.signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
            }));
        vi.stubGlobal('fetch', fetchMock);
        mountTests();
        await wrapper.get('button').trigger('click');
        await flushPromises();
        await vi.advanceTimersByTimeAsync(30000);
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls[1][1].signal.aborted).toBe(true);
        expect(wrapper.text()).toContain('Zeitlimit von 30 Sekunden erreicht');
        expect(wrapper.findAll('tbody tr')[0].text()).toContain('123.45');
        expect(wrapper.get('button').attributes('disabled')).toBeUndefined();
    });

    it('automatically shows ARGT in USD with Yahoo identity, unknown delay and stale EODHD comparison', async () => {
        const argt = { isin: 'US37950E2596', symbol: 'ARGT', name: 'Global X MSCI Argentina ETF', exchange: 'US', currency: 'USD',
            state: 'found', us_instrument: true, price: '79.18', trade_at: '2026-10-30T20:00:00Z',
            provider: 'Yahoo Finance', trade_source: 'Yahoo Finance', quote_venue: 'NYSEArca', listing_mic: 'ARCX',
            price_type: 'regular_market_last', quote_note: 'Möglicherweise verzögert; Verzögerung unbekannt.',
            stored_quote: { price: '89.82', currency: 'USD', as_of: '2026-06-26T07:00:00Z', source: 'EODHD real-time open',
                price_type: 'historical_session_start', stale: true },
            comparison: { label: 'Wert abweichend · nicht direkt vergleichbar', reasons: ['Gespeicherter Kurs veraltet'] } };
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ test: result({ source: 'Yahoo Finance · US-Kurse (inoffiziell)', instruments: [argt], files: [] }) }));
        vi.stubGlobal('fetch', fetchMock);
        mountTests();
        await wrapper.get('button').trigger('click');
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        const row = wrapper.get('tbody tr').text();
        expect(row).toContain('79.180 USD');
        expect(row).not.toContain('USD USD');
        expect(row).toContain('Yahoo Finance · NYSEArca · Listing-MIC ARCX');
        expect(row).toContain('Ausführungs-MIC unbekannt');
        expect(row).toContain('21:00:00');
        expect(row).toContain('historical_session_start');
        expect(row).toContain('Gespeicherter Kurs veraltet');
        expect(wrapper.text()).not.toContain('Kein Dateisegment konnte geprüft werden');
    });

    it('stops the entire Deutsche Boerse family after 429 and summarizes the coverage limitation', async () => {
        const missing = result().instruments[1];
        const fetchMock = vi.fn().mockResolvedValueOnce(jsonResponse({ test: skippedUs() }))
            .mockResolvedValue(jsonResponse({ test: result({ instruments: [missing], rate_limited: true,
                warnings: ['Deutsche Börse: Abruflimit (HTTP 429). Alle weiteren Abrufe dieser Anbieterfamilie werden gestoppt.'] }) }));
        vi.stubGlobal('fetch', fetchMock);
        mountTests();
        await wrapper.get('button').trigger('click');
        await flushPromises();
        expect(fetchMock.mock.calls.map(([, options]) => JSON.parse(options.body).batch)).toEqual([9, 0]);
        expect(wrapper.text()).toContain('Abdeckung bleibt unvollständig');
        expect(wrapper.text()).toContain('Abruflimit (HTTP 429)');
    });

    it('does not keep searching German catalogs for a blocked or missing US-only instrument', async () => {
        const missing = { ...result().instruments[0], isin: 'US37950E2596', symbol: 'ARGT', us_instrument: true, price: null };
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ test: result({ instruments: [missing], rate_limited: true }) }));
        vi.stubGlobal('fetch', fetchMock);
        mountTests();
        await wrapper.get('button').trigger('click');
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(wrapper.text()).toContain('fehlende US-Kurse bleiben ungeklärt');
    });
});
