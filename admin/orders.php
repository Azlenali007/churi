<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('orders.view');
$pageTitle = 'Order Management';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    if (!Auth::adminHasPermission($admin, 'orders.edit')) {
        Helpers::setFlash('error', 'Permission denied.');
        Helpers::redirect('/admin/orders.php');
    }

    $action = $_POST['action'] ?? '';
    $oid = (int)($_POST['order_id'] ?? 0);

    if ($action === 'update_order' && $oid > 0) {
        $status = isset(ORDER_STATUSES[$_POST['status'] ?? '']) ? $_POST['status'] : 'pending';
        $start = max(0, (int)($_POST['start_count'] ?? 0));
        $remains = max(0, (int)($_POST['remains'] ?? 0));
        $order = Database::fetch("SELECT * FROM orders WHERE id = ? LIMIT 1", [$oid]);
        if ($order) {
            Database::execute("UPDATE orders SET status = ?, start_count = ?, remains = ? WHERE id = ?", [$status, $start, $remains, $oid]);
            if ($status === 'canceled') {
                OrderService::refundOrder($oid, (string)$order['charge'], 'Admin canceled order #' . $oid);
            } elseif ($status === 'partial' && $remains > 0 && (int)$order['quantity'] > 0) {
                $ratio = Currency::div((string)$remains, (string)$order['quantity'], 6);
                $refAmt = Currency::mul((string)$order['charge'], $ratio, 4);
                OrderService::refundOrder($oid, $refAmt, 'Admin partial refund (' . $remains . ' remains)');
            }
            Database::insert("INSERT INTO order_logs (order_id, actor_type, actor_id, action, details) VALUES (?, 'admin', ?, 'updated', ?)", [$oid, $admin['id'], "Status: $status, Start: $start, Remains: $remains"]);
            Helpers::setFlash('success', 'Order #' . $oid . ' updated.');
        }
    } elseif ($action === 'sync_order' && $oid > 0) {
        $res = OrderService::syncOrderStatus($oid);
        Helpers::setFlash($res['ok'] ? 'success' : 'error', $res['ok'] ? ('Synced: ' . $res['status']) : ($res['error'] ?? 'Sync failed'));
    } elseif ($action === 'sync_all_active') {
        $rows = Database::fetchAll("SELECT id FROM orders WHERE provider_order_id IS NOT NULL AND status IN ('pending','in_progress','processing') LIMIT 30");
        $count = 0;
        foreach ($rows as $r) {
            $sr = OrderService::syncOrderStatus((int)$r['id']);
            if ($sr['ok']) $count++;
        }
        Helpers::setFlash('success', "Synchronized {$count} active provider orders.");
    } elseif ($action === 'manual_order') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $sid = (int)($_POST['service_id'] ?? 0);
        $link = (string)($_POST['link'] ?? '');
        $qty = (int)($_POST['quantity'] ?? 0);
        $res = OrderService::placeOrder($uid, $sid, $link, $qty);
        Helpers::setFlash($res['ok'] ? 'success' : 'error', $res['ok'] ? ('Manual Order #' . $res['order_id'] . ' created.') : ($res['error'] ?? 'Failed'));
    } elseif ($action === 'bulk_status') {
        $ids = array_map('intval', $_POST['order_ids'] ?? []);
        $newStatus = isset(ORDER_STATUSES[$_POST['bulk_status'] ?? '']) ? $_POST['bulk_status'] : '';
        if (!empty($ids) && $newStatus !== '') {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            Database::execute("UPDATE orders SET status = ? WHERE id IN ($ph)", array_merge([$newStatus], $ids));
            Helpers::setFlash('success', count($ids) . ' orders updated to ' . $newStatus . '.');
        }
    }

    Helpers::redirect('/admin/orders.php');
}

$statusFilter = Validator::str($_GET['status'] ?? 'all', 30);
$search = Validator::str($_GET['search'] ?? '', 120);
$sql = "SELECT o.*, u.username, s.name AS service_name, s.platform, p.name AS provider_name
        FROM orders o
        JOIN users u ON u.id = o.user_id
        JOIN services s ON s.id = o.service_id
        LEFT JOIN providers p ON p.id = o.provider_id
        WHERE 1=1";
