# MZ Tech Repair System – Installationsanleitung

## Voraussetzungen
- PHP 8.0 oder höher
- MariaDB / MySQL 5.7 oder höher
- Apache mit mod_rewrite
- All-Inkl KAS Hosting (getestet)

## Wichtig: Das System läuft jetzt installationsordner-unabhängig

Alle internen Links, Redirects, Formular-Aktionen, Asset-Pfade (CSS/JS) und
AJAX-Aufrufe werden **zur Laufzeit dynamisch** ermittelt – es gibt **keine
fest codierten Pfade** wie `/repair/`, `/public/` oder eine fest einprogrammierte
Domain mehr. Das bedeutet:

- Sie können das Projekt **direkt im Domain-Root** installieren, z. B.
  `https://mztech-it.de/`
- Sie können es genauso gut in einer **Subdomain** (`https://admin.mztech-it.de/`)
  oder einem **Unterordner** (`https://mztech-it.de/reparatur/`) betreiben
- In allen drei Fällen funktioniert das System **ohne jede manuelle
  Anpassung** an Dateien nach dem Upload

Sie müssen sich beim Hochladen also nicht mehr an eine bestimmte
Verzeichnisstruktur oder Domain halten – wählen Sie einfach die Variante,
die zu Ihrem All-Inkl-Zugang passt.

## Schritt 1 – Dateien hochladen

### Variante A – Installation im Domain-Root (empfohlen, z. B. `https://mztech-it.de/`)

```
/www/htdocs/w0XXXXXX/
├── (Inhalt von public/ direkt hier)   ← Webroot der Domain
│   ├── .htaccess
│   ├── index.php
│   ├── dashboard.php
│   ├── ... (alle PHP-Dateien)
│   └── assets/
├── private/                  ← AUSSERHALB des Webroots!
│   ├── config.php
│   ├── db.php
│   ├── functions.php
│   └── ...
├── uploads/                  ← AUSSERHALB des Webroots!
├── logs/                     ← AUSSERHALB des Webroots!
├── backups/                  ← AUSSERHALB des Webroots!
├── sql/
│   └── schema.sql
└── vendor/                   ← PHP-Bibliotheken
```

Wichtig: Der **Inhalt** des Ordners `public/` (also `index.php`, `dashboard.php`,
`assets/` usw.) kommt direkt in den Webroot der Domain – nicht der Ordner
`public/` selbst. Die Ordner `private/`, `uploads/`, `logs/`, `backups/`,
`sql/` und `vendor/` liegen eine Ebene **darüber**, außerhalb des Webroots.

### Variante B – Installation auf einer Subdomain (z. B. `https://admin.mztech-it.de/`)

Gleiche Struktur wie oben, nur dass der Webroot-Ordner die Subdomain ist:

```
/www/htdocs/w0XXXXXX/
├── admin.mztech-it.de/       ← Webroot der Subdomain (Inhalt von public/)
├── private/
├── uploads/
├── logs/
├── backups/
├── sql/
└── vendor/
```

In der KAS-Oberfläche zusätzlich:
1. **Subdomain anlegen**: admin.mztech-it.de
   - Zielverzeichnis: `/www/htdocs/w0XXXXXX/admin.mztech-it.de/`
2. **SSL aktivieren**: Let's Encrypt SSL für admin.mztech-it.de

### Variante C – Installation in einem Unterordner (z. B. `https://mztech-it.de/reparatur/`)

Gleiche Struktur, der Webroot-Ordner ist dann `reparatur/` innerhalb Ihres
bestehenden Webroots. Auch hier bleiben `private/`, `uploads/`, `logs/`,
`backups/`, `sql/` und `vendor/` außerhalb des öffentlich erreichbaren Bereichs
(z. B. eine Ebene über dem Hauptwebroot).

### In jedem Fall gilt:
- Der Inhalt von `public/` → in den gewählten Webroot-Ordner
- Die Ordner `private/`, `sql/`, `vendor/` → außerhalb des Webroots, auf
  gleicher Ebene wie `uploads/`, `logs/`, `backups/`
- **Keine Datei muss nach dem Upload manuell bearbeitet werden** – das System
  erkennt den Installationspfad automatisch beim ersten Aufruf

