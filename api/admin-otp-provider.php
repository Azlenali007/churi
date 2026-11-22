<?php
/**
 * Admin AJAX API for OTP Provider Management & Batch Service/Country Importer
 * Actions:
 *  - test_connection         : Real provider API test call -> updates connection_status & balance
 *  - check_balance           : Real provider balance check -> updates balance & last_balance_check
 *  - refresh_provider        : Refresh balance + diagnostics + card stats for a provider
 *  - provider_details        : Sanitized provider config, stats, active job & recent API logs
 *  - toggle_status           : Enable / Disable OTP provider
 *  - delete_provider         : Safely delete unused OTP provider (blocks if activations exist)
 *  - assign_service_provider : Assign an OTP provider to one or more OTP services
 *  - fetch_preview           : Live pricing/service discovery staged in DB + returns paginated preview
 *  - preview_page            : Fast paginated/filtered slice from staged preview job (no re-fetch)
 *  - start_import_job        : Start persistent DB-backed batch import job (`services` or `countries`)
 *  - process_import_batch    : Process next batch of records & return real progress + final report
 *  - import_job_status       : Check status/progress of a specific job or active/latest job for provider
 *  - cancel_import_job       : Cancel an active import job and release provider lock
 *  - run_import              : Legacy synchronous wrapper (kept for compatibility)
 *  - sync_prices             : Sync latest provider prices & recalculate auto-updated selling prices
 *  - sync_availability       : Sync verified availability via provider availability endpoint
 *  - provider_stats          : Fresh provider card statistics from database
 */

if (!defined('IS_ADMIN_CONTEXT')) {
    define('IS_ADMIN_CONTEXT', true);
}

@set_time_limit(90);
@ini_set('memory_limit', '256M');

if (!ob_get_level()) {
    ob_start();
}

require_once __DIR__ . '/../includes/bootstrap.php';

ProviderErrorHandler::startAjaxGuard([
    'scope'    => 'otp',
    'endpoint' => 'admin-otp-provider',
]);

$action = '';
$providerId = 0;

/**
 * Helper to fetch live provider card stats without ever exposing encrypted credentials.
 */
