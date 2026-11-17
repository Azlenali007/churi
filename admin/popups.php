<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('popups.manage');
$pageTitle = 'Popup Management System';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_popup') {
        $id = (int)($_POST['id'] ?? 0);
        $title = Validator::str($_POST['title'] ?? '', 160);
        $badge = Validator::str($_POST['badge_text'] ?? 'Exclusive Offer', 60);
        $desc = Validator::str($_POST['description'] ?? '', 2000);
        $ctaText = Validator::str($_POST['cta_text'] ?? 'Shop Now', 80);
        $ctaLink = Validator::str($_POST['cta_link'] ?? '/user/new-order.php', 255);
        $pos = in_array($_POST['position'] ?? '', ['center','bottom_right','top_banner'], true) ? $_POST['position'] : 'center';
        $freq = in_array($_POST['frequency'] ?? '', ['once','every_visit','daily'], true) ? $_POST['frequency'] : 'daily';
        $pub = !empty($_POST['show_on_public']) ? 1 : 0;
        $dash = !empty($_POST['show_on_dashboard']) ? 1 : 0;
        $mob = !empty($_POST['show_mobile']) ? 1 : 0;
        $desk = !empty($_POST['show_desktop']) ? 1 : 0;
        $starts = !empty($_POST['starts_at']) ? str_replace('T', ' ', $_POST['starts_at']) : null;
        $ends = !empty($_POST['ends_at']) ? str_replace('T', ' ', $_POST['ends_at']) : null;
        $priority = max(1, (int)($_POST['priority'] ?? 1));
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        $imgPath = $_POST['existing_image'] ?? null;
        if (!empty($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $up = Uploader::uploadImage($_FILES['image'], 'popups');
            if (!$up['ok']) {
                Helpers::setFlash('error', $up['error']);
                Helpers::redirect('/admin/popups.php');
            }
            $imgPath = $up['path'];
        }

        if ($id > 0) {
            Database::execute(
                "UPDATE popups SET title=?, badge_text=?, description=?, image_path=?, cta_text=?, cta_link=?, position=?, show_on_public=?, show_on_dashboard=?, starts_at=?, ends_at=?, frequency=?, show_mobile=?, show_desktop=?, priority=?, status=? WHERE id=?",
                [$title, $badge, $desc, $imgPath, $ctaText, $ctaLink, $pos, $pub, $dash, $starts, $ends, $freq, $mob, $desk, $priority, $status, $id]
            );
            Helpers::setFlash('success', 'Popup updated.');
        } else {
            Database::insert(
                "INSERT INTO popups (title, badge_text, description, image_path, cta_text, cta_link, position, show_on_public, show_on_dashboard, starts_at, ends_at, frequency, show_mobile, show_desktop, priority, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$title, $badge, $desc, $imgPath, $ctaText, $ctaLink, $pos, $pub, $dash, $starts, $ends, $freq, $mob, $desk, $priority, $status]
            );
            Helpers::setFlash('success', 'Promotional popup created.');
        }
    } elseif ($action === 'delete_popup') {
        Database::execute("DELETE FROM popups WHERE id = ?", [(int)($_POST['id'] ?? 0)]);
        Helpers::setFlash('success', 'Popup deleted.');
    } elseif ($action === 'toggle_popup') {
        $id = (int)($_POST['id'] ?? 0);
        Database::execute("UPDATE popups SET status = IF(status='active','inactive','active') WHERE id = ?", [$id]);
        Helpers::setFlash('success', 'Popup visibility toggled.');
    }

    Helpers::redirect('/admin/popups.php');
}

