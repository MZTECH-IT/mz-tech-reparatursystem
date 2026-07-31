# Datenbankänderungen

## Gerätearten und Reparaturen

- neue Stammdatentabelle `device_types`
- `repairs.device_type_id`
- `repairs.device_type_legacy_value`
- `repairs.working_hours` als `DECIMAL(8,2) NOT NULL DEFAULT 0.00`
- `repairs.hourly_rate` als `DECIMAL(10,2) NOT NULL DEFAULT 79.00`
- `repairs.labor_cost` als `DECIMAL(12,2) NOT NULL DEFAULT 0.00`
- `repairs.performed_work` als `TEXT NULL`
- Index und Fremdschlüssel für `device_type_id`
- Einstellung `default_hourly_rate`, ohne bestehende Einstellung zu überschreiben

Unbekannte Altwerte bleiben in `device_type` und `device_type_legacy_value` erhalten und werden für die stabile Zuordnung defensiv auf „Sonstiges“ referenziert.

## Konto-Verifizierung

- `customer_accounts.verified_at`, `customer_accounts.verified_by`
- `company_contacts.verified_at`, `company_contacts.verified_by`
- `company_contacts.password_initialized` für den sicheren ersten Firmenlogin
- neue Tabelle `portal_admin_notifications`
- neue Tabelle `portal_activation_attempts`
- ausstehende bestehende Konten erhalten eine interne Benachrichtigung
- Aktivierungs-E-Mail-Vorlagen werden aktualisiert

Alle Änderungen sind additiv; keine Geschäftstabelle und keine Geschäftszeile wird gelöscht.
