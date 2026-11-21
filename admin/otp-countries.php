<?php
/**
 * Admin OTP Countries Manager
 * Add, edit, enable/disable, safe-delete countries, or sync real country lists from an active provider (e.g. 5SIM).
 */
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('otp.manage');
$pageTitle = 'OTP Countries';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_country') {
        $id = (int)($_POST['id'] ?? 0);
        $name = Validator::str($_POST['name'] ?? '', 120);
        $iso = strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim((string)($_POST['iso_code'] ?? ''))));
        $dial = Validator::str($_POST['dial_code'] ?? '', 15);
        $flag = Validator::str($_POST['flag_emoji'] ?? '', 16);
        $provCode = strtolower(Validator::str($_POST['default_provider_code'] ?? '', 80));
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $status = (($_POST['status'] ?? 'active') === 'inactive') ? 'inactive' : 'active';

        if ($name === '' || $iso === '' || $provCode === '') {
            Helpers::setFlash('error', 'Name, ISO code, and default provider country code are required.');
            Helpers::redirect('/admin/otp-countries.php');
        }

        try {
            if ($id > 0) {
                Database::execute(
                    "UPDATE otp_countries SET name = ?, iso_code = ?, dial_code = ?, flag_emoji = ?, default_provider_code = ?, sort_order = ?, status = ? WHERE id = ?",
                    [$name, $iso, $dial, $flag, $provCode, $sortOrder, $status, $id]
                );
                Helpers::setFlash('success', 'Country updated.');
            } else {
                Database::insert(
                    "INSERT INTO otp_countries (name, iso_code, dial_code, flag_emoji, default_provider_code, sort_order, status) VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$name, $iso, $dial, $flag, $provCode, $sortOrder, $status]
                );
                Helpers::setFlash('success', 'Country added.');
            }
        } catch (Throwable $e) {
            Helpers::setFlash('error', 'Could not save country (ISO code must be unique): ' . $e->getMessage());
        }
        Helpers::redirect('/admin/otp-countries.php');
    } elseif ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        $c = Database::fetch("SELECT id, status FROM otp_countries WHERE id = ?", [$id]);
        if ($c) {
            $next = $c['status'] === 'active' ? 'inactive' : 'active';
            Database::execute("UPDATE otp_countries SET status = ? WHERE id = ?", [$next, $id]);
            Helpers::setFlash('success', 'Country status set to ' . $next . '.');
        }
        Helpers::redirect('/admin/otp-countries.php');
    } elseif ($action === 'delete_country') {
        $id = (int)($_POST['id'] ?? 0);
        $used = (int)Database::fetchColumn("SELECT COUNT(*) FROM otp_activations WHERE country_id = ?", [$id]);
        if ($used > 0) {
            Helpers::setFlash('error', "Cannot delete country with {$used} activation record(s). Disable it instead.");
        } else {
            Database::execute("DELETE FROM otp_countries WHERE id = ?", [$id]);
            Helpers::setFlash('success', 'Country deleted.');
        }
        Helpers::redirect('/admin/otp-countries.php');
    } elseif ($action === 'sync_from_provider') {
        $pid = (int)($_POST['provider_id'] ?? 0);
        $prov = Database::fetch("SELECT * FROM otp_providers WHERE id = ? LIMIT 1", [$pid]);
        if (!$prov) {
            Helpers::setFlash('error', 'Please select a provider to import countries from.');
            Helpers::redirect('/admin/otp-countries.php');
        }
        try {
            $adapter = OtpProviderFactory::make($prov);
            $res = $adapter->getCountries();
            if (empty($res['ok']) || empty($res['countries'])) {
                Helpers::setFlash('error', $res['error'] ?? 'Provider did not return a country list.');
            } else {
                $imported = 0;
                foreach ($res['countries'] as $ct) {
                    $iso = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', (string)($ct['iso_code'] ?? '')), 0, 10));
                    $code = strtolower(trim((string)($ct['code'] ?? '')));
                    $cname = trim((string)($ct['name'] ?? $code));
                    if ($iso === '' || $code === '') continue;
                    $exists = Database::fetchColumn("SELECT id FROM otp_countries WHERE iso_code = ? LIMIT 1", [$iso]);
                    if (!$exists) {
                        Database::insert(
                            "INSERT INTO otp_countries (name, iso_code, dial_code, default_provider_code, status) VALUES (?, ?, ?, ?, 'active')",
                            [$cname, $iso, (string)($ct['dial_code'] ?? ''), $code]
                        );
                        $imported++;
                    }
                }
                Helpers::setFlash('success', "Imported {$imported} new countries from " . $prov['name'] . '.');
            }
        } catch (Throwable $e) {
            Helpers::setFlash('error', 'Country import failed: ' . $e->getMessage());
        }
        Helpers::redirect('/admin/otp-countries.php');
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$editCountry = $editId > 0 ? Database::fetch("SELECT * FROM otp_countries WHERE id = ?", [$editId]) : null;
$providers = Database::fetchAll("SELECT id, name, adapter_type FROM otp_providers WHERE status = 'active' ORDER BY name ASC");
$countries = Database::fetchAll(
    "SELECT c.*, (SELECT COUNT(*) FROM otp_activations a WHERE a.country_id = c.id) AS act_count
     FROM otp_countries c ORDER BY c.sort_order ASC, c.name ASC"
);

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="grid lg:grid-cols-12 gap-6">
    <div class="lg:col-span-4 space-y-6">
        <div class="smm-card rounded-3xl p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-extrabold text-slate-900"><?= $editCountry ? 'Edit Country' : 'Add OTP Country' ?></h2>
                <?php if ($editCountry): ?><a href="/admin/otp-countries.php" class="text-xs font-bold text-purple-600">+ New</a><?php endif; ?>
            </div>
            <form method="POST" class="space-y-3 text-xs">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="save_country">
                <input type="hidden" name="id" value="<?= (int)($editCountry['id'] ?? 0) ?>">

                <div>
                    <label class="block font-bold text-slate-600 mb-1">Country Name *</label>
                    <input type="text" name="name" required value="<?= Validator::e($editCountry['name'] ?? '') ?>" placeholder="India" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">ISO Code *</label>
                        <input type="text" name="iso_code" required value="<?= Validator::e($editCountry['iso_code'] ?? '') ?>" placeholder="IN" class="w-full px-3 py-2 rounded-xl border border-purple-200 uppercase font-mono">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Dial Code</label>
                        <input type="text" name="dial_code" value="<?= Validator::e($editCountry['dial_code'] ?? '') ?>" placeholder="+91" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Flag Emoji</label>
                        <input type="text" name="flag_emoji" value="<?= Validator::e($editCountry['flag_emoji'] ?? '') ?>" placeholder="🇮🇳" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Provider Country Code *</label>
                        <input type="text" name="default_provider_code" required value="<?= Validator::e($editCountry['default_provider_code'] ?? '') ?>" placeholder="india" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Sort / Status</label>
                        <div class="flex gap-1.5">
                            <input type="number" name="sort_order" value="<?= (int)($editCountry['sort_order'] ?? 0) ?>" class="w-16 px-2 py-2 rounded-xl border border-purple-200">
                            <select name="status" class="flex-1 px-2 py-2 rounded-xl border border-purple-200 font-semibold">
                                <option value="active" <?= ($editCountry['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= ($editCountry['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <button type="submit" class="w-full py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-extrabold shadow">
                    <?= $editCountry ? 'Update Country' : 'Add Country' ?>
                </button>
            </form>
        </div>

        <?php if (!empty($providers)): ?>
        <div class="smm-card rounded-3xl p-6">
            <h3 class="text-sm font-extrabold text-slate-900 mb-2">Import Countries From Provider</h3>
            <p class="text-xs text-slate-500 mb-3">Fetch real supported countries directly from an active OTP provider (e.g. 5SIM) without creating duplicates.</p>
            <form method="POST" class="space-y-2.5 text-xs">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="sync_from_provider">
                <select name="provider_id" required class="w-full px-3 py-2 rounded-xl border border-purple-200 font-semibold">
                    <?php foreach ($providers as $p): ?>
                    <option value="<?= (int)$p['id'] ?>"><?= Validator::e($p['name']) ?> (<?= Validator::e($p['adapter_type']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="w-full py-2.5 rounded-xl bg-slate-900 text-white font-extrabold">Fetch &amp; Import Countries</button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <div class="lg:col-span-8 smm-card rounded-3xl overflow-hidden">
        <div class="px-6 py-4 border-b border-purple-50">
            <h2 class="text-sm font-extrabold text-slate-900">Configured Countries (<?= count($countries) ?>)</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-[#FAF8FF] text-slate-500 uppercase text-[10px] font-extrabold">
                    <tr>
                        <th class="px-4 py-3">Country</th>
                        <th class="px-4 py-3">ISO / Dial</th>
                        <th class="px-4 py-3">Provider Code</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-purple-50">
                    <?php if (empty($countries)): ?>
                        <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">No countries configured yet.</td></tr>
                    <?php else: foreach ($countries as $c): ?>
                    <tr class="hover:bg-purple-50/30">
                        <td class="px-4 py-3 font-extrabold text-slate-900">
                            <?= Validator::e(($c['flag_emoji'] ? $c['flag_emoji'] . ' ' : '') . $c['name']) ?>
                            <span class="text-[10px] font-normal text-slate-400 ml-1">(<?= (int)$c['act_count'] ?> orders)</span>
                        </td>
                        <td class="px-4 py-3 font-mono"><?= Validator::e($c['iso_code']) ?> <?= Validator::e($c['dial_code']) ?></td>
                        <td class="px-4 py-3 font-mono text-purple-700 font-bold"><?= Validator::e($c['default_provider_code']) ?></td>
                        <td class="px-4 py-3"><?= Helpers::statusBadge((string)$c['status']) ?></td>
                        <td class="px-4 py-3 text-right space-x-1 whitespace-nowrap">
                            <a href="/admin/otp-countries.php?edit=<?= (int)$c['id'] ?>" class="px-2.5 py-1 rounded-lg bg-purple-50 text-purple-700 font-bold">Edit</a>
                            <form method="POST" class="inline">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button type="submit" class="px-2.5 py-1 rounded-lg bg-slate-100 text-slate-700 font-bold"><?= $c['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
                            </form>
                            <form method="POST" class="inline" onsubmit="return confirm('Delete this country?')">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="delete_country">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
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
