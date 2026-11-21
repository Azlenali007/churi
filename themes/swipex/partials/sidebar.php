<?php
$siteName = Helpers::setting('site_name', 'SMM PRO');
$siteTagline = Helpers::setting('site_tagline', 'Grow Your Social Media');
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php');
$unreadCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0", [$user['id']]);

$smmOn = StoreManager::isSmmEnabled();
$otpOn = StoreManager::isOtpEnabled();

$navItems = [];
if ($smmOn) {
    $navItems[] = ['file' => 'dashboard.php',  'label' => 'Dashboard',  'icon' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>'];
    $navItems[] = ['file' => 'new-order.php',  'label' => 'New Order',  'icon' => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>'];
    $navItems[] = ['file' => 'mass-order.php', 'label' => 'Mass Order', 'icon' => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>'];
    $navItems[] = ['file' => 'services.php',   'label' => 'Services',   'icon' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>'];
}
$navItems[] = ['file' => 'orders.php',        'label' => 'SMM Orders',     'icon' => '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/>'];
$navItems[] = ['file' => 'add-funds.php',     'label' => 'Add Funds',      'icon' => '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>'];
if ($smmOn) {
    $navItems[] = ['file' => 'reseller-api.php',  'label' => 'Reseller API',   'icon' => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>'];
    $navItems[] = ['file' => 'child-panel.php',   'label' => 'Child Panel',    'icon' => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>'];
}
$navItems[] = ['file' => 'tickets.php',       'label' => 'Support Tickets','icon' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'];
$navItems[] = ['file' => 'referrals.php',     'label' => 'Refer & Earn',   'icon' => '<polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/>'];
$navItems[] = ['file' => 'notifications.php', 'label' => 'Notifications',  'icon' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>', 'badge' => $unreadCount];
$navItems[] = ['file' => 'profile.php',       'label' => 'Profile',        'icon' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>'];
?>
<div @click="sidebarOpen = false"
     :class="{ 'is-open': sidebarOpen }"
     class="swipex-sidebar-backdrop"></div>

<aside :class="{ 'is-open': sidebarOpen }"
       class="swipex-sidebar"
       aria-label="SwipeX Sidebar Navigation">
    <div class="px-5 py-5 flex items-center justify-between border-b border-slate-100">
        <a href="/user/dashboard.php" class="flex items-center gap-3 min-w-0">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-600 to-indigo-600 text-white flex items-center justify-center shadow-md shadow-blue-600/25 shrink-0">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
            </div>
            <div class="min-w-0">
                <div class="font-extrabold text-base tracking-tight text-slate-900 leading-none truncate"><?= Validator::e($siteName) ?></div>
                <div class="text-[11px] font-medium text-slate-400 mt-1 truncate"><?= Validator::e($siteTagline) ?></div>
            </div>
        </a>
        <button type="button" @click="sidebarOpen = false" aria-label="Close menu" class="swipex-mobile-only items-center justify-center w-8 h-8 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 text-xl leading-none shrink-0">&times;</button>
    </div>

    <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-1">
        <?php foreach ($navItems as $item):
            $isActive = $currentScript === $item['file'];
        ?>
        <a href="/user/<?= $item['file'] ?>"
           @click="sidebarOpen = false"
           class="flex items-center justify-between px-3.5 py-2.5 rounded-xl text-sm font-semibold transition <?= $isActive ? 'bg-blue-600 text-white shadow-md shadow-blue-600/25' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' ?>">
            <span class="flex items-center gap-3 min-w-0">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><?= $item['icon'] ?></svg>
                <span class="truncate"><?= Validator::e($item['label']) ?></span>
            </span>
            <?php if (!empty($item['badge']) && $item['badge'] > 0): ?>
                <span class="px-2 py-0.5 rounded-full text-[11px] font-bold shrink-0 <?= $isActive ? 'bg-white/25 text-white' : 'bg-rose-500 text-white' ?>"><?= (int)$item['badge'] ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </nav>

    <div class="p-3 border-t border-slate-100 space-y-1.5">
        <?php if ($otpOn): ?>
        <a href="/otp/dashboard.php" class="flex items-center justify-between gap-2 px-3.5 py-2.5 rounded-xl bg-gradient-to-r from-violet-600 to-purple-600 text-white text-xs font-extrabold shadow-md shadow-purple-600/25 hover:opacity-95 transition">
            <span class="flex items-center gap-2.5">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="2.5"/><path d="M9 10h6M9 14h4"/></svg>
                <span>OTP Store</span>
            </span>
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
        </a>
        <?php endif; ?>
        <a href="/user/logout.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-sm font-semibold text-rose-600 hover:bg-rose-50 transition">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            <span>Logout</span>
        </a>
    </div>
</aside>

<div class="swipex-main-wrap">
