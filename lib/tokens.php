<?php
// lib/tokens.php

declare(strict_types=1);

/**
 * Adds or deducts tokens and logs the transaction.
 *
 * @param PDO $pdo The database connection object.
 * @param int $user_id The ID of the user.
 * @param int $amount The number of tokens to add (positive) or deduct (negative).
 * @param string $action A description of the action (e.g., 'generate_book', 'initial_signup_bonus').
 * @param string $entity_type Type of entity related to the transaction (e.g., 'book', 'user').
 * @param int|null $entity_id ID of the related entity.
 * @param string $type The category of the transaction ('recharge', 'deduction', 'bonus', etc.).
 * @return bool True on success, false on failure (rollback).
 */
function add_tokens(PDO $pdo, int $user_id, int $amount, string $action, string $entity_type, ?int $entity_id, string $type): bool
{
    // Ensure the amount is non-zero
    if ($amount === 0) return true;
    
    // Ensure the user_id is consistent with the BIGINT UNSIGNED type in the database
    $userIdBigInt = $user_id;

    try {
        $pdo->beginTransaction();

        // 1. Update user_tokens balance (INSERT OR UPDATE)
        // Note: The SQL assumes the corrected BIGINT UNSIGNED for user_id is used.
        $stmt_balance = $pdo->prepare(
            'INSERT INTO user_tokens (user_id, balance)
             VALUES (:uid, :amount)
             ON DUPLICATE KEY UPDATE balance = balance + :amount_update'
        );
        $stmt_balance->execute([
            ':uid' => $userIdBigInt,
            ':amount' => $amount,
            ':amount_update' => $amount
        ]);

        // 2. Log the transaction
        $stmt_log = $pdo->prepare(
            'INSERT INTO token_transactions (user_id, amount, type, action, entity_type, entity_id)
             VALUES (:uid, :amount, :type, :action, :entity_type, :entity_id)'
        );
        $stmt_log->execute([
            ':uid' => $userIdBigInt,
            ':amount' => $amount,
            ':type' => $type,
            ':action' => $action,
            ':entity_type' => $entity_type,
            ':entity_id' => $entity_id
        ]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        // In a real application, you should log the exception ($e) details here.
        error_log("Token transaction failed for user $user_id: " . $e->getMessage());
        return false;
    }
}