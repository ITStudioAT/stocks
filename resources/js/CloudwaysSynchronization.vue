<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { request } from './stores/auth';

defineProps({ showCard: Boolean });

const run = ref(null);
const error = ref('');
const starting = ref(false);
const confirming = ref(false);
const running = computed(() => ['queued', 'running'].includes(run.value?.status));
const busy = computed(() => starting.value || running.value);
const comparison = computed(() => run.value?.action === 'check' && run.value.status === 'finished' ? run.value.comparison : null);
const syncResult = computed(() => run.value?.action === 'sync' ? run.value.sync : null);
const progress = computed(() => run.value?.progress);
const differentTables = computed(() => (comparison.value?.tables ?? []).filter((table) => table.status !== 'identical'));
const countedDifferences = computed(() => differentTables.value.reduce((total, table) => {
    const rows = table.row_differences;
    return total + (rows ? rows.changed + rows.source_only + rows.local_only : 0);
}, 0));
const uncountedTables = computed(() => differentTables.value.filter((table) => !table.row_differences).length);
const tableStatus = (status) => ({ identical: 'Übereinstimmend', missing_local: 'Lokal nicht vorhanden', missing_cloudways: 'Auf Cloudways nicht vorhanden' }[status] ?? 'Unterschiedlich');
let timer;
let requestGeneration = 0;

async function refresh() {
    const generation = requestGeneration;
    try {
        const result = await request('/admin/cloudways/operation');
        if (generation !== requestGeneration || starting.value) return;
        run.value = result.run;
        error.value = '';
    } catch (exception) {
        error.value = exception.message;
    }
}

async function start(action) {
    requestGeneration++;
    starting.value = true;
    error.value = '';
    try {
        const result = await request('/admin/cloudways/operation', {
            method: 'POST',
            body: JSON.stringify({ action }),
        });
        run.value = result.run;
    } catch (exception) {
        error.value = exception.message;
    } finally {
        starting.value = false;
    }
}

function synchronize() {
    confirming.value = false;
    return start('sync');
}

