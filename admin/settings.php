<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('settings.manage');
$pageTitle = 'System Settings, Currencies & Database Backup';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_general') {
        $tz = trim((string)($_POST['timezone'] ?? 'Asia/Kolkata'));
        if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            $tz = 'Asia/Kolkata';
        }
        // Clean structured save
        Helpers::setSetting('site_name', Validator::str($_POST['site_name'] ?? 'SMM PRO', 120), 'general');
        Helpers::setSetting('site_tagline', Validator::str($_POST['site_tagline'] ?? '', 180), 'general');
        Helpers::setSetting('support_email', Validator::email($_POST['support_email'] ?? '') ?? '', 'general');
        Helpers::setSetting('timezone', $tz, 'general');
        Helpers::setSetting('default_profit_margin', Validator::decimal($_POST['default_profit_margin'] ?? '30.00', 2) ?? '30.00', 'pricing');
        Helpers::setSetting('maintenance_mode', isset($_POST['maintenance_mode']) ? '1' : '0', 'system');
        Helpers::setSetting('maintenance_message', Validator::str($_POST['maintenance_message'] ?? '', 500), 'system');
        Helpers::setSetting('registration_enabled', isset($_POST['registration_enabled']) ? '1' : '0', 'auth');
        Helpers::setSetting('email_verification_required', isset($_POST['email_verification_required']) ? '1' : '0', 'auth');
        Helpers::setSetting('child_panel_price', Validator::decimal($_POST['child_panel_price'] ?? '999.00', 2) ?? '999.00', 'child_panel');
        Helpers::setSetting('child_panel_auto_approve', isset($_POST['child_panel_auto_approve']) ? '1' : '0', 'child_panel');

        if (!empty($_FILES['site_logo']['name'])) {
            $up = Uploader::uploadImage($_FILES['site_logo'], 'branding', 2097152);
            if ($up['ok']) {
                Helpers::setSetting('site_logo', $up['path'], 'general');
            } else {
                Helpers::setFlash('error', 'Logo upload failed: ' . $up['error']);
            }
        }
        if (!empty($_FILES['site_favicon']['name'])) {
            $up = Uploader::uploadImage($_FILES['site_favicon'], 'branding', 1048576);
            if ($up['ok']) {
                Helpers::setSetting('site_favicon', $up['path'], 'general');
            } else {
                Helpers::setFlash('error', 'Favicon upload failed: ' . $up['error']);
            }
        }

        Logger::activity('admin', (int)$admin['id'], 'settings.general_update', 'settings', 'general', 'Updated general, pricing, auth & branding settings');
        Helpers::setFlash('success', 'General settings saved.');
        Helpers::redirect('/admin/settings.php');
    }

    if ($action === 'save_smtp') {
        Helpers::setSetting('smtp_host', Validator::str($_POST['smtp_host'] ?? '', 180), 'smtp');
        Helpers::setSetting('smtp_port', (string)max(1, (int)($_POST['smtp_port'] ?? 587)), 'smtp');
        Helpers::setSetting('smtp_user', Validator::str($_POST['smtp_user'] ?? '', 180), 'smtp');
        if (trim((string)($_POST['smtp_pass'] ?? '')) !== '') {
            Helpers::setSetting('smtp_pass', Helpers::encryptSecret((string)$_POST['smtp_pass']), 'smtp');
        }
        $enc = in_array($_POST['smtp_encryption'] ?? 'tls', ['tls', 'ssl', 'none'], true) ? $_POST['smtp_encryption'] : 'tls';
        Helpers::setSetting('smtp_encryption', $enc, 'smtp');
        Helpers::setSetting('smtp_from_email', Validator::email($_POST['smtp_from_email'] ?? '') ?? '', 'smtp');
        Helpers::setSetting('smtp_from_name', Validator::str($_POST['smtp_from_name'] ?? 'SMM PRO', 120), 'smtp');

        Logger::activity('admin', (int)$admin['id'], 'settings.smtp_update', 'settings', 'smtp', 'Updated SMTP mail configuration');
        Helpers::setFlash('success', 'SMTP configuration saved.');
        Helpers::redirect('/admin/settings.php');
    }

    if ($action === 'test_smtp') {
        $to = Validator::email($_POST['test_email'] ?? $admin['email']);
        if (!$to) {
            Helpers::setFlash('error', 'Enter a valid recipient email for the SMTP test.');
            Helpers::redirect('/admin/settings.php');
        }
        $res = Mailer::send($to, 'SMTP Configuration Test — ' . Helpers::setting('site_name', 'SMM PRO'), '<p>Your SMTP email settings are working properly.</p>');
        if ($res['ok']) {
            Helpers::setFlash('success', "Test email sent successfully to {$to}.");
        } else {
            Helpers::setFlash('error', 'SMTP test failed: ' . ($res['error'] ?? 'Unknown error'));
        }
        Helpers::redirect('/admin/settings.php');
    }

    if ($action === 'save_seo') {
        Helpers::setSetting('seo_title', Validator::str($_POST['seo_title'] ?? '', 180), 'seo');
        Helpers::setSetting('seo_description', Validator::str($_POST['seo_description'] ?? '', 350), 'seo');
        Helpers::setSetting('seo_keywords', Validator::str($_POST['seo_keywords'] ?? '', 300), 'seo');
        Helpers::setSetting('social_instagram', Validator::url($_POST['social_instagram'] ?? '') ?? '', 'social');
        Helpers::setSetting('social_telegram', Validator::url($_POST['social_telegram'] ?? '') ?? '', 'social');
        Helpers::setSetting('social_youtube', Validator::url($_POST['social_youtube'] ?? '') ?? '', 'social');
        Helpers::setSetting('social_twitter', Validator::url($_POST['social_twitter'] ?? '') ?? '', 'social');

        Logger::activity('admin', (int)$admin['id'], 'settings.seo_update', 'settings', 'seo', 'Updated SEO & social links');
        Helpers::setFlash('success', 'SEO & Social settings saved.');
        Helpers::redirect('/admin/settings.php');
    }

    if ($action === 'save_currency') {
        $code = strtoupper(preg_replace('/[^A-Z]/', '', (string)($_POST['code'] ?? '')));
        $name = Validator::str($_POST['name'] ?? '', 60);
        $symbol = Validator::str($_POST['symbol'] ?? '', 10);
        $rate = Validator::decimal($_POST['exchange_rate'] ?? '1.000000', 6);
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $setBase = isset($_POST['is_base']);

        if (strlen($code) < 2 || $name === '' || $symbol === '' || !$rate || Currency::cmp($rate, '0', 6) <= 0) {
            Helpers::setFlash('error', 'Provide valid currency code, name, symbol, and positive exchange rate.');
            Helpers::redirect('/admin/settings.php');
        }

        if ($setBase) {
            ExchangeRateService::switchBaseCurrency($code, (int)$admin['id']);
            Helpers::setFlash('success', "Currency {$code} set as platform base.");
            Helpers::redirect('/admin/settings.php');
        }

        Database::execute(
            "INSERT INTO currencies (code, name, symbol, exchange_rate, is_base, is_manual_override, rate_source, update_status, last_updated_at, status)
             VALUES (?, ?, ?, ?, 0, 1, 'manual', 'manual', NOW(), ?)
             ON DUPLICATE KEY UPDATE name=VALUES(name), symbol=VALUES(symbol), exchange_rate=VALUES(exchange_rate), is_manual_override=1, rate_source='manual', update_status='manual', last_updated_at=NOW(), status=VALUES(status)",
            [$code, $name, $symbol, $rate, $status]
        );
        Currency::clearCache();
        Logger::activity('admin', (int)$admin['id'], 'currency.save', 'currency', $code, "Saved currency {$code} ({$symbol})");
        Helpers::setFlash('success', "Currency {$code} saved.");
        Helpers::redirect('/admin/settings.php');
    }

    if ($action === 'download_backup') {
        $pdo = Database::pdo();
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $dump = "-- SMM PRO Panel SQL Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

        foreach ($tables as $table) {
            $safeTable = str_replace('`', '', $table);
            $createRow = $pdo->query("SHOW CREATE TABLE `{$safeTable}`")->fetch(PDO::FETCH_ASSOC);
            $createSql = $createRow['Create Table'] ?? array_values($createRow)[1] ?? '';
            $dump .= "DROP TABLE IF EXISTS `{$safeTable}`;\n{$createSql};\n\n";

            $stmt = $pdo->query("SELECT * FROM `{$safeTable}`");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $cols = array_map(fn($c) => "`" . str_replace('`', '', $c) . "`", array_keys($row));
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($row));
                $dump .= "INSERT INTO `{$safeTable}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
            }
            $dump .= "\n";
        }
        $dump .= "SET FOREIGN_KEY_CHECKS = 1;\n";

        if (!is_dir(BACKUPS_PATH)) {
            @mkdir(BACKUPS_PATH, 0755, true);
        }
        $filename = 'smmpro_backup_' . date('Ymd_His') . '.sql';
        @file_put_contents(BACKUPS_PATH . '/' . $filename, $dump);
        Logger::activity('admin', (int)$admin['id'], 'system.backup', 'database', $filename, 'Generated & downloaded SQL backup');

        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $dump;
        exit;
    }
}

