<?php
/**
 * Admin OTP Services & Manual Service Mapping Manager
 * Manage local OTP services, fixed/dynamic pricing rules (fixed price, % margin, fixed profit, min/max, auto price update),
 * and manual country-provider service mappings with verified availability status.
 */
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('otp.manage');
$pageTitle = 'OTP Services & Mappings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_service') {
        $id = (int)($_POST['id'] ?? 0);
        $name = Validator::str($_POST['name'] ?? '', 120);
        $slug = strtolower((string)preg_replace('/[^a-z0-9_-]+/i', '', trim((string)($_POST['slug'] ?? ''))));
        if ($slug === '' && $name !== '') {
            $slug = trim(strtolower((string)preg_replace('/[^a-z0-9]+/i', '_', trim($name))), '_');
        }
        $iconKey = Validator::str($_POST['icon_key'] ?? 'general', 60);
        $desc = Validator::str($_POST['description'] ?? '', 500);
        $providerId = !empty($_POST['provider_id']) ? (int)$_POST['provider_id'] : null;
        $provCode = Validator::str($_POST['provider_service_code'] ?? $slug, 100);
        $sellingPrice = Currency::normalize((string)($_POST['selling_price'] ?? '0'), 4);
        $profitMargin = Currency::normalize((string)($_POST['profit_margin'] ?? Helpers::setting('otp_default_profit_margin', '25.00')), 2);
        $fixedProfit = Currency::normalize((string)($_POST['fixed_profit'] ?? '0'), 4);
        $minPrice = trim((string)($_POST['min_price'] ?? '')) !== '' ? Currency::normalize((string)$_POST['min_price'], 4) : null;
        $maxPrice = trim((string)($_POST['max_price'] ?? '')) !== '' ? Currency::normalize((string)$_POST['max_price'], 4) : null;
        $autoPriceUpdate = isset($_POST['auto_price_update']) ? 1 : 0;
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
                         selling_price = ?, profit_margin = ?, fixed_profit = ?, min_price = ?, max_price = ?,
                         auto_price_update = ?, is_popular = ?, sort_order = ?, status = ?
                     WHERE id = ?",
                    [
                        $name, $slug, $iconKey, $desc, $providerId, $provCode,
                        $sellingPrice, $profitMargin, $fixedProfit, $minPrice, $maxPrice,
                        $autoPriceUpdate, $isPopular, $sortOrder, $status, $id,
                    ]
                );
                Helpers::setFlash('success', 'OTP service updated.');
            } else {
                Database::insert(
                    "INSERT INTO otp_services
                     (name, slug, icon_key, description, provider_id, provider_service_code,
                      selling_price, profit_margin, fixed_profit, min_price, max_price, auto_price_update,
                      is_popular, sort_order, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $name, $slug, $iconKey, $desc, $providerId, $provCode,
                        $sellingPrice, $profitMargin, $fixedProfit, $minPrice, $maxPrice, $autoPriceUpdate,
                        $isPopular, $sortOrder, $status,
                    ]
                );
                Helpers::setFlash('success', 'OTP service created.');
            }
        } catch (Throwable $e) {
            Helpers::setFlash('error', 'Could not save service (ensure slug is unique): ' . $e->getMessage());
        }
        Helpers::redirect('/admin/otp-services.php' . ($id > 0 ? '?edit=' . $id : ''));
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
        $provPrice = trim((string)($_POST['provider_price'] ?? '')) !== '' ? Currency::normalize((string)$_POST['provider_price'], 4) : null;
        $pricingMode = (($_POST['pricing_mode'] ?? 'auto') === 'fixed') ? 'fixed' : 'auto';
        $customPrice = trim((string)($_POST['custom_selling_price'] ?? '')) !== '' ? Currency::normalize((string)$_POST['custom_selling_price'], 4) : null;
        $mapMargin = trim((string)($_POST['profit_margin'] ?? '')) !== '' ? Currency::normalize((string)$_POST['profit_margin'], 2) : null;
        $mapFixedProfit = trim((string)($_POST['fixed_profit'] ?? '')) !== '' ? Currency::normalize((string)$_POST['fixed_profit'], 4) : null;
        $mapMin = trim((string)($_POST['min_price'] ?? '')) !== '' ? Currency::normalize((string)$_POST['min_price'], 4) : null;
        $mapMax = trim((string)($_POST['max_price'] ?? '')) !== '' ? Currency::normalize((string)$_POST['max_price'], 4) : null;
        $autoUpdate = isset($_POST['auto_price_update']) ? 1 : 0;
        $avail = isset($_POST['is_available']) ? 1 : 0;

        if ($serviceId > 0 && $countryId > 0 && $providerId > 0) {
            Database::execute(
                "INSERT INTO otp_service_provider_mappings
                 (service_id, country_id, provider_id, provider_service_code, provider_country_code, provider_operator,
                  provider_price, custom_selling_price, pricing_mode, profit_margin, fixed_profit, min_price, max_price,
                  auto_price_update, is_available, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
                 ON DUPLICATE KEY UPDATE
                   provider_service_code = VALUES(provider_service_code),
                   provider_country_code = VALUES(provider_country_code),
                   provider_operator = VALUES(provider_operator),
                   provider_price = COALESCE(VALUES(provider_price), provider_price),
                   custom_selling_price = VALUES(custom_selling_price),
                   pricing_mode = VALUES(pricing_mode),
                   profit_margin = VALUES(profit_margin),
                   fixed_profit = VALUES(fixed_profit),
                   min_price = VALUES(min_price),
                   max_price = VALUES(max_price),
                   auto_price_update = VALUES(auto_price_update),
                   is_available = VALUES(is_available),
                   status = 'active'",
                [
                    $serviceId, $countryId, $providerId, $provSvc ?: null, $provCntry ?: null, $provOp,
                    $provPrice, $customPrice, $pricingMode, $mapMargin, $mapFixedProfit, $mapMin, $mapMax,
                    $autoUpdate, $avail,
                ]
            );
            Helpers::setFlash('success', 'Service-country provider mapping saved.');
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
$editMapId = (int)($_GET['edit_map'] ?? 0);
$editMap = $editMapId > 0 ? Database::fetch("SELECT * FROM otp_service_provider_mappings WHERE id = ?", [$editMapId]) : null;

$providers = Database::fetchAll("SELECT id, name, adapter_type, currency_code, status FROM otp_providers ORDER BY name ASC");
$countries = Database::fetchAll("SELECT id, name, iso_code, flag_emoji, default_provider_code FROM otp_countries ORDER BY name ASC");
$services = Database::fetchAll(
    "SELECT s.*, p.name AS provider_name, p.currency_code AS prov_curr,
            (SELECT COUNT(*) FROM otp_activations a WHERE a.service_id = s.id) AS act_count,
            (SELECT COUNT(*) FROM otp_service_provider_mappings m WHERE m.service_id = s.id) AS map_count
     FROM otp_services s
     LEFT JOIN otp_providers p ON p.id = s.provider_id
     ORDER BY s.sort_order ASC, s.name ASC"
);
$mappings = $editSvc ? Database::fetchAll(
    "SELECT m.*, c.name AS country_name, c.iso_code, c.flag_emoji, c.default_provider_code, p.name AS provider_name, p.currency_code AS prov_curr
     FROM otp_service_provider_mappings m
     JOIN otp_countries c ON c.id = m.country_id
     JOIN otp_providers p ON p.id = m.provider_id
     WHERE m.service_id = ? ORDER BY c.name ASC",
    [$editSvc['id']]
) : [];

$iconOptions = ['whatsapp', 'telegram', 'google', 'instagram', 'facebook', 'twitter', 'tiktok', 'discord', 'amazon', 'apple', 'microsoft', 'uber', 'youtube', 'snapchat', 'general'];
$baseCode = Currency::getBaseCode();

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="grid lg:grid-cols-12 gap-6">
    <!-- Left Column: Add/Edit Service + Manual Service Mappings -->
    <div class="lg:col-span-5 space-y-6">
        <div class="smm-card rounded-3xl p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-extrabold text-slate-900"><?= $editSvc ? 'Edit OTP Service' : 'Add OTP Service' ?></h2>
                <div class="flex items-center gap-2 text-xs">
                    <a href="/admin/otp-providers.php?tab=import" class="font-bold text-indigo-600 hover:underline">Auto Import &rarr;</a>
                    <?php if ($editSvc): ?><a href="/admin/otp-services.php" class="font-bold text-purple-600">+ New</a><?php endif; ?>
                </div>
            </div>
            <form method="POST" class="space-y-3 text-xs">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="save_service">
                <input type="hidden" name="id" value="<?= (int)($editSvc['id'] ?? 0) ?>">

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Service Name *</label>
                        <input type="text" name="name" required value="<?= Validator::e($editSvc['name'] ?? '') ?>" placeholder="Instagram" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Slug *</label>
                        <input type="text" name="slug" value="<?= Validator::e($editSvc['slug'] ?? '') ?>" placeholder="instagram" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
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
                        <label class="block font-bold text-slate-600 mb-1">Provider Service ID</label>
                        <input type="text" name="provider_service_code" value="<?= Validator::e($editSvc['provider_service_code'] ?? '') ?>" placeholder="Actual API service ID" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
                    </div>
                </div>

                <?php if ($editSvc && $editSvc['provider_price'] !== null): ?>
                <div class="p-2.5 rounded-xl bg-purple-50 border border-purple-100 text-[11px] flex items-center justify-between">
                    <span class="font-bold text-purple-800">Latest Synced Provider Cost: <span class="font-mono"><?= Validator::e((string)$editSvc['provider_price'] . ' ' . ($editSvc['provider_currency'] ?: '')) ?></span></span>
                    <span class="text-[10px] text-slate-500"><?= $editSvc['last_price_sync_at'] ? date('M d, H:i', strtotime($editSvc['last_price_sync_at'])) : '' ?></span>
                </div>
                <?php endif; ?>

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

                <div class="grid grid-cols-3 gap-2">
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Selling Price (<?= Validator::e($baseCode) ?>)</label>
                        <input type="number" step="0.01" min="0" name="selling_price" value="<?= Validator::e($editSvc['selling_price'] ?? '0.00') ?>" class="w-full px-2.5 py-2 rounded-xl border border-purple-200 font-mono">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Profit Margin (%)</label>
                        <input type="number" step="0.01" min="0" name="profit_margin" value="<?= Validator::e($editSvc['profit_margin'] ?? Helpers::setting('otp_default_profit_margin', '25.00')) ?>" class="w-full px-2.5 py-2 rounded-xl border border-purple-200 font-mono">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Fixed Profit (<?= Validator::e($baseCode) ?>)</label>
                        <input type="number" step="0.01" min="0" name="fixed_profit" value="<?= Validator::e($editSvc['fixed_profit'] ?? '0.00') ?>" class="w-full px-2.5 py-2 rounded-xl border border-purple-200 font-mono">
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Min Price</label>
                        <input type="number" step="0.01" min="0" name="min_price" value="<?= Validator::e((string)($editSvc['min_price'] ?? '')) ?>" class="w-full px-2.5 py-2 rounded-xl border border-purple-200 font-mono">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Max Price</label>
                        <input type="number" step="0.01" min="0" name="max_price" value="<?= Validator::e((string)($editSvc['max_price'] ?? '')) ?>" class="w-full px-2.5 py-2 rounded-xl border border-purple-200 font-mono">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Sort Order</label>
                        <input type="number" name="sort_order" value="<?= (int)($editSvc['sort_order'] ?? 0) ?>" class="w-full px-2.5 py-2 rounded-xl border border-purple-200">
                    </div>
                </div>

                <div>
                    <label class="block font-bold text-slate-600 mb-1">Description</label>
                    <input type="text" name="description" value="<?= Validator::e($editSvc['description'] ?? '') ?>" placeholder="Instant one-time SMS verification" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                </div>

                <div class="flex flex-wrap items-center gap-4 pt-1">
                    <label class="flex items-center gap-2 font-bold text-slate-700">
                        <input type="checkbox" name="auto_price_update" value="1" <?= (!isset($editSvc['auto_price_update']) || !empty($editSvc['auto_price_update'])) ? 'checked' : '' ?> class="accent-purple-600">
                        <span>Automatic Price Update</span>
                    </label>
                    <label class="flex items-center gap-2 font-bold text-slate-700">
                        <input type="checkbox" name="is_popular" value="1" <?= !empty($editSvc['is_popular']) ? 'checked' : '' ?> class="accent-purple-600">
                        <span>Popular Service</span>
                    </label>
                </div>

                <button type="submit" class="w-full py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-extrabold shadow">
                    <?= $editSvc ? 'Update OTP Service' : 'Create OTP Service' ?>
                </button>
            </form>
        </div>

        <?php if ($editSvc && !empty($editSvc['provider_id'])): ?>
        <!-- Manual Service Mapping & Pricing Editor -->
        <div class="smm-card rounded-3xl p-6">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-extrabold text-slate-900">Service Mappings &amp; Country Pricing (<?= Validator::e($editSvc['name']) ?>)</h3>
                <?php if ($editMap): ?>
                <a href="/admin/otp-services.php?edit=<?= (int)$editSvc['id'] ?>" class="text-[11px] font-bold text-purple-600">+ New Map</a>
                <?php endif; ?>
            </div>
            <form method="POST" class="space-y-2.5 text-xs mb-4">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="save_mapping">
                <input type="hidden" name="service_id" value="<?= (int)$editSvc['id'] ?>">
                <input type="hidden" name="provider_id" value="<?= (int)$editSvc['provider_id'] ?>">

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 mb-0.5">Local Country *</label>
                        <select name="country_id" required class="w-full px-2.5 py-2 rounded-xl border border-purple-200 font-semibold bg-white">
                            <option value="">Select Country...</option>
                            <?php foreach ($countries as $ct): ?>
                            <option value="<?= (int)$ct['id'] ?>" <?= (int)($editMap['country_id'] ?? 0) === (int)$ct['id'] ? 'selected' : '' ?>><?= Validator::e(($ct['flag_emoji'] ? $ct['flag_emoji'] . ' ' : '') . $ct['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 mb-0.5">Pricing Mode</label>
                        <select name="pricing_mode" class="w-full px-2.5 py-2 rounded-xl border border-purple-200 font-semibold bg-white">
                            <option value="auto" <?= ($editMap['pricing_mode'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Dynamic (Auto)</option>
                            <option value="fixed" <?= ($editMap['pricing_mode'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed Selling Price</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 mb-0.5">Provider Country ID</label>
                        <input type="text" name="provider_country_code" value="<?= Validator::e((string)($editMap['provider_country_code'] ?? '')) ?>" placeholder="API country ID" class="w-full px-2.5 py-2 rounded-xl border border-purple-200 font-mono">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 mb-0.5">Provider Service ID</label>
                        <input type="text" name="provider_service_code" value="<?= Validator::e((string)($editMap['provider_service_code'] ?? '')) ?>" placeholder="API service ID" class="w-full px-2.5 py-2 rounded-xl border border-purple-200 font-mono">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 mb-0.5">Operator</label>
                        <input type="text" name="provider_operator" value="<?= Validator::e((string)($editMap['provider_operator'] ?? 'any')) ?>" placeholder="any" class="w-full px-2.5 py-2 rounded-xl border border-purple-200 font-mono">
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 mb-0.5">Provider Price</label>
                        <input type="number" step="0.0001" min="0" name="provider_price" value="<?= Validator::e((string)($editMap['provider_price'] ?? '')) ?>" placeholder="Synced cost" class="w-full px-2.5 py-2 rounded-xl border border-purple-200 font-mono">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 mb-0.5">Fixed/Selling (<?= Validator::e($baseCode) ?>)</label>
                        <input type="number" step="0.01" min="0" name="custom_selling_price" value="<?= Validator::e((string)($editMap['custom_selling_price'] ?? '')) ?>" placeholder="Auto or fixed" class="w-full px-2.5 py-2 rounded-xl border border-purple-200 font-mono">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 mb-0.5">Margin (%)</label>
                        <input type="number" step="0.01" min="0" name="profit_margin" value="<?= Validator::e((string)($editMap['profit_margin'] ?? '')) ?>" placeholder="Inherit" class="w-full px-2.5 py-2 rounded-xl border border-purple-200 font-mono">
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 mb-0.5">Fixed Profit</label>
                        <input type="number" step="0.01" min="0" name="fixed_profit" value="<?= Validator::e((string)($editMap['fixed_profit'] ?? '')) ?>" placeholder="0.00" class="w-full px-2.5 py-2 rounded-xl border border-purple-200 font-mono">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 mb-0.5">Min Price</label>
                        <input type="number" step="0.01" min="0" name="min_price" value="<?= Validator::e((string)($editMap['min_price'] ?? '')) ?>" placeholder="Optional" class="w-full px-2.5 py-2 rounded-xl border border-purple-200 font-mono">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 mb-0.5">Max Price</label>
                        <input type="number" step="0.01" min="0" name="max_price" value="<?= Validator::e((string)($editMap['max_price'] ?? '')) ?>" placeholder="Optional" class="w-full px-2.5 py-2 rounded-xl border border-purple-200 font-mono">
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
                    <div class="flex items-center gap-3">
                        <label class="flex items-center gap-1.5 font-bold text-slate-700">
                            <input type="checkbox" name="is_available" value="1" <?= (!isset($editMap['is_available']) || !empty($editMap['is_available'])) ? 'checked' : '' ?> class="accent-purple-600"> Enabled
                        </label>
                        <label class="flex items-center gap-1.5 font-bold text-slate-700">
                            <input type="checkbox" name="auto_price_update" value="1" <?= (!isset($editMap['auto_price_update']) || !empty($editMap['auto_price_update'])) ? 'checked' : '' ?> class="accent-purple-600"> Auto Price Sync
                        </label>
                    </div>
                    <button type="submit" class="px-4 py-1.5 rounded-xl bg-slate-900 text-white font-bold">Save Mapping</button>
                </div>
            </form>

            <?php if (!empty($mappings)): ?>
            <div class="space-y-1.5 text-xs max-h-80 overflow-y-auto">
                <?php foreach ($mappings as $mp): ?>
                <div class="flex items-center justify-between gap-2 p-2.5 rounded-xl bg-[#FAF8FF] border border-purple-100">
                    <div class="min-w-0">
                        <div class="font-bold text-slate-900 truncate">
                            <?= Validator::e(($mp['flag_emoji'] ? $mp['flag_emoji'] . ' ' : '') . $mp['country_name']) ?>
                            <span class="font-mono text-[10px] text-slate-400">
                                [C:<?= Validator::e($mp['provider_country_code'] ?: $mp['default_provider_code']) ?> / S:<?= Validator::e($mp['provider_service_code'] ?: $editSvc['provider_service_code']) ?> / Op:<?= Validator::e($mp['provider_operator']) ?>]
                            </span>
                        </div>
                        <div class="text-[10px] text-slate-600 mt-0.5 flex flex-wrap items-center gap-2">
                            <?php if ($mp['provider_price'] !== null): ?>
                            <span>Cost: <strong class="font-mono"><?= Validator::e((string)$mp['provider_price'] . ' ' . $mp['prov_curr']) ?></strong></span>
                            <?php endif; ?>
                            <?php if ($mp['custom_selling_price'] !== null): ?>
                            <span>Sell: <strong class="font-mono text-purple-700"><?= Currency::formatBase((string)$mp['custom_selling_price'], 2, true) ?></strong> (<?= Validator::e($mp['pricing_mode']) ?>)</span>
                            <?php endif; ?>
                            <?php if (!empty($mp['availability_verified'])): ?>
                            <span class="<?= (int)$mp['available_count'] > 0 ? 'text-emerald-700 font-bold' : 'text-rose-600 font-bold' ?>">Verified Stock: <?= (int)$mp['available_count'] ?></span>
                            <?php else: ?>
                            <span class="text-amber-600 font-semibold">Availability Unverified</span>
                            <?php endif; ?>
                            <?= !$mp['is_available'] ? '<span class="text-rose-600 font-extrabold">(Disabled)</span>' : '' ?>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <a href="/admin/otp-services.php?edit=<?= (int)$editSvc['id'] ?>&amp;edit_map=<?= (int)$mp['id'] ?>" class="text-purple-700 font-bold hover:underline">Edit</a>
                        <form method="POST">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="delete_mapping">
                            <input type="hidden" name="mapping_id" value="<?= (int)$mp['id'] ?>">
                            <input type="hidden" name="service_id" value="<?= (int)$editSvc['id'] ?>">
                            <button type="submit" class="text-rose-600 font-bold hover:underline">Remove</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right Column: Configured Services List -->
    <div class="lg:col-span-7 smm-card rounded-3xl overflow-hidden">
        <div class="px-6 py-4 border-b border-purple-50 flex items-center justify-between">
            <h2 class="text-sm font-extrabold text-slate-900">Configured OTP Services (<?= count($services) ?>)</h2>
            <a href="/admin/otp-providers.php?tab=import" class="px-3 py-1.5 rounded-xl bg-purple-600 text-white text-xs font-extrabold hover:bg-purple-700">Auto Import from Provider</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-[#FAF8FF] text-slate-500 uppercase text-[10px] font-extrabold">
                    <tr>
                        <th class="px-4 py-3">Service</th>
                        <th class="px-4 py-3">Provider / API ID</th>
                        <th class="px-4 py-3">Provider Cost / Sell</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-purple-50">
                    <?php if (empty($services)): ?>
                        <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">No OTP services added yet. Use Auto Import or add manually on the left.</td></tr>
                    <?php else: foreach ($services as $s): ?>
                    <tr class="hover:bg-purple-50/30">
                        <td class="px-4 py-3">
                            <div class="font-extrabold text-slate-900"><?= Validator::e($s['name']) ?> <?= $s['is_popular'] ? '<span class="ml-1 text-[10px] text-amber-600">★</span>' : '' ?></div>
                            <div class="text-[10px] font-mono text-slate-400"><?= Validator::e($s['slug']) ?> · <?= (int)$s['map_count'] ?> countries · <?= (int)$s['act_count'] ?> orders</div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-semibold"><?= Validator::e($s['provider_name'] ?: 'Unassigned') ?></div>
                            <div class="text-[10px] font-mono text-slate-400">ID: <?= Validator::e($s['provider_service_code'] ?: '—') ?></div>
                        </td>
                        <td class="px-4 py-3 tabular-nums">
                            <div class="font-bold text-purple-800"><?= Currency::cmp((string)$s['selling_price'], '0', 4) > 0 ? Currency::formatBase((string)$s['selling_price'], 2, true) : 'Dynamic' ?></div>
                            <div class="text-[10px] text-slate-500">
                                <?php if ($s['provider_price'] !== null): ?>
                                Cost: <?= Validator::e((string)$s['provider_price'] . ' ' . ($s['provider_currency'] ?: $s['prov_curr'])) ?> ·
                                <?php endif; ?>
                                +<?= Validator::e($s['profit_margin']) ?>%<?= Currency::cmp((string)($s['fixed_profit'] ?? '0'), '0', 4) > 0 ? (' +' . Currency::formatBase((string)$s['fixed_profit'], 2)) : '' ?>
                            </div>
                        </td>
                        <td class="px-4 py-3"><?= Helpers::statusBadge((string)$s['status']) ?></td>
                        <td class="px-4 py-3 text-right space-x-1 whitespace-nowrap">
                            <a href="/admin/otp-services.php?edit=<?= (int)$s['id'] ?>" class="px-2.5 py-1 rounded-lg bg-purple-50 text-purple-700 font-bold">Edit / Map</a>
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
