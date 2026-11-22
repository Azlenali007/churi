<?php
$pageTitle = 'Profile & Settings';
$isOtpStoreContext = (($storeContext ?? 'smm') === 'otp');
$storeQuery = $isOtpStoreContext ? '?store=otp' : '';
if ($isOtpStoreContext) {
    require APP_ROOT . '/otp/includes/head.php';
    require APP_ROOT . '/otp/includes/sidebar.php';
    require APP_ROOT . '/otp/includes/header.php';
} else {
    require __DIR__ . '/partials/head.php';
    require __DIR__ . '/partials/sidebar.php';
    require __DIR__ . '/partials/header.php';
}

$notifPrefs = json_decode((string)$user['notification_prefs_json'], true) ?: ['order_updates' => true, 'payment_alerts' => true, 'promo_emails' => false];
$loginLogs = Database::fetchAll(
    "SELECT * FROM login_attempts WHERE user_type = 'user' AND (identifier = ? OR identifier = ?) ORDER BY id DESC LIMIT 10",
    [strtolower($user['email']), strtolower($user['username'])]
);
?>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
    <!-- Edit Profile & Notification Settings -->
    <div class="lg:col-span-6 space-y-6">
        <div class="smm-card rounded-3xl p-6">
            <h1 class="text-lg font-extrabold text-slate-900 mb-4">Profile Details</h1>
            <form method="POST" action="/user/profile.php<?= $storeQuery ?>" class="space-y-4">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="update_profile">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Full Name</label>
                        <input type="text" name="full_name" value="<?= Validator::e($user['full_name']) ?>" required class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Username</label>
                        <input type="text" disabled value="<?= Validator::e($user['username']) ?>" class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-sm text-slate-500">
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Email Address</label>
                        <input type="email" name="email" value="<?= Validator::e($user['email']) ?>" required class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Phone</label>
                        <input type="text" name="phone" value="<?= Validator::e((string)$user['phone']) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 text-sm">
                    </div>
                </div>

                <div class="pt-2 space-y-2">
                    <div class="text-xs font-bold text-slate-600 uppercase">Email & Notification Preferences</div>
                    <label class="flex items-center gap-2 text-xs text-slate-700">
                        <input type="checkbox" name="pref_orders" value="1" <?= !empty($notifPrefs['order_updates']) ? 'checked' : '' ?> class="rounded text-purple-600">
                        <span>Order completion & partial refund alerts</span>
                    </label>
                    <label class="flex items-center gap-2 text-xs text-slate-700">
                        <input type="checkbox" name="pref_payments" value="1" <?= !empty($notifPrefs['payment_alerts']) ? 'checked' : '' ?> class="rounded text-purple-600">
                        <span>Payment verification & wallet deposit alerts</span>
                    </label>
                    <label class="flex items-center gap-2 text-xs text-slate-700">
                        <input type="checkbox" name="pref_promo" value="1" <?= !empty($notifPrefs['promo_emails']) ? 'checked' : '' ?> class="rounded text-purple-600">
                        <span>Flash sale & promotional announcements</span>
                    </label>
                </div>

                <button type="submit" class="px-6 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs shadow">Save Profile & Preferences</button>
            </form>
        </div>

        <div class="smm-card rounded-3xl p-6">
            <h2 class="text-base font-extrabold text-slate-900 mb-3">API Settings</h2>
            <p class="text-xs text-slate-500 mb-3">Control whether your Reseller API key can accept automated orders.</p>
            <form method="POST" action="/user/profile.php<?= $storeQuery ?>" class="flex items-center justify-between">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="toggle_api">
                <span class="text-sm font-bold text-slate-800">API Status: <?= Helpers::statusBadge((string)$user['api_status']) ?></span>
                <button type="submit" class="px-4 py-2 rounded-xl bg-purple-100 hover:bg-purple-200 text-purple-800 text-xs font-bold">
                    <?= $user['api_status'] === 'active' ? 'Disable API' : 'Enable API' ?>
                </button>
            </form>
        </div>
    </div>

    <!-- Change Password & Account Security -->
    <div class="lg:col-span-6 space-y-6">
        <div class="smm-card rounded-3xl p-6">
            <h2 class="text-lg font-extrabold text-slate-900 mb-4">Change Password</h2>
            <form method="POST" action="/user/profile.php<?= $storeQuery ?>" class="space-y-4">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="change_password">
                <div>
                    <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Current Password</label>
                    <input type="password" name="current_password" required class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 uppercase mb-1">New Password (min 8 chars)</label>
                    <input type="password" name="new_password" required minlength="8" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Confirm New Password</label>
                    <input type="password" name="confirm_password" required minlength="8" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 text-sm">
                </div>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs shadow">Update Password</button>
            </form>
        </div>

        <div class="smm-card rounded-3xl p-6">
            <h2 class="text-base font-extrabold text-slate-900 mb-3">Recent Login Activity</h2>
            <?php if (empty($loginLogs)): ?>
                <p class="text-xs text-slate-500">No login records found.</p>
            <?php else: ?>
                <div class="divide-y divide-slate-100 text-xs">
                    <?php foreach ($loginLogs as $ll): ?>
                    <div class="py-2.5 flex items-center justify-between">
                        <div>
                            <span class="font-bold text-slate-800"><?= Validator::e($ll['ip_address']) ?></span>
                            <div class="text-[11px] text-slate-400"><?= date('M j, Y H:i', strtotime($ll['created_at'])) ?></div>
                        </div>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $ll['success'] ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' ?>">
                            <?= $ll['success'] ? 'Success' : 'Failed' ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
if ($isOtpStoreContext) {
    require APP_ROOT . '/otp/includes/footer.php';
} else {
    require __DIR__ . '/partials/footer.php';
}
?>
