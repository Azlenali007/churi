<?php
/**
 * Admin OTP Providers Manager
 * Supports Official 5SIM API Adapter + Configurable Custom JSON Providers + Custom PHP Adapters.
 * Features encrypted API credentials, Test API Connection, Check Balance, Webhook URL/Secret, and Redacted Logs Viewer.
 */
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('otp.providers');
$pageTitle = 'OTP Providers';

$defaultCustomEndpoints = json_encode([
    'balance'      => '/api/balance',
    'countries'    => '/api/countries',
    'availability' => '/api/prices?country={country}&service={service}',
    'buy'          => '/api/buy',
    'check'        => '/api/status?id={order_id}',
    'cancel'       => '/api/cancel?id={order_id}',
    'finish'       => '/api/finish?id={order_id}',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

$defaultCustomParams = json_encode([
    'buy' => ['country' => '{country}', 'service' => '{service}', 'operator' => '{operator}'],
], JSON_PRETTY_PRINT);

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
], JSON_PRETTY_PRINT);

$defaultStatusMap = json_encode([
    'PENDING'   => 'waiting_otp',
    'WAITING'   => 'waiting_otp',
    'RECEIVED'  => 'otp_received',
    'FINISHED'  => 'completed',
    'COMPLETED' => 'completed',
    'CANCELED'  => 'canceled',
    'TIMEOUT'   => 'expired',
    'EXPIRED'   => 'expired',
], JSON_PRETTY_PRINT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_provider') {
        $id = (int)($_POST['id'] ?? 0);
        $name = Validator::str($_POST['name'] ?? '', 120);
        $slug = strtolower(preg_replace('/[^a-z0-9_-]+/i', '', trim((string)($_POST['slug'] ?? ''))));
        if ($slug === '' && $name !== '') {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($name)));
            $slug = trim($slug, '_');
        }
        $adapterType = in_array($_POST['adapter_type'] ?? '', ['fivesim', 'custom_json', 'custom_php'], true) ? $_POST['adapter_type'] : 'fivesim';
        $customClass = Validator::str($_POST['custom_adapter_class'] ?? '', 120) ?: null;
        $baseUrl = rtrim(Validator::str($_POST['base_url'] ?? 'https://5sim.net', 255), '/');
        $apiKeyRaw = trim((string)($_POST['api_key'] ?? ''));
        $webhookSecretRaw = trim((string)($_POST['webhook_secret'] ?? ''));
        $authType = in_array($_POST['auth_type'] ?? '', ['bearer', 'header', 'query', 'post_body'], true) ? $_POST['auth_type'] : 'bearer';
        $authHeader = Validator::str($_POST['auth_header_name'] ?? 'Authorization', 80);
        $authParam = Validator::str($_POST['auth_param_name'] ?? 'api_key', 80);
        $reqMethod = (($_POST['request_method'] ?? 'GET') === 'POST') ? 'POST' : 'GET';
        $endpointsJson = trim((string)($_POST['endpoints_json'] ?? ''));
        $paramsJson = trim((string)($_POST['request_params_json'] ?? ''));
        $respMapJson = trim((string)($_POST['response_map_json'] ?? ''));
        $statusMapJson = trim((string)($_POST['status_map_json'] ?? ''));
        $timeout = max(5, min(60, (int)($_POST['timeout_seconds'] ?? 20)));
        $pollSec = max(2, min(60, (int)($_POST['polling_interval_seconds'] ?? 5)));
        $currCode = strtoupper(Validator::str($_POST['currency_code'] ?? 'RUB', 10)) ?: 'RUB';
        $rateMode = (($_POST['rate_mode'] ?? 'auto') === 'manual') ? 'manual' : 'auto';
        $exRate = Currency::normalize((string)($_POST['exchange_rate'] ?? '1.000000'), 6);
        $status = (($_POST['status'] ?? 'active') === 'inactive') ? 'inactive' : 'active';

        if ($name === '' || $slug === '' || $baseUrl === '') {
            Helpers::setFlash('error', 'Provider name, slug, and base URL are required.');
            Helpers::redirect('/admin/otp-providers.php');
        }
        $urlErr = OtpHttpClient::validateUrl($baseUrl);
        if ($urlErr !== null) {
            Helpers::setFlash('error', $urlErr);
            Helpers::redirect('/admin/otp-providers.php');
        }

        // Validate JSON blocks if non-empty
        foreach (['Endpoints' => $endpointsJson, 'Request Params' => $paramsJson, 'Response Map' => $respMapJson, 'Status Map' => $statusMapJson] as $lbl => $jStr) {
            if ($jStr !== '' && json_decode($jStr, true) === null && json_last_error() !== JSON_ERROR_NONE) {
                Helpers::setFlash('error', $lbl . ' must be valid JSON.');
                Helpers::redirect('/admin/otp-providers.php' . ($id > 0 ? '?edit=' . $id : ''));
            }
        }

        try {
            if ($id > 0) {
                $existing = Database::fetch("SELECT api_key_enc, webhook_secret_enc FROM otp_providers WHERE id = ?", [$id]);
                $apiEnc = $apiKeyRaw !== '' ? Crypto::encrypt($apiKeyRaw) : ($existing['api_key_enc'] ?? null);
                $whEnc = $webhookSecretRaw !== '' ? Crypto::encrypt($webhookSecretRaw) : ($existing['webhook_secret_enc'] ?? null);

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
                Helpers::setFlash('success', 'OTP provider updated.');
            } else {
                $apiEnc = $apiKeyRaw !== '' ? Crypto::encrypt($apiKeyRaw) : null;
                $whEnc = $webhookSecretRaw !== '' ? Crypto::encrypt($webhookSecretRaw) : null;

                Database::insert(
                    "INSERT INTO otp_providers
                     (name, slug, adapter_type, custom_adapter_class, base_url, api_key_enc, auth_type, auth_header_name,
                      auth_param_name, request_method, endpoints_json, request_params_json, response_map_json, status_map_json,
                      timeout_seconds, polling_interval_seconds, webhook_secret_enc, currency_code, exchange_rate, rate_mode, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $name, $slug, $adapterType, $customClass, $baseUrl, $apiEnc, $authType, $authHeader,
                        $authParam, $reqMethod, $endpointsJson ?: null, $paramsJson ?: null, $respMapJson ?: null, $statusMapJson ?: null,
                        $timeout, $pollSec, $whEnc, $currCode, $exRate, $rateMode, $status,
                    ]
                );
                Helpers::setFlash('success', 'OTP provider created.');
            }
            Logger::activity('admin', (int)$admin['id'], 'otp_provider.save', 'otp_provider', (string)$slug, 'Saved provider ' . $name);
        } catch (Throwable $e) {
            Helpers::setFlash('error', 'Failed to save OTP provider: ' . $e->getMessage());
        }
        Helpers::redirect('/admin/otp-providers.php');
    } elseif ($action === 'test_connection' || $action === 'check_balance') {
        $id = (int)($_POST['id'] ?? 0);
        $prov = Database::fetch("SELECT * FROM otp_providers WHERE id = ? LIMIT 1", [$id]);
        if ($prov) {
            try {
                $adapter = OtpProviderFactory::make($prov);
                $res = ($action === 'test_connection') ? $adapter->testConnection() : $adapter->getBalance();
                if (!empty($res['ok'])) {
                    $bal = isset($res['balance']) ? Currency::normalize((string)$res['balance'], 4) : null;
                    $cur = (string)($res['currency'] ?? $prov['currency_code']);
                    Database::execute(
                        "UPDATE otp_providers SET balance = COALESCE(?, balance), currency_code = ?, last_balance_check = NOW(), last_error = NULL WHERE id = ?",
                        [$bal, $cur, $id]
                    );
                    Helpers::setFlash('success', $prov['name'] . ' connected successfully! Balance: ' . ($bal !== null ? $bal . ' ' . $cur : 'OK'));
                } else {
                    $err = (string)($res['error'] ?? 'Connection failed.');
                    Database::execute("UPDATE otp_providers SET last_error = ? WHERE id = ?", [substr($err, 0, 500), $id]);
                    Helpers::setFlash('error', $prov['name'] . ' error: ' . $err);
                }
            } catch (Throwable $e) {
                Database::execute("UPDATE otp_providers SET last_error = ? WHERE id = ?", [substr($e->getMessage(), 0, 500), $id]);
                Helpers::setFlash('error', 'Adapter error: ' . $e->getMessage());
            }
        }
        Helpers::redirect('/admin/otp-providers.php');
    } elseif ($action === 'delete_provider') {
        $id = (int)($_POST['id'] ?? 0);
        $used = (int)Database::fetchColumn("SELECT COUNT(*) FROM otp_activations WHERE provider_id = ?", [$id]);
        if ($used > 0) {
            Helpers::setFlash('error', "Cannot delete provider with {$used} activation(s). Set status to Inactive instead.");
        } else {
            Database::execute("DELETE FROM otp_providers WHERE id = ?", [$id]);
            Helpers::setFlash('success', 'OTP provider deleted.');
        }
        Helpers::redirect('/admin/otp-providers.php');
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$editProv = $editId > 0 ? Database::fetch("SELECT * FROM otp_providers WHERE id = ?", [$editId]) : null;
$providers = Database::fetchAll("SELECT * FROM otp_providers ORDER BY id ASC");
$logs = Database::fetchAll(
    "SELECT l.*, p.name AS provider_name
     FROM otp_provider_logs l
     LEFT JOIN otp_providers p ON p.id = l.provider_id
     ORDER BY l.id DESC LIMIT 30"
);

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="grid lg:grid-cols-12 gap-6 mb-6">
    <!-- Add / Edit Provider Form -->
    <div class="lg:col-span-5 smm-card rounded-3xl p-6"
         x-data="{ adapterType: '<?= Validator::e($editProv['adapter_type'] ?? 'fivesim') ?>' }">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-base font-extrabold text-slate-900"><?= $editProv ? 'Edit OTP Provider' : 'Add OTP Provider' ?></h2>
            <?php if ($editProv): ?><a href="/admin/otp-providers.php" class="text-xs font-bold text-purple-600">+ New</a><?php endif; ?>
        </div>

        <form method="POST" class="space-y-3 text-xs">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="save_provider">
            <input type="hidden" name="id" value="<?= (int)($editProv['id'] ?? 0) ?>">

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Provider Name *</label>
                    <input type="text" name="name" required value="<?= Validator::e($editProv['name'] ?? '') ?>" placeholder="5SIM Official" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Slug *</label>
                    <input type="text" name="slug" value="<?= Validator::e($editProv['slug'] ?? '') ?>" placeholder="5sim" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Adapter Type</label>
                    <select name="adapter_type" x-model="adapterType" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-semibold">
                        <option value="fivesim">5SIM Official API (Built-in)</option>
                        <option value="custom_json">Custom JSON Provider API</option>
                        <option value="custom_php">Custom PHP Adapter Class</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Status</label>
                    <select name="status" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-semibold">
                        <option value="active" <?= ($editProv['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= ($editProv['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-600 mb-1">API Base URL *</label>
                <input type="url" name="base_url" required value="<?= Validator::e($editProv['base_url'] ?? 'https://5sim.net') ?>" placeholder="https://5sim.net" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
            </div>

            <div>
                <label class="block font-bold text-slate-600 mb-1">API Key / Bearer Token <?= $editProv && !empty($editProv['api_key_enc']) ? '(Leave blank to keep encrypted key)' : '*' ?></label>
                <input type="password" name="api_key" autocomplete="new-password" placeholder="<?= $editProv && !empty($editProv['api_key_enc']) ? '•••••••••••• (Stored Encrypted)' : 'Paste provider API token' ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
            </div>

            <div class="grid grid-cols-3 gap-2">
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Currency</label>
                    <input type="text" name="currency_code" value="<?= Validator::e($editProv['currency_code'] ?? 'RUB') ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200 uppercase font-mono">
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Rate Mode</label>
                    <select name="rate_mode" class="w-full px-2.5 py-2 rounded-xl border border-purple-200 font-semibold">
                        <option value="auto" <?= ($editProv['rate_mode'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Auto FX</option>
                        <option value="manual" <?= ($editProv['rate_mode'] ?? '') === 'manual' ? 'selected' : '' ?>>Manual</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Manual Rate</label>
                    <input type="number" step="0.000001" name="exchange_rate" value="<?= Validator::e($editProv['exchange_rate'] ?? '1.000000') ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Timeout (sec)</label>
                    <input type="number" min="5" max="60" name="timeout_seconds" value="<?= (int)($editProv['timeout_seconds'] ?? 20) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Polling Interval (sec)</label>
                    <input type="number" min="2" max="60" name="polling_interval_seconds" value="<?= (int)($editProv['polling_interval_seconds'] ?? 5) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                </div>
            </div>

            <!-- Custom JSON Provider Fields -->
            <div x-show="adapterType === 'custom_json'" x-cloak class="space-y-3 pt-2 border-t border-purple-100">
                <div class="text-[11px] font-extrabold uppercase text-purple-700">Custom JSON API Configuration</div>
                <div class="grid grid-cols-3 gap-2">
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Auth Type</label>
                        <select name="auth_type" class="w-full px-2 py-2 rounded-xl border border-purple-200">
                            <?php foreach (['bearer', 'header', 'query', 'post_body'] as $at): ?>
                            <option value="<?= $at ?>" <?= ($editProv['auth_type'] ?? 'bearer') === $at ? 'selected' : '' ?>><?= $at ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Method</label>
                        <select name="request_method" class="w-full px-2 py-2 rounded-xl border border-purple-200">
                            <option value="GET" <?= ($editProv['request_method'] ?? 'GET') === 'GET' ? 'selected' : '' ?>>GET</option>
                            <option value="POST" <?= ($editProv['request_method'] ?? '') === 'POST' ? 'selected' : '' ?>>POST</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Header / Param</label>
                        <input type="text" name="auth_header_name" value="<?= Validator::e($editProv['auth_header_name'] ?? 'Authorization') ?>" class="w-full px-2 py-2 rounded-xl border border-purple-200 font-mono">
                    </div>
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Query/Body Auth Param Name</label>
                    <input type="text" name="auth_param_name" value="<?= Validator::e($editProv['auth_param_name'] ?? 'api_key') ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Endpoints JSON</label>
                    <textarea name="endpoints_json" rows="4" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono text-[11px]"><?= Validator::e($editProv['endpoints_json'] ?: $defaultCustomEndpoints) ?></textarea>
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Request Params JSON</label>
                    <textarea name="request_params_json" rows="3" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono text-[11px]"><?= Validator::e($editProv['request_params_json'] ?: $defaultCustomParams) ?></textarea>
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Response JSON Field Mapping (dot paths)</label>
                    <textarea name="response_map_json" rows="4" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono text-[11px]"><?= Validator::e($editProv['response_map_json'] ?: $defaultResponseMap) ?></textarea>
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Status Mapping JSON</label>
                    <textarea name="status_map_json" rows="3" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono text-[11px]"><?= Validator::e($editProv['status_map_json'] ?: $defaultStatusMap) ?></textarea>
                </div>
            </div>

            <div x-show="adapterType === 'custom_php'" x-cloak>
                <label class="block font-bold text-slate-600 mb-1">Custom Adapter Class (inside /core/otp_adapters/)</label>
                <input type="text" name="custom_adapter_class" value="<?= Validator::e($editProv['custom_adapter_class'] ?? '') ?>" placeholder="MyOtpAdapter" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
            </div>

            <div>
                <label class="block font-bold text-slate-600 mb-1">Webhook Secret (Optional, HMAC-SHA256)</label>
                <input type="password" name="webhook_secret" autocomplete="new-password" placeholder="<?= $editProv && !empty($editProv['webhook_secret_enc']) ? '•••••••• (Leave blank to keep)' : 'Shared webhook secret' ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
            </div>

            <button type="submit" class="w-full py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-extrabold shadow">
                <?= $editProv ? 'Update OTP Provider' : 'Save OTP Provider' ?>
            </button>
        </form>
    </div>

    <!-- Providers List -->
    <div class="lg:col-span-7 space-y-6">
        <div class="smm-card rounded-3xl overflow-hidden">
            <div class="px-6 py-4 border-b border-purple-50">
                <h2 class="text-sm font-extrabold text-slate-900">Connected OTP Providers (<?= count($providers) ?>)</h2>
            </div>
            <?php if (empty($providers)): ?>
                <div class="p-8 text-center text-xs text-slate-500">No OTP providers configured yet. Add 5SIM or a Custom JSON provider on the left.</div>
            <?php else: ?>
            <div class="divide-y divide-purple-50">
                <?php foreach ($providers as $p): ?>
                <div class="p-5 space-y-2.5">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-extrabold text-slate-900"><?= Validator::e($p['name']) ?></span>
                                <span class="px-2 py-0.5 rounded bg-purple-100 text-purple-800 text-[10px] font-extrabold uppercase"><?= Validator::e($p['adapter_type']) ?></span>
                                <?= Helpers::statusBadge((string)$p['status']) ?>
                            </div>
                            <div class="text-[11px] font-mono text-slate-500 mt-0.5"><?= Validator::e($p['base_url']) ?></div>
                            <div class="text-[11px] text-slate-500 mt-0.5">
                                Webhook: <code class="bg-slate-100 px-1.5 py-0.5 rounded text-[10px]">/api/otp-webhook.php?provider_id=<?= (int)$p['id'] ?></code>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-xs font-extrabold text-purple-700 font-mono">
                                <?= $p['balance'] !== null ? Validator::e($p['balance'] . ' ' . $p['currency_code']) : 'Balance Unchecked' ?>
                            </div>
                            <?php if ($p['last_balance_check']): ?>
                            <div class="text-[10px] text-slate-400">Checked <?= Helpers::timeAgo($p['last_balance_check']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($p['last_error'])): ?>
                    <div class="p-2.5 rounded-xl bg-rose-50 border border-rose-200 text-[11px] text-rose-700 font-semibold">
                        Last Error: <?= Validator::e($p['last_error']) ?>
                    </div>
                    <?php endif; ?>

                    <div class="flex flex-wrap items-center gap-2 text-xs pt-1">
                        <form method="POST" class="inline">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button type="submit" name="action" value="test_connection" class="px-3 py-1.5 rounded-xl bg-emerald-600 text-white font-bold">Test Connection</button>
                            <button type="submit" name="action" value="check_balance" class="px-3 py-1.5 rounded-xl bg-purple-100 text-purple-800 font-bold">Check Balance</button>
                        </form>
                        <a href="/admin/otp-providers.php?edit=<?= (int)$p['id'] ?>" class="px-3 py-1.5 rounded-xl bg-slate-100 text-slate-800 font-bold">Edit</a>
                        <form method="POST" class="inline" onsubmit="return confirm('Delete this provider?')">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="delete_provider">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button type="submit" class="px-3 py-1.5 rounded-xl bg-rose-50 text-rose-700 font-bold">Delete</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Recent Redacted Provider API Logs -->
        <div class="smm-card rounded-3xl overflow-hidden">
            <div class="px-6 py-4 border-b border-purple-50">
                <h3 class="text-sm font-extrabold text-slate-900">Recent OTP Provider Logs &amp; API Errors (Redacted)</h3>
            </div>
            <div class="max-h-80 overflow-y-auto divide-y divide-purple-50 text-xs">
                <?php if (empty($logs)): ?>
                    <div class="p-6 text-center text-slate-400">No provider API calls logged yet.</div>
                <?php else: foreach ($logs as $lg): ?>
                <div class="px-5 py-3 <?= $lg['status'] === 'error' ? 'bg-rose-50/40' : '' ?>">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-bold text-slate-900">
                            <?= Validator::e($lg['provider_name'] ?: 'Provider') ?> &middot; <?= Validator::e($lg['action']) ?>
                            <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] <?= $lg['status'] === 'ok' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' ?>">HTTP <?= (int)$lg['http_code'] ?></span>
                        </span>
                        <span class="text-[10px] text-slate-400"><?= date('M d, H:i:s', strtotime($lg['created_at'])) ?></span>
                    </div>
                    <?php if (!empty($lg['error_message'])): ?>
                    <div class="text-[11px] text-rose-700 font-semibold mt-0.5"><?= Validator::e($lg['error_message']) ?></div>
                    <?php endif; ?>
                    <div class="text-[10px] font-mono text-slate-500 truncate mt-0.5"><?= Validator::e((string)$lg['response_payload']) ?></div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
