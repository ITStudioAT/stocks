# Stocks-Vorschau auf Cloudways

## Stand und lokale Befehle

`gitstart`, `gitsave`, `gitwork`, `gitmain`, `gitupdate`, `gitprepare` und `gitcheck` bleiben der Geräte-/Feature-Workflow. `gitpreview prepare` schreibt bei sauberem Checkout einen **lokalen Prüfplan für den exakten Commit** unter `.git/stocks-preview/`. `gitpreview bundle` verlangt erfolgreiche GitHub-CI für genau diesen gespeicherten Commit und baut in einem eigenen Verzeichnis ein ZIP mit Frontend und Produktions-PHP-Abhängigkeiten aus den Lockfiles. Der Befehl lädt nichts hoch und kopiert keine Daten. Feature-Pushes unter `codex/**` durchlaufen die PHP-/Frontend-CI; nur main benötigt das bestehende Release-Artefakt.

Die Erstinstallation auf Stocks-Feature wurde am **24. September 2026 (Europe/Vienna)** einschließlich Freigabe und tatsächlichem Browser-Login abgeschlossen. Der vollständige Originaldaten-Export ist inzwischen ausschließlich lesend über verifiziertes TLS abgeschlossen: **690.136 Datensätze aus 35 Tabellen**, verschlüsseltes Archiv mit 307.125.462 Bytes. Das Archiv wurde vollständig entschlüsselt, strukturell geprüft und bis zum authentifizierten Abschluss gelesen; die Tabellenzahlen stimmen mit dem Exportbeleg überein. **Auf Stocks-Feature wurde noch kein Datenimport ausgeführt.** Für Upload und Ausführung fehlt dem Agenten weiterhin ein authentifizierter Vorschau-Zugang. `gitpreview deploy`, `gitrelease`, `gitdeploy` und `gitdiscard` dürfen nicht als fertig eingerichtet betrachtet werden. `gitpush` bleibt der bestehende, ausdrücklich auf main begrenzte Veröffentlichungsweg; er ist kein Vorschau-Deploy.

## Nachweis der abgeschlossenen Erstinstallation

Die folgenden Remote-Ergebnisse wurden über die eigene SSH-Sitzung und den Browser des Nutzers bestätigt, zuletzt mit einem Screenshot des angemeldeten Dashboards. Der Agent hatte keinen eigenen authentifizierten SSH-Zugang. Diese Dokumentation ist ein manueller Abschlussnachweis und kein automatisch erzeugter Serverbeleg für spätere Deployments.

| Nachweis | Bestätigter Stand |
| --- | --- |
| Ziel | Stocks-Feature, App 6690486, Server 1486907 |
| Installierter Anwendungscode | `75e531e6b022a09c424d6c73db4bff6923493b53` |
| ZIP-SHA-256 | `aa154c794bd285629b64d57e6d59c544e916ad396dff60fee8d2819c7b136bfd` |
| Zusatzhelfer zur Vorlagenkonfiguration | Commit `c182a93a8f6789aa96dcab4082e200e36718ccd3`; Anwendungspaket unverändert |
| Root / HTTP-DocumentRoot | `/home/1486907.cloudwaysapps.com/hbnucgvzmy/public_html` / `public_html/public` |
| Dateirechte | Root UID 1013 / GID 33 / 775; privater Arbeitsbereich 0700; neue `.env` 0600 |
| Eigene DB / eigener DB-Benutzer | `hbnucgvzmy`; Datenbankrechte auf eigenes Schema und anfängliche Leere geprüft |
| CLI / Web-PHP | 8.4.25 / 8.4.25, Web-SAPI `fpm-fcgi`; Zip, Sodium und PDO-MySQL vorhanden |
| Web-Dateiidentität | Exklusiv vom Webprozess erstellte neue Datei mit UID 1013; anschließend entfernt; `.env` lesbar |
| Initialisierung / Freigabe | `preview:check`, `preview:initialize` und `preview:activate --web-php=8.4.25` erfolgreich |
| HTTP-Schutz | Ohne Basic Auth 401; mit Basic Auth 200; `X-Stocks-Preview: true`, `Cache-Control: no-store`, `X-Robots-Tag: noindex` bestätigt |
| Browser-Login | Angemeldetes Dashboard unter `https://vorschau.gkstocks.at/admin/dashboard`, sichtbare Version 1.0.2 |
| Datenbestand | Neues Preview-Schema mit eigenem Admin; Dashboard zeigt „Keine Daten“; keine Live-Datenkopie |

