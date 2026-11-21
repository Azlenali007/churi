<?php
/**
 * User OTP Store Dashboard
 * Accessible to logged-in users even if OTP purchasing is temporarily OFF so they can monitor active activations & history.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$user = Auth::requireUser();
$userId = (int)$user['id'];
$pageTitle = 'OTP Dashboard';
$otpEnabled = StoreManager::isOtpEnabled();

$kpi = Database::fetch(
    "SELECT
        SUM(CASE WHEN status IN ('processing','active','waiting_otp','otp_received') THEN 1 ELSE 0 END) AS active_count,
        SUM(CASE WHEN status IN ('processing','active','waiting_otp') THEN 1 ELSE 0 END) AS waiting_count,
        SUM(CASE WHEN status = 'otp_received' THEN 1 ELSE 0 END) AS received_count,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count
     FROM otp_activations WHERE user_id = ?",
    [$userId]
) ?: ['active_count' => 0, 'waiting_count' => 0, 'received_count' => 0, 'completed_count' => 0];

$recentActivations = Database::fetchAll(
    "SELECT a.*, s.name AS service_name, c.name AS country_name, c.flag_emoji AS country_flag
     FROM otp_activations a
     JOIN otp_services s ON s.id = a.service_id
     JOIN otp_countries c ON c.id = a.country_id
     WHERE a.user_id = ?
     ORDER BY a.id DESC LIMIT 8",
    [$userId]
);

$popularServices = $otpEnabled ? OtpService::getEnabledServices('', true, 8) : [];
if ($otpEnabled && empty($popularServices)) {
    $popularServices = OtpService::getEnabledServices('', false, 8);
}

$recentlyUsed = Database::fetchAll(
    "SELECT s.id, s.name, s.icon_key, s.selling_price, MAX(a.created_at) AS last_used
     FROM otp_activations a
     JOIN otp_services s ON s.id = a.service_id
     WHERE a.user_id = ? AND s.status = 'active'
     GROUP BY s.id, s.name, s.icon_key, s.selling_price
     ORDER BY last_used DESC LIMIT 6",
    [$userId]
);

$recentNotifs = Database::fetchAll(
    "SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 5",
    [$userId]
);

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<!-- Welcome & Shared Wallet Banner -->
<div class="rounded-3xl bg-gradient-to-br from-violet-700 via-purple-700 to-indigo-700 text-white p-6 sm:p-7 shadow-xl shadow-purple-900/15 mb-6">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-5">
        <div>
            <div class="text-xs font-bold uppercase tracking-wider text-purple-200">Welcome back, <?= Validator::e($user['full_name'] ?: $user['username']) ?></div>
            <h2 class="text-2xl sm:text-3xl font-extrabold mt-1 tracking-tight">Shared Wallet Balance</h2>
            <div class="mt-2 text-3xl sm:text-4xl font-extrabold tabular-nums" data-otp-wallet-balance>
                <?= Currency::renderMoney((string)$user['wallet_balance'], null, 2, true) ?>
            </div>
            <p class="mt-1.5 text-xs text-purple-100/90">One wallet powers all your purchases and refunds seamlessly.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2.5">
            <?php if ($otpEnabled): ?>
            <a href="/otp/buy.php" class="inline-flex items-center gap-2 px-5 py-3 rounded-2xl bg-white text-purple-800 hover:bg-purple-50 text-xs sm:text-sm font-extrabold shadow-lg transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                <span>Buy OTP Activation</span>
            </a>
            <?php endif; ?>
            <a href="/user/add-funds.php" class="inline-flex items-center gap-2 px-5 py-3 rounded-2xl bg-white/15 hover:bg-white/25 border border-white/25 text-white text-xs sm:text-sm font-bold transition">
                <span>Add Funds</span>
            </a>
            <a href="/otp/activations.php" class="inline-flex items-center gap-2 px-4 py-3 rounded-2xl bg-black/20 hover:bg-black/30 text-white text-xs sm:text-sm font-bold transition">
                <span>Active (<?= (int)$kpi['active_count'] ?>)</span>
            </a>
        </div>
    </div>
</div>

<!-- KPI Cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-2xl border border-purple-100 p-5 shadow-xs">
        <div class="text-[11px] font-extrabold uppercase tracking-wider text-slate-400">Active Activations</div>
        <div class="mt-2 text-2xl sm:text-3xl font-extrabold text-purple-700 tabular-nums"><?= (int)$kpi['active_count'] ?></div>
        <a href="/otp/activations.php" class="mt-2 inline-block text-xs font-bold text-purple-600 hover:underline">Track live &rarr;</a>
    </div>
    <div class="bg-white rounded-2xl border border-purple-100 p-5 shadow-xs">
        <div class="text-[11px] font-extrabold uppercase tracking-wider text-slate-400">Waiting for OTP</div>
        <div class="mt-2 text-2xl sm:text-3xl font-extrabold text-amber-600 tabular-nums"><?= (int)$kpi['waiting_count'] ?></div>
        <div class="mt-2 text-xs text-slate-500">Live SMS polling</div>
    </div>
    <div class="bg-white rounded-2xl border border-purple-100 p-5 shadow-xs">
        <div class="text-[11px] font-extrabold uppercase tracking-wider text-slate-400">OTP Received</div>
        <div class="mt-2 text-2xl sm:text-3xl font-extrabold text-emerald-600 tabular-nums"><?= (int)$kpi['received_count'] ?></div>
        <div class="mt-2 text-xs text-slate-500">Ready to use</div>
    </div>
    <div class="bg-white rounded-2xl border border-purple-100 p-5 shadow-xs">
        <div class="text-[11px] font-extrabold uppercase tracking-wider text-slate-400">Completed</div>
        <div class="mt-2 text-2xl sm:text-3xl font-extrabold text-slate-900 tabular-nums"><?= (int)$kpi['completed_count'] ?></div>
        <a href="/otp/history.php" class="mt-2 inline-block text-xs font-bold text-slate-500 hover:text-purple-700">View history &rarr;</a>
    </div>
</div>

<div class="grid lg:grid-cols-12 gap-6">
    <!-- Recent OTP Activity -->
    <div class="lg:col-span-8 space-y-6">
        <div class="bg-white rounded-3xl border border-purple-100 shadow-xs overflow-hidden">
            <div class="px-6 py-4 border-b border-purple-50 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-extrabold text-slate-900">Recent OTP Activations</h3>
                    <p class="text-xs text-slate-500">Your latest SMS verification sessions</p>
                </div>
                <a href="/otp/history.php" class="text-xs font-bold text-purple-600 hover:underline">Full History</a>
            </div>
            <?php if (empty($recentActivations)): ?>
            <div class="p-8 text-center">
                <p class="text-sm font-semibold text-slate-600">No OTP activations yet.</p>
                <?php if ($otpEnabled): ?>
                <a href="/otp/buy.php" class="mt-3 inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-purple-600 text-white text-xs font-bold hover:bg-purple-700">Buy Your First OTP</a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-[#FAF8FF] text-slate-500 uppercase text-[10px] font-extrabold">
                        <tr>
                            <th class="px-5 py-3">Activation</th>
                            <th class="px-5 py-3">Service / Country</th>
                            <th class="px-5 py-3">Number &amp; OTP</th>
                            <th class="px-5 py-3">Price</th>
                            <th class="px-5 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-purple-50">
                        <?php foreach ($recentActivations as $a): ?>
                        <tr class="hover:bg-purple-50/30">
                            <td class="px-5 py-3.5 font-mono font-bold text-slate-800">
                                <?= Validator::e($a['activation_code']) ?>
                                <div class="text-[10px] font-sans text-slate-400 font-medium"><?= date('M d, H:i', strtotime($a['created_at'])) ?></div>
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="font-bold text-slate-900"><?= Validator::e($a['service_name']) ?></div>
                                <div class="text-[11px] text-slate-500"><?= Validator::e(($a['country_flag'] ? $a['country_flag'] . ' ' : '') . $a['country_name']) ?></div>
                            </td>
                            <td class="px-5 py-3.5 font-mono">
                                <div class="text-slate-800 font-semibold"><?= Validator::e($a['phone_number'] ?: 'Pending') ?></div>
                                <?php if (!empty($a['otp_code'])): ?>
                                <span class="inline-block mt-0.5 px-2 py-0.5 rounded-md bg-emerald-100 text-emerald-800 font-extrabold"><?= Validator::e($a['otp_code']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-3.5 font-bold tabular-nums"><?= Currency::renderMoney((string)$a['selling_price'], null, 2, true) ?></td>
                            <td class="px-5 py-3.5"><?= Helpers::statusBadge($a['status']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($otpEnabled && !empty($popularServices)): ?>
        <div class="bg-white rounded-3xl border border-purple-100 p-6 shadow-xs">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-extrabold text-slate-900">Popular OTP Services</h3>
                <a href="/otp/buy.php" class="text-xs font-bold text-purple-600 hover:underline">Browse All</a>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <?php foreach ($popularServices as $s): ?>
                <a href="/otp/buy.php?service_id=<?= (int)$s['id'] ?>" class="p-3.5 rounded-2xl bg-[#FAF8FF] hover:bg-purple-50 border border-purple-100 hover:border-purple-300 transition">
                    <div class="w-9 h-9 rounded-xl bg-white border border-purple-100 text-purple-700 flex items-center justify-center mb-2.5">
                        <?= Helpers::platformIcon((string)$s['icon_key']) ?>
                    </div>
                    <div class="text-xs font-extrabold text-slate-900 truncate"><?= Validator::e($s['name']) ?></div>
                    <div class="text-[11px] font-bold text-purple-700 mt-0.5 tabular-nums">
                        <?= Currency::cmp((string)$s['selling_price'], '0', 4) > 0 ? Currency::renderMoney((string)$s['selling_price'], null, 2, true) : 'Live Quote' ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right Column: Recently Used & Notifications -->
    <div class="lg:col-span-4 space-y-6">
        <?php if ($otpEnabled && !empty($recentlyUsed)): ?>
        <div class="bg-white rounded-3xl border border-purple-100 p-5 shadow-xs">
            <h3 class="text-sm font-extrabold text-slate-900 mb-3">Recently Used Services</h3>
            <div class="space-y-2">
                <?php foreach ($recentlyUsed as $ru): ?>
                <a href="/otp/buy.php?service_id=<?= (int)$ru['id'] ?>" class="flex items-center justify-between gap-2 p-2.5 rounded-xl bg-[#FAF8FF] hover:bg-purple-50 border border-purple-100/70 transition">
                    <span class="text-xs font-bold text-slate-800 truncate"><?= Validator::e($ru['name']) ?></span>
                    <span class="text-[11px] font-extrabold text-purple-700 shrink-0">Buy Again &rarr;</span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="bg-white rounded-3xl border border-purple-100 p-5 shadow-xs">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-extrabold text-slate-900">Recent Notifications</h3>
                <a href="/user/notifications.php" class="text-xs font-bold text-purple-600 hover:underline">All</a>
            </div>
            <?php if (empty($recentNotifs)): ?>
            <p class="text-xs text-slate-500">No notifications yet.</p>
            <?php else: ?>
            <div class="space-y-2.5">
                <?php foreach ($recentNotifs as $n): ?>
                <a href="<?= Validator::e($n['link'] ?: '/user/notifications.php') ?>" class="block p-3 rounded-2xl bg-[#FAF8FF] hover:bg-purple-50/70 border border-purple-100/60 transition">
                    <div class="text-xs font-extrabold text-slate-900"><?= Validator::e($n['title']) ?></div>
                    <div class="text-xs text-slate-600 mt-0.5 line-clamp-2"><?= Validator::e($n['message']) ?></div>
                    <div class="text-[10px] text-slate-400 mt-1"><?= Helpers::timeAgo($n['created_at']) ?></div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
