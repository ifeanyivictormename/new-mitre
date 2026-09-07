<?php
/**
 * Site Settings API – GLOBAL only (no zone_id)
 * Super_admin write; any admin can read.
 *
 * GET  /api/settings/index.php          → list all settings
 * GET  /api/settings/index.php?key=xxx  → get one setting
 * POST /api/settings/index.php          → create / update settings
 *      Body: { "settings": [ {"key":"...","value":"...","description":"..."}, ... ] }
 *      or single: { "key":"...", "value":"...", "description":"..." }
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/auth.php';

$admin  = requireAdmin(['super_admin', 'admin']);
$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];

$canWrite = ($admin['role'] === 'super_admin');

// ---------- GET ----------
if ($method === 'GET') {
    $key = trim($_GET['key'] ?? '');

    if ($key !== '') {
        $stmt = $pdo->prepare("
            SELECT id, setting_key, setting_value, description, updated_at
            FROM settings
            WHERE setting_key = ?
            LIMIT 1
        ");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!$row) {
            jsonError('Setting not found', 404);
        }
        jsonSuccess($row);
    }

    $rows = $pdo->query("
        SELECT id, setting_key, setting_value, description, updated_at
        FROM settings
        ORDER BY setting_key
    ")->fetchAll();

    jsonSuccess($rows);
}

// ---------- POST: Create / Update ----------
if ($method === 'POST') {
    if (!$canWrite) {
        jsonError('Only super_admin can modify site settings', 403);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $items = [];
    if (!empty($input['settings']) && is_array($input['settings'])) {
        $items = $input['settings'];
    } elseif (!empty($input['key'])) {
        $items = [[
            'key'         => $input['key'],
            'value'       => $input['value'] ?? '',
            'description' => $input['description'] ?? null
        ]];
    } else {
        jsonError('Provide "key" + "value" or a "settings" array');
    }

    try {
        $pdo->beginTransaction();

        $upsert = $pdo->prepare("
            INSERT INTO settings (setting_key, setting_value, description)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                description   = COALESCE(VALUES(description), description),
                updated_at    = NOW()
        ");

        $saved = [];
        foreach ($items as $item) {
            $key   = trim($item['key'] ?? '');
            $value = isset($item['value']) ? (string)$item['value'] : '';
            $desc  = isset($item['description']) ? trim($item['description']) : null;

            if ($key === '') continue;

            $upsert->execute([$key, $value, $desc]);
            $saved[] = $key;
        }

        $pdo->commit();
        jsonSuccess(['updated' => $saved], 'Settings saved successfully');

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Settings save error: ' . $e->getMessage());
        jsonError('Failed to save settings: ' . $e->getMessage(), 500);
    }
}

jsonError('Method not allowed', 405);
