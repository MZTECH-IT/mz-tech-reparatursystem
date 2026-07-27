<?php
/**
 * MZ Tech – Lieferantenverwaltung (Phase 6)
 * ----------------------------------------------------------------------
 * CRUD für Lieferanten, Schnittstellenprofile, Dokumente sowie die
 * Synchronisations-/Test-Orchestrierung (delegiert an
 * private/supplier_adapters.php für den eigentlichen Datenabruf und an
 * private/import_engine.php für das Parsen/den Abgleich).
 */

require_once __DIR__ . '/supplier_adapters.php';
require_once __DIR__ . '/import_engine.php';
require_once __DIR__ . '/pricing_engine.php';

// ── Lieferanten CRUD ─────────────────────────────────────────────────────

function suppliers_list(array $filters = []): array {
    $db = get_db();
    $where = [];
    $params = [];
    if (!empty($filters['status'])) { $where[] = 's.status = ?'; $params[] = $filters['status']; }
    if (!empty($filters['q'])) {
        $like = '%' . $filters['q'] . '%';
        $where[] = '(s.name LIKE ? OR s.short_code LIKE ? OR s.email LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    $sql = 'SELECT s.*,
                   (SELECT COUNT(*) FROM product_supplier_offers o WHERE o.supplier_id = s.id) AS offer_count,
                   (SELECT COUNT(*) FROM supplier_interface_profiles ip WHERE ip.supplier_id = s.id AND ip.is_active = 1) AS active_profile_count
            FROM suppliers s';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY s.name ASC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function supplier_find(int $id): ?array {
    $stmt = get_db()->prepare('SELECT * FROM suppliers WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function supplier_save(array $data, ?int $id, ?int $userId): int {
    $db = get_db();
    $fields = [
        'name'                        => trim($data['name'] ?? ''),
        'short_code'                  => trim($data['short_code'] ?? '') ?: null,
        'status'                      => $data['status'] ?? 'aktiv',
        'contact_person'              => trim($data['contact_person'] ?? '') ?: null,
        'email'                       => trim($data['email'] ?? '') ?: null,
        'phone'                       => trim($data['phone'] ?? '') ?: null,
        'website'                     => trim($data['website'] ?? '') ?: null,
        'address_street'              => trim($data['address_street'] ?? '') ?: null,
        'address_zip'                 => trim($data['address_zip'] ?? '') ?: null,
        'address_city'                => trim($data['address_city'] ?? '') ?: null,
        'address_country'             => trim($data['address_country'] ?? 'DE') ?: 'DE',
        'customer_number_at_supplier' => trim($data['customer_number_at_supplier'] ?? '') ?: null,
        'vat_id'                      => trim($data['vat_id'] ?? '') ?: null,
        'currency'                    => trim($data['currency'] ?? 'EUR') ?: 'EUR',
        'default_tax_rate'            => ($data['default_tax_rate'] ?? '') !== '' ? (float)str_replace(',', '.', (string)$data['default_tax_rate']) : null,
        'payment_terms'               => trim($data['payment_terms'] ?? '') ?: null,
        'delivery_time_days'          => ($data['delivery_time_days'] ?? '') !== '' ? (int)$data['delivery_time_days'] : null,
        'minimum_order_value'         => ($data['minimum_order_value'] ?? '') !== '' ? (float)str_replace(',', '.', (string)$data['minimum_order_value']) : null,
        'notes'                       => trim($data['notes'] ?? '') ?: null,
        'auto_sync_mode'              => $data['auto_sync_mode'] ?? 'manuell',
        'auto_sync_cron_expression'   => trim($data['auto_sync_cron_expression'] ?? '') ?: null,
    ];

    if ($fields['name'] === '') {
        throw new InvalidArgumentException('Name ist erforderlich.');
    }

    if ($id) {
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($fields)));
        $db->prepare("UPDATE suppliers SET $sets WHERE id = ?")->execute([...array_values($fields), $id]);
        supplier_recalculate_next_sync($id);
        log_activity('update', 'suppliers', $id);
        return $id;
    }

    $fields['created_by'] = $userId;
    $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($fields)));
    $qs = implode(', ', array_fill(0, count($fields), '?'));
    $db->prepare("INSERT INTO suppliers ($cols) VALUES ($qs)")->execute(array_values($fields));
    $newId = (int)$db->lastInsertId();
    supplier_recalculate_next_sync($newId);
    log_activity('create', 'suppliers', $newId);
    return $newId;
}

