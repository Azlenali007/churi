<?php
$pageTitle = 'Dashboard';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/sidebar.php';
require __DIR__ . '/partials/header.php';

$displayCurr = Currency::getActiveDisplayCurrency($user);
$displayCode = $displayCurr['code'];
$displaySymbol = $displayCurr['symbol'];
$baseCode = Currency::getBaseCode();

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

// Real Popular Services by active platform (cheapest active platform rate from DB)
$popularPlatforms = Database::fetchAll(
    "SELECT s.platform, MIN(s.rate) AS min_rate, COUNT(*) AS svc_count
     FROM services s
     JOIN categories c ON c.id = s.category_id
     WHERE s.status = 'active' AND c.status = 'active' AND s.platform != '' AND s.platform != 'general'
     GROUP BY s.platform
     ORDER BY svc_count DESC, min_rate ASC
     LIMIT 4"
);

// Real 7-day / 30-day daily spending series for SVG chart (converted once to display currency)
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
$periodTotalBase = '0.0000';
for ($i = $daysBack - 1; $i >= 0; $i--) {
    $dateKey = date('Y-m-d', strtotime("-{$i} days"));
    $label = $daysBack === 7 ? date('D', strtotime($dateKey)) : date('M j', strtotime($dateKey));
    $valBase = number_format($spendMap[$dateKey] ?? 0.0, 4, '.', '');
    $periodTotalBase = Currency::add($periodTotalBase, $valBase, 4);
    $valDisplay = (float)Currency::convertFromBase($valBase, $displayCode, 4);
    $chartPoints[] = ['label' => $label, 'value' => $valDisplay, 'base_str' => $valBase];
}

// Categories & Services for Quick Order (pre-converted ONCE to display currency)
$quickCategories = Database::fetchAll("SELECT id, name, platform FROM categories WHERE status = 'active' ORDER BY sort_order ASC, id ASC");
$quickServicesRaw = Database::fetchAll("SELECT id, category_id, name, rate, min_qty, max_qty, platform FROM services WHERE status = 'active' ORDER BY sort_order ASC, id ASC");
$quickServices = [];
$userDiscount = Currency::normalize((string)($user['custom_rate_discount'] ?? '0'), 2);
foreach ($quickServicesRaw as $qs) {
    $p = FlashSale::getServicePricing($qs);
    $effBase = $p['effective_rate'];
    if (!$p['on_sale'] && Currency::cmp($userDiscount, '0', 2) > 0) {
        $effBase = Currency::applyDiscount($effBase, 'percent', $userDiscount);
    }
    $qs['effective_rate_display'] = (float)Currency::convertFromBase($effBase, $displayCode, 4);
    $qs['on_sale'] = $p['on_sale'];
    $quickServices[] = $qs;
}

$isActiveAccount = ($user['status'] ?? 'active') === 'active';
?>

<?php require APP_ROOT . '/includes/flash_sale_banner.php'; ?>

