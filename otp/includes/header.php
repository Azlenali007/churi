<?php
/**
 * Custom OTP Store User Portal — Sticky Top Header
 * Shares display currency selector, shared wallet balance, notifications, and profile menu.
 */
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
$smmEnabled = StoreManager::isSmmEnabled();
?>
<header class="sticky top-0 z-30 bg-white/90 backdrop-blur-xl border-b border-purple-100/80 px-3.5 sm:px-7 h-16 flex items-center justify-between gap-3">
    <div class="flex items-center gap-2.5 min-w-0">
        <button type="button" @click="sidebarOpen = true" aria-label="Open Menu" class="lg:hidden p-2 rounded-xl text-purple-700 hover:bg-purple-50 shrink-0">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <div class="min-w-0">
            <h1 class="text-sm sm:text-base font-extrabold text-slate-900 truncate"><?= Validator::e($pageTitle ?? 'OTP Store') ?></h1>
            <p class="hidden sm:block text-[11px] font-medium text-slate-500 truncate">Direct One-Time SMS &amp; OTP Verification</p>
        </div>
    </div>

    <div class="flex items-center gap-2 sm:gap-3 shrink-0">
        <?php if ($smmEnabled): ?>
        <a href="/user/dashboard.php" class="hidden md:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            <span>SMM Store</span>
        </a>
        <?php endif; ?>

        <!-- Shared Multi-Currency Selector -->
        <div class="relative" x-data="smmCurrencySelector(window.__SMM_CURRENCY_CFG)">
            <button type="button" @click="openCurr = !openCurr"
                    aria-label="Select display currency"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-[#F3F1FC] hover:bg-purple-100 border border-purple-200/80 text-slate-800 text-xs font-extrabold shadow-sm transition">
                <span class="text-purple-700" x-text="activeSymbol"><?= Validator::e($activeCurr['symbol']) ?></span>
                <span x-text="activeCode"><?= Validator::e($activeCurr['code']) ?></span>
                <svg class="w-3.5 h-3.5 text-slate-500 transition-transform" :class="openCurr ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
            </button>

            <div x-show="openCurr" x-cloak @click.outside="openCurr = false"
                 class="absolute right-0 mt-2 w-60 bg-white rounded-2xl shadow-xl border border-purple-100 py-1.5 z-50 overflow-hidden">
                <div class="px-3.5 py-2 border-b border-purple-50 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                    Display Currency
                </div>
                <?php foreach ($enabledCurrencies as $cCode => $cRow):
                    $cCodeEsc = Validator::e($cCode);
                ?>
                <button type="button"
                        :disabled="switching"
                        @click.stop.prevent="selectCurrency('<?= $cCodeEsc ?>')"
                        :class="activeCode === '<?= $cCodeEsc ?>' ? 'bg-purple-50 text-purple-900 font-extrabold' : 'text-slate-700 hover:bg-purple-50/60 font-semibold'"
                        class="w-full px-3.5 py-2.5 text-left flex items-center justify-between gap-2 text-xs transition">
                    <span class="flex items-center gap-2.5 min-w-0">
                        <span :class="activeCode === '<?= $cCodeEsc ?>' ? 'bg-purple-600 text-white' : 'bg-purple-100/80 text-purple-700'"
                              class="w-7 h-7 rounded-xl flex items-center justify-center font-extrabold shrink-0">
                            <?= Validator::e($cRow['symbol']) ?>
                        </span>
                        <span class="truncate">
                            <span class="block leading-tight"><?= Validator::e($cRow['name']) ?></span>
                            <span class="text-[10px] font-bold text-slate-400"><?= Validator::e($cRow['symbol'] . ' ' . $cCode) ?></span>
                        </span>
                    </span>
                    <svg x-show="activeCode === '<?= $cCodeEsc ?>'" x-cloak class="w-4 h-4 text-purple-600 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Shared Wallet Balance Pill -->
        <a href="/user/add-funds.php" class="hidden sm:inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full bg-purple-50 border border-purple-200 text-purple-800 text-xs font-extrabold hover:bg-purple-100 transition tabular-nums">
            <span>Wallet:</span>
            <span data-otp-wallet-balance><?= Currency::renderMoney((string)$user['wallet_balance'], null, 2, true) ?></span>
        </a>

        <!-- Notification Bell -->
        <div class="relative" x-data="{ open: false }">
            <button type="button" @click="open = !open" aria-label="Notifications" class="relative p-2 rounded-full hover:bg-purple-50 text-slate-700 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <?php if ($unreadCount > 0): ?>
                <span class="absolute top-0.5 right-0.5 min-w-[17px] h-[17px] px-1 rounded-full bg-rose-500 text-white text-[10px] font-bold flex items-center justify-center ring-2 ring-white"><?= $unreadCount ?></span>
                <?php endif; ?>
            </button>
            <div x-show="open" x-cloak @click.away="open = false"
                 class="absolute right-0 mt-2 w-80 bg-white rounded-2xl shadow-xl border border-purple-100 overflow-hidden z-40">
                <div class="px-4 py-3 bg-purple-50/70 border-b border-purple-100 flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-800 uppercase">Notifications</span>
                    <a href="/user/notifications.php" class="text-xs font-bold text-purple-600 hover:underline">View All</a>
                </div>
                <div class="divide-y divide-slate-100 max-h-72 overflow-y-auto">
                    <?php if (empty($recentNotifs)): ?>
                        <div class="p-4 text-xs text-slate-500 text-center">No notifications yet.</div>
                    <?php else: foreach ($recentNotifs as $n): ?>
                        <a href="<?= Validator::e($n['link'] ?: '/user/notifications.php') ?>" class="block px-4 py-3 hover:bg-purple-50/40 <?= !$n['is_read'] ? 'bg-purple-50/30' : '' ?>">
                            <div class="text-xs font-bold text-slate-900"><?= Validator::e($n['title']) ?></div>
                            <div class="text-xs text-slate-500 mt-0.5 line-clamp-2"><?= Validator::e($n['message']) ?></div>
                            <div class="text-[10px] text-slate-400 mt-1"><?= Helpers::timeAgo($n['created_at']) ?></div>
                        </a>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <!-- User Avatar -->
        <div class="relative" x-data="{ open: false }">
            <button type="button" @click="open = !open" class="flex items-center gap-2 p-0.5 rounded-full hover:bg-purple-50 transition">
                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-violet-600 to-purple-700 text-white font-bold text-sm flex items-center justify-center shadow-sm">
                    <?= Validator::e($initials) ?>
                </div>
            </button>
            <div x-show="open" x-cloak @click.away="open = false"
                 class="absolute right-0 mt-2 w-48 bg-white rounded-2xl shadow-xl border border-purple-100 py-1.5 z-40 text-xs font-semibold">
                <div class="px-4 py-2 border-b border-purple-50">
                    <div class="font-extrabold text-slate-900 truncate"><?= Validator::e($user['full_name']) ?></div>
                    <div class="text-[11px] text-slate-500 truncate">@<?= Validator::e($user['username']) ?></div>
                </div>
                <a href="/user/profile.php" class="block px-4 py-2 text-slate-700 hover:bg-purple-50">Profile &amp; Settings</a>
                <a href="/user/add-funds.php" class="block px-4 py-2 text-slate-700 hover:bg-purple-50">Add Funds</a>
                <a href="/user/tickets.php?store=otp" class="block px-4 py-2 text-slate-700 hover:bg-purple-50">OTP Support</a>
                <div class="border-t border-slate-100 my-1"></div>
                <a href="/user/logout.php" class="block px-4 py-2 text-rose-600 hover:bg-rose-50 font-bold">Logout</a>
            </div>
        </div>
    </div>
</header>

<main class="flex-1 p-4 sm:p-6 lg:p-7 pb-24 lg:pb-8 max-w-[1400px] w-full mx-auto">
    <?php foreach (Helpers::getFlashes() as $f): ?>
    <div class="mb-5 p-4 rounded-2xl text-sm font-semibold border <?= $f['type'] === 'error' ? 'bg-rose-50 border-rose-200 text-rose-700' : 'bg-emerald-50 border-emerald-200 text-emerald-700' ?>">
        <?= Validator::e($f['message']) ?>
    </div>
    <?php endforeach; ?>
