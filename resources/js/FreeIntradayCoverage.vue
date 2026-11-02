<script setup>
defineProps({ report: { type: Object, required: true } });

const statusLabels = {
    complete: 'Dateien vollständig geprüft',
    no_trades: 'Dateien geprüft · kein Handel',
    incomplete: 'Abruf unvollständig',
    unresolved_corrections: 'Korrekturen offen · Daten ausgeschlossen',
    available_unverified: 'Anbieterdaten vorhanden · Vollständigkeit ungeprüft',
    unavailable: 'Quelle derzeit nicht verfügbar',
    unsupported_market: 'Keine bestätigte Quelle für diesen Handelsplatz',
};

function dateTime(value) {
    if (!value) {
        return 'Kein beobachteter Trade';
    }

    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString('de-AT', { timeZone: 'Europe/Vienna' });
}
</script>

<template>
    <v-card-text aria-label="Kostenlose Intraday-Abdeckung">
        <v-alert :type="Number(report.pending_files ?? 0) > 0 ? 'warning' : 'info'" variant="tonal" density="compact">
            Xetra: 15 Minuten verzögert. Dateiabdeckung ist kein Nachweis für einen Trade in jedem Intervall.
            Geprüft wird nur der unten angegebene veröffentlichte Zeitraum; der laufende Handelstag ist noch offen.
            Yahoo-OHLC bleiben getrennte, auf Vollständigkeit ungeprüfte Anbieterdaten.
            <div>Prüfstand: {{ report.checked_at ? dateTime(report.checked_at) : 'Noch kein Collector-Bericht' }}</div>
            <div>Offene Dateien: {{ report.pending_files ?? 'unbekannt' }} · Ausgeschlossene Altreihen: {{ report.excluded_legacy_rows ?? 0 }}</div>
        </v-alert>
        <v-table v-if="report.instruments?.length" density="compact" aria-label="Intraday-Abdeckung je Instrument">
            <thead>
                <tr><th>Instrument</th><th>Abdeckung</th><th>Letzte Datenzeit (Wien)</th><th>Fehler</th></tr>
            </thead>
            <tbody>
                <tr v-for="instrument in report.instruments" :key="instrument.isin">
                    <td>{{ instrument.symbol }} · {{ instrument.isin }} · {{ instrument.currency }}</td>
                    <td>{{ statusLabels[instrument.status] ?? instrument.status }}</td>
                    <td>{{ dateTime(instrument.trade_at) }}<div v-if="instrument.quote_age_minutes != null">{{ instrument.quote_age_minutes }} Minuten alt</div></td>
                    <td>{{ instrument.sync_error || instrument.error || instrument.warnings?.join(' ') || '—' }}</td>
                </tr>
            </tbody>
        </v-table>
        <details v-for="(day, date) in report.days" :key="date">
            <summary>
                {{ date }} · {{ day.processed_files }}/{{ day.expected_files }} Dateien ·
                {{ day.complete ? 'vollständig geprüft' : 'unvollständig' }} · {{ dateTime(day.from) }} bis {{ dateTime(day.until) }}
            </summary>
            <div v-for="file in day.missing_files" :key="file">{{ file }} · {{ day.file_errors?.[file] || 'noch nicht verarbeitet oder nicht angeboten' }}</div>
        </details>
        <div>Trades werden als Beobachtungen gespeichert; fehlende Intervalle werden nicht mit Kursen aufgefüllt.</div>
        <a :href="report.terms_url" target="_blank" rel="noopener noreferrer">Bedingungen: kostenlos bei nichtkommerzieller Nutzung; keine Verfügbarkeitsgarantie</a>
    </v-card-text>
</template>
