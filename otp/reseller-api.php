<?php
/**
 * User OTP Reseller API Dashboard
 * - Separate from existing SMM Reseller API (/user/reseller-api.php & /api/v2.php)
 * - Uses the exact same user account and shared wallet balance
 * - Cryptographically secure SHA-256 hashed API key (one-time reveal on generate/regenerate)
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$user = Auth::requireUser();
$userId = (int)$user['id'];
$pageTitle = 'OTP Reseller API';

$globalApiEnabled = Helpers::setting('otp_api_enabled', '1') === '1';
$ipRestrictionRequired = Helpers::setting('otp_api_ip_restriction_enabled', '0') === '1';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$apiBaseUrl = $scheme . '://' . $host . '/api/otp/v1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'generate_key' || $action === 'regenerate_key') {
        $existing = OtpResellerApi::getKeyRecordForUser($userId);
        // Prevent a user whose OTP API was explicitly disabled by Admin from self-reactivating by regenerating
        if ($existing && $existing['status'] === 'disabled') {
            Helpers::setFlash('error', 'Your OTP Reseller API access is currently disabled by an administrator. Please contact support.');
            Helpers::redirect('/otp/reseller-api.php');
        }
        $gen = OtpResellerApi::generateKeyForUser($userId, true);
        if (!empty($gen['ok'])) {
            $_SESSION['_otp_api_new_key'] = $gen['raw_key'];
            Logger::activity('user', $userId, 'otp_api.' . $action, 'otp_api_key', (string)$gen['key_id'], 'Generated new OTP Reseller API key');
            Helpers::setFlash('success', $action === 'regenerate_key'
                ? 'New OTP Reseller API key generated. Your previous key was invalidated immediately.'
                : 'Your OTP Reseller API key has been generated. Copy it now — it will only be shown once.');
        } else {
            Helpers::setFlash('error', $gen['error'] ?? 'Could not generate API key.');
        }
        Helpers::redirect('/otp/reseller-api.php');
    } elseif ($action === 'save_ips') {
        $keyRec = OtpResellerApi::getKeyRecordForUser($userId);
        if (!$keyRec) {
            Helpers::setFlash('error', 'Generate an OTP Reseller API key first before configuring IP restrictions.');
            Helpers::redirect('/otp/reseller-api.php');
        }
        $rawIps = trim((string)($_POST['allowed_ips'] ?? ''));
        $validIps = OtpResellerApi::parseIpList($rawIps);
        $cleanIps = implode("\n", $validIps);
        Database::execute("UPDATE otp_api_keys SET allowed_ips = ? WHERE id = ? AND user_id = ?", [$cleanIps ?: null, (int)$keyRec['id'], $userId]);
        Logger::activity('user', $userId, 'otp_api.update_ips', 'otp_api_key', (string)$keyRec['id'], 'Updated IP allowlist (' . count($validIps) . ' IPs)');
        Helpers::setFlash('success', empty($validIps) ? 'IP allowlist cleared.' : ('Saved ' . count($validIps) . ' allowed IP address(es).'));
        Helpers::redirect('/otp/reseller-api.php');
    }
}

// One-time raw key reveal (immediately cleared from session after rendering)
$newlyGeneratedKey = null;
if (!empty($_SESSION['_otp_api_new_key'])) {
    $newlyGeneratedKey = (string)$_SESSION['_otp_api_new_key'];
    unset($_SESSION['_otp_api_new_key']);
}

$keyRecord = OtpResellerApi::getKeyRecordForUser($userId);
$effectiveLimits = $keyRecord
    ? OtpResellerApi::getEffectiveLimits($keyRecord)
    : [
        'max_active'   => max(1, (int)Helpers::setting('otp_api_max_active_activations', '25')),
        'max_daily'    => max(1, (int)Helpers::setting('otp_api_max_daily_orders', '500')),
        'rate_per_min' => max(1, (int)Helpers::setting('otp_api_default_user_rate_limit', '60')),
    ];

// Real Database KPIs for this user's OTP API usage
$stats = Database::fetch(
    "SELECT
        COUNT(*) AS total_orders,
        SUM(CASE WHEN status IN ('processing','active','waiting_otp','otp_received') THEN 1 ELSE 0 END) AS active_orders,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_orders,
        SUM(CASE WHEN status IN ('failed','expired','canceled','refunded') THEN 1 ELSE 0 END) AS failed_orders,
        COALESCE(SUM(selling_price), 0) AS total_spent
     FROM otp_activations
     WHERE user_id = ? AND source = 'api'",
    [$userId]
) ?: ['total_orders' => 0, 'active_orders' => 0, 'completed_orders' => 0, 'failed_orders' => 0, 'total_spent' => '0'];

$activeTab = in_array($_GET['tab'] ?? '', ['orders', 'transactions', 'logs'], true) ? (string)$_GET['tab'] : 'orders';

$apiOrders = Database::fetchAll(
    "SELECT a.*, s.name AS service_name, c.name AS country_name, c.flag_emoji
     FROM otp_activations a
     JOIN otp_services s ON s.id = a.service_id
     JOIN otp_countries c ON c.id = a.country_id
     WHERE a.user_id = ? AND a.source = 'api'
     ORDER BY a.id DESC LIMIT 30",
    [$userId]
);

$apiTransactions = Database::fetchAll(
    "SELECT wt.*
     FROM wallet_transactions wt
     LEFT JOIN otp_activations a ON wt.reference_type = 'otp_activation' AND wt.reference_id = CAST(a.id AS CHAR)
     WHERE wt.user_id = ?
       AND (a.source = 'api' OR wt.description LIKE 'OTP API%')
     ORDER BY wt.id DESC LIMIT 30",
    [$userId]
);

$apiLogs = Database::fetchAll(
    "SELECT id, endpoint, http_method, http_status, response_time_ms, success, error_code, ip_address, created_at
     FROM otp_api_logs
     WHERE user_id = ?
     ORDER BY id DESC LIMIT 40",
    [$userId]
);

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="space-y-6" x-data="{ confirmRegen: false }">
    <!-- Header Banner -->
    <div class="bg-gradient-to-br from-[#1B1145] via-purple-900 to-violet-800 rounded-3xl p-6 sm:p-7 text-white shadow-lg shadow-purple-900/15 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div class="space-y-1.5">
            <div class="flex flex-wrap items-center gap-2">
                <span class="px-2.5 py-0.5 rounded-lg bg-white/15 text-[11px] font-extrabold uppercase tracking-wider">OTP Reseller API v1</span>
                <?php if (!$globalApiEnabled): ?>
                    <span class="px-2.5 py-0.5 rounded-lg bg-rose-500/90 text-[11px] font-extrabold uppercase">Globally Paused</span>
                <?php elseif ($keyRecord && $keyRecord['status'] === 'disabled'): ?>
                    <span class="px-2.5 py-0.5 rounded-lg bg-rose-500/90 text-[11px] font-extrabold uppercase">Account API Disabled</span>
                <?php elseif ($keyRecord): ?>
                    <span class="px-2.5 py-0.5 rounded-lg bg-emerald-500/90 text-[11px] font-extrabold uppercase">Active</span>
                <?php else: ?>
                    <span class="px-2.5 py-0.5 rounded-lg bg-amber-400 text-slate-950 text-[11px] font-extrabold uppercase">Key Not Generated</span>
                <?php endif; ?>
            </div>
            <h1 class="text-xl sm:text-2xl font-extrabold tracking-tight">Automate &amp; Resell OTP Activations</h1>
            <p class="text-xs sm:text-sm text-purple-100/90 max-w-2xl">
                Connect your panel, bot, or application to our REST API. Purchases deduct automatically from your shared wallet balance and share real-time status &amp; automatic refunds.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2.5 shrink-0">
            <a href="/otp/api-docs.php" class="px-4 py-2.5 rounded-xl bg-white text-purple-900 hover:bg-purple-50 text-xs font-extrabold shadow transition">
                API Documentation &rarr;
            </a>
            <a href="/user/add-funds.php" class="px-4 py-2.5 rounded-xl bg-white/15 hover:bg-white/25 text-white border border-white/20 text-xs font-extrabold transition">
                + Add Shared Funds
            </a>
        </div>
    </div>

    <?php if ($newlyGeneratedKey !== null): ?>
    <!-- One-Time Raw API Key Reveal Banner -->
    <div class="rounded-3xl bg-emerald-50 border-2 border-emerald-300 p-5 sm:p-6 space-y-3 shadow-sm">
        <div class="flex items-start justify-between gap-3">
            <div>
                <div class="text-xs font-extrabold uppercase tracking-wider text-emerald-800">Save Your New OTP Reseller API Key Now</div>
                <p class="text-xs text-emerald-700 mt-0.5">For your security, we only store a cryptographic SHA-256 hash of your key. This full key will never be displayed again after you leave or refresh this page.</p>
            </div>
        </div>
        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
            <input type="text" readonly value="<?= Validator::e($newlyGeneratedKey) ?>"
                   class="flex-1 px-4 py-3 rounded-xl bg-white border border-emerald-300 font-mono text-xs sm:text-sm font-extrabold text-slate-900 select-all">
            <button type="button" onclick="window.copyText(<?= htmlspecialchars(json_encode($newlyGeneratedKey), ENT_QUOTES, 'UTF-8') ?>, 'OTP API Key copied!')"
                    class="px-5 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-extrabold shadow shrink-0 transition">
                Copy API Key
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- KPI Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3.5">
        <div class="bg-white rounded-2xl border border-purple-100 p-4">
            <div class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400">API Shared Balance</div>
            <div class="mt-1.5 text-lg font-extrabold text-purple-700 tabular-nums"><?= Currency::renderMoney((string)$user['wallet_balance'], null, 2, true) ?></div>
            <div class="text-[10px] text-slate-400 mt-0.5">Shared with SMM &amp; OTP Store</div>
        </div>
        <div class="bg-white rounded-2xl border border-purple-100 p-4">
            <div class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400">Total API Orders</div>
            <div class="mt-1.5 text-lg font-extrabold text-slate-900 tabular-nums"><?= (int)$stats['total_orders'] ?></div>
            <div class="text-[10px] text-slate-400 mt-0.5">Spent: <?= Currency::renderMoney((string)$stats['total_spent'], null, 2, true) ?></div>
        </div>
        <div class="bg-white rounded-2xl border border-purple-100 p-4">
            <div class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400">Active Activations</div>
            <div class="mt-1.5 text-lg font-extrabold text-amber-600 tabular-nums"><?= (int)$stats['active_orders'] ?></div>
            <div class="text-[10px] text-slate-400 mt-0.5">Limit: <?= (int)$effectiveLimits['max_active'] ?> concurrent</div>
        </div>
        <div class="bg-white rounded-2xl border border-purple-100 p-4">
            <div class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400">Completed Orders</div>
            <div class="mt-1.5 text-lg font-extrabold text-emerald-600 tabular-nums"><?= (int)$stats['completed_orders'] ?></div>
            <div class="text-[10px] text-slate-400 mt-0.5">Daily limit: <?= (int)$effectiveLimits['max_daily'] ?>/day</div>
        </div>
        <div class="bg-white rounded-2xl border border-purple-100 p-4 col-span-2 lg:col-span-1">
            <div class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400">Failed / Refunded</div>
            <div class="mt-1.5 text-lg font-extrabold text-rose-600 tabular-nums"><?= (int)$stats['failed_orders'] ?></div>
            <div class="text-[10px] text-slate-400 mt-0.5">Rate limit: <?= (int)$effectiveLimits['rate_per_min'] ?> req/min</div>
        </div>
    </div>

    <!-- Credentials & IP Allowlist Grid -->
    <div class="grid lg:grid-cols-12 gap-5">
        <!-- API Credentials -->
        <div class="lg:col-span-7 bg-white rounded-3xl border border-purple-100 p-5 sm:p-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2 pb-3 border-b border-purple-50">
                <div>
                    <h2 class="text-sm font-extrabold text-slate-900">OTP Reseller API Credentials</h2>
                    <p class="text-xs text-slate-500">Separate from your SMM API key. Authenticate via <code class="font-mono text-[11px] bg-purple-50 text-purple-700 px-1 rounded">Authorization: Bearer</code> or <code class="font-mono text-[11px] bg-purple-50 text-purple-700 px-1 rounded">X-API-Key</code>.</p>
                </div>
            </div>

            <div class="space-y-3 text-xs">
                <div>
                    <label class="block font-bold text-slate-600 mb-1">API Base URL</label>
                    <div class="flex items-center gap-2">
                        <input type="text" readonly value="<?= Validator::e($apiBaseUrl) ?>" class="flex-1 px-3.5 py-2.5 rounded-xl bg-[#FAF8FF] border border-purple-100 font-mono text-xs text-slate-800">
                        <button type="button" onclick="window.copyText(<?= htmlspecialchars(json_encode($apiBaseUrl), ENT_QUOTES, 'UTF-8') ?>, 'Base URL copied!')"
                                class="px-3.5 py-2.5 rounded-xl bg-purple-100 hover:bg-purple-200 text-purple-800 font-extrabold shrink-0">Copy URL</button>
                    </div>
                </div>

                <div>
                    <label class="block font-bold text-slate-600 mb-1">Your OTP Reseller API Key</label>
                    <?php if ($keyRecord): ?>
                    <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                        <input type="text" readonly value="<?= Validator::e($keyRecord['masked_key']) ?>"
                               class="flex-1 px-3.5 py-2.5 rounded-xl bg-[#FAF8FF] border border-purple-100 font-mono text-xs text-slate-700">
                        <?php if ($keyRecord['status'] === 'active'): ?>
                        <button type="button" @click="confirmRegen = true"
                                class="px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-white font-extrabold shrink-0 transition">
                            Regenerate Key
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-slate-500">
                        <span>Status: <strong class="<?= $keyRecord['status'] === 'active' ? 'text-emerald-700' : 'text-rose-600' ?>"><?= ucfirst($keyRecord['status']) ?></strong></span>
                        <span>Requests: <strong class="text-slate-800"><?= number_format((int)$keyRecord['total_requests']) ?></strong></span>
                        <span>Last Used: <strong class="text-slate-800"><?= $keyRecord['last_used_at'] ? date('M d, Y H:i', strtotime($keyRecord['last_used_at'])) : 'Never' ?></strong></span>
                        <?php if (!empty($keyRecord['last_used_ip'])): ?>
                        <span>Last IP: <strong class="font-mono text-slate-800"><?= Validator::e($keyRecord['last_used_ip']) ?></strong></span>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="p-4 rounded-2xl bg-[#FAF8FF] border border-dashed border-purple-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div class="text-slate-600">You have not generated an OTP Reseller API key yet.</div>
                        <form method="POST">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="generate_key">
                            <button type="submit" class="px-5 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-extrabold shadow transition">
                                Generate API Key
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Optional IP Allowlist -->
        <div class="lg:col-span-5 bg-white rounded-3xl border border-purple-100 p-5 sm:p-6 space-y-3">
            <div class="flex items-center justify-between gap-2 pb-2 border-b border-purple-50">
                <h2 class="text-sm font-extrabold text-slate-900">IP Allowlist Security</h2>
                <span class="px-2 py-0.5 rounded-lg text-[10px] font-extrabold uppercase <?= $ipRestrictionRequired ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600' ?>">
                    <?= $ipRestrictionRequired ? 'Required' : 'Optional' ?>
                </span>
            </div>
            <p class="text-xs text-slate-500">
                Restrict API access to specific server IPv4/IPv6 addresses (one per line). Your current IP is <code class="font-mono font-bold text-purple-700"><?= Validator::e(Helpers::ip()) ?></code>.
            </p>
            <form method="POST" class="space-y-3 text-xs">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="save_ips">
                <textarea name="allowed_ips" rows="3" placeholder="e.g.&#10;203.0.113.10&#10;198.51.100.42"
                          class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono text-xs"
                          <?= $keyRecord ? '' : 'disabled' ?>><?= Validator::e($keyRecord['allowed_ips'] ?? '') ?></textarea>
                <button type="submit" <?= $keyRecord ? '' : 'disabled' ?>
                        class="w-full py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 disabled:opacity-40 text-white font-extrabold transition">
                    Save Allowed IPs
                </button>
            </form>
        </div>
    </div>

    <!-- Regenerate Confirmation Modal -->
    <div x-show="confirmRegen" x-cloak class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
        <div @click.away="confirmRegen = false" class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-purple-100 space-y-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-2xl bg-amber-100 text-amber-700 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg>
                </div>
                <div>
                    <h3 class="text-base font-extrabold text-slate-900">Regenerate OTP API Key?</h3>
                    <p class="text-xs text-slate-500">Immediate invalidation warning</p>
                </div>
            </div>
            <p class="text-xs text-slate-600 leading-relaxed">
                Generating a new OTP Reseller API key will <strong>immediately invalidate your current key</strong>. Any external website, panel, or script using the old key will stop working until updated. Your SMM API key will NOT be affected.
            </p>
            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" @click="confirmRegen = false" class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold">Cancel</button>
                <form method="POST">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="regenerate_key">
                    <button type="submit" class="px-5 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-extrabold shadow">Yes, Regenerate Now</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Activity Tabs: Orders / Wallet Transactions / API Logs -->
    <div class="bg-white rounded-3xl border border-purple-100 overflow-hidden">
        <div class="px-5 py-3.5 border-b border-purple-50 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <a href="?tab=orders" class="px-3.5 py-2 rounded-xl font-extrabold transition <?= $activeTab === 'orders' ? 'bg-purple-600 text-white' : 'bg-purple-50 text-purple-800 hover:bg-purple-100' ?>">
                    API Orders (<?= count($apiOrders) ?>)
                </a>
                <a href="?tab=transactions" class="px-3.5 py-2 rounded-xl font-extrabold transition <?= $activeTab === 'transactions' ? 'bg-purple-600 text-white' : 'bg-purple-50 text-purple-800 hover:bg-purple-100' ?>">
                    API Wallet Ledger (<?= count($apiTransactions) ?>)
                </a>
                <a href="?tab=logs" class="px-3.5 py-2 rounded-xl font-extrabold transition <?= $activeTab === 'logs' ? 'bg-purple-600 text-white' : 'bg-purple-50 text-purple-800 hover:bg-purple-100' ?>">
                    API Usage Logs (<?= count($apiLogs) ?>)
                </a>
            </div>
            <a href="/otp/history.php?source=api" class="text-xs font-extrabold text-purple-600 hover:underline">Full History &rarr;</a>
        </div>

        <?php if ($activeTab === 'orders'): ?>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-[#FAF8FF] text-slate-500 uppercase text-[10px] font-extrabold">
                    <tr>
                        <th class="px-5 py-3">Activation</th>
                        <th class="px-5 py-3">Service &amp; Country</th>
                        <th class="px-5 py-3">Phone &amp; OTP</th>
                        <th class="px-5 py-3">Price</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-purple-50">
                    <?php if (empty($apiOrders)): ?>
                    <tr><td colspan="6" class="px-5 py-8 text-center text-slate-400">No OTP Reseller API orders placed yet.</td></tr>
                    <?php else: foreach ($apiOrders as $o): ?>
                    <tr class="hover:bg-purple-50/30">
                        <td class="px-5 py-3 font-mono font-bold text-slate-800">
                            <?= Validator::e($o['activation_code']) ?>
                            <span class="ml-1 px-1.5 py-0.5 rounded bg-indigo-100 text-indigo-700 font-sans text-[10px] font-extrabold">API</span>
                        </td>
                        <td class="px-5 py-3">
                            <div class="font-bold text-slate-900"><?= Validator::e($o['service_name']) ?></div>
                            <div class="text-[11px] text-slate-500"><?= Validator::e(($o['flag_emoji'] ? $o['flag_emoji'] . ' ' : '') . $o['country_name']) ?></div>
                        </td>
                        <td class="px-5 py-3 font-mono">
                            <div><?= Validator::e($o['phone_number'] ?: '—') ?></div>
                            <?php if (!empty($o['otp_code'])): ?><span class="inline-block mt-0.5 px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 font-extrabold"><?= Validator::e($o['otp_code']) ?></span><?php endif; ?>
                        </td>
                        <td class="px-5 py-3 font-bold tabular-nums"><?= Currency::renderMoney((string)$o['selling_price'], null, 2, true) ?></td>
                        <td class="px-5 py-3"><?= Helpers::statusBadge((string)$o['status']) ?></td>
                        <td class="px-5 py-3 text-slate-500 whitespace-nowrap"><?= date('M d, H:i', strtotime($o['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php elseif ($activeTab === 'transactions'): ?>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-[#FAF8FF] text-slate-500 uppercase text-[10px] font-extrabold">
                    <tr>
                        <th class="px-5 py-3">ID</th>
                        <th class="px-5 py-3">Type</th>
                        <th class="px-5 py-3">Description</th>
                        <th class="px-5 py-3">Amount</th>
                        <th class="px-5 py-3">Balance After</th>
                        <th class="px-5 py-3">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-purple-50">
                    <?php if (empty($apiTransactions)): ?>
                    <tr><td colspan="6" class="px-5 py-8 text-center text-slate-400">No OTP API wallet transactions recorded yet.</td></tr>
                    <?php else: foreach ($apiTransactions as $t):
                        $isCredit = in_array($t['type'], ['deposit', 'refund', 'bonus', 'admin_credit'], true);
                    ?>
                    <tr class="hover:bg-purple-50/30">
                        <td class="px-5 py-3 font-mono text-slate-500">#<?= (int)$t['id'] ?></td>
                        <td class="px-5 py-3"><span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase <?= $isCredit ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-700' ?>"><?= Validator::e($t['type']) ?></span></td>
                        <td class="px-5 py-3 font-semibold text-slate-800"><?= Validator::e($t['description']) ?></td>
                        <td class="px-5 py-3 font-extrabold tabular-nums <?= $isCredit ? 'text-emerald-600' : 'text-rose-600' ?>"><?= $isCredit ? '+' : '-' ?><?= Currency::renderMoney((string)$t['amount'], null, 2, true) ?></td>
                        <td class="px-5 py-3 font-bold tabular-nums"><?= Currency::renderMoney((string)$t['balance_after'], null, 2, true) ?></td>
                        <td class="px-5 py-3 text-slate-500 whitespace-nowrap"><?= date('M d, H:i', strtotime($t['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-[#FAF8FF] text-slate-500 uppercase text-[10px] font-extrabold">
                    <tr>
                        <th class="px-5 py-3">Endpoint</th>
                        <th class="px-5 py-3">HTTP</th>
                        <th class="px-5 py-3">Result</th>
                        <th class="px-5 py-3">Latency</th>
                        <th class="px-5 py-3">IP Address</th>
                        <th class="px-5 py-3">Time</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-purple-50">
                    <?php if (empty($apiLogs)): ?>
                    <tr><td colspan="6" class="px-5 py-8 text-center text-slate-400">No API requests logged yet.</td></tr>
                    <?php else: foreach ($apiLogs as $lg): ?>
                    <tr class="hover:bg-purple-50/30">
                        <td class="px-5 py-3 font-mono font-bold text-slate-800"><span class="text-purple-700"><?= Validator::e($lg['http_method']) ?></span> <?= Validator::e($lg['endpoint']) ?></td>
                        <td class="px-5 py-3 font-mono"><?= (int)$lg['http_status'] ?></td>
                        <td class="px-5 py-3">
                            <?php if ((int)$lg['success'] === 1): ?>
                            <span class="px-2 py-0.5 rounded bg-emerald-100 text-emerald-700 text-[10px] font-extrabold">SUCCESS</span>
                            <?php else: ?>
                            <span class="px-2 py-0.5 rounded bg-rose-100 text-rose-700 text-[10px] font-extrabold font-mono"><?= Validator::e($lg['error_code'] ?: 'ERROR') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-3 font-mono text-slate-600"><?= (int)$lg['response_time_ms'] ?> ms</td>
                        <td class="px-5 py-3 font-mono text-slate-500"><?= Validator::e($lg['ip_address'] ?: '—') ?></td>
                        <td class="px-5 py-3 text-slate-500 whitespace-nowrap"><?= date('M d, H:i:s', strtotime($lg['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