Die vollständigen CI-Läufe für [Anwendungspaket 75e531e](https://github.com/ITStudioAT/stocks/actions/runs/35930318882) und [Zusatzhelfer c182a93](https://github.com/ITStudioAT/stocks/actions/runs/35932176927) waren erfolgreich. Root-erhaltender Austausch, Wiederherstellung nach Unterbrechungen, Dateiintegrität, Isolation und Konfigurationsänderung wurden getestet. Die ursprüngliche Vorlage wurde unter `/home/1486907.cloudwaysapps.com/hbnucgvzmy/public_html/.stocks-preview-private/template-4d55f90649a111fee8953a409c94c5d0` gesichert. Nach Freigabe ist der begrenzte `restore-template`-Befehl gesperrt; diese Sicherung ist kein Datenbank-Rollback für spätere Updates.

Die beiden eigenen Zugänge wurden ausschließlich im Nutzerterminal abgerufen. Keine Passwörter oder Schlüssel werden hier dokumentiert. Für die Anmeldung zuerst den Basic-Auth-Zugang verwenden, anschließend unter `https://vorschau.gkstocks.at/admin/login` den Tab **Password** mit dem eigenen Preview-Admin. Temporäre Web-Prüfdateien wurden entfernt. Live und dessen Datenbank wurden nicht verändert; `main` blieb auf `4cb0ab0d532c75738c4ca19dd29185f90fe9b740`.

Für diese Erstinstallation sind keine weiteren Prüfungen offen. Die nachfolgende Installationsanleitung dient als Referenz und darf nicht erneut auf die nun aktive Preview angewendet werden. Der separat autorisierte Originaldatenimport ist vorbereitet und lokal getestet, seine Durchführung auf dem Server steht noch aus. Sichere Folge-Updates sowie die automatisierten Preview-/Release-/Deploy-/Discard-Kommandos bleiben weitere Ausbauarbeiten.

## Bestätigtes Ziel

Die nicht geheimen Identitäten stehen in `scripts/stocks_preview_target.json`:

| | LIVE | Vorschau |
| --- | --- | --- |
| Anwendung | stocks, 6468818 | Stocks-Feature, 6690486 |
| Domain | gkstocks.at | vorschau.gkstocks.at |
| Datenbank / DB-Benutzer | cfbckymfgk | hbnucgvzmy |

Beide liegen auf Server2025, ID 1486907, 165.227.156.99. Preview-Ordner: `hbnucgvzmy`; Webroot: `public_html/public`. Der Nutzer hat SSH als `sftp_for_gkstocks_feature` eingerichtet; die Sitzung meldet `whoami=hbnucgvzmy`, `/home/1486907.cloudwaysapps.com/hbnucgvzmy/public_html` und CLI PHP 8.4.25. Kanonischer Pfad und numerischer Eigentümer wurden mit `pwd -P`, `id -u` und `stat -c '%u %U %a' .` abgeglichen; Web-PHP wurde separat verifiziert. Die Nutzersitzung stellt keinen automatischen Agent-Zugang bereit.

Die Laravel-10-Vorlage ist nur der anfängliche Inhalt. Stocks bringt Laravel 13 über `composer.lock` mit und verlangt PHP ^8.4.1. Keine Vorlagen-`.env`, `vendor`- oder Bootstrap-Caches übernehmen. Die vorhandenen Deploy-Skripte ersetzen nicht automatisch alle Vorlagenreste. Nachgewiesen sind UID 1013, Root-Eigentümer 1013/GID 33/Modus 775 und CLI PHP 8.4.25 mit Zip/Sodium/PDO-MySQL/POSIX. Der Elternordner gehört root (Modus 755) und ist nicht beschreibbar. Die früheren Uploadbefehle neben `public_html` und das Paket `0e4d4e3` sind daher überholt: Der korrigierte Installer erhält `public_html` und benötigt keine Elternordnerrechte.

## Einmalige Einrichtung – vor der Erstinstallation

1. Nur für App 6690486 einen Anwendungs-SSH-Zugang mit eingeschränkten Dateirechten einrichten. Mit diesem Login `id -un`, `pwd -P`, den kanonischen App-Pfad, `php -v` und `composer check-platform-reqs` nach Installation prüfen. Web-PHP gesondert verifizieren. Keine globale PHP-Änderung wegen der Preview vornehmen.
2. Der Installer richtet einen eigenen zufälligen Basic-Auth-Zugang für alle Laravel-Routen ein. Cloudways-Passwortschutz/IP-Freigabe kann zusätzlich eingesetzt werden. HTTPS beibehalten und Varnish/CDN-Caching deaktivieren. Ein Robots-Header ersetzt keinen Zugriffsschutz. Keine Cronjobs, Queue-Worker oder Mailtransporte einrichten.
3. Der Installer erzeugt einen neuen zufälligen APP_KEY und protokolliert dessen Fingerprint im an App-ID/Root gebundenen Marker. Er übernimmt nur die geprüften eigenen DB-Zugangsdaten aus der Preview-Vorlage. Keine APP_PREVIOUS_KEYS, Live-`.env`, EODHD-, Cloudways-, SMTP-, AWS- oder KI-Schlüssel übernehmen. Bei manueller Konfiguration alternativ nur den SHA-256 des dekodierten Live-Key-Materials zum Vergleich verwenden, niemals den Live-Key selbst übertragen. Keine Schlüssel im Chat, Git oder Prüfplan.
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
PREVIEW_ACCESS_PASSWORD_HASH=<Hash eines eigenen zufälligen Vorschau-Zugangspassworts>
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

## Geprüfter Erstinstallationsablauf

Das Bundle, die vier Service-Helfer, `preview-install.php` und `stocks_preview_target.json` per authentifiziertem SCP/SFTP nach `public_html/.stocks-preview-private/uploads/<Commit>` übertragen. `.stocks-preview-private` gehört UID 1013 und hat Modus 0700; der vollständige private Arbeitsbereich liegt außerhalb des bestätigten HTTP-DocumentRoot `public_html/public`. Niemals unter `public` hochladen und keine Webserver-Aliase auf den privaten Bereich anlegen. Den lokal ausgegebenen SHA-256 getrennt übernehmen. `scripts/preview-install.php` ist absichtlich auf Server 1486907 / App 6690486 / DB hbnucgvzmy und Linux PHP >=8.4.1 festgelegt; PHP-FPM und CLI müssen Zip/Sodium/PDO-MySQL unterstützen. Der CLI-Benutzer muss dem verifizierten Verzeichnis-Eigentümer entsprechen.

Die Cloudways-Vorlage enthielt abweichende DB-/Benutzernamen und ein leeres DB-Passwort. Für genau diesen bestätigten Zustand gibt es den separat hochladbaren Helfer `scripts/preview-configure-database.php`, gebunden an das bereits geprüfte Staging von Commit `75e531e`. Er erhält ausschließlich das eigene Stocks-Feature-Datenbankpasswort über STDIN aus einer verdeckten Shell-Eingabe mit deaktiviertem Tracing, niemals als Argument, Umgebungsvariable oder Chatnachricht. Nach eigener DB-Anmeldung, Rechte- und Leerprüfung aktualisiert er nur `DB_DATABASE`, `DB_USERNAME` und `DB_PASSWORD`, prüft das Dotenv-Roundtrip-Ergebnis und sichert die ursprüngliche `.env` privat mit 0600. Alle übrigen Zeilen bleiben erhalten. Das bestehende Anwendungspaket bleibt unverändert; anschließend prüft der Installer erneut seine vollständigen Voraussetzungen. Kein Passwort aus dem SFTP-Zugang und keine Live-Credentials verwenden.

1. `php preview-install.php inspect ROOT UID BUNDLE SHA256 stocks_preview_target.json` prüft Root/Owner und alle ZIP-Dateien, ohne DB-Zugriff. Das ZIP wird nie ungeprüft per `extractTo` entpackt: absolute Pfade, Traversal, Links, doppelte Pfade, Secrets und Runtime-Caches sind verboten; Dateizahl und Größe sind begrenzt.
2. Derselbe Aufruf mit `activate` prüft die eigenen DB-Credentials/Rechte und verweigert jede vorhandene Tabelle. Er sichert sämtliche Vorlageneinträge unter `public_html/.stocks-preview-private/template-…`. Das Root-Verzeichnis, sein Eigentümer und seine Rechte bleiben erhalten; nur Einträge darin werden verschoben. Der reservierte private Arbeitsbereich bleibt an Ort und Stelle. Altes `public` wird zuerst gesichert, neues `public` zuletzt eingesetzt; dazwischen sind HTTP-Fehler während der kurzen Unterbrechung zu erwarten. Ein dauerhaft vor den Verschiebungen geschriebenes Journal und eine gemeinsame Sperre sichern den Ablauf. Frische Preview-Konfiguration hält Wartung aktiv. Keine Datenbank wird dabei verändert. Bei einem Fehler wird der vorherige Dateistand ohne Überschreiben widersprüchlicher Pfade wiederhergestellt. Private Zustandsdateien und die `.env` sind nur für den eigenen Unix-Benutzer lesbar; funktioniert PHP-FPM mit einem anderen Benutzer, zuerst diese Zuordnung klären, nicht pauschal Rechte öffnen.
3. Im neuen `public_html`: `php artisan preview:check`, dann `php artisan preview:initialize --commit=COMMIT`. Nur bei weiterhin leerem Schema und unveränderten Release-Dateien werden Migrationen und ein eigenes `preview-admin@stocks.invalid`-Konto angelegt. Wartung bleibt aktiv. Ein Fehler führt nicht zu automatischem Löschen oder Wiederholen teilweiser Migrationen.
4. Erst mit unabhängig bestätigter PHP-FPM-Version: `php artisan preview:activate --commit=COMMIT --web-php=VERSION`. Nur die zur eigenen Installation gehörende Wartungsdatei wird entfernt. Danach HTTPS, anonyme Antwort 401, Basic Auth, Passwortanmeldung und gesperrte Integrationen prüfen.

Die beiden neu erzeugten Zugänge liegen ausschließlich unter `storage/app/private/preview-access.json` (0600). Der Nutzer übernimmt sie direkt über seinen privaten Serverzugang in den Passwortmanager; nicht in Chat oder Logs ausgeben. Basic-Auth-Benutzer ist `preview`, die App-Anmeldung verwendet `preview-admin@stocks.invalid` mit eigenem anderem Passwort.

Vor der endgültigen Aktivierung kann `php preview-install.php restore-template ROOT UID BUNDLE SHA256 stocks_preview_target.json` die gesicherte Vorlage zurückbringen. Dabei bleibt die neue Installation in einem privaten `failed-…`-Verzeichnis erhalten; **die DB bleibt unverändert**. Dies ist ein getesteter Datei-Restore für die Erstinstallation, kein DB-Rollback und kein Update-Restore für befüllte Vorschauen. Nach Aktivierung ist dieser Vorlagen-Restore gesperrt.

Nach einem Prozessabbruch während eines Dateiaustauschs: aus dem unveränderten privaten Uploadordner `php preview-install.php recover-files ROOT UID BUNDLE SHA256 stocks_preview_target.json` mit demselben geprüften Paket ausführen. Dieser Befehl benötigt weder Laravel-Boot noch `.env` oder DB-Verbindung und stellt den Dateistand vor dem unterbrochenen Austausch her. Er gilt auch für unterbrochenen `restore-template`. Wiederholte Recovery ist unschädlich; bei beidseitig belegten/fehlenden Pfaden stoppt sie und bewahrt Journal sowie beide Bestände zur Prüfung. Initialisierung, Aktivierung, erneuter Austausch und Live-Deploy sind bei vorhandenem Journal gesperrt.

## Datenkopie und Rückkehr zum vorherigen Stand

Der Nutzer hat ausdrücklich eine vollständige **Originaldatenkopie** autorisiert. `PreviewOriginalSchema` prüft die exakten Spalten von 35 Tabellen sowie Primärschlüssel, Beziehungen und Benutzerzuordnung privater Analysen. `PreviewOriginalPolicy` erhält Originalbenutzer mit unveränderten Passworthashes, Rollen und Berechtigungen, IDs, Depotnamen, Kontonummern, Notizen, KI-Verläufe und die gesamte Kurshistorie. Der temporäre Preview-Admin wird gesichert und ersetzt; Originaldaten werden ihm nicht zugeordnet. Remember-Tokens werden entwertet, Integrationsgeheimnisse entfernt. Sessions, Login-Codes, Passwort-Reset- und API-Tokens, Jobs, Caches und Migrationseinträge werden nicht aus Live übernommen. Mail und externe Integrationen bleiben gesperrt. Die ältere `PreviewSnapshotPolicy` mit 15 anonymisierten Tabellen bleibt ausschließlich für das getrennte Legacy-Format bestehen.

`PreviewSnapshotStream` verarbeitet das Archiv zeilenweise mit Sodium-Empfängerverschlüsselung und authentifiziertem Abschluss. Die Grenzen betragen 2 GiB pro verschlüsselter Datei und 1 MiB pro Datensatz; eine Überschreitung bricht ab, ohne die Historie zu kürzen. Der geprüfte SHA-256 muss über einen authentifizierten Kanal übertragen werden. Kontext, App-IDs, Quellreferenz, Ziel-Commit und einmaliger Nonce werden geprüft. Kein Klartext-Dump wird gespeichert. Der tatsächliche Export verwendet `scripts/preview-export-local.php` mit der bestehenden lokalen Cloudways-Leseverbindung, verifiziertem TLS und konsistenter **READ ONLY**-Transaktion. Die Quellreferenz `4cb0ab0d532c75738c4ca19dd29185f90fe9b740` bezeichnet den freigegebenen lokalen Checkout; sie bestätigt nicht den ungeprüften Live-Dateisystem-Commit. Das Live-Datenbankschema wurde direkt geprüft.

Der CLI-Helfer `scripts/preview-snapshot.php` ist auf die erste Befüllung der noch leeren Geschäftstabellen begrenzt. Er prüft den installierten Release bereits vor dem Laravel-Boot, anschließend Isolation, eigenen DB-Benutzer, Rechte, Root und Unix-Eigentümer. Ein Installationslock, ein dauerhaft verbrauchter Nonce und eine atomar veröffentlichte Wartungsdatei schützen den Ablauf. Vor dem Ersetzen wird der vorhandene Preview-Datenbestand einschließlich Admin verschlüsselt gesichert. Zuerst werden Import, Beziehungen und Rückleseprüfung in einer zurückgerollten Transaktion geprobt; danach erfolgt derselbe Import dauerhaft. Fremdschlüssel bleiben aktiv. Die Freigabe prüft den kompletten Datenfingerprint erneut und entfernt alte Sessions und Berechtigungscaches aus den aktiven Verzeichnissen.

Für den bereits vorhandenen lokalen Export werden ausschließlich das verschlüsselte Archiv, der private Empfängerschlüssel, die zugehörige Anfrage und die geprüften CLI-Helfer authentifiziert nach `.stocks-preview-private` übertragen. Das Datenverzeichnis muss ein eigenes direktes Unterverzeichnis sein, UID 1013 gehören und Modus 0700 haben; Schlüsseldateien erhalten 0600. Die acht Service-Dateien `PreviewOriginalSchema`, `PreviewOriginalPolicy`, `PreviewSnapshotPolicy`, `PreviewSnapshotArchive`, `PreviewSnapshotStream`, `PreviewSnapshotDatabase`, `PreviewSnapshotTransfer` und `PreviewReleaseBundle` liegen beim gebündelten CLI-Skript. Anwendungscode, `.env` und Live-Dateien werden nicht ersetzt.

```text
php preview-snapshot.php adopt ROOT PRIVATE_DATA_DIRECTORY
php preview-snapshot.php inspect ROOT PRIVATE_DATA_DIRECTORY ENCRYPTED_SNAPSHOT TRUSTED_SHA256
php preview-snapshot.php import ROOT PRIVATE_DATA_DIRECTORY ENCRYPTED_SNAPSHOT TRUSTED_SHA256
php preview-snapshot.php finish ROOT PRIVATE_DATA_DIRECTORY
```

`adopt` übernimmt die vorhandene Empfängeranfrage und bindet sie an den unveränderten installierten Ziel-Commit. `import` lässt Wartung aktiv. Bei einem Fehler oder vor einer verworfenen Freigabe stellt `restore` ausschließlich den eigenen gesicherten Anfangsstand wieder her; spätere Änderungen werden nicht überschrieben. Anschließend gibt `finish` den nachgewiesenen Stand frei. Nach Freigabe ist dieser Restore gesperrt. Erfolgreiche lokale Tests oder CI ersetzen weder den tatsächlichen Serverimport noch dessen Funktionsprüfung. Keine Live-Migration und kein Sync zurück zu Live.

Der lokale Helfer `scripts/preview-snapshot-upload.ps1 -BundleDirectory PRIVATE_BUNDLE -ExpectedManifestSha256 TRUSTED_MANIFEST_SHA256` führt diesen Ablauf einschließlich Upload aus. Er prüft vorher die exakt 13 Paketdateien gegen das separat bestätigte Manifest, bindet das Ziel an den installierten Commit und verlangt die bereits bekannte SSH-Serveridentität. `-VerifyOnly` prüft das Paket ohne Netzwerkzugriff. Die Anmeldung erfolgt direkt durch OpenSSH im Nutzerterminal; das Skript liest oder speichert kein Passwort. Ein existierendes Zielverzeichnis wird nicht überschrieben. Bei Abbruch bleiben Paket und Wiederherstellungsbelege erhalten; der Helfer nennt den konkreten Restore-Aufruf. Die lokalen Tests prüfen zusätzlich manipulierte Dateien, falsche Zielidentität und unzulässige Manifestpfade. MySQL prüft Wiederherstellung und Freigabe mit jeweils neuer CLI-Instanz sowie begrenzten Speicher beim Sperren großer vorhandener Datenbestände.

## Laufzeitgrenzen

Externe Stocks-Integrationen, Laravel-HTTP, Laravel-AI-Ereignisse, Mail, Redis, entfernte Dateisysteme, fremde Cache-/Queue-Treiber und Broadcasts werden blockiert. Scheduler ist leer. CLI erlaubt nur `preview:check`, die streng begrenzten `preview:initialize`/`preview:activate`, `list`, `help`, `about`; bestehende Migrations-/Update-/Deploy-Befehle bleiben gesperrt. Auch Cache-Löschbefehle bleiben gesperrt, damit fehlerhafte Pfad-Overrides keine Live-Dateien entfernen. Logging wird in dieser Vorbereitungsstufe verworfen, damit keine kopierte Remote-Log-Konfiguration Finanzdaten überträgt.

Das ist eine Anwendungsgrenze, keine Sandbox für beliebigen PHP-Code. Direkte PDO-/cURL-/Prozesszugriffe aus neuem Feature-Code benötigen zusätzlich Betriebssystem-/Netzwerkbeschränkungen und Codeprüfung. FPM-`disable_functions` ist kein Beweis für entsprechende CLI-Beschränkungen.

Die Erstinstallation und der lesende Originaldaten-Export sind abgeschlossen. Serverimport und anschließende Funktionsprüfung stehen aus. Automatisierte, an Ziel/Commit gebundene Deploy-Belege und spätere Updates befüllter Vorschauen sind noch nicht eingerichtet. Weder eine manuell gesetzte Flag noch ein lokaler Prüfplan darf diese Voraussetzungen überspringen.
