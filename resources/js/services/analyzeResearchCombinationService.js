export function calculateAnalyzeResearchCombinationCount(settings) {
    const simulationConfiguration = createSimulationConfiguration(settings);

    if (simulationConfiguration === null) {
        return 0n;
    }

    return simulationConfiguration.dimensions.reduce((combinationCount, dimension) => (
        combinationCount * calculateRangeValueCount(dimension.range)
    ), 1n);
}

export function formatAnalyzeResearchCombinationCount(combinationCount) {
    return combinationCount.toLocaleString('en-US');
}

export function rememberAnalyzeResearchBestResults(bestResults, result, limit = 10) {
    return [...bestResults, result]
        .sort((firstResult, secondResult) => secondResult.changeAmount - firstResult.changeAmount)
        .slice(0, limit);
}

export function calculateAnalyzeResearchInvestmentByHolding(holdingRows) {
    if (!Array.isArray(holdingRows)) {
        return [];
    }

    return holdingRows
        .map(({ holdingIndex, rows }) => ({
            holdingIndex,
            investedAmount: (Array.isArray(rows) ? rows : []).reduce((totalInvested, row) => (
                totalInvested + (Array.isArray(row?.virtualTradeActions) ? row.virtualTradeActions : [])
                    .filter((action) => action?.type === 'buy' && Number.isFinite(Number(action.amount)))
                    .reduce((rowInvested, action) => rowInvested + Number(action.amount), 0)
            ), 0),
        }))
        .filter(({ investedAmount }) => investedAmount > 0)
        .sort((firstHolding, secondHolding) => (
            secondHolding.investedAmount - firstHolding.investedAmount
            || firstHolding.holdingIndex - secondHolding.holdingIndex
        ));
}

export function* generateAnalyzeResearchVariants(settings) {
    const simulationConfiguration = createSimulationConfiguration(settings);

    if (simulationConfiguration === null) {
        return;
    }

    const { buyRules, dimensions, maxInvestment } = simulationConfiguration;
    const currentValues = dimensions.map((dimension) => dimension.range.from);

    while (true) {
        const buyThresholds = buyRules.map(() => null);
        let sellThreshold = null;
        let virtualBuyAmount = null;

        dimensions.forEach((dimension, index) => {
            const value = scaledIntegerToNumber(currentValues[index], dimension.range.scale);

            if (dimension.type === 'buy') {
                buyThresholds[dimension.buyRuleIndex] = value;
            } else if (dimension.type === 'sell') {
                sellThreshold = value;
            } else if (dimension.type === 'invest') {
                virtualBuyAmount = value;
            }
        });

        yield {
            rowLimit: Number(settings?.rows),
            buyThresholds,
            sellThreshold,
            virtualBuyAmount,
            maxInvestment,
        };

        if (!advanceDimensionValues(dimensions, currentValues)) {
            return;
        }
    }
}

function createSimulationConfiguration(settings) {
    const buyRules = Array.isArray(settings?.buy_rules) ? settings.buy_rules : [];
    const dimensions = [];
    const maxInvestment = Number(settings?.max_invest?.value);

    if (!Number.isFinite(maxInvestment) || maxInvestment < 0) {
        return null;
    }

    buyRules.forEach((buyRule, buyRuleIndex) => {
        if (!buyRule?.enabled) {
            return;
        }

        dimensions.push({
            type: 'buy',
            buyRuleIndex,
            range: createScaledRange(buyRule.from, buyRule.to, settings?.buy_step),
        });
    });
    dimensions.push({
        type: 'sell',
        range: createScaledRange(settings?.sell?.from, settings?.sell?.to, settings?.sell?.step),
    });
    dimensions.push({
        type: 'invest',
        range: createScaledRange(settings?.invest?.from, settings?.invest?.to, settings?.invest?.step),
    });

    if (dimensions.some((dimension) => dimension.range === null)) {
        return null;
    }

    return {
        buyRules,
        dimensions,
        maxInvestment,
    };
}

function createScaledRange(from, to, step) {
    const decimalParts = [from, to, step].map(decimalPartsFromValue);

    if (decimalParts.some((parts) => parts === null)) {
        return null;
    }

    const scale = Math.max(0, ...decimalParts.map((parts) => parts.scale));
    const [scaledFrom, scaledTo, scaledStep] = decimalParts.map((parts) => (
        parts.coefficient * (10n ** BigInt(scale - parts.scale))
    ));

    if (scaledStep <= 0n || scaledTo < scaledFrom) {
        return null;
    }

    return {
        from: scaledFrom,
        to: scaledTo,
        step: scaledStep,
        scale,
    };
}

function calculateRangeValueCount(range) {
    return ((range.to - range.from) / range.step) + 1n;
}

function advanceDimensionValues(dimensions, currentValues) {
    for (let index = dimensions.length - 1; index >= 0; index -= 1) {
        const range = dimensions[index].range;
        const nextValue = currentValues[index] + range.step;

        if (nextValue <= range.to) {
            currentValues[index] = nextValue;

            return true;
        }

        currentValues[index] = range.from;
    }

    return false;
}

function scaledIntegerToNumber(value, scale) {
    return Number(value) / (10 ** scale);
}

function decimalPartsFromValue(value) {
    const match = String(value).trim().match(/^([+-]?)(\d+)(?:\.(\d*))?(?:e([+-]?\d+))?$/i);

    if (!match) {
        return null;
    }

    const [, sign, integerDigits, fractionDigits = '', exponentValue = '0'] = match;
    const unsignedDigits = `${integerDigits}${fractionDigits}`.replace(/^0+(?=\d)/, '') || '0';
    const coefficient = BigInt(unsignedDigits) * (sign === '-' ? -1n : 1n);

    return {
        coefficient,
        scale: fractionDigits.length - Number(exponentValue),
    };
}
