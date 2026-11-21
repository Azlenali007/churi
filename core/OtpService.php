<?php
/**
 * OTP Activation Engine
 * - Direct One-Time Activation Purchasing (Atomic Shared Wallet Debit + Provider Dispatch)
 * - Safe Failure Reconciliation & Exact-Once Refund Management
 * - Live Activation Status Polling, Expiry Management & Unified Notifications
 */

class OtpService
{
    /**
     * Fetch enabled OTP services that have an active provider assigned.
     */
    public static function getEnabledServices(string $search = '', bool $popularOnly = false, int $limit = 100): array
    {
        $sql = "SELECT s.*, p.name AS provider_name, p.adapter_type, p.currency_code AS provider_currency, p.status AS provider_status
                FROM otp_services s
                JOIN otp_providers p ON p.id = s.provider_id
                WHERE s.status = 'active' AND p.status = 'active'";
        $params = [];
        if ($popularOnly) {
            $sql .= " AND s.is_popular = 1";
        }
        if ($search !== '') {
            $sql .= " AND (s.name LIKE ? OR s.slug LIKE ?)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        $sql .= " ORDER BY s.is_popular DESC, s.sort_order ASC, s.name ASC LIMIT " . max(1, min(200, $limit));
        return Database::fetchAll($sql, $params);
    }

    /**
     * Fetch enabled countries with live availability and selling price for a specific service.
     */
    public static function getCountriesForService(int $serviceId, bool $queryLiveProvider = false): array
    {
        $service = Database::fetch(
            "SELECT s.*, p.id AS prov_id, p.name AS prov_name, p.adapter_type, p.base_url, p.api_key_enc,
                    p.auth_type, p.auth_header_name, p.auth_param_name, p.request_method,
                    p.endpoints_json, p.request_params_json, p.response_map_json, p.status_map_json,
                    p.timeout_seconds, p.currency_code AS prov_currency, p.exchange_rate AS prov_ex_rate,
                    p.rate_mode AS prov_rate_mode, p.status AS prov_status
             FROM otp_services s
             JOIN otp_providers p ON p.id = s.provider_id
             WHERE s.id = ? AND s.status = 'active' AND p.status = 'active' LIMIT 1",
            [$serviceId]
        );
        if (!$service) {
            return ['ok' => false, 'error' => 'Selected OTP service is unavailable.', 'countries' => []];
        }

        $countries = Database::fetchAll(
            "SELECT c.*, m.id AS map_id, m.provider_service_code AS map_service_code, m.provider_country_code AS map_country_code,
                    m.provider_operator AS map_operator, m.provider_price AS map_provider_price,
                    m.provider_cost_base AS map_provider_cost_base, m.custom_selling_price,
                    m.pricing_mode AS map_pricing_mode, m.profit_margin AS map_profit_margin,
                    m.fixed_profit AS map_fixed_profit, m.min_price AS map_min_price, m.max_price AS map_max_price,
                    m.auto_price_update AS map_auto_price_update, m.is_available AS map_available,
                    m.available_count AS map_available_count, m.availability_verified AS map_availability_verified,
                    m.last_availability_sync_at AS map_last_avail_sync, m.status AS map_status
             FROM otp_countries c
             LEFT JOIN otp_service_provider_mappings m
               ON m.country_id = c.id AND m.service_id = ? AND m.provider_id = ?
             WHERE c.status = 'active'
               AND (m.id IS NULL OR (m.status = 'active' AND m.is_available = 1))
             ORDER BY c.sort_order ASC, c.name ASC",
            [$serviceId, (int)$service['prov_id']]
        );

        $adapter = null;
        if ($queryLiveProvider) {
            try {
                $adapter = OtpProviderFactory::make(self::extractProviderRow($service));
            } catch (Throwable $e) {
                $adapter = null;
            }
        }

        $out = [];
        foreach ($countries as $c) {
            $provCountry = (string)($c['map_country_code'] ?: $c['default_provider_code']);
            $provService = (string)($c['map_service_code'] ?: ($service['provider_service_code'] ?: $service['slug']));
            $operator = (string)($c['map_operator'] ?: 'any');

            $hasMapping = !empty($c['map_id']);
            $verified = $hasMapping ? ((int)($c['map_availability_verified'] ?? 0) === 1) : false;
            $stock = $hasMapping && $verified ? (int)($c['map_available_count'] ?? 0) : null;
            $available = $hasMapping
                ? ((int)$c['map_available'] === 1 && (!$verified || (int)$c['map_available_count'] > 0))
                : true;
            $providerCost = isset($c['map_provider_price']) && $c['map_provider_price'] !== null
                ? (string)$c['map_provider_price']
                : (isset($service['provider_price']) && $service['provider_price'] !== null ? (string)$service['provider_price'] : null);

            if ($adapter !== null && count($countries) <= 15) {
                $liveInfo = $adapter->getAvailabilityAndPrice($provCountry, $provService, $operator);
                if (!empty($liveInfo['ok'])) {
                    $available = !empty($liveInfo['available']);
                    $verified = !empty($liveInfo['verified']);
                    $stock = $liveInfo['count'];
                    if ($liveInfo['cost'] !== null) {
                        $providerCost = (string)$liveInfo['cost'];
                    }
                    if (!empty($liveInfo['operator'])) {
                        $operator = (string)$liveInfo['operator'];
                    }
                } else {
                    $available = false;
                }
            }

            $priceCalc = self::calculateSellingPrice($service, $c, $providerCost);
            $out[] = [
                'country_id'            => (int)$c['id'],
                'name'                  => (string)$c['name'],
                'iso_code'              => (string)$c['iso_code'],
                'dial_code'             => (string)($c['dial_code'] ?? ''),
                'flag_emoji'            => (string)($c['flag_emoji'] ?? ''),
                'provider_code'         => $provCountry,
                'operator'              => $operator,
                'available'             => $available,
                'availability_verified' => $verified,
                'stock'                 => $stock,
                'selling_price'         => $priceCalc['selling_price'],
                'provider_cost'         => $priceCalc['provider_cost_base'],
                'provider_name'         => (string)$service['prov_name'],
            ];
        }

        return ['ok' => true, 'service' => $service, 'countries' => $out];
    }

    /**
     * Live single service + country quote & availability check before purchase.
     */
    public static function getLiveQuote(int $serviceId, int $countryId, ?string $operatorOverride = null): array
    {
        $service = Database::fetch(
            "SELECT s.*, p.id AS prov_id, p.name AS prov_name, p.adapter_type, p.custom_adapter_class,
                    p.base_url, p.api_key_enc, p.auth_type, p.auth_header_name, p.auth_param_name,
                    p.request_method, p.endpoints_json, p.request_params_json, p.response_map_json,
                    p.status_map_json, p.timeout_seconds, p.polling_interval_seconds,
                    p.currency_code AS prov_currency, p.exchange_rate AS prov_ex_rate,
                    p.rate_mode AS prov_rate_mode, p.status AS prov_status
             FROM otp_services s
             JOIN otp_providers p ON p.id = s.provider_id
             WHERE s.id = ? AND s.status = 'active' AND p.status = 'active' LIMIT 1",
            [$serviceId]
        );
        if (!$service) {
            return ['ok' => false, 'error_code' => 'INVALID_SERVICE', 'error' => 'Selected OTP service is unavailable or inactive.'];
        }

        $country = Database::fetch(
            "SELECT c.*, m.id AS map_id, m.provider_service_code AS map_service_code, m.provider_country_code AS map_country_code,
                    m.provider_operator AS map_operator, m.provider_price AS map_provider_price,
                    m.provider_cost_base AS map_provider_cost_base, m.custom_selling_price,
                    m.pricing_mode AS map_pricing_mode, m.profit_margin AS map_profit_margin,
                    m.fixed_profit AS map_fixed_profit, m.min_price AS map_min_price, m.max_price AS map_max_price,
                    m.auto_price_update AS map_auto_price_update, m.is_available AS map_available,
                    m.available_count AS map_available_count, m.availability_verified AS map_availability_verified,
                    m.status AS map_status
             FROM otp_countries c
             LEFT JOIN otp_service_provider_mappings m
               ON m.country_id = c.id AND m.service_id = ? AND m.provider_id = ?
             WHERE c.id = ? AND c.status = 'active' LIMIT 1",
            [$serviceId, (int)$service['prov_id'], $countryId]
        );
        if (!$country) {
            return ['ok' => false, 'error_code' => 'INVALID_COUNTRY', 'error' => 'Selected country is unavailable or disabled.'];
        }
        if (isset($country['map_status']) && ($country['map_status'] === 'inactive' || (int)$country['map_available'] === 0)) {
            return ['ok' => false, 'error_code' => 'SERVICE_UNAVAILABLE', 'error' => 'This service is currently disabled for the selected country.'];
        }

        $provCountry = (string)($country['map_country_code'] ?: $country['default_provider_code']);
        $provService = (string)($country['map_service_code'] ?: ($service['provider_service_code'] ?: $service['slug']));
        $cleanOpOverride = $operatorOverride !== null ? preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($operatorOverride)) : '';
        $operator = $cleanOpOverride !== '' ? substr($cleanOpOverride, 0, 60) : (string)($country['map_operator'] ?: 'any');

        $adapter = OtpProviderFactory::make(self::extractProviderRow($service));
        $live = $adapter->getAvailabilityAndPrice($provCountry, $provService, $operator);
        if (empty($live['ok'])) {
            return ['ok' => false, 'error_code' => 'PROVIDER_ERROR', 'error' => self::formatProviderError($live['error'] ?? 'Provider availability check failed.')];
        }
        if (empty($live['available'])) {
            return ['ok' => false, 'available' => false, 'count' => 0, 'error_code' => 'SERVICE_UNAVAILABLE', 'error' => 'No numbers currently available from provider for this service & country.'];
        }

        $liveCostRaw = $live['cost'] ?? ($country['map_provider_price'] ?? ($service['provider_price'] ?? null));
        $priceCalc = self::calculateSellingPrice($service, $country, $liveCostRaw !== null ? (string)$liveCostRaw : null);

        return [
            'ok'                 => true,
            'available'          => true,
            'verified'           => !empty($live['verified']),
            'count'              => $live['count'] ?? null,
            'service'            => $service,
            'country'            => $country,
            'provider_row'       => self::extractProviderRow($service),
            'provider_country'   => $provCountry,
            'provider_service'   => $provService,
            'operator'           => (string)($live['operator'] ?? $operator),
            'provider_cost_raw'  => $priceCalc['provider_cost_raw'],
            'provider_cost_base' => $priceCalc['provider_cost_base'],
            'exchange_rate'      => $priceCalc['exchange_rate'],
            'selling_price'      => $priceCalc['selling_price'],
            'currency_code'      => Currency::getBaseCode(),
        ];
    }

