## 23. Phase 5 — Sicherheits-/Robustheitshärtung

Voraussetzung: Abschnitte 21–22 (Phase 2a/2b) bestanden, Integrationspatches
A–F (Integrationsphase/Phase 4) eingespielt.

### 23.1 Wareneingang — Transaktionsschutz

- ☐ Normaler Wareneingang (eine Position, vollständig geliefert) funktioniert
  wie bisher: Menge, Lagerbestand und Status werden korrekt aktualisiert
- ☐ Simulierter Fehler während der Verarbeitung (z. B. DB-Verbindungsabbruch
  testweise erzwungen) führt zu vollständigem Rollback — weder Menge noch
  Lagerbestand noch Status wurden teilweise verändert

### 23.2 Import-Härtung

- ☐ Regulärer XML-Import (Preisliste eines Lieferanten) funktioniert nach
  der Änderung unverändert korrekt
- ☐ Regulärer ZIP-Import (z. B. ZIP mit einer CSV-Datei) funktioniert
  unverändert korrekt
- ☐ ZIP mit sehr vielen Dateien (Testfall: >500) wird mit einer klaren
  Fehlermeldung abgelehnt, kein Absturz
- ☐ ZIP mit sehr großer unkomprimierter Gesamtgröße (Testfall: >100 MB)
  wird mit einer klaren Fehlermeldung abgelehnt, kein Absturz/keine
  Speicherüberlastung

### 23.3 CLI-Worker — Sperre gegen Doppel-Ausführung

- ☐ Zwei manuell gleichzeitig gestartete Worker-Läufe: der zweite beendet
  sich sofort mit einer Info-Meldung und Exit-Code 0, ohne Lieferanten zu
  verarbeiten
- ☐ Nach Abschluss des ersten Laufs kann ein neuer Lauf wieder normal
  starten und verarbeiten

### 23.4 Cron-Endpunkt — GET-Warnprotokoll

- ☐ Aufruf mit gültigem Secret per POST: kein neuer Eintrag mit Aktion
  `cron_secret_via_get` in `activity_log`
- ☐ Aufruf mit gültigem Secret per GET: genau ein neuer Eintrag mit Aktion
  `cron_secret_via_get`, das Secret selbst erscheint NICHT im
  Log-Eintrag
- ☐ Aufruf mit ungültigem Secret (GET oder POST): weiterhin HTTP 403, kein
  neuer Log-Eintrag

### 23.5 Kundenportal — Regressionsprüfung (bereits vorhandene Absicherung, nicht verändert)

- ☐ Login erzeugt eine neue Session-ID (Session-Fixation-Schutz weiterhin
  aktiv)
- ☐ Mehrere aufeinanderfolgende Fehlversuche vom selben Gerät/derselben IP
  führen zur zeitlich begrenzten Sperre
- ☐ Ein Kunde kann ausschließlich eigene Reparaturen/Termine/Fotos/
  Nachrichten sehen, auch bei geratener/veränderter ID in der URL
- ☐ Foto-Upload akzeptiert nur echte Bilddateien der erlaubten Endungen
  bis zur konfigurierten Maximalgröße

### 23.6 Vollständige Regressionsprüfung

- ☐ Alle Abschnitte 1–22 der Testcheckliste bleiben nach Anwendung der
  Phase-5-Patches bestehbar

### 23.7 Offene reale Serverprüfungen (nicht aus dem Repository verifizierbar)

- ☐ Tatsächliche Werte von `PORTAL_MAX_LOGIN_ATTEMPTS`,
  `PORTAL_LOGIN_LOCKOUT_MINUTES`, `MAX_UPLOAD_SIZE`, `ALLOWED_EXTENSIONS`,
  `UPLOAD_PATH` in der produktiven `private/config.php` auf Sinnhaftigkeit
  prüfen (nicht im Repository, konnte nicht verifiziert werden)
- ☐ `UPLOAD_PATH` liegt tatsächlich außerhalb des öffentlich per HTTP
  erreichbaren Verzeichnisses (auf dem echten Server prüfen)
- ☐ PHP-Erweiterungen `zip`, `simplexml`, `curl`, optional `soap`/`ssh2`
  tatsächlich beim Hoster aktiv (siehe INSTALLATION.md-Ergänzung)
- ☐ Cronjob(s) beim Hoster tatsächlich eingerichtet und laufen im
  gewünschten Intervall
- ☐ Dateiberechtigungen von `private/` und dem Upload-Verzeichnis auf dem
  echten Server wie vorgesehen gesetzt
- ☐ Reales Lastverhalten des Zip-Bomben-Limits (500 Dateien / 100 MB) mit
  echten, größeren Lieferanten-Preislisten gegenprüfen — Limits ggf.
  anpassen, falls legitime Dateien angepasst werden müssen
