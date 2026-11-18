<?php
$pageTitle = 'Child Panel';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/sidebar.php';
require __DIR__ . '/partials/header.php';

$panels = Database::fetchAll(
    "SELECT * FROM child_panels WHERE owner_user_id = ? ORDER BY id DESC",
    [$user['id']]
);

$selectedId = (int)($_GET['panel_id'] ?? (!empty($panels) ? $panels[0]['id'] : 0));
$activePanel = null;
foreach ($panels as $p) {
    if ((int)$p['id'] === $selectedId) {
        $activePanel = $p;
        break;
    }
}

$panelServices = [];
$panelUsers = [];
$panelOrders = [];
$panelStats = ['users' => 0, 'orders' => 0, 'revenue' => '0.00'];
$allParentServices = Database::fetchAll("SELECT id, name, rate, platform FROM services WHERE status = 'active' ORDER BY id ASC");

if ($activePanel) {
    $cpId = (int)$activePanel['id'];
    $panelServices = Database::fetchAll(
        "SELECT cps.*, s.name AS parent_name, s.rate AS parent_rate, s.platform
         FROM child_panel_services cps
         JOIN services s ON s.id = cps.parent_service_id
         WHERE cps.child_panel_id = ? ORDER BY cps.id DESC",
        [$cpId]
    );
    $panelUsers = Database::fetchAll(
        "SELECT * FROM child_panel_users WHERE child_panel_id = ? ORDER BY id DESC LIMIT 50",
        [$cpId]
    );
    $panelOrders = Database::fetchAll(
        "SELECT o.*, s.name AS service_name FROM orders o JOIN services s ON s.id = o.service_id WHERE o.child_panel_id = ? ORDER BY o.id DESC LIMIT 50",
        [$cpId]
    );
    $panelStats = Database::fetch(
        "SELECT
            (SELECT COUNT(*) FROM child_panel_users WHERE child_panel_id = ?) AS users,
            COUNT(*) AS orders,
            COALESCE(SUM(charge), 0) AS revenue
         FROM orders WHERE child_panel_id = ?",
        [$cpId, $cpId]
    );
}
$panelSetupFee = Helpers::setting('child_panel_price', '999.00');
$baseCode = Currency::getBaseCode();
$displayCurr = Currency::getActiveDisplayCurrency($user);
$displayCode = $displayCurr['code'];
?>

<div class="swipex-page-hero rounded-[22px] p-4 sm:p-5 mb-5 flex flex-wrap items-center justify-between gap-3">
    <div class="flex items-center gap-3 min-w-0">
        <div class="w-11 h-11 rounded-2xl bg-gradient-to-br from-slate-800 to-indigo-700 text-white flex items-center justify-center shadow-md shadow-indigo-700/20 shrink-0">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
        </div>
        <div class="min-w-0">
            <h1 class="text-lg sm:text-xl font-extrabold text-slate-900 truncate">Child Panels</h1>
            <p class="text-xs text-slate-500">Launch your own branded white-label SMM storefront with custom domains &amp; margins.</p>
        </div>
    </div>
    <span class="px-3 py-1 rounded-full bg-white text-blue-700 text-xs font-extrabold border border-blue-200/80 shadow-sm tabular-nums">
        Setup Fee: <?= Currency::renderMoney($panelSetupFee, $displayCode, 2, true) ?>
    </span>
