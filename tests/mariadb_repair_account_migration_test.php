<?php
declare(strict_types=1);

/**
 * Integrationstest gegen eine ausdrücklich isolierte lokale MariaDB auf Port
 * 33079. Verwendet weder Projektkonfiguration noch Produktivzugänge.
 */
$root = dirname(__DIR__);
$dbName = 'mztech_repair_account_test';
$pdo = new PDO('mysql:host=127.0.0.1;port=33079;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => true,
]);

$template = (string)file_get_contents($root . '/deployment/tools/repair_account_runner_template.php');
$start = strpos($template, 'function deployment_split_sql');
$end = strpos($template, 'function deployment_execute_sql', $start ?: 0);
if ($start === false || $end === false) throw new RuntimeException('SQL-Parser fehlt.');
eval(substr($template, $start, $end - $start));

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
    echo "OK: {$message}\n";
};

$executeFile = static function (PDO $db, string $path): array {
    $rows = [];
    foreach (deployment_split_sql((string)file_get_contents($path)) as $statement) {
        $keyword = strtoupper((string)strtok(ltrim($statement), " \t\r\n"));
        if (in_array($keyword, ['SELECT','SHOW','DESCRIBE'], true)) {
            $query = $db->query($statement);
            $rows = array_merge($rows, $query->fetchAll(PDO::FETCH_ASSOC));
            $query->closeCursor();
        } else {
            $db->exec($statement);
        }
    }
    return $rows;
};

try {
    $pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    $pdo->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbName}`");
    foreach ([
        'CREATE TABLE users (id INT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY(id)) ENGINE=InnoDB',
        'CREATE TABLE customers (id INT UNSIGNED NOT NULL AUTO_INCREMENT, first_name VARCHAR(100), last_name VARCHAR(100), PRIMARY KEY(id)) ENGINE=InnoDB',
        'CREATE TABLE settings (setting_key VARCHAR(100) NOT NULL, setting_value TEXT, PRIMARY KEY(setting_key)) ENGINE=InnoDB',
        'CREATE TABLE repairs (id INT UNSIGNED NOT NULL AUTO_INCREMENT, device_type VARCHAR(100) NOT NULL, price DECIMAL(10,2) NULL, advance_payment DECIMAL(10,2) NOT NULL DEFAULT 0.00, internal_notes TEXT NULL, PRIMARY KEY(id)) ENGINE=InnoDB',
        'CREATE TABLE companies (id INT UNSIGNED NOT NULL AUTO_INCREMENT, company_name VARCHAR(190), PRIMARY KEY(id)) ENGINE=InnoDB',
        'CREATE TABLE customer_accounts (id INT UNSIGNED NOT NULL AUTO_INCREMENT, customer_id INT UNSIGNED NOT NULL, email VARCHAR(190) NOT NULL, password_hash VARCHAR(255) NOT NULL, is_verified TINYINT(1) NOT NULL DEFAULT 0, verify_token VARCHAR(64), verify_token_hash CHAR(64), verify_expires DATETIME, reset_token VARCHAR(64), reset_token_hash CHAR(64), reset_expires DATETIME, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY(id)) ENGINE=InnoDB',
        'CREATE TABLE company_contacts (id INT UNSIGNED NOT NULL AUTO_INCREMENT, company_id INT UNSIGNED NOT NULL, first_name VARCHAR(100), last_name VARCHAR(100), email VARCHAR(190), password_hash VARCHAR(255), is_verified TINYINT(1) NOT NULL DEFAULT 0, verify_token VARCHAR(64), verify_token_hash CHAR(64), verify_expires DATETIME, reset_token VARCHAR(64), reset_token_hash CHAR(64), reset_expires DATETIME, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY(id)) ENGINE=InnoDB',
        "CREATE TABLE portal_invitations (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, recipient_type ENUM('customer','company_contact') NOT NULL, recipient_id INT UNSIGNED NOT NULL, email VARCHAR(190), token_hash CHAR(64), expires_at DATETIME, accepted_at DATETIME, revoked_at DATETIME, created_by INT UNSIGNED, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id)) ENGINE=InnoDB",
        'CREATE TABLE portal_activity_log (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY(id)) ENGINE=InnoDB',
        'CREATE TABLE email_templates (status_key VARCHAR(100) NOT NULL, subject VARCHAR(255), body TEXT, enabled TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY(status_key)) ENGINE=InnoDB',
        "INSERT INTO repairs (device_type) VALUES ('Fernseher'),('Unbekanntes TEST-Gerät')",
        "INSERT INTO customers (first_name,last_name) VALUES ('TEST','Kunde')",
        "INSERT INTO companies (company_name) VALUES ('TEST Firma')",
        "INSERT INTO customer_accounts (customer_id,email,password_hash,is_verified) VALUES (1,'test@example.invalid','hash',0)",
        "INSERT INTO company_contacts (company_id,first_name,last_name,email,password_hash,is_verified) VALUES (1,'TEST','Kontakt','firma@example.invalid','hash',0)",
    ] as $statement) {
        $pdo->exec($statement);
    }

    $repairPreflight = $executeFile($pdo, $root . '/sql/repair_device_work_preflight.sql');
    $accountPreflight = $executeFile($pdo, $root . '/sql/account_verification_preflight.sql');
    foreach (array_merge($repairPreflight, $accountPreflight) as $row) {
        $assert(!in_array(($row['status'] ?? ''), ['FEHLT','FEHLER'], true), 'Preflight meldet keine unerwartete Abweichung');
    }

    $executeFile($pdo, $root . '/sql/repair_device_work_migration.sql');
    $executeFile($pdo, $root . '/sql/account_verification_migration.sql');
    $repairPost = $executeFile($pdo, $root . '/sql/repair_device_work_postcheck.sql');
    $accountPost = $executeFile($pdo, $root . '/sql/account_verification_postcheck.sql');
    foreach (array_merge($repairPost, $accountPost) as $row) {
        $assert(!in_array(($row['status'] ?? ''), ['FEHLT','FEHLER'], true), 'Postcheck meldet keine Abweichung');
    }

    $unknown = $pdo->query("SELECT r.device_type, r.device_type_legacy_value, d.technical_key
        FROM repairs r JOIN device_types d ON d.id=r.device_type_id WHERE r.id=2")->fetch();
    $assert($unknown['device_type'] === 'Unbekanntes TEST-Gerät', 'Unbekannter Altwert bleibt unverändert');
    $assert($unknown['device_type_legacy_value'] === 'Unbekanntes TEST-Gerät', 'Unbekannter Altwert wird separat bewahrt');
    $assert($unknown['technical_key'] === 'sonstiges', 'Unbekannter Altwert erhält defensive Sonstiges-Zuordnung');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM portal_admin_notifications WHERE status='pending'")->fetchColumn() === 2,
        'Ausstehende Kunden- und Firmenkonten erzeugen Benachrichtigungen');

    $executeFile($pdo, $root . '/sql/repair_device_work_migration.sql');
    $executeFile($pdo, $root . '/sql/account_verification_migration.sql');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM device_types WHERE technical_key='sonstiges'")->fetchColumn() === 1,
        'Erneuter Import erzeugt keine doppelten Gerätearten');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM portal_admin_notifications')->fetchColumn() === 2,
        'Erneuter Import erzeugt keine doppelten Benachrichtigungen');

    echo "ERGEBNIS: {$checks} Prüfungen bestanden\n";
} finally {
    $pdo->exec('USE mysql');
    $pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}
