<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { request } from './stores/auth';

defineProps({ showCard: Boolean });

const run = ref(null);
const error = ref('');
const loading = ref(false);
const initialLoading = ref(true);
const running = computed(() => ['queued', 'running'].includes(run.value?.status));
const historyRows = computed(() => run.value?.daily_history ?? []);
const affectedRows = computed(() => historyRows.value.filter(row => row.status !== 'complete' || row.filled_count > 0));
const completeRows = computed(() => historyRows.value.filter(row => row.status === 'complete' && !row.filled_count));
const initialKnown = computed(() => historyRows.value.every(row => Number.isInteger(row.initial_missing_count)));
const sum = (field) => historyRows.value.reduce((total, row) => total + (Number.isInteger(row[field]) ? row[field] : 0), 0);
const hasUnknownCoverage = computed(() => historyRows.value.some(row => row.missing_count == null));
const pausedCount = computed(() => historyRows.value.filter(row => row.status !== 'complete' && (row.repair_status === 'cooldown' || row.pause_reason)).length);
const unsupportedCount = computed(() => historyRows.value.filter(row => row.status !== 'complete' && !row.pause_reason && row.repair_status !== 'cooldown' && (row.status === 'unsupported' || row.repair_status === 'unsupported')).length);
const failedCount = computed(() => historyRows.value.filter(row => row.status !== 'complete' && row.repair_status === 'failed' && !row.pause_reason).length);
const hasIssues = computed(() => run.value?.unavailable || run.value?.missing || run.value?.incorrect
    || historyRows.value.some(row => row.status !== 'complete'));
const periodLabels = { week: 'Wochen', month: 'Monate', year: 'Jahre' };
const reasons = { missing: 'Fehlt oder ist veraltet', incorrect: 'Weicht von der Berechnung ab', unavailable: 'Nicht berechenbar' };
const statusText = computed(() => {
    if (!run.value) return 'Noch nicht geprüft';
    if (running.value) return run.value.repair ? 'Neuaufbau läuft' : 'Prüfung läuft';
    if (run.value.status === 'failed') return 'Vorgang fehlgeschlagen';
    if (hasIssues.value) return run.value.repair ? 'Neuaufbau mit Hinweisen' : 'Prüfung mit Hinweisen';
    return 'Abgeschlossen';
});
let timer;
let generation = 0;

function formatDate(date) {
    return date ? date.split('-').reverse().join('.') : '–';
}

function historyStatus(row) {
    if (row.status === 'complete') return 'Vollständig';
    if (row.repair_status === 'cooldown' || row.pause_reason) {
        if (row.pause_reason === 'market_data_upgrade') return 'Datenquelle wegen Wartung pausiert';
        if (row.pause_reason === 'provider') return 'Anbieter pausiert den Abruf';
        return 'Abruf für diesen Titel pausiert';
    }
    if (row.repair_status === 'failed') return 'Abruf fehlgeschlagen';
    if (row.status === 'unsupported' || row.repair_status === 'unsupported') return 'Keine verifizierte Gratisquelle';
    if (row.repair_status === 'partial') return 'Teilweise nachgeladen';
    return 'Tageskurse fehlen';
}

