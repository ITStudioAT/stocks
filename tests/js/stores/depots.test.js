import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useDepotStore } from '../../../resources/js/stores/depots';

function jsonResponse(data, options = {}) {
    return {
        ok: options.ok ?? true,
        status: options.status ?? 200,
        json: () => Promise.resolve(data),
    };
}

describe('useDepotStore', () => {
    afterEach(() => vi.useRealTimers());
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    it('searches the free catalogue by default and keeps its source status visible', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse({
            results: [{ symbol: 'UN9', source_key: 'xetra_instruments' }],
            message: 'Dated catalogue', status: 'stale', catalog_as_of: '2026-11-04',
        }));
        vi.stubGlobal('fetch', fetchMock);
        const depots = useDepotStore();
        await depots.searchStocks('UN9');
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ query: 'UN9', source: 'free' });
        expect(depots.stockSearchStatus).toBe('stale');
        expect(depots.stockSearchCatalogDate).toBe('2026-11-04');
        expect(depots.stockSearchNotice).toBe('Dated catalogue');
    });

    it('uses EODHD search only after it is selected explicitly', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ results: [] }));
        vi.stubGlobal('fetch', fetchMock);
        const depots = useDepotStore();
        depots.stockSearchSource = 'eodhd';
        await depots.searchStocks('AAPL');
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ query: 'AAPL', source: 'eodhd' });
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('clears old search results and does not retry a failed free search with another provider', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ message: 'Unavailable' }, { ok: false, status: 503 }));
        vi.stubGlobal('fetch', fetchMock);
        const depots = useDepotStore();
        depots.stockSearchResults = [{ symbol: 'OLD' }];
        await expect(depots.searchStocks('UN9')).rejects.toThrow('Unavailable');
        expect(depots.stockSearchResults).toEqual([]);
        expect(depots.stockSearchSource).toBe('free');
        expect(depots.stockSearchError).toBe('Unavailable');
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('does not apply a late EODHD search result after switching back to free search', async () => {
        let finishPaidSearch;
        const fetchMock = vi.fn().mockImplementationOnce(() => new Promise((resolve) => {
            finishPaidSearch = resolve;
        })).mockResolvedValueOnce(jsonResponse({ results: [{ symbol: 'FREE' }], message: 'Free source' }));
        vi.stubGlobal('fetch', fetchMock);
        const depots = useDepotStore();
        depots.stockSearchSource = 'eodhd';
        const paidSearch = depots.searchStocks('AAPL');
        depots.stockSearchSource = 'free';
        await depots.searchStocks('UN9');
        finishPaidSearch(jsonResponse({ results: [{ symbol: 'PAID' }], message: 'EODHD source' }));
        await paidSearch;
        expect(depots.stockSearchResults).toEqual([{ symbol: 'FREE' }]);
        expect(depots.stockSearchNotice).toBe('Free source');
    });

    it('reloads only the selected stock and merges its new quote into both visible lists', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse({
            status: 'updated', holding: { id: 34, latest_price: '17.64', latest_price_as_of: '2026-11-04T09:04:40+01:00' },
        }));
        vi.stubGlobal('fetch', fetchMock);
        const depots = useDepotStore();
        depots.holdings = [{ id: 34, daily_prices: [{ price: '17' }] }, { id: 13, latest_price: '2.3165' }];
        depots.depotHoldings = [{ id: 34, position_pieces: '5' }, { id: 13, latest_price: '2.3165' }];
        expect((await depots.reloadHoldingPrice(34)).status).toBe('updated');
        expect(fetchMock).toHaveBeenCalledExactlyOnceWith('/admin/watchlist/holdings/34/price/reload', expect.objectContaining({ method: 'POST' }));
        expect(depots.holdings[0].latest_price).toBe('17.64');
        expect(depots.holdings[0].daily_prices).toEqual([{ price: '17' }]);
        expect(depots.depotHoldings[0].position_pieces).toBe('5');
        expect(depots.holdings[1]).toEqual({ id: 13, latest_price: '2.3165' });
        expect(depots.depotHoldings[1]).toEqual({ id: 13, latest_price: '2.3165' });
    });

    it('keeps stored candles visible while a queued refresh runs and reloads them after completion', async () => {
        vi.useFakeTimers();
        const stored = { intraday: { rows: [{ close: '100' }] }, refresh: { status: 'queued' } };
        const completed = { intraday: { rows: [{ close: '101' }] }, refresh: { status: 'finished' } };
        const fetchMock = vi.fn().mockResolvedValueOnce(jsonResponse(stored))
            .mockResolvedValueOnce(jsonResponse({ refresh: { status: 'running' } }))
            .mockResolvedValueOnce(jsonResponse({ refresh: { status: 'finished' } }))
            .mockResolvedValueOnce(jsonResponse(completed));
        vi.stubGlobal('fetch', fetchMock);
        const depots = useDepotStore();
        const loading = depots.loadHoldingIntradayCandles(7);
        await vi.advanceTimersByTimeAsync(0);
        expect(depots.analyzeIntradayCandles.intraday.rows[0].close).toBe('100');
        expect(depots.analyzeIntradayCandlesLoading).toBe(true);
        await vi.advanceTimersByTimeAsync(4000);
        await loading;
        expect(depots.analyzeIntradayCandles.intraday.rows[0].close).toBe('101');
        expect(depots.analyzeIntradayCandlesLoading).toBe(false);
        expect(fetchMock).toHaveBeenNthCalledWith(2, '/admin/watchlist/holdings/7/intraday-refresh/detail', expect.any(Object));
    });

    it('polls an index chart without repeating the provider-triggering POST', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn().mockResolvedValueOnce(jsonResponse({ index: { id: 3, latest_price: '100' }, refresh: { status: 'queued' } }))
            .mockResolvedValueOnce(jsonResponse({ index: { id: 3, latest_price: '101' }, refresh: { status: 'finished' } }));
        vi.stubGlobal('fetch', fetchMock);
        const depots = useDepotStore();
        depots.indexWatchItems = [{ id: 3, latest_price: '100' }];
        const loading = depots.ensureIndexWatchItemPrices(3, 'intraday');
        await vi.advanceTimersByTimeAsync(2000);
        expect((await loading).index.latest_price).toBe('101');
        expect(depots.indexWatchItems[0].latest_price).toBe('101');
        expect(fetchMock).toHaveBeenNthCalledWith(1, '/admin/index-watch-items/3/prices/ensure?range=intraday', expect.objectContaining({ method: 'POST' }));
        expect(fetchMock).toHaveBeenNthCalledWith(2, '/admin/index-watch-items/3/prices/refresh?range=intraday', expect.not.objectContaining({ method: 'POST' }));
    });

    it('refreshes missing stock chart samples in the background without keeping the list request open', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn().mockResolvedValueOnce(jsonResponse({ holdings: [{ id: 4, historical_prices_fetching: true, intraday_refresh: { status: 'queued' } }] }))
            .mockResolvedValueOnce(jsonResponse({ refresh: { status: 'finished' } }))
            .mockResolvedValueOnce(jsonResponse({ holdings: [{ id: 4, historical_prices_fetching: false, intraday_refresh: { status: 'finished' }, intraday_prices: [{ price: '101' }] }] }));
        vi.stubGlobal('fetch', fetchMock);
        const depots = useDepotStore();
        await depots.loadWatchlistHoldings(1, { includeCharts: true, chartStockId: 4 });
        expect(depots.holdingsLoading).toBe(false);
        expect(depots.holdings[0].historical_prices_fetching).toBe(true);
        await vi.advanceTimersByTimeAsync(2000);
        expect(depots.holdings[0].historical_prices_fetching).toBe(false);
        expect(depots.holdings[0].intraday_prices[0].price).toBe('101');
    });

    it('stops polling after leaving a view and reports queue failures', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn().mockResolvedValueOnce(jsonResponse({ refresh: { status: 'queued' } }));
        vi.stubGlobal('fetch', fetchMock);
        const depots = useDepotStore();
        const loading = depots.loadHoldingIntradayCandles(7);
        await vi.advanceTimersByTimeAsync(0);
        depots.cancelIntradayRefreshes();
        await vi.advanceTimersByTimeAsync(2000);
        await loading;
        expect(fetchMock).toHaveBeenCalledTimes(1);

        fetchMock.mockResolvedValueOnce(jsonResponse({ refresh: { status: 'queued' } }))
            .mockResolvedValueOnce(jsonResponse({ refresh: { status: 'failed', error: 'Provider unavailable' } }));
        const failed = depots.loadHoldingIntradayCandles(8).catch(error => error);
        await vi.advanceTimersByTimeAsync(2000);
        expect(await failed).toMatchObject({ message: 'Provider unavailable' });
        expect(depots.analyzeIntradayCandlesError).toBe('Provider unavailable');
        expect(depots.analyzeIntradayCandlesLoading).toBe(false);
    });

    it('does not replace a newer holding selection with a late previous response', async () => {
        let resolveOlder;
        const fetchMock = vi.fn().mockImplementationOnce(() => new Promise(resolve => { resolveOlder = resolve; }))
            .mockResolvedValueOnce(jsonResponse({ holdings: [{ id: 2 }] }));
        vi.stubGlobal('fetch', fetchMock);
        const depots = useDepotStore();
        const older = depots.loadWatchlistHoldings(1);
        await depots.loadWatchlistHoldings(2, { silent: true });
        resolveOlder(jsonResponse({ holdings: [{ id: 1 }] }));
        await older;
        expect(depots.holdings).toEqual([{ id: 2 }]);
        expect(depots.holdingsLoading).toBe(false);
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
