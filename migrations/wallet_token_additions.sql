
-- ============================================================================
-- WALLET & TOKEN SYSTEM ADDITIONS
-- ============================================================================
-- Description: Additional tables for comprehensive wallet and token system
-- Date: 2025-11-09
-- Note: These tables extend the existing token_balances and token_ledger system
-- ============================================================================

-- --------------------------------------------------------

--
-- Table structure for table `wallet_ledger`
--

CREATE TABLE `wallet_ledger` (
  `id` varchar(26) NOT NULL COMMENT 'ULID primary key',
  `user_id` int(11) UNSIGNED NOT NULL,
  `token_type` enum('regular','promo') NOT NULL,
  `direction` enum('credit','debit') NOT NULL,
  `reason` enum('registration_bonus','chapter_generation','refund_generation_failure','token_purchase','referral_bonus','promo_expiry','admin_adjustment','migration_correction') NOT NULL,
  `amount` int(11) UNSIGNED NOT NULL,
  `balance_after_regular` int(11) UNSIGNED NOT NULL DEFAULT '0',
  `balance_after_promo` int(11) UNSIGNED NOT NULL DEFAULT '0',
  `occurred_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reference_id` varchar(64) DEFAULT NULL COMMENT 'e.g., chapter_id, purchase_id',
  `metadata` json DEFAULT NULL,
  `idempotency_key` varchar(128) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Append-only ledger for all wallet transactions';

-- --------------------------------------------------------

--
-- Table structure for table `wallet_balance_cache`
--

CREATE TABLE `wallet_balance_cache` (
  `user_id` int(11) UNSIGNED NOT NULL,
  `regular_balance` int(11) UNSIGNED NOT NULL DEFAULT '0',
  `promo_balance` int(11) UNSIGNED NOT NULL DEFAULT '0',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cached wallet balances for fast reads';

-- --------------------------------------------------------

--
-- Table structure for table `idempotency_keys`
--

CREATE TABLE `idempotency_keys` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL COMMENT 'Null for webhooks',
  `operation` varchar(64) NOT NULL COMMENT 'e.g., WALLET_SEED, TOKENS_AUTHORIZE',
  `resource_key` varchar(128) NOT NULL COMMENT 'e.g., purchase_id, sha256(chapter_params)',
  `idempotency_key` varchar(128) NOT NULL,
  `response_hash` char(64) NOT NULL COMMENT 'SHA256 of response body',
  `status_code` smallint(6) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Idempotency key storage for preventing duplicate operations';

-- --------------------------------------------------------

--
-- Table structure for table `token_authorizations`
--

CREATE TABLE `token_authorizations` (
  `id` varchar(26) NOT NULL COMMENT 'ULID primary key',
  `user_id` int(11) UNSIGNED NOT NULL,
  `feature` varchar(64) NOT NULL COMMENT 'e.g., chapter_generation',
  `resource_key` varchar(128) NOT NULL COMMENT 'Stable hash of resource params',
  `amount` int(11) UNSIGNED NOT NULL COMMENT 'Total tokens reserved',
  `status` enum('created','held','captured','voided','expired') NOT NULL DEFAULT 'created',
  `hold_expires_at` timestamp NULL DEFAULT NULL COMMENT 'When hold expires (10 minutes from creation)',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `metadata` json DEFAULT NULL,
  `idempotency_key` varchar(128) DEFAULT NULL,
  `captured_transaction_id` varchar(26) DEFAULT NULL COMMENT 'Ledger entry ID when captured',
  `voided_transaction_id` varchar(26) DEFAULT NULL COMMENT 'Ledger entry ID when voided (refund)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Token authorizations for hold-then-capture pattern';

-- --------------------------------------------------------

--
-- Table structure for table `purchases`
--

CREATE TABLE `purchases` (
  `id` varchar(26) NOT NULL COMMENT 'ULID primary key',
  `user_id` int(11) UNSIGNED NOT NULL,
  `status` enum('created','pending','paid','failed','expired','refunded') NOT NULL DEFAULT 'created',
  `tokens` int(11) UNSIGNED NOT NULL COMMENT 'Number of tokens to credit',
  `inr_amount` int(11) UNSIGNED NOT NULL COMMENT 'Amount in paise (₹1 = 100 paise)',
  `provider` varchar(32) NOT NULL COMMENT 'Payment provider (razorpay, stripe, etc.)',
  `provider_order_id` varchar(128) DEFAULT NULL COMMENT 'Provider order ID',
  `provider_payment_id` varchar(128) DEFAULT NULL COMMENT 'Provider payment ID (set on success)',
  `receipt_no` varchar(64) DEFAULT NULL COMMENT 'Receipt number (generated on payment success)',
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `idempotency_key` varchar(128) DEFAULT NULL,
  `ledger_transaction_id` varchar(26) DEFAULT NULL COMMENT 'Wallet ledger entry ID when credited'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Token purchase records';

-- --------------------------------------------------------

--
-- Table structure for table `payment_webhook_events`
--

CREATE TABLE `payment_webhook_events` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider` varchar(32) NOT NULL COMMENT 'Payment provider name',
  `event_id` varchar(128) NOT NULL COMMENT 'Unique event ID from provider',
  `payload` json NOT NULL COMMENT 'Full webhook payload',
  `processed_at` timestamp NULL DEFAULT NULL COMMENT 'When event was processed',
  `status` enum('received','processed','skipped','error') NOT NULL DEFAULT 'received',
  `error_msg` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payment webhook event log';

-- --------------------------------------------------------

--
-- Table structure for table `promotion_campaigns`
--

CREATE TABLE `promotion_campaigns` (
  `id` varchar(26) NOT NULL COMMENT 'ULID primary key',
  `name` varchar(120) NOT NULL,
  `type` enum('referral','seasonal','bulk') NOT NULL,
  `bonus_amount` int(11) UNSIGNED NOT NULL COMMENT 'Token amount to award',
  `token_type` enum('regular','promo') NOT NULL DEFAULT 'promo',
  `start_at` timestamp NULL DEFAULT NULL COMMENT 'Campaign start date (null = immediate)',
  `end_at` timestamp NULL DEFAULT NULL COMMENT 'Campaign end date (null = no end)',
  `per_user_cap` int(11) UNSIGNED DEFAULT NULL COMMENT 'Max times a user can benefit (null = unlimited)',
  `terms` text COMMENT 'Campaign terms and conditions',
  `status` enum('draft','active','paused','archived') NOT NULL DEFAULT 'draft',
  `created_by` int(11) UNSIGNED DEFAULT NULL COMMENT 'Admin user who created',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `metadata` json DEFAULT NULL COMMENT 'Additional campaign settings'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Promotional campaign configurations';

-- --------------------------------------------------------

--
-- Table structure for table `referrals`
--

CREATE TABLE `referrals` (
  `id` varchar(26) NOT NULL COMMENT 'ULID primary key',
  `campaign_id` varchar(26) NOT NULL,
  `referrer_user_id` int(11) UNSIGNED NOT NULL COMMENT 'User who referred',
  `referral_code` varchar(12) NOT NULL COMMENT 'Unique referral code',
  `referee_user_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'User who signed up (null until signup)',
  `status` enum('generated','clicked','joined','credited','rejected') NOT NULL DEFAULT 'generated',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `ledger_transaction_id` varchar(26) DEFAULT NULL COMMENT 'Ledger entry when credited',
  `click_count` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Number of clicks on referral link',
  `last_clicked_at` timestamp NULL DEFAULT NULL COMMENT 'Last click timestamp'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Referral tracking';

-- --------------------------------------------------------

--
-- Table structure for table `promo_expiry_schedules`
--

CREATE TABLE `promo_expiry_schedules` (
  `id` varchar(26) NOT NULL COMMENT 'ULID primary key',
  `user_id` int(11) UNSIGNED NOT NULL,
  `source_ledger_id` varchar(26) NOT NULL COMMENT 'FK to wallet_ledger (promo credit)',
  `expiry_at` timestamp NOT NULL COMMENT 'When promo tokens expire',
  `amount_initial` int(11) UNSIGNED NOT NULL COMMENT 'Initial promo amount',
  `amount_remaining` int(11) UNSIGNED NOT NULL COMMENT 'Remaining amount to expire',
  `status` enum('scheduled','partially_expired','expired') NOT NULL DEFAULT 'scheduled',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Promo token expiry schedules';

-- --------------------------------------------------------

--
-- Table structure for table `analytics_token_daily`
--

CREATE TABLE `analytics_token_daily` (
  `date` date NOT NULL,
  `credited` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Total tokens credited',
  `debited` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Total tokens debited',
  `net` bigint(20) NOT NULL DEFAULT '0' COMMENT 'Net tokens (credited - debited)',
  `revenue_in_inr` decimal(15,2) NOT NULL DEFAULT '0.00' COMMENT 'Revenue from purchases in INR',
  `active_users` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Unique active users',
  `by_feature` json DEFAULT NULL COMMENT 'Breakdown by feature',
  `regular_vs_promo` json DEFAULT NULL COMMENT 'Regular vs promo split',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Daily aggregated token analytics';

-- --------------------------------------------------------

--
-- Table structure for table `analytics_exports`
--

CREATE TABLE `analytics_exports` (
  `id` varchar(26) NOT NULL COMMENT 'ULID primary key',
  `user_id` int(11) UNSIGNED NOT NULL COMMENT 'Admin who requested export',
  `type` enum('csv','json') NOT NULL DEFAULT 'csv',
  `dataset` varchar(64) NOT NULL COMMENT 'Dataset to export (transactions, purchases, etc.)',
  `filters` json DEFAULT NULL COMMENT 'Export filters',
  `status` enum('preparing','ready','failed','expired') NOT NULL DEFAULT 'preparing',
  `estimated_rows` int(11) UNSIGNED DEFAULT NULL COMMENT 'Estimated number of rows',
  `actual_rows` int(11) UNSIGNED DEFAULT NULL COMMENT 'Actual rows exported',
  `file_path` varchar(255) DEFAULT NULL COMMENT 'Path to export file',
  `download_url` varchar(512) DEFAULT NULL COMMENT 'Signed download URL',
  `expires_at` timestamp NULL DEFAULT NULL COMMENT 'When download link expires',
  `error_message` text COMMENT 'Error message if failed',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL COMMENT 'When export completed'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Data export request tracking';

--
-- Indexes for dumped tables (wallet & token system additions)
--

--
-- Indexes for table `wallet_ledger`
--
ALTER TABLE `wallet_ledger`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idempotency_key` (`idempotency_key`),
  ADD KEY `idx_user_occurred` (`user_id`,`occurred_at`),
  ADD KEY `idx_reason` (`reason`),
  ADD KEY `idx_reference` (`reference_id`);

--
-- Indexes for table `wallet_balance_cache`
--
ALTER TABLE `wallet_balance_cache`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `idempotency_keys`
--
ALTER TABLE `idempotency_keys`
  ADD UNIQUE KEY `idx_unique_operation` (`user_id`,`operation`,`resource_key`),
  ADD UNIQUE KEY `idx_unique_idempotency_key` (`idempotency_key`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `token_authorizations`
--
ALTER TABLE `token_authorizations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idempotency_key` (`idempotency_key`),
  ADD KEY `idx_user_feature` (`user_id`,`feature`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_expires` (`hold_expires_at`),
  ADD KEY `idx_user_feature_resource` (`user_id`,`feature`,`resource_key`,`status`);

--
-- Indexes for table `purchases`
--
ALTER TABLE `purchases`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `provider_order_id` (`provider_order_id`),
  ADD UNIQUE KEY `receipt_no` (`receipt_no`),
  ADD UNIQUE KEY `idempotency_key` (`idempotency_key`),
  ADD KEY `idx_user_status` (`user_id`,`status`),
  ADD KEY `idx_provider_order` (`provider`,`provider_order_id`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `payment_webhook_events`
--
ALTER TABLE `payment_webhook_events`
  ADD UNIQUE KEY `event_id` (`event_id`),
  ADD KEY `idx_provider_event` (`provider`,`event_id`),
  ADD KEY `idx_status` (`status`,`created_at`),
  ADD KEY `idx_processed` (`processed_at`);

--
-- Indexes for table `promotion_campaigns`
--
ALTER TABLE `promotion_campaigns`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_type_status` (`type`,`status`),
  ADD KEY `idx_dates` (`start_at`,`end_at`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `referrals`
--
ALTER TABLE `referrals`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_unique_referrer_code` (`referrer_user_id`,`referral_code`),
  ADD UNIQUE KEY `idx_unique_campaign_referee` (`campaign_id`,`referee_user_id`),
  ADD KEY `idx_code` (`referral_code`),
  ADD KEY `idx_referrer` (`referrer_user_id`,`status`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `fk_referrals_campaign` (`campaign_id`),
  ADD KEY `fk_referrals_referee` (`referee_user_id`);

--
-- Indexes for table `promo_expiry_schedules`
--
ALTER TABLE `promo_expiry_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_expiry` (`user_id`,`expiry_at`),
  ADD KEY `idx_expiry_status` (`expiry_at`,`status`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `fk_promo_expiry_ledger` (`source_ledger_id`);

--
-- Indexes for table `analytics_token_daily`
--
ALTER TABLE `analytics_token_daily`
  ADD PRIMARY KEY (`date`),
  ADD KEY `idx_date_range` (`date`);

--
-- Indexes for table `analytics_exports`
--
ALTER TABLE `analytics_exports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_status` (`user_id`,`status`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- AUTO_INCREMENT for dumped tables (wallet & token system additions)
--

--
-- AUTO_INCREMENT for table `idempotency_keys`
--
ALTER TABLE `idempotency_keys`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_webhook_events`
--
ALTER TABLE `payment_webhook_events`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables (wallet & token system additions)
--

--
-- Constraints for table `wallet_ledger`
--
ALTER TABLE `wallet_ledger`
  ADD CONSTRAINT `fk_wallet_ledger_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `wallet_balance_cache`
--
ALTER TABLE `wallet_balance_cache`
  ADD CONSTRAINT `fk_wallet_balance_cache_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `idempotency_keys`
--
ALTER TABLE `idempotency_keys`
  ADD CONSTRAINT `fk_idempotency_keys_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `token_authorizations`
--
ALTER TABLE `token_authorizations`
  ADD CONSTRAINT `fk_token_authorizations_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchases`
--
ALTER TABLE `purchases`
  ADD CONSTRAINT `fk_purchases_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `referrals`
--
ALTER TABLE `referrals`
  ADD CONSTRAINT `fk_referrals_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `promotion_campaigns` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_referrals_referee` FOREIGN KEY (`referee_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_referrals_referrer` FOREIGN KEY (`referrer_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `promo_expiry_schedules`
--
ALTER TABLE `promo_expiry_schedules`
  ADD CONSTRAINT `fk_promo_expiry_ledger` FOREIGN KEY (`source_ledger_id`) REFERENCES `wallet_ledger` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_promo_expiry_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `analytics_exports`
--
ALTER TABLE `analytics_exports`
  ADD CONSTRAINT `fk_analytics_exports_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- ============================================================================
-- TRIGGER: Wallet Balance Cache Auto-Update
-- ============================================================================

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
-- END OF WALLET & TOKEN SYSTEM ADDITIONS
-- ============================================================================
