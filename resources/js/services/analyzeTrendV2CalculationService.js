export function calculateAnalyzeTrendV2Rows(
    dailyPrices,
    {
        rowLimit = 200,
        virtualBuyAmount = 0,
        buyThresholds = [],
        sellThreshold = 0,
    } = {},
) {
    const normalizedRowLimit = normalizeRowLimit(rowLimit);
    const prices = normalizeDailyPrices(dailyPrices).slice(-(normalizedRowLimit + 1));
    const normalizedVirtualBuyAmount = normalizePositiveNumber(virtualBuyAmount);
    const normalizedSellThreshold = Number(sellThreshold);
    const rows = [];
    let virtualPosition = null;
    let virtualRealizedAmount = 0;
    let hasVirtualTrade = false;

    prices.forEach((price, priceIndex) => {
        if (priceIndex === 0) {
            return;
        }

        const dayChangePercent = priceChangePercent(price.price, prices[priceIndex - 1].price);
        const negativeStreak = calculateNegativeStreak(prices, priceIndex);
        const previousNegativeStreak = calculateNegativeStreak(prices, priceIndex - 1);
        const row = {
            date: price.trading_date,
            price: price.price,
            dayChangePercent,
            virtualTradeActions: [],
            virtualBuyReason: null,
            virtualInvestAmount: null,
            virtualChangePercent: null,
            virtualChangeAmount: null,
            virtualTotalChangeAmount: null,
        };

        if (virtualPosition !== null) {
            virtualPosition.changePercent += dayChangePercent ?? 0;
            row.virtualChangePercent = virtualPosition.changePercent;
            row.virtualChangeAmount = virtualPosition.amount * (virtualPosition.changePercent / 100);

            if (
                Number.isFinite(normalizedSellThreshold)
                && virtualPosition.changePercent >= normalizedSellThreshold
            ) {
                row.virtualTradeActions.push({
                    type: 'sell',
                    label: 'VSELL',
                    amount: virtualPosition.amount,
                });
                virtualRealizedAmount += row.virtualChangeAmount;
                hasVirtualTrade = true;
                virtualPosition = null;
            }
        }

        if (
            virtualPosition === null
            && normalizedVirtualBuyAmount > 0
            && isBuySignal(negativeStreak, previousNegativeStreak, buyThresholds)
        ) {
            virtualPosition = {
                amount: normalizedVirtualBuyAmount,
                changePercent: 0,
            };
            row.virtualChangePercent = 0;
            row.virtualChangeAmount = 0;
            row.virtualBuyReason = {
                streakCount: negativeStreak.count,
                changePercent: negativeStreak.changePercent,
            };
            row.virtualInvestAmount = normalizedVirtualBuyAmount;
            hasVirtualTrade = true;
            row.virtualTradeActions.push({
                type: 'buy',
                label: 'VBUY',
                amount: normalizedVirtualBuyAmount,
            });
        }

        const virtualOpenChangeAmount = virtualPosition === null
            ? 0
            : virtualPosition.amount * (virtualPosition.changePercent / 100);

        row.virtualPositionOpen = virtualPosition !== null;
        row.virtualTotalChangeAmount = hasVirtualTrade
            ? virtualRealizedAmount + virtualOpenChangeAmount
            : null;

        rows.push(row);
    });

    return rows.reverse();
}

export function shouldShowAnalyzeTrendSellRecommendation(holding, row) {
    const positionPieces = Number(holding?.position_pieces);
    const virtualTradeActions = Array.isArray(row?.virtualTradeActions) ? row.virtualTradeActions : [];
    const depotActions = Array.isArray(row?.depot?.actions) ? row.depot.actions : [];

    return Number.isFinite(positionPieces)
        && positionPieces > 0
        && virtualTradeActions.some((action) => action.type === 'sell')
        && !depotActions.some((action) => action.type === 'sell');
}

export function calculateAnalyzeTrendV2Total(rows) {
    if (!Array.isArray(rows) || rows.length === 0) {
        return null;
    }

    const latestRow = rows.reduce((latest, row) => (
        latest === null || row.date > latest.date ? row : latest
    ), null);
    const total = Number(latestRow?.virtualTotalChangeAmount);

    return Number.isFinite(total) && latestRow?.virtualTotalChangeAmount !== null ? total : null;
}

