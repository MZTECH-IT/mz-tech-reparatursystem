# Phase 4 — Patch F: `WEBSITE-INTEGRATION.html`

## Befund vor dem Patch (verifiziert, kein Code fehlt für Portalfunktionen selbst)

Geprüft wurde `public/portal.php` (öffentlicher Kundenportal-Hub) sowie
`private/customer_auth.php`. Ergebnis: **Registrierung, Login, Portalzugang,
Reparaturstatus, Nachrichten, Dokumente/Fotos und Termine sind bereits
vollständig implementiert und miteinander verknüpft:**

- Registrierung/Login/Passwort-Reset/Gastzugang: `private/customer_auth.php`
  (`customer_register()`, `customer_verify_email()`, `customer_attempt_login()`,
  `customer_request_password_reset()`, `customer_reset_password()`), über
  `public/portal.php?view=register|account_login|forgot|reset_password|guest`.
- Reparaturstatus: `repair_status_badge()` + Status-Verlauf-Timeline in
  `portal.php`.
- Termine: eigener Kartenblock „Anstehende Termine" in `portal.php`,
  `SELECT * FROM appointments WHERE customer_id = ? ...`.
- Dokumente/Fotos: „Fotos"-Sektion in `portal.php` über die
  `repair_photos`-Tabelle, Zugriff via `portal_photo.php`.
- Nachrichten: Nachrichtenformular/-anzeige in `portal.php`
  (`?ok=message_sent#messages`), zusätzlich `public/portal_tickets.php`
  für Support-Tickets — beide bidirektional mit `portal.php` verlinkt.

**Der einzige tatsächliche Lücke:** `WEBSITE-INTEGRATION.html` — die
bestehende, für den Webmaster gedachte Integrationsdatei — verlinkt bisher
ausschließlich auf `public/termin.php` (Terminbuchung, kein Login nötig)
und `public/anfrage.php` (Reparaturanfrage, kein Login nötig). Es gibt
**keinen einzigen Verweis auf `public/portal.php`** — bestehende Kunden
haben von der öffentlichen Website aus keinen Weg zu ihrem Portal
(Status/Nachrichten/Dokumente/Termine). Genau das ist mit „alle
vorhandenen Portalfunktionen über die Website erreichbar machen" gemeint
und wird hier geschlossen — **ohne neue PHP-Logik, ohne neue
Datenbankstruktur**, rein als zusätzlicher Link in der bereits
bestehenden Integrationsdatei.

## Patch (additiv, erweitert 2 der 5 bestehenden Copy-Paste-Blöcke)

### VORHER — Hero-Buttons (verbatim aus dem echten Repository)
```html
<div class="hero-btns">
  <a href="https://mztech-it.de/repair/public/termin.php" class="btn-primary">📅 Termin vereinbaren</a>
  <a href="https://mztech-it.de/repair/public/anfrage.php" class="btn-outline">🔧 Reparatur anfragen</a>
  <a href="#leistungen" class="btn-outline">Leistungen ansehen</a>
</div>
```

### NACHHER
```html
<div class="hero-btns">
  <a href="https://mztech-it.de/repair/public/termin.php" class="btn-primary">📅 Termin vereinbaren</a>
  <a href="https://mztech-it.de/repair/public/anfrage.php" class="btn-outline">🔧 Reparatur anfragen</a>
  <a href="https://mztech-it.de/repair/public/portal.php" class="btn-outline">👤 Kundenportal</a>
  <a href="#leistungen" class="btn-outline">Leistungen ansehen</a>
</div>
```

### VORHER — Navigationsmenü (verbatim)
```html
<li><a href="https://mztech-it.de/repair/public/termin.php">Termin vereinbaren</a></li>
<li><a href="https://mztech-it.de/repair/public/anfrage.php" class="nav-cta">Reparatur anfragen</a></li>
```

### NACHHER
```html
<li><a href="https://mztech-it.de/repair/public/termin.php">Termin vereinbaren</a></li>
<li><a href="https://mztech-it.de/repair/public/anfrage.php" class="nav-cta">Reparatur anfragen</a></li>
<li><a href="https://mztech-it.de/repair/public/portal.php">Kundenportal</a></li>
```

### Nicht verändert

Die „Kontakt-Karten" und der „Floating Button" (beide ausschließlich für
die Terminbuchung gedacht) sowie die Konfigurationshinweise wurden bewusst
**nicht** angefasst — Kundenportal-Erreichbarkeit ist über Hero-Button und
Navigationsmenü bereits durchgängig und prominent gegeben; das Risiko
einer fehlerhaften Rekonstruktion nicht-verbatim geprüfter Blöcke wird so
vermieden (Vorgabe „nichts erfinden").

### Getestet

- HTML-Wohlgeformtheit der geänderten Blöcke: `DOMDocument`-Parse ohne
  Fehler.
- URL-Musterkonsistenz: alle drei Links (`termin.php`, `anfrage.php`,
  `portal.php`) folgen exakt demselben Domain-/Pfadschema
  (`https://mztech-it.de/repair/public/*.php`) — per Regex-Prüfung
  bestätigt, kein Ausreißer.
