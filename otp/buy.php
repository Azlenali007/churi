<?php
/**
 * Buy OTP Activation (Direct One-Time Activation Checkout)
 * - Guarded by StoreManager::requireOtpEnabled().
 * - Never displays phone numbers prior to purchase.
 * - Debits shared wallet atomically and reveals assigned number only after upstream assignment.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$user = Auth::requireUser();
StoreManager::requireOtpEnabled(false);

$pageTitle = 'Buy OTP Activation';
$dispCurrency = Currency::getActiveDisplayCurrency($user);
$preselectServiceId = (int)($_GET['service_id'] ?? 0);
$pollIntervalSec = max(3, (int)Helpers::setting('otp_polling_interval', '5'));

$services = OtpService::getEnabledServices('', false, 150);
$servicesJs = [];
foreach ($services as $s) {
    $priceBase = (string)$s['selling_price'];
    $servicesJs[] = [
        'id'            => (int)$s['id'],
        'name'          => (string)$s['name'],
        'slug'          => (string)$s['slug'],
        'description'   => (string)($s['description'] ?? ''),
        'is_popular'    => !empty($s['is_popular']),
        'display_price' => Currency::cmp($priceBase, '0', 4) > 0
            ? Currency::display($priceBase, $dispCurrency['code'], 2, true)
            : 'Live Quote',
    ];
}

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div x-data="otpBuyFlow()" x-init="init()" class="space-y-6">
    <!-- Step Progress Strip -->
    <div class="bg-white rounded-2xl border border-purple-100 p-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2 sm:gap-4 text-xs font-bold">
            <span class="inline-flex items-center gap-2" :class="selectedService ? 'text-emerald-700' : 'text-purple-700'">
                <span class="w-6 h-6 rounded-full flex items-center justify-center text-[11px] font-extrabold"
                      :class="selectedService ? 'bg-emerald-100 text-emerald-700' : 'bg-purple-600 text-white'">1</span>
                <span>Select Service</span>
            </span>
            <span class="text-slate-300">&rarr;</span>
            <span class="inline-flex items-center gap-2" :class="selectedCountry ? 'text-emerald-700' : (selectedService ? 'text-purple-700' : 'text-slate-400')">
                <span class="w-6 h-6 rounded-full flex items-center justify-center text-[11px] font-extrabold"
                      :class="selectedCountry ? 'bg-emerald-100 text-emerald-700' : (selectedService ? 'bg-purple-600 text-white' : 'bg-slate-100 text-slate-400')">2</span>
                <span>Choose Country</span>
            </span>
            <span class="text-slate-300">&rarr;</span>
            <span class="inline-flex items-center gap-2" :class="purchasedActivation ? 'text-emerald-700' : (quote && quote.ok ? 'text-purple-700' : 'text-slate-400')">
                <span class="w-6 h-6 rounded-full flex items-center justify-center text-[11px] font-extrabold"
                      :class="purchasedActivation ? 'bg-emerald-100 text-emerald-700' : (quote && quote.ok ? 'bg-purple-600 text-white' : 'bg-slate-100 text-slate-400')">3</span>
                <span>Purchase &amp; Receive OTP</span>
            </span>
        </div>
        <div class="text-xs font-semibold text-slate-500">
            Wallet: <span class="font-extrabold text-purple-700 tabular-nums" data-otp-wallet-balance><?= Currency::renderMoney((string)$user['wallet_balance'], null, 2, true) ?></span>
        </div>
    </div>

    <!-- Assigned Activation Live Card (Shown ONLY AFTER Successful Provider Purchase) -->
    <template x-if="purchasedActivation">
        <div class="bg-white rounded-3xl border-2 border-purple-300 shadow-xl shadow-purple-900/10 p-6">
            <div class="flex flex-wrap items-center justify-between gap-3 pb-4 border-b border-purple-100">
                <div>
                    <div class="text-[11px] font-extrabold uppercase tracking-wider text-purple-600">Activation Assigned</div>
                    <h2 class="text-lg font-extrabold text-slate-900 mt-0.5">
                        <span x-text="purchasedActivation.service_name"></span>
                        <span class="text-slate-400 font-normal">&middot;</span>
                        <span x-text="(purchasedActivation.country_flag ? purchasedActivation.country_flag + ' ' : '') + purchasedActivation.country_name"></span>
                    </h2>
                    <div class="text-xs text-slate-500 font-mono mt-0.5">
                        ID: <span x-text="purchasedActivation.activation_code"></span>
                        <span class="mx-1">&middot;</span>
                        Paid: <span class="font-bold text-slate-800" x-text="purchasedActivation.display_price"></span>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="px-3 py-1 rounded-full text-xs font-extrabold bg-purple-100 text-purple-800 uppercase" x-text="purchasedActivation.status.replace('_',' ')"></span>
                    <span class="px-3 py-1 rounded-full text-xs font-mono font-extrabold bg-slate-900 text-white" x-text="formatTimer(liveRemaining)"></span>
                </div>
            </div>

            <div class="mt-5 grid md:grid-cols-2 gap-4">
                <!-- Assigned Phone Number -->
                <div class="p-4 rounded-2xl bg-[#FAF8FF] border border-purple-100">
                    <div class="text-[11px] font-extrabold uppercase tracking-wider text-slate-500">Assigned Phone Number</div>
                    <div class="mt-2 flex items-center justify-between gap-3">
                        <span class="text-xl sm:text-2xl font-mono font-extrabold text-slate-950 select-all" x-text="purchasedActivation.phone_number"></span>
                        <button type="button" @click="window.copyText(purchasedActivation.phone_number, 'Phone number copied!')"
                                class="px-3.5 py-2 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-xs font-extrabold shrink-0">
                            Copy Number
                        </button>
                    </div>
                    <p class="mt-2 text-[11px] text-slate-500">Enter this number on <span x-text="purchasedActivation.service_name" class="font-bold"></span> to request your verification SMS.</p>
                </div>

                <!-- Live OTP Code Box -->
                <div class="p-4 rounded-2xl border" :class="purchasedActivation.otp_code ? 'bg-emerald-50 border-emerald-200' : 'bg-amber-50/60 border-amber-200'">
                    <div class="text-[11px] font-extrabold uppercase tracking-wider" :class="purchasedActivation.otp_code ? 'text-emerald-700' : 'text-amber-700'">
                        <span x-text="purchasedActivation.otp_code ? 'Verification Code Received' : 'Waiting for SMS Code...'"></span>
                    </div>
                    <template x-if="purchasedActivation.otp_code">
                        <div class="mt-2">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-2xl sm:text-3xl font-mono font-extrabold text-emerald-800 tracking-wider select-all" x-text="purchasedActivation.otp_code"></span>
                                <button type="button" @click="window.copyText(purchasedActivation.otp_code, 'OTP code copied!')"
                                        class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-extrabold shrink-0">
                                    Copy OTP
                                </button>
                            </div>
                            <div x-show="purchasedActivation.sms_text" class="mt-2 text-xs text-emerald-900 bg-white/80 rounded-xl p-2.5 border border-emerald-200" x-text="purchasedActivation.sms_text"></div>
                        </div>
                    </template>
                    <template x-if="!purchasedActivation.otp_code">
                        <div class="mt-2.5 flex items-center gap-2.5 text-xs text-amber-800 font-semibold">
                            <svg class="w-4 h-4 animate-spin shrink-0" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                            <span>Polling live every <?= $pollIntervalSec ?>s for incoming SMS...</span>
                        </div>
                    </template>
                </div>
            </div>

            <div class="mt-5 flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-purple-50">
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" @click="checkLiveNow()" :disabled="pollingNow"
                            class="px-4 py-2 rounded-xl bg-purple-100 hover:bg-purple-200 text-purple-800 text-xs font-extrabold transition">
                        <span x-text="pollingNow ? 'Checking...' : 'Check SMS Now'"></span>
                    </button>
                    <button type="button" x-show="purchasedActivation.can_complete" @click="finishLive()"
                            class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-extrabold transition">
                        Complete Activation
                    </button>
                    <button type="button" x-show="purchasedActivation.can_cancel" @click="cancelLive()"
                            class="px-4 py-2 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-xs font-extrabold transition">
                        Cancel &amp; Refund
                    </button>
                </div>
                <div class="flex items-center gap-2">
                    <a href="/otp/activations.php" class="px-4 py-2 rounded-xl bg-slate-900 text-white text-xs font-extrabold hover:bg-slate-800 transition">Open Active Activations</a>
                    <button type="button" @click="startAnother()" class="px-4 py-2 rounded-xl bg-purple-50 text-purple-700 text-xs font-bold hover:bg-purple-100 transition">Buy Another</button>
                </div>
            </div>
        </div>
    </template>

    <?php if (empty($services)): ?>
    <div class="bg-white rounded-3xl border border-purple-100 p-10 text-center">
        <h3 class="text-base font-extrabold text-slate-900">No OTP Services Available Yet</h3>
        <p class="text-sm text-slate-500 mt-1">An administrator needs to configure an active OTP provider and enable services before activations can be purchased.</p>
    </div>
    <?php else: ?>
    <div class="grid lg:grid-cols-12 gap-6 items-start">
        <!-- Left & Center: Step 1 (Services) + Step 2 (Countries) -->
        <div class="lg:col-span-8 space-y-6">
            <!-- Step 1: Select Service -->
            <div class="bg-white rounded-3xl border border-purple-100 p-5 sm:p-6 shadow-xs">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                    <div>
                        <h2 class="text-sm font-extrabold text-slate-900">1. Choose OTP Service</h2>
                        <p class="text-xs text-slate-500">Select the app or website you want to verify</p>
                    </div>
                    <input type="text" x-model="serviceSearch" placeholder="Search service..."
                           class="w-full sm:w-64 px-3.5 py-2 rounded-xl bg-[#FAF8FF] border border-purple-100 focus:border-purple-400 outline-none text-xs font-semibold">
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2.5 max-h-72 overflow-y-auto pr-1">
                    <template x-for="svc in filteredServices" :key="svc.id">
                        <button type="button" @click="pickService(svc)"
                                :class="selectedService && selectedService.id === svc.id
                                    ? 'bg-purple-600 text-white border-purple-600 shadow-md shadow-purple-600/20'
                                    : 'bg-[#FAF8FF] hover:bg-purple-50 text-slate-800 border-purple-100'"
                                class="p-3 rounded-2xl border text-left transition flex flex-col justify-between">
                            <div class="flex items-center justify-between gap-1">
                                <span class="text-xs font-extrabold truncate" x-text="svc.name"></span>
                                <span x-show="svc.is_popular" class="w-2 h-2 rounded-full bg-amber-400 shrink-0" title="Popular"></span>
                            </div>
                            <div class="mt-1.5 text-[11px] font-bold tabular-nums"
                                 :class="selectedService && selectedService.id === svc.id ? 'text-purple-100' : 'text-purple-700'"
                                 x-text="svc.display_price"></div>
                        </button>
                    </template>
                </div>
            </div>

            <!-- Step 2: Select Country -->
            <div class="bg-white rounded-3xl border border-purple-100 p-5 sm:p-6 shadow-xs">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                    <div>
                        <h2 class="text-sm font-extrabold text-slate-900">2. Choose Country</h2>
                        <p class="text-xs text-slate-500" x-text="selectedService ? ('Enabled countries for ' + selectedService.name) : 'Select a service above first'"></p>
                    </div>
                    <input type="text" x-model="countrySearch" placeholder="Search country..." :disabled="!selectedService"
                           class="w-full sm:w-64 px-3.5 py-2 rounded-xl bg-[#FAF8FF] border border-purple-100 focus:border-purple-400 outline-none text-xs font-semibold disabled:opacity-50">
                </div>

                <div x-show="!selectedService" class="p-6 rounded-2xl bg-[#FAF8FF] border border-dashed border-purple-200 text-center text-xs text-slate-500 font-semibold">
                    Pick a service in Step 1 to load available countries and pricing.
                </div>

                <div x-show="loadingCountries" x-cloak class="p-6 text-center text-xs font-bold text-purple-600">
                    Loading countries...
                </div>

                <div x-show="selectedService && !loadingCountries && filteredCountries.length === 0" x-cloak
                     class="p-6 rounded-2xl bg-amber-50 border border-amber-200 text-xs font-semibold text-amber-800 text-center">
                    No active countries matched your selection.
                </div>

                <div x-show="selectedService && !loadingCountries && filteredCountries.length > 0" x-cloak
                     class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2.5 max-h-80 overflow-y-auto pr-1">
                    <template x-for="c in filteredCountries" :key="c.country_id">
                        <button type="button" @click="pickCountry(c)"
                                :class="selectedCountry && selectedCountry.country_id === c.country_id
                                    ? 'bg-purple-600 text-white border-purple-600 shadow-md shadow-purple-600/20'
                                    : 'bg-[#FAF8FF] hover:bg-purple-50 text-slate-800 border-purple-100'"
                                class="p-3.5 rounded-2xl border text-left flex items-center justify-between gap-2 transition">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <span class="text-lg leading-none" x-text="c.flag_emoji || '🌐'"></span>
                                <div class="min-w-0">
                                    <div class="text-xs font-extrabold truncate" x-text="c.name"></div>
                                    <div class="text-[10px] font-semibold"
                                         :class="selectedCountry && selectedCountry.country_id === c.country_id ? 'text-purple-100' : 'text-slate-500'"
                                         x-text="c.iso_code + (c.dial_code ? ' · ' + c.dial_code : '')"></div>
                                </div>
                            </div>
                            <span class="text-xs font-extrabold tabular-nums shrink-0"
                                  :class="selectedCountry && selectedCountry.country_id === c.country_id ? 'text-white' : 'text-purple-700'"
                                  x-text="c.display_price"></span>
                        </button>
                    </template>
                </div>
            </div>
        </div>

        <!-- Right Column: Step 3 Summary & Direct Purchase Card -->
        <div class="lg:col-span-4">
            <div class="bg-white rounded-3xl border border-purple-100 p-6 shadow-sm sticky top-20 space-y-4">
                <div>
                    <div class="text-[11px] font-extrabold uppercase tracking-wider text-purple-600">3. Direct Activation Checkout</div>
                    <h3 class="text-base font-extrabold text-slate-900 mt-0.5">Order Summary</h3>
                </div>

                <div class="space-y-2.5 text-xs bg-[#FAF8FF] rounded-2xl p-4 border border-purple-100/80">
                    <div class="flex items-center justify-between">
                        <span class="text-slate-500 font-semibold">Service</span>
                        <span class="font-extrabold text-slate-900" x-text="selectedService ? selectedService.name : 'Not selected'"></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-500 font-semibold">Country</span>
                        <span class="font-extrabold text-slate-900" x-text="selectedCountry ? ((selectedCountry.flag_emoji ? selectedCountry.flag_emoji + ' ' : '') + selectedCountry.name) : 'Not selected'"></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-500 font-semibold">Provider Stock</span>
                        <span x-show="loadingQuote" class="text-purple-600 font-bold">Checking live...</span>
                        <span x-show="!loadingQuote && quote && quote.ok" class="text-emerald-700 font-extrabold"
                              x-text="quote && quote.count !== null ? (quote.count + ' available') : 'Available'"></span>
                        <span x-show="!loadingQuote && quote && !quote.ok" class="text-rose-600 font-extrabold">Unavailable</span>
                        <span x-show="!loadingQuote && !quote" class="text-slate-400 font-semibold">—</span>
                    </div>
                    <div class="pt-2.5 border-t border-purple-100 flex items-center justify-between">
                        <span class="text-slate-600 font-bold">Activation Price</span>
                        <span class="text-lg font-extrabold text-purple-700 tabular-nums"
                              x-text="quote && quote.ok ? quote.display_price : (selectedCountry ? selectedCountry.display_price : '—')"></span>
                    </div>
                </div>

                <div class="p-3 rounded-xl bg-purple-50/70 border border-purple-100 text-[11px] text-purple-900 leading-relaxed">
                    Numbers are assigned directly by the upstream provider <strong>after</strong> purchase confirmation. If no SMS arrives before expiry, eligible activations are refunded to your shared wallet.
                </div>

                <div x-show="errorMsg" x-cloak class="p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-xs font-bold text-rose-700" x-text="errorMsg"></div>

                <template x-if="quote && quote.ok && !quote.sufficient_balance">
                    <div class="p-3.5 rounded-xl bg-amber-50 border border-amber-200 text-xs font-semibold text-amber-800 space-y-2">
                        <div>Insufficient shared wallet balance for this activation.</div>
                        <a href="/user/add-funds.php" class="inline-block px-3.5 py-1.5 rounded-lg bg-amber-600 text-white font-bold">Add Funds to Wallet</a>
                    </div>
                </template>

                <button type="button"
                        @click="openConfirm()"
                        :disabled="purchasing || loadingQuote || !quote || !quote.ok || !quote.sufficient_balance"
                        class="w-full py-3.5 px-5 rounded-2xl bg-gradient-to-r from-violet-600 via-purple-600 to-fuchsia-600 text-white font-extrabold text-sm shadow-lg shadow-purple-600/25 hover:opacity-95 disabled:opacity-45 disabled:cursor-not-allowed transition">
                    <span x-text="purchasing ? 'Assigning Number...' : 'Buy OTP Activation'"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div x-show="confirmModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-purple-100" @click.outside="if(!purchasing) confirmModal = false">
            <h3 class="text-lg font-extrabold text-slate-900">Confirm One-Time Activation</h3>
            <p class="mt-2 text-xs text-slate-600 leading-relaxed">
                You are about to purchase a direct one-time OTP activation for
                <strong class="text-slate-900" x-text="selectedService ? selectedService.name : ''"></strong>
                in <strong class="text-slate-900" x-text="selectedCountry ? selectedCountry.name : ''"></strong>.
                <strong class="text-purple-700" x-text="quote ? quote.display_price : ''"></strong> will be debited from your shared wallet immediately.
            </p>
            <div class="mt-5 flex items-center justify-end gap-2.5">
                <button type="button" @click="confirmModal = false" :disabled="purchasing"
                        class="px-4 py-2.5 rounded-xl bg-slate-100 text-slate-700 text-xs font-bold hover:bg-slate-200">Cancel</button>
                <button type="button" @click="executePurchase()" :disabled="purchasing"
                        class="px-5 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-xs font-extrabold shadow-md">
                    <span x-text="purchasing ? 'Processing...' : 'Confirm & Purchase'"></span>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function otpBuyFlow() {
    return {
        services: <?= json_encode($servicesJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        preselectId: <?= (int)$preselectServiceId ?>,
        pollSeconds: <?= (int)$pollIntervalSec ?>,
        serviceSearch: '',
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
        get filteredServices() {
            const q = this.serviceSearch.trim().toLowerCase();
            if (!q) return this.services;
            return this.services.filter(s => s.name.toLowerCase().includes(q) || s.slug.toLowerCase().includes(q));
        },
        get filteredCountries() {
            const q = this.countrySearch.trim().toLowerCase();
            if (!q) return this.countries;
            return this.countries.filter(c => c.name.toLowerCase().includes(q) || c.iso_code.toLowerCase().includes(q));
        },
        async pickService(svc) {
            this.selectedService = svc;
            this.selectedCountry = null;
            this.quote = null;
            this.errorMsg = '';
            this.loadingCountries = true;
            try {
                const resp = await fetch('/api/otp.php?action=countries&service_id=' + encodeURIComponent(svc.id), { credentials: 'same-origin' });
                const data = await resp.json();
                if (!data.ok) {
                    this.errorMsg = data.error || 'Could not load countries.';
                    this.countries = [];
                } else {
                    this.countries = data.countries || [];
                }
            } catch (e) {
                this.errorMsg = 'Network error loading countries.';
            } finally {
                this.loadingCountries = false;
            }
        },
        async pickCountry(c) {
            this.selectedCountry = c;
            this.quote = null;
            this.errorMsg = '';
            this.loadingQuote = true;
            try {
                const url = '/api/otp.php?action=quote&service_id=' + encodeURIComponent(this.selectedService.id) + '&country_id=' + encodeURIComponent(c.country_id);
                const resp = await fetch(url, { credentials: 'same-origin' });
                const data = await resp.json();
                this.quote = data;
                if (!data.ok) {
                    this.errorMsg = data.error || 'Unavailable for selected country.';
                }
            } catch (e) {
                this.errorMsg = 'Could not verify live provider quote.';
            } finally {
                this.loadingQuote = false;
            }
        },
        openConfirm() {
            if (!this.quote || !this.quote.ok || !this.quote.sufficient_balance) return;
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
                    this.errorMsg = data.error || 'Activation purchase failed.';
                    this.confirmModal = false;
                    if (window.showToast) window.showToast(this.errorMsg, 'error');
                    return;
                }
                this.confirmModal = false;
                this.purchasedActivation = data.activation;
                this.liveRemaining = data.activation.remaining_seconds || 0;
                if (window.showToast) window.showToast('Number assigned! Waiting for SMS code.', 'success');
                this.startLivePolling();
            } catch (e) {
                this.errorMsg = 'Network error while purchasing activation.';
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
                window.showToast(data.error || 'Unable to cancel activation.', 'error');
            }
        },
        async finishLive() {
            if (!this.purchasedActivation) return;
            const fd = new FormData();
            fd.append('action', 'finish');
            fd.append('id', this.purchasedActivation.id);
            fd.append('_csrf', window.__CSRF_TOKEN || document.querySelector('meta[name="csrf-token"]')?.content || '');
            const resp = await fetch('/api/otp.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await resp.json();
            if (data.ok) {
                this.purchasedActivation = data.activation;
                this.stopLivePolling();
                if (window.showToast) window.showToast('Activation marked completed!', 'success');
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
