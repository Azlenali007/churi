<?php
$pageTitle = 'Dashboard';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/sidebar.php';
require __DIR__ . '/partials/header.php';

$displayCurr = Currency::getActiveDisplayCurrency($user);
$displayCode = $displayCurr['code'];
$displaySymbol = $displayCurr['symbol'];
$baseCode = Currency::getBaseCode();

// Real User Statistics
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

$popularPlatforms = Database::fetchAll(
    "SELECT s.platform, MIN(s.rate) AS min_rate, COUNT(*) AS svc_count
     FROM services s
     JOIN categories c ON c.id = s.category_id
     WHERE s.status = 'active' AND c.status = 'active' AND s.platform != '' AND s.platform != 'general'
     GROUP BY s.platform
     ORDER BY svc_count DESC, min_rate ASC
     LIMIT 4"
);

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

// Quick Order data
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

$swipeCards = [
    [
        'title'    => 'New Order',
        'subtitle' => 'Place a new order for your social media',
        'href'     => '/user/new-order.php',
        'grad'     => 'from-blue-600 via-indigo-600 to-violet-600',
        'btnColor' => 'text-blue-600',
        'shadow'   => 'shadow-blue-600/30',
        'icon'     => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
    ],
    [
        'title'    => 'Mass Order',
        'subtitle' => 'Submit multiple links in one clean batch',
        'href'     => '/user/mass-order.php',
        'grad'     => 'from-violet-600 via-purple-600 to-fuchsia-600',
        'btnColor' => 'text-purple-700',
        'shadow'   => 'shadow-purple-600/30',
        'icon'     => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
    ],
    [
        'title'    => 'Services',
        'subtitle' => 'Browse live pricing & refill guarantee tiers',
        'href'     => '/user/services.php',
        'grad'     => 'from-sky-500 via-blue-600 to-indigo-600',
        'btnColor' => 'text-blue-600',
        'shadow'   => 'shadow-sky-500/30',
        'icon'     => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    ],
    [
        'title'    => 'Orders',
        'subtitle' => 'Track live fulfillment & request refills',
        'href'     => '/user/orders.php',
        'grad'     => 'from-emerald-500 via-teal-600 to-cyan-600',
        'btnColor' => 'text-emerald-700',
        'shadow'   => 'shadow-emerald-500/30',
        'icon'     => '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/>',
    ],
    [
        'title'    => 'Add Funds',
        'subtitle' => 'Top up your wallet instantly via UPI or card',
        'href'     => '/user/add-funds.php',
        'grad'     => 'from-indigo-600 via-blue-600 to-cyan-600',
        'btnColor' => 'text-indigo-600',
        'shadow'   => 'shadow-indigo-600/30',
        'icon'     => '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
    ],
    [
        'title'    => 'Refer & Earn',
        'subtitle' => 'Share your link & earn deposit commissions',
        'href'     => '/user/referrals.php',
        'grad'     => 'from-rose-500 via-pink-600 to-fuchsia-600',
        'btnColor' => 'text-rose-600',
        'shadow'   => 'shadow-rose-500/30',
        'icon'     => '<polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/>',
    ],
    [
        'title'    => 'Child Panel',
        'subtitle' => 'Launch your own branded white-label SMM store',
        'href'     => '/user/child-panel.php',
        'grad'     => 'from-slate-800 via-indigo-900 to-blue-800',
        'btnColor' => 'text-indigo-700',
        'shadow'   => 'shadow-slate-800/30',
        'icon'     => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
    ],
];
?>

<!-- SwipeX Minimal Open Greeting & Elevated Balance Hero (Matches Reference Photo) -->
<div class="max-w-2xl mx-auto lg:max-w-none space-y-5 mb-6">
    <!-- User Greeting Block -->
    <div class="flex items-center justify-between gap-4 px-1">
        <div class="flex items-center gap-3.5 min-w-0">
            <div class="w-12 h-12 rounded-full bg-blue-50 border-2 border-blue-200 text-blue-600 flex items-center justify-center shrink-0 shadow-sm">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </div>
            <div class="min-w-0">
                <div class="text-xs font-medium text-slate-400">Welcome back,</div>
                <h1 class="text-xl sm:text-2xl font-extrabold text-slate-900 tracking-tight truncate"><?= Validator::e($user['full_name'] ?: $user['username']) ?></h1>
                <div class="text-xs font-semibold text-slate-400">User ID: #<?= (int)$user['id'] ?></div>
            </div>
        </div>
        <div class="hidden sm:flex items-center gap-2 text-xs">
            <span class="px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 font-bold border border-emerald-200/70">
                <?= number_format((int)($stats['total_orders'] ?? 0)) ?> Orders
            </span>
            <span class="px-3 py-1 rounded-full bg-blue-50 text-blue-700 font-bold border border-blue-200/70 tabular-nums">
                Spent <?= Currency::display((string)$user['total_spent'], null, 2, true) ?>
            </span>
        </div>
    </div>

    <!-- Elevated White Your Balance Card -->
    <div class="swipex-balance-card rounded-2xl p-5 sm:p-6 flex items-center justify-between gap-4">
        <div class="min-w-0">
            <div class="text-xs font-semibold text-slate-400">Your Balance</div>
            <div class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight mt-0.5 tabular-nums truncate">
                <?= Currency::display((string)$user['wallet_balance'], null, 2, true) ?>
            </div>
        </div>
        <a href="/user/add-funds.php"
           class="shrink-0 inline-flex items-center gap-1.5 px-5 py-2.5 sm:py-3 rounded-full bg-blue-600 hover:bg-blue-700 text-white text-xs sm:text-sm font-bold shadow-lg shadow-blue-600/25 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.6" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
            <span>Add Funds</span>
        </a>
    </div>
