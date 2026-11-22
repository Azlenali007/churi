<?php
$siteName = Helpers::setting('site_name', 'SMM PRO');
$siteTagline = Helpers::setting('site_tagline', 'Grow Faster, Smarter');
$siteLogo = Helpers::setting('site_logo', '');
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php');
$unreadCount = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0",
    [$user['id']]
);

$smmOn = StoreManager::isSmmEnabled();
$otpOn = StoreManager::isOtpEnabled();

$navItems = [];
if ($smmOn) {
    $navItems[] = ['file' => 'dashboard.php',  'label' => 'Dashboard',  'icon' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>'];
    $navItems[] = ['file' => 'new-order.php',  'label' => 'New Order',  'icon' => '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M12 8v8M8 12h8"/>'];
    $navItems[] = ['file' => 'mass-order.php', 'label' => 'Mass Order', 'icon' => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>'];
    $navItems[] = ['file' => 'services.php',   'label' => 'Services',   'icon' => '<path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>'];
}
$navItems[] = ['file' => 'orders.php',        'label' => 'SMM Orders',        'icon' => '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/>'];
$navItems[] = ['file' => 'add-funds.php',     'label' => 'Add Funds',         'icon' => '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>'];
if ($smmOn) {
    $navItems[] = ['file' => 'reseller-api.php',  'label' => 'Reseller API',      'icon' => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>'];
    $navItems[] = ['file' => 'child-panel.php',   'label' => 'Child Panel',       'icon' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'];
}
$navItems[] = ['file' => 'tickets.php',       'label' => 'Support Tickets',   'icon' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'];
$navItems[] = ['file' => 'referrals.php',     'label' => 'Refer & Earn',      'icon' => '<polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>'];
$navItems[] = ['file' => 'notifications.php', 'label' => 'Notifications',     'icon' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>', 'badge' => $unreadCount];
$navItems[] = ['file' => 'profile.php',       'label' => 'Profile & Settings','icon' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>'];
?>
<!-- Mobile Backdrop -->
<div x-show="sidebarOpen" x-cloak @click="sidebarOpen = false" class="fixed inset-0 z-40 bg-slate-900/50 backdrop-blur-sm lg:hidden"></div>

<!-- Left Dark Indigo-Purple Sidebar -->
<aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
       class="fixed lg:static inset-y-0 left-0 z-50 w-64 smm-sidebar text-white flex flex-col shrink-0 transition-transform duration-200 ease-out">
    <!-- Brand -->
    <div class="px-5 py-6 flex items-center justify-between border-b border-white/10">
        <a href="/user/dashboard.php" class="flex items-center gap-3 min-w-0">
            <?php if ($siteLogo): ?>
                <img src="<?= Validator::e($siteLogo) ?>" alt="<?= Validator::e($siteName) ?>" class="h-10 w-10 rounded-xl object-cover shrink-0">
            <?php else: ?>
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500 to-violet-700 flex items-center justify-center shadow-lg shadow-purple-500/30 shrink-0">
                    <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="currentColor"><path d="M5 16L3 5l5.5 5L12 4l3.5 6L21 5l-2 11H5zm0 2h14v2H5v-2z"/></svg>
                </div>
            <?php endif; ?>
            <div class="min-w-0">
                <div class="font-extrabold text-lg tracking-tight leading-none truncate"><?= Validator::e($siteName) ?></div>
                <div class="text-[11px] text-purple-300 mt-1 truncate"><?= Validator::e($siteTagline) ?></div>
            </div>
        </a>
        <button type="button" @click="sidebarOpen = false" class="lg:hidden text-purple-200 hover:text-white p-1">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg>
        </button>
    </div>

    <!-- Nav Items -->
    <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-1">
        <?php foreach ($navItems as $item):
            $isActive = empty($item['href']) && $currentScript === $item['file'];
            $itemHref = $item['href'] ?? ('/user/' . $item['file']);
        ?>
        <a href="<?= Validator::e($itemHref) ?>"
           class="flex items-center justify-between px-3.5 py-2.5 rounded-xl text-sm font-semibold transition <?= $isActive ? 'smm-nav-active text-white' : 'text-purple-100/80 hover:bg-white/10 hover:text-white' ?>">
            <span class="flex items-center gap-3 min-w-0">
                <svg class="w-5 h-5 shrink-0 <?= $isActive ? 'text-white' : 'text-purple-300' ?>" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><?= $item['icon'] ?></svg>
                <span class="truncate"><?= Validator::e($item['label']) ?></span>
            </span>
            <?php if (!empty($item['badge']) && $item['badge'] > 0): ?>
                <span class="ml-2 px-2 py-0.5 rounded-full text-[11px] font-bold bg-rose-500 text-white"><?= (int)$item['badge'] ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </nav>

    <!-- Store Switcher + Logout Footer -->
    <div class="p-3 border-t border-white/10 space-y-1.5">
        <?php if ($otpOn): ?>
        <a href="/store-switch.php?to=otp" data-store-switch="otp"
           class="flex items-center justify-between gap-2 px-3.5 py-2.5 rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:opacity-95 text-white text-xs font-extrabold shadow-lg shadow-purple-600/30 transition">
            <span class="flex items-center gap-2.5">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="2.5"/><path d="M9 10h6M9 14h4"/></svg>
                <span>Switch to OTP Store</span>
            </span>
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
        </a>
        <?php endif; ?>
        <a href="/user/logout.php"
           class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-sm font-semibold text-purple-200 hover:bg-rose-500/20 hover:text-white transition">
            <svg class="w-5 h-5 text-purple-300" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            <span>Logout</span>
        </a>
    </div>
</aside>

<div class="flex-1 flex flex-col min-w-0">
