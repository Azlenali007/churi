<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('flash_sales.manage');
$pageTitle = 'Flash Sale Management System';

FlashSale::expireFinishedSales();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_sale') {
        $id = (int)($_POST['id'] ?? 0);
        $title = Validator::str($_POST['title'] ?? '', 150);
        $desc = Validator::str($_POST['description'] ?? '', 255);
        $discType = ($_POST['discount_type'] ?? 'percent') === 'fixed_price' ? 'fixed_price' : 'percent';
        $discVal = Currency::normalize((string)($_POST['discount_value'] ?? '0'), 4);
        $starts = str_replace('T', ' ', (string)($_POST['starts_at'] ?? '')) . ':00';
        $ends = str_replace('T', ' ', (string)($_POST['ends_at'] ?? '')) . ':00';
        $priority = max(1, (int)($_POST['priority'] ?? 1));
        $status = in_array($_POST['status'] ?? '', ['active','inactive','stopped'], true) ? $_POST['status'] : 'active';

        // Gather selected services + services from selected categories
        $serviceIds = array_map('intval', $_POST['service_ids'] ?? []);
        $catIds = array_map('intval', $_POST['category_ids'] ?? []);
        if (!empty($catIds)) {
            $ph = implode(',', array_fill(0, count($catIds), '?'));
            $catSvcs = Database::fetchAll("SELECT id FROM services WHERE category_id IN ($ph) AND status = 'active'", $catIds);
            foreach ($catSvcs as $cs) {
                $serviceIds[] = (int)$cs['id'];
            }
        }
        $serviceIds = array_values(array_unique(array_filter($serviceIds)));

        if ($title === '' || empty($serviceIds) || strtotime($ends) <= strtotime($starts)) {
            Helpers::setFlash('error', 'Title, valid start/end date range, and at least one service or category are required.');
            Helpers::redirect('/admin/flash-sales.php');
        }

        // Prevent conflicting active sales on the same service
        if ($status === 'active') {
            $conflicts = FlashSale::findConflictingServices($serviceIds, $starts, $ends, $id > 0 ? $id : null);
            if (!empty($conflicts)) {
                $names = implode(', ', array_slice(array_column($conflicts, 'name'), 0, 3));
                Helpers::setFlash('error', 'Conflicting active Flash Sale already exists for: ' . $names);
                Helpers::redirect('/admin/flash-sales.php');
            }
        }

        $bannerPath = $_POST['existing_banner'] ?? null;
        if (!empty($_FILES['banner']) && ($_FILES['banner']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $up = Uploader::uploadImage($_FILES['banner'], 'flash_sales');
            if (!$up['ok']) {
                Helpers::setFlash('error', $up['error']);
                Helpers::redirect('/admin/flash-sales.php');
            }
            $bannerPath = $up['path'];
        }

        Database::beginTransaction();
        try {
            if ($id > 0) {
                Database::execute(
                    "UPDATE flash_sales SET title=?, description=?, banner_image=?, discount_type=?, discount_value=?, starts_at=?, ends_at=?, priority=?, status=? WHERE id=?",
                    [$title, $desc, $bannerPath, $discType, $discVal, $starts, $ends, $priority, $status, $id]
                );
                Database::execute("DELETE FROM flash_sale_services WHERE flash_sale_id = ?", [$id]);
                $saleId = $id;
            } else {
                $saleId = Database::insert(
                    "INSERT INTO flash_sales (title, description, banner_image, discount_type, discount_value, starts_at, ends_at, priority, status, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$title, $desc, $bannerPath, $discType, $discVal, $starts, $ends, $priority, $status, $admin['id']]
                );
            }

            foreach ($serviceIds as $sid) {
                Database::insert(
                    "INSERT INTO flash_sale_services (flash_sale_id, service_id) VALUES (?, ?)",
                    [$saleId, $sid]
                );
            }
            Database::commit();
            Helpers::setFlash('success', 'Flash Sale saved with ' . count($serviceIds) . ' service(s).');
        } catch (Throwable $e) {
            Database::rollBack();
            Helpers::setFlash('error', 'Failed to save Flash Sale: ' . $e->getMessage());
        }
    } elseif ($action === 'stop_sale') {
        $id = (int)($_POST['id'] ?? 0);
        Database::execute("UPDATE flash_sales SET status = 'stopped' WHERE id = ?", [$id]);
        Helpers::setFlash('success', 'Flash Sale stopped immediately. Normal prices are now active.');
    } elseif ($action === 'delete_sale') {
        $id = (int)($_POST['id'] ?? 0);
        Database::execute("DELETE FROM flash_sales WHERE id = ?", [$id]);
        Helpers::setFlash('success', 'Flash Sale deleted.');
    }

    Helpers::redirect('/admin/flash-sales.php');
}

