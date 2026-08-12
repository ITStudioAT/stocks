import { describe, expect, it } from 'vitest';
import {
    calculateAnalyzeTrendV2BuyOnceEmergencyPortfolio,
    calculateAnalyzeTrendV2BuyOncePortfolio,
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

    it('never calculates more than one thousand analysis rows', () => {
        const prices = Array.from({ length: 1005 }, (_, index) => ({
            trading_date: new Date(Date.UTC(2020, 0, index + 1)).toISOString().slice(0, 10),
            price: String(100 + index),
        }));

        const rows = calculateAnalyzeTrendV2Rows(prices, {
            rowLimit: 2000,
            virtualBuyAmount: 7000,
            buyThresholds,
            sellThreshold: 3,
        });

        expect(rows).toHaveLength(1000);
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

    it('invests once per stock and holds each virtual position through the latest price', () => {
        const result = calculateAnalyzeTrendV2BuyOncePortfolio([
            {
                daily_prices: [
                    { trading_date: '2026-06-01', price: 100 },
                    { trading_date: '2026-06-02', price: 99 },
                    { trading_date: '2026-06-03', price: 98.01 },
                    { trading_date: '2026-06-04', price: 97.0299 },
                    { trading_date: '2026-06-05', price: 106.73289 },
                ],
            },
            {
                daily_prices: [
                    { trading_date: '2026-06-01', price: 50 },
                    { trading_date: '2026-06-02', price: 49.5 },
                    { trading_date: '2026-06-03', price: 49.005 },
                    { trading_date: '2026-06-04', price: 48.51495 },
                    { trading_date: '2026-06-05', price: 46.0892025 },
                ],
            },
        ], {
            buyThresholds,
            maxInvestment: 1000,
            rowLimit: 4,
        });

        expect(result.investmentAmount).toBe(500);
        expect(result.investedAmount).toBe(1000);
        expect(result.currentValue).toBeCloseTo(1025, 2);
        expect(result.changeAmount).toBeCloseTo(25, 2);
        expect(result.holdingRows[0].rows.find((row) => row.date === '2026-06-02').currentValue)
            .toBeNull();
        expect(result.holdingRows[0].rows.find((row) => row.date === '2026-06-04')).toEqual({
            changeAmount: 0,
            date: '2026-06-04',
            currentValue: 500,
            investmentAmount: 500,
            isBuy: true,
        });
        expect(result.holdingRows[0].rows[0].currentValue).toBeCloseTo(550, 2);
        expect(result.holdingRows[0].rows[0].changeAmount).toBeCloseTo(50, 2);
        expect(result.holdingRows[1].rows[0].currentValue).toBeCloseTo(475, 2);
        expect(result.holdingRows[1].rows[0].changeAmount).toBeCloseTo(-25, 2);
        expect(result.holdingRows[1].rows.filter((row) => row.isBuy)).toHaveLength(1);
    });

    it('divides maximum investment by every stock even when one has no prices', () => {
        const result = calculateAnalyzeTrendV2BuyOncePortfolio([
            {
                daily_prices: [
                    { trading_date: '2026-05-29', price: 100 },
                    { trading_date: '2026-05-30', price: 99 },
                    { trading_date: '2026-05-31', price: 98.01 },
                    { trading_date: '2026-06-01', price: 97.0299 },
                ],
            },
            { daily_prices: [] },
        ], {
            buyThresholds,
            maxInvestment: 1000,
        });

        expect(result.investmentAmount).toBe(500);
        expect(result.investedAmount).toBe(500);
        expect(result.currentValue).toBe(500);
        expect(result.changeAmount).toBe(0);
        expect(result.holdingRows[1].rows).toEqual([]);
    });

    it('emergency-sells at a minus four percent streak and rebuys on the next VBUY signal', () => {
        const result = calculateAnalyzeTrendV2BuyOnceEmergencyPortfolio([
            {
                daily_prices: [
                    { trading_date: '2026-06-01', price: 100 },
                    { trading_date: '2026-06-02', price: 99 },
                    { trading_date: '2026-06-03', price: 98.01 },
                    { trading_date: '2026-06-04', price: 97.0299 },
                    { trading_date: '2026-06-05', price: 96.059601 },
                    { trading_date: '2026-06-06', price: 95.09900499 },
                    { trading_date: '2026-06-07', price: 94.1480149401 },
                    { trading_date: '2026-06-08', price: 93.206534790699 },
                    { trading_date: '2026-06-09', price: 94.138600138606 },
                    { trading_date: '2026-06-10', price: 93.19721413722 },
                    { trading_date: '2026-06-11', price: 92.265241995848 },
                    { trading_date: '2026-06-12', price: 91.342589575889 },
                ],
            },
        ], {
            buyThresholds,
            maxInvestment: 1000,
        });
        const rows = result.holdingRows[0].rows;
        const firstBuyRow = rows.find((row) => row.date === '2026-06-04');
        const firstPostBuyRow = rows.find((row) => row.date === '2026-06-05');
        const emergencySellRow = rows.find((row) => row.date === '2026-06-08');
        const waitingRow = rows.find((row) => row.date === '2026-06-09');
        const secondBuyRow = rows.find((row) => row.date === '2026-06-12');

        expect(firstBuyRow.emergencyTradeActions).toEqual([
            { amount: 1000, label: 'VBUY', type: 'buy' },
        ]);
        expect(firstPostBuyRow.emergencyTradeActions).toEqual([]);
        expect(emergencySellRow.emergencyTradeActions).toEqual([
            { amount: 1000, label: 'VSELL EMERGENCY', type: 'sell' },
        ]);
        expect(emergencySellRow.totalChangeAmount).toBeCloseTo(-39.4, 2);
        expect(emergencySellRow.displayChangeAmount).toBeCloseTo(-39.4, 2);
        expect(waitingRow.totalChangeAmount).toBeCloseTo(-39.4, 2);
        expect(waitingRow.displayChangeAmount).toBeNull();
        expect(secondBuyRow.emergencyTradeActions).toEqual([
            { amount: 1000, label: 'VBUY', type: 'buy' },
        ]);
        expect(rows.filter((row) => row.emergencyTradeActions.some((action) => action.type === 'buy')))
            .toHaveLength(2);
        expect(result.changeAmount).toBeCloseTo(-39.4, 2);
    });

    it('starts the emergency sell streak after the VBUY signal day', () => {
        const result = calculateAnalyzeTrendV2BuyOnceEmergencyPortfolio([
            {
                daily_prices: [
                    { trading_date: '2025-11-03', price: 94.706 },
                    { trading_date: '2025-11-04', price: 92.751 },
                    { trading_date: '2025-11-05', price: 92.572 },
                    { trading_date: '2025-11-06', price: 89.724 },
                    { trading_date: '2025-11-07', price: 88.99 },
                ],
            },
        ], {
            buyThresholds,
            maxInvestment: 4444.44,
        });
        const rows = result.holdingRows[0].rows;
        const buyRow = rows.find((row) => row.date === '2025-11-06');
        const nextRow = rows.find((row) => row.date === '2025-11-07');

        expect(buyRow.emergencyTradeActions).toEqual([
            { amount: 4444.44, label: 'VBUY', type: 'buy' },
        ]);
        expect(nextRow.emergencyTradeActions).toEqual([]);
        expect(nextRow.displayChangeAmount).toBeCloseTo(-36.36, 2);
    });

    it('emergency-sells when the holding loses four percent without a four percent loss streak', () => {
        const result = calculateAnalyzeTrendV2BuyOnceEmergencyPortfolio([
            {
                daily_prices: [
                    { trading_date: '2026-06-01', price: 100 },
                    { trading_date: '2026-06-02', price: 99 },
                    { trading_date: '2026-06-03', price: 98.01 },
                    { trading_date: '2026-06-04', price: 97.0299 },
                    { trading_date: '2026-06-05', price: 95.089302 },
                    { trading_date: '2026-06-06', price: 96.059601 },
                    { trading_date: '2026-06-07', price: 94.119003 },
                    { trading_date: '2026-06-08', price: 93.148704 },
                ],
            },
        ], {
            buyThresholds,
            maxInvestment: 1000,
        });
        const rows = result.holdingRows[0].rows;
        const threePercentHoldingLossRow = rows.find((row) => row.date === '2026-06-07');
        const fourPercentHoldingLossRow = rows.find((row) => row.date === '2026-06-08');

        expect(threePercentHoldingLossRow.emergencyTradeActions).toEqual([]);
        expect(fourPercentHoldingLossRow.emergencyTradeActions).toEqual([
            { amount: 1000, label: 'VSELL EMERGENCY', type: 'sell' },
        ]);
        expect(fourPercentHoldingLossRow.totalChangeAmount).toBeCloseTo(-40, 2);
        expect(result.changeAmount).toBeCloseTo(-40, 2);
    });

    it('rebuys after an emergency sell when the positive streak reaches three percent', () => {
        const result = calculateAnalyzeTrendV2BuyOnceEmergencyPortfolio([
            {
                daily_prices: [
                    { trading_date: '2026-06-01', price: 100 },
                    { trading_date: '2026-06-02', price: 99 },
                    { trading_date: '2026-06-03', price: 98.01 },
                    { trading_date: '2026-06-04', price: 97.0299 },
                    { trading_date: '2026-06-05', price: 96.059601 },
                    { trading_date: '2026-06-06', price: 95.09900499 },
                    { trading_date: '2026-06-07', price: 94.1480149401 },
                    { trading_date: '2026-06-08', price: 93.206534790699 },
                    { trading_date: '2026-06-09', price: 94.13860013860599 },
                    { trading_date: '2026-06-10', price: 95.07998613999205 },
                    { trading_date: '2026-06-11', price: 96.03078600139197 },
                ],
            },
        ], {
            buyThresholds,
            maxInvestment: 1000,
        });
        const rows = result.holdingRows[0].rows;
        const positiveStreakRebuyRow = rows.find((row) => row.date === '2026-06-11');

        expect(positiveStreakRebuyRow.emergencyTradeActions).toEqual([
            { amount: 1000, label: 'VBUY +3% STREAK', type: 'buy' },
        ]);
        expect(rows.filter((row) => row.emergencyTradeActions.some((action) => action.type === 'buy')))
            .toHaveLength(2);
        expect(result.changeAmount).toBeCloseTo(-39.4, 2);
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