export function calculateAnalyzeTrendV2PortfolioTotal(holdings, options = {}) {
    if (!Array.isArray(holdings)) {
        return 0;
    }

    return holdings.reduce((portfolioTotal, holding) => {
        const rows = calculateAnalyzeTrendV2Rows(holding?.daily_prices, {
            ...options,
        });
        const holdingTotal = calculateAnalyzeTrendV2Total(rows);

        return portfolioTotal + (holdingTotal ?? 0);
    }, 0);
}

export function calculateAnalyzeTrendV2BuyOncePortfolio(
    holdings,
    {
        buyThresholds = [],
        maxInvestment = 0,
        rowLimit = 200,
    } = {},
) {
    const normalizedHoldings = Array.isArray(holdings) ? holdings : [];
    const normalizedMaxInvestment = normalizePositiveNumber(maxInvestment);
    const investmentAmount = normalizedHoldings.length > 0
        ? normalizedMaxInvestment / normalizedHoldings.length
        : 0;
    let currentValue = 0;
    let investedAmount = 0;

    const holdingRows = normalizedHoldings.map((holding, holdingIndex) => {
        const prices = normalizeDailyPrices(holding?.daily_prices)
            .slice(-(normalizeRowLimit(rowLimit) + 1));
        let buyPrice = null;

        if (investmentAmount <= 0) {
            return {
                holdingIndex,
                rows: [],
            };
        }

        const rows = prices.slice(1).map((price, rowIndex) => {
            const priceIndex = rowIndex + 1;
            const negativeStreak = calculateNegativeStreak(prices, priceIndex);
            const previousNegativeStreak = calculateNegativeStreak(prices, priceIndex - 1);
            const isBuy = buyPrice === null
                && price.price > 0
                && isBuySignal(negativeStreak, previousNegativeStreak, buyThresholds);

            if (isBuy) {
                buyPrice = price.price;
                investedAmount += investmentAmount;
            }

            const rowCurrentValue = buyPrice === null
                ? null
                : investmentAmount * (price.price / buyPrice);

            return {
                changeAmount: rowCurrentValue === null ? null : rowCurrentValue - investmentAmount,
                date: price.trading_date,
                currentValue: rowCurrentValue,
                investmentAmount: isBuy ? investmentAmount : null,
                isBuy,
            };
        });

        currentValue += rows.at(-1)?.currentValue ?? 0;

        return {
            holdingIndex,
            rows: rows.reverse(),
        };
    });

    return {
        changeAmount: currentValue - investedAmount,
        currentValue,
        holdingRows,
        investedAmount,
        investmentAmount,
    };
}

