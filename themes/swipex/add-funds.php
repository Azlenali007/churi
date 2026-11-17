<?php
$pageTitle = 'Add Funds & Wallet';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/sidebar.php';
require __DIR__ . '/partials/header.php';

$gateways = PaymentService::getActiveGateways();
$rzpCfg = PaymentService::getRazorpayConfig();
$payments = Database::fetchAll(
    "SELECT p.*, pg.name AS gateway_name
     FROM payments p
     JOIN payment_gateways pg ON pg.id = p.gateway_id
     WHERE p.user_id = ?
     ORDER BY p.id DESC LIMIT 30",
    [$user['id']]
);
$transactions = Database::fetchAll(
    "SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 40",
    [$user['id']]
);
$refunds = Database::fetchAll(
    "SELECT * FROM wallet_transactions WHERE user_id = ? AND type = 'refund' ORDER BY id DESC LIMIT 20",
    [$user['id']]
);
$baseCode = Currency::getBaseCode();
$displayCurr = Currency::getActiveDisplayCurrency($user);
$displayCode = $displayCurr['code'];
$currencySymbol = $displayCurr['symbol'];
$defaultRzpDisp = Currency::convertFromBase('500', $displayCode, 2);
$rzpMinDisp = $rzpCfg ? Currency::convertFromBase((string)$rzpCfg['gateway']['min_amount'], $displayCode, 2) : '0.00';
$rzpMaxDisp = $rzpCfg ? Currency::convertFromBase((string)$rzpCfg['gateway']['max_amount'], $displayCode, 2) : '0.00';
?>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6 mb-6">
    <!-- Left Wallet & Deposit Form -->
    <div class="lg:col-span-5 space-y-6 min-w-0">
        <div class="swipex-balance-card rounded-3xl p-6">
            <div class="flex items-center justify-between gap-2">
                <div class="text-xs font-semibold text-slate-400">Available Wallet Balance</div>
                <span class="px-2.5 py-0.5 rounded-full bg-blue-50 text-blue-700 text-[11px] font-extrabold border border-blue-200/70"><?= Validator::e($displayCode) ?></span>
            </div>
            <div class="text-3xl font-extrabold text-slate-900 mt-1 tabular-nums"><?= Currency::display((string)$user['wallet_balance'], $displayCode, 2, true) ?></div>
            <?php if ($displayCode !== $baseCode): ?>
                <div class="text-[11px] text-slate-400 mt-1 tabular-nums">Wallet Ledger Base: <?= Currency::formatBase((string)$user['wallet_balance'], 2, true) ?></div>
            <?php endif; ?>
            <div class="text-xs text-slate-500 mt-3 tabular-nums">Total Lifetime Spent: <strong class="text-slate-800"><?= Currency::display((string)$user['total_spent'], $displayCode, 2, true) ?></strong></div>
        </div>

        <div class="swipex-card rounded-3xl p-6" x-data="{ tab: '<?= $rzpCfg ? 'razorpay' : 'manual' ?>', rzpAmount: '<?= Validator::e($defaultRzpDisp) ?>', rzpBusy: false }">
            <h2 class="text-lg font-extrabold text-slate-900 mb-4">Add Funds</h2>

            <div class="flex gap-2 mb-5 bg-slate-100 p-1 rounded-full text-xs font-bold">
                <button type="button" @click="tab = 'razorpay'" :class="tab === 'razorpay' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-500'" class="flex-1 py-2 rounded-full transition">Razorpay (Instant)</button>
                <button type="button" @click="tab = 'manual'" :class="tab === 'manual' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-500'" class="flex-1 py-2 rounded-full transition">Manual Payment</button>
            </div>

            <!-- Razorpay Tab -->
            <div x-show="tab === 'razorpay'" class="space-y-4">
                <?php if (!$rzpCfg): ?>
                    <div class="p-4 rounded-2xl bg-amber-50 border border-amber-200 text-amber-800 text-xs leading-relaxed">
                        <strong>Razorpay is not configured yet.</strong> An administrator must enable Razorpay and enter valid Key ID &amp; Key Secret in Admin &rarr; Payments. Please use Manual Payment below.
                    </div>
                <?php else: ?>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Amount (<?= Validator::e($currencySymbol . ' ' . $displayCode) ?>)</label>
                        <input type="number" step="0.01" min="<?= Validator::e($rzpMinDisp) ?>" max="<?= Validator::e($rzpMaxDisp) ?>"
                               x-model="rzpAmount" class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-blue-600 outline-none text-sm font-bold tabular-nums bg-white">
                        <div class="text-[11px] text-slate-500 mt-1 tabular-nums">
                            Limits: <?= Currency::display((string)$rzpCfg['gateway']['min_amount'], $displayCode, 2, true) ?> &ndash; <?= Currency::display((string)$rzpCfg['gateway']['max_amount'], $displayCode, 2, true) ?>
                        </div>
                        <?php if ($displayCode !== $baseCode): ?>
                        <div class="text-[11px] text-blue-700 mt-1">
                            Converted automatically to <?= Validator::e($baseCode) ?> for Razorpay checkout &amp; wallet credit.
                        </div>
                        <?php endif; ?>
                    </div>
                    <button type="button" :disabled="rzpBusy"
                            @click="startRazorpay(rzpAmount, '<?= Validator::e(CSRF::token()) ?>', '<?= Validator::e($displayCode) ?>', () => rzpBusy = true, () => rzpBusy = false)"
                            class="w-full py-3.5 rounded-full bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm shadow-lg shadow-blue-600/25 transition">
                        <span x-text="rzpBusy ? 'Connecting to Razorpay...' : 'Pay Securely with Razorpay'"></span>
                    </button>
                    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
                    <script>
                    async function startRazorpay(amount, csrf, displayCurrency, onStart, onEnd) {
                        onStart();
                        try {
                            const fd = new FormData();
                            fd.append('action', 'rzp_create');
                            fd.append('amount', amount);
                            fd.append('display_currency', displayCurrency);
                            fd.append('_csrf_token', csrf);
                            const resp = await fetch('/api/ajax.php', { method: 'POST', credentials: 'same-origin', body: fd });
                            const data = await resp.json();
                            if (!data.ok) {
                                window.showToast(data.error || 'Failed to initiate payment', 'error');
                                onEnd();
                                return;
                            }
                            const rzp = new Razorpay({
                                key: data.key_id,
                                amount: data.amount_paise,
                                currency: data.currency,
                                name: <?= json_encode(Helpers::setting('site_name', 'SMM PRO')) ?>,
                                description: 'Wallet Deposit ' + data.tx_ref,
                                order_id: data.razorpay_order_id,
                                handler: async function (response) {
                                    const vfd = new FormData();
                                    vfd.append('action', 'rzp_verify');
                                    vfd.append('_csrf_token', csrf);
                                    vfd.append('razorpay_order_id', response.razorpay_order_id);
                                    vfd.append('razorpay_payment_id', response.razorpay_payment_id);
                                    vfd.append('razorpay_signature', response.razorpay_signature);
                                    const vr = await fetch('/api/ajax.php', { method: 'POST', credentials: 'same-origin', body: vfd });
                                    const vj = await vr.json();
                                    if (vj.ok) {
                                        window.showToast('Payment verified and credited!', 'success');
                                        setTimeout(() => location.reload(), 900);
                                    } else {
                                        window.showToast(vj.error || 'Verification failed', 'error');
                                    }
                                },
                                modal: { ondismiss: onEnd }
                            });
                            rzp.open();
                        } catch (e) {
                            window.showToast('Network error initiating Razorpay.', 'error');
                            onEnd();
                        }
                    }
                    </script>
                <?php endif; ?>
            </div>

            <!-- Manual Payment Tab -->
            <div x-show="tab === 'manual'" x-cloak>
                <?php
                $manualGateways = array_values(array_filter($gateways, fn($g) => $g['type'] === 'manual'));
                if (empty($manualGateways)):
                ?>
                    <p class="text-xs text-slate-500">No manual payment methods are currently enabled.</p>
                <?php else: ?>
                    <form method="POST" action="/user/add-funds.php" enctype="multipart/form-data" class="space-y-3.5"
                          x-data="{ gateways: <?= Validator::e(json_encode($manualGateways)) ?>, gid: <?= (int)$manualGateways[0]['id'] ?>, get active() { return this.gateways.find(g => Number(g.id) === Number(this.gid)); } }">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="manual_deposit">
                        <input type="hidden" name="display_currency" value="<?= Validator::e($displayCode) ?>">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Payment Method</label>
                            <select name="gateway_id" x-model="gid" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-sm font-semibold outline-none focus:border-blue-600 bg-white">
                                <?php foreach ($manualGateways as $mg): ?>
                                    <option value="<?= (int)$mg['id'] ?>"><?= Validator::e($mg['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="p-3.5 rounded-2xl bg-blue-50/70 border border-blue-100 text-xs text-blue-900 space-y-1" x-show="active">
                            <div class="font-bold">Payment Instructions:</div>
                            <div x-text="active?.instructions" class="whitespace-pre-line text-slate-700"></div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Amount Sent (<?= Validator::e($currencySymbol . ' ' . $displayCode) ?>)</label>
                            <input type="number" step="0.01" min="0.01" name="amount" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-sm font-bold outline-none focus:border-blue-600 tabular-nums bg-white">
                            <?php if ($displayCode !== $baseCode): ?>
                            <div class="text-[11px] text-slate-500 mt-1">
                                Converted from <?= Validator::e($displayCode) ?> to <?= Validator::e($baseCode) ?> once upon submission.
                            </div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">UTR / Transaction Reference ID</label>
                            <input type="text" name="transaction_ref" required placeholder="e.g. UTR1234567890" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-sm outline-none focus:border-blue-600 bg-white">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Payment Screenshot / Proof (Optional)</label>
                            <input type="file" name="proof" accept="image/*,application/pdf" class="w-full text-xs text-slate-600">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Notes</label>
                            <input type="text" name="payer_note" placeholder="Sender name or UPI ID" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-sm outline-none focus:border-blue-600 bg-white">
                        </div>
                        <button type="submit" class="w-full py-3 rounded-full bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm shadow-md transition">Submit Payment Request</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Tabs: Payments / Wallet Ledger / Refunds -->
    <div class="lg:col-span-7 swipex-card rounded-3xl p-6 min-w-0" x-data="{ historyTab: 'payments' }">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
            <h2 class="text-lg font-extrabold text-slate-900">Financial History</h2>
            <div class="flex gap-1.5 bg-slate-100 p-1 rounded-full text-xs font-bold">
                <button type="button" @click="historyTab = 'payments'" :class="historyTab === 'payments' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-500'" class="px-3.5 py-1.5 rounded-full">Payments</button>
                <button type="button" @click="historyTab = 'transactions'" :class="historyTab === 'transactions' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-500'" class="px-3.5 py-1.5 rounded-full">Transactions</button>
                <button type="button" @click="historyTab = 'refunds'" :class="historyTab === 'refunds' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-500'" class="px-3.5 py-1.5 rounded-full">Refunds</button>
            </div>
        </div>

        <!-- Payment History -->
        <div x-show="historyTab === 'payments'" class="overflow-x-auto">
            <?php if (empty($payments)): ?>
                <p class="text-xs text-slate-500 py-8 text-center">No payment requests recorded yet.</p>
            <?php else: ?>
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-slate-100 uppercase text-slate-400">
                            <th class="py-2.5 pr-3">Ref</th>
                            <th class="py-2.5 pr-3">Method</th>
                            <th class="py-2.5 pr-3">Amount</th>
                            <th class="py-2.5 pr-3">Status</th>
                            <th class="py-2.5 text-right">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($payments as $pay):
                            $payCurr = strtoupper((string)($pay['currency'] ?? $baseCode));
                            if ($payCurr === '') $payCurr = $baseCode;
                            $payOrig = Currency::formatRaw((string)$pay['amount'], $payCurr, 2, true);
                            $payDisp = ($displayCode !== $payCurr)
                                ? Currency::display(Currency::convertToBase((string)$pay['amount'], $payCurr, 4), $displayCode, 2, true)
                                : $payOrig;
                        ?>
                        <tr>
                            <td class="py-3 pr-3 font-bold text-slate-800"><?= Validator::e($pay['transaction_ref']) ?></td>
                            <td class="py-3 pr-3 text-slate-600"><?= Validator::e($pay['gateway_name']) ?></td>
                            <td class="py-3 pr-3 font-bold text-blue-600 tabular-nums whitespace-nowrap">
                                <div><?= Validator::e($payDisp) ?></div>
                                <?php if ($displayCode !== $payCurr): ?>
                                    <div class="text-[10px] font-medium text-slate-400">Orig: <?= Validator::e($payOrig) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 pr-3"><?= Helpers::statusBadge((string)$pay['status']) ?></td>
                            <td class="py-3 text-right text-slate-500"><?= date('M j, H:i', strtotime($pay['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Wallet Ledger -->
        <div x-show="historyTab === 'transactions'" x-cloak class="overflow-x-auto">
            <?php if (empty($transactions)): ?>
                <p class="text-xs text-slate-500 py-8 text-center">No wallet transactions yet.</p>
            <?php else: ?>
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-slate-100 uppercase text-slate-400">
                            <th class="py-2.5 pr-3">Type</th>
                            <th class="py-2.5 pr-3">Description</th>
                            <th class="py-2.5 pr-3">Amount</th>
                            <th class="py-2.5 pr-3">Balance After</th>
                            <th class="py-2.5 text-right">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($transactions as $tx):
                            $isCredit = in_array($tx['type'], ['credit','refund','referral_bonus','admin_add','payment'], true);
                            $txCurr = strtoupper((string)($tx['currency_code'] ?? $baseCode));
                            if ($txCurr === '') $txCurr = $baseCode;
                            $txOrig = Currency::formatRaw((string)$tx['amount'], $txCurr, 2, true);
                            $txDisp = ($displayCode !== $txCurr)
                                ? Currency::display(Currency::convertToBase((string)$tx['amount'], $txCurr, 4), $displayCode, 2, true)
                                : $txOrig;
                            $balOrig = Currency::formatRaw((string)$tx['balance_after'], $txCurr, 2, true);
                            $balDisp = ($displayCode !== $txCurr)
                                ? Currency::display(Currency::convertToBase((string)$tx['balance_after'], $txCurr, 4), $displayCode, 2, true)
                                : $balOrig;
                        ?>
                        <tr>
                            <td class="py-3 pr-3 font-semibold uppercase text-slate-600"><?= Validator::e($tx['type']) ?></td>
                            <td class="py-3 pr-3 text-slate-700"><?= Validator::e($tx['description']) ?></td>
                            <td class="py-3 pr-3 font-bold tabular-nums whitespace-nowrap <?= $isCredit ? 'text-emerald-600' : 'text-rose-600' ?>">
                                <div><?= $isCredit ? '+' : '-' ?><?= Validator::e($txDisp) ?></div>
                                <?php if ($displayCode !== $txCurr): ?>
                                    <div class="text-[10px] font-medium text-slate-400">Orig: <?= $isCredit ? '+' : '-' ?><?= Validator::e($txOrig) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 pr-3 font-semibold text-slate-800 tabular-nums whitespace-nowrap">
                                <div><?= Validator::e($balDisp) ?></div>
                                <?php if ($displayCode !== $txCurr): ?>
                                    <div class="text-[10px] font-normal text-slate-400">Orig: <?= Validator::e($balOrig) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 text-right text-slate-400"><?= date('M j, H:i', strtotime($tx['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Refund History -->
        <div x-show="historyTab === 'refunds'" x-cloak class="overflow-x-auto">
            <?php if (empty($refunds)): ?>
                <p class="text-xs text-slate-500 py-8 text-center">No order refunds issued yet.</p>
            <?php else: ?>
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-slate-100 uppercase text-slate-400">
                            <th class="py-2.5 pr-3">Description</th>
                            <th class="py-2.5 pr-3">Refunded</th>
                            <th class="py-2.5 text-right">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($refunds as $rf):
                            $rfCurr = strtoupper((string)($rf['currency_code'] ?? $baseCode));
                            if ($rfCurr === '') $rfCurr = $baseCode;
                            $rfOrig = Currency::formatRaw((string)$rf['amount'], $rfCurr, 2, true);
                            $rfDisp = ($displayCode !== $rfCurr)
                                ? Currency::display(Currency::convertToBase((string)$rf['amount'], $rfCurr, 4), $displayCode, 2, true)
                                : $rfOrig;
                        ?>
                        <tr>
                            <td class="py-3 pr-3 text-slate-700"><?= Validator::e($rf['description']) ?></td>
                            <td class="py-3 pr-3 font-bold text-emerald-600 tabular-nums whitespace-nowrap">
                                <div>+<?= Validator::e($rfDisp) ?></div>
                                <?php if ($displayCode !== $rfCurr): ?>
                                    <div class="text-[10px] font-medium text-slate-400">Orig: +<?= Validator::e($rfOrig) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 text-right text-slate-400"><?= date('M j, H:i', strtotime($rf['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
