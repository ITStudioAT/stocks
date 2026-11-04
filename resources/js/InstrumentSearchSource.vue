<script setup>
import { watch } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: 'free' },
    notice: { type: String, default: '' },
    catalogDate: { type: String, default: null },
    status: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

watch(() => props.modelValue, (value) => {
    if (value !== 'free') {
        emit('update:modelValue', 'free');
    }
}, { immediate: true });
</script>

<template>
    <p class="text-caption text-medium-emphasis mb-3">
        Free search · Saved and verified instruments remain available. Missing free results stay unavailable; EODHD is disabled.
    </p>
    <v-alert v-if="notice" :type="['unavailable', 'stale'].includes(status) ? 'warning' : 'info'" variant="tonal" density="compact" class="mb-4">
        {{ notice }}
        <span v-if="catalogDate">Catalogue date: {{ catalogDate }}.</span>
    </v-alert>
</template>
