<?php
/**
 * Public Instructors / Staff Self-Registration
 * POST /api/instructors/register.php
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
$qualification = trim($input['qualification'] ?? '') ?: null;
$specialization = trim($input['specialization'] ?? '') ?: null;
$bio = trim($input['bio'] ?? '') ?: null;
$zoneId = !empty($input['zone_id']) ? (int)$input['zone_id'] : null;

if ($fullName === '') {
    jsonError('Full name is required');
}

$phone = $phoneRaw ? normalizePhone($phoneRaw) : null;

$pdo = db();

$stmt = $pdo->prepare("
    INSERT INTO instructors (zone_id, full_name, phone, email, gender, qualification, specialization, bio, status, joined_date)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', CURDATE())
");
$stmt->execute([$zoneId, $fullName, $phone, $email, $gender, $qualification, $specialization, $bio]);

$id = (int)$pdo->lastInsertId();

jsonSuccess(['id' => $id, 'full_name' => $fullName], 'Instructor registration successful. Thank you!');
