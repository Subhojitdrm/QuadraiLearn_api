-- Migration: Wallet System (Phase 1)
-- Description: Creates tables for wallet_ledger, wallet_balance_cache, and idempotency_keys
-- Author: Generated from API specification
-- Date: 2025-11-08

-- ============================================================================
-- Table: wallet_ledger
-- Description: Append-only ledger for all wallet transactions
-- ============================================================================
CREATE TABLE IF NOT EXISTS `wallet_ledger` (
  `id` VARCHAR(26) PRIMARY KEY COMMENT 'ULID primary key',
  `user_id` INT UNSIGNED NOT NULL,
  `token_type` ENUM('regular', 'promo') NOT NULL,
  `direction` ENUM('credit', 'debit') NOT NULL,
  `reason` ENUM(
    'registration_bonus',
    'chapter_generation',
    'refund_generation_failure',
    'token_purchase',
    'referral_bonus',
    'promo_expiry',
    'admin_adjustment',
    'migration_correction'
  ) NOT NULL,
  `amount` INT UNSIGNED NOT NULL CHECK (`amount` > 0),
  `balance_after_regular` INT UNSIGNED NOT NULL DEFAULT 0,
  `balance_after_promo` INT UNSIGNED NOT NULL DEFAULT 0,
  `occurred_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reference_id` VARCHAR(64) NULL COMMENT 'e.g., chapter_id, purchase_id',
  `metadata` JSON NULL,
  `idempotency_key` VARCHAR(128) UNIQUE NULL,

  -- Foreign key to users table
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,

  -- Indexes for performance
  INDEX `idx_user_occurred` (`user_id`, `occurred_at` DESC),
  INDEX `idx_reason` (`reason`),
  INDEX `idx_reference` (`reference_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Append-only ledger for all wallet transactions';

-- Partial unique index: only one registration_bonus per user
CREATE UNIQUE INDEX `idx_unique_registration_bonus`
ON `wallet_ledger` (`user_id`)
WHERE `reason` = 'registration_bonus';

-- ============================================================================
-- Table: wallet_balance_cache
-- Description: Cached balance snapshot for fast reads
-- ============================================================================
CREATE TABLE IF NOT EXISTS `wallet_balance_cache` (
  `user_id` INT UNSIGNED PRIMARY KEY,
  `regular_balance` INT UNSIGNED NOT NULL DEFAULT 0,
  `promo_balance` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Foreign key to users table
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Cached wallet balances for fast reads';

-- ============================================================================
-- Table: idempotency_keys
-- Description: Prevents duplicate operations
-- ============================================================================
CREATE TABLE IF NOT EXISTS `idempotency_keys` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NULL COMMENT 'Null for webhooks',
  `operation` VARCHAR(64) NOT NULL COMMENT 'e.g., WALLET_SEED, TOKENS_AUTHORIZE',
  `resource_key` VARCHAR(128) NOT NULL COMMENT 'e.g., purchase_id, sha256(chapter_params)',
  `idempotency_key` VARCHAR(128) NOT NULL,
  `response_hash` CHAR(64) NOT NULL COMMENT 'SHA256 of response body',
  `status_code` SMALLINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- Foreign key (nullable for webhooks)
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,

  -- Unique constraint: one operation per (user, operation, resource)
  UNIQUE KEY `idx_unique_operation` (`user_id`, `operation`, `resource_key`),
  UNIQUE KEY `idx_unique_idempotency_key` (`idempotency_key`),

  -- Index for lookups
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Idempotency key storage for preventing duplicate operations';

-- ============================================================================
-- Trigger: Update wallet_balance_cache on ledger insert
-- Description: Automatically updates cache when new ledger entry is created
-- ============================================================================
DELIMITER //

CREATE TRIGGER `trg_wallet_ledger_after_insert`
AFTER INSERT ON `wallet_ledger`
FOR EACH ROW
BEGIN
  INSERT INTO `wallet_balance_cache` (
    `user_id`,
    `regular_balance`,
    `promo_balance`,
    `updated_at`
  )
  VALUES (
    NEW.user_id,
    NEW.balance_after_regular,
    NEW.balance_after_promo,
    NEW.occurred_at
  )
  ON DUPLICATE KEY UPDATE
    `regular_balance` = NEW.balance_after_regular,
    `promo_balance` = NEW.balance_after_promo,
    `updated_at` = NEW.occurred_at;
END//

DELIMITER ;

-- ============================================================================
-- Initial Data Seeding (Optional)
-- Description: Seed configuration or initial data if needed
-- ============================================================================
-- None required for Phase 1

-- ============================================================================
-- Rollback Instructions
-- ============================================================================
-- To rollback this migration, execute:
-- DROP TRIGGER IF EXISTS `trg_wallet_ledger_after_insert`;
-- DROP TABLE IF EXISTS `idempotency_keys`;
-- DROP TABLE IF EXISTS `wallet_balance_cache`;
-- DROP TABLE IF EXISTS `wallet_ledger`;
