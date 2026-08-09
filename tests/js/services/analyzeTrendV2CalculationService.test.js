import { describe, expect, it } from 'vitest';
import {
    calculateAnalyzeTrendV2Rows,
    calculateAnalyzeTrendV2ConstrainedPortfolioTotal,
    calculateAnalyzeTrendV2PortfolioMaximumInvestment,
    calculateAnalyzeTrendV2PortfolioTotal,
    calculateAnalyzeTrendV2SafeInvestmentAmount,
    calculateAnalyzeTrendV2SafeInvestmentTotal,
    calculateAnalyzeTrendV2Total,
    shouldShowAnalyzeTrendSellRecommendation,
} from '../../../resources/js/services/analyzeTrendV2CalculationService';

const buyThresholds = [-4, -3, -2, -1, 0];

function virtualTradePrices() {
    return [
        { trading_date: '2026-06-01', price: '100.000000' },
        { trading_date: '2026-06-02', price: '99.000000' },
        { trading_date: '2026-06-03', price: '98.010000' },
        { trading_date: '2026-06-04', price: '97.029900' },
        { trading_date: '2026-06-05', price: '96.059601' },
        { trading_date: '2026-06-06', price: '95.099005' },
        { trading_date: '2026-06-07', price: '97.000985' },
        { trading_date: '2026-06-08', price: '100.008016' },
    ];
}

