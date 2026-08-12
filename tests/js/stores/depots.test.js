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

    it('stores stock schedule status from an EODHD realtime sync', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse({
            message: 'EODHD sync: 2 record(s) created, 2 record(s) updated.',
            refresh: {
                refresh_id: 'refresh-1',
                status: 'finished',
                processed: 2,
                total: 2,
                step: '2/2',
                message: 'EODHD sync: 2 record(s) created, 2 record(s) updated.',
                current: null,
                started_at: '2026-06-05T09:31:00+00:00',
                finished_at: '2026-06-05T09:31:01+00:00',
                error: null,
            },
            price_refresh_settings: {
                status: 'waiting',
                status_label: 'waiting',
            },
            index_price_refresh_settings: {
                status: 'waiting',
                status_label: 'waiting',
            },
        }));
        vi.stubGlobal('fetch', fetchMock);

        const depots = useDepotStore();

        await depots.refreshWatchlistPrices();

        expect(fetchMock).toHaveBeenCalledWith('/admin/watchlist/holdings/refresh-prices', expect.objectContaining({
            method: 'POST',
        }));
        expect(depots.priceRefresh.status).toBe('finished');
        expect(depots.priceRefreshSettings.status).toBe('waiting');
        expect(depots.indexPriceRefreshSettings.status).toBe('waiting');
        expect(depots.indexPriceRefreshSettings.status_label).toBe('waiting');
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

        expect(fetchMock).toHaveBeenCalledWith('/admin/tests/stocks/5/intraday', expect.objectContaining({
            method: 'POST',
        }));
        expect(data.day.rows[0].close).toBe('499.15000000');
        expect(depots.testIntraday.stock.symbol).toBe('AMES');
        expect(depots.testIntradayLoading).toBe(false);
        expect(depots.testIntradayError).toBe('');
    });

    it('uses POST requests for paid ticker and exchange tests', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(jsonResponse({
                exchange_code: 'XETRA',
                tickers: [],
            }))
            .mockResolvedValueOnce(jsonResponse({
                exchanges: [],
                exchange_details: {},
                exchange_detail_errors: {},
            }));
        vi.stubGlobal('fetch', fetchMock);

        const depots = useDepotStore();

        await depots.loadTestTickers();
        await depots.loadTestExchanges();

        expect(fetchMock).toHaveBeenCalledWith('/admin/tests/tickers', expect.objectContaining({
            method: 'POST',
        }));
        expect(fetchMock).toHaveBeenCalledWith('/admin/tests/exchanges', expect.objectContaining({
            method: 'POST',
        }));
    });

    it('uses POST requests when ensuring intraday candles', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse({
            holding: {
                id: 7,
                symbol: 'AMES',
            },
            intraday: null,
            intraday_days: [],
        }));
        vi.stubGlobal('fetch', fetchMock);

        const depots = useDepotStore();

        await depots.loadHoldingIntradayCandles(7);
        await depots.loadExpandedHoldingIntradayCandles(7);

        expect(fetchMock).toHaveBeenNthCalledWith(1, '/admin/watchlist/holdings/7/intraday-candles', expect.objectContaining({
            method: 'POST',
        }));
        expect(fetchMock).toHaveBeenNthCalledWith(2, '/admin/watchlist/holdings/7/intraday-candles', expect.objectContaining({
            method: 'POST',
        }));
    });

    it('requests every watchlist holding when requested', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse({
            depot: null,
            holdings: [],
            meta: {
                current_page: 1,
                last_page: 1,
                per_page: 0,
                total: 0,
                from: null,
                to: null,
            },
        }));
        vi.stubGlobal('fetch', fetchMock);

        const depots = useDepotStore();

        await depots.loadWatchlistHoldings(1, { allHoldings: true });

        expect(fetchMock).toHaveBeenCalledWith('/admin/watchlist/holdings?page=1&all=1', expect.any(Object));
    });

    it('uses a POST request when chart loading may fetch provider data', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse({
            depot: null,
            holdings: [],
            meta: {},
        }));
        vi.stubGlobal('fetch', fetchMock);

        const depots = useDepotStore();

        await depots.loadWatchlistHoldings(1, {
            includeCharts: true,
            chartStockId: 7,
        });

        expect(fetchMock).toHaveBeenCalledWith(
            '/admin/watchlist/holdings/charts?page=1&include_charts=1&chart_stock_id=7',
            expect.objectContaining({ method: 'POST' }),
        );
    });

    it('checks and confirms bounded historical row downloads', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(jsonResponse({ coverage: { is_complete: false } }))
            .mockResolvedValueOnce(jsonResponse({ coverage: { is_complete: true } }))
            .mockResolvedValueOnce(jsonResponse({ depot: null, holdings: [], meta: {} }));
        vi.stubGlobal('fetch', fetchMock);

        const depots = useDepotStore();

        await depots.loadHistoricalPriceRowCoverage(1000);
        await depots.ensureHistoricalPriceRows(1000);
        await depots.loadWatchlistHoldings(1, {
            allChartHoldings: true,
            historyRowLimit: 1000,
            includeCharts: true,
        });

        expect(fetchMock).toHaveBeenNthCalledWith(
            1,
            '/admin/watchlist/holdings/historical-price-rows?row_count=1000',
            expect.any(Object),
        );
        expect(fetchMock).toHaveBeenNthCalledWith(
            2,
            '/admin/watchlist/holdings/historical-price-rows',
            expect.objectContaining({
                body: JSON.stringify({ row_count: 1000 }),
                method: 'POST',
            }),
        );
        expect(fetchMock).toHaveBeenNthCalledWith(
            3,
            '/admin/watchlist/holdings/charts?page=1&include_charts=1&all_chart_holdings=1&history_row_limit=1000',
            expect.objectContaining({ method: 'POST' }),
        );
    });

    it('merges end-of-day repair results without dropping historical data info', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse({
            repair: {
                end_of_day: {
                    missing_stocks_count: 0,
                    covered_stocks_count: 2,
                },
            },
            eodhd_api_usage: {
                credits_limit: 100000,
                credits_used: 42,
            },
        }));
        vi.stubGlobal('fetch', fetchMock);

        const depots = useDepotStore();
        depots.dataRepairSummary = {
            historical_data: {
                missing_stocks_count: 1,
                covered_stocks_count: 4,
            },
            end_of_day: {
                missing_stocks_count: 2,
                covered_stocks_count: 0,
            },
        };

        await depots.repairEndOfDayData();

        expect(fetchMock).toHaveBeenCalledWith('/admin/data/repair/end-of-day', expect.objectContaining({
            method: 'POST',
        }));
        expect(depots.dataRepairSummary).toEqual({
            historical_data: {
                missing_stocks_count: 1,
                covered_stocks_count: 4,
            },
            end_of_day: {
                missing_stocks_count: 0,
                covered_stocks_count: 2,
            },
        });
        expect(depots.eodhdApiUsage.credits_used).toBe(42);
    });
});
