-- Migration: Token Authorizations (Phase 2)
-- Description: Creates table for token holds/authorizations for chapter generation
-- Author: Generated from API specification
-- Date: 2025-11-08

-- ============================================================================
-- Table: token_authorizations
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

  -- Foreign key to users table
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,

  -- Indexes for performance
  INDEX `idx_user_feature` (`user_id`, `feature`),
  INDEX `idx_status` (`status`),
  INDEX `idx_expires` (`hold_expires_at`),

  -- Unique constraint: one active authorization per (user_id, feature, resource_key)
  -- Note: MySQL doesn't support filtered indexes, so we'll enforce this in application code
  INDEX `idx_user_feature_resource` (`user_id`, `feature`, `resource_key`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Token authorizations for hold-then-capture pattern';

-- ============================================================================
-- Add captured_transaction_id and voided_transaction_id for tracking
-- ============================================================================
ALTER TABLE `token_authorizations`
  ADD COLUMN `captured_transaction_id` VARCHAR(26) NULL COMMENT 'Ledger entry ID when captured',
  ADD COLUMN `voided_transaction_id` VARCHAR(26) NULL COMMENT 'Ledger entry ID when voided (refund)';

-- ============================================================================
-- Rollback Instructions
-- ============================================================================
-- To rollback this migration, execute:
-- DROP TABLE IF EXISTS `token_authorizations`;
