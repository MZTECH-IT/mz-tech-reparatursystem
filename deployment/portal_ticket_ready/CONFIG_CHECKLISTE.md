# Konfigurationscheckliste

- [ ] `private/config.php` bleibt unverändert und wird nicht aus diesem Paket ersetzt.
- [ ] HTTPS ist für alle Portalrouten aktiv.
- [ ] Sichere Session-Cookies (`Secure`, `HttpOnly`, `SameSite=Lax`) funktionieren.
- [ ] `APP_URL_BASE` zeigt auf `/repair_neu/public`.
- [ ] Upload-Pfad liegt außerhalb direkt ausführbarer Webpfade oder verhindert Skriptausführung.
- [ ] Schreibrechte bestehen nur für notwendige Upload-/Sessionverzeichnisse.
- [ ] `portal_enabled` ist nach erfolgreichem Postcheck bewusst gesetzt.
- [ ] `customer_accounts_enabled` ist fachlich freigegeben.
- [ ] `portal_email_delivery_enabled` bleibt bis zur SMTP-Prüfung `0`.
- [ ] SMTP-Absender, Reply-To und Vorlagen sind intern geprüft.
- [ ] Keine API-, Lieferanten-, Buchhaltungs- oder Cron-Aktion wird durch diesen Rollout ausgelöst.
- [ ] PHP-Erweiterung `fileinfo` ist aktiv.
- [ ] Optional: `ZipArchive` für Office-/Archivimporte installieren.
- [ ] Webserver liefert Uploads mit `X-Content-Type-Options: nosniff`.
- [ ] Backup- und Restore-Verfahren wurde praktisch verifiziert.
