<?php
/**
 * Admin OTP Dashboard — Real Database KPIs, Revenue/Profit, Provider Balances & Recent Activations
 */
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('otp.manage');
$pageTitle = 'OTP Store Dashboard';

$stats = Database::fetch(
    "SELECT
        COUNT(*) AS total_all,
        SUM(CASE WHEN created_at >= CURDATE() THEN 1 ELSE 0 END) AS total_today,
        SUM(CASE WHEN status IN ('processing','active','waiting_otp','otp_received') THEN 1 ELSE 0 END) AS active_cnt,
        SUM(CASE WHEN status IN ('active','waiting_otp') THEN 1 ELSE 0 END) AS waiting_cnt,
        SUM(CASE WHEN status = 'otp_received' THEN 1 ELSE 0 END) AS received_cnt,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_cnt,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_cnt,
        SUM(CASE WHEN status = 'canceled' THEN 1 ELSE 0 END) AS canceled_cnt,
        SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) AS refunded_cnt,
        COALESCE(SUM(CASE WHEN status IN ('otp_received','completed') THEN selling_price ELSE 0 END), 0) AS revenue,
        COALESCE(SUM(CASE WHEN status IN ('otp_received','completed') THEN provider_cost ELSE 0 END), 0) AS prov_cost,
        COALESCE(SUM(CASE WHEN status IN ('otp_received','completed') THEN profit ELSE 0 END), 0) AS net_profit,
        COALESCE(SUM(refunded_amount), 0) AS total_refunded
     FROM otp_activations"
) ?: [];

$providers = Database::fetchAll("SELECT id, name, adapter_type, currency_code, balance, last_balance_check, last_error, status FROM otp_providers ORDER BY id ASC");
$recent = Database::fetchAll(
    "SELECT a.*, u.username, s.name AS service_name, c.name AS country_name, c.flag_emoji, p.name AS provider_name
     FROM otp_activations a
     JOIN users u ON u.id = a.user_id
     JOIN otp_services s ON s.id = a.service_id
     JOIN otp_countries c ON c.id = a.country_id
     LEFT JOIN otp_providers p ON p.id = a.provider_id
     ORDER BY a.id DESC LIMIT 15"
);

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 mb-6">
    <div class="smm-card rounded-2xl p-4">
        <div class="text-[10px] font-extrabold uppercase text-slate-400">Total Activations</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-1 tabular-nums"><?= (int)($stats['total_all'] ?? 0) ?></div>
        <div class="text-[11px] text-purple-600 font-bold mt-0.5">Today: <?= (int)($stats['total_today'] ?? 0) ?></div>
    </div>
    <div class="smm-card rounded-2xl p-4">
        <div class="text-[10px] font-extrabold uppercase text-slate-400">Active / Waiting</div>
        <div class="text-2xl font-extrabold text-amber-600 mt-1 tabular-nums"><?= (int)($stats['active_cnt'] ?? 0) ?></div>
        <div class="text-[11px] text-slate-500 mt-0.5">Waiting: <?= (int)($stats['waiting_cnt'] ?? 0) ?> · Got OTP: <?= (int)($stats['received_cnt'] ?? 0) ?></div>
    </div>
    <div class="smm-card rounded-2xl p-4">
        <div class="text-[10px] font-extrabold uppercase text-slate-400">Completed</div>
        <div class="text-2xl font-extrabold text-emerald-600 mt-1 tabular-nums"><?= (int)($stats['completed_cnt'] ?? 0) ?></div>
        <div class="text-[11px] text-slate-500 mt-0.5">Successful SMS</div>
    </div>
    <div class="smm-card rounded-2xl p-4">
        <div class="text-[10px] font-extrabold uppercase text-slate-400">Failed / Cancelled</div>
        <div class="text-2xl font-extrabold text-rose-600 mt-1 tabular-nums"><?= (int)($stats['failed_cnt'] ?? 0) + (int)($stats['canceled_cnt'] ?? 0) ?></div>
        <div class="text-[11px] text-slate-500 mt-0.5">Refunded: <?= (int)($stats['refunded_cnt'] ?? 0) ?></div>
    </div>
    <div class="smm-card rounded-2xl p-4">
        <div class="text-[10px] font-extrabold uppercase text-slate-400">OTP Revenue</div>
        <div class="text-xl font-extrabold text-slate-900 mt-1 tabular-nums"><?= Currency::display((string)($stats['revenue'] ?? '0'), null, 2, true) ?></div>
        <div class="text-[11px] text-slate-500 mt-0.5">Refunds: <?= Currency::display((string)($stats['total_refunded'] ?? '0'), null, 2, true) ?></div>
    </div>
    <div class="smm-card rounded-2xl p-4">
        <div class="text-[10px] font-extrabold uppercase text-slate-400">Est. Net Profit</div>
        <div class="text-xl font-extrabold text-purple-700 mt-1 tabular-nums"><?= Currency::display((string)($stats['net_profit'] ?? '0'), null, 2, true) ?></div>
        <div class="text-[11px] text-slate-500 mt-0.5">Cost: <?= Currency::display((string)($stats['prov_cost'] ?? '0'), null, 2, true) ?></div>
    </div>
