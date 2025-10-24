<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/audit.php'; // For logging token actions in audit_log

function get_user_token_balance(PDO $pdo, int $userId): int {
    $stmt = $pdo->prepare('SELECT balance FROM user_tokens WHERE user_id = :uid');
    $stmt->execute([':uid' => $userId]);
    $balance = $stmt->fetchColumn();
    return $balance !== false ? (int)$balance : 0;
}

function deduct_tokens(PDO $pdo, int $userId, int $amount, string $action, ?string $entityType = null, ?int $entityId = null): bool {
    if ($amount <= 0) {
        // Log error or throw exception for invalid deduction amount
        error_log("Attempted to deduct non-positive tokens for user $userId, amount $amount");
        return false;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT balance FROM user_tokens WHERE user_id = :uid FOR UPDATE');
        $stmt->execute([':uid' => $userId]);
        $currentBalance = $stmt->fetchColumn();

        if ($currentBalance === false) {
            // User has no token record, treat as 0 balance
            $currentBalance = 0;
        } else {
            $currentBalance = (int)$currentBalance;
        }

        if ($currentBalance < $amount) {
            $pdo->rollBack();
            return false; // Insufficient tokens
        }

        $stmt = $pdo->prepare('UPDATE user_tokens SET balance = balance - :amount WHERE user_id = :uid');
        $stmt->execute([':amount' => $amount, ':uid' => $userId]);

        log_token_transaction($pdo, $userId, -$amount, 'deduction', $action, $entityType, $entityId);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Token deduction failed for user $userId: " . $e->getMessage());
        return false;
    }
}

function add_tokens(PDO $pdo, int $userId, int $amount, string $action, ?string $entityType = null, ?int $entityId = null): bool {
    if ($amount <= 0) {
        // Log error or throw exception for invalid addition amount
        error_log("Attempted to add non-positive tokens for user $userId, amount $amount");
        return false;
    }

    try {
        $pdo->beginTransaction();

        // Upsert: Insert if user_id doesn't exist, otherwise update
        $stmt = $pdo->prepare('
            INSERT INTO user_tokens (user_id, balance)
            VALUES (:uid, :amount)
            ON DUPLICATE KEY UPDATE balance = balance + :amount
        ');
        $stmt->execute([':uid' => $userId, ':amount' => $amount]);

        log_token_transaction($pdo, $userId, $amount, 'recharge', $action, $entityType, $entityId);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Token addition failed for user $userId: " . $e->getMessage());
        return false;
    }
}

function log_token_transaction(PDO $pdo, int $userId, int $amount, string $type, string $action, ?string $entityType = null, ?int $entityId = null): void {
    $stmt = $pdo->prepare('
        INSERT INTO token_transactions (user_id, amount, type, action, entity_type, entity_id)
        VALUES (:uid, :amount, :type, :action, :entity_type, :entity_id)
    ');
    $stmt->execute([
        ':uid' => $userId,
        ':amount' => $amount,
        ':type' => $type,
        ':action' => $action,
        ':entity_type' => $entityType,
        ':entity_id' => $entityId
    ]);

    // Also log to general audit_log for broader visibility
    audit_log($pdo, [
        'user_id' => $userId,
        'action' => "TOKEN_TRANSACTION",
        'entity_type' => 'token_transaction',
        'entity_id' => (int)$pdo->lastInsertId(),
        'details' => [
            'amount' => $amount,
            'type' => $type,
            'specific_action' => $action,
            'related_entity_type' => $entityType,
            'related_entity_id' => $entityId
        ]
    ]);
}