$params = [];
if ($statusFilter !== 'all' && isset(ORDER_STATUSES[$statusFilter])) {
    $sql .= " AND o.status = ?";
    $params[] = $statusFilter;
}
if ($search !== '') {
    $sql .= " AND (o.id = ? OR o.link LIKE ? OR u.username LIKE ? OR o.provider_order_id = ?)";
    $params[] = ctype_digit($search) ? (int)$search : 0;
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = $search;
}
$sql .= " ORDER BY o.id DESC LIMIT 100";
$orders = Database::fetchAll($sql, $params);

$allUsers = Database::fetchAll("SELECT id, username FROM users WHERE status = 'active' ORDER BY id DESC LIMIT 100");
$allServices = Database::fetchAll("SELECT id, name, rate FROM services WHERE status = 'active' ORDER BY id ASC");

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="smm-card rounded-3xl p-6" x-data="{ editOrder: null, showManual: false }">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <form method="GET" class="flex flex-wrap gap-2">
            <input type="hidden" name="status" value="<?= Validator::e($statusFilter) ?>">
            <input type="text" name="search" value="<?= Validator::e($search) ?>" placeholder="ID, user, link, provider ID..." class="px-4 py-2 rounded-xl border border-purple-200 text-xs w-60">
            <button type="submit" class="px-4 py-2 rounded-xl bg-purple-600 text-white text-xs font-bold">Filter</button>
        </form>
        <div class="flex flex-wrap gap-2">
            <form method="POST">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="sync_all_active">
                <button type="submit" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">Sync Provider Orders</button>
            </form>
            <button type="button" @click="showManual = !showManual" class="px-4 py-2 rounded-xl bg-purple-600 text-white text-xs font-bold">+ Manual Order</button>
        </div>
    </div>

    <!-- Manual Order Drawer -->
    <form x-show="showManual" x-cloak method="POST" class="mb-6 p-4 rounded-2xl bg-[#F6F4FC] grid grid-cols-1 sm:grid-cols-5 gap-3 items-end text-xs">
        <?= CSRF::field() ?>
        <input type="hidden" name="action" value="manual_order">
        <select name="user_id" required class="px-3 py-2 rounded-xl border border-purple-200">
            <?php foreach ($allUsers as $au): ?><option value="<?= (int)$au['id'] ?>">@<?= Validator::e($au['username']) ?></option><?php endforeach; ?>
        </select>
        <select name="service_id" required class="px-3 py-2 rounded-xl border border-purple-200">
            <?php foreach ($allServices as $as): ?><option value="<?= (int)$as['id'] ?>">#<?= (int)$as['id'] ?> <?= Validator::e($as['name']) ?></option><?php endforeach; ?>
        </select>
        <input type="text" name="link" required placeholder="Target Link" class="px-3 py-2 rounded-xl border border-purple-200">
        <input type="number" name="quantity" required min="1" placeholder="Quantity" class="px-3 py-2 rounded-xl border border-purple-200">
        <button type="submit" class="py-2 px-4 rounded-xl bg-purple-600 text-white font-bold">Place Order</button>
    </form>

    <!-- Status Tabs -->
    <div class="flex flex-wrap gap-1.5 mb-5">
        <a href="/admin/orders.php?status=all" class="px-3 py-1.5 rounded-xl text-xs font-bold <?= $statusFilter === 'all' ? 'bg-purple-600 text-white' : 'bg-[#F3F1FC] text-slate-600' ?>">All</a>
        <?php foreach (ORDER_STATUSES as $sk => $sl): ?>
            <a href="/admin/orders.php?status=<?= urlencode($sk) ?>" class="px-3 py-1.5 rounded-xl text-xs font-bold <?= $statusFilter === $sk ? 'bg-purple-600 text-white' : 'bg-[#F3F1FC] text-slate-600' ?>"><?= Validator::e($sl) ?></a>
        <?php endforeach; ?>
    </div>

    <!-- Bulk Update + Orders Table -->
    <form method="POST">
        <?= CSRF::field() ?>
        <input type="hidden" name="action" value="bulk_status">
        <div class="flex items-center gap-2 mb-3 text-xs">
            <select name="bulk_status" class="px-3 py-1.5 rounded-xl border border-purple-200">
                <option value="">Bulk Action...</option>
                <?php foreach (ORDER_STATUSES as $sk => $sl): ?><option value="<?= $sk ?>">Mark <?= Validator::e($sl) ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="px-3 py-1.5 rounded-xl bg-slate-800 text-white font-bold">Apply Selected</button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="border-b border-purple-100 uppercase text-slate-400">
                        <th class="py-2.5 pr-2"></th>
                        <th class="py-2.5 pr-3">ID</th>
                        <th class="py-2.5 pr-3">User</th>
                        <th class="py-2.5 pr-3">Service / Provider</th>
                        <th class="py-2.5 pr-3">Link / Qty</th>
                        <th class="py-2.5 pr-3">Charge / Profit</th>
                        <th class="py-2.5 pr-3">Status</th>
                        <th class="py-2.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($orders as $o): ?>
                    <tr class="hover:bg-purple-50/40">
                        <td class="py-3 pr-2"><input type="checkbox" name="order_ids[]" value="<?= (int)$o['id'] ?>" class="rounded text-purple-600"></td>
                        <td class="py-3 pr-3 font-bold">#<?= (int)$o['id'] ?></td>
                        <td class="py-3 pr-3 font-semibold">@<?= Validator::e($o['username']) ?></td>
                        <td class="py-3 pr-3">
                            <div class="font-semibold text-slate-900 max-w-[200px] truncate"><?= Validator::e($o['service_name']) ?></div>
                            <div class="text-[11px] text-slate-400">
                                <?= $o['provider_name'] ? Validator::e($o['provider_name']) . ' #' . Validator::e($o['provider_order_id']) : 'Manual / Local' ?>
                            </div>
                        </td>
                        <td class="py-3 pr-3">
                            <div class="max-w-[160px] truncate text-purple-700"><?= Validator::e($o['link']) ?></div>
                            <div class="text-[11px] text-slate-500">Qty: <?= (int)$o['quantity'] ?> | Rem: <?= (int)$o['remains'] ?></div>
                        </td>
                        <td class="py-3 pr-3">
                            <div class="font-bold text-slate-900"><?= Currency::format((string)$o['charge']) ?></div>
                            <div class="text-[11px] text-emerald-600">Profit: <?= Currency::format((string)$o['profit']) ?></div>
                        </td>
                        <td class="py-3 pr-3"><?= Helpers::statusBadge((string)$o['status']) ?></td>
                        <td class="py-3 text-right whitespace-nowrap">
                            <button type="button" @click="editOrder = <?= Validator::e(json_encode($o)) ?>" class="px-2.5 py-1 rounded-lg bg-purple-100 text-purple-800 font-bold">Edit</button>
                            <?php if ($o['provider_order_id']): ?>
                            <button type="submit" form="sync_<?= (int)$o['id'] ?>" class="px-2.5 py-1 rounded-lg bg-indigo-100 text-indigo-800 font-bold">Sync</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($orders)): ?>
                        <tr><td colspan="8" class="py-8 text-center text-slate-500">No orders found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>

    <?php foreach ($orders as $o): if ($o['provider_order_id']): ?>
    <form id="sync_<?= (int)$o['id'] ?>" method="POST" class="hidden">
        <?= CSRF::field() ?>
        <input type="hidden" name="action" value="sync_order">
        <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
    </form>
    <?php endif; endforeach; ?>

    <!-- Edit Order Modal -->
    <div x-show="editOrder" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm">
        <div @click.away="editOrder = null" class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-purple-100">
            <h3 class="text-base font-extrabold text-slate-900 mb-4">Edit Order #<span x-text="editOrder?.id"></span></h3>
            <form method="POST" class="space-y-3 text-xs">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="update_order">
                <input type="hidden" name="order_id" :value="editOrder?.id">
                <div>
                    <label class="block font-bold uppercase text-slate-500 mb-1">Status</label>
                    <select name="status" :value="editOrder?.status" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                        <?php foreach (ORDER_STATUSES as $sk => $sl): ?><option value="<?= $sk ?>"><?= Validator::e($sl) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold uppercase text-slate-500 mb-1">Start Count</label>
                        <input type="number" name="start_count" :value="editOrder?.start_count" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                    </div>
                    <div>
                        <label class="block font-bold uppercase text-slate-500 mb-1">Remains</label>
                        <input type="number" name="remains" :value="editOrder?.remains" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="editOrder = null" class="px-4 py-2 rounded-xl bg-slate-100 font-bold">Cancel</button>
                    <button type="submit" class="px-4 py-2 rounded-xl bg-purple-600 text-white font-bold">Update Order</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