</div>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
    <!-- Left: Create Child Panel + Panel Selector -->
    <div class="lg:col-span-4 space-y-6 min-w-0">
        <div class="swipex-card rounded-3xl p-6">
            <h2 class="text-base font-extrabold text-slate-900 mb-3">Your Child Panels</h2>

            <?php if (empty($panels)): ?>
                <p class="text-xs text-slate-500 mb-4">You have not created a child panel yet.</p>
            <?php else: ?>
                <div class="space-y-2 mb-5">
                    <?php foreach ($panels as $p): ?>
                    <a href="/user/child-panel.php?panel_id=<?= (int)$p['id'] ?>"
                       class="block p-3.5 rounded-2xl border transition <?= $activePanel && (int)$activePanel['id'] === (int)$p['id'] ? 'border-blue-600 bg-blue-50/70' : 'border-slate-200 hover:bg-slate-50' ?>">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-bold text-sm text-slate-900 truncate"><?= Validator::e($p['panel_name']) ?></span>
                            <?= Helpers::statusBadge((string)$p['status']) ?>
                        </div>
                        <div class="text-xs text-blue-600 mt-0.5 truncate"><?= Validator::e($p['domain']) ?></div>
                    </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="border-t border-slate-100 pt-4">
                <h3 class="text-sm font-extrabold text-slate-900 mb-3">Create New Child Panel</h3>
                <form method="POST" action="/user/child-panel.php" class="space-y-3">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="create_panel">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-500 uppercase mb-1">Panel Brand Name</label>
                        <input type="text" name="panel_name" required placeholder="My SMM Store" class="swipex-input px-3.5 py-2 text-xs">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-500 uppercase mb-1">Custom Domain</label>
                        <input type="text" name="domain" required placeholder="panel.yourdomain.com" class="swipex-input px-3.5 py-2 text-xs">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-500 uppercase mb-1">Default Profit Margin (%)</label>
                        <input type="number" step="0.5" min="0" max="500" name="profit_margin" value="25" required class="swipex-input px-3.5 py-2 text-xs">
                    </div>
                    <div class="p-3 rounded-xl bg-blue-50 text-xs text-blue-900 border border-blue-100">
                        Setup Fee: <strong class="tabular-nums"><?= Currency::renderMoney($panelSetupFee, $displayCode, 2, true) ?></strong> (deducted from wallet if &gt; 0)
                    </div>
                    <button type="submit" class="swipex-btn-primary w-full py-2.5 rounded-full font-bold text-xs">
                        Create Child Panel
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Right: Selected Child Panel Isolated Workspace -->
    <div class="lg:col-span-8 min-w-0">
        <?php if (!$activePanel): ?>
            <div class="swipex-card rounded-3xl p-10 text-center text-sm text-slate-500">
                Select or create a Child Panel on the left to manage its domain verification, branding, services, users, orders, and API.
            </div>
        <?php else: ?>
            <div class="swipex-card rounded-3xl p-6 min-w-0" x-data="{ tab: 'overview' }">
                <div class="flex flex-wrap items-center justify-between gap-3 pb-4 border-b border-slate-100 mb-5">
                    <div class="flex items-center gap-3 min-w-0">
                        <?php if (!empty($activePanel['logo_path'])): ?>
                            <img src="<?= Validator::e($activePanel['logo_path']) ?>" class="w-10 h-10 rounded-xl object-cover shrink-0">
                        <?php endif; ?>
                        <div class="min-w-0">
                            <h2 class="text-lg font-extrabold text-slate-900 truncate"><?= Validator::e($activePanel['panel_name']) ?></h2>
                            <div class="text-xs text-slate-500 break-all">
                                Domain: <strong><?= Validator::e($activePanel['domain']) ?></strong>
                                &bull; <?= $activePanel['domain_verified'] ? '<span class="text-emerald-600 font-bold">Verified</span>' : '<span class="text-amber-600 font-bold">DNS Pending</span>' ?>
                            </div>
                        </div>
                    </div>
                    <?= Helpers::statusBadge((string)$activePanel['status']) ?>
                </div>

                <!-- Sub-navigation -->
                <div class="flex flex-wrap gap-1.5 bg-slate-100 p-1.5 rounded-2xl text-xs font-bold mb-6">
                    <?php foreach (['overview' => 'Overview & DNS', 'services' => 'Services & Pricing', 'users' => 'Panel Users', 'orders' => 'Panel Orders', 'settings' => 'Branding & Settings'] as $tk => $tl): ?>
                    <button type="button" @click="tab = '<?= $tk ?>'"
                            :class="tab === '<?= $tk ?>' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-600'"
                            class="px-3.5 py-2 rounded-xl transition"><?= $tl ?></button>
                    <?php endforeach; ?>
                </div>

                <!-- Overview & DNS Verification -->
                <div x-show="tab === 'overview'" class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100">
                            <div class="text-xs text-slate-500">Isolated Users</div>
                            <div class="text-2xl font-extrabold text-slate-900 tabular-nums"><?= (int)$panelStats['users'] ?></div>
                        </div>
                        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100">
                            <div class="text-xs text-slate-500">Panel Orders</div>
                            <div class="text-2xl font-extrabold text-slate-900 tabular-nums"><?= (int)$panelStats['orders'] ?></div>
                        </div>
                        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100">
                            <div class="text-xs text-slate-500">Panel Revenue</div>
                            <div class="text-2xl font-extrabold text-blue-600 tabular-nums"><?= Currency::renderMoney((string)$panelStats['revenue'], $displayCode, 2, true) ?></div>
                        </div>
                    </div>

                    <div class="p-5 rounded-2xl bg-blue-50/70 border border-blue-100 space-y-3 text-xs">
                        <div class="font-bold text-blue-900">Domain Verification &amp; Isolated Storefront</div>
                        <p class="text-slate-600">Point a CNAME or A record for <strong><?= Validator::e($activePanel['domain']) ?></strong> to this server and add TXT record: <code class="px-2 py-0.5 rounded bg-white border"><?= Validator::e($activePanel['verification_token']) ?></code></p>
                        <div class="flex flex-wrap gap-2">
                            <form method="POST" action="/user/child-panel.php">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="verify_domain">
                                <input type="hidden" name="panel_id" value="<?= (int)$activePanel['id'] ?>">
                                <button type="submit" class="px-4 py-2 rounded-full bg-blue-600 hover:bg-blue-700 text-white font-bold">Verify DNS Record</button>
                            </form>
                            <a href="/child-panel-site.php?panel_id=<?= (int)$activePanel['id'] ?>" target="_blank" class="px-4 py-2 rounded-full bg-white border border-slate-200 text-blue-600 font-bold">Preview Storefront &rarr;</a>
                        </div>
                        <div class="pt-2">
                            <div class="font-bold text-slate-700 mb-1">Child Panel API Endpoint &amp; Key</div>
                            <div class="font-mono bg-white p-2.5 rounded-xl border border-slate-200 text-[11px] break-all">
                                /api/child-api.php &bull; key=<?= Validator::e($activePanel['api_key']) ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Child Panel Services -->
                <div x-show="tab === 'services'" x-cloak class="space-y-5">
                    <form method="POST" action="/user/child-panel.php" class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end p-4 rounded-2xl bg-slate-50 border border-slate-100">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="add_service">
                        <input type="hidden" name="panel_id" value="<?= (int)$activePanel['id'] ?>">
                        <div class="sm:col-span-2">
                            <label class="block text-[11px] font-bold text-slate-500 uppercase mb-1">Parent Service</label>
                            <select name="parent_service_id" class="swipex-input px-3 py-2 text-xs">
                                <?php foreach ($allParentServices as $ps): ?>
                                    <option value="<?= (int)$ps['id'] ?>">#<?= (int)$ps['id'] ?> <?= Validator::e($ps['name']) ?> (Base: <?= Currency::displayRate((string)$ps['rate'], $displayCode, true) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-500 uppercase mb-1">Custom Rate / 1k (<span data-smm-curr-code><?= Validator::e($displayCode) ?></span>)</label>
                            <input type="number" step="0.0001" min="0.0001" name="custom_rate" required placeholder="Auto or custom" class="swipex-input px-3 py-2 text-xs tabular-nums">
                        </div>
                        <button type="submit" class="swipex-btn-primary py-2 px-4 rounded-full text-xs font-bold">Add / Update</button>
                    </form>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead><tr class="border-b border-slate-100 uppercase text-slate-400"><th class="py-2">Service</th><th class="py-2">Base Rate</th><th class="py-2">Your Rate</th><th class="py-2 text-right">Status</th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($panelServices as $cps): ?>
                                <tr>
                                    <td class="py-2.5 font-semibold text-slate-800"><?= Validator::e($cps['custom_name'] ?: $cps['parent_name']) ?></td>
                                    <td class="py-2.5 text-slate-500 tabular-nums"><?= Currency::renderRate((string)$cps['parent_rate'], $displayCode, true) ?></td>
                                    <td class="py-2.5 font-bold text-blue-600 tabular-nums"><?= Currency::renderRate((string)$cps['custom_rate'], $displayCode, true) ?></td>
                                    <td class="py-2.5 text-right"><?= Helpers::statusBadge((string)$cps['status']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($panelServices)): ?>
                                    <tr><td colspan="4" class="py-6 text-center text-slate-500">No services added to this child panel yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Child Panel Users -->
                <div x-show="tab === 'users'" x-cloak class="overflow-x-auto">
                    <?php if (empty($panelUsers)): ?>
                        <p class="text-xs text-slate-500 py-6 text-center">No users have registered on this child panel yet.</p>
                    <?php else: ?>
                        <table class="w-full text-left text-xs">
                            <thead><tr class="border-b border-slate-100 uppercase text-slate-400"><th class="py-2">Username</th><th class="py-2">Email</th><th class="py-2">Wallet</th><th class="py-2 text-right">Status</th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($panelUsers as $cu): ?>
                                <tr>
                                    <td class="py-2.5 font-bold"><?= Validator::e($cu['username']) ?></td>
                                    <td class="py-2.5 text-slate-600"><?= Validator::e($cu['email']) ?></td>
                                    <td class="py-2.5 font-bold text-blue-600 tabular-nums"><?= Currency::renderMoney((string)$cu['wallet_balance'], $displayCode, 2, true) ?></td>
                                    <td class="py-2.5 text-right"><?= Helpers::statusBadge((string)$cu['status']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Child Panel Orders -->
                <div x-show="tab === 'orders'" x-cloak class="overflow-x-auto">
                    <?php if (empty($panelOrders)): ?>
                        <p class="text-xs text-slate-500 py-6 text-center">No orders placed through this child panel yet.</p>
                    <?php else: ?>
                        <table class="w-full text-left text-xs">
                            <thead><tr class="border-b border-slate-100 uppercase text-slate-400"><th class="py-2">ID</th><th class="py-2">Service</th><th class="py-2">Qty</th><th class="py-2">Charge</th><th class="py-2 text-right">Status</th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($panelOrders as $co):
                                    $coCurr = strtoupper((string)($co['currency_code'] ?: $baseCode));
                                ?>
                                <tr>
                                    <td class="py-2.5 font-bold">#<?= (int)$co['id'] ?></td>
                                    <td class="py-2.5"><?= Validator::e($co['service_name']) ?></td>
                                    <td class="py-2.5"><?= number_format((int)$co['quantity']) ?></td>
                                    <td class="py-2.5 font-bold tabular-nums"><?= Currency::renderEquivalent((string)$co['charge'], $coCurr, 2, true, '', true) ?></td>
                                    <td class="py-2.5 text-right"><?= Helpers::statusBadge((string)$co['status']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Branding & Settings -->
                <div x-show="tab === 'settings'" x-cloak>
                    <form method="POST" action="/user/child-panel.php" enctype="multipart/form-data" class="space-y-4">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="update_branding">
                        <input type="hidden" name="panel_id" value="<?= (int)$activePanel['id'] ?>">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Panel Name</label>
                                <input type="text" name="panel_name" value="<?= Validator::e($activePanel['panel_name']) ?>" required class="swipex-input px-3.5 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Primary Accent Color</label>
                                <input type="color" name="primary_color" value="<?= Validator::e($activePanel['primary_color']) ?>" class="w-full h-10 rounded-xl border border-slate-200">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Profit Margin (%)</label>
                                <input type="number" step="0.5" name="profit_margin" value="<?= Validator::e((string)$activePanel['profit_margin']) ?>" class="swipex-input px-3.5 py-2.5 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Custom Logo</label>
                                <input type="file" name="logo" accept="image/*" class="w-full text-xs">
                            </div>
                        </div>
                        <button type="submit" class="swipex-btn-primary px-6 py-2.5 rounded-full font-bold text-xs">Save Branding &amp; Margin</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
