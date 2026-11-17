<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('services.manage');
$pageTitle = 'Service Management';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_service') {
        $id = (int)($_POST['id'] ?? 0);
        $catId = (int)($_POST['category_id'] ?? 1);
        $subId = !empty($_POST['subcategory_id']) ? (int)$_POST['subcategory_id'] : null;
        $name = Validator::str($_POST['name'] ?? '', 255);
        $desc = Validator::str($_POST['description'] ?? '', 5000);
        $platform = Validator::str($_POST['platform'] ?? 'general', 60);
        $type = in_array($_POST['type'] ?? '', ['default','custom_comments','package','drip_feed','subscriptions'], true) ? $_POST['type'] : 'default';
        $provRate = Currency::normalize((string)($_POST['provider_rate'] ?? '0'), 4);
        $provCurr = strtoupper(Validator::str($_POST['provider_currency'] ?? Currency::getBaseCode(), 10));
        $rate = Currency::normalize((string)($_POST['rate'] ?? '0'), 4);
        $minQty = max(1, (int)($_POST['min_qty'] ?? 10));
        $maxQty = max($minQty, (int)($_POST['max_qty'] ?? 10000));
        $avgTime = Validator::str($_POST['avg_time'] ?? '', 80);
        $sort = (int)($_POST['sort_order'] ?? 0);
        $refill = !empty($_POST['refill_support']) ? 1 : 0;
        $cancel = !empty($_POST['cancel_support']) ? 1 : 0;
        $drip = !empty($_POST['drip_feed_support']) ? 1 : 0;
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if ($id > 0) {
            Database::execute(
                "UPDATE services SET category_id=?, subcategory_id=?, name=?, description=?, platform=?, icon_key=?, type=?, provider_rate=?, provider_currency=?, rate=?, min_qty=?, max_qty=?, avg_time=?, sort_order=?, refill_support=?, cancel_support=?, drip_feed_support=?, status=? WHERE id=?",
                [$catId, $subId, $name, $desc, $platform, $platform, $type, $provRate, $provCurr, $rate, $minQty, $maxQty, $avgTime, $sort, $refill, $cancel, $drip, $status, $id]
            );
            Helpers::setFlash('success', 'Service #' . $id . ' updated.');
        } else {
            Database::insert(
                "INSERT INTO services (category_id, subcategory_id, name, description, platform, icon_key, type, provider_rate, provider_currency, rate, min_qty, max_qty, avg_time, sort_order, refill_support, cancel_support, drip_feed_support, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$catId, $subId, $name, $desc, $platform, $platform, $type, $provRate, $provCurr, $rate, $minQty, $maxQty, $avgTime, $sort, $refill, $cancel, $drip, $status]
            );
            Helpers::setFlash('success', 'Service created.');
        }
    } elseif ($action === 'delete_service') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            Database::execute("DELETE FROM services WHERE id = ?", [$id]);
            Helpers::setFlash('success', 'Service deleted.');
        } catch (Throwable $e) {
            Database::execute("UPDATE services SET status = 'inactive' WHERE id = ?", [$id]);
            Helpers::setFlash('error', 'Service has historical orders and was marked inactive instead of deleted.');
        }
    } elseif ($action === 'bulk_action') {
        $ids = array_map('intval', $_POST['service_ids'] ?? []);
        $bulkOp = (string)($_POST['bulk_op'] ?? '');
        if (empty($ids)) {
            Helpers::setFlash('error', 'Please select at least one service to run a bulk operation.');
        } elseif ($bulkOp === '') {
            Helpers::setFlash('error', 'Please choose a bulk operation first.');
        } else {
            $res = CategoryMapper::bulkOperateLocalServices($bulkOp, $ids, [
                'category_id'    => (int)($_POST['bulk_category_id'] ?? 0),
                'margin_percent' => trim((string)($_POST['bulk_margin_percent'] ?? '')),
                'price_mode'     => (string)($_POST['bulk_price_mode'] ?? 'fixed'),
                'price_value'    => trim((string)($_POST['bulk_price_value'] ?? '')),
                'status'         => (string)($_POST['bulk_status'] ?? 'active'),
            ], null, (int)$admin['id']);
            if ($res['ok']) {
                $parts = [];
                if (!empty($res['updated'])) $parts[] = $res['updated'] . ' updated';
                if (!empty($res['deleted'])) $parts[] = $res['deleted'] . ' deleted';
                if (!empty($res['skipped'])) $parts[] = $res['skipped'] . ' skipped/disabled';
                Helpers::setFlash('success', 'Bulk operation complete: ' . ($parts ? implode(', ', $parts) : 'done') . '.');
            } else {
                Helpers::setFlash('error', $res['error'] ?? 'Bulk operation failed.');
            }
        }
    }

    Helpers::redirect('/admin/services.php');
}

