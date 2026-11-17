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
        'name' => $s['name'],
        'rate' => (float)Currency::convertFromBase((string)$p['effective_rate'], $displayCode, 4),
        'min'  => (int)$s['min_qty'],
        'max'  => (int)$s['max_qty'],
    ];
}
?>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6"
     x-data="{
        rates: <?= Validator::e(json_encode($rateMap)) ?>,
        raw: '',
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
    <div class="lg:col-span-7 smm-card rounded-3xl p-6 sm:p-7">
        <h1 class="text-xl font-extrabold text-slate-900 mb-1">Mass Order</h1>
        <p class="text-sm text-slate-500 mb-5">Submit multiple orders at once using one order per line in format: <code class="px-2 py-0.5 rounded bg-purple-50 text-purple-800 font-bold">service_id | link | quantity</code></p>

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
                      class="w-full px-4 py-3 rounded-2xl border border-purple-200 focus:border-purple-600 outline-none font-mono text-xs"></textarea>

            <div class="p-4 rounded-2xl bg-[#F3F1FC] flex items-center justify-between">
                <div class="text-xs text-slate-600">
                    Valid Lines: <strong x-text="parsed.valid">0</strong>
                </div>
                <div class="text-lg font-extrabold text-purple-800 tabular-nums">
                    Estimated Total: <?= Validator::e($currencySymbol) ?><span x-text="parsed.total">0.00</span> <span class="text-xs font-bold text-purple-600"><?= Validator::e($displayCode) ?></span>
                </div>
            </div>

            <button type="submit" class="w-full py-3 rounded-xl bg-gradient-to-r from-purple-600 to-violet-600 hover:from-purple-700 hover:to-violet-700 text-white font-bold text-sm shadow-lg shadow-purple-500/25">
                Submit Mass Order
            </button>
        </form>
    </div>

    <div class="lg:col-span-5 smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-4">Live Line Preview</h2>
        <div class="space-y-2 max-h-[420px] overflow-y-auto">
            <template x-if="parsed.rows.length === 0">
                <p class="text-xs text-slate-500">Enter lines on the left to preview calculated costs before submission.</p>
            </template>
            <template x-for="r in parsed.rows" :key="r.line">
                <div class="p-3 rounded-xl border text-xs" :class="r.err ? 'bg-rose-50 border-rose-200' : 'bg-emerald-50/60 border-emerald-200'">
                    <div class="flex items-center justify-between font-bold">
                        <span x-text="`Line ${r.line}: #${r.sid || '?'}`"></span>
                        <span x-show="!r.err" class="text-emerald-700 tabular-nums"><?= Validator::e($currencySymbol) ?><span x-text="r.cost.toFixed(2)"></span> <?= Validator::e($displayCode) ?></span>
                    </div>
                    <div class="text-slate-600 truncate mt-0.5" x-text="r.svcName"></div>
                    <div x-show="r.err" class="text-rose-600 font-semibold mt-0.5" x-text="r.err"></div>
                </div>
            </template>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
