import { describe, expect, it } from 'vitest';
import {
    calculateAnalyzeResearchCombinationCount,
    calculateAnalyzeResearchInvestmentByHolding,
    formatAnalyzeResearchCombinationCount,
    generateAnalyzeResearchVariants,
    rememberAnalyzeResearchBestResults,
} from '../../../resources/js/services/analyzeResearchCombinationService';

describe('analyze research combination service', () => {
    it('multiplies every enabled research interval', () => {
        const combinationCount = calculateAnalyzeResearchCombinationCount({
            buy_rules: [
                { enabled: true, from: -1, to: 0 },
                { enabled: false, from: -100, to: 0 },
                { enabled: true, from: -2, to: -1.5 },
            ],
            buy_step: 0.25,
            sell: { from: 1, to: 2, step: 0.5 },
            invest: { from: 100, to: 200, step: 50 },
            max_invest: { value: 500 },
        });

        expect(combinationCount).toBe(135n);

        const variants = [...generateAnalyzeResearchVariants({
            rows: 200,
            buy_rules: [
                { enabled: true, from: -1, to: 0 },
                { enabled: false, from: -100, to: 0 },
                { enabled: true, from: -2, to: -1.5 },
            ],
            buy_step: 0.25,
            sell: { from: 1, to: 2, step: 0.5 },
            invest: { from: 100, to: 200, step: 50 },
            max_invest: { value: 500 },
        })];

        expect(variants).toHaveLength(135);
        expect(variants[0]).toEqual({
            rowLimit: 200,
            buyThresholds: [-1, null, -2],
            sellThreshold: 1,
            virtualBuyAmount: 100,
            maxInvestment: 500,
        });
        expect(variants.at(-1)).toEqual({
            rowLimit: 200,
            buyThresholds: [0, null, -1.5],
            sellThreshold: 2,
            virtualBuyAmount: 200,
            maxInvestment: 500,
        });
        expect(new Set(variants.map((variant) => variant.maxInvestment))).toEqual(new Set([500]));
    });

    it('uses one choice for a disabled BUY rule and keeps the fixed max invest', () => {
        const combinationCount = calculateAnalyzeResearchCombinationCount({
            buy_rules: [{ enabled: false, from: -100, to: 0 }],
            buy_step: 0.000001,
            sell: { from: 3, to: 3, step: 0.25 },
            invest: { from: 7000, to: 7000, step: 100 },
            max_invest: { value: 80000 },
        });

        expect(combinationCount).toBe(1n);
        expect([...generateAnalyzeResearchVariants({
            rows: 200,
            buy_rules: [{ enabled: false, from: -100, to: 0 }],
            buy_step: 0.000001,
            sell: { from: 3, to: 3, step: 0.25 },
            invest: { from: 7000, to: 7000, step: 100 },
            max_invest: { value: 80000 },
        })]).toEqual([{
            rowLimit: 200,
            buyThresholds: [null],
            sellThreshold: 3,
            virtualBuyAmount: 7000,
            maxInvestment: 80000,
        }]);
    });

    it('counts decimal steps exactly and formats large totals', () => {
        const combinationCount = calculateAnalyzeResearchCombinationCount({
            buy_rules: [{ enabled: true, from: 0, to: 1 }],
            buy_step: 0.000001,
            sell: { from: 3, to: 3, step: 0.25 },
            invest: { from: 7000, to: 7000, step: 100 },
            max_invest: { value: 80000 },
        });

        expect(combinationCount).toBe(1000001n);
        expect(formatAnalyzeResearchCombinationCount(12345678901234567890n))
            .toBe('12,345,678,901,234,567,890');
    });

    it('remembers only the ten highest simulation results', () => {
        const results = Array.from({ length: 12 }, (_, index) => ({
            variantNumber: String(index + 1),
            changeAmount: index % 2 === 0 ? index * 100 : -index,
        }));
        const bestResults = results.reduce((rememberedResults, result) => (
            rememberAnalyzeResearchBestResults(rememberedResults, result)
        ), []);

        expect(bestResults).toHaveLength(10);
        expect(bestResults.map((result) => result.changeAmount)).toEqual([
            1000,
            800,
            600,
            400,
            200,
            0,
            -1,
            -3,
            -5,
            -7,
        ]);
    });

    it('orders invested stocks by their summed accepted BUY amounts', () => {
        const investmentByHolding = calculateAnalyzeResearchInvestmentByHolding([
            {
                holdingIndex: 0,
                rows: [
                    { virtualTradeActions: [{ type: 'buy', amount: 5000 }] },
                    { virtualTradeActions: [{ type: 'sell', amount: 5300 }, { type: 'buy', amount: 4000 }] },
                ],
            },
            {
                holdingIndex: 1,
                rows: [{ virtualTradeActions: [{ type: 'buy', amount: 12000 }] }],
            },
            {
                holdingIndex: 2,
                rows: [{ virtualTradeActions: [] }],
            },
        ]);

        expect(investmentByHolding).toEqual([
            { holdingIndex: 1, investedAmount: 12000 },
            { holdingIndex: 0, investedAmount: 9000 },
        ]);
    });
});
