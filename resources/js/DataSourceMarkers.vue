<script setup>
import { computed } from 'vue';
import { mergeMarketDataMarkers } from './utils/marketDataProvenance';

const props = defineProps({ markers: { type: Array, default: () => [] } });
const visibleMarkers = computed(() => mergeMarketDataMarkers(props.markers));
</script>

<template>
    <span v-if="visibleMarkers.length" class="data-source-markers" aria-label="Data provenance">
        <span v-for="marker in visibleMarkers" :key="marker" class="data-source-marker" :title="`Data origin: ${marker}`">{{ marker }}</span>
    </span>
</template>

<style scoped>
.data-source-markers {
    display: inline-flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.25rem;
    margin-inline: 0.25rem;
    vertical-align: baseline;
}
.data-source-marker {
    font-size: 0.72rem;
    font-weight: 600;
    line-height: 1.3;
    white-space: nowrap;
}
</style>