onMounted(() => {
    refresh();
    timer = window.setInterval(refresh, 3000);
});
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <v-alert v-if="running && !showCard" type="info" density="compact" class="mb-4" aria-live="polite">
        Cloudways: {{ run.action === 'sync' ? 'Synchronisation' : 'Vergleich' }} {{ run.status === 'queued' ? 'wartet in der Queue' : 'läuft' }}<template v-if="progress?.table"> · {{ progress.table }}</template>
    </v-alert>
    <v-card v-if="showCard" class="cloudways-card mt-4" aria-label="Cloudways Synchronisation">
        <v-card-text class="cloudways-content">
            <div class="cloudways-eyebrow">CLOUDWAYS → LOKAL</div>
            <h2>Datensynchronisation</h2>
            <p class="cloudways-intro">Vergleiche die Datenbanken und übernimm anschließend abweichende Tabellen von Cloudways in die lokale Datenbank.</p>
            <v-alert v-if="error || run?.error" type="error" class="mt-4">{{ error || run.error }}</v-alert>
            <div v-if="busy" class="cloudways-progress" aria-live="polite">
                <v-progress-linear indeterminate color="primary" class="mb-3" />
                <strong>{{ run?.status === 'queued' ? 'Wartet in der Queue' : progress?.table ? `Tabelle ${progress.table}` : 'Vorgang wird vorbereitet' }}</strong>
                <span v-if="progress?.total"> · {{ progress.completed ?? 0 }} von {{ progress.total }} Tabellen abgeschlossen</span>
            </div>
            <div v-if="run?.action === 'check' && run.status !== 'finished' && run.checked_tables?.length" class="mt-5" aria-live="polite">
                <h3>Bereits geprüft</h3>
                <ul class="cloudways-checked">
                    <li v-for="table in run.checked_tables" :key="table.name"><strong>{{ table.name }}</strong><span>{{ tableStatus(table.status) }}</span></li>
                </ul>
            </div>
            <div v-if="comparison" class="cloudways-summary" aria-live="polite">
                <div><strong>{{ comparison.total_tables }}</strong><span>Tabellen geprüft</span></div>
                <div><strong>{{ comparison.identical_tables }}</strong><span>stimmen überein</span></div>
                <div><strong>{{ comparison.different_tables }}</strong><span>mit Unterschieden</span></div>
                <div><strong>{{ countedDifferences }}</strong><span>abweichende Datensätze{{ uncountedTables ? ' (teilweise gezählt)' : '' }}</span></div>
            </div>
            <div v-if="comparison" class="mt-5">
                <h3>Ergebnis nach Tabelle</h3>
                <p class="cloudways-muted">Gezählt werden geänderte Datensätze und Datensätze, die nur auf einer Seite vorhanden sind. Der Vergleich berücksichtigt gemeinsame Spalten.</p>
                <p v-if="uncountedTables" class="cloudways-muted">Bei {{ uncountedTables }} Tabellen ist die Anzahl einzelner Abweichungen nicht ermittelbar. Tabellen- und Zeilenzahlen bleiben sichtbar.</p>
                <div class="cloudways-table-wrap">
                    <table class="cloudways-table">
                        <thead><tr><th>Tabelle</th><th>Cloudways</th><th>Lokal</th><th>Ergebnis</th><th>Datensatz-Abweichungen</th></tr></thead>
                        <tbody>
                            <tr v-for="table in comparison.tables" :key="table.name">
                                <td>{{ table.name }}</td><td>{{ table.cloudways_rows ?? '–' }}</td><td>{{ table.local_rows ?? '–' }}</td>
                                <td>{{ tableStatus(table.status) }}</td>
                                <td v-if="table.row_differences">{{ table.row_differences.changed }} geändert · {{ table.row_differences.source_only }} nur Cloudways · {{ table.row_differences.local_only }} nur lokal</td>
                                <td v-else>Einzeln nicht ermittelbar</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <v-alert v-if="syncResult" :type="syncResult.skipped_tables?.length ? 'warning' : 'success'" class="mt-4">
                {{ syncResult.synced_tables }} Tabellen mit {{ syncResult.rows }} Datensätzen übernommen.
                <span v-if="syncResult.skipped_tables?.length"> Nicht übernommen: {{ syncResult.skipped_tables.join(', ') }}.</span>
            </v-alert>
        </v-card-text>
        <v-card-actions class="cloudways-actions">
            <v-btn color="primary" variant="flat" prepend-icon="mdi-magnify" :disabled="busy" @click="start('check')">Prüfen</v-btn>
            <v-btn variant="outlined" prepend-icon="mdi-cloud-download-outline" :disabled="busy || !differentTables.length" @click="confirming = true">Neu synchronisieren</v-btn>
        </v-card-actions>
        <v-dialog v-model="confirming" max-width="540">
            <v-card>
                <v-card-title>Cloudways-Daten übernehmen?</v-card-title>
                <v-card-text>Die geprüften abweichenden Tabellen werden lokal durch die Daten von Cloudways ersetzt. Betroffen sind {{ differentTables.length }} Tabellen.</v-card-text>
                <v-card-actions><v-spacer /><v-btn @click="confirming = false">Abbrechen</v-btn><v-btn color="primary" @click="synchronize">Synchronisieren</v-btn></v-card-actions>
            </v-card>
        </v-dialog>
    </v-card>
</template>

<style scoped>
.cloudways-card { border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 16px; }
.cloudways-content { padding: 24px; }
.cloudways-eyebrow { color: rgb(var(--v-theme-primary)); font-size: .72rem; font-weight: 700; letter-spacing: .08em; margin-bottom: 6px; }
.cloudways-content h2 { font-size: 1.35rem; }
.cloudways-intro, .cloudways-muted { color: rgba(var(--v-theme-on-surface), .7); margin-top: 6px; }
.cloudways-progress { margin-top: 22px; }
.cloudways-checked { list-style: none; padding: 0; margin-top: 10px; max-height: 240px; overflow-y: auto; }
.cloudways-checked li { display: flex; gap: 12px; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.cloudways-checked span { color: rgba(var(--v-theme-on-surface), .7); }
.cloudways-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-top: 22px; }
.cloudways-summary > div { display: flex; flex-direction: column; padding: 15px; border-radius: 12px; background: rgba(var(--v-theme-on-surface), .045); }
.cloudways-summary strong { font-size: 1.65rem; }
.cloudways-summary span { color: rgba(var(--v-theme-on-surface), .7); }
.cloudways-table-wrap { overflow-x: auto; margin-top: 12px; }
.cloudways-table { width: 100%; border-collapse: collapse; text-align: left; font-size: .9rem; }
.cloudways-table th, .cloudways-table td { padding: 10px 12px; border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.cloudways-actions { padding: 0 24px 24px; flex-wrap: wrap; gap: 8px; }
</style>