function supplier_set_status(int $id, string $status): void {
    $status = in_array($status, ['aktiv', 'inaktiv', 'archiviert'], true) ? $status : 'inaktiv';
    $archivedAt = $status === 'archiviert' ? date('Y-m-d H:i:s') : null;
    get_db()->prepare('UPDATE suppliers SET status = ?, archived_at = ? WHERE id = ?')->execute([$status, $archivedAt, $id]);
    log_activity('update', 'suppliers', $id, "status=$status");
}

// ── Schnittstellenprofile ────────────────────────────────────────────────

function supplier_interface_profiles_list(int $supplierId): array {
    $stmt = get_db()->prepare('SELECT * FROM supplier_interface_profiles WHERE supplier_id = ? ORDER BY is_active DESC, id ASC');
    $stmt->execute([$supplierId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function supplier_interface_profile_find(int $id): ?array {
    $stmt = get_db()->prepare('SELECT * FROM supplier_interface_profiles WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Speichert ein Schnittstellenprofil. $credentials (Klartext, nur
 * transient) wird sofort verschlüsselt; ein leeres $credentials-Array
 * lässt bereits gespeicherte Zugangsdaten unverändert (Formularfeld
 * "Zugangsdaten unverändert lassen" leer gelassen).
 */
function supplier_interface_profile_save(int $supplierId, array $data, array $credentials, ?int $id): int {
    $db = get_db();
    $interfaceType = (string)($data['interface_type'] ?? 'manual_upload');
    if (!array_key_exists($interfaceType, supplier_interface_type_labels())) {
        throw new InvalidArgumentException('Nicht unterstützter Schnittstellentyp.');
    }
    $authType = (string)($data['auth_type'] ?? 'none');
    if (!in_array($authType, ['none', 'basic', 'bearer_token', 'api_key', 'oauth2', 'custom'], true)) {
        throw new InvalidArgumentException('Nicht unterstützte Authentifizierungsart.');
    }
    if (!empty($credentials['soap_params'])) {
        json_decode((string)$credentials['soap_params'], true, 32, JSON_THROW_ON_ERROR);
    }
    $fields = [
        'supplier_id'    => $supplierId,
        'label'          => trim($data['label'] ?? '') ?: 'Standard-Schnittstelle',
        'interface_type' => $interfaceType,
        'is_active'      => !empty($data['is_active']) ? 1 : 0,
        'endpoint_url'   => trim($data['endpoint_url'] ?? '') ?: null,
        'auth_type'      => $authType,
        'ftp_host'       => trim($data['ftp_host'] ?? '') ?: null,
        'ftp_port'       => ($data['ftp_port'] ?? '') !== '' ? (int)$data['ftp_port'] : null,
        'ftp_path'       => trim($data['ftp_path'] ?? '') ?: null,
        'ftp_passive'    => !empty($data['ftp_passive']) ? 1 : 0,
        'format'         => $data['format'] ?? null,
        'delimiter'      => trim($data['delimiter'] ?? '') ?: null,
        'notes'          => trim($data['notes'] ?? '') ?: null,
    ];

    if (!empty($credentials)) {
        $enc = supplier_interface_credentials_encrypt($credentials);
        $fields['credentials_encrypted'] = $enc['encrypted'];
        $fields['credentials_iv'] = $enc['iv'];
    }

    if ($id) {
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($fields)));
        $db->prepare("UPDATE supplier_interface_profiles SET $sets WHERE id = ?")->execute([...array_values($fields), $id]);
        log_activity('update', 'supplier_interface_profiles', $id);
        return $id;
    }
    $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($fields)));
    $qs = implode(', ', array_fill(0, count($fields), '?'));
    $db->prepare("INSERT INTO supplier_interface_profiles ($cols) VALUES ($qs)")->execute(array_values($fields));
    $newId = (int)$db->lastInsertId();
    log_activity('create', 'supplier_interface_profiles', $newId);
    return $newId;
}

