<?php
/**
 * OTP Activation History — Filterable User History
 * Strictly scoped to the authenticated user's own activations. Works even when OTP Store is OFF.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$user = Auth::requireUser();
$userId = (int)$user['id'];
$pageTitle = 'OTP History';

$status = Validator::str($_GET['status'] ?? 'all', 30);
$serviceId = (int)($_GET['service_id'] ?? 0);
$countryId = (int)($_GET['country_id'] ?? 0);
$dateFrom = Validator::str($_GET['from'] ?? '', 20);
$dateTo = Validator::str($_GET['to'] ?? '', 20);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = ["a.user_id = ?"];
$params = [$userId];

if ($status !== '' && $status !== 'all' && in_array($status, OTP_ACTIVATION_STATUSES, true)) {
    $where[] = "a.status = ?";
    $params[] = $status;
}
if ($serviceId > 0) {
    $where[] = "a.service_id = ?";
    $params[] = $serviceId;
}
if ($countryId > 0) {
    $where[] = "a.country_id = ?";
    $params[] = $countryId;
}
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = "a.created_at >= ?";
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = "a.created_at <= ?";
    $params[] = $dateTo . ' 23:59:59';
}

$whereSql = implode(' AND ', $where);
$total = (int)Database::fetchColumn("SELECT COUNT(*) FROM otp_activations a WHERE {$whereSql}", $params);
$totalPages = max(1, (int)ceil($total / $perPage));

$rows = Database::fetchAll(
    "SELECT a.*, s.name AS service_name, c.name AS country_name, c.flag_emoji AS country_flag,
            p.name AS provider_name, r.status AS refund_status
     FROM otp_activations a
     JOIN otp_services s ON s.id = a.service_id
     JOIN otp_countries c ON c.id = a.country_id
     LEFT JOIN otp_providers p ON p.id = a.provider_id
     LEFT JOIN otp_refunds r ON r.activation_id = a.id
     WHERE {$whereSql}
     ORDER BY a.id DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$servicesList = Database::fetchAll("SELECT id, name FROM otp_services ORDER BY name ASC");
$countriesList = Database::fetchAll("SELECT id, name FROM otp_countries ORDER BY name ASC");

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<!-- Filters -->
<form method="GET" class="bg-white rounded-2xl border border-purple-100 p-4 mb-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
    <select name="status" class="px-3 py-2 rounded-xl bg-[#FAF8FF] border border-purple-100 text-xs font-semibold">
        <option value="all">All Statuses</option>
        <?php foreach (OTP_ACTIVATION_STATUSES as $st): ?>
        <option value="<?= Validator::e($st) ?>" <?= $status === $st ? 'selected' : '' ?>><?= Validator::e(ucwords(str_replace('_', ' ', $st))) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="service_id" class="px-3 py-2 rounded-xl bg-[#FAF8FF] border border-purple-100 text-xs font-semibold">
        <option value="0">All Services</option>
        <?php foreach ($servicesList as $sv): ?>
        <option value="<?= (int)$sv['id'] ?>" <?= $serviceId === (int)$sv['id'] ? 'selected' : '' ?>><?= Validator::e($sv['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="country_id" class="px-3 py-2 rounded-xl bg-[#FAF8FF] border border-purple-100 text-xs font-semibold">
        <option value="0">All Countries</option>
        <?php foreach ($countriesList as $ct): ?>
        <option value="<?= (int)$ct['id'] ?>" <?= $countryId === (int)$ct['id'] ? 'selected' : '' ?>><?= Validator::e($ct['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="date" name="from" value="<?= Validator::e($dateFrom) ?>" class="px-3 py-2 rounded-xl bg-[#FAF8FF] border border-purple-100 text-xs font-semibold">
    <input type="date" name="to" value="<?= Validator::e($dateTo) ?>" class="px-3 py-2 rounded-xl bg-[#FAF8FF] border border-purple-100 text-xs font-semibold">
    <div class="flex items-center gap-2">
        <button type="submit" class="flex-1 px-4 py-2 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-xs font-extrabold">Filter</button>
        <a href="/otp/history.php" class="px-3 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold">Reset</a>
    </div>
</form>

<!-- History Table -->
<div class="bg-white rounded-3xl border border-purple-100 shadow-xs overflow-hidden">
    <div class="px-6 py-4 border-b border-purple-50 flex items-center justify-between">
        <div class="text-sm font-extrabold text-slate-900">OTP Activation History <span class="text-xs font-semibold text-slate-400">(<?= $total ?>)</span></div>
    </div>

    <?php if (empty($rows)): ?>
    <div class="p-10 text-center text-xs font-semibold text-slate-500">No OTP activation records matched your filters.</div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead class="bg-[#FAF8FF] text-slate-500 uppercase text-[10px] font-extrabold">
                <tr>
                    <th class="px-5 py-3">Activation ID</th>
                    <th class="px-5 py-3">Service &amp; Country</th>
                    <th class="px-5 py-3">Phone &amp; OTP</th>
                    <th class="px-5 py-3">Price</th>
                    <th class="px-5 py-3">Status</th>
                    <th class="px-5 py-3">Refund</th>
                    <th class="px-5 py-3">Date</th>
                    <th class="px-5 py-3 text-right">Support</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-purple-50">
                <?php foreach ($rows as $r): ?>
                <tr class="hover:bg-purple-50/30">
                    <td class="px-5 py-3.5 font-mono font-bold text-slate-800">
                        <?= Validator::e($r['activation_code']) ?>
                        <?php if (!empty($r['provider_name'])): ?>
                        <div class="text-[10px] font-sans text-slate-400 font-medium"><?= Validator::e($r['provider_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3.5">
                        <div class="font-bold text-slate-900"><?= Validator::e($r['service_name']) ?></div>
                        <div class="text-[11px] text-slate-500"><?= Validator::e(($r['country_flag'] ? $r['country_flag'] . ' ' : '') . $r['country_name']) ?></div>
                    </td>
                    <td class="px-5 py-3.5 font-mono">
                        <div class="font-semibold text-slate-800"><?= Validator::e($r['phone_number'] ?: '—') ?></div>
                        <?php if (!empty($r['otp_code'])): ?>
                        <span class="inline-block mt-0.5 px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 font-extrabold"><?= Validator::e($r['otp_code']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3.5 font-bold tabular-nums"><?= Currency::renderMoney((string)$r['selling_price'], null, 2, true) ?></td>
                    <td class="px-5 py-3.5"><?= Helpers::statusBadge($r['status']) ?></td>
                    <td class="px-5 py-3.5">
                        <?php if (Currency::cmp((string)$r['refunded_amount'], '0', 4) > 0): ?>
                        <span class="text-emerald-700 font-extrabold tabular-nums"><?= Currency::renderMoney((string)$r['refunded_amount'], null, 2, true) ?></span>
                        <?php elseif (!empty($r['refund_status'])): ?>
                        <?= Helpers::statusBadge($r['refund_status']) ?>
                        <?php else: ?>
                        <span class="text-slate-400">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3.5 text-slate-500 whitespace-nowrap"><?= date('M d, Y H:i', strtotime($r['created_at'])) ?></td>
                    <td class="px-5 py-3.5 text-right">
                        <a href="/user/tickets.php?store=otp&otp_activation_id=<?= (int)$r['id'] ?>" class="px-3 py-1.5 rounded-lg bg-purple-50 hover:bg-purple-100 text-purple-700 font-bold">Help</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="px-6 py-4 border-t border-purple-50 flex items-center justify-between text-xs">
        <span class="text-slate-500">Page <?= $page ?> of <?= $totalPages ?></span>
        <div class="flex items-center gap-2">
            <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="px-3 py-1.5 rounded-lg bg-slate-100 font-bold">Previous</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="px-3 py-1.5 rounded-lg bg-purple-600 text-white font-bold">Next</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
