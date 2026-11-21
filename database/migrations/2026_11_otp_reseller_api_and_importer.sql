-- ============================================================================
-- Migration: 5SIM Batch Importer Jobs + Complete OTP Reseller API System
-- Safe & Repeat-Executable on existing MySQL 8.0+ / MariaDB 10.4+ databases
-- Preserves all existing SMM + OTP production data. Never drops tables/rows.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Persistent Batch Import Jobs (prevents timeouts, preserves progress across reloads)
CREATE TABLE IF NOT EXISTS `otp_import_jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider_id` INT UNSIGNED NOT NULL,
  `admin_id` INT UNSIGNED NULL,
  `job_type` ENUM('services','countries') NOT NULL DEFAULT 'services',
  `mode` VARCHAR(40) NOT NULL DEFAULT 'import_all',
  `status` ENUM('queued','fetching_countries','fetching_services','ready','processing','importing','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
  `stage_message` VARCHAR(255) NULL,
  `options_json` TEXT NULL,
  `staged_items_json` LONGTEXT NULL,
  `total_records` INT UNSIGNED NOT NULL DEFAULT 0,
  `processed_records` INT UNSIGNED NOT NULL DEFAULT 0,
  `batch_offset` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_countries_found` INT UNSIGNED NOT NULL DEFAULT 0,
  `new_countries` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_countries` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_services_found` INT UNSIGNED NOT NULL DEFAULT 0,
  `new_services` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_services` INT UNSIGNED NOT NULL DEFAULT 0,
  `prices_updated` INT UNSIGNED NOT NULL DEFAULT 0,
  `unsupported_records` INT UNSIGNED NOT NULL DEFAULT 0,
  `skipped_records` INT UNSIGNED NOT NULL DEFAULT 0,
  `failed_records` INT UNSIGNED NOT NULL DEFAULT 0,
  `errors_json` MEDIUMTEXT NULL,
  `zero_reason` VARCHAR(255) NULL,
  `started_at` DATETIME NULL,
  `completed_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_otp_ij_provider_status` (`provider_id`, `status`, `created_at`),
  KEY `idx_otp_ij_created` (`created_at`),
  CONSTRAINT `fk_otp_ij_provider` FOREIGN KEY (`provider_id`) REFERENCES `otp_providers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Hashed OTP Reseller API Keys & Per-User Controls (separate from SMM users.api_key)
CREATE TABLE IF NOT EXISTS `otp_api_keys` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `key_prefix` VARCHAR(20) NOT NULL,
  `key_hash` CHAR(64) NOT NULL,
  `status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
  `rate_limit_per_min` INT UNSIGNED NULL,
  `daily_purchase_limit` INT UNSIGNED NULL,
  `max_active_activations` INT UNSIGNED NULL,
  `allowed_ips` TEXT NULL,
  `custom_markup_percent` DECIMAL(8,2) NULL,
  `last_used_at` DATETIME NULL,
  `last_used_ip` VARCHAR(45) NULL,
  `total_requests` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_otp_apikey_user` (`user_id`),
  UNIQUE KEY `uk_otp_apikey_hash` (`key_hash`),
  KEY `idx_otp_apikey_status` (`status`),
  CONSTRAINT `fk_otp_apikey_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Admin-Controlled OTP Reseller API Pricing Rules
CREATE TABLE IF NOT EXISTS `otp_api_pricing_rules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope` ENUM('global','service','country','service_country') NOT NULL DEFAULT 'global',
  `service_id` INT UNSIGNED NULL,
  `country_id` INT UNSIGNED NULL,
  `pricing_type` ENUM('percent','fixed_profit','fixed_price') NOT NULL DEFAULT 'percent',
  `markup_percent` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `fixed_profit` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
  `fixed_price` DECIMAL(16,4) NULL,
  `min_selling_price` DECIMAL(16,4) NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_otp_apirule_lookup` (`scope`, `service_id`, `country_id`, `status`),
  CONSTRAINT `fk_otp_apirule_service` FOREIGN KEY (`service_id`) REFERENCES `otp_services` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_otp_apirule_country` FOREIGN KEY (`country_id`) REFERENCES `otp_countries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Sanitized OTP Reseller API Request Logs (never stores raw API keys or OTP codes)
CREATE TABLE IF NOT EXISTS `otp_api_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL,
  `api_key_id` INT UNSIGNED NULL,
  `endpoint` VARCHAR(120) NOT NULL,
  `http_method` VARCHAR(10) NOT NULL DEFAULT 'GET',
  `http_status` SMALLINT UNSIGNED NOT NULL DEFAULT 200,
  `response_time_ms` INT UNSIGNED NOT NULL DEFAULT 0,
  `success` TINYINT(1) NOT NULL DEFAULT 1,
  `error_code` VARCHAR(60) NULL,
  `request_meta_json` TEXT NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(200) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_otp_al_user_time` (`user_id`, `created_at`),
  KEY `idx_otp_al_endpoint` (`endpoint`, `created_at`),
  KEY `idx_otp_al_status` (`success`, `error_code`, `created_at`),
  CONSTRAINT `fk_otp_al_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_otp_al_key` FOREIGN KEY (`api_key_id`) REFERENCES `otp_api_keys` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Idempotency Guard for OTP API Activation Creation
CREATE TABLE IF NOT EXISTS `otp_api_idempotency` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `idempotency_key` VARCHAR(128) NOT NULL,
  `request_hash` CHAR(64) NOT NULL,
  `activation_id` BIGINT UNSIGNED NULL,
  `response_status` SMALLINT UNSIGNED NOT NULL DEFAULT 200,
  `response_json` MEDIUMTEXT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_otp_api_idem_user_key` (`user_id`, `idempotency_key`),
  KEY `idx_otp_api_idem_exp` (`expires_at`),
  CONSTRAINT `fk_otp_api_idem_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Safely add `source` and `api_key_id` columns to `otp_activations` (repeat-safe)
SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'otp_activations' AND COLUMN_NAME = 'source'),
    'SELECT 1',
    'ALTER TABLE `otp_activations` ADD COLUMN `source` ENUM(''direct'',''api'') NOT NULL DEFAULT ''direct'' AFTER `user_id`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'otp_activations' AND COLUMN_NAME = 'api_key_id'),
    'SELECT 1',
    'ALTER TABLE `otp_activations` ADD COLUMN `api_key_id` INT UNSIGNED NULL AFTER `source`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'otp_activations' AND INDEX_NAME = 'idx_otp_act_source'),
    'SELECT 1',
    'ALTER TABLE `otp_activations` ADD KEY `idx_otp_act_source` (`source`, `status`, `created_at`)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. Seed default OTP Reseller API global settings (non-destructive)
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `setting_group`) VALUES
('otp_api_enabled', '1', 'otp_api'),
('otp_api_version', 'v1', 'otp_api'),
('otp_api_global_rate_limit', '120', 'otp_api'),
('otp_api_default_user_rate_limit', '60', 'otp_api'),
('otp_api_max_daily_orders', '500', 'otp_api'),
('otp_api_max_active_activations', '25', 'otp_api'),
('otp_api_allowed_operations', 'balance,services,countries,prices,create,status,cancel,complete,history', 'otp_api'),
('otp_api_ip_restriction_enabled', '0', 'otp_api'),
('otp_api_log_retention_days', '30', 'otp_api'),
('otp_api_global_markup_type', 'percent', 'otp_api'),
('otp_api_global_markup_percent', '0.00', 'otp_api'),
('otp_api_global_fixed_profit', '0.0000', 'otp_api'),
('otp_api_global_min_price', '0.0000', 'otp_api');

SET FOREIGN_KEY_CHECKS = 1;
