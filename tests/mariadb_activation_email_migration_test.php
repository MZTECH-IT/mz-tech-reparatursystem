<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dbName = 'mztech_activation_email_test';
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
$execute = static function (PDO $db, string $path): array {
    $rows = [];
    foreach (deployment_split_sql((string)file_get_contents($path)) as $sql) {
        $keyword = strtoupper((string)strtok(ltrim($sql), " \t\r\n"));
        if (in_array($keyword, ['SELECT','SHOW','DESCRIBE'], true)) {
            $query = $db->query($sql); $rows = array_merge($rows, $query->fetchAll()); $query->closeCursor();
        } else {
            $db->exec($sql);
        }
    }
    return $rows;
};

try {
    $pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    $pdo->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbName}`");
    foreach ([
        'CREATE TABLE settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT) ENGINE=InnoDB',
        'CREATE TABLE users(id INT UNSIGNED AUTO_INCREMENT,PRIMARY KEY(id)) ENGINE=InnoDB',
        'CREATE TABLE customers(id INT UNSIGNED AUTO_INCREMENT,first_name VARCHAR(100),last_name VARCHAR(100),PRIMARY KEY(id)) ENGINE=InnoDB',
        'CREATE TABLE companies(id INT UNSIGNED AUTO_INCREMENT,company_name VARCHAR(190),PRIMARY KEY(id)) ENGINE=InnoDB',
        'CREATE TABLE customer_accounts(id INT UNSIGNED AUTO_INCREMENT,customer_id INT UNSIGNED,email VARCHAR(190),is_verified TINYINT(1) NOT NULL DEFAULT 0,is_active TINYINT(1) NOT NULL DEFAULT 1,verify_token VARCHAR(64),verify_token_hash CHAR(64),verify_expires DATETIME,PRIMARY KEY(id)) ENGINE=InnoDB',
        'CREATE TABLE company_contacts(id INT UNSIGNED AUTO_INCREMENT,company_id INT UNSIGNED,email VARCHAR(190),is_verified TINYINT(1) NOT NULL DEFAULT 0,is_active TINYINT(1) NOT NULL DEFAULT 1,password_initialized TINYINT(1) NOT NULL DEFAULT 0,verify_token VARCHAR(64),verify_token_hash CHAR(64),verify_expires DATETIME,PRIMARY KEY(id)) ENGINE=InnoDB',
        "CREATE TABLE portal_invitations(id BIGINT UNSIGNED AUTO_INCREMENT,recipient_type ENUM('customer','company_contact') NOT NULL,recipient_id INT UNSIGNED,email VARCHAR(190),token_hash CHAR(64),expires_at DATETIME,accepted_at DATETIME,revoked_at DATETIME,created_by INT UNSIGNED,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),UNIQUE KEY uq_token(token_hash)) ENGINE=InnoDB",
        'CREATE TABLE portal_activity_log(id BIGINT UNSIGNED AUTO_INCREMENT,PRIMARY KEY(id)) ENGINE=InnoDB',
        'CREATE TABLE email_templates(status_key VARCHAR(100) PRIMARY KEY,subject VARCHAR(255),body TEXT,enabled TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB',
        "INSERT INTO settings VALUES ('portal_email_delivery_enabled','0')",
    ] as $sql) $pdo->exec($sql);

    $preflight = $execute($pdo, $root . '/sql/activation_email_preflight.sql');
    foreach ($preflight as $row) {
        if (in_array(($row['status'] ?? ''), ['FEHLT','FEHLER'], true)) throw new RuntimeException('Preflight-Abweichung: ' . json_encode($row));
    }
    $execute($pdo, $root . '/sql/activation_email_migration.sql');
    $execute($pdo, $root . '/sql/activation_email_migration.sql');
    $postcheck = $execute($pdo, $root . '/sql/activation_email_postcheck.sql');
    foreach ($postcheck as $row) {
        if (in_array(($row['status'] ?? ''), ['FEHLT','FEHLER'], true)) throw new RuntimeException('Postcheck-Abweichung: ' . json_encode($row));
    }
    if ((int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='{$dbName}' AND TABLE_NAME='portal_activation_mail_log'")->fetchColumn() !== 1) {
        throw new RuntimeException('Versandprotokoll fehlt.');
    }
    if ((int)$pdo->query("SELECT COUNT(*) FROM email_templates WHERE status_key IN ('konto_verifizieren','firmenkontakt_konto_erstellt')")->fetchColumn() !== 2) {
        throw new RuntimeException('Aktivierungsvorlagen fehlen.');
    }
    if ($pdo->query("SELECT setting_value FROM settings WHERE setting_key='portal_email_delivery_enabled'")->fetchColumn() !== '0') {
        throw new RuntimeException('Migration darf den Versand nicht aktivieren.');
    }
    echo "MARIADB_ACTIVATION_EMAIL_MIGRATION_OK\n";
} finally {
    $pdo->exec('USE mysql');
    $pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}