function supplier_interface_profile_delete(int $id): void {
    get_db()->prepare('DELETE FROM supplier_interface_profiles WHERE id = ?')->execute([$id]);
}

/** Führt den "Verbindung testen"-Knopf aus. Gibt niemals Zugangsdaten preis. */
function supplier_interface_test(int $profileId): array {
    $profile = supplier_interface_profile_find($profileId);
    if (!$profile) return ['success' => false, 'message' => 'Schnittstellenprofil nicht gefunden.'];
    $credentials = supplier_interface_credentials_decrypt($profile);
    $adapter = supplier_adapter_factory($profile['interface_type']);
    try {
        return $adapter->testConnection($profile, $credentials);
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Unerwarteter Fehler beim Verbindungstest.'];
    } finally {
        unset($credentials);
    }
}

// ── Dokumente ────────────────────────────────────────────────────────────

function supplier_document_save(array $file, int $supplierId, ?string $description, ?int $uploadedBy): array|false {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return false;
    if (!is_uploaded_file($file['tmp_name'])) return false;
    if ($file['size'] > MAX_UPLOAD_SIZE) return false;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'csv'];
    if (!in_array($ext, $allowed, true)) return false;

    $dir = UPLOAD_PATH . '/suppliers/' . $supplierId;
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) return false;

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return false;

    $db = get_db();
    $db->prepare('INSERT INTO supplier_documents (supplier_id, filename, original_name, file_size, description, uploaded_by) VALUES (?,?,?,?,?,?)')
       ->execute([$supplierId, $filename, basename($file['name']), $file['size'], $description ?: null, $uploadedBy]);
    return ['id' => (int)$db->lastInsertId(), 'filename' => $filename];
}

