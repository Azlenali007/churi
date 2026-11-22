<?php
$recentNotifs = Database::fetchAll(
    "SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 5",
    [$user['id']]
);
$unreadCount = $unreadCount ?? (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0",
    [$user['id']]
);
$initials = strtoupper(substr((string)$user['full_name'], 0, 1) ?: substr((string)$user['username'], 0, 1));
$enabledCurrencies = Currency::getEnabledCurrencies();
$activeCurr = Currency::getActiveDisplayCurrency($user);
$headerSiteName = Helpers::setting('site_name', 'SMM PRO');
$headerTagline = Helpers::setting('site_tagline', 'Grow Your Social Media');
?>
<header class="swipex-topbar px-3 sm:px-7 h-16 gap-2 sm:gap-4">
    <div class="flex items-center gap-2 sm:gap-2.5 flex-1 min-w-0">
        <!-- Mobile Drawer Button (always accessible below 1024px) -->
        <button type="button" @click="sidebarOpen = true" aria-label="Open menu"
                class="swipex-mobile-only items-center justify-center w-8 h-8 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition shrink-0">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>

        <!-- Mobile Brand Lockup matching SwipeX Reference -->
        <a href="/user/dashboard.php" class="swipex-mobile-flex items-center gap-2 min-w-0">
            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-600 to-indigo-600 text-white flex items-center justify-center shadow-sm shadow-blue-600/20 shrink-0">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
            </div>
            <div class="min-w-0">
                <div class="text-sm font-extrabold text-slate-900 leading-none truncate"><?= Validator::e($headerSiteName) ?></div>
                <div class="text-[10px] text-slate-400 truncate mt-0.5"><?= Validator::e($headerTagline) ?></div>
            </div>
        </a>

        <?php if (StoreManager::isSmmEnabled()): ?>
        <!-- Desktop Service Search -->
        <form action="/user/services.php" method="GET" class="swipex-desktop-block relative w-full max-w-md">
            <svg class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
            <input type="text" name="search" value="<?= Validator::e($_GET['search'] ?? '') ?>"
                   placeholder="Search services..."
                   class="w-full pl-10 pr-4 py-2 rounded-full bg-slate-100/85 border border-slate-200/75 focus:bg-white focus:border-blue-500 outline-none text-sm text-slate-800 placeholder-slate-400 transition">
        </form>
        <?php endif; ?>
    </div>

    <div class="flex items-center gap-1.5 sm:gap-2.5 shrink-0">
        <?php if (StoreManager::isOtpEnabled()): ?>
        <a href="/store-switch.php?to=otp" data-store-switch="otp" class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-gradient-to-r from-violet-600 to-purple-600 text-white text-xs font-extrabold shadow-sm hover:opacity-95 transition whitespace-nowrap">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="2.5"/><path d="M9 10h6M9 14h4"/></svg>
            <span>Switch to OTP Store</span>
        </a>
        <?php endif; ?>
        <!-- Outlined White Currency Pill (₹ INR ▾) matching SwipeX reference -->
        <div class="relative" x-data="smmCurrencySelector(window.__SMM_CURRENCY_CFG)">
            <button type="button" @click="openCurr = !openCurr"
                    aria-label="Select display currency"
                    class="inline-flex items-center gap-1 px-2.5 sm:px-3 py-1.5 rounded-full bg-white hover:bg-slate-50 border border-slate-200 text-slate-800 text-xs font-extrabold shadow-sm transition whitespace-nowrap">
                <span class="text-blue-600" x-text="activeSymbol"><?= Validator::e($activeCurr['symbol']) ?></span>
                <span x-text="activeCode"><?= Validator::e($activeCurr['code']) ?></span>
                <svg class="w-3 h-3 text-slate-500 transition-transform" :class="openCurr ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
            </button>

            <div x-show="openCurr" x-cloak @click.outside="openCurr = false"
                 class="absolute right-0 mt-2 w-60 bg-white rounded-2xl shadow-xl border border-slate-200 py-1.5 z-50 overflow-hidden">
                <div class="px-3.5 py-2 border-b border-slate-100 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                    Display Currency
                </div>
                <?php foreach ($enabledCurrencies as $cCode => $cRow):
                    $cCodeEsc = Validator::e($cCode);
                ?>
                <button type="button"
                        :disabled="switching"
                        @click.stop.prevent="selectCurrency('<?= $cCodeEsc ?>')"
                        :class="activeCode === '<?= $cCodeEsc ?>' ? 'bg-blue-50 text-blue-900 font-extrabold' : 'text-slate-700 hover:bg-slate-50 font-semibold'"
                        class="w-full px-3.5 py-2.5 text-left flex items-center justify-between gap-2 text-xs transition">
                    <span class="flex items-center gap-2.5 min-w-0">
                        <span :class="activeCode === '<?= $cCodeEsc ?>' ? 'bg-blue-600 text-white' : 'bg-blue-50 text-blue-600'"
                              class="w-7 h-7 rounded-xl flex items-center justify-center font-extrabold shrink-0">
                            <?= Validator::e($cRow['symbol']) ?>
                        </span>
                        <span class="truncate">
                            <span class="block leading-tight"><?= Validator::e($cRow['name']) ?></span>
                            <span :class="activeCode === '<?= $cCodeEsc ?>' ? 'text-blue-600' : 'text-slate-400'"
                                  class="text-[10px] font-bold"><?= Validator::e($cRow['symbol'] . ' ' . $cCode) ?></span>
                        </span>
                    </span>
                    <svg x-show="activeCode === '<?= $cCodeEsc ?>'" x-cloak class="w-4 h-4 text-blue-600 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Circular Notification Bell -->
        <div class="relative" x-data="{ open: false }">
            <button type="button" @click="open = !open" aria-label="Notifications"
                    class="relative w-8 h-8 sm:w-9 sm:h-9 rounded-full bg-slate-50 hover:bg-slate-100 border border-slate-200/75 text-slate-700 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <?php if ($unreadCount > 0): ?>
                <span class="absolute -top-0.5 -right-0.5 min-w-[16px] h-[16px] px-1 rounded-full bg-rose-500 text-white text-[10px] font-bold flex items-center justify-center ring-2 ring-white"><?= $unreadCount ?></span>
                <?php endif; ?>
            </button>
            <div x-show="open" x-cloak @click.away="open = false"
                 class="absolute right-0 mt-2 w-80 bg-white rounded-2xl shadow-xl border border-slate-200 overflow-hidden z-40">
                <div class="px-4 py-3 bg-slate-50 border-b border-slate-100 flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-800 uppercase">Notifications</span>
                    <a href="/user/notifications.php" class="text-xs font-bold text-blue-600 hover:underline">View All</a>
                </div>
                <div class="divide-y divide-slate-100 max-h-72 overflow-y-auto">
                    <?php if (empty($recentNotifs)): ?>
                        <div class="p-4 text-xs text-slate-500 text-center">No notifications yet.</div>
                    <?php else: foreach ($recentNotifs as $n): ?>
                        <a href="<?= Validator::e($n['link'] ?: '/user/notifications.php') ?>" class="block px-4 py-3 hover:bg-slate-50 <?= !$n['is_read'] ? 'bg-blue-50/40' : '' ?>">
                            <div class="text-xs font-bold text-slate-900"><?= Validator::e($n['title']) ?></div>
                            <div class="text-xs text-slate-500 mt-0.5 line-clamp-2"><?= Validator::e($n['message']) ?></div>
                            <div class="text-[10px] text-slate-400 mt-1"><?= Helpers::timeAgo($n['created_at']) ?></div>
                        </a>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <!-- Support Shortcut Icon -->
        <a href="/user/tickets.php" aria-label="Support Tickets"
           class="w-8 h-8 sm:w-9 sm:h-9 rounded-full bg-slate-50 hover:bg-slate-100 border border-slate-200/75 text-slate-700 flex items-center justify-center transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><line x1="4.93" y1="4.93" x2="9.17" y2="9.17"/><line x1="14.83" y1="14.83" x2="19.07" y2="19.07"/><line x1="14.83" y1="9.17" x2="19.07" y2="4.93"/><line x1="4.93" y1="19.07" x2="9.17" y2="14.83"/></svg>
        </a>

        <!-- Solid Royal-Blue Avatar Circle -->
        <div class="relative" x-data="{ open: false }">
            <button type="button" @click="open = !open" aria-label="Account Menu" class="flex items-center gap-2 p-0.5 rounded-full hover:bg-slate-100 transition">
                <div class="w-8 h-8 sm:w-9 sm:h-9 rounded-full bg-blue-600 text-white font-bold text-sm flex items-center justify-center shadow-md shadow-blue-600/25">
                    <?= Validator::e($initials) ?>
                </div>
            </button>
            <div x-show="open" x-cloak @click.away="open = false"
                 class="absolute right-0 mt-2 w-48 bg-white rounded-2xl shadow-xl border border-slate-200 py-1.5 z-40 text-sm">
                <div class="px-4 py-2 border-b border-slate-100">
                    <div class="text-xs font-extrabold text-slate-900 truncate"><?= Validator::e($user['full_name']) ?></div>
                    <div class="text-[11px] text-slate-500">@<?= Validator::e($user['username']) ?></div>
                </div>
                <a href="/user/profile.php" class="block px-4 py-2 text-slate-700 hover:bg-slate-50 font-medium text-xs">Profile &amp; Settings</a>
                <a href="/user/add-funds.php" class="block px-4 py-2 text-slate-700 hover:bg-slate-50 font-medium text-xs">Add Funds</a>
                <a href="/user/mass-order.php" class="block px-4 py-2 text-slate-700 hover:bg-slate-50 font-medium text-xs">Mass Order</a>
                <a href="/user/reseller-api.php" class="block px-4 py-2 text-slate-700 hover:bg-slate-50 font-medium text-xs">API Key</a>
                <div class="border-t border-slate-100 my-1"></div>
                <a href="/user/logout.php" class="block px-4 py-2 text-rose-600 hover:bg-rose-50 font-semibold text-xs">Logout</a>
            </div>
        </div>
    </div>
</header>

<main class="flex-1 px-4 py-5 sm:p-6 lg:p-8 pb-28 lg:pb-10 max-w-[1380px] w-full mx-auto">
    <?php foreach (Helpers::getFlashes() as $f): ?>
    <div class="mb-5 p-4 rounded-2xl text-sm font-semibold border <?= $f['type'] === 'error' ? 'bg-rose-50 border-rose-200 text-rose-700' : 'bg-emerald-50 border-emerald-200 text-emerald-700' ?>">
        <?= Validator::e($f['message']) ?>
    </div>
    <?php endforeach; ?>