export function calculateAnalyzeTrendV2BuyOnceEmergencyPortfolio(
    holdings,
    {
        buyThresholds = [],
        emergencySellThreshold = -4,
        maxInvestment = 0,
        positiveRebuyThreshold = 3,
        rowLimit = 200,
    } = {},
) {
    const normalizedHoldings = Array.isArray(holdings) ? holdings : [];
    const normalizedMaxInvestment = normalizePositiveNumber(maxInvestment);
    const normalizedEmergencySellThreshold = Number.isFinite(Number(emergencySellThreshold))
        ? Number(emergencySellThreshold)
        : -4;
    const normalizedPositiveRebuyThreshold = normalizePositiveNumber(positiveRebuyThreshold) || 3;
    const percentageComparisonTolerance = 0.000000001;
    const investmentAmount = normalizedHoldings.length > 0
        ? normalizedMaxInvestment / normalizedHoldings.length
        : 0;
    let changeAmount = 0;

    const holdingRows = normalizedHoldings.map((holding, holdingIndex) => {
        const prices = normalizeDailyPrices(holding?.daily_prices)
            .slice(-(normalizeRowLimit(rowLimit) + 1));
        const rows = [];
        let hasTrade = false;
        let isWaitingForReentry = false;
        let position = null;
        let realizedChangeAmount = 0;

        prices.forEach((price, priceIndex) => {
            if (priceIndex === 0) {
                return;
            }

            const negativeStreak = calculateNegativeStreak(prices, priceIndex);
            const previousNegativeStreak = calculateNegativeStreak(prices, priceIndex - 1);
            const positiveStreak = calculatePositiveStreak(prices, priceIndex);
            const hasBuySignal = isBuySignal(
                negativeStreak,
                previousNegativeStreak,
                buyThresholds,
            );
            const hasPositiveReentrySignal = positiveStreak.count > 0
                && positiveStreak.changePercent
                    >= normalizedPositiveRebuyThreshold - percentageComparisonTolerance;
            const row = {
                date: price.trading_date,
                displayChangeAmount: null,
                emergencyTradeActions: [],
                investmentAmount: null,
                totalChangeAmount: null,
            };
            let soldOnCurrentRow = false;

            if (position !== null) {
                const dayChangePercent = priceChangePercent(
                    price.price,
                    prices[priceIndex - 1].price,
                );

                if (dayChangePercent !== null && dayChangePercent < 0) {
                    position.emergencyStreakCount += 1;
                    position.emergencyStreakChangePercent += dayChangePercent;
                } else {
                    position.emergencyStreakCount = 0;
                    position.emergencyStreakChangePercent = 0;
                }

                const openChangeAmount = investmentAmount * ((price.price / position.buyPrice) - 1);
                const openChangePercent = priceChangePercent(price.price, position.buyPrice);
                const hasEmergencyStreakLoss = position.emergencyStreakCount > 0
                    && position.emergencyStreakChangePercent
                        <= normalizedEmergencySellThreshold + percentageComparisonTolerance;
                const hasEmergencyHoldingLoss = openChangePercent !== null
                    && openChangePercent
                        <= normalizedEmergencySellThreshold + percentageComparisonTolerance;

                if (hasEmergencyStreakLoss || hasEmergencyHoldingLoss) {
                    realizedChangeAmount += openChangeAmount;
                    row.emergencyTradeActions.push({
                        amount: investmentAmount,
                        label: 'VSELL EMERGENCY',
                        type: 'sell',
                    });
                    position = null;
                    isWaitingForReentry = true;
                    soldOnCurrentRow = true;
                }
            }

            const isInitialBuy = !hasTrade && hasBuySignal;
            const isEmergencyRebuy = isWaitingForReentry
                && (hasBuySignal || hasPositiveReentrySignal);

            if (
                position === null
                && !soldOnCurrentRow
                && investmentAmount > 0
                && (isInitialBuy || isEmergencyRebuy)
            ) {
                position = {
                    buyPrice: price.price,
                    emergencyStreakChangePercent: 0,
                    emergencyStreakCount: 0,
                };
                hasTrade = true;
                isWaitingForReentry = false;
                row.emergencyTradeActions.push({
                    amount: investmentAmount,
                    label: isEmergencyRebuy && !hasBuySignal
                        ? 'VBUY +3% STREAK'
                        : 'VBUY',
                    type: 'buy',
                });
                row.investmentAmount = investmentAmount;
            }

            const openChangeAmount = position === null
                ? 0
                : investmentAmount * ((price.price / position.buyPrice) - 1);
            row.totalChangeAmount = hasTrade
                ? realizedChangeAmount + openChangeAmount
                : null;
            row.displayChangeAmount = position !== null || soldOnCurrentRow
                ? row.totalChangeAmount
                : null;
            rows.push(row);
        });

        changeAmount += rows.at(-1)?.totalChangeAmount ?? 0;

        return {
            holdingIndex,
            rows: rows.reverse(),
        };
    });

    return {
        changeAmount,
        holdingRows,
        investmentAmount,
    };
}

export function calculateAnalyzeTrendV2PortfolioMaximumInvestment(holdings, options = {}) {
    if (!Array.isArray(holdings)) {
        return {
            amount: 0,
            date: null,
        };
    }

    const investmentChangeByDate = new Map();

    holdings.forEach((holding) => {
        const rows = calculateAnalyzeTrendV2Rows(holding?.daily_prices, {
            ...options,
        });

        rows.forEach((row) => {
            const investmentChange = row.virtualTradeActions.reduce((change, virtualTradeAction) => {
                if (virtualTradeAction.type === 'buy') {
                    return change + virtualTradeAction.amount;
                }

                if (virtualTradeAction.type === 'sell') {
                    return change - virtualTradeAction.amount;
                }

                return change;
            }, 0);

            if (investmentChange !== 0) {
                investmentChangeByDate.set(
                    row.date,
                    (investmentChangeByDate.get(row.date) ?? 0) + investmentChange,
                );
            }
        });
    });

    let currentAmount = 0;
    let maximumAmount = 0;
    let maximumAmountDate = null;

    [...investmentChangeByDate.entries()]
        .sort(([firstDate], [secondDate]) => firstDate.localeCompare(secondDate))
        .forEach(([date, investmentChange]) => {
            currentAmount += investmentChange;

            if (currentAmount > maximumAmount) {
                maximumAmount = currentAmount;
                maximumAmountDate = date;
            }
        });

    return {
        amount: maximumAmount,
        date: maximumAmountDate,
    };
}

