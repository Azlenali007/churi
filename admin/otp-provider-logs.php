<?php
/**
 * Admin OTP Provider API Logs
 * Filterable audit log of all outbound provider API requests (strictly sanitized — never exposes API keys).
 */
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('otp.providers');
$pageTitle = 'OTP Provider API Logs';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'prune_old') {
        $days = max(7, min(365, (int)($_POST['days'] ?? 30)));
        $deleted = Database::execute("DELETE FROM otp_provider_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)", [$days]);
        Logger::activity('admin', (int)$admin['id'], 'otp_provider_logs.prune', 'otp_provider_logs', (string)$days, "Pruned {$deleted} logs older than {$days} days");
        Helpers::setFlash('success', "Removed {$deleted} API log entries older than {$days} days.");
        Helpers::redirect('/admin/otp-provider-logs.php');
    }
}

$filterProvider = (int)($_GET['provider_id'] ?? 0);
$filterAction = Validator::str($_GET['action_filter'] ?? '', 60);
$filterStatus = in_array($_GET['status'] ?? '', ['ok', 'error'], true) ? (string)$_GET['status'] : '';
$filterDateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_from'] ?? '')) ? (string)$_GET['date_from'] : '';
$filterDateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_to'] ?? '')) ? (string)$_GET['date_to'] : '';
$filterSearch = Validator::str($_GET['q'] ?? '', 120);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if ($filterProvider > 0) {
    $where[] = 'l.provider_id = ?';
    $params[] = $filterProvider;
}
if ($filterAction !== '') {
    $where[] = 'l.action = ?';
    $params[] = $filterAction;
}
if ($filterStatus !== '') {
    $where[] = 'l.status = ?';
    $params[] = $filterStatus;
}
if ($filterDateFrom !== '') {
    $where[] = 'l.created_at >= ?';
    $params[] = $filterDateFrom . ' 00:00:00';
}
if ($filterDateTo !== '') {
    $where[] = 'l.created_at <= ?';
    $params[] = $filterDateTo . ' 23:59:59';
}
if ($filterSearch !== '') {
    $where[] = '(l.endpoint LIKE ? OR l.response_payload LIKE ? OR l.error_message LIKE ?)';
    $like = '%' . $filterSearch . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = implode(' AND ', $where);

$stats = Database::fetch(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN l.status = 'ok' THEN 1 ELSE 0 END) AS ok_count,
            SUM(CASE WHEN l.status = 'error' THEN 1 ELSE 0 END) AS err_count,
            ROUND(AVG(l.response_time_ms)) AS avg_ms
     FROM otp_provider_logs l
     WHERE {$whereSql}",
    $params
) ?: ['total' => 0, 'ok_count' => 0, 'err_count' => 0, 'avg_ms' => 0];