    /**
     * Unified One-Time Activation Purchase Engine (used by both Direct OTP Store and OTP Reseller API).
     * Performs Atomic Shared Wallet Debit + Real Provider Number Assignment.
     */
    public static function purchaseActivation(int $userId, int $serviceId, int $countryId, string $clientToken = '', array $options = []): array
    {
        $source = (($options['source'] ?? 'direct') === 'api') ? 'api' : 'direct';
        $apiKeyId = !empty($options['api_key_id']) ? (int)$options['api_key_id'] : null;
        $operatorOverride = isset($options['operator']) && trim((string)$options['operator']) !== '' ? trim((string)$options['operator']) : null;

        if ($source === 'direct' && !StoreManager::isOtpEnabled()) {
            return ['ok' => false, 'error_code' => 'STORE_DISABLED', 'error' => 'OTP Store is currently disabled.'];
        }

        $user = Database::fetch("SELECT * FROM users WHERE id = ? LIMIT 1", [$userId]);
        if (!$user || $user['status'] !== 'active') {
            return ['ok' => false, 'error_code' => 'API_DISABLED', 'error' => 'Account is not active.'];
        }

        // Enforce minimum wallet balance setting
        $minBal = Currency::normalize(Helpers::setting('otp_min_wallet_balance', '0'), 4);
        if (Currency::cmp((string)$user['wallet_balance'], $minBal, 4) < 0) {
            return [
                'ok'         => false,
                'error_code' => 'INSUFFICIENT_BALANCE',
                'error'      => 'Minimum wallet balance of ' . Currency::display($minBal, null, 2, true) . ' is required to buy OTP activations.',
            ];
        }

        // Enforce active activations limit & daily purchase limit
        $maxActive = !empty($options['max_active_override'])
            ? max(1, (int)$options['max_active_override'])
            : max(1, (int)Helpers::setting('otp_max_active_per_user', '5'));
        $activeCount = (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM otp_activations WHERE user_id = ? AND status IN ('processing','active','waiting_otp')",
            [$userId]
        );
        if ($activeCount >= $maxActive) {
            return [
                'ok'         => false,
                'error_code' => 'RATE_LIMIT_EXCEEDED',
                'error'      => "You already have {$activeCount} active activations (maximum {$maxActive} concurrent). Complete or cancel an active activation first.",
            ];
        }

        $maxDaily = !empty($options['max_daily_override'])
            ? max(1, (int)$options['max_daily_override'])
            : max(1, (int)Helpers::setting('otp_max_daily_per_user', '50'));
        $todayCount = (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM otp_activations WHERE user_id = ? AND created_at >= CURDATE()",
            [$userId]
        );
        if ($todayCount >= $maxDaily) {
            return [
                'ok'         => false,
                'error_code' => 'RATE_LIMIT_EXCEEDED',
                'error'      => "Daily OTP purchase limit ({$maxDaily}) reached. Please try again tomorrow.",
            ];
        }

        // Idempotency protection
        $idemPrefix = ($source === 'api') ? 'otp_api_' : 'otp_buy_';
        $idemKey = $clientToken !== '' ? $idemPrefix . $userId . '_' . hash('sha256', $clientToken) : $idemPrefix . $userId . '_' . bin2hex(random_bytes(8));
        $idemKey = substr($idemKey, 0, 96);
        $existingByIdem = Database::fetch("SELECT id, activation_code, status FROM otp_activations WHERE idempotency_key = ? LIMIT 1", [$idemKey]);
        if ($existingByIdem) {
            return [
                'ok'            => false,
                'duplicate'     => true,
                'error_code'    => 'DUPLICATE_REQUEST',
                'activation_id' => (int)$existingByIdem['id'],
                'error'         => 'Duplicate activation request prevented.',
            ];
        }

        // Live provider quote & availability verification
        $quote = self::getLiveQuote($serviceId, $countryId, $operatorOverride);
        if (empty($quote['ok'])) {
            return [
                'ok'         => false,
                'error_code' => (string)($quote['error_code'] ?? 'SERVICE_UNAVAILABLE'),
                'error'      => $quote['error'] ?? 'Service is currently unavailable from provider.',
            ];
        }

        $sellingPrice = Currency::normalize((string)$quote['selling_price'], 4);
        if ($source === 'api' && class_exists('OtpResellerApi')) {
            $resellerCalc = OtpResellerApi::calculateResellerPrice(
                $sellingPrice,
                (string)$quote['provider_cost_base'],
                $serviceId,
                $countryId,
                $options['api_key_record'] ?? null
            );
            $sellingPrice = $resellerCalc['reseller_price'];
        } elseif (!empty($options['custom_selling_price']) && Currency::cmp((string)$options['custom_selling_price'], '0', 4) > 0) {
            $sellingPrice = Currency::normalize((string)$options['custom_selling_price'], 4);
        }

        if (Currency::cmp($sellingPrice, '0', 4) <= 0) {
            return ['ok' => false, 'error_code' => 'SERVICE_UNAVAILABLE', 'error' => 'Invalid selling price configured for this OTP service.'];
        }

        $service = $quote['service'];
        $country = $quote['country'];
        $providerRow = $quote['provider_row'];
        $baseCode = Currency::getBaseCode();
        $actCode = ($source === 'api' ? 'OTPAPI-' : 'OTP-') . strtoupper(bin2hex(random_bytes(4)));

        // Step A: Atomic Shared Wallet Debit + Activation Record Creation (processing)
        Database::beginTransaction();
        try {
            $debitIdem = 'otp_debit_' . $idemKey;
            $txDescPrefix = ($source === 'api') ? 'OTP API Purchase: ' : 'Direct OTP Purchase: ';
            $debit = Wallet::debit(
                $userId,
                $sellingPrice,
                'debit',
                $txDescPrefix . $service['name'] . ' (' . $country['name'] . ')',
                'otp_activation',
                null,
                substr($debitIdem, 0, 100),
                true
            );
            if (empty($debit['ok'])) {
                Database::rollBack();
                $debit['error_code'] = 'INSUFFICIENT_BALANCE';
                return $debit;
            }

            $timeoutMins = max(2, (int)Helpers::setting('otp_default_timeout_minutes', '15'));
            $defaultExpires = date('Y-m-d H:i:s', time() + ($timeoutMins * 60));

            $activationId = Database::insert(
                "INSERT INTO otp_activations
                 (activation_code, user_id, source, api_key_id, service_id, country_id, provider_id, provider_operator,
                  provider_cost_raw, provider_cost, provider_currency, exchange_rate, selling_price, currency_code, profit,
                  status, can_cancel, can_complete, idempotency_key, wallet_tx_id, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'processing', ?, 1, ?, ?, ?)",
                [
                    $actCode,
                    $userId,
                    $source,
                    $apiKeyId,
                    $serviceId,
                    $countryId,
                    (int)$providerRow['id'],
                    $quote['operator'],
                    Currency::normalize((string)($quote['provider_cost_raw'] ?? '0'), 4),
                    $quote['provider_cost_base'],
                    (string)$providerRow['currency_code'],
                    Currency::normalize((string)($quote['exchange_rate'] ?? '1.000000'), 6),
                    $sellingPrice,
                    $baseCode,
                    Currency::sub($sellingPrice, $quote['provider_cost_base'], 4),
                    Helpers::setting('otp_allow_user_cancel', '1') === '1' ? 1 : 0,
                    $idemKey,
                    (int)$debit['tx_id'],
                    $defaultExpires,
                ]
            );

            Database::execute(
                "UPDATE wallet_transactions SET reference_id = ? WHERE id = ?",
                [(string)$activationId, (int)$debit['tx_id']]
            );

            Database::commit();
        } catch (Throwable $e) {
            Database::rollBack();
            Logger::errorFile('OtpService::purchaseActivation DB error: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not initialize activation. Please try again.'];
        }

        // Step B: Dispatch Live Activation Request to Provider API
        try {
            $adapter = OtpProviderFactory::make($providerRow);
            $buyRes = $adapter->buyActivation(
                (string)$quote['provider_country'],
                (string)$quote['provider_service'],
                (string)$quote['operator'],
                $activationId
            );
        } catch (Throwable $e) {
            $buyRes = ['ok' => false, 'uncertain' => false, 'error' => $e->getMessage()];
        }

        // Step C: Reconcile Provider Response Safely
        if (!empty($buyRes['ok']) && !empty($buyRes['phone_number']) && !empty($buyRes['provider_order_id'])) {
            $actualProvCostRaw = Currency::normalize((string)($quote['provider_cost_raw'] ?? '0'), 4);
            $actualProvCostBase = $quote['provider_cost_base'];
            $actualExRate = Currency::normalize((string)($quote['exchange_rate'] ?? '1.000000'), 6);
            if (!empty($buyRes['provider_cost']) && Currency::cmp((string)$buyRes['provider_cost'], '0', 4) > 0) {
                $actualProvCostRaw = Currency::normalize((string)$buyRes['provider_cost'], 4);
                $conv = Currency::calculateProviderToBase(
                    $actualProvCostRaw,
                    (string)($buyRes['provider_currency'] ?: $providerRow['currency_code']),
                    '0',
                    (string)($providerRow['rate_mode'] ?? 'auto'),
                    (string)($providerRow['exchange_rate'] ?? '1')
                );
                $actualProvCostBase = $conv['cost_base'];
                $actualExRate = $conv['effective_exchange_rate'];
            }
            $profit = Currency::sub($sellingPrice, $actualProvCostBase, 4);
            $expiresAt = !empty($buyRes['expires_at']) ? $buyRes['expires_at'] : $defaultExpires;
            $status = in_array($buyRes['status'] ?? '', ['active', 'waiting_otp', 'otp_received'], true)
                ? (string)$buyRes['status']
                : 'waiting_otp';

            Database::execute(
                "UPDATE otp_activations
                 SET provider_order_id = ?, phone_number = ?, provider_operator = ?,
                     provider_cost_raw = ?, provider_cost = ?, exchange_rate = ?, profit = ?, status = ?, provider_status = ?,
                     expires_at = ?, last_checked_at = NOW(), provider_response_json = ?
                 WHERE id = ?",
                [
                    (string)$buyRes['provider_order_id'],
                    (string)$buyRes['phone_number'],
                    (string)($buyRes['operator'] ?? $quote['operator']),
                    $actualProvCostRaw,
                    $actualProvCostBase,
                    $actualExRate,
                    $profit,
                    $status,
                    (string)($buyRes['provider_status'] ?? 'PENDING'),
                    $expiresAt,
                    json_encode($buyRes['raw'] ?? []),
                    $activationId,
                ]
            );

            self::notifyUser(
                $userId,
                'otp',
                'OTP Activation #' . $actCode . ' Assigned',
                'Number ' . $buyRes['phone_number'] . ' assigned for ' . $service['name'] . ' (' . $country['name'] . '). Waiting for SMS code.',
                '/otp/activations.php?id=' . $activationId
            );

            $fresh = self::getActivationForUser($activationId, $userId);
            return [
                'ok'         => true,
                'activation' => $fresh,
                'balance'    => (string)Database::fetchColumn("SELECT wallet_balance FROM users WHERE id = ?", [$userId]),
            ];
        }

        // Uncertain provider timeout / network error -> DO NOT blindly refund; flag for reconciliation
        if (!empty($buyRes['uncertain'])) {
            $reason = 'Uncertain provider response — awaiting provider status verification before any retry or refund.';
            Database::execute(
                "UPDATE otp_activations SET status = 'processing', failure_reason = ?, provider_response_json = ? WHERE id = ?",
                [$reason, json_encode(['error' => $buyRes['error'] ?? 'timeout']), $activationId]
            );
            self::notifyUser(
                $userId,
                'otp',
                'Activation #' . $actCode . ' Pending Verification',
                'Provider response timed out. Our system is verifying the order status automatically before processing any refund.',
                '/otp/activations.php?id=' . $activationId
            );
            return [
                'ok'            => false,
                'uncertain'     => true,
                'activation_id' => $activationId,
                'error'         => $reason,
            ];
        }

        // Definitive provider failure -> mark failed and issue exact-once automatic refund
        $errMsg = self::formatProviderError((string)($buyRes['error'] ?? 'Provider could not assign a number.'));
        Database::execute(
            "UPDATE otp_activations SET status = 'failed', failure_reason = ?, provider_response_json = ? WHERE id = ?",
            [substr($errMsg, 0, 255), json_encode(['error' => $buyRes['error'] ?? 'failed']), $activationId]
        );

        $refundRes = self::issueRefund($activationId, 'system', null, 'Automatic refund: provider assignment failed (' . $errMsg . ')');
        self::notifyUser(
            $userId,
            'otp_refund',
            'Activation Failed & Refunded',
            $service['name'] . ' (' . $country['name'] . ') could not be assigned: ' . $errMsg . '. Your wallet has been refunded.',
            '/otp/history.php'
        );

        return [
            'ok'       => false,
            'refunded' => !empty($refundRes['ok']),
            'error'    => $errMsg . (!empty($refundRes['ok']) ? ' Your wallet balance has been refunded.' : ''),
            'balance'  => (string)Database::fetchColumn("SELECT wallet_balance FROM users WHERE id = ?", [$userId]),
        ];
    }