$fetchProviderCardStats = static function (int $pid): ?array {
    if ($pid <= 0) {
        return null;
    }
    $row = Database::fetch(
        "SELECT p.id, p.name, p.slug, p.adapter_type, p.custom_adapter_class, p.base_url,
                (p.api_key_enc IS NOT NULL AND p.api_key_enc != '') AS has_api_key,
                p.auth_type, p.request_method, p.timeout_seconds, p.polling_interval_seconds,
                p.currency_code, p.exchange_rate, p.rate_mode, p.balance,
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
        'custom_adapter_class'        => $row['custom_adapter_class'],
        'base_url'                    => (string)$row['base_url'],
        'has_api_key'                 => !empty($row['has_api_key']),
        'auth_type'                   => (string)$row['auth_type'],
        'request_method'              => (string)$row['request_method'],
        'timeout_seconds'             => (int)$row['timeout_seconds'],
        'polling_interval_seconds'    => (int)$row['polling_interval_seconds'],
        'currency_code'               => (string)$row['currency_code'],
        'exchange_rate'               => (string)$row['exchange_rate'],
        'rate_mode'                   => (string)$row['rate_mode'],
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
    // Verify admin session & permissions cleanly inside try/catch (never crashes on undefined helper)
    $admin = Auth::admin();
    if (!$admin) {
        ProviderErrorHandler::jsonError('Admin authentication required. Please sign in again.', 'UNAUTHENTICATED', 401);
    }

    $canProviders = Auth::adminHasPermission($admin, 'otp.providers');
    $canOtpManage = Auth::adminHasPermission($admin, 'otp.manage');
    if (!$canProviders && !$canOtpManage) {
        ProviderErrorHandler::jsonError('Insufficient admin permissions for OTP provider actions.', 'FORBIDDEN', 403);
    }

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
    $providerId = (int)($input['provider_id'] ?? 0);

    // Restrict non-country actions to otp.providers
    $countrySharedActions = ['start_import_job', 'process_import_batch', 'import_job_status', 'cancel_import_job', 'provider_stats', 'provider_details'];
    if (!$canProviders && !in_array($action, $countrySharedActions, true)) {
        ProviderErrorHandler::jsonError('Permission otp.providers is required for this action.', 'FORBIDDEN', 403);
    }

    if ($method === 'POST') {
        $csrfCandidate = (string)($input['_csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!CSRF::verify($csrfCandidate)) {
            ProviderErrorHandler::jsonError('Invalid or expired security token. Please refresh the page and try again.', 'CSRF_INVALID', 419);
        }
    }

    // Lightweight per-admin, per-provider rate limit for external API sync actions (never throttle batch stepping)
    $heavyActions = ['test_connection', 'check_balance', 'refresh_provider', 'fetch_preview', 'run_import', 'sync_prices', 'sync_availability'];
    if (in_array($action, $heavyActions, true)) {
        $rateKey = '_otp_prov_rl_' . $action . '_' . $providerId;
        $lastTs = (float)($_SESSION[$rateKey] ?? 0);
        $nowTs = microtime(true);
        if (($nowTs - $lastTs) < 0.6) {
            ProviderErrorHandler::jsonError('Please wait a moment before sending another request to this provider.', 'RATE_LIMITED', 429);
        }
        $_SESSION[$rateKey] = $nowTs;
    }

    if ($action === 'provider_stats') {
        if ($providerId <= 0) {
            ProviderErrorHandler::jsonError('Invalid provider ID.', 'INVALID_INPUT', 422);
        }
        $stats = $fetchProviderCardStats($providerId);
        if (!$stats) {
            ProviderErrorHandler::jsonError('OTP provider not found.', 'NOT_FOUND', 404);
        }
        ProviderErrorHandler::jsonSuccess(['provider' => $stats]);
    }

    if ($action === 'provider_details') {
        if ($providerId <= 0) {
            ProviderErrorHandler::jsonError('Invalid provider ID.', 'INVALID_INPUT', 422);
        }
        $stats = $fetchProviderCardStats($providerId);
        if (!$stats) {
            ProviderErrorHandler::jsonError('OTP provider not found.', 'NOT_FOUND', 404);
        }
        $provRow = Database::fetch("SELECT endpoints_json, created_at, updated_at FROM otp_providers WHERE id = ? LIMIT 1", [$providerId]);
        $endpoints = [];
        if ($provRow && !empty($provRow['endpoints_json'])) {
            $decodedEp = json_decode((string)$provRow['endpoints_json'], true);
            if (is_array($decodedEp)) {
                $endpoints = $decodedEp;
            }
        }
        $recentLogs = Database::fetchAll(
            "SELECT id, action, endpoint, http_code, response_time_ms, status, error_message, created_at
             FROM otp_provider_logs
             WHERE provider_id = ?
             ORDER BY id DESC LIMIT 10",
            [$providerId]
        );
        $activationsCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM otp_activations WHERE provider_id = ?", [$providerId]);
        $latestJob = OtpProviderImporter::getActiveOrLatestJob($providerId, null);

        ProviderErrorHandler::jsonSuccess([
            'provider'          => $stats,
            'endpoints'         => $endpoints,
            'activations_count' => $activationsCount,
            'latest_job'        => $latestJob,
            'recent_logs'       => $recentLogs,
            'created_at'        => $provRow['created_at'] ?? null,
            'updated_at'        => $provRow['updated_at'] ?? null,
        ]);
    }

    if ($action === 'import_job_status') {
        $jobId = (int)($input['job_id'] ?? 0);
        if ($jobId > 0) {
            $job = OtpProviderImporter::getJobStatus($jobId);
            if (!$job) {
                ProviderErrorHandler::jsonError('Import job not found.', 'NOT_FOUND', 404);
            }
            ProviderErrorHandler::jsonSuccess([
                'job'      => $job,
                'provider' => $fetchProviderCardStats((int)$job['provider_id']),
            ]);
        }
        if ($providerId <= 0) {
            ProviderErrorHandler::jsonError('Provide job_id or provider_id.', 'INVALID_INPUT', 422);
        }
        $jobType = isset($input['job_type']) && in_array($input['job_type'], ['services', 'countries'], true)
            ? (string)$input['job_type']
            : null;
        $job = OtpProviderImporter::getActiveOrLatestJob($providerId, $jobType);
        ProviderErrorHandler::jsonSuccess([
            'job'      => $job,
            'provider' => $fetchProviderCardStats($providerId),
        ]);
    }

    if ($method !== 'POST') {
        ProviderErrorHandler::jsonError('POST method required for this action.', 'METHOD_NOT_ALLOWED', 405);
    }

    if ($action === 'process_import_batch') {
        $jobId = (int)($input['job_id'] ?? 0);
        if ($jobId <= 0) {
            ProviderErrorHandler::jsonError('Invalid import job ID.', 'INVALID_INPUT', 422);
        }
        $batchSize = isset($input['batch_size']) ? (int)$input['batch_size'] : null;
        $res = OtpProviderImporter::processNextBatch($jobId, $batchSize);
        $pid = (int)($res['job']['provider_id'] ?? $providerId);
        if (!empty($res['done']) && !empty($res['job'])) {
            $j = $res['job'];
            Logger::activity(
                'admin',
                (int)$admin['id'],
                'otp_provider.batch_import',
                'otp_provider',
                (string)$pid,
                "Type={$j['job_type']}, Mode={$j['mode']}, Countries(new={$j['new_countries']},upd={$j['updated_countries']}), Services(new={$j['new_services']},upd={$j['updated_services']})"
            );
        }
        if ($pid > 0) {
            $res['provider'] = $fetchProviderCardStats($pid);
        }
        Helpers::jsonResponse($res, 200);
    }

    if ($action === 'cancel_import_job') {
        $jobId = (int)($input['job_id'] ?? 0);
        if ($jobId <= 0 || $providerId <= 0) {
            ProviderErrorHandler::jsonError('Valid job_id and provider_id are required.', 'INVALID_INPUT', 422);
        }
        $res = OtpProviderImporter::cancelJob($jobId, $providerId);
        $res['provider'] = $fetchProviderCardStats($providerId);
        Helpers::jsonResponse($res, 200);
    }

    if ($providerId <= 0) {
        ProviderErrorHandler::jsonError('Please select a valid OTP provider.', 'INVALID_INPUT', 422);
    }

    $provider = Database::fetch("SELECT * FROM otp_providers WHERE id = ? LIMIT 1", [$providerId]);
    if (!$provider) {
        ProviderErrorHandler::jsonError('OTP provider not found.', 'NOT_FOUND', 404);
    }

    if ($action === 'test_connection' || $action === 'check_balance' || $action === 'refresh_provider') {
        try {
            $adapter = OtpProviderFactory::make($provider);
            $res = ($action === 'check_balance') ? $adapter->getBalance() : $adapter->testConnection();
        } catch (Throwable $adapterErr) {
            $errMsg = ProviderErrorHandler::redact($adapterErr->getMessage());
            ProviderErrorHandler::recordProviderError('otp', $providerId, $errMsg, true);
            ProviderErrorHandler::jsonError(
                $errMsg,
                'ADAPTER_CONFIG_ERROR',
                200,
                [
                    'error_category'    => 'config',
                    'connection_status' => 'failed',
                    'http_code'         => 0,
                    'response_time_ms'  => 0,
                    'provider'          => $fetchProviderCardStats($providerId),
                ]
            );
        }

        if (!empty($res['ok'])) {
            $bal = isset($res['balance']) && is_numeric((string)$res['balance'])
                ? Currency::normalize((string)$res['balance'], 4)
                : null;
            $cur = (string)($res['currency'] ?? $provider['currency_code'] ?: Currency::getBaseCode());
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
            ProviderErrorHandler::jsonSuccess([
                'connection_status' => 'connected',
                'balance'           => $bal,
                'currency'          => $cur,
                'http_code'         => (int)($res['http_code'] ?? 200),
                'response_time_ms'  => (int)($res['response_time_ms'] ?? 0),
                'message'           => $provider['name'] . ' connected! Balance: ' . ($bal !== null ? $bal . ' ' . $cur : 'Verified'),
                'provider'          => $fetchProviderCardStats($providerId),
            ]);
        }

        $err = ProviderErrorHandler::redact((string)($res['error'] ?? 'Connection failed.'));
        $errCategory = (string)($res['error_category'] ?? 'provider');
        $errCode = (string)($res['error_code'] ?? 'PROVIDER_CONNECTION_FAILED');
        ProviderErrorHandler::recordProviderError('otp', $providerId, $err, true);

        ProviderErrorHandler::jsonError(
            $err,
            $errCode,
            200,
            [
                'error_category'    => $errCategory,
                'connection_status' => 'failed',
                'http_code'         => (int)($res['http_code'] ?? 0),
                'response_time_ms'  => (int)($res['response_time_ms'] ?? 0),
                'provider'          => $fetchProviderCardStats($providerId),
            ]
        );
    }

    if ($action === 'toggle_status') {
        $nextStatus = ($provider['status'] === 'active') ? 'inactive' : 'active';
        Database::execute("UPDATE otp_providers SET status = ? WHERE id = ?", [$nextStatus, $providerId]);
        Logger::activity('admin', (int)$admin['id'], 'otp_provider.toggle', 'otp_provider', (string)$providerId, 'Status set to ' . $nextStatus);
        ProviderErrorHandler::jsonSuccess([
            'status'   => $nextStatus,
            'message'  => $provider['name'] . ' is now ' . $nextStatus . '.',
            'provider' => $fetchProviderCardStats($providerId),
        ]);
    }

    if ($action === 'delete_provider') {
        $used = (int)Database::fetchColumn("SELECT COUNT(*) FROM otp_activations WHERE provider_id = ?", [$providerId]);
        if ($used > 0) {
            ProviderErrorHandler::jsonError(
                "Cannot delete provider with {$used} activation record(s). Disable it instead to preserve order history.",
                'PROVIDER_IN_USE',
                200,
                ['provider' => $fetchProviderCardStats($providerId)]
            );
        }
        Database::execute("DELETE FROM otp_providers WHERE id = ?", [$providerId]);
        Logger::activity('admin', (int)$admin['id'], 'otp_provider.delete', 'otp_provider', (string)$providerId, 'Deleted OTP provider ' . $provider['name']);
        ProviderErrorHandler::jsonSuccess([
            'deleted_id' => $providerId,
            'message'    => 'OTP provider "' . $provider['name'] . '" deleted.',
        ]);
    }

    if ($action === 'assign_service_provider') {
        $serviceIds = array_values(array_filter(array_map('intval', (array)($input['service_ids'] ?? []))));
        if (empty($serviceIds)) {
            $singleSvcId = (int)($input['service_id'] ?? 0);
            if ($singleSvcId > 0) {
                $serviceIds = [$singleSvcId];
            }
        }
        if (empty($serviceIds)) {
            ProviderErrorHandler::jsonError('Select at least one OTP service to assign.', 'INVALID_INPUT', 422);
        }
        $ph = implode(',', array_fill(0, count($serviceIds), '?'));
        $params = array_merge([$providerId], $serviceIds);
        $updated = Database::execute("UPDATE otp_services SET provider_id = ? WHERE id IN ({$ph})", $params);
        Logger::activity('admin', (int)$admin['id'], 'otp_provider.assign_services', 'otp_provider', (string)$providerId, "Assigned {$updated} service(s) to {$provider['name']}");
        ProviderErrorHandler::jsonSuccess([
            'updated'  => $updated,
            'message'  => "Assigned {$updated} OTP service(s) to {$provider['name']}.",
            'provider' => $fetchProviderCardStats($providerId),
        ]);
    }

    if ($action === 'fetch_preview') {
        $countryCode = isset($input['country_code']) && trim((string)$input['country_code']) !== ''
            ? trim((string)$input['country_code']) : null;
        $serviceCode = isset($input['service_code']) && trim((string)$input['service_code']) !== ''
            ? trim((string)$input['service_code']) : null;
        $page = max(1, (int)($input['page'] ?? 1));
        $perPage = max(25, min(200, (int)($input['per_page'] ?? 100)));

        $pricingOpts = [
            'pricing_mode'        => (string)($input['pricing_mode'] ?? 'auto'),
            'fixed_selling_price' => $input['fixed_selling_price'] ?? null,
            'profit_margin'       => $input['profit_margin'] ?? Helpers::setting('otp_default_profit_margin', '25.00'),
            'fixed_profit'        => $input['fixed_profit'] ?? '0',
            'min_price'           => $input['min_price'] ?? null,
            'max_price'           => $input['max_price'] ?? null,
        ];

        $preview = OtpProviderImporter::startPreviewAndStage(
            $providerId,
            $countryCode,
            $serviceCode,
            $pricingOpts,
            (int)$admin['id'],
            $page,
            $perPage
        );
        $preview['provider'] = $fetchProviderCardStats($providerId);
        Helpers::jsonResponse($preview, 200);
    }

    if ($action === 'preview_page') {
        $stagedJobId = (int)($input['staged_job_id'] ?? 0);
        if ($stagedJobId <= 0) {
            ProviderErrorHandler::jsonError('No active preview session. Fetch preview first.', 'INVALID_INPUT', 422);
        }
        $pricingOpts = [
            'pricing_mode'        => (string)($input['pricing_mode'] ?? 'auto'),
            'fixed_selling_price' => $input['fixed_selling_price'] ?? null,
            'profit_margin'       => $input['profit_margin'] ?? Helpers::setting('otp_default_profit_margin', '25.00'),
            'fixed_profit'        => $input['fixed_profit'] ?? '0',
            'min_price'           => $input['min_price'] ?? null,
            'max_price'           => $input['max_price'] ?? null,
        ];
        $slice = OtpProviderImporter::getPreviewPage(
            $providerId,
            $stagedJobId,
            max(1, (int)($input['page'] ?? 1)),
            max(25, min(200, (int)($input['per_page'] ?? 100))),
            trim((string)($input['search'] ?? '')),
            trim((string)($input['country_filter'] ?? '')),
            trim((string)($input['status_filter'] ?? 'all')),
            $pricingOpts
        );
        Helpers::jsonResponse($slice, 200);
    }

    if ($action === 'start_import_job') {
        $jobType = (($input['job_type'] ?? 'services') === 'countries') ? 'countries' : 'services';
        $mode = (string)($input['mode'] ?? ($jobType === 'countries' ? 'import_countries' : 'import_all'));

        if ($jobType === 'services') {
            $allowedModes = ['import_all', 'import_by_country', 'import_selected', 'update_existing', 'import_missing_only'];
            if (!in_array($mode, $allowedModes, true)) {
                ProviderErrorHandler::jsonError('Invalid batch import mode selected.', 'INVALID_INPUT', 422);
            }
        }

        $selectedKeys = is_array($input['selected_keys'] ?? null) ? $input['selected_keys'] : [];
        $reuseStagedId = !empty($input['staged_job_id']) ? (int)$input['staged_job_id'] : null;

        $options = [
            'country_code'            => $input['country_code'] ?? null,
            'service_code'            => $input['service_code'] ?? null,
            'pricing_mode'            => (string)($input['pricing_mode'] ?? 'auto'),
            'fixed_selling_price'     => $input['fixed_selling_price'] ?? null,
            'profit_margin'           => $input['profit_margin'] ?? Helpers::setting('otp_default_profit_margin', '25.00'),
            'fixed_profit'            => $input['fixed_profit'] ?? '0',
            'min_price'               => $input['min_price'] ?? null,
            'max_price'               => $input['max_price'] ?? null,
            'auto_price_update'       => !array_key_exists('auto_price_update', $input) || !empty($input['auto_price_update']),
            'override_existing_rules' => !empty($input['override_existing_rules']),
            'import_status'           => (string)($input['import_status'] ?? 'active'),
            'link_services'           => !empty($input['link_services']),
            'batch_size'              => (int)($input['batch_size'] ?? 100),
        ];

        $res = OtpProviderImporter::startBatchJob(
            $providerId,
            (int)$admin['id'],
            $jobType,
            $mode,
            $selectedKeys,
            $options,
            $reuseStagedId
        );
        $res['provider'] = $fetchProviderCardStats($providerId);
        Helpers::jsonResponse($res, 200);
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
            ProviderErrorHandler::jsonError('Invalid import mode selected.', 'INVALID_INPUT', 422);
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
        Helpers::jsonResponse($report, 200);
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
        Helpers::jsonResponse($report, 200);
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
        Helpers::jsonResponse($report, 200);
    }

    ProviderErrorHandler::jsonError('Unknown provider action requested.', 'UNKNOWN_ACTION', 400);
} catch (Throwable $e) {
    $handled = ProviderErrorHandler::handleException($e, [
        'scope'       => 'otp',
        'action'      => $action ?: 'unknown',
        'provider_id' => $providerId,
    ]);
    $status = (int)($handled['http_status'] ?? 422);
    unset($handled['http_status']);
    if ($providerId > 0) {
        try {
            $handled['provider'] = $fetchProviderCardStats($providerId);
        } catch (Throwable $ignored) {
            // Keep clean error response even if stats query failed
        }
    }
    Helpers::jsonResponse($handled, $status);
}
