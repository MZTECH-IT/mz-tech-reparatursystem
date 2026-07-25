# CHANGELOG – MZ Tech Reparatur-Management-System

Alle Änderungen dieses finalen Überarbeitungs-Durchgangs, gegliedert nach den
14 vereinbarten Themenblöcken. Das System basiert weiterhin ausschließlich
auf **PHP 8.x, MariaDB/MySQL, HTML5, CSS3 und Vanilla-JavaScript** – keine
Frameworks, keine monatlichen Kosten, keine kostenpflichtigen APIs, keine
Cloud-Datenbanken. Alles läuft lokal auf einem klassischen All-Inkl-Webspace.

---

## 1. Allgemeine Überarbeitung

Vollständige Durchsicht des gesamten Projekts (PHP-Syntaxprüfung aller
Dateien, Konsistenzprüfung zwischen Datenbank-Schema, Anwendungscode und
Frontend) nach jedem der folgenden Arbeitsschritte. Alle 49 PHP-Dateien im
Projekt sind frei von Syntaxfehlern (`php -l`), alle Icon-Referenzen im
SVG-Icon-System sind gültig, alle CSS-Variablen sind definiert.

## 2. Öffentliche Terminbuchung

Neue öffentlich erreichbare Seite **`termin.php`**, über die Kunden ohne
Login einen Reparaturtermin anfragen können:
- Auswahl von Gerätetyp, Hersteller/Modell, Problembeschreibung
- Terminwunsch (Datum + Uhrzeit) auf Basis der in den Einstellungen
  hinterlegten Öffnungszeiten, Slot-Länge, Kapazität pro Slot, Vorlaufzeit
  und maximaler Vorausbuchungszeitraum
- Live-Prüfung freier Termin-Slots über die API `api/public_slots.php`
- Automatische Bestätigungs-/Status-E-Mail an den Kunden
- Neue Admin-Übersicht **`booking_requests.php`** / **`booking_view.php`**
  zur Bestätigung, Ablehnung, Umplanung oder Umwandlung einer Anfrage in
  einen echten Reparaturauftrag

## 3. Button auf der bestehenden Website

Eigenständige Anleitung **`WEBSITE-INTEGRATION.html`** (im ZIP enthalten,
im Browser öffnen) mit fertigem Copy-&-Paste-Code für:
- Buttons „Termin vereinbaren" und „Reparatur anfragen" (Hero-Bereich,
  Navigation, floatendes Aktions-Icon)
- Fertige Farb-/Stil-Varianten passend zur bestehenden MZ-Tech-Website
- Direkte Verlinkung auf die produktiven URLs unter
  `https://mztech-it.de/repair/public/`

## 4. Öffentliche Reparaturanfrage

Neue öffentlich erreichbare Seite **`anfrage.php`** für Kunden, die noch
keinen konkreten Termin, aber bereits eine Reparaturanfrage stellen
möchten (z. B. zur Klärung, ob sich eine Reparatur lohnt):
- Eigenes Nummernkreis-Präfix, eigene Statuspipeline (`neu`, `abgelehnt`,
  `archiviert`, `umgewandelt`)
- Admin-Übersicht **`repair_requests.php`** / **`repair_request_view.php`**
- Umwandlung einer Anfrage in einen echten Reparaturauftrag per Klick

## 5. Kundenportal

Neues passwortloses Kundenportal **`portal.php`**:
- Zugang ausschließlich über einen individuellen, langen Zufallslink
  (`?t=…`) oder Auftragsnummer + persönliche PIN – **kein Passwort, keine
  klassische Registrierung**
- QR-Code für den direkten mobilen Zugriff, generiert vollständig lokal
  (`api/portal_qrcode.php`, keine Google-API)
- Kunden sehen ausschließlich ihre **eigenen** Aufträge, Status, Dokumente
  und Kostenvoranschläge (strikte Datentrennung pro Kunde auf DB-Ebene)
- Freigabe von Kostenvoranschlägen direkt im Portal möglich
- Rate-Limiting für PIN-Versuche (`portal_login_attempts`) gegen Brute-Force

## 6. Reparaturstatus und E-Mails

Die Statuspipeline einer Reparatur wurde auf **14 differenzierte Stufen**
erweitert (vorher deutlich gröber):

