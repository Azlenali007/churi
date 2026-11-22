<?php
/**
 * Authenticated OTP Store AJAX/Fetch API
 * - Purchasing endpoints enforce OTP Store ON status.
 * - Active activation polling, manual check, cancel & finish remain available even if OTP Store is OFF.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_POST['action'] ?? ($_GET['action'] ?? ''));

if ($method === 'POST') {
    CSRF::requireValid();
}

$user = Auth::user();
if (!$user) {
    Helpers::jsonResponse(['ok' => false, 'error' => 'Please sign in to continue.'], 401);
}

$userId = (int)$user['id'];
$dispCurrency = Currency::getActiveDisplayCurrency($user);
$showFullSms = Helpers::setting('otp_show_full_sms', '1') === '1';

/**
 * Convert any internal/provider technical error into clean customer-safe wording.
 */
$customerSafeError = static function (?string $msg, string $fallback): string {
    $m = trim((string)$msg);
    if ($m === '') {
        return $fallback;
    }
    if (preg_match('/provider|upstream|adapter|curl|5sim|http\s*5\d\d|sqlstate|pdo|json|endpoint|api\s*key|secret|stack|trace|exception|timeout|timed\s*out/i', $m)) {
        if (stripos($m, 'balance') !== false || stripos($m, 'fund') !== false) {
            return 'This option is temporarily unavailable. Please try another country or service.';
        }
        if (stripos($m, 'no number') !== false || stripos($m, 'out of stock') !== false || stripos($m, 'unavailable') !== false) {
            return 'No numbers are currently available for this service and country. Please try another country.';
        }
        return $fallback;
    }
    return $m;
};

/**
 * Format an activation row cleanly for frontend rendering (never leaks provider details or raw errors).
 */
$formatActivation = static function (array $a) use ($dispCurrency, $showFullSms, $customerSafeError): array {
    $now = time();
    $expiresTs = !empty($a['expires_at']) ? strtotime((string)$a['expires_at']) : null;
    $remaining = $expiresTs ? max(0, $expiresTs - $now) : 0;
    $status = (string)$a['status'];

    return [
        'id'                => (int)$a['id'],
        'activation_code'   => (string)$a['activation_code'],
        'source'            => (string)($a['source'] ?? 'direct'),
        'service_id'        => (int)$a['service_id'],
        'service_name'      => (string)($a['service_name'] ?? ''),
        'service_icon'      => (string)($a['service_icon'] ?? 'general'),
        'country_id'        => (int)$a['country_id'],
        'country_name'      => (string)($a['country_name'] ?? ''),
        'country_iso'       => (string)($a['country_iso'] ?? ''),
        'country_flag'      => (string)($a['country_flag'] ?? ''),
        'phone_number'      => (string)($a['phone_number'] ?? ''),
        'otp_code'          => (string)($a['otp_code'] ?? ''),
        'sms_text'          => $showFullSms ? (string)($a['sms_text'] ?? '') : '',
        'sms_received_at'   => $a['sms_received_at'] ? date('M d, H:i:s', strtotime((string)$a['sms_received_at'])) : null,
        'selling_price'     => (string)$a['selling_price'],
        'display_price'     => Currency::display((string)$a['selling_price'], $dispCurrency['code'], 2, true),
        'refunded_amount'   => (string)$a['refunded_amount'],
        'display_refund'    => Currency::display((string)$a['refunded_amount'], $dispCurrency['code'], 2, true),
        'status'            => $status,
        'status_badge'      => Helpers::statusBadge($status),
        'can_cancel'        => !empty($a['can_cancel']) && empty($a['otp_code']) && in_array($status, ['active', 'waiting_otp'], true) && Helpers::setting('otp_allow_user_cancel', '1') === '1',
        'can_complete'      => !empty($a['can_complete']) && in_array($status, ['otp_received', 'waiting_otp', 'active'], true),
        'expires_at'        => $a['expires_at'] ?: null,
        'expires_ts'        => $expiresTs,
        'remaining_seconds' => $remaining,
        'failure_reason'    => !empty($a['failure_reason']) ? $customerSafeError((string)$a['failure_reason'], 'Activation could not be completed.') : '',
        'created_at'        => date('M d, Y H:i', strtotime((string)$a['created_at'])),
    ];
};

