# Stocks auf Windows: täglicher Git-Ablauf

Stocks und Schooltool bleiben getrennte Projekte. Wechsle zuerst in den richtigen
Projektordner. Die PowerShell-Helfer erkennen ihn am GitHub-Remote.

```powershell
cd C:\laravel\stocks
gitcheck
```

`main` enthält bei Stocks vollständige Releases einschließlich Frontend-Paket.
Neue Arbeit liegt auf Feature-Branches. `gitstart depot-filter` legt
`codex/depot-filter` an. Die zehn Alltagsbefehle heißen wie bei Schooltool.

## Einmal auf dem Hauptgerät

```powershell
cd C:\laravel\stocks
composer setup:powershell
. $PROFILE
```

Der Installer richtet Windows PowerShell 5.1 und PowerShell 7 ein, bewahrt eigene
Profilabschnitte und sichert geänderte Profile als `*.stocks-backup-*`. Wiederholtes
Ausführen erzeugt keine doppelten Helfer. Er aktiviert den vorhandenen
`.githooks/pre-push`-Schutz für dieses Repository. Git-Benutzername und E-Mail müssen
bereits gesetzt sein (`git config user.name`, `git config user.email`).

Der gemeinsame Dispatcher unterstützt Stocks und Schooltool, einschließlich
`gitpreview`, `gitrelease` und `gitdeploy`.
Ein älterer Schooltool-Installer kann den gemeinsamen Dispatcher wieder auf seinen
älteren Stand setzen. Dann zuletzt in Stocks erneut `composer setup:powershell`
ausführen und ein neues Terminal öffnen. Schooltools Repository wird durch den
Stocks-Installer nicht verändert.

## Neue Arbeit beginnen und sichern

```powershell
gitstart depot-filter
# Arbeiten und passende Tests ausführen
git status
gitsave "Add depot filter"
```

`gitstart` beginnt lokal am frisch abgefragten `origin/main`. Erst `gitsave` legt den
Feature-Branch auf GitHub an. `gitsave` übernimmt alle nicht ignorierten Änderungen
einschließlich neuer Dateien und Löschungen in einen Commit und überträgt genau
diesen Branch. Prüfe deshalb vorher `git status`. Auch ohne neue Änderungen sichert
`gitsave` vorhandene lokale Commits oder einen gerade angelegten Branch. Es erzeugt
keine Version, keinen Release und kein Deployment. Tags werden nicht mitgeschickt.

Die Sicherung ist erst abgeschlossen, wenn `gitsave` Erfolg meldet. Bei einem
Netzwerkfehler bleiben die Commits lokal; nach Behebung erneut `gitsave` ausführen.
Feature-Sicherungen sind keine Testbestätigung. GitHub-CI läuft im Hintergrund;
mit `-WaitForCI` wartet nur ein main-Release ausdrücklich auf sein Ergebnis.

## Zwischen parallelen Aufgaben wechseln

```powershell
gitsave "Save current filter work"
gitstart kursanzeige
gitsave "Share price display feature"
gitwork depot-filter
gitmain
gitwork kursanzeige
```

`gitwork NAME` holt einen auf GitHub vorhandenen Feature-Branch und setzt die Arbeit
dort fort. Ohne Namen funktioniert `gitwork` nur, wenn genau ein Feature vorhanden
ist. `gitcheck` zeigt den lokalen Zustand und die verfügbaren Remote-Features.

`gitmain` wechselt zu `main` und aktualisiert nur per Fast-forward. Es übernimmt keine
Feature-Arbeit in `main`. Ungespeicherte Dateien und nicht veröffentlichte Commits
verhindern den Wechsel; die Helfer verwerfen nichts und erstellen keinen
automatischen Stash. Ein Branch, der schon vollständig in `origin/main` enthalten
ist, darf verlassen werden. Git blockiert außerdem Branches, die bereits in einem
anderen Worktree ausgecheckt sind.

## Neues main in eine laufende Aufgabe übernehmen

```powershell
gitsave "Save feature before updating"
gitupdate
# Ergebnis prüfen und passende Tests ausführen
gitsave "Integrate current main"
```

`gitupdate` aktualisiert zuerst den gespeicherten Feature-Branch per Fast-forward
und führt dann `origin/main` lokal zusammen. Es schreibt keine veröffentlichte
Historie um und pusht das Ergebnis nicht automatisch.

Bei Konflikten bleibt der Merge sichtbar stehen. `git status` zeigt die betroffenen
Dateien. Nach fachlicher Auflösung `git add DATEI` und `git commit` ausführen,
anschließend `gitprepare`, testen und `gitsave`. Mit `git merge --abort` kann dieser
Merge abgebrochen werden. Die Workflow-Befehle starten während eines offenen
Merge/Rebase/Cherry-pick keine weitere Operation.

