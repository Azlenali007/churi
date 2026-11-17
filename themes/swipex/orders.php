<?php
$pageTitle = 'Orders';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/sidebar.php';
require __DIR__ . '/partials/header.php';

$statusFilter = Validator::str($_GET['status'] ?? 'all', 30);
$search = Validator::str($_GET['search'] ?? '', 120);

$sql = "SELECT o.*, s.name AS service_name, s.platform, s.refill_support, s.cancel_support
        FROM orders o
        JOIN services s ON s.id = o.service_id
        WHERE o.user_id = ?";
$params = [$user['id']];

if ($statusFilter !== 'all' && isset(ORDER_STATUSES[$statusFilter])) {
    $sql .= " AND o.status = ?";
    $params[] = $statusFilter;
}
if ($search !== '') {
    $sql .= " AND (o.id = ? OR o.link LIKE ? OR s.name LIKE ?)";
    $params[] = ctype_digit($search) ? (int)$search : 0;
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$sql .= " ORDER BY o.id DESC LIMIT 100";
$orders = Database::fetchAll($sql, $params);
$displayCurr = Currency::getActiveDisplayCurrency($user);
$displayCode = $displayCurr['code'];
$baseCode = Currency::getBaseCode();
?>

<div class="swipex-card rounded-3xl p-5 sm:p-6 min-w-0 max-w-full overflow-hidden" x-data="{ detailOrder: null }">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 mb-6 min-w-0">
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold text-slate-900">Order History</h1>
            <p class="text-sm text-slate-500">Track status, start count, remaining quantity, and refill/cancel actions.</p>
        </div>
        <form method="GET" class="flex flex-wrap gap-2 w-full lg:w-auto">
            <input type="hidden" name="status" value="<?= Validator::e($statusFilter) ?>">
            <input type="text" name="search" value="<?= Validator::e($search) ?>" placeholder="Order ID, link, or service..."
                   class="px-4 py-2 rounded-full border border-slate-200 text-sm outline-none focus:border-blue-600 flex-1 sm:w-60 min-w-0 bg-white">
            <button type="submit" class="px-5 py-2 rounded-full bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold shrink-0">Search</button>
        </form>
    </div>

    <!-- Status Filter Pills -->
    <div class="flex flex-wrap gap-2 mb-6">
        <a href="/user/orders.php?status=all" class="px-3.5 py-1.5 rounded-full text-xs font-bold transition <?= $statusFilter === 'all' ? 'bg-blue-600 text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-blue-50' ?>">All</a>
        <?php foreach (ORDER_STATUSES as $key => $label): ?>
            <a href="/user/orders.php?status=<?= urlencode($key) ?>" class="px-3.5 py-1.5 rounded-full text-xs font-bold transition <?= $statusFilter === $key ? 'bg-blue-600 text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-blue-50' ?>"><?= Validator::e($label) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($orders)): ?>
        <div class="text-center py-12 text-sm text-slate-500">No orders match your filter.</div>
    <?php else: ?>
        <div class="w-full max-w-full overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-xs uppercase text-slate-400">
                        <th class="py-3 pr-3">ID</th>
                        <th class="py-3 pr-3">Date</th>
                        <th class="py-3 pr-3">Link</th>
                        <th class="py-3 pr-3">Charge</th>
                        <th class="py-3 pr-3">Start / Qty / Remains</th>
                        <th class="py-3 pr-3">Service</th>
                        <th class="py-3 pr-3">Status</th>
                        <th class="py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($orders as $ord):
                        $oCurr = (string)($ord['currency_code'] ?? $baseCode);
                        if ($oCurr === '') $oCurr = $baseCode;
                        $chargeFormatted = Currency::formatRaw((string)$ord['charge'], $oCurr, 2, true);
                        $unitFormatted = Currency::formatRaw((string)$ord['unit_price'], $oCurr, 2, true);
                        $chargeDisplayEq = ($displayCode !== $oCurr)
                            ? Currency::display(Currency::convert((string)$ord['charge'], $oCurr, $baseCode, 4), $displayCode, 2, true)
                            : null;
                        $unitDisplayEq = ($displayCode !== $oCurr)
                            ? Currency::display(Currency::convert((string)$ord['unit_price'], $oCurr, $baseCode, 4), $displayCode, 2, true)
                            : null;
                        $ord['charge_formatted'] = $chargeDisplayEq ? ($chargeDisplayEq . ' (Orig: ' . $chargeFormatted . ')') : $chargeFormatted;
                        $ord['unit_formatted'] = $unitDisplayEq ? ($unitDisplayEq . ' (Orig: ' . $unitFormatted . ')') : $unitFormatted;
                    ?>
                    <tr class="hover:bg-blue-50/40">
                        <td class="py-3.5 pr-3 font-bold text-slate-800 whitespace-nowrap">#<?= (int)$ord['id'] ?></td>
                        <td class="py-3.5 pr-3 text-xs text-slate-500 whitespace-nowrap"><?= date('M j, H:i', strtotime($ord['created_at'])) ?></td>
                        <td class="py-3.5 pr-3 max-w-[160px] truncate text-xs text-blue-600 font-medium">
                            <?= Validator::e($ord['link']) ?>
                        </td>
                        <td class="py-3.5 pr-3 font-bold text-slate-900 tabular-nums whitespace-nowrap">
                            <?php if ($chargeDisplayEq): ?>
                                <div><?= Validator::e($chargeDisplayEq) ?></div>
                                <div class="text-[10px] font-semibold text-slate-400">Orig: <?= Validator::e($chargeFormatted) ?></div>
                            <?php else: ?>
                                <div><?= Validator::e($chargeFormatted) ?></div>
                            <?php endif; ?>
                            <?php if (Currency::cmp((string)$ord['refunded_amount'], '0', 4) > 0):
                                $refDisplay = ($displayCode !== $oCurr)
                                    ? Currency::display(Currency::convert((string)$ord['refunded_amount'], $oCurr, $baseCode, 4), $displayCode, 2, true)
                                    : Currency::formatRaw((string)$ord['refunded_amount'], $oCurr, 2, true);
                            ?>
                                <div class="text-[10px] text-emerald-600">Refunded: <?= Validator::e($refDisplay) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="py-3.5 pr-3 text-xs text-slate-600 whitespace-nowrap">
                            <?= number_format((int)$ord['start_count']) ?> / <strong><?= number_format((int)$ord['quantity']) ?></strong> / <?= number_format((int)$ord['remains']) ?>
                        </td>
                        <td class="py-3.5 pr-3">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="shrink-0"><?= Helpers::platformIcon((string)$ord['platform'], 'w-6 h-6') ?></span>
                                <span class="text-xs font-semibold text-slate-800 max-w-[180px] truncate"><?= Validator::e($ord['service_name']) ?></span>
                            </div>
                        </td>
                        <td class="py-3.5 pr-3"><?= Helpers::statusBadge((string)$ord['status']) ?></td>
                        <td class="py-3.5 text-right whitespace-nowrap">
                            <div class="inline-flex items-center gap-1.5">
                                <button type="button" @click="detailOrder = <?= Validator::e(json_encode($ord)) ?>"
                                        class="px-2.5 py-1 rounded-full bg-slate-100 hover:bg-slate-200 text-xs font-bold text-slate-700">Details</button>
                                <?php if ($ord['refill_support'] && $ord['status'] === 'completed' && $ord['refill_status'] === 'none'): ?>
                                    <form method="POST" action="/user/orders.php" class="inline">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="refill">
                                        <input type="hidden" name="order_id" value="<?= (int)$ord['id'] ?>">
                                        <button type="submit" class="px-2.5 py-1 rounded-full bg-emerald-100 hover:bg-emerald-200 text-xs font-bold text-emerald-800">Refill</button>
                                    </form>
                                <?php elseif ($ord['refill_status'] !== 'none'): ?>
                                    <span class="px-2 py-1 rounded-full bg-emerald-50 text-emerald-700 text-[10px] font-bold">Refill: <?= Validator::e($ord['refill_status']) ?></span>
                                <?php endif; ?>
                                <?php if ($ord['cancel_support'] && in_array($ord['status'], ['pending','in_progress','processing'], true) && $ord['cancel_status'] === 'none'): ?>
                                    <form method="POST" action="/user/orders.php" class="inline">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="order_id" value="<?= (int)$ord['id'] ?>">
                                        <button type="submit" class="px-2.5 py-1 rounded-full bg-rose-100 hover:bg-rose-200 text-xs font-bold text-rose-800">Cancel</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- Order Details Modal -->
    <div x-show="detailOrder" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm">
        <div @click.away="detailOrder = null" class="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-200">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-extrabold text-slate-900">Order #<span x-text="detailOrder?.id"></span> Details</h3>
                <button type="button" @click="detailOrder = null" class="text-slate-400 hover:text-slate-700 font-bold text-lg">&times;</button>
            </div>
            <div class="space-y-2.5 text-xs">
                <div class="p-3 rounded-xl bg-slate-50"><strong>Service:</strong> <span x-text="detailOrder?.service_name"></span></div>
                <div class="p-3 rounded-xl bg-slate-50 break-all"><strong>Link:</strong> <span x-text="detailOrder?.link"></span></div>
                <div class="grid grid-cols-3 gap-2">
                    <div class="p-3 rounded-xl bg-slate-50"><strong>Quantity</strong><div x-text="detailOrder?.quantity"></div></div>
                    <div class="p-3 rounded-xl bg-slate-50"><strong>Start Count</strong><div x-text="detailOrder?.start_count"></div></div>
                    <div class="p-3 rounded-xl bg-slate-50"><strong>Remains</strong><div x-text="detailOrder?.remains"></div></div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div class="p-3 rounded-xl bg-slate-50"><strong>Unit Rate / 1k</strong><div class="font-bold text-slate-900 tabular-nums" x-text="detailOrder?.unit_formatted || detailOrder?.unit_price"></div></div>
                    <div class="p-3 rounded-xl bg-slate-50"><strong>Total Charge</strong><div class="font-bold text-blue-600 tabular-nums" x-text="detailOrder?.charge_formatted || detailOrder?.charge"></div></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
