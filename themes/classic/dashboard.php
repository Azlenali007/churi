<?php
$pageTitle = 'Dashboard';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/sidebar.php';
require __DIR__ . '/partials/header.php';

// Real User Statistics from MySQL
$stats = Database::fetch(
    "SELECT
        COUNT(*) AS total_orders,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_orders,
        SUM(CASE WHEN status IN ('pending','in_progress','processing') THEN 1 ELSE 0 END) AS active_orders
     FROM orders WHERE user_id = ?",
    [$user['id']]
);

$recentOrders = Database::fetchAll(
    "SELECT o.*, s.name AS service_name, s.platform
     FROM orders o
     JOIN services s ON s.id = o.service_id
     WHERE o.user_id = ?
     ORDER BY o.id DESC LIMIT 6",
    [$user['id']]
);

$recentTx = Database::fetchAll(
    "SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 5",
    [$user['id']]
);

$announcements = Database::fetchAll(
    "SELECT * FROM announcements WHERE status = 'active' ORDER BY id DESC LIMIT 3"
);

// Real 7-day daily spending series for SVG chart
$daysBack = (($_GET['range'] ?? '7') === '30') ? 30 : 7;
$spendRows = Database::fetchAll(
    "SELECT DATE(created_at) AS d, COALESCE(SUM(charge), 0) AS total
     FROM orders
     WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
     GROUP BY DATE(created_at)
     ORDER BY d ASC",
    [$user['id'], $daysBack - 1]
);
$spendMap = [];
foreach ($spendRows as $r) {
    $spendMap[$r['d']] = (float)$r['total'];
}
$chartPoints = [];
$periodTotal = 0.0;
for ($i = $daysBack - 1; $i >= 0; $i--) {
    $dateKey = date('Y-m-d', strtotime("-{$i} days"));
    $label = $daysBack === 7 ? date('D', strtotime($dateKey)) : date('M j', strtotime($dateKey));
    $val = $spendMap[$dateKey] ?? 0.0;
    $periodTotal += $val;
    $chartPoints[] = ['label' => $label, 'value' => $val];
}

// Categories & Services for Quick Order
$quickCategories = Database::fetchAll("SELECT id, name, platform FROM categories WHERE status = 'active' ORDER BY sort_order ASC, id ASC");
$quickServices = Database::fetchAll("SELECT id, category_id, name, rate, min_qty, max_qty, platform FROM services WHERE status = 'active' ORDER BY sort_order ASC, id ASC");
foreach ($quickServices as &$qs) {
    $p = FlashSale::getServicePricing($qs);
    $qs['effective_rate'] = $p['effective_rate'];
    $qs['on_sale'] = $p['on_sale'];
}
unset($qs);
?>

<?php require APP_ROOT . '/includes/flash_sale_banner.php'; ?>

<!-- 4 KPI Cards Row (Real User DB Metrics) -->
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
    <div class="smm-card rounded-2xl p-5 flex items-center gap-4">
        <div class="w-12 h-12 rounded-2xl bg-purple-100 text-purple-700 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
        </div>
        <div>
            <div class="text-xs font-semibold text-slate-500">Total Orders</div>
            <div class="text-2xl font-extrabold text-slate-900 mt-0.5"><?= number_format((int)($stats['total_orders'] ?? 0)) ?></div>
            <div class="text-[11px] font-semibold text-purple-600 mt-0.5">Lifetime submissions</div>
        </div>
    </div>

    <div class="smm-card rounded-2xl p-5 flex items-center gap-4">
        <div class="w-12 h-12 rounded-2xl bg-emerald-100 text-emerald-600 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div>
            <div class="text-xs font-semibold text-slate-500">Completed Orders</div>
            <div class="text-2xl font-extrabold text-slate-900 mt-0.5"><?= number_format((int)($stats['completed_orders'] ?? 0)) ?></div>
            <div class="text-[11px] font-semibold text-emerald-600 mt-0.5">Delivered successfully</div>
        </div>
    </div>

    <div class="smm-card rounded-2xl p-5 flex items-center gap-4">
        <div class="w-12 h-12 rounded-2xl bg-indigo-100 text-indigo-600 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div>
            <div class="text-xs font-semibold text-slate-500">Pending / Active</div>
            <div class="text-2xl font-extrabold text-slate-900 mt-0.5"><?= number_format((int)($stats['active_orders'] ?? 0)) ?></div>
            <div class="text-[11px] font-semibold text-indigo-600 mt-0.5">In fulfillment queue</div>
        </div>
    </div>

    <div class="smm-card rounded-2xl p-5 flex items-center gap-4">
        <div class="w-12 h-12 rounded-2xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor"><path d="M5 16L3 5l5.5 5L12 4l3.5 6L21 5l-2 11H5zm0 2h14v2H5v-2z"/></svg>
        </div>
        <div>
            <div class="text-xs font-semibold text-slate-500">Total Spent</div>
            <div class="text-2xl font-extrabold text-slate-900 mt-0.5"><?= Currency::format((string)$user['total_spent']) ?></div>
            <div class="text-[11px] font-semibold text-amber-600 mt-0.5">Verified wallet usage</div>
        </div>
    </div>
