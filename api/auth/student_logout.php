<?php
/**
 * POST /api/auth/student_logout.php
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

logout();
jsonSuccess(null, 'Logged out successfully');
