<?php
/**
 * Buy OTP Activation — Mobile-First Compact Purchase Workbench
 * - Guarded by StoreManager::requireOtpEnabled().
 * - Never displays phone numbers prior to purchase.
 * - Debits shared wallet atomically and reveals assigned number only after live assignment.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$user = Auth::requireUser();
StoreManager::requireOtpEnabled(false);

$pageTitle = 'Buy OTP Activation';
$dispCurrency = Currency::getActiveDisplayCurrency($user);
$preselectServiceId = (int)($_GET['service_id'] ?? 0);
$pollIntervalSec = max(3, (int)Helpers::setting('otp_polling_interval', '5'));

/**
 * Map a service's name/slug/icon_key into a clean storefront category tab.
 */
$classifyService = static function (array $s): string {
    $hay = strtolower(trim(($s['name'] ?? '') . ' ' . ($s['slug'] ?? '') . ' ' . ($s['icon_key'] ?? '')));
    if (preg_match('/whatsapp|telegram|viber|signal|wechat|line|imo|skype|discord|slack|kakaotalk|messenger/', $hay)) {
        return 'messaging';
    }
    if (preg_match('/instagram|facebook|tiktok|twitter|x\.com|snapchat|linkedin|reddit|pinterest|youtube|twitch|threads|tinder|bumble/', $hay)) {
        return 'social';
    }
    if (preg_match('/paypal|stripe|binance|coinbase|wise|revolut|cashapp|venmo|paytm|phonepe|gpay|bank|crypto|okx|bybit|kucoin/', $hay)) {
        return 'finance';
    }
    if (preg_match('/amazon|ebay|flipkart|shopify|uber|doordash|grab|swiggy|zomato|walmart|temu|aliexpress|etsy|airbnb|olx/', $hay)) {
        return 'shopping';
    }
    return 'other';
};

$services = OtpService::getEnabledServices('', false, 200);
$servicesJs = [];
$iconHtmlMap = [];
$categoryCounts = [
    'all'       => 0,
    'popular'   => 0,
    'messaging' => 0,
    'social'    => 0,
    'finance'   => 0,
    'shopping'  => 0,
    'other'     => 0,
];

foreach ($services as $s) {
    $priceBase = (string)$s['selling_price'];
    $iconKey = strtolower(trim((string)($s['icon_key'] ?? 'general'))) ?: 'general';
    if (!isset($iconHtmlMap[$iconKey])) {
        $iconHtmlMap[$iconKey] = Helpers::platformIcon($iconKey, 'w-7 h-7');
    }
    $cat = $classifyService($s);
    $isPop = !empty($s['is_popular']);

    $categoryCounts['all']++;
    if ($isPop) {
        $categoryCounts['popular']++;
    }
    $categoryCounts[$cat] = ($categoryCounts[$cat] ?? 0) + 1;

    $servicesJs[] = [
        'id'            => (int)$s['id'],
        'name'          => (string)$s['name'],
        'slug'          => (string)$s['slug'],
        'icon_key'      => $iconKey,
        'category'      => $cat,
        'description'   => (string)($s['description'] ?? ''),
        'is_popular'    => $isPop,
        'display_price' => Currency::cmp($priceBase, '0', 4) > 0
            ? Currency::display($priceBase, $dispCurrency['code'], 2, true)
            : 'Check Price',
    ];
}

