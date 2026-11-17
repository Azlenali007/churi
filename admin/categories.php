<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('categories.manage');
$pageTitle = 'Category & Subcategory Management';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_category') {
        $id = (int)($_POST['id'] ?? 0);
        $name = Validator::str($_POST['name'] ?? '', 150);
        $platform = Validator::str($_POST['platform'] ?? 'general', 60);
        $desc = Validator::str($_POST['description'] ?? '', 1000);
        $sort = (int)($_POST['sort_order'] ?? 0);
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $slug = Validator::slug($name);

        if ($id > 0) {
            Database::execute(
                "UPDATE categories SET name = ?, platform = ?, icon_svg_key = ?, description = ?, sort_order = ?, status = ? WHERE id = ?",
                [$name, $platform, $platform, $desc, $sort, $status, $id]
            );
            Helpers::setFlash('success', 'Category updated.');
        } else {
            $uniqSlug = $slug;
            $i = 1;
            while (Database::fetch("SELECT id FROM categories WHERE slug = ? LIMIT 1", [$uniqSlug])) {
                $uniqSlug = $slug . '-' . (++$i);
            }
            Database::insert(
                "INSERT INTO categories (name, slug, platform, icon_svg_key, description, sort_order, status) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$name, $uniqSlug, $platform, $platform, $desc, $sort, $status]
            );
            Helpers::setFlash('success', 'Category created.');
        }
    } elseif ($action === 'delete_category') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === 1) {
            Helpers::setFlash('error', 'Default fallback category cannot be deleted.');
        } else {
            try {
                Database::execute("DELETE FROM categories WHERE id = ?", [$id]);
                Helpers::setFlash('success', 'Category deleted.');
            } catch (Throwable $e) {
                Helpers::setFlash('error', 'Category contains services. Reassign or delete those services first.');
            }
        }
    } elseif ($action === 'add_subcategory') {
        $catId = (int)($_POST['category_id'] ?? 0);
        $name = Validator::str($_POST['name'] ?? '', 150);
        if ($catId > 0 && $name !== '') {
            Database::insert(
                "INSERT INTO subcategories (category_id, name, slug, sort_order, status) VALUES (?, ?, ?, ?, 'active')",
                [$catId, $name, Validator::slug($name), (int)($_POST['sort_order'] ?? 0)]
            );
            Helpers::setFlash('success', 'Subcategory added.');
        }
    } elseif ($action === 'delete_subcategory') {
        Database::execute("DELETE FROM subcategories WHERE id = ?", [(int)($_POST['id'] ?? 0)]);
        Helpers::setFlash('success', 'Subcategory deleted.');
    }

    Helpers::redirect('/admin/categories.php');
}

