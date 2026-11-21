<?php
$siteName = Helpers::setting('site_name', 'SMM PRO');
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');

$pendingPayments = (int)Database::fetchColumn("SELECT COUNT(*) FROM payments WHERE status = 'pending'");
$openTickets = (int)Database::fetchColumn("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','pending','waiting_user')");
$pendingPanels = (int)Database::fetchColumn("SELECT COUNT(*) FROM child_panels WHERE status = 'pending'");
$pendingOtpRefunds = (int)Database::fetchColumn("SELECT COUNT(*) FROM otp_refunds WHERE status = 'pending'");
$activeOtpCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM otp_activations WHERE status IN ('processing','active','waiting_otp','otp_received')");

$adminNavGroups = [
    'Store Controls' => [
        ['file' => 'index.php',            'label' => 'Dashboard',          'perm' => 'dashboard.view'],
        ['file' => 'store-management.php', 'label' => 'Store Management',   'perm' => 'stores.manage'],
    ],
    'OTP Store' => [
        ['file' => 'otp-dashboard.php',    'label' => 'OTP Dashboard',      'perm' => 'otp.manage'],
        ['file' => 'otp-orders.php',       'label' => 'OTP Orders',         'perm' => 'otp.manage', 'badge' => $activeOtpCount, 'badge_cls' => 'bg-purple-500'],
        ['file' => 'otp-services.php',     'label' => 'OTP Services',       'perm' => 'otp.manage'],
        ['file' => 'otp-countries.php',    'label' => 'OTP Countries',      'perm' => 'otp.manage'],
        ['file' => 'otp-providers.php',    'label' => 'OTP Providers',      'perm' => 'otp.providers'],
        ['file' => 'otp-refunds.php',      'label' => 'OTP Refunds',        'perm' => 'otp.refunds', 'badge' => $pendingOtpRefunds],
        ['file' => 'otp-settings.php',     'label' => 'OTP Settings',       'perm' => 'otp.manage'],
    ],
    'SMM Store' => [
        ['file' => 'orders.php',              'label' => 'SMM Orders',              'perm' => 'orders.view'],
        ['file' => 'categories.php',          'label' => 'Categories',              'perm' => 'categories.manage'],
        ['file' => 'services.php',            'label' => 'Services',                'perm' => 'services.manage'],
        ['file' => 'providers.php',           'label' => 'Provider APIs',           'perm' => 'providers.manage'],
        ['file' => 'provider-categories.php', 'label' => 'Provider Category Import','perm' => 'providers.manage'],
        ['file' => 'provider-import.php',     'label' => 'Provider Import',         'perm' => 'providers.manage'],
        ['file' => 'child-panels.php',        'label' => 'Child Panels',            'perm' => 'child_panels.manage', 'badge' => $pendingPanels],
        ['file' => 'flash-sales.php',         'label' => 'Flash Sales',             'perm' => 'flash_sales.manage'],
    ],
    'Shared Platform' => [
        ['file' => 'users.php',            'label' => 'Users',              'perm' => 'users.view'],
        ['file' => 'payments.php',         'label' => 'Payments & Wallet',  'perm' => 'payments.manage', 'badge' => $pendingPayments],
        ['file' => 'tickets.php',          'label' => 'Support Tickets',    'perm' => 'tickets.manage', 'badge' => $openTickets],
        ['file' => 'referrals.php',        'label' => 'Referrals',          'perm' => 'referrals.manage'],
        ['file' => 'popups.php',           'label' => 'Popups',             'perm' => 'popups.manage'],
        ['file' => 'content.php',          'label' => 'Website CMS',        'perm' => 'content.manage'],
        ['file' => 'reports.php',          'label' => 'Reports & CSV',      'perm' => 'reports.view'],
        ['file' => 'staff.php',            'label' => 'Staff & 2FA',        'perm' => 'staff.manage'],
        ['file' => 'currencies.php',       'label' => 'Currencies & Rates', 'perm' => 'settings.manage'],
        ['file' => 'themes.php',           'label' => 'Themes',             'perm' => 'settings.manage'],
        ['file' => 'settings.php',         'label' => 'System Settings',    'perm' => 'settings.manage'],
        ['file' => 'logs.php',             'label' => 'Logs & Health',      'perm' => 'logs.view'],
    ],
];
?>
<div x-show="sidebarOpen" x-cloak @click="sidebarOpen = false" class="fixed inset-0 z-40 bg-slate-900/50 backdrop-blur-sm lg:hidden"></div>

<aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
       class="fixed lg:static inset-y-0 left-0 z-50 w-64 smm-sidebar text-white flex flex-col shrink-0 transition-transform duration-200">
    <div class="px-5 py-5 flex items-center justify-between border-b border-white/10">
        <a href="/admin/index.php" class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500 to-violet-700 flex items-center justify-center shadow-lg">
                <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="currentColor"><path d="M5 16L3 5l5.5 5L12 4l3.5 6L21 5l-2 11H5zm0 2h14v2H5v-2z"/></svg>
            </div>
            <div>
                <div class="font-extrabold text-base leading-none"><?= Validator::e($siteName) ?></div>
                <div class="text-[10px] font-bold uppercase tracking-wider text-purple-300 mt-1">Dual Store Admin</div>
            </div>
        </a>
        <button type="button" @click="sidebarOpen = false" class="lg:hidden text-purple-200 p-1">&times;</button>
    </div>

    <nav class="flex-1 overflow-y-auto py-3 px-3 space-y-4">
        <?php foreach ($adminNavGroups as $groupLabel => $items):
            $visible = array_filter($items, static fn($it) => Auth::adminHasPermission($admin, $it['perm']));
            if (empty($visible)) continue;
        ?>
        <div>
            <div class="px-3 mb-1 text-[10px] font-extrabold uppercase tracking-wider text-purple-300/70"><?= Validator::e($groupLabel) ?></div>
            <div class="space-y-0.5">
                <?php foreach ($visible as $item):
                    $active = $currentScript === $item['file'];
                ?>
                <a href="/admin/<?= $item['file'] ?>"
                   class="flex items-center justify-between px-3.5 py-2 rounded-xl text-xs font-semibold transition <?= $active ? 'smm-nav-active text-white' : 'text-purple-100/80 hover:bg-white/10 hover:text-white' ?>">
                    <span><?= Validator::e($item['label']) ?></span>
                    <?php if (!empty($item['badge']) && $item['badge'] > 0): ?>
                        <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold <?= Validator::e($item['badge_cls'] ?? 'bg-rose-500') ?> text-white"><?= (int)$item['badge'] ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </nav>

    <div class="p-3 border-t border-white/10 space-y-1">
        <?php if (StoreManager::isSmmEnabled()): ?>
        <a href="/" target="_blank" class="block px-3.5 py-2 rounded-xl text-xs font-semibold text-purple-200 hover:bg-white/10">View SMM Website &rarr;</a>
        <?php endif; ?>
        <?php if (StoreManager::isOtpEnabled()): ?>
        <a href="/otp/" target="_blank" class="block px-3.5 py-2 rounded-xl text-xs font-semibold text-purple-200 hover:bg-white/10">View OTP Store &rarr;</a>
        <?php endif; ?>
        <a href="/admin/logout.php" class="block px-3.5 py-2 rounded-xl text-xs font-semibold text-rose-300 hover:bg-rose-500/20">Sign Out</a>
    </div>
</aside>

<div class="flex-1 flex flex-col min-w-0">
