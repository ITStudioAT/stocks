<script setup>
import { computed } from 'vue';
import DataSourceMarkers from './DataSourceMarkers.vue';
import { marketDataDisplayText } from './utils/marketDataProvenance';
import { formatPriceValue, hasPriceValue } from './utils/numberFormatters';

const props = defineProps({
    holding: { type: Object, required: true },
    history: { type: Object, default: null },
    intraday: { type: Object, default: null },
});

const emit = defineEmits(['retry', 'close']);

const rows = computed(() => {
    const prices = props.history?.prices ?? [];
    let previous = props.history?.trend_anchor ?? null;
    let direction = 0;
    let basePrice = null;
    let trendCount = 0;

    const chronologicalRows = [...prices].reverse().map((price, index) => {
        const comparable = previous && price.currency && price.currency === previous.currency
            && hasPriceValue(price.price) && hasPriceValue(previous.price)
            && Number.isFinite(Number(price.price)) && Number.isFinite(Number(previous.price));
        const difference = comparable ? Math.round((Number(price.price) - Number(previous.price)) * 1e8) / 1e8 : null;
        const percentage = difference !== null && Number(previous.price) !== 0
            ? difference / Number(previous.price) * 100
            : null;

        if (difference === null) {
            direction = 0;
            basePrice = null;
            trendCount = 0;
        } else if (difference !== 0) {
            if (Math.sign(difference) !== direction) {
                direction = Math.sign(difference);
                basePrice = Number(previous.price);
                trendCount = 0;
            }

            if (index === 0 && props.history?.trend_anchor) {
                const priorCount = props.history.trend_prior_count;
                trendCount = Number.isInteger(priorCount) && priorCount > 0 ? priorCount : null;
            } else if (trendCount !== null) {
                trendCount++;
            }
        } else if (direction !== 0 && trendCount !== null) {
            trendCount++;
        }

        const cumulativePercentage = difference === null
            ? null
            : direction === 0
                ? (Number(previous.price) === 0 ? null : 0)
                : basePrice === 0 ? null : (Number(price.price) / basePrice - 1) * 100;
        previous = price;

        return { ...price, difference, percentage, cumulativePercentage, trendDirection: direction, trendCount: direction === 0 ? null : trendCount };
    });

    return chronologicalRows.reverse().slice(0, 100);
});

function handleHistoryClick(event) {
    if (event.target instanceof Element && event.target.closest('button, a, input, select, textarea, [role="button"], [contenteditable="true"]')) {
        return;
    }

    emit('close');
}

function formatDate(date) {
    const parts = date.split('-');

    return parts.length === 3 ? `${parts[2]}.${parts[1]}.${parts[0]}` : date;
}

function formatIntradayTime(value) {
    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '–' : new Intl.DateTimeFormat('de-AT', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(date);
}

function formatChange(value, currency) {
    if (value === null) {
        return '–';
    }

    return `${value > 0 ? '+' : ''}${formatPriceValue(value, currency, { showCurrency: true })}`;
}

function formatPercent(value) {
    if (value === null) {
        return '–';
    }

    return `${value > 0 ? '+' : ''}${value.toFixed(2)}%`;
}

function changeClass(value) {
    return value > 0 ? 'text-success' : value < 0 ? 'text-error' : 'text-medium-emphasis';
}
</script>

<template>
    <section class="analysis-price-history pa-4" :aria-label="`${holding.name || holding.symbol} historical prices`" @click.stop="handleHistoryClick">
        <h3 class="text-subtitle-1 mb-2">Historical prices</h3>
        <p v-if="intraday" class="text-body-2 mb-2">Latest stored intraday: {{ formatPriceValue(intraday.price, intraday.currency, { showCurrency: true }) }} <DataSourceMarkers :markers="intraday.source_markers ?? []" /> · <time :datetime="intraday.as_of">{{ formatIntradayTime(intraday.as_of) }}</time></p>
        <p v-else class="text-body-2 mb-2">No stored intraday price available.</p>
        <p class="text-caption text-medium-emphasis mb-2">Click the history to close it.</p>
        <p class="text-caption text-medium-emphasis mb-3">Last 100 available daily prices, newest first. Adjusted close where available; otherwise close. Trend % compares with the price before the current rise or fall; Count includes each observation in the trend, including unchanged prices. If no earlier reversal is stored, the oldest available price is the starting point.</p>
        <div v-if="!history || history.loading" role="status" class="text-body-2">Loading historical prices…</div>
        <v-alert v-else-if="history.error" type="error" variant="tonal" density="compact">
            <span>{{ marketDataDisplayText(history.error) }}</span>
            <v-btn size="small" variant="text" @click.stop="$emit('retry')">Retry</v-btn>
        </v-alert>
        <p v-else-if="rows.length === 0" class="text-body-2 text-medium-emphasis">No stored historical prices available.</p>
        <div v-else class="analysis-price-history-scroll">
            <table class="holding-intraday-table analysis-price-history-table">
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col" class="text-right">Price</th>
                        <th scope="col" class="text-right">Change</th>
                        <th scope="col" class="text-right">Change %</th>
                        <th scope="col" class="text-right">Trend %</th>
                        <th scope="col" class="text-right">Count</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.trading_date">
                        <td><time :datetime="row.trading_date">{{ formatDate(row.trading_date) }}</time></td>
                        <td class="text-right">{{ formatPriceValue(row.price, row.currency, { showCurrency: true }) }} <DataSourceMarkers :markers="row.source_markers ?? []" /></td>
                        <td class="text-right" :class="changeClass(row.difference)">{{ formatChange(row.difference, row.currency) }}</td>
                        <td class="text-right" :class="changeClass(row.difference)">{{ formatPercent(row.percentage) }}</td>
                        <td class="text-right" :class="changeClass(row.trendDirection)">{{ formatPercent(row.cumulativePercentage) }}</td>
                        <td class="text-right" :class="changeClass(row.trendDirection)">{{ row.trendCount ?? '–' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>

<style scoped>
.analysis-price-history-scroll {
    overflow-x: auto;
}

.analysis-price-history .analysis-price-history-table {
    border-collapse: collapse;
    min-width: 0;
    table-layout: auto;
    width: auto;
}

.analysis-price-history-table th,
.analysis-price-history-table td {
    border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    padding: 0.4rem 0.6rem;
    white-space: nowrap;
}
</style>