describe('analyze Trend V2 calculation service', () => {
    it('shows a calculated sell recommendation only for a real held position', () => {
        const sellRow = {
            virtualTradeActions: [{ type: 'sell', label: 'VSELL', amount: 7000 }],
            depot: { actions: [] },
        };

        expect(shouldShowAnalyzeTrendSellRecommendation({ position_pieces: '2.00000000' }, sellRow)).toBe(true);
        expect(shouldShowAnalyzeTrendSellRecommendation({ position_pieces: '0.00000000' }, sellRow)).toBe(false);
        expect(shouldShowAnalyzeTrendSellRecommendation({ position_pieces: null }, sellRow)).toBe(false);
        expect(shouldShowAnalyzeTrendSellRecommendation({ position_pieces: '2.00000000' }, {
            ...sellRow,
            depot: { actions: [{ type: 'sell', label: 'SELL' }] },
        })).toBe(false);
        expect(shouldShowAnalyzeTrendSellRecommendation({ position_pieces: '2.00000000' }, {
            ...sellRow,
            virtualTradeActions: [{ type: 'buy', label: 'VBUY', amount: 7000 }],
        })).toBe(false);
    });

    it('creates a virtual buy and sell with the configured investment amount', () => {
        const rows = calculateAnalyzeTrendV2Rows(virtualTradePrices(), {
            rowLimit: 200,
            virtualBuyAmount: 7000,
            buyThresholds,
            sellThreshold: 3,
        });

        const buyRow = rows.find((row) => row.date === '2026-06-04');
        const openPositionRow = rows.find((row) => row.date === '2026-06-05');
        const sellRow = rows.find((row) => row.date === '2026-06-08');

        expect(buyRow.virtualTradeActions).toEqual([
            { type: 'buy', label: 'VBUY', amount: 7000 },
        ]);
        expect(buyRow.virtualBuyReason.streakCount).toBe(3);
        expect(buyRow.virtualBuyReason.changePercent).toBeCloseTo(-3, 5);
        expect(buyRow.virtualInvestAmount).toBe(7000);
        expect(sellRow.virtualTradeActions).toEqual([
            { type: 'sell', label: 'VSELL', amount: 7000 },
        ]);
        expect(buyRow.virtualChangePercent).toBe(0);
        expect(buyRow.virtualChangeAmount).toBe(0);
        expect(openPositionRow.virtualChangePercent).toBeCloseTo(-1, 5);
        expect(openPositionRow.virtualChangeAmount).toBeCloseTo(-70, 2);
        expect(openPositionRow.virtualInvestAmount).toBeNull();
        expect(sellRow.virtualChangePercent).toBeCloseTo(3.1, 5);
        expect(sellRow.virtualChangeAmount).toBeCloseTo(217, 2);
        expect(calculateAnalyzeTrendV2Total(rows)).toBeCloseTo(217, 2);
    });

    it('uses the row limit as the virtual trade calculation window', () => {
        const rows = calculateAnalyzeTrendV2Rows(virtualTradePrices(), {
            rowLimit: 1,
            virtualBuyAmount: 7000,
            buyThresholds,
            sellThreshold: 3,
        });

        expect(rows).toHaveLength(1);
        expect(rows[0].date).toBe('2026-06-08');
        expect(rows[0].virtualTradeActions).toEqual([]);
        expect(rows[0].virtualChangeAmount).toBeNull();
        expect(calculateAnalyzeTrendV2Total(rows)).toBeNull();
    });

    it('returns no rows for missing price data', () => {
        expect(calculateAnalyzeTrendV2Rows(null, {
            virtualBuyAmount: 7000,
            buyThresholds,
            sellThreshold: 3,
        })).toEqual([]);
        expect(calculateAnalyzeTrendV2Total([])).toBeNull();
    });

    it('adds the current value of an open position to realized sell results', () => {
        const prices = [
            ...virtualTradePrices(),
            { trading_date: '2026-06-09', price: '99.007936' },
            { trading_date: '2026-06-10', price: '98.017857' },
            { trading_date: '2026-06-11', price: '97.037678' },
            { trading_date: '2026-06-12', price: '96.067301' },
        ];
        const rows = calculateAnalyzeTrendV2Rows(prices, {
            rowLimit: 200,
            virtualBuyAmount: 7000,
            buyThresholds,
            sellThreshold: 3,
        });

        expect(rows[0].virtualPositionOpen).toBe(true);
        expect(rows[0].virtualChangeAmount).toBeCloseTo(-70, 2);
        expect(calculateAnalyzeTrendV2Total(rows)).toBeCloseTo(147, 2);
    });

    it('sums VBUY and VSELL totals across all holdings', () => {
        const holdings = [
            { daily_prices: virtualTradePrices() },
            { daily_prices: virtualTradePrices().slice(0, 5) },
            { daily_prices: [] },
        ];

        expect(calculateAnalyzeTrendV2PortfolioTotal(holdings, {
            virtualBuyAmount: 7000,
            buyThresholds,
            sellThreshold: 3,
        })).toBeCloseTo(147, 2);
    });

    it('recalculates the portfolio total when the row limit changes', () => {
        const holdings = [
            { daily_prices: virtualTradePrices() },
        ];
        const options = {
            virtualBuyAmount: 7000,
            buyThresholds,
            sellThreshold: 3,
        };

        expect(calculateAnalyzeTrendV2PortfolioTotal(holdings, {
            ...options,
            rowLimit: 7,
        })).toBeCloseTo(217, 2);
        expect(calculateAnalyzeTrendV2PortfolioTotal(holdings, {
            ...options,
            rowLimit: 1,
        })).toBe(0);
    });

    it('calculates the maximum investment in simultaneous VBUY positions', () => {
        const holdings = [
            { daily_prices: virtualTradePrices() },
            { daily_prices: virtualTradePrices().slice(0, 5) },
        ];

        expect(calculateAnalyzeTrendV2PortfolioMaximumInvestment(holdings, {
            virtualBuyAmount: 7000,
            buyThresholds,
            sellThreshold: 3,
        })).toEqual({
            amount: 14000,
            date: '2026-06-04',
        });
    });

    it('skips VBUY signals that exceed the shared maximum investment', () => {
        const holdings = [
            { daily_prices: virtualTradePrices() },
            { daily_prices: virtualTradePrices().slice(0, 5) },
        ];

        const result = calculateAnalyzeTrendV2ConstrainedPortfolioTotal(holdings, {
            maxInvestment: 7000,
            virtualBuyAmount: 7000,
            buyThresholds,
            sellThreshold: 3,
        });
        const acceptedRows = result.holdingRows[0].rows;
        const rejectedRows = result.holdingRows[1].rows;

        expect(result.changeAmount).toBeCloseTo(217, 2);
        expect(result.holdingTotals).toEqual([
            { holdingIndex: 0, changeAmount: expect.closeTo(217, 2) },
            { holdingIndex: 1, changeAmount: 0 },
        ]);
        expect(result.maximumInvestedAmount).toBe(7000);
        expect(result.skippedBuyCount).toBe(1);
        expect(acceptedRows.find((row) => row.date === '2026-06-04').virtualTradeActions[0].type)
            .toBe('buy');
        expect(acceptedRows.find((row) => row.date === '2026-06-08').virtualTradeActions[0].type)
            .toBe('sell');
        expect(rejectedRows.every((row) => row.virtualTradeActions.length === 0)).toBe(true);
    });

    it('reports maximum invested capital without enforcing an investment limit', () => {
        const holdings = [
            { daily_prices: virtualTradePrices() },
            { daily_prices: virtualTradePrices().slice(0, 5) },
        ];

        const result = calculateAnalyzeTrendV2ConstrainedPortfolioTotal(holdings, {
            maxInvestment: null,
            virtualBuyAmount: 7000,
            buyThresholds,
            sellThreshold: 3,
        });

        expect(result.changeAmount).toBeCloseTo(calculateAnalyzeTrendV2PortfolioTotal(holdings, {
            virtualBuyAmount: 7000,
            buyThresholds,
            sellThreshold: 3,
        }), 2);
        expect(result.maximumInvestedAmount).toBe(14000);
        expect(result.skippedBuyCount).toBe(0);
    });

    it('calculates a safe uniform VBUY amount for the maximum investment', () => {
        const holdings = [
            { daily_prices: virtualTradePrices() },
            { daily_prices: virtualTradePrices().slice(0, 5) },
        ];

        expect(calculateAnalyzeTrendV2SafeInvestmentAmount(holdings, {
            maxInvestment: 10000,
            virtualBuyAmount: 7000,
            buyThresholds,
            sellThreshold: 3,
        })).toEqual({
            amount: 5000,
            simultaneousPositionCount: 2,
        });
    });

    it('calculates the portfolio total when every VBUY uses the safe investment amount', () => {
        const holdings = [
            { daily_prices: virtualTradePrices() },
            { daily_prices: virtualTradePrices().slice(0, 5) },
        ];

        expect(calculateAnalyzeTrendV2SafeInvestmentTotal(holdings, {
            maxInvestment: 10000,
            virtualBuyAmount: 7000,
            buyThresholds,
            sellThreshold: 3,
        })).toBeCloseTo(105, 2);
    });
});
