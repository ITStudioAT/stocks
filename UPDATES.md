
## 2.0.1

### System

- Main Update

- Cloudways unter Data ist ausschließlich lokal für Super-Administratoren verfügbar. Online sind der Menüpunkt und die zugehörigen Zugriffe gesperrt.
- Zentrale Kursablage in `market_prices`, gemeinsame Instrument-/Börsenplatz-Zuordnung und nachvollziehbare Kurskorrekturen ohne Verlust der Altdaten.
- Koordinierte kostenlose Kursabrufe alle fünf Minuten nach Börsenkalender und Zeitzone; bekannte Verzögerungen und Abrufpausen bleiben sichtbar.
- Kostenlose Tageshistorie über geprüfte Yahoo-Zuordnungen, einschließlich Take-Two auf Xetra in EUR. Neue und vorhandene Kurse verwenden dieselbe Ablage.
- Vorbereitete historische Leser, gebündelte Watchlist-Abfragen und passende Leseindizes; Cashflow-Bereinigung der Depotkurve bleibt erhalten.
- `gitdeploy` bereitet die Datenumstellung im Wartungsmodus vor: private MySQL-Sicherung mit Prüfsumme, Schreibsperren für alte Queue-Arbeiten, wiederaufnehmbare Übernahme und vollständige Mengen-/Wertprüfung vor der dauerhaften Aktivierung.
- Alte Kurstabellen bleiben für den Rückfall erhalten. Bei Fehlern wird nicht auf einen unvollständigen zentralen Bestand umgeschaltet. `gitsave` pusht auf `main` nach GitHub; lokale Datenbank und `.env` werden nicht hochgeladen.
- Wiederherstellung ist über den gesicherten Upgrade-Manifestpfad und die ausdrückliche Bestätigung des Datenbanknamens möglich; laufende Queue-Arbeiten verhindern den Restore.

## 1.2.1

### System

- Veröffentlichung: Flow mit Ankündigung und Refresh


## 1.2.0

### System

- Data-Flow without paying

## 1.1.3

### System

- Git-Workflow-changed

## 1.1.2

### System

- Der abgesicherte `gitpush`-Befehl veröffentlicht Stocks-Releases; andere Repositories behalten ihren bisherigen persönlichen Helfer.
- Feature-Namen werden beim Start auf GitHub reserviert. Speichern, Freigeben und Verwerfen schützen Branch und Reservierung gegen parallele Änderungen.
- `gitrelease` startet GitHub-CI wie bei Schooltool im Hintergrund; `gitcheck` zeigt offene Features und den Stand je Gerät genauer an.

## 1.1.1

### System

- `gitdeploy` prüft weiterhin den exakten Release und das Paket; GitHub-CI läuft wie bei Schooltool im Hintergrund und blockiert den Live-Deploy nicht.
- Git-Ablauf für Entwicklung, Vorschau und Veröffentlichung aktualisiert.

## 1.1.0

### System

- Implementierung einer Vorschau-Option vorschau.gkstocks.at

## 1.0.2

### Depot

- Actual months value for 1.xx fixed
- Speed up

## 1.0.0 - 1.0.1

### AI-ANALYSIS

- Show this under Stocks and under Depot and on the Dashboard

### ANALYSIS

- Research/Settings: Count of rows !!!

## 0.8.12

### Depots/Cash

- Adding new Cash calculation

## 0.8.10-0.8.11

### ANALYSIS

- New Analysis types

## 0.8.9

### BUY/SELL-Signals

- Show this under Stocks and under Depot and on the Dashboard

### Analyse

- Signal, if a holded stock, should be sold

## 0.8.8

### System

- Major Security Update with Sol Ultra

### Analyze

- Research-Menu-Item Added
- Simulation added
- Trend removed, Trend v2 is the actual version

## 0.8.7

### Dashboard

- Summe Woche, Monat, Jahr hinzugefügt

### Data

- Sync Cloudways to local

### Analyze

- Trend v2

### System

- Transfer SSHX-Command to other Devices

## 0.8.6

### Dashboard

- Infos über die Handelswoche

## 0.8.3-0.8.5

### Test

- Test-Version

## 0.8.2

### Depot

- Previous Day prices synchronized
- Vortages-Balance hinzugefügt

### Depot

## 0.7.0 - 0.8.1

### Depot

- Eigener Cash-Menüpunkt
- Auswertung aller Stocks aus der Vergangenheit

## 0.6.9

### System

- Deployment fixed

## 0.6.8

### System

- Schließen einer Info-Card bleibt geschlossen
- Git Cl Workflow Fixed

### Data

- Ohne Selektion Anzeige der Gesamtdaten

### Indices

- Trading-Zeitren werden angezeigt

### Dashboard

- New Dashboard

## 0.6.7

### Data-Healtch

- New Menu-Item

## 0.6.6

### System

- Indizes Status Anzeige gefixt

### Stocks

- Untertitel hinzugefügt

### Data

- Completly new Data-Menu-Item

## 0.6.5

### Indices

- Own Dashboard menu-item
- add, remove indices
- show charts for all date-areas
- Sync all indices

### Stocks

- Own Dashboard menu-item
- add, remove stocks
- sync all stocks

### Queue: Worker

- Memory fixes

### System

- Composer Dev fixed
