<?php
$pageTitle = 'Services';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/sidebar.php';
require __DIR__ . '/partials/header.php';

$search = Validator::str($_GET['search'] ?? '', 120);
$categoryId = (int)($_GET['category_id'] ?? 0);
$platform = Validator::str($_GET['platform'] ?? '', 40);

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
if ($platform !== '') {
    $sql .= " AND LOWER(s.platform) = LOWER(?)";
    $params[] = $platform;
}
if ($search !== '') {
    $sql .= " AND (s.name LIKE ? OR s.id = ? OR c.name LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = ctype_digit($search) ? (int)$search : 0;
    $params[] = '%' . $search . '%';
}
$sql .= " ORDER BY c.sort_order ASC, s.sort_order ASC, s.id ASC";
$services = Database::fetchAll($sql, $params);

$activeCurr = Currency::getActiveDisplayCurrency($user);
$grouped = [];
foreach ($services as $svc) {
    $grouped[$svc['category_name']]['platform'] = $svc['cat_platform'];
    $grouped[$svc['category_name']]['items'][] = $svc;
}
?>

<div class="smm-card rounded-3xl p-5 sm:p-6" x-data="{ descModal: null }">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 mb-6">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-extrabold text-slate-900">Service Directory</h1>
                <span class="px-2.5 py-0.5 rounded-full bg-purple-100 text-purple-800 text-[11px] font-extrabold">
                    Rates in <?= Validator::e($activeCurr['symbol'] . ' ' . $activeCurr['code']) ?>
                </span>
            </div>
            <p class="text-sm text-slate-500">All available SMM services with live rates per 1,000, limits, and refill/cancel support.</p>
        </div>
        <form method="GET" action="/user/services.php" class="flex flex-wrap gap-2">
            <input type="text" name="search" value="<?= Validator::e($search) ?>" placeholder="Search ID or name..."
                   class="px-4 py-2 rounded-xl border border-purple-200 text-sm outline-none focus:border-purple-600 w-full sm:w-52 bg-white">
            <select name="category_id" class="px-4 py-2 rounded-xl border border-purple-200 text-sm outline-none focus:border-purple-600 bg-white">
                <option value="0">All Categories</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $categoryId === (int)$c['id'] ? 'selected' : '' ?>><?= Validator::e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="px-4 py-2 rounded-xl bg-gradient-to-r from-purple-600 to-violet-600 text-white text-sm font-bold shadow-sm">Filter</button>
        </form>
    </div>

    <?php if (empty($grouped)): ?>
        <div class="text-center py-12 text-sm text-slate-500">No active services matched your search.</div>
    <?php else: ?>
        <div class="space-y-7">
            <?php foreach ($grouped as $catName => $bundle): ?>
            <div>
                <div class="flex items-center gap-3 px-4 py-3 rounded-2xl bg-[#1B1145] text-white mb-3">
                    <?= Helpers::platformIcon((string)$bundle['platform'], 'w-7 h-7') ?>
                    <h2 class="font-bold text-sm sm:text-base"><?= Validator::e($catName) ?></h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-purple-100 text-xs uppercase text-slate-400">
                                <th class="py-2.5 px-3">ID</th>
                                <th class="py-2.5 px-3">Service</th>
                                <th class="py-2.5 px-3">Rate / 1k (<?= Validator::e($activeCurr['code']) ?>)</th>
                                <th class="py-2.5 px-3">Min / Max</th>
                                <th class="py-2.5 px-3">Support</th>
                                <th class="py-2.5 px-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($bundle['items'] as $s): $p = FlashSale::getServicePricing($s); ?>
                            <tr class="hover:bg-purple-50/40">
                                <td class="py-3 px-3 font-bold text-slate-500">#<?= (int)$s['id'] ?></td>
                                <td class="py-3 px-3">
                                    <div class="flex items-center gap-2.5">
                                        <?= Helpers::platformIcon((string)$s['platform'], 'w-6 h-6') ?>
                                        <div>
                                            <div class="font-semibold text-slate-900"><?= Validator::e($s['name']) ?></div>
                                            <?php if ($s['avg_time']): ?><div class="text-[11px] text-slate-400">Avg: <?= Validator::e($s['avg_time']) ?></div><?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 px-3 font-bold tabular-nums whitespace-nowrap">
                                    <?php if ($p['on_sale']): ?>
                                        <span class="line-through text-xs text-slate-400 mr-1"><?= Currency::displayRate($p['original_rate'], null, true) ?></span>
                                        <span class="text-rose-600"><?= Currency::displayRate($p['effective_rate'], null, true) ?></span>
                                    <?php else: ?>
                                        <span class="text-purple-700"><?= Currency::displayRate($p['effective_rate'], null, true) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-3 text-xs text-slate-600"><?= number_format((int)$s['min_qty']) ?> - <?= number_format((int)$s['max_qty']) ?></td>
                                <td class="py-3 px-3">
                                    <div class="flex flex-wrap gap-1">
                                        <?php if ($s['refill_support']): ?><span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700">Refill</span><?php endif; ?>
                                        <?php if ($s['cancel_support']): ?><span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700">Cancel</span><?php endif; ?>
                                    </div>
                                </td>
                                <td class="py-3 px-3 text-right whitespace-nowrap">
                                    <button type="button" @click="descModal = <?= Validator::e(json_encode(['id' => $s['id'], 'name' => $s['name'], 'desc' => $s['description'] ?: 'No special instructions.'])) ?>"
                                            class="px-2.5 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-xs font-bold text-slate-700 mr-1">Info</button>
                                    <a href="/user/new-order.php?service_id=<?= (int)$s['id'] ?>"
                                       class="inline-flex px-3.5 py-1.5 rounded-lg bg-purple-600 hover:bg-purple-700 text-white text-xs font-bold">Order</a>
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

    <!-- Description Modal -->
    <div x-show="descModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm">
        <div @click.away="descModal = null" class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-purple-100">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-extrabold text-slate-900 text-base" x-text="`#${descModal?.id} ${descModal?.name}`"></h3>
                <button type="button" @click="descModal = null" class="text-slate-400 hover:text-slate-700 font-bold">&times;</button>
            </div>
            <p class="text-xs text-slate-600 leading-relaxed whitespace-pre-line" x-text="descModal?.desc"></p>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