$sales = Database::fetchAll(
    "SELECT fs.*,
            (SELECT COUNT(*) FROM flash_sale_services fss WHERE fss.flash_sale_id = fs.id) AS service_count,
            (SELECT COUNT(*) FROM orders o WHERE o.flash_sale_id = fs.id) AS orders_count,
            (SELECT COALESCE(SUM(o.charge - o.refunded_amount), 0) FROM orders o WHERE o.flash_sale_id = fs.id AND o.status NOT IN ('canceled','failed')) AS sale_revenue
     FROM flash_sales fs
     ORDER BY fs.id DESC"
);
$categories = Database::fetchAll("SELECT id, name FROM categories WHERE status = 'active' ORDER BY sort_order ASC");
$services = Database::fetchAll("SELECT id, name, rate FROM services WHERE status = 'active' ORDER BY id ASC");

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
    <div class="lg:col-span-5 smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-4">Create Flash Sale</h2>
        <form method="POST" enctype="multipart/form-data" class="space-y-3 text-xs">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="save_sale">
            <div>
                <label class="block font-bold uppercase text-slate-500 mb-1">Flash Sale Title</label>
                <input type="text" name="title" required placeholder="FLASH SALE" class="w-full px-3.5 py-2 rounded-xl border border-purple-200 text-sm">
            </div>
            <div>
                <label class="block font-bold uppercase text-slate-500 mb-1">Description</label>
                <input type="text" name="description" placeholder="Limited Time Offer — Don't Miss!" class="w-full px-3.5 py-2 rounded-xl border border-purple-200">
            </div>
            <div class="grid grid-cols-3 gap-2">
                <select name="discount_type" class="px-3 py-2 rounded-xl border border-purple-200">
                    <option value="percent">Discount %</option>
                    <option value="fixed_price">Fixed Price /1k</option>
                </select>
                <input type="number" step="0.01" name="discount_value" required placeholder="e.g. 50" class="px-3 py-2 rounded-xl border border-purple-200 font-bold">
                <input type="number" name="priority" value="1" min="1" placeholder="Priority" class="px-3 py-2 rounded-xl border border-purple-200">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold uppercase text-slate-500 mb-1">Starts At</label>
                    <input type="datetime-local" name="starts_at" value="<?= date('Y-m-d\TH:i') ?>" required class="w-full px-3 py-2 rounded-xl border border-purple-200">
                </div>
                <div>
                    <label class="block font-bold uppercase text-slate-500 mb-1">Ends At</label>
                    <input type="datetime-local" name="ends_at" value="<?= date('Y-m-d\TH:i', strtotime('+2 days')) ?>" required class="w-full px-3 py-2 rounded-xl border border-purple-200">
                </div>
            </div>
            <div>
                <label class="block font-bold uppercase text-slate-500 mb-1">Include Whole Categories (Ctrl/Cmd+Click)</label>
                <select name="category_ids[]" multiple size="4" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                    <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= Validator::e($c['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block font-bold uppercase text-slate-500 mb-1">Or Select Specific Services (Ctrl/Cmd+Click)</label>
                <select name="service_ids[]" multiple size="5" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                    <?php foreach ($services as $s): ?><option value="<?= (int)$s['id'] ?>">#<?= (int)$s['id'] ?> <?= Validator::e($s['name']) ?> (<?= Currency::format((string)$s['rate']) ?>)</option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block font-bold uppercase text-slate-500 mb-1">Banner Background Upload (Optional)</label>
                <input type="file" name="banner" accept="image/*" class="w-full text-xs">
            </div>
            <button type="submit" class="w-full py-2.5 rounded-xl bg-gradient-to-r from-purple-600 to-pink-600 text-white font-bold shadow">Launch Flash Sale</button>
        </form>
    </div>

    <div class="lg:col-span-7 smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-4">Flash Sales &amp; Performance (<?= count($sales) ?>)</h2>
        <?php if (empty($sales)): ?>
            <p class="text-xs text-slate-500 py-8 text-center">No flash sales created yet.</p>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($sales as $fs): ?>
                <div class="p-4 rounded-2xl bg-[#F6F4FC] border border-purple-100">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                        <div>
                            <div class="font-extrabold text-sm text-slate-900"><?= Validator::e($fs['title']) ?></div>
                            <div class="text-[11px] text-slate-500"><?= date('M j, H:i', strtotime($fs['starts_at'])) ?> &rarr; <?= date('M j, H:i', strtotime($fs['ends_at'])) ?></div>
                        </div>
                        <?= Helpers::statusBadge((string)$fs['status']) ?>
                    </div>
                    <div class="grid grid-cols-4 gap-2 text-xs mb-3">
                        <div>Discount: <strong><?= $fs['discount_type'] === 'percent' ? ($fs['discount_value'] . '%') : Currency::format((string)$fs['discount_value']) ?></strong></div>
                        <div>Services: <strong><?= (int)$fs['service_count'] ?></strong></div>
                        <div>Orders: <strong><?= (int)$fs['orders_count'] ?></strong></div>
                        <div>Revenue: <strong class="text-purple-700"><?= Currency::format((string)$fs['sale_revenue']) ?></strong></div>
                    </div>
                    <div class="flex gap-2 text-xs">
                        <?php if ($fs['status'] === 'active'): ?>
                        <form method="POST">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="stop_sale">
                            <input type="hidden" name="id" value="<?= (int)$fs['id'] ?>">
                            <button type="submit" class="px-3 py-1.5 rounded-lg bg-amber-500 text-white font-bold">Stop Sale Now</button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" onsubmit="return confirm('Delete flash sale record?')">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="delete_sale">
                            <input type="hidden" name="id" value="<?= (int)$fs['id'] ?>">
                            <button type="submit" class="px-3 py-1.5 rounded-lg bg-rose-100 text-rose-700 font-bold">Delete</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
