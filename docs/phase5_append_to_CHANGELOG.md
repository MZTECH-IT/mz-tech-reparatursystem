## Phase 5 — Sicherheits-/Robustheitshärtung (Vorbereitung, noch nicht produktiv)

**Status: Patches erstellt und lokal getestet, noch NICHT angewendet/eingespielt.**

Gezielte Sicherheits- und Robustheitsprüfung des Gesamtsystems mit Fokus auf
SQLi/XSS/CSRF/IDOR/Uploads/Session/Cron/XXE/Zip-Bomben. Ergebnis: Kundenportal
(Login, Registrierung, Session, Berechtigungen, Uploads, CSRF) war bereits
vollständig und korrekt abgesichert — keine Änderung nötig. Vier reale
Robustheits-/Härtungslücken im Lieferanten-/Einkaufsmodul gefunden und
behoben:

- **Transaktionsschutz beim Wareneingang** (`purchase_order_item_receive()`):
  die drei zusammengehörigen Datenbankänderungen (Positions-Menge,
  Lagerbestand, Bestellstatus) laufen jetzt atomar in einer Transaktion —
  verhindert inkonsistente Zwischenzustände bei einem Fehler mitten in der
  Verarbeitung.
- **XML-Import gehärtet**: `LIBXML_NONET` beim Parsen ergänzt (kein
  Netzwerkzugriff während des Parsens möglich).
- **ZIP-Import gehärtet**: Eintragsanzahl- und Größenlimit vor dem
  Entpacken (Schutz vor Zip-Bomben), normale Importe unverändert
  funktionsfähig.
- **CLI-Sync-Worker gegen parallele Doppel-Ausführung abgesichert**
  (Datei-Sperre) — verhindert doppelte Synchronisation bei zu kurzem
  Cron-Intervall.

Zusätzlich: Aufrufe des HTTP-Cron-Endpunkts mit Secret-Übertragung per GET
(statt POST) werden jetzt protokolliert (ohne das Secret selbst
auszugeben) als Hinweis zur Umstellung auf den sichereren CLI-Worker.

Geprüft und bewusst unverändert gelassen: Zugriff auf Lieferantendokumente
ist rollenbasiert (nicht lieferantenspezifisch) — entspricht dem
bestehenden, beabsichtigten Rechtemodell dieses internen Werkzeugs, kein
Bug.

Alle Änderungen additiv und rückwärtskompatibel: keine bestehende Funktion
wurde entfernt oder in ihrem bisherigen Verhalten verändert (nur
zusätzliche Absicherung).
