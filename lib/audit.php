<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

/**
 * Create a per-request ID once and reuse it across all audit calls.
 */
function current_request_id(): string {
    static $rid = null;
    if ($rid === null) {
        $rid = bin2hex(random_bytes(16)); // 32 hex chars
    }
    return $rid;
}

/**
 * Safe accessor helpers
 */
function client_ip(): string {
    $keys = ['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            // X-Forwarded-For can be a list; take first
            $v = explode(',', $_SERVER[$k])[0];
            return trim($v);
        }
    }
    return '0.0.0.0';
}

/**
 * Write a single audit record.
 *
 * $details should be a small array (avoid raw payloads / passwords / tokens!)
 */
function audit_log(PDO $pdo, array $opts): void {
    $sql = "INSERT INTO audit_log
            (user_id, session_id, request_id, ip, user_agent, route, method,
             action, entity_type, entity_id, status_code, details)
            VALUES
            (:user_id, :session_id, :request_id, :ip, :user_agent, :route, :method,
             :action, :entity_type, :entity_id, :status_code, :details)";

    $sessionId = session_status() === PHP_SESSION_ACTIVE ? session_id() : null;
    $route     = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? 'unknown');
    $method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    $detailsJson = null;
    if (isset($opts['details']) && is_array($opts['details'])) {
        // hard-scrub sensitive keys if someone accidentally passes them
        $scrub = ['password','confirmPassword','token','authorization','auth','secret','password_hash'];
        foreach ($scrub as $key) {
            if (array_key_exists($key, $opts['details'])) {
                $opts['details'][$key] = '[REDACTED]';
            }
        }
        $detailsJson = json_encode($opts['details'], JSON_UNESCAPED_UNICODE);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':user_id'     => $opts['user_id']     ?? null,
        ':session_id'  => $sessionId,
        ':request_id'  => current_request_id(),
        ':ip'          => client_ip(),
        ':user_agent'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
        ':route'       => substr($opts['route'] ?? $route, 0, 255),
        ':method'      => $opts['method'] ?? $method,
        ':action'      => substr($opts['action'] ?? 'UNKNOWN', 0, 64),
        ':entity_type' => isset($opts['entity_type']) ? substr((string)$opts['entity_type'], 0, 64) : null,
        ':entity_id'   => $opts['entity_id'] ?? null,
        ':status_code' => (int)($opts['status_code'] ?? 200),
        ':details'     => $detailsJson,
    ]);
}
