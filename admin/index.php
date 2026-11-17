<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('dashboard.view');
$pageTitle = 'Admin Dashboard';

$totalUsers = (int)Database::fetchColumn("SELECT COUNT(*) FROM users");
$totalWallet = (string)Database::fetchColumn("SELECT COALESCE(SUM(wallet_balance), 0) FROM users");
$totalProviders = (int)Database::fetchColumn("SELECT COUNT(*) FROM providers");

$orderAgg = Database::fetch(
    "SELECT
        COUNT(*) AS total_orders,
        COALESCE(SUM(CASE WHEN status NOT IN ('canceled','failed') THEN charge - refunded_amount ELSE 0 END), 0) AS total_revenue,
        COALESCE(SUM(CASE WHEN status NOT IN ('canceled','failed') THEN profit ELSE 0 END), 0) AS total_profit,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_orders,
        SUM(CASE WHEN status IN ('processing','in_progress') THEN 1 ELSE 0 END) AS processing_orders,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_orders,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_orders
     FROM orders"
);

$providers = Database::fetchAll("SELECT id, name, balance, currency_code, status, last_sync_at, last_error FROM providers ORDER BY id DESC LIMIT 6");
$recentUsers = Database::fetchAll("SELECT id, username, email, wallet_balance, status, created_at FROM users ORDER BY id DESC LIMIT 6");
$recentTx = Database::fetchAll(
    "SELECT wt.*, u.username FROM wallet_transactions wt JOIN users u ON u.id = wt.user_id ORDER BY wt.id DESC LIMIT 6"
);

// 7-Day Sales & Profit Chart Data
$chartRows = Database::fetchAll(
    "SELECT DATE(created_at) AS d,
            COALESCE(SUM(charge - refunded_amount), 0) AS revenue,
            COALESCE(SUM(profit), 0) AS profit
     FROM orders
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND status NOT IN ('canceled','failed')
     GROUP BY DATE(created_at) ORDER BY d ASC"
);
$chartMap = [];
foreach ($chartRows as $cr) {
    $chartMap[$cr['d']] = ['rev' => (float)$cr['revenue'], 'prof' => (float)$cr['profit']];
}
$series = [];
for ($i = 6; $i >= 0; $i--) {
    $dk = date('Y-m-d', strtotime("-{$i} days"));
    $series[] = [
        'label' => date('D', strtotime($dk)),
        'rev'   => $chartMap[$dk]['rev'] ?? 0.0,
        'prof'  => $chartMap[$dk]['prof'] ?? 0.0,
    ];
}

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<!-- Primary 4 KPI Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
    <div class="smm-card rounded-2xl p-5 flex items-center gap-4">
        <div class="w-12 h-12 rounded-2xl bg-purple-100 text-purple-700 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        </div>
        <div>
            <div class="text-xs font-semibold text-slate-500">Total Users</div>
            <div class="text-2xl font-extrabold text-slate-900"><?= number_format($totalUsers) ?></div>
            <div class="text-[11px] text-purple-600 font-semibold">User Wallets: <?= Currency::format($totalWallet) ?></div>
        </div>
    </div>

    <div class="smm-card rounded-2xl p-5 flex items-center gap-4">
        <div class="w-12 h-12 rounded-2xl bg-emerald-100 text-emerald-600 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
        </div>
        <div>
            <div class="text-xs font-semibold text-slate-500">Total Orders</div>
            <div class="text-2xl font-extrabold text-slate-900"><?= number_format((int)($orderAgg['total_orders'] ?? 0)) ?></div>
            <div class="text-[11px] text-emerald-600 font-semibold"><?= (int)($orderAgg['completed_orders'] ?? 0) ?> Completed</div>
        </div>
    </div>

    <div class="smm-card rounded-2xl p-5 flex items-center gap-4">
        <div class="w-12 h-12 rounded-2xl bg-indigo-100 text-indigo-600 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
        </div>
        <div>
            <div class="text-xs font-semibold text-slate-500">Total Revenue</div>
            <div class="text-2xl font-extrabold text-slate-900"><?= Currency::format((string)($orderAgg['total_revenue'] ?? '0')) ?></div>
            <div class="text-[11px] text-indigo-600 font-semibold">Providers: <?= $totalProviders ?> connected</div>
        </div>
    </div>

    <div class="smm-card rounded-2xl p-5 flex items-center gap-4">
        <div class="w-12 h-12 rounded-2xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor"><path d="M5 16L3 5l5.5 5L12 4l3.5 6L21 5l-2 11H5zm0 2h14v2H5v-2z"/></svg>
        </div>
        <div>
            <div class="text-xs font-semibold text-slate-500">Total Net Profit</div>
            <div class="text-2xl font-extrabold text-slate-900"><?= Currency::format((string)($orderAgg['total_profit'] ?? '0')) ?></div>
            <div class="text-[11px] text-amber-600 font-semibold">After provider costs</div>
        </div>
    </div>
