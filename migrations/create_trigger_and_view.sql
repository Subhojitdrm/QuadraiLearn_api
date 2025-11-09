-- ============================================================================
-- TRIGGER AND VIEW - Run this AFTER creating all tables
-- ============================================================================

-- Create the trigger for auto-updating wallet balance cache
DELIMITER $$

CREATE TRIGGER IF NOT EXISTS `trg_wallet_ledger_after_insert`
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

-- Create the view for real-time analytics
CREATE OR REPLACE VIEW `v_token_analytics_realtime` AS
SELECT
    DATE(occurred_at) as date,
    SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END) as credited,
    SUM(CASE WHEN direction = 'debit' THEN amount ELSE 0 END) as debited,
    SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END) as net,
    COUNT(DISTINCT user_id) as active_users
FROM wallet_ledger
GROUP BY DATE(occurred_at);