</div>

<div class="grid lg:grid-cols-12 gap-6">
    <div class="lg:col-span-4 smm-card rounded-3xl p-5">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-sm font-extrabold text-slate-900">OTP Providers</h2>
            <a href="/admin/otp-providers.php" class="text-xs font-bold text-purple-600 hover:underline">Manage</a>
        </div>
        <?php if (empty($providers)): ?>
            <p class="text-xs text-slate-500">No OTP providers configured yet.</p>
        <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($providers as $p): ?>
            <div class="p-3.5 rounded-2xl border border-purple-100 bg-[#FAF8FF]">
                <div class="flex items-center justify-between gap-2">
                    <div class="font-extrabold text-xs text-slate-900"><?= Validator::e($p['name']) ?></div>
                    <?= Helpers::statusBadge((string)$p['status']) ?>
                </div>
                <div class="mt-1 text-[11px] text-slate-500 flex items-center justify-between">
                    <span>Adapter: <strong><?= Validator::e($p['adapter_type']) ?></strong></span>
                    <span class="font-mono font-bold text-purple-700">
                        <?= $p['balance'] !== null ? Validator::e($p['balance'] . ' ' . $p['currency_code']) : 'Not checked' ?>
                    </span>
                </div>
                <?php if (!empty($p['last_error'])): ?>
                <div class="mt-1.5 text-[10px] text-rose-600 truncate" title="<?= Validator::e($p['last_error']) ?>">Error: <?= Validator::e($p['last_error']) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="lg:col-span-8 smm-card rounded-3xl overflow-hidden">
        <div class="px-6 py-4 border-b border-purple-50 flex items-center justify-between">
            <h2 class="text-sm font-extrabold text-slate-900">Recent OTP Activations</h2>
            <a href="/admin/otp-orders.php" class="text-xs font-bold text-purple-600 hover:underline">View All Orders</a>
        </div>
        <?php if (empty($recent)): ?>
            <div class="p-8 text-center text-xs text-slate-500">No OTP activations recorded yet.</div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-[#FAF8FF] text-slate-500 uppercase text-[10px] font-extrabold">
                    <tr>
                        <th class="px-4 py-3">Code / User</th>
                        <th class="px-4 py-3">Service / Country</th>
                        <th class="px-4 py-3">Phone / OTP</th>
                        <th class="px-4 py-3">Price</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-purple-50">
                    <?php foreach ($recent as $r): ?>
                    <tr class="hover:bg-purple-50/30">
                        <td class="px-4 py-3">
                            <a href="/admin/otp-orders.php?view=<?= (int)$r['id'] ?>" class="font-mono font-bold text-purple-700 hover:underline"><?= Validator::e($r['activation_code']) ?></a>
                            <div class="text-[10px] text-slate-400">@<?= Validator::e($r['username']) ?></div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-bold text-slate-900"><?= Validator::e($r['service_name']) ?></div>
                            <div class="text-[10px] text-slate-500"><?= Validator::e(($r['flag_emoji'] ? $r['flag_emoji'] . ' ' : '') . $r['country_name']) ?></div>
                        </td>
                        <td class="px-4 py-3 font-mono">
                            <div><?= Validator::e($r['phone_number'] ?: '—') ?></div>
                            <?php if (!empty($r['otp_code'])): ?>
                            <span class="px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-800 font-extrabold"><?= Validator::e($r['otp_code']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 font-bold tabular-nums"><?= Currency::display((string)$r['selling_price'], null, 2, true) ?></td>
                        <td class="px-4 py-3"><?= Helpers::statusBadge((string)$r['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