export function calculateAnalyzeTrendV2ConstrainedPortfolioTotal(
    holdings,
    {
        maxInvestment = 0,
        ...options
    } = {},
) {
    const normalizedMaxInvestment = maxInvestment === null
        ? Number.POSITIVE_INFINITY
        : normalizePositiveNumber(maxInvestment);

    if (!Array.isArray(holdings)) {
        return {
            changeAmount: 0,
            holdingRows: [],
            holdingTotals: [],
            maximumInvestedAmount: 0,
            skippedBuyCount: 0,
        };
    }

    const rowsByDate = new Map();
    const holdingRows = [];

    holdings.forEach((holding, holdingIndex) => {
        const rows = calculateAnalyzeTrendV2Rows(holding?.daily_prices, {
            ...options,
        });
        const constrainedRows = rows.map((row) => ({
            date: row.date,
            virtualTradeActions: [],
            virtualBuyReason: null,
            virtualInvestAmount: null,
            virtualChangePercent: null,
            virtualChangeAmount: null,
        }));

        holdingRows.push({
            holdingIndex,
            rows: constrainedRows,
        });

        rows.forEach((row, rowIndex) => {
            const dateRows = rowsByDate.get(row.date) ?? [];

            dateRows.push({
                constrainedRow: constrainedRows[rowIndex],
                holdingIndex,
                row,
            });
            rowsByDate.set(row.date, dateRows);
        });
    });

    const positionByHolding = new Map();
    const realizedChangeAmountByHolding = new Map();
    let currentInvestedAmount = 0;
    let maximumInvestedAmount = 0;
    let realizedChangeAmount = 0;
    let skippedBuyCount = 0;

    [...rowsByDate.entries()]
        .sort(([firstDate], [secondDate]) => firstDate.localeCompare(secondDate))
        .forEach(([, dateRows]) => {
            dateRows.forEach(({ constrainedRow, holdingIndex, row }) => {
                const position = positionByHolding.get(holdingIndex);

                if (position?.isAccepted && row.virtualChangeAmount !== null) {
                    position.changeAmount = row.virtualChangeAmount;
                    constrainedRow.virtualChangePercent = row.virtualChangePercent;
                    constrainedRow.virtualChangeAmount = row.virtualChangeAmount;
                }
            });

            dateRows.forEach(({ constrainedRow, holdingIndex, row }) => {
                const sellAction = row.virtualTradeActions.find((action) => action.type === 'sell');
                const position = positionByHolding.get(holdingIndex);

                if (!sellAction || !position?.isAccepted) {
                    return;
                }

                constrainedRow.virtualTradeActions.push({ ...sellAction });
                realizedChangeAmount += row.virtualChangeAmount ?? 0;
                realizedChangeAmountByHolding.set(
                    holdingIndex,
                    (realizedChangeAmountByHolding.get(holdingIndex) ?? 0) + (row.virtualChangeAmount ?? 0),
                );
                currentInvestedAmount -= position.amount;
                positionByHolding.set(holdingIndex, {
                    amount: 0,
                    changeAmount: 0,
                    isAccepted: false,
                });
            });

            dateRows.forEach(({ constrainedRow, holdingIndex, row }) => {
                const buyAction = row.virtualTradeActions.find((action) => action.type === 'buy');

                if (!buyAction) {
                    return;
                }

                if (currentInvestedAmount + buyAction.amount > normalizedMaxInvestment) {
                    skippedBuyCount += 1;

                    return;
                }

                currentInvestedAmount += buyAction.amount;
                maximumInvestedAmount = Math.max(maximumInvestedAmount, currentInvestedAmount);
                constrainedRow.virtualTradeActions.push({ ...buyAction });
                constrainedRow.virtualBuyReason = row.virtualBuyReason === null
                    ? null
                    : { ...row.virtualBuyReason };
                constrainedRow.virtualInvestAmount = buyAction.amount;
                constrainedRow.virtualChangePercent = 0;
                constrainedRow.virtualChangeAmount = 0;
                positionByHolding.set(holdingIndex, {
                    amount: buyAction.amount,
                    changeAmount: 0,
                    isAccepted: true,
                });
            });
        });

    const openChangeAmount = [...positionByHolding.values()].reduce((total, position) => (
        total + (position.isAccepted ? position.changeAmount : 0)
    ), 0);
    const holdingTotals = holdings.map((holding, holdingIndex) => {
        const position = positionByHolding.get(holdingIndex);

        return {
            holdingIndex,
            changeAmount: (realizedChangeAmountByHolding.get(holdingIndex) ?? 0)
                + (position?.isAccepted ? position.changeAmount : 0),
        };
    });

    return {
        changeAmount: realizedChangeAmount + openChangeAmount,
        holdingRows,
        holdingTotals,
        maximumInvestedAmount,
        skippedBuyCount,
    };
}