    /**
     * Synchronize activation state with the upstream provider (used by AJAX polling & Cron).
     * Continues to work even when OTP Store is turned OFF so active activations complete/refund properly.
     */
    public static function syncActivationStatus(int $activationId, bool $forceProvider = false): array
    {
        $act = Database::fetch(
            "SELECT a.*, s.name AS service_name, c.name AS country_name,
                    p.id AS prov_id, p.name AS prov_name, p.adapter_type, p.custom_adapter_class,
                    p.base_url, p.api_key_enc, p.auth_type, p.auth_header_name, p.auth_param_name,
                    p.request_method, p.endpoints_json, p.request_params_json, p.response_map_json,
                    p.status_map_json, p.timeout_seconds, p.polling_interval_seconds, p.currency_code AS prov_currency
             FROM otp_activations a
             JOIN otp_services s ON s.id = a.service_id
             JOIN otp_countries c ON c.id = a.country_id
             LEFT JOIN otp_providers p ON p.id = a.provider_id
             WHERE a.id = ? LIMIT 1",
            [$activationId]
        );
        if (!$act) {
            return ['ok' => false, 'error' => 'Activation not found.'];
        }

        $terminal = ['completed', 'expired', 'canceled', 'refunded', 'failed'];
        if (in_array($act['status'], $terminal, true)) {
            return ['ok' => true, 'activation' => $act];
        }

        // Throttle provider polling if recently checked and not forced
        $minPoll = max(2, (int)($act['polling_interval_seconds'] ?? Helpers::setting('otp_polling_interval', '5')));
        if (!$forceProvider && !empty($act['last_checked_at'])) {
            $elapsed = time() - (int)strtotime((string)$act['last_checked_at']);
            if ($elapsed < $minPoll) {
                return ['ok' => true, 'throttled' => true, 'activation' => $act];
            }
        }

        // Check if provider_order_id exists; if missing and expired, skip blind refund unless admin reconciles
        if (empty($act['provider_order_id']) || empty($act['prov_id'])) {
            return ['ok' => true, 'activation' => $act];
        }

        $adapter = OtpProviderFactory::make(self::extractProviderRow($act));
        $chk = $adapter->checkActivation((string)$act['provider_order_id'], $activationId);
        if (empty($chk['ok'])) {
            Database::execute("UPDATE otp_activations SET last_checked_at = NOW() WHERE id = ?", [$activationId]);
            return ['ok' => false, 'error' => $chk['error'] ?? 'Status check failed.', 'activation' => $act];
        }

        $newStatus = (string)($chk['status'] ?? $act['status']);
        $otpCode = $chk['otp_code'] ?? $act['otp_code'];
        $smsText = $chk['sms_text'] ?? $act['sms_text'];
        $smsAt = $chk['sms_received_at'] ?? $act['sms_received_at'];
        $expiresAt = !empty($chk['expires_at']) ? $chk['expires_at'] : $act['expires_at'];

        // Detect local expiry if provider hasn't flipped yet and no OTP received
        if (in_array($newStatus, ['active', 'waiting_otp'], true) && empty($otpCode) && !empty($expiresAt) && strtotime($expiresAt) <= time()) {
            $newStatus = 'expired';
        }

        $wasOtpReceived = ($act['status'] === 'otp_received' || !empty($act['otp_code']));
        $nowOtpReceived = (!empty($otpCode) || !empty($smsText));

        $completedAt = $act['completed_at'];
        if ($newStatus === 'completed' && !$completedAt) {
            $completedAt = date('Y-m-d H:i:s');
        }

        Database::execute(
            "UPDATE otp_activations
             SET status = ?, provider_status = ?, otp_code = ?, sms_text = ?, sms_received_at = ?,
                 expires_at = ?, completed_at = ?, last_checked_at = NOW(), provider_response_json = ?
             WHERE id = ?",
            [
                $newStatus,
                (string)($chk['provider_status'] ?? $act['provider_status']),
                $otpCode,
                $smsText,
                $smsAt,
                $expiresAt,
                $completedAt,
                json_encode($chk['raw'] ?? []),
                $activationId,
            ]
        );

        // Notifications on state changes
        if (!$wasOtpReceived && $nowOtpReceived) {
            self::notifyUser(
                (int)$act['user_id'],
                'otp',
                'OTP Received for ' . $act['service_name'],
                'Verification code ' . ($otpCode ?: 'SMS') . ' received for activation #' . $act['activation_code'] . '.',
                '/otp/activations.php?id=' . $activationId
            );
        } elseif ($newStatus === 'completed' && $act['status'] !== 'completed') {
            self::notifyUser(
                (int)$act['user_id'],
                'otp',
                'Activation Completed',
                'OTP activation #' . $act['activation_code'] . ' (' . $act['service_name'] . ') is completed.',
                '/otp/history.php'
            );
        } elseif ($newStatus === 'expired' && $act['status'] !== 'expired') {
            if (empty($otpCode) && Helpers::setting('otp_auto_refund_expired', '1') === '1') {
                self::issueRefund($activationId, 'system', null, 'Automatic refund: activation expired without receiving OTP.');
            } else {
                self::notifyUser(
                    (int)$act['user_id'],
                    'otp',
                    'Activation Expired',
                    'OTP activation #' . $act['activation_code'] . ' expired without receiving a code.',
                    '/otp/history.php'
                );
            }
        } elseif ($newStatus === 'canceled' && $act['status'] !== 'canceled') {
            if (empty($otpCode) && Helpers::setting('otp_auto_refund_canceled', '1') === '1') {
                self::issueRefund($activationId, 'system', null, 'Automatic refund: activation cancelled by provider before OTP delivery.');
            }
        } elseif (in_array($newStatus, ['active', 'waiting_otp'], true) && empty($act['expiring_notified']) && !empty($expiresAt)) {
            $rem = strtotime($expiresAt) - time();
            if ($rem > 0 && $rem <= 120) {
                Database::execute("UPDATE otp_activations SET expiring_notified = 1 WHERE id = ?", [$activationId]);
                self::notifyUser(
                    (int)$act['user_id'],
                    'otp',
                    'Activation Expiring Soon',
                    'Activation #' . $act['activation_code'] . ' (' . $act['service_name'] . ') expires in less than 2 minutes.',
                    '/otp/activations.php?id=' . $activationId
                );
            }
        }

        return ['ok' => true, 'activation' => self::getActivationById($activationId)];
    }

