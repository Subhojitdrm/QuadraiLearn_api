-- Migration: Promotions, Referrals & Expiry (Phase 4)
-- Description: Creates tables for promotional campaigns, referrals, and promo token expiry
-- Author: Generated from API specification
-- Date: 2025-11-08

-- ============================================================================
-- Table: promotion_campaigns
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

  -- Indexes
  INDEX `idx_type_status` (`type`, `status`),
  INDEX `idx_dates` (`start_at`, `end_at`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Promotional campaign configurations';

-- ============================================================================
-- Table: referrals
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

  -- Foreign keys
  FOREIGN KEY (`campaign_id`) REFERENCES `promotion_campaigns`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`referrer_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`referee_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,

  -- Unique constraint: one referral code per referrer
  UNIQUE KEY `idx_unique_referrer_code` (`referrer_user_id`, `referral_code`),

  -- Unique constraint: prevent double credit (one referee per campaign)
  UNIQUE KEY `idx_unique_campaign_referee` (`campaign_id`, `referee_user_id`),

  -- Indexes
  INDEX `idx_code` (`referral_code`),
  INDEX `idx_referrer` (`referrer_user_id`, `status`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Referral tracking';

-- ============================================================================
-- Table: promo_expiry_schedules
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

  -- Foreign keys
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`source_ledger_id`) REFERENCES `wallet_ledger`(`id`) ON DELETE CASCADE,

  -- Indexes
  INDEX `idx_user_expiry` (`user_id`, `expiry_at`),
  INDEX `idx_expiry_status` (`expiry_at`, `status`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Promo token expiry schedules';

-- ============================================================================
-- Add metadata to promotion_campaigns
-- ============================================================================
ALTER TABLE `promotion_campaigns`
  ADD COLUMN `metadata` JSON NULL COMMENT 'Additional campaign settings';

-- ============================================================================
-- Add referral tracking columns
-- ============================================================================
ALTER TABLE `referrals`
  ADD COLUMN `ledger_transaction_id` VARCHAR(26) NULL COMMENT 'Ledger entry when credited',
  ADD COLUMN `click_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of clicks on referral link',
  ADD COLUMN `last_clicked_at` TIMESTAMP NULL COMMENT 'Last click timestamp';

-- ============================================================================
-- Rollback Instructions
-- ============================================================================
-- To rollback this migration, execute:
-- DROP TABLE IF EXISTS `promo_expiry_schedules`;
-- DROP TABLE IF EXISTS `referrals`;
-- DROP TABLE IF EXISTS `promotion_campaigns`;
