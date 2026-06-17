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
});
