# MZ Tech Reparaturverwaltung – Test-Checkliste

Diese Checkliste dient dazu, das System nach der Installation (oder nach jedem Update) einmal
vollständig manuell durchzutesten, bevor es im produktiven Alltag genutzt wird. Alle Punkte lassen
sich ohne Entwicklerkenntnisse abhaken – es wird lediglich ein Browser (Desktop + Smartphone
empfohlen) benötigt.

Empfehlung: Diese Datei ausdrucken oder als PDF offen halten und Punkt für Punkt abhaken (☐ → ☑).

---

## 0. Voraussetzungen vor dem Test

- ☐ `setup.php` wurde einmalig aufgerufen und der Installationsassistent vollständig durchlaufen
- ☐ Die Datei `setup.php` hat sich danach selbst gesperrt (Aufruf im Browser zeigt eine
  Sperr-Meldung, kein Formular mehr)
- ☐ Admin-Zugangsdaten wurden notiert und funktionieren
- ☐ HTTPS ist aktiv (Schloss-Symbol im Browser, `http://` leitet automatisch auf `https://` um)
- ☐ Absender-E-Mail-Adresse (SMTP) wurde in den Einstellungen hinterlegt und getestet (siehe 8.5)

---

## 1. Admin-Login & Sicherheit

- ☐ Login mit korrekten Zugangsdaten funktioniert und führt zum Dashboard
- ☐ Login mit falschem Passwort zeigt eine Fehlermeldung, aber keine Info, ob der Benutzername
  existiert
- ☐ Nach 5 fehlgeschlagenen Versuchen wird das Konto/die IP kurzzeitig gesperrt (Brute-Force-Schutz)
- ☐ Nach erfolgreichem Login funktioniert die erste Aktion (z. B. Kunde anlegen) sofort, **ohne**
  dass eine CSRF-Fehlermeldung erscheint (Regressionstest für den in diesem Release behobenen
  CSRF-Bug direkt nach Login)
- ☐ Direkter Aufruf einer geschützten Seite (z. B. `dashboard.php`) ohne Login leitet automatisch
  zum Login-Formular um
- ☐ „Passwort vergessen"/Profil-Passwortänderung funktioniert (falls vorhanden) und erzwingt ein
  sicheres Passwort
- ☐ Logout funktioniert und die Session ist danach wirklich beendet (Zurück-Button führt nicht
  wieder ins Dashboard)
- ☐ Aktivitätsprotokoll (`activity.php`) zeigt den Login-Vorgang korrekt an

## 2. Dashboard

- ☐ Dashboard zeigt aktuelle Kennzahlen (offene Reparaturen, heutige Termine, etc.) korrekt an
- ☐ Diagramme (Chart.js: 30-Tage-Verlauf, Status-Verteilung) laden ohne Fehler und zeigen
  plausible Daten
- ☐ Widgets verlinken korrekt auf die jeweiligen Detailseiten (z. B. Klick auf „offene Reparaturen"
  öffnet gefilterte Liste)
- ☐ Dashboard ist auch auf einem Smartphone-Bildschirm nutzbar (responsives Layout)

## 3. Kalender

