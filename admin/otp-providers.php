<?php
/**
 * Admin OTP Provider Management Dashboard
 * - Built-in 5SIM API1 Adapter (Exact Endpoint: http://api1.5sim.net/stubs/handler_api.php)
 * - Configurable Custom JSON Providers & Custom PHP Adapters (kept separate from 5SIM)
 * - Automatic Service Import System (7 import/sync modes via live getPrices)
 * - Real-time Availability Synchronization (via getNumbersStatus)
 * - Server-side Price Management & Encrypted API Credentials
 */
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('otp.providers');
$pageTitle = 'OTP Provider Management';

$defaultCustomEndpointsArr = [
    'balance'            => '/api/balance',
    'purchase'           => '/api/purchase',
    'pricing'            => '/api/prices',
    'availability'       => '/api/availability?country={country}',
    'status'             => '/api/status?id={id}',
    'cancel'             => '/api/cancel?id={id}',
    'complete'           => '/api/complete?id={id}',
    'countries_services' => '/api/countries',
];

$defaultCustomParams = json_encode([
    'purchase' => ['country' => '{country}', 'service' => '{service}', 'operator' => '{operator}'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

$defaultResponseMap = json_encode([
    'balance'   => 'balance',
    'available' => 'available',
    'stock'     => 'count',
    'cost'      => 'price',
    'order_id'  => 'order_id',
    'phone'     => 'phone',
    'status'    => 'status',
    'otp_code'  => 'otp_code',
    'sms_text'  => 'sms_text',
    'expires'   => 'expires_at',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

$defaultStatusMap = json_encode([
    'PENDING'   => 'waiting_otp',
    'WAITING'   => 'waiting_otp',
    'RECEIVED'  => 'otp_received',
    'FINISHED'  => 'completed',
    'COMPLETED' => 'completed',
    'CANCELED'  => 'canceled',
    'TIMEOUT'   => 'expired',
    'EXPIRED'   => 'expired',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_provider') {
        $id = (int)($_POST['id'] ?? 0);
        $name = Validator::str($_POST['name'] ?? '', 120);
        $slug = strtolower((string)preg_replace('/[^a-z0-9_-]+/i', '', trim((string)($_POST['slug'] ?? ''))));
        if ($slug === '' && $name !== '') {
            $slug = trim(strtolower((string)preg_replace('/[^a-z0-9]+/i', '_', trim($name))), '_');
        }
        $adapterType = in_array($_POST['adapter_type'] ?? '', ['fivesim', 'custom_json', 'custom_php'], true)
            ? (string)$_POST['adapter_type']
            : 'custom_json';
        $customClass = Validator::str($_POST['custom_adapter_class'] ?? '', 120) ?: null;

        $defaultUrl = $adapterType === 'fivesim' ? FiveSimAdapter::DEFAULT_BASE_URL : '';
        $baseUrl = trim(Validator::str($_POST['base_url'] ?? $defaultUrl, 255));
        if ($adapterType !== 'fivesim') {
            $baseUrl = rtrim($baseUrl, '/');
        }

        $apiKeyRaw = trim((string)($_POST['api_key'] ?? ''));
        $webhookSecretRaw = trim((string)($_POST['webhook_secret'] ?? ''));
        $authType = in_array($_POST['auth_type'] ?? '', ['bearer', 'header', 'query', 'post_body'], true)
            ? (string)$_POST['auth_type']
            : ($adapterType === 'fivesim' ? 'query' : 'bearer');
        $authHeader = Validator::str($_POST['auth_header_name'] ?? 'Authorization', 80) ?: 'Authorization';
        $authParam = Validator::str($_POST['auth_param_name'] ?? 'api_key', 80) ?: 'api_key';
        $reqMethod = (($_POST['request_method'] ?? 'GET') === 'POST') ? 'POST' : 'GET';

        // Build custom endpoints JSON from structured endpoint inputs if provided
        $endpointsJson = trim((string)($_POST['endpoints_json'] ?? ''));
        if ($adapterType === 'custom_json') {
            $structuredEps = [];
            foreach (['balance', 'purchase', 'pricing', 'availability', 'status', 'cancel', 'complete', 'countries_services'] as $epKey) {
                $val = trim((string)($_POST['ep_' . $epKey] ?? ''));
                if ($val !== '') {
                    $structuredEps[$epKey] = $val;
                }
            }
            if (!empty($structuredEps)) {
                $endpointsJson = json_encode($structuredEps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
        } elseif ($adapterType === 'fivesim') {
            $endpointsJson = '';
        }

        $paramsJson = $adapterType === 'custom_json' ? trim((string)($_POST['request_params_json'] ?? '')) : '';
        $respMapJson = $adapterType === 'custom_json' ? trim((string)($_POST['response_map_json'] ?? '')) : '';
        $statusMapJson = $adapterType === 'custom_json' ? trim((string)($_POST['status_map_json'] ?? '')) : '';

        $timeout = max(5, min(60, (int)($_POST['timeout_seconds'] ?? 20)));
        $pollSec = max(2, min(60, (int)($_POST['polling_interval_seconds'] ?? 5)));
        $defaultCurr = $adapterType === 'fivesim' ? 'RUB' : Currency::getBaseCode();
        $currCode = strtoupper(Validator::str($_POST['currency_code'] ?? $defaultCurr, 10)) ?: $defaultCurr;
        $rateMode = (($_POST['rate_mode'] ?? 'auto') === 'manual') ? 'manual' : 'auto';
        $exRate = Currency::normalize((string)($_POST['exchange_rate'] ?? '1.000000'), 6);
        $status = (($_POST['status'] ?? 'active') === 'inactive') ? 'inactive' : 'active';

        if ($name === '' || $slug === '' || $baseUrl === '') {
            Helpers::setFlash('error', 'Provider name, slug, and base URL are required.');
            Helpers::redirect('/admin/otp-providers.php' . ($id > 0 ? '?edit=' . $id : '?tab=settings'));
        }
        $urlErr = OtpHttpClient::validateUrl($baseUrl);
        if ($urlErr !== null) {
            Helpers::setFlash('error', $urlErr);
            Helpers::redirect('/admin/otp-providers.php' . ($id > 0 ? '?edit=' . $id : '?tab=settings'));
        }

        if ($adapterType === 'custom_php' && ($customClass === null || !preg_match('/^[A-Za-z][A-Za-z0-9_]{2,60}$/', $customClass))) {
            Helpers::setFlash('error', 'Custom PHP Adapter Class must be a valid PHP class name inside /core/otp_adapters/.');
            Helpers::redirect('/admin/otp-providers.php' . ($id > 0 ? '?edit=' . $id : '?tab=settings'));
        }

        foreach (['Endpoints' => $endpointsJson, 'Request Parameters' => $paramsJson, 'Response Mapping' => $respMapJson, 'Status Mapping' => $statusMapJson] as $lbl => $jStr) {
            if ($jStr !== '' && json_decode($jStr, true) === null && json_last_error() !== JSON_ERROR_NONE) {
                Helpers::setFlash('error', $lbl . ' must be valid JSON.');
                Helpers::redirect('/admin/otp-providers.php' . ($id > 0 ? '?edit=' . $id : '?tab=settings'));
            }
        }

        try {
            if ($id > 0) {
                $existing = Database::fetch("SELECT api_key_enc, webhook_secret_enc FROM otp_providers WHERE id = ?", [$id]);
                if (!$existing) {
                    Helpers::setFlash('error', 'OTP provider not found.');
                    Helpers::redirect('/admin/otp-providers.php');
                }
                $apiEnc = $apiKeyRaw !== '' ? Helpers::encryptSecret($apiKeyRaw) : ($existing['api_key_enc'] ?? null);
                $whEnc = $webhookSecretRaw !== '' ? Helpers::encryptSecret($webhookSecretRaw) : ($existing['webhook_secret_enc'] ?? null);

                Database::execute(
                    "UPDATE otp_providers
                     SET name = ?, slug = ?, adapter_type = ?, custom_adapter_class = ?, base_url = ?, api_key_enc = ?,
                         auth_type = ?, auth_header_name = ?, auth_param_name = ?, request_method = ?,
                         endpoints_json = ?, request_params_json = ?, response_map_json = ?, status_map_json = ?,
                         timeout_seconds = ?, polling_interval_seconds = ?, webhook_secret_enc = ?,
                         currency_code = ?, exchange_rate = ?, rate_mode = ?, status = ?
                     WHERE id = ?",
                    [
                        $name, $slug, $adapterType, $customClass, $baseUrl, $apiEnc,
                        $authType, $authHeader, $authParam, $reqMethod,
                        $endpointsJson ?: null, $paramsJson ?: null, $respMapJson ?: null, $statusMapJson ?: null,
                        $timeout, $pollSec, $whEnc,
                        $currCode, $exRate, $rateMode, $status, $id,
                    ]
                );
                Helpers::setFlash('success', 'OTP provider "' . $name . '" updated securely.');
            } else {
                $apiEnc = $apiKeyRaw !== '' ? Helpers::encryptSecret($apiKeyRaw) : null;
                $whEnc = $webhookSecretRaw !== '' ? Helpers::encryptSecret($webhookSecretRaw) : null;

                Database::insert(
                    "INSERT INTO otp_providers
                     (name, slug, adapter_type, custom_adapter_class, base_url, api_key_enc, auth_type, auth_header_name,
                      auth_param_name, request_method, endpoints_json, request_params_json, response_map_json, status_map_json,
                      timeout_seconds, polling_interval_seconds, webhook_secret_enc, currency_code, exchange_rate, rate_mode,
                      connection_status, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unknown', ?)",
                    [
                        $name, $slug, $adapterType, $customClass, $baseUrl, $apiEnc, $authType, $authHeader,
                        $authParam, $reqMethod, $endpointsJson ?: null, $paramsJson ?: null, $respMapJson ?: null, $statusMapJson ?: null,
                        $timeout, $pollSec, $whEnc, $currCode, $exRate, $rateMode, $status,
                    ]
                );
                Helpers::setFlash('success', 'OTP provider "' . $name . '" added. Click Test Connection to verify credentials.');
            }
            Logger::activity('admin', (int)$admin['id'], 'otp_provider.save', 'otp_provider', $slug, 'Saved OTP provider ' . $name);
        } catch (Throwable $e) {
            $handled = ProviderErrorHandler::handleException($e, [
                'scope'    => 'otp',
                'action'   => 'save_provider',
                'admin_id' => (int)$admin['id'],
            ], array_filter([$apiKeyRaw, $webhookSecretRaw]));
            Helpers::setFlash('error', $handled['error']);
        }
        Helpers::redirect('/admin/otp-providers.php');
    } elseif ($action === 'delete_provider') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $used = (int)Database::fetchColumn("SELECT COUNT(*) FROM otp_activations WHERE provider_id = ?", [$id]);
            if ($used > 0) {
                Helpers::setFlash('error', "Cannot delete provider with {$used} activation record(s). Disable it instead to preserve order history.");
            } else {
                Database::beginTransaction();
                try {
                    Database::execute("UPDATE otp_services SET provider_id = NULL WHERE provider_id = ?", [$id]);
                    Database::execute("DELETE FROM otp_providers WHERE id = ?", [$id]);
                    Database::commit();
                } catch (Throwable $txErr) {
                    Database::rollBack();
                    throw $txErr;
                }
                Logger::activity('admin', (int)$admin['id'], 'otp_provider.delete', 'otp_provider', (string)$id, 'Deleted OTP provider');
                Helpers::setFlash('success', 'OTP provider deleted.');
            }
        } catch (Throwable $e) {
            $handled = ProviderErrorHandler::handleException($e, [
                'scope'       => 'otp',
                'action'      => 'delete_provider',
                'provider_id' => $id,
                'admin_id'    => (int)$admin['id'],
            ]);
            Helpers::setFlash('error', $handled['error']);
        }
        Helpers::redirect('/admin/otp-providers.php');
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$editProv = $editId > 0 ? Database::fetch("SELECT * FROM otp_providers WHERE id = ?", [$editId]) : null;
$editEndpoints = $editProv && !empty($editProv['endpoints_json'])
    ? (json_decode((string)$editProv['endpoints_json'], true) ?: $defaultCustomEndpointsArr)
    : $defaultCustomEndpointsArr;

$providers = Database::fetchAll(
    "SELECT p.id, p.name, p.slug, p.adapter_type, p.custom_adapter_class, p.base_url,
            (p.api_key_enc IS NOT NULL AND p.api_key_enc != '') AS has_api_key,
            p.auth_type, p.request_method, p.timeout_seconds, p.polling_interval_seconds,
            p.currency_code, p.exchange_rate, p.rate_mode, p.balance, p.connection_status,
            p.last_balance_check, p.last_sync_at, p.last_availability_sync_at, p.last_error, p.status,
            (SELECT COUNT(*) FROM otp_services s WHERE s.provider_id = p.id) AS total_imported_services,
            (SELECT COUNT(*) FROM otp_services s WHERE s.provider_id = p.id AND s.status = 'active') AS enabled_services,
            (SELECT COUNT(*) FROM otp_service_provider_mappings m WHERE m.provider_id = p.id) AS total_mappings,
            (SELECT COUNT(*) FROM otp_service_provider_mappings m WHERE m.provider_id = p.id AND m.availability_verified = 1 AND m.available_count > 0 AND m.is_available = 1) AS verified_available_mappings
     FROM otp_providers p
     ORDER BY FIELD(p.status,'active','inactive'), p.id ASC"
);

$localCountries = Database::fetchAll("SELECT id, name, iso_code, flag_emoji, default_provider_code FROM otp_countries WHERE status = 'active' ORDER BY name ASC");
$localServices = Database::fetchAll("SELECT id, name, slug, provider_id, provider_service_code, status FROM otp_services ORDER BY name ASC");

$recentLogs = Database::fetchAll(
    "SELECT l.id, l.provider_id, l.action, l.endpoint, l.http_code, l.response_time_ms, l.status, l.error_message, l.response_payload, l.created_at,
            p.name AS provider_name
     FROM otp_provider_logs l
     LEFT JOIN otp_providers p ON p.id = l.provider_id
     ORDER BY l.id DESC LIMIT 12"
);

$defaultMargin = Helpers::setting('otp_default_profit_margin', '25.00');
$baseCurrencyCode = Currency::getBaseCode();
$baseCurrencySymbol = Currency::getSymbol($baseCurrencyCode);

$providersJsPayload = array_map(static function (array $p): array {
    return [
        'id'                          => (int)$p['id'],
        'name'                        => (string)$p['name'],
        'slug'                        => (string)$p['slug'],
        'adapter_type'                => (string)$p['adapter_type'],
        'base_url'                    => (string)$p['base_url'],
        'has_api_key'                 => !empty($p['has_api_key']),
        'currency_code'               => (string)$p['currency_code'],
        'balance'                     => $p['balance'] !== null ? (string)$p['balance'] : null,
        'connection_status'           => (string)($p['connection_status'] ?? 'unknown'),
        'last_balance_check'          => $p['last_balance_check'],
        'last_sync_at'                => $p['last_sync_at'],
        'last_availability_sync_at'   => $p['last_availability_sync_at'],
        'last_error'                  => $p['last_error'],
        'status'                      => (string)$p['status'],
        'total_imported_services'     => (int)$p['total_imported_services'],
        'enabled_services'            => (int)$p['enabled_services'],
        'total_mappings'              => (int)$p['total_mappings'],
        'verified_available_mappings' => (int)$p['verified_available_mappings'],
    ];
}, $providers);

$defaultTab = $editProv ? 'settings' : (isset($_GET['tab']) && in_array($_GET['tab'], ['dashboard', 'import', 'availability', 'settings'], true) ? $_GET['tab'] : 'dashboard');

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div x-data="otpProviderHub()" x-init="init()" class="space-y-6">
    <!-- Top Header & Workspace Tabs -->
    <div class="smm-card rounded-3xl p-5 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-purple-600 to-violet-700 text-white flex items-center justify-center shadow-md shadow-purple-600/20">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7"/></svg>
                </div>
                <div>
                    <h2 class="text-lg font-extrabold text-slate-900">OTP Provider Management</h2>
                    <p class="text-xs text-slate-500">Connect and manage multiple OTP providers (Custom REST/JSON, Custom PHP Adapters, or optional 5SIM API1), auto-import services, and sync live stock.</p>
                </div>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <button type="button" @click="activeTab = 'dashboard'"
                    :class="activeTab === 'dashboard' ? 'bg-purple-600 text-white shadow' : 'bg-purple-50 text-purple-800 hover:bg-purple-100'"
                    class="px-3.5 py-2 rounded-xl font-extrabold transition">Providers (<span x-text="providers.length"></span>)</button>
            <button type="button" @click="openImportWorkspace(selectedProviderId || (providers[0]?.id || 0))"
                    :class="activeTab === 'import' ? 'bg-purple-600 text-white shadow' : 'bg-purple-50 text-purple-800 hover:bg-purple-100'"
                    class="px-3.5 py-2 rounded-xl font-extrabold transition">Auto Service Import</button>
            <button type="button" @click="openAvailabilityWorkspace(selectedProviderId || (providers[0]?.id || 0))"
                    :class="activeTab === 'availability' ? 'bg-purple-600 text-white shadow' : 'bg-purple-50 text-purple-800 hover:bg-purple-100'"
                    class="px-3.5 py-2 rounded-xl font-extrabold transition">Availability Sync</button>
            <button type="button" @click="activeTab = 'settings'"
                    :class="activeTab === 'settings' ? 'bg-slate-900 text-white shadow' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
                    class="px-3.5 py-2 rounded-xl font-extrabold transition"><?= $editProv ? 'Edit Provider' : '+ Add Provider' ?></button>
            <a href="/admin/otp-provider-logs.php" class="px-3.5 py-2 rounded-xl bg-white border border-purple-200 text-purple-700 font-extrabold hover:bg-purple-50 transition">API Logs</a>
        </div>
    </div>

    <!-- Live Status Toast Banner -->
    <template x-if="notice.message">
        <div :class="notice.type === 'error' ? 'bg-rose-50 border-rose-200 text-rose-800' : 'bg-emerald-50 border-emerald-200 text-emerald-800'"
             class="rounded-2xl border px-4 py-3 text-xs font-bold flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full shrink-0" :class="notice.type === 'error' ? 'bg-rose-500' : 'bg-emerald-500'"></span>
                <span x-text="notice.message"></span>
            </div>
            <button type="button" @click="notice.message = ''" class="text-slate-400 hover:text-slate-700 font-extrabold text-sm">&times;</button>
        </div>
    </template>

    <!-- ====================================================================== -->
    <!-- TAB 1: PROVIDER CARDS DASHBOARD                                        -->
    <!-- ====================================================================== -->
    <div x-show="activeTab === 'dashboard'" x-cloak class="space-y-6">
        <template x-if="providers.length === 0">
            <div class="smm-card rounded-3xl p-10 text-center space-y-3">
                <div class="w-14 h-14 mx-auto rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                </div>
                <h3 class="text-base font-extrabold text-slate-900">No OTP Providers Configured Yet</h3>
                <p class="text-xs text-slate-500 max-w-md mx-auto">Add any Custom REST/JSON OTP provider, Custom PHP adapter, or optional 5SIM API1 provider to test connectivity, fetch real balances, and automatically import services.</p>
                <div class="pt-2">
                    <button type="button" @click="activeTab = 'settings'" class="px-5 py-2.5 rounded-xl bg-purple-600 text-white text-xs font-extrabold shadow hover:bg-purple-700">Configure First OTP Provider</button>
                </div>
            </div>
        </template>

        <div class="grid md:grid-cols-2 gap-6" x-show="providers.length > 0">
            <template x-for="p in providers" :key="p.id">
                <div class="smm-card rounded-3xl p-4 sm:p-6 flex flex-col justify-between gap-4 border border-purple-100/80 min-w-0 overflow-hidden">
                    <div class="space-y-4 min-w-0">
                        <!-- Card Header -->
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-base font-extrabold text-slate-900 truncate" x-text="p.name"></h3>
                                    <span class="px-2 py-0.5 rounded-lg text-[10px] font-extrabold uppercase tracking-wide"
                                          :class="p.adapter_type === 'fivesim' ? 'bg-purple-100 text-purple-800' : (p.adapter_type === 'custom_php' ? 'bg-indigo-100 text-indigo-800' : 'bg-sky-100 text-sky-800')"
                                          x-text="adapterLabel(p.adapter_type)"></span>
                                    <span class="px-2 py-0.5 rounded-lg text-[10px] font-extrabold uppercase"
                                          :class="p.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'"
                                          x-text="p.status === 'active' ? 'Active' : 'Inactive'"></span>
                                </div>
                                <div class="text-[11px] font-mono text-slate-500 truncate mt-1" x-text="p.base_url"></div>
                            </div>
                            <div class="text-right shrink-0">
                                <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-[11px] font-extrabold"
                                     :class="p.connection_status === 'connected' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : (p.connection_status === 'failed' ? 'bg-rose-50 text-rose-700 border border-rose-200' : 'bg-amber-50 text-amber-700 border border-amber-200')">
                                    <span class="w-2 h-2 rounded-full"
                                          :class="p.connection_status === 'connected' ? 'bg-emerald-500' : (p.connection_status === 'failed' ? 'bg-rose-500' : 'bg-amber-500')"></span>
                                    <span x-text="connectionLabel(p.connection_status)"></span>
                                </div>
                            </div>
                        </div>

                        <!-- Real Metrics Grid -->
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 text-xs">
                            <div class="p-3 rounded-2xl bg-[#FAF8FF] border border-purple-100">
                                <div class="text-[10px] font-bold uppercase text-slate-400">Provider Balance</div>
                                <div class="font-extrabold text-purple-800 font-mono mt-0.5 text-sm"
                                     x-text="p.balance !== null ? (Number(p.balance).toFixed(2) + ' ' + p.currency_code) : 'Not Checked'"></div>
                            </div>
                            <div class="p-3 rounded-2xl bg-[#FAF8FF] border border-purple-100">
                                <div class="text-[10px] font-bold uppercase text-slate-400">Imported Services</div>
                                <div class="font-extrabold text-slate-900 mt-0.5 text-sm">
                                    <span x-text="p.total_imported_services"></span>
                                    <span class="text-[10px] font-semibold text-slate-400" x-text="'(' + p.total_mappings + ' pairs)'"></span>
                                </div>
                            </div>
                            <div class="p-3 rounded-2xl bg-[#FAF8FF] border border-purple-100">
                                <div class="text-[10px] font-bold uppercase text-slate-400">Enabled Services</div>
                                <div class="font-extrabold text-emerald-700 mt-0.5 text-sm">
                                    <span x-text="p.enabled_services"></span>
                                    <span class="text-[10px] font-semibold text-slate-400" x-text="'/ ' + p.verified_available_mappings + ' live'"></span>
                                </div>
                            </div>
                            <div class="p-3 rounded-2xl bg-[#FAF8FF] border border-purple-100">
                                <div class="text-[10px] font-bold uppercase text-slate-400">Last API Sync</div>
                                <div class="font-bold text-slate-700 mt-0.5 text-[11px] truncate" x-text="formatDate(p.last_sync_at)"></div>
                            </div>
                        </div>

                        <!-- Timestamps & Webhook Info -->
                        <div class="flex flex-wrap items-center justify-between gap-2 text-[11px] text-slate-500">
                            <div>
                                <span class="font-semibold text-slate-600">Balance Checked:</span>
                                <span x-text="formatDate(p.last_balance_check)"></span>
                                <span class="mx-1.5 text-slate-300">|</span>
                                <span class="font-semibold text-slate-600">Availability Sync:</span>
                                <span x-text="formatDate(p.last_availability_sync_at)"></span>
                            </div>
                            <div>
                                <span class="font-semibold text-slate-600">API Key:</span>
                                <span :class="p.has_api_key ? 'text-emerald-700 font-bold' : 'text-rose-600 font-bold'" x-text="p.has_api_key ? 'Encrypted' : 'Missing'"></span>
                            </div>
                        </div>

                        <!-- Last API Error -->
                        <template x-if="p.last_error">
                            <div class="p-3 rounded-2xl bg-rose-50 border border-rose-200 text-[11px] text-rose-800">
                                <span class="font-extrabold uppercase text-[10px] mr-1">Last API Error:</span>
                                <span class="font-semibold break-words" x-text="p.last_error"></span>
                            </div>
                        </template>
                    </div>

                    <!-- Provider Card Actions -->
                    <div class="pt-3 border-t border-purple-100/80 flex flex-wrap items-center gap-1.5 text-[11px]">
                        <button type="button" @click="openImportWorkspace(p.id)"
                                class="px-3 py-1.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-extrabold transition">Manage Provider</button>
                        <button type="button" @click="openProviderDetails(p.id)"
                                class="px-3 py-1.5 rounded-xl bg-white hover:bg-purple-50 border border-purple-200 text-purple-800 font-bold transition">Details</button>
                        <a :href="'/admin/otp-providers.php?edit=' + p.id"
                           class="px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold transition">Edit Settings</a>
                        <button type="button" @click="runQuickAction(p.id, 'test_connection')" :disabled="busyProvider === p.id"
                                class="px-3 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white font-bold transition">
                            <span x-text="busyProvider === p.id && busyAction === 'test_connection' ? 'Testing...' : 'Test Connection'"></span>
                        </button>
                        <button type="button" @click="runQuickAction(p.id, 'check_balance')" :disabled="busyProvider === p.id"
                                class="px-3 py-1.5 rounded-xl bg-purple-50 hover:bg-purple-100 text-purple-800 font-bold transition">
                            <span x-text="busyProvider === p.id && busyAction === 'check_balance' ? 'Checking...' : 'Check Balance'"></span>
                        </button>
                        <button type="button" @click="runQuickAction(p.id, 'refresh_provider')" :disabled="busyProvider === p.id"
                                class="px-3 py-1.5 rounded-xl bg-teal-50 hover:bg-teal-100 text-teal-800 border border-teal-200 font-bold transition">
                            <span x-text="busyProvider === p.id && busyAction === 'refresh_provider' ? 'Refreshing...' : 'Refresh'"></span>
                        </button>
                        <button type="button" @click="openImportWorkspace(p.id, true)"
                                class="px-3 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold transition">Import Services</button>
                        <button type="button" @click="runQuickAction(p.id, 'sync_prices')" :disabled="busyProvider === p.id"
                                class="px-3 py-1.5 rounded-xl bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-200 font-bold transition">
                            <span x-text="busyProvider === p.id && busyAction === 'sync_prices' ? 'Syncing...' : 'Sync Prices'"></span>
                        </button>
                        <button type="button" @click="openAvailabilityWorkspace(p.id)"
                                class="px-3 py-1.5 rounded-xl bg-sky-50 hover:bg-sky-100 text-sky-800 border border-sky-200 font-bold transition">Sync Availability</button>
                        <a :href="'/admin/otp-provider-logs.php?provider_id=' + p.id"
                           class="px-3 py-1.5 rounded-xl bg-white hover:bg-slate-50 border border-slate-200 text-slate-700 font-bold transition">View API Logs</a>
                        <button type="button" @click="runQuickAction(p.id, 'toggle_status')" :disabled="busyProvider === p.id"
                                :class="p.status === 'active' ? 'bg-rose-50 text-rose-700 hover:bg-rose-100' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100'"
                                class="px-3 py-1.5 rounded-xl font-bold transition"
                                x-text="p.status === 'active' ? 'Disable' : 'Enable'"></button>
                    </div>
                </div>
            </template>
        </div>

        <!-- Recent Sanitized API Logs Strip -->
        <div class="smm-card rounded-3xl overflow-hidden">
            <div class="px-6 py-4 border-b border-purple-50 flex items-center justify-between gap-2">
                <div>
                    <h3 class="text-sm font-extrabold text-slate-900">Recent Provider API Calls (Sanitized)</h3>
                    <p class="text-[11px] text-slate-500">API keys and credentials are automatically redacted before storage.</p>
                </div>
                <a href="/admin/otp-provider-logs.php" class="text-xs font-extrabold text-purple-600 hover:text-purple-800">Open Full Logs &rarr;</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-[#FAF8FF] text-slate-500 uppercase text-[10px] font-extrabold">
                        <tr>
                            <th class="px-4 py-2.5">Provider</th>
                            <th class="px-4 py-2.5">Action</th>
                            <th class="px-4 py-2.5">Status / HTTP</th>
                            <th class="px-4 py-2.5">Response Time</th>
                            <th class="px-4 py-2.5">Details</th>
                            <th class="px-4 py-2.5 text-right">Time</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-purple-50">
                        <?php if (empty($recentLogs)): ?>
                        <tr><td colspan="6" class="px-4 py-6 text-center text-slate-400">No provider API calls logged yet.</td></tr>
                        <?php else: foreach ($recentLogs as $lg): ?>
                        <tr class="<?= $lg['status'] === 'error' ? 'bg-rose-50/40' : '' ?>">
                            <td class="px-4 py-2.5 font-bold text-slate-800"><?= Validator::e($lg['provider_name'] ?: 'Provider') ?></td>
                            <td class="px-4 py-2.5 font-mono text-[11px] text-purple-700"><?= Validator::e($lg['action']) ?></td>
                            <td class="px-4 py-2.5">
                                <span class="px-2 py-0.5 rounded-lg text-[10px] font-extrabold <?= $lg['status'] === 'ok' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' ?>">
                                    <?= $lg['status'] === 'ok' ? 'OK' : 'ERROR' ?> · HTTP <?= (int)$lg['http_code'] ?>
                                </span>
                            </td>
                            <td class="px-4 py-2.5 font-mono text-[11px] text-slate-600"><?= $lg['response_time_ms'] !== null ? ((int)$lg['response_time_ms'] . ' ms') : '—' ?></td>
                            <td class="px-4 py-2.5 max-w-xs truncate font-mono text-[10px] <?= !empty($lg['error_message']) ? 'text-rose-700 font-semibold' : 'text-slate-500' ?>">
                                <?= Validator::e($lg['error_message'] ?: substr((string)$lg['response_payload'], 0, 120)) ?>
                            </td>
                            <td class="px-4 py-2.5 text-right text-[11px] text-slate-400 whitespace-nowrap"><?= date('M d, H:i:s', strtotime($lg['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ====================================================================== -->
    <!-- TAB 2: AUTOMATIC SERVICE IMPORT SYSTEM (getPrices + 7 Modes + Report)  -->
    <!-- ====================================================================== -->
    <div x-show="activeTab === 'import'" x-cloak class="space-y-6">
        <div class="smm-card rounded-3xl p-6 space-y-5">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 border-b border-purple-100 pb-4">
                <div>
                    <h3 class="text-base font-extrabold text-slate-900">Automatic OTP Service Discovery &amp; Import</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Connects to the provider's <code class="font-mono bg-purple-50 text-purple-700 px-1 rounded">getPrices</code> endpoint to discover actual services, countries, and provider costs. Never invents missing services.</p>
                </div>
                <div class="flex items-center gap-2 text-xs">
                    <label class="font-bold text-slate-600">Provider:</label>
                    <select x-model.number="selectedProviderId" class="px-3 py-2 rounded-xl border border-purple-200 font-bold text-slate-800 bg-white">
                        <template x-for="p in providers" :key="p.id">
                            <option :value="p.id" x-text="p.name + ' (' + adapterLabel(p.adapter_type) + ')'"></option>
                        </template>
                    </select>
                </div>
            </div>

            <!-- Step 1: API Fetch Filters & Server-Side Pricing Rules -->
            <div class="grid lg:grid-cols-12 gap-5 text-xs">
                <div class="lg:col-span-4 p-4 rounded-2xl bg-[#FAF8FF] border border-purple-100 space-y-3">
                    <div class="font-extrabold uppercase text-[11px] text-purple-800">1. API Discovery Scope (getPrices)</div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Provider Country ID (Optional)</label>
                        <input type="text" x-model="importForm.apiCountry" placeholder="Leave blank for all countries, or e.g. 22"
                               class="w-full px-3 py-2 rounded-xl border border-purple-200 bg-white font-mono">
                        <p class="text-[10px] text-slate-400 mt-1">Supports country-wise fetching when required by the API.</p>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Provider Service ID (Optional)</label>
                        <input type="text" x-model="importForm.apiService" placeholder="Leave blank for all, or e.g. wa, tg, ig"
                               class="w-full px-3 py-2 rounded-xl border border-purple-200 bg-white font-mono">
                    </div>
                    <button type="button" @click="fetchServicePreview()" :disabled="importLoading || !selectedProviderId"
                            class="w-full py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white font-extrabold shadow transition">
                        <span x-text="importLoading ? 'Connecting & Fetching getPrices...' : 'Fetch Live Services Preview'"></span>
                    </button>
                </div>

                <div class="lg:col-span-8 p-4 rounded-2xl bg-[#FAF8FF] border border-purple-100 space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="font-extrabold uppercase text-[11px] text-purple-800">2. Server-Side Price Management Rules (Base: <?= Validator::e($baseCurrencyCode) ?>)</div>
                        <span class="text-[10px] text-slate-500">All user prices are calculated server-side with explicit currency conversion.</span>
                    </div>
                    <div class="grid sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block font-bold text-slate-600 mb-1">Pricing Mode</label>
                            <select x-model="importForm.pricing_mode" class="w-full px-3 py-2 rounded-xl border border-purple-200 bg-white font-semibold">
                                <option value="auto">Dynamic (Provider Cost + Profit)</option>
                                <option value="fixed">Fixed Selling Price</option>
                            </select>
                        </div>
                        <div x-show="importForm.pricing_mode === 'fixed'">
                            <label class="block font-bold text-slate-600 mb-1">Fixed Selling Price (<?= Validator::e($baseCurrencyCode) ?>)</label>
                            <input type="number" step="0.01" min="0" x-model="importForm.fixed_selling_price" placeholder="e.g. 25.00"
                                   class="w-full px-3 py-2 rounded-xl border border-purple-200 bg-white font-mono">
                        </div>
                        <div x-show="importForm.pricing_mode === 'auto'">
                            <label class="block font-bold text-slate-600 mb-1">Profit Margin (%)</label>
                            <input type="number" step="0.01" min="0" x-model="importForm.profit_margin"
                                   class="w-full px-3 py-2 rounded-xl border border-purple-200 bg-white font-mono">
                        </div>
                        <div x-show="importForm.pricing_mode === 'auto'">
                            <label class="block font-bold text-slate-600 mb-1">Fixed Profit Amount (<?= Validator::e($baseCurrencyCode) ?>)</label>
                            <input type="number" step="0.01" min="0" x-model="importForm.fixed_profit"
                                   class="w-full px-3 py-2 rounded-xl border border-purple-200 bg-white font-mono">
                        </div>
                    </div>
                    <div class="grid sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block font-bold text-slate-600 mb-1">Minimum Selling Price (<?= Validator::e($baseCurrencyCode) ?>)</label>
                            <input type="number" step="0.01" min="0" x-model="importForm.min_price" placeholder="Optional floor"
                                   class="w-full px-3 py-2 rounded-xl border border-purple-200 bg-white font-mono">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-600 mb-1">Maximum Selling Price (<?= Validator::e($baseCurrencyCode) ?>)</label>
                            <input type="number" step="0.01" min="0" x-model="importForm.max_price" placeholder="Optional cap"
                                   class="w-full px-3 py-2 rounded-xl border border-purple-200 bg-white font-mono">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-600 mb-1">Default Imported Status</label>
                            <select x-model="importForm.import_status" class="w-full px-3 py-2 rounded-xl border border-purple-200 bg-white font-semibold">
                                <option value="active">Active (Enabled)</option>
                                <option value="inactive">Inactive (Draft)</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-5 pt-1">
                        <label class="inline-flex items-center gap-2 font-bold text-slate-700 cursor-pointer">
                            <input type="checkbox" x-model="importForm.auto_price_update" class="accent-purple-600">
                            <span>Enable Automatic Price Update on future syncs</span>
                        </label>
                        <label class="inline-flex items-center gap-2 font-bold text-slate-700 cursor-pointer">
                            <input type="checkbox" x-model="importForm.override_existing_rules" class="accent-purple-600">
                            <span>Apply pricing rules to existing mappings</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- 7 Import / Sync Action Modes Bar -->
            <div class="p-4 rounded-2xl bg-slate-900 text-white space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-[11px] font-extrabold uppercase tracking-wider text-purple-300">3. Execute Import / Sync Mode</span>
                    <span class="text-[11px] text-slate-300">Selected in preview: <strong class="text-white" x-text="selectedKeys.length"></strong> / <span x-text="previewItems.length"></span></span>
                </div>
                <div class="flex flex-wrap gap-2 text-xs">
                    <button type="button" @click="executeImport('import_all')" :disabled="importExecuting || !selectedProviderId"
                            class="px-3.5 py-2 rounded-xl bg-purple-600 hover:bg-purple-500 disabled:opacity-50 font-extrabold transition">1. Import All Available</button>
                    <button type="button" @click="executeImport('import_by_country')" :disabled="importExecuting || !selectedProviderId || (!previewFilterCountry && !importForm.apiCountry)"
                            class="px-3.5 py-2 rounded-xl bg-violet-600 hover:bg-violet-500 disabled:opacity-50 font-extrabold transition">2. Import Selected Country</button>
                    <button type="button" @click="executeImport('import_selected')" :disabled="importExecuting || selectedKeys.length === 0"
                            class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 font-extrabold transition">3. Import Selected (<span x-text="selectedKeys.length"></span>)</button>
                    <button type="button" @click="executeImport('update_existing')" :disabled="importExecuting || !selectedProviderId"
                            class="px-3.5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 font-extrabold transition">4. Update Existing</button>
                    <button type="button" @click="executeImport('sync_prices')" :disabled="importExecuting || !selectedProviderId"
                            class="px-3.5 py-2 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 disabled:opacity-50 font-extrabold transition">5. Sync Latest Prices</button>
                    <button type="button" @click="openAvailabilityWorkspace(selectedProviderId)"
                            class="px-3.5 py-2 rounded-xl bg-sky-500 hover:bg-sky-400 text-slate-950 font-extrabold transition">6. Sync Availability</button>
                    <button type="button" @click="executeImport('import_missing_only')" :disabled="importExecuting || !selectedProviderId"
                            class="px-3.5 py-2 rounded-xl bg-white/15 hover:bg-white/25 disabled:opacity-50 font-extrabold transition">7. Import Missing Only</button>
                </div>
            </div>

            <!-- Active Batch Import Progress Bar (DB-backed, survives page refresh) -->
            <template x-if="activeJob && !['completed','cancelled'].includes(activeJob.status)">
                <div class="rounded-2xl border p-5 space-y-3"
                     :class="activeJob.status === 'failed' ? 'border-rose-200 bg-rose-50/70' : 'border-purple-200 bg-purple-50/70'">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2.5">
                            <span class="w-2.5 h-2.5 rounded-full"
                                  :class="activeJob.status === 'failed' ? 'bg-rose-500' : 'bg-purple-600 animate-pulse'"></span>
                            <h4 class="text-sm font-extrabold"
                                :class="activeJob.status === 'failed' ? 'text-rose-900' : 'text-purple-900'">
                                Job #<span x-text="activeJob.id"></span> &middot;
                                <span x-text="activeJob.stage_message || activeJob.status"></span>
                            </h4>
                        </div>
                        <div class="flex items-center gap-2 text-xs">
                            <span class="font-mono font-extrabold text-slate-700"
                                  x-text="(activeJob.processed_records || 0) + ' / ' + (activeJob.total_records || 0) + ' (' + (activeJob.progress_percent || 0) + '%)'"></span>
                            <button type="button" x-show="activeJob.status === 'failed' && lastImportMode" @click="executeImport(lastImportMode)"
                                    class="px-3 py-1 rounded-lg bg-purple-600 text-white font-bold">Retry</button>
                            <button type="button" x-show="activeJob.status !== 'failed'" @click="cancelActiveJob()"
                                    class="px-3 py-1 rounded-lg bg-rose-100 text-rose-700 font-bold hover:bg-rose-200">Cancel</button>
                        </div>
                    </div>
                    <div class="w-full h-2.5 bg-white rounded-full overflow-hidden border border-purple-100">
                        <div class="h-full transition-all duration-300"
                             :class="activeJob.status === 'failed' ? 'bg-rose-500' : 'bg-gradient-to-r from-purple-600 to-violet-500'"
                             :style="'width:' + Math.max(activeJob.status === 'failed' ? 100 : 4, activeJob.progress_percent || 0) + '%'"></div>
                    </div>
                    <div class="flex flex-wrap gap-3 text-[11px] font-semibold text-slate-600">
                        <span>Countries Found: <strong class="text-slate-900" x-text="activeJob.total_countries_found || 0"></strong></span>
                        <span>Services Found: <strong class="text-slate-900" x-text="activeJob.total_services_found || 0"></strong></span>
                        <span>New Services: <strong class="text-emerald-700" x-text="activeJob.new_services || 0"></strong></span>
                        <span>Updated: <strong class="text-indigo-700" x-text="activeJob.updated_services || 0"></strong></span>
                        <span>Skipped: <strong class="text-slate-700" x-text="activeJob.skipped_records || 0"></strong></span>
                        <span>Failed: <strong class="text-rose-700" x-text="activeJob.failed_records || 0"></strong></span>
                    </div>
                    <template x-if="activeJob.status === 'failed' && activeJob.zero_reason">
                        <div class="p-3 rounded-xl bg-white border border-rose-200 text-xs font-bold text-rose-800" x-text="activeJob.zero_reason"></div>
                    </template>
                </div>
            </template>

            <!-- Complete 11-Metric Import Report -->
            <template x-if="importReport">
                <div class="rounded-2xl border p-5 space-y-3"
                     :class="importReport.zero_reason ? 'border-amber-200 bg-amber-50/70' : 'border-emerald-200 bg-emerald-50/70'">
                    <div class="flex items-center justify-between gap-2">
                        <h4 class="text-sm font-extrabold" :class="importReport.zero_reason ? 'text-amber-900' : 'text-emerald-900'">
                            Import / Sync Report (<span class="font-mono" x-text="importReport.mode || importReport.job_type"></span>)
                        </h4>
                        <button type="button" @click="importReport = null" class="text-xs font-bold text-slate-500 hover:text-slate-800">Dismiss</button>
                    </div>
                    <template x-if="importReport.zero_reason">
                        <div class="p-3 rounded-xl bg-white border border-amber-300 text-xs font-bold text-amber-900" x-text="importReport.zero_reason"></div>
                    </template>
                    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-2.5 text-center">
                        <div class="p-3 rounded-xl bg-white border border-slate-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Countries Found</div>
                            <div class="text-lg font-extrabold text-slate-900" x-text="importReport.total_countries_found ?? 0"></div>
                        </div>
                        <div class="p-3 rounded-xl bg-white border border-slate-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">New Countries</div>
                            <div class="text-lg font-extrabold text-emerald-700" x-text="importReport.new_countries ?? 0"></div>
                        </div>
                        <div class="p-3 rounded-xl bg-white border border-slate-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Updated Countries</div>
                            <div class="text-lg font-extrabold text-indigo-700" x-text="importReport.updated_countries ?? 0"></div>
                        </div>
                        <div class="p-3 rounded-xl bg-white border border-slate-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Services Found</div>
                            <div class="text-lg font-extrabold text-slate-900" x-text="importReport.total_services_found ?? importReport.total_found ?? 0"></div>
                        </div>
                        <div class="p-3 rounded-xl bg-white border border-slate-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">New Services</div>
                            <div class="text-lg font-extrabold text-emerald-700" x-text="importReport.new_services ?? importReport.new_imported ?? 0"></div>
                        </div>
                        <div class="p-3 rounded-xl bg-white border border-slate-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Updated Services</div>
                            <div class="text-lg font-extrabold text-indigo-700" x-text="importReport.updated_services ?? importReport.existing_updated ?? 0"></div>
                        </div>
                        <div class="p-3 rounded-xl bg-white border border-slate-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Prices Updated</div>
                            <div class="text-lg font-extrabold text-purple-700" x-text="importReport.prices_updated ?? 0"></div>
                        </div>
                        <div class="p-3 rounded-xl bg-white border border-slate-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Unsupported</div>
                            <div class="text-lg font-extrabold text-amber-700" x-text="importReport.unsupported_records ?? 0"></div>
                        </div>
                        <div class="p-3 rounded-xl bg-white border border-slate-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Skipped</div>
                            <div class="text-lg font-extrabold text-slate-600" x-text="importReport.skipped_records ?? importReport.skipped ?? 0"></div>
                        </div>
                        <div class="p-3 rounded-xl bg-white border border-slate-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Failed Records</div>
                            <div class="text-lg font-extrabold" :class="(importReport.failed_records || 0) > 0 ? 'text-rose-600' : 'text-emerald-700'" x-text="importReport.failed_records ?? 0"></div>
                        </div>
                        <div class="p-3 rounded-xl bg-white border border-slate-100 col-span-2">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Error Count</div>
                            <div class="text-lg font-extrabold" :class="(importReport.error_count || 0) > 0 ? 'text-rose-600' : 'text-emerald-700'" x-text="importReport.error_count ?? 0"></div>
                        </div>
                    </div>
                    <template x-if="importReport.errors && importReport.errors.length > 0">
                        <div class="p-3 rounded-xl bg-rose-50 border border-rose-200 text-xs text-rose-800 space-y-1 max-h-36 overflow-y-auto">
                            <template x-for="(err, idx) in importReport.errors" :key="idx">
                                <div class="font-mono text-[11px]" x-text="err"></div>
                            </template>
                        </div>
                    </template>
                </div>
            </template>

            <!-- Live Paginated Preview Table (Fast even with 10,000+ 5SIM entries) -->
            <template x-if="previewMeta">
                <div class="space-y-3">
                    <template x-if="previewMeta.zero_reason">
                        <div class="p-3.5 rounded-2xl bg-amber-50 border border-amber-200 text-xs font-bold text-amber-900" x-text="previewMeta.zero_reason"></div>
                    </template>
                    <div class="flex flex-wrap items-center justify-between gap-3 text-xs">
                        <div class="flex flex-wrap items-center gap-2">
                            <input type="text" x-model="previewSearch" @input.debounce.300ms="loadPreviewPage(1)" placeholder="Search service or country..."
                                   class="px-3 py-2 rounded-xl border border-purple-200 w-52">
                            <select x-model="previewFilterCountry" @change="loadPreviewPage(1)" class="px-3 py-2 rounded-xl border border-purple-200 font-semibold">
                                <option value="">All API Countries (<span x-text="previewCountries.length"></span>)</option>
                                <template x-for="c in previewCountries" :key="c.provider_code || c.code">
                                    <option :value="c.provider_code || c.code" x-text="(c.flag_emoji ? c.flag_emoji + ' ' : '') + c.name + ' (ID: ' + (c.provider_code || c.code) + ')'"></option>
                                </template>
                            </select>
                            <select x-model="previewFilterStatus" @change="loadPreviewPage(1)" class="px-3 py-2 rounded-xl border border-purple-200 font-semibold">
                                <option value="all">All Items (<span x-text="previewMeta.total_found"></span>)</option>
                                <option value="new">New Only (<span x-text="previewMeta.new_count"></span>)</option>
                                <option value="existing">Existing Only (<span x-text="previewMeta.existing_count"></span>)</option>
                            </select>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <button type="button" @click="selectAllFiltered()" class="px-3 py-1.5 rounded-xl bg-purple-100 text-purple-800 font-bold">Select Page</button>
                            <button type="button" @click="selectMissingFiltered()" class="px-3 py-1.5 rounded-xl bg-emerald-100 text-emerald-800 font-bold">Select New on Page</button>
                            <button type="button" @click="selectedKeys = []" class="px-3 py-1.5 rounded-xl bg-slate-100 text-slate-700 font-bold">Clear</button>
                        </div>
                    </div>

                    <div class="border border-purple-100 rounded-2xl overflow-hidden">
                        <div class="overflow-x-auto max-h-[460px] overflow-y-auto">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-[#FAF8FF] text-slate-500 uppercase text-[10px] font-extrabold sticky top-0 z-10">
                                    <tr>
                                        <th class="px-3 py-2.5 w-10">
                                            <input type="checkbox" @change="toggleAllFiltered($event.target.checked)" class="accent-purple-600">
                                        </th>
                                        <th class="px-3 py-2.5">Local Service / API ID</th>
                                        <th class="px-3 py-2.5">Country / API ID</th>
                                        <th class="px-3 py-2.5">Provider Cost</th>
                                        <th class="px-3 py-2.5">Converted Cost</th>
                                        <th class="px-3 py-2.5">Selling Price (Server)</th>
                                        <th class="px-3 py-2.5">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-purple-50">
                                    <template x-if="previewPageLoading">
                                        <tr><td colspan="7" class="px-4 py-8 text-center text-purple-700 font-bold">Loading page...</td></tr>
                                    </template>
                                    <template x-if="!previewPageLoading && previewItems.length === 0">
                                        <tr><td colspan="7" class="px-4 py-8 text-center text-slate-400">No items match your current filter.</td></tr>
                                    </template>
                                    <template x-for="item in previewItems" :key="item.key">
                                        <tr class="hover:bg-purple-50/40">
                                            <td class="px-3 py-2.5">
                                                <input type="checkbox" :value="item.key" x-model="selectedKeys" class="accent-purple-600">
                                            </td>
                                            <td class="px-3 py-2.5">
                                                <div class="font-extrabold text-slate-900" x-text="item.service_name"></div>
                                                <div class="text-[10px] font-mono text-slate-400">ID: <span x-text="item.service_code"></span> · Op: <span x-text="item.operator"></span></div>
                                            </td>
                                            <td class="px-3 py-2.5">
                                                <div class="font-bold text-slate-800" x-text="(item.country_flag ? item.country_flag + ' ' : '') + item.country_name"></div>
                                                <div class="text-[10px] font-mono text-slate-400">ID: <span x-text="item.country_code"></span> (<span x-text="item.country_iso"></span>)</div>
                                            </td>
                                            <td class="px-3 py-2.5 font-mono font-bold text-slate-700" x-text="Number(item.provider_cost).toFixed(2) + ' ' + item.provider_currency"></td>
                                            <td class="px-3 py-2.5 font-mono text-slate-600" x-text="baseSymbol + Number(item.provider_cost_base).toFixed(2)"></td>
                                            <td class="px-3 py-2.5">
                                                <div class="font-mono font-extrabold text-purple-800" x-text="baseSymbol + Number(item.calculated_selling_price).toFixed(2)"></div>
                                                <div class="text-[10px] text-emerald-700 font-semibold" x-text="'Profit: ' + baseSymbol + Number(item.estimated_profit).toFixed(2)"></div>
                                            </td>
                                            <td class="px-3 py-2.5">
                                                <span class="px-2 py-0.5 rounded-lg text-[10px] font-extrabold"
                                                      :class="item.exists_locally ? 'bg-indigo-100 text-indigo-700' : 'bg-emerald-100 text-emerald-700'"
                                                      x-text="item.exists_locally ? 'Existing' : 'New'"></span>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <div class="px-4 py-2.5 bg-[#FAF8FF] border-t border-purple-100 flex flex-wrap items-center justify-between gap-2 text-xs">
                            <span class="text-slate-600 font-semibold">
                                Showing page <strong x-text="previewPagination.page"></strong> of <strong x-text="previewPagination.total_pages"></strong>
                                (<strong x-text="previewPagination.total_filtered"></strong> matching records,
                                <strong x-text="previewMeta.total_countries_found || 0"></strong> countries,
                                <strong x-text="previewMeta.total_services_found || 0"></strong> services)
                            </span>
                            <div class="flex items-center gap-1.5">
                                <button type="button" @click="loadPreviewPage(previewPagination.page - 1)" :disabled="previewPagination.page <= 1 || previewPageLoading"
                                        class="px-3 py-1 rounded-lg bg-white border border-purple-200 font-bold text-slate-700 disabled:opacity-40">&larr; Prev</button>
                                <button type="button" @click="loadPreviewPage(previewPagination.page + 1)" :disabled="previewPagination.page >= previewPagination.total_pages || previewPageLoading"
                                        class="px-3 py-1 rounded-lg bg-white border border-purple-200 font-bold text-slate-700 disabled:opacity-40">Next &rarr;</button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <!-- ====================================================================== -->
    <!-- TAB 3: AVAILABILITY SYNCHRONIZATION (getNumbersStatus)                 -->
    <!-- ====================================================================== -->
    <div x-show="activeTab === 'availability'" x-cloak class="space-y-6">
        <div class="smm-card rounded-3xl p-6 space-y-5">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 border-b border-purple-100 pb-4">
                <div>
                    <h3 class="text-base font-extrabold text-slate-900">Live Availability Synchronization (<code class="font-mono text-xs text-purple-700">getNumbersStatus</code>)</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Verifies actual real-time stock directly from the provider API. Services are marked verified only after live stock confirmation.</p>
                </div>
                <div class="text-xs text-slate-500">
                    Last Availability Update:
                    <strong class="text-slate-900" x-text="formatDate(currentProvider()?.last_availability_sync_at)"></strong>
                </div>
            </div>

            <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Provider</label>
                    <select x-model.number="selectedProviderId" class="w-full px-3 py-2.5 rounded-xl border border-purple-200 font-bold bg-white">
                        <template x-for="p in providers" :key="p.id">
                            <option :value="p.id" x-text="p.name"></option>
                        </template>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Sync Scope</label>
                    <select x-model="availForm.scope" class="w-full px-3 py-2.5 rounded-xl border border-purple-200 font-semibold bg-white">
                        <option value="all_enabled">Sync All Enabled Services</option>
                        <option value="selected_country">Sync Selected Country</option>
                        <option value="selected_services">Sync Selected Services</option>
                    </select>
                </div>
                <div x-show="availForm.scope === 'selected_country'">
                    <label class="block font-bold text-slate-600 mb-1">Select Country</label>
                    <select x-model.number="availForm.country_id" class="w-full px-3 py-2.5 rounded-xl border border-purple-200 bg-white">
                        <option value="0">— Choose Country —</option>
                        <?php foreach ($localCountries as $lc): ?>
                        <option value="<?= (int)$lc['id'] ?>"><?= Validator::e(($lc['flag_emoji'] ? $lc['flag_emoji'] . ' ' : '') . $lc['name'] . ' (' . $lc['default_provider_code'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div x-show="availForm.scope === 'selected_services'">
                    <label class="block font-bold text-slate-600 mb-1">Select Services (Hold Ctrl/Cmd)</label>
                    <select multiple x-model="availForm.service_ids" class="w-full px-3 py-2 rounded-xl border border-purple-200 bg-white h-24">
                        <?php foreach ($localServices as $ls): ?>
                        <option value="<?= (int)$ls['id'] ?>"><?= Validator::e($ls['name'] . ' (' . ($ls['provider_service_code'] ?: $ls['slug']) . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex flex-col justify-end gap-2">
                    <label class="inline-flex items-center gap-2 font-bold text-slate-700 cursor-pointer">
                        <input type="checkbox" x-model="availForm.disable_unavailable" class="accent-purple-600">
                        <span>Auto Disable Unavailable / Enable Available</span>
                    </label>
                    <button type="button" @click="executeAvailabilitySync()" :disabled="availLoading || !selectedProviderId"
                            class="w-full py-2.5 rounded-xl bg-sky-600 hover:bg-sky-700 disabled:opacity-50 text-white font-extrabold shadow transition">
                        <span x-text="availLoading ? 'Syncing Live Stock...' : 'Run Availability Sync Now'"></span>
                    </button>
                </div>
            </div>

            <!-- Availability Report -->
            <template x-if="availReport">
                <div class="rounded-2xl border border-sky-200 bg-sky-50/70 p-5 space-y-3">
                    <div class="flex items-center justify-between">
                        <h4 class="text-sm font-extrabold text-sky-900">Availability Verification Report</h4>
                        <span class="text-[11px] font-mono text-sky-700" x-text="availReport.last_availability_sync_at"></span>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2.5 text-center">
                        <div class="p-3 rounded-xl bg-white border border-sky-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Countries Checked</div>
                            <div class="text-lg font-extrabold text-slate-900" x-text="availReport.countries_checked ?? 0"></div>
                        </div>
                        <div class="p-3 rounded-xl bg-white border border-sky-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Pairs Checked</div>
                            <div class="text-lg font-extrabold text-slate-900" x-text="availReport.mappings_checked ?? 0"></div>
                        </div>
                        <div class="p-3 rounded-xl bg-white border border-sky-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Available</div>
                            <div class="text-lg font-extrabold text-emerald-700" x-text="availReport.available_services ?? 0"></div>
                        </div>
                        <div class="p-3 rounded-xl bg-white border border-sky-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Unavailable</div>
                            <div class="text-lg font-extrabold text-rose-600" x-text="availReport.unavailable_services ?? 0"></div>
                        </div>
                        <div class="p-3 rounded-xl bg-white border border-sky-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Auto-Disabled</div>
                            <div class="text-lg font-extrabold text-amber-700" x-text="availReport.disabled_count ?? 0"></div>
                        </div>
                        <div class="p-3 rounded-xl bg-white border border-sky-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Auto-Enabled</div>
                            <div class="text-lg font-extrabold text-indigo-700" x-text="availReport.enabled_count ?? 0"></div>
                        </div>
                    </div>
                    <template x-if="availReport.errors && availReport.errors.length > 0">
                        <div class="p-3 rounded-xl bg-rose-50 border border-rose-200 text-xs text-rose-800 space-y-1">
                            <template x-for="(err, idx) in availReport.errors" :key="idx">
                                <div class="font-mono text-[11px]" x-text="err"></div>
                            </template>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>

    <!-- ====================================================================== -->
    <!-- TAB 4: ADD / EDIT PROVIDER SETTINGS (MULTI-PROVIDER ARCHITECTURE)      -->
    <!-- ====================================================================== -->
    <div x-show="activeTab === 'settings'" x-cloak class="grid lg:grid-cols-12 gap-6">
        <div class="lg:col-span-8 smm-card rounded-3xl p-6"
             x-data="{ adapterType: '<?= Validator::e($editProv['adapter_type'] ?? 'custom_json') ?>' }">
            <div class="flex items-center justify-between mb-4 pb-3 border-b border-purple-100">
                <div>
                    <h3 class="text-base font-extrabold text-slate-900"><?= $editProv ? 'Edit OTP Provider: ' . Validator::e($editProv['name']) : 'Add OTP Provider' ?></h3>
                    <p class="text-[11px] text-slate-500">Credentials are encrypted with AES-256-GCM server-side and never exposed to the browser.</p>
                </div>
                <?php if ($editProv): ?>
                <a href="/admin/otp-providers.php?tab=settings" class="text-xs font-bold text-purple-600 hover:underline">+ New Provider</a>
                <?php endif; ?>
            </div>

            <form method="POST" class="space-y-4 text-xs">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="save_provider">
                <input type="hidden" name="id" value="<?= (int)($editProv['id'] ?? 0) ?>">

                <div class="grid sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Provider Adapter *</label>
                        <select name="adapter_type" x-model="adapterType"
                                @change="if(adapterType === 'fivesim'){ if(!$refs.baseUrlInput.value){ $refs.baseUrlInput.value = '<?= FiveSimAdapter::DEFAULT_BASE_URL ?>'; } if($refs.currInput.value === '<?= Validator::e($baseCurrencyCode) ?>'){ $refs.currInput.value = 'RUB'; } } else if($refs.baseUrlInput.value === '<?= FiveSimAdapter::DEFAULT_BASE_URL ?>'){ $refs.baseUrlInput.value = ''; }"
                                class="w-full px-3 py-2.5 rounded-xl border border-purple-200 font-bold bg-white">
                            <option value="custom_json">Custom OTP Provider (Configurable REST/JSON)</option>
                            <option value="custom_php">Custom PHP Adapter Class (/core/otp_adapters/)</option>
                            <option value="fivesim">5SIM API1 — Optional Built-in Adapter (handler_api.php)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Provider Name *</label>
                        <input type="text" name="name" required value="<?= Validator::e($editProv['name'] ?? '') ?>" placeholder="My OTP Provider" class="w-full px-3 py-2.5 rounded-xl border border-purple-200">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Slug *</label>
                        <input type="text" name="slug" value="<?= Validator::e($editProv['slug'] ?? '') ?>" placeholder="my-provider" class="w-full px-3 py-2.5 rounded-xl border border-purple-200 font-mono">
                    </div>
                </div>

                <!-- Optional 5SIM API1 Notice -->
                <div x-show="adapterType === 'fivesim'" x-cloak class="p-3.5 rounded-2xl bg-purple-50 border border-purple-200 text-purple-900 space-y-1">
                    <div class="font-extrabold text-[11px] uppercase">Optional 5SIM API1 Adapter (handler_api.php)</div>
                    <p class="text-[11px] text-purple-800">Uses endpoint <code class="font-mono bg-white px-1.5 py-0.5 rounded border border-purple-200">http://api1.5sim.net/stubs/handler_api.php</code> with server-side actions: <code>getBalance</code>, <code>getPrices</code>, <code>getNumbersStatus</code>, <code>getNumber</code>, <code>getStatus</code>, and <code>setStatus</code> (1/3/6/8). 5SIM is completely optional and never required for other providers.</p>
                </div>

                <div class="grid sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">API Base URL *</label>
                        <input type="url" x-ref="baseUrlInput" name="base_url" required
                               value="<?= Validator::e($editProv['base_url'] ?? '') ?>"
                               placeholder="https://api.provider.example.com"
                               class="w-full px-3 py-2.5 rounded-xl border border-purple-200 font-mono">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">API Key / Token <?= $editProv && !empty($editProv['api_key_enc']) ? '(Leave blank to keep stored encrypted key)' : '*' ?></label>
                        <input type="password" name="api_key" autocomplete="new-password"
                               placeholder="<?= $editProv && !empty($editProv['api_key_enc']) ? '•••••••••••• (Stored Encrypted)' : 'Enter provider API key' ?>"
                               class="w-full px-3 py-2.5 rounded-xl border border-purple-200 font-mono">
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Currency</label>
                        <input type="text" x-ref="currInput" name="currency_code" value="<?= Validator::e($editProv['currency_code'] ?? $baseCurrencyCode) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200 uppercase font-mono">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">FX Mode</label>
                        <select name="rate_mode" class="w-full px-2.5 py-2 rounded-xl border border-purple-200 font-semibold bg-white">
                            <option value="auto" <?= ($editProv['rate_mode'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Auto FX</option>
                            <option value="manual" <?= ($editProv['rate_mode'] ?? '') === 'manual' ? 'selected' : '' ?>>Manual</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Manual FX Rate</label>
                        <input type="number" step="0.000001" name="exchange_rate" value="<?= Validator::e($editProv['exchange_rate'] ?? '1.000000') ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Timeout (sec)</label>
                        <input type="number" min="5" max="60" name="timeout_seconds" value="<?= (int)($editProv['timeout_seconds'] ?? 20) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Poll Interval (s)</label>
                        <input type="number" min="2" max="60" name="polling_interval_seconds" value="<?= (int)($editProv['polling_interval_seconds'] ?? 5) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                    </div>
                </div>

                <div class="grid sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Request Method</label>
                        <select name="request_method" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-semibold bg-white">
                            <option value="GET" <?= ($editProv['request_method'] ?? 'GET') === 'GET' ? 'selected' : '' ?>>GET</option>
                            <option value="POST" <?= ($editProv['request_method'] ?? '') === 'POST' ? 'selected' : '' ?>>POST</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Status</label>
                        <select name="status" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-semibold bg-white">
                            <option value="active" <?= ($editProv['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= ($editProv['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Webhook Secret (Optional)</label>
                        <input type="password" name="webhook_secret" autocomplete="new-password"
                               placeholder="<?= $editProv && !empty($editProv['webhook_secret_enc']) ? '•••••••• (Keep stored)' : 'HMAC-SHA256 secret' ?>"
                               class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
                    </div>
                </div>

                <!-- Custom JSON Provider Full Configuration -->
                <div x-show="adapterType === 'custom_json'" x-cloak class="space-y-4 pt-4 border-t border-purple-100">
                    <div class="font-extrabold uppercase text-[11px] text-sky-800">Custom OTP Provider Endpoints, Auth &amp; Response Mapping</div>
                    <div class="grid sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block font-bold text-slate-600 mb-1">Authentication Method</label>
                            <select name="auth_type" class="w-full px-3 py-2 rounded-xl border border-purple-200 bg-white">
                                <?php foreach (['bearer' => 'Bearer Token Header', 'header' => 'Custom API Key Header', 'query' => 'Query Parameter', 'post_body' => 'POST Body Field'] as $atVal => $atLbl): ?>
                                <option value="<?= $atVal ?>" <?= ($editProv['auth_type'] ?? 'bearer') === $atVal ? 'selected' : '' ?>><?= $atLbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-600 mb-1">Auth Header Name</label>
                            <input type="text" name="auth_header_name" value="<?= Validator::e($editProv['auth_header_name'] ?? 'X-API-Key') ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-600 mb-1">Auth Query / Body Param</label>
                            <input type="text" name="auth_param_name" value="<?= Validator::e($editProv['auth_param_name'] ?? 'api_key') ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
                        </div>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-600 mb-1">Balance Endpoint</label>
                            <input type="text" name="ep_balance" value="<?= Validator::e((string)($editEndpoints['balance'] ?? '/api/balance')) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-600 mb-1">Purchase Endpoint</label>
                            <input type="text" name="ep_purchase" value="<?= Validator::e((string)($editEndpoints['purchase'] ?? ($editEndpoints['buy'] ?? '/api/purchase'))) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-600 mb-1">Pricing Endpoint</label>
                            <input type="text" name="ep_pricing" value="<?= Validator::e((string)($editEndpoints['pricing'] ?? ($editEndpoints['prices'] ?? '/api/prices'))) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-600 mb-1">Availability Endpoint</label>
                            <input type="text" name="ep_availability" value="<?= Validator::e((string)($editEndpoints['availability'] ?? '/api/availability?country={country}')) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-600 mb-1">Status / OTP Endpoint</label>
                            <input type="text" name="ep_status" value="<?= Validator::e((string)($editEndpoints['status'] ?? ($editEndpoints['check'] ?? '/api/status?id={id}'))) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-600 mb-1">Cancellation Endpoint</label>
                            <input type="text" name="ep_cancel" value="<?= Validator::e((string)($editEndpoints['cancel'] ?? '/api/cancel?id={id}')) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-600 mb-1">Completion Endpoint</label>
                            <input type="text" name="ep_complete" value="<?= Validator::e((string)($editEndpoints['complete'] ?? ($editEndpoints['finish'] ?? '/api/complete?id={id}'))) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-600 mb-1">Countries / Services Endpoint</label>
                            <input type="text" name="ep_countries_services" value="<?= Validator::e((string)($editEndpoints['countries_services'] ?? ($editEndpoints['countries'] ?? '/api/countries'))) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
                        </div>
                    </div>

                    <div class="grid sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block font-bold text-slate-600 mb-1">Request Parameters (JSON)</label>
                            <textarea name="request_params_json" rows="5" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono text-[11px]"><?= Validator::e($editProv['request_params_json'] ?: $defaultCustomParams) ?></textarea>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-600 mb-1">Response Mapping (Dot-Path JSON)</label>
                            <textarea name="response_map_json" rows="5" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono text-[11px]"><?= Validator::e($editProv['response_map_json'] ?: $defaultResponseMap) ?></textarea>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-600 mb-1">Status Mapping (JSON)</label>
                            <textarea name="status_map_json" rows="5" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono text-[11px]"><?= Validator::e($editProv['status_map_json'] ?: $defaultStatusMap) ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Custom PHP Adapter Class -->
                <div x-show="adapterType === 'custom_php'" x-cloak class="pt-3 border-t border-purple-100">
                    <label class="block font-bold text-slate-600 mb-1">Custom PHP Adapter Class Name (placed in <code>/core/otp_adapters/&lt;ClassName&gt;.php</code>)</label>
                    <input type="text" name="custom_adapter_class" value="<?= Validator::e($editProv['custom_adapter_class'] ?? '') ?>" placeholder="MyCustomOtpAdapter" class="w-full px-3 py-2.5 rounded-xl border border-purple-200 font-mono">
                    <p class="text-[10px] text-slate-400 mt-1">Class must implement <code>OtpProviderInterface</code> for non-standard or proprietary OTP provider protocols.</p>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                    <button type="submit" class="px-6 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-extrabold shadow">
                        <?= $editProv ? 'Save Provider Changes' : 'Create OTP Provider' ?>
                    </button>
                    <?php if ($editProv): ?>
                    <button type="button" onclick="if(confirm('Delete this provider? (Blocked if activations exist)')){ document.getElementById('deleteProvForm').submit(); }"
                            class="px-4 py-2 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold">Delete Provider</button>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ($editProv): ?>
            <form id="deleteProvForm" method="POST" class="hidden">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="delete_provider">
                <input type="hidden" name="id" value="<?= (int)$editProv['id'] ?>">
            </form>
            <?php endif; ?>
        </div>

        <!-- Reference Sidebar -->
        <div class="lg:col-span-4 space-y-4">
            <div class="smm-card rounded-3xl p-5 space-y-3 text-xs">
                <h4 class="text-sm font-extrabold text-slate-900">Provider Integration Reference</h4>
                <div class="space-y-2 text-[11px] text-slate-600">
                    <div><strong class="text-slate-800">Custom REST/JSON:</strong> Configure any endpoint path, auth method (Bearer / Header / Query / Body), and dot-path response mapping.</div>
                    <div><strong class="text-slate-800">Custom PHP Adapter:</strong> Drop a class implementing <code class="font-mono">OtpProviderInterface</code> into <code class="font-mono">/core/otp_adapters/</code> for proprietary protocols.</div>
                    <div><strong class="text-slate-800">Optional 5SIM API1:</strong> Supported as a built-in optional adapter (<code class="font-mono text-purple-700 break-all">handler_api.php</code>) using <code>getBalance</code>, <code>getPrices</code>, <code>getNumbersStatus</code>, <code>getNumber</code>, <code>getStatus</code>, and <code>setStatus</code>.</div>
                </div>
            </div>
            <div class="smm-card rounded-3xl p-5 space-y-2.5 text-xs">
                <h4 class="text-sm font-extrabold text-slate-900">Currency &amp; Snapshot Protection</h4>
                <p class="text-[11px] text-slate-600 leading-relaxed">
                    Configure each provider's native currency (e.g., USD, EUR, INR, RUB) and either use automatic exchange rates from <a href="/admin/currencies.php" class="text-purple-700 font-bold underline">Currencies &amp; Rates</a> or a manual FX factor. Every purchased activation permanently stores its original provider cost, selling price, profit, currency, and FX rate snapshot.
                </p>
            </div>
        </div>
    </div>

    <!-- Provider Details Modal (Sanitized - Zero Secret Exposure) -->
    <div x-show="detailsModal || detailsLoading" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm">
        <div @click.away="detailsModal = null; detailsLoading = false" class="bg-white rounded-3xl max-w-2xl w-full p-6 shadow-2xl border border-purple-100 max-h-[88vh] overflow-y-auto space-y-4 text-xs">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-extrabold text-slate-900" x-text="detailsModal ? detailsModal.provider.name : 'Loading Provider Details...'"></h3>
                    <div class="text-[11px] font-mono text-slate-500 break-all mt-0.5" x-text="detailsModal?.provider?.base_url || ''"></div>
                </div>
                <button type="button" @click="detailsModal = null; detailsLoading = false" class="text-slate-400 hover:text-slate-700 font-extrabold text-lg">&times;</button>
            </div>

            <template x-if="detailsLoading">
                <div class="py-10 text-center font-bold text-purple-700">Fetching provider diagnostics...</div>
            </template>

            <template x-if="!detailsLoading && detailsModal">
                <div class="space-y-4">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                        <div class="p-3 rounded-2xl bg-[#FAF8FF] border border-purple-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Adapter</div>
                            <div class="font-extrabold text-slate-900 mt-0.5" x-text="adapterLabel(detailsModal.provider.adapter_type)"></div>
                        </div>
                        <div class="p-3 rounded-2xl bg-[#FAF8FF] border border-purple-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Balance</div>
                            <div class="font-mono font-extrabold text-purple-800 mt-0.5" x-text="detailsModal.provider.balance !== null ? (Number(detailsModal.provider.balance).toFixed(2) + ' ' + detailsModal.provider.currency_code) : 'Not Checked'"></div>
                        </div>
                        <div class="p-3 rounded-2xl bg-[#FAF8FF] border border-purple-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Services / Pairs</div>
                            <div class="font-extrabold text-slate-900 mt-0.5"><span x-text="detailsModal.provider.total_imported_services"></span> / <span x-text="detailsModal.provider.total_mappings"></span></div>
                        </div>
                        <div class="p-3 rounded-2xl bg-[#FAF8FF] border border-purple-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Activations</div>
                            <div class="font-extrabold text-slate-900 mt-0.5" x-text="detailsModal.activations_count || 0"></div>
                        </div>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-2.5 p-3.5 rounded-2xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600">
                        <div><strong>Connection:</strong> <span x-text="connectionLabel(detailsModal.provider.connection_status)"></span></div>
                        <div><strong>API Key:</strong> <span :class="detailsModal.provider.has_api_key ? 'text-emerald-700 font-bold' : 'text-rose-600 font-bold'" x-text="detailsModal.provider.has_api_key ? 'Encrypted (Stored)' : 'Missing'"></span></div>
                        <div><strong>Auth / Method:</strong> <span class="font-mono" x-text="(detailsModal.config?.auth_type || '-') + ' / ' + (detailsModal.config?.request_method || 'GET')"></span></div>
                        <div><strong>Timeout / Poll:</strong> <span x-text="(detailsModal.config?.timeout_seconds || 20) + 's / ' + (detailsModal.config?.polling_interval_seconds || 5) + 's'"></span></div>
                        <div><strong>FX Mode:</strong> <span class="uppercase" x-text="detailsModal.config?.rate_mode || 'auto'"></span> (<span class="font-mono" x-text="detailsModal.config?.exchange_rate || '1.000000'"></span>)</div>
                        <div><strong>Last Sync:</strong> <span x-text="formatDate(detailsModal.provider.last_sync_at)"></span></div>
                    </div>

                    <template x-if="detailsModal.provider.last_error">
                        <div class="p-3 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800">
                            <strong class="uppercase text-[10px]">Last API Error:</strong>
                            <span x-text="detailsModal.provider.last_error"></span>
                        </div>
                    </template>

                    <div>
                        <div class="font-extrabold text-slate-800 mb-2">Recent Provider API Logs (Sanitized)</div>
                        <div class="border border-purple-100 rounded-2xl overflow-hidden max-h-48 overflow-y-auto">
                            <table class="w-full text-left text-[11px]">
                                <thead class="bg-[#FAF8FF] text-slate-500 uppercase text-[10px]">
                                    <tr>
                                        <th class="px-3 py-2">Action</th>
                                        <th class="px-3 py-2">Status</th>
                                        <th class="px-3 py-2">Time</th>
                                        <th class="px-3 py-2">Details</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-purple-50">
                                    <template x-for="lg in (detailsModal.recent_logs || [])" :key="lg.id">
                                        <tr>
                                            <td class="px-3 py-2 font-mono text-purple-700" x-text="lg.action"></td>
                                            <td class="px-3 py-2"><span class="px-1.5 py-0.5 rounded text-[10px] font-bold" :class="lg.status === 'ok' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'" x-text="lg.status.toUpperCase() + ' · ' + lg.http_code"></span></td>
                                            <td class="px-3 py-2 font-mono text-slate-500" x-text="(lg.response_time_ms ?? 0) + ' ms'"></td>
                                            <td class="px-3 py-2 truncate max-w-[220px] font-mono text-[10px]" x-text="lg.error_message || lg.created_at"></td>
                                        </tr>
                                    </template>
                                    <tr x-show="!detailsModal.recent_logs || detailsModal.recent_logs.length === 0">
                                        <td colspan="4" class="px-3 py-4 text-center text-slate-400">No recent API calls logged for this provider.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <a :href="'/admin/otp-providers.php?edit=' + detailsModal.provider.id" class="px-4 py-2 rounded-xl bg-purple-100 text-purple-800 font-bold">Edit Settings</a>
                        <button type="button" @click="detailsModal = null" class="px-4 py-2 rounded-xl bg-slate-900 text-white font-bold">Close</button>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

<script>
function otpProviderHub() {
    return {
        activeTab: <?= json_encode($defaultTab) ?>,
        csrfToken: <?= json_encode(CSRF::token()) ?>,
        baseSymbol: <?= json_encode($baseCurrencySymbol) ?>,
        providers: <?= json_encode($providersJsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        selectedProviderId: <?= (int)($editProv['id'] ?? ($providers[0]['id'] ?? 0)) ?>,
        busyProvider: 0,
        busyAction: '',
        detailsModal: null,
        detailsLoading: false,
        notice: { type: 'success', message: '' },

        importLoading: false,
        importExecuting: false,
        previewPageLoading: false,
        stagedJobId: 0,
        activeJob: null,
        lastImportMode: '',
        batchAbortRequested: false,
        importForm: {
            apiCountry: '',
            apiService: '',
            pricing_mode: 'auto',
            fixed_selling_price: '',
            profit_margin: <?= json_encode($defaultMargin) ?>,
            fixed_profit: '0.00',
            min_price: '',
            max_price: '',
            auto_price_update: true,
            override_existing_rules: false,
            import_status: 'active',
        },
        previewMeta: null,
        previewItems: [],
        previewCountries: [],
        previewSearch: '',
        previewFilterCountry: '',
        previewFilterStatus: 'all',
        previewPagination: { page: 1, per_page: 100, total_filtered: 0, total_pages: 1 },
        selectedKeys: [],
        importReport: null,

        availLoading: false,
        availForm: {
            scope: 'all_enabled',
            country_id: 0,
            service_ids: [],
            disable_unavailable: false,
        },
        availReport: null,

        init() {
            if (!this.selectedProviderId && this.providers.length > 0) {
                this.selectedProviderId = this.providers[0].id;
            }
            if (this.selectedProviderId) {
                this.checkActiveImportJob();
            }
        },

        currentProvider() {
            return this.providers.find(p => p.id === this.selectedProviderId) || null;
        },

        adapterLabel(t) {
            if (t === 'fivesim') return '5SIM API1';
            if (t === 'custom_php') return 'Custom PHP';
            return 'Custom JSON';
        },

        connectionLabel(st) {
            if (st === 'connected') return 'Connected';
            if (st === 'failed') return 'Failed';
            return 'Unverified';
        },

        formatDate(dt) {
            return dt ? dt : 'Never';
        },

        showNotice(type, msg) {
            this.notice = { type, message: msg };
        },

        updateProviderRow(updated) {
            if (!updated || !updated.id) return;
            const idx = this.providers.findIndex(p => p.id === updated.id);
            if (idx >= 0) {
                this.providers[idx] = Object.assign({}, this.providers[idx], updated);
            }
        },

        async postApi(payload, timeoutMs = 70000) {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), timeoutMs);
            try {
                const res = await fetch('/api/admin-otp-provider.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': this.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(payload),
                    signal: controller.signal,
                });
                const rawText = await res.text();
                let data = null;
                try {
                    data = JSON.parse(rawText);
                } catch (parseErr) {
                    const snippet = rawText.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 180);
                    return {
                        ok: false,
                        error: `Server returned non-JSON response (HTTP ${res.status}): ${snippet || 'Empty response'}`,
                    };
                }
                if (data && data.provider) {
                    this.updateProviderRow(data.provider);
                }
                if (!res.ok && data && data.ok !== false) {
                    data.ok = false;
                    data.error = data.error || `Request failed with HTTP ${res.status}`;
                }
                return data || { ok: false, error: `Empty response (HTTP ${res.status})` };
            } catch (err) {
                if (err && err.name === 'AbortError') {
                    return { ok: false, error: 'Request timed out waiting for the provider API. Try filtering by a specific country or retry.' };
                }
                return { ok: false, error: 'Network error communicating with server: ' + (err?.message || 'Unknown error') };
            } finally {
                clearTimeout(timer);
            }
        },

        async checkActiveImportJob() {
            if (!this.selectedProviderId) return;
            try {
                const res = await fetch(`/api/admin-otp-provider.php?action=import_job_status&provider_id=${this.selectedProviderId}&job_type=services`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json();
                if (data.ok && data.job && ['queued', 'fetching_countries', 'fetching_services', 'processing', 'importing'].includes(data.job.status)) {
                    this.activeJob = data.job;
                    this.showNotice('success', `Resuming active import job #${data.job.id} (${data.job.processed_records}/${data.job.total_records})...`);
                    this.runBatchLoop(data.job.id);
                }
            } catch (e) {
                // Silent check on init
            }
        },

        async openProviderDetails(providerId) {
            if (!providerId || this.detailsLoading) return;
            this.detailsLoading = true;
            this.detailsModal = null;
            try {
                const data = await this.postApi({ action: 'provider_details', provider_id: providerId }, 25000);
                if (!data.ok) {
                    this.showNotice('error', data.error || 'Could not load provider details.');
                    return;
                }
                this.detailsModal = data;
            } finally {
                this.detailsLoading = false;
            }
        },

        async runQuickAction(providerId, action) {
            if (!providerId || this.busyProvider) return;
            this.busyProvider = providerId;
            this.busyAction = action;
            try {
                const data = await this.postApi({ action, provider_id: providerId });
                if (data.ok) {
                    let msg = data.message || 'Operation completed.';
                    if (action === 'sync_prices') {
                        msg = `Prices synced: ${data.existing_updated || 0} checked, ${data.prices_updated || 0} price(s) updated.`;
                    } else if (data.response_time_ms) {
                        msg += ` (${data.response_time_ms} ms)`;
                    }
                    this.showNotice('success', msg);
                } else {
                    this.showNotice('error', data.error || 'Provider action failed.');
                }
            } finally {
                this.busyProvider = 0;
                this.busyAction = '';
            }
        },

        openImportWorkspace(providerId, autoFetch = false) {
            if (providerId) this.selectedProviderId = providerId;
            this.activeTab = 'import';
            if (autoFetch && this.selectedProviderId) {
                this.fetchServicePreview();
            }
        },

        openAvailabilityWorkspace(providerId) {
            if (providerId) this.selectedProviderId = providerId;
            this.activeTab = 'availability';
        },

        async fetchServicePreview() {
            if (!this.selectedProviderId || this.importLoading) return;
            this.importLoading = true;
            this.importReport = null;
            this.previewSearch = '';
            this.previewFilterCountry = '';
            this.previewFilterStatus = 'all';
            try {
                const data = await this.postApi({
                    action: 'fetch_preview',
                    provider_id: this.selectedProviderId,
                    country_code: this.importForm.apiCountry,
                    service_code: this.importForm.apiService,
                    pricing_mode: this.importForm.pricing_mode,
                    fixed_selling_price: this.importForm.fixed_selling_price,
                    profit_margin: this.importForm.profit_margin,
                    fixed_profit: this.importForm.fixed_profit,
                    min_price: this.importForm.min_price,
                    max_price: this.importForm.max_price,
                    page: 1,
                    per_page: 100,
                });
                if (!data.ok) {
                    this.showNotice('error', data.error || 'Failed to fetch service preview.');
                    return;
                }
                this.stagedJobId = data.staged_job_id || 0;
                this.previewMeta = {
                    total_found: data.total_found || 0,
                    total_countries_found: data.total_countries_found || 0,
                    total_services_found: data.total_services_found || 0,
                    new_count: data.new_count || 0,
                    existing_count: data.existing_count || 0,
                    zero_reason: data.zero_reason || null,
                };
                this.previewItems = data.items || [];
                this.previewCountries = data.countries || [];
                this.previewPagination = data.pagination || { page: 1, per_page: 100, total_filtered: this.previewItems.length, total_pages: 1 };
                this.selectedKeys = this.previewItems.filter(i => !i.exists_locally).map(i => i.key);
                if (data.zero_reason) {
                    this.showNotice('error', data.zero_reason);
                } else {
                    this.showNotice('success', `Fetched ${data.total_found} service/country entries across ${data.total_countries_found} countries & ${data.total_services_found} services (${data.new_count} new, ${data.existing_count} existing).`);
                }
            } finally {
                this.importLoading = false;
            }
        },

        async loadPreviewPage(page) {
            if (!this.stagedJobId || !this.selectedProviderId || page < 1) return;
            this.previewPageLoading = true;
            try {
                const data = await this.postApi({
                    action: 'preview_page',
                    provider_id: this.selectedProviderId,
                    staged_job_id: this.stagedJobId,
                    page,
                    per_page: 100,
                    search: this.previewSearch,
                    country_filter: this.previewFilterCountry,
                    status_filter: this.previewFilterStatus,
                    pricing_mode: this.importForm.pricing_mode,
                    fixed_selling_price: this.importForm.fixed_selling_price,
                    profit_margin: this.importForm.profit_margin,
                    fixed_profit: this.importForm.fixed_profit,
                    min_price: this.importForm.min_price,
                    max_price: this.importForm.max_price,
                }, 30000);
                if (!data.ok) {
                    this.showNotice('error', data.error || 'Failed to load preview page.');
                    return;
                }
                this.previewItems = data.items || [];
                this.previewPagination = data.pagination || this.previewPagination;
            } finally {
                this.previewPageLoading = false;
            }
        },

        selectAllFiltered() {
            const set = new Set(this.selectedKeys);
            this.previewItems.forEach(i => set.add(i.key));
            this.selectedKeys = Array.from(set);
        },

        selectMissingFiltered() {
            const set = new Set(this.selectedKeys);
            this.previewItems.filter(i => !i.exists_locally).forEach(i => set.add(i.key));
            this.selectedKeys = Array.from(set);
        },

        toggleAllFiltered(checked) {
            if (checked) {
                this.selectAllFiltered();
            } else {
                const pageKeys = new Set(this.previewItems.map(i => i.key));
                this.selectedKeys = this.selectedKeys.filter(k => !pageKeys.has(k));
            }
        },

        async executeImport(mode) {
            if (!this.selectedProviderId || this.importExecuting) return;
            this.lastImportMode = mode;

            // sync_prices runs quickly in a single transaction
            if (mode === 'sync_prices') {
                this.importExecuting = true;
                try {
                    const data = await this.postApi({
                        action: 'sync_prices',
                        provider_id: this.selectedProviderId,
                        country_code: this.previewFilterCountry || this.importForm.apiCountry,
                    });
                    if (!data.ok) {
                        this.showNotice('error', data.error || 'Price sync failed.');
                        return;
                    }
                    this.importReport = data;
                    this.showNotice(data.zero_reason ? 'error' : 'success', data.zero_reason || `Prices synced: ${data.updated_services || 0} checked, ${data.prices_updated || 0} updated.`);
                } finally {
                    this.importExecuting = false;
                }
                return;
            }

            this.importExecuting = true;
            this.importReport = null;
            this.batchAbortRequested = false;

            const countryForImport = this.previewFilterCountry || this.importForm.apiCountry;
            this.activeJob = {
                id: '...',
                status: 'fetching_services',
                stage_message: 'Fetching Services',
                total_records: 0,
                processed_records: 0,
                progress_percent: 5,
            };

            let startRes = null;
            try {
                startRes = await this.postApi({
                    action: 'start_import_job',
                    provider_id: this.selectedProviderId,
                    job_type: 'services',
                    mode,
                    staged_job_id: this.stagedJobId || 0,
                    selected_keys: this.selectedKeys,
                    country_code: mode === 'import_by_country' ? countryForImport : this.importForm.apiCountry,
                    service_code: this.importForm.apiService,
                    pricing_mode: this.importForm.pricing_mode,
                    fixed_selling_price: this.importForm.fixed_selling_price,
                    profit_margin: this.importForm.profit_margin,
                    fixed_profit: this.importForm.fixed_profit,
                    min_price: this.importForm.min_price,
                    max_price: this.importForm.max_price,
                    auto_price_update: this.importForm.auto_price_update ? 1 : 0,
                    override_existing_rules: this.importForm.override_existing_rules ? 1 : 0,
                    import_status: this.importForm.import_status,
                    batch_size: 100,
                });
            } catch (e) {
                this.importExecuting = false;
                this.activeJob = null;
                this.showNotice('error', 'Failed to start import job.');
                return;
            }

            if (!startRes || !startRes.ok) {
                this.importExecuting = false;
                if (startRes && startRes.job) {
                    this.activeJob = startRes.job;
                } else if (startRes && startRes.active_job) {
                    this.activeJob = startRes.active_job;
                } else {
                    this.activeJob = null;
                }
                this.showNotice('error', (startRes && startRes.error) || 'Could not start import job.');
                return;
            }

            this.activeJob = startRes.job;
            if (startRes.done) {
                this.importExecuting = false;
                this.importReport = startRes.job;
                this.showNotice(startRes.job?.zero_reason ? 'error' : 'success', startRes.job?.zero_reason || 'Import completed.');
                return;
            }

            await this.runBatchLoop(startRes.job_id);
        },

        async runBatchLoop(jobId) {
            this.importExecuting = true;
            try {
                let done = false;
                let guard = 0;
                while (!done && !this.batchAbortRequested && guard < 1000) {
                    guard++;
                    const step = await this.postApi({
                        action: 'process_import_batch',
                        provider_id: this.selectedProviderId,
                        job_id: jobId,
                        batch_size: 100,
                    }, 60000);

                    if (!step.ok) {
                        if (step.job) this.activeJob = step.job;
                        this.showNotice('error', step.error || 'Batch import encountered an error.');
                        return;
                    }
                    this.activeJob = step.job;
                    done = !!step.done || ['completed', 'failed', 'cancelled'].includes(step.job?.status);
                }

                if (this.activeJob && this.activeJob.status === 'completed') {
                    this.importReport = this.activeJob;
                    const r = this.activeJob;
                    if (r.zero_reason) {
                        this.showNotice('error', r.zero_reason);
                    } else {
                        this.showNotice('success', `Import complete: ${r.new_services} new service(s), ${r.updated_services} updated, ${r.new_countries} new countr(ies), ${r.prices_updated} price(s) updated.`);
                    }
                    if (this.stagedJobId > 0) {
                        await this.loadPreviewPage(this.previewPagination.page || 1);
                    }
                }
            } finally {
                this.importExecuting = false;
            }
        },

        async cancelActiveJob() {
            if (!this.activeJob || !this.activeJob.id || this.activeJob.id === '...') return;
            this.batchAbortRequested = true;
            const res = await this.postApi({
                action: 'cancel_import_job',
                provider_id: this.selectedProviderId,
                job_id: this.activeJob.id,
            });
            if (res.ok) {
                this.activeJob = null;
                this.importExecuting = false;
                this.showNotice('success', 'Import job cancelled.');
            }
        },

        async executeAvailabilitySync() {
            if (!this.selectedProviderId || this.availLoading) return;
            this.availLoading = true;
            try {
                const data = await this.postApi({
                    action: 'sync_availability',
                    provider_id: this.selectedProviderId,
                    scope: this.availForm.scope,
                    country_id: this.availForm.country_id,
                    service_ids: this.availForm.service_ids,
                    disable_unavailable: this.availForm.disable_unavailable ? 1 : 0,
                });
                if (!data.ok) {
                    this.showNotice('error', data.error || 'Availability sync failed.');
                    return;
                }
                this.availReport = data;
                this.showNotice('success', `Availability verified across ${data.countries_checked} country(ies): ${data.available_services} available, ${data.unavailable_services} unavailable.`);
            } finally {
                this.availLoading = false;
            }
        },
    };
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
