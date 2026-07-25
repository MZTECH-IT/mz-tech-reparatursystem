<?php
/**
 * MZ Tech – Datenbankverbindung (PDO Singleton)
 */

function get_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        DB_HOST, DB_NAME, DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        error_log('DB connection failed: ' . $e->getMessage());
        die(json_encode(['success' => false, 'message' => 'Datenbankfehler. Bitte Administrator kontaktieren.']));
    }

    return $pdo;
}

/**
 * Einstellung aus der DB lesen
 */
function get_setting(string $key, string $default = ''): string {
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    try {
        $stmt = get_db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $cache[$key] = $row ? (string)$row['setting_value'] : $default;
    } catch (PDOException $e) {
        $cache[$key] = $default;
    }
    return $cache[$key];
}

/**
 * Einstellung in DB speichern
 */
function set_setting(string $key, string $value): void {
    $stmt = get_db()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?,?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
}
