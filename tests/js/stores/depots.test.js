import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useDepotStore } from '../../../resources/js/stores/depots';

function jsonResponse(data, options = {}) {
    return {
        ok: options.ok ?? true,
        status: options.status ?? 200,
        json: () => Promise.resolve(data),
    };
}

describe('useDepotStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    it('stores stock and index schedule status from a queued watchlist price refresh', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse({
            message: '2 prices queued for refresh.',
            refresh: {
                refresh_id: 'refresh-1',
                status: 'queued',
                processed: 0,
                total: 2,
                step: '0/2',
                message: '2 prices queued for refresh.',
                current: null,
                started_at: '2026-06-05T09:31:00+00:00',
                finished_at: null,
                error: null,
            },
            price_refresh_settings: {
                status: 'updating',
                status_label: 'Updating prices',
            },
            index_price_refresh_settings: {
                status: 'updating',
                status_label: 'Updating prices',
            },
        }));
        vi.stubGlobal('fetch', fetchMock);

        const depots = useDepotStore();

        await depots.refreshWatchlistPrices();

        expect(fetchMock).toHaveBeenCalledWith('/admin/watchlist/holdings/refresh-prices', expect.objectContaining({
            method: 'POST',
        }));
        expect(depots.priceRefresh.status).toBe('queued');
        expect(depots.priceRefreshSettings.status).toBe('updating');
        expect(depots.indexPriceRefreshSettings.status).toBe('updating');
        expect(depots.indexPriceRefreshSettings.status_label).toBe('Updating prices');
    });

    it('clears stale watchlist price refresh polling without storing an error', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse({
            message: 'Price refresh not found.',
        }, {
            ok: false,
            status: 404,
        }));
        vi.stubGlobal('fetch', fetchMock);

        const depots = useDepotStore();
        depots.priceRefresh = {
            refresh_id: 'missing-refresh',
            status: 'running',
            processed: 0,
            total: 2,
            step: '0/2',
        };

        const data = await depots.loadWatchlistPriceRefresh('missing-refresh');

        expect(fetchMock).toHaveBeenCalledWith(
            '/admin/watchlist/holdings/refresh-prices/missing-refresh',
            expect.any(Object),
        );
        expect(data).toEqual({
            message: '',
            refresh: null,
            stale: true,
        });
        expect(depots.priceRefresh).toBeNull();
        expect(depots.holdingsError).toBe('');
    });

    it('loads today intraday values for a selected test stock', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse({
            stock: {
                id: 5,
                symbol: 'AMES',
                name: 'Amundi IBEX 35 UCITS ETF',
                currency: 'EUR',
            },
            day: {
                trading_date: '2026-06-25',
                interval: '5m',
                overview: null,
                rows: [
                    {
                        datetime: '2026-06-25 07:00:00',
                        open: '498.10000000',
                        high: '499.20000000',
                        low: '497.90000000',
                        close: '499.15000000',
                        volume: 1200,
                    },
                ],
            },
            refresh: {
                message: '1 intraday candles loaded/updated.',
            },
        }));
        vi.stubGlobal('fetch', fetchMock);

        const depots = useDepotStore();
        const data = await depots.loadTestIntraday(5);

        expect(fetchMock).toHaveBeenCalledWith('/admin/tests/stocks/5/intraday', expect.any(Object));
        expect(data.day.rows[0].close).toBe('499.15000000');
        expect(depots.testIntraday.stock.symbol).toBe('AMES');
        expect(depots.testIntradayLoading).toBe(false);
        expect(depots.testIntradayError).toBe('');
    });
});
