-- ============================================================================
-- Wallet & Token System Tables
-- Database: calmconq_quadrailearn
-- ============================================================================

USE `calmconq_quadrailearn`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- TABLE: wallet_balance_cache
-- ============================================================================
DROP TABLE IF EXISTS `wallet_balance_cache`;

CREATE TABLE `wallet_balance_cache` (
  `user_id` int(11) NOT NULL,
  `regular_balance` int(11) NOT NULL DEFAULT '0',
  `promo_balance` int(11) NOT NULL DEFAULT '0',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_wallet_balance_cache_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- TABLE: wallet_ledger
-- ============================================================================
DROP TABLE IF EXISTS `wallet_ledger`;

CREATE TABLE `wallet_ledger` (
  `id` varchar(26) NOT NULL COMMENT 'ULID primary key',
  `user_id` int(11) NOT NULL,
  `token_type` enum('regular','promo') NOT NULL,
  `direction` enum('credit','debit') NOT NULL,
  `reason` enum('registration_bonus','chapter_generation','refund_generation_failure','token_purchase','referral_bonus','promo_expiry','admin_adjustment','migration_correction') NOT NULL,
  `amount` int(11) UNSIGNED NOT NULL,
  `balance_after_regular` int(11) NOT NULL DEFAULT '0',
  `balance_after_promo` int(11) NOT NULL DEFAULT '0',
  `occurred_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reference_id` varchar(64) DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `idempotency_key` varchar(128) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `idx_user_occurred` (`user_id`,`occurred_at`),
  KEY `idx_reason` (`reason`),
  KEY `idx_reference` (`reference_id`),
  CONSTRAINT `fk_wallet_ledger_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- TABLE: idempotency_keys
-- ============================================================================
DROP TABLE IF EXISTS `idempotency_keys`;

CREATE TABLE `idempotency_keys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `operation` varchar(64) NOT NULL,
  `resource_key` varchar(128) NOT NULL,
  `idempotency_key` varchar(128) NOT NULL,
  `response_hash` char(64) NOT NULL,
  `status_code` smallint(6) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_unique_operation` (`user_id`,`operation`,`resource_key`),
  UNIQUE KEY `idx_unique_idempotency_key` (`idempotency_key`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_idempotency_keys_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- TABLE: token_authorizations
-- ============================================================================
DROP TABLE IF EXISTS `token_authorizations`;

CREATE TABLE `token_authorizations` (
  `id` varchar(26) NOT NULL,
  `user_id` int(11) NOT NULL,
  `feature` varchar(64) NOT NULL,
  `resource_key` varchar(128) NOT NULL,
  `amount` int(11) UNSIGNED NOT NULL,
  `status` enum('created','held','captured','voided','expired') NOT NULL DEFAULT 'created',
  `hold_expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `metadata` json DEFAULT NULL,
  `idempotency_key` varchar(128) DEFAULT NULL,
  `captured_transaction_id` varchar(26) DEFAULT NULL,
  `voided_transaction_id` varchar(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `idx_user_feature` (`user_id`,`feature`),
  KEY `idx_status` (`status`),
  KEY `idx_expires` (`hold_expires_at`),
  CONSTRAINT `fk_token_authorizations_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- TABLE: purchases
-- ============================================================================
DROP TABLE IF EXISTS `purchases`;

CREATE TABLE `purchases` (
  `id` varchar(26) NOT NULL,
  `user_id` int(11) NOT NULL,
  `status` enum('created','pending','paid','failed','expired','refunded') NOT NULL DEFAULT 'created',
  `tokens` int(11) UNSIGNED NOT NULL,
  `inr_amount` int(11) UNSIGNED NOT NULL,
  `provider` varchar(32) NOT NULL,
  `provider_order_id` varchar(128) DEFAULT NULL,
  `provider_payment_id` varchar(128) DEFAULT NULL,
  `receipt_no` varchar(64) DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `idempotency_key` varchar(128) DEFAULT NULL,
  `ledger_transaction_id` varchar(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `provider_order_id` (`provider_order_id`),
  UNIQUE KEY `receipt_no` (`receipt_no`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `idx_user_status` (`user_id`,`status`),
  CONSTRAINT `fk_purchases_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- TABLE: payment_webhook_events
-- ============================================================================
DROP TABLE IF EXISTS `payment_webhook_events`;

CREATE TABLE `payment_webhook_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `provider` varchar(32) NOT NULL,
  `event_id` varchar(128) NOT NULL,
  `payload` json NOT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `status` enum('received','processed','skipped','error') NOT NULL DEFAULT 'received',
  `error_msg` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_id` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- TABLE: promotion_campaigns
-- ============================================================================
DROP TABLE IF EXISTS `promotion_campaigns`;

CREATE TABLE `promotion_campaigns` (
  `id` varchar(26) NOT NULL,
  `name` varchar(120) NOT NULL,
  `type` enum('referral','seasonal','bulk') NOT NULL,
  `bonus_amount` int(11) UNSIGNED NOT NULL,
  `token_type` enum('regular','promo') NOT NULL DEFAULT 'promo',
  `start_at` timestamp NULL DEFAULT NULL,
  `end_at` timestamp NULL DEFAULT NULL,
  `per_user_cap` int(11) UNSIGNED DEFAULT NULL,
  `terms` text,
  `status` enum('draft','active','paused','archived') NOT NULL DEFAULT 'draft',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `metadata` json DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- TABLE: referrals
-- ============================================================================
DROP TABLE IF EXISTS `referrals`;

CREATE TABLE `referrals` (
  `id` varchar(26) NOT NULL,
  `campaign_id` varchar(26) NOT NULL,
  `referrer_user_id` int(11) NOT NULL,
  `referral_code` varchar(12) NOT NULL,
  `referee_user_id` int(11) DEFAULT NULL,
  `status` enum('generated','clicked','joined','credited','rejected') NOT NULL DEFAULT 'generated',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `ledger_transaction_id` varchar(26) DEFAULT NULL,
  `click_count` int(11) NOT NULL DEFAULT '0',
  `last_clicked_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_unique_referrer_code` (`referrer_user_id`,`referral_code`),
  KEY `fk_referrals_campaign` (`campaign_id`),
  KEY `fk_referrals_referee` (`referee_user_id`),
  CONSTRAINT `fk_referrals_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `promotion_campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_referrals_referee` FOREIGN KEY (`referee_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_referrals_referrer` FOREIGN KEY (`referrer_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- TABLE: promo_expiry_schedules
-- ============================================================================
DROP TABLE IF EXISTS `promo_expiry_schedules`;

CREATE TABLE `promo_expiry_schedules` (
  `id` varchar(26) NOT NULL,
  `user_id` int(11) NOT NULL,
  `source_ledger_id` varchar(26) NOT NULL,
  `expiry_at` timestamp NOT NULL,
  `amount_initial` int(11) UNSIGNED NOT NULL,
  `amount_remaining` int(11) UNSIGNED NOT NULL,
  `status` enum('scheduled','partially_expired','expired') NOT NULL DEFAULT 'scheduled',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_expiry` (`user_id`,`expiry_at`),
  KEY `fk_promo_expiry_ledger` (`source_ledger_id`),
  CONSTRAINT `fk_promo_expiry_ledger` FOREIGN KEY (`source_ledger_id`) REFERENCES `wallet_ledger` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_promo_expiry_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- TABLE: analytics_token_daily
-- ============================================================================
DROP TABLE IF EXISTS `analytics_token_daily`;

CREATE TABLE `analytics_token_daily` (
  `date` date NOT NULL,
  `credited` bigint(20) NOT NULL DEFAULT '0',
  `debited` bigint(20) NOT NULL DEFAULT '0',
  `net` bigint(20) NOT NULL DEFAULT '0',
  `revenue_in_inr` decimal(15,2) NOT NULL DEFAULT '0.00',
  `active_users` int(11) NOT NULL DEFAULT '0',
  `by_feature` json DEFAULT NULL,
  `regular_vs_promo` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- TABLE: analytics_exports
-- ============================================================================
DROP TABLE IF EXISTS `analytics_exports`;

CREATE TABLE `analytics_exports` (
  `id` varchar(26) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('csv','json') NOT NULL DEFAULT 'csv',
  `dataset` varchar(64) NOT NULL,
  `filters` json DEFAULT NULL,
  `status` enum('preparing','ready','failed','expired') NOT NULL DEFAULT 'preparing',
  `estimated_rows` int(11) DEFAULT NULL,
  `actual_rows` int(11) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `download_url` varchar(512) DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `error_message` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`,`status`),
  CONSTRAINT `fk_analytics_exports_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- TRIGGER: Wallet Balance Cache Auto-Update
-- ============================================================================
DROP TRIGGER IF EXISTS `trg_wallet_ledger_after_insert`;

DELIMITER $$

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
END$$

DELIMITER ;

-- ============================================================================
-- VIEW: Real-time Token Analytics
-- ============================================================================
DROP VIEW IF EXISTS `v_token_analytics_realtime`;

CREATE VIEW `v_token_analytics_realtime` AS
SELECT
    DATE(occurred_at) as date,
    SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END) as credited,
    SUM(CASE WHEN direction = 'debit' THEN amount ELSE 0 END) as debited,
    SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END) as net,
    COUNT(DISTINCT user_id) as active_users
FROM wallet_ledger
GROUP BY DATE(occurred_at);

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- VERIFICATION QUERY
-- ============================================================================
SELECT
  TABLE_NAME,
  TABLE_ROWS,
  CREATE_TIME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'calmconq_quadrailearn'
  AND TABLE_NAME IN (
    'wallet_ledger',
    'wallet_balance_cache',
    'idempotency_keys',
    'token_authorizations',
    'purchases',
    'payment_webhook_events',
    'promotion_campaigns',
    'referrals',
    'promo_expiry_schedules',
    'analytics_token_daily',
    'analytics_exports'
  )
ORDER BY TABLE_NAME;
