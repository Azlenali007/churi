<?php
/**
 * Admin Dual Store Management — Independent SMM Store & OTP Store ON/OFF Controls
 * Supports all 4 operational permutations cleanly without maintenance pages.
 */
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('stores.manage');
$pageTitle = 'Store Management';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $smmOn = isset($_POST['smm_store_enabled']) && $_POST['smm_store_enabled'] === '1';
    $otpOn = isset($_POST['otp_store_enabled']) && $_POST['otp_store_enabled'] === '1';
    StoreManager::setStoreStatus($smmOn, $otpOn);
    Logger::activity(
        'admin',
        (int)$admin['id'],
        'stores.toggle',
        'settings',
        'dual_store',
        'SMM=' . ($smmOn ? 'ON' : 'OFF') . ', OTP=' . ($otpOn ? 'ON' : 'OFF')
    );
    Helpers::setFlash('success', 'Store availability updated immediately (SMM: ' . ($smmOn ? 'ON' : 'OFF') . ', OTP: ' . ($otpOn ? 'ON' : 'OFF') . ').');
    Helpers::redirect('/admin/store-management.php');
}

$smmOn = StoreManager::isSmmEnabled();
$otpOn = StoreManager::isOtpEnabled();
$activeOtpCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM otp_activations WHERE status IN ('processing','active','waiting_otp','otp_received')");
$pendingSmmOrders = (int)Database::fetchColumn("SELECT COUNT(*) FROM orders WHERE status IN ('pending','in_progress','processing')");

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="max-w-4xl space-y-6">
    <div class="smm-card rounded-3xl p-6 sm:p-7">
        <div class="flex flex-wrap items-center justify-between gap-3 pb-5 border-b border-purple-100">
            <div>
                <h1 class="text-lg font-extrabold text-slate-900">Dual Store Availability Controls</h1>
                <p class="text-xs text-slate-500 mt-0.5">Independently enable or disable the SMM Store and OTP Activation Store at any time. Turning a store OFF hides its navigation &amp; blocks new purchases cleanly without showing any maintenance page.</p>
            </div>
            <div class="flex items-center gap-2 text-xs font-extrabold">
                <span class="px-3 py-1 rounded-full <?= $smmOn ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' ?>">SMM: <?= $smmOn ? 'ON' : 'OFF' ?></span>
                <span class="px-3 py-1 rounded-full <?= $otpOn ? 'bg-purple-100 text-purple-800' : 'bg-slate-200 text-slate-600' ?>">OTP: <?= $otpOn ? 'ON' : 'OFF' ?></span>
            </div>
        </div>

        <form method="POST" class="mt-6 space-y-5">
            <?= CSRF::field() ?>

            <div class="grid md:grid-cols-2 gap-4">
                <!-- SMM Store Card -->
                <label class="cursor-pointer block p-5 rounded-2xl border-2 transition <?= $smmOn ? 'border-indigo-500 bg-indigo-50/40' : 'border-slate-200 bg-slate-50/60' ?>">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-xs font-extrabold uppercase tracking-wider text-indigo-600">Store 1</div>
                            <h2 class="text-base font-extrabold text-slate-900 mt-0.5">SMM Store</h2>
                            <p class="text-xs text-slate-600 mt-1">Social media services catalog, New Order, Mass Order, SMM API v2 ordering, and SMM homepage.</p>
                        </div>
                        <input type="checkbox" name="smm_store_enabled" value="1" <?= $smmOn ? 'checked' : '' ?>
                               class="w-5 h-5 accent-indigo-600 rounded mt-1">
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-200/70 flex items-center justify-between text-[11px] font-semibold text-slate-500">
                        <span>In-progress SMM orders: <strong><?= $pendingSmmOrders ?></strong></span>
                        <span class="text-indigo-700 font-bold"><?= $smmOn ? 'Active & Accepting Orders' : 'Hidden & Blocked' ?></span>
                    </div>
                </label>

                <!-- OTP Store Card -->
                <label class="cursor-pointer block p-5 rounded-2xl border-2 transition <?= $otpOn ? 'border-purple-600 bg-purple-50/50' : 'border-slate-200 bg-slate-50/60' ?>">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-xs font-extrabold uppercase tracking-wider text-purple-600">Store 2</div>
                            <h2 class="text-base font-extrabold text-slate-900 mt-0.5">OTP Activation Store</h2>
                            <p class="text-xs text-slate-600 mt-1">Public OTP landing page, "Need OTP? Tap Here!" button, Buy OTP checkout, and live provider purchasing.</p>
                        </div>
                        <input type="checkbox" name="otp_store_enabled" value="1" <?= $otpOn ? 'checked' : '' ?>
                               class="w-5 h-5 accent-purple-600 rounded mt-1">
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-200/70 flex items-center justify-between text-[11px] font-semibold text-slate-500">
                        <span>Active OTP sessions: <strong><?= $activeOtpCount ?></strong></span>
                        <span class="text-purple-700 font-bold"><?= $otpOn ? 'Active & Accepting Purchases' : 'Hidden & Blocked' ?></span>
                    </div>
                </label>
            </div>

            <div class="p-4 rounded-2xl bg-amber-50 border border-amber-200 text-xs text-amber-900 leading-relaxed">
                <strong>Always-Available Shared Systems:</strong> Even when one or both stores are OFF, existing users retain full access to login, shared wallet balance &amp; deposits, order/OTP history, unified support tickets, profile settings, and <strong>active OTP SMS tracking/completion/refunds</strong>.
            </div>

            <div class="flex justify-end">
                <button type="submit" class="px-6 py-3 rounded-2xl bg-purple-600 hover:bg-purple-700 text-white font-extrabold text-xs shadow-lg shadow-purple-600/20 transition">
                    Save Store Status
                </button>
            </div>
        </form>
    </div>

    <!-- 4-Mode Matrix Reference -->
    <div class="smm-card rounded-3xl p-6">
        <h3 class="text-sm font-extrabold text-slate-900 mb-3">Behavior Across All 4 Store Combinations</h3>
        <div class="grid sm:grid-cols-2 gap-3 text-xs">
            <div class="p-3.5 rounded-2xl border <?= ($smmOn && $otpOn) ? 'border-emerald-400 bg-emerald-50/50' : 'border-purple-100 bg-slate-50' ?>">
                <div class="font-extrabold text-slate-900">1. SMM ON + OTP ON</div>
                <p class="text-slate-600 mt-1">Both stores active. Public SMM shows "Need OTP? Tap Here!" pill; user dashboards show store switchers.</p>
            </div>
            <div class="p-3.5 rounded-2xl border <?= (!$smmOn && $otpOn) ? 'border-emerald-400 bg-emerald-50/50' : 'border-purple-100 bg-slate-50' ?>">
                <div class="font-extrabold text-slate-900">2. SMM OFF + OTP ON</div>
                <p class="text-slate-600 mt-1">Root website routes directly to OTP Store. SMM buttons hidden &amp; SMM purchase routes return 404/403.</p>
            </div>
            <div class="p-3.5 rounded-2xl border <?= ($smmOn && !$otpOn) ? 'border-emerald-400 bg-emerald-50/50' : 'border-purple-100 bg-slate-50' ?>">
                <div class="font-extrabold text-slate-900">3. SMM ON + OTP OFF</div>
                <p class="text-slate-600 mt-1">All OTP buttons/pills hidden. Direct `/otp/` &amp; `/otp/buy.php` blocked; existing active OTP tracking keeps working.</p>
            </div>
            <div class="p-3.5 rounded-2xl border <?= (!$smmOn && !$otpOn) ? 'border-emerald-400 bg-emerald-50/50' : 'border-purple-100 bg-slate-50' ?>">
                <div class="font-extrabold text-slate-900">4. SMM OFF + OTP OFF</div>
                <p class="text-slate-600 mt-1">Both store purchases blocked cleanly (zero maintenance page); users can still sign in to wallet, history &amp; tickets.</p>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
