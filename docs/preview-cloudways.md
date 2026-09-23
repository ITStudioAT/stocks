# Stocks-Vorschau auf Cloudways

## Stand und lokale Befehle

`gitstart`, `gitsave`, `gitwork`, `gitmain`, `gitupdate`, `gitprepare` und `gitcheck` bleiben der Geräte-/Feature-Workflow. `gitpreview prepare` schreibt bei sauberem Checkout einen **lokalen Prüfplan für den exakten Commit** unter `.git/stocks-preview/`. Der Befehl lädt nichts hoch und kopiert keine Daten. Feature-Pushes unter `codex/**` durchlaufen jetzt die PHP-/Frontend-CI; nur main benötigt das bestehende Release-Artefakt.

Dieser Stand liefert die Laufzeitisolation, Konfigurationsprüfung und getestete Snapshot-Formatbausteine. Ein produktiver Snapshot-Export/-Import, die Erstinstallation, ein Backup-/Restore-Ablauf und ein serverbestätigter Preview-Beleg sind noch nicht freigeschaltet. `gitpreview deploy`, `gitrelease`, `gitdeploy` und `gitdiscard` dürfen nicht als fertig eingerichtet betrachtet werden. Bis zur Zielprüfung bleibt `gitpush` der bestehende, ausdrücklich auf main begrenzte Veröffentlichungsweg; er ist kein Vorschau-Deploy.

## Bestätigtes Ziel

Die nicht geheimen Identitäten stehen in `scripts/stocks_preview_target.json`:

| | LIVE | Vorschau |
| --- | --- | --- |
| Anwendung | stocks, 6468818 | Stocks-Feature, 6690486 |
| Domain | gkstocks.at | vorschau.gkstocks.at |
| Datenbank / DB-Benutzer | cfbckymfgk | hbnucgvzmy |

Beide liegen auf Server2025, ID 1486907, 165.227.156.99. Preview-Ordner: `hbnucgvzmy`; Webroot: `public_html/public`. Der absolute kanonische Pfad und Unix-Login sind noch nicht bestätigt. Cloudways meldet serverseitig PHP 8.4; Web-Patchversion und CLI-Version sind separat zu prüfen. Preview-SSH ist noch deaktiviert, die App-SSH/SFTP-Tabelle leer.

Die Laravel-10-Vorlage ist nur der anfängliche Inhalt. Stocks bringt Laravel 13 über `composer.lock` mit und verlangt PHP ^8.4.1. Keine Vorlagen-`.env`, `vendor`- oder Bootstrap-Caches übernehmen. Die vorhandenen Deploy-Skripte ersetzen nicht automatisch alle Vorlagenreste.

## Einmalige Einrichtung – vor der Erstinstallation

1. Nur für App 6690486 einen Anwendungs-SSH-Zugang mit eingeschränkten Dateirechten einrichten. Mit diesem Login `id -un`, `pwd -P`, den kanonischen App-Pfad, `php -v` und `composer check-platform-reqs` nach Installation prüfen. Web-PHP gesondert verifizieren. Keine globale PHP-Änderung wegen der Preview vornehmen.
2. Vorschau über Cloudways-Passwortschutz oder eine IP-Freigabe privat halten, HTTPS beibehalten und Varnish/CDN-Caching für sie deaktivieren. Ein Robots-Header ersetzt keinen Zugriffsschutz. Keine Cronjobs, Queue-Worker oder Mailtransporte einrichten.
3. Neue App-Konfiguration ausschließlich im Ziel anlegen. Eigener zufälliger APP_KEY, keine APP_PREVIOUS_KEYS, kein Kopieren der Live-`.env`, keine EODHD-, Cloudways-, SMTP-, AWS- oder KI-Schlüssel. Live-APP_KEY nur in seiner Live-Umgebung in SHA-256 des **dekodierten Schlüsselmaterials** umwandeln; ausschließlich den Fingerprint für den Vergleich übertragen. Keine Schlüssel im Chat, Git oder Prüfplan.
4. Vor dem ersten Start dauerhaft die Datei `storage/framework/stocks-preview-instance` im eigenen Ziel anlegen. Sie bleibt bei allen späteren Releases erhalten und aktiviert den Schutz auch bei veralteter Config. Speicher-/Session-/Cachepfade dürfen weder auf Live noch auf einen gemeinsamen Symlink zeigen. Preview-Dateirechte dürfen keinen Zugriff auf Live-`.env` oder Live-Speicher zulassen.
5. Datenbankzugriff nur als `hbnucgvzmy`. Der Connector prüft vor Anwendungsabfragen `DATABASE()` und `SHOW GRANTS FOR CURRENT_USER`: nur USAGE global und Rechte auf exakt `hbnucgvzmy` erlaubt, keine Rollen, anderen Schemas, Wildcards oder GRANT OPTION. Ein anderer DB-Name allein beweist keine Isolation. Die Prüfung verändert keine Rechte.

Konfigurationswerte für das Ziel (Platzhalter erst nach Verifikation ersetzen):

