# Aktivierungs-E-Mail – Produktionsbereitstellung

Die Bereitstellung erfolgt ausschließlich über `deployment/tools/deploy_activation_email.ps1` und die projektbezogenen Windows-Credential-Einträge. Geheimnisse werden weder in diesem Paket noch in Protokollen gespeichert.

Reihenfolge:

1. SMTP-Credential lokal mit `setup_smtp_credentials_gui.ps1` speichern.
2. TEST-Empfänger ausdrücklich mit `setup_activation_test_recipient_gui.ps1` bestätigen.
3. `deploy_activation_email.ps1 -PrepareTest` ausführen. Dabei bleibt der Portalversand deaktiviert.
4. Empfang der gekennzeichneten TEST-Mail manuell bestätigen.
5. `deploy_activation_email.ps1 -Finalize` ausführen: Preflight, Migration, Postcheck, Dateisicherungen, Upload, Hashprüfung und Aktivierung.
6. Kunden- und Firmenaktivierung ausschließlich mit TEST-Konten prüfen.

SQL-Reihenfolge:

1. `sql/activation_email_preflight.sql` – ausschließlich lesend
2. `sql/activation_email_migration.sql` – additiv und wiederholbar
3. `sql/activation_email_postcheck.sql` – ausschließlich lesend

Rollback: `sql/activation_email_rollback.sql` deaktiviert den automatischen Versand, ohne Konten oder Versandhistorie zu löschen. Zusätzlich liegen alle ersetzten Serverdateien mit SHA-256-Manifest unter `backups/production/activation_email_<Zeitstempel>`.

`private/config.php` darf niemals gelesen, heruntergeladen oder überschrieben werden.