    /**
     * Cancel an active OTP activation (ownership enforced unless admin).
     */
    public static function cancelActivation(int $activationId, int $actorId, bool $isAdmin = false): array
    {
        $act = self::getActivationById($activationId);
        if (!$act || (!$isAdmin && (int)$act['user_id'] !== $actorId)) {
            return ['ok' => false, 'error' => 'Activation not found.'];
        }
        if (!$isAdmin && (empty($act['can_cancel']) || Helpers::setting('otp_allow_user_cancel', '1') !== '1')) {
            return ['ok' => false, 'error' => 'Cancellation is not enabled for this activation.'];
        }
        if (!in_array($act['status'], ['active', 'waiting_otp'], true) || !empty($act['otp_code'])) {
            return ['ok' => false, 'error' => 'This activation can no longer be cancelled (OTP already received or finalized).'];
        }

        // First re-check provider so we never cancel/refund an activation that just received an SMS
        if (!empty($act['provider_order_id']) && !empty($act['provider_id'])) {
            $sync = self::syncActivationStatus($activationId, true);
            $latest = $sync['activation'] ?? $act;
            if (!empty($latest['otp_code']) || $latest['status'] === 'otp_received' || $latest['status'] === 'completed') {
                return ['ok' => false, 'error' => 'An OTP has already been received for this activation. Cancellation is blocked.'];
            }

            $provider = Database::fetch("SELECT * FROM otp_providers WHERE id = ? LIMIT 1", [$act['provider_id']]);
            if ($provider) {
                $adapter = OtpProviderFactory::make($provider);
                $cancelRes = $adapter->cancelActivation((string)$act['provider_order_id'], $activationId);
                if (empty($cancelRes['ok'])) {
                    return ['ok' => false, 'error' => self::formatProviderError($cancelRes['error'] ?? 'Provider did not permit cancellation right now.')];
                }
            }
        }

        Database::execute("UPDATE otp_activations SET status = 'canceled', provider_status = 'CANCELED', can_cancel = 0, completed_at = NOW() WHERE id = ?", [$activationId]);

        $refunded = false;
        if (Helpers::setting('otp_auto_refund_canceled', '1') === '1') {
            $ref = self::issueRefund($activationId, $isAdmin ? 'admin' : 'user', $actorId, 'Activation cancelled prior to OTP delivery.');
            $refunded = !empty($ref['ok']);
        } else {
            self::notifyUser(
                (int)$act['user_id'],
                'otp',
                'Activation Cancelled',
                'OTP activation #' . $act['activation_code'] . ' was cancelled.',
                '/otp/history.php'
            );
        }

        return [
            'ok'         => true,
            'refunded'   => $refunded,
            'activation' => self::getActivationById($activationId),
            'balance'    => (string)Database::fetchColumn("SELECT wallet_balance FROM users WHERE id = ?", [$act['user_id']]),
        ];
    }

