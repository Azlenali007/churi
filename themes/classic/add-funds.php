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
$currencySymbol = Helpers::setting('currency_symbol', '₹');
?>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6 mb-6">
    <!-- Left Wallet & Deposit Form -->
    <div class="lg:col-span-5 space-y-6">
        <div class="smm-wallet-card rounded-3xl p-6 text-white">
            <div class="text-xs font-semibold text-purple-100">Available Wallet Balance</div>
            <div class="text-3xl font-extrabold mt-1"><?= Currency::format((string)$user['wallet_balance']) ?></div>
            <div class="text-xs text-purple-200 mt-3">Total Lifetime Spent: <?= Currency::format((string)$user['total_spent']) ?></div>
        </div>

        <div class="smm-card rounded-3xl p-6" x-data="{ tab: '<?= $rzpCfg ? 'razorpay' : 'manual' ?>', rzpAmount: '500', rzpBusy: false }">
            <h2 class="text-lg font-extrabold text-slate-900 mb-4">Add Funds</h2>

            <div class="flex gap-2 mb-5 bg-[#F3F1FC] p-1 rounded-xl text-xs font-bold">
                <button type="button" @click="tab = 'razorpay'" :class="tab === 'razorpay' ? 'bg-white text-purple-700 shadow-sm' : 'text-slate-500'" class="flex-1 py-2 rounded-lg transition">Razorpay (Instant)</button>
                <button type="button" @click="tab = 'manual'" :class="tab === 'manual' ? 'bg-white text-purple-700 shadow-sm' : 'text-slate-500'" class="flex-1 py-2 rounded-lg transition">Manual Payment</button>
            </div>

            <!-- Razorpay Tab -->
            <div x-show="tab === 'razorpay'" class="space-y-4">
                <?php if (!$rzpCfg): ?>
                    <div class="p-4 rounded-2xl bg-amber-50 border border-amber-200 text-amber-800 text-xs leading-relaxed">
                        <strong>Razorpay is not configured yet.</strong> An administrator must enable Razorpay and enter valid Key ID & Key Secret in Admin &rarr; Payments. Please use Manual Payment below.
                    </div>
                <?php else: ?>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Amount (<?= Validator::e($currencySymbol) ?>)</label>
                        <input type="number" step="0.01" min="<?= Validator::e((string)$rzpCfg['gateway']['min_amount']) ?>" max="<?= Validator::e((string)$rzpCfg['gateway']['max_amount']) ?>"
                               x-model="rzpAmount" class="w-full px-4 py-2.5 rounded-xl border border-purple-200 focus:border-purple-600 outline-none text-sm font-bold">
                        <div class="text-[11px] text-slate-500 mt-1">
                            Limits: <?= Currency::format((string)$rzpCfg['gateway']['min_amount']) ?> – <?= Currency::format((string)$rzpCfg['gateway']['max_amount']) ?>
                        </div>
                    </div>
                    <button type="button" :disabled="rzpBusy"
                            @click="startRazorpay(rzpAmount, '<?= Validator::e(CSRF::token()) ?>', () => rzpBusy = true, () => rzpBusy = false)"
                            class="w-full py-3 rounded-xl bg-gradient-to-r from-purple-600 to-violet-600 hover:from-purple-700 hover:to-violet-700 text-white font-bold text-sm shadow-lg shadow-purple-500/25">
                        <span x-text="rzpBusy ? 'Connecting to Razorpay...' : 'Pay Securely with Razorpay'"></span>
                    </button>
                    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
                    <script>
                    async function startRazorpay(amount, csrf, onStart, onEnd) {
                        onStart();
                        try {
                            const fd = new FormData();
                            fd.append('action', 'rzp_create');
                            fd.append('amount', amount);
                            fd.append('_csrf_token', csrf);
                            const resp = await fetch('/api/ajax.php', { method: 'POST', body: fd });
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
                                    const vr = await fetch('/api/ajax.php', { method: 'POST', body: vfd });
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
                        <div>
                            <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Payment Method</label>
                            <select name="gateway_id" x-model="gid" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 text-sm font-semibold outline-none">
                                <?php foreach ($manualGateways as $mg): ?>
                                    <option value="<?= (int)$mg['id'] ?>"><?= Validator::e($mg['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="p-3.5 rounded-2xl bg-purple-50 border border-purple-100 text-xs text-purple-900 space-y-1" x-show="active">
                            <div class="font-bold">Payment Instructions:</div>
                            <div x-text="active?.instructions" class="whitespace-pre-line text-slate-700"></div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Amount Sent (<?= Validator::e($currencySymbol) ?>)</label>
                            <input type="number" step="0.01" name="amount" required class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 text-sm font-bold outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 uppercase mb-1">UTR / Transaction Reference ID</label>
                            <input type="text" name="transaction_ref" required placeholder="e.g. UTR1234567890" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 text-sm outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Payment Screenshot / Proof (Optional)</label>
                            <input type="file" name="proof" accept="image/*,application/pdf" class="w-full text-xs text-slate-600">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Notes</label>
                            <input type="text" name="payer_note" placeholder="Sender name or UPI ID" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 text-sm outline-none">
                        </div>
                        <button type="submit" class="w-full py-3 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-bold text-sm shadow-md">Submit Payment Request</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Tabs: Payments / Wallet Ledger / Refunds -->
    <div class="lg:col-span-7 smm-card rounded-3xl p-6" x-data="{ historyTab: 'payments' }">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
            <h2 class="text-lg font-extrabold text-slate-900">Financial History</h2>
            <div class="flex gap-1.5 bg-[#F3F1FC] p-1 rounded-xl text-xs font-bold">
                <button type="button" @click="historyTab = 'payments'" :class="historyTab === 'payments' ? 'bg-white text-purple-700 shadow-sm' : 'text-slate-500'" class="px-3 py-1.5 rounded-lg">Payments</button>
                <button type="button" @click="historyTab = 'transactions'" :class="historyTab === 'transactions' ? 'bg-white text-purple-700 shadow-sm' : 'text-slate-500'" class="px-3 py-1.5 rounded-lg">Transactions</button>
                <button type="button" @click="historyTab = 'refunds'" :class="historyTab === 'refunds' ? 'bg-white text-purple-700 shadow-sm' : 'text-slate-500'" class="px-3 py-1.5 rounded-lg">Refunds</button>
            </div>
        </div>

        <!-- Payment History -->
        <div x-show="historyTab === 'payments'" class="overflow-x-auto">
            <?php if (empty($payments)): ?>
                <p class="text-xs text-slate-500 py-8 text-center">No payment requests recorded yet.</p>
            <?php else: ?>
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-purple-100 uppercase text-slate-400">
                            <th class="py-2.5 pr-3">Ref</th>
                            <th class="py-2.5 pr-3">Method</th>
                            <th class="py-2.5 pr-3">Amount</th>
                            <th class="py-2.5 pr-3">Status</th>
                            <th class="py-2.5 text-right">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($payments as $pay): ?>
                        <tr>
                            <td class="py-3 pr-3 font-bold text-slate-800"><?= Validator::e($pay['transaction_ref']) ?></td>
                            <td class="py-3 pr-3 text-slate-600"><?= Validator::e($pay['gateway_name']) ?></td>
                            <td class="py-3 pr-3 font-bold text-purple-700"><?= Currency::format((string)$pay['amount']) ?></td>
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
                        <tr class="border-b border-purple-100 uppercase text-slate-400">
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
                        ?>
                        <tr>
                            <td class="py-3 pr-3 font-semibold uppercase text-slate-600"><?= Validator::e($tx['type']) ?></td>
                            <td class="py-3 pr-3 text-slate-700"><?= Validator::e($tx['description']) ?></td>
                            <td class="py-3 pr-3 font-bold <?= $isCredit ? 'text-emerald-600' : 'text-rose-600' ?>"><?= $isCredit ? '+' : '-' ?><?= Currency::format((string)$tx['amount']) ?></td>
                            <td class="py-3 pr-3 font-semibold text-slate-800"><?= Currency::format((string)$tx['balance_after']) ?></td>
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
                        <tr class="border-b border-purple-100 uppercase text-slate-400">
                            <th class="py-2.5 pr-3">Description</th>
                            <th class="py-2.5 pr-3">Refunded</th>
                            <th class="py-2.5 text-right">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($refunds as $rf): ?>
                        <tr>
                            <td class="py-3 pr-3 text-slate-700"><?= Validator::e($rf['description']) ?></td>
                            <td class="py-3 pr-3 font-bold text-emerald-600">+<?= Currency::format((string)$rf['amount']) ?></td>
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