$categoryTabs = [
    ['key' => 'all',       'label' => 'All'],
    ['key' => 'popular',   'label' => 'Popular'],
    ['key' => 'messaging', 'label' => 'Messaging'],
    ['key' => 'social',    'label' => 'Social'],
    ['key' => 'finance',   'label' => 'Finance'],
    ['key' => 'shopping',  'label' => 'Shopping'],
    ['key' => 'other',     'label' => 'Other'],
];

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div x-data="otpBuyFlow()" x-init="init()" class="space-y-4 pb-44 lg:pb-8">
    <!-- Compact Wallet & Quick Status Strip -->
    <div class="bg-white rounded-2xl border border-purple-100 px-4 py-3 flex flex-wrap items-center justify-between gap-3 shadow-xs">
        <div class="flex items-center gap-2.5 min-w-0">
            <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-violet-600 to-purple-600 text-white flex items-center justify-center shrink-0 shadow-sm shadow-purple-600/20">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.3" viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
            </span>
            <div class="min-w-0">
                <h1 class="text-sm sm:text-base font-extrabold text-slate-900 leading-tight truncate">Instant OTP Activation</h1>
                <p class="text-[11px] text-slate-500 truncate">Pick a service and country to receive your verification SMS immediately.</p>
            </div>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <div class="px-3 py-1.5 rounded-xl bg-[#FAF8FF] border border-purple-100 text-xs font-semibold text-slate-600">
                Wallet: <span class="font-extrabold text-purple-700 tabular-nums" data-otp-wallet-balance><?= Currency::renderMoney((string)$user['wallet_balance'], null, 2, true) ?></span>
            </div>
            <a href="/user/add-funds.php?store=otp"
               class="px-3 py-1.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-xs font-extrabold shadow-xs transition">
                + Add Funds
            </a>
        </div>
    </div>

    <!-- Live Post-Purchase Activation Card (Shown ONLY AFTER Successful Purchase) -->
    <template x-if="purchasedActivation">
        <div id="live-activation-card" class="bg-white rounded-3xl border-2 border-purple-300 shadow-xl shadow-purple-900/10 p-5 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3 pb-4 border-b border-purple-100">
                <div>
                    <div class="text-[11px] font-extrabold uppercase tracking-wider text-purple-600">Active Verification Session</div>
                    <h2 class="text-lg font-extrabold text-slate-900 mt-0.5">
                        <span x-text="purchasedActivation.service_name"></span>
                        <span class="text-slate-400 font-normal">&middot;</span>
                        <span x-text="(purchasedActivation.country_flag ? purchasedActivation.country_flag + ' ' : '') + purchasedActivation.country_name"></span>
                    </h2>
                    <div class="text-xs text-slate-500 font-mono mt-0.5">
                        ID: <span x-text="purchasedActivation.activation_code"></span>
                        <span class="mx-1">&middot;</span>
                        Paid: <span class="font-bold text-slate-800 font-sans" x-text="purchasedActivation.display_price"></span>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="px-3 py-1 rounded-full text-xs font-extrabold uppercase"
                          :class="purchasedActivation.otp_code ? 'bg-emerald-100 text-emerald-800' : 'bg-purple-100 text-purple-800'"
                          x-text="purchasedActivation.status.replace('_', ' ')"></span>
                    <span class="px-3 py-1 rounded-full text-xs font-mono font-extrabold bg-slate-900 text-white tabular-nums"
                          x-text="formatTimer(liveRemaining)"></span>
                </div>
            </div>

            <div class="mt-4 grid md:grid-cols-2 gap-4">
                <!-- Assigned Phone Number -->
                <div class="p-4 rounded-2xl bg-[#FAF8FF] border border-purple-100">
                    <div class="text-[11px] font-extrabold uppercase tracking-wider text-slate-500">Assigned Phone Number</div>
                    <div class="mt-2 flex items-center justify-between gap-3">
                        <span class="text-xl sm:text-2xl font-mono font-extrabold text-slate-950 select-all break-all"
                              x-text="purchasedActivation.phone_number"></span>
                        <button type="button" @click="window.copyText(purchasedActivation.phone_number, 'Phone number copied!')"
                                class="px-3.5 py-2 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-xs font-extrabold shrink-0 transition">
                            Copy Number
                        </button>
                    </div>
                    <p class="mt-2 text-[11px] text-slate-500">
                        Use this number on <span x-text="purchasedActivation.service_name" class="font-bold text-slate-700"></span> to request your SMS verification code.
                    </p>
                </div>

                <!-- Live OTP Code Box -->
                <div class="p-4 rounded-2xl border" :class="purchasedActivation.otp_code ? 'bg-emerald-50 border-emerald-200' : 'bg-amber-50/60 border-amber-200'">
                    <div class="text-[11px] font-extrabold uppercase tracking-wider"
                         :class="purchasedActivation.otp_code ? 'text-emerald-700' : 'text-amber-700'">
                        <span x-text="purchasedActivation.otp_code ? 'Verification Code Received' : 'Waiting for SMS Code...'"></span>
                    </div>
                    <template x-if="purchasedActivation.otp_code">
                        <div class="mt-2">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-2xl sm:text-3xl font-mono font-extrabold text-emerald-800 tracking-wider select-all"
                                      x-text="purchasedActivation.otp_code"></span>
                                <button type="button" @click="window.copyText(purchasedActivation.otp_code, 'OTP code copied!')"
                                        class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-extrabold shrink-0 transition">
                                    Copy OTP
                                </button>
                            </div>
                            <div x-show="purchasedActivation.sms_text"
                                 class="mt-2 text-xs text-emerald-900 bg-white/80 rounded-xl p-2.5 border border-emerald-200 break-words"
                                 x-text="purchasedActivation.sms_text"></div>
                        </div>
                    </template>
                    <template x-if="!purchasedActivation.otp_code">
                        <div class="mt-2.5 flex items-center gap-2.5 text-xs text-amber-800 font-semibold">
                            <svg class="w-4 h-4 animate-spin shrink-0" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                            </svg>
                            <span>Checking automatically every <?= $pollIntervalSec ?>s for incoming SMS...</span>
                        </div>
                    </template>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-purple-50">
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" @click="checkLiveNow(true)" :disabled="pollingNow"
                            class="px-4 py-2 rounded-xl bg-purple-100 hover:bg-purple-200 text-purple-800 text-xs font-extrabold transition">
                        <span x-text="pollingNow ? 'Checking...' : 'Check SMS Now'"></span>
                    </button>
                    <button type="button" x-show="purchasedActivation.can_complete" @click="finishLive()"
                            class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-extrabold transition">
                        Complete
                    </button>
                    <button type="button" x-show="purchasedActivation.can_cancel" @click="cancelLive()"
                            class="px-4 py-2 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-xs font-extrabold transition">
                        Cancel &amp; Refund
                    </button>
                </div>
                <div class="flex items-center gap-2">
                    <a href="/otp/activations.php" class="px-4 py-2 rounded-xl bg-slate-900 text-white text-xs font-extrabold hover:bg-slate-800 transition">Active Activations</a>
                    <button type="button" @click="startAnother()" class="px-4 py-2 rounded-xl bg-purple-50 text-purple-700 text-xs font-bold hover:bg-purple-100 transition">Buy Another</button>
                </div>
            </div>
        </div>
    </template>

    <?php if (empty($services)): ?>
    <div class="bg-white rounded-3xl border border-purple-100 p-10 text-center">
        <div class="w-12 h-12 mx-auto rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center mb-3">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="2.5"/><path d="M9 10h6M9 14h4"/></svg>
        </div>
        <h2 class="text-base font-extrabold text-slate-900">OTP Services Are Updating</h2>
        <p class="text-sm text-slate-500 mt-1 max-w-md mx-auto">Our verification catalog is currently being refreshed. Please check back shortly or reach out to support.</p>
        <a href="/user/tickets.php?store=otp" class="mt-4 inline-flex px-4 py-2 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-xs font-extrabold">Contact Support</a>
    </div>
    <?php else: ?>

    <div class="grid lg:grid-cols-12 gap-5 items-start">
        <!-- Left 8 Cols: Fast Service Selector + Country Selector -->
        <div class="lg:col-span-8 space-y-4 min-w-0">

            <!-- 1. Service Selector Card -->
            <section class="bg-white rounded-3xl border border-purple-100 p-4 sm:p-5 shadow-xs">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5 mb-3">
                    <div class="flex items-center gap-2">
                        <span class="w-6 h-6 rounded-lg text-[11px] font-extrabold flex items-center justify-center shrink-0"
                              :class="selectedService ? 'bg-emerald-100 text-emerald-700' : 'bg-purple-600 text-white'">1</span>
                        <h2 class="text-sm font-extrabold text-slate-900">Choose Service</h2>
                        <span class="text-[11px] font-bold text-slate-400 tabular-nums" x-text="'(' + filteredServices.length + ')'"></span>
                    </div>
                    <div class="relative w-full sm:w-64">
                        <svg class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                        <input type="text" x-model="serviceSearch" placeholder="Search WhatsApp, Google..."
                               class="w-full pl-9 pr-8 py-2 rounded-xl bg-[#FAF8FF] border border-purple-100 focus:border-purple-500 focus:bg-white outline-none text-xs font-semibold transition">
                        <button type="button" x-show="serviceSearch" @click="serviceSearch = ''" aria-label="Clear search"
                                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 text-sm font-bold">&times;</button>
                    </div>
                </div>

                <!-- Category Filter Pills -->
                <div class="flex items-center gap-1.5 overflow-x-auto pb-2 mb-3">
                    <?php foreach ($categoryTabs as $tab):
                        $cnt = (int)($categoryCounts[$tab['key']] ?? 0);
                        if ($tab['key'] !== 'all' && $cnt === 0) continue;
                    ?>
                    <button type="button" @click="serviceCategory = '<?= $tab['key'] ?>'"
                            :class="serviceCategory === '<?= $tab['key'] ?>'
                                ? 'bg-purple-600 text-white shadow-xs'
                                : 'bg-[#FAF8FF] text-slate-600 hover:bg-purple-50 border border-purple-100'"
                            class="px-3 py-1.5 rounded-xl text-[11px] font-extrabold whitespace-nowrap transition shrink-0">
                        <?= Validator::e($tab['label']) ?>
                        <span class="opacity-75 ml-0.5">(<?= $cnt ?>)</span>
                    </button>
                    <?php endforeach; ?>
                </div>

                <!-- Service Grid -->
                <div x-show="filteredServices.length === 0" x-cloak
                     class="p-6 rounded-2xl bg-[#FAF8FF] border border-dashed border-purple-200 text-center text-xs text-slate-500">
                    No services matched "<span x-text="serviceSearch" class="font-bold text-purple-700"></span>".
                    <button type="button" @click="serviceSearch = ''; serviceCategory = 'all'" class="ml-1 font-extrabold text-purple-700 underline">Reset</button>
                </div>

                <div x-show="filteredServices.length > 0"
                     class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2 max-h-64 sm:max-h-72 overflow-y-auto pr-1">
                    <template x-for="svc in filteredServices" :key="svc.id">
                        <button type="button" @click="pickService(svc)"
                                :class="selectedService && selectedService.id === svc.id
                                    ? 'bg-purple-600 text-white border-purple-600 ring-2 ring-purple-300 shadow-md shadow-purple-600/20'
                                    : 'bg-[#FAF8FF] hover:bg-purple-50 text-slate-800 border-purple-100 hover:border-purple-300'"
                                class="p-2.5 rounded-2xl border text-left transition flex items-center gap-2.5 min-w-0">
                            <span class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
                                  :class="selectedService && selectedService.id === svc.id ? 'bg-white/20 text-white' : 'bg-white border border-purple-100 text-purple-700'"
                                  x-html="serviceIconHtml(svc.icon_key)"></span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-1">
                                    <span class="text-xs font-extrabold truncate" x-text="svc.name"></span>
                                    <span x-show="svc.is_popular" class="w-1.5 h-1.5 rounded-full bg-amber-400 shrink-0" title="Popular"></span>
                                </div>
                                <div class="text-[11px] font-bold tabular-nums mt-0.5 truncate"
                                     :class="selectedService && selectedService.id === svc.id ? 'text-purple-100' : 'text-purple-700'"
                                     x-text="svc.display_price"></div>
                            </div>
                        </button>
                    </template>
                </div>
            </section>

            <!-- 2. Country Selector Card -->
            <section id="otp-country-section" class="bg-white rounded-3xl border border-purple-100 p-4 sm:p-5 shadow-xs">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5 mb-3">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="w-6 h-6 rounded-lg text-[11px] font-extrabold flex items-center justify-center shrink-0"
                              :class="selectedCountry ? 'bg-emerald-100 text-emerald-700' : (selectedService ? 'bg-purple-600 text-white' : 'bg-slate-100 text-slate-400')">2</span>
                        <div class="min-w-0">
                            <h2 class="text-sm font-extrabold text-slate-900 truncate">
                                Select Country
                                <span x-show="selectedService" class="text-purple-600 font-bold" x-text="selectedService ? ('— ' + selectedService.name) : ''"></span>
                            </h2>
                        </div>
                    </div>
                    <div class="relative w-full sm:w-64">
                        <svg class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                        <input type="text" x-model="countrySearch" placeholder="Search country or code..." :disabled="!selectedService"
                               class="w-full pl-9 pr-8 py-2 rounded-xl bg-[#FAF8FF] border border-purple-100 focus:border-purple-500 focus:bg-white outline-none text-xs font-semibold disabled:opacity-50 transition">
                        <button type="button" x-show="countrySearch" @click="countrySearch = ''" aria-label="Clear country search"
                                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 text-sm font-bold">&times;</button>
                    </div>
                </div>

                <!-- Prompt if no service selected -->
                <div x-show="!selectedService"
                     class="p-6 rounded-2xl bg-[#FAF8FF] border border-dashed border-purple-200 text-center text-xs text-slate-500 font-semibold">
                    Select a service above to view available countries and exact prices.
                </div>

                <!-- Loading countries -->
                <div x-show="loadingCountries" x-cloak class="p-6 flex items-center justify-center gap-2 text-xs font-bold text-purple-600">
                    <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                    </svg>
                    <span>Loading countries...</span>
                </div>

                <!-- No countries available -->
                <div x-show="selectedService && !loadingCountries && filteredCountries.length === 0" x-cloak
                     class="p-6 rounded-2xl bg-amber-50 border border-amber-200 text-xs font-semibold text-amber-800 text-center">
                    No available countries matched your filter for this service.
                </div>

                <!-- Country Grid -->
                <div x-show="selectedService && !loadingCountries && filteredCountries.length > 0" x-cloak
                     class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2 max-h-80 overflow-y-auto pr-1">
                    <template x-for="c in filteredCountries" :key="c.country_id">
                        <button type="button" @click="pickCountry(c)"
                                :class="selectedCountry && selectedCountry.country_id === c.country_id
                                    ? 'bg-purple-600 text-white border-purple-600 ring-2 ring-purple-300 shadow-md shadow-purple-600/20'
                                    : 'bg-[#FAF8FF] hover:bg-purple-50 text-slate-800 border-purple-100 hover:border-purple-300'"
                                class="p-3 rounded-2xl border text-left flex items-center justify-between gap-2 transition">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <span class="text-xl leading-none shrink-0" x-text="c.flag_emoji || '🌐'"></span>
                                <div class="min-w-0">
                                    <div class="text-xs font-extrabold truncate" x-text="c.name"></div>
                                    <div class="flex items-center gap-1.5 mt-0.5 text-[10px] font-semibold"
                                         :class="selectedCountry && selectedCountry.country_id === c.country_id ? 'text-purple-100' : 'text-slate-500'">
                                        <span x-text="c.iso_code + (c.dial_code ? ' · ' + c.dial_code : '')"></span>
                                        <span class="px-1.5 py-0.2 rounded-md font-bold"
                                              :class="selectedCountry && selectedCountry.country_id === c.country_id
                                                  ? 'bg-white/20 text-white'
                                                  : 'bg-emerald-50 text-emerald-700'"
                                              x-text="(c.availability_verified && c.stock !== null && c.stock > 0) ? (c.stock + ' avail') : 'Available'"></span>
                                    </div>
                                </div>
                            </div>
                            <span class="text-xs font-extrabold tabular-nums shrink-0"
                                  :class="selectedCountry && selectedCountry.country_id === c.country_id ? 'text-white' : 'text-purple-700'"
                                  x-text="c.display_price"></span>
                        </button>
                    </template>
                </div>
            </section>
        </div>

        <!-- Right 4 Cols: Sticky Order Summary & Buy Activation CTA -->
        <aside class="lg:col-span-4 min-w-0">
            <div class="bg-white rounded-3xl border border-purple-100 p-5 shadow-sm lg:sticky lg:top-20 space-y-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-6 h-6 rounded-lg text-[11px] font-extrabold flex items-center justify-center"
                              :class="quote && quote.ok ? 'bg-emerald-100 text-emerald-700' : 'bg-purple-100 text-purple-700'">3</span>
                        <h3 class="text-sm font-extrabold text-slate-900">Checkout Summary</h3>
                    </div>
                    <span x-show="quote && quote.ok" x-cloak class="px-2 py-0.5 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-700 text-[10px] font-extrabold">
                        Ready
                    </span>
                </div>

                <div class="space-y-2.5 text-xs bg-[#FAF8FF] rounded-2xl p-4 border border-purple-100/80">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-slate-500 font-semibold">Service</span>
                        <span class="font-extrabold text-slate-900 truncate" x-text="selectedService ? selectedService.name : 'Select a service'"></span>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-slate-500 font-semibold">Country</span>
                        <span class="font-extrabold text-slate-900 truncate" x-text="selectedCountry ? ((selectedCountry.flag_emoji ? selectedCountry.flag_emoji + ' ' : '') + selectedCountry.name) : 'Select a country'"></span>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-slate-500 font-semibold">Availability</span>
                        <span x-show="loadingQuote" x-cloak class="text-purple-600 font-bold">Checking live...</span>
                        <span x-show="!loadingQuote && quote && quote.ok" x-cloak class="text-emerald-700 font-extrabold"
                              x-text="(quote && quote.availability_verified && quote.count !== null) ? (quote.count + ' available') : 'Available now'"></span>
                        <span x-show="!loadingQuote && quote && !quote.ok" x-cloak class="text-rose-600 font-extrabold">Unavailable</span>
                        <span x-show="!loadingQuote && !quote" class="text-slate-400 font-semibold">&mdash;</span>
                    </div>
                    <div class="pt-2.5 border-t border-purple-100 flex items-center justify-between">
                        <span class="text-slate-700 font-bold">Total Price</span>
                        <span class="text-xl font-extrabold text-purple-700 tabular-nums"
                              x-text="quote && quote.ok ? quote.display_price : (selectedCountry ? selectedCountry.display_price : '—')"></span>
                    </div>
                </div>

                <div x-show="errorMsg" x-cloak
                     class="p-3.5 rounded-2xl bg-rose-50 border border-rose-200 text-xs font-bold text-rose-700 leading-relaxed"
                     x-text="errorMsg"></div>

                <template x-if="quote && quote.ok && !quote.sufficient_balance">
                    <div class="p-3.5 rounded-2xl bg-amber-50 border border-amber-200 text-xs font-semibold text-amber-800 space-y-2">
                        <div>Your wallet balance is lower than this activation price.</div>
                        <a href="/user/add-funds.php?store=otp"
                           class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-amber-600 hover:bg-amber-700 text-white font-extrabold transition">
                            + Add Funds to Wallet
                        </a>
                    </div>
                </template>

                <button type="button"
                        @click="openConfirm()"
                        :disabled="purchasing || loadingQuote || !quote || !quote.ok || !quote.sufficient_balance"
                        class="w-full py-3.5 px-5 rounded-2xl bg-gradient-to-r from-violet-600 via-purple-600 to-fuchsia-600 text-white font-extrabold text-sm shadow-lg shadow-purple-600/25 hover:opacity-95 disabled:opacity-45 disabled:cursor-not-allowed transition flex items-center justify-center gap-2">
                    <svg x-show="!purchasing" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                    <svg x-show="purchasing" x-cloak class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                    <span x-text="purchasing ? 'Assigning Number...' : (quote && quote.ok ? ('Buy Activation · ' + quote.display_price) : 'Buy Activation')"></span>
                </button>

                <div class="grid grid-cols-2 gap-2 pt-1 text-[11px] text-slate-500 font-semibold">
                    <div class="flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 text-emerald-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        <span>Instant number</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 text-emerald-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        <span>Auto-refund protected</span>
                    </div>
                </div>
            </div>
        </aside>
    </div>

    <!-- Mobile Sticky Purchase Bar (Sits cleanly above bottom nav without overlapping content) -->
    <div x-show="selectedService && selectedCountry && !purchasedActivation" x-cloak
         class="lg:hidden fixed bottom-[62px] inset-x-0 z-30 bg-white/95 backdrop-blur-xl border-t border-purple-200 px-4 py-2.5 shadow-[0_-8px_24px_-12px_rgba(88,28,135,0.25)]">
        <div class="flex items-center justify-between gap-3 max-w-xl mx-auto">
            <div class="min-w-0">
                <div class="text-xs font-extrabold text-slate-900 truncate"
                     x-text="(selectedService ? selectedService.name : '') + ' · ' + (selectedCountry ? selectedCountry.name : '')"></div>
                <div class="text-xs font-extrabold text-purple-700 tabular-nums"
                     x-text="loadingQuote ? 'Checking price...' : (quote && quote.ok ? quote.display_price : (selectedCountry ? selectedCountry.display_price : ''))"></div>
            </div>
            <template x-if="quote && quote.ok && !quote.sufficient_balance">
                <a href="/user/add-funds.php?store=otp"
                   class="px-4 py-2.5 rounded-xl bg-amber-600 text-white text-xs font-extrabold shrink-0 shadow-sm">
                    Add Funds
                </a>
            </template>
            <template x-if="!(quote && quote.ok && !quote.sufficient_balance)">
                <button type="button"
                        @click="openConfirm()"
                        :disabled="purchasing || loadingQuote || !quote || !quote.ok"
                        class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-violet-600 to-purple-600 text-white text-xs font-extrabold shrink-0 shadow-md shadow-purple-600/25 disabled:opacity-45 transition">
                    <span x-text="purchasing ? 'Assigning...' : 'Buy Activation'"></span>
                </button>
            </template>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div x-show="confirmModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-purple-100" @click.outside="if(!purchasing) confirmModal = false">
            <div class="flex items-center gap-3">
                <span class="w-10 h-10 rounded-2xl bg-purple-100 text-purple-700 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                </span>
                <div>
                    <h3 class="text-base font-extrabold text-slate-900">Confirm OTP Activation</h3>
                    <p class="text-xs text-slate-500">One-time verification number</p>
                </div>
            </div>
            <div class="mt-4 p-3.5 rounded-2xl bg-[#FAF8FF] border border-purple-100 text-xs space-y-1.5">
                <div class="flex justify-between">
                    <span class="text-slate-500 font-semibold">Service</span>
                    <span class="font-extrabold text-slate-900" x-text="selectedService ? selectedService.name : ''"></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-500 font-semibold">Country</span>
                    <span class="font-extrabold text-slate-900" x-text="selectedCountry ? ((selectedCountry.flag_emoji ? selectedCountry.flag_emoji + ' ' : '') + selectedCountry.name) : ''"></span>
                </div>
                <div class="flex justify-between pt-1.5 border-t border-purple-100">
                    <span class="text-slate-600 font-bold">Charge</span>
                    <span class="font-extrabold text-purple-700 tabular-nums" x-text="quote ? quote.display_price : ''"></span>
                </div>
            </div>
            <p class="mt-3 text-[11px] text-slate-500 leading-relaxed">
                Your wallet will be charged immediately upon confirmation. If no verification SMS arrives before the timer expires, your wallet is refunded automatically.
            </p>
            <div class="mt-5 flex items-center justify-end gap-2.5">
                <button type="button" @click="confirmModal = false" :disabled="purchasing"
                        class="px-4 py-2.5 rounded-xl bg-slate-100 text-slate-700 text-xs font-bold hover:bg-slate-200">Cancel</button>
                <button type="button" @click="executePurchase()" :disabled="purchasing"
                        class="px-5 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-xs font-extrabold shadow-md">
                    <span x-text="purchasing ? 'Processing...' : 'Confirm & Buy'"></span>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