$currencies = Database::fetchAll("SELECT * FROM currencies ORDER BY is_base DESC, code ASC");
$mysqlVersion = (string)Database::fetchColumn("SELECT VERSION()");
$storageWritable = is_writable(STORAGE_PATH);
$diskFreeGb = function_exists('disk_free_space') ? round(@disk_free_space(APP_ROOT) / 1073741824, 2) : null;

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
    <!-- General & Branding -->
    <div class="xl:col-span-2 smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-4">General, Branding &amp; Platform Rules</h2>
        <form method="POST" enctype="multipart/form-data" class="space-y-4 text-xs">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="save_general">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Website Name</label>
                    <input type="text" name="site_name" value="<?= Validator::e(Helpers::setting('site_name', 'SMM PRO')) ?>" required class="w-full px-3.5 py-2.5 rounded-xl border border-purple-100">
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Tagline</label>
                    <input type="text" name="site_tagline" value="<?= Validator::e(Helpers::setting('site_tagline', 'Grow Faster, Smarter')) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-100">
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Support Email</label>
                    <input type="email" name="support_email" value="<?= Validator::e(Helpers::setting('support_email', '')) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-100">
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Timezone</label>
                    <select name="timezone" class="w-full px-3 py-2.5 rounded-xl border border-purple-100">
                        <?php $curTz = Helpers::setting('timezone', 'Asia/Kolkata');
                        foreach (['Asia/Kolkata', 'UTC', 'America/New_York', 'Europe/London', 'Asia/Dubai', 'Asia/Singapore'] as $tz): ?>
                        <option value="<?= $tz ?>" <?= $curTz === $tz ? 'selected' : '' ?>><?= $tz ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Default Profit Margin (%)</label>
                    <input type="number" step="0.01" name="default_profit_margin" value="<?= Validator::e(Helpers::setting('default_profit_margin', '30.00')) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-100">
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Child Panel Monthly Price</label>
                    <input type="number" step="0.01" name="child_panel_price" value="<?= Validator::e(Helpers::setting('child_panel_price', '999.00')) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-100">
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Upload Website Logo</label>
                    <input type="file" name="site_logo" accept="image/*" class="w-full px-3 py-2 rounded-xl border border-purple-100 bg-slate-50">
                    <?php if (Helpers::setting('site_logo') !== ''): ?>
                        <div class="mt-1 text-[11px] text-emerald-700">Current: <?= Validator::e(Helpers::setting('site_logo')) ?></div>
                    <?php endif; ?>
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Upload Favicon</label>
                    <input type="file" name="site_favicon" accept="image/*" class="w-full px-3 py-2 rounded-xl border border-purple-100 bg-slate-50">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
                <label class="flex items-center gap-2 p-3 rounded-xl bg-slate-50 border border-slate-100 cursor-pointer">
                    <input type="checkbox" name="registration_enabled" <?= Helpers::setting('registration_enabled', '1') === '1' ? 'checked' : '' ?> class="rounded text-purple-600">
                    <span class="font-bold text-slate-700">Allow New User Registration</span>
                </label>
                <label class="flex items-center gap-2 p-3 rounded-xl bg-slate-50 border border-slate-100 cursor-pointer">
                    <input type="checkbox" name="email_verification_required" <?= Helpers::setting('email_verification_required', '0') === '1' ? 'checked' : '' ?> class="rounded text-purple-600">
                    <span class="font-bold text-slate-700">Require Email Verification</span>
                </label>
                <label class="flex items-center gap-2 p-3 rounded-xl bg-slate-50 border border-slate-100 cursor-pointer">
                    <input type="checkbox" name="child_panel_auto_approve" <?= Helpers::setting('child_panel_auto_approve', '0') === '1' ? 'checked' : '' ?> class="rounded text-purple-600">
                    <span class="font-bold text-slate-700">Auto-Approve Child Panels</span>
                </label>
                <label class="flex items-center gap-2 p-3 rounded-xl bg-amber-50 border border-amber-200 cursor-pointer">
                    <input type="checkbox" name="maintenance_mode" <?= Helpers::setting('maintenance_mode', '0') === '1' ? 'checked' : '' ?> class="rounded text-amber-600">
                    <span class="font-bold text-amber-900">Maintenance Mode</span>
                </label>
            </div>

            <div>
                <label class="block font-bold text-slate-600 mb-1">Maintenance Message</label>
                <input type="text" name="maintenance_message" value="<?= Validator::e(Helpers::setting('maintenance_message', '')) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-100">
            </div>

            <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-purple-600 to-violet-600 text-white font-bold shadow">Save General Settings</button>
        </form>
    </div>

    <!-- System Info & Backup -->
    <div class="space-y-6">
        <div class="smm-card rounded-3xl p-6">
            <h2 class="text-base font-extrabold text-slate-900 mb-3">Database Backup (.sql)</h2>
            <p class="text-xs text-slate-500 mb-4">Exports complete schema and data of all tables into `/storage/backups/` and downloads an `.sql` snapshot.</p>
            <form method="POST">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="download_backup">
                <button type="submit" class="w-full py-2.5 rounded-xl bg-emerald-600 text-white text-xs font-bold shadow hover:bg-emerald-700">Generate &amp; Download SQL Backup</button>
            </form>
        </div>

        <div class="smm-card rounded-3xl p-6 text-xs space-y-2">
            <h2 class="text-base font-extrabold text-slate-900 mb-3">System Environment</h2>
            <div class="flex justify-between py-1 border-b border-slate-100"><span class="text-slate-500">PHP Version</span><span class="font-bold"><?= PHP_VERSION ?></span></div>
            <div class="flex justify-between py-1 border-b border-slate-100"><span class="text-slate-500">MySQL / MariaDB</span><span class="font-bold"><?= Validator::e($mysqlVersion) ?></span></div>
            <div class="flex justify-between py-1 border-b border-slate-100"><span class="text-slate-500">PDO MySQL / cURL / OpenSSL</span><span class="font-bold text-emerald-600"><?= (extension_loaded('pdo_mysql') && extension_loaded('curl') && extension_loaded('openssl')) ? 'Loaded' : 'Missing' ?></span></div>
            <div class="flex justify-between py-1 border-b border-slate-100"><span class="text-slate-500">Storage Writable</span><span class="font-bold <?= $storageWritable ? 'text-emerald-600' : 'text-rose-600' ?>"><?= $storageWritable ? 'Yes' : 'No' ?></span></div>
            <div class="flex justify-between py-1"><span class="text-slate-500">Disk Free Space</span><span class="font-bold"><?= $diskFreeGb !== null ? $diskFreeGb . ' GB' : 'N/A' ?></span></div>
        </div>
    </div>
