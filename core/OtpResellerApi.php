<?php
/**
 * OTP Reseller API Core Engine
 * - Separate from existing SMM Reseller API (/api/v2.php & users.api_key)
 * - Cryptographically secure API keys stored ONLY as SHA-256 hashes (one-time reveal on generation)
 * - Global & per-user controls, IP allowlist, sliding-window rate limiting
 * - Server-side reseller pricing rules (Service+Country > Service > Country > Global + per-user markup)
 * - Idempotency key storage & sanitized API request logging (never logs raw keys or OTP codes)
 */

class OtpResellerApi
{
    public const KEY_PREFIX_MARKER = 'otp_live_';

    /**
     * Generate or regenerate a user's OTP Reseller API key.
     * Immediately invalidates any previous key. Returns raw key ONCE (never stored in plaintext).
     */
    public static function generateKeyForUser(int $userId, bool $preserveLimits = true): array
    {
        $user = Database::fetch("SELECT id, username, status FROM users WHERE id = ? LIMIT 1", [$userId]);
        if (!$user) {
            return ['ok' => false, 'error' => 'User account not found.'];
        }

        $rawKey = self::KEY_PREFIX_MARKER . bin2hex(random_bytes(24)); // 57 chars total, 192 bits entropy
        $prefix = substr($rawKey, 0, 16); // e.g. otp_live_a1b2c3d
        $hash = hash('sha256', $rawKey);

        $existing = Database::fetch("SELECT * FROM otp_api_keys WHERE user_id = ? LIMIT 1", [$userId]);
        if ($existing) {
            if ($preserveLimits) {
                Database::execute(
                    "UPDATE otp_api_keys SET key_prefix = ?, key_hash = ?, status = 'active' WHERE id = ?",
                    [$prefix, $hash, (int)$existing['id']]
                );
            } else {
                Database::execute(
                    "UPDATE otp_api_keys
                     SET key_prefix = ?, key_hash = ?, status = 'active',
                         rate_limit_per_min = NULL, daily_purchase_limit = NULL,
                         max_active_activations = NULL, allowed_ips = NULL, custom_markup_percent = NULL
                     WHERE id = ?",
                    [$prefix, $hash, (int)$existing['id']]
                );
            }
            $keyId = (int)$existing['id'];
        } else {
            $keyId = (int)Database::insert(
                "INSERT INTO otp_api_keys (user_id, key_prefix, key_hash, status) VALUES (?, ?, ?, 'active')",
                [$userId, $prefix, $hash]
            );
        }

        return [
            'ok'         => true,
            'key_id'     => $keyId,
            'raw_key'    => $rawKey,
            'key_prefix' => $prefix,
            'masked_key' => $prefix . str_repeat('•', 24),
        ];
    }

    /**
     * Fetch a user's API key metadata (never returns raw key or hash).
     */
    public static function getKeyRecordForUser(int $userId): ?array
    {
        $row = Database::fetch(
            "SELECT id, user_id, key_prefix, status, rate_limit_per_min, daily_purchase_limit,
                    max_active_activations, allowed_ips, custom_markup_percent,
                    last_used_at, last_used_ip, total_requests, created_at, updated_at
             FROM otp_api_keys WHERE user_id = ? LIMIT 1",
            [$userId]
        );
        if (!$row) {
            return null;
        }
        $row['masked_key'] = $row['key_prefix'] . '••••••••••••••••••••••••';
        return $row;
    }

    /**
     * Extract API key from Authorization: Bearer, X-API-Key, or fallback api_key param.
     */
    public static function extractApiKeyFromRequest(array $input = []): string
    {
        $authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if ($authHeader !== '' && stripos($authHeader, 'Bearer ') === 0) {
            return trim(substr($authHeader, 7));
        }
        $xKey = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
        if ($xKey !== '') {
            return $xKey;
        }
        if (isset($input['api_key']) && is_string($input['api_key'])) {
            return trim($input['api_key']);
        }
        return '';
    }