<!-- Hero Row: Greeting Card + Vibrant Your Balance Card (Matches Visual Reference) -->
<div class="grid grid-cols-1 lg:grid-cols-12 gap-5 mb-6">
    <!-- Welcome Back Hero Card -->
    <div class="lg:col-span-6 smm-greeting-card rounded-3xl p-5 sm:p-6 flex items-center justify-between gap-4 relative overflow-hidden">
        <div class="flex items-center gap-4 min-w-0 z-10">
            <div class="w-14 h-14 rounded-2xl bg-amber-50 border border-amber-200/70 flex items-center justify-center shadow-sm shrink-0">
                <svg class="w-8 h-8 text-amber-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 11V6a2 2 0 0 0-2-2a2 2 0 0 0-2 2"/><path d="M14 10V4a2 2 0 0 0-2-2a2 2 0 0 0-2 2v2"/><path d="M10 10.5V6a2 2 0 0 0-2-2a2 2 0 0 0-2 2v8"/><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15"/></svg>
            </div>
            <div class="min-w-0">
                <div class="text-xs sm:text-sm font-semibold text-slate-500">Welcome back,</div>
                <div class="flex items-center gap-2 mt-0.5">
                    <h1 class="text-xl sm:text-2xl font-extrabold text-slate-900 truncate"><?= Validator::e($user['full_name']) ?></h1>
                    <span title="Verified Account" class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-sky-500 text-white shrink-0">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                    </span>
                </div>
                <div class="text-xs font-bold text-slate-500 mt-1">
                    User ID: #<?= (int)$user['id'] ?> <span class="text-slate-300 mx-1">&bull;</span> @<?= Validator::e($user['username']) ?>
                </div>
            </div>
        </div>

        <!-- Crisp 3D-style Growth Chart Illustration -->
        <div class="hidden sm:flex items-end gap-1.5 shrink-0 pr-1" aria-hidden="true">
            <div class="w-4 h-6 rounded-t-lg bg-gradient-to-t from-purple-500 to-indigo-400 shadow-sm"></div>
            <div class="w-4 h-9 rounded-t-lg bg-gradient-to-t from-indigo-500 to-sky-400 shadow-sm"></div>
            <div class="w-4 h-12 rounded-t-lg bg-gradient-to-t from-violet-600 to-purple-400 shadow-sm"></div>
            <div class="w-4 h-16 rounded-t-lg bg-gradient-to-t from-indigo-600 to-violet-500 shadow-md"></div>
        </div>
    </div>

    <!-- Your Balance Hero Card -->
    <div class="lg:col-span-6 smm-wallet-card rounded-3xl p-5 sm:p-6 text-white relative overflow-hidden flex items-center justify-between gap-4">
        <div class="absolute -right-10 -top-10 w-44 h-44 bg-white/10 rounded-full blur-2xl pointer-events-none"></div>
        <div class="flex items-center gap-4 min-w-0 z-10">
            <div class="w-14 h-14 rounded-2xl bg-white/20 backdrop-blur-md border border-white/25 flex items-center justify-center shadow-inner shrink-0">
                <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="3"/><line x1="1" y1="10" x2="23" y2="10"/><circle cx="17" cy="14.5" r="1.2" fill="currentColor"/></svg>
            </div>
            <div class="min-w-0">
                <div class="text-xs font-semibold text-purple-100">Your Balance</div>
                <div class="text-2xl sm:text-3xl font-extrabold tracking-tight mt-0.5 tabular-nums truncate">
                    <?= Currency::display((string)$user['wallet_balance'], null, 2, true) ?>
                </div>
                <div class="mt-2 inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold <?= $isActiveAccount ? 'bg-emerald-500 text-white' : 'bg-amber-500 text-white' ?> shadow-sm">
                    <span class="w-2 h-2 rounded-full bg-white"></span>
                    <span><?= $isActiveAccount ? 'Active Account' : ucfirst((string)$user['status']) ?></span>
                </div>
            </div>
        </div>

        <a href="/user/add-funds.php"
           class="z-10 shrink-0 inline-flex items-center gap-1.5 px-4 py-2.5 rounded-full bg-white text-purple-800 font-extrabold text-xs sm:text-sm shadow-lg hover:bg-purple-50 transition">
            <span>+ Add Funds</span>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
        </a>
    </div>
</div>

<!-- 2x2 / 4-col Quick Action Cards (Matches Visual Reference) -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5 sm:gap-4 mb-6">
    <a href="/user/new-order.php" class="smm-card rounded-3xl p-4 flex items-center justify-between gap-2 hover:-translate-y-0.5 transition">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-11 h-11 rounded-2xl bg-purple-100 text-purple-700 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.1" viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
            </div>
            <div class="min-w-0">
                <div class="text-sm font-extrabold text-slate-900 truncate">New Order</div>
                <div class="text-[11px] text-slate-500 truncate">Place Order Now</div>
            </div>
        </div>
        <span class="w-7 h-7 rounded-full bg-purple-50 border border-purple-100 text-purple-600 flex items-center justify-center shrink-0">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </span>
    </a>

    <a href="/user/services.php" class="smm-card rounded-3xl p-4 flex items-center justify-between gap-2 hover:-translate-y-0.5 transition">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-11 h-11 rounded-2xl bg-sky-100 text-sky-600 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.1" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
            </div>
            <div class="min-w-0">
                <div class="text-sm font-extrabold text-slate-900 truncate">Services</div>
                <div class="text-[11px] text-slate-500 truncate">View All Services</div>
            </div>
        </div>
        <span class="w-7 h-7 rounded-full bg-purple-50 border border-purple-100 text-purple-600 flex items-center justify-center shrink-0">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </span>
    </a>

    <a href="/user/add-funds.php" class="smm-card rounded-3xl p-4 flex items-center justify-between gap-2 hover:-translate-y-0.5 transition">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-11 h-11 rounded-2xl bg-emerald-100 text-emerald-600 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.1" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            </div>
            <div class="min-w-0">
                <div class="text-sm font-extrabold text-slate-900 truncate">Add Funds</div>
                <div class="text-[11px] text-slate-500 truncate">Recharge Wallet</div>
            </div>
        </div>
        <span class="w-7 h-7 rounded-full bg-purple-50 border border-purple-100 text-purple-600 flex items-center justify-center shrink-0">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </span>
    </a>

    <a href="/user/orders.php" class="smm-card rounded-3xl p-4 flex items-center justify-between gap-2 hover:-translate-y-0.5 transition">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-11 h-11 rounded-2xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.1" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg>
            </div>
            <div class="min-w-0">
                <div class="text-sm font-extrabold text-slate-900 truncate">Orders</div>
                <div class="text-[11px] text-slate-500 truncate">Check Your Orders</div>
            </div>
        </div>
        <span class="w-7 h-7 rounded-full bg-purple-50 border border-purple-100 text-purple-600 flex items-center justify-center shrink-0">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </span>
    </a>