$popups = Database::fetchAll("SELECT * FROM popups ORDER BY priority ASC, id DESC");

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6" x-data="{ editP: null }">
    <div class="lg:col-span-5 smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-4" x-text="editP ? 'Edit Popup' : 'Create Promotional Popup'"></h2>
        <form method="POST" enctype="multipart/form-data" class="space-y-3 text-xs">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="save_popup">
            <input type="hidden" name="id" :value="editP?.id || 0">
            <input type="hidden" name="existing_image" :value="editP?.image_path || ''">

            <div>
                <label class="block font-bold uppercase text-slate-500 mb-1">Popup Title</label>
                <input type="text" name="title" :value="editP?.title || ''" required placeholder="Get 50% OFF on All Services!" class="w-full px-3.5 py-2 rounded-xl border border-purple-200 text-sm">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold uppercase text-slate-500 mb-1">Badge Text</label>
                    <input type="text" name="badge_text" :value="editP?.badge_text ?? 'Exclusive Offer'" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                </div>
                <div>
                    <label class="block font-bold uppercase text-slate-500 mb-1">Priority</label>
                    <input type="number" name="priority" min="1" :value="editP?.priority ?? 1" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                </div>
            </div>
            <div>
                <label class="block font-bold uppercase text-slate-500 mb-1">Description</label>
                <textarea name="description" rows="2" x-text="editP?.description || ''" class="w-full px-3 py-2 rounded-xl border border-purple-200"></textarea>
            </div>
            <div>
                <label class="block font-bold uppercase text-slate-500 mb-1">Banner / Gift Image Upload</label>
                <input type="file" name="image" accept="image/*" class="w-full text-xs">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <input type="text" name="cta_text" :value="editP?.cta_text ?? 'Shop Now'" placeholder="CTA Button Text" class="px-3 py-2 rounded-xl border border-purple-200">
                <input type="text" name="cta_link" :value="editP?.cta_link ?? '/user/new-order.php'" placeholder="CTA URL" class="px-3 py-2 rounded-xl border border-purple-200">
            </div>
            <div class="grid grid-cols-3 gap-2">
                <select name="position" :value="editP?.position || 'center'" class="px-2.5 py-2 rounded-xl border border-purple-200">
                    <option value="center">Center Modal</option>
                    <option value="bottom_right">Bottom Right</option>
                    <option value="top_banner">Top Modal</option>
                </select>
                <select name="frequency" :value="editP?.frequency || 'daily'" class="px-2.5 py-2 rounded-xl border border-purple-200">
                    <option value="daily">Once Per Day</option>
                    <option value="once">Show Once</option>
                    <option value="every_visit">Every Visit</option>
                </select>
                <select name="status" :value="editP?.status || 'active'" class="px-2.5 py-2 rounded-xl border border-purple-200">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold uppercase text-slate-500 mb-1">Starts At (Optional)</label>
                    <input type="datetime-local" name="starts_at" :value="editP?.starts_at ? editP.starts_at.replace(' ', 'T').slice(0,16) : ''" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                </div>
                <div>
                    <label class="block font-bold uppercase text-slate-500 mb-1">Ends At (Optional)</label>
                    <input type="datetime-local" name="ends_at" :value="editP?.ends_at ? editP.ends_at.replace(' ', 'T').slice(0,16) : ''" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-2 pt-1">
                <label class="flex items-center gap-2"><input type="checkbox" name="show_on_public" value="1" :checked="!editP || editP.show_on_public == 1"> Show on Public Home</label>
                <label class="flex items-center gap-2"><input type="checkbox" name="show_on_dashboard" value="1" :checked="!editP || editP.show_on_dashboard == 1"> Show on User Dashboard</label>
                <label class="flex items-center gap-2"><input type="checkbox" name="show_mobile" value="1" :checked="!editP || editP.show_mobile == 1"> Mobile Visible</label>
                <label class="flex items-center gap-2"><input type="checkbox" name="show_desktop" value="1" :checked="!editP || editP.show_desktop == 1"> Desktop Visible</label>
            </div>
            <div class="flex gap-2 pt-2">
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-purple-600 text-white font-bold">Save Popup</button>
                <button type="button" x-show="editP" @click="editP = null" class="px-4 py-2.5 rounded-xl bg-slate-100 font-bold">Cancel</button>
            </div>
        </form>
    </div>

    <div class="lg:col-span-7 smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-4">Configured Popups (<?= count($popups) ?>)</h2>
        <?php if (empty($popups)): ?>
            <p class="text-xs text-slate-500 py-8 text-center">No popups configured yet.</p>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($popups as $p): ?>
                <div class="p-4 rounded-2xl bg-[#F6F4FC] border border-purple-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex items-center gap-3 min-w-0">
                        <?php if ($p['image_path']): ?>
                            <img src="<?= Validator::e($p['image_path']) ?>" class="w-14 h-14 rounded-xl object-cover shrink-0">
                        <?php endif; ?>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-extrabold text-sm text-slate-900 truncate"><?= Validator::e($p['title']) ?></span>
                                <?= Helpers::statusBadge((string)$p['status']) ?>
                            </div>
                            <div class="text-[11px] text-slate-500 mt-0.5">
                                Priority: <?= (int)$p['priority'] ?> &bull; Freq: <?= Validator::e($p['frequency']) ?> &bull;
                                <?= $p['show_on_public'] ? 'Public ' : '' ?><?= $p['show_on_dashboard'] ? 'Dashboard' : '' ?>
                            </div>
                        </div>
                    </div>
                    <div class="flex gap-1.5 text-xs shrink-0">
                        <button type="button" @click="editP = <?= Validator::e(json_encode($p)) ?>" class="px-3 py-1.5 rounded-lg bg-purple-100 text-purple-800 font-bold">Edit</button>
                        <form method="POST">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="toggle_popup">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-200 font-bold"><?= $p['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
                        </form>
                        <form method="POST" onsubmit="return confirm('Delete popup?')">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="delete_popup">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button type="submit" class="px-3 py-1.5 rounded-lg bg-rose-100 text-rose-700 font-bold">Del</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
