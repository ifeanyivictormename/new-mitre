<?php
/**
 * Admin Notifications API
 *
 * GET    /api/notifications/index.php
 *        → recent notifications for current admin + unread_count
 *        Query: limit=20 (default), since_id=N (only newer than N), unread_only=1
 *
 * GET    /api/notifications/index.php?action=recipients
 *        → list of active admins (id, full_name, role) excluding self – for compose UI
 *
 * GET    /api/notifications/index.php?action=unread_count
 *        → { unread_count: N } only (lightweight poll)
 *
 * POST   /api/notifications/index.php
 *        Body: { "message": "..." }
 *           or { "message": "...", "recipient_id": N }
 *           or { "message": "...", "recipient_ids": [N, M, ...] }
 *        → create notification(s); defaults to all active admins except sender
 *
 * POST   /api/notifications/index.php?action=mark_read
 *        Body: { "id": N }
 *        → mark one as read
 *
 * POST   /api/notifications/index.php?action=mark_all_read
 *        → mark all for current admin as read
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$admin  = requireAdmin(['super_admin', 'admin']);
$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = trim($_GET['action'] ?? '');

$myId = (int)$admin['id'];

function notifRow(array $row): array {
    return [
        'id'            => (int)$row['id'],
        'sender_id'     => (int)$row['sender_id'],
        'sender_name'   => $row['sender_name'] ?? null,
        'recipient_id'  => (int)$row['recipient_id'],
        'message'       => $row['message'],
        'is_read'       => (int)$row['is_read'] === 1,
        'read_at'       => $row['read_at'],
        'created_at'    => $row['created_at'],
    ];
}

// ---------- GET ----------
if ($method === 'GET') {

    // Lightweight unread count (for polling)
    if ($action === 'unread_count') {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS cnt
            FROM admin_notifications
            WHERE recipient_id = ? AND is_read = 0
        ");
        $stmt->execute([$myId]);
        $cnt = (int)$stmt->fetchColumn();
        jsonSuccess(['unread_count' => $cnt]);
    }

    // Recipients list for compose dropdown
    if ($action === 'recipients') {
        $stmt = $pdo->prepare("
            SELECT id, full_name, role, zone_id
            FROM admins
            WHERE is_active = 1 AND id != ?
            ORDER BY full_name
        ");
        $stmt->execute([$myId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = array_map(function ($r) {
            return [
                'id'        => (int)$r['id'],
                'full_name' => $r['full_name'],
                'role'      => $r['role'],
                'zone_id'   => $r['zone_id'] !== null ? (int)$r['zone_id'] : null,
            ];
        }, $rows);
        jsonSuccess($out);
    }

    // List notifications (optionally only newer than since_id)
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
    $sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;
    $unreadOnly = !empty($_GET['unread_only']);

    $sql = "
        SELECT n.*, s.full_name AS sender_name
        FROM admin_notifications n
        INNER JOIN admins s ON s.id = n.sender_id
        WHERE n.recipient_id = ?
    ";
    $params = [$myId];

    if ($sinceId > 0) {
        $sql .= " AND n.id > ?";
        $params[] = $sinceId;
    }
    if ($unreadOnly) {
        $sql .= " AND n.is_read = 0";
    }

    $sql .= " ORDER BY n.id DESC LIMIT " . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Always return current unread count alongside the list
    $cntStmt = $pdo->prepare("
        SELECT COUNT(*) FROM admin_notifications
        WHERE recipient_id = ? AND is_read = 0
    ");
    $cntStmt->execute([$myId]);
    $unreadCount = (int)$cntStmt->fetchColumn();

    $list = array_map('notifRow', $rows);

    // Highest id in this result set (useful for next poll)
    $maxId = 0;
    foreach ($list as $item) {
        if ($item['id'] > $maxId) $maxId = $item['id'];
    }

    jsonSuccess([
        'notifications' => $list,
        'unread_count'  => $unreadCount,
        'max_id'        => $maxId,
    ]);
}

// ---------- POST ----------
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    // Mark one as read
    if ($action === 'mark_read') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            jsonError('Invalid notification id');
        }
        $stmt = $pdo->prepare("
            UPDATE admin_notifications
            SET is_read = 1, read_at = NOW()
            WHERE id = ? AND recipient_id = ? AND is_read = 0
        ");
        $stmt->execute([$id, $myId]);
        jsonSuccess(['updated' => $stmt->rowCount()], 'Marked as read');
    }

    // Mark all as read
    if ($action === 'mark_all_read') {
        $stmt = $pdo->prepare("
            UPDATE admin_notifications
            SET is_read = 1, read_at = NOW()
            WHERE recipient_id = ? AND is_read = 0
        ");
        $stmt->execute([$myId]);
        jsonSuccess(['updated' => $stmt->rowCount()], 'All marked as read');
    }

    // Send notification(s)
    $message = trim((string)($input['message'] ?? ''));
    if ($message === '' || mb_strlen($message) > 2000) {
        jsonError('Message is required and must be 1–2000 characters');
    }

    $recipientIds = [];
    if (!empty($input['recipient_ids']) && is_array($input['recipient_ids'])) {
        foreach ($input['recipient_ids'] as $rid) {
            $rid = (int)$rid;
            if ($rid > 0 && $rid !== $myId) {
                $recipientIds[] = $rid;
            }
        }
    } elseif (!empty($input['recipient_id'])) {
        $rid = (int)$input['recipient_id'];
        if ($rid > 0 && $rid !== $myId) {
            $recipientIds[] = $rid;
        }
    }

    $recipientIds = array_values(array_unique($recipientIds));

    // If no recipient is provided, send globally to all active admins except self.
    if (empty($recipientIds)) {
        $allStmt = $pdo->prepare("\n            SELECT id\n            FROM admins\n            WHERE is_active = 1 AND id != ?\n            ORDER BY id\n        ");
        $allStmt->execute([$myId]);
        $recipientIds = array_map('intval', $allStmt->fetchAll(PDO::FETCH_COLUMN));
    }

    if (empty($recipientIds)) {
        jsonError('No active recipients available');
    }

    // Validate recipients exist and are active
    $placeholders = implode(',', array_fill(0, count($recipientIds), '?'));
    $check = $pdo->prepare("
        SELECT id FROM admins
        WHERE is_active = 1 AND id IN ($placeholders)
    ");
    $check->execute($recipientIds);
    $validIds = array_map('intval', $check->fetchAll(PDO::FETCH_COLUMN));

    if (count($validIds) !== count($recipientIds)) {
        jsonError('One or more recipients are invalid or inactive');
    }

    $insert = $pdo->prepare("
        INSERT INTO admin_notifications (sender_id, recipient_id, message)
        VALUES (?, ?, ?)
    ");

    $created = [];
    try {
        $pdo->beginTransaction();
        foreach ($validIds as $rid) {
            $insert->execute([$myId, $rid, $message]);
            $newId = (int)$pdo->lastInsertId();
            $created[] = $newId;
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Notification send failed: ' . $e->getMessage());
        jsonError('Failed to send notification', 500);
    }

    jsonSuccess([
        'ids'   => $created,
        'count' => count($created),
    ], 'Notification sent');
}

jsonError('Method not allowed', 405);
