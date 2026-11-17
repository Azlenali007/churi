-- ============================================================================
-- Migration: Provider Category Mappings Table
-- Safe & non-destructive for existing installations
-- ============================================================================

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
