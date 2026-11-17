<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Services & Pricing — ' . Helpers::setting('site_name', 'SMM PRO');
$search = Validator::str($_GET['search'] ?? '', 120);
$categoryId = (int)($_GET['category_id'] ?? 0);
$platformFilter = Validator::str($_GET['platform'] ?? '', 40);

$categories = Database::fetchAll("SELECT * FROM categories WHERE status = 'active' ORDER BY sort_order ASC, id ASC");

$sql = "SELECT s.*, c.name AS category_name, c.platform AS cat_platform
        FROM services s
        JOIN categories c ON c.id = s.category_id
        WHERE s.status = 'active' AND c.status = 'active'";
$params = [];

if ($categoryId > 0) {
    $sql .= " AND s.category_id = ?";
    $params[] = $categoryId;
}
if ($platformFilter !== '') {
    $sql .= " AND LOWER(s.platform) = LOWER(?)";
    $params[] = $platformFilter;
}
if ($search !== '') {
    $sql .= " AND (s.name LIKE ? OR s.id = ? OR c.name LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = ctype_digit($search) ? (int)$search : 0;
    $params[] = '%' . $search . '%';
}
$sql .= " ORDER BY c.sort_order ASC, s.sort_order ASC, s.id ASC";
$services = Database::fetchAll($sql, $params);

$grouped = [];
foreach ($services as $svc) {
    $grouped[$svc['category_name']]['platform'] = $svc['cat_platform'];
    $grouped[$svc['category_name']]['items'][] = $svc;
}

require __DIR__ . '/includes/public_header.php';
?>
<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <?php require __DIR__ . '/includes/flash_sale_banner.php'; ?>

    <div class="smm-card rounded-3xl p-6 sm:p-8">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-extrabold text-slate-900">All Services & Pricing</h1>
                <p class="text-sm text-slate-500 mt-1">Real-time catalog rates per 1,000 units.</p>
            </div>
            <form method="GET" class="flex flex-wrap gap-3">
                <input type="text" name="search" value="<?= Validator::e($search) ?>" placeholder="Search service name or ID..."
                       class="px-4 py-2.5 rounded-xl border border-purple-200 text-sm focus:border-purple-500 outline-none w-56">
                <select name="category_id" class="px-4 py-2.5 rounded-xl border border-purple-200 text-sm focus:border-purple-500 outline-none">
                    <option value="0">All Categories</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $categoryId === (int)$c['id'] ? 'selected' : '' ?>><?= Validator::e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-sm font-bold">Filter</button>
            </form>
        </div>

        <?php if (empty($grouped)): ?>
            <div class="text-center py-12 text-slate-500 text-sm">No matching active services found.</div>
        <?php else: ?>
            <div class="space-y-8">
                <?php foreach ($grouped as $catName => $bundle): ?>
                <div>
                    <div class="flex items-center gap-3 px-4 py-3 rounded-2xl bg-[#1B1145] text-white mb-3">
                        <?= Helpers::platformIcon((string)$bundle['platform'], 'w-7 h-7') ?>
                        <h2 class="font-bold text-base"><?= Validator::e($catName) ?></h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="border-b border-purple-100 text-xs uppercase text-slate-500">
                                    <th class="py-2.5 px-3">ID</th>
                                    <th class="py-2.5 px-3">Service</th>
                                    <th class="py-2.5 px-3">Rate / 1k</th>
                                    <th class="py-2.5 px-3">Min / Max</th>
                                    <th class="py-2.5 px-3">Features</th>
                                    <th class="py-2.5 px-3 text-right">Order</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($bundle['items'] as $s): $p = FlashSale::getServicePricing($s); ?>
                                <tr class="hover:bg-purple-50/40">
                                    <td class="py-3 px-3 font-bold text-slate-500">#<?= (int)$s['id'] ?></td>
                                    <td class="py-3 px-3">
                                        <div class="font-semibold text-slate-900"><?= Validator::e($s['name']) ?></div>
                                        <?php if (!empty($s['avg_time'])): ?>
                                            <div class="text-xs text-slate-400">Avg. Time: <?= Validator::e($s['avg_time']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-3 font-bold">
                                        <?php if ($p['on_sale']): ?>
                                            <span class="line-through text-slate-400 text-xs"><?= Currency::format($p['original_rate']) ?></span>
                                            <span class="text-rose-600 ml-1"><?= Currency::format($p['effective_rate']) ?></span>
                                            <span class="ml-1 px-2 py-0.5 rounded-full text-[10px] bg-rose-100 text-rose-700"><?= Validator::e($p['discount_label']) ?></span>
                                        <?php else: ?>
                                            <span class="text-purple-700"><?= Currency::format($p['effective_rate']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-3 text-xs text-slate-600"><?= number_format((int)$s['min_qty']) ?> / <?= number_format((int)$s['max_qty']) ?></td>
                                    <td class="py-3 px-3">
                                        <div class="flex flex-wrap gap-1">
                                            <?php if ($s['refill_support']): ?><span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700">Refill</span><?php endif; ?>
                                            <?php if ($s['cancel_support']): ?><span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700">Cancel</span><?php endif; ?>
                                            <?php if ($s['drip_feed_support']): ?><span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-50 text-indigo-700">Drip</span><?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="py-3 px-3 text-right">
                                        <a href="<?= Auth::user() ? '/user/new-order.php?service_id=' . (int)$s['id'] : '/register.php' ?>"
                                           class="inline-flex px-3.5 py-1.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-xs font-bold">Order Now</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/includes/public_footer.php'; ?>
