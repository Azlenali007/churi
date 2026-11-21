<?php
/**
 * Admin OTP Services Manager
 * Add, edit, enable/disable, safely delete OTP services and manage country-provider mappings & custom prices.
 */
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('otp.manage');
$pageTitle = 'OTP Services';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_service') {
        $id = (int)($_POST['id'] ?? 0);
        $name = Validator::str($_POST['name'] ?? '', 120);
        $slug = strtolower(preg_replace('/[^a-z0-9_-]+/i', '', trim((string)($_POST['slug'] ?? ''))));
        if ($slug === '' && $name !== '') {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($name)));
            $slug = trim($slug, '_');
        }
        $iconKey = Validator::str($_POST['icon_key'] ?? 'general', 60);
        $desc = Validator::str($_POST['description'] ?? '', 500);
        $providerId = !empty($_POST['provider_id']) ? (int)$_POST['provider_id'] : null;
        $provCode = Validator::str($_POST['provider_service_code'] ?? $slug, 100);
        $sellingPrice = Currency::normalize((string)($_POST['selling_price'] ?? '0'), 4);
        $profitMargin = Currency::normalize((string)($_POST['profit_margin'] ?? Helpers::setting('otp_default_profit_margin', '25.00')), 2);
        $minPrice = trim((string)($_POST['min_price'] ?? '')) !== '' ? Currency::normalize((string)$_POST['min_price'], 4) : null;
        $maxPrice = trim((string)($_POST['max_price'] ?? '')) !== '' ? Currency::normalize((string)$_POST['max_price'], 4) : null;
        $isPopular = isset($_POST['is_popular']) ? 1 : 0;
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $status = (($_POST['status'] ?? 'active') === 'inactive') ? 'inactive' : 'active';

        if ($name === '' || $slug === '') {
            Helpers::setFlash('error', 'Service name and slug are required.');
            Helpers::redirect('/admin/otp-services.php');
        }

        try {
            if ($id > 0) {
                Database::execute(
                    "UPDATE otp_services
                     SET name = ?, slug = ?, icon_key = ?, description = ?, provider_id = ?, provider_service_code = ?,
                         selling_price = ?, profit_margin = ?, min_price = ?, max_price = ?, is_popular = ?, sort_order = ?, status = ?
                     WHERE id = ?",
                    [$name, $slug, $iconKey, $desc, $providerId, $provCode, $sellingPrice, $profitMargin, $minPrice, $maxPrice, $isPopular, $sortOrder, $status, $id]
                );
                Helpers::setFlash('success', 'OTP service updated.');
            } else {
                Database::insert(
                    "INSERT INTO otp_services
                     (name, slug, icon_key, description, provider_id, provider_service_code, selling_price, profit_margin, min_price, max_price, is_popular, sort_order, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$name, $slug, $iconKey, $desc, $providerId, $provCode, $sellingPrice, $profitMargin, $minPrice, $maxPrice, $isPopular, $sortOrder, $status]
                );
                Helpers::setFlash('success', 'OTP service created.');
            }
        } catch (Throwable $e) {
            Helpers::setFlash('error', 'Could not save service (ensure slug is unique): ' . $e->getMessage());
        }
        Helpers::redirect('/admin/otp-services.php');
    } elseif ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        $svc = Database::fetch("SELECT id, status FROM otp_services WHERE id = ?", [$id]);
        if ($svc) {
            $next = $svc['status'] === 'active' ? 'inactive' : 'active';
            Database::execute("UPDATE otp_services SET status = ? WHERE id = ?", [$next, $id]);
            Helpers::setFlash('success', 'Service status set to ' . $next . '.');
        }
        Helpers::redirect('/admin/otp-services.php');
    } elseif ($action === 'delete_service') {
        $id = (int)($_POST['id'] ?? 0);
        $used = (int)Database::fetchColumn("SELECT COUNT(*) FROM otp_activations WHERE service_id = ?", [$id]);
        if ($used > 0) {
            Helpers::setFlash('error', "Cannot delete service with {$used} activation record(s). Disable it instead to preserve history.");
        } else {
            Database::execute("DELETE FROM otp_services WHERE id = ?", [$id]);
            Helpers::setFlash('success', 'OTP service deleted.');
        }
        Helpers::redirect('/admin/otp-services.php');
    } elseif ($action === 'save_mapping') {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $countryId = (int)($_POST['country_id'] ?? 0);
        $providerId = (int)($_POST['provider_id'] ?? 0);
        $provSvc = Validator::str($_POST['provider_service_code'] ?? '', 100);
        $provCntry = Validator::str($_POST['provider_country_code'] ?? '', 100);
        $provOp = Validator::str($_POST['provider_operator'] ?? 'any', 80) ?: 'any';
        $customPrice = trim((string)($_POST['custom_selling_price'] ?? '')) !== '' ? Currency::normalize((string)$_POST['custom_selling_price'], 4) : null;
        $avail = isset($_POST['is_available']) ? 1 : 0;

        if ($serviceId > 0 && $countryId > 0 && $providerId > 0) {
            Database::execute(
                "INSERT INTO otp_service_provider_mappings
                 (service_id, country_id, provider_id, provider_service_code, provider_country_code, provider_operator, custom_selling_price, is_available, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
                 ON DUPLICATE KEY UPDATE
                   provider_service_code = VALUES(provider_service_code),
                   provider_country_code = VALUES(provider_country_code),
                   provider_operator = VALUES(provider_operator),
                   custom_selling_price = VALUES(custom_selling_price),
                   is_available = VALUES(is_available),
                   status = 'active'",
                [$serviceId, $countryId, $providerId, $provSvc ?: null, $provCntry ?: null, $provOp, $customPrice, $avail]
            );
            Helpers::setFlash('success', 'Country-service mapping saved.');
        }
        Helpers::redirect('/admin/otp-services.php?edit=' . $serviceId);
    } elseif ($action === 'delete_mapping') {
        $mapId = (int)($_POST['mapping_id'] ?? 0);
        $svcId = (int)($_POST['service_id'] ?? 0);
        Database::execute("DELETE FROM otp_service_provider_mappings WHERE id = ?", [$mapId]);
        Helpers::setFlash('success', 'Mapping removed.');
        Helpers::redirect('/admin/otp-services.php?edit=' . $svcId);
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$editSvc = $editId > 0 ? Database::fetch("SELECT * FROM otp_services WHERE id = ?", [$editId]) : null;
$providers = Database::fetchAll("SELECT id, name, adapter_type, status FROM otp_providers ORDER BY name ASC");
$countries = Database::fetchAll("SELECT id, name, iso_code, flag_emoji, default_provider_code FROM otp_countries ORDER BY name ASC");
$services = Database::fetchAll(
    "SELECT s.*, p.name AS provider_name,
            (SELECT COUNT(*) FROM otp_activations a WHERE a.service_id = s.id) AS act_count
     FROM otp_services s
     LEFT JOIN otp_providers p ON p.id = s.provider_id
     ORDER BY s.sort_order ASC, s.name ASC"
);
$mappings = $editSvc ? Database::fetchAll(
    "SELECT m.*, c.name AS country_name, c.flag_emoji, p.name AS provider_name
     FROM otp_service_provider_mappings m
     JOIN otp_countries c ON c.id = m.country_id
     JOIN otp_providers p ON p.id = m.provider_id
     WHERE m.service_id = ? ORDER BY c.name ASC",
    [$editSvc['id']]
) : [];

$iconOptions = ['whatsapp', 'telegram', 'google', 'instagram', 'facebook', 'twitter', 'tiktok', 'discord', 'amazon', 'apple', 'microsoft', 'uber', 'youtube', 'snapchat', 'general'];

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="grid lg:grid-cols-12 gap-6">
    <!-- Add / Edit Form -->
    <div class="lg:col-span-5 space-y-6">
        <div class="smm-card rounded-3xl p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-extrabold text-slate-900"><?= $editSvc ? 'Edit OTP Service' : 'Add OTP Service' ?></h2>
                <?php if ($editSvc): ?><a href="/admin/otp-services.php" class="text-xs font-bold text-purple-600">+ New</a><?php endif; ?>
            </div>
            <form method="POST" class="space-y-3 text-xs">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="save_service">
                <input type="hidden" name="id" value="<?= (int)($editSvc['id'] ?? 0) ?>">

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Service Name *</label>
                        <input type="text" name="name" required value="<?= Validator::e($editSvc['name'] ?? '') ?>" placeholder="WhatsApp" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Slug *</label>
                        <input type="text" name="slug" value="<?= Validator::e($editSvc['slug'] ?? '') ?>" placeholder="whatsapp" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Assigned Provider *</label>
                        <select name="provider_id" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-semibold">
                            <option value="">— Select Provider —</option>
                            <?php foreach ($providers as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= (int)($editSvc['provider_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= Validator::e($p['name']) ?> (<?= Validator::e($p['status']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Provider Service Code</label>
                        <input type="text" name="provider_service_code" value="<?= Validator::e($editSvc['provider_service_code'] ?? '') ?>" placeholder="e.g. whatsapp or tg" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Icon</label>
                        <select name="icon_key" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-semibold">
                            <?php foreach ($iconOptions as $ic): ?>
                            <option value="<?= $ic ?>" <?= ($editSvc['icon_key'] ?? 'general') === $ic ? 'selected' : '' ?>><?= ucfirst($ic) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Status</label>
                        <select name="status" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-semibold">
                            <option value="active" <?= ($editSvc['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= ($editSvc['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Base Selling Price (<?= Validator::e(Currency::getBaseCode()) ?>)</label>
                        <input type="number" step="0.01" min="0" name="selling_price" value="<?= Validator::e($editSvc['selling_price'] ?? '0.00') ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                        <span class="text-[10px] text-slate-400">Set 0 for pure live provider cost + margin</span>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Profit Margin (%)</label>
                        <input type="number" step="0.01" min="0" name="profit_margin" value="<?= Validator::e($editSvc['profit_margin'] ?? Helpers::setting('otp_default_profit_margin', '25.00')) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Min Price</label>
                        <input type="number" step="0.01" min="0" name="min_price" value="<?= Validator::e((string)($editSvc['min_price'] ?? '')) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Max Price</label>
                        <input type="number" step="0.01" min="0" name="max_price" value="<?= Validator::e((string)($editSvc['max_price'] ?? '')) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Sort Order</label>
                        <input type="number" name="sort_order" value="<?= (int)($editSvc['sort_order'] ?? 0) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                    </div>
                </div>

                <div>
                    <label class="block font-bold text-slate-600 mb-1">Description</label>
                    <input type="text" name="description" value="<?= Validator::e($editSvc['description'] ?? '') ?>" placeholder="Instant one-time SMS verification" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                </div>

                <label class="flex items-center gap-2 font-bold text-slate-700 pt-1">
                    <input type="checkbox" name="is_popular" value="1" <?= !empty($editSvc['is_popular']) ? 'checked' : '' ?> class="accent-purple-600">
                    <span>Feature in Popular Services</span>
                </label>

                <button type="submit" class="w-full py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-extrabold shadow">
                    <?= $editSvc ? 'Update OTP Service' : 'Create OTP Service' ?>
                </button>
            </form>
        </div>

        <?php if ($editSvc && !empty($editSvc['provider_id'])): ?>
        <!-- Per-Country Overrides for this Service -->
        <div class="smm-card rounded-3xl p-6">
            <h3 class="text-sm font-extrabold text-slate-900 mb-3">Country Pricing &amp; Code Overrides (<?= Validator::e($editSvc['name']) ?>)</h3>
            <form method="POST" class="space-y-2.5 text-xs mb-4">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="save_mapping">
                <input type="hidden" name="service_id" value="<?= (int)$editSvc['id'] ?>">
                <input type="hidden" name="provider_id" value="<?= (int)$editSvc['provider_id'] ?>">
                <div class="grid grid-cols-2 gap-2">
                    <select name="country_id" required class="px-3 py-2 rounded-xl border border-purple-200 font-semibold">
                        <option value="">Select Country...</option>
                        <?php foreach ($countries as $ct): ?>
                        <option value="<?= (int)$ct['id'] ?>"><?= Validator::e(($ct['flag_emoji'] ? $ct['flag_emoji'] . ' ' : '') . $ct['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" step="0.01" min="0" name="custom_selling_price" placeholder="Custom price (opt)" class="px-3 py-2 rounded-xl border border-purple-200">
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <input type="text" name="provider_country_code" placeholder="Prov country code" class="px-2.5 py-2 rounded-xl border border-purple-200 font-mono">
                    <input type="text" name="provider_service_code" placeholder="Prov service code" class="px-2.5 py-2 rounded-xl border border-purple-200 font-mono">
                    <input type="text" name="provider_operator" value="any" placeholder="Operator" class="px-2.5 py-2 rounded-xl border border-purple-200 font-mono">
                </div>
                <div class="flex items-center justify-between">
                    <label class="flex items-center gap-1.5 font-bold text-slate-700">
                        <input type="checkbox" name="is_available" value="1" checked class="accent-purple-600"> Enabled for this country
                    </label>
                    <button type="submit" class="px-4 py-1.5 rounded-xl bg-slate-900 text-white font-bold">Save Override</button>
                </div>
            </form>

            <?php if (!empty($mappings)): ?>
            <div class="space-y-1.5 text-xs">
                <?php foreach ($mappings as $mp): ?>
                <div class="flex items-center justify-between p-2.5 rounded-xl bg-[#FAF8FF] border border-purple-100">
                    <div>
                        <span class="font-bold"><?= Validator::e(($mp['flag_emoji'] ? $mp['flag_emoji'] . ' ' : '') . $mp['country_name']) ?></span>
                        <span class="text-slate-500 ml-1">Op: <?= Validator::e($mp['provider_operator']) ?></span>
                        <?php if ($mp['custom_selling_price'] !== null): ?>
                        <span class="ml-1 font-bold text-purple-700"><?= Currency::display((string)$mp['custom_selling_price'], null, 2, true) ?></span>
                        <?php endif; ?>
                        <?= !$mp['is_available'] ? '<span class="ml-1 text-rose-600 font-bold">(Disabled)</span>' : '' ?>
                    </div>
                    <form method="POST">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="delete_mapping">
                        <input type="hidden" name="mapping_id" value="<?= (int)$mp['id'] ?>">
                        <input type="hidden" name="service_id" value="<?= (int)$editSvc['id'] ?>">
                        <button type="submit" class="text-rose-600 font-bold hover:underline">Remove</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Services List -->
    <div class="lg:col-span-7 smm-card rounded-3xl overflow-hidden">
        <div class="px-6 py-4 border-b border-purple-50 flex items-center justify-between">
            <h2 class="text-sm font-extrabold text-slate-900">Configured OTP Services (<?= count($services) ?>)</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-[#FAF8FF] text-slate-500 uppercase text-[10px] font-extrabold">
                    <tr>
                        <th class="px-4 py-3">Service</th>
                        <th class="px-4 py-3">Provider / Code</th>
                        <th class="px-4 py-3">Price / Margin</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-purple-50">
                    <?php if (empty($services)): ?>
                        <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">No OTP services added yet.</td></tr>
                    <?php else: foreach ($services as $s): ?>
                    <tr class="hover:bg-purple-50/30">
                        <td class="px-4 py-3">
                            <div class="font-extrabold text-slate-900"><?= Validator::e($s['name']) ?> <?= $s['is_popular'] ? '<span class="ml-1 text-[10px] text-amber-600">★</span>' : '' ?></div>
                            <div class="text-[10px] font-mono text-slate-400"><?= Validator::e($s['slug']) ?> · <?= (int)$s['act_count'] ?> orders</div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-semibold"><?= Validator::e($s['provider_name'] ?: 'Unassigned') ?></div>
                            <div class="text-[10px] font-mono text-slate-400"><?= Validator::e($s['provider_service_code'] ?: '—') ?></div>
                        </td>
                        <td class="px-4 py-3 tabular-nums">
                            <div class="font-bold"><?= Currency::cmp((string)$s['selling_price'], '0', 4) > 0 ? Currency::display((string)$s['selling_price'], null, 2, true) : 'Dynamic' ?></div>
                            <div class="text-[10px] text-slate-400">+<?= Validator::e($s['profit_margin']) ?>% margin</div>
                        </td>
                        <td class="px-4 py-3"><?= Helpers::statusBadge((string)$s['status']) ?></td>
                        <td class="px-4 py-3 text-right space-x-1 whitespace-nowrap">
                            <a href="/admin/otp-services.php?edit=<?= (int)$s['id'] ?>" class="px-2.5 py-1 rounded-lg bg-purple-50 text-purple-700 font-bold">Edit</a>
                            <form method="POST" class="inline">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" class="px-2.5 py-1 rounded-lg bg-slate-100 text-slate-700 font-bold"><?= $s['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
                            </form>
                            <form method="POST" class="inline" onsubmit="return confirm('Delete this OTP service?')">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="delete_service">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" class="px-2.5 py-1 rounded-lg bg-rose-50 text-rose-700 font-bold">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