function formatValue(value, suffix) {
    if (value === null || value === undefined) return 'nicht verfügbar';
    return new Intl.NumberFormat('de-AT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value)) + suffix;
}

async function refresh() {
    if (loading.value) return;
    const requestGeneration = ++generation;
    try {
        const response = await request('/admin/data/performance-consistency');
        if (requestGeneration === generation) {
            run.value = response.run;
            error.value = '';
        }
    } catch (exception) {
        if (requestGeneration === generation) error.value = exception.message;
    } finally {
        initialLoading.value = false;
    }
}

async function start(repair) {
    if (loading.value || running.value) return;
    generation++;
    loading.value = true;
    try {
        const response = await request('/admin/data/performance-consistency', {
            method: 'POST',
            body: JSON.stringify({ repair }),
        });
        run.value = response.run;
        error.value = '';
    } catch (exception) {
        error.value = exception.message;
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    refresh();
    timer = window.setInterval(refresh, 3000);
});
onBeforeUnmount(() => {
    generation++;
    window.clearInterval(timer);
});
</script>

<template>
    <v-alert v-if="running && !showCard" type="info" density="compact" class="mb-4" aria-live="polite">
        {{ statusText }}: {{ run.step }}
    </v-alert>
    <v-card v-if="showCard" class="consistency-card mt-4" aria-label="Datenkonsistenz">
        <v-card-text class="consistency-content">
            <div class="consistency-header">
                <div>
                    <h2>Daten prüfen und ergänzen</h2>
                    <p class="consistency-intro">Tageskurse der letzten zwölf Monate und abgeschlossene Depotzeiträume.</p>
                </div>
                <span class="consistency-status" role="status">{{ statusText }}</span>
            </div>

            <div class="consistency-actions">
                <div><v-btn :disabled="loading || running || initialLoading" variant="flat" color="primary" prepend-icon="mdi-magnify" @click="start(false)">Prüfen</v-btn><small>Zeigt Lücken und Abweichungen.</small></div>
                <div><v-btn :disabled="loading || running || initialLoading" variant="outlined" prepend-icon="mdi-refresh" @click="start(true)">Neu aufbauen</v-btn><small>Lädt fehlende Kurse und korrigiert betroffene Zeiträume.</small></div>
            </div>
            <v-alert v-if="error" type="error" class="mt-4" role="alert">{{ error }}</v-alert>
            <div v-if="initialLoading || loading || running" class="consistency-progress" role="status" aria-live="polite">
                <v-progress-linear indeterminate color="primary" class="mb-3" />
                <strong>{{ loading ? 'Vorgang wird gestartet …' : (run?.step ?? 'Status wird geladen …') }}</strong>
                <span v-if="running && run.phase === 'daily'">{{ run.history_checked ?? historyRows.length }} von {{ run.history_total ?? '–' }} Titeln geprüft</span>
                <span v-else-if="running && run.phase === 'periods'">{{ run.history_checked ?? historyRows.length }} Titel und {{ run.checked ?? 0 }} Depotzeiträume geprüft</span>
                <span v-if="running && historyRows.length && historyRows[historyRows.length - 1].repair_status === 'cooldown'">{{ historyRows[historyRows.length - 1].label }}: {{ historyStatus(historyRows[historyRows.length - 1]) }}.</span>
            </div>
            <p v-else-if="!run" class="consistency-empty">Noch keine Prüfung vorhanden. Wähle „Prüfen“, um den Stand zu sehen.</p>

            <template v-if="run && !running">
                <p v-if="run.status === 'failed'" class="consistency-message" role="alert">{{ run.step }}. Bisherige Ergebnisse können unvollständig sein.</p>
                <p v-else class="consistency-message">{{ run.repair ? 'Ergebnis des Neuaufbaus' : 'Ergebnis der Prüfung' }}<span v-if="run.finished_at"> · {{ new Date(run.finished_at).toLocaleString('de-AT') }}</span></p>

                <section class="consistency-section" aria-labelledby="daily-heading">
                    <div class="section-heading"><div><h3 id="daily-heading">Tageskurse</h3><p>Nur abgeschlossene Handelstage; der laufende Börsentag zählt nicht als Lücke.</p></div><span>{{ historyRows.length }} Titel geprüft</span></div>
                    <div v-if="historyRows.length" class="consistency-metrics">
                        <div><strong>{{ initialKnown ? sum('initial_missing_count') : '–' }}</strong><span>zuvor fehlende Tage</span></div>
                        <div v-if="run.repair"><strong>{{ sum('filled_count') }}</strong><span>Kurse nachgeladen</span></div>
                        <div><strong>{{ sum('missing_count') }}</strong><span>noch fehlende Tage<template v-if="hasUnknownCoverage">, teils unbekannt</template></span></div>
                    </div>
                    <p v-else class="consistency-muted">Für Tageskurse liegen aus diesem Lauf keine Ergebnisse vor.</p>
                    <p v-if="!initialKnown && historyRows.length" class="consistency-muted">Die Zahl zuvor fehlender Tage wurde bei diesem älteren Lauf nicht gespeichert.</p>
                    <p v-if="pausedCount || unsupportedCount || failedCount" class="consistency-muted">
                        <template v-if="pausedCount">{{ pausedCount }} Abruf{{ pausedCount === 1 ? '' : 'e' }} pausiert. </template>
                        <template v-if="unsupportedCount">{{ unsupportedCount }} Titel ohne verifizierte Gratisquelle. </template>
                        <template v-if="failedCount">{{ failedCount }} Abruf{{ failedCount === 1 ? '' : 'e' }} fehlgeschlagen.</template>
                    </p>
                    <details v-if="affectedRows.length" class="consistency-details">
                        <summary>{{ affectedRows.length }} betroffene Titel anzeigen</summary>
                        <div v-for="row in affectedRows" :key="row.holding_id" class="consistency-item">
                            <div class="item-heading"><strong>{{ row.label }}</strong><span>{{ historyStatus(row) }}</span></div>
                            <p v-if="row.expected_count != null">{{ row.expected_count - row.missing_count }} von {{ row.expected_count }} Tagen vorhanden · {{ row.missing_count }} offen<template v-if="run.repair"> · {{ row.filled_count ?? 0 }} nachgeladen</template></p>
                            <p v-else>Handelstage konnten nicht bestimmt werden.</p>
                            <p v-if="row.from">Zeitraum: {{ formatDate(row.from) }} bis {{ formatDate(row.to) }}</p>
                            <p v-if="row.repair_status === 'cooldown' || row.pause_reason">Später erneut versuchen<template v-if="row.retry_at">, frühestens ab {{ new Date(row.retry_at * 1000).toLocaleString('de-AT') }}</template>.</p>
                            <p v-else-if="row.repair_status === 'failed'">Die Quelle konnte nicht erfolgreich verarbeitet werden. Später erneut versuchen.</p>
                            <details v-if="row.missing_dates?.length" class="dates"><summary>Offene Tage ({{ row.missing_dates.length }})</summary><p>{{ row.missing_dates.map(formatDate).join(', ') }}</p></details>
                            <details v-if="run.repair && row.initial_missing_dates?.length" class="dates"><summary>Vor dem Neuaufbau fehlende Tage ({{ row.initial_missing_dates.length }})</summary><p>{{ row.initial_missing_dates.map(formatDate).join(', ') }}</p></details>
                        </div>
                    </details>
                    <p v-else-if="historyRows.length" class="consistency-good">Alle geprüften Titel sind vollständig.</p>
                    <details v-if="completeRows.length" class="consistency-details"><summary>{{ completeRows.length }} weitere vollständige Titel</summary><p>{{ completeRows.map(row => row.label).join(', ') }}</p></details>
                </section>

                <section class="consistency-section" aria-labelledby="period-heading">
                    <div class="section-heading"><div><h3 id="period-heading">Depotzeiträume</h3><p>Wochen, Monate und Jahre werden getrennt von den Kursen geprüft.</p></div><span>{{ run.checked ?? 0 }} geprüft</span></div>
                    <div class="consistency-metrics">
                        <div><strong>{{ run.missing ?? 0 }}</strong><span>fehlend oder veraltet</span></div>
                        <div><strong>{{ run.incorrect ?? 0 }}</strong><span>abweichend</span></div>
                        <div><strong>{{ run.unavailable ?? 0 }}</strong><span>nicht berechenbar</span></div>
                        <div v-if="run.repair"><strong>{{ run.repaired ?? 0 }}</strong><span>neu aufgebaut</span></div>
                    </div>
                    <p v-if="run.repair" class="consistency-muted">Fehlende und abweichende Werte zeigen den Stand vor dem Neuaufbau; nicht berechenbare Zeiträume bleiben offen.</p>
                    <details v-if="run.issues?.length || Object.keys(run.periods ?? {}).length" class="consistency-details">
                        <summary>Zeiträume und Feststellungen</summary>
                        <p v-for="(label, key) in periodLabels" :key="key">{{ label }}: {{ run.periods?.[key]?.checked ?? 0 }} geprüft · {{ run.periods?.[key]?.missing ?? 0 }} fehlend · {{ run.periods?.[key]?.incorrect ?? 0 }} abweichend · {{ run.periods?.[key]?.unavailable ?? 0 }} nicht berechenbar</p>
                        <div v-for="(issue, index) in run.issues" :key="index" class="consistency-item">
                            <strong>{{ issue.depot }} · {{ periodLabels[issue.period] }} bis {{ formatDate(issue.end_date) }}</strong>
                            <p>{{ reasons[issue.reason] }}<template v-if="issue.reason === 'incorrect' && issue.differences?.length">: {{ issue.differences.join(', ') }}</template></p>
                            <p v-if="issue.reason === 'incorrect'">Gespeichert {{ formatValue(issue.saved_amount, ' €') }} / {{ formatValue(issue.saved_percent, ' %') }} · Berechnet {{ formatValue(issue.calculated_amount, ' €') }} / {{ formatValue(issue.calculated_percent, ' %') }}</p>
                        </div>
                        <p v-if="run.issues?.length === 100" class="consistency-muted">Höchstens 100 Feststellungen werden angezeigt.</p>
                    </details>
                </section>
                <details v-if="run.steps?.length" class="consistency-details consistency-log"><summary>Verlauf</summary><ul><li v-for="(step, index) in run.steps" :key="index">{{ step }}</li></ul></details>
            </template>
        </v-card-text>
    </v-card>
</template>

<style scoped>
.consistency-card { border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 16px; }
.consistency-content { padding: 24px; }
.consistency-header, .section-heading, .item-heading { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
.consistency-header h2 { font-size: 1.35rem; line-height: 1.3; }
.consistency-intro, .section-heading p, .consistency-muted, .consistency-item p, .consistency-details p { color: rgba(var(--v-theme-on-surface), .68); font-size: .88rem; }
.consistency-intro { margin-top: 6px; }
.consistency-status { border-radius: 999px; padding: 7px 12px; background: rgba(var(--v-theme-primary), .09); color: rgb(var(--v-theme-primary)); font-size: .82rem; font-weight: 600; }
.consistency-actions { display: flex; gap: 20px; flex-wrap: wrap; margin-top: 22px; }
.consistency-actions > div { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; max-width: 300px; }
.consistency-actions small { color: rgba(var(--v-theme-on-surface), .68); }
.consistency-progress, .consistency-empty, .consistency-message { margin-top: 24px; }
.consistency-progress span { display: block; color: rgba(var(--v-theme-on-surface), .68); font-size: .88rem; margin-top: 4px; }
.consistency-message { font-weight: 600; }
.consistency-message span { color: rgba(var(--v-theme-on-surface), .62); font-size: .85rem; font-weight: 400; }
.consistency-section { margin-top: 24px; padding-top: 20px; border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.section-heading h3 { font-size: 1rem; }
.section-heading > span { color: rgba(var(--v-theme-on-surface), .65); font-size: .84rem; white-space: nowrap; }
.consistency-metrics { display: flex; flex-wrap: wrap; gap: 8px 28px; margin: 16px 0; }
.consistency-metrics > div { display: flex; flex-direction: column; min-width: 115px; }
.consistency-metrics strong { font-size: 1.5rem; line-height: 1.2; }
.consistency-metrics span { color: rgba(var(--v-theme-on-surface), .68); font-size: .82rem; }
.consistency-details { margin-top: 14px; font-size: .88rem; }
summary { cursor: pointer; color: rgb(var(--v-theme-primary)); font-weight: 600; }
summary:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: 3px; }
.consistency-item { padding: 13px 0; border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.item-heading span { font-size: .82rem; font-weight: 600; color: rgb(var(--v-theme-primary)); }
.consistency-item p { margin-top: 3px; }
.dates { margin-top: 6px; }
.dates p { overflow-wrap: anywhere; }
.consistency-good { color: rgb(var(--v-theme-success)); font-size: .88rem; }
.consistency-log ul { padding-left: 20px; color: rgba(var(--v-theme-on-surface), .68); }
@media (max-width: 600px) { .consistency-content { padding: 18px; } .consistency-actions { gap: 14px; } .consistency-metrics > div { min-width: 125px; } }
</style>