$totalRows = (int)($stats['total'] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$logs = Database::fetchAll(
    "SELECT l.*, p.name AS provider_name, p.adapter_type
     FROM otp_provider_logs l
     LEFT JOIN otp_providers p ON p.id = l.provider_id
     WHERE {$whereSql}
     ORDER BY l.id DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$providers = Database::fetchAll("SELECT id, name FROM otp_providers ORDER BY name ASC");
$actionsList = Database::fetchAll("SELECT DISTINCT action FROM otp_provider_logs ORDER BY action ASC");

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="space-y-6" x-data="{ openId: 0 }">
    <!-- Header & Summary KPIs -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h2 class="text-lg font-extrabold text-slate-900">OTP Provider API Logs</h2>
            <p class="text-xs text-slate-500">All API keys, tokens, and secrets are strictly redacted before logging.</p>
        </div>
        <div class="flex items-center gap-2 text-xs">
            <a href="/admin/otp-providers.php" class="px-3.5 py-2 rounded-xl bg-purple-100 text-purple-800 font-extrabold hover:bg-purple-200 transition">&larr; Back to Providers</a>
            <form method="POST" onsubmit="return confirm('Delete provider logs older than 30 days?')">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="prune_old">
                <input type="hidden" name="days" value="30">
                <button type="submit" class="px-3.5 py-2 rounded-xl bg-rose-50 text-rose-700 font-bold hover:bg-rose-100 transition">Prune 30d+ Logs</button>
            </form>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="smm-card rounded-2xl p-4">
            <div class="text-[10px] font-extrabold uppercase text-slate-400">Matching Calls</div>
            <div class="text-xl font-extrabold text-slate-900 mt-1"><?= number_format($totalRows) ?></div>
        </div>
        <div class="smm-card rounded-2xl p-4">
            <div class="text-[10px] font-extrabold uppercase text-slate-400">Success</div>
            <div class="text-xl font-extrabold text-emerald-700 mt-1"><?= number_format((int)($stats['ok_count'] ?? 0)) ?></div>
        </div>
        <div class="smm-card rounded-2xl p-4">
            <div class="text-[10px] font-extrabold uppercase text-slate-400">Failures</div>
            <div class="text-xl font-extrabold text-rose-600 mt-1"><?= number_format((int)($stats['err_count'] ?? 0)) ?></div>
        </div>
        <div class="smm-card rounded-2xl p-4">
            <div class="text-[10px] font-extrabold uppercase text-slate-400">Avg Response Time</div>
            <div class="text-xl font-extrabold text-purple-700 font-mono mt-1"><?= (int)($stats['avg_ms'] ?? 0) ?> ms</div>
        </div>
    </div>

    <!-- Filters Bar -->
    <form method="GET" class="smm-card rounded-3xl p-5 grid sm:grid-cols-2 lg:grid-cols-6 gap-3 text-xs">
        <div>
            <label class="block font-bold text-slate-500 mb-1">Provider</label>
            <select name="provider_id" class="w-full px-3 py-2 rounded-xl border border-purple-200 bg-white font-semibold">
                <option value="0">All Providers</option>
                <?php foreach ($providers as $pr): ?>
                <option value="<?= (int)$pr['id'] ?>" <?= $filterProvider === (int)$pr['id'] ? 'selected' : '' ?>><?= Validator::e($pr['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block font-bold text-slate-500 mb-1">Action</label>
            <select name="action_filter" class="w-full px-3 py-2 rounded-xl border border-purple-200 bg-white font-mono">
                <option value="">All Actions</option>
                <?php foreach ($actionsList as $al): ?>
                <option value="<?= Validator::e($al['action']) ?>" <?= $filterAction === $al['action'] ? 'selected' : '' ?>><?= Validator::e($al['action']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block font-bold text-slate-500 mb-1">Status</label>
            <select name="status" class="w-full px-3 py-2 rounded-xl border border-purple-200 bg-white font-semibold">
                <option value="">All</option>
                <option value="ok" <?= $filterStatus === 'ok' ? 'selected' : '' ?>>Success (OK)</option>
                <option value="error" <?= $filterStatus === 'error' ? 'selected' : '' ?>>Failure (Error)</option>
            </select>
        </div>
        <div>
            <label class="block font-bold text-slate-500 mb-1">Date From</label>
            <input type="date" name="date_from" value="<?= Validator::e($filterDateFrom) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
        </div>
        <div>
            <label class="block font-bold text-slate-500 mb-1">Date To</label>
            <input type="date" name="date_to" value="<?= Validator::e($filterDateTo) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
        </div>
        <div>
            <label class="block font-bold text-slate-500 mb-1">Search</label>
            <div class="flex gap-1.5">
                <input type="text" name="q" value="<?= Validator::e($filterSearch) ?>" placeholder="Error or response..." class="w-full px-3 py-2 rounded-xl border border-purple-200">
                <button type="submit" class="px-3.5 py-2 rounded-xl bg-purple-600 text-white font-extrabold">Filter</button>
            </div>
        </div>
    </form>

    <!-- Logs Table -->
    <div class="smm-card rounded-3xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-[#FAF8FF] text-slate-500 uppercase text-[10px] font-extrabold">
                    <tr>
                        <th class="px-4 py-3">Request Time</th>
                        <th class="px-4 py-3">Provider</th>
                        <th class="px-4 py-3">Action</th>
                        <th class="px-4 py-3">HTTP / Latency</th>
                        <th class="px-4 py-3">Result</th>
                        <th class="px-4 py-3">Sanitized Response / Error</th>
                        <th class="px-4 py-3 text-right">Inspect</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-purple-50">
                    <?php if (empty($logs)): ?>
                    <tr><td colspan="7" class="px-4 py-10 text-center text-slate-400">No API log records matched your filter criteria.</td></tr>
                    <?php else: foreach ($logs as $lg): $lid = (int)$lg['id']; ?>
                    <tr class="hover:bg-purple-50/30 <?= $lg['status'] === 'error' ? 'bg-rose-50/30' : '' ?>">
                        <td class="px-4 py-3 whitespace-nowrap font-mono text-[11px] text-slate-600"><?= Validator::e($lg['created_at']) ?></td>
                        <td class="px-4 py-3">
                            <div class="font-bold text-slate-900"><?= Validator::e($lg['provider_name'] ?: 'Deleted Provider') ?></div>
                            <?php if (!empty($lg['activation_id'])): ?>
                            <div class="text-[10px] text-slate-400">Activation #<?= (int)$lg['activation_id'] ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 font-mono font-bold text-purple-700"><?= Validator::e($lg['action']) ?></td>
                        <td class="px-4 py-3 font-mono text-[11px] whitespace-nowrap">
                            <span class="font-bold text-slate-800">HTTP <?= (int)$lg['http_code'] ?></span>
                            <span class="text-slate-400 ml-1"><?= $lg['response_time_ms'] !== null ? ((int)$lg['response_time_ms'] . ' ms') : '—' ?></span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-0.5 rounded-lg text-[10px] font-extrabold uppercase <?= $lg['status'] === 'ok' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' ?>">
                                <?= $lg['status'] === 'ok' ? 'Success' : 'Failure' ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 max-w-sm">
                            <?php if (!empty($lg['error_message'])): ?>
                            <div class="text-rose-700 font-bold truncate"><?= Validator::e($lg['error_message']) ?></div>
                            <?php endif; ?>
                            <div class="font-mono text-[10px] text-slate-500 truncate"><?= Validator::e(substr((string)$lg['response_payload'], 0, 140)) ?></div>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button type="button" @click="openId = (openId === <?= $lid ?> ? 0 : <?= $lid ?>)"
                                    class="px-2.5 py-1 rounded-lg bg-purple-50 text-purple-700 font-bold hover:bg-purple-100">
                                <span x-text="openId === <?= $lid ?> ? 'Hide' : 'Details'"></span>
                            </button>
                        </td>
                    </tr>
                    <tr x-show="openId === <?= $lid ?>" x-cloak class="bg-[#FAF8FF]">
                        <td colspan="7" class="px-5 py-4 space-y-2.5 text-[11px]">
                            <div>
                                <span class="font-extrabold text-slate-700 uppercase text-[10px]">Sanitized Endpoint:</span>
                                <div class="font-mono text-slate-700 bg-white border border-purple-100 rounded-xl p-2 mt-1 break-all"><?= Validator::e((string)$lg['endpoint']) ?></div>
                            </div>
                            <?php if (!empty($lg['request_payload'])): ?>
                            <div>
                                <span class="font-extrabold text-slate-700 uppercase text-[10px]">Sanitized Request Payload:</span>
                                <pre class="font-mono text-slate-700 bg-white border border-purple-100 rounded-xl p-2.5 mt-1 overflow-x-auto max-h-40 whitespace-pre-wrap break-all"><?= Validator::e((string)$lg['request_payload']) ?></pre>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($lg['error_message'])): ?>
                            <div>
                                <span class="font-extrabold text-rose-700 uppercase text-[10px]">Error Message:</span>
                                <div class="font-semibold text-rose-800 bg-rose-50 border border-rose-200 rounded-xl p-2.5 mt-1"><?= Validator::e((string)$lg['error_message']) ?></div>
                            </div>
                            <?php endif; ?>
                            <div>
                                <span class="font-extrabold text-slate-700 uppercase text-[10px]">Sanitized Response:</span>
                                <pre class="font-mono text-slate-700 bg-white border border-purple-100 rounded-xl p-2.5 mt-1 overflow-x-auto max-h-56 whitespace-pre-wrap break-all"><?= Validator::e((string)$lg['response_payload']) ?></pre>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="px-6 py-4 border-t border-purple-50 flex items-center justify-between text-xs">
            <span class="text-slate-500">Page <?= $page ?> of <?= $totalPages ?></span>
            <div class="flex items-center gap-1.5">
                <?php
                $qs = $_GET;
                if ($page > 1):
                    $qs['page'] = $page - 1;
                ?>
                <a href="?<?= http_build_query($qs) ?>" class="px-3 py-1.5 rounded-xl bg-slate-100 text-slate-700 font-bold">&larr; Prev</a>
                <?php endif;
                if ($page < $totalPages):
                    $qs['page'] = $page + 1;
                ?>
                <a href="?<?= http_build_query($qs) ?>" class="px-3 py-1.5 rounded-xl bg-purple-600 text-white font-bold">Next &rarr;</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