    /**
     * Finish/complete an activation after OTP has been received.
     */
    public static function finishActivation(int $activationId, int $actorId, bool $isAdmin = false): array
    {
        $act = self::getActivationById($activationId);
        if (!$act || (!$isAdmin && (int)$act['user_id'] !== $actorId)) {
            return ['ok' => false, 'error' => 'Activation not found.'];
        }
        if (!in_array($act['status'], ['otp_received', 'waiting_otp', 'active'], true)) {
            return ['ok' => false, 'error' => 'Activation is already finalized.'];
        }

        if (!empty($act['provider_order_id']) && !empty($act['provider_id'])) {
            $provider = Database::fetch("SELECT * FROM otp_providers WHERE id = ? LIMIT 1", [$act['provider_id']]);
            if ($provider) {
                $adapter = OtpProviderFactory::make($provider);
                $adapter->finishActivation((string)$act['provider_order_id'], $activationId);
            }
        }

        Database::execute(
            "UPDATE otp_activations SET status = 'completed', provider_status = 'FINISHED', can_cancel = 0, can_complete = 0, completed_at = NOW() WHERE id = ?",
            [$activationId]
        );
        self::notifyUser(
            (int)$act['user_id'],
            'otp',
            'Activation Completed',
            'OTP activation #' . $act['activation_code'] . ' has been marked completed.',
            '/otp/history.php'
        );

        return ['ok' => true, 'activation' => self::getActivationById($activationId)];
    }

