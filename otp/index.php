<?php
/**
 * Public OTP Store Landing Page
 * Distinct white-and-purple SaaS experience for Direct One-Time SMS/OTP Activations.
 * Guarded by StoreManager::requireOtpEnabled() — never shows a maintenance page when OFF.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
StoreManager::requireOtpEnabled(false);

$user = Auth::user();
$smmEnabled = StoreManager::isSmmEnabled();
$siteName = Helpers::setting('site_name', 'SMM PRO');
$siteFavicon = Helpers::setting('site_favicon', '');
$dispCurrency = Currency::getActiveDisplayCurrency($user);

$services = OtpService::getEnabledServices('', false, 120);
$countries = Database::fetchAll(
    "SELECT id, name, iso_code, dial_code, flag_emoji FROM otp_countries WHERE status = 'active' ORDER BY sort_order ASC, name ASC LIMIT 60"
);
StoreManager::setActiveStore('otp');

$serviceListJs = [];
foreach ($services as $s) {
    $priceBase = (string)$s['selling_price'];
    $serviceListJs[] = [
        'id'            => (int)$s['id'],
        'name'          => (string)$s['name'],
        'slug'          => (string)$s['slug'],
        'icon_key'      => (string)($s['icon_key'] ?? 'general'),
        'description'   => (string)($s['description'] ?? ''),
        'is_popular'    => !empty($s['is_popular']),
        'has_price'     => Currency::cmp($priceBase, '0', 4) > 0,
        'display_price' => Currency::cmp($priceBase, '0', 4) > 0
            ? Currency::display($priceBase, $dispCurrency['code'], 2, true)
            : 'Live Quote',
        'buy_url'       => $user
            ? '/otp/buy.php?service_id=' . (int)$s['id']
            : '/login.php?next=' . urlencode('/otp/buy.php?service_id=' . (int)$s['id']),
    ];
}

$faqs = [
    [
        'q' => 'How does a direct one-time OTP activation work?',
        'a' => 'Choose your target service and country, review the live price, and confirm your activation using your shared wallet balance. A fresh temporary number is assigned immediately so you can receive your verification SMS in real time.',
    ],
    [
        'q' => 'Do I need a separate account or wallet for the OTP Store?',
        'a' => 'No. One account, one login, and one shared wallet work seamlessly across the entire platform. Any funds added to your wallet can be spent on OTP activations and any eligible refunds are credited straight back to your shared wallet.',
    ],
    [
        'q' => 'What happens if no SMS verification code arrives?',
        'a' => 'If an activation expires before an SMS arrives or if you cancel an active request prior to OTP delivery, eligible refunds are returned automatically to your shared wallet with full transaction tracking.',
    ],
    [
        'q' => 'Are these long-term rented phone numbers?',
        'a' => 'No. This store provides fast, direct one-time SMS/OTP activations designed for instant verification rather than number rentals or pre-listed number marketplaces.',
    ],
    [
        'q' => 'How do I get help with an OTP activation?',
        'a' => 'Open our unified Support Tickets portal from your dashboard and select OTP Support. You can attach the exact activation record so our support team can review your order and resolve your issue quickly.',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instant OTP Activations — <?= Validator::e($siteName) ?></title>
    <meta name="description" content="Direct one-time SMS & OTP verification activations with real-time delivery and your shared wallet.">
    <?php if ($siteFavicon): ?>
    <link rel="icon" href="<?= Validator::e($siteFavicon) ?>">
    <?php endif; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="/assets/js/app.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        body { font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-[#FAF8FF] text-slate-900 antialiased selection:bg-purple-200 selection:text-purple-900"
      x-data="{
          mobileNav: false,
          search: '',
          onlyPopular: false,
          openFaq: 0,
          services: <?= Validator::e(json_encode($serviceListJs, JSON_UNESCAPED_UNICODE)) ?>,
          get filteredServices() {
              const q = this.search.trim().toLowerCase();
              return this.services.filter(s => {
                  if (this.onlyPopular && !s.is_popular) return false;
                  if (!q) return true;
                  return s.name.toLowerCase().includes(q) || s.slug.toLowerCase().includes(q) || (s.description || '').toLowerCase().includes(q);
              });
          }
      }">

<!-- Sticky Header -->
<header class="sticky top-0 z-40 bg-white/90 backdrop-blur-xl border-b border-purple-100">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-4">
        <a href="/otp/" class="flex items-center gap-2.5 shrink-0">
            <span class="w-10 h-10 rounded-2xl bg-gradient-to-br from-violet-600 via-purple-600 to-fuchsia-600 text-white flex items-center justify-center shadow-md shadow-purple-500/25">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                    <rect x="5" y="2" width="14" height="20" rx="2.5"/>
                    <path d="M9 10h6M9 14h4"/>
                    <line x1="12" y1="18" x2="12.01" y2="18"/>
                </svg>
            </span>
            <div>
                <div class="text-base font-extrabold tracking-tight text-slate-900 leading-none"><?= Validator::e($siteName) ?> <span class="text-purple-600">OTP</span></div>
                <div class="text-[11px] font-semibold text-slate-500 mt-0.5">Direct SMS Verification</div>
            </div>
        </a>

        <nav class="hidden md:flex items-center gap-7 text-sm font-semibold text-slate-600">
            <a href="#services" class="hover:text-purple-700 transition">Services</a>
            <a href="#countries" class="hover:text-purple-700 transition">Countries</a>
            <a href="#how-it-works" class="hover:text-purple-700 transition">How It Works</a>
            <a href="#faq" class="hover:text-purple-700 transition">FAQ</a>
        </nav>

        <div class="hidden md:flex items-center gap-2.5">
            <?php if ($smmEnabled): ?>
            <a href="/store-switch.php?to=smm" data-store-switch="smm" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-purple-200 bg-purple-50/70 text-purple-800 text-xs font-bold hover:bg-purple-100 transition">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                <span>Switch to SMM Store</span>
            </a>
            <?php endif; ?>

            <?php if ($user): ?>
            <a href="/otp/dashboard.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-violet-600 to-purple-600 text-white text-xs font-extrabold shadow-md shadow-purple-600/20 hover:opacity-95 transition">
                <span>OTP Dashboard</span>
                <span class="px-2 py-0.5 rounded-lg bg-white/20 text-[11px] tabular-nums"><?= Currency::display((string)$user['wallet_balance'], $dispCurrency['code'], 2, true) ?></span>
            </a>
            <?php else: ?>
            <a href="/login.php?next=<?= urlencode('/otp/dashboard.php') ?>" class="px-4 py-2 rounded-xl text-xs font-bold text-slate-700 hover:bg-purple-50 hover:text-purple-700 transition">Sign In</a>
            <a href="/register.php" class="px-4 py-2 rounded-xl bg-gradient-to-r from-violet-600 to-purple-600 text-white text-xs font-extrabold shadow-md shadow-purple-600/20 hover:opacity-95 transition">Create Account</a>
            <?php endif; ?>
        </div>

        <button type="button" @click="mobileNav = !mobileNav" aria-label="Toggle Menu" class="md:hidden p-2 rounded-xl text-purple-700 hover:bg-purple-50">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
    </div>

    <!-- Mobile Navigation Drawer -->
    <div x-show="mobileNav" x-cloak class="md:hidden border-t border-purple-100 bg-white px-4 py-4 space-y-2.5 shadow-lg">
        <a @click="mobileNav=false" href="#services" class="block px-3 py-2 rounded-xl text-sm font-semibold text-slate-700 hover:bg-purple-50">Services</a>
        <a @click="mobileNav=false" href="#countries" class="block px-3 py-2 rounded-xl text-sm font-semibold text-slate-700 hover:bg-purple-50">Countries</a>
        <a @click="mobileNav=false" href="#how-it-works" class="block px-3 py-2 rounded-xl text-sm font-semibold text-slate-700 hover:bg-purple-50">How It Works</a>
        <a @click="mobileNav=false" href="#faq" class="block px-3 py-2 rounded-xl text-sm font-semibold text-slate-700 hover:bg-purple-50">FAQ</a>
        <div class="pt-2 border-t border-purple-100 flex flex-col gap-2">
            <?php if ($smmEnabled): ?>
            <a href="/store-switch.php?to=smm" data-store-switch="smm" class="w-full text-center px-4 py-2.5 rounded-xl border border-purple-200 bg-purple-50 text-purple-800 text-xs font-bold">Switch to SMM Store</a>
            <?php endif; ?>
            <?php if ($user): ?>
            <a href="/otp/dashboard.php" class="w-full text-center px-4 py-2.5 rounded-xl bg-gradient-to-r from-violet-600 to-purple-600 text-white text-xs font-extrabold">Open OTP Dashboard</a>
            <?php else: ?>
            <a href="/login.php?next=<?= urlencode('/otp/dashboard.php') ?>" class="w-full text-center px-4 py-2.5 rounded-xl bg-slate-100 text-slate-800 text-xs font-bold">Sign In</a>
            <a href="/register.php" class="w-full text-center px-4 py-2.5 rounded-xl bg-gradient-to-r from-violet-600 to-purple-600 text-white text-xs font-extrabold">Create Account</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<!-- Hero Section -->
<section class="relative overflow-hidden bg-gradient-to-b from-white via-[#F6F2FF] to-[#FAF8FF] border-b border-purple-100/80">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-14 sm:py-20 lg:py-24">
        <div class="grid lg:grid-cols-12 gap-10 lg:gap-12 items-center">
            <div class="lg:col-span-7">
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-purple-100/80 border border-purple-200 text-purple-800 text-xs font-bold mb-6">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    <span>Direct One-Time SMS &amp; OTP Activations</span>
                </div>

                <h1 class="text-3xl sm:text-5xl font-extrabold tracking-tight text-slate-950 leading-[1.12]">
                    Receive Instant <span class="bg-gradient-to-r from-violet-600 to-fuchsia-600 bg-clip-text text-transparent">OTP Verification</span> Codes Without Waiting
                </h1>

                <p class="mt-5 text-base sm:text-lg text-slate-600 leading-relaxed max-w-2xl">
                    Select any supported app and country, confirm your one-time activation using your shared wallet balance, and receive real-time SMS codes straight inside your dashboard—backed by automatic refund protection if a code does not arrive.
                </p>

                <!-- Quick Search Bar -->
                <div class="mt-7 max-w-xl">
                    <div class="relative flex items-center bg-white rounded-2xl border border-purple-200 shadow-lg shadow-purple-900/5 p-1.5">
                        <svg class="w-5 h-5 text-purple-400 ml-3 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                        <input type="text" x-model="search" @keydown.enter="document.getElementById('services').scrollIntoView({behavior:'smooth'})"
                               placeholder="Search a service (e.g. WhatsApp, Telegram, Google)..."
                               class="w-full px-3 py-2.5 text-sm text-slate-800 placeholder-slate-400 bg-transparent outline-none">
                        <a href="#services" class="px-4 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-xs font-extrabold shrink-0 transition">
                            Browse
                        </a>
                    </div>
                </div>

                <div class="mt-7 flex flex-wrap items-center gap-3">
                    <a href="<?= $user ? '/otp/buy.php' : '/login.php?next=' . urlencode('/otp/buy.php') ?>"
                       class="inline-flex items-center gap-2 px-6 py-3.5 rounded-2xl bg-gradient-to-r from-violet-600 via-purple-600 to-fuchsia-600 text-white text-sm font-extrabold shadow-lg shadow-purple-600/25 hover:opacity-95 transition">
                        <span>Buy OTP Activation</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                    </a>
                    <a href="#how-it-works" class="inline-flex items-center gap-2 px-5 py-3.5 rounded-2xl bg-white border border-purple-200 text-slate-800 text-sm font-bold hover:bg-purple-50 transition">
                        <span>How It Works</span>
                    </a>
                </div>

                <div class="mt-8 flex flex-wrap items-center gap-x-6 gap-y-2 text-xs font-semibold text-slate-500">
                    <span class="inline-flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        One Shared Account &amp; Wallet
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        Assigned Only After Purchase
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        Auto-Refund on Undelivered Expiry
                    </span>
                </div>
            </div>

            <!-- Right Live Workflow Schematic Card -->
            <div class="lg:col-span-5">
                <div class="bg-white rounded-3xl border border-purple-100 shadow-2xl shadow-purple-900/10 p-6 sm:p-7">
                    <div class="flex items-center justify-between pb-4 border-b border-purple-50">
                        <div>
                            <div class="text-xs font-extrabold uppercase tracking-wider text-purple-600">Direct Activation Flow</div>
                            <div class="text-base font-extrabold text-slate-900 mt-0.5">Instant SMS Dispatch</div>
                        </div>
                        <span class="px-2.5 py-1 rounded-full bg-purple-50 border border-purple-100 text-[11px] font-bold text-purple-700">
                            <?= count($services) ?> Services
                        </span>
                    </div>

                    <div class="mt-5 space-y-3.5">
                        <div class="flex items-start gap-3.5 p-3.5 rounded-2xl bg-[#FAF8FF] border border-purple-100">
                            <span class="w-8 h-8 rounded-xl bg-purple-600 text-white font-extrabold text-xs flex items-center justify-center shrink-0">01</span>
                            <div>
                                <div class="text-xs font-extrabold text-slate-900">Select Service &amp; Country</div>
                                <div class="text-xs text-slate-500 mt-0.5">Real-time availability and live selling price calculated before checkout.</div>
                            </div>
                        </div>
                        <div class="flex items-start gap-3.5 p-3.5 rounded-2xl bg-[#FAF8FF] border border-purple-100">
                            <span class="w-8 h-8 rounded-xl bg-violet-600 text-white font-extrabold text-xs flex items-center justify-center shrink-0">02</span>
                            <div>
                                <div class="text-xs font-extrabold text-slate-900">Atomic Wallet Checkout</div>
                                <div class="text-xs text-slate-500 mt-0.5">Debits your shared wallet once and requests a live activation number.</div>
                            </div>
                        </div>
                        <div class="flex items-start gap-3.5 p-3.5 rounded-2xl bg-emerald-50/70 border border-emerald-200/70">
                            <span class="w-8 h-8 rounded-xl bg-emerald-600 text-white font-extrabold text-xs flex items-center justify-center shrink-0">03</span>
                            <div>
                                <div class="text-xs font-extrabold text-slate-900">Live SMS Polling &amp; Copy</div>
                                <div class="text-xs text-slate-600 mt-0.5">Assigned number appears immediately with countdown timer and live OTP code arrival.</div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 pt-4 border-t border-purple-50 grid grid-cols-3 gap-3 text-center">
                        <div class="p-2.5 rounded-xl bg-slate-50">
                            <div class="text-lg font-extrabold text-slate-900 tabular-nums"><?= count($services) ?></div>
                            <div class="text-[11px] font-semibold text-slate-500">Services</div>
                        </div>
                        <div class="p-2.5 rounded-xl bg-slate-50">
                            <div class="text-lg font-extrabold text-slate-900 tabular-nums"><?= count($countries) ?></div>
                            <div class="text-[11px] font-semibold text-slate-500">Countries</div>
                        </div>
                        <div class="p-2.5 rounded-xl bg-slate-50">
                            <div class="text-sm font-extrabold text-purple-700 leading-7">Auto-Refund</div>
                            <div class="text-[11px] font-semibold text-slate-500">Protected</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Enabled OTP Services Directory -->
<section id="services" class="py-16 sm:py-20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 mb-8">
            <div>
                <div class="text-xs font-extrabold uppercase tracking-wider text-purple-600">Supported Platforms</div>
                <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-950 mt-1">Available OTP Services</h2>
                <p class="text-sm text-slate-600 mt-1">Choose a service to verify with fast one-time SMS delivery.</p>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" @click="onlyPopular = false"
                        :class="!onlyPopular ? 'bg-purple-600 text-white shadow-sm' : 'bg-white text-slate-700 border border-purple-100 hover:bg-purple-50'"
                        class="px-4 py-2 rounded-xl text-xs font-bold transition">All Services</button>
                <button type="button" @click="onlyPopular = true"
                        :class="onlyPopular ? 'bg-purple-600 text-white shadow-sm' : 'bg-white text-slate-700 border border-purple-100 hover:bg-purple-50'"
                        class="px-4 py-2 rounded-xl text-xs font-bold transition">Popular</button>
            </div>
        </div>

        <?php if (empty($services)): ?>
        <div class="bg-white rounded-3xl border border-purple-100 p-10 text-center max-w-2xl mx-auto">
            <div class="w-12 h-12 mx-auto rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center mb-3">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="2.5"/><path d="M9 10h6M9 14h4"/></svg>
            </div>
            <h3 class="text-base font-extrabold text-slate-900">OTP Services Updating</h3>
            <p class="text-sm text-slate-500 mt-1">Our OTP service catalog is currently being updated. Please check back shortly or contact support.</p>
        </div>
        <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4" x-show="filteredServices.length > 0">
            <template x-for="svc in filteredServices" :key="svc.id">
                <div class="group bg-white rounded-2xl border border-purple-100 hover:border-purple-300 hover:shadow-lg hover:shadow-purple-900/5 p-5 flex flex-col justify-between transition">
                    <div>
                        <div class="flex items-start justify-between gap-3">
                            <div class="w-11 h-11 rounded-2xl bg-purple-50 text-purple-700 border border-purple-100 flex items-center justify-center font-extrabold text-sm uppercase shrink-0"
                                 x-text="svc.name.slice(0,2)"></div>
                            <span x-show="svc.is_popular" class="px-2.5 py-0.5 rounded-full bg-amber-50 border border-amber-200 text-amber-700 text-[10px] font-extrabold uppercase">Popular</span>
                        </div>
                        <h3 class="mt-3.5 text-base font-extrabold text-slate-900 group-hover:text-purple-700 transition" x-text="svc.name"></h3>
                        <p class="mt-1 text-xs text-slate-500 line-clamp-2" x-text="svc.description || 'Direct one-time SMS verification activation.'"></p>
                    </div>

                    <div class="mt-5 pt-3.5 border-t border-purple-50 flex items-center justify-between gap-2">
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Starting From</div>
                            <div class="text-sm font-extrabold text-slate-900 tabular-nums" x-text="svc.display_price"></div>
                        </div>
                        <a :href="svc.buy_url" class="inline-flex items-center gap-1 px-3.5 py-2 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-xs font-extrabold transition">
                            <span>Activate</span>
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                        </a>
                    </div>
                </div>
            </template>
        </div>

        <div x-show="filteredServices.length === 0" x-cloak class="bg-white rounded-2xl border border-purple-100 p-8 text-center">
            <p class="text-sm font-semibold text-slate-600">No services matched "<span x-text="search" class="text-purple-700"></span>".</p>
            <button type="button" @click="search = ''; onlyPopular = false" class="mt-3 px-4 py-2 rounded-xl bg-purple-50 text-purple-700 text-xs font-bold hover:bg-purple-100">Reset Filters</button>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- Country Availability Preview -->
<section id="countries" class="py-14 bg-white border-y border-purple-100/80">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="max-w-2xl mb-8">
            <div class="text-xs font-extrabold uppercase tracking-wider text-purple-600">Global Coverage</div>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-950 mt-1">Supported Countries</h2>
            <p class="text-sm text-slate-600 mt-1">Choose your preferred region during checkout. Live availability is verified automatically before charging your wallet.</p>
        </div>

        <?php if (empty($countries)): ?>
        <div class="rounded-2xl bg-[#FAF8FF] border border-purple-100 p-6 text-sm text-slate-500">
            Country availability is currently being updated. Please check back shortly.
        </div>
        <?php else: ?>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
            <?php foreach ($countries as $c): ?>
            <div class="flex items-center gap-2.5 px-3.5 py-3 rounded-2xl bg-[#FAF8FF] border border-purple-100/80">
                <span class="text-lg leading-none"><?= Validator::e($c['flag_emoji'] ?: '🌐') ?></span>
                <div class="min-w-0">
                    <div class="text-xs font-extrabold text-slate-900 truncate"><?= Validator::e($c['name']) ?></div>
                    <div class="text-[11px] font-semibold text-slate-500"><?= Validator::e($c['iso_code']) ?><?= $c['dial_code'] ? ' · ' . Validator::e($c['dial_code']) : '' ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- 3-Step How It Works -->
<section id="how-it-works" class="py-16 sm:py-20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center max-w-2xl mx-auto mb-12">
            <div class="text-xs font-extrabold uppercase tracking-wider text-purple-600">Simple &amp; Transparent</div>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-950 mt-1">How Direct OTP Activation Works</h2>
        </div>

        <div class="grid md:grid-cols-3 gap-6">
            <div class="bg-white rounded-3xl border border-purple-100 p-7 shadow-sm">
                <div class="w-12 h-12 rounded-2xl bg-purple-100 text-purple-700 font-extrabold text-lg flex items-center justify-center mb-5">1</div>
                <h3 class="text-base font-extrabold text-slate-900">Select Service &amp; Country</h3>
                <p class="mt-2 text-sm text-slate-600 leading-relaxed">Pick the platform you need to verify and select an available country. Live pricing and availability are fetched directly before you confirm.</p>
            </div>
            <div class="bg-white rounded-3xl border border-purple-100 p-7 shadow-sm">
                <div class="w-12 h-12 rounded-2xl bg-violet-100 text-violet-700 font-extrabold text-lg flex items-center justify-center mb-5">2</div>
                <h3 class="text-base font-extrabold text-slate-900">Instant Activation &amp; Number</h3>
                <p class="mt-2 text-sm text-slate-600 leading-relaxed">Your shared wallet is charged atomically and a fresh number is assigned exclusively for your activation session.</p>
            </div>
            <div class="bg-white rounded-3xl border border-purple-100 p-7 shadow-sm">
                <div class="w-12 h-12 rounded-2xl bg-emerald-100 text-emerald-700 font-extrabold text-lg flex items-center justify-center mb-5">3</div>
                <h3 class="text-base font-extrabold text-slate-900">Read Live SMS &amp; Complete</h3>
                <p class="mt-2 text-sm text-slate-600 leading-relaxed">Your dashboard auto-polls for the incoming SMS code in real time. Copy your OTP with one tap, or receive an automatic refund if expired undelivered.</p>
            </div>
        </div>
    </div>
</section>

<!-- FAQ Accordion -->
<section id="faq" class="py-16 bg-white border-t border-purple-100/80">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-10">
            <div class="text-xs font-extrabold uppercase tracking-wider text-purple-600">Questions &amp; Answers</div>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-950 mt-1">Frequently Asked Questions</h2>
        </div>

        <div class="space-y-3">
            <?php foreach ($faqs as $idx => $faq): ?>
            <div class="rounded-2xl border border-purple-100 bg-[#FAF8FF] overflow-hidden">
                <button type="button" @click="openFaq = openFaq === <?= $idx ?> ? -1 : <?= $idx ?>"
                        class="w-full px-5 py-4 text-left flex items-center justify-between gap-4 font-bold text-sm text-slate-900">
                    <span><?= Validator::e($faq['q']) ?></span>
                    <svg class="w-4 h-4 text-purple-600 shrink-0 transition-transform" :class="openFaq === <?= $idx ?> ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
                </button>
                <div x-show="openFaq === <?= $idx ?>" x-cloak class="px-5 pb-4 text-sm text-slate-600 leading-relaxed">
                    <?= Validator::e($faq['a']) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Footer -->
<footer class="bg-slate-950 text-slate-400 py-10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs">
        <div class="flex items-center gap-2 text-white font-extrabold text-sm">
            <span><?= Validator::e($siteName) ?> OTP Store</span>
            <span class="text-slate-500 font-normal">&copy; <?= date('Y') ?></span>
        </div>
        <div class="flex flex-wrap items-center gap-5 font-semibold">
            <?php if ($smmEnabled): ?>
            <a href="/store-switch.php?to=smm" data-store-switch="smm" class="hover:text-white transition">Switch to SMM Store</a>
            <?php endif; ?>
            <a href="<?= $user ? '/otp/dashboard.php' : '/login.php?next=' . urlencode('/otp/dashboard.php') ?>" class="hover:text-white transition">OTP Dashboard</a>
            <a href="<?= $user ? '/user/tickets.php?store=otp' : '/login.php?next=' . urlencode('/user/tickets.php?store=otp') ?>" class="hover:text-white transition">Support</a>
            <a href="/terms.php" class="hover:text-white transition">Terms</a>
        </div>
    </div>
</footer>
</body>
</html>
