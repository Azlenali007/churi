-- ============================================================================
-- Non-Destructive Currency & Exchange Rate Migration (Existing Installations)
-- Preserves all existing users, wallet balances, services, and historical orders.
-- Note: For MySQL 8.0 compatibility without IF NOT EXISTS on ADD COLUMN,
-- Currency::ensureSchema() also runs idempotent INFORMATION_SCHEMA checks.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `exchange_rate_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `base_currency` VARCHAR(10) NOT NULL,
  `provider_source` VARCHAR(60) NOT NULL,
  `rates_json` TEXT NULL,
  `status` ENUM('success','failed') NOT NULL DEFAULT 'success',
  `error_message` VARCHAR(255) NULL,
  `triggered_by` ENUM('manual','cron','fallback') NOT NULL DEFAULT 'manual',
  `admin_id` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_erl_created` (`created_at`),
  KEY `idx_erl_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `currencies` (`code`, `name`, `symbol`, `exchange_rate`, `is_base`, `status`) VALUES
('INR', 'Indian Rupee', '₹', 1.000000, 1, 'active'),
('USD', 'US Dollar', '$', 0.011900, 0, 'active'),
('EUR', 'Euro', '€', 0.011000, 0, 'active'),
('GBP', 'British Pound', '£', 0.009400, 0, 'active');

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `setting_group`) VALUES
('exchange_rate_auto_update', '1', 'currency'),
('exchange_rate_interval_hours', '12', 'currency'),
('exchange_rate_last_attempt_at', '', 'currency'),
('exchange_rate_last_success_at', '', 'currency'),
('exchange_rate_last_status', 'never', 'currency'),
('exchange_rate_last_error', '', 'currency'),
('exchange_rate_provider', 'frankfurter', 'currency');