- ☐ Monatsansicht zeigt alle Termine des Monats an den richtigen Tagen
- ☐ Wochenansicht zeigt Termine im richtigen Zeitraster
- ☐ Tagesansicht zeigt Termine im richtigen Zeitraster
- ☐ Navigation (vor/zurück, „Heute") funktioniert in allen drei Ansichten
- ☐ Neuen Termin über den Dialog anlegen: Titel, Datum, Uhrzeit, Typ, Notiz, Kundenzuordnung
  (Live-Suche) funktionieren
- ☐ **Regressionstest:** Termin unmittelbar nach einem frischen Login anlegen – darf **nicht** mit
  „Ungültiges CSRF-Token" fehlschlagen
- ☐ Termin per Drag & Drop in der Monatsansicht auf einen anderen Tag verschieben – Änderung bleibt
  nach Neuladen der Seite erhalten
- ☐ Termin per Drag & Drop in der Wochen-/Tagesansicht auf eine andere Uhrzeit verschieben – Dauer
  bleibt erhalten, Änderung bleibt nach Neuladen der Seite erhalten
- ☐ Termin löschen funktioniert (Bestätigungsabfrage erscheint, Termin verschwindet aus allen
  Ansichten)
- ☐ Termin, der mit einem Reparaturauftrag verknüpft ist, zeigt einen Link/Verweis auf den Auftrag

## 4. Kundenverwaltung

- ☐ Neuen Kunden anlegen (Pflichtfelder werden validiert)
- ☐ Kunde suchen/filtern in der Kundenliste
- ☐ Kunde bearbeiten – Änderungen werden gespeichert
- ☐ Kundendetailseite zeigt alle zugehörigen Reparaturen und Termine
- ☐ Kunde löschen (falls erlaubt) – Prüfen, ob verknüpfte Daten sinnvoll behandelt werden
  (z. B. Löschung verhindert, wenn noch offene Aufträge bestehen)
- ☐ DSGVO: Export/Auskunft zu einem Kunden ist möglich (falls im System vorgesehen)

## 5. Reparaturaufträge

Für jede der 14 Status-Stufen der Pipeline (siehe CHANGELOG.md, Abschnitt „Standard-E-Mail-Vorlagen")
einmal durchspielen:

- ☐ Neuen Reparaturauftrag anlegen (Gerät, Hersteller, Modell, Fehlerbeschreibung, Kunde zuordnen)
- ☐ Auftragsnummer wird automatisch und fortlaufend vergeben
- ☐ Statuswechsel eines Auftrags durchführen – zugehörige E-Mail wird korrekt versendet (Absender,
  Betreff, Platzhalter wie `{{vorname}}`, `{{auftragsnummer}}` sind korrekt ersetzt)
- ☐ Statuswechsel auf „Kostenvoranschlag" bzw. „Freigabe ausstehend“ – die E-Mail enthält einen
  funktionierenden Link zum Kundenportal (Regressionstest für den in diesem Release behobenen Bug
  in `email_templates.php`)
- ☐ Auftrag mit Notizen/internen Kommentaren versehen
- ☐ Ersatzteile einem Auftrag zuordnen (siehe auch Abschnitt 6)
- ☐ Auftrag als „abgeschlossen"/„abgeholt" markieren
- ☐ Auftragsliste lässt sich nach Status, Kunde, Datum filtern und sortieren
- ☐ Auftrag löschen/archivieren funktioniert wie vorgesehen

## 6. Ersatzteile / Lagerverwaltung

- ☐ Neues Ersatzteil anlegen (Bezeichnung, Bestand, Mindestbestand, Preis)
- ☐ Bestand über die Schnellkorrektur-Buttons (+/-) per AJAX anpassen
- ☐ **Regressionstest:** Bestandsanpassung funktioniert auch dann sofort (ohne CSRF-Fehler), wenn
  die Ersatzteilliste beim Laden der Seite leer war und danach das erste Teil angelegt wurde
  (behobener Bug in `parts.php`)
- ☐ Warnhinweis erscheint bei Unterschreiten des Mindestbestands
- ☐ Ersatzteil einem Reparaturauftrag zuordnen – Bestand wird automatisch reduziert
- ☐ Ersatzteil bearbeiten/löschen funktioniert

## 7. Öffentliche Terminbuchung (`termin.php`)

- ☐ Seite ist ohne Login erreichbar und zeigt freie Termine gemäß hinterlegten Öffnungszeiten
- ☐ Bereits ausgebuchte oder gesperrte Zeitslots sind nicht auswählbar
- ☐ Termine außerhalb der Vorlaufzeit (`booking_lead_hours`) bzw. zu weit in der Zukunft
  (`booking_max_days_ahead`) sind nicht buchbar
- ☐ DSGVO-Einwilligung ist Pflichtfeld und ohne Häkchen nicht absendbar
- ☐ Nach Absenden erhält der Kunde eine Bestätigungs-E-Mail („angefragt")
- ☐ Neue Anfrage erscheint im Admin-Bereich unter „Terminanfragen" (`booking_requests.php`)
- ☐ Admin kann Anfrage bestätigen → Kunde erhält Bestätigungs-E-Mail, Termin erscheint im Kalender
- ☐ Admin kann Anfrage ablehnen → Kunde erhält Ablehnungs-E-Mail
- ☐ Admin kann Anfrage umplanen (neues Datum/Uhrzeit) → Kunde erhält Info-E-Mail
- ☐ Formular auf einem Smartphone testen (responsives Layout, Datepicker nutzbar)

## 8. Öffentliche Reparaturanfrage (`anfrage.php`)

- ☐ Seite ist ohne Login erreichbar
- ☐ Pflichtfelder werden validiert, DSGVO-Einwilligung ist Pflicht
- ☐ Nach Absenden erhält der Kunde eine Eingangsbestätigung per E-Mail
- ☐ Neue Anfrage erscheint im Admin-Bereich unter „Reparaturanfragen" (`repair_requests.php`)
- ☐ Admin kann Anfrage in einen echten Kunden + Reparaturauftrag umwandeln
- ☐ Admin kann Anfrage ablehnen → Kunde erhält entsprechende E-Mail
- ☐ Formular auf einem Smartphone testen

## 9. Kundenportal (`portal.php`)

- ☐ Zugang über den in der E-Mail versendeten personalisierten Link funktioniert
- ☐ Zugang über PIN-Eingabe funktioniert
- ☐ QR-Code (z. B. aus dem Abholschein/der Terminbestätigung) führt korrekt zum Portal-Login
- ☐ Kunde sieht **ausschließlich** eigene Aufträge/Termine – Test mit zwei verschiedenen
  Kundenlinks/PINs, um sicherzustellen, dass keine fremden Daten sichtbar sind (strikte
  Datenisolation)
- ☐ Bei Status „Kostenvoranschlag"/„Freigabe ausstehend" kann der Kunde den Kostenvoranschlag im
  Portal einsehen und freigeben/ablehnen
- ☐ Freigabe/Ablehnung durch den Kunden löst im Admin-Bereich eine sichtbare Statusänderung aus
- ☐ Ungültiger/abgelaufener Link bzw. falsche PIN führt zu einer sauberen Fehlermeldung (keine
  Systeminfos, kein Zugriff auf fremde Daten)

## 10. PDF-Dokumente

Für jede der 6 Vorlagen einmal ein PDF erzeugen und prüfen:

- ☐ Abholschein – Firmendaten, Kundendaten, QR-Code, §19-UStG-Hinweis (falls Kleinunternehmer)
  korrekt
- ☐ Reparaturauftrag/-annahme – alle Gerätedaten korrekt, Unterschriftenfeld vorhanden (falls
  vorgesehen)
- ☐ Kostenvoranschlag – Positionen, Preise, Summen korrekt berechnet
- ☐ Rechnung – Positionen, MwSt.-Ausweis bzw. §19-UStG-Hinweis, Rechnungsnummer korrekt
- ☐ Reparaturbericht – durchgeführte Arbeiten korrekt aufgelistet
- ☐ Terminbestätigung – Datum/Uhrzeit korrekt, QR-Code zum Portal funktioniert
- ☐ Alle PDFs lassen sich ohne Fehlermeldung öffnen (Adobe Reader, Browser-PDF-Viewer und
  Smartphone testen)
- ☐ Firmenlogo/-daten aus den Einstellungen erscheinen korrekt auf allen PDFs

## 11. E-Mail-Vorlagen (`email_templates.php`)

- ☐ Alle 20 Vorlagen (14 Reparaturstatus + 4 Terminbuchung + 2 Reparaturanfrage) sind in der
  Übersicht sichtbar und einzeln bearbeitbar
- ☐ Vorlage bearbeiten und speichern funktioniert, Platzhalter-Hinweise werden angezeigt
- ☐ „Zurücksetzen" bei **Kostenvoranschlag** und **Freigabe ausstehend** stellt einen Text her, der
  den Portal-Link enthält (Regressionstest für den in diesem Release behobenen Bug)
- ☐ Vorlage kann deaktiviert werden, ohne dass der Statuswechsel selbst fehlschlägt (es wird dann
  nur keine E-Mail verschickt)
- ☐ Test-Versand einer Vorlage (falls vorhanden) kommt an und ist korrekt formatiert

## 12. Einstellungen (`settings.php`)

- ☐ Firmendaten (Name, Adresse, Telefon, E-Mail, USt-IdNr./Kleinunternehmerhinweis) speichern und
  auf PDFs/E-Mails wiederfinden
- ☐ SMTP-Zugangsdaten speichern und mit Testversand prüfen
- ☐ Öffnungszeiten/Buchungseinstellungen (Slot-Dauer, Vorlaufzeit, max. Tage im Voraus, gesperrte
  Tage) speichern und in `termin.php` wirksam prüfen
- ☐ Datenschutztext (Privacy Notice) bearbeiten und prüfen, dass er auf `termin.php`/`anfrage.php`
  korrekt angezeigt wird
- ☐ Firmenlogo hochladen und Anzeige in PDF/Portal prüfen

## 13. Backup & Wartung

- ☐ Datenbank-Backup manuell über `backup.php` erzeugen und herunterladen
- ☐ Backup-Datei lässt sich öffnen/enthält plausible SQL-Daten
- ☐ Backup-Verzeichnis ist von außen nicht direkt aufrufbar (`backups/.htaccess` blockt Zugriff –
  im Browser `https://.../repair/backups/` aufrufen, es muss „403 Forbidden" oder „404" erscheinen)
- ☐ Gleiche Prüfung für `private/`, `logs/`, `uploads/` (jeweils direkter Ordneraufruf blockiert
  bzw. Verzeichnislisting deaktiviert)

## 14. Statistiken (`statistics.php`)

- ☐ Kennzahlen (Umsatz, Auftragsanzahl, Durchlaufzeiten etc.) werden plausibel berechnet
- ☐ Zeitraum-Filter funktioniert
- ☐ Diagramme laden korrekt

## 15. Aktivitätsprotokoll (`activity.php`)

- ☐ Wichtige Aktionen (Login, Anlegen/Ändern/Löschen von Kunden, Aufträgen, Terminen, Ersatzteilen)
  erscheinen mit Zeitstempel und Benutzer im Protokoll
- ☐ Protokoll lässt sich filtern/durchsuchen

## 16. Installation & Update-Sicherheit

- ☐ `setup.php` nach abgeschlossener Installation erneut im Browser aufrufen → Zugriff ist gesperrt
- ☐ `sql/update.sql` auf einer bestehenden, befüllten Datenbank ausführen → keine Datenverluste,
  keine Fehler (nutzt `CREATE TABLE IF NOT EXISTS` / `ON DUPLICATE KEY UPDATE`)
- ☐ Datei-/Ordnerrechte gemäß `INSTALLATION.md` gesetzt (private/ außerhalb des Webroots bzw. per
  `.htaccess` geschützt)

## 17. Allgemeine technische Prüfung

- ☐ Alle Formulare im gesamten System funktionieren beim allerersten Aufruf nach einem frischen
  Login fehlerfrei (kein CSRF-Fehler) – stichprobenartig mind. 3 verschiedene Formulare testen
- ☐ System auf Desktop-Browser (Chrome/Firefox/Edge) getestet
- ☐ System auf mobilem Browser (Smartphone, Safari/Chrome) getestet
- ☐ Seitenaufruf ohne HTTPS (`http://...`) leitet automatisch auf `https://...` weiter
- ☐ Kein Formular/keine URL zeigt PHP-Fehlermeldungen oder Stacktraces (Debug-Modus im Livebetrieb
  deaktiviert)

## 18. Kundenkonto: Registrierung & Login (`portal.php`)

- ☐ Registrierung mit E-Mail + Passwort ist ohne Login möglich, Passwort-Mindestlänge wird geprüft
- ☐ Nach der Registrierung erhält der Kunde eine E-Mail mit Bestätigungslink; Login ist vor der
  Bestätigung nicht möglich
- ☐ Bestätigungslink aktiviert das Konto und ermöglicht danach den Login
- ☐ Login mit falschem Passwort mehrfach hintereinander → Konto wird vorübergehend gesperrt
  (Brute-Force-Schutz), Fehlermeldung ist unspezifisch (kein Hinweis, ob die E-Mail existiert)
- ☐ „Passwort vergessen" versendet einen zeitlich begrenzten Reset-Link; Link ist nach Nutzung bzw.
  Ablauf nicht mehr gültig
- ☐ Eingeloggter Konto-Kunde kann sein Passwort ändern
- ☐ Eingeloggter Konto-Kunde sieht vollständige Reparaturhistorie, alle Termine und Rechnungen
- ☐ Nachrichten-Bereich: Kunde kann eine Nachricht senden, Admin sieht sie im Mitarbeiterbereich
  und kann antworten; ungelesene Mitarbeiter-Nachrichten werden beim nächsten Portal-Aufruf des
  Kunden automatisch als gelesen markiert
- ☐ Konto-Login und der bestehende passwortlose Gast-Zugang (Link/PIN) funktionieren unabhängig
  voneinander im selben Browser, ohne sich gegenseitig abzumelden (getrennte Sessions)
- ☐ Test mit zwei verschiedenen Kundenkonten: keinerlei fremde Daten (Aufträge, Nachrichten,
  Fotos, Rechnungen) sind über das jeweils andere Konto einsehbar

## 19. Kalender-Synchronisation

- ☐ Lokaler .ics-Abo-Link ist über die Admin-Einstellungen auffindbar und liefert eine gültige
  iCalendar-Datei (in Apple Kalender, Google Kalender oder Outlook abonnieren und prüfen, dass
  Termine erscheinen)
- ☐ Der .ics-Link ist geheim/individuell – ohne den korrekten Link ist kein Zugriff möglich
- ☐ Neue/geänderte Termine erscheinen nach dem nächsten Kalender-Abgleich des jeweiligen externen
  Kalenderprogramms automatisch
- ☐ Google-Kalender-Synchronisation ist standardmäßig **deaktiviert** und beeinflusst den
  restlichen Betrieb nicht, solange sie nicht aktiviert wurde
- ☐ Optional (falls gewünscht): Google-Kalender-Synchronisation in den Einstellungen aktivieren,
  OAuth2-Autorisierung durchführen und prüfen, dass neue Termine im verknüpften Google-Kalender
  erscheinen

## 20. Bild-Upload durch Kunden

**Bei der öffentlichen Reparaturanfrage (`anfrage.php`):**
- ☐ Bis zu 5 Fotos (JPG/PNG/GIF/WebP) lassen sich beim Absenden der Anfrage anhängen
- ☐ Eine Datei mit falscher Endung (z. B. `.exe`, in `.jpg` umbenannt) wird zuverlässig abgelehnt
- ☐ Eine Datei über der Größengrenze (>10 MB) wird abgelehnt, mit verständlicher Fehlermeldung
- ☐ Mehr als 5 Dateien gleichzeitig werden abgelehnt, mit verständlicher Fehlermeldung
- ☐ Hochgeladene Fotos sind im Admin-Bereich unter der jeweiligen Anfrage (`repair_request_view.php`)
  sichtbar
- ☐ Nach Umwandlung der Anfrage in einen Reparaturauftrag sind dieselben Fotos auch in der
  Foto-Galerie des neuen Auftrags vorhanden (nichts geht verloren)

**Im Kundenportal, an einem bestehenden Auftrag (`portal.php`):**
- ☐ Sowohl als Gast (Link/PIN) als auch als eingeloggtes Konto lassen sich weitere Fotos zu einem
  eigenen Auftrag hochladen
- ☐ Hochgeladene Fotos erscheinen unmittelbar danach in der Foto-Galerie des Auftrags im Portal
- ☐ Dieselben Validierungen wie bei der Reparaturanfrage greifen auch hier (Dateityp, Größe,
  Anzahl, echtes Bild)
- ☐ Test mit zwei verschiedenen Kundenzugängen: Über die Bild-URL eines fremden Auftrags (Foto-ID
  eines anderen Kunden manuell in der Adressleiste ausprobieren) ist **kein** Zugriff möglich
  (403/404 statt Bildanzeige) – IDOR-Schutz
- ☐ Vom Kunden hochgeladene Fotos sind im Admin-Bereich am jeweiligen Auftrag ebenfalls sichtbar
  und als „vom Kunden" erkennbar

---

## Abschluss

Wenn alle Punkte abgehakt sind, ist das System für den produktiven Einsatz bei MZ Tech freigegeben.
Bei Auffälligkeiten: betroffenen Punkt notieren, Schritt zur Reproduktion festhalten und vor dem
produktiven Start klären.