```dotenv
APP_ENV=preview
APP_DEBUG=false
APP_URL=https://vorschau.gkstocks.at
STOCKS_PREVIEW=true
PREVIEW_SERVER_ID=1486907
PREVIEW_SOURCE_APP_ID=6468818
PREVIEW_TARGET_APP_ID=6690486
PREVIEW_SOURCE_URL=https://gkstocks.at
PREVIEW_SOURCE_DATABASE=cfbckymfgk
PREVIEW_SOURCE_DATABASE_USER=cfbckymfgk
PREVIEW_SOURCE_KEY_SHA256=<nur Fingerprint>
PREVIEW_TARGET_ROOT=<verifizierter kanonischer App-Pfad>
DB_CONNECTION=mysql
DB_DATABASE=hbnucgvzmy
DB_USERNAME=hbnucgvzmy
CACHE_STORE=file
SESSION_DRIVER=file
SESSION_COOKIE=__Host-stocks-preview-6690486
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_ENCRYPT=true
SESSION_SAME_SITE=strict
QUEUE_CONNECTION=sync
MAIL_MAILER=array
FILESYSTEM_DISK=local
APP_MAINTENANCE_DRIVER=file
AWS_USE_DEFAULT_CREDENTIALS=false
```

APP_KEY und DB_PASSWORD sicher nur im Ziel setzen. SESSION_DOMAIN muss leer/null bleiben. Keine DB_URL-, Read/Write- oder Socket-Overrides. `php artisan preview:check` ist ausschließlich eine Konfigurationsprüfung ohne Datenbankzugriff; Erfolg beweist noch keine Serveridentität oder erfolgreiche Installation. Fehler zeigen Feldnamen, keine Werte. Bei ungültiger Konfiguration antwortet HTTP mit 503 und Datenbankverbindungen bleiben gesperrt.

## Datenkopie und Rückkehr zum vorherigen Stand

Die Vorbereitung kopiert **noch keine Daten**. `PreviewSnapshotPolicy` lässt ausdrücklich nur Depot-/Transaktions-/Wertpapier-/Kurstabellen zu. IDs, Beziehungen, Bestände und Beträge bleiben erhalten; Kontonummern, Notizen, Beschreibungen, Rohantworten und URLs werden entfernt, Depotnamen ersetzt. Die genauen Tabellenspalten, Nullbarkeit, Fremdschlüssel und ggf. verschlüsselten Werte müssen vor dem Export mit dem tatsächlichen Schema abgeglichen werden. Unbekannte Tabellen und credential-artige Spalten werden zurückgewiesen. Bestehende Daten sind trotz Bereinigung vertrauliche Finanzdaten.

Keine Live-Benutzer, Passwörter, Rollen, Tokens, Sessions, Login-Codes, Jobs, Caches, generischen App-Einstellungen oder KI-Verläufe übernehmen. Einen eigenen Vorschau-Admin später gezielt im Ziel provisionieren. Mail bleibt blockiert; für die Vorschau ist eine eigene Passwortanmeldung nötig.

`PreviewSnapshotArchive` ist ein getestetes, größenbegrenztes In-Memory-Format mit Sodium-Empfängerverschlüsselung, App-IDs, Quell-/Ziel-Commit und einmaligem Nonce. Der SHA-256 des Chiffretexts muss über einen authentifizierten Kanal bestätigt werden: Empfängerverschlüsselung allein bestätigt nicht den Absender. Der zukünftige Importer muss verbrauchte Nonces dauerhaft protokollieren. Die Klasse selbst schreibt keine Dateien und ersetzt keine Datenbank. Keine rohen SQL-Dumps ausführen.

Vor jedem späteren Import: Quell-Export über eine konsistente **READ ONLY**-Transaktion, vollständige Schema-/Inhaltsprüfung, verschlüsseltes Backup des bisherigen Preview-Codes/DB/Zustands außerhalb `public`, getestete Wiederherstellung, eigener Lock und dauerhafter Pending-Marker. Erst danach dürfen ausschließlich Zieltabellen ersetzt und kompatible Migrationen ausgeführt werden. Bei Fehlern Wartungszustand beibehalten, Daten und Code gemeinsam wiederherstellen. Vor jedem Deploy App-ID, kanonischen Root, Unix-Owner, aktuelle Commit-ID und Zustand erneut prüfen. Keine Live-Migration, kein Sync zurück zu Live.

## Laufzeitgrenzen

Externe Stocks-Integrationen, Laravel-HTTP, Laravel-AI-Ereignisse, Mail, Redis, entfernte Dateisysteme, fremde Cache-/Queue-Treiber und Broadcasts werden blockiert. Scheduler ist leer. CLI erlaubt zunächst nur `preview:check`, `list`, `help`, `about`; bestehende Migrations-/Update-/Deploy-Befehle bleiben gesperrt. Auch Cache-Löschbefehle bleiben gesperrt, damit fehlerhafte Pfad-Overrides keine Live-Dateien entfernen. Logging wird in dieser Vorbereitungsstufe verworfen, damit keine kopierte Remote-Log-Konfiguration Finanzdaten überträgt.

Das ist eine Anwendungsgrenze, keine Sandbox für beliebigen PHP-Code. Direkte PDO-/cURL-/Prozesszugriffe aus neuem Feature-Code benötigen zusätzlich Betriebssystem-/Netzwerkbeschränkungen und Codeprüfung. FPM-`disable_functions` ist kein Beweis für entsprechende CLI-Beschränkungen.

Nach erfolgreicher Zielprüfung folgen erst der dedizierte Installer, Export/Import mit Restore-Test, Smoke-Test und ein an Ziel/Commit gebundener Deploy-Beleg. Erst auf dieser Grundlage werden die Release-/Deploy-/Discard-Kommandos erweitert. Weder eine manuell gesetzte Flag noch ein lokaler Prüfplan darf diese Gates überspringen.
