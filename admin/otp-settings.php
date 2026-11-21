<?php
/**
 * Admin OTP Global Settings
 */
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('otp.manage');
$pageTitle = 'OTP Settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $pairs = [
        'otp_default_provider_id'     => (string)(int)($_POST['otp_default_provider_id'] ?? 0),
        'otp_polling_interval'        => (string)max(2, min(60, (int)($_POST['otp_polling_interval'] ?? 5))),
        'otp_max_active_per_user'     => (string)max(1, min(100, (int)($_POST['otp_max_active_per_user'] ?? 5))),
        'otp_max_daily_per_user'      => (string)max(1, min(1000, (int)($_POST['otp_max_daily_per_user'] ?? 50))),
        'otp_min_wallet_balance'      => Currency::normalize((string)($_POST['otp_min_wallet_balance'] ?? '0.00'), 2),
        'otp_default_timeout_minutes' => (string)max(2, min(120, (int)($_POST['otp_default_timeout_minutes'] ?? 15))),
        'otp_api_timeout'             => (string)max(5, min(60, (int)($_POST['otp_api_timeout'] ?? 20))),
        'otp_default_profit_margin'   => Currency::normalize((string)($_POST['otp_default_profit_margin'] ?? '25.00'), 2),
        'otp_provider_error_mode'     => (($_POST['otp_provider_error_mode'] ?? 'friendly') === 'detailed') ? 'detailed' : 'friendly',
        'otp_auto_refund_expired'     => isset($_POST['otp_auto_refund_expired']) ? '1' : '0',
        'otp_auto_refund_canceled'    => isset($_POST['otp_auto_refund_canceled']) ? '1' : '0',
        'otp_allow_user_cancel'       => isset($_POST['otp_allow_user_cancel']) ? '1' : '0',
        'otp_show_full_sms'           => isset($_POST['otp_show_full_sms']) ? '1' : '0',
        'otp_notify_events'           => isset($_POST['otp_notify_events']) ? '1' : '0',
    ];
    foreach ($pairs as $k => $v) {
        Helpers::setSetting($k, $v, 'otp');
    }
    Logger::activity('admin', (int)$admin['id'], 'otp.settings.update', 'settings', 'otp', 'Updated OTP Store settings');
    Helpers::setFlash('success', 'OTP Store settings saved.');
    Helpers::redirect('/admin/otp-settings.php');
}