// 1. List enabled OTP services (purchasing)
if ($action === 'services') {
    StoreManager::requireOtpEnabled(true);
    $search = Validator::str($_GET['q'] ?? '', 80);
    $services = OtpService::getEnabledServices($search, false, 150);
    $items = [];
    foreach ($services as $s) {
        $items[] = [
            'id'            => (int)$s['id'],
            'name'          => (string)$s['name'],
            'slug'          => (string)$s['slug'],
            'icon_key'      => (string)($s['icon_key'] ?? 'general'),
            'description'   => (string)($s['description'] ?? ''),
            'is_popular'    => !empty($s['is_popular']),
            'selling_price' => (string)$s['selling_price'],
            'display_price' => Currency::cmp((string)$s['selling_price'], '0', 4) > 0
                ? Currency::display((string)$s['selling_price'], $dispCurrency['code'], 2, true)
                : 'Live Price',
        ];
    }
    Helpers::jsonResponse(['ok' => true, 'services' => $items]);
}

// 2. Countries & pricing for a selected service (purchasing)
if ($action === 'countries') {
    StoreManager::requireOtpEnabled(true);
    $serviceId = (int)($_GET['service_id'] ?? 0);
    $live = ((string)($_GET['live'] ?? '0')) === '1';
    if ($serviceId <= 0) {
        Helpers::jsonResponse(['ok' => false, 'error' => 'Please select a valid service.'], 400);
    }

    $res = OtpService::getCountriesForService($serviceId, $live);
    if (empty($res['ok'])) {
        Helpers::jsonResponse(['ok' => false, 'error' => $customerSafeError($res['error'] ?? null, 'Could not load countries right now.')], 400);
    }

    $countries = [];
    foreach ($res['countries'] as $c) {
        $verified = !empty($c['availability_verified']);
        $avail = !empty($c['available']);
        $stock = $c['stock'];
        // Never allow selecting countries verified as unavailable / out of stock
        if ($verified && (!$avail || ($stock !== null && (int)$stock <= 0))) {
            continue;
        }
        $priceBase = (string)$c['selling_price'];
        $countries[] = [
            'country_id'            => (int)$c['country_id'],
            'name'                  => (string)$c['name'],
            'iso_code'              => (string)$c['iso_code'],
            'dial_code'             => (string)$c['dial_code'],
            'flag_emoji'            => (string)$c['flag_emoji'],
            'available'             => $avail,
            'availability_verified' => $verified,
            'stock'                 => $verified && $stock !== null ? (int)$stock : null,
            'selling_price'         => $priceBase,
            'display_price'         => Currency::cmp($priceBase, '0', 4) > 0
                ? Currency::display($priceBase, $dispCurrency['code'], 2, true)
                : 'Check Price',
        ];
    }

    Helpers::jsonResponse([
        'ok'           => true,
        'service_id'   => $serviceId,
        'service_name' => (string)$res['service']['name'],
        'countries'    => $countries,
    ]);
}

