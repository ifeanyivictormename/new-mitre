<?php
/**
 * Health check endpoint
 * GET /api/health.php
 */

require_once __DIR__ . '/includes/cors.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/database.php';

try {
    $pdo = db();
    $pdo->query('SELECT 1');
    
    // Count zones as a quick sanity check
    $zoneCount = $pdo->query('SELECT COUNT(*) FROM zones')->fetchColumn();
    
    jsonSuccess([
        'status'      => 'ok',
        'app'         => APP_NAME,
        'version'     => APP_VERSION,
        'database'    => 'connected',
        'zones_count' => (int)$zoneCount,
        'timestamp'   => date('c')
    ], 'System is healthy');
} catch (Exception $e) {
    jsonError('Database connection failed: ' . $e->getMessage(), 500);
}
