<?php
declare(strict_types=1);

/**
 * Error Handling Utilities
 *
 * Provides uniform error response format according to API specification:
 * { "error": { "code": "...", "message": "...", "details": {...} } }
 */

// Error codes as constants
define('ERROR_INVALID_INPUT', '400_INVALID_INPUT');
define('ERROR_UNAUTHENTICATED', '401_UNAUTHENTICATED');
define('ERROR_FORBIDDEN', '403_FORBIDDEN');
define('ERROR_NOT_FOUND', '404_NOT_FOUND');
define('ERROR_CONFLICT', '409_CONFLICT');
define('ERROR_GONE', '410_GONE');
define('ERROR_PRECONDITION_FAILED', '412_PRECONDITION_FAILED');
define('ERROR_BUSINESS_RULE', '422_BUSINESS_RULE');
define('ERROR_RATE_LIMIT', '429_RATE_LIMIT');
define('ERROR_SERVER_ERROR', '500_SERVER_ERROR');
define('ERROR_UPSTREAM_UNAVAILABLE', '503_UPSTREAM_UNAVAILABLE');

/**
 * Send a uniform error response and exit
 *
 * @param string $code Error code (e.g., '400_INVALID_INPUT')
 * @param string $message Human-readable error message
 * @param array $details Additional error details (optional)
 * @param int|null $httpStatus HTTP status code (auto-detected from error code if null)
 */
function send_error(string $code, string $message, array $details = [], ?int $httpStatus = null): void
{
    // Auto-detect HTTP status from error code if not provided
    if ($httpStatus === null) {
        $httpStatus = (int) substr($code, 0, 3);
    }

    // Set HTTP status
    http_response_code($httpStatus);

    // Build response
    $response = [
        'error' => [
            'code' => $code,
            'message' => $message,
        ]
    ];

    // Add details if provided
    if (!empty($details)) {
        $response['error']['details'] = $details;
    }

    // Send JSON response
    header('Content-Type: application/json');
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

/**
 * Validation error helper (400_INVALID_INPUT)
 *
 * @param string $message Error message
 * @param array $details Field-level validation errors
 */
function validation_error(string $message, array $details = []): void
{
    send_error(ERROR_INVALID_INPUT, $message, $details, 400);
}

/**
 * Unauthenticated error (401_UNAUTHENTICATED)
 *
 * @param string $message Error message
 */
function unauthenticated_error(string $message = 'Authentication required'): void
{
    send_error(ERROR_UNAUTHENTICATED, $message, [], 401);
}

/**
 * Forbidden error (403_FORBIDDEN)
 *
 * @param string $message Error message
 */
function forbidden_error(string $message = 'Access forbidden'): void
{
    send_error(ERROR_FORBIDDEN, $message, [], 403);
}

/**
 * Not found error (404_NOT_FOUND)
 *
 * @param string $message Error message
 */
function not_found_error(string $message = 'Resource not found'): void
{
    send_error(ERROR_NOT_FOUND, $message, [], 404);
}

/**
 * Conflict error (409_CONFLICT)
 *
 * @param string $message Error message
 * @param array $details Additional details
 */
function conflict_error(string $message, array $details = []): void
{
    send_error(ERROR_CONFLICT, $message, $details, 409);
}

/**
 * Business rule violation (422_BUSINESS_RULE)
 *
 * @param string $message Error message
 * @param array $details Additional details (e.g., required vs available balance)
 */
function business_rule_error(string $message, array $details = []): void
{
    send_error(ERROR_BUSINESS_RULE, $message, $details, 422);
}

/**
 * Rate limit error (429_RATE_LIMIT)
 *
 * @param string $message Error message
 * @param array $details Additional details (e.g., retry_after)
 */
function rate_limit_error(string $message = 'Rate limit exceeded', array $details = []): void
{
    send_error(ERROR_RATE_LIMIT, $message, $details, 429);
}

/**
 * Server error (500_SERVER_ERROR)
 *
 * @param string $message Error message
 */
function server_error(string $message = 'Internal server error'): void
{
    send_error(ERROR_SERVER_ERROR, $message, [], 500);
}

/**
 * Upstream service unavailable (503_UPSTREAM_UNAVAILABLE)
 *
 * @param string $message Error message
 */
function upstream_unavailable_error(string $message = 'Service temporarily unavailable'): void
{
    send_error(ERROR_UPSTREAM_UNAVAILABLE, $message, [], 503);
}

/**
 * Validate required fields in request data
 *
 * @param array $data Request data
 * @param array $requiredFields List of required field names
 * @return void Exits with validation error if fields are missing
 */
function validate_required_fields(array $data, array $requiredFields): void
{
    $missing = [];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
            $missing[$field] = "Field '{$field}' is required";
        }
    }

    if (!empty($missing)) {
        validation_error('Validation failed', $missing);
    }
}

/**
 * Validate field types
 *
 * @param array $data Request data
 * @param array $rules Validation rules ['field' => 'type']
 * @return void Exits with validation error if validation fails
 */
function validate_types(array $data, array $rules): void
{
    $errors = [];
    foreach ($rules as $field => $type) {
        if (!isset($data[$field])) {
            continue; // Skip missing fields (use validate_required_fields for required checks)
        }

        $value = $data[$field];
        $valid = false;

        switch ($type) {
            case 'int':
            case 'integer':
                $valid = is_int($value) || (is_string($value) && ctype_digit($value));
                break;
            case 'string':
                $valid = is_string($value);
                break;
            case 'bool':
            case 'boolean':
                $valid = is_bool($value);
                break;
            case 'array':
                $valid = is_array($value);
                break;
            case 'email':
                $valid = filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
                break;
            case 'uuid':
                $valid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
                break;
            default:
                $valid = true;
        }

        if (!$valid) {
            $errors[$field] = "Field '{$field}' must be of type {$type}";
        }
    }

    if (!empty($errors)) {
        validation_error('Validation failed', $errors);
    }
}

/**
 * Success response helper
 *
 * @param mixed $data Response data
 * @param int $statusCode HTTP status code (default 200)
 */
function send_success($data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}
