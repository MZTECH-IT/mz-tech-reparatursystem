# Konto-Verifizierung

- Kunden- und Firmenkonten erzeugen unabhängig von SMTP eine interne Administratorbenachrichtigung.
- Nur interne Administratoren können Konten manuell bestätigen oder Aktivierungslinks verwalten.
- „Jetzt bestätigen“ setzt `is_verified`, `verified_at` und `verified_by`, ohne Passwort, E-Mail, Rolle oder Firmenzuordnung zu ändern.
- Ein manuell bestätigter Firmenkontakt ohne eigenes Passwort erhält anschließend kontrolliert einen Link zum erstmaligen Festlegen des Passworts.
- Aktivierungstokens werden kryptografisch zufällig erzeugt und nur als SHA-256-Hash gespeichert.
- Links sind 72 Stunden gültig, kontogebunden und einmal verwendbar.
- Ein neuer Link widerruft noch offene ältere Links.
- Vollständige Links erscheinen nur unmittelbar nach der Erstellung.
- Bei deaktiviertem SMTP wird keine Scheinsendung gemeldet; der manuelle Kopierweg bleibt verfügbar.
- Fehlversuche werden begrenzt, technische Details und Token werden nicht protokolliert.