$providers = Database::fetchAll("SELECT id, name FROM otp_providers ORDER BY name ASC");
$s = static fn(string $k, string $d = '') => Helpers::setting($k, $d);

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="max-w-3xl smm-card rounded-3xl p-6 sm:p-7">
    <h1 class="text-lg font-extrabold text-slate-900 mb-1">OTP Activation Store Settings</h1>
    <p class="text-xs text-slate-500 mb-6">Configure polling intervals, rate limits, automatic refunds, SMS display, and provider timeout behavior.</p>

    <form method="POST" class="space-y-4 text-xs">
        <?= CSRF::field() ?>

        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <label class="block font-bold text-slate-700 mb-1">Default OTP Provider</label>
                <select name="otp_default_provider_id" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 font-semibold">
                    <option value="0">None</option>
                    <?php foreach ($providers as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= (int)$s('otp_default_provider_id', '0') === (int)$p['id'] ? 'selected' : '' ?>><?= Validator::e($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block font-bold text-slate-700 mb-1">Default Profit Margin (%)</label>
                <input type="number" step="0.01" min="0" name="otp_default_profit_margin" value="<?= Validator::e($s('otp_default_profit_margin', '25.00')) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200">
            </div>
            <div>
                <label class="block font-bold text-slate-700 mb-1">OTP Polling Interval (Seconds)</label>
                <input type="number" min="2" max="60" name="otp_polling_interval" value="<?= Validator::e($s('otp_polling_interval', '5')) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200">
            </div>
            <div>
                <label class="block font-bold text-slate-700 mb-1">Default Activation Timeout (Minutes)</label>
                <input type="number" min="2" max="120" name="otp_default_timeout_minutes" value="<?= Validator::e($s('otp_default_timeout_minutes', '15')) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200">
            </div>
            <div>
                <label class="block font-bold text-slate-700 mb-1">Max Active Activations Per User</label>
                <input type="number" min="1" max="100" name="otp_max_active_per_user" value="<?= Validator::e($s('otp_max_active_per_user', '5')) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200">
            </div>
            <div>
                <label class="block font-bold text-slate-700 mb-1">Max Daily Purchases Per User</label>
                <input type="number" min="1" max="1000" name="otp_max_daily_per_user" value="<?= Validator::e($s('otp_max_daily_per_user', '50')) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200">
            </div>
            <div>
                <label class="block font-bold text-slate-700 mb-1">Minimum Wallet Balance (<?= Validator::e(Currency::getBaseCode()) ?>)</label>
                <input type="number" step="0.01" min="0" name="otp_min_wallet_balance" value="<?= Validator::e($s('otp_min_wallet_balance', '0.00')) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200">
            </div>
            <div>
                <label class="block font-bold text-slate-700 mb-1">API Request Timeout (Seconds)</label>
                <input type="number" min="5" max="60" name="otp_api_timeout" value="<?= Validator::e($s('otp_api_timeout', '20')) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200">
            </div>
        </div>

        <div>
            <label class="block font-bold text-slate-700 mb-1">Provider Error Display Mode</label>
            <select name="otp_provider_error_mode" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 font-semibold">
                <option value="friendly" <?= $s('otp_provider_error_mode', 'friendly') === 'friendly' ? 'selected' : '' ?>>User-Friendly Messages (Recommended)</option>
                <option value="detailed" <?= $s('otp_provider_error_mode', 'friendly') === 'detailed' ? 'selected' : '' ?>>Detailed Provider Errors</option>
            </select>
        </div>

        <div class="grid sm:grid-cols-2 gap-3 pt-2">
            <label class="flex items-center gap-2.5 p-3 rounded-2xl bg-[#FAF8FF] border border-purple-100 font-bold text-slate-700">
                <input type="checkbox" name="otp_auto_refund_expired" value="1" <?= $s('otp_auto_refund_expired', '1') === '1' ? 'checked' : '' ?> class="accent-purple-600">
                <span>Auto-Refund Expired (No OTP)</span>
            </label>
            <label class="flex items-center gap-2.5 p-3 rounded-2xl bg-[#FAF8FF] border border-purple-100 font-bold text-slate-700">
                <input type="checkbox" name="otp_auto_refund_canceled" value="1" <?= $s('otp_auto_refund_canceled', '1') === '1' ? 'checked' : '' ?> class="accent-purple-600">
                <span>Auto-Refund Cancelled Activations</span>
            </label>
            <label class="flex items-center gap-2.5 p-3 rounded-2xl bg-[#FAF8FF] border border-purple-100 font-bold text-slate-700">
                <input type="checkbox" name="otp_allow_user_cancel" value="1" <?= $s('otp_allow_user_cancel', '1') === '1' ? 'checked' : '' ?> class="accent-purple-600">
                <span>Allow User Cancellation (Before SMS)</span>
            </label>
            <label class="flex items-center gap-2.5 p-3 rounded-2xl bg-[#FAF8FF] border border-purple-100 font-bold text-slate-700">
                <input type="checkbox" name="otp_show_full_sms" value="1" <?= $s('otp_show_full_sms', '1') === '1' ? 'checked' : '' ?> class="accent-purple-600">
                <span>Display Full SMS Body Text</span>
            </label>
            <label class="flex items-center gap-2.5 p-3 rounded-2xl bg-[#FAF8FF] border border-purple-100 font-bold text-slate-700">
                <input type="checkbox" name="otp_notify_events" value="1" <?= $s('otp_notify_events', '1') === '1' ? 'checked' : '' ?> class="accent-purple-600">
                <span>Send In-App OTP Notifications</span>
            </label>
        </div>

        <div class="pt-3 flex justify-end">
            <button type="submit" class="px-6 py-3 rounded-2xl bg-purple-600 hover:bg-purple-700 text-white font-extrabold shadow-lg shadow-purple-600/20">
                Save OTP Settings
            </button>
        </div>
    </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
