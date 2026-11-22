<?php
/**
 * Dual Store Manager — Independent ON/OFF Enforcement, Schema Auto-Ensure & Store Switcher UI
 */

class StoreManager
{
    public static function isSmmEnabled(): bool
    {
        return Helpers::setting('smm_store_enabled', '1') === '1';
    }

    public static function isOtpEnabled(): bool
    {
        return Helpers::setting('otp_store_enabled', '1') === '1';
    }

    public static function setStoreStatus(bool $smmEnabled, bool $otpEnabled): void
    {
        Helpers::setSetting('smm_store_enabled', $smmEnabled ? '1' : '0', 'stores');
        Helpers::setSetting('otp_store_enabled', $otpEnabled ? '1' : '0', 'stores');
    }

    /**
     * Persist the user's currently active storefront ('smm' or 'otp') in the PHP session.
     */
    public static function setActiveStore(string $store): void
    {
        $normalized = strtolower(trim($store)) === 'otp' ? 'otp' : 'smm';
        Auth::startSession();
        $_SESSION['active_store'] = $normalized;
    }

    /**
     * Read the user's currently active storefront, falling back cleanly if a store is disabled.
     */
    public static function getActiveStore(string $default = 'smm'): string
    {
        Auth::startSession();
        $sess = $_SESSION['active_store'] ?? '';
        if ($sess === 'otp' || $sess === 'smm') {
            return $sess;
        }
        if ($default === 'smm' && !self::isSmmEnabled() && self::isOtpEnabled()) {
            return 'otp';
        }
        if ($default === 'otp' && !self::isOtpEnabled() && self::isSmmEnabled()) {
            return 'smm';
        }
        return $default === 'otp' ? 'otp' : 'smm';
    }

    /**
     * Resolve active store context for shared account pages (Wallet, Notifications, Support, Profile).
     * Explicit ?store=otp|smm updates session; otherwise preserves the active store session.
     */
    public static function resolveStoreContext(string $default = 'smm'): string
    {
        $explicit = strtolower(trim((string)($_GET['store'] ?? ($_POST['store'] ?? ''))));
        if ($explicit === 'otp' || $explicit === 'smm') {
            self::setActiveStore($explicit);
            return $explicit;
        }
        return self::getActiveStore($default);
    }

    /**
     * Enforce SMM Store availability on SMM purchasing pages & API endpoints.
     * NEVER displays a maintenance page or maintenance message.
     */
    public static function requireSmmEnabled(bool $isJson = false): void
    {
        if (self::isSmmEnabled()) {
            return;
        }
        if ($isJson) {
            Helpers::jsonResponse([
                'ok'    => false,
                'error' => 'SMM Store is currently disabled.',
                'code'  => 'STORE_DISABLED',
            ], 403);
        }
        self::renderStoreUnavailable('smm');
    }

    /**
     * Enforce OTP Store availability on OTP purchasing pages & new-activation endpoints.
     * Note: Active activation tracking & history remain accessible even when OTP Store is OFF.
     * NEVER displays a maintenance page or maintenance message.
     */
    public static function requireOtpEnabled(bool $isJson = false): void
    {
        if (self::isOtpEnabled()) {
            return;
        }
        if ($isJson) {
            Helpers::jsonResponse([
                'ok'    => false,
                'error' => 'OTP Store is currently disabled.',
                'code'  => 'STORE_DISABLED',
            ], 403);
        }
        self::renderStoreUnavailable('otp');
    }

