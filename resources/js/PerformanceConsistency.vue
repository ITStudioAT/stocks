<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { request } from './stores/auth';

defineProps({ showCard: Boolean });

const run = ref(null);
const error = ref('');
const loading = ref(false);
const running = computed(() => ['queued', 'running'].includes(run.value?.status));
const periodLabels = { week: 'Wochen', month: 'Monate', year: 'Jahre' };
const reasons = { missing: 'Zeitraum fehlt oder ist veraltet', incorrect: 'Gespeicherter Wert weicht ab', unavailable: 'Wert konnte nicht berechnet werden' };
const statusText = computed(() => {
    if (!run.value) return 'Noch nicht geprüft';
    if (running.value) return 'Prüfung läuft';
    if (run.value.status === 'failed') return 'Prüfung fehlgeschlagen';
    if (run.value.unavailable || run.value.missing || run.value.incorrect) return 'Prüfung mit Hinweisen';
    return 'Werte stimmen überein';
});
const statusIcon = computed(() => {
    if (running.value) return 'mdi-progress-clock';
    if (!run.value) return 'mdi-clipboard-search-outline';
    if (run.value.status === 'failed' || run.value.unavailable || run.value.missing || run.value.incorrect) return 'mdi-alert-circle-outline';
    return 'mdi-check-decagram-outline';
});
let timer;

function formatDate(date) {
    return date ? date.split('-').reverse().join('.') : '–';
}

