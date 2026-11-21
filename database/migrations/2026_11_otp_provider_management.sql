-- ============================================================================
-- OTP PROVIDER MANAGEMENT UPGRADE (5SIM API1 + AUTO IMPORT + SYNC + LOGS)
-- Repeat-Safe Migration for MySQL 5.7+ / 8.0+ / MariaDB 10.3+
-- Preserves all existing data; safely adds missing columns & indexes.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP PROCEDURE IF EXISTS `sp_otp_provider_mgmt_upgrade`;
DELIMITER $$
CREATE PROCEDURE `sp_otp_provider_mgmt_upgrade`()
BEGIN
  -- 1. otp_providers: connection status, last sync & availability sync timestamps
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_providers' AND COLUMN_NAME = 'connection_status') THEN
    ALTER TABLE `otp_providers` ADD COLUMN `connection_status` ENUM('unknown','connected','failed') NOT NULL DEFAULT 'unknown' AFTER `balance`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_providers' AND COLUMN_NAME = 'last_sync_at') THEN
    ALTER TABLE `otp_providers` ADD COLUMN `last_sync_at` DATETIME NULL AFTER `last_balance_check`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_providers' AND COLUMN_NAME = 'last_availability_sync_at') THEN
    ALTER TABLE `otp_providers` ADD COLUMN `last_availability_sync_at` DATETIME NULL AFTER `last_sync_at`;
  END IF;
  ALTER TABLE `otp_providers` MODIFY COLUMN `base_url` VARCHAR(255) NOT NULL DEFAULT 'http://api1.5sim.net/stubs/handler_api.php';

  -- 2. otp_services: fixed profit amount, auto price update toggle, latest provider price
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_services' AND COLUMN_NAME = 'fixed_profit') THEN
    ALTER TABLE `otp_services` ADD COLUMN `fixed_profit` DECIMAL(16,4) NOT NULL DEFAULT 0.0000 AFTER `profit_margin`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_services' AND COLUMN_NAME = 'auto_price_update') THEN
    ALTER TABLE `otp_services` ADD COLUMN `auto_price_update` TINYINT(1) NOT NULL DEFAULT 1 AFTER `max_price`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_services' AND COLUMN_NAME = 'provider_price') THEN
    ALTER TABLE `otp_services` ADD COLUMN `provider_price` DECIMAL(16,4) NULL AFTER `provider_service_code`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_services' AND COLUMN_NAME = 'provider_currency') THEN
    ALTER TABLE `otp_services` ADD COLUMN `provider_currency` VARCHAR(10) NULL AFTER `provider_price`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_services' AND COLUMN_NAME = 'last_price_sync_at') THEN
    ALTER TABLE `otp_services` ADD COLUMN `last_price_sync_at` DATETIME NULL AFTER `auto_price_update`;
  END IF;

  -- 3. otp_service_provider_mappings: provider price, pricing rules & verified availability tracking
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_service_provider_mappings' AND COLUMN_NAME = 'provider_price') THEN
    ALTER TABLE `otp_service_provider_mappings` ADD COLUMN `provider_price` DECIMAL(16,4) NULL AFTER `provider_operator`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_service_provider_mappings' AND COLUMN_NAME = 'provider_cost_base') THEN
    ALTER TABLE `otp_service_provider_mappings` ADD COLUMN `provider_cost_base` DECIMAL(16,4) NULL AFTER `provider_price`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_service_provider_mappings' AND COLUMN_NAME = 'pricing_mode') THEN
    ALTER TABLE `otp_service_provider_mappings` ADD COLUMN `pricing_mode` ENUM('auto','fixed') NOT NULL DEFAULT 'auto' AFTER `custom_selling_price`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_service_provider_mappings' AND COLUMN_NAME = 'profit_margin') THEN
    ALTER TABLE `otp_service_provider_mappings` ADD COLUMN `profit_margin` DECIMAL(8,2) NULL AFTER `pricing_mode`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_service_provider_mappings' AND COLUMN_NAME = 'fixed_profit') THEN
    ALTER TABLE `otp_service_provider_mappings` ADD COLUMN `fixed_profit` DECIMAL(16,4) NULL AFTER `profit_margin`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_service_provider_mappings' AND COLUMN_NAME = 'min_price') THEN
    ALTER TABLE `otp_service_provider_mappings` ADD COLUMN `min_price` DECIMAL(16,4) NULL AFTER `fixed_profit`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_service_provider_mappings' AND COLUMN_NAME = 'max_price') THEN
    ALTER TABLE `otp_service_provider_mappings` ADD COLUMN `max_price` DECIMAL(16,4) NULL AFTER `min_price`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_service_provider_mappings' AND COLUMN_NAME = 'auto_price_update') THEN
    ALTER TABLE `otp_service_provider_mappings` ADD COLUMN `auto_price_update` TINYINT(1) NOT NULL DEFAULT 1 AFTER `max_price`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_service_provider_mappings' AND COLUMN_NAME = 'available_count') THEN
    ALTER TABLE `otp_service_provider_mappings` ADD COLUMN `available_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `is_available`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_service_provider_mappings' AND COLUMN_NAME = 'availability_verified') THEN
    ALTER TABLE `otp_service_provider_mappings` ADD COLUMN `availability_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `available_count`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_service_provider_mappings' AND COLUMN_NAME = 'last_price_sync_at') THEN
    ALTER TABLE `otp_service_provider_mappings` ADD COLUMN `last_price_sync_at` DATETIME NULL AFTER `availability_verified`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_service_provider_mappings' AND COLUMN_NAME = 'last_availability_sync_at') THEN
    ALTER TABLE `otp_service_provider_mappings` ADD COLUMN `last_availability_sync_at` DATETIME NULL AFTER `last_price_sync_at`;
  END IF;

  -- 4. otp_activations: immutable raw cost & FX rate snapshot at purchase time
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_activations' AND COLUMN_NAME = 'provider_cost_raw') THEN
    ALTER TABLE `otp_activations` ADD COLUMN `provider_cost_raw` DECIMAL(16,4) NOT NULL DEFAULT 0.0000 AFTER `provider_operator`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_activations' AND COLUMN_NAME = 'exchange_rate') THEN
    ALTER TABLE `otp_activations` ADD COLUMN `exchange_rate` DECIMAL(16,6) NOT NULL DEFAULT 1.000000 AFTER `provider_currency`;
  END IF;

  -- 5. otp_provider_logs: exact request duration in milliseconds
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_provider_logs' AND COLUMN_NAME = 'response_time_ms') THEN
    ALTER TABLE `otp_provider_logs` ADD COLUMN `response_time_ms` INT UNSIGNED NULL AFTER `http_code`;
  END IF;
END$$
DELIMITER ;

CALL `sp_otp_provider_mgmt_upgrade`();
DROP PROCEDURE IF EXISTS `sp_otp_provider_mgmt_upgrade`;

-- Seed RUB currency if missing (5SIM API1 reports prices in RUB by default)
INSERT IGNORE INTO `currencies` (`code`, `name`, `symbol`, `exchange_rate`, `is_base`, `is_manual_override`, `rate_source`, `update_status`, `status`) VALUES
('RUB', 'Russian Ruble', '₽', 1.150000, 0, 0, 'initial_seed', 'manual', 'inactive');

SET FOREIGN_KEY_CHECKS = 1;
