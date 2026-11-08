<script setup>
import { computed, ref } from 'vue';
import { useDepotStore } from './stores/depots';
import { hasPriceValue } from './utils/numberFormatters';
import { marketDataDisplayText } from './utils/marketDataProvenance';

const props = defineProps({ holding: { type: Object, required: true } });
const depots = useDepotStore();
const loading = ref(false);
const result = ref(null);
const error = ref('');
const action = computed(() => {
    const holding = props.holding;
    const missingLatestPrice = !hasPriceValue(holding.latest_price);
    const missingLastDay = !hasPriceValue(holding.end_price_24);

    if (missingLatestPrice && holding.price_reload_available) {
        return 'reload';
    }

    if (missingLastDay && holding.daily_history_available && holding.end_price_24_date) {
        return 'history';
    }

    return missingLastDay && holding.price_reload_available ? 'reload' : null;
});
const actionLabel = computed(() => action.value === 'history' ? 'Load daily prices' : 'Load prices');
const actionAriaLabel = computed(() => {
    const stockName = props.holding.symbol || props.holding.name;

    return action.value === 'history' ? `Load ${stockName} daily history` : `Reload ${stockName} price`;
});
const showsIntervalEnd = computed(() => props.holding.latest_price_time_basis === 'interval_end'
    && hasPriceValue(props.holding.latest_price) && props.holding.latest_price_as_of);

async function loadMissingPrices() {
    if (loading.value || !action.value) {
        return;
    }

    const loadsHistory = action.value === 'history';
    loading.value = true;
    result.value = null;
    error.value = '';
    try {
        result.value = loadsHistory
            ? await depots.loadHoldingDailyHistory(props.holding.id)
            : await depots.reloadHoldingPrice(props.holding.id);
    } catch (failure) {
        error.value = failure.message || (loadsHistory ? 'History import failed.' : 'Reload failed. Please try again.');
    } finally {
        loading.value = false;
    }
}

function checkedAt(value) {
    return new Intl.DateTimeFormat('de-AT', {
        timeZone: 'Europe/Vienna', hour: '2-digit', minute: '2-digit', second: '2-digit',
    }).format(new Date(value));
}
</script>

<template>
    <div v-if="action || loading || result || error || showsIntervalEnd" class="d-flex flex-column align-start ga-1" @click.stop @keydown.stop>
        <span v-if="showsIntervalEnd" class="text-caption">
            {{ holding.latest_price_interval }} OHLC · time shown is interval end.
        </span>
        <v-btn
            v-if="action || loading"
            class="holding-price-reload-button"
            size="x-small"
            variant="text"
            color="primary"
            :loading="loading"
            :disabled="loading"
            :aria-label="actionAriaLabel"
            @click="loadMissingPrices"
        >
            {{ loading ? 'Loading…' : actionLabel }}
        </v-btn>
        <span
            v-if="action || loading || result || error"
            role="status"
            aria-live="polite"
            class="text-caption text-wrap"
            :class="{ 'text-error': error || result?.status === 'error', 'text-warning': result?.status === 'incomplete' }"
        >
            {{ marketDataDisplayText(error || result?.message || '') }}
            <span v-if="result?.checked_at">Checked {{ checkedAt(result.checked_at) }} Vienna.</span>
            <span v-if="result?.warnings?.length">{{ marketDataDisplayText(result.warnings.join(' ')) }}</span>
        </span>
    </div>
</template>
