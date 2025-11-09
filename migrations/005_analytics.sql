-- Migration: Token Analytics & Exports (Phase 5)
-- Description: Creates tables and views for token analytics and data exports
-- Author: Generated from API specification
-- Date: 2025-11-08

-- ============================================================================
-- Table: analytics_token_daily
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

  -- Indexes
  INDEX `idx_date_range` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Daily aggregated token analytics';

-- ============================================================================
-- Table: analytics_exports
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

  -- Foreign key
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,

  -- Indexes
  INDEX `idx_user_status` (`user_id`, `status`),
  INDEX `idx_created` (`created_at`),
  INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Data export request tracking';

-- ============================================================================
-- View: v_token_analytics_realtime
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
-- Rollback Instructions
-- ============================================================================
-- To rollback this migration, execute:
-- DROP VIEW IF EXISTS `v_token_analytics_realtime`;
-- DROP TABLE IF EXISTS `analytics_exports`;
-- DROP TABLE IF EXISTS `analytics_token_daily`;
