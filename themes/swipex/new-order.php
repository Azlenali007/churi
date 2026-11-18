<?php
$pageTitle = 'New Order';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/sidebar.php';
require __DIR__ . '/partials/header.php';

$categories = Database::fetchAll(
    "SELECT c.*, COUNT(s.id) AS svc_count
     FROM categories c
     JOIN services s ON s.category_id = c.id AND s.status = 'active'
     WHERE c.status = 'active'
     GROUP BY c.id
     ORDER BY c.sort_order ASC, c.id ASC"
);

$subcategories = Database::fetchAll("SELECT * FROM subcategories WHERE status = 'active' ORDER BY sort_order ASC, id ASC");

$services = Database::fetchAll(
    "SELECT s.*, c.name AS category_name
     FROM services s
     JOIN categories c ON c.id = s.category_id
     WHERE s.status = 'active' AND c.status = 'active'
     ORDER BY c.sort_order ASC, s.sort_order ASC, s.id ASC"
);

$userDiscount = (float)($user['custom_rate_discount'] ?? 0);
$enrichedServices = [];
$platformsPresent = [];

$displayCurr = Currency::getActiveDisplayCurrency($user);
$displayCode = $displayCurr['code'];
$currencySymbol = $displayCurr['symbol'];

foreach ($services as $svc) {
    $pricing = FlashSale::getServicePricing($svc);
    $effective = $pricing['effective_rate'];
    if (!$pricing['on_sale'] && $userDiscount > 0) {
        $effective = Currency::applyDiscount($effective, 'percent', (string)$userDiscount);
    }
    $platformsPresent[strtolower((string)$svc['platform'])] = true;
    $enrichedServices[] = [
        'id'              => (int)$svc['id'],
        'category_id'     => (int)$svc['category_id'],
        'subcategory_id'  => $svc['subcategory_id'] ? (int)$svc['subcategory_id'] : 0,
        'name'            => $svc['name'],
        'description'     => (string)$svc['description'],
        'platform'        => strtolower((string)$svc['platform']),
        'type'            => $svc['type'],
        'original_rate_base'  => (float)$pricing['original_rate'],
        'effective_rate_base' => (float)$effective,
        'original_rate'       => Currency::convertFromBase((string)$pricing['original_rate'], $displayCode, 4),
        'effective_rate'      => Currency::convertFromBase((string)$effective, $displayCode, 4),
        'on_sale'         => $pricing['on_sale'],
        'discount_label'  => $pricing['discount_label'],
        'sale_title'      => $pricing['sale_title'],
        'min_qty'         => (int)$svc['min_qty'],
        'max_qty'         => (int)$svc['max_qty'],
        'refill_support'  => (int)$svc['refill_support'],
        'cancel_support'  => (int)$svc['cancel_support'],
        'drip_feed'       => (int)$svc['drip_feed_support'],
        'avg_time'        => $svc['avg_time'] ?: 'Instant - 1 hour',
    ];
}

$preselectServiceId = (int)($_GET['service_id'] ?? ($_POST['service_id'] ?? 0));
?>

<?php require APP_ROOT . '/includes/flash_sale_banner.php'; ?>

<div class="swipex-page-hero rounded-[22px] p-4 sm:p-5 mb-5 flex flex-wrap items-center justify-between gap-3">
    <div class="flex items-center gap-3 min-w-0">
        <div class="w-11 h-11 rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-600 text-white flex items-center justify-center shadow-md shadow-blue-600/20 shrink-0">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
        </div>
        <div class="min-w-0">
            <h1 class="text-lg sm:text-xl font-extrabold text-slate-900 truncate">Place New Order</h1>
            <p class="text-xs text-slate-500">Pick a service, enter your link, and watch live rate calculation.</p>
        </div>
    </div>
    <div class="flex items-center gap-2 shrink-0">
        <span class="px-3 py-1 rounded-full bg-white text-slate-700 text-xs font-extrabold border border-slate-200 shadow-sm tabular-nums">
            Wallet: <span class="text-blue-600"><?= Currency::renderMoney((string)$user['wallet_balance'], null, 2, true) ?></span>
        </span>
        <span class="px-3 py-1 rounded-full bg-blue-600 text-white text-xs font-extrabold"
              data-smm-curr-badge="{symbol} {code}">
            <?= Validator::e($currencySymbol . ' ' . $displayCode) ?>
        </span>
    </div>