const __OTP_SERVICE_ICONS = <?= json_encode($iconHtmlMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

function otpBuyFlow() {
    return {
        services: <?= json_encode($servicesJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        preselectId: <?= (int)$preselectServiceId ?>,
        pollSeconds: <?= (int)$pollIntervalSec ?>,
        serviceSearch: '',
        serviceCategory: 'all',
        countrySearch: '',
        selectedService: null,
        countries: [],
        selectedCountry: null,
        loadingCountries: false,
        loadingQuote: false,
        quote: null,
        errorMsg: '',
        confirmModal: false,
        purchasing: false,
        idemToken: '',
        purchasedActivation: null,
        liveRemaining: 0,
        pollingNow: false,
        pollTimer: null,
        tickTimer: null,

        init() {
            if (this.preselectId > 0) {
                const found = this.services.find(s => s.id === this.preselectId);
                if (found) this.pickService(found);
            }
        },
        serviceIconHtml(key) {
            return __OTP_SERVICE_ICONS[key] || __OTP_SERVICE_ICONS['general'] || '';
        },
        get filteredServices() {
            const q = this.serviceSearch.trim().toLowerCase();
            return this.services.filter(s => {
                if (this.serviceCategory === 'popular' && !s.is_popular) return false;
                if (this.serviceCategory !== 'all' && this.serviceCategory !== 'popular' && s.category !== this.serviceCategory) return false;
                if (!q) return true;
                return s.name.toLowerCase().includes(q) || s.slug.toLowerCase().includes(q);
            });
        },
        get filteredCountries() {
            const q = this.countrySearch.trim().toLowerCase();
            return this.countries.filter(c => {
                if (c.availability_verified && (!c.available || (c.stock !== null && Number(c.stock) <= 0))) {
                    return false;
                }
                if (!q) return true;
                return c.name.toLowerCase().includes(q)
                    || c.iso_code.toLowerCase().includes(q)
                    || (c.dial_code || '').toLowerCase().includes(q);
            });
        },
        async pickService(svc) {
            this.selectedService = svc;
            this.selectedCountry = null;
            this.countrySearch = '';
            this.quote = null;
            this.errorMsg = '';
            this.loadingCountries = true;
            try {
                const resp = await fetch('/api/otp.php?action=countries&service_id=' + encodeURIComponent(svc.id), { credentials: 'same-origin' });
                const data = await resp.json();
                if (!data.ok) {
                    this.errorMsg = data.error || 'Could not load countries right now.';
                    this.countries = [];
                } else {
                    this.countries = Array.isArray(data.countries) ? data.countries : [];
                }
            } catch (e) {
                this.errorMsg = 'Network error while loading countries. Please try again.';
                this.countries = [];
            } finally {
                this.loadingCountries = false;
            }
        },
        async pickCountry(c) {
            if (c.availability_verified && (!c.available || (c.stock !== null && Number(c.stock) <= 0))) {
                return;
            }
            this.selectedCountry = c;
            this.quote = null;
            this.errorMsg = '';
            this.loadingQuote = true;
            try {
                const url = '/api/otp.php?action=quote&service_id=' + encodeURIComponent(this.selectedService.id) + '&country_id=' + encodeURIComponent(c.country_id);
                const resp = await fetch(url, { credentials: 'same-origin' });
                const data = await resp.json();
                this.quote = data;
                if (data.display_balance && window.updateOtpWalletDisplay) {
                    window.updateOtpWalletDisplay(data.display_balance);
                }
                if (!data.ok) {
                    this.errorMsg = data.error || 'This option is temporarily unavailable. Please choose another country.';
                }
            } catch (e) {
                this.errorMsg = 'Unable to verify availability right now. Please try again.';
            } finally {
                this.loadingQuote = false;
            }
        },
        openConfirm() {
            if (this.purchasing || !this.quote || !this.quote.ok || !this.quote.sufficient_balance) return;
            this.idemToken = 'tok_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);
            this.confirmModal = true;
        },
        async executePurchase() {
            if (this.purchasing) return;
            this.purchasing = true;
            this.errorMsg = '';
            try {
                const fd = new FormData();
                fd.append('action', 'purchase');
                fd.append('service_id', this.selectedService.id);
                fd.append('country_id', this.selectedCountry.country_id);
                fd.append('idempotency_token', this.idemToken);
                fd.append('_csrf', window.__CSRF_TOKEN || document.querySelector('meta[name="csrf-token"]')?.content || '');

                const resp = await fetch('/api/otp.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                const data = await resp.json();
                if (data.display_balance && window.updateOtpWalletDisplay) {
                    window.updateOtpWalletDisplay(data.display_balance);
                }
                if (!data.ok) {
                    this.errorMsg = data.error || 'Could not complete activation right now. Please try again.';
                    this.confirmModal = false;
                    if (window.showToast) window.showToast(this.errorMsg, 'error');
                    return;
                }
                this.confirmModal = false;
                this.purchasedActivation = data.activation;
                this.liveRemaining = data.activation.remaining_seconds || 0;
                if (window.showToast) window.showToast('Number assigned! Waiting for SMS code.', 'success');
                this.startLivePolling();
                this.$nextTick(() => {
                    const card = document.getElementById('live-activation-card');
                    if (card) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            } catch (e) {
                this.errorMsg = 'Network error while purchasing activation. Please try again.';
                this.confirmModal = false;
            } finally {
                this.purchasing = false;
            }
        },
        startLivePolling() {
            this.stopLivePolling();
            this.tickTimer = setInterval(() => {
                if (this.liveRemaining > 0) this.liveRemaining--;
            }, 1000);
            this.pollTimer = setInterval(() => this.checkLiveNow(false), this.pollSeconds * 1000);
        },
        stopLivePolling() {
            if (this.pollTimer) clearInterval(this.pollTimer);
            if (this.tickTimer) clearInterval(this.tickTimer);
            this.pollTimer = null;
            this.tickTimer = null;
        },
        async checkLiveNow(force = true) {
            if (!this.purchasedActivation || this.pollingNow) return;
            this.pollingNow = true;
            try {
                const act = force ? 'check' : 'poll';
                const resp = await fetch('/api/otp.php?action=' + act + '&id=' + encodeURIComponent(this.purchasedActivation.id), { credentials: 'same-origin' });
                const data = await resp.json();
                if (data.display_balance && window.updateOtpWalletDisplay) {
                    window.updateOtpWalletDisplay(data.display_balance);
                }
                if (data.activation) {
                    const hadOtp = !!this.purchasedActivation.otp_code;
                    this.purchasedActivation = data.activation;
                    this.liveRemaining = data.activation.remaining_seconds || 0;
                    if (!hadOtp && data.activation.otp_code && window.showToast) {
                        window.showToast('OTP code received: ' + data.activation.otp_code, 'success');
                    }
                    if (['completed', 'expired', 'canceled', 'refunded', 'failed'].includes(data.activation.status)) {
                        this.stopLivePolling();
                    }
                }
            } catch (e) {} finally {
                this.pollingNow = false;
            }
        },
        async cancelLive() {
            if (!this.purchasedActivation) return;
            try {
                const fd = new FormData();
                fd.append('action', 'cancel');
                fd.append('id', this.purchasedActivation.id);
                fd.append('_csrf', window.__CSRF_TOKEN || document.querySelector('meta[name="csrf-token"]')?.content || '');
                const resp = await fetch('/api/otp.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                const data = await resp.json();
                if (data.display_balance && window.updateOtpWalletDisplay) window.updateOtpWalletDisplay(data.display_balance);
                if (data.ok) {
                    this.purchasedActivation = data.activation;
                    this.stopLivePolling();
                    if (window.showToast) window.showToast(data.refunded ? 'Activation cancelled and refunded.' : 'Activation cancelled.', 'info');
                } else if (window.showToast) {
                    window.showToast(data.error || 'Unable to cancel activation right now.', 'error');
                }
            } catch (e) {
                if (window.showToast) window.showToast('Network error while cancelling activation.', 'error');
            }
        },
        async finishLive() {
            if (!this.purchasedActivation) return;
            try {
                const fd = new FormData();
                fd.append('action', 'finish');
                fd.append('id', this.purchasedActivation.id);
                fd.append('_csrf', window.__CSRF_TOKEN || document.querySelector('meta[name="csrf-token"]')?.content || '');
                const resp = await fetch('/api/otp.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                const data = await resp.json();
                if (data.ok) {
                    this.purchasedActivation = data.activation;
                    this.stopLivePolling();
                    if (window.showToast) window.showToast('Activation completed!', 'success');
                }
            } catch (e) {
                if (window.showToast) window.showToast('Network error while completing activation.', 'error');
            }
        },
        startAnother() {
            this.stopLivePolling();
            this.purchasedActivation = null;
            this.quote = null;
            this.selectedCountry = null;
            this.errorMsg = '';
        },
        formatTimer(sec) {
            const s = Math.max(0, parseInt(sec || 0, 10));
            const m = Math.floor(s / 60);
            const r = s % 60;
            return String(m).padStart(2, '0') + ':' + String(r).padStart(2, '0');
        }
    };
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
