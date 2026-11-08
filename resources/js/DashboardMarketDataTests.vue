<script setup>
import DataSourceMarkers from './DataSourceMarkers.vue';
import { marketDataDisplayText, marketDataMarkers } from './utils/marketDataProvenance';
import { onBeforeUnmount, ref } from 'vue';
import { request } from './stores/auth';
import { formatPriceValue } from './utils/numberFormatters';

const loading = ref(false);
const result = ref(null);
const error = ref('');
const progress = ref('');
const stopReason = ref('');
let deutscheBoerseRetryAt = 0;
let yahooRetryAt = 0;
let abortController;
let timeLimit;

onBeforeUnmount(() => {
    clearTimeout(timeLimit);
    abortController?.abort();
});

async function runTest() {
    if (loading.value) {
        return;
    }
    loading.value = true;
    result.value = null;
    error.value = '';
    stopReason.value = '';
    abortController = new AbortController();
    const deadline = Date.now() + 30000;
    const exhaustedSources = new Set();
    let remainingIsins;
    let before = null;
    timeLimit = setTimeout(() => {
        stopReason.value = 'Zeitlimit von 30 Sekunden erreicht; die Suche ist unvollständig.';
        abortController.abort();
    }, 30000);
    try {
        for (const batch of [9, 0, 1, 2, 3, 4, 5, 6, 7, 8]) {
            const sourceCode = batch === 9 ? 'YAHOO' : ['DETR', 'DGAT', 'DFRA'][batch % 3];
            if ((batch === 9 && Date.now() < yahooRetryAt) || (batch !== 9 && Date.now() < deutscheBoerseRetryAt)) {
                error.value = 'Ein Anbieter befindet sich nach einer Sperre in der Abrufpause. Diese Abdeckung bleibt unvollständig; bitte später erneut versuchen.';
                continue;
            }
            if (exhaustedSources.has(sourceCode)) {
                continue;
            }
            if (Date.now() >= deadline) {
                stopReason.value = 'Zeitlimit von 30 Sekunden erreicht; die Suche ist unvollständig.';
                break;
            }
            progress.value = batch === 9 ? 'Yahoo Finance · US-Instrumente' : `${['Xetra', 'Tradegate', 'Frankfurt'][batch % 3]} · Suchrunde ${Math.floor(batch / 3) + 1} von 3`;
            const data = await request('/admin/dashboard/tests/deutsche-boerse', {
                method: 'POST', signal: abortController.signal,
                body: JSON.stringify({ batch, before, ...(remainingIsins ? { remaining_isins: remainingIsins } : {}) }),
            });
            before ??= data.test.search_before;
            result.value = result.value ? mergeResults(result.value, data.test) : data.test;
            if (data.test.rate_limited || data.test.provider_blocked) {
                if (batch === 9) {
                    yahooRetryAt = Date.now() + 60000;
                } else {
                    deutscheBoerseRetryAt = Date.now() + 60000;
                }
            }
            if (data.test.next_round == null) {
                exhaustedSources.add(sourceCode);
            }
            const unresolved = result.value.instruments.filter(instrument => instrument.price === null);
            remainingIsins = unresolved.filter(instrument => !instrument.us_instrument).map(instrument => instrument.isin);
            if (remainingIsins.length === 0) {
                stopReason.value = unresolved.length === 0 ? 'Für alle geprüften Instrumente wurde ein Kurs gefunden.'
                    : 'Begrenzte US-Suche abgeschlossen; fehlende US-Kurse bleiben ungeklärt.';
                break;
            }
        }
        stopReason.value ||= 'Begrenzte Suche abgeschlossen. Fehlende Treffer gelten nur für die tatsächlich gelesenen Segmente.';
    } catch (exception) {
        if (exception.name === 'AbortError') {
            return;
        }
        error.value = exception.status === 429
            ? 'Abruflimit erreicht. Bitte vor einem neuen Test eine Minute warten; die Suche ist unvollständig.'
            : (exception.message || 'Testabruf fehlgeschlagen. Bitte später erneut versuchen.');
    } finally {
        clearTimeout(timeLimit);
        loading.value = false;
    }
}

function stopSearch() {
    stopReason.value = 'Suche abgebrochen; die Abdeckung ist unvollständig.';
    abortController?.abort();
}

