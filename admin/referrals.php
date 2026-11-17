<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('referrals.manage');
$pageTitle = 'Referral Program';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    Helpers::setSetting('referral_enabled', !empty($_POST['referral_enabled']) ? '1' : '0', 'referral');
    Helpers::setSetting('referral_commission_percent', Currency::normalize((string)($_POST['commission_percent'] ?? '5.00'), 2), 'referral');
    Helpers::setFlash('success', 'Referral settings saved.');
    Helpers::redirect('/admin/referrals.php');
}

$referrals = Database::fetchAll(
    "SELECT r.*, u1.username AS referrer_name, u2.username AS referred_name
     FROM referrals r
     JOIN users u1 ON u1.id = r.referrer_user_id
     JOIN users u2 ON u2.id = r.referred_user_id
     ORDER BY r.id DESC LIMIT 100"
);

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
    <div class="lg:col-span-4 smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-4">Referral Settings</h2>
        <form method="POST" class="space-y-4 text-xs">
            <?= CSRF::field() ?>
            <label class="flex items-center gap-2 font-bold text-slate-700">
                <input type="checkbox" name="referral_enabled" value="1" <?= Helpers::setting('referral_enabled', '1') === '1' ? 'checked' : '' ?> class="rounded text-purple-600">
                <span>Enable Referral Commissions</span>
            </label>
            <div>
                <label class="block font-bold uppercase text-slate-500 mb-1">Commission Percentage (%)</label>
                <input type="number" step="0.01" name="commission_percent" value="<?= Validator::e(Helpers::setting('referral_commission_percent', '5.00')) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 text-sm font-bold">
            </div>
            <button type="submit" class="px-5 py-2.5 rounded-xl bg-purple-600 text-white font-bold">Save Settings</button>
        </form>
    </div>

    <div class="lg:col-span-8 smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-4">All Referrals (<?= count($referrals) ?>)</h2>
        <table class="w-full text-left text-xs">
            <thead><tr class="border-b uppercase text-slate-400"><th class="py-2">Referrer</th><th class="py-2">Referred User</th><th class="py-2">Rate</th><th class="py-2">Earned</th><th class="py-2 text-right">Date</th></tr></thead>
            <tbody class="divide-y">
                <?php foreach ($referrals as $r): ?>
                <tr>
                    <td class="py-2.5 font-bold">@<?= Validator::e($r['referrer_name']) ?></td>
                    <td class="py-2.5">@<?= Validator::e($r['referred_name']) ?></td>
                    <td class="py-2.5"><?= Validator::e((string)$r['commission_rate']) ?>%</td>
                    <td class="py-2.5 font-bold text-emerald-600"><?= Currency::format((string)$r['total_earned']) ?></td>
                    <td class="py-2.5 text-right text-slate-400"><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($referrals)) echo '<tr><td colspan="5" class="py-8 text-center text-slate-500">No referrals recorded yet.</td></tr>'; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