    /**
     * Clean, non-maintenance 404/unavailable response for direct URL access to a disabled store.
     */
    public static function renderStoreUnavailable(string $store): void
    {
        http_response_code(404);
        $siteName = Helpers::setting('site_name', 'SMM PRO');
        $user = Auth::user();
        $smmOn = self::isSmmEnabled();
        $otpOn = self::isOtpEnabled();
        $title = $store === 'otp' ? 'OTP Store Unavailable' : 'SMM Store Unavailable';
        $desc = $store === 'otp'
            ? 'New OTP activations are not open right now. Your existing account, wallet, active activations, history, and support tickets remain accessible.'
            : 'New SMM orders are not open right now. Your existing account, wallet, order history, and support tickets remain accessible.';

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . Validator::e($title . ' — ' . $siteName) . '</title>'
            . '<script src="https://cdn.tailwindcss.com"></script>'
            . '<link rel="stylesheet" href="/assets/css/app.css">'
            . '</head><body class="min-h-screen bg-[#F6F4FC] flex items-center justify-center p-5">'
            . '<div class="max-w-md w-full bg-white rounded-3xl shadow-xl shadow-purple-900/5 border border-purple-100 p-8 text-center">'
            . '<div class="w-14 h-14 mx-auto mb-4 rounded-2xl bg-purple-50 border border-purple-100 text-purple-600 flex items-center justify-center">'
            . '<svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>'
            . '</div>'
            . '<h1 class="text-xl font-extrabold text-slate-900 mb-2">' . Validator::e($title) . '</h1>'
            . '<p class="text-sm text-slate-600 leading-relaxed mb-6">' . Validator::e($desc) . '</p>'
            . '<div class="flex flex-wrap justify-center gap-2.5">';

        if ($store === 'smm' && $otpOn) {
            echo '<a href="/otp/" class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-purple-600 to-violet-600 text-white text-xs font-bold shadow-md hover:opacity-95 transition">Open OTP Store</a>';
        } elseif ($store === 'otp' && $smmOn) {
            echo '<a href="' . ($user ? '/user/dashboard.php' : '/') . '" class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-purple-600 to-violet-600 text-white text-xs font-bold shadow-md hover:opacity-95 transition">Go to SMM Store</a>';
        }

        if ($user) {
            if ($store === 'otp') {
                echo '<a href="/otp/activations.php" class="px-4 py-2.5 rounded-xl bg-purple-50 text-purple-700 text-xs font-bold hover:bg-purple-100 transition">Active Activations</a>';
                echo '<a href="/otp/history.php" class="px-4 py-2.5 rounded-xl bg-slate-100 text-slate-700 text-xs font-bold hover:bg-slate-200 transition">OTP History</a>';
            } else {
                echo '<a href="/user/orders.php" class="px-4 py-2.5 rounded-xl bg-purple-50 text-purple-700 text-xs font-bold hover:bg-purple-100 transition">Order History</a>';
            }
            echo '<a href="/user/add-funds.php" class="px-4 py-2.5 rounded-xl bg-slate-100 text-slate-700 text-xs font-bold hover:bg-slate-200 transition">Wallet</a>';
            echo '<a href="/user/tickets.php" class="px-4 py-2.5 rounded-xl bg-slate-100 text-slate-700 text-xs font-bold hover:bg-slate-200 transition">Support</a>';
        } else {
            echo '<a href="/login.php" class="px-5 py-2.5 rounded-xl bg-slate-100 text-slate-800 text-xs font-bold hover:bg-slate-200 transition">Sign In</a>';
        }

        echo '</div></div></body></html>';
        exit;
    }

    /**
     * Render the prominent "Need OTP? Tap Here!" pill button on the public SMM page (hidden when OTP Store is OFF).
     */
    public static function renderPublicOtpPill(string $extraClasses = ''): string
    {
        if (!self::isOtpEnabled()) {
            return '';
        }
        $href = '/store-switch.php?to=otp';
        return '<a href="' . Validator::e($href) . '" data-store-switch="otp" class="otp-switch-pill group inline-flex items-center gap-2.5 px-5 py-2.5 rounded-full bg-gradient-to-r from-violet-600 via-purple-600 to-fuchsia-600 text-white font-extrabold text-xs sm:text-sm shadow-lg shadow-purple-600/30 hover:shadow-xl hover:shadow-purple-600/40 hover:-translate-y-0.5 active:scale-95 transition-all ' . Validator::e($extraClasses) . '">'
            . '<span class="relative flex items-center justify-center w-6 h-6 rounded-full bg-white/20 shrink-0">'
            . '<svg class="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="2.5"/><path d="M9 10h6M9 14h4"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>'
            . '<span class="absolute -top-0.5 -right-0.5 w-2 h-2 rounded-full bg-emerald-300 animate-ping"></span>'
            . '<span class="absolute -top-0.5 -right-0.5 w-2 h-2 rounded-full bg-emerald-400"></span>'
            . '</span>'
            . '<span>Need OTP? Tap Here!</span>'
            . '<svg class="w-3.5 h-3.5 opacity-90 group-hover:translate-x-0.5 transition-transform" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 12h14M13 5l7 7-7 7"/></svg>'
            . '</a>';
    }