    /**
     * Authenticate an incoming OTP Reseller API request.
     * Validates: Global API status -> Key hash -> User active -> Key active -> IP allowlist -> Operation -> Rate limits.
     */
    public static function authenticateRequest(string $rawKey, string $operation, string $clientIp): array
    {
        // 1. Global OTP API toggle (blocks new API requests without disabling Direct OTP Store or existing tracking)
        if (Helpers::setting('otp_api_enabled', '1') !== '1') {
            return [
                'ok'          => false,
                'http_status' => 503,
                'error_code'  => 'API_DISABLED',
                'message'     => 'OTP Reseller API is temporarily disabled by the administrator.',
            ];
        }

        // 2. Allowed operations check
        $allowedOpsRaw = (string)Helpers::setting('otp_api_allowed_operations', 'balance,services,countries,prices,create,status,cancel,complete,history');
        $allowedOps = array_values(array_filter(array_map('trim', explode(',', strtolower($allowedOpsRaw)))));
        if ($operation !== '' && !in_array(strtolower($operation), $allowedOps, true)) {
            return [
                'ok'          => false,
                'http_status' => 403,
                'error_code'  => 'OPERATION_DISABLED',
                'message'     => 'This API operation is currently disabled.',
            ];
        }

        if ($rawKey === '' || strlen($rawKey) < 20 || strlen($rawKey) > 128) {
            return [
                'ok'          => false,
                'http_status' => 401,
                'error_code'  => 'INVALID_API_KEY',
                'message'     => 'Missing or invalid OTP Reseller API key.',
            ];
        }

        $hash = hash('sha256', $rawKey);
        $record = Database::fetch(
            "SELECT k.*, u.username, u.email, u.status AS user_status, u.wallet_balance
             FROM otp_api_keys k
             JOIN users u ON u.id = k.user_id
             WHERE k.key_hash = ? LIMIT 1",
            [$hash]
        );

        if (!$record || !hash_equals((string)$record['key_hash'], $hash)) {
            return [
                'ok'          => false,
                'http_status' => 401,
                'error_code'  => 'INVALID_API_KEY',
                'message'     => 'Invalid OTP Reseller API key.',
            ];
        }

        if (($record['user_status'] ?? '') !== 'active') {
            return [
                'ok'          => false,
                'http_status' => 403,
                'error_code'  => 'API_DISABLED',
                'message'     => 'Your user account is suspended or inactive.',
                'user_id'     => (int)$record['user_id'],
                'api_key_id'  => (int)$record['id'],
            ];
        }

        if (($record['status'] ?? '') !== 'active') {
            return [
                'ok'          => false,
                'http_status' => 403,
                'error_code'  => 'API_DISABLED',
                'message'     => 'OTP Reseller API access is disabled for this account.',
                'user_id'     => (int)$record['user_id'],
                'api_key_id'  => (int)$record['id'],
            ];
        }

        // 3. IP Allowlist check (enforced whenever user configured allowed_ips OR global IP restriction is ON)
        $globalIpEnforced = Helpers::setting('otp_api_ip_restriction_enabled', '0') === '1';
        $allowedIpsText = trim((string)($record['allowed_ips'] ?? ''));
        if ($allowedIpsText !== '' || $globalIpEnforced) {
            $ips = self::parseIpList($allowedIpsText);
            if ($globalIpEnforced && empty($ips)) {
                return [
                    'ok'          => false,
                    'http_status' => 403,
                    'error_code'  => 'IP_NOT_ALLOWED',
                    'message'     => 'IP allowlisting is required. Please add your server IP in your OTP Reseller API dashboard.',
                    'user_id'     => (int)$record['user_id'],
                    'api_key_id'  => (int)$record['id'],
                ];
            }
            if (!empty($ips) && !self::ipMatchesAllowlist($clientIp, $ips)) {
                return [
                    'ok'          => false,
                    'http_status' => 403,
                    'error_code'  => 'IP_NOT_ALLOWED',
                    'message'     => 'Request IP address is not in your API key allowlist.',
                    'user_id'     => (int)$record['user_id'],
                    'api_key_id'  => (int)$record['id'],
                ];
            }
        }

        // 4. Sliding-window rate limiting (per-user & global per minute)
        $userLimit = !empty($record['rate_limit_per_min'])
            ? (int)$record['rate_limit_per_min']
            : max(1, (int)Helpers::setting('otp_api_default_user_rate_limit', '60'));
        $globalLimit = max(1, (int)Helpers::setting('otp_api_global_rate_limit', '120'));

        $userRecentCount = (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM otp_api_logs WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND)",
            [(int)$record['user_id']]
        );
        if ($userRecentCount >= $userLimit) {
            return [
                'ok'          => false,
                'http_status' => 429,
                'error_code'  => 'RATE_LIMIT_EXCEEDED',
                'message'     => "Rate limit exceeded ({$userLimit} requests/minute). Please slow down.",
                'user_id'     => (int)$record['user_id'],
                'api_key_id'  => (int)$record['id'],
            ];
        }