</div>

<!-- Main Dashboard Grid -->
<div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
    <!-- Left Column (8 cols) -->
    <div class="xl:col-span-8 space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div>
                <h1 class="text-xl sm:text-2xl font-extrabold text-slate-900">Welcome Back, <?= Validator::e($user['full_name']) ?>!</h1>
                <p class="text-sm text-slate-500">Here's what's happening with your account and campaigns today.</p>
            </div>
        </div>

        <!-- Wallet Balance Card + Quick Order Card -->
        <div class="grid grid-cols-1 md:grid-cols-12 gap-5">
            <div class="md:col-span-5 smm-wallet-card rounded-3xl p-6 text-white flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-11 h-11 rounded-2xl bg-white/20 flex items-center justify-center">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                        </div>
                        <span class="px-2.5 py-1 rounded-full bg-white/20 text-[11px] font-bold">Active Wallet</span>
                    </div>
                    <div class="text-xs font-semibold text-purple-100">Wallet Balance</div>
                    <div class="text-3xl font-extrabold tracking-tight mt-1"><?= Currency::format((string)$user['wallet_balance']) ?></div>
                </div>
                <div class="mt-6">
                    <a href="/user/add-funds.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-white text-purple-800 font-bold text-sm shadow-md hover:bg-purple-50 transition">
                        Add Funds
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                    </a>
                </div>
            </div>

            <!-- Quick Order Box -->
            <div class="md:col-span-7 smm-card rounded-3xl p-5 sm:p-6"
                 x-data="{
                    services: <?= Validator::e(json_encode($quickServices)) ?>,
                    categoryId: <?= !empty($quickCategories) ? (int)$quickCategories[0]['id'] : 0 ?>,
                    serviceId: 0,
                    qty: 100,
                    init() { this.syncFirstService(); },
                    get filtered() { return this.services.filter(s => Number(s.category_id) === Number(this.categoryId)); },
                    get activeSvc() { return this.services.find(s => Number(s.id) === Number(this.serviceId)) || null; },
                    get total() {
                        if (!this.activeSvc) return '0.00';
                        const val = (Number(this.activeSvc.effective_rate) * Number(this.qty || 0)) / 1000;
                        return val.toFixed(2);
                    },
                    syncFirstService() {
                        const list = this.filtered;
                        this.serviceId = list.length ? Number(list[0].id) : 0;
                        if (list.length) this.qty = Number(list[0].min_qty);
                    }
                 }">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="font-extrabold text-slate-900 text-base">Quick Order</h2>
                    <a href="/user/new-order.php" class="text-xs font-bold text-purple-600 hover:underline">Full Order Form &rarr;</a>
                </div>

                <?php if (empty($quickServices)): ?>
                    <p class="text-xs text-slate-500 py-6 text-center">No active services available yet.</p>
                <?php else: ?>
                <form action="/user/new-order.php" method="POST" class="space-y-3">
                    <?= CSRF::field() ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <select x-model="categoryId" @change="syncFirstService()" class="w-full px-3 py-2 rounded-xl border border-purple-200 text-xs font-semibold outline-none focus:border-purple-600">
                            <?php foreach ($quickCategories as $qc): ?>
                                <option value="<?= (int)$qc['id'] ?>"><?= Validator::e($qc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="service_id" x-model="serviceId" @change="if(activeSvc) qty = Number(activeSvc.min_qty)" class="w-full px-3 py-2 rounded-xl border border-purple-200 text-xs font-semibold outline-none focus:border-purple-600">
                            <template x-for="s in filtered" :key="s.id">
                                <option :value="s.id" x-text="`#${s.id} ${s.name}`"></option>
                            </template>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <input type="text" name="link" required placeholder="Target link or @username" class="sm:col-span-2 px-3 py-2 rounded-xl border border-purple-200 text-xs outline-none focus:border-purple-600">
                        <input type="number" name="quantity" x-model="qty" :min="activeSvc ? activeSvc.min_qty : 1" :max="activeSvc ? activeSvc.max_qty : 1000000" required class="px-3 py-2 rounded-xl border border-purple-200 text-xs font-bold outline-none focus:border-purple-600">
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <div class="text-xs text-slate-500">
                            Charge: <strong class="text-sm text-purple-700"><?= Validator::e(Helpers::setting('currency_symbol', '₹')) ?><span x-text="total">0.00</span></strong>
                        </div>
                        <button type="submit" class="px-4 py-2 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-xs font-bold shadow-sm transition">
                            Place Order
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Orders Card -->
        <div class="smm-card rounded-3xl p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-extrabold text-slate-900">Recent Orders</h2>
                <a href="/user/orders.php" class="text-xs font-bold text-purple-600 hover:text-purple-800 inline-flex items-center gap-1">
                    View All <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </a>
            </div>
            <?php if (empty($recentOrders)): ?>
                <div class="text-center py-8 text-sm text-slate-500">
                    You have not placed any orders yet. <a href="/user/new-order.php" class="text-purple-600 font-bold hover:underline">Create your first order</a>.
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-purple-100 text-xs uppercase text-slate-400">
                                <th class="py-2.5 pr-3">ID</th>
                                <th class="py-2.5 pr-3">Service</th>
                                <th class="py-2.5 pr-3">Status</th>
                                <th class="py-2.5 pr-3">Amount</th>
                                <th class="py-2.5 text-right">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($recentOrders as $ord): ?>
                            <tr class="hover:bg-purple-50/40">
                                <td class="py-3 pr-3 font-bold text-slate-700">#<?= (int)$ord['id'] ?></td>
                                <td class="py-3 pr-3">
                                    <div class="flex items-center gap-2.5">
                                        <?= Helpers::platformIcon((string)$ord['platform'], 'w-7 h-7') ?>
                                        <span class="font-semibold text-slate-900 truncate max-w-[220px]"><?= Validator::e($ord['service_name']) ?></span>
                                    </div>
                                </td>
                                <td class="py-3 pr-3"><?= Helpers::statusBadge((string)$ord['status']) ?></td>
                                <td class="py-3 pr-3 font-bold text-slate-900"><?= Currency::format((string)$ord['charge']) ?></td>
                                <td class="py-3 text-right text-xs text-slate-500"><?= Helpers::timeAgo($ord['created_at']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right Column (4 cols) -->
    <div class="xl:col-span-4 space-y-6">
        <!-- Spending Overview Chart -->
        <div class="smm-card rounded-3xl p-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-base font-extrabold text-slate-900">Spending Overview</h2>
                    <div class="text-xs text-slate-500">Period Total: <strong class="text-purple-700"><?= Currency::format((string)$periodTotal) ?></strong></div>
                </div>
                <div class="flex gap-1 bg-[#F3F1FC] p-1 rounded-xl text-xs font-bold">
                    <a href="?range=7" class="px-2.5 py-1 rounded-lg <?= $daysBack === 7 ? 'bg-white text-purple-700 shadow-sm' : 'text-slate-500' ?>">7D</a>
                    <a href="?range=30" class="px-2.5 py-1 rounded-lg <?= $daysBack === 30 ? 'bg-white text-purple-700 shadow-sm' : 'text-slate-500' ?>">30D</a>
                </div>
            </div>
            <?php
            $maxVal = max(array_column($chartPoints, 'value') ?: [1]);
            if ($maxVal <= 0) $maxVal = 1;
            $w = 320; $h = 140; $pad = 14;
            $count = count($chartPoints);
            $coords = [];
            foreach ($chartPoints as $idx => $pt) {
                $x = $count > 1 ? $pad + ($idx * (($w - 2 * $pad) / ($count - 1))) : $w / 2;
                $y = $h - $pad - (($pt['value'] / $maxVal) * ($h - 2 * $pad));
                $coords[] = ['x' => round($x, 1), 'y' => round($y, 1), 'label' => $pt['label'], 'val' => $pt['value']];
            }
            $linePath = '';
            foreach ($coords as $i => $c) {
                $linePath .= ($i === 0 ? 'M ' : ' L ') . $c['x'] . ' ' . $c['y'];
            }
            $areaPath = $linePath . ' L ' . end($coords)['x'] . ' ' . ($h - $pad) . ' L ' . $coords[0]['x'] . ' ' . ($h - $pad) . ' Z';
            ?>
            <svg viewBox="0 0 <?= $w ?> <?= $h ?>" class="w-full h-40 overflow-visible">
                <defs>
                    <linearGradient id="userSpendGrad" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#7c3aed" stop-opacity="0.35"/>
                        <stop offset="100%" stop-color="#7c3aed" stop-opacity="0.0"/>
                    </linearGradient>
                </defs>
                <path d="<?= $areaPath ?>" fill="url(#userSpendGrad)"/>
                <path d="<?= $linePath ?>" fill="none" stroke="#7c3aed" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                <?php foreach ($coords as $c): ?>
                    <circle cx="<?= $c['x'] ?>" cy="<?= $c['y'] ?>" r="4" fill="#fff" stroke="#7c3aed" stroke-width="2.5">
                        <title><?= Validator::e($c['label']) ?>: <?= Currency::format((string)$c['val']) ?></title>
                    </circle>
                <?php endforeach; ?>
            </svg>
            <div class="flex justify-between text-[10px] font-semibold text-slate-400 mt-1 px-1">
                <span><?= Validator::e($chartPoints[0]['label'] ?? '') ?></span>
                <span><?= Validator::e($chartPoints[(int)(count($chartPoints)/2)]['label'] ?? '') ?></span>
                <span><?= Validator::e(end($chartPoints)['label'] ?? '') ?></span>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="smm-card rounded-3xl p-6">
            <h2 class="text-base font-extrabold text-slate-900 mb-4">Quick Actions</h2>
            <div class="grid grid-cols-3 gap-3">
                <a href="/user/new-order.php" class="flex flex-col items-center justify-center p-4 rounded-2xl bg-[#F3F1FC] hover:bg-purple-100 text-purple-700 transition text-center">
                    <svg class="w-6 h-6 mb-2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                    <span class="text-xs font-bold">New Order</span>
                </a>
                <a href="/user/add-funds.php" class="flex flex-col items-center justify-center p-4 rounded-2xl bg-[#F3F1FC] hover:bg-purple-100 text-purple-700 transition text-center">
                    <svg class="w-6 h-6 mb-2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                    <span class="text-xs font-bold">Add Funds</span>
                </a>
                <a href="/user/tickets.php" class="flex flex-col items-center justify-center p-4 rounded-2xl bg-[#F3F1FC] hover:bg-purple-100 text-purple-700 transition text-center">
                    <svg class="w-6 h-6 mb-2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>
                    <span class="text-xs font-bold">Support</span>
                </a>
            </div>
        </div>

        <!-- Announcements & Recent Wallet Transactions -->
        <div class="smm-card rounded-3xl p-6">
            <h2 class="text-base font-extrabold text-slate-900 mb-3">Announcements</h2>
            <?php if (empty($announcements)): ?>
                <p class="text-xs text-slate-500">No active announcements right now.</p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($announcements as $ann): ?>
                    <div class="p-3.5 rounded-2xl bg-purple-50/70 border border-purple-100">
                        <div class="text-xs font-bold text-purple-900"><?= Validator::e($ann['title']) ?></div>
                        <div class="text-xs text-slate-600 mt-1"><?= Validator::e($ann['content']) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="smm-card rounded-3xl p-6">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-base font-extrabold text-slate-900">Recent Transactions</h2>
                <a href="/user/add-funds.php" class="text-xs font-bold text-purple-600 hover:underline">History</a>
            </div>
            <?php if (empty($recentTx)): ?>
                <p class="text-xs text-slate-500">No wallet transactions recorded yet.</p>
            <?php else: ?>
                <div class="divide-y divide-slate-100">
                    <?php foreach ($recentTx as $tx):
                        $isCredit = in_array($tx['type'], ['credit','refund','referral_bonus','admin_add','payment'], true);
                    ?>
                    <div class="py-2.5 flex items-center justify-between gap-2 text-xs">
                        <div class="min-w-0">
                            <div class="font-semibold text-slate-800 truncate"><?= Validator::e($tx['description']) ?></div>
                            <div class="text-[11px] text-slate-400"><?= Helpers::timeAgo($tx['created_at']) ?></div>
                        </div>
                        <span class="font-extrabold shrink-0 <?= $isCredit ? 'text-emerald-600' : 'text-rose-600' ?>">
                            <?= $isCredit ? '+' : '-' ?><?= Currency::format((string)$tx['amount']) ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