function mergeResults(previous, current) {
    const oldRows = new Map(previous.instruments.map(instrument => [instrument.isin, instrument]));
    current.instruments.forEach(instrument => {
        const old = oldRows.get(instrument.isin);
        if (old?.price != null) {
            return;
        }
        if (old?.state === 'correction' && old.trade_source === instrument.trade_source && instrument.price !== null) {
            return;
        }
        oldRows.set(instrument.isin, instrument);
    });
    const instruments = [...oldRows.values()];

    const latestObserved = [previous.latest_observed_trade_at, current.latest_observed_trade_at].filter(Boolean).sort().at(-1) ?? null;

    return { ...current, instruments, source: 'Automatische Suche · Deutsche Börse / Yahoo Finance', files: [...previous.files, ...current.files],
        coverage: [...(previous.coverage ?? []), ...(current.coverage ?? [])],
        warnings: [...new Set([...previous.warnings, ...current.warnings])],
        files_selected: previous.files_selected + current.files_selected,
        records_checked: previous.records_checked + current.records_checked,
        records_ignored: previous.records_ignored + current.records_ignored,
        duration_ms: previous.duration_ms + current.duration_ms,
        matched_instruments: instruments.filter(instrument => instrument.price !== null).length,
        latest_observed_trade_at: latestObserved,
        observed_age_minutes: latestObserved === null ? null : Math.max(0, Math.floor((new Date(current.checked_at) - new Date(latestObserved)) / 60000)),
    };
}

function dateTime(value) {
    const date = value ? new Date(value) : null;
    if (date === null || Number.isNaN(date.getTime())) {
        return 'Kein Datenzeitpunkt vorhanden';
    }

    return new Intl.DateTimeFormat('de-AT', {
        timeZone: 'Europe/Vienna', dateStyle: 'short', timeStyle: 'medium',
    }).format(date);
}

function finding(instrument) {
    if (instrument.state === 'found') {
        if (instrument.provider === 'Yahoo Finance') {
            return 'US-Marktkurs über Yahoo Finance gefunden';
        }
        return 'Trade in der Stichprobe gefunden';
    }
    if (instrument.state === 'correction') {
        return 'Korrektur/Storno in der Stichprobe – kein Preis ausgewiesen';
    }
    return instrument.state === 'not_xetra'
        ? 'Anderer Handelsplatz – nicht geprüft'
        : 'Kein Trade in den geprüften Segmenten';
}
</script>

