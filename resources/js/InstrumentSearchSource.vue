<script setup>
defineProps({
    modelValue: { type: String, default: 'free' },
    notice: { type: String, default: '' },
    catalogDate: { type: String, default: null },
    status: { type: String, default: '' },
});
defineEmits(['update:modelValue']);

const sources = [
    { title: 'free first · EODHD fallback', value: 'free' },
    { title: 'EODHD · existing account / quota', value: 'eodhd' },
];
</script>

<template>
    <v-select
        :model-value="modelValue"
        :items="sources"
        label="Search source"
        density="comfortable"
        hide-details
        class="mb-3"
        @update:model-value="$emit('update:modelValue', $event)"
    />
    <p class="text-caption text-medium-emphasis mb-3">
        {{ modelValue === 'free'
            ? 'Suitable free results have priority. If none are available, search uses EODHD.'
            : 'Search uses the configured EODHD account and consumes its quota.' }}
    </p>
    <v-alert v-if="notice" :type="['unavailable', 'stale'].includes(status) ? 'warning' : 'info'" variant="tonal" density="compact" class="mb-4">
        {{ notice }}
        <span v-if="catalogDate">Catalogue date: {{ catalogDate }}.</span>
    </v-alert>
</template>
