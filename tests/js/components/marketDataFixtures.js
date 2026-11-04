export const provenanceHolding = {
    id: 1, symbol: 'DEMO', name: 'Demo investment', isin: 'AT0000606306', currency: 'EUR',
    position_pieces: '2', latest_price: '56', latest_price_source: 'Yahoo Finance Xetra',
    latest_price_source_markers: ['free'], latest_price_as_of: '2026-11-04T10:00:00Z',
    latest_price_status: 'fresh', price_status: 'fresh', price_type: 'regular_market_last',
    previous_day_price: '54', previous_day_price_source_markers: ['EODHD'],
    previous_day_change_percent: '3.70', previous_day_change_percent_source_markers: ['free', 'EODHD'],
    end_price_24: '54', end_price_24_source_markers: ['EODHD'], end_price_24_date: '2026-11-03',
    start_price: '55', start_price_source_markers: ['free'], flatex_price: '57', year_start_price: '50',
    data_source_markers: ['free', 'EODHD'], trading_times: 'Monday-Friday 09:00-17:30 Europe/Berlin',
    daily_prices: [
        { trading_date: '2026-11-02', price: '53', source_markers: ['EODHD'] },
        { trading_date: '2026-11-03', price: '54', source_markers: ['EODHD'] },
        { trading_date: '2026-11-04', price: '56', source_markers: ['free'] },
    ],
    recent_prices: [{ id: 1, price: '56', as_of: '2026-11-04T10:00:00Z', source_name: 'Yahoo Finance real-time', source_markers: ['free'] }],
    intraday_prices: [{ id: 1, price: '56', close: '56', currency: 'EUR', as_of: '2026-11-04T10:00:00Z', source_key: 'yahoo_xetra_intraday', source_markers: ['free'] }],
    intraday_candles: [], depot_transactions: [],
};

const depot = { id: 1, name: 'Demo depot', is_active: true, account_balance: '1000' };
const valuation = {
    stock_balance: '112', cash_balance: '1000', account_balance: '1112', current_balance: '1112',
    previous_day_balance: '1108', previous_day_change_amount: '4', previous_day_change_percent: '0.36',
    year_start_balance: '1100', balance_change_amount: '12', balance_change_percent: '1.09',
    taxable_stock_gain_amount: '12', one_week_start_balance: '1100', one_week_change_amount: '12', one_week_change_percent: '1.09',
    month_start_balance: '1100', month_change_amount: '12', month_change_percent: '1.09',
    ...Object.fromEntries(['stock_balance', 'account_balance', 'current_balance', 'balance_change_amount', 'taxable_stock_gain_amount'].map((field) => [`${field}_source_markers`, ['free']])),
    ...Object.fromEntries(['previous_day_balance', 'month_start_balance', 'one_week_start_balance'].map((field) => [`${field}_source_markers`, ['EODHD']])),
    ...Object.fromEntries(['previous_day_change_amount', 'month_change_amount', 'one_week_change_amount'].map((field) => [`${field}_source_markers`, ['free', 'EODHD']])),
};
const periodStock = {
    ...provenanceHolding, opening_pieces: '2', bought_pieces: '0', sold_pieces: '0',
    average_buy_or_year_start_price: '50', average_buy_or_year_start_price_source_markers: ['EODHD'],
    average_sell_or_current_price: '56', average_sell_or_current_price_source_markers: ['free'],
    traded_volume: '100', traded_volume_pieces: '2', change_percent: '12', change_amount: '12',
    change_percent_source_markers: ['free', 'EODHD'], change_amount_source_markers: ['free', 'EODHD'],
};
export const provenanceIndex = {
    id: 1, symbol: 'DAX', name: 'DAX performance index', currency: 'EUR', latest_price: '24000',
    latest_price_source: 'EODHD real-time', latest_price_source_markers: ['EODHD'], data_source_markers: ['EODHD'],
    latest_price_change_pct: '1.20', latest_price_as_of: '2026-11-04T10:00:00Z',
    recent_prices: [{ trading_date: '2026-11-04', actual_price: '24000', start_price: '23900', last_price: '23800', source_markers: ['EODHD'] }],
    realtime_prices: [{ trading_date: '2026-11-04', price: '24000', as_of: '2026-11-04T10:00:00Z', source_markers: ['EODHD'] }],
};
const research = {
    id: 'demo-research', stock_holding_id: 1, status: 'finished', summary: 'Demo research with documented evidence.',
    recommendation: 'hold', recommendation_percentages: { buy: 20, hold: 60, sell: 20 },
    developments: [], calculated_events: {}, sources: [{ url: 'https://example.com/issuer', title: 'Issuer report', source_markers: ['free'] }],
    now_relevant: { blocks: [{ key: 'data_coverage', title: 'Evidence coverage', items: [{ type: 'coverage', title: 'Earnings', detail: 'Documented provider data', source_title: 'EODHD', source_markers: ['EODHD'] }] }] },
    data_source_markers: ['free', 'EODHD'],
};

