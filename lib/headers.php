<?php
declare(strict_types=1);

/**
 * Standard Headers Middleware
 *
 * Validates and extracts required headers per API specification:
 * - Authorization: Bearer <jwt>
 * - X-Request-Id (UUID v4; required for every request)
 * - X-Idempotency-Key (required for mutating calls; 1–128 chars)
 * - X-Source (web|admin|service|mobile)
 */

require_once __DIR__ . '/errors.php';

/**
 * Validate and extract standard headers
 *
 * @param bool $requireIdempotencyKey Whether idempotency key is required (for mutations)
 * @return array Associative array with validated headers
 */
function validate_standard_headers(bool $requireIdempotencyKey = false): array
{
    $headers = [];

    // 1. X-Request-Id (required, must be UUID v4)
    $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
    if (!$requestId) {
        validation_error('X-Request-Id header is required', ['X-Request-Id' => 'Missing required header']);
    }

    // Validate UUID v4 format
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $requestId)) {
        validation_error('X-Request-Id must be a valid UUID v4', ['X-Request-Id' => 'Invalid UUID v4 format']);
    }
    $headers['request_id'] = $requestId;

    // 2. X-Idempotency-Key (required for mutations, 1-128 chars)
    $idempotencyKey = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? null;
    if ($requireIdempotencyKey) {
        if (!$idempotencyKey) {
            validation_error('X-Idempotency-Key header is required for this operation',
                ['X-Idempotency-Key' => 'Missing required header']);
        }

        $keyLength = strlen($idempotencyKey);
        if ($keyLength < 1 || $keyLength > 128) {
            validation_error('X-Idempotency-Key must be between 1 and 128 characters',
                ['X-Idempotency-Key' => 'Length must be 1-128 characters']);
        }
    }
    $headers['idempotency_key'] = $idempotencyKey;

    // 3. X-Source (optional: web|admin|service|mobile)
    $source = $_SERVER['HTTP_X_SOURCE'] ?? 'web';
    $validSources = ['web', 'admin', 'service', 'mobile'];
    if (!in_array($source, $validSources, true)) {
        validation_error('X-Source must be one of: ' . implode(', ', $validSources),
            ['X-Source' => 'Invalid value']);
    }
    $headers['source'] = $source;

    return $headers;
}

/**
 * Get Authorization header (Bearer token)
 *
 * @return string|null JWT token or null if not present
 */
function get_authorization_header(): ?string
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    // Check for Bearer token
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        return $matches[1];
    }

    return null;
}

/**
 * Validate required scopes for the current user
 *
 * @param array $userClaims JWT claims from require_auth()
 * @param array $requiredScopes Array of required scopes (e.g., ['wallet:read'])
 * @return void Exits with forbidden error if scopes not met
 */
function validate_scopes(array $userClaims, array $requiredScopes): void
{
    // Get user role
    $role = $userClaims['role'] ?? 'user';

    // Build scopes based on role
    $userScopes = [];
    switch ($role) {
        case 'admin':
            $userScopes = [
                'wallet:read', 'wallet:write', 'wallet:admin',
                'analytics:read',
                'payments:write',
                'promotions:write', 'promotions:admin'
            ];
            break;
        case 'service':
            $userScopes = ['wallet:write', 'payments:write'];
            break;
        case 'user':
        default:
            $userScopes = ['wallet:read', 'wallet:write'];
            break;
    }

    // Check if user has all required scopes
    $missingScopes = array_diff($requiredScopes, $userScopes);
    if (!empty($missingScopes)) {
        forbidden_error('Insufficient permissions: missing scopes ' . implode(', ', $missingScopes));
    }
}

/**
 * Check if user has admin role
 *
 * @param array $userClaims JWT claims from require_auth()
 * @return void Exits with forbidden error if not admin
 */
function require_admin(array $userClaims): void
{
    $role = $userClaims['role'] ?? 'user';
    if ($role !== 'admin') {
        forbidden_error('Admin access required');
    }
}

/**
 * Extract cursor from query parameters
 *
 * @param int $defaultLimit Default limit if not provided
 * @param int $maxLimit Maximum allowed limit
 * @return array ['limit' => int, 'cursor' => string|null]
 */
function get_pagination_params(int $defaultLimit = 25, int $maxLimit = 100): array
{
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : $defaultLimit;
    $cursor = $_GET['cursor'] ?? null;

    // Enforce max limit
    if ($limit > $maxLimit) {
        $limit = $maxLimit;
    }

    // Ensure minimum limit
    if ($limit < 1) {
        $limit = 1;
    }

    return [
        'limit' => $limit,
        'cursor' => $cursor
    ];
}

/**
 * Encode cursor for pagination (base64 of timestamp + id)
 *
 * @param string $timestamp ISO 8601 timestamp
 * @param string $id Record ID
 * @return string Base64-encoded cursor
 */
function encode_cursor(string $timestamp, string $id): string
{
    return base64_encode(json_encode(['t' => $timestamp, 'id' => $id]));
}

/**
 * Decode cursor for pagination
 *
 * @param string $cursor Base64-encoded cursor
 * @return array|null ['t' => timestamp, 'id' => id] or null if invalid
 */
function decode_cursor(string $cursor): ?array
{
    $decoded = base64_decode($cursor, true);
    if ($decoded === false) {
        return null;
    }

    $data = json_decode($decoded, true);
    if (!is_array($data) || !isset($data['t'], $data['id'])) {
        return null;
    }

    return $data;
}