function formatValue(value, suffix) {
    if (value === null || value === undefined) return 'nicht verfügbar';
    return new Intl.NumberFormat('de-AT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value)) + suffix;
}

async function refresh() {
    try {
        const response = await request('/admin/data/performance-consistency');
        run.value = response.run;
        error.value = '';
    } catch (exception) {
        error.value = exception.message;
    }
}

async function start(repair) {
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
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <v-alert v-if="running && !showCard" type="info" density="compact" class="mb-4" aria-live="polite">
        Datenkonsistenz: {{ run.step }} · {{ run.checked }} Zeiträume geprüft
    </v-alert>
    <v-card v-if="showCard" class="consistency-card mt-4" aria-label="Datenkonsistenz">
        <v-card-text class="consistency-content">
            <div class="consistency-header">
                <div>
                    <div class="consistency-eyebrow">DATA OVERVIEW</div>
                    <h2>Datenkonsistenz</h2>
                    <p class="consistency-intro">Abgeschlossene Wochen, Monate und Jahre werden mit den vorhandenen Buchungen und Kursen verglichen.</p>
                </div>
                <span class="consistency-status"><v-icon size="18" :icon="statusIcon" /> {{ statusText }}</span>
            </div>

            <v-alert v-if="error" type="error" class="mt-4">{{ error }}</v-alert>

            <template v-if="run">
                <div class="consistency-progress" aria-live="polite">
                    <v-progress-linear v-if="running" indeterminate color="primary" class="mb-3" />
                    <strong>{{ run.step }}</strong>
                    <div v-if="run.repair" class="consistency-muted">Die Zahlen zeigen die Feststellungen vor dem Neuaufbau.</div>
                </div>

                <div class="consistency-summary">
                    <div><span>Geprüft</span><strong>{{ run.checked }}</strong><small>abgeschlossene Zeiträume</small></div>
                    <div><span>Fehlend</span><strong>{{ run.missing }}</strong><small>fehlt oder ist veraltet</small></div>
                    <div><span>Abweichend</span><strong>{{ run.incorrect }}</strong><small>weicht vom neu berechneten Wert ab</small></div>
                    <div><span>Offen</span><strong>{{ run.unavailable }}</strong><small>nicht berechenbar</small></div>
                </div>

                <div v-if="run.periods" class="consistency-section">
                    <h3>Prüfung nach Zeitraum</h3>
                    <div class="consistency-periods">
                        <div v-for="(label, key) in periodLabels" :key="key" class="consistency-period">
                            <strong>{{ label }}</strong>
                            <span>{{ run.periods[key]?.checked ?? 0 }} geprüft</span>
                            <small>{{ run.periods[key]?.missing ?? 0 }} fehlend · {{ run.periods[key]?.incorrect ?? 0 }} abweichend · {{ run.periods[key]?.unavailable ?? 0 }} offen</small>
                        </div>
                    </div>
                </div>
                <p v-else-if="run.status === 'finished'" class="consistency-muted mt-4">Für eine Aufschlüsselung die Prüfung erneut starten.</p>

                <div v-if="run.issues?.length" class="consistency-section">
                    <h3>Feststellungen</h3>
                    <p class="consistency-muted">Abweichend bedeutet, dass Betrag, Prozentwert oder Quellenangabe vom neu berechneten Ergebnis abweichen.</p>
                    <div class="consistency-issues">
                        <div v-for="(issue, index) in run.issues" :key="index" class="consistency-issue">
                            <div><strong>{{ issue.depot }} · {{ periodLabels[issue.period] }} bis {{ formatDate(issue.end_date) }}</strong><br><span>{{ reasons[issue.reason] }}<template v-if="issue.reason === 'incorrect' && issue.differences?.length">: {{ issue.differences.join(', ') }}</template></span></div>
                            <div v-if="issue.reason === 'incorrect'" class="consistency-amounts">
                                Gespeichert {{ formatValue(issue.saved_amount, ' €') }} / {{ formatValue(issue.saved_percent, ' %') }}<br>
                                Berechnet {{ formatValue(issue.calculated_amount, ' €') }} / {{ formatValue(issue.calculated_percent, ' %') }}
                            </div>
                        </div>
                    </div>
                    <p v-if="run.issues.length === 100" class="consistency-muted mt-2">Es werden höchstens 100 Feststellungen angezeigt.</p>
                </div>

                <div v-if="run.steps?.length" class="consistency-section">
                    <h3>Verlauf</h3>
                    <ul class="consistency-steps"><li v-for="(step, index) in run.steps" :key="index">{{ step }}</li></ul>
                    <p v-if="run.repair" class="consistency-muted mt-2">{{ run.repaired }} Zeiträume neu aufgebaut.</p>
                </div>
            </template>
            <p v-else class="consistency-muted mt-5">Starte die Prüfung, um den Zustand der gespeicherten Zeiträume zu sehen.</p>
        </v-card-text>
        <v-card-actions class="consistency-actions">
            <v-btn :disabled="loading || running" variant="flat" color="primary" prepend-icon="mdi-magnify" @click="start(false)">Prüfen</v-btn>
            <v-btn :disabled="loading || running" variant="outlined" prepend-icon="mdi-refresh" @click="start(true)">Neu aufbauen</v-btn>
        </v-card-actions>
    </v-card>
</template>

<style scoped>
.consistency-card { border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 16px; }
.consistency-content { padding: 24px; }
.consistency-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; flex-wrap: wrap; }
.consistency-header h2 { font-size: 1.35rem; line-height: 1.3; }
.consistency-eyebrow { color: rgb(var(--v-theme-primary)); font-size: .72rem; font-weight: 700; letter-spacing: .08em; margin-bottom: 6px; }
.consistency-intro { color: rgba(var(--v-theme-on-surface), .7); max-width: 680px; margin-top: 6px; }
.consistency-status { display: inline-flex; gap: 6px; align-items: center; border-radius: 999px; padding: 7px 12px; background: rgba(var(--v-theme-primary), .09); color: rgb(var(--v-theme-primary)); font-size: .82rem; font-weight: 600; }
.consistency-progress { margin-top: 22px; }
.consistency-muted { color: rgba(var(--v-theme-on-surface), .65); font-size: .88rem; }
.consistency-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-top: 16px; }
.consistency-summary > div { display: flex; flex-direction: column; gap: 3px; padding: 15px; border-radius: 12px; background: rgba(var(--v-theme-on-surface), .045); }
.consistency-summary span, .consistency-summary small { color: rgba(var(--v-theme-on-surface), .65); }
.consistency-summary strong { font-size: 1.65rem; line-height: 1.2; }
.consistency-section { margin-top: 24px; }
.consistency-section h3 { font-size: 1rem; margin-bottom: 10px; }
.consistency-periods { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; }
.consistency-period { display: flex; flex-direction: column; gap: 4px; padding: 13px; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 10px; }
.consistency-period small { color: rgba(var(--v-theme-on-surface), .65); }
.consistency-issues { max-height: 340px; overflow: auto; margin-top: 10px; }
.consistency-issue { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 12px 0; border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); font-size: .88rem; }
.consistency-issue span, .consistency-amounts { color: rgba(var(--v-theme-on-surface), .7); }
.consistency-steps { padding-left: 20px; color: rgba(var(--v-theme-on-surface), .7); font-size: .88rem; }
.consistency-actions { padding: 0 24px 24px; flex-wrap: wrap; gap: 8px; }
</style>