</div>

<!-- SwipeX Center-Focus Interactive Swipeable Action Carousel -->
<div class="mb-7 overflow-hidden"
     x-data="{
        active: 0,
        total: <?= count($swipeCards) ?>,
        startX: 0,
        deltaX: 0,
        dragging: false,
        offsetFor(idx) {
            let d = idx - this.active;
            if (d > this.total / 2) d -= this.total;
            if (d < -this.total / 2) d += this.total;
            return d;
        },
        cardStyle(idx) {
            const d = this.offsetFor(idx);
            const absD = Math.abs(d);
            const dragShift = this.dragging ? this.deltaX * 0.35 : 0;
            const translateX = (d * 245) + dragShift;
            const scale = d === 0 ? 1 : (absD === 1 ? 0.87 : 0.76);
            const opacity = d === 0 ? 1 : (absD === 1 ? 0.92 : 0);
            const zIndex = 30 - absD * 10;
            return `transform: translateX(calc(-50% + ${translateX}px)) scale(${scale}); opacity: ${opacity}; z-index: ${zIndex}; pointer-events: ${absD <= 1 ? 'auto' : 'none'};`;
        },
        next() { this.active = (this.active + 1) % this.total; },
        prev() { this.active = (this.active - 1 + this.total) % this.total; },
        onTouchStart(e) { this.startX = e.touches[0].clientX; this.dragging = true; this.deltaX = 0; },
        onTouchMove(e) { if (this.dragging) this.deltaX = e.touches[0].clientX - this.startX; },
        onTouchEnd() {
            if (!this.dragging) return;
            if (this.deltaX < -42) this.next();
            else if (this.deltaX > 42) this.prev();
            this.dragging = false;
            this.deltaX = 0;
        }
     }"
     @touchstart.passive="onTouchStart($event)"
     @touchmove.passive="onTouchMove($event)"
     @touchend="onTouchEnd()">

    <div class="swipex-deck-stage">
        <?php foreach ($swipeCards as $idx => $card): ?>
        <div class="swipex-deck-card bg-gradient-to-br <?= Validator::e($card['grad']) ?> text-white shadow-2xl <?= Validator::e($card['shadow']) ?> p-6 flex flex-col items-center justify-center text-center cursor-pointer"
             :style="cardStyle(<?= $idx ?>)"
             @click="if (offsetFor(<?= $idx ?>) !== 0) { active = <?= $idx ?>; } else { window.location.href = '<?= Validator::e($card['href']) ?>'; }">
            <div class="w-16 h-16 rounded-full bg-white/20 backdrop-blur-sm border border-white/25 flex items-center justify-center mb-4 shadow-inner">
                <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><?= $card['icon'] ?></svg>
            </div>
            <h2 class="text-xl sm:text-2xl font-extrabold tracking-tight"><?= Validator::e($card['title']) ?></h2>
            <p class="text-xs sm:text-sm text-white/85 mt-1.5 max-w-[230px] leading-relaxed"><?= Validator::e($card['subtitle']) ?></p>
            <a href="<?= Validator::e($card['href']) ?>"
               @click.stop
               class="mt-5 inline-flex items-center gap-2 px-6 py-2.5 rounded-full bg-white hover:bg-slate-50 <?= Validator::e($card['btnColor']) ?> font-extrabold text-xs sm:text-sm shadow-md transition">
                <span>Open</span>
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
            </a>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Deck Controls & Dots -->
    <div class="flex items-center justify-center gap-3 mt-1">
        <button type="button" @click="prev()" aria-label="Previous card" class="w-8 h-8 rounded-full bg-white border border-slate-200 text-slate-600 hover:text-blue-600 flex items-center justify-center shadow-sm transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
        <div class="flex items-center gap-1.5">
            <?php foreach ($swipeCards as $idx => $c): ?>
            <button type="button" @click="active = <?= $idx ?>" aria-label="Slide <?= $idx + 1 ?>"
                    class="h-2 rounded-full transition-all"
                    :class="active === <?= $idx ?> ? 'w-6 bg-blue-600' : 'w-2 bg-slate-300 hover:bg-slate-400'"></button>
            <?php endforeach; ?>
        </div>
        <button type="button" @click="next()" aria-label="Next card" class="w-8 h-8 rounded-full bg-white border border-slate-200 text-slate-600 hover:text-blue-600 flex items-center justify-center shadow-sm transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
    </div>