1. Anfrage eingegangen
2. Termin angefragt
3. Termin bestätigt
4. Gerät angenommen
5. Diagnose
6. Kostenvoranschlag erstellt
7. Freigabe ausstehend
8. Ersatzteil bestellt
9. In Reparatur
10. Funktionstest
11. Fertig
12. Abholbereit
13. Abgeholt
14. Storniert

Zu jeder Statusänderung kann automatisch eine E-Mail an den Kunden
versendet werden (PHPMailer, lokal mitgeliefert). Betreff und Text jeder
einzelnen E-Mail sind unter **Admin → E-Mail-Vorlagen** frei editierbar
(siehe Abschnitt „Standard-E-Mail-Vorlagen" unten) – ohne jede
Code-Änderung.

## 7. Dashboard und Kalender

**Dashboard** (`dashboard.php`) um zusätzliche Kennzahlen und Widgets
erweitert:
- Neue Kennzahlkarten „Termine heute" und „Offene Terminanfragen"
- Neues Ring-Diagramm „Statusverteilung" (offene Reparaturen nach Status,
  Chart.js) neben dem bestehenden 30-Tage-Verlaufsdiagramm
- Neue Übersichtstabellen „Termine heute" und „Offene Terminanfragen" mit
  Direktlinks zu Reparatur bzw. Terminanfrage

**Kalender** (`calendar.php`) vollständig überarbeitet:
- Umschaltbare Ansichten **Monat / Woche / Tag** mit konsistenter
  Anker-Datum-Navigation (Vor/Zurück/Heute je Ansicht)
