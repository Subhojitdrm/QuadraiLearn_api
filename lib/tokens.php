<?php
declare(strict_types=1);

/**
 * Token helpers. IMPORTANT:
 * - Use the caller's $pdo (same transaction).
 * - Do NOT begin/commit/rollback here.
 * - Return true on success, false on a safe, expected failure.
 */

/**
 * Adds (or subtracts) tokens to a user's balance and records a ledger row.
 *
 * @param PDO    $pdo         Existing PDO (transaction may already be open).
 * @param int    $userId      Target user id (must exist in users.id).
 * @param int    $delta       Positive to credit, negative to debit.
 * @param string $reason      Short machine-readable reason (e.g., 'initial_signup_bonus').
 * @param string $actorType   Who did it? e.g. 'user','system','admin'
 * @param int    $actorId     ID of the actor (can equal $userId if self).
 * @param string $kind        'bonus','purchase','spend','adjustment', etc.
 *
 * @return bool               true if success, false if failed (e.g., no such user or rowcount 0)
 */
function add_tokens(
    PDO $pdo,
    int $userId,
    int $delta,
    string $reason,
    string $actorType,
    int $actorId,
    string $kind
): bool {
    // Ensure the user exists (defensive, helps catch FK issues early)
    $chk = $pdo->prepare('SELECT 1 FROM users WHERE id = :u LIMIT 1');
    $chk->execute([':u' => $userId]);
    if (!$chk->fetchColumn()) {
        return false; // unknown user -> caller may 500/rollback
    }

    // Ensure a balance row exists (idempotent upsert)
    $ins = $pdo->prepare(
        'INSERT INTO token_balances (user_id, balance)
         VALUES (:u, 0)
         ON DUPLICATE KEY UPDATE balance = balance'
    );
    $ins->execute([':u' => $userId]);

    // Apply delta; guard against going negative if you want (optional)
    // If you want "no negative balances", uncomment the check below.
    // $pre = $pdo->prepare('SELECT balance FROM token_balances WHERE user_id = :u FOR UPDATE');
    // $pre->execute([':u' => $userId]);
    // $cur = (int)$pre->fetchColumn();
    // if ($cur + $delta < 0) return false;

    $upd = $pdo->prepare(
        'UPDATE token_balances
         SET balance = balance + :d
         WHERE user_id = :u'
    );
    $upd->execute([':d' => $delta, ':u' => $userId]);

    if ($upd->rowCount() !== 1) {
        // Should always affect exactly one row
        return false;
    }

    // Ledger (audit trail)
    $led = $pdo->prepare(
        'INSERT INTO token_ledger
           (user_id, delta, reason, actor_type, actor_id, kind, created_at)
         VALUES
           (:u, :d, :r, :at, :aid, :k, NOW())'
    );
    $led->execute([
        ':u'   => $userId,
        ':d'   => $delta,
        ':r'   => $reason,
        ':at'  => $actorType,
        ':aid' => $actorId,
        ':k'   => $kind,
    ]);

    return true;
}

/** Helper (optional): current balance */
function get_token_balance(PDO $pdo, int $userId): ?int {
    $stmt = $pdo->prepare('SELECT balance FROM token_balances WHERE user_id = :u');
    $stmt->execute([':u' => $userId]);
    $val = $stmt->fetchColumn();
    return $val === false ? null : (int)$val;
}