## Schritt 2 – PHP-Bibliotheken (bereits enthalten)

Der Ordner `vendor/` ist im ZIP-Paket bereits vollständig enthalten und einsatzbereit.
Es ist **keine lokale Composer-Installation und kein SSH-Zugriff erforderlich** – Sie
müssen nichts weiter installieren oder ausführen.

Laden Sie den Ordner `vendor/` einfach wie in Schritt 1 beschrieben per FTP/SFTP
zusammen mit `private/` und `sql/` außerhalb des Webroots hoch. Danach funktionieren
E-Mail-Versand (PHPMailer), PDF-Erstellung (TCPDF) und QR-Code-Generierung (ohne Google-API)
sofort, ohne weitere Schritte.

### Enthaltene Bibliotheken:
- PHPMailer-kompatible Klasse – E-Mail-Versand
- TCPDF-kompatible Klasse – PDF-Generierung
- QR-Code-Klasse – QR-Codes, vollständig lokal generiert (keine Google-API, keine externen Aufrufe)

## Schritt 3 – Datenbank erstellen

1. In KAS: Neue MySQL-Datenbank erstellen
2. Datenbankname, -benutzer und -passwort notieren
3. Die Datei `sql/schema.sql` über phpMyAdmin (oder den Setup-Assistenten in
   Schritt 4) importieren

## Schritt 4 – Installation via Setup-Assistent

Öffnen Sie im Browser die Adresse, unter der Sie den Inhalt von `public/`
abgelegt haben, z. B.:

```
https://mztech-it.de/setup.php
```

(oder `https://admin.mztech-it.de/setup.php` bzw. `https://mztech-it.de/reparatur/setup.php`,
je nachdem, welche Variante aus Schritt 1 Sie gewählt haben)

Der Assistent führt Sie durch:
1. Systemprüfung
2. Datenbankverbindung konfigurieren
3. Administrator-Konto anlegen
4. Firmendaten eingeben
5. Fertigstellung

Nach Abschluss des Assistenten ist das System sofort unter der gleichen
Adresse erreichbar – alle Links, Weiterleitungen und AJAX-Aufrufe passen sich
automatisch an den gewählten Installationspfad an.

## Schritt 5 – Nach der Installation

Sobald Sie Schritt 4 des Assistenten abgeschlossen haben, sperrt sich
`setup.php` **automatisch selbst** (es wird die Datei `private/installed.lock`
angelegt). Ein erneuter Aufruf von `setup.php` – egal ob aus Versehen oder von
einem unbefugten Dritten – zeigt danach nur noch einen Hinweis "Installation
bereits abgeschlossen" und führt garantiert keine Datenbank- oder
Konfigurationsänderungen mehr aus. Sie müssen die Datei also **nicht mehr
manuell löschen** – sie können es als zusätzliche Vorsichtsmaßnahme aber
jederzeit tun.

1. SMTP-Einstellungen in Admin → Einstellungen konfigurieren
2. Test-E-Mail senden
3. Ersten Kunden anlegen und Reparatur testen

Die für QR-Codes und E-Mail-Links verwendete Basis-URL wird automatisch aus
der aktuellen Domain/Aufruf-Adresse ermittelt – eine manuelle Konfiguration
ist nicht erforderlich.

## Updates einspielen (bestehende Installation aktualisieren)

Wenn Sie später eine neue Version dieses Systems erhalten (z. B. mit neuen
Funktionen), gehen Sie so vor, damit **keine bestehenden Kunden-, Reparatur-
oder Termindaten verloren gehen**:

1. **Datenbank-Backup erstellen** – entweder über Admin → Backup im System
   oder über phpMyAdmin (Export).
2. Alle Dateien des Updates hochladen **außer**:
   - `private/config.php` (enthält Ihre Datenbank-Zugangsdaten und den
     Verschlüsselungsschlüssel – NICHT überschreiben)
   - `private/installed.lock` (falls vorhanden – NICHT löschen, sonst hält
     `setup.php` die Installation fälschlich für "nicht abgeschlossen")
   - den Ordner `uploads/` (enthält hochgeladene Dateien/Fotos)