- Stundenraster für Wochen-/Tagesansicht, das sich automatisch an
  tatsächlich vorhandene Termine anpasst (kein Termin wird durch ein zu
  eng gewähltes Zeitfenster „versteckt")
- **Drag & Drop** zum Verschieben von Terminen (Monatsraster: Termin auf
  anderen Tag ziehen; Wochen-/Tagesraster: Termin auf andere Stunde/anderen
  Tag ziehen), umgesetzt mit nativen HTML5-Drag&Drop-APIs ohne jede
  externe Bibliothek
- Neuer API-Endpunkt `api/calendar.php?action=reschedule`, der die
  ursprüngliche Termindauer beim Verschieben beibehält
- Termine deutschsprachig formatiert (Monats-/Wochentagsnamen ohne
  Abhängigkeit von der PHP-`intl`-Erweiterung, für maximale Kompatibilität
  mit Shared-Hosting-Umgebungen)

## 8. Datenschutz und Sicherheit

Bestehende Sicherheitsmaßnahmen bestätigt und ergänzt:
- Alle sensiblen Dateien (`private/`, `uploads/`, `logs/`, `backups/`,
  `sql/`, `vendor/`) liegen außerhalb des Webroots
- Ausschließlich PDO mit vorbereiteten Statements (keine SQL-Injection-
  Angriffsfläche)
- CSRF-Schutz auf allen authentifizierten Formularen und API-Endpunkten
  (`verify_csrf()` / `csrf_field()` / `csrf_token()`)
- Konsequentes HTML-Escaping aller Ausgaben (`h()`) gegen XSS
- Brute-Force-Schutz beim Admin-Login und beim Kundenportal-PIN-Login
- HTTPS-Erzwingung und sichere Session-Cookies über `.htaccess` bzw.
  Session-Konfiguration
- **Neu geprüft und behoben in diesem Durchgang:** Auf `calendar.php` und
  `parts.php` wurde das CSRF-Token für AJAX-Aktionen (Termin speichern/
  verschieben/löschen, Lagerbestand anpassen) direkt aus der Session
  gelesen, ohne sicherzustellen, dass zuvor überhaupt ein Token erzeugt
  wurde. Bei einer taufrischen Session (z. B. direkt nach dem Login) konnte
  dies dazu führen, dass die allererste Aktion auf diesen Seiten mit
  „Ungültiges CSRF-Token" fehlschlug. Beide Stellen rufen jetzt konsequent
  `csrf_token()` bzw. `csrf_field()` auf, wie es auf allen anderen Seiten
  bereits der Fall war.

## 9. Datenschutzformulare

Der Text der Datenschutzhinweise auf allen öffentlichen Formularen
(Terminbuchung, Reparaturanfrage) ist jetzt **zentral unter
Admin → Einstellungen** editierbar (`privacy_notice_text`, unterstützt die
Platzhalter `{{firma}}` und `{{email}}`) – keine Code-Änderung nötig, falls
sich der Text ändern soll. Einwilligung (Pflichtfeld) und optionale
Werbeeinwilligung werden pro Anfrage in der Datenbank protokolliert
(`privacy_consent`, `marketing_consent`, inkl. IP-Adresse und Zeitstempel).

## 10. PDF-Dokumente

Sechs vollständige PDF-Vorlagen (TCPDF-kompatible Klasse, lokal
mitgeliefert, kein externer Dienst) unter `public/pdf/`:

| Datei | Dokument |
|---|---|
| `auftrag.php` | Reparaturannahme / Reparaturauftrag |
| `kostenvoranschlag.php` | Kostenvoranschlag |
| `rechnung.php` | Rechnung |
| `abholschein.php` | Abholschein |
| `reparaturbericht.php` | Reparaturbericht |
| `terminbestaetigung.php` | Terminbestätigung |

Alle Vorlagen enthalten den §19-UStG-Kleinunternehmerhinweis (Text unter
Admin → Einstellungen anpassbar, falls Regelbesteuerung greift) sowie
einen QR-Code, der direkt in das Kundenportal des jeweiligen Kunden führt.

## 11. Installation bei All-Inkl

Das System erkennt seinen Installationspfad und die Basis-URL zur
Laufzeit automatisch – lauffähig im Domain-Root, auf einer Subdomain oder
in einem Unterordner, **ohne jede manuelle Anpassung** an Dateien nach dem
Hochladen. Details siehe `INSTALLATION.md`.

## 12. Update-Sicherheit

- `setup.php` sperrt sich nach erfolgreicher Erstinstallation automatisch
  selbst (`private/installed.lock`) und verweigert bei erneutem Aufruf
  jede Datenbank- oder Konfigurationsänderung
- `sql/update.sql` ist so geschrieben, dass es **nie** bestehende Tabellen
  oder Datensätze löscht oder überschreibt (`CREATE TABLE IF NOT EXISTS`,
  ergänzende `INSERT … ON DUPLICATE KEY UPDATE`) – ein Update kann jederzeit
  gefahrlos eingespielt werden, auch mehrfach

## 13. Testdaten und Testplan

Siehe **`TEST-CHECKLISTE.md`** für einen vollständigen, manuell
abarbeitbaren Testplan über alle Funktionsbereiche des Systems.

## 14. Ausgabe

Diese finale Version wird als **ein einziges ZIP-Archiv** ausgeliefert und
enthält u. a.:
- `INSTALLATION.md` – Installationsanleitung für All-Inkl (KAS)
- `WEBSITE-INTEGRATION.html` – fertiger Button-/Link-Code für die
  bestehende Website
- `TEST-CHECKLISTE.md` – manueller Testplan
- `CHANGELOG.md` – dieses Dokument
- den vollständigen, geprüften Quellcode inkl. `vendor/`-Bibliotheken

---

## Öffentliche URLs im Überblick

Bezogen auf die produktive Basis-Adresse `https://mztech-it.de/repair/public/`
(bei anderer Installationsvariante entsprechend anpassen, siehe
`INSTALLATION.md`):

| URL | Zweck | Login erforderlich |
|---|---|---|
| `/index.php` | Admin-Login | Nein (führt zum Login) |
| `/dashboard.php` | Admin-Startseite nach Login | Ja |
| `/termin.php` | Öffentliche Terminbuchung | Nein |
| `/anfrage.php` | Öffentliche Reparaturanfrage | Nein |
| `/portal.php` | Kundenportal (Link, QR-Code oder PIN) | Nein (eigener Zugang) |
| `/setup.php` | Einmaliger Installations-Assistent | Nein (sperrt sich danach selbst) |

Alle übrigen Seiten (`repairs.php`, `customers.php`, `calendar.php`,
`parts.php`, `settings.php`, `email_templates.php`, `booking_requests.php`,
`repair_requests.php`, `statistics.php`, `backup.php`, `activity.php`,
`profile.php` usw.) sind ausschließlich für eingeloggte Administratoren
erreichbar.

## Standard-E-Mail-Vorlagen (Referenz)

Alle folgenden Vorlagen sind bereits vorinstalliert (Tabelle
`email_templates`) und unter **Admin → E-Mail-Vorlagen** frei bearbeitbar.
Verfügbare Platzhalter: `{{vorname}}`, `{{nachname}}`, `{{firma}}`,
`{{auftragsnummer}}`, `{{status}}`, `{{geraet}}`, `{{hersteller}}`,
`{{modell}}`, `{{portal_link}}`.

**Reparaturstatus-Pipeline** (automatisch bei jedem Statuswechsel):
Anfrage eingegangen · Termin angefragt · Termin bestätigt · Gerät
angenommen · Diagnose · Kostenvoranschlag erstellt (inkl. Portal-Link) ·
Freigabe ausstehend (inkl. Portal-Link) · Ersatzteil bestellt · In
Reparatur · Funktionstest · Fertig · Abholbereit · Abgeholt · Storniert

**Terminbuchung** (öffentliche Terminanfrage ohne Login):
Terminanfrage erhalten · Termin bestätigt · Terminanfrage abgelehnt ·
Termin verschoben

**Reparaturanfrage** (öffentliches Formular ohne Termin):
Reparaturanfrage erhalten · Reparaturanfrage abgelehnt

Jede Vorlage lässt sich einzeln aktivieren/deaktivieren (z. B. um für
einen bestimmten Status keine E-Mail zu versenden) und per Klick auf den
mitgelieferten Standardtext zurücksetzen.

---

## Bekannte Grenzen / bewusste Designentscheidungen

- Drag & Drop im Kalender lädt die Seite nach erfolgreichem Verschieben
  neu (`window.location.reload()`), statt die Ansicht per JavaScript
  partiell zu aktualisieren – bewusst einfach und robust gehalten, wie im
  bestehenden Speichern-/Löschen-Code der Seite.
- Datumsformatierung erfolgt über eigene, fest hinterlegte deutsche
  Monats-/Wochentagsnamen statt über die PHP-`intl`-Erweiterung, da deren
  Verfügbarkeit auf Shared-Hosting-Paketen nicht garantiert ist.

---

## Erweiterungsrunde 2: Kundenkonto, Kalender-Sync, Bild-Upload

Nach der ursprünglichen Fertigstellung wurde das System auf ausdrücklichen
Wunsch um drei weitere, produktionsreife Funktionsblöcke erweitert. Wie
zuvor gilt: ausschließlich PHP 8.x/MariaDB/HTML5/CSS3/Vanilla-JS, keine
Frameworks, keine kostenpflichtigen APIs, keine Cloud-Dienste – alles läuft
lokal auf dem All-Inkl-Webspace. Jede Änderung wurde unmittelbar per
`php -l` sowie einem abschließenden Voll-Sweep über alle PHP-Dateien des
Projekts geprüft.

### A) Kundenkonto-Registrierung + Login (parallel zum Gast-Zugang)

Das bisherige, rein passwortlose Kundenportal (Zugangslink/QR-Code oder
Auftragsnummer+PIN) bleibt vollständig erhalten und ist weiterhin die
schnellste Möglichkeit für Kunden ohne Registrierung. Zusätzlich können
Kunden nun **wahlweise** ein vollwertiges Kundenkonto anlegen:

- Registrierung mit E-Mail + selbstgewähltem Passwort (bcrypt, Cost 12),
  E-Mail-Verifizierung per Bestätigungslink vor Erstlogin
- Login mit Rate-Limiting/Sperre nach mehreren Fehlversuchen
  (`portal_login_attempts`, analog zum bestehenden Admin-Bruteforce-Schutz)
- „Passwort vergessen"-Funktion mit zeitlich begrenztem, einmalig gültigem
  Reset-Link per E-Mail
- Angemeldete Konto-Kunden sehen zusätzlich zur bisherigen Statusübersicht:
  vollständige Reparaturhistorie, alle Termine, alle Rechnungen/Belege,
  sowie einen neuen **Nachrichten-Bereich** zur direkten Kommunikation mit
  MZ Tech (`customer_messages`, beidseitig lesbar/schreibbar, ungelesene
  Mitarbeiter-Nachrichten werden beim Portal-Aufruf automatisch als
  gelesen markiert)
- Passwort im eingeloggten Zustand jederzeit änderbar
- Neue eigenständige Session (`mztech_portal_sess`) getrennt von der
  Admin-Session, damit sich Kunden- und Mitarbeiter-Login im selben
  Browser niemals überschneiden können
- Sämtliche Datenbankzugriffe im Portal filtern konsequent nach
  `portal_current_customer_id()` statt nach einer vom Client übergebenen
  ID (IDOR-Schutz), sowohl im bestehenden Gast-Modus als auch im neuen
  Konto-Modus

### B) Kalender-Synchronisation (lokal + optional Google)

Auf Wunsch wurden **beide** Varianten parallel umgesetzt:

- **Lokaler .ics-Feed (Standard, immer aktiv):** Neuer Endpunkt liefert
  alle Termine/Reparaturen als Standard-iCalendar-Datei aus – abonnierbar
  in jedem gängigen Kalenderprogramm (Apple Kalender, Google Kalender,
  Outlook, Thunderbird) über einen individuellen, geheimen Abo-Link, ganz
  ohne externe API oder Internetzugriff des Servers
- **Optionale Google-Kalender-Synchronisation (OAuth2, standardmäßig
  deaktiviert):** In den Admin-Einstellungen aktivierbar; nutzt
  ausschließlich das kostenlose Kontingent der Google Calendar API, keine
  Pflicht-Freischaltung für den Betrieb des Systems
- Beide Wege sind komplett unabhängig voneinander nutzbar – wer keine
  Google-Anbindung möchte, verwendet einfach nur den lokalen Feed

### C) Bild-Upload durch Kunden

Kunden können nun an zwei Stellen eigene Fotos hochladen, um dem Team die
Diagnose zu erleichtern:

**1. Bei der öffentlichen Reparaturanfrage (`anfrage.php`)**
- Bis zu `MAX_PHOTOS_PER_UPLOAD` (5) Fotos gleichzeitig, JPG/PNG/GIF/WebP,
  je max. 10 MB
- Validierung (Dateiendung, Größe, Bestätigung als echtes Bild via
  `getimagesize()`, `is_uploaded_file()`) erfolgt vollständig **vor** dem
  Anlegen des Datenbank-Eintrags
- Speicherung außerhalb des Webroots unter zufälligem, nicht erratbarem
  Dateinamen (`uploads/requests/{request_id}/…`)
- Admin sieht die Fotos in der neuen Foto-Galerie in
  `repair_request_view.php`, ausgeliefert über einen neuen,
  admin-authentifizierten API-Endpunkt (`get_request_photo`)
- Wird eine Anfrage vom Admin in einen echten Reparaturauftrag
  umgewandelt, werden die ursprünglich hochgeladenen Fotos automatisch in
  die Foto-Galerie des neuen Auftrags übernommen (nichts geht verloren)

**2. Im Kundenportal, direkt an einem bestehenden Auftrag (`portal.php`)**
- Sowohl Gast- als auch Konto-Kunden können pro Auftrag zusätzliche Fotos
  nachreichen (z. B. während der laufenden Reparatur)
- Dieselbe Validierungslogik wie bei der Reparaturanfrage
  (`validate_photo_upload()`), zusätzlich strikt IDOR-geschützt: Ein
  Kunde kann ausschließlich Fotos zu **eigenen** Aufträgen hochladen oder
  einsehen (`WHERE r.id = ? AND r.customer_id = ?`, nie eine reine
  Foto-/Auftrags-ID ohne Eigentümerprüfung)
- Neuer, eigenständiger Auslieferungs-Endpunkt `portal_photo.php`, der
  bewusst **nicht** die admin-only-Endpunkte aus `api/repairs.php`
  wiederverwendet, sondern über die getrennte Portal-Session
  (`portal_auth.php`) läuft
- Hochgeladene Kundenfotos werden in derselben Tabelle wie
  Mitarbeiter-Fotos geführt (`repair_photos`), aber über die Spalte
  `source = 'customer'` eindeutig unterscheidbar
- Gemeinsam genutzte Hilfsfunktionen (`normalize_multi_upload()`,
  `save_upload()`, `validate_photo_upload()`) wurden dabei zusätzlich um
  eine `getimagesize()`-Prüfung gehärtet, sodass auch als Bild getarnte
  ausführbare Dateien zuverlässig abgelehnt werden – dies kommt allen
  Upload-Wegen im System zugute, auch dem bestehenden Mitarbeiter-Upload

### Geprüfte Bereiche dieser Erweiterungsrunde

Wie bei jeder vorherigen Änderung wurde nach jedem Arbeitsschritt erneut
geprüft: PHP-Syntax aller berührten und aller sonstigen Projektdateien
(`php -l`, durchgehend fehlerfrei), Pfad-/Include-Korrektheit je nach
Verzeichnistiefe (`public/*.php` vs. `public/api/*.php`), CSRF-Schutz auf
allen neuen POST-Formularen/-Endpunkten, XSS-sichere Ausgabe (`h()`)
überall im neuen UI, sowie die durchgängige IDOR-Schutzlogik im gesamten
Kundenportal.
