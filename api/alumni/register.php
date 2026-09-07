<?php
/**
 * Public Alumni Self-Registration
 * POST /api/alumni/register.php
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$fullName = trim($input['full_name'] ?? '');
$phoneRaw = trim($input['phone'] ?? '');
$email    = trim($input['email'] ?? '') ?: null;
$gender   = in_array($input['gender'] ?? '', ['male','female','other']) ? $input['gender'] : null;
$gradYear = !empty($input['graduation_year']) ? (int)$input['graduation_year'] : null;
$occupation = trim($input['current_occupation'] ?? '') ?: null;
$location   = trim($input['current_location'] ?? '') ?: null;
$bio        = trim($input['bio'] ?? '') ?: null;
$zoneId     = !empty($input['zone_id']) ? (int)$input['zone_id'] : null;

if ($fullName === '') {
    jsonError('Full name is required');
}

$phone = $phoneRaw ? normalizePhone($phoneRaw) : null;

$pdo = db();

// Optional: prevent exact duplicate by phone
if ($phone) {
    $stmt = $pdo->prepare("SELECT id FROM alumni WHERE phone = ? LIMIT 1");
    $stmt->execute([$phone]);
    if ($stmt->fetch()) {
        jsonError('An alumni record with this phone number already exists');
    }
}

$stmt = $pdo->prepare("
    INSERT INTO alumni (zone_id, full_name, phone, email, gender, graduation_year, current_occupation, current_location, bio)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([$zoneId, $fullName, $phone, $email, $gender, $gradYear, $occupation, $location, $bio]);

$id = (int)$pdo->lastInsertId();

jsonSuccess(['id' => $id, 'full_name' => $fullName], 'Alumni registration successful. Thank you!');