// 3. Live availability & price quote for service + country (purchasing)
if ($action === 'quote') {
    StoreManager::requireOtpEnabled(true);
    $serviceId = (int)($_GET['service_id'] ?? 0);
    $countryId = (int)($_GET['country_id'] ?? 0);
    if ($serviceId <= 0 || $countryId <= 0) {
        Helpers::jsonResponse(['ok' => false, 'error' => 'Select both a service and a country.'], 400);
    }

    $quote = OtpService::getLiveQuote($serviceId, $countryId);
    if (empty($quote['ok'])) {
        Helpers::jsonResponse([
            'ok'        => false,
            'available' => false,
            'error'     => $customerSafeError($quote['error'] ?? null, 'This service is temporarily unavailable for the selected country.'),
        ], 200);
    }

    $priceBase = (string)$quote['selling_price'];
    $walletBase = (string)Database::fetchColumn("SELECT wallet_balance FROM users WHERE id = ?", [$userId]);
    $sufficient = Currency::cmp($walletBase, $priceBase, 4) >= 0;

    Helpers::jsonResponse([
        'ok'                    => true,
        'available'             => true,
        'availability_verified' => !empty($quote['verified']),
        'count'                 => !empty($quote['verified']) ? $quote['count'] : null,
        'service_id'            => $serviceId,
        'service_name'          => (string)$quote['service']['name'],
        'country_id'            => $countryId,
        'country_name'          => (string)$quote['country']['name'],
        'country_flag'          => (string)($quote['country']['flag_emoji'] ?? ''),
        'selling_price'         => $priceBase,
        'display_price'         => Currency::display($priceBase, $dispCurrency['code'], 2, true),
        'wallet_balance'        => $walletBase,
        'display_balance'       => Currency::display($walletBase, $dispCurrency['code'], 2, true),
        'sufficient_balance'    => $sufficient,
        'timeout_minutes'       => max(2, (int)Helpers::setting('otp_default_timeout_minutes', '15')),
        'allow_cancel'          => Helpers::setting('otp_allow_user_cancel', '1') === '1',
    ]);
}

