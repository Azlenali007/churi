<header class="sticky top-0 z-30 bg-white/90 backdrop-blur-md border-b border-purple-100 px-4 sm:px-7 h-16 flex items-center justify-between gap-4">
    <div class="flex items-center gap-3">
        <button type="button" @click="sidebarOpen = true" class="lg:hidden p-2 rounded-xl text-slate-700 hover:bg-purple-50">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <h1 class="text-base sm:text-lg font-extrabold text-slate-900"><?= Validator::e($pageTitle ?? 'Admin Dashboard') ?></h1>
    </div>

    <div class="flex items-center gap-3">
        <a href="/admin/currencies.php" class="hidden md:inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-purple-50 border border-purple-200 text-purple-800 text-xs font-bold hover:bg-purple-100">
            <span>Base: <?= Validator::e(Currency::getSymbol(Currency::getBaseCode()) . ' ' . Currency::getBaseCode()) ?></span>
        </a>
        <span class="hidden sm:inline-flex px-3 py-1 rounded-full bg-purple-100 text-purple-800 text-xs font-bold">
            <?= Validator::e($admin['role_name']) ?>
        </span>
        <div class="text-right">
            <div class="text-xs font-bold text-slate-900"><?= Validator::e($admin['full_name']) ?></div>
            <div class="text-[11px] text-slate-500">@<?= Validator::e($admin['username']) ?></div>
        </div>
    </div>
</header>

<main class="flex-1 p-4 sm:p-6 lg:p-7 max-w-[1520px] w-full mx-auto">
    <?php foreach (Helpers::getFlashes() as $f): ?>
    <div class="mb-5 p-4 rounded-2xl text-sm font-semibold border <?= $f['type'] === 'error' ? 'bg-rose-50 border-rose-200 text-rose-700' : 'bg-emerald-50 border-emerald-200 text-emerald-700' ?>">
        <?= Validator::e($f['message']) ?>
    </div>
    <?php endforeach; ?>