</div>

<!-- Currencies, SMTP, SEO -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- SMTP -->
    <div class="smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-4">SMTP Email Configuration</h2>
        <form method="POST" class="space-y-3 text-xs">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="save_smtp">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-600 mb-1">SMTP Host</label>
                    <input type="text" name="smtp_host" value="<?= Validator::e(Helpers::setting('smtp_host', '')) ?>" placeholder="smtp.example.com" class="w-full px-3 py-2 rounded-xl border border-purple-100">
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">SMTP Port</label>
                    <input type="number" name="smtp_port" value="<?= Validator::e(Helpers::setting('smtp_port', '587')) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-100">
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">SMTP Username</label>
                    <input type="text" name="smtp_user" value="<?= Validator::e(Helpers::setting('smtp_user', '')) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-100">
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">SMTP Password (leave blank to keep)</label>
                    <input type="password" name="smtp_pass" placeholder="••••••••" class="w-full px-3 py-2 rounded-xl border border-purple-100">
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Encryption</label>
                    <select name="smtp_encryption" class="w-full px-3 py-2 rounded-xl border border-purple-100">
                        <?php $enc = Helpers::setting('smtp_encryption', 'tls'); ?>
                        <option value="tls" <?= $enc === 'tls' ? 'selected' : '' ?>>TLS</option>
                        <option value="ssl" <?= $enc === 'ssl' ? 'selected' : '' ?>>SSL</option>
                        <option value="none" <?= $enc === 'none' ? 'selected' : '' ?>>None</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">From Email</label>
                    <input type="email" name="smtp_from_email" value="<?= Validator::e(Helpers::setting('smtp_from_email', '')) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-100">
                </div>
            </div>
            <div>
                <label class="block font-bold text-slate-600 mb-1">From Name</label>
                <input type="text" name="smtp_from_name" value="<?= Validator::e(Helpers::setting('smtp_from_name', 'SMM PRO')) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-100">
            </div>
            <button type="submit" class="px-5 py-2.5 rounded-xl bg-purple-600 text-white font-bold">Save SMTP Settings</button>
        </form>

        <form method="POST" class="mt-4 pt-4 border-t border-slate-100 flex items-center gap-2 text-xs">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="test_smtp">
            <input type="email" name="test_email" value="<?= Validator::e($admin['email']) ?>" required class="flex-1 px-3 py-2 rounded-xl border border-purple-100">
            <button type="submit" class="px-4 py-2 rounded-xl bg-slate-900 text-white font-bold">Send Test Email</button>
        </form>
    </div>

    <!-- SEO & Social -->
    <div class="smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-4">SEO &amp; Social Media Links</h2>
        <form method="POST" class="space-y-3 text-xs">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="save_seo">
            <div>
                <label class="block font-bold text-slate-600 mb-1">SEO Title</label>
                <input type="text" name="seo_title" value="<?= Validator::e(Helpers::setting('seo_title', '')) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-100">
            </div>
            <div>
                <label class="block font-bold text-slate-600 mb-1">SEO Meta Description</label>
                <textarea name="seo_description" rows="2" class="w-full px-3 py-2 rounded-xl border border-purple-100"><?= Validator::e(Helpers::setting('seo_description', '')) ?></textarea>
            </div>
            <div>
                <label class="block font-bold text-slate-600 mb-1">SEO Keywords</label>
                <input type="text" name="seo_keywords" value="<?= Validator::e(Helpers::setting('seo_keywords', '')) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-100">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <input type="url" name="social_instagram" value="<?= Validator::e(Helpers::setting('social_instagram', '')) ?>" placeholder="Instagram URL" class="px-3 py-2 rounded-xl border border-purple-100">
                <input type="url" name="social_telegram" value="<?= Validator::e(Helpers::setting('social_telegram', '')) ?>" placeholder="Telegram URL" class="px-3 py-2 rounded-xl border border-purple-100">
                <input type="url" name="social_youtube" value="<?= Validator::e(Helpers::setting('social_youtube', '')) ?>" placeholder="YouTube URL" class="px-3 py-2 rounded-xl border border-purple-100">
                <input type="url" name="social_twitter" value="<?= Validator::e(Helpers::setting('social_twitter', '')) ?>" placeholder="X / Twitter URL" class="px-3 py-2 rounded-xl border border-purple-100">
            </div>
            <button type="submit" class="px-5 py-2.5 rounded-xl bg-purple-600 text-white font-bold">Save SEO &amp; Social</button>
        </form>
    </div>
