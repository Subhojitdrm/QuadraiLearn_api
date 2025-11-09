-- ============================================================================
-- Complete Wallet & Token System Database Schema
-- ============================================================================
-- Description: Complete database schema for wallet, token, and user system
-- Author: Generated from API specification
-- Date: 2025-11-09
-- MySQL Version: 5.7+
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- TABLE: users
-- Description: User accounts and registration
-- ============================================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `username` VARCHAR(50) UNIQUE NOT NULL,
  `email` VARCHAR(255) UNIQUE NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `interests` JSON NULL COMMENT 'Array of interested areas',
  `primary_study_need` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX `idx_username` (`username`),
  INDEX `idx_email` (`email`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='User accounts and registration data';

-- ============================================================================
-- TABLE: wallet_ledger
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

  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,

  INDEX `idx_user_occurred` (`user_id`, `occurred_at` DESC),
  INDEX `idx_reason` (`reason`),
  INDEX `idx_reference` (`reference_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Append-only ledger for all wallet transactions';

-- ============================================================================
-- TABLE: wallet_balance_cache
-- Description: Cached balance snapshot for fast reads
-- ============================================================================
CREATE TABLE IF NOT EXISTS `wallet_balance_cache` (
  `user_id` INT UNSIGNED PRIMARY KEY,
  `regular_balance` INT UNSIGNED NOT NULL DEFAULT 0,
  `promo_balance` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Cached wallet balances for fast reads';

-- ============================================================================
-- TABLE: idempotency_keys
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

  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,

  UNIQUE KEY `idx_unique_operation` (`user_id`, `operation`, `resource_key`),
  UNIQUE KEY `idx_unique_idempotency_key` (`idempotency_key`),

  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Idempotency key storage for preventing duplicate operations';

-- ============================================================================
-- TABLE: token_authorizations
-- Description: Holds and captures for token deductions (authorize-then-capture pattern)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `token_authorizations` (
  `id` VARCHAR(26) PRIMARY KEY COMMENT 'ULID primary key',
  `user_id` INT UNSIGNED NOT NULL,
  `feature` VARCHAR(64) NOT NULL COMMENT 'e.g., chapter_generation',
  `resource_key` VARCHAR(128) NOT NULL COMMENT 'Stable hash of resource params',
  `amount` INT UNSIGNED NOT NULL COMMENT 'Total tokens reserved',
  `status` ENUM('created', 'held', 'captured', 'voided', 'expired') NOT NULL DEFAULT 'created',
  `hold_expires_at` TIMESTAMP NULL COMMENT 'When hold expires (10 minutes from creation)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `metadata` JSON NULL,
  `idempotency_key` VARCHAR(128) UNIQUE NULL,
  `captured_transaction_id` VARCHAR(26) NULL COMMENT 'Ledger entry ID when captured',
  `voided_transaction_id` VARCHAR(26) NULL COMMENT 'Ledger entry ID when voided (refund)',

  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,

  INDEX `idx_user_feature` (`user_id`, `feature`),
  INDEX `idx_status` (`status`),
  INDEX `idx_expires` (`hold_expires_at`),
  INDEX `idx_user_feature_resource` (`user_id`, `feature`, `resource_key`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Token authorizations for hold-then-capture pattern';

-- ============================================================================
-- TABLE: purchases
-- Description: Token purchase records with payment provider integration
-- ============================================================================
CREATE TABLE IF NOT EXISTS `purchases` (
  `id` VARCHAR(26) PRIMARY KEY COMMENT 'ULID primary key',
  `user_id` INT UNSIGNED NOT NULL,
  `status` ENUM('created', 'pending', 'paid', 'failed', 'expired', 'refunded') NOT NULL DEFAULT 'created',
  `tokens` INT UNSIGNED NOT NULL COMMENT 'Number of tokens to credit',
  `inr_amount` INT UNSIGNED NOT NULL COMMENT 'Amount in paise (₹1 = 100 paise)',
  `provider` VARCHAR(32) NOT NULL COMMENT 'Payment provider (razorpay, stripe, etc.)',
  `provider_order_id` VARCHAR(128) UNIQUE NULL COMMENT 'Provider order ID',
  `provider_payment_id` VARCHAR(128) NULL COMMENT 'Provider payment ID (set on success)',
  `receipt_no` VARCHAR(64) UNIQUE NULL COMMENT 'Receipt number (generated on payment success)',
  `metadata` JSON NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `idempotency_key` VARCHAR(128) UNIQUE NULL,
  `ledger_transaction_id` VARCHAR(26) NULL COMMENT 'Wallet ledger entry ID when credited',

  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,

  INDEX `idx_user_status` (`user_id`, `status`),
  INDEX `idx_provider_order` (`provider`, `provider_order_id`),
  INDEX `idx_receipt` (`receipt_no`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Token purchase records';

-- ============================================================================
-- TABLE: payment_webhook_events
-- Description: Logs all webhook events from payment providers
-- ============================================================================
CREATE TABLE IF NOT EXISTS `payment_webhook_events` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `provider` VARCHAR(32) NOT NULL COMMENT 'Payment provider name',
  `event_id` VARCHAR(128) UNIQUE NOT NULL COMMENT 'Unique event ID from provider',
  `payload` JSON NOT NULL COMMENT 'Full webhook payload',
  `processed_at` TIMESTAMP NULL COMMENT 'When event was processed',
  `status` ENUM('received', 'processed', 'skipped', 'error') NOT NULL DEFAULT 'received',
  `error_msg` TEXT NULL COMMENT 'Error message if processing failed',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX `idx_provider_event` (`provider`, `event_id`),
  INDEX `idx_status` (`status`, `created_at`),
  INDEX `idx_processed` (`processed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Payment webhook event log';

-- ============================================================================
-- TABLE: promotion_campaigns
-- Description: Promotional campaign configurations
-- ============================================================================
CREATE TABLE IF NOT EXISTS `promotion_campaigns` (
  `id` VARCHAR(26) PRIMARY KEY COMMENT 'ULID primary key',
  `name` VARCHAR(120) NOT NULL,
  `type` ENUM('referral', 'seasonal', 'bulk') NOT NULL,
  `bonus_amount` INT UNSIGNED NOT NULL COMMENT 'Token amount to award',
  `token_type` ENUM('regular', 'promo') NOT NULL DEFAULT 'promo',
  `start_at` TIMESTAMP NULL COMMENT 'Campaign start date (null = immediate)',
  `end_at` TIMESTAMP NULL COMMENT 'Campaign end date (null = no end)',
  `per_user_cap` INT UNSIGNED NULL COMMENT 'Max times a user can benefit (null = unlimited)',
  `terms` TEXT NULL COMMENT 'Campaign terms and conditions',
  `status` ENUM('draft', 'active', 'paused', 'archived') NOT NULL DEFAULT 'draft',
  `created_by` INT UNSIGNED NULL COMMENT 'Admin user who created',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `metadata` JSON NULL COMMENT 'Additional campaign settings',

  INDEX `idx_type_status` (`type`, `status`),
  INDEX `idx_dates` (`start_at`, `end_at`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Promotional campaign configurations';

-- ============================================================================
-- TABLE: referrals
-- Description: Referral tracking for users
-- ============================================================================
CREATE TABLE IF NOT EXISTS `referrals` (
  `id` VARCHAR(26) PRIMARY KEY COMMENT 'ULID primary key',
  `campaign_id` VARCHAR(26) NOT NULL,
  `referrer_user_id` INT UNSIGNED NOT NULL COMMENT 'User who referred',
  `referral_code` VARCHAR(12) NOT NULL COMMENT 'Unique referral code',
  `referee_user_id` INT UNSIGNED NULL COMMENT 'User who signed up (null until signup)',
  `status` ENUM('generated', 'clicked', 'joined', 'credited', 'rejected') NOT NULL DEFAULT 'generated',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `ledger_transaction_id` VARCHAR(26) NULL COMMENT 'Ledger entry when credited',
  `click_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of clicks on referral link',
  `last_clicked_at` TIMESTAMP NULL COMMENT 'Last click timestamp',

  FOREIGN KEY (`campaign_id`) REFERENCES `promotion_campaigns`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`referrer_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`referee_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,

  UNIQUE KEY `idx_unique_referrer_code` (`referrer_user_id`, `referral_code`),
  UNIQUE KEY `idx_unique_campaign_referee` (`campaign_id`, `referee_user_id`),

  INDEX `idx_code` (`referral_code`),
  INDEX `idx_referrer` (`referrer_user_id`, `status`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Referral tracking';

-- ============================================================================
-- TABLE: promo_expiry_schedules
-- Description: Tracks expiry of promotional tokens
-- ============================================================================
CREATE TABLE IF NOT EXISTS `promo_expiry_schedules` (
  `id` VARCHAR(26) PRIMARY KEY COMMENT 'ULID primary key',
  `user_id` INT UNSIGNED NOT NULL,
  `source_ledger_id` VARCHAR(26) NOT NULL COMMENT 'FK to wallet_ledger (promo credit)',
  `expiry_at` TIMESTAMP NOT NULL COMMENT 'When promo tokens expire',
  `amount_initial` INT UNSIGNED NOT NULL COMMENT 'Initial promo amount',
  `amount_remaining` INT UNSIGNED NOT NULL COMMENT 'Remaining amount to expire',
  `status` ENUM('scheduled', 'partially_expired', 'expired') NOT NULL DEFAULT 'scheduled',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`source_ledger_id`) REFERENCES `wallet_ledger`(`id`) ON DELETE CASCADE,

  INDEX `idx_user_expiry` (`user_id`, `expiry_at`),
  INDEX `idx_expiry_status` (`expiry_at`, `status`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Promo token expiry schedules';

-- ============================================================================
-- TABLE: analytics_token_daily
-- Description: Materialized daily token statistics for fast analytics queries
-- ============================================================================
CREATE TABLE IF NOT EXISTS `analytics_token_daily` (
  `date` DATE PRIMARY KEY,
  `credited` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total tokens credited',
  `debited` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total tokens debited',
  `net` BIGINT NOT NULL DEFAULT 0 COMMENT 'Net tokens (credited - debited)',
  `revenue_in_inr` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Revenue from purchases in INR',
  `active_users` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unique active users',
  `by_feature` JSON NULL COMMENT 'Breakdown by feature',
  `regular_vs_promo` JSON NULL COMMENT 'Regular vs promo split',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX `idx_date_range` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Daily aggregated token analytics';

-- ============================================================================
-- TABLE: analytics_exports
-- Description: Tracks data export requests and their status
-- ============================================================================
CREATE TABLE IF NOT EXISTS `analytics_exports` (
  `id` VARCHAR(26) PRIMARY KEY COMMENT 'ULID primary key',
  `user_id` INT UNSIGNED NOT NULL COMMENT 'Admin who requested export',
  `type` ENUM('csv', 'json') NOT NULL DEFAULT 'csv',
  `dataset` VARCHAR(64) NOT NULL COMMENT 'Dataset to export (transactions, purchases, etc.)',
  `filters` JSON NULL COMMENT 'Export filters',
  `status` ENUM('preparing', 'ready', 'failed', 'expired') NOT NULL DEFAULT 'preparing',
  `estimated_rows` INT UNSIGNED NULL COMMENT 'Estimated number of rows',
  `actual_rows` INT UNSIGNED NULL COMMENT 'Actual rows exported',
  `file_path` VARCHAR(255) NULL COMMENT 'Path to export file',
  `download_url` VARCHAR(512) NULL COMMENT 'Signed download URL',
  `expires_at` TIMESTAMP NULL COMMENT 'When download link expires',
  `error_message` TEXT NULL COMMENT 'Error message if failed',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` TIMESTAMP NULL COMMENT 'When export completed',

  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,

  INDEX `idx_user_status` (`user_id`, `status`),
  INDEX `idx_created` (`created_at`),
  INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Data export request tracking';

-- ============================================================================
-- TRIGGER: trg_wallet_ledger_after_insert
-- Description: Automatically updates balance cache when new ledger entry is created
-- ============================================================================
DROP TRIGGER IF EXISTS `trg_wallet_ledger_after_insert`;

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
-- VIEW: v_token_analytics_realtime
-- Description: Real-time analytics view (use when materialized table is stale)
-- ============================================================================
CREATE OR REPLACE VIEW `v_token_analytics_realtime` AS
SELECT
    DATE(occurred_at) as date,
    SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END) as credited,
    SUM(CASE WHEN direction = 'debit' THEN amount ELSE 0 END) as debited,
    SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END) as net,
    COUNT(DISTINCT user_id) as active_users
FROM wallet_ledger
GROUP BY DATE(occurred_at);

-- ============================================================================
-- Re-enable foreign key checks
-- ============================================================================
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- END OF SCHEMA
-- ============================================================================
-- Summary:
-- - 13 Tables Created
-- - 1 Trigger Created (wallet_ledger auto-update)
-- - 1 View Created (realtime analytics)
--
-- Tables:
--   1. users
--   2. wallet_ledger
--   3. wallet_balance_cache
--   4. idempotency_keys
--   5. token_authorizations
--   6. purchases
--   7. payment_webhook_events
--   8. promotion_campaigns
--   9. referrals
--   10. promo_expiry_schedules
--   11. analytics_token_daily
--   12. analytics_exports
--
-- Compatible with MySQL 5.7+
-- All JSON fields use NULL instead of DEFAULT (JSON_OBJECT()) for compatibility
-- ============================================================================
