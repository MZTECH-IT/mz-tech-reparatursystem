-- Kontrollierter, nicht destruktiver Rückbau.
-- Bestehende Kunden-, Firmen-, Projekt-, Reparatur- und Ticketdaten bleiben erhalten.
-- Tabellen/Spalten werden absichtlich NICHT entfernt.
SET @portal_schema := DATABASE();
SELECT @portal_schema AS active_schema,
       'NON_DESTRUCTIVE_ROLLBACK' AS rollback_mode;

INSERT INTO `settings` (`setting_key`,`setting_value`) VALUES
  ('portal_enabled','0'),
  ('customer_accounts_enabled','0'),
  ('portal_email_delivery_enabled','0')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

UPDATE `portal_guest_access`
SET `revoked_at` = COALESCE(`revoked_at`, NOW())
WHERE `revoked_at` IS NULL;

SELECT
  'Portale deaktiviert und Gastlinks widerrufen. Schema und sämtliche Fach-/Historiedaten wurden beibehalten.' AS result,
  'Für vollständige Wiederinbetriebnahme Einstellungen gezielt reaktivieren.' AS next_step;

-- Ein physisches DROP der neuen Tabellen ist bewusst nicht automatisiert:
-- Es würde nach Produktivnutzung Portal- und Tickethistorie vernichten.