        $globalRecentCount = (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM otp_api_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND)"
        );
        if ($globalRecentCount >= $globalLimit) {
            return [
                'ok'          => false,
                'http_status' => 429,
                'error_code'  => 'RATE_LIMIT_EXCEEDED',
                'message'     => 'API rate limit temporarily reached. Please retry shortly.',
                'user_id'     => (int)$record['user_id'],
                'api_key_id'  => (int)$record['id'],
            ];
        }

        // Update usage metadata
        Database::execute(
            "UPDATE otp_api_keys SET last_used_at = NOW(), last_used_ip = ?, total_requests = total_requests + 1 WHERE id = ?",
            [substr($clientIp, 0, 45), (int)$record['id']]
        );

        return [
            'ok'         => true,
            'user_id'    => (int)$record['user_id'],
            'api_key_id' => (int)$record['id'],
            'key_record' => $record,
        ];
    }

    /**
     * Authoritative Server-Side Reseller Pricing Calculator.
     * Precedence:
     *  1. Active rule matching `service_country`
     *  2. Active rule matching `service`
     *  3. Active rule matching `country`
     *  4. Active rule matching `global`
     *  5. Global settings (`otp_api_global_markup_*`)
     * Plus optional per-user `custom_markup_percent` adjustment (can be negative discount or positive surcharge).
     * Never accepts or trusts client-submitted prices.
     */
    public static function calculateResellerPrice(
        string $baseSellingPrice,
        string $providerCostBase,
        int $serviceId,
        int $countryId,
        ?array $keyRecord = null
    ): array {
        $baseSelling = Currency::normalize($baseSellingPrice, 4);
        $provCost = Currency::normalize($providerCostBase, 4);

        // Fetch highest-priority active rule
        $rule = Database::fetch(
            "SELECT * FROM otp_api_pricing_rules
             WHERE status = 'active'
               AND (
                    (scope = 'service_country' AND service_id = ? AND country_id = ?)
                 OR (scope = 'service' AND service_id = ? AND country_id IS NULL)
                 OR (scope = 'country' AND country_id = ? AND service_id IS NULL)
                 OR (scope = 'global' AND service_id IS NULL AND country_id IS NULL)
               )
             ORDER BY FIELD(scope, 'service_country', 'service', 'country', 'global'), id DESC
             LIMIT 1",
            [$serviceId, $countryId, $serviceId, $countryId]
        );

        $price = $baseSelling;
        $appliedRule = 'store_default';

        if ($rule) {
            $appliedRule = 'rule_' . $rule['scope'] . '_#' . (int)$rule['id'];
            $pType = (string)$rule['pricing_type'];
            if ($pType === 'fixed_price' && !empty($rule['fixed_price']) && Currency::cmp((string)$rule['fixed_price'], '0', 4) > 0) {
                $price = Currency::normalize((string)$rule['fixed_price'], 4);
            } elseif ($pType === 'fixed_profit') {
                $ref = Currency::cmp($provCost, '0', 4) > 0 ? $provCost : $baseSelling;
                $price = Currency::add($ref, Currency::normalize((string)$rule['fixed_profit'], 4), 4);
            } else {
                // percent markup applied on base selling price (or cost if baseSelling is 0)
                $pct = Currency::normalize((string)$rule['markup_percent'], 2);
                $mult = Currency::div(Currency::add('100', $pct, 4), '100', 6);
                $price = Currency::mul($baseSelling, $mult, 4);
                if (Currency::cmp((string)$rule['fixed_profit'], '0', 4) !== 0) {
                    $price = Currency::add($price, Currency::normalize((string)$rule['fixed_profit'], 4), 4);
                }
            }
            if (!empty($rule['min_selling_price']) && Currency::cmp((string)$rule['min_selling_price'], '0', 4) > 0) {
                if (Currency::cmp($price, (string)$rule['min_selling_price'], 4) < 0) {
                    $price = Currency::normalize((string)$rule['min_selling_price'], 4);
                }
            }
        } else {
            // Apply global reseller API markup settings
            $gType = (string)Helpers::setting('otp_api_global_markup_type', 'percent');
            $gPct = Currency::normalize((string)Helpers::setting('otp_api_global_markup_percent', '0.00'), 2);
            $gFixed = Currency::normalize((string)Helpers::setting('otp_api_global_fixed_profit', '0.0000'), 4);
            $gMin = Currency::normalize((string)Helpers::setting('otp_api_global_min_price', '0.0000'), 4);

            if ($gType === 'fixed_profit' && Currency::cmp($gFixed, '0', 4) > 0) {
                $price = Currency::add($baseSelling, $gFixed, 4);
                $appliedRule = 'global_fixed_profit';
            } elseif (Currency::cmp($gPct, '0', 2) !== 0) {
                $mult = Currency::div(Currency::add('100', $gPct, 4), '100', 6);
                $price = Currency::mul($baseSelling, $mult, 4);
                $appliedRule = 'global_percent';
            }
            if (Currency::cmp($gMin, '0', 4) > 0 && Currency::cmp($price, $gMin, 4) < 0) {
                $price = $gMin;
            }
        }

        // Apply optional per-user custom markup % (e.g. -5.00 for VIP reseller discount, +10.00 for surcharge)
        if ($keyRecord && isset($keyRecord['custom_markup_percent']) && $keyRecord['custom_markup_percent'] !== null && $keyRecord['custom_markup_percent'] !== '') {
            $uPct = Currency::normalize((string)$keyRecord['custom_markup_percent'], 2);
            if (Currency::cmp($uPct, '0', 2) !== 0) {
                $mult = Currency::div(Currency::add('100', $uPct, 4), '100', 6);
                $price = Currency::mul($price, $mult, 4);
                $appliedRule .= '+user_markup';
            }
        }

        // Never allow negative or zero reseller price if base price was positive
        if (Currency::cmp($price, '0.0001', 4) < 0) {
            $price = Currency::cmp($baseSelling, '0', 4) > 0 ? $baseSelling : '0.0001';
        }

        // Enforce global minimum price floor if set
        $globalMin = Currency::normalize((string)Helpers::setting('otp_api_global_min_price', '0.0000'), 4);
        if (Currency::cmp($globalMin, '0', 4) > 0 && Currency::cmp($price, $globalMin, 4) < 0) {
            $price = $globalMin;
        }

        return [
            'reseller_price' => Currency::normalize($price, 4),
            'base_price'     => $baseSelling,
            'applied_rule'   => $appliedRule,
        ];
    }

    /**
     * Check idempotency cache for POST /activation/create.
     */
    public static function checkIdempotency(int $userId, string $idemKey, string $requestHash): ?array
    {
        if ($idemKey === '') {
            return null;
        }
        $row = Database::fetch(
            "SELECT * FROM otp_api_idempotency
             WHERE user_id = ? AND idempotency_key = ? AND expires_at > NOW()
             LIMIT 1",
            [$userId, substr($idemKey, 0, 128)]
        );
        if (!$row) {
            return null;
        }
        return [
            'matched_hash'    => hash_equals((string)$row['request_hash'], $requestHash),
            'activation_id'   => $row['activation_id'] ? (int)$row['activation_id'] : null,
            'response_status' => (int)$row['response_status'],
            'response_json'   => (string)($row['response_json'] ?? ''),
        ];
    }

    /**
     * Store idempotency result (24h TTL).
     */
    public static function storeIdempotency(
        int $userId,
        string $idemKey,
        string $requestHash,
        ?int $activationId,
        int $httpStatus,
        array $responsePayload
    ): void {
        if ($idemKey === '') {
            return;
        }
        try {
            Database::execute(
                "INSERT INTO otp_api_idempotency
                 (user_id, idempotency_key, request_hash, activation_id, response_status, response_json, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))
                 ON DUPLICATE KEY UPDATE
                   activation_id = VALUES(activation_id),
                   response_status = VALUES(response_status),
                   response_json = VALUES(response_json),
                   expires_at = VALUES(expires_at)",
                [
                    $userId,
                    substr($idemKey, 0, 128),
                    $requestHash,
                    $activationId,
                    $httpStatus,
                    json_encode($responsePayload, JSON_UNESCAPED_UNICODE),
                ]
            );
        } catch (Throwable $e) {
            Logger::errorFile('OtpResellerApi::storeIdempotency error: ' . $e->getMessage());
        }
    }

    /**
     * Log sanitized API request metadata (NEVER logs raw API keys, auth headers, or OTP SMS codes).
     */
    public static function logRequest(
        ?int $userId,
        ?int $apiKeyId,
        string $endpoint,
        string $method,
        int $httpStatus,
        int $responseTimeMs,
        bool $success,
        ?string $errorCode,
        array $meta,
        string $ipAddress
    ): void {
        $safeMeta = [];
        $blockedKeys = ['api_key', 'key', 'token', 'authorization', 'password', 'secret', 'otp', 'otp_code', 'sms', 'sms_text'];
        foreach ($meta as $k => $v) {
            $kl = strtolower((string)$k);
            if (in_array($kl, $blockedKeys, true)) {
                continue;
            }
            if (is_scalar($v) || $v === null) {
                $safeMeta[$k] = is_string($v) ? substr($v, 0, 120) : $v;
            }
        }

        try {
            Database::insert(
                "INSERT INTO otp_api_logs
                 (user_id, api_key_id, endpoint, http_method, http_status, response_time_ms,
                  success, error_code, request_meta_json, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $userId,
                    $apiKeyId,
                    substr($endpoint, 0, 120),
                    substr(strtoupper($method), 0, 10),
                    $httpStatus,
                    max(0, $responseTimeMs),
                    $success ? 1 : 0,
                    $errorCode ? substr($errorCode, 0, 60) : null,
                    json_encode($safeMeta, JSON_UNESCAPED_UNICODE),
                    substr($ipAddress, 0, 45),
                    substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
                ]
            );
        } catch (Throwable $e) {
            Logger::errorFile('OtpResellerApi::logRequest error: ' . $e->getMessage());
        }
    }

    /**
     * Prune expired idempotency records and old API logs per configured retention days.
     */
    public static function pruneLogs(?int $daysOverride = null): int
    {
        $days = max(1, (int)($daysOverride ?? Helpers::setting('otp_api_log_retention_days', '30')));
        Database::execute("DELETE FROM otp_api_idempotency WHERE expires_at < NOW()");
        return Database::execute(
            "DELETE FROM otp_api_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
    }

    public static function parseIpList(string $raw): array
    {
        $parts = preg_split('/[\r\n,;]+/', $raw) ?: [];
        $valid = [];
        foreach ($parts as $p) {
            $ip = trim($p);
            if ($ip === '') continue;
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $valid[] = $ip;
            }
        }
        return array_values(array_unique($valid));
    }

    public static function ipMatchesAllowlist(string $clientIp, array $allowedIps): bool
    {
        $clientIp = trim($clientIp);
        if ($clientIp === '') {
            return false;
        }
        foreach ($allowedIps as $ip) {
            if (strcasecmp($clientIp, $ip) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Helper to determine the user's effective API active activation & daily limits.
     */
    public static function getEffectiveLimits(array $keyRecord): array
    {
        $maxActive = !empty($keyRecord['max_active_activations'])
            ? (int)$keyRecord['max_active_activations']
            : max(1, (int)Helpers::setting('otp_api_max_active_activations', '25'));
        $maxDaily = !empty($keyRecord['daily_purchase_limit'])
            ? (int)$keyRecord['daily_purchase_limit']
            : max(1, (int)Helpers::setting('otp_api_max_daily_orders', '500'));
        $ratePerMin = !empty($keyRecord['rate_limit_per_min'])
            ? (int)$keyRecord['rate_limit_per_min']
            : max(1, (int)Helpers::setting('otp_api_default_user_rate_limit', '60'));

        return [
            'max_active'   => $maxActive,
            'max_daily'    => $maxDaily,
            'rate_per_min' => $ratePerMin,
        ];
    }
}
