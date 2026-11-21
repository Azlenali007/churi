-- ============================================================================
-- SMM PRO PANEL — Complete MySQL / MariaDB Database Schema
-- Compatible with MySQL 8.0+ and MariaDB 10.6+ (InnoDB, utf8mb4)
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` LONGTEXT NULL,
  `setting_group` VARCHAR(50) NOT NULL DEFAULT 'general',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_key` (`setting_key`),
  KEY `idx_setting_group` (`setting_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `currencies` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(10) NOT NULL,
  `name` VARCHAR(60) NOT NULL,
  `symbol` VARCHAR(10) NOT NULL,
  `exchange_rate` DECIMAL(16,6) NOT NULL DEFAULT 1.000000,
  `is_base` TINYINT(1) NOT NULL DEFAULT 0,
  `is_manual_override` TINYINT(1) NOT NULL DEFAULT 0,
  `rate_source` VARCHAR(60) NOT NULL DEFAULT 'manual',
  `update_status` ENUM('ok','failed','manual','fallback') NOT NULL DEFAULT 'manual',
  `last_error` VARCHAR(255) NULL,
  `last_updated_at` DATETIME NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_currency_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL,
  `slug` VARCHAR(80) NOT NULL,
  `description` VARCHAR(255) NULL,
  `permissions_json` LONGTEXT NOT NULL,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_name` (`name`),
  UNIQUE KEY `uk_role_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admins` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id` INT UNSIGNED NOT NULL,
  `username` VARCHAR(60) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(120) NOT NULL,
  `two_factor_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `two_factor_secret` VARCHAR(64) NULL,
  `status` ENUM('active','suspended') NOT NULL DEFAULT 'active',
  `last_login_at` DATETIME NULL,
  `last_login_ip` VARCHAR(45) NULL,
  `reset_token` VARCHAR(128) NULL,
  `reset_expires_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_username` (`username`),
  UNIQUE KEY `uk_admin_email` (`email`),
  KEY `idx_admin_role` (`role_id`),
  CONSTRAINT `fk_admins_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(60) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(120) NOT NULL,
  `phone` VARCHAR(30) NULL,
  `wallet_balance` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
  `total_spent` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
  `api_key` VARCHAR(80) NULL,
  `api_status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
  `referral_code` VARCHAR(32) NOT NULL,
  `referred_by` INT UNSIGNED NULL,
  `email_verified_at` DATETIME NULL,
  `email_verify_token` VARCHAR(128) NULL,
  `reset_token` VARCHAR(128) NULL,
  `reset_expires_at` DATETIME NULL,
  `two_factor_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `two_factor_secret` VARCHAR(64) NULL,
  `notification_prefs_json` TEXT NULL,
  `custom_rate_discount` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `preferred_currency` VARCHAR(10) NULL DEFAULT NULL,
  `status` ENUM('active','suspended','pending') NOT NULL DEFAULT 'active',
  `admin_notes` TEXT NULL,
  `last_login_at` DATETIME NULL,
  `last_login_ip` VARCHAR(45) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_username` (`username`),
  UNIQUE KEY `uk_user_email` (`email`),
  UNIQUE KEY `uk_user_api_key` (`api_key`),
  UNIQUE KEY `uk_user_referral_code` (`referral_code`),
  KEY `idx_user_referred_by` (`referred_by`),
  KEY `idx_user_status` (`status`),
  CONSTRAINT `fk_users_referred_by` FOREIGN KEY (`referred_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier` VARCHAR(150) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `user_type` ENUM('user','admin') NOT NULL DEFAULT 'user',
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `user_agent` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_ip_time` (`ip_address`, `user_type`, `created_at`),
  KEY `idx_login_ident_time` (`identifier`, `user_type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(170) NOT NULL,
  `platform` VARCHAR(60) NOT NULL DEFAULT 'general',
  `icon_svg_key` VARCHAR(60) NOT NULL DEFAULT 'general',
  `description` TEXT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_category_slug` (`slug`),
  KEY `idx_category_status_sort` (`status`, `sort_order`),
  KEY `idx_category_platform` (`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subcategories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(170) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_subcat_category` (`category_id`),
  CONSTRAINT `fk_subcat_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `providers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `api_url` VARCHAR(255) NOT NULL,
  `api_key_enc` TEXT NOT NULL,
  `currency_code` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `exchange_rate` DECIMAL(16,6) NOT NULL DEFAULT 1.000000,
  `rate_mode` ENUM('auto','manual') NOT NULL DEFAULT 'auto',
  `default_profit_margin` DECIMAL(8,2) NOT NULL DEFAULT 30.00,
  `balance` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
  `last_balance_check` DATETIME NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `last_sync_at` DATETIME NULL,
  `last_error` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_provider_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `services` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` INT UNSIGNED NOT NULL,
  `subcategory_id` INT UNSIGNED NULL,
  `provider_id` INT UNSIGNED NULL,
  `provider_service_id` VARCHAR(80) NULL,
  `provider_category_raw` VARCHAR(200) NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `type` ENUM('default','custom_comments','package','drip_feed','subscriptions') NOT NULL DEFAULT 'default',
  `platform` VARCHAR(60) NOT NULL DEFAULT 'general',
  `icon_key` VARCHAR(60) NOT NULL DEFAULT 'general',
  `provider_rate` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
  `provider_currency` VARCHAR(10) NULL DEFAULT NULL,
  `rate` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
  `min_qty` INT UNSIGNED NOT NULL DEFAULT 10,
  `max_qty` INT UNSIGNED NOT NULL DEFAULT 10000,
  `drip_feed_support` TINYINT(1) NOT NULL DEFAULT 0,
  `refill_support` TINYINT(1) NOT NULL DEFAULT 0,
  `cancel_support` TINYINT(1) NOT NULL DEFAULT 0,
  `avg_time` VARCHAR(80) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_service_category` (`category_id`),
  KEY `idx_service_subcategory` (`subcategory_id`),
  KEY `idx_service_provider` (`provider_id`, `provider_service_id`),
  KEY `idx_service_status_sort` (`status`, `sort_order`),
  KEY `idx_service_platform` (`platform`),
  CONSTRAINT `fk_service_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_service_subcategory` FOREIGN KEY (`subcategory_id`) REFERENCES `subcategories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_service_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `provider_service_mappings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider_id` INT UNSIGNED NOT NULL,
  `service_id` INT UNSIGNED NOT NULL,
  `external_service_id` VARCHAR(80) NOT NULL,
  `external_category_name` VARCHAR(200) NULL,
  `external_rate` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
  `external_currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `last_synced_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_provider_ext_service` (`provider_id`, `external_service_id`),
  KEY `idx_psm_service` (`service_id`),
  CONSTRAINT `fk_psm_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_psm_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `provider_category_mappings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider_id` INT UNSIGNED NOT NULL,
  `external_category_id` VARCHAR(100) NULL,
  `external_category_name` VARCHAR(200) NOT NULL,
  `external_category_key` VARCHAR(200) NOT NULL,
  `platform_category_id` INT UNSIGNED NOT NULL,
  `service_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `mapping_mode` ENUM('mapped','created') NOT NULL DEFAULT 'mapped',
  `last_synced_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pcm_provider_key` (`provider_id`, `external_category_key`),
  KEY `idx_pcm_platform_cat` (`platform_category_id`),
  CONSTRAINT `fk_pcm_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pcm_category` FOREIGN KEY (`platform_category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flash_sales` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(150) NOT NULL,
  `description` VARCHAR(255) NULL,
  `banner_image` VARCHAR(255) NULL,
  `discount_type` ENUM('percent','fixed_price') NOT NULL DEFAULT 'percent',
  `discount_value` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
  `starts_at` DATETIME NOT NULL,
  `ends_at` DATETIME NOT NULL,
  `priority` INT NOT NULL DEFAULT 1,
  `status` ENUM('active','inactive','stopped','expired') NOT NULL DEFAULT 'active',
  `created_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fs_active_window` (`status`, `starts_at`, `ends_at`, `priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flash_sale_services` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `flash_sale_id` INT UNSIGNED NOT NULL,
  `service_id` INT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED NULL,
  `custom_sale_price` DECIMAL(16,4) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fs_service` (`flash_sale_id`, `service_id`),
  KEY `idx_fss_service` (`service_id`),
  CONSTRAINT `fk_fss_sale` FOREIGN KEY (`flash_sale_id`) REFERENCES `flash_sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fss_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `popups` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(160) NOT NULL,
  `badge_text` VARCHAR(60) NULL DEFAULT 'Exclusive Offer',
  `description` TEXT NULL,
  `image_path` VARCHAR(255) NULL,
  `cta_text` VARCHAR(80) NULL DEFAULT 'Shop Now',
  `cta_link` VARCHAR(255) NULL,
  `position` ENUM('center','bottom_right','top_banner') NOT NULL DEFAULT 'center',
  `show_on_public` TINYINT(1) NOT NULL DEFAULT 1,
  `show_on_dashboard` TINYINT(1) NOT NULL DEFAULT 1,
  `starts_at` DATETIME NULL,
  `ends_at` DATETIME NULL,
  `frequency` ENUM('once','every_visit','daily') NOT NULL DEFAULT 'daily',
  `show_mobile` TINYINT(1) NOT NULL DEFAULT 1,
  `show_desktop` TINYINT(1) NOT NULL DEFAULT 1,
  `priority` INT NOT NULL DEFAULT 1,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_popup_active` (`status`, `priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `child_panels` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_user_id` INT UNSIGNED NOT NULL,
  `panel_name` VARCHAR(120) NOT NULL,
  `domain` VARCHAR(190) NOT NULL,
  `domain_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `verification_token` VARCHAR(64) NOT NULL,
  `logo_path` VARCHAR(255) NULL,
  `primary_color` VARCHAR(20) NOT NULL DEFAULT '#7C3AED',
  `currency_code` VARCHAR(10) NOT NULL DEFAULT 'INR',
  `profit_margin` DECIMAL(8,2) NOT NULL DEFAULT 20.00,
  `wallet_balance` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
  `api_key` VARCHAR(80) NOT NULL,
  `status` ENUM('pending','active','suspended','rejected','expired') NOT NULL DEFAULT 'pending',
  `expires_at` DATETIME NULL,
  `admin_notes` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cp_domain` (`domain`),
  UNIQUE KEY `uk_cp_api_key` (`api_key`),
  KEY `idx_cp_owner` (`owner_user_id`),
  KEY `idx_cp_status` (`status`),
  CONSTRAINT `fk_cp_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `child_panel_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `child_panel_id` INT UNSIGNED NOT NULL,
  `username` VARCHAR(60) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(120) NOT NULL,
  `wallet_balance` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
  `api_key` VARCHAR(80) NULL,
  `status` ENUM('active','suspended') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpu_email` (`child_panel_id`, `email`),
  UNIQUE KEY `uk_cpu_username` (`child_panel_id`, `username`),
  CONSTRAINT `fk_cpu_panel` FOREIGN KEY (`child_panel_id`) REFERENCES `child_panels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `child_panel_services` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `child_panel_id` INT UNSIGNED NOT NULL,
  `parent_service_id` INT UNSIGNED NOT NULL,
  `custom_name` VARCHAR(255) NULL,
  `custom_rate` DECIMAL(16,4) NOT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cps_service` (`child_panel_id`, `parent_service_id`),
  KEY `idx_cps_parent` (`parent_service_id`),
  CONSTRAINT `fk_cps_panel` FOREIGN KEY (`child_panel_id`) REFERENCES `child_panels` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cps_service` FOREIGN KEY (`parent_service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `child_panel_id` INT UNSIGNED NULL,
  `child_panel_user_id` INT UNSIGNED NULL,
  `service_id` INT UNSIGNED NOT NULL,
  `provider_id` INT UNSIGNED NULL,
  `provider_order_id` VARCHAR(80) NULL,
  `flash_sale_id` INT UNSIGNED NULL,
  `link` VARCHAR(500) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `start_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `remains` INT UNSIGNED NOT NULL DEFAULT 0,
  `unit_price` DECIMAL(16,4) NOT NULL,
  `original_unit_price` DECIMAL(16,4) NOT NULL,
  `charge` DECIMAL(16,4) NOT NULL,
  `currency_code` VARCHAR(10) NOT NULL DEFAULT 'INR',
  `provider_cost` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
  `profit` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
  `refunded_amount` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
  `is_mass_order` TINYINT(1) NOT NULL DEFAULT 0,
  `mass_order_batch` VARCHAR(40) NULL,
  `is_api_order` TINYINT(1) NOT NULL DEFAULT 0,
  `custom_comments` TEXT NULL,
  `drip_feed_runs` INT UNSIGNED NULL,
  `drip_feed_interval` INT UNSIGNED NULL,
  `status` ENUM('pending','in_progress','processing','completed','partial','canceled','failed') NOT NULL DEFAULT 'pending',
  `refill_status` ENUM('none','requested','completed','rejected') NOT NULL DEFAULT 'none',
  `cancel_status` ENUM('none','requested','completed','rejected') NOT NULL DEFAULT 'none',
  `provider_response` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_user_status` (`user_id`, `status`),
  KEY `idx_order_cp` (`child_panel_id`, `child_panel_user_id`),
  KEY `idx_order_service` (`service_id`),
  KEY `idx_order_provider` (`provider_id`, `provider_order_id`),
  KEY `idx_order_status_created` (`status`, `created_at`),
  KEY `idx_order_flash_sale` (`flash_sale_id`),
  CONSTRAINT `fk_order_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_order_cp` FOREIGN KEY (`child_panel_id`) REFERENCES `child_panels` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_order_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_order_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_order_fs` FOREIGN KEY (`flash_sale_id`) REFERENCES `flash_sales` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `actor_type` ENUM('system','user','admin','cron','provider') NOT NULL DEFAULT 'system',
  `actor_id` INT UNSIGNED NULL,
  `action` VARCHAR(80) NOT NULL,
  `details` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ol_order` (`order_id`),
  CONSTRAINT `fk_ol_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wallet_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `child_panel_id` INT UNSIGNED NULL,
  `type` ENUM('credit','debit','refund','referral_bonus','admin_add','admin_deduct','payment') NOT NULL,
  `amount` DECIMAL(16,4) NOT NULL,
  `currency_code` VARCHAR(10) NOT NULL DEFAULT 'INR',
  `balance_before` DECIMAL(16,4) NOT NULL,
  `balance_after` DECIMAL(16,4) NOT NULL,
  `reference_type` VARCHAR(40) NULL,
  `reference_id` VARCHAR(80) NULL,
  `idempotency_key` VARCHAR(100) NULL,
  `description` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_wt_idempotency` (`idempotency_key`),
  KEY `idx_wt_user_created` (`user_id`, `created_at`),
  KEY `idx_wt_cp` (`child_panel_id`),
  CONSTRAINT `fk_wt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_gateways` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(50) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `type` ENUM('automatic','manual') NOT NULL DEFAULT 'manual',
  `config_json` TEXT NULL,
  `instructions` TEXT NULL,
  `min_amount` DECIMAL(16,4) NOT NULL DEFAULT 10.0000,
  `max_amount` DECIMAL(16,4) NOT NULL DEFAULT 100000.0000,
  `fee_percent` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'inactive',
  `sort_order` INT NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pg_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `gateway_id` INT UNSIGNED NOT NULL,
  `gateway_code` VARCHAR(50) NOT NULL,
  `transaction_ref` VARCHAR(120) NOT NULL,
  `gateway_order_id` VARCHAR(120) NULL,
  `gateway_payment_id` VARCHAR(120) NULL,
  `amount` DECIMAL(16,4) NOT NULL,
  `fee` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
  `net_amount` DECIMAL(16,4) NOT NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'INR',
  `proof_path` VARCHAR(255) NULL,
  `payer_note` VARCHAR(255) NULL,
  `admin_note` VARCHAR(255) NULL,
  `status` ENUM('pending','completed','rejected','failed') NOT NULL DEFAULT 'pending',
  `verified_at` DATETIME NULL,
  `reviewed_by` INT UNSIGNED NULL,
  `raw_response` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_payment_tx_ref` (`transaction_ref`),
  KEY `idx_payment_user` (`user_id`, `status`),
  KEY `idx_payment_gateway` (`gateway_id`),
  CONSTRAINT `fk_payment_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_payment_gateway` FOREIGN KEY (`gateway_id`) REFERENCES `payment_gateways` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `child_panel_id` INT UNSIGNED NULL,
  `assigned_admin_id` INT UNSIGNED NULL,
  `ticket_number` VARCHAR(32) NOT NULL,
  `store_type` ENUM('smm','otp') NOT NULL DEFAULT 'smm',
  `department` ENUM('orders','payments','api','child_panel','technical','general') NOT NULL DEFAULT 'general',
  `ticket_category` VARCHAR(60) NULL,
  `priority` ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `subject` VARCHAR(200) NOT NULL,
  `order_id` BIGINT UNSIGNED NULL,
  `otp_activation_id` BIGINT UNSIGNED NULL,
  `status` ENUM('open','pending','answered','waiting_user','resolved','closed') NOT NULL DEFAULT 'open',
  `internal_notes` TEXT NULL,
  `last_reply_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ticket_number` (`ticket_number`),
  KEY `idx_ticket_user` (`user_id`, `status`),
  KEY `idx_ticket_admin` (`assigned_admin_id`),
  KEY `idx_ticket_store_type` (`store_type`, `status`),
  KEY `idx_ticket_otp_activation` (`otp_activation_id`),
  CONSTRAINT `fk_ticket_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ticket_admin` FOREIGN KEY (`assigned_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_replies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` BIGINT UNSIGNED NOT NULL,
  `sender_type` ENUM('user','admin') NOT NULL,
  `sender_id` INT UNSIGNED NOT NULL,
  `message` TEXT NOT NULL,
  `attachment_path` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tr_ticket` (`ticket_id`),
  CONSTRAINT `fk_tr_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `referrals` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `referrer_user_id` INT UNSIGNED NOT NULL,
  `referred_user_id` INT UNSIGNED NOT NULL,
  `commission_rate` DECIMAL(5,2) NOT NULL DEFAULT 5.00,
  `total_earned` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ref_referred` (`referred_user_id`),
  KEY `idx_ref_referrer` (`referrer_user_id`),
  CONSTRAINT `fk_ref_referrer` FOREIGN KEY (`referrer_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ref_referred` FOREIGN KEY (`referred_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `referral_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `referral_id` INT UNSIGNED NOT NULL,
  `referrer_user_id` INT UNSIGNED NOT NULL,
  `referred_user_id` INT UNSIGNED NOT NULL,
  `source_type` ENUM('payment','order') NOT NULL DEFAULT 'payment',
  `source_id` BIGINT UNSIGNED NOT NULL,
  `base_amount` DECIMAL(16,4) NOT NULL,
  `commission_amount` DECIMAL(16,4) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rt_referral` (`referral_id`),
  KEY `idx_rt_referrer` (`referrer_user_id`),
  CONSTRAINT `fk_rt_referral` FOREIGN KEY (`referral_id`) REFERENCES `referrals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL,
  `admin_id` INT UNSIGNED NULL,
  `type` ENUM('order','payment','ticket','announcement','system','sale','otp','otp_refund') NOT NULL DEFAULT 'system',
  `title` VARCHAR(160) NOT NULL,
  `message` TEXT NOT NULL,
  `link` VARCHAR(255) NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user` (`user_id`, `is_read`, `created_at`),
  KEY `idx_notif_admin` (`admin_id`, `is_read`, `created_at`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notif_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `announcements` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(160) NOT NULL,
  `content` TEXT NOT NULL,
  `type` ENUM('info','warning','success','promo') NOT NULL DEFAULT 'info',
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ann_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cms_pages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(80) NOT NULL,
  `title` VARCHAR(180) NOT NULL,
  `content_html` LONGTEXT NOT NULL,
  `meta_title` VARCHAR(180) NULL,
  `meta_description` VARCHAR(320) NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cms_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cms_blocks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `section_key` VARCHAR(80) NOT NULL,
  `title` VARCHAR(200) NULL,
  `subtitle` TEXT NULL,
  `content_json` LONGTEXT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cms_block_key` (`section_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_type` ENUM('admin','user','system') NOT NULL DEFAULT 'system',
  `actor_id` INT UNSIGNED NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(60) NULL,
  `entity_id` VARCHAR(60) NULL,
  `details` TEXT NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_al_actor` (`actor_type`, `actor_id`),
  KEY `idx_al_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `log_type` ENUM('reseller_in','provider_out','payment_webhook','php_error') NOT NULL,
  `provider_id` INT UNSIGNED NULL,
  `user_id` INT UNSIGNED NULL,
  `endpoint` VARCHAR(255) NULL,
  `request_payload` MEDIUMTEXT NULL,
  `response_payload` MEDIUMTEXT NULL,
  `http_code` INT NULL,
  `status` VARCHAR(40) NOT NULL DEFAULT 'ok',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_api_log_type` (`log_type`, `created_at`),
  KEY `idx_api_log_provider` (`provider_id`),
  KEY `idx_api_log_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `provider_import_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider_id` INT UNSIGNED NULL,
  `admin_id` INT UNSIGNED NULL,
  `operation_type` VARCHAR(60) NOT NULL,
  `total_selected` INT UNSIGNED NOT NULL DEFAULT 0,
  `imported_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `skipped_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `failed_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `categories_created` INT UNSIGNED NOT NULL DEFAULT 0,
  `details_json` MEDIUMTEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pil_provider` (`provider_id`, `created_at`),
  KEY `idx_pil_created` (`created_at`),
  CONSTRAINT `fk_pil_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- OTP ACTIVATION STORE TABLES (SHARED USERS, WALLET, TICKETS & NOTIFICATIONS)
-- ============================================================================

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

-- ============================================================================
-- ESSENTIAL SYSTEM CONFIGURATION & INITIAL STRUCTURAL DEFAULTS (NO FAKE DATA)
-- ============================================================================

INSERT IGNORE INTO `currencies` (`code`, `name`, `symbol`, `exchange_rate`, `is_base`, `is_manual_override`, `rate_source`, `update_status`, `status`) VALUES
('INR', 'Indian Rupee', '₹', 1.000000, 1, 0, 'base', 'ok', 'active'),
('USD', 'US Dollar', '$', 0.011900, 0, 0, 'initial_seed', 'manual', 'active'),
('EUR', 'Euro', '€', 0.011000, 0, 0, 'initial_seed', 'manual', 'active'),
('GBP', 'British Pound', '£', 0.009400, 0, 0, 'initial_seed', 'manual', 'active');

INSERT IGNORE INTO `roles` (`id`, `name`, `slug`, `description`, `permissions_json`, `is_system`) VALUES
(1, 'Super Admin', 'super_admin', 'Unrestricted full access to all administrative modules', '["*"]', 1),
(2, 'Support Manager', 'support_manager', 'Manage support tickets, orders, and user inquiries', '["dashboard.view","orders.view","orders.edit","tickets.manage","users.view","logs.view"]', 1),
(3, 'Operations Staff', 'operations_staff', 'Manage services, categories, providers, and order synchronization', '["dashboard.view","orders.view","orders.edit","categories.manage","services.manage","providers.manage"]', 1);

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `setting_group`) VALUES
('site_name', 'SMM PRO', 'general'),
('site_tagline', 'Grow Faster, Smarter', 'general'),
('site_logo', '', 'general'),
('site_favicon', '', 'general'),
('base_currency', 'INR', 'currency'),
('currency_symbol', '₹', 'currency'),
('exchange_rate_auto_update', '1', 'currency'),
('exchange_rate_interval_hours', '12', 'currency'),
('exchange_rate_last_attempt_at', '', 'currency'),
('exchange_rate_last_success_at', '', 'currency'),
('exchange_rate_last_status', 'never', 'currency'),
('exchange_rate_last_error', '', 'currency'),
('exchange_rate_provider', 'frankfurter', 'currency'),
('default_profit_margin', '30.00', 'pricing'),
('timezone', 'Asia/Kolkata', 'general'),
('maintenance_mode', '0', 'system'),
('maintenance_message', 'We are performing scheduled maintenance. Please check back shortly.', 'system'),
('registration_enabled', '1', 'auth'),
('email_verification_required', '0', 'auth'),
('referral_enabled', '1', 'referral'),
('referral_commission_percent', '5.00', 'referral'),
('child_panel_price', '999.00', 'child_panel'),
('child_panel_auto_approve', '0', 'child_panel'),
('smtp_host', '', 'smtp'),
('smtp_port', '587', 'smtp'),
('smtp_user', '', 'smtp'),
('smtp_pass', '', 'smtp'),
('smtp_encryption', 'tls', 'smtp'),
('smtp_from_email', '', 'smtp'),
('smtp_from_name', 'SMM PRO', 'smtp'),
('seo_title', 'SMM PRO — Automated Social Media Marketing Panel', 'seo'),
('seo_description', 'Enterprise-grade automated SMM Panel for Instagram, YouTube, Telegram, TikTok, X, and Spotify services with API and Child Panels.', 'seo'),
('seo_keywords', 'smm panel, social media marketing, reseller api, child panel', 'seo'),
('social_instagram', '', 'social'),
('social_telegram', '', 'social'),
('social_youtube', '', 'social'),
('social_twitter', '', 'social'),
('support_email', 'support@example.com', 'general'),
('active_theme', 'classic', 'theme'),
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

INSERT IGNORE INTO `payment_gateways` (`code`, `name`, `type`, `config_json`, `instructions`, `min_amount`, `max_amount`, `fee_percent`, `status`, `sort_order`) VALUES
('razorpay', 'Razorpay (UPI / Cards / NetBanking)', 'automatic', '{"key_id":"","key_secret":"","webhook_secret":""}', 'Pay instantly via Razorpay Checkout. Credits are applied automatically after server-side signature verification.', 10.0000, 100000.0000, 0.00, 'inactive', 1),
('manual_bank', 'Manual Bank Transfer / UPI', 'manual', '{"account_name":"","account_number":"","ifsc_or_iban":"","upi_id":""}', 'Transfer the exact amount to the bank/UPI details shown and submit your UTR / Reference ID with payment screenshot for verification.', 50.0000, 100000.0000, 0.00, 'active', 2);

INSERT IGNORE INTO `categories` (`id`, `name`, `slug`, `platform`, `icon_svg_key`, `description`, `sort_order`, `status`) VALUES
(1, 'General Services', 'general-services', 'general', 'general', 'Default fallback category for uncategorized or imported services', 999, 'active');

INSERT IGNORE INTO `cms_blocks` (`section_key`, `title`, `subtitle`, `content_json`) VALUES
('hero', 'Accelerate Your Social Growth With Precision Automation', 'Connect with reliable, real-time SMM fulfillment across Instagram, YouTube, Telegram, TikTok, X, and Spotify.', '{"cta_primary_text":"Get Started","cta_primary_link":"/register.php","cta_secondary_text":"Explore Services","cta_secondary_link":"/services.php"}'),
('why_choose_us', 'Built for Agencies, Creators & Resellers', 'Engineered for speed, transparent pricing, and uninterrupted API delivery.', '{"items":[{"title":"Instant Automated Processing","desc":"Orders are dispatched to verified upstream providers via live API connections in seconds."},{"title":"Transparent Decimal Pricing","desc":"Exact rate calculations, active flash sale discounts, and itemized wallet transaction ledgers."},{"title":"Reseller API v2 & Child Panels","desc":"Full API compatibility and isolated white-label child panels with custom domains and margins."},{"title":"24/7 Ticket & Order Support","desc":"Dedicated support ticketing with order-level tracking, refill requests, and automated partial refunds."}]}'),
('how_it_works', 'Launch Your Campaign in 4 Simple Steps', 'From registration to live fulfillment in under two minutes.', '{"steps":[{"step":"01","title":"Create Your Account","desc":"Register securely with instant dashboard access and API key generation."},{"step":"02","title":"Add Wallet Funds","desc":"Top up using Razorpay or verified manual transfer with full transaction logs."},{"step":"03","title":"Select Your Service","desc":"Pick a platform category, review min/max limits, and calculate exact pricing."},{"step":"04","title":"Track Real-Time Delivery","desc":"Monitor live start count, remaining quantity, and automated status sync."}]}'),
('features', 'Enterprise Capabilities Out of the Box', 'Every tool required to scale social campaigns and resell services.', '{"items":["Live Flash Sale Engine & Countdowns","Multi-Provider Auto-Import & Category Sync","Mass Order Bulk Processor","Child Panel Multi-Tenant Isolation","Razorpay Server-Side Verification","Automated Partial & Cancel Refunds"]}'),
('testimonials', 'Trusted by Digital Agencies & Resellers', 'Feedback from teams running daily campaigns on our infrastructure.', '{"items":[{"name":"Arjun Mehta","role":"Performance Agency Lead","quote":"Provider syncing and automatic category mapping cut our catalog maintenance down to minutes."},{"name":"Priya Nair","role":"Social Media Strategist","quote":"Transparent wallet ledgers, instant order tracking, and reliable refill support make client reporting effortless."},{"name":"Daniel Brooks","role":"Reseller Partner","quote":"The isolated Child Panel and v2 API allowed us to launch our own branded storefront cleanly."}]}'),
('faq', 'Frequently Asked Questions', 'Clear answers about orders, wallet funding, API usage, and child panels.', '{"items":[{"q":"How quickly do orders start processing?","a":"Most automated services dispatch immediately upon submission, depending on the selected service average completion time."},{"q":"How does wallet funding work?","a":"Wallet deposits via Razorpay are credited only after cryptographic server-side signature verification. Manual transfers are credited after admin review."},{"q":"What happens if an order is canceled or partially delivered?","a":"Our order synchronization engine automatically calculates undelivered remains and refunds the exact decimal amount back to your wallet."},{"q":"Can I run my own SMM storefront?","a":"Yes. You can launch an isolated Child Panel from your dashboard with custom branding, domain verification, and your own profit margin."}]}');

INSERT IGNORE INTO `cms_pages` (`slug`, `title`, `content_html`, `meta_title`, `meta_description`) VALUES
('terms', 'Terms & Conditions', '<h2>1. Acceptance of Terms</h2><p>By accessing and using this SMM Panel, you agree to comply with these Terms and Conditions and all applicable laws.</p><h2>2. Service Delivery & Pricing</h2><p>All service rates are displayed per 1,000 units unless stated otherwise. Active Flash Sale discounts are verified server-side at the moment of order placement.</p><h2>3. Wallet & Refunds</h2><p>Wallet funds are used strictly for purchasing platform services. Orders marked as Canceled or Partial are automatically refunded for undelivered quantities.</p><h2>4. Account Security</h2><p>You are responsible for safeguarding your account credentials and Reseller API key.</p>', 'Terms & Conditions', 'Platform Terms & Conditions'),
('privacy', 'Privacy Policy', '<h2>1. Data Collection</h2><p>We collect only the account details necessary to operate your panel account, process wallet transactions, and fulfill submitted orders.</p><h2>2. Payment Security</h2><p>Payment verification is handled securely on the server side. We never store raw card credentials.</p><h2>3. Session & Cookie Security</h2><p>Secure HTTP-only session cookies are used to authenticate sessions and prevent CSRF attacks.</p><h2>4. Data Protection</h2><p>All passwords are hashed using modern one-way cryptographic hashing and API keys are stored securely.</p>', 'Privacy Policy', 'Platform Privacy Policy');

SET FOREIGN_KEY_CHECKS = 1;
