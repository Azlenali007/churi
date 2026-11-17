<?php
$siteName = Helpers::setting('site_name', 'SMM PRO');
$siteTagline = Helpers::setting('site_tagline', 'Grow Faster, Smarter');
$siteLogo = Helpers::setting('site_logo', '');
$siteFavicon = Helpers::setting('site_favicon', '');
$seoTitle = $pageTitle ?? Helpers::setting('seo_title', $siteName . ' — SMM Panel');
$seoDesc = $pageDescription ?? Helpers::setting('seo_description', 'Automated SMM Panel');
$currentUser = Auth::user();
$currentUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$enabledCurrencies = Currency::getEnabledCurrencies();
$activeCurr = Currency::getActiveDisplayCurrency($currentUser);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= Validator::e(CSRF::token()) ?>">
    <title><?= Validator::e($seoTitle) ?></title>
    <meta name="description" content="<?= Validator::e($seoDesc) ?>">
    <meta name="keywords" content="<?= Validator::e(Helpers::setting('seo_keywords', 'smm panel')) ?>">
    <meta property="og:title" content="<?= Validator::e($seoTitle) ?>">
    <meta property="og:description" content="<?= Validator::e($seoDesc) ?>">
    <?php if ($siteFavicon): ?>
    <link rel="icon" href="<?= Validator::e($siteFavicon) ?>">
    <?php endif; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-[#F6F4FC] text-slate-900 antialiased" x-data="{ mobileMenu: false }">
<div id="toast-container" class="fixed bottom-5 right-5 z-50 flex flex-col gap-2 max-w-sm"></div>

<header class="sticky top-0 z-40 bg-white/90 backdrop-blur-md border-b border-purple-100">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-3 sm:gap-4">
        <a href="/" class="flex items-center gap-3 shrink-0">
            <?php if ($siteLogo): ?>
                <img src="<?= Validator::e($siteLogo) ?>" alt="<?= Validator::e($siteName) ?>" class="h-9 w-auto rounded-lg">
            <?php else: ?>
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-600 to-indigo-700 text-white flex items-center justify-center shadow-md shadow-purple-500/25">
                    <svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor"><path d="M5 16L3 5l5.5 5L12 4l3.5 6L21 5l-2 11H5zm0 2h14v2H5v-2z"/></svg>
                </div>
            <?php endif; ?>
            <div>
                <div class="font-extrabold text-lg tracking-tight text-slate-900 leading-none"><?= Validator::e($siteName) ?></div>
                <div class="text-[11px] font-medium text-purple-600"><?= Validator::e($siteTagline) ?></div>
            </div>
        </a>

        <nav class="hidden md:flex items-center gap-7 text-sm font-semibold text-slate-600">
            <a href="/" class="<?= $currentUri === '/' || $currentUri === '/index.php' ? 'text-purple-700' : 'hover:text-purple-700' ?> transition">Home</a>
            <a href="/services.php" class="<?= $currentUri === '/services.php' ? 'text-purple-700' : 'hover:text-purple-700' ?> transition">Services &amp; Pricing</a>
            <a href="/#how-it-works" class="hover:text-purple-700 transition">How It Works</a>
            <a href="/faq.php" class="<?= $currentUri === '/faq.php' ? 'text-purple-700' : 'hover:text-purple-700' ?> transition">FAQ</a>
            <a href="/contact.php" class="<?= $currentUri === '/contact.php' ? 'text-purple-700' : 'hover:text-purple-700' ?> transition">Contact</a>
        </nav>

        <div class="flex items-center gap-2 sm:gap-3">
            <!-- Public Currency Selector Pill (Desktop & Mobile) -->
            <div class="relative" x-data="{ openCurr: false }">
                <button type="button" @click="openCurr = !openCurr"
                        aria-label="Select display currency"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-[#F3F1FC] hover:bg-purple-100 border border-purple-200/80 text-slate-800 text-xs font-extrabold shadow-sm transition">
                    <span class="text-purple-700"><?= Validator::e($activeCurr['symbol']) ?></span>
                    <span><?= Validator::e($activeCurr['code']) ?></span>
                    <svg class="w-3.5 h-3.5 text-slate-500 transition-transform" :class="openCurr ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
                </button>
                <div x-show="openCurr" x-cloak @click.away="openCurr = false"
                     class="absolute right-0 mt-2 w-60 bg-white rounded-2xl shadow-xl border border-purple-100 py-1.5 z-50 overflow-hidden">
                    <div class="px-3.5 py-2 border-b border-purple-50 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                        Display Currency
                    </div>
                    <?php foreach ($enabledCurrencies as $cCode => $cRow):
                        $isCurrent = $cCode === $activeCurr['code'];
                    ?>
                    <button type="button"
                            onclick="window.smmSwitchCurrency('<?= Validator::e($cCode) ?>')"
                            class="w-full px-3.5 py-2.5 text-left flex items-center justify-between gap-2 text-xs transition <?= $isCurrent ? 'bg-purple-50 text-purple-900 font-extrabold' : 'text-slate-700 hover:bg-purple-50/60 font-semibold' ?>">
                        <span class="flex items-center gap-2.5 min-w-0">
                            <span class="w-7 h-7 rounded-xl <?= $isCurrent ? 'bg-purple-600 text-white' : 'bg-purple-100/80 text-purple-700' ?> flex items-center justify-center font-extrabold shrink-0">
                                <?= Validator::e($cRow['symbol']) ?>
                            </span>
                            <span class="truncate">
                                <span class="block leading-tight"><?= Validator::e($cRow['name']) ?></span>
                                <span class="text-[10px] <?= $isCurrent ? 'text-purple-600' : 'text-slate-400' ?> font-bold"><?= Validator::e($cRow['symbol'] . ' ' . $cCode) ?></span>
                            </span>
                        </span>
                        <?php if ($isCurrent): ?>
                        <svg class="w-4 h-4 text-purple-600 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        <?php endif; ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="hidden md:flex items-center gap-2.5">
                <?php if ($currentUser): ?>
                    <a href="/user/dashboard.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-purple-600 to-violet-600 hover:from-purple-700 hover:to-violet-700 text-white text-sm font-bold shadow-md shadow-purple-500/25 transition tabular-nums">
                        Dashboard (<?= Currency::display((string)$currentUser['wallet_balance'], null, 2, true) ?>)
                    </a>
                <?php else: ?>
                    <a href="/login.php" class="px-4 py-2 rounded-xl text-sm font-bold text-slate-700 hover:text-purple-700 hover:bg-purple-50 transition">Sign In</a>
                    <a href="/register.php" class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-purple-600 to-violet-600 hover:from-purple-700 hover:to-violet-700 text-white text-sm font-bold shadow-md shadow-purple-500/25 transition">Get Started</a>
                <?php endif; ?>
            </div>

            <button type="button" @click="mobileMenu = !mobileMenu" class="md:hidden p-2 rounded-xl text-slate-700 hover:bg-purple-50">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
        </div>
    </div>

    <div x-show="mobileMenu" x-cloak class="md:hidden bg-white border-t border-purple-100 px-4 py-4 space-y-2">
        <a href="/" class="block px-3 py-2 rounded-xl text-sm font-semibold text-slate-700 hover:bg-purple-50">Home</a>
        <a href="/services.php" class="block px-3 py-2 rounded-xl text-sm font-semibold text-slate-700 hover:bg-purple-50">Services &amp; Pricing</a>
        <a href="/faq.php" class="block px-3 py-2 rounded-xl text-sm font-semibold text-slate-700 hover:bg-purple-50">FAQ</a>
        <a href="/contact.php" class="block px-3 py-2 rounded-xl text-sm font-semibold text-slate-700 hover:bg-purple-50">Contact</a>
        <div class="pt-2 flex gap-2">
            <?php if ($currentUser): ?>
                <a href="/user/dashboard.php" class="flex-1 text-center py-2.5 rounded-xl bg-purple-600 text-white text-sm font-bold">Dashboard</a>
            <?php else: ?>
                <a href="/login.php" class="flex-1 text-center py-2.5 rounded-xl bg-purple-50 text-purple-700 text-sm font-bold">Sign In</a>
                <a href="/register.php" class="flex-1 text-center py-2.5 rounded-xl bg-purple-600 text-white text-sm font-bold">Register</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<?php foreach (Helpers::getFlashes() as $f): ?>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
    <div class="p-4 rounded-2xl text-sm font-semibold border <?= $f['type'] === 'error' ? 'bg-rose-50 border-rose-200 text-rose-700' : 'bg-emerald-50 border-emerald-200 text-emerald-700' ?>">
        <?= Validator::e($f['message']) ?>
    </div>
</div>
<?php endforeach; ?>
