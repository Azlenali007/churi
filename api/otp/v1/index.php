<?php
/**
 * Versioned OTP Reseller REST API (v1)
 * Base Path: /api/otp/v1/
 * Separate from existing SMM Reseller API (/api/v2.php) — shares User Accounts, Shared Wallet, and OtpService engine.
 *
 * Endpoints:
 *  - GET  /api/otp/v1/balance
 *  - GET  /api/otp/v1/services
 *  - GET  /api/otp/v1/countries
 *  - GET  /api/otp/v1/prices
 *  - POST /api/otp/v1/activation/create
 *  - GET  /api/otp/v1/activation/status
 *  - POST /api/otp/v1/activation/cancel
 *  - POST /api/otp/v1/activation/complete
 *  - GET  /api/otp/v1/activation/history
 */

$apiStartTime = microtime(true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../../includes/bootstrap.php';

// Override global exception handler so OTP API v1 ALWAYS emits standardized JSON without leaking internals
set_exception_handler(static function (Throwable $e): void {
    Logger::errorFile('OTP API v1 Uncaught Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'status'     => 'error',
        'error_code' => 'INTERNAL_ERROR',
        'message'    => 'An unexpected server error occurred. Please try again later.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$clientIp = Helpers::ip();

// Resolve normalized route (supports ?route=... via .htaccess/router.php or PATH_INFO)
$route = trim((string)($_GET['route'] ?? ''), '/');
if ($route === '') {
    $reqPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
    if (preg_match('#/api/otp/v1(?:/(.*))?$#', $reqPath, $m)) {
        $route = trim((string)($m[1] ?? ''), '/');
    }
}
$route = strtolower($route);

// Enforce strict 64KB request body size limit
$maxBodyBytes = 65536;
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$rawBody = file_get_contents('php://input', false, null, 0, $maxBodyBytes + 1);
if ($contentLength > $maxBodyBytes || ($rawBody !== false && strlen($rawBody) > $maxBodyBytes)) {
    http_response_code(413);
    echo json_encode([
        'status'     => 'error',
        'error_code' => 'PAYLOAD_TOO_LARGE',
        'message'    => 'Request payload exceeds the maximum allowed size (64 KB).',
    ]);
    exit;
}

$jsonInput = [];
if ($rawBody !== false && trim($rawBody) !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $jsonInput = $decoded;
    } elseif (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
        http_response_code(400);
        echo json_encode([
            'status'     => 'error',
            'error_code' => 'INVALID_JSON',
            'message'    => 'Malformed JSON request body.',
        ]);
        exit;
    }
}

$input = array_merge($_GET, $_POST, $jsonInput);
unset($input['route']);

// Helper to emit consistent JSON + write sanitized log entry
$authUserId = null;
$authKeyId = null;
$respond = static function (int $httpStatus, bool $ok, string $message, array $data = [], ?string $errorCode = null, array $logMeta = []) use (&$authUserId, &$authKeyId, $route, $method, $clientIp, $apiStartTime): void {
    $elapsedMs = (int)round((microtime(true) - $apiStartTime) * 1000);
    OtpResellerApi::logRequest(
        $authUserId,
        $authKeyId,
        '/api/otp/v1/' . $route,
        $method,
        $httpStatus,
        $elapsedMs,
        $ok,
        $errorCode,
        $logMeta,
        $clientIp
    );

    http_response_code($httpStatus);
    if ($ok) {
        $payload = [
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
        ];
    } else {
        $payload = [
            'status'     => 'error',
            'error_code' => $errorCode ?: 'REQUEST_FAILED',
            'message'    => $message,
        ];
        if (!empty($data)) {
            $payload['data'] = $data;
        }
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

// Map route -> operation name + required HTTP method
$routeMap = [
    'balance'             => ['op' => 'balance',  'method' => 'GET'],
    'services'            => ['op' => 'services', 'method' => 'GET'],
    'countries'           => ['op' => 'countries','method' => 'GET'],
    'prices'              => ['op' => 'prices',   'method' => 'GET'],
    'activation/create'   => ['op' => 'create',   'method' => 'POST'],
    'activation/status'   => ['op' => 'status',   'method' => 'GET'],
    'activation/cancel'   => ['op' => 'cancel',   'method' => 'POST'],
    'activation/complete' => ['op' => 'complete', 'method' => 'POST'],
    'activation/history'  => ['op' => 'history',  'method' => 'GET'],
];

if (!isset($routeMap[$route])) {
    $respond(404, false, 'Unknown OTP API endpoint. See /otp/api-docs.php for available v1 routes.', [], 'ENDPOINT_NOT_FOUND');
}

$expectedMethod = $routeMap[$route]['method'];
$operationName = $routeMap[$route]['op'];
if ($method !== $expectedMethod) {
    $respond(405, false, "HTTP {$expectedMethod} is required for /api/otp/v1/{$route}.", [], 'METHOD_NOT_ALLOWED');
}

// Authenticate request (checks global toggle, key hash, user active, key active, IP allowlist, allowed operation, rate limits)
$rawApiKey = OtpResellerApi::extractApiKeyFromRequest($input);
$auth = OtpResellerApi::authenticateRequest($rawApiKey, $operationName, $clientIp);
$authUserId = $auth['user_id'] ?? null;
$authKeyId = $auth['api_key_id'] ?? null;

if (empty($auth['ok'])) {
    $respond(
        (int)($auth['http_status'] ?? 401),
        false,
        (string)($auth['message'] ?? 'Authentication failed.'),
        [],
        (string)($auth['error_code'] ?? 'INVALID_API_KEY')
    );
}

$userId = (int)$auth['user_id'];
$apiKeyId = (int)$auth['api_key_id'];
$keyRecord = $auth['key_record'];
$baseCurrency = Currency::getBaseCode();

/**
 * Helper to resolve activation by numeric ID or activation_code and enforce strict owner-only access.
 */
$resolveOwnedActivation = static function ($rawId) use ($userId, $respond): array {
    $idStr = trim((string)$rawId);
    if ($idStr === '') {
        $respond(422, false, 'Parameter activation_id is required.', [], 'INVALID_PARAMETER');
    }

    if (ctype_digit($idStr)) {
        $act = OtpService::getActivationById((int)$idStr);
    } else {
        $rowId = Database::fetchColumn("SELECT id FROM otp_activations WHERE activation_code = ? LIMIT 1", [substr($idStr, 0, 40)]);
        $act = $rowId ? OtpService::getActivationById((int)$rowId) : null;
    }

    if (!$act) {
        $respond(404, false, 'Activation not found.', [], 'ACTIVATION_NOT_FOUND', ['activation_ref' => $idStr]);
    }
    if ((int)$act['user_id'] !== $userId) {
        $respond(403, false, 'You do not have permission to access this activation.', [], 'ACTIVATION_NOT_OWNED', ['activation_id' => (int)$act['id']]);
    }
    return $act;
};

/**
 * Format an activation row cleanly for API consumers (never exposes internal provider cost/profit/credentials).
 */
$formatActivation = static function (array $act): array {
    return [
        'activation_id'   => (int)$act['id'],
        'activation_code' => (string)$act['activation_code'],
        'source'          => (string)($act['source'] ?? 'api'),
        'service_id'      => (int)$act['service_id'],
        'service_name'    => (string)($act['service_name'] ?? ''),
        'country_id'      => (int)$act['country_id'],
        'country_name'    => (string)($act['country_name'] ?? ''),
        'country_iso'     => (string)($act['country_iso'] ?? ''),
        'phone_number'    => $act['phone_number'] ?: null,
        'operator'        => (string)($act['provider_operator'] ?? 'any'),
        'price'           => Currency::normalize((string)$act['selling_price'], 4),
        'currency_code'   => (string)$act['currency_code'],
        'status'          => (string)$act['status'],
        'otp_code'        => $act['otp_code'] ?: null,
        'sms_text'        => $act['sms_text'] ?: null,
        'sms_received_at' => $act['sms_received_at'] ?: null,
        'refunded_amount' => Currency::normalize((string)($act['refunded_amount'] ?? '0'), 4),
        'can_cancel'      => ((int)($act['can_cancel'] ?? 0) === 1) && in_array($act['status'], ['active', 'waiting_otp'], true) && empty($act['otp_code']),
        'can_complete'    => ((int)($act['can_complete'] ?? 0) === 1) && in_array($act['status'], ['active', 'waiting_otp', 'otp_received'], true),
        'expires_at'      => $act['expires_at'] ?: null,
        'completed_at'    => $act['completed_at'] ?: null,
        'created_at'      => (string)$act['created_at'],
    ];
};

// ============================================================================
// 1. GET /api/otp/v1/balance
// ============================================================================
if ($route === 'balance') {
    $bal = (string)Database::fetchColumn("SELECT wallet_balance FROM users WHERE id = ?", [$userId]);
    $respond(200, true, 'Wallet balance retrieved.', [
        'balance'       => Currency::normalize($bal, 4),
        'currency_code' => $baseCurrency,
        'username'      => (string)$keyRecord['username'],
    ]);
}

// ============================================================================
// 2. GET /api/otp/v1/services
// ============================================================================
if ($route === 'services') {
    $search = trim((string)($input['search'] ?? ''));
    $popularOnly = !empty($input['popular']);
    $limit = max(1, min(200, (int)($input['limit'] ?? 100)));

    $services = OtpService::getEnabledServices($search, $popularOnly, $limit);
    $items = [];
    foreach ($services as $s) {
        $calc = OtpService::calculateSellingPrice($s, [], $s['provider_price'] !== null ? (string)$s['provider_price'] : null);
        $reseller = OtpResellerApi::calculateResellerPrice(
            $calc['selling_price'],
            $calc['provider_cost_base'],
            (int)$s['id'],
            0,
            $keyRecord
        );
        $items[] = [
            'service_id'    => (int)$s['id'],
            'name'          => (string)$s['name'],
            'slug'          => (string)$s['slug'],
            'icon_key'      => (string)$s['icon_key'],
            'is_popular'    => (int)$s['is_popular'] === 1,
            'starting_price'=> $reseller['reseller_price'],
            'currency_code' => $baseCurrency,
        ];
    }

    $respond(200, true, 'Available OTP services retrieved.', [
        'count'         => count($items),
        'currency_code' => $baseCurrency,
        'services'      => $items,
    ], null, ['search' => $search, 'count' => count($items)]);
}

// ============================================================================
// 3. GET /api/otp/v1/countries
// ============================================================================
if ($route === 'countries') {
    $serviceId = isset($input['service_id']) && $input['service_id'] !== '' ? (int)$input['service_id'] : 0;

    if ($serviceId > 0) {
        $svcCheck = Database::fetch(
            "SELECT s.id, s.provider_id FROM otp_services s JOIN otp_providers p ON p.id = s.provider_id WHERE s.id = ? AND s.status = 'active' AND p.status = 'active' LIMIT 1",
            [$serviceId]
        );
        if (!$svcCheck) {
            $respond(404, false, 'Service not found or inactive.', [], 'INVALID_SERVICE', ['service_id' => $serviceId]);
        }
        $rows = Database::fetchAll(
            "SELECT c.id, c.name, c.iso_code, c.dial_code, c.flag_emoji
             FROM otp_countries c
             LEFT JOIN otp_service_provider_mappings m
               ON m.country_id = c.id AND m.service_id = ? AND m.provider_id = ?
             WHERE c.status = 'active'
               AND (m.id IS NULL OR (m.status = 'active' AND m.is_available = 1))
             ORDER BY c.sort_order ASC, c.name ASC",
            [$serviceId, (int)$svcCheck['provider_id']]
        );
    } else {
        $rows = Database::fetchAll(
            "SELECT id, name, iso_code, dial_code, flag_emoji FROM otp_countries WHERE status = 'active' ORDER BY sort_order ASC, name ASC"
        );
    }

    $items = array_map(static fn(array $c): array => [
        'country_id' => (int)$c['id'],
        'name'       => (string)$c['name'],
        'iso_code'   => (string)$c['iso_code'],
        'dial_code'  => (string)($c['dial_code'] ?? ''),
        'flag_emoji' => (string)($c['flag_emoji'] ?? ''),
    ], $rows);

    $respond(200, true, 'Available countries retrieved.', [
        'count'     => count($items),
        'countries' => $items,
    ], null, ['service_id' => $serviceId ?: null]);
}

// ============================================================================
// 4. GET /api/otp/v1/prices
// ============================================================================
if ($route === 'prices') {
    $serviceId = isset($input['service_id']) && $input['service_id'] !== '' ? (int)$input['service_id'] : 0;
    $countryId = isset($input['country_id']) && $input['country_id'] !== '' ? (int)$input['country_id'] : 0;
    $limit = max(1, min(500, (int)($input['limit'] ?? 200)));

    if ($serviceId > 0) {
        $existsSvc = Database::fetchColumn(
            "SELECT s.id FROM otp_services s JOIN otp_providers p ON p.id = s.provider_id WHERE s.id = ? AND s.status = 'active' AND p.status = 'active' LIMIT 1",
            [$serviceId]
        );
        if (!$existsSvc) {
            $respond(404, false, 'Service not found or inactive.', [], 'INVALID_SERVICE', ['service_id' => $serviceId]);
        }
    }
    if ($countryId > 0) {
        $existsCntry = Database::fetchColumn("SELECT id FROM otp_countries WHERE id = ? AND status = 'active' LIMIT 1", [$countryId]);
        if (!$existsCntry) {
            $respond(404, false, 'Country not found or inactive.', [], 'INVALID_COUNTRY', ['country_id' => $countryId]);
        }
    }

    $sql = "SELECT m.service_id, m.country_id, m.provider_operator AS map_operator,
                   m.provider_price AS map_provider_price, m.provider_cost_base AS map_provider_cost_base,
                   m.custom_selling_price, m.pricing_mode AS map_pricing_mode,
                   m.profit_margin AS map_profit_margin, m.fixed_profit AS map_fixed_profit,
                   m.min_price AS map_min_price, m.max_price AS map_max_price,
                   m.auto_price_update AS map_auto_price_update,
                   m.is_available, m.available_count, m.availability_verified,
                   s.name AS service_name, s.slug AS service_slug, s.provider_price, s.selling_price,
                   s.profit_margin, s.fixed_profit, s.min_price, s.max_price, s.auto_price_update,
                   c.name AS country_name, c.iso_code AS country_iso,
                   p.currency_code AS prov_currency, p.exchange_rate AS prov_ex_rate, p.rate_mode AS prov_rate_mode
            FROM otp_service_provider_mappings m
            JOIN otp_services s ON s.id = m.service_id AND s.status = 'active'
            JOIN otp_countries c ON c.id = m.country_id AND c.status = 'active'
            JOIN otp_providers p ON p.id = m.provider_id AND p.status = 'active'
            WHERE m.status = 'active'";
    $params = [];
    if ($serviceId > 0) {
        $sql .= " AND m.service_id = ?";
        $params[] = $serviceId;
    }
    if ($countryId > 0) {
        $sql .= " AND m.country_id = ?";
        $params[] = $countryId;
    }
    $sql .= " ORDER BY s.name ASC, c.name ASC LIMIT {$limit}";
    $rows = Database::fetchAll($sql, $params);

    $prices = [];
    foreach ($rows as $r) {
        $baseCalc = OtpService::calculateSellingPrice($r, $r, $r['map_provider_price'] !== null ? (string)$r['map_provider_price'] : null);
        $reseller = OtpResellerApi::calculateResellerPrice(
            $baseCalc['selling_price'],
            $baseCalc['provider_cost_base'],
            (int)$r['service_id'],
            (int)$r['country_id'],
            $keyRecord
        );
        $verified = (int)$r['availability_verified'] === 1;
        $available = ((int)$r['is_available'] === 1) && (!$verified || (int)$r['available_count'] > 0);
        $prices[] = [
            'service_id'            => (int)$r['service_id'],
            'service_name'          => (string)$r['service_name'],
            'service_slug'          => (string)$r['service_slug'],
            'country_id'            => (int)$r['country_id'],
            'country_name'          => (string)$r['country_name'],
            'country_iso'           => (string)$r['country_iso'],
            'operator'              => (string)($r['map_operator'] ?: 'any'),
            'price'                 => $reseller['reseller_price'],
            'currency_code'         => $baseCurrency,
            'available'             => $available,
            'availability_verified' => $verified,
            'stock'                 => $verified ? (int)$r['available_count'] : null,
        ];
    }

    $respond(200, true, 'Reseller OTP prices retrieved.', [
        'count'         => count($prices),
        'currency_code' => $baseCurrency,
        'prices'        => $prices,
    ], null, ['service_id' => $serviceId ?: null, 'country_id' => $countryId ?: null]);
}

// ============================================================================
// 5. POST /api/otp/v1/activation/create
// ============================================================================
if ($route === 'activation/create') {
    $serviceId = (int)($input['service_id'] ?? 0);
    $countryId = (int)($input['country_id'] ?? 0);
    $operator = isset($input['operator']) ? trim((string)$input['operator']) : '';

    if ($serviceId <= 0) {
        $respond(422, false, 'Valid service_id is required.', [], 'INVALID_SERVICE');
    }
    if ($countryId <= 0) {
        $respond(422, false, 'Valid country_id is required.', [], 'INVALID_COUNTRY');
    }

    // Optional Idempotency-Key (header or request body) prevents accidental duplicate orders/charges
    $idemKey = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($input['idempotency_key'] ?? '')));
    if ($idemKey !== '' && strlen($idemKey) > 128) {
        $respond(422, false, 'Idempotency-Key must be 128 characters or fewer.', [], 'INVALID_PARAMETER');
    }
    $requestHash = hash('sha256', json_encode(['service_id' => $serviceId, 'country_id' => $countryId, 'operator' => $operator]));

    if ($idemKey !== '') {
        $cached = OtpResellerApi::checkIdempotency($userId, $idemKey, $requestHash);
        if ($cached !== null) {
            if (empty($cached['matched_hash'])) {
                $respond(409, false, 'Idempotency-Key was already used with different request parameters.', [], 'DUPLICATE_REQUEST', ['service_id' => $serviceId, 'country_id' => $countryId]);
            }
            $cachedPayload = json_decode($cached['response_json'], true);
            if (is_array($cachedPayload)) {
                $cachedPayload['idempotent_replay'] = true;
                $elapsedMs = (int)round((microtime(true) - $apiStartTime) * 1000);
                OtpResellerApi::logRequest($userId, $apiKeyId, '/api/otp/v1/activation/create', $method, (int)$cached['response_status'], $elapsedMs, ($cachedPayload['status'] ?? '') === 'success', ($cachedPayload['error_code'] ?? null), ['idempotent_replay' => true, 'activation_id' => $cached['activation_id']], $clientIp);
                http_response_code((int)$cached['response_status']);
                echo json_encode($cachedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
        }
    }

    $limits = OtpResellerApi::getEffectiveLimits($keyRecord);
    $res = OtpService::purchaseActivation($userId, $serviceId, $countryId, $idemKey, [
        'source'              => 'api',
        'api_key_id'          => $apiKeyId,
        'api_key_record'      => $keyRecord,
        'operator'            => $operator !== '' ? $operator : null,
        'max_active_override' => $limits['max_active'],
        'max_daily_override'  => $limits['max_daily'],
    ]);

    if (!empty($res['ok']) && !empty($res['activation'])) {
        $data = $formatActivation($res['activation']);
        $data['balance_after'] = Currency::normalize((string)($res['balance'] ?? '0'), 4);

        if ($idemKey !== '') {
            OtpResellerApi::storeIdempotency($userId, $idemKey, $requestHash, (int)$data['activation_id'], 201, [
                'status'  => 'success',
                'message' => 'OTP activation created and phone number assigned.',
                'data'    => $data,
            ]);
        }
        $respond(201, true, 'OTP activation created and phone number assigned.', $data, null, [
            'service_id'    => $serviceId,
            'country_id'    => $countryId,
            'activation_id' => (int)$data['activation_id'],
        ]);
    }

    // Map failure to accurate standardized error code & HTTP status
    $errCode = (string)($res['error_code'] ?? 'PROVIDER_ERROR');
    $errMsg = (string)($res['error'] ?? 'Could not create OTP activation.');
    $httpCode = 400;

    if (!empty($res['duplicate'])) {
        $errCode = 'DUPLICATE_REQUEST';
        $httpCode = 409;
    } elseif ($errCode === 'INSUFFICIENT_BALANCE' || stripos($errMsg, 'insufficient') !== false || stripos($errMsg, 'minimum wallet') !== false) {
        $errCode = 'INSUFFICIENT_BALANCE';
        $httpCode = 402;
    } elseif ($errCode === 'INVALID_SERVICE') {
        $httpCode = 404;
    } elseif ($errCode === 'INVALID_COUNTRY') {
        $httpCode = 404;
    } elseif ($errCode === 'SERVICE_UNAVAILABLE' || stripos($errMsg, 'no numbers') !== false) {
        $errCode = 'SERVICE_UNAVAILABLE';
        $httpCode = 409;
    } elseif ($errCode === 'RATE_LIMIT_EXCEEDED') {
        $httpCode = 429;
    } elseif (!empty($res['uncertain'])) {
        $errCode = 'PROVIDER_TIMEOUT';
        $httpCode = 504;
    } else {
        $errCode = 'PROVIDER_ERROR';
        $httpCode = 502;
    }

    $extraData = [];
    if (isset($res['refunded'])) {
        $extraData['refunded'] = (bool)$res['refunded'];
    }
    if (isset($res['balance'])) {
        $extraData['balance'] = Currency::normalize((string)$res['balance'], 4);
    }

    if ($idemKey !== '' && $errCode !== 'RATE_LIMIT_EXCEEDED' && $errCode !== 'PROVIDER_TIMEOUT') {
        OtpResellerApi::storeIdempotency($userId, $idemKey, $requestHash, null, $httpCode, [
            'status'     => 'error',
            'error_code' => $errCode,
            'message'    => $errMsg,
            'data'       => $extraData,
        ]);
    }

    $respond($httpCode, false, $errMsg, $extraData, $errCode, ['service_id' => $serviceId, 'country_id' => $countryId]);
}

// ============================================================================
// 6. GET /api/otp/v1/activation/status
// ============================================================================
if ($route === 'activation/status') {
    $act = $resolveOwnedActivation($input['activation_id'] ?? ($input['id'] ?? ''));
    $actId = (int)$act['id'];
    $force = !empty($input['force']);

    $sync = OtpService::syncActivationStatus($actId, $force);
    $fresh = $sync['activation'] ?? OtpService::getActivationById($actId);

    $respond(200, true, 'Activation status retrieved.', $formatActivation($fresh), null, ['activation_id' => $actId, 'status' => $fresh['status']]);
}

// ============================================================================
// 7. POST /api/otp/v1/activation/cancel
// ============================================================================
if ($route === 'activation/cancel') {
    $act = $resolveOwnedActivation($input['activation_id'] ?? ($input['id'] ?? ''));
    $actId = (int)$act['id'];

    if ($act['status'] === 'expired') {
        $respond(409, false, 'Activation has already expired.', $formatActivation($act), 'ACTIVATION_EXPIRED', ['activation_id' => $actId]);
    }
    if (!in_array($act['status'], ['active', 'waiting_otp'], true) || !empty($act['otp_code'])) {
        $respond(409, false, 'Cancellation is not allowed for this activation (OTP already received or activation finalized).', $formatActivation($act), 'CANCELLATION_NOT_ALLOWED', ['activation_id' => $actId]);
    }

    $cancel = OtpService::cancelActivation($actId, $userId, false);
    if (empty($cancel['ok'])) {
        $errMsg = (string)($cancel['error'] ?? 'Cancellation not allowed by provider.');
        $code = stripos($errMsg, 'provider') !== false ? 'PROVIDER_ERROR' : 'CANCELLATION_NOT_ALLOWED';
        $respond(409, false, $errMsg, [], $code, ['activation_id' => $actId]);
    }

    $data = $formatActivation($cancel['activation']);
    $data['refunded'] = !empty($cancel['refunded']);
    $data['balance_after'] = Currency::normalize((string)($cancel['balance'] ?? '0'), 4);
    $respond(200, true, 'Activation cancelled successfully.', $data, null, ['activation_id' => $actId, 'refunded' => $data['refunded']]);
}

// ============================================================================
// 8. POST /api/otp/v1/activation/complete
// ============================================================================
if ($route === 'activation/complete') {
    $act = $resolveOwnedActivation($input['activation_id'] ?? ($input['id'] ?? ''));
    $actId = (int)$act['id'];

    if ($act['status'] === 'expired') {
        $respond(409, false, 'Activation has already expired and cannot be completed.', $formatActivation($act), 'ACTIVATION_EXPIRED', ['activation_id' => $actId]);
    }
    if (!in_array($act['status'], ['active', 'waiting_otp', 'otp_received'], true)) {
        $respond(409, false, 'Activation is already finalized (' . $act['status'] . ').', $formatActivation($act), 'COMPLETION_NOT_ALLOWED', ['activation_id' => $actId]);
    }

    $fin = OtpService::finishActivation($actId, $userId, false);
    if (empty($fin['ok'])) {
        $respond(409, false, (string)($fin['error'] ?? 'Could not complete activation.'), [], 'COMPLETION_NOT_ALLOWED', ['activation_id' => $actId]);
    }

    $respond(200, true, 'Activation completed successfully.', $formatActivation($fin['activation']), null, ['activation_id' => $actId]);
}

// ============================================================================
// 9. GET /api/otp/v1/activation/history
// ============================================================================
if ($route === 'activation/history') {
    $page = max(1, (int)($input['page'] ?? 1));
    $perPage = max(1, min(100, (int)($input['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;

    $status = trim((string)($input['status'] ?? ''));
    $sourceFilter = trim((string)($input['source'] ?? 'api'));
    $serviceId = isset($input['service_id']) && $input['service_id'] !== '' ? (int)$input['service_id'] : 0;
    $countryId = isset($input['country_id']) && $input['country_id'] !== '' ? (int)$input['country_id'] : 0;

    $where = ['a.user_id = ?'];
    $params = [$userId];

    if (in_array($sourceFilter, ['api', 'direct'], true)) {
        $where[] = 'a.source = ?';
        $params[] = $sourceFilter;
    }
    $validStatuses = ['processing', 'active', 'waiting_otp', 'otp_received', 'completed', 'expired', 'canceled', 'refunded', 'failed'];
    if ($status !== '' && in_array($status, $validStatuses, true)) {
        $where[] = 'a.status = ?';
        $params[] = $status;
    }
    if ($serviceId > 0) {
        $where[] = 'a.service_id = ?';
        $params[] = $serviceId;
    }
    if ($countryId > 0) {
        $where[] = 'a.country_id = ?';
        $params[] = $countryId;
    }

    $whereSql = implode(' AND ', $where);
    $total = (int)Database::fetchColumn("SELECT COUNT(*) FROM otp_activations a WHERE {$whereSql}", $params);
    $rows = Database::fetchAll(
        "SELECT a.*, s.name AS service_name, c.name AS country_name, c.iso_code AS country_iso
         FROM otp_activations a
         JOIN otp_services s ON s.id = a.service_id
         JOIN otp_countries c ON c.id = a.country_id
         WHERE {$whereSql}
         ORDER BY a.id DESC
         LIMIT {$perPage} OFFSET {$offset}",
        $params
    );

    $items = array_map($formatActivation, $rows);

    $respond(200, true, 'Activation history retrieved.', [
        'activations' => $items,
        'pagination'  => [
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => max(1, (int)ceil($total / $perPage)),
        ],
    ], null, ['page' => $page, 'source' => $sourceFilter, 'count' => count($items)]);
}