$categories = Database::fetchAll("SELECT * FROM categories ORDER BY sort_order ASC, id ASC");
$providers = Database::fetchAll("SELECT id, name FROM providers ORDER BY id DESC");
$catFilter = (int)($_GET['category_id'] ?? 0);
$provFilter = (int)($_GET['provider_id'] ?? 0);
$searchFilter = Validator::str($_GET['search'] ?? '', 120);

$sql = "SELECT s.*, c.name AS category_name, p.name AS provider_name, p.currency_code AS prov_currency
        FROM services s
        JOIN categories c ON c.id = s.category_id
        LEFT JOIN providers p ON p.id = s.provider_id
        WHERE 1=1";
$params = [];
if ($catFilter > 0) {
    $sql .= " AND s.category_id = ?";
    $params[] = $catFilter;
}
if ($provFilter > 0) {
    $sql .= " AND s.provider_id = ?";
    $params[] = $provFilter;
}
if ($searchFilter !== '') {
    $sql .= " AND (s.name LIKE ? OR s.id = ? OR s.provider_service_id LIKE ?)";
    $params[] = '%' . $searchFilter . '%';
    $params[] = ctype_digit($searchFilter) ? (int)$searchFilter : 0;
    $params[] = '%' . $searchFilter . '%';
}
$sql .= " ORDER BY c.sort_order ASC, s.sort_order ASC, s.id DESC LIMIT 250";
$services = Database::fetchAll($sql, $params);

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="space-y-6" x-data="{ showForm: false, editSvc: null, selectedCount: 0, bulkOp: '' }">
    <div class="smm-card rounded-3xl p-6">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
            <form method="GET" class="flex flex-wrap gap-2">
                <input type="text" name="search" value="<?= Validator::e($searchFilter) ?>" placeholder="Search name or ID..." class="px-3.5 py-2 rounded-xl border border-purple-200 text-xs w-44">
                <select name="category_id" class="px-3.5 py-2 rounded-xl border border-purple-200 text-xs max-w-[210px]">
                    <option value="0">All Categories</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $catFilter === (int)$c['id'] ? 'selected' : '' ?>><?= Validator::e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="provider_id" class="px-3.5 py-2 rounded-xl border border-purple-200 text-xs">
                    <option value="0">All Providers</option>
                    <?php foreach ($providers as $pr): ?>
                        <option value="<?= (int)$pr['id'] ?>" <?= $provFilter === (int)$pr['id'] ? 'selected' : '' ?>><?= Validator::e($pr['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="px-4 py-2 rounded-xl bg-purple-600 text-white text-xs font-bold">Filter</button>
            </form>
            <div class="flex gap-2">
                <a href="/admin/provider-import.php" class="px-4 py-2 rounded-xl bg-indigo-100 text-indigo-800 text-xs font-bold">Fetch &amp; Select from Provider</a>
                <button type="button" @click="editSvc = null; showForm = !showForm" class="px-4 py-2 rounded-xl bg-purple-600 text-white text-xs font-bold">+ Add Service</button>
            </div>
        </div>

        <!-- Add/Edit Service Form -->
        <form x-show="showForm" x-cloak method="POST" class="mb-6 p-5 rounded-2xl bg-[#F6F4FC] border border-purple-100 space-y-4 text-xs">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="save_service">
            <input type="hidden" name="id" :value="editSvc?.id || 0">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="sm:col-span-2">
                    <label class="block font-bold uppercase text-slate-500 mb-1">Service Name</label>
                    <input type="text" name="name" :value="editSvc?.name || ''" required class="w-full px-3 py-2 rounded-xl border border-purple-200">
                </div>
                <div>
                    <label class="block font-bold uppercase text-slate-500 mb-1">Category</label>
                    <select name="category_id" :value="editSvc?.category_id || <?= (int)($categories[0]['id'] ?? 1) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                        <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= Validator::e($c['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-6 gap-3">
                <div>
                    <label class="block font-bold uppercase text-slate-500 mb-1">Platform</label>
                    <select name="platform" :value="editSvc?.platform || 'instagram'" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                        <?php foreach (PLATFORM_KEYS as $pk => $pl): ?><option value="<?= $pk ?>"><?= Validator::e($pl) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block font-bold uppercase text-slate-500 mb-1">Service Type</label>
                    <select name="type" :value="editSvc?.type || 'default'" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                        <option value="default">Default</option>
                        <option value="custom_comments">Custom Comments</option>
                        <option value="package">Package</option>
                        <option value="drip_feed">Drip Feed</option>
                        <option value="subscriptions">Subscriptions</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold uppercase text-slate-500 mb-1">Provider Cost</label>
                    <input type="number" step="0.0001" name="provider_rate" :value="editSvc?.provider_rate ?? '0'" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                </div>
                <div>
                    <label class="block font-bold uppercase text-slate-500 mb-1">Prov Currency</label>
                    <select name="provider_currency" :value="editSvc?.provider_currency || editSvc?.prov_currency || '<?= Validator::e(Currency::getBaseCode()) ?>'" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                        <?php foreach (Currency::getAllCurrencies() as $cc => $cr): ?>
                            <option value="<?= Validator::e($cc) ?>"><?= Validator::e($cc) ?> (<?= Validator::e($cr['symbol']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block font-bold uppercase text-slate-500 mb-1">Selling (<?= Validator::e(Currency::getBaseCode()) ?> / 1k)</label>
                    <input type="number" step="0.0001" name="rate" :value="editSvc?.rate ?? '100'" required class="w-full px-3 py-2 rounded-xl border border-purple-200 font-bold">
                </div>
                <div>
                    <label class="block font-bold uppercase text-slate-500 mb-1">Status</label>
                    <select name="status" :value="editSvc?.status || 'active'" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <input type="number" name="min_qty" :value="editSvc?.min_qty ?? 10" placeholder="Min Qty" class="px-3 py-2 rounded-xl border border-purple-200">
                <input type="number" name="max_qty" :value="editSvc?.max_qty ?? 10000" placeholder="Max Qty" class="px-3 py-2 rounded-xl border border-purple-200">
                <input type="text" name="avg_time" :value="editSvc?.avg_time || ''" placeholder="Avg Time (e.g. 15 mins)" class="px-3 py-2 rounded-xl border border-purple-200">
                <input type="number" name="sort_order" :value="editSvc?.sort_order ?? 0" placeholder="Sort Order" class="px-3 py-2 rounded-xl border border-purple-200">
            </div>
            <textarea name="description" rows="2" x-text="editSvc?.description || ''" placeholder="Service description..." class="w-full px-3 py-2 rounded-xl border border-purple-200"></textarea>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex gap-4">
                    <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="refill_support" value="1" :checked="editSvc?.refill_support == 1"> Refill</label>
                    <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="cancel_support" value="1" :checked="editSvc?.cancel_support == 1"> Cancel</label>
                    <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="drip_feed_support" value="1" :checked="editSvc?.drip_feed_support == 1"> Drip-Feed</label>
                </div>
                <div class="flex gap-2">
                    <button type="button" @click="showForm = false" class="px-4 py-2 rounded-xl bg-white border border-slate-200 font-bold">Close</button>
                    <button type="submit" class="px-5 py-2 rounded-xl bg-purple-600 text-white font-bold">Save Service</button>
                </div>
            </div>
        </form>

        <!-- Bulk Table -->
        <form method="POST" @submit="if (bulkOp === 'delete' && !confirm('Delete selected services? (Services with orders will be marked inactive.)')) { $event.preventDefault(); }">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="bulk_action">
            <div class="flex flex-wrap items-center gap-2 mb-3 p-3 rounded-2xl bg-[#F6F4FC] border border-purple-100 text-xs">
                <select name="bulk_op" x-model="bulkOp" class="px-3 py-1.5 rounded-xl border border-purple-200 bg-white font-bold">
                    <option value="">Bulk Action...</option>
                    <option value="enable">Enable Selected</option>
                    <option value="disable">Disable Selected</option>
                    <option value="set_status">Update Service Status</option>
                    <option value="assign_category">Assign Platform Category</option>
                    <option value="update_margin">Update Profit Margin (%)</option>
                    <option value="update_selling_price">Update Selling Price</option>
                    <option value="delete">Delete Selected</option>
                </select>
                <select name="bulk_category_id" x-show="bulkOp === 'assign_category'" x-cloak class="px-3 py-1.5 rounded-xl border border-purple-200 bg-white max-w-[200px]">
                    <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= Validator::e($c['name']) ?></option><?php endforeach; ?>
                </select>
                <input type="number" step="0.01" min="0" name="bulk_margin_percent" x-show="bulkOp === 'update_margin'" x-cloak placeholder="Margin %" class="px-3 py-1.5 rounded-xl border border-purple-200 bg-white w-28">
                <select name="bulk_price_mode" x-show="bulkOp === 'update_selling_price'" x-cloak class="px-3 py-1.5 rounded-xl border border-purple-200 bg-white">
                    <option value="fixed">Fixed Price (<?= Validator::e(Currency::getBaseCode()) ?>)</option>
                    <option value="percent_adjust">% Adjust (+/-)</option>
                </select>
                <input type="number" step="0.0001" name="bulk_price_value" x-show="bulkOp === 'update_selling_price'" x-cloak placeholder="Value" class="px-3 py-1.5 rounded-xl border border-purple-200 bg-white w-28">
                <select name="bulk_status" x-show="bulkOp === 'set_status'" x-cloak class="px-3 py-1.5 rounded-xl border border-purple-200 bg-white">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                <button type="submit" :class="bulkOp === 'delete' ? 'bg-rose-600' : 'bg-slate-800'" class="px-3.5 py-1.5 rounded-xl text-white font-bold">Apply (<span x-text="selectedCount">0</span>)</button>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-purple-100 uppercase text-slate-400">
                            <th class="py-2 pr-2">
                                <input type="checkbox" class="rounded text-purple-600"
                                       @change="const checks = $el.closest('table').querySelectorAll('tbody input[name=\'service_ids[]\']'); checks.forEach(c => c.checked = $event.target.checked); selectedCount = $event.target.checked ? checks.length : 0;">
                            </th>
                            <th class="py-2 pr-3">ID</th>
                            <th class="py-2 pr-3">Service</th>
                            <th class="py-2 pr-3">Category</th>
                            <th class="py-2 pr-3">Cost / Price</th>
                            <th class="py-2 pr-3">Min / Max</th>
                            <th class="py-2 pr-3">Status</th>
                            <th class="py-2 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($services as $s): ?>
                        <tr class="hover:bg-purple-50/40">
                            <td class="py-2.5 pr-2"><input type="checkbox" name="service_ids[]" value="<?= (int)$s['id'] ?>" @change="selectedCount = $el.closest('tbody').querySelectorAll('input[name=\'service_ids[]\']:checked').length" class="rounded text-purple-600"></td>
                            <td class="py-2.5 pr-3 font-bold">#<?= (int)$s['id'] ?></td>
                            <td class="py-2.5 pr-3">
                                <div class="flex items-center gap-2">
                                    <?= Helpers::platformIcon((string)$s['platform'], 'w-6 h-6') ?>
                                    <div>
                                        <div class="font-bold text-slate-900 max-w-[230px] truncate"><?= Validator::e($s['name']) ?></div>
                                        <div class="text-[10px] text-slate-400"><?= $s['provider_name'] ? Validator::e($s['provider_name']) . ' #' . Validator::e($s['provider_service_id']) : 'Local' ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="py-2.5 pr-3 text-slate-600"><?= Validator::e($s['category_name']) ?></td>
                            <td class="py-2.5 pr-3">
                                <?php $pCurr = (string)($s['provider_currency'] ?: ($s['prov_currency'] ?: Currency::getBaseCode())); ?>
                                <div class="font-bold text-purple-700 tabular-nums"><?= Currency::formatBase((string)$s['rate'], 2, true) ?></div>
                                <div class="text-[10px] text-slate-400 tabular-nums">Cost: <?= Currency::formatRaw((string)$s['provider_rate'], $pCurr, 2, true) ?></div>
                            </td>
                            <td class="py-2.5 pr-3 text-slate-500"><?= (int)$s['min_qty'] ?> - <?= (int)$s['max_qty'] ?></td>
                            <td class="py-2.5 pr-3"><?= Helpers::statusBadge((string)$s['status']) ?></td>
                            <td class="py-2.5 text-right whitespace-nowrap">
                                <button type="button" @click="editSvc = <?= Validator::e(json_encode($s)) ?>; showForm = true; window.scrollTo({top:0,behavior:'smooth'})" class="px-2.5 py-1 rounded-lg bg-purple-100 text-purple-800 font-bold">Edit</button>
                                <button type="submit" form="del_svc_<?= (int)$s['id'] ?>" class="px-2.5 py-1 rounded-lg bg-rose-100 text-rose-700 font-bold">Del</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($services)): ?>
                            <tr><td colspan="8" class="py-8 text-center text-slate-500">No services added yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
        <?php foreach ($services as $s): ?>
        <form id="del_svc_<?= (int)$s['id'] ?>" method="POST" class="hidden" onsubmit="return confirm('Delete service?')">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="delete_service">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
        </form>
        <?php endforeach; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