    /**
     * Repeat-safe runtime schema check so existing databases upgrade cleanly without white pages.
     */
    public static function ensureSchema(): void
    {
        $pdo = Database::pdo();

        $pdo->exec("CREATE TABLE IF NOT EXISTS `otp_providers` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(120) NOT NULL,
            `slug` VARCHAR(80) NOT NULL,
            `adapter_type` ENUM('fivesim','custom_json','custom_php') NOT NULL DEFAULT 'custom_json',
            `custom_adapter_class` VARCHAR(120) NULL,
            `base_url` VARCHAR(255) NOT NULL DEFAULT '',
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
            `currency_code` VARCHAR(10) NOT NULL DEFAULT 'USD',
            `exchange_rate` DECIMAL(16,6) NOT NULL DEFAULT 1.000000,
            `rate_mode` ENUM('auto','manual') NOT NULL DEFAULT 'auto',
            `balance` DECIMAL(16,4) NULL,
            `connection_status` ENUM('unknown','connected','failed') NOT NULL DEFAULT 'unknown',
            `last_balance_check` DATETIME NULL,
            `last_sync_at` DATETIME NULL,
            `last_availability_sync_at` DATETIME NULL,
            `last_error` TEXT NULL,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_otp_provider_slug` (`slug`),
            KEY `idx_otp_provider_status` (`status`, `adapter_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `otp_services` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(120) NOT NULL,
            `slug` VARCHAR(100) NOT NULL,
            `icon_key` VARCHAR(60) NOT NULL DEFAULT 'general',
            `description` TEXT NULL,
            `provider_id` INT UNSIGNED NULL,
            `provider_service_code` VARCHAR(100) NULL,
            `provider_price` DECIMAL(16,4) NULL,
            `provider_currency` VARCHAR(10) NULL,
            `selling_price` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
            `profit_margin` DECIMAL(8,2) NOT NULL DEFAULT 25.00,
            `fixed_profit` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
            `min_price` DECIMAL(16,4) NULL,
            `max_price` DECIMAL(16,4) NULL,
            `auto_price_update` TINYINT(1) NOT NULL DEFAULT 1,
            `last_price_sync_at` DATETIME NULL,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `otp_countries` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `otp_service_provider_mappings` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `service_id` INT UNSIGNED NOT NULL,
            `country_id` INT UNSIGNED NOT NULL,
            `provider_id` INT UNSIGNED NOT NULL,
            `provider_service_code` VARCHAR(100) NULL,
            `provider_country_code` VARCHAR(100) NULL,
            `provider_operator` VARCHAR(80) NOT NULL DEFAULT 'any',
            `provider_price` DECIMAL(16,4) NULL,
            `provider_cost_base` DECIMAL(16,4) NULL,
            `custom_selling_price` DECIMAL(16,4) NULL,
            `pricing_mode` ENUM('auto','fixed') NOT NULL DEFAULT 'auto',
            `profit_margin` DECIMAL(8,2) NULL,
            `fixed_profit` DECIMAL(16,4) NULL,
            `min_price` DECIMAL(16,4) NULL,
            `max_price` DECIMAL(16,4) NULL,
            `auto_price_update` TINYINT(1) NOT NULL DEFAULT 1,
            `is_available` TINYINT(1) NOT NULL DEFAULT 1,
            `available_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `availability_verified` TINYINT(1) NOT NULL DEFAULT 0,
            `last_price_sync_at` DATETIME NULL,
            `last_availability_sync_at` DATETIME NULL,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `otp_activations` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `activation_code` VARCHAR(32) NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `service_id` INT UNSIGNED NOT NULL,
            `country_id` INT UNSIGNED NOT NULL,
            `provider_id` INT UNSIGNED NULL,
            `provider_order_id` VARCHAR(120) NULL,
            `provider_operator` VARCHAR(80) NULL,
            `provider_cost_raw` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
            `phone_number` VARCHAR(40) NULL,
            `otp_code` VARCHAR(64) NULL,
            `sms_text` TEXT NULL,
            `sms_received_at` DATETIME NULL,
            `provider_cost` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
            `provider_currency` VARCHAR(10) NOT NULL DEFAULT 'RUB',
            `exchange_rate` DECIMAL(16,6) NOT NULL DEFAULT 1.000000,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `otp_provider_logs` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `provider_id` INT UNSIGNED NULL,
            `activation_id` BIGINT UNSIGNED NULL,
            `action` VARCHAR(60) NOT NULL,
            `endpoint` VARCHAR(255) NULL,
            `http_code` INT NULL,
            `response_time_ms` INT UNSIGNED NULL,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `otp_refunds` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Repeat-safe column upgrades on pre-existing OTP tables
        $addMissingColumns = function (string $table, array $colDefs) use ($pdo): void {
            try {
                $existing = [];
                foreach (Database::fetchAll("SHOW COLUMNS FROM `{$table}`") as $c) {
                    $existing[$c['Field']] = true;
                }
                foreach ($colDefs as $col => $alterSql) {
                    if (empty($existing[$col])) {
                        $pdo->exec("ALTER TABLE `{$table}` {$alterSql}");
                    }
                }
            } catch (Throwable $e) {
                Logger::errorFile("StoreManager::ensureSchema {$table} alter warning: " . $e->getMessage());
            }
        };

        $addMissingColumns('otp_providers', [
            'connection_status'         => "ADD COLUMN `connection_status` ENUM('unknown','connected','failed') NOT NULL DEFAULT 'unknown' AFTER `balance`",
            'last_sync_at'              => "ADD COLUMN `last_sync_at` DATETIME NULL AFTER `last_balance_check`",
            'last_availability_sync_at' => "ADD COLUMN `last_availability_sync_at` DATETIME NULL AFTER `last_sync_at`",
        ]);
        $addMissingColumns('otp_services', [
            'provider_price'     => "ADD COLUMN `provider_price` DECIMAL(16,4) NULL AFTER `provider_service_code`",
            'provider_currency'  => "ADD COLUMN `provider_currency` VARCHAR(10) NULL AFTER `provider_price`",
            'fixed_profit'       => "ADD COLUMN `fixed_profit` DECIMAL(16,4) NOT NULL DEFAULT 0.0000 AFTER `profit_margin`",
            'auto_price_update'  => "ADD COLUMN `auto_price_update` TINYINT(1) NOT NULL DEFAULT 1 AFTER `max_price`",
            'last_price_sync_at' => "ADD COLUMN `last_price_sync_at` DATETIME NULL AFTER `auto_price_update`",
        ]);
        $addMissingColumns('otp_service_provider_mappings', [
            'provider_price'            => "ADD COLUMN `provider_price` DECIMAL(16,4) NULL AFTER `provider_operator`",
            'provider_cost_base'        => "ADD COLUMN `provider_cost_base` DECIMAL(16,4) NULL AFTER `provider_price`",
            'pricing_mode'              => "ADD COLUMN `pricing_mode` ENUM('auto','fixed') NOT NULL DEFAULT 'auto' AFTER `custom_selling_price`",
            'profit_margin'             => "ADD COLUMN `profit_margin` DECIMAL(8,2) NULL AFTER `pricing_mode`",
            'fixed_profit'              => "ADD COLUMN `fixed_profit` DECIMAL(16,4) NULL AFTER `profit_margin`",
            'min_price'                 => "ADD COLUMN `min_price` DECIMAL(16,4) NULL AFTER `fixed_profit`",
            'max_price'                 => "ADD COLUMN `max_price` DECIMAL(16,4) NULL AFTER `min_price`",
            'auto_price_update'         => "ADD COLUMN `auto_price_update` TINYINT(1) NOT NULL DEFAULT 1 AFTER `max_price`",
            'available_count'           => "ADD COLUMN `available_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `is_available`",
            'availability_verified'     => "ADD COLUMN `availability_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `available_count`",
            'last_price_sync_at'        => "ADD COLUMN `last_price_sync_at` DATETIME NULL AFTER `availability_verified`",
            'last_availability_sync_at' => "ADD COLUMN `last_availability_sync_at` DATETIME NULL AFTER `last_price_sync_at`",
        ]);
        $addMissingColumns('otp_activations', [
            'source'            => "ADD COLUMN `source` ENUM('direct','api') NOT NULL DEFAULT 'direct' AFTER `user_id`",
            'api_key_id'        => "ADD COLUMN `api_key_id` INT UNSIGNED NULL AFTER `source`",
            'provider_cost_raw' => "ADD COLUMN `provider_cost_raw` DECIMAL(16,4) NOT NULL DEFAULT 0.0000 AFTER `provider_operator`",
            'exchange_rate'     => "ADD COLUMN `exchange_rate` DECIMAL(16,6) NOT NULL DEFAULT 1.000000 AFTER `provider_currency`",
        ]);
        $addMissingColumns('otp_provider_logs', [
            'response_time_ms' => "ADD COLUMN `response_time_ms` INT UNSIGNED NULL AFTER `http_code`",
        ]);

