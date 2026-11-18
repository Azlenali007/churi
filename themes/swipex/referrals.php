<?php
$pageTitle = 'Refer & Earn';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/sidebar.php';
require __DIR__ . '/partials/header.php';

$appUrl = rtrim((string)($GLOBALS['APP_CONFIG']['app']['url'] ?? ('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'))), '/');
$refLink = $appUrl . '/register.php?ref=' . urlencode((string)$user['referral_code']);
$commRate = Helpers::setting('referral_commission_percent', '5.00');

$referrals = Database::fetchAll(
    "SELECT r.*, u.username, u.created_at AS joined_at
     FROM referrals r
     JOIN users u ON u.id = r.referred_user_id
     WHERE r.referrer_user_id = ?
     ORDER BY r.id DESC",
    [$user['id']]
);

$totalEarned = Database::fetchColumn(
    "SELECT COALESCE(SUM(total_earned), 0) FROM referrals WHERE referrer_user_id = ?",
    [$user['id']]
);

$refHistory = Database::fetchAll(
    "SELECT rt.*, u.username
     FROM referral_transactions rt
     JOIN users u ON u.id = rt.referred_user_id
     WHERE rt.referrer_user_id = ?
     ORDER BY rt.id DESC LIMIT 30",
    [$user['id']]
);
?>

<div class="swipex-page-hero rounded-[22px] p-4 sm:p-5 mb-5 flex flex-wrap items-center justify-between gap-3">
    <div class="flex items-center gap-3 min-w-0">
        <div class="w-11 h-11 rounded-2xl bg-gradient-to-br from-rose-500 to-pink-600 text-white flex items-center justify-center shadow-md shadow-rose-500/25 shrink-0">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/></svg>
        </div>
        <div class="min-w-0">
            <h1 class="text-lg sm:text-xl font-extrabold text-slate-900 truncate">Refer &amp; Earn</h1>
            <p class="text-xs text-slate-500">Invite friends with your link &amp; earn <?= Validator::e($commRate) ?>% on every wallet deposit.</p>
        </div>
    </div>
    <span class="px-3 py-1 rounded-full bg-white text-rose-600 text-xs font-extrabold border border-rose-200/80 shadow-sm">
        <?= Validator::e($commRate) ?>% Commission
    </span>
</div>

<div class="space-y-6">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="rounded-3xl p-6 bg-gradient-to-br from-blue-600 to-indigo-600 text-white shadow-lg shadow-blue-600/20">
            <div class="text-xs text-blue-100 font-semibold">Commission Rate</div>
            <div class="text-3xl font-extrabold mt-1"><?= Validator::e($commRate) ?>%</div>
            <div class="text-xs text-blue-100/90 mt-2">Earned automatically on referred user deposits</div>
        </div>
        <div class="swipex-card rounded-3xl p-6">
            <div class="text-xs text-slate-500 font-semibold">Total Referrals</div>
            <div class="text-3xl font-extrabold text-slate-900 mt-1 tabular-nums"><?= count($referrals) ?></div>
            <div class="text-xs text-blue-600 font-semibold mt-2">Active referred accounts</div>
        </div>
        <div class="swipex-card rounded-3xl p-6">
            <div class="text-xs text-slate-500 font-semibold">Total Referral Earnings</div>
            <div class="text-3xl font-extrabold text-emerald-600 mt-1 tabular-nums"><?= Currency::renderMoney((string)$totalEarned, null, 2, true) ?></div>
            <div class="text-xs text-slate-500 mt-2">Credited directly to your wallet</div>
        </div>
    </div>

    <div class="swipex-card rounded-3xl p-6">
        <h2 class="text-lg font-extrabold text-slate-900 mb-3">Your Referral Link &amp; Code</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="min-w-0">
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Shareable Invite Link</label>
                <div class="flex gap-2">
                    <input type="text" readonly value="<?= Validator::e($refLink) ?>" class="flex-1 min-w-0 px-4 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs font-mono">
                    <button type="button" onclick="copyText('<?= Validator::e($refLink) ?>', 'Referral link copied!')" class="swipex-btn-primary px-5 py-2.5 rounded-full text-xs font-bold shrink-0">Copy</button>
                </div>
            </div>
            <div class="min-w-0">
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Referral Code</label>
                <div class="flex gap-2">
                    <input type="text" readonly value="<?= Validator::e($user['referral_code']) ?>" class="flex-1 min-w-0 px-4 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs font-mono font-bold">
                    <button type="button" onclick="copyText('<?= Validator::e($user['referral_code']) ?>', 'Referral code copied!')" class="px-5 py-2.5 rounded-full bg-blue-50 hover:bg-blue-100 text-blue-700 text-xs font-bold shrink-0">Copy</button>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="swipex-card rounded-3xl p-6 min-w-0 overflow-x-auto">
            <h2 class="text-base font-extrabold text-slate-900 mb-4">Referred Users</h2>
            <?php if (empty($referrals)): ?>
                <p class="text-xs text-slate-500 py-6 text-center">No users referred yet.</p>
            <?php else: ?>
                <table class="w-full text-left text-xs">
                    <thead><tr class="border-b border-slate-100 uppercase text-slate-400"><th class="py-2">User</th><th class="py-2">Rate</th><th class="py-2">Earned</th><th class="py-2 text-right">Joined</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($referrals as $r): ?>
                        <tr>
                            <td class="py-2.5 font-bold">@<?= Validator::e($r['username']) ?></td>
                            <td class="py-2.5"><?= Validator::e((string)$r['commission_rate']) ?>%</td>
                            <td class="py-2.5 font-bold text-emerald-600 tabular-nums"><?= Currency::renderMoney((string)$r['total_earned'], null, 2, true) ?></td>
                            <td class="py-2.5 text-right text-slate-500"><?= date('M j, Y', strtotime($r['joined_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="swipex-card rounded-3xl p-6 min-w-0 overflow-x-auto">
            <h2 class="text-base font-extrabold text-slate-900 mb-4">Commission History</h2>
            <?php if (empty($refHistory)): ?>
                <p class="text-xs text-slate-500 py-6 text-center">No referral commissions earned yet.</p>
            <?php else: ?>
                <table class="w-full text-left text-xs">
                    <thead><tr class="border-b border-slate-100 uppercase text-slate-400"><th class="py-2">From User</th><th class="py-2">Deposit</th><th class="py-2">Bonus</th><th class="py-2 text-right">Date</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($refHistory as $rh): ?>
                        <tr>
                            <td class="py-2.5 font-bold">@<?= Validator::e($rh['username']) ?></td>
                            <td class="py-2.5 tabular-nums"><?= Currency::renderMoney((string)$rh['base_amount'], null, 2, true) ?></td>
                            <td class="py-2.5 font-bold text-emerald-600 tabular-nums"><?= Currency::renderMoney((string)$rh['commission_amount'], null, 2, true, '+') ?></td>
                            <td class="py-2.5 text-right text-slate-500"><?= date('M j, H:i', strtotime($rh['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
