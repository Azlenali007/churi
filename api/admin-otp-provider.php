<?php
/**
 * Admin AJAX API for OTP Provider Management
 * Actions:
 *  - test_connection    : Real provider getBalance / test call -> updates connection_status & balance
 *  - check_balance      : Real provider getBalance call -> updates balance & last_balance_check
 *  - toggle_status      : Enable / Disable OTP provider
 *  - fetch_preview      : Live getPrices discovery preview with server-calculated prices
 *  - run_import         : Execute any of the 7 automatic service import / sync modes
 *  - sync_prices        : Sync latest provider prices & recalculate auto-updated selling prices
 *  - sync_availability  : Sync verified availability via getNumbersStatus with throttling
 *  - provider_stats     : Fresh provider card statistics from database
 */

if (!defined('IS_ADMIN_CONTEXT')) {
    define('IS_ADMIN_CONTEXT', true);
}

require_once __DIR__ . '/../includes/bootstrap.php';

$admin = Auth::requireAdmin('otp.providers');

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$rawBody = file_get_contents('php://input');
$jsonBody = [];
if ($rawBody !== false && trim($rawBody) !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $jsonBody = $decoded;
    }
}

$input = array_merge($_GET, $_POST, $jsonBody);
$action = trim((string)($input['action'] ?? ''));

