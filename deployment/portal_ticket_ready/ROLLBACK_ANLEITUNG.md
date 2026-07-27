# Rollback

## Anwendung

1. Weitere Uploads sofort stoppen.
2. Betroffene hochgeladene Datei anhand des Upload-Protokolls bestimmen.
3. Die unmittelbar davor angelegte zeitgestempelte Serversicherung verwenden.
4. Nur diese Datei per explizitem FTPS zurückladen.
5. Serverdatei erneut herunterladen und Hash vergleichen.
6. Fehler und Rollback vollständig protokollieren.

## Datenbank

`sql/portal_ticket_rollback.sql` ist nicht destruktiv:

- Kundenportal wird deaktiviert.
- registrierte Kundenkonten werden deaktiviert.
- Portal-E-Mail-Versand wird deaktiviert.
- aktive Gastlinks werden widerrufen.
- Tabellen, Spalten sowie Kunden-, Firmen-, Projekt-, Reparatur-, Ticket-,
  Nachrichten-, Dokument- und Historiedaten bleiben bestehen.

Die Datei nur nach ausdrücklicher Freigabe und nach geprüftem Backup ausführen.
Neue Tabellen niemals pauschal droppen. Ein physischer Schema-Rückbau bedarf
einer separaten Datenaufbewahrungsentscheidung und ist nicht Bestandteil
dieses Pakets.