Hat ein anderer Rechner denselben Branch weitergeschrieben, stoppt `gitsave` vor
einem neuen Commit. Bei sauberem Arbeitsbaum zunächst `gitwork NAME` verwenden.
Bei eigenen Änderungen diese lokal committen, dann nach `git fetch origin` den
zugehörigen `origin/codex/NAME` bewusst mit `git merge` integrieren, Konflikte lösen,
testen und erneut `gitsave` ausführen. Kein Force-Push. Am einfachsten bearbeitest du
denselben Feature-Branch abwechselnd auf den Geräten; unterschiedliche Aufgaben
können parallel auf unterschiedlichen Branches liegen.

## Lokale Vorbereitung

`gitstart`, `gitwork`, `gitmain` und `gitupdate` installieren die zum Lockfile
passenden Abhängigkeiten, leeren Konfigurations-/View-Caches und bauen das Frontend.
Dafür werden PHP 8.4.1+, Composer, Node/npm und eine lokale `.env` mit `APP_ENV=local`
benötigt. Nach einem Wechsel laufende Entwicklungsserver/Queue-Worker neu starten.

```powershell
gitprepare                 # Vorbereitung erneut ausführen
gitwork depot-filter -NoPrepare  # Nur Git; Vorbereitung später nachholen
composer dev
```

Die Vorbereitung führt keine Migrationen, Seeder, Datenbankkopien oder Cloudways-
Befehle aus. `.env`, Datenbank, Uploads, `vendor`, `node_modules` und laufende Prozesse
werden durch Git nicht zwischen Branches oder Geräten synchronisiert. Für Features
mit Schemaänderungen eine separate lokale Datenbank verwenden und Migrationen
bewusst dort ausführen. `composer deploy` ist für fertige Stocks-Releases gedacht;
für einen laufenden Feature-Branch ist `gitprepare` der passende Befehl.

Schlägt die Vorbereitung fehl, ist der Git-Wechsel möglicherweise bereits erfolgt.
`git status` prüfen, die gemeldete Ursache beheben und `gitprepare` wiederholen.

## Feature in main veröffentlichen und live stellen

Ein geprüftes und mit `gitsave` auf GitHub gesichertes Feature wird so veröffentlicht:

```powershell
gitwork depot-filter
# Passende Tests ausführen
gitsave "Complete depot filter"
gitrelease "Add depot filter" 1.1.1
gitmain
gitdeploy
```

`gitrelease` übernimmt das Feature als Squash-Commit, baut/verifiziert das
Frontend-Paket, veröffentlicht `main` und wartet auf CI. Die Versionsnummer ist
optional und muss zu den vorbereiteten Notizen in `UPDATES.md` passen. Bei
Änderungen direkt auf `main` veröffentlicht `gitsave "Beschreibung" VERSION`
einen vollständigen Release; `-Full` startet alle lokalen Tests und `-WaitForCI`
wartet zusätzlich auf GitHub-CI.

`gitdeploy` verlangt einen sauberen `main` auf genau dem GitHub-Release-Commit,
prüft das Paket und zeigt das Live-Ziel vor der Eingabe `LIVE`. Wie bei Schooltool
läuft GitHub-CI im Hintergrund: `gitdeploy` wartet nicht auf ihren Abschluss.
Bei einem fehlgeschlagenen CI-Lauf ist der Fehler gesondert zu beheben.
Ein normaler Git-Push von Quellcode allein auf `main` erfüllt den Release-Vertrag
nicht. Auch ein GitHub-PR darf nicht ohne diesen Release-Schritt nach `main` gemergt
werden. GitHub-Veröffentlichung und Cloudways-Deployment sind getrennte Schritte.

## Spätere Phase: weiterer PC und Laptop

Diese Geräte werden jetzt noch nicht verändert. Später auf jedem Gerät Git,
PHP/Composer, Node/npm und die lokale Stocks-Umgebung vorbereiten. Entweder das
bestehende saubere Stocks-Repository per `git pull --ff-only origin main`
aktualisieren oder `ITStudioAT/stocks` neu klonen. Vor dem ersten `gitprepare` die
lokale `.env`, den App-Key und die separate Stocks-Datenbank passend einrichten;
vorhandene Schlüssel oder Datenbanken nicht überschreiben.

Dann `composer setup:powershell`, ein neues Terminal und `gitprepare`. Die tägliche
Übergabe lautet anschließend:

| Gerät verlassen | Auf dem anderen Gerät weiterarbeiten |
| --- | --- |
| `gitsave "Describe changes"` erfolgreich abwarten | `gitwork depot-filter` |
| Einen fertigen main-Release veröffentlichen | `gitmain` |

Die getrennte Cloudways-Vorschau wird mit `gitpreview -Feature NAME` oder
`gitpreview -Main` aktualisiert. `gitpreview prepare` erstellt nur das geprüfte
Paket; `gitpreview resume BUNDLE_ID` setzt einen vorbereiteten Vorgang fort.
`-RefreshData` ist ein eigener Datenwechsel und verlangt die passende
Wiederherstellung. Details stehen in [preview-cloudways.md](preview-cloudways.md).