<template>
    <section aria-label="Dashboard Tests">
        <v-card border flat rounded="xl">
            <v-card-title>Automatischer Kursdatentest · Europa / USA</v-card-title>
            <v-card-text>
                <p class="text-body-1 mb-3">
                    Deutsche Börse: öffentliche Handelsereignisse mit 15 Minuten Verzögerung laut Anbieter.
                    US-Instrumente wie ARGT: Yahoo Finance, inoffizieller Zugang, möglicherweise verzögert.
                    Keine garantierten Echtzeitkurse. US-Marktkurse sind keine verifizierten Einzeltrades.
                </p>
                <p class="text-body-2 text-medium-emphasis mb-4">
                    Ein Klick prüft automatisch bis zu drei US-Instrumente über Yahoo Finance (je ein Abruf,
                    insgesamt sechs Sekunden). Für die anderen Instrumente: zuerst Xetra,
                    dann für fehlende Treffer automatisch Tradegate und Frankfurt. Anschließend werden ältere
                    verfügbare Segmente geprüft. Je Quelle zuerst höchstens vier Dateien, danach zwei ältere
                    Stichproben mit je zwei Dateien (insgesamt höchstens 24), sechs Sekunden je Teilabruf und 30 Sekunden insgesamt.
                    Quellenfehler verhindern die Prüfung anderer Quellen nicht. Keine Speicherung in den Kurstabellen.
                </p>
                <v-btn color="primary" prepend-icon="mdi-flask-outline" type="button" :loading="loading" :disabled="loading" @click="runTest">
                    Testabruf starten
                </v-btn>
                <v-btn v-if="loading" class="ms-2" variant="text" @click="stopSearch">Suche abbrechen</v-btn>
                <p v-if="loading" class="text-body-2 mt-3" role="status">Katalog und begrenzte Dateistichprobe werden gelesen … {{ progress }}</p>
                <p v-if="stopReason" class="text-body-2 mt-3" role="status">{{ stopReason }}</p>
                <v-alert v-if="error" class="mt-4" type="error" variant="tonal" role="alert">{{ marketDataDisplayText(error) }}</v-alert>
                <p v-if="!result && !loading && !error" class="text-body-2 text-medium-emphasis mt-4">
                    Noch kein Test ausgeführt. Der Abruf startet erst auf Knopfdruck.
                </p>
            </v-card-text>
        </v-card>

        <v-card v-if="result" class="mt-4" border flat rounded="xl" aria-label="Deutsche Börse Testergebnis">
            <v-card-title>{{ loading ? 'Laufende Stichprobe' : 'Ergebnis der begrenzten Suche' }}</v-card-title>
            <v-card-text>
                <v-alert class="mb-4" type="info" variant="tonal">
                    Dieses Ergebnis ist eine Momentaufnahme des Testabrufs, keine Aussage zur aktuellen oder vollständigen Marktabdeckung.
                    Bei geschlossenem Markt oder illiquiden Instrumenten können Trades fehlen. Fehlende Werte bleiben leer.
                    Deutsche-Börse-Treffer benötigen passende ISIN und Währung. US-Kurse werden anhand
                    Bestandsticker, USD und US-Börse geprüft; Yahoo bestätigt keine ISIN und keinen Ausführungs-MIC.
                    Unterschiedliche Zeitpunkte, Kurstypen oder MICs erlauben keinen direkten Qualitätsvergleich.
                </v-alert>
                <p class="mb-4 text-body-2">Nicht geprüfte Zwischenzeiten bleiben offen. Die erste Stichprobe erfolgt auf Xetra; alternative Quellen werden nur für Instrumente ohne Treffer geprüft. Angezeigt wird der jüngste Trade aus den für dieses Instrument gelesenen Dateien vor dem Quellenwechsel.</p>
                <ul class="mb-4 text-body-2" aria-label="Geprüfte Quellen und Abdeckung">
                    <li v-for="coverage in result.coverage" :key="`${coverage.source_code}-${coverage.round}`">
                        {{ marketDataDisplayText(coverage.source) }} · Runde {{ coverage.round + 1 }} · <template v-if="coverage.source_code === 'YAHOO'">{{ coverage.requests }} US-Anfragen</template><template v-else>{{ coverage.files }} von {{ coverage.selected }} Dateien</template>
                        · {{ coverage.status === 'failed' ? 'Quellenfehler – keine Abdeckung' : (coverage.status === 'partial' ? 'teilweise geprüft / Fehler oder Limit' : (coverage.status === 'skipped' ? 'keine passenden US-Instrumente' : 'geprüft')) }}
                        <span v-if="coverage.from"> · {{ dateTime(coverage.from) }} bis {{ dateTime(coverage.to) }} ({{ coverage.source_code === 'YAHOO' ? 'Datenzeiten der US-Kurse' : 'Dateisegmente, keine lückenlose Zeitreihe' }})</span>
                    </li>
                </ul>
                <dl class="text-body-2 mb-4">
                    <dt>Test abgeschlossen · Europe/Vienna</dt><dd>{{ dateTime(result.checked_at) }}</dd>
                    <dt>Quellen</dt><dd>{{ marketDataDisplayText(result.source) }} <DataSourceMarkers :markers="['free']" /> · Deutsche Börse: 15 Minuten verzögert; Yahoo: Verzögerung unbekannt</dd>
                    <dt>Katalog erstellt</dt><dd>{{ dateTime(result.catalog_generated_at) }}</dd>
                    <dt>Jüngstes ausgewähltes Dateisegment</dt><dd>{{ dateTime(result.newest_catalog_segment_at) }}</dd>
                    <dt>Jüngster tatsächlich gelesener Quellzeitpunkt</dt>
                    <dd>{{ dateTime(result.latest_observed_trade_at) }}<span v-if="result.observed_age_minutes !== null"> · beim Test etwa {{ result.observed_age_minutes }} Minuten alt</span></dd>
                    <dt>Umfang</dt><dd>{{ result.files.length }} von {{ result.files_selected }} ausgewählten Segmenten gelesen · {{ result.records_checked }} gültige Quellwerte / Ereignisse · {{ result.matched_instruments }} Instrumente mit Treffer · {{ result.duration_ms }} ms</dd>
                </dl>
                <v-alert v-if="result.files.length === 0 && result.matched_instruments === 0" class="mb-3" type="warning" variant="tonal">
                    Kein Dateisegment konnte geprüft werden oder der Katalog enthält keine passenden Dateien. Damit ist keine Kursaussage möglich.
                </v-alert>
                <v-alert v-else-if="result.records_checked === 0" class="mb-3" type="warning" variant="tonal">
                    Dateien wurden gelesen, aber darin wurden keine gültigen Handelsereignisse gefunden.
                </v-alert>
                <v-alert v-else-if="result.matched_instruments === 0" class="mb-3" type="warning" variant="tonal">
                    Die Quelle enthält Handelsereignisse, aber keine passenden Trades für die Instrumente dieser Stichprobe.
                </v-alert>
                <v-alert v-if="result.instrument_limit_reached" class="mb-3" type="info" variant="tonal">Es werden nur die ersten 50 Instrumente nach Name geprüft.</v-alert>
                <v-alert v-if="result.records_ignored" class="mb-3" type="info" variant="tonal">{{ result.records_ignored }} nicht preisfähige oder korrigierte Meldungen wurden übersprungen.</v-alert>
                <v-alert v-for="warning in result.warnings" :key="warning" class="mb-3" type="warning" variant="tonal">{{ marketDataDisplayText(warning) }}</v-alert>
                <v-table density="compact" aria-label="Instrumente der Deutsche-Börse-Stichprobe">
                    <thead><tr><th>Instrument / ISIN</th><th>Gefundener Quellkurs / Trade</th><th>Zuletzt gespeicherter gültiger Kurs</th><th>Vergleich / Befund</th></tr></thead>
                    <tbody>
                        <tr v-for="instrument in result.instruments" :key="instrument.isin">
                            <td>{{ instrument.name }}<div class="text-caption text-medium-emphasis">{{ instrument.symbol }} · {{ instrument.isin }} · {{ instrument.exchange }} / {{ instrument.mic_code || '–' }}</div></td>
                            <td>
                                <div>{{ instrument.price === null ? '–' : formatPriceValue(instrument.price) }} <span v-if="instrument.price !== null">{{ instrument.currency }}</span></div>
                                <div>{{ instrument.trade_at ? dateTime(instrument.trade_at) : '–' }}</div>
                                <div v-if="instrument.provider === 'Yahoo Finance'" class="text-caption">Yahoo Finance · {{ instrument.quote_venue || 'US-Börse ungeklärt' }} · Listing-MIC {{ instrument.listing_mic || '–' }} · Ausführungs-MIC unbekannt · {{ instrument.price_type || 'kein Kurs' }}</div>
                                <div v-else class="text-caption">Deutsche Börse · {{ instrument.trade_source || 'Xetra' }} · MIC {{ instrument.trade_venue || '–' }}</div>
                                <div v-if="instrument.quote_note" class="text-caption">{{ marketDataDisplayText(instrument.quote_note) }}</div>
                            </td>
                            <td>
                                <template v-if="instrument.stored_quote">
                                    <div>{{ formatPriceValue(instrument.stored_quote.price) }} {{ instrument.stored_quote.currency }}</div>
                                    <div>{{ instrument.stored_quote.as_of ? dateTime(instrument.stored_quote.as_of) : `Tagesschluss ${instrument.stored_quote.trading_date} (keine Uhrzeit)` }}</div>
                                    <div class="text-caption">{{ marketDataDisplayText(instrument.stored_quote.source) }} <DataSourceMarkers :markers="marketDataMarkers(instrument.stored_quote)" /> · {{ instrument.stored_quote.venue || 'Handelsplatz unbekannt' }} · MIC {{ instrument.stored_quote.mic || '–' }} · {{ instrument.stored_quote.price_type }}</div>
                                    <div v-if="instrument.stored_quote.stale" class="text-caption text-warning">Gespeicherter Kurs veraltet (älter als vier Tage)</div>
                                </template>
                                <span v-else>Kein gespeicherter Kurs</span>
                            </td>
                            <td>
                                <div>{{ instrument.comparison?.label }}</div>
                                <div v-for="reason in instrument.comparison?.reasons" :key="reason" class="text-caption">{{ reason }}</div>
                                <div class="text-caption">{{ finding(instrument) }}</div>
                            </td>
                        </tr>
                        <tr v-if="result.instruments.length === 0"><td colspan="4">Keine Instrumente mit ISIN im Bestand.</td></tr>
                    </tbody>
                </v-table>
                <details class="mt-4">
                    <summary>Geprüfte Dateisegmente und Quellenhinweis</summary>
                    <ul><li v-for="file in result.files" :key="file.name">{{ file.name }} · {{ dateTime(file.segment_at) }} · {{ file.records }} Ereignisse</li></ul>
                    <p class="mt-2">Alle Uhrzeiten werden in Europe/Vienna angezeigt. Die Dateisegmente können aus getrennten Zeitfenstern stammen. Zwischenzeiten wurden nicht geprüft. Der Ausführungs-MIC stammt unverändert aus der Quelldatei. Frankfurt und Tradegate sind alternative Handelsplätze.</p>
                    <a href="https://www.mds.deutsche-boerse.com/mds-en/real-time-data/Delayed-data" target="_blank" rel="noopener noreferrer">Anbieterinformationen und Nutzungsbedingungen</a>
                    <p class="mt-2">Yahoo Finance: inoffizieller Zugang ohne Verfügbarkeitsgarantie; bei Sperre oder Abruflimit wird nicht erneut versucht. <a href="https://finance.yahoo.com/quote/ARGT/" target="_blank" rel="noopener noreferrer">ARGT bei Yahoo Finance</a></p>
                </details>
            </v-card-text>
        </v-card>
    </section>
</template>