export function provenanceResponse(path) {
    if (path.endsWith('/date-range')) {
        return { range: { date_from: '2026-11-03', date_to: '2026-11-04', row_count: 2, source_markers: ['free', 'EODHD'] } };
    }
    if (path === '/admin/me') {
        return { user: { id: 1, name: 'Preview Admin', roles: ['admin'] } };
    }
    if (path === '/indices') {
        return { indexes: [provenanceIndex] };
    }
    if (path === '/admin/depots/active') {
        return { depot };
    }
    if (path === '/admin/depot-transactions') {
        return {
            depot_holdings: [provenanceHolding], transactions: [], depot_valuations: {
                latest: valuation,
                flatex: {
                    ...valuation, stock_balance: '114', account_balance: '1114', current_balance: '1114',
                    ...Object.fromEntries(['stock_balance', 'account_balance', 'current_balance', 'balance_change_amount', 'taxable_stock_gain_amount'].map((field) => [`${field}_source_markers`, []])),
                    ...Object.fromEntries(['previous_day_change_amount', 'month_change_amount', 'one_week_change_amount'].map((field) => [`${field}_source_markers`, ['EODHD']])),
                },
            },
            depot_performance_series: [
                { date: '2026-11-03', account_balance: '1108', source_markers: ['EODHD'] },
                { date: '2026-11-04', account_balance: '1112', source_markers: ['free'] },
            ],
        };
    }
    if (path.startsWith('/admin/depot-stocks/')) {
        const period = path.split('/').at(-1);
        const stock = period === 'last-year'
            ? { ...periodStock, data_source_markers: ['EODHD'], average_sell_or_current_price_source_markers: ['EODHD'], change_amount_source_markers: ['EODHD'], change_percent_source_markers: ['EODHD'] }
            : period === '4-ever'
                ? { ...periodStock, data_source_markers: ['free'], average_buy_or_year_start_price_source_markers: [], change_amount_source_markers: ['free'], change_percent_source_markers: ['free'] }
                : periodStock;

        return { period, label: 'Actual Year', stocks: [stock] };
    }
    if (path.startsWith('/admin/watchlist/holdings')) {
        return { depot, holdings: [provenanceHolding], holding: provenanceHolding, meta: { current_page: 1, last_page: 1, total: 1 }, intraday_refresh: { status: 'finished' } };
    }
    if (path.startsWith('/admin/index-watch-items')) {
        return { indexes: [provenanceIndex], index: provenanceIndex };
    }
    if (path === '/admin/dashboard/ai/stock-researches') {
        return { researches: [research], holdings: [provenanceHolding] };
    }
    if (path === '/admin/dashboard/performance') {
        return { days: [{ date: '2026-11-04', change_amount: '4', change_percent: '0.36', data_source_markers: ['free', 'EODHD'] }], sums: [] };
    }

    return {};
}