// 4. Direct One-Time Activation Purchase (purchasing)
if ($action === 'purchase') {
    if ($method !== 'POST') {
        Helpers::jsonResponse(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }
    StoreManager::requireOtpEnabled(true);

    $serviceId = (int)($_POST['service_id'] ?? 0);
    $countryId = (int)($_POST['country_id'] ?? 0);
    $clientToken = Validator::str($_POST['idempotency_token'] ?? '', 96);

    if ($serviceId <= 0 || $countryId <= 0) {
        Helpers::jsonResponse(['ok' => false, 'error' => 'Please select a service and country first.'], 400);
    }

    $res = OtpService::purchaseActivation($userId, $serviceId, $countryId, $clientToken);
    $walletBase = (string)Database::fetchColumn("SELECT wallet_balance FROM users WHERE id = ?", [$userId]);

    if (empty($res['ok'])) {
        Helpers::jsonResponse([
            'ok'              => false,
            'duplicate'       => !empty($res['duplicate']),
            'uncertain'       => !empty($res['uncertain']),
            'refunded'        => !empty($res['refunded']),
            'activation_id'   => $res['activation_id'] ?? null,
            'error'           => $customerSafeError($res['error'] ?? null, 'Could not complete activation right now. Please try again.'),
            'wallet_balance'  => $walletBase,
            'display_balance' => Currency::display($walletBase, $dispCurrency['code'], 2, true),
        ], !empty($res['duplicate']) ? 409 : 400);
    }

    Helpers::jsonResponse([
        'ok'              => true,
        'activation'      => $formatActivation($res['activation']),
        'wallet_balance'  => $walletBase,
        'display_balance' => Currency::display($walletBase, $dispCurrency['code'], 2, true),
    ]);
}

// 5. Poll active activations (works even when OTP Store is OFF!)
if ($action === 'poll') {
    $singleId = (int)($_GET['id'] ?? 0);
    $force = ((string)($_GET['force'] ?? '0')) === '1';

    if ($singleId > 0) {
        $owned = OtpService::getActivationForUser($singleId, $userId);
        if (!$owned) {
            Helpers::jsonResponse(['ok' => false, 'error' => 'Activation not found.'], 404);
        }
        $sync = OtpService::syncActivationStatus($singleId, $force);
        $fresh = $sync['activation'] ?? $owned;
        $walletBase = (string)Database::fetchColumn("SELECT wallet_balance FROM users WHERE id = ?", [$userId]);

        Helpers::jsonResponse([
            'ok'              => true,
            'server_time'     => time(),
            'activation'      => $formatActivation($fresh),
            'wallet_balance'  => $walletBase,
            'display_balance' => Currency::display($walletBase, $dispCurrency['code'], 2, true),
        ]);
    }

    $active = OtpService::getActiveActivationsForUser($userId);
    $updated = [];
    foreach ($active as $row) {
        $sync = OtpService::syncActivationStatus((int)$row['id'], $force);
        $fresh = $sync['activation'] ?? $row;
        $updated[] = $formatActivation($fresh);
    }

    $walletBase = (string)Database::fetchColumn("SELECT wallet_balance FROM users WHERE id = ?", [$userId]);
    Helpers::jsonResponse([
        'ok'              => true,
        'server_time'     => time(),
        'activations'     => $updated,
        'wallet_balance'  => $walletBase,
        'display_balance' => Currency::display($walletBase, $dispCurrency['code'], 2, true),
    ]);
}

// 6. Force check single activation (works even when OTP Store is OFF!)
if ($action === 'check') {
    $id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
    $owned = OtpService::getActivationForUser($id, $userId);
    if (!$owned) {
        Helpers::jsonResponse(['ok' => false, 'error' => 'Activation not found.'], 404);
    }
    $sync = OtpService::syncActivationStatus($id, true);
    $fresh = $sync['activation'] ?? $owned;
    $walletBase = (string)Database::fetchColumn("SELECT wallet_balance FROM users WHERE id = ?", [$userId]);

    Helpers::jsonResponse([
        'ok'              => !empty($sync['ok']),
        'error'           => !empty($sync['error']) ? $customerSafeError((string)$sync['error'], 'Unable to refresh SMS status right now.') : null,
        'server_time'     => time(),
        'activation'      => $formatActivation($fresh),
        'wallet_balance'  => $walletBase,
        'display_balance' => Currency::display($walletBase, $dispCurrency['code'], 2, true),
    ]);
}

// 7. Cancel active activation (works even when OTP Store is OFF!)
if ($action === 'cancel') {
    if ($method !== 'POST') {
        Helpers::jsonResponse(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        Helpers::jsonResponse(['ok' => false, 'error' => 'Invalid activation ID.'], 400);
    }
    $res = OtpService::cancelActivation($id, $userId, false);
    $walletBase = (string)Database::fetchColumn("SELECT wallet_balance FROM users WHERE id = ?", [$userId]);

    if (empty($res['ok'])) {
        Helpers::jsonResponse([
            'ok'              => false,
            'error'           => $customerSafeError($res['error'] ?? null, 'Could not cancel this activation right now.'),
            'wallet_balance'  => $walletBase,
            'display_balance' => Currency::display($walletBase, $dispCurrency['code'], 2, true),
        ], 400);
    }

    Helpers::jsonResponse([
        'ok'              => true,
        'refunded'        => !empty($res['refunded']),
        'activation'      => $formatActivation($res['activation']),
        'wallet_balance'  => $walletBase,
        'display_balance' => Currency::display($walletBase, $dispCurrency['code'], 2, true),
    ]);
}

// 8. Complete/Finish activation (works even when OTP Store is OFF!)
if ($action === 'finish') {
    if ($method !== 'POST') {
        Helpers::jsonResponse(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        Helpers::jsonResponse(['ok' => false, 'error' => 'Invalid activation ID.'], 400);
    }
    $res = OtpService::finishActivation($id, $userId, false);
    if (empty($res['ok'])) {
        Helpers::jsonResponse(['ok' => false, 'error' => $customerSafeError($res['error'] ?? null, 'Could not complete this activation right now.')], 400);
    }
    Helpers::jsonResponse([
        'ok'         => true,
        'activation' => $formatActivation($res['activation']),
    ]);
}

Helpers::jsonResponse(['ok' => false, 'error' => 'Unknown OTP action.'], 400);
