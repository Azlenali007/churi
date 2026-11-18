<?php
$pageTitle = 'Mass Order';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/sidebar.php';
require __DIR__ . '/partials/header.php';

$displayCurr = Currency::getActiveDisplayCurrency($user);
$displayCode = $displayCurr['code'];
$currencySymbol = $displayCurr['symbol'];
$services = Database::fetchAll("SELECT id, name, rate, min_qty, max_qty FROM services WHERE status = 'active'");
$rateMap = [];
foreach ($services as $s) {
    $p = FlashSale::getServicePricing($s);
    $rateMap[(int)$s['id']] = [
        'name'      => $s['name'],
        'rate_base' => (float)$p['effective_rate'],
        'rate'      => (float)Currency::convertFromBase((string)$p['effective_rate'], $displayCode, 4),
        'min'       => (int)$s['min_qty'],
        'max'       => (int)$s['max_qty'],
    ];
}
?>

<div class="swipex-page-hero rounded-[22px] p-4 sm:p-5 mb-5 flex flex-wrap items-center justify-between gap-3">
    <div class="flex items-center gap-3 min-w-0">
        <div class="w-11 h-11 rounded-2xl bg-gradient-to-br from-violet-600 to-purple-600 text-white flex items-center justify-center shadow-md shadow-purple-600/20 shrink-0">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
        </div>
        <div class="min-w-0">
            <h1 class="text-lg sm:text-xl font-extrabold text-slate-900 truncate">Mass Order</h1>
            <p class="text-xs text-slate-500">Submit batch orders in one click: <code class="px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 font-bold">service_id | link | quantity</code></p>
        </div>
    </div>
    <span class="px-3 py-1 rounded-full bg-white text-blue-700 text-xs font-extrabold border border-blue-200/80 shadow-sm"
          data-smm-curr-badge="{symbol} {code}">
        <?= Validator::e($currencySymbol . ' ' . $displayCode) ?>
    </span>
</div>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6"
     x-data="{
        rates: <?= Validator::e(json_encode($rateMap)) ?>,
        raw: '',
        displayCode: '<?= Validator::e($displayCode) ?>',
        currencySymbol: '<?= Validator::e($currencySymbol) ?>',
        init() {
            window.addEventListener('smm:currency-changed', (ev) => {
                const d = ev.detail || {};
                if (!d.code) return;
                this.displayCode = d.code;
                this.currencySymbol = d.symbol || d.code;
                Object.values(this.rates).forEach((svc) => {
                    svc.rate = window.smmConvertFromBase(svc.rate_base, d.code);
                });
            });
        },
        get parsed() {
            const lines = this.raw.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
            let total = 0; let valid = 0;
            const rows = lines.map((line, i) => {
                const parts = line.split('|').map(p => p.trim());
                const sid = Number(parts[0]);
                const link = parts[1] || '';
                const qty = Number(parts[2]);
                const svc = this.rates[sid];
                let err = '';
                let cost = 0;
                if (!svc) err = 'Unknown/inactive Service ID';
                else if (!link) err = 'Missing link';
                else if (!Number.isInteger(qty) || qty < svc.min || qty > svc.max) err = `Qty must be ${svc.min}-${svc.max}`;
                else {
                    cost = (svc.rate * qty) / 1000;
                    total += cost;
                    valid++;
                }
                return { line: i + 1, sid, link, qty, svcName: svc ? svc.name : '-', cost, err };
            });
            return { rows, total: total.toFixed(2), valid };
        }
     }">
    <div class="lg:col-span-7 swipex-card rounded-3xl p-5 sm:p-7 min-w-0">
        <?php if (!empty($massResults)): ?>
        <div class="mb-5 p-4 rounded-2xl bg-slate-50 border border-slate-200 space-y-1.5 text-xs">
            <div class="font-bold text-slate-800 mb-1">Batch Processing Report:</div>
            <?php foreach ($massResults as $mr): ?>
                <div class="<?= $mr['ok'] ? 'text-emerald-700' : 'text-rose-600' ?> font-medium">
                    Line <?= (int)$mr['line'] ?>: <?= Validator::e($mr['message']) ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="/user/mass-order.php" class="space-y-4">
            <?= CSRF::field() ?>
            <textarea name="mass_orders" x-model="raw" rows="10" required
                      placeholder="12 | https://instagram.com/p/example1 | 1000&#10;15 | https://youtube.com/watch?v=example2 | 500"
                      class="swipex-input px-4 py-3 font-mono text-xs"></textarea>

            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 flex items-center justify-between">
                <div class="text-xs text-slate-600">
                    Valid Lines: <strong x-text="parsed.valid">0</strong>
                </div>
                <div class="text-lg font-extrabold text-blue-600 tabular-nums">
                    Estimated Total: <span x-text="currencySymbol"><?= Validator::e($currencySymbol) ?></span><span x-text="parsed.total">0.00</span> <span class="text-xs font-bold text-blue-500" x-text="displayCode"><?= Validator::e($displayCode) ?></span>
                </div>
            </div>

            <button type="submit" class="swipex-btn-primary w-full py-3.5 rounded-full font-bold text-sm">
                Submit Mass Order
            </button>
        </form>
    </div>

    <div class="lg:col-span-5 swipex-card rounded-3xl p-6 min-w-0">
        <h2 class="text-base font-extrabold text-slate-900 mb-4">Live Line Preview</h2>
        <div class="space-y-2 max-h-[440px] overflow-y-auto">
            <template x-if="parsed.rows.length === 0">
                <p class="text-xs text-slate-500">Enter lines on the left to preview calculated costs before submission.</p>
            </template>
            <template x-for="r in parsed.rows" :key="r.line">
                <div class="p-3 rounded-2xl border text-xs" :class="r.err ? 'bg-rose-50 border-rose-200' : 'bg-emerald-50/70 border-emerald-200'">
                    <div class="flex items-center justify-between font-bold">
                        <span x-text="`Line ${r.line}: #${r.sid || '?'}`"></span>
                        <span x-show="!r.err" class="text-emerald-700 tabular-nums"><span x-text="currencySymbol"></span><span x-text="r.cost.toFixed(2)"></span> <span x-text="displayCode"></span></span>
                    </div>
                    <div class="text-slate-600 truncate mt-0.5" x-text="r.svcName"></div>
                    <div x-show="r.err" class="text-rose-600 font-semibold mt-0.5" x-text="r.err"></div>
                </div>
            </template>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