if ($method === 'POST') {
    $csrfCandidate = (string)($input['_csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!CSRF::verify($csrfCandidate)) {
        Helpers::jsonResponse(['ok' => false, 'error' => 'Invalid or expired security token. Please refresh the page.'], 419);
    }
}

$providerId = (int)($input['provider_id'] ?? 0);

// Lightweight per-admin, per-provider rate limit for external API sync actions (prevent accidental rapid hammering)
$heavyActions = ['test_connection', 'check_balance', 'fetch_preview', 'run_import', 'sync_prices', 'sync_availability'];
if (in_array($action, $heavyActions, true)) {
    $rateKey = '_otp_prov_rl_' . $action . '_' . $providerId;
    $lastTs = (float)($_SESSION[$rateKey] ?? 0);
    $nowTs = microtime(true);
    if (($nowTs - $lastTs) < 1.0) {
        Helpers::jsonResponse(['ok' => false, 'error' => 'Please wait a moment before sending another request to this provider.'], 429);
    }
    $_SESSION[$rateKey] = $nowTs;
}

/**
 * Helper to fetch live provider card stats without ever exposing encrypted credentials.
 */
$fetchProviderCardStats = static function (int $pid): ?array {
    $row = Database::fetch(
        "SELECT p.id, p.name, p.slug, p.adapter_type, p.base_url, p.currency_code, p.balance,
                p.connection_status, p.last_balance_check, p.last_sync_at, p.last_availability_sync_at,
                p.last_error, p.status,
                (SELECT COUNT(*) FROM otp_services s WHERE s.provider_id = p.id) AS total_imported_services,
                (SELECT COUNT(*) FROM otp_services s WHERE s.provider_id = p.id AND s.status = 'active') AS enabled_services,
                (SELECT COUNT(*) FROM otp_service_provider_mappings m WHERE m.provider_id = p.id) AS total_mappings,
                (SELECT COUNT(*) FROM otp_service_provider_mappings m WHERE m.provider_id = p.id AND m.availability_verified = 1 AND m.available_count > 0 AND m.is_available = 1) AS verified_available_mappings
         FROM otp_providers p WHERE p.id = ? LIMIT 1",
        [$pid]
    );
    if (!$row) {
        return null;
    }
    return [
        'id'                          => (int)$row['id'],
        'name'                        => (string)$row['name'],
        'slug'                        => (string)$row['slug'],
        'adapter_type'                => (string)$row['adapter_type'],
        'base_url'                    => (string)$row['base_url'],
        'currency_code'               => (string)$row['currency_code'],
        'balance'                     => $row['balance'] !== null ? (string)$row['balance'] : null,
        'connection_status'           => (string)($row['connection_status'] ?? 'unknown'),
        'last_balance_check'          => $row['last_balance_check'],
        'last_sync_at'                => $row['last_sync_at'],
        'last_availability_sync_at'   => $row['last_availability_sync_at'],
        'last_error'                  => $row['last_error'],
        'status'                      => (string)$row['status'],
        'total_imported_services'     => (int)$row['total_imported_services'],
        'enabled_services'            => (int)$row['enabled_services'],
        'total_mappings'              => (int)$row['total_mappings'],
        'verified_available_mappings' => (int)$row['verified_available_mappings'],
    ];
};

try {
    if ($action === 'provider_stats') {
        if ($providerId <= 0) {
            Helpers::jsonResponse(['ok' => false, 'error' => 'Invalid provider ID.'], 422);
        }
        $stats = $fetchProviderCardStats($providerId);
        if (!$stats) {
            Helpers::jsonResponse(['ok' => false, 'error' => 'Provider not found.'], 404);
        }
        Helpers::jsonResponse(['ok' => true, 'provider' => $stats]);
    }

    if ($method !== 'POST') {
        Helpers::jsonResponse(['ok' => false, 'error' => 'POST method required for this action.'], 405);
    }

    if ($providerId <= 0) {
        Helpers::jsonResponse(['ok' => false, 'error' => 'Please select a valid OTP provider.'], 422);
    }

    $provider = Database::fetch("SELECT * FROM otp_providers WHERE id = ? LIMIT 1", [$providerId]);
    if (!$provider) {
        Helpers::jsonResponse(['ok' => false, 'error' => 'OTP provider not found.'], 404);
    }

    if ($action === 'test_connection' || $action === 'check_balance') {
        $adapter = OtpProviderFactory::make($provider);
        $res = ($action === 'test_connection') ? $adapter->testConnection() : $adapter->getBalance();

        if (!empty($res['ok'])) {
            $bal = isset($res['balance']) ? Currency::normalize((string)$res['balance'], 4) : null;
            $cur = (string)($res['currency'] ?? $provider['currency_code']);
            Database::execute(
                "UPDATE otp_providers
                 SET balance = COALESCE(?, balance),
                     currency_code = ?,
                     connection_status = 'connected',
                     last_balance_check = NOW(),
                     last_error = NULL
                 WHERE id = ?",
                [$bal, $cur, $providerId]
            );
            Logger::activity('admin', (int)$admin['id'], 'otp_provider.' . $action, 'otp_provider', (string)$providerId, 'Connected; balance: ' . ($bal ?? 'n/a') . ' ' . $cur);
            Helpers::jsonResponse([
                'ok'               => true,
                'balance'          => $bal,
                'currency'         => $cur,
                'http_code'        => (int)($res['http_code'] ?? 200),
                'response_time_ms' => (int)($res['response_time_ms'] ?? 0),
                'message'          => $provider['name'] . ' connected! Balance: ' . ($bal !== null ? $bal . ' ' . $cur : 'Verified'),
                'provider'         => $fetchProviderCardStats($providerId),
            ]);
        }

        $err = (string)($res['error'] ?? 'Connection failed.');
        Database::execute(
            "UPDATE otp_providers SET connection_status = 'failed', last_error = ? WHERE id = ?",
            [substr($err, 0, 500), $providerId]
        );
        Helpers::jsonResponse([
            'ok'               => false,
            'error'            => $err,
            'http_code'        => (int)($res['http_code'] ?? 0),
            'response_time_ms' => (int)($res['response_time_ms'] ?? 0),
            'provider'         => $fetchProviderCardStats($providerId),
        ], 200);
    }

    if ($action === 'toggle_status') {
        $nextStatus = ($provider['status'] === 'active') ? 'inactive' : 'active';
        Database::execute("UPDATE otp_providers SET status = ? WHERE id = ?", [$nextStatus, $providerId]);
        Logger::activity('admin', (int)$admin['id'], 'otp_provider.toggle', 'otp_provider', (string)$providerId, 'Status set to ' . $nextStatus);
        Helpers::jsonResponse([
            'ok'       => true,
            'status'   => $nextStatus,
            'message'  => $provider['name'] . ' is now ' . $nextStatus . '.',
            'provider' => $fetchProviderCardStats($providerId),
        ]);
    }

    if ($action === 'fetch_preview') {
        $countryCode = isset($input['country_code']) && trim((string)$input['country_code']) !== ''
            ? trim((string)$input['country_code']) : null;
        $serviceCode = isset($input['service_code']) && trim((string)$input['service_code']) !== ''
            ? trim((string)$input['service_code']) : null;

        $pricingOpts = [
            'pricing_mode'        => (string)($input['pricing_mode'] ?? 'auto'),
            'fixed_selling_price' => $input['fixed_selling_price'] ?? null,
            'profit_margin'       => $input['profit_margin'] ?? Helpers::setting('otp_default_profit_margin', '25.00'),
            'fixed_profit'        => $input['fixed_profit'] ?? '0',
            'min_price'           => $input['min_price'] ?? null,
            'max_price'           => $input['max_price'] ?? null,
        ];

        $preview = OtpProviderImporter::fetchPreview($providerId, $countryCode, $serviceCode, $pricingOpts);
        $preview['provider'] = $fetchProviderCardStats($providerId);
        Helpers::jsonResponse($preview);
    }

    if ($action === 'run_import') {
        $mode = (string)($input['mode'] ?? 'import_selected');
        $allowedModes = [
            'import_all',
            'import_by_country',
            'import_selected',
            'update_existing',
            'sync_prices',
            'sync_availability',
            'import_missing_only',
        ];
        if (!in_array($mode, $allowedModes, true)) {
            Helpers::jsonResponse(['ok' => false, 'error' => 'Invalid import mode selected.'], 422);
        }

        $selectedKeys = is_array($input['selected_keys'] ?? null) ? $input['selected_keys'] : [];
        $options = [
            'country_code'            => $input['country_code'] ?? null,
            'service_code'            => $input['service_code'] ?? null,
            'country_id'              => $input['country_id'] ?? null,
            'service_ids'             => $input['service_ids'] ?? [],
            'scope'                   => $input['scope'] ?? 'all_enabled',
            'disable_unavailable'     => !empty($input['disable_unavailable']),
            'pricing_mode'            => (string)($input['pricing_mode'] ?? 'auto'),
            'fixed_selling_price'     => $input['fixed_selling_price'] ?? null,
            'profit_margin'           => $input['profit_margin'] ?? Helpers::setting('otp_default_profit_margin', '25.00'),
            'fixed_profit'            => $input['fixed_profit'] ?? '0',
            'min_price'               => $input['min_price'] ?? null,
            'max_price'               => $input['max_price'] ?? null,
            'auto_price_update'       => !array_key_exists('auto_price_update', $input) || !empty($input['auto_price_update']),
            'override_existing_rules' => !empty($input['override_existing_rules']),
            'import_status'           => (string)($input['import_status'] ?? 'active'),
        ];

        $report = OtpProviderImporter::runImport($providerId, $mode, $selectedKeys, $options);
        if (!empty($report['ok'])) {
            Logger::activity(
                'admin',
                (int)$admin['id'],
                'otp_provider.import',
                'otp_provider',
                (string)$providerId,
                "Mode={$mode}, Found=" . (int)($report['total_found'] ?? 0)
                . ", New=" . (int)($report['new_imported'] ?? 0)
                . ", Updated=" . (int)($report['existing_updated'] ?? 0)
            );
        }
        $report['provider'] = $fetchProviderCardStats($providerId);
        Helpers::jsonResponse($report);
    }

    if ($action === 'sync_prices') {
        $countryCode = isset($input['country_code']) && trim((string)$input['country_code']) !== ''
            ? trim((string)$input['country_code']) : null;
        $serviceIds = array_values(array_filter(array_map('intval', (array)($input['service_ids'] ?? []))));
        $report = OtpProviderImporter::syncPrices($providerId, $countryCode, $serviceIds);
        if (!empty($report['ok'])) {
            Logger::activity(
                'admin',
                (int)$admin['id'],
                'otp_provider.sync_prices',
                'otp_provider',
                (string)$providerId,
                'Updated=' . (int)$report['existing_updated'] . ', PricesChanged=' . (int)$report['prices_updated']
            );
        }
        $report['provider'] = $fetchProviderCardStats($providerId);
        Helpers::jsonResponse($report);
    }

    if ($action === 'sync_availability') {
        $scope = in_array($input['scope'] ?? '', ['all_enabled', 'selected_country', 'selected_services'], true)
            ? (string)$input['scope']
            : 'all_enabled';
        $countryId = !empty($input['country_id']) ? (int)$input['country_id'] : null;
        $serviceIds = array_values(array_filter(array_map('intval', (array)($input['service_ids'] ?? []))));
        $disableUnavailable = !empty($input['disable_unavailable']);

        $report = OtpProviderImporter::syncAvailability($providerId, $scope, $countryId, $serviceIds, $disableUnavailable);
        if (!empty($report['ok'])) {
            Logger::activity(
                'admin',
                (int)$admin['id'],
                'otp_provider.sync_availability',
                'otp_provider',
                (string)$providerId,
                "Scope={$scope}, Countries=" . (int)$report['countries_checked'] . ', Checked=' . (int)$report['mappings_checked']
            );
        }
        $report['provider'] = $fetchProviderCardStats($providerId);
        Helpers::jsonResponse($report);
    }

    Helpers::jsonResponse(['ok' => false, 'error' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    Logger::errorFile('api/admin-otp-provider.php error: ' . $e->getMessage());
    Helpers::jsonResponse(['ok' => false, 'error' => 'Provider operation failed: ' . $e->getMessage()], 500);
}