        // Ensure Batch Import Jobs & OTP Reseller API tables
        $pdo->exec("CREATE TABLE IF NOT EXISTS `otp_import_jobs` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `otp_api_keys` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `otp_api_pricing_rules` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `otp_api_logs` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `otp_api_idempotency` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Seed RUB currency if missing so 5SIM RUB->Base FX conversion works reliably
        try {
            $pdo->exec("INSERT IGNORE INTO `currencies` (`code`, `name`, `symbol`, `exchange_rate`, `is_base`, `is_manual_override`, `rate_source`, `update_status`, `status`) VALUES ('RUB', 'Russian Ruble', '₽', 1.150000, 0, 0, 'initial_seed', 'manual', 'inactive')");
            Currency::clearCache();
        } catch (Throwable $e) {
            Logger::errorFile('StoreManager::ensureSchema RUB currency seed warning: ' . $e->getMessage());
        }

        // Safely extend support_tickets & notifications if columns don't exist yet
        $ticketCols = [];
        try {
            $cols = Database::fetchAll("SHOW COLUMNS FROM support_tickets");
            foreach ($cols as $c) {
                $ticketCols[$c['Field']] = true;
            }
            if (empty($ticketCols['store_type'])) {
                $pdo->exec("ALTER TABLE `support_tickets` ADD COLUMN `store_type` ENUM('smm','otp') NOT NULL DEFAULT 'smm' AFTER `ticket_number`, ADD INDEX `idx_ticket_store_type` (`store_type`, `status`)");
            }
            if (empty($ticketCols['ticket_category'])) {
                $pdo->exec("ALTER TABLE `support_tickets` ADD COLUMN `ticket_category` VARCHAR(60) NULL AFTER `department`");
            }
            if (empty($ticketCols['otp_activation_id'])) {
                $pdo->exec("ALTER TABLE `support_tickets` ADD COLUMN `otp_activation_id` BIGINT UNSIGNED NULL AFTER `order_id`, ADD INDEX `idx_ticket_otp_activation` (`otp_activation_id`)");
            }
            if (empty($ticketCols['internal_notes'])) {
                $pdo->exec("ALTER TABLE `support_tickets` ADD COLUMN `internal_notes` TEXT NULL AFTER `status`");
            }
            $pdo->exec("ALTER TABLE `support_tickets` MODIFY COLUMN `status` ENUM('open','pending','answered','waiting_user','resolved','closed') NOT NULL DEFAULT 'open'");
        } catch (Throwable $e) {
            Logger::errorFile('StoreManager::ensureSchema support_tickets alter warning: ' . $e->getMessage());
        }

        try {
            $pdo->exec("ALTER TABLE `notifications` MODIFY COLUMN `type` ENUM('order','payment','ticket','announcement','system','sale','otp','otp_refund') NOT NULL DEFAULT 'system'");
        } catch (Throwable $e) {
            Logger::errorFile('StoreManager::ensureSchema notifications alter warning: ' . $e->getMessage());
        }

        $defaults = [
            'smm_store_enabled'               => ['1', 'stores'],
            'otp_store_enabled'               => ['1', 'stores'],
            'otp_default_provider_id'         => ['', 'otp'],
            'otp_polling_interval'            => ['5', 'otp'],
            'otp_max_active_per_user'         => ['5', 'otp'],
            'otp_max_daily_per_user'          => ['50', 'otp'],
            'otp_min_wallet_balance'          => ['0.00', 'otp'],
            'otp_default_timeout_minutes'     => ['15', 'otp'],
            'otp_auto_refund_expired'         => ['1', 'otp'],
            'otp_auto_refund_canceled'        => ['1', 'otp'],
            'otp_allow_user_cancel'           => ['1', 'otp'],
            'otp_api_timeout'                 => ['20', 'otp'],
            'otp_show_full_sms'               => ['1', 'otp'],
            'otp_notify_events'               => ['1', 'otp'],
            'otp_provider_error_mode'         => ['friendly', 'otp'],
            'otp_default_profit_margin'       => ['25.00', 'otp'],
            // OTP Reseller API defaults
            'otp_api_enabled'                 => ['1', 'otp_api'],
            'otp_api_version'                 => ['v1', 'otp_api'],
            'otp_api_global_rate_limit'       => ['120', 'otp_api'],
            'otp_api_default_user_rate_limit' => ['60', 'otp_api'],
            'otp_api_max_daily_orders'        => ['500', 'otp_api'],
            'otp_api_max_active_activations'  => ['25', 'otp_api'],
            'otp_api_allowed_operations'      => ['balance,services,countries,prices,create,status,cancel,complete,history', 'otp_api'],
            'otp_api_ip_restriction_enabled'  => ['0', 'otp_api'],
            'otp_api_log_retention_days'      => ['30', 'otp_api'],
            'otp_api_global_markup_type'      => ['percent', 'otp_api'],
            'otp_api_global_markup_percent'   => ['0.00', 'otp_api'],
            'otp_api_global_fixed_profit'     => ['0.0000', 'otp_api'],
            'otp_api_global_min_price'        => ['0.0000', 'otp_api'],
        ];
        foreach ($defaults as $k => [$v, $grp]) {
            Database::execute(
                "INSERT IGNORE INTO settings (setting_key, setting_value, setting_group) VALUES (?, ?, ?)",
                [$k, $v, $grp]
            );
        }
    }
}