</div>

<!-- Currencies -->
<div class="smm-card rounded-3xl p-6">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
            <h2 class="text-base font-extrabold text-slate-900">Currencies &amp; Exchange Rates</h2>
            <p class="text-xs text-slate-500">Use the dedicated Currencies Console for free live ECB/Frankfurter auto-updates, enable/disable toggles, and sync logs.</p>
        </div>
        <a href="/admin/currencies.php" class="px-4 py-2 rounded-xl bg-purple-600 text-white text-xs font-bold shadow hover:bg-purple-700">Open Currency &amp; Auto-Rates Console &rarr;</a>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead><tr class="border-b uppercase text-slate-400"><th class="py-2">Code</th><th class="py-2">Name</th><th class="py-2">Symbol</th><th class="py-2">Rate</th><th class="py-2">Base</th><th class="py-2">Save</th></tr></thead>
                <tbody class="divide-y">
                    <?php foreach ($currencies as $c): ?>
                    <tr>
                        <form method="POST">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="save_currency">
                            <input type="hidden" name="code" value="<?= Validator::e($c['code']) ?>">
                            <td class="py-2.5 font-extrabold"><?= Validator::e($c['code']) ?></td>
                            <td class="py-2.5"><input type="text" name="name" value="<?= Validator::e($c['name']) ?>" class="w-32 px-2 py-1 rounded-lg border border-slate-200"></td>
                            <td class="py-2.5"><input type="text" name="symbol" value="<?= Validator::e($c['symbol']) ?>" class="w-14 px-2 py-1 rounded-lg border border-slate-200"></td>
                            <td class="py-2.5"><input type="number" step="0.000001" name="exchange_rate" value="<?= Validator::e($c['exchange_rate']) ?>" class="w-24 px-2 py-1 rounded-lg border border-slate-200"></td>
                            <td class="py-2.5"><label class="inline-flex items-center gap-1"><input type="checkbox" name="is_base" <?= (int)$c['is_base'] === 1 ? 'checked' : '' ?>> Base</label></td>
                            <td class="py-2.5"><button type="submit" class="px-3 py-1 rounded-lg bg-purple-600 text-white font-bold">Update</button></td>
                        </form>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="POST" class="p-4 rounded-2xl bg-slate-50 border border-slate-100 space-y-2.5 text-xs">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="save_currency">
            <h3 class="font-extrabold text-slate-900">Add Currency</h3>
            <div class="grid grid-cols-2 gap-2">
                <input type="text" name="code" placeholder="GBP" required maxlength="10" class="px-3 py-2 rounded-xl border border-purple-100 uppercase">
                <input type="text" name="symbol" placeholder="£" required maxlength="10" class="px-3 py-2 rounded-xl border border-purple-100">
            </div>
            <input type="text" name="name" placeholder="British Pound" required class="w-full px-3 py-2 rounded-xl border border-purple-100">
            <input type="number" step="0.000001" name="exchange_rate" placeholder="0.009500" required class="w-full px-3 py-2 rounded-xl border border-purple-100">
            <button type="submit" class="w-full py-2 rounded-xl bg-slate-900 text-white font-bold">Add Currency</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