export function calculateAnalyzeTrendV2SafeInvestmentAmount(
    holdings,
    {
        maxInvestment = 0,
        virtualBuyAmount = 0,
        ...options
    } = {},
) {
    const normalizedMaxInvestment = normalizePositiveNumber(maxInvestment);
    const normalizedVirtualBuyAmount = normalizePositiveNumber(virtualBuyAmount);
    const maximumInvestment = calculateAnalyzeTrendV2PortfolioMaximumInvestment(holdings, {
        ...options,
        virtualBuyAmount: normalizedVirtualBuyAmount,
    });
    const simultaneousPositionCount = normalizedVirtualBuyAmount > 0
        ? Math.round(maximumInvestment.amount / normalizedVirtualBuyAmount)
        : 0;

    return {
        amount: simultaneousPositionCount > 0
            ? Math.floor(normalizedMaxInvestment / simultaneousPositionCount)
            : 0,
        simultaneousPositionCount,
    };
}

export function calculateAnalyzeTrendV2SafeInvestmentTotal(
    holdings,
    {
        maxInvestment = 0,
        virtualBuyAmount = 0,
        ...options
    } = {},
) {
    const safeInvestment = calculateAnalyzeTrendV2SafeInvestmentAmount(holdings, {
        ...options,
        maxInvestment,
        virtualBuyAmount,
    });

    return calculateAnalyzeTrendV2PortfolioTotal(holdings, {
        ...options,
        virtualBuyAmount: safeInvestment.amount,
    });
}

function normalizeDailyPrices(dailyPrices) {
    if (!Array.isArray(dailyPrices)) {
        return [];
    }

    return dailyPrices
        .map((dailyPrice) => ({
            trading_date: dailyPrice.trading_date,
            price: Number(dailyPrice.price),
        }))
        .filter((dailyPrice) => dailyPrice.trading_date && Number.isFinite(dailyPrice.price))
        .sort((firstPrice, secondPrice) => firstPrice.trading_date.localeCompare(secondPrice.trading_date));
}

function normalizePositiveNumber(value) {
    const number = Number(value);

    return Number.isFinite(number) && number > 0 ? number : 0;
}

function normalizeRowLimit(rowLimit) {
    const number = Number(rowLimit);

    return Number.isFinite(number) && number > 0 ? Math.floor(number) : 1;
}

function priceChangePercent(price, referencePrice) {
    if (referencePrice === 0) {
        return null;
    }

    return ((price - referencePrice) / referencePrice) * 100;
}

function calculateNegativeStreak(prices, priceIndex) {
    let count = 0;
    let changePercent = 0;

    for (let index = priceIndex; index > 0; index -= 1) {
        const dayChangePercent = priceChangePercent(prices[index].price, prices[index - 1].price);

        if (dayChangePercent === null || dayChangePercent >= 0) {
            break;
        }

        count += 1;
        changePercent += dayChangePercent;
    }

    return {
        count,
        changePercent,
    };
}

function calculatePositiveStreak(prices, priceIndex) {
    let count = 0;
    let changePercent = 0;

    for (let index = priceIndex; index > 0; index -= 1) {
        const dayChangePercent = priceChangePercent(prices[index].price, prices[index - 1].price);

        if (dayChangePercent === null || dayChangePercent <= 0) {
            break;
        }

        count += 1;
        changePercent += dayChangePercent;
    }

    return {
        count,
        changePercent,
    };
}

function isBuySignal(streak, previousStreak, buyThresholds) {
    return qualifiesForBuy(streak, buyThresholds) && !qualifiesForBuy(previousStreak, buyThresholds);
}

function qualifiesForBuy(streak, buyThresholds) {
    if (!streak || streak.count <= 0 || !Array.isArray(buyThresholds) || buyThresholds.length === 0) {
        return false;
    }

    const thresholdIndex = Math.min(streak.count, buyThresholds.length) - 1;
    const threshold = buyThresholds[thresholdIndex];

    return threshold !== null && Number.isFinite(Number(threshold)) && streak.changePercent <= Number(threshold);
}