$categories = Database::fetchAll(
    "SELECT c.*, COUNT(s.id) AS svc_count
     FROM categories c
     LEFT JOIN services s ON s.category_id = c.id
     GROUP BY c.id
     ORDER BY c.sort_order ASC, c.id ASC"
);
$subcategories = Database::fetchAll(
    "SELECT sc.*, c.name AS cat_name FROM subcategories sc JOIN categories c ON c.id = sc.category_id ORDER BY c.sort_order ASC, sc.sort_order ASC"
);

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6" x-data="{ editCat: null }">
    <div class="lg:col-span-5 space-y-6">
        <div class="smm-card rounded-3xl p-6">
            <h2 class="text-base font-extrabold text-slate-900 mb-4" x-text="editCat ? 'Edit Category' : 'Add Category'"></h2>
            <form method="POST" class="space-y-3 text-xs">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="save_category">
                <input type="hidden" name="id" :value="editCat?.id || 0">
                <div>
                    <label class="block font-bold uppercase text-slate-500 mb-1">Category Name</label>
                    <input type="text" name="name" :value="editCat?.name || ''" required class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 text-sm">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold uppercase text-slate-500 mb-1">Platform &amp; Icon</label>
                        <select name="platform" :value="editCat?.platform || 'instagram'" class="w-full px-3 py-2.5 rounded-xl border border-purple-200">
                            <?php foreach (PLATFORM_KEYS as $pk => $pl): ?><option value="<?= $pk ?>"><?= Validator::e($pl) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block font-bold uppercase text-slate-500 mb-1">Sort Order</label>
                        <input type="number" name="sort_order" :value="editCat?.sort_order ?? 1" class="w-full px-3 py-2.5 rounded-xl border border-purple-200">
                    </div>
                </div>
                <div>
                    <label class="block font-bold uppercase text-slate-500 mb-1">Status</label>
                    <select name="status" :value="editCat?.status || 'active'" class="w-full px-3 py-2.5 rounded-xl border border-purple-200">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold uppercase text-slate-500 mb-1">Description</label>
                    <textarea name="description" rows="2" x-text="editCat?.description || ''" class="w-full px-3 py-2 rounded-xl border border-purple-200"></textarea>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-purple-600 text-white font-bold">Save Category</button>
                    <button type="button" x-show="editCat" @click="editCat = null" class="px-4 py-2.5 rounded-xl bg-slate-100 font-bold">Cancel</button>
                </div>
            </form>
        </div>

        <div class="smm-card rounded-3xl p-6">
            <h2 class="text-base font-extrabold text-slate-900 mb-3">Add Subcategory</h2>
            <form method="POST" class="space-y-3 text-xs">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="add_subcategory">
                <select name="category_id" required class="w-full px-3 py-2.5 rounded-xl border border-purple-200">
                    <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= Validator::e($c['name']) ?></option><?php endforeach; ?>
                </select>
                <div class="grid grid-cols-3 gap-2">
                    <input type="text" name="name" required placeholder="Subcategory name" class="col-span-2 px-3 py-2 rounded-xl border border-purple-200">
                    <input type="number" name="sort_order" value="0" placeholder="Sort" class="px-3 py-2 rounded-xl border border-purple-200">
                </div>
                <button type="submit" class="px-4 py-2 rounded-xl bg-purple-600 text-white font-bold">Add Subcategory</button>
            </form>
        </div>
    </div>

    <div class="lg:col-span-7 space-y-6">
        <div class="smm-card rounded-3xl p-6">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                <h2 class="text-base font-extrabold text-slate-900">All Categories (<?= count($categories) ?>)</h2>
                <a href="/admin/provider-categories.php" class="px-3.5 py-1.5 rounded-xl bg-purple-100 hover:bg-purple-200 text-purple-800 text-xs font-bold transition">Import from Provider &rarr;</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead><tr class="border-b border-purple-100 uppercase text-slate-400"><th class="py-2">Sort</th><th class="py-2">Category</th><th class="py-2">Services</th><th class="py-2">Status</th><th class="py-2 text-right">Action</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($categories as $c): ?>
                        <tr>
                            <td class="py-2.5 font-bold text-slate-400"><?= (int)$c['sort_order'] ?></td>
                            <td class="py-2.5">
                                <div class="flex items-center gap-2.5">
                                    <?= Helpers::platformIcon((string)$c['icon_svg_key'], 'w-6 h-6') ?>
                                    <span class="font-bold text-slate-900"><?= Validator::e($c['name']) ?></span>
                                </div>
                            </td>
                            <td class="py-2.5 font-semibold"><?= (int)$c['svc_count'] ?></td>
                            <td class="py-2.5"><?= Helpers::statusBadge((string)$c['status']) ?></td>
                            <td class="py-2.5 text-right whitespace-nowrap">
                                <button type="button" @click="editCat = <?= Validator::e(json_encode($c)) ?>" class="px-2.5 py-1 rounded-lg bg-purple-100 text-purple-800 font-bold">Edit</button>
                                <?php if ((int)$c['id'] !== 1): ?>
                                <form method="POST" class="inline" onsubmit="return confirm('Delete category?')">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="delete_category">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button type="submit" class="px-2.5 py-1 rounded-lg bg-rose-100 text-rose-700 font-bold">Del</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (!empty($subcategories)): ?>
        <div class="smm-card rounded-3xl p-6">
            <h2 class="text-base font-extrabold text-slate-900 mb-3">Subcategories</h2>
            <div class="divide-y divide-slate-100 text-xs">
                <?php foreach ($subcategories as $sc): ?>
                <div class="py-2 flex items-center justify-between">
                    <span><strong><?= Validator::e($sc['cat_name']) ?></strong> &rarr; <?= Validator::e($sc['name']) ?></span>
                    <form method="POST">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="delete_subcategory">
                        <input type="hidden" name="id" value="<?= (int)$sc['id'] ?>">
                        <button type="submit" class="text-rose-600 font-bold">Remove</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
