-- ============================================================================
-- Migration: Provider Import Logs & SwipeX Theme Registry Defaults
-- Safe & non-destructive for existing installations
-- ============================================================================

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

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `setting_group`) VALUES
('active_theme', 'classic', 'theme');
