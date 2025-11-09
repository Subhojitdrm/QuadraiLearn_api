<?php
declare(strict_types=1);

/**
 * POST /api/v1/admin/analytics/exports
 *
 * Create a new data export
 *
 * Request Body:
 * {
 *   "type": "csv",  // or "json"
 *   "dataset": "transactions",  // transactions, purchases, referrals, authorizations, users
 *   "filters": {
 *     "from": "2025-11-01",
 *     "to": "2025-11-08",
 *     "token_type": "regular"  // optional, dataset-specific filters
 *   }
 * }
 *
 * Scope: admin
 */

require_once __DIR__ . '/../../../../config.php';
require_once __DIR__ . '/../../../../db.php';
require_once __DIR__ . '/../../../../lib/errors.php';
require_once __DIR__ . '/../../../../lib/headers.php';
require_once __DIR__ . '/../../../../lib/exports.php';

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error('method_not_allowed', 'Only POST requests are allowed', [], 405);
}

try {
    // Validate standard headers
    validate_standard_headers();

    // Validate authentication and admin scope
    $userId = validate_scopes(['admin']);

    // Get and validate request body
    $requestBody = file_get_contents('php://input');
    $data = json_decode($requestBody, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        validation_error('Invalid JSON in request body');
    }

    // Validate required fields
    if (!isset($data['type']) || !isset($data['dataset'])) {
        validation_error('type and dataset are required');
    }

    $type = $data['type'];
    $dataset = $data['dataset'];
    $filters = $data['filters'] ?? [];

    // Validate type
    if (!in_array($type, ['csv', 'json'], true)) {
        validation_error('type must be either "csv" or "json"');
    }

    // Validate dataset
    $validDatasets = ['transactions', 'purchases', 'referrals', 'authorizations', 'users'];
    if (!in_array($dataset, $validDatasets, true)) {
        validation_error('Invalid dataset. Available: ' . implode(', ', $validDatasets));
    }

    // Validate filters is an object/array
    if (!is_array($filters)) {
        validation_error('filters must be an object');
    }

    // Validate date filters if present
    if (isset($filters['from']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['from'])) {
        validation_error('Invalid from date format. Use YYYY-MM-DD');
    }

    if (isset($filters['to']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['to'])) {
        validation_error('Invalid to date format. Use YYYY-MM-DD');
    }

    // Validate date range if both present
    if (isset($filters['from']) && isset($filters['to'])) {
        $fromDate = new DateTime($filters['from']);
        $toDate = new DateTime($filters['to']);

        if ($fromDate > $toDate) {
            validation_error('from date must be before or equal to to date');
        }

        $diff = $fromDate->diff($toDate);
        if ($diff->days > 365) {
            business_rule_error('Date range for exports cannot exceed 365 days');
        }
    }

    // Get database connection
    $pdo = get_db();

    // Create export request
    $export = create_export($pdo, $userId, $type, $dataset, $filters);

    // Queue async generation (in production, use a job queue)
    // For now, generate synchronously
    generate_export_file($pdo, $export['export_id']);

    // Get updated export status
    $updatedExport = get_export_record($pdo, $export['export_id']);

    // Send response
    http_response_code(201);
    echo json_encode([
        'export_id' => $updatedExport['id'],
        'status' => $updatedExport['status'],
        'type' => $updatedExport['type'],
        'dataset' => $updatedExport['dataset'],
        'estimated_rows' => (int)$updatedExport['estimated_rows'],
        'actual_rows' => $updatedExport['actual_rows'] ? (int)$updatedExport['actual_rows'] : null,
        'download_url' => $updatedExport['download_url'],
        'expires_at' => $updatedExport['expires_at'],
        'created_at' => $updatedExport['created_at'],
        'completed_at' => $updatedExport['completed_at']
    ]);

} catch (InvalidArgumentException $e) {
    validation_error($e->getMessage());
} catch (PDOException $e) {
    error_log('Database error in export creation: ' . $e->getMessage());
    send_error('database_error', 'A database error occurred', [], 500);
} catch (Exception $e) {
    error_log('Error in export creation: ' . $e->getMessage());
    send_error('internal_error', 'An internal error occurred', [], 500);
}