function supplier_document_find(int $id): ?array {
    $stmt = get_db()->prepare('SELECT * FROM supplier_documents WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function supplier_documents_list(int $supplierId): array {
    $stmt = get_db()->prepare('SELECT * FROM supplier_documents WHERE supplier_id = ? ORDER BY created_at DESC');
    $stmt->execute([$supplierId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function supplier_document_delete(int $id): void {
    $doc = supplier_document_find($id);
    if (!$doc) return;
    $path = UPLOAD_PATH . '/suppliers/' . $doc['supplier_id'] . '/' . $doc['filename'];
    if (is_file($path)) @unlink($path);
    get_db()->prepare('DELETE FROM supplier_documents WHERE id = ?')->execute([$id]);
}

/** Streaming-Endpunkt für Lieferantendokumente (identisches Muster wie serve_company_document()). */
function serve_supplier_document(int $documentId): void {
    $doc = supplier_document_find($documentId);
    if (!$doc) { http_response_code(404); exit; }
    $path = UPLOAD_PATH . '/suppliers/' . $doc['supplier_id'] . '/' . $doc['filename'];
    if (!file_exists($path)) { http_response_code(404); exit; }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'pdf'         => 'application/pdf',
        'jpg', 'jpeg' => 'image/jpeg',
        'png'         => 'image/png',
        'doc'         => 'application/msword',
        'docx'        => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'         => 'application/vnd.ms-excel',
        'xlsx'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv'         => 'text/csv',
        default       => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . rawurlencode($doc['original_name'] ?: $doc['filename']) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
}

// ── Sync-Zeitplanung ─────────────────────────────────────────────────────

/** Berechnet next_scheduled_sync_at gemäß auto_sync_mode (rein informativ, siehe unten). */
function supplier_recalculate_next_sync(int $supplierId): void {
    $supplier = supplier_find($supplierId);
    if (!$supplier) return;
    $base = $supplier['last_sync_at'] ? strtotime($supplier['last_sync_at']) : time();
    $next = match ($supplier['auto_sync_mode']) {
        'stuendlich' => $base + 3600,
        'taeglich'   => $base + 86400,
        'woechentlich' => $base + 7 * 86400,
        default      => null, // manuell / benutzerdefiniert (erfordert echten Cron-Job, siehe unten)
    };
    $nextStr = $next ? date('Y-m-d H:i:s', $next) : null;
    get_db()->prepare('UPDATE suppliers SET next_scheduled_sync_at = ? WHERE id = ?')->execute([$nextStr, $supplierId]);
}

/**
 * Liefert Lieferanten, deren automatische Synchronisation fällig ist.
 * WICHTIG: dieses System selbst kann in dieser Umgebung keinen echten,
 * hintergrundlaufenden Cron-Prozess betreiben (siehe OFFENE_PUNKTE_PHASE6.txt).
 * Diese Funktion ist der Baustein für einen von Ihrem Hosting-Provider aus
 * per echtem Server-Cronjob aufgerufenen Endpunkt (z. B.
 * public/api/supplier_cron.php, einmal pro Stunde aufgerufen) — ohne
 * einen solchen externen Cronjob wird die Synchronisation ausschließlich
 * manuell über den "Jetzt synchronisieren"-Knopf ausgelöst.
 */
function suppliers_due_for_auto_sync(): array {
    $stmt = get_db()->query(
        "SELECT * FROM suppliers
          WHERE status = 'aktiv'
            AND auto_sync_mode != 'manuell'
            AND (next_scheduled_sync_at IS NULL OR next_scheduled_sync_at <= NOW())"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Führt eine vollständige Synchronisation für einen Lieferanten aus: holt
 * die Rohdaten über das aktive Schnittstellenprofil, erkennt das Format,
 * baut eine Vorschau (Import-Job im Status "wartet_auf_freigabe") — wendet
 * die Änderungen ABSICHTLICH NICHT automatisch an, sondern erstellt nur
 * den Import-Job zur manuellen Freigabe (siehe Auftragsabschnitt 7: "Nie
 * automatisch bestellen"; sinngemäß auch für automatische Preisänderungen
 * angewendet, um Fehlimporte nicht unbeaufsichtigt zu übernehmen).
 */
function supplier_sync_now(int $supplierId, ?int $userId): array {
    $supplier = supplier_find($supplierId);
    if (!$supplier) return ['success' => false, 'message' => 'Lieferant nicht gefunden.'];

    $profiles = array_values(array_filter(supplier_interface_profiles_list($supplierId), fn($p) => $p['is_active']));
    if (empty($profiles)) {
        return ['success' => false, 'message' => 'Kein aktives Schnittstellenprofil hinterlegt.'];
    }
    $profile = $profiles[0];
    $credentials = supplier_interface_credentials_decrypt($profile);
    $adapter = supplier_adapter_factory($profile['interface_type']);

    try {
        $result = $adapter->fetchPayload($profile, $credentials);
    } catch (Throwable $e) {
        $result = ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'Unerwarteter Fehler beim Datenabruf.'];
    } finally {
        unset($credentials);
    }

    $db = get_db();
    if (!$result['success'] || $result['raw'] === null) {
        $db->prepare('UPDATE suppliers SET last_sync_at = NOW(), last_sync_status = "fehler", last_sync_message = ? WHERE id = ?')
           ->execute([mb_substr($result['message'], 0, 500), $supplierId]);
        log_activity('sync_error', 'suppliers', $supplierId, $result['message']);
        supplier_recalculate_next_sync($supplierId);
        return ['success' => false, 'message' => $result['message']];
    }

    $format = $profile['format'] ?: import_format_detect($result['filename'] ?? 'data', $result['raw']);
    $parsed = import_parse_rows($format, $result['raw'], ['delimiter' => $profile['delimiter'], 'has_header' => true]);
    if ($parsed['error']) {
        $db->prepare('UPDATE suppliers SET last_sync_at = NOW(), last_sync_status = "fehler", last_sync_message = ? WHERE id = ?')
           ->execute([mb_substr($parsed['error'], 0, 500), $supplierId]);
        supplier_recalculate_next_sync($supplierId);
        return ['success' => false, 'message' => $parsed['error']];
    }

    // Zuletzt verwendetes Import-Profil dieses Lieferanten für die
    // Spaltenzuordnung wiederverwenden, falls vorhanden.
    $profStmt = $db->prepare('SELECT * FROM import_profiles WHERE supplier_id = ? ORDER BY updated_at DESC LIMIT 1');
    $profStmt->execute([$supplierId]);
    $importProfile = $profStmt->fetch(PDO::FETCH_ASSOC);
    if (!$importProfile) {
        $db->prepare('UPDATE suppliers SET last_sync_at = NOW(), last_sync_status = "teilweise", last_sync_message = ? WHERE id = ?')
           ->execute(['Daten abgerufen, aber noch kein Import-Profil (Spaltenzuordnung) hinterlegt – bitte einmalig über den Import-Assistenten anlegen.', $supplierId]);
        supplier_recalculate_next_sync($supplierId);
        return ['success' => false, 'message' => 'Daten abgerufen, aber noch kein Import-Profil hinterlegt. Bitte einmalig über "Import-Datei hochladen" ein Profil anlegen.'];
    }

    $mapping = json_decode($importProfile['column_mapping_json'], true) ?: [];
    $preview = import_build_preview($supplierId, $parsed['header'], $parsed['rows'], $mapping, $importProfile['dedupe_key']);

    $jobId = import_job_create($supplierId, (int)$importProfile['id'], 'adapter_sync', $result['filename'], null, $preview, $userId);

    $db->prepare('UPDATE suppliers SET last_sync_at = NOW(), last_sync_status = "erfolgreich", last_sync_message = ? WHERE id = ?')
       ->execute(['Vorschau erstellt, wartet auf Freigabe (Import-Auftrag #' . $jobId . ').', $supplierId]);
    supplier_recalculate_next_sync($supplierId);
    log_activity('sync', 'suppliers', $supplierId, "import_job=$jobId");

    return ['success' => true, 'message' => 'Synchronisation erfolgreich, Vorschau erstellt.', 'import_job_id' => $jobId];
}

/** Legt einen neuen Import-Job (Audit-Log-Eintrag) aus einer Vorschau an. */
function import_job_create(?int $supplierId, ?int $importProfileId, string $sourceType, ?string $sourceFilename, ?string $sourcePath, array $preview, ?int $userId): int {
    $db = get_db();
    $db->prepare(
        'INSERT INTO import_jobs
            (supplier_id, import_profile_id, source_type, source_filename, source_path, status,
             rows_total, rows_new, rows_updated, rows_unchanged, rows_duplicate, rows_error, rows_warning,
             preview_json, created_by)
         VALUES (?,?,?,?,?, "wartet_auf_freigabe", ?,?,?,?,?,?,?, ?, ?)'
    )->execute([
        $supplierId, $importProfileId, $sourceType, $sourceFilename, $sourcePath,
        count($preview['new']) + count($preview['updated']) + count($preview['unchanged']) + count($preview['duplicate']) + count($preview['errors']),
        count($preview['new']), count($preview['updated']), count($preview['unchanged']),
        count($preview['duplicate']), count($preview['errors']), count($preview['warnings']),
        json_encode($preview, JSON_UNESCAPED_UNICODE),
        $userId,
    ]);
    return (int)$db->lastInsertId();
}
