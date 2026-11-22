<?php
/**
 * Custom OTP Store User Portal — Sidebar Navigation
 * Includes all 11 required OTP navigation links with independent store ON/OFF visibility.
 */
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$siteName = Helpers::setting('site_name', 'SMM PRO');
$otpEnabled = StoreManager::isOtpEnabled();
$smmEnabled = StoreManager::isSmmEnabled();

$activeOtpCount = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM otp_activations WHERE user_id = ? AND status IN ('processing','active','waiting_otp','otp_received')",
    [$user['id']]
);
$unreadCount = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0",
    [$user['id']]
);

$otpNav = [
    ['href' => '/otp/dashboard.php', 'label' => 'OTP Dashboard', 'icon' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>'],
];
if ($otpEnabled) {
    $otpNav[] = ['href' => '/otp/buy.php', 'label' => 'Buy OTP', 'highlight' => true, 'icon' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>'];
}
$otpNav[] = ['href' => '/otp/activations.php', 'label' => 'Active Activations', 'badge' => $activeOtpCount, 'icon' => '<path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>'];
$otpNav[] = ['href' => '/otp/history.php', 'label' => 'OTP History', 'icon' => '<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 15"/>'];
$otpNav[] = ['href' => '/otp/reseller-api.php', 'active_paths' => ['/otp/reseller-api.php', '/otp/api-docs.php'], 'label' => 'OTP Reseller API', 'icon' => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>'];

$sharedNav = [
    ['href' => '/user/add-funds.php?store=otp', 'label' => 'Wallet', 'icon' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M16 12h2"/>'],
    ['href' => '/otp/transactions.php', 'label' => 'Transactions', 'icon' => '<path d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 0 0 3-3V8a3 3 0 0 0-3-3H6a3 3 0 0 0-3 3v8a3 3 0 0 0 3 3z"/>'],
    ['href' => '/user/notifications.php?store=otp', 'label' => 'Notifications', 'badge' => $unreadCount, 'icon' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>'],
    ['href' => '/user/tickets.php?store=otp', 'label' => 'Support Tickets', 'icon' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'],
    ['href' => '/user/profile.php?store=otp', 'label' => 'Profile & Settings', 'icon' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>'],
];
?>
<!-- Mobile Backdrop -->
<div x-show="sidebarOpen" x-cloak @click="sidebarOpen = false"
     class="fixed inset-0 bg-slate-900/50 backdrop-blur-xs z-40 lg:hidden"></div>

<aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
       class="fixed lg:sticky top-0 left-0 z-50 h-screen w-64 bg-white border-r border-purple-100/90 flex flex-col transition-transform duration-200 shrink-0 shadow-xl shadow-purple-900/5 lg:shadow-none">
    <!-- OTP Brand Header -->
    <div class="h-16 px-5 flex items-center justify-between border-b border-purple-100/80">
        <a href="/otp/dashboard.php" class="flex items-center gap-2.5 min-w-0">
            <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-violet-600 via-purple-600 to-fuchsia-600 text-white flex items-center justify-center shadow-md shadow-purple-500/25 shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="2.5"/><path d="M9 10h6M9 14h4"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
            </span>
            <div class="min-w-0">
                <div class="text-sm font-extrabold text-slate-900 truncate"><?= Validator::e($siteName) ?></div>
                <div class="text-[10px] font-extrabold uppercase tracking-wider text-purple-600">OTP Activation Store</div>
            </div>
        </a>
        <button type="button" @click="sidebarOpen = false" class="lg:hidden p-1.5 rounded-lg text-slate-500 hover:bg-purple-50">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg>
        </button>
    </div>

    <!-- Shared Wallet Snapshot Card -->
    <div class="px-4 pt-4 pb-2">
        <div class="p-3.5 rounded-2xl bg-gradient-to-br from-violet-600 via-purple-600 to-indigo-600 text-white shadow-md shadow-purple-600/20">
            <div class="flex items-center justify-between text-[10px] font-bold uppercase tracking-wider text-purple-100">
                <span>Shared Wallet</span>
                <a href="/user/add-funds.php?store=otp" class="underline hover:text-white">+ Top Up</a>
            </div>
            <div class="mt-1 text-lg font-extrabold tabular-nums" data-otp-wallet-balance>
                <?= Currency::renderMoney((string)$user['wallet_balance'], null, 2, true) ?>
            </div>
        </div>
    </div>

    <!-- Navigation Links -->
    <nav class="flex-1 overflow-y-auto px-3 py-2 space-y-5">
        <div>
            <div class="px-3 mb-1.5 text-[10px] font-extrabold uppercase tracking-wider text-slate-400">OTP Activations</div>
            <div class="space-y-1">
                <?php foreach ($otpNav as $item):
                    $activePaths = $item['active_paths'] ?? [$item['href']];
                    $isActive = in_array($currentPath, $activePaths, true);
                    $cls = $isActive
                        ? 'bg-purple-600 text-white shadow-md shadow-purple-600/20 font-bold'
                        : (!empty($item['highlight'])
                            ? 'bg-purple-50 text-purple-700 hover:bg-purple-100 font-bold'
                            : 'text-slate-600 hover:bg-purple-50/70 hover:text-purple-800 font-semibold');
                ?>
                <a href="<?= Validator::e($item['href']) ?>" class="flex items-center justify-between gap-2.5 px-3 py-2.5 rounded-xl text-[13px] transition <?= $cls ?>">
                    <span class="flex items-center gap-2.5 min-w-0">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><?= $item['icon'] ?></svg>
                        <span class="truncate"><?= Validator::e($item['label']) ?></span>
                    </span>
                    <?php if (!empty($item['badge'])): ?>
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold <?= $isActive ? 'bg-white/25 text-white' : 'bg-purple-100 text-purple-700' ?>">
                        <?= (int)$item['badge'] ?>
                    </span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div>
            <div class="px-3 mb-1.5 text-[10px] font-extrabold uppercase tracking-wider text-slate-400">Account &amp; Wallet</div>
            <div class="space-y-1">
                <?php foreach ($sharedNav as $item):
                    $baseHref = strtok($item['href'], '?');
                    $isActive = ($currentPath === $baseHref);
                    $cls = $isActive
                        ? 'bg-purple-600 text-white shadow-md shadow-purple-600/20 font-bold'
                        : 'text-slate-600 hover:bg-purple-50/70 hover:text-purple-800 font-semibold';
                ?>
                <a href="<?= Validator::e($item['href']) ?>" class="flex items-center justify-between gap-2.5 px-3 py-2.5 rounded-xl text-[13px] transition <?= $cls ?>">
                    <span class="flex items-center gap-2.5 min-w-0">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><?= $item['icon'] ?></svg>
                        <span class="truncate"><?= Validator::e($item['label']) ?></span>
                    </span>
                    <?php if (!empty($item['badge'])): ?>
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold <?= $isActive ? 'bg-white/25 text-white' : 'bg-rose-100 text-rose-700' ?>">
                        <?= (int)$item['badge'] ?>
                    </span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </nav>

    <!-- Bottom Switcher & Logout -->
    <div class="p-3 border-t border-purple-100/80 space-y-1.5">
        <?php if ($smmEnabled): ?>
        <a href="/store-switch.php?to=smm" data-store-switch="smm" class="w-full flex items-center justify-center gap-2 px-3.5 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-xs font-extrabold transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            <span>Switch to SMM Store</span>
        </a>
        <?php endif; ?>
        <a href="/user/logout.php" class="w-full flex items-center justify-center gap-2 px-3.5 py-2 rounded-xl text-rose-600 hover:bg-rose-50 text-xs font-bold transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            <span>Logout</span>
        </a>
    </div>
</aside>

<div class="flex-1 flex flex-col min-w-0">
