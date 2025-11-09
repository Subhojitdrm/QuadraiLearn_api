-- Migration: Token Purchases (Phase 3)
-- Description: Creates tables for token purchases and payment webhook events
-- Author: Generated from API specification
-- Date: 2025-11-08

-- ============================================================================
-- Table: purchases
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

  -- Foreign key to users table
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,

  -- Indexes for performance
  INDEX `idx_user_status` (`user_id`, `status`),
  INDEX `idx_provider_order` (`provider`, `provider_order_id`),
  INDEX `idx_receipt` (`receipt_no`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Token purchase records';

-- ============================================================================
-- Table: payment_webhook_events
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

  -- Indexes for performance
  INDEX `idx_provider_event` (`provider`, `event_id`),
  INDEX `idx_status` (`status`, `created_at`),
  INDEX `idx_processed` (`processed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Payment webhook event log';

-- ============================================================================
-- Add ledger transaction ID to purchases for tracking
-- ============================================================================
ALTER TABLE `purchases`
  ADD COLUMN `ledger_transaction_id` VARCHAR(26) NULL COMMENT 'Wallet ledger entry ID when credited';

-- ============================================================================
-- Rollback Instructions
-- ============================================================================
-- To rollback this migration, execute:
-- DROP TABLE IF EXISTS `payment_webhook_events`;
-- DROP TABLE IF EXISTS `purchases`;
