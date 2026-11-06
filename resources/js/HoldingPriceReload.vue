<script setup>
import { ref } from 'vue';
import { useDepotStore } from './stores/depots';

const props = defineProps({ holding: { type: Object, required: true } });
const depots = useDepotStore();
const loading = ref(false);
const result = ref(null);
const error = ref('');

async function reload() {
    if (loading.value) {
        return;
    }
    loading.value = true;
    result.value = null;
    error.value = '';
    try {
        result.value = await depots.reloadHoldingPrice(props.holding.id);
    } catch (failure) {
        error.value = failure.message || 'Reload failed. Please try again.';
    } finally {
        loading.value = false;
    }
}

async function loadHistory() {
    if (loading.value) {
        return;
    }
    loading.value = true;
    result.value = null;
    error.value = '';
    try {
        result.value = await depots.loadHoldingDailyHistory(props.holding.id);
    } catch (failure) {
        error.value = failure.message || 'History import failed.';
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
    <div class="d-flex flex-column align-start ga-1" @click.stop>
        <v-btn
            size="x-small"
            variant="text"
            color="primary"
            :loading="loading"
            :disabled="loading"
            :aria-label="`Reload ${holding.symbol || holding.name} price`"
            title="Check this stock's free source for newer data"
            @click="reload"
        >
            {{ loading ? 'Loading…' : 'Reload' }}
        </v-btn>
        <span v-if="holding.latest_price_time_basis === 'interval_end'" class="text-caption">
            {{ holding.latest_price_interval }} OHLC · time shown is interval end.
        </span>
        <v-btn v-if="holding.daily_history_available" size="x-small" variant="text" :loading="loading" :disabled="loading"
            :aria-label="`Load ${holding.symbol || holding.name} daily history`" @click="loadHistory">
            Load 1 year
        </v-btn>
        <span
            role="status"
            aria-live="polite"
            class="text-caption text-wrap"
            :class="{ 'text-error': error || result?.status === 'error', 'text-warning': result?.status === 'incomplete' }"
        >
            {{ error || result?.message || '' }}
            <span v-if="result?.checked_at">Checked {{ checkedAt(result.checked_at) }} Vienna.</span>
            <span v-if="result?.warnings?.length">{{ result.warnings.join(' ') }}</span>
        </span>
    </div>
</template>