    /**
     * Exact-once refund processor (uses shared Wallet::credit + UNIQUE activation_id in otp_refunds + idempotency key).
     */
    public static function issueRefund(
        int $activationId,
        string $actorType,
        ?int $actorId,
        string $reason,
        ?int $ticketId = null
    ): array {
        Database::beginTransaction();
        try {
            $act = Database::fetch("SELECT * FROM otp_activations WHERE id = ? FOR UPDATE", [$activationId]);
            if (!$act) {
                Database::rollBack();
                return ['ok' => false, 'error' => 'Activation not found.'];
            }
            if (Currency::cmp((string)$act['refunded_amount'], '0', 4) > 0 || !empty($act['refund_tx_id']) || $act['status'] === 'refunded') {
                Database::rollBack();
                return ['ok' => false, 'duplicate' => true, 'error' => 'Refund already processed for this activation.'];
            }

            $refundAmt = Currency::normalize((string)$act['selling_price'], 4);
            if (Currency::cmp($refundAmt, '0', 4) <= 0) {
                Database::rollBack();
                return ['ok' => false, 'error' => 'Refundable amount is zero.'];
            }

            // Ensure no existing approved refund row
            $existingRefund = Database::fetch("SELECT * FROM otp_refunds WHERE activation_id = ? FOR UPDATE", [$activationId]);
            if ($existingRefund && $existingRefund['status'] === 'approved') {
                Database::rollBack();
                return ['ok' => false, 'duplicate' => true, 'error' => 'Refund already approved for this activation.'];
            }

            $idemKey = 'otp_refund_' . $activationId;
            $credit = Wallet::credit(
                (int)$act['user_id'],
                $refundAmt,
                'refund',
                'OTP Refund #' . $act['activation_code'] . ': ' . $reason,
                'otp_activation',
                (string)$activationId,
                $idemKey
            );
            if (empty($credit['ok'])) {
                Database::rollBack();
                return $credit;
            }
            if (!empty($credit['duplicate'])) {
                Database::rollBack();
                return ['ok' => false, 'duplicate' => true, 'error' => 'Duplicate refund prevented.'];
            }

            $txId = (int)$credit['tx_id'];
            Database::execute(
                "UPDATE otp_activations
                 SET status = 'refunded', refunded_amount = ?, refund_tx_id = ?, can_cancel = 0, can_complete = 0
                 WHERE id = ?",
                [$refundAmt, $txId, $activationId]
            );

            if ($existingRefund) {
                Database::execute(
                    "UPDATE otp_refunds
                     SET status = 'approved', admin_note = ?, wallet_tx_id = ?, processed_by = ?, processed_at = NOW(), provider_status_snapshot = ?
                     WHERE id = ?",
                    [$reason, $txId, $actorType === 'admin' ? $actorId : null, (string)$act['provider_status'], $existingRefund['id']]
                );
            } else {
                Database::insert(
                    "INSERT INTO otp_refunds
                     (activation_id, user_id, ticket_id, requested_by_type, requested_by_id, amount, currency_code,
                      reason, admin_note, provider_status_snapshot, status, wallet_tx_id, processed_by, processed_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', ?, ?, NOW())",
                    [
                        $activationId,
                        (int)$act['user_id'],
                        $ticketId,
                        in_array($actorType, ['system', 'user', 'admin'], true) ? $actorType : 'system',
                        $actorId,
                        $refundAmt,
                        (string)$act['currency_code'],
                        substr($reason, 0, 255),
                        $reason,
                        (string)$act['provider_status'],
                        $txId,
                        $actorType === 'admin' ? $actorId : null,
                    ]
                );
            }

            Database::commit();
        } catch (Throwable $e) {
            Database::rollBack();
            Logger::errorFile('OtpService::issueRefund error: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Failed to process refund safely.'];
        }

        self::notifyUser(
            (int)$act['user_id'],
            'otp_refund',
            'OTP Refund Processed',
            Currency::display($refundAmt, null, 2, true) . ' refunded to your shared wallet for activation #' . $act['activation_code'] . '.',
            '/user/add-funds.php'
        );

        return ['ok' => true, 'amount' => $refundAmt, 'tx_id' => $txId];
    }

    /**
     * Reject a pending refund request cleanly.
     */
    public static function rejectRefund(int $activationId, int $adminId, string $reason, ?int $ticketId = null): array
    {
        $act = self::getActivationById($activationId);
        if (!$act) {
            return ['ok' => false, 'error' => 'Activation not found.'];
        }
        $existing = Database::fetch("SELECT * FROM otp_refunds WHERE activation_id = ? LIMIT 1", [$activationId]);
        if ($existing && $existing['status'] === 'approved') {
            return ['ok' => false, 'error' => 'Cannot reject a refund that was already approved.'];
        }
        if ($existing) {
            Database::execute(
                "UPDATE otp_refunds SET status = 'rejected', admin_note = ?, processed_by = ?, processed_at = NOW() WHERE id = ?",
                [$reason, $adminId, $existing['id']]
            );
        } else {
            Database::insert(
                "INSERT INTO otp_refunds
                 (activation_id, user_id, ticket_id, requested_by_type, requested_by_id, amount, currency_code,
                  reason, admin_note, provider_status_snapshot, status, processed_by, processed_at)
                 VALUES (?, ?, ?, 'admin', ?, ?, ?, ?, ?, ?, 'rejected', ?, NOW())",
                [
                    $activationId,
                    (int)$act['user_id'],
                    $ticketId,
                    $adminId,
                    (string)$act['selling_price'],
                    (string)$act['currency_code'],
                    substr($reason, 0, 255),
                    $reason,
                    (string)$act['provider_status'],
                    $adminId,
                ]
            );
        }

        self::notifyUser(
            (int)$act['user_id'],
            'otp_refund',
            'OTP Refund Request Rejected',
            'Refund request for activation #' . $act['activation_code'] . ' was rejected: ' . $reason,
            '/otp/history.php'
        );
        return ['ok' => true];
    }

    public static function createPendingRefundRequest(int $activationId, int $userId, string $reason, ?int $ticketId = null): array
    {
        $act = self::getActivationById($activationId);
        if (!$act || (int)$act['user_id'] !== $userId) {
            return ['ok' => false, 'error' => 'Activation not found.'];
        }
        $existing = Database::fetch("SELECT id, status FROM otp_refunds WHERE activation_id = ? LIMIT 1", [$activationId]);
        if ($existing) {
            return ['ok' => true, 'existing' => true, 'status' => $existing['status']];
        }
        Database::insert(
            "INSERT INTO otp_refunds (activation_id, user_id, ticket_id, requested_by_type, requested_by_id, amount, currency_code, reason, provider_status_snapshot, status)
             VALUES (?, ?, ?, 'user', ?, ?, ?, ?, ?, 'pending')",
            [
                $activationId,
                $userId,
                $ticketId,
                $userId,
                (string)$act['selling_price'],
                (string)$act['currency_code'],
                substr($reason, 0, 255),
                (string)$act['provider_status'],
            ]
        );
        return ['ok' => true];
    }

    public static function getActivationById(int $activationId): ?array
    {
        return Database::fetch(
            "SELECT a.*, s.name AS service_name, s.icon_key AS service_icon,
                    c.name AS country_name, c.iso_code AS country_iso, c.flag_emoji AS country_flag,
                    p.name AS provider_name
             FROM otp_activations a
             JOIN otp_services s ON s.id = a.service_id
             JOIN otp_countries c ON c.id = a.country_id
             LEFT JOIN otp_providers p ON p.id = a.provider_id
             WHERE a.id = ? LIMIT 1",
            [$activationId]
        );
    }

    public static function getActivationForUser(int $activationId, int $userId): ?array
    {
        $act = self::getActivationById($activationId);
        if (!$act || (int)$act['user_id'] !== $userId) {
            return null;
        }
        return $act;
    }

    public static function getActiveActivationsForUser(int $userId): array
    {
        return Database::fetchAll(
            "SELECT a.*, s.name AS service_name, s.icon_key AS service_icon,
                    c.name AS country_name, c.iso_code AS country_iso, c.flag_emoji AS country_flag,
                    p.name AS provider_name
             FROM otp_activations a
             JOIN otp_services s ON s.id = a.service_id
             JOIN otp_countries c ON c.id = a.country_id
             LEFT JOIN otp_providers p ON p.id = a.provider_id
             WHERE a.user_id = ? AND a.status IN ('processing','active','waiting_otp','otp_received')
             ORDER BY a.id DESC",
            [$userId]
        );
    }

    /**
     * Server-side selling price calculation supporting:
     * - Explicit provider currency -> platform base currency FX conversion (never mixes currencies)
     * - Percentage Profit Margin (%)
     * - Fixed Profit Amount (base currency)
     * - Fixed Selling Price override (mapping pricing_mode='fixed' or custom_selling_price)
     * - Minimum & Maximum Selling Price clamping
     */
    public static function calculateSellingPrice(array $service, array $countryMapping = [], ?string $liveProviderCost = null): array
    {
        $provCurr = (string)($service['prov_currency'] ?? $service['provider_currency'] ?? 'RUB');
        $rateMode = (string)($service['prov_rate_mode'] ?? $service['rate_mode'] ?? 'auto');
        $exRate = (string)($service['prov_ex_rate'] ?? $service['exchange_rate'] ?? '1');

        $mapMargin = $countryMapping['map_profit_margin'] ?? $countryMapping['profit_margin'] ?? null;
        $margin = ($mapMargin !== null && $mapMargin !== '')
            ? Currency::normalize((string)$mapMargin, 2)
            : Currency::normalize((string)($service['profit_margin'] ?? Helpers::setting('otp_default_profit_margin', '25.00')), 2);

        $mapFixedProfit = $countryMapping['map_fixed_profit'] ?? $countryMapping['fixed_profit'] ?? null;
        $fixedProfit = ($mapFixedProfit !== null && $mapFixedProfit !== '')
            ? Currency::normalize((string)$mapFixedProfit, 4)
            : Currency::normalize((string)($service['fixed_profit'] ?? '0'), 4);

        $pricingMode = (string)($countryMapping['map_pricing_mode'] ?? $countryMapping['pricing_mode'] ?? 'auto');
        $customSelling = $countryMapping['custom_selling_price'] ?? null;

        $costRaw = '0.0000';
        $costBase = '0.0000';
        $effectiveExRate = '1.000000';
        $selling = Currency::normalize((string)($service['selling_price'] ?? '0'), 4);

        $rawToConvert = $liveProviderCost;
        if (($rawToConvert === null || Currency::cmp($rawToConvert, '0', 4) <= 0)
            && !empty($countryMapping['map_provider_price'])
            && Currency::cmp((string)$countryMapping['map_provider_price'], '0', 4) > 0
        ) {
            $rawToConvert = (string)$countryMapping['map_provider_price'];
        } elseif (($rawToConvert === null || Currency::cmp($rawToConvert, '0', 4) <= 0)
            && !empty($service['provider_price'])
            && Currency::cmp((string)$service['provider_price'], '0', 4) > 0
        ) {
            $rawToConvert = (string)$service['provider_price'];
        }

        if ($rawToConvert !== null && Currency::cmp($rawToConvert, '0', 4) > 0) {
            $costRaw = Currency::normalize($rawToConvert, 4);
            $conv = Currency::calculateProviderToBase($costRaw, $provCurr, $margin, $rateMode, $exRate);
            $costBase = $conv['cost_base'];
            $effectiveExRate = $conv['effective_exchange_rate'];
            $dynamicSelling = Currency::add($conv['selling_rate_base'], $fixedProfit, 4);

            $autoUpdate = isset($countryMapping['map_auto_price_update'])
                ? ((int)$countryMapping['map_auto_price_update'] === 1)
                : (!isset($service['auto_price_update']) || (int)$service['auto_price_update'] === 1);

            if ($pricingMode === 'auto' && ($autoUpdate || Currency::cmp($selling, '0', 4) <= 0)) {
                $selling = $dynamicSelling;
            } elseif (Currency::cmp($selling, '0', 4) <= 0) {
                $selling = $dynamicSelling;
            }
        }

        // Explicit fixed selling price on country mapping takes precedence when configured
        if ($customSelling !== null && Currency::cmp((string)$customSelling, '0', 4) > 0) {
            if ($pricingMode === 'fixed' || Currency::cmp($selling, '0', 4) <= 0) {
                $selling = Currency::normalize((string)$customSelling, 4);
            }
        }

        // Respect mapping-level or service-level min_price / max_price bounds
        $minPrice = $countryMapping['map_min_price'] ?? $countryMapping['min_price'] ?? ($service['min_price'] ?? null);
        $maxPrice = $countryMapping['map_max_price'] ?? $countryMapping['max_price'] ?? ($service['max_price'] ?? null);

        if ($minPrice !== null && $minPrice !== '' && Currency::cmp((string)$minPrice, '0', 4) > 0 && Currency::cmp($selling, (string)$minPrice, 4) < 0) {
            $selling = Currency::normalize((string)$minPrice, 4);
        }
        if ($maxPrice !== null && $maxPrice !== '' && Currency::cmp((string)$maxPrice, '0', 4) > 0 && Currency::cmp($selling, (string)$maxPrice, 4) > 0) {
            $selling = Currency::normalize((string)$maxPrice, 4);
        }

        return [
            'provider_cost_raw'  => $costRaw,
            'provider_cost_base' => $costBase,
            'exchange_rate'      => $effectiveExRate,
            'selling_price'      => $selling,
            'profit'             => Currency::sub($selling, $costBase, 4),
        ];
    }

    public static function notifyUser(int $userId, string $type, string $title, string $message, string $link = '/otp/dashboard.php'): void
    {
        if (Helpers::setting('otp_notify_events', '1') !== '1') {
            return;
        }
        try {
            $safeType = in_array($type, ['otp', 'otp_refund', 'order', 'payment', 'ticket', 'system'], true) ? $type : 'otp';
            Database::insert(
                "INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)",
                [$userId, $safeType, substr($title, 0, 160), $message, substr($link, 0, 255)]
            );
        } catch (Throwable $e) {
            Logger::errorFile('OtpService::notifyUser error: ' . $e->getMessage());
        }
    }

    public static function formatProviderError(string $rawError): string
    {
        $mode = Helpers::setting('otp_provider_error_mode', 'friendly');
        $low = strtolower($rawError);
        if ($mode === 'friendly') {
            if (str_contains($low, 'no free phones') || str_contains($low, 'out of stock') || str_contains($low, 'no_numbers') || str_contains($low, 'no numbers')) {
                return 'No numbers are currently available for this country and service. Please choose another country or try again shortly.';
            }
            if (str_contains($low, 'not enough user balance') || str_contains($low, 'low balance') || str_contains($low, 'no_balance') || str_contains($low, 'no balance')) {
                return 'Service is temporarily unavailable from upstream provider. Please try again later.';
            }
            if (str_contains($low, 'unauthorized') || str_contains($low, 'bad_key') || str_contains($low, '401') || str_contains($low, '403')) {
                return 'Upstream provider authentication failed. Please contact support.';
            }
            if (str_contains($low, 'early_cancel_denied')) {
                return 'Cancellation is not allowed immediately after number assignment. Please wait briefly and try again.';
            }
        }
        return $rawError;
    }

    private static function extractProviderRow(array $row): array
    {
        return [
            'id'                       => $row['prov_id'] ?? $row['provider_id'] ?? 0,
            'name'                     => $row['prov_name'] ?? $row['provider_name'] ?? '',
            'adapter_type'             => $row['adapter_type'] ?? 'fivesim',
            'custom_adapter_class'     => $row['custom_adapter_class'] ?? null,
            'base_url'                 => $row['base_url'] ?? FiveSimAdapter::DEFAULT_BASE_URL,
            'api_key_enc'              => $row['api_key_enc'] ?? '',
            'auth_type'                => $row['auth_type'] ?? 'query',
            'auth_header_name'         => $row['auth_header_name'] ?? 'Authorization',
            'auth_param_name'          => $row['auth_param_name'] ?? 'api_key',
            'request_method'           => $row['request_method'] ?? 'GET',
            'endpoints_json'           => $row['endpoints_json'] ?? '{}',
            'request_params_json'      => $row['request_params_json'] ?? '{}',
            'response_map_json'        => $row['response_map_json'] ?? '{}',
            'status_map_json'          => $row['status_map_json'] ?? '{}',
            'timeout_seconds'          => $row['timeout_seconds'] ?? 20,
            'polling_interval_seconds' => $row['polling_interval_seconds'] ?? 5,
            'currency_code'            => $row['prov_currency'] ?? $row['provider_currency'] ?? 'RUB',
            'exchange_rate'            => $row['prov_ex_rate'] ?? '1',
            'rate_mode'                => $row['prov_rate_mode'] ?? 'auto',
        ];
    }
}