</div>

<?php require APP_ROOT . '/includes/flash_sale_banner.php'; ?>

<?php if (!empty($popularPlatforms)): ?>
<!-- Popular Services Section -->
<div class="swipex-card rounded-3xl p-5 sm:p-6 mb-6">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-base font-extrabold text-slate-900">Popular Services</h2>
        <a href="/user/services.php" class="text-xs font-bold text-blue-600 hover:underline">View All &rarr;</a>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3.5">
        <?php foreach ($popularPlatforms as $pp):
            $pKey = strtolower((string)$pp['platform']);
            $pName = PLATFORM_KEYS[$pKey] ?? ucfirst($pKey);
        ?>
        <a href="/user/services.php?platform=<?= urlencode($pKey) ?>"
           class="group rounded-2xl bg-slate-50/90 hover:bg-blue-50/70 border border-slate-200/70 p-4 text-center transition">
            <div class="flex justify-center mb-2.5 group-hover:scale-105 transition-transform">
                <?= Helpers::platformIcon($pKey, 'w-10 h-10 rounded-xl') ?>
            </div>
            <div class="font-extrabold text-slate-900 text-sm"><?= Validator::e($pName) ?></div>
            <div class="text-[11px] text-slate-400 mb-2"><?= (int)$pp['svc_count'] ?> Services</div>
            <div class="inline-block px-3 py-1 rounded-full bg-blue-100/80 text-blue-700 text-[11px] font-extrabold tabular-nums">
                From <?= Currency::displayRate((string)$pp['min_rate'], null, false) ?>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Main Content Grid -->
