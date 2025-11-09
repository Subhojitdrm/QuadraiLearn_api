<?php
declare(strict_types=1);

/**
 * GET /api/v1/admin/analytics/exports/{id}
 *
 * Get export status and download link
 *
 * URL Parameters:
 * - export_id: Export ID (from query string or $_GET)
 *
 * Scope: admin
 */

require_once __DIR__ . '/../../../../config.php';
require_once __DIR__ . '/../../../../db.php';
require_once __DIR__ . '/../../../../lib/errors.php';
require_once __DIR__ . '/../../../../lib/headers.php';
require_once __DIR__ . '/../../../../lib/exports.php';

header('Content-Type: application/json');

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_error('method_not_allowed', 'Only GET requests are allowed', [], 405);
}

try {
    // Validate standard headers
    validate_standard_headers();

    // Validate authentication and admin scope
    validate_scopes(['admin']);

    // Get export_id from query string
    $exportId = $_GET['export_id'] ?? null;

    if (!$exportId) {
        validation_error('export_id parameter is required');
    }

    // Validate ULID format (26 characters)
    if (strlen($exportId) !== 26) {
        validation_error('Invalid export_id format');
    }

    // Get database connection
    $pdo = get_db();

    // Get export record
    $export = get_export_record($pdo, $exportId);

    if (!$export) {
        not_found_error('Export not found');
    }

    // Build response
    $response = [
        'export_id' => $export['id'],
        'status' => $export['status'],
        'type' => $export['type'],
        'dataset' => $export['dataset'],
        'filters' => json_decode($export['filters'], true),
        'estimated_rows' => $export['estimated_rows'] ? (int)$export['estimated_rows'] : null,
        'actual_rows' => $export['actual_rows'] ? (int)$export['actual_rows'] : null,
        'created_at' => $export['created_at'],
        'completed_at' => $export['completed_at']
    ];

    // Add download URL only if status is ready and not expired
    if ($export['status'] === 'ready') {
        $response['download_url'] = $export['download_url'];
        $response['expires_at'] = $export['expires_at'];
    }

    // Add error message if failed
    if ($export['status'] === 'failed' && $export['error_message']) {
        $response['error_message'] = $export['error_message'];
    }

    // Send response
    http_response_code(200);
    echo json_encode($response);

} catch (PDOException $e) {
    error_log('Database error in export status: ' . $e->getMessage());
    send_error('database_error', 'A database error occurred', [], 500);
} catch (Exception $e) {
    error_log('Error in export status: ' . $e->getMessage());
    send_error('internal_error', 'An internal error occurred', [], 500);
}