</div>

<div x-data="{
    categories: <?= Validator::e(json_encode($categories)) ?>,
    subcategories: <?= Validator::e(json_encode($subcategories)) ?>,
    services: <?= Validator::e(json_encode($enrichedServices)) ?>,
    platformFilter: 'all',
    searchQuery: '',
    categoryId: 0,
    subcategoryId: 0,
    serviceId: <?= $preselectServiceId ?>,
    link: '',
    quantity: 100,
    comments: '',
    runs: 2,
    interval: 60,
    useDrip: false,
    displayCode: '<?= Validator::e($displayCode) ?>',
    currencySymbol: '<?= Validator::e($currencySymbol) ?>',
    init() {
        window.addEventListener('smm:currency-changed', (ev) => {
            const d = ev.detail || {};
            if (!d.code) return;
            this.displayCode = d.code;
            this.currencySymbol = d.symbol || d.code;
            this.services.forEach((s) => {
                s.original_rate = window.smmConvertFromBase(s.original_rate_base, d.code);
                s.effective_rate = window.smmConvertFromBase(s.effective_rate_base, d.code);
            });
        });
        if (this.serviceId > 0) {
            const target = this.services.find(s => s.id === this.serviceId);
            if (target) {
                this.categoryId = target.category_id;
                this.subcategoryId = target.subcategory_id;
                this.quantity = target.min_qty;
                return;
            }
        }
        if (this.filteredCategories.length) {
            this.categoryId = Number(this.filteredCategories[0].id);
            this.pickFirstService();
        }
    },
    get filteredCategories() {
        return this.categories.filter(c => {
            if (this.platformFilter !== 'all' && String(c.platform).toLowerCase() !== this.platformFilter) return false;
            return true;
        });
    },
    get filteredSubcategories() {
        return this.subcategories.filter(sc => Number(sc.category_id) === Number(this.categoryId));
    },
    get filteredServices() {
        const q = this.searchQuery.trim().toLowerCase();
        return this.services.filter(s => {
            if (q !== '') {
                return s.name.toLowerCase().includes(q) || String(s.id) === q;
            }
            if (this.platformFilter !== 'all' && s.platform !== this.platformFilter) return false;
            if (Number(this.categoryId) > 0 && s.category_id !== Number(this.categoryId)) return false;
            if (Number(this.subcategoryId) > 0 && s.subcategory_id !== Number(this.subcategoryId)) return false;
            return true;
        });
    },
    get currentService() {
        return this.services.find(s => s.id === Number(this.serviceId)) || null;
    },
    fmtRate(r) {
        const n = Number(r || 0);
        if (n > 0 && n < 1) {
            return n.toFixed(4).replace(/0+$/, '').replace(/\.$/, '.00');
        }
        return n.toFixed(2);
    },
    get totalCharge() {
        const s = this.currentService;
        if (!s) return '0.00';
        let q = Number(this.quantity || 0);
        if (s.type === 'custom_comments' && this.comments.trim() !== '') {
            q = this.comments.split(/\r?\n/).filter(l => l.trim() !== '').length;
        }
        const totalQty = (this.useDrip && s.drip_feed) ? q * Math.max(1, Number(this.runs || 1)) : q;
        return ((Number(s.effective_rate) * totalQty) / 1000).toFixed(2);
    },
    selectPlatform(p) {
        this.platformFilter = p;
        this.searchQuery = '';
        if (this.filteredCategories.length) {
            this.categoryId = Number(this.filteredCategories[0].id);
        } else {
            this.categoryId = 0;
        }
        this.subcategoryId = 0;
        this.pickFirstService();
    },
    pickFirstService() {
        const list = this.filteredServices;
        if (list.length) {
            this.serviceId = list[0].id;
            this.quantity = list[0].min_qty;
        } else {
            this.serviceId = 0;
        }
    }
}">
    <!-- Platform Filter Pills -->
    <div class="swipex-card rounded-3xl p-4 mb-6">
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" @click="selectPlatform('all')"
                    :class="platformFilter === 'all' ? 'bg-blue-600 text-white shadow-md shadow-blue-600/20' : 'bg-slate-100 text-slate-700 hover:bg-blue-50'"
                    class="inline-flex items-center gap-2 px-3.5 py-2 rounded-full text-xs font-bold transition">
                All Platforms
            </button>
            <?php foreach (PLATFORM_KEYS as $pKey => $pName):
                if (!isset($platformsPresent[$pKey])) continue;
            ?>
            <button type="button" @click="selectPlatform('<?= $pKey ?>')"
                    :class="platformFilter === '<?= $pKey ?>' ? 'bg-blue-600 text-white shadow-md shadow-blue-600/20' : 'bg-slate-100 text-slate-700 hover:bg-blue-50'"
                    class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-bold transition">
                <?= Helpers::platformIcon($pKey, 'w-5 h-5') ?>
                <span><?= Validator::e($pName) ?></span>
            </button>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (empty($enrichedServices)): ?>
        <div class="swipex-card rounded-3xl p-10 text-center text-slate-500">
            No active services are available right now. Please check back soon.
        </div>
    <?php else: ?>
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <!-- Order Form -->
        <div class="lg:col-span-7 swipex-card rounded-3xl p-5 sm:p-7 min-w-0">
            <form method="POST" action="/user/new-order.php" class="space-y-4">
                <?= CSRF::field() ?>

                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Search Service (Optional)</label>
                    <input type="text" x-model="searchQuery" @input="pickFirstService()" placeholder="Search by service name or ID..."
                           class="swipex-input px-4 py-2.5 text-sm">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Category</label>
                    <select x-model="categoryId" @change="subcategoryId = 0; searchQuery = ''; pickFirstService()"
                            class="swipex-input px-4 py-2.5 text-sm font-semibold">
                        <template x-for="c in filteredCategories" :key="c.id">
                            <option :value="c.id" x-text="c.name"></option>
                        </template>
                    </select>
                </div>

                <div x-show="filteredSubcategories.length > 0" x-cloak>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Subcategory</label>
                    <select x-model="subcategoryId" @change="pickFirstService()"
                            class="swipex-input px-4 py-2.5 text-sm font-semibold">
                        <option value="0">All Subcategories</option>
                        <template x-for="sc in filteredSubcategories" :key="sc.id">
                            <option :value="sc.id" x-text="sc.name"></option>
                        </template>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Service</label>
                    <select name="service_id" x-model="serviceId" @change="if(currentService) quantity = currentService.min_qty" required
                            class="swipex-input px-4 py-2.5 text-sm font-semibold">
                        <template x-for="s in filteredServices" :key="s.id">
                            <option :value="s.id" x-text="`#${s.id} — ${s.name} (${currencySymbol}${fmtRate(s.effective_rate)} ${displayCode}/1k)${s.on_sale ? ' 🔥 SALE' : ''}`"></option>
                        </template>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Target Link / Username</label>
                    <input type="text" name="link" x-model="link" required placeholder="https://instagram.com/p/... or @username"
                           class="swipex-input px-4 py-2.5 text-sm">
                </div>

                <template x-if="currentService && currentService.type === 'custom_comments'">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Custom Comments (1 per line)</label>
                        <textarea name="comments" x-model="comments" rows="4" required placeholder="Enter one comment per line..."
                                  class="swipex-input px-4 py-2.5 text-sm"></textarea>
                    </div>
                </template>

                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Quantity</label>
                    <input type="number" name="quantity" x-model="quantity"
                           :min="currentService ? currentService.min_qty : 1"
                           :max="currentService ? currentService.max_qty : 1000000"
                           required
                           class="swipex-input px-4 py-2.5 text-sm font-bold tabular-nums">
                    <div class="text-xs text-slate-500 mt-1" x-show="currentService">
                        Min: <strong x-text="currentService?.min_qty"></strong> &mdash; Max: <strong x-text="currentService?.max_qty"></strong>
                    </div>
                </div>

                <template x-if="currentService && currentService.drip_feed === 1">
                    <div class="p-4 rounded-2xl bg-blue-50/70 border border-blue-100 space-y-3">
                        <label class="inline-flex items-center gap-2 text-xs font-bold text-blue-900 cursor-pointer">
                            <input type="checkbox" name="drip_feed" value="1" x-model="useDrip" class="rounded text-blue-600">
                            <span>Enable Drip-Feed Delivery</span>
                        </label>
                        <div x-show="useDrip" class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Runs</label>
                                <input type="number" name="runs" x-model="runs" min="2" max="100" class="swipex-input px-3 py-2 text-xs">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Interval (min)</label>
                                <input type="number" name="interval" x-model="interval" min="5" max="1440" class="swipex-input px-3 py-2 text-xs">
                            </div>
                        </div>
                    </div>
                </template>

                <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 flex items-center justify-between gap-3">
                    <div>
                        <div class="text-xs font-semibold text-slate-500">Estimated Total Charge</div>
                        <div class="text-[11px] text-blue-700" x-show="currentService && currentService.on_sale">
                            Flash Sale Applied (<span x-text="currentService?.discount_label"></span>) &mdash; Verified Server-Side
                        </div>
                    </div>
                    <div class="text-2xl font-extrabold text-blue-600 tabular-nums"><span x-text="currencySymbol"><?= Validator::e($currencySymbol) ?></span><span x-text="totalCharge">0.00</span> <span class="text-xs font-bold text-blue-500" x-text="displayCode"><?= Validator::e($displayCode) ?></span></div>
                </div>

                <button type="submit" class="swipex-btn-primary w-full py-3.5 rounded-full font-bold text-sm">
                    Submit Order
                </button>
            </form>
        </div>

        <!-- Service Details Sidebar -->
        <div class="lg:col-span-5 space-y-5 min-w-0">
            <div class="swipex-card rounded-3xl p-6" x-show="currentService">
                <div class="flex items-start justify-between gap-3 mb-4">
                    <div class="min-w-0">
                        <span class="text-xs font-bold uppercase tracking-wider text-blue-600">Service Details</span>
                        <h2 class="text-lg font-extrabold text-slate-900 mt-0.5 break-words [overflow-wrap:anywhere]" x-text="currentService?.name"></h2>
                    </div>
                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-blue-50 text-blue-700 shrink-0" x-text="`#${currentService?.id}`"></span>
                </div>

                <div class="grid grid-cols-2 gap-3 mb-5">
                    <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-100">
                        <div class="text-[11px] text-slate-500 font-semibold">Rate per 1,000</div>
                        <div class="text-base font-extrabold text-blue-600 mt-0.5 tabular-nums">
                            <template x-if="currentService?.on_sale">
                                <span>
                                    <span class="line-through text-xs text-slate-400 mr-1"><span x-text="currencySymbol"></span><span x-text="fmtRate(currentService.original_rate)"></span></span>
                                    <span class="text-rose-600"><span x-text="currencySymbol"></span><span x-text="fmtRate(currentService.effective_rate)"></span></span>
                                </span>
                            </template>
                            <template x-if="!currentService?.on_sale">
                                <span><span x-text="currencySymbol"></span><span x-text="fmtRate(currentService?.effective_rate || 0)"></span></span>
                            </template>
                            <span class="text-[11px] font-bold text-blue-500 ml-1" x-text="displayCode"><?= Validator::e($displayCode) ?></span>
                        </div>
                    </div>
                    <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-100">
                        <div class="text-[11px] text-slate-500 font-semibold">Average Time</div>
                        <div class="text-sm font-bold text-slate-900 mt-0.5" x-text="currentService?.avg_time"></div>
                    </div>
                    <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-100">
                        <div class="text-[11px] text-slate-500 font-semibold">Min / Max Order</div>
                        <div class="text-sm font-bold text-slate-900 mt-0.5 tabular-nums" x-text="`${currentService?.min_qty} / ${currentService?.max_qty}`"></div>
                    </div>
                    <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-100">
                        <div class="text-[11px] text-slate-500 font-semibold">Guarantee / Options</div>
                        <div class="flex flex-wrap gap-1 mt-1">
                            <span x-show="currentService?.refill_support" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-700">Refill</span>
                            <span x-show="currentService?.cancel_support" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-700">Cancel</span>
                            <span x-show="currentService?.drip_feed" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-100 text-blue-700">Drip-Feed</span>
                            <span x-show="!currentService?.refill_support && !currentService?.cancel_support && !currentService?.drip_feed" class="text-xs text-slate-500">Standard</span>
                        </div>
                    </div>
                </div>

                <div class="border-t border-slate-100 pt-4">
                    <div class="text-xs font-bold uppercase text-slate-500 mb-2">Service Description &amp; Notes</div>
                    <div class="text-xs text-slate-600 leading-relaxed whitespace-pre-line break-words" x-text="currentService?.description || 'Ensure the target account/post is public before ordering.'"></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