</div>

<!-- Order Queue Mini Stats -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
    <a href="/admin/orders.php?status=pending" class="smm-card rounded-2xl p-4 hover:border-purple-300">
        <div class="text-xs text-slate-500 font-semibold">Pending Orders</div>
        <div class="text-xl font-extrabold text-amber-600 mt-1"><?= (int)($orderAgg['pending_orders'] ?? 0) ?></div>
    </a>
    <a href="/admin/orders.php?status=processing" class="smm-card rounded-2xl p-4 hover:border-purple-300">
        <div class="text-xs text-slate-500 font-semibold">Processing / In Progress</div>
        <div class="text-xl font-extrabold text-indigo-600 mt-1"><?= (int)($orderAgg['processing_orders'] ?? 0) ?></div>
    </a>
    <a href="/admin/orders.php?status=completed" class="smm-card rounded-2xl p-4 hover:border-purple-300">
        <div class="text-xs text-slate-500 font-semibold">Completed Orders</div>
        <div class="text-xl font-extrabold text-emerald-600 mt-1"><?= (int)($orderAgg['completed_orders'] ?? 0) ?></div>
    </a>
    <a href="/admin/orders.php?status=failed" class="smm-card rounded-2xl p-4 hover:border-purple-300">
        <div class="text-xs text-slate-500 font-semibold">Failed Orders</div>
        <div class="text-xl font-extrabold text-rose-600 mt-1"><?= (int)($orderAgg['failed_orders'] ?? 0) ?></div>
    </a>
</div>

