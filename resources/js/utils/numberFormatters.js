export function formatPriceValue(value, currency, { showCurrency = false } = {}) {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    const formattedAmount = formatAdaptiveNumber(value);
    const shouldShowCurrency = currency && (showCurrency || currency !== 'EUR');

    if (!shouldShowCurrency) {
        return formattedAmount;
    }

    return `${formattedAmount} ${currency}`;
}

export function formatAdaptiveNumber(value) {
    const amount = Number(value);

    if (Number.isNaN(amount)) {
        return value;
    }

    const fractionDigits = adaptiveNumberFractionDigits(amount);

    return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: fractionDigits,
        maximumFractionDigits: fractionDigits,
    }).format(amount);
}

function adaptiveNumberFractionDigits(amount) {
    const preDecimalDigitCount = Math.floor(Math.abs(amount)).toString().length;

    if (preDecimalDigitCount >= 3) {
        return 2;
    }

    if (preDecimalDigitCount === 2) {
        return 3;
    }

    return 4;
}