</div>

<?php if (!empty($popularPlatforms)): ?>
<!-- Popular Services Strip (Real DB Cheapest Rates per Platform) -->
<div class="smm-card rounded-3xl p-5 sm:p-6 mb-6">
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-2">
            <span class="w-7 h-7 rounded-xl bg-orange-100 text-orange-500 flex items-center justify-center">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2c.5 4-2 6-2 9a4 4 0 0 0 8 0c0-3-2-5-2-9 2.5 2 5 5 5 9a9 9 0 1 1-18 0c0-4 2.5-7 5-9-.5 2.5 1 4 2 5 .5-2 1-3.5 2-5z"/></svg>
            </span>
            <h2 class="text-base font-extrabold text-slate-900">Popular Services</h2>
        </div>
        <a href="/user/services.php" class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-[#F3F1FC] text-purple-700 text-xs font-bold hover:bg-purple-100 transition">
            <span>View All</span>
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
        </a>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3.5">
        <?php foreach ($popularPlatforms as $pp):
            $pKey = strtolower((string)$pp['platform']);
            $pName = PLATFORM_KEYS[$pKey] ?? ucfirst($pKey);
        ?>
        <a href="/user/services.php?platform=<?= urlencode($pKey) ?>"
           class="group rounded-2xl bg-[#F7F5FE] hover:bg-purple-50 border border-purple-100/80 p-4 text-center transition">
            <div class="flex justify-center mb-2.5 group-hover:scale-105 transition-transform">
                <?= Helpers::platformIcon($pKey, 'w-11 h-11 rounded-2xl') ?>
            </div>
            <div class="font-extrabold text-slate-900 text-sm"><?= Validator::e($pName) ?></div>
            <div class="text-[11px] text-slate-500 mb-2.5"><?= (int)$pp['svc_count'] ?> Service<?= (int)$pp['svc_count'] > 1 ? 's' : '' ?></div>
            <div class="inline-block px-3 py-1 rounded-full bg-purple-100/90 text-purple-800 text-[11px] font-extrabold tabular-nums">
                From <?= Currency::displayRate((string)$pp['min_rate'], null, false) ?>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- 4 KPI Cards Row (Real User DB Metrics) -->
<div class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
    <div class="smm-card rounded-3xl p-5 flex items-center gap-4">
        <div class="w-12 h-12 rounded-2xl bg-purple-100 text-purple-700 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
        </div>
        <div>
            <div class="text-xs font-semibold text-slate-500">Total Orders</div>
            <div class="text-2xl font-extrabold text-slate-900 mt-0.5 tabular-nums"><?= number_format((int)($stats['total_orders'] ?? 0)) ?></div>
            <div class="text-[11px] font-semibold text-purple-600 mt-0.5">Lifetime orders</div>
        </div>
    </div>

    <div class="smm-card rounded-3xl p-5 flex items-center gap-4">
        <div class="w-12 h-12 rounded-2xl bg-emerald-100 text-emerald-600 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div>
            <div class="text-xs font-semibold text-slate-500">Completed Orders</div>
            <div class="text-2xl font-extrabold text-slate-900 mt-0.5 tabular-nums"><?= number_format((int)($stats['completed_orders'] ?? 0)) ?></div>
            <div class="text-[11px] font-semibold text-emerald-600 mt-0.5">Delivered cleanly</div>
        </div>
    </div>

    <div class="smm-card rounded-3xl p-5 flex items-center gap-4">
        <div class="w-12 h-12 rounded-2xl bg-indigo-100 text-indigo-600 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div>
            <div class="text-xs font-semibold text-slate-500">Pending / Active</div>
            <div class="text-2xl font-extrabold text-slate-900 mt-0.5 tabular-nums"><?= number_format((int)($stats['active_orders'] ?? 0)) ?></div>
            <div class="text-[11px] font-semibold text-indigo-600 mt-0.5">In fulfillment queue</div>
        </div>
    </div>

    <div class="smm-card rounded-3xl p-5 flex items-center gap-4">
        <div class="w-12 h-12 rounded-2xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor"><path d="M5 16L3 5l5.5 5L12 4l3.5 6L21 5l-2 11H5zm0 2h14v2H5v-2z"/></svg>
        </div>
        <div class="min-w-0">
            <div class="text-xs font-semibold text-slate-500">Total Spent</div>
            <div class="text-xl sm:text-2xl font-extrabold text-slate-900 mt-0.5 tabular-nums truncate"><?= Currency::display((string)$user['total_spent'], null, 2, true) ?></div>
            <div class="text-[11px] font-semibold text-amber-600 mt-0.5">Verified usage</div>
        </div>
    </div>
</div>

<!-- Main Dashboard Grid -->
<div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
    <!-- Left Column (8 cols) -->
    <div class="xl:col-span-8 space-y-6">
        <!-- Quick Order Box -->
        <div class="smm-card rounded-3xl p-5 sm:p-6"
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
                    const val = (Number(this.activeSvc.effective_rate_display) * Number(this.qty || 0)) / 1000;
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
                    <select x-model="categoryId" @change="syncFirstService()" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 text-xs font-semibold outline-none focus:border-purple-600 bg-white">
                        <?php foreach ($quickCategories as $qc): ?>
                            <option value="<?= (int)$qc['id'] ?>"><?= Validator::e($qc['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="service_id" x-model="serviceId" @change="if(activeSvc) qty = Number(activeSvc.min_qty)" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 text-xs font-semibold outline-none focus:border-purple-600 bg-white">
                        <template x-for="s in filtered" :key="s.id">
                            <option :value="s.id" x-text="`#${s.id} ${s.name}`"></option>
                        </template>
                    </select>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <input type="text" name="link" required placeholder="Target link or @username" class="sm:col-span-2 px-3.5 py-2.5 rounded-xl border border-purple-200 text-xs outline-none focus:border-purple-600">
                    <input type="number" name="quantity" x-model="qty" :min="activeSvc ? activeSvc.min_qty : 1" :max="activeSvc ? activeSvc.max_qty : 1000000" required class="px-3.5 py-2.5 rounded-xl border border-purple-200 text-xs font-bold outline-none focus:border-purple-600 tabular-nums">
                </div>
                <div class="flex items-center justify-between pt-1">
                    <div class="text-xs text-slate-500">
                        Estimated Charge: <strong class="text-sm text-purple-700 tabular-nums"><?= Validator::e($displaySymbol) ?><span x-text="total">0.00</span> <?= Validator::e($displayCode) ?></strong>
                    </div>
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-purple-600 to-violet-600 hover:opacity-95 text-white text-xs font-bold shadow-md shadow-purple-500/20 transition">
                        Place Order
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>

        <!-- Recent Orders Card -->
        <div class="smm-card rounded-3xl p-5 sm:p-6">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-2">
                    <span class="w-7 h-7 rounded-xl bg-purple-100 text-purple-700 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </span>
                    <h2 class="text-base font-extrabold text-slate-900">Recent Orders</h2>
                </div>
                <a href="/user/orders.php" class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-[#F3F1FC] text-purple-700 text-xs font-bold hover:bg-purple-100 transition">
                    <span>View All</span>
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </a>
            </div>
            <?php if (empty($recentOrders)): ?>
                <div class="text-center py-8 text-sm text-slate-500">
                    You have not placed any orders yet. <a href="/user/new-order.php" class="text-purple-600 font-bold hover:underline">Create your first order</a>.
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($recentOrders as $ord):
                        $ordCurr = (string)($ord['currency_code'] ?? $baseCode);
                        if ($ordCurr === '') $ordCurr = $baseCode;
                    ?>
                    <a href="/user/orders.php?search=<?= (int)$ord['id'] ?>"
                       class="flex items-center justify-between gap-3 p-3.5 rounded-2xl bg-[#F8F6FE] hover:bg-purple-50/90 border border-purple-100/70 transition">
                        <div class="flex items-center gap-3 min-w-0">
                            <?= Helpers::platformIcon((string)$ord['platform'], 'w-10 h-10 rounded-xl') ?>
                            <div class="min-w-0">
                                <div class="font-bold text-slate-900 text-xs sm:text-sm truncate"><?= Validator::e($ord['service_name']) ?></div>
                                <div class="text-[11px] text-slate-500 mt-0.5 tabular-nums">
                                    Qty: <?= number_format((int)$ord['quantity']) ?> &bull; Order ID: #<?= (int)$ord['id'] ?>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2.5 shrink-0">
                            <?php
                                $ordOrig = Currency::formatRaw((string)$ord['charge'], $ordCurr, 2, true);
                                $ordDisp = ($displayCode !== $ordCurr)
                                    ? Currency::display(Currency::convert((string)$ord['charge'], $ordCurr, $baseCode, 4), $displayCode, 2, true)
                                    : $ordOrig;
                            ?>
                            <div class="text-right hidden sm:block tabular-nums">
                                <div class="text-xs font-extrabold text-slate-900"><?= Validator::e($ordDisp) ?></div>
                                <?php if ($displayCode !== $ordCurr): ?>
                                    <div class="text-[10px] text-slate-400">Orig: <?= Validator::e($ordOrig) ?></div>
                                <?php endif; ?>
                            </div>
                            <?= Helpers::statusBadge((string)$ord['status']) ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
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
                    <div class="text-xs text-slate-500 tabular-nums">Period Total: <strong class="text-purple-700"><?= Currency::display($periodTotalBase, null, 2, true) ?></strong></div>
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
                $coords[] = ['x' => round($x, 1), 'y' => round($y, 1), 'label' => $pt['label'], 'base_str' => $pt['base_str']];
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
                        <title><?= Validator::e($c['label']) ?>: <?= Currency::display($c['base_str'], null, 2, true) ?></title>
                    </circle>
                <?php endforeach; ?>
            </svg>
            <div class="flex justify-between text-[10px] font-semibold text-slate-400 mt-1 px-1">
                <span><?= Validator::e($chartPoints[0]['label'] ?? '') ?></span>
                <span><?= Validator::e($chartPoints[(int)(count($chartPoints)/2)]['label'] ?? '') ?></span>
                <span><?= Validator::e(end($chartPoints)['label'] ?? '') ?></span>
            </div>
        </div>

        <!-- Announcements -->
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

        <!-- Recent Wallet Transactions -->
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
                        $txCurr = (string)($tx['currency_code'] ?? $baseCode);
                        if ($txCurr === '') $txCurr = $baseCode;
                    ?>
                    <div class="py-2.5 flex items-center justify-between gap-2 text-xs">
                        <div class="min-w-0">
                            <div class="font-semibold text-slate-800 truncate"><?= Validator::e($tx['description']) ?></div>
                            <div class="text-[11px] text-slate-400"><?= Helpers::timeAgo($tx['created_at']) ?></div>
                        </div>
                        <?php
                            $txOrig = Currency::formatRaw((string)$tx['amount'], $txCurr, 2, true);
                            $txDisp = ($displayCode !== $txCurr)
                                ? Currency::display(Currency::convert((string)$tx['amount'], $txCurr, $baseCode, 4), $displayCode, 2, true)
                                : $txOrig;
                        ?>
                        <div class="text-right shrink-0 tabular-nums">
                            <div class="font-extrabold <?= $isCredit ? 'text-emerald-600' : 'text-rose-600' ?>">
                                <?= $isCredit ? '+' : '-' ?><?= Validator::e($txDisp) ?>
                            </div>
                            <?php if ($displayCode !== $txCurr): ?>
                                <div class="text-[10px] text-slate-400">Orig: <?= $isCredit ? '+' : '-' ?><?= Validator::e($txOrig) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