3. Die Datei `sql/update.sql` einmal über phpMyAdmin (Reiter „SQL“, Inhalt
   einfügen und ausführen) einspielen. Dieses Skript ist bewusst so
   geschrieben, dass es **niemals bestehende Tabellen oder Zeilen löscht** –
   es legt nur neue Tabellen/Spalten an, falls diese noch fehlen
   (`CREATE TABLE IF NOT EXISTS`), und ergänzt neue Standardwerte, ohne
   vorhandene zu überschreiben.
4. Fertig – es ist **keine erneute Ausführung von `setup.php`** nötig und
   auch nicht möglich, da der Assistent nach der Erstinstallation automatisch
   gesperrt bleibt (siehe oben).

## Verzeichnis-Berechtigungen (chmod)

```bash
chmod 750 /www/htdocs/w0XXXXXX/private/
chmod 750 /www/htdocs/w0XXXXXX/uploads/
chmod 750 /www/htdocs/w0XXXXXX/logs/
chmod 750 /www/htdocs/w0XXXXXX/backups/
chmod 640 /www/htdocs/w0XXXXXX/private/config.php
```

## Sicherheitshinweise

- Die `private/` Verzeichnis liegt AUSSERHALB des Webroots – kein direkter Web-Zugriff möglich
- Uploads werden nicht öffentlich zugänglich gespeichert
- `setup.php` sperrt sich nach erfolgreicher Installation automatisch selbst
  (`private/installed.lock`) – ein manuelles Löschen ist nicht mehr nötig,
  aber weiterhin als zusätzliche Vorsichtsmaßnahme möglich
- Starkes Admin-Passwort verwenden (min. 8 Zeichen, Großbuchstabe, Zahl)
- HTTPS ist Pflicht (im .htaccess erzwungen)
- Die Session-Cookies werden automatisch auf den tatsächlichen
  Installationsordner beschränkt (`SESSION_COOKIE_PATH`) – bei einer
  Installation im Unterordner sind Sessions dadurch sauber vom Rest der
  Domain getrennt

## Troubleshooting

**"Weiße Seite" oder 500-Fehler:**
- PHP-Fehlerlog prüfen: `logs/error.log`
- In KAS: PHP-Version auf 8.0+ setzen

**404 Not Found beim Aufruf von index.php oder anderen Seiten:**
- Prüfen, ob der **Inhalt** von `public/` (nicht der Ordner `public/` selbst)
  direkt im gewählten Webroot liegt
- Prüfen, ob `.htaccess` mit hochgeladen wurde (manche FTP-Clients blenden
  Dateien aus, die mit einem Punkt beginnen – „versteckte Dateien anzeigen"
  aktivieren)

**Links oder Bilder funktionieren nach dem Verschieben in einen anderen
Ordner nicht mehr:**
- Das System ermittelt den Installationspfad automatisch aus der aktuellen
  Aufruf-URL. Ein Cache im Browser kann nach einer Verschiebung noch alte
  Pfade zeigen – Seite mit Strg+F5 (Hard-Reload) neu laden

**Datenbankfehler:**
- DB-Zugangsdaten in `private/config.php` prüfen
- Datenbankname enthält manchmal den Kontonamen als Prefix: `w0XXXXXX_dbname`

**Upload-Fehler:**
- `uploads/` Verzeichnis muss schreibbar sein (chmod 750)

**PDF nicht sichtbar:**
- Prüfen, ob der Ordner `vendor/` vollständig hochgeladen wurde (er liegt außerhalb
  des Webroots, siehe Schritt 1). Falls `vendor/autoload.php` fehlt oder unvollständig
  ist, die `vendor/`-Dateien aus dem Projekt-Paket erneut per FTP hochladen.

**QR-Code oder E-Mail-Links zeigen auf die falsche Domain:**
- Die Basis-URL wird automatisch aus der aktuellen Aufruf-Adresse (Domain +
  Installationsordner) ermittelt. Prüfen Sie, ob der Server hinter einem
  Reverse-Proxy oder Load-Balancer läuft, der `HTTP_HOST` verfälscht – das
  ist bei einem klassischen All-Inkl-Hosting normalerweise nicht der Fall

## Kontakt & Support

MZ Tech – Heidestraße 7, 33818 Leopoldshöhe
E-Mail: info@mztech-it.de