<div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
    <div class="xl:col-span-8 space-y-6">
        <!-- Quick Order Box -->
        <div class="swipex-card rounded-3xl p-5 sm:p-6"
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
                <a href="/user/new-order.php" class="text-xs font-bold text-blue-600 hover:underline">Full Order Form &rarr;</a>
            </div>
            <?php if (empty($quickServices)): ?>
                <p class="text-xs text-slate-500 py-6 text-center">No active services available yet.</p>
            <?php else: ?>
            <form action="/user/new-order.php" method="POST" class="space-y-3">
                <?= CSRF::field() ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <select x-model="categoryId" @change="syncFirstService()" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold outline-none focus:border-blue-600 bg-white">
                        <?php foreach ($quickCategories as $qc): ?>
                            <option value="<?= (int)$qc['id'] ?>"><?= Validator::e($qc['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="service_id" x-model="serviceId" @change="if(activeSvc) qty = Number(activeSvc.min_qty)" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold outline-none focus:border-blue-600 bg-white">
                        <template x-for="s in filtered" :key="s.id">
                            <option :value="s.id" x-text="`#${s.id} ${s.name}`"></option>
                        </template>
                    </select>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <input type="text" name="link" required placeholder="Target link or @username" class="sm:col-span-2 px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs outline-none focus:border-blue-600">
                    <input type="number" name="quantity" x-model="qty" :min="activeSvc ? activeSvc.min_qty : 1" :max="activeSvc ? activeSvc.max_qty : 1000000" required class="px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold outline-none focus:border-blue-600 tabular-nums">
                </div>
                <div class="flex items-center justify-between pt-1">
                    <div class="text-xs text-slate-500">
                        Estimated Charge: <strong class="text-sm text-blue-600 tabular-nums"><?= Validator::e($displaySymbol) ?><span x-text="total">0.00</span> <?= Validator::e($displayCode) ?></strong>
                    </div>
                    <button type="submit" class="px-5 py-2.5 rounded-full bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold shadow-md shadow-blue-600/20 transition">
                        Place Order
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>

        <!-- Recent Orders -->
        <div class="swipex-card rounded-3xl p-5 sm:p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-extrabold text-slate-900">Recent Orders</h2>
                <a href="/user/orders.php" class="text-xs font-bold text-blue-600 hover:underline">View All &rarr;</a>
            </div>
            <?php if (empty($recentOrders)): ?>
                <div class="text-center py-8 text-sm text-slate-500">
                    You have not placed any orders yet. <a href="/user/new-order.php" class="text-blue-600 font-bold hover:underline">Create your first order</a>.
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($recentOrders as $ord):
                        $ordCurr = (string)($ord['currency_code'] ?? $baseCode);
                        if ($ordCurr === '') $ordCurr = $baseCode;
                        $ordOrig = Currency::formatRaw((string)$ord['charge'], $ordCurr, 2, true);
                        $ordDisp = ($displayCode !== $ordCurr)
                            ? Currency::display(Currency::convert((string)$ord['charge'], $ordCurr, $baseCode, 4), $displayCode, 2, true)
                            : $ordOrig;
                    ?>
                    <a href="/user/orders.php?search=<?= (int)$ord['id'] ?>"
                       class="flex items-center justify-between gap-3 p-3.5 rounded-2xl bg-slate-50 hover:bg-blue-50/60 border border-slate-200/70 transition">
                        <div class="flex items-center gap-3 min-w-0">
                            <?= Helpers::platformIcon((string)$ord['platform'], 'w-10 h-10 rounded-xl') ?>
                            <div class="min-w-0">
                                <div class="font-bold text-slate-900 text-xs sm:text-sm truncate"><?= Validator::e($ord['service_name']) ?></div>
                                <div class="text-[11px] text-slate-400 mt-0.5 tabular-nums">Qty: <?= number_format((int)$ord['quantity']) ?> &bull; #<?= (int)$ord['id'] ?></div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2.5 shrink-0">
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

    <div class="xl:col-span-4 space-y-6">
        <!-- Spending Overview -->
        <div class="swipex-card rounded-3xl p-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-base font-extrabold text-slate-900">Spending Overview</h2>
                    <div class="text-xs text-slate-500 tabular-nums">Period Total: <strong class="text-blue-600"><?= Currency::display($periodTotalBase, null, 2, true) ?></strong></div>
                </div>
                <div class="flex gap-1 bg-slate-100 p-1 rounded-xl text-xs font-bold">
                    <a href="?range=7" class="px-2.5 py-1 rounded-lg <?= $daysBack === 7 ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-500' ?>">7D</a>
                    <a href="?range=30" class="px-2.5 py-1 rounded-lg <?= $daysBack === 30 ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-500' ?>">30D</a>
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
                    <linearGradient id="swipexSpendGrad" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#2563EB" stop-opacity="0.32"/>
                        <stop offset="100%" stop-color="#2563EB" stop-opacity="0.0"/>
                    </linearGradient>
                </defs>
                <path d="<?= $areaPath ?>" fill="url(#swipexSpendGrad)"/>
                <path d="<?= $linePath ?>" fill="none" stroke="#2563EB" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                <?php foreach ($coords as $c): ?>
                    <circle cx="<?= $c['x'] ?>" cy="<?= $c['y'] ?>" r="4" fill="#fff" stroke="#2563EB" stroke-width="2.5">
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
        <div class="swipex-card rounded-3xl p-6">
            <h2 class="text-base font-extrabold text-slate-900 mb-3">Announcements</h2>
            <?php if (empty($announcements)): ?>
                <p class="text-xs text-slate-500">No active announcements right now.</p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($announcements as $ann): ?>
                    <div class="p-3.5 rounded-2xl bg-blue-50/70 border border-blue-100">
                        <div class="text-xs font-bold text-blue-900"><?= Validator::e($ann['title']) ?></div>
                        <div class="text-xs text-slate-600 mt-1"><?= Validator::e($ann['content']) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Recent Transactions -->
        <div class="swipex-card rounded-3xl p-6">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-base font-extrabold text-slate-900">Recent Transactions</h2>
                <a href="/user/add-funds.php" class="text-xs font-bold text-blue-600 hover:underline">History</a>
            </div>
            <?php if (empty($recentTx)): ?>
                <p class="text-xs text-slate-500">No wallet transactions recorded yet.</p>
            <?php else: ?>
                <div class="divide-y divide-slate-100">
                    <?php foreach ($recentTx as $tx):
                        $isCredit = in_array($tx['type'], ['credit','refund','referral_bonus','admin_add','payment'], true);
                        $txCurr = (string)($tx['currency_code'] ?? $baseCode);
                        if ($txCurr === '') $txCurr = $baseCode;
                        $txOrig = Currency::formatRaw((string)$tx['amount'], $txCurr, 2, true);
                        $txDisp = ($displayCode !== $txCurr)
                            ? Currency::display(Currency::convert((string)$tx['amount'], $txCurr, $baseCode, 4), $displayCode, 2, true)
                            : $txOrig;
                    ?>
                    <div class="py-2.5 flex items-center justify-between gap-2 text-xs">
                        <div class="min-w-0">
                            <div class="font-semibold text-slate-800 truncate"><?= Validator::e($tx['description']) ?></div>
                            <div class="text-[11px] text-slate-400"><?= Helpers::timeAgo($tx['created_at']) ?></div>
                        </div>
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