<div class="grid grid-cols-1 xl:grid-cols-12 gap-6 mb-6">
    <!-- 7-Day Sales & Profit Chart -->
    <div class="xl:col-span-7 smm-card rounded-3xl p-6">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-base font-extrabold text-slate-900">Sales &amp; Profit Overview (Last 7 Days)</h2>
                <p class="text-xs text-slate-500">Purple: Revenue &bull; Emerald: Net Profit</p>
            </div>
            <a href="/admin/reports.php" class="text-xs font-bold text-purple-600 hover:underline">Full Reports &rarr;</a>
        </div>
        <?php
        $maxR = max(array_merge([1], array_column($series, 'rev')));
        $w = 500; $h = 170; $pad = 16; $cnt = count($series);
        $revPts = []; $profPts = [];
        foreach ($series as $idx => $s) {
            $x = $pad + ($idx * (($w - 2 * $pad) / max(1, $cnt - 1)));
            $yr = $h - $pad - (($s['rev'] / $maxR) * ($h - 2 * $pad));
            $yp = $h - $pad - ((max(0, $s['prof']) / $maxR) * ($h - 2 * $pad));
            $revPts[] = ['x' => round($x, 1), 'y' => round($yr, 1), 'l' => $s['label'], 'v' => $s['rev']];
            $profPts[] = ['x' => round($x, 1), 'y' => round($yp, 1), 'l' => $s['label'], 'v' => $s['prof']];
        }
        $revPath = ''; $profPath = '';
        foreach ($revPts as $i => $p) $revPath .= ($i === 0 ? 'M ' : ' L ') . $p['x'] . ' ' . $p['y'];
        foreach ($profPts as $i => $p) $profPath .= ($i === 0 ? 'M ' : ' L ') . $p['x'] . ' ' . $p['y'];
        $revArea = $revPath . ' L ' . end($revPts)['x'] . ' ' . ($h - $pad) . ' L ' . $revPts[0]['x'] . ' ' . ($h - $pad) . ' Z';
        ?>
        <svg viewBox="0 0 <?= $w ?> <?= $h ?>" class="w-full h-48 overflow-visible">
            <defs>
                <linearGradient id="admRevGrad" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#7c3aed" stop-opacity="0.30"/>
                    <stop offset="100%" stop-color="#7c3aed" stop-opacity="0"/>
                </linearGradient>
            </defs>
            <path d="<?= $revArea ?>" fill="url(#admRevGrad)"/>
            <path d="<?= $revPath ?>" fill="none" stroke="#7c3aed" stroke-width="3" stroke-linecap="round"/>
            <path d="<?= $profPath ?>" fill="none" stroke="#10b981" stroke-width="2.5" stroke-dasharray="5 4" stroke-linecap="round"/>
            <?php foreach ($revPts as $p): ?>
                <circle cx="<?= $p['x'] ?>" cy="<?= $p['y'] ?>" r="4" fill="#fff" stroke="#7c3aed" stroke-width="2.5">
                    <title><?= Validator::e($p['l']) ?> Revenue: <?= Currency::format((string)$p['v']) ?></title>
                </circle>
            <?php endforeach; ?>
        </svg>
        <div class="flex justify-between text-[11px] font-semibold text-slate-400 mt-1 px-2">
            <?php foreach ($series as $s): ?><span><?= Validator::e($s['label']) ?></span><?php endforeach; ?>
        </div>
    </div>

    <!-- Provider API Status -->
    <div class="xl:col-span-5 smm-card rounded-3xl p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-base font-extrabold text-slate-900">Provider API Status</h2>
            <a href="/admin/providers.php" class="text-xs font-bold text-purple-600 hover:underline">Manage &rarr;</a>
        </div>
        <?php if (empty($providers)): ?>
            <p class="text-xs text-slate-500 py-8 text-center">No upstream providers configured yet.</p>
        <?php else: ?>
            <div class="space-y-2.5">
                <?php foreach ($providers as $pr): ?>
                <div class="p-3 rounded-2xl bg-[#F6F4FC] flex items-center justify-between gap-3 text-xs">
                    <div class="min-w-0">
                        <div class="font-bold text-slate-900 truncate"><?= Validator::e($pr['name']) ?></div>
                        <div class="text-[11px] <?= $pr['last_error'] ? 'text-rose-600' : 'text-slate-500' ?> truncate">
                            <?= $pr['last_error'] ? Validator::e($pr['last_error']) : ('Balance: ' . Validator::e($pr['currency_code']) . ' ' . number_format((float)$pr['balance'], 2)) ?>
                        </div>
                    </div>
                    <?= Helpers::statusBadge((string)$pr['status']) ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Users & Recent Transactions -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="smm-card rounded-3xl p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-base font-extrabold text-slate-900">Recent Users</h2>
            <a href="/admin/users.php" class="text-xs font-bold text-purple-600 hover:underline">View All</a>
        </div>
        <?php if (empty($recentUsers)): ?>
            <p class="text-xs text-slate-500">No users registered yet.</p>
        <?php else: ?>
            <div class="divide-y divide-slate-100 text-xs">
                <?php foreach ($recentUsers as $ru): ?>
                <div class="py-2.5 flex items-center justify-between">
                    <div>
                        <div class="font-bold text-slate-900">@<?= Validator::e($ru['username']) ?></div>
                        <div class="text-slate-500"><?= Validator::e($ru['email']) ?></div>
                    </div>
                    <div class="text-right">
                        <div class="font-bold text-purple-700"><?= Currency::format((string)$ru['wallet_balance']) ?></div>
                        <div class="text-[10px] text-slate-400"><?= Helpers::timeAgo($ru['created_at']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="smm-card rounded-3xl p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-base font-extrabold text-slate-900">Recent Wallet Transactions</h2>
            <a href="/admin/payments.php" class="text-xs font-bold text-purple-600 hover:underline">Payments</a>
        </div>
        <?php if (empty($recentTx)): ?>
            <p class="text-xs text-slate-500">No wallet transactions recorded yet.</p>
        <?php else: ?>
            <div class="divide-y divide-slate-100 text-xs">
                <?php foreach ($recentTx as $rt): ?>
                <div class="py-2.5 flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="font-bold text-slate-900">@<?= Validator::e($rt['username']) ?> <span class="font-normal text-slate-500">(<?= Validator::e($rt['type']) ?>)</span></div>
                        <div class="text-slate-500 truncate"><?= Validator::e($rt['description']) ?></div>
                    </div>
                    <div class="font-bold text-purple-700 shrink-0"><?= Currency::format((string)$rt['amount']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
