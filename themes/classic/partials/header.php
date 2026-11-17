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
?>
<header class="sticky top-0 z-30 bg-white/90 backdrop-blur-md border-b border-purple-100/80 px-4 sm:px-7 h-16 flex items-center justify-between gap-4">
    <div class="flex items-center gap-3 flex-1 min-w-0">
        <button type="button" @click="sidebarOpen = true" class="lg:hidden p-2 rounded-xl text-slate-700 hover:bg-purple-50">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>

        <form action="/user/services.php" method="GET" class="relative w-full max-w-md hidden sm:block">
            <svg class="w-4 h-4 text-purple-400 absolute left-3.5 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
            <input type="text" name="search" value="<?= Validator::e($_GET['search'] ?? '') ?>"
                   placeholder="Search services..."
                   class="w-full pl-10 pr-4 py-2 rounded-full bg-[#F3F1FC] border border-purple-100 focus:bg-white focus:border-purple-400 outline-none text-sm text-slate-800 placeholder-slate-400 transition">
        </form>
    </div>

    <div class="flex items-center gap-3 sm:gap-5">
        <a href="/user/add-funds.php" class="hidden md:inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full bg-purple-50 border border-purple-200 text-purple-800 text-xs font-bold hover:bg-purple-100 transition">
            <span>Wallet:</span>
            <span><?= Currency::format((string)$user['wallet_balance']) ?></span>
        </a>

        <!-- Notification Bell -->
        <div class="relative" x-data="{ open: false }">
            <button type="button" @click="open = !open" class="relative p-2 rounded-full hover:bg-purple-50 text-slate-600 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <?php if ($unreadCount > 0): ?>
                <span class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-rose-500 text-white text-[10px] font-bold flex items-center justify-center"><?= $unreadCount ?></span>
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

        <!-- User Profile Dropdown -->
        <div class="relative" x-data="{ open: false }">
            <button type="button" @click="open = !open" class="flex items-center gap-2.5 p-1 rounded-full hover:bg-purple-50 transition">
                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-purple-600 to-indigo-600 text-white font-bold text-sm flex items-center justify-center shadow-sm">
                    <?= Validator::e($initials) ?>
                </div>
                <div class="hidden sm:block text-left">
                    <div class="text-xs font-bold text-slate-900 leading-tight"><?= Validator::e($user['full_name']) ?></div>
                    <div class="text-[11px] text-slate-500">@<?= Validator::e($user['username']) ?></div>
                </div>
                <svg class="w-4 h-4 text-slate-400 hidden sm:block" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div x-show="open" x-cloak @click.away="open = false"
                 class="absolute right-0 mt-2 w-48 bg-white rounded-2xl shadow-xl border border-purple-100 py-1.5 z-40 text-sm">
                <a href="/user/profile.php" class="block px-4 py-2 text-slate-700 hover:bg-purple-50 font-medium">Profile & Settings</a>
                <a href="/user/add-funds.php" class="block px-4 py-2 text-slate-700 hover:bg-purple-50 font-medium">Add Funds</a>
                <a href="/user/reseller-api.php" class="block px-4 py-2 text-slate-700 hover:bg-purple-50 font-medium">API Key</a>
                <div class="border-t border-slate-100 my-1"></div>
                <a href="/user/logout.php" class="block px-4 py-2 text-rose-600 hover:bg-rose-50 font-semibold">Logout</a>
            </div>
        </div>
    </div>
</header>

<main class="flex-1 p-4 sm:p-6 lg:p-7 max-w-[1440px] w-full mx-auto">
    <?php foreach (Helpers::getFlashes() as $f): ?>
    <div class="mb-5 p-4 rounded-2xl text-sm font-semibold border <?= $f['type'] === 'error' ? 'bg-rose-50 border-rose-200 text-rose-700' : 'bg-emerald-50 border-emerald-200 text-emerald-700' ?>">
        <?= Validator::e($f['message']) ?>
    </div>
    <?php endforeach; ?>
