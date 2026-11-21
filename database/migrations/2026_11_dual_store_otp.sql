-- ============================================================================
-- DUAL STORE PLATFORM UPGRADE (SMM STORE + OTP ACTIVATION STORE)
-- Repeat-Safe Migration for MySQL 5.7+ / 8.0+ / MariaDB 10.3+
-- Safe to execute on existing production SMM Panel databases (no drops/data loss)
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. OTP Providers (5SIM Built-In + Configurable Custom Providers)
CREATE TABLE IF NOT EXISTS `otp_providers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `slug` VARCHAR(80) NOT NULL,
  `adapter_type` ENUM('fivesim','custom_json','custom_php') NOT NULL DEFAULT 'fivesim',
  `custom_adapter_class` VARCHAR(120) NULL,
  `base_url` VARCHAR(255) NOT NULL DEFAULT 'https://5sim.net',
  `api_key_enc` TEXT NULL,
  `auth_type` ENUM('bearer','header','query','post_body') NOT NULL DEFAULT 'bearer',
  `auth_header_name` VARCHAR(80) NULL DEFAULT 'Authorization',
  `auth_param_name` VARCHAR(80) NULL DEFAULT 'api_key',
  `request_method` ENUM('GET','POST') NOT NULL DEFAULT 'GET',
  `endpoints_json` TEXT NULL,
  `request_params_json` TEXT NULL,
  `response_map_json` TEXT NULL,
  `status_map_json` TEXT NULL,
  `timeout_seconds` INT UNSIGNED NOT NULL DEFAULT 20,
  `polling_interval_seconds` INT UNSIGNED NOT NULL DEFAULT 5,
  `webhook_secret_enc` TEXT NULL,
  `currency_code` VARCHAR(10) NOT NULL DEFAULT 'RUB',
  `exchange_rate` DECIMAL(16,6) NOT NULL DEFAULT 1.000000,
  `rate_mode` ENUM('auto','manual') NOT NULL DEFAULT 'auto',
  `balance` DECIMAL(16,4) NULL,
  `last_balance_check` DATETIME NULL,
  `last_error` TEXT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_otp_provider_slug` (`slug`),
  KEY `idx_otp_provider_status` (`status`, `adapter_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. OTP Services Catalog
CREATE TABLE IF NOT EXISTS `otp_services` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `slug` VARCHAR(100) NOT NULL,
  `icon_key` VARCHAR(60) NOT NULL DEFAULT 'general',
  `description` TEXT NULL,
  `provider_id` INT UNSIGNED NULL,
  `provider_service_code` VARCHAR(100) NULL,
  `selling_price` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
  `profit_margin` DECIMAL(8,2) NOT NULL DEFAULT 25.00,
  `min_price` DECIMAL(16,4) NULL,
  `max_price` DECIMAL(16,4) NULL,
  `is_popular` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_otp_service_slug` (`slug`),
  KEY `idx_otp_service_status` (`status`, `sort_order`),
  KEY `idx_otp_service_provider` (`provider_id`),
  CONSTRAINT `fk_otp_service_provider` FOREIGN KEY (`provider_id`) REFERENCES `otp_providers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. OTP Countries Catalog
CREATE TABLE IF NOT EXISTS `otp_countries` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `iso_code` VARCHAR(10) NOT NULL,
  `dial_code` VARCHAR(15) NULL,
  `flag_emoji` VARCHAR(16) NULL,
  `default_provider_code` VARCHAR(80) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_otp_country_iso` (`iso_code`),
  KEY `idx_otp_country_status` (`status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. OTP Service-Country-Provider Mappings & Country-Specific Overrides
CREATE TABLE IF NOT EXISTS `otp_service_provider_mappings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `service_id` INT UNSIGNED NOT NULL,
  `country_id` INT UNSIGNED NOT NULL,
  `provider_id` INT UNSIGNED NOT NULL,
  `provider_service_code` VARCHAR(100) NULL,
  `provider_country_code` VARCHAR(100) NULL,
  `provider_operator` VARCHAR(80) NOT NULL DEFAULT 'any',
  `custom_selling_price` DECIMAL(16,4) NULL,
  `is_available` TINYINT(1) NOT NULL DEFAULT 1,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_otp_map_svc_cntry_prov` (`service_id`, `country_id`, `provider_id`),
  KEY `idx_otp_map_country` (`country_id`),
  KEY `idx_otp_map_provider` (`provider_id`),
  CONSTRAINT `fk_otp_map_service` FOREIGN KEY (`service_id`) REFERENCES `otp_services` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_otp_map_country` FOREIGN KEY (`country_id`) REFERENCES `otp_countries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_otp_map_provider` FOREIGN KEY (`provider_id`) REFERENCES `otp_providers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. OTP Activations (Separate from SMM Orders, Linked to Shared Users & Shared Wallet Transactions)
CREATE TABLE IF NOT EXISTS `otp_activations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activation_code` VARCHAR(32) NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `service_id` INT UNSIGNED NOT NULL,
  `country_id` INT UNSIGNED NOT NULL,
  `provider_id` INT UNSIGNED NULL,
  `provider_order_id` VARCHAR(120) NULL,
  `provider_operator` VARCHAR(80) NULL,
  `phone_number` VARCHAR(40) NULL,
  `otp_code` VARCHAR(64) NULL,
  `sms_text` TEXT NULL,
  `sms_received_at` DATETIME NULL,
  `provider_cost` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
  `provider_currency` VARCHAR(10) NOT NULL DEFAULT 'RUB',
  `selling_price` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
  `currency_code` VARCHAR(10) NOT NULL DEFAULT 'INR',
  `profit` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
  `refunded_amount` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
  `status` ENUM('processing','active','waiting_otp','otp_received','completed','expired','canceled','refunded','failed') NOT NULL DEFAULT 'processing',
  `provider_status` VARCHAR(60) NULL,
  `can_cancel` TINYINT(1) NOT NULL DEFAULT 1,
  `can_complete` TINYINT(1) NOT NULL DEFAULT 1,
  `idempotency_key` VARCHAR(100) NULL,
  `wallet_tx_id` BIGINT UNSIGNED NULL,
  `refund_tx_id` BIGINT UNSIGNED NULL,
  `expires_at` DATETIME NULL,
  `completed_at` DATETIME NULL,
  `last_checked_at` DATETIME NULL,
  `provider_response_json` MEDIUMTEXT NULL,
  `failure_reason` VARCHAR(255) NULL,
  `expiring_notified` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_otp_act_code` (`activation_code`),
  UNIQUE KEY `uk_otp_act_idem` (`idempotency_key`),
  KEY `idx_otp_act_user_status` (`user_id`, `status`, `created_at`),
  KEY `idx_otp_act_provider_ref` (`provider_id`, `provider_order_id`),
  KEY `idx_otp_act_phone` (`phone_number`),
  KEY `idx_otp_act_status_exp` (`status`, `expires_at`),
  KEY `idx_otp_act_service` (`service_id`),
  KEY `idx_otp_act_country` (`country_id`),
  CONSTRAINT `fk_otp_act_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_otp_act_service` FOREIGN KEY (`service_id`) REFERENCES `otp_services` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_otp_act_country` FOREIGN KEY (`country_id`) REFERENCES `otp_countries` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_otp_act_provider` FOREIGN KEY (`provider_id`) REFERENCES `otp_providers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. OTP Provider Request/Response Logs (Secrets Redacted Before Write)
CREATE TABLE IF NOT EXISTS `otp_provider_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider_id` INT UNSIGNED NULL,
  `activation_id` BIGINT UNSIGNED NULL,
  `action` VARCHAR(60) NOT NULL,
  `endpoint` VARCHAR(255) NULL,
  `http_code` INT NULL,
  `request_payload` MEDIUMTEXT NULL,
  `response_payload` MEDIUMTEXT NULL,
  `status` ENUM('ok','error') NOT NULL DEFAULT 'ok',
  `error_message` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_opl_provider` (`provider_id`, `created_at`),
  KEY `idx_opl_activation` (`activation_id`, `created_at`),
  KEY `idx_opl_status` (`status`, `created_at`),
  CONSTRAINT `fk_opl_provider` FOREIGN KEY (`provider_id`) REFERENCES `otp_providers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_opl_activation` FOREIGN KEY (`activation_id`) REFERENCES `otp_activations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. OTP Refunds (Exact-Once Refund Guard via UNIQUE activation_id)
CREATE TABLE IF NOT EXISTS `otp_refunds` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activation_id` BIGINT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `ticket_id` BIGINT UNSIGNED NULL,
  `requested_by_type` ENUM('system','user','admin') NOT NULL DEFAULT 'system',
  `requested_by_id` INT UNSIGNED NULL,
  `amount` DECIMAL(16,4) NOT NULL,
  `currency_code` VARCHAR(10) NOT NULL DEFAULT 'INR',
  `reason` VARCHAR(255) NULL,
  `admin_note` TEXT NULL,
  `provider_status_snapshot` VARCHAR(80) NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `wallet_tx_id` BIGINT UNSIGNED NULL,
  `processed_by` INT UNSIGNED NULL,
  `processed_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_otp_refund_activation` (`activation_id`),
  KEY `idx_otp_refund_status` (`status`, `created_at`),
  KEY `idx_otp_refund_user` (`user_id`),
  CONSTRAINT `fk_otp_ref_activation` FOREIGN KEY (`activation_id`) REFERENCES `otp_activations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_otp_ref_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Extend Existing Unified Support Tickets Table Safely (Repeat-Safe Stored Procedure)
DROP PROCEDURE IF EXISTS `sp_dual_store_otp_upgrade`;
DELIMITER $$
CREATE PROCEDURE `sp_dual_store_otp_upgrade`()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'support_tickets' AND COLUMN_NAME = 'store_type'
  ) THEN
    ALTER TABLE `support_tickets`
      ADD COLUMN `store_type` ENUM('smm','otp') NOT NULL DEFAULT 'smm' AFTER `ticket_number`,
      ADD INDEX `idx_ticket_store_type` (`store_type`, `status`);
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'support_tickets' AND COLUMN_NAME = 'ticket_category'
  ) THEN
    ALTER TABLE `support_tickets`
      ADD COLUMN `ticket_category` VARCHAR(60) NULL AFTER `department`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'support_tickets' AND COLUMN_NAME = 'otp_activation_id'
  ) THEN
    ALTER TABLE `support_tickets`
      ADD COLUMN `otp_activation_id` BIGINT UNSIGNED NULL AFTER `order_id`,
      ADD INDEX `idx_ticket_otp_activation` (`otp_activation_id`);
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'support_tickets' AND COLUMN_NAME = 'internal_notes'
  ) THEN
    ALTER TABLE `support_tickets`
      ADD COLUMN `internal_notes` TEXT NULL AFTER `status`;
  END IF;

  -- Extend ticket statuses cleanly while keeping backward compatibility
  ALTER TABLE `support_tickets`
    MODIFY COLUMN `status` ENUM('open','pending','answered','waiting_user','resolved','closed') NOT NULL DEFAULT 'open';

  -- Extend notifications type enum safely for OTP notifications
  ALTER TABLE `notifications`
    MODIFY COLUMN `type` ENUM('order','payment','ticket','announcement','system','sale','otp','otp_refund') NOT NULL DEFAULT 'system';
END$$
DELIMITER ;

CALL `sp_dual_store_otp_upgrade`();
DROP PROCEDURE IF EXISTS `sp_dual_store_otp_upgrade`;

-- 9. Dual Store & OTP Configuration Settings (Defaults: Both Stores Enabled)
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `setting_group`) VALUES
('smm_store_enabled', '1', 'stores'),
('otp_store_enabled', '1', 'stores'),
('otp_default_provider_id', '', 'otp'),
('otp_polling_interval', '5', 'otp'),
('otp_max_active_per_user', '5', 'otp'),
('otp_max_daily_per_user', '50', 'otp'),
('otp_min_wallet_balance', '0.00', 'otp'),
('otp_default_timeout_minutes', '15', 'otp'),
('otp_auto_refund_expired', '1', 'otp'),
('otp_auto_refund_canceled', '1', 'otp'),
('otp_allow_user_cancel', '1', 'otp'),
('otp_api_timeout', '20', 'otp'),
('otp_show_full_sms', '1', 'otp'),
('otp_notify_events', '1', 'otp'),
('otp_provider_error_mode', 'friendly', 'otp'),
('otp_default_profit_margin', '25.00', 'otp');

SET FOREIGN_KEY_CHECKS = 1;
