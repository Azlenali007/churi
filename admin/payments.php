<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('payments.manage');
$pageTitle = 'Payments & Gateway Settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = $_POST['action'] ?? '';

    if ($action === 'review_payment') {
        $pid = (int)($_POST['payment_id'] ?? 0);
        $decision = ($_POST['decision'] ?? '') === 'approve' ? 'approve' : 'reject';
        $note = Validator::str($_POST['admin_note'] ?? '', 250);
        $res = PaymentService::reviewManualPayment($pid, (int)$admin['id'], $decision, $note);
        Helpers::setFlash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Payment ' . $decision . 'd.' : ($res['error'] ?? 'Failed'));
    } elseif ($action === 'save_razorpay') {
        $status = ($_POST['status'] ?? 'inactive') === 'active' ? 'active' : 'inactive';
        $cfg = json_encode([
            'key_id'         => trim((string)($_POST['key_id'] ?? '')),
            'key_secret'     => trim((string)($_POST['key_secret'] ?? '')),
            'webhook_secret' => trim((string)($_POST['webhook_secret'] ?? '')),
        ]);
        Database::execute(
            "UPDATE payment_gateways SET config_json = ?, min_amount = ?, max_amount = ?, fee_percent = ?, status = ? WHERE code = 'razorpay'",
            [$cfg, Currency::normalize((string)$_POST['min_amount'], 4), Currency::normalize((string)$_POST['max_amount'], 4), Currency::normalize((string)$_POST['fee_percent'], 2), $status]
        );
        Helpers::setFlash('success', 'Razorpay settings updated.');
    } elseif ($action === 'save_manual') {
        $status = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';
        Database::execute(
            "UPDATE payment_gateways SET name = ?, instructions = ?, min_amount = ?, max_amount = ?, status = ? WHERE code = 'manual_bank'",
            [
                Validator::str($_POST['name'] ?? 'Manual Bank / UPI', 100),
                Validator::str($_POST['instructions'] ?? '', 2000),
                Currency::normalize((string)$_POST['min_amount'], 4),
                Currency::normalize((string)$_POST['max_amount'], 4),
                $status,
            ]
        );
        Helpers::setFlash('success', 'Manual payment instructions updated.');
    }
    Helpers::redirect('/admin/payments.php');
}

$gateways = Database::fetchAll("SELECT * FROM payment_gateways ORDER BY sort_order ASC");
$rzp = null; $manual = null;
foreach ($gateways as $g) {
    if ($g['code'] === 'razorpay') $rzp = $g;
    if ($g['code'] === 'manual_bank') $manual = $g;
}
$rzpCfg = $rzp ? (json_decode((string)$rzp['config_json'], true) ?: []) : [];

$payments = Database::fetchAll(
    "SELECT p.*, u.username, pg.name AS gateway_name
     FROM payments p
     JOIN users u ON u.id = p.user_id
     JOIN payment_gateways pg ON pg.id = p.gateway_id
     ORDER BY FIELD(p.status, 'pending', 'completed', 'rejected', 'failed'), p.id DESC LIMIT 100"
);

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- Razorpay Gateway -->
    <div class="smm-card rounded-3xl p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-base font-extrabold text-slate-900">Razorpay Gateway Settings</h2>
            <?= Helpers::statusBadge((string)($rzp['status'] ?? 'inactive')) ?>
        </div>
        <form method="POST" class="space-y-3 text-xs">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="save_razorpay">
            <div>
                <label class="block font-bold uppercase text-slate-500 mb-1">Key ID</label>
                <input type="text" name="key_id" value="<?= Validator::e($rzpCfg['key_id'] ?? '') ?>" placeholder="rzp_live_..." class="w-full px-3 py-2 rounded-xl border border-purple-200">
            </div>
            <div>
                <label class="block font-bold uppercase text-slate-500 mb-1">Key Secret</label>
                <input type="password" name="key_secret" value="<?= Validator::e($rzpCfg['key_secret'] ?? '') ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
            </div>
            <div>
                <label class="block font-bold uppercase text-slate-500 mb-1">Webhook Secret</label>
                <input type="password" name="webhook_secret" value="<?= Validator::e($rzpCfg['webhook_secret'] ?? '') ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
            </div>
            <div class="grid grid-cols-4 gap-2">
                <input type="number" step="0.01" name="min_amount" value="<?= Validator::e((string)($rzp['min_amount'] ?? '10')) ?>" placeholder="Min" class="px-3 py-2 rounded-xl border border-purple-200">
                <input type="number" step="0.01" name="max_amount" value="<?= Validator::e((string)($rzp['max_amount'] ?? '100000')) ?>" placeholder="Max" class="px-3 py-2 rounded-xl border border-purple-200">
                <input type="number" step="0.01" name="fee_percent" value="<?= Validator::e((string)($rzp['fee_percent'] ?? '0')) ?>" placeholder="Fee %" class="px-3 py-2 rounded-xl border border-purple-200">
                <select name="status" class="px-3 py-2 rounded-xl border border-purple-200">
                    <option value="active" <?= ($rzp['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= ($rzp['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <button type="submit" class="px-5 py-2.5 rounded-xl bg-purple-600 text-white font-bold">Save Razorpay Config</button>
        </form>
    </div>

    <!-- Manual Gateway -->
    <div class="smm-card rounded-3xl p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-base font-extrabold text-slate-900">Manual Payment Method</h2>
            <?= Helpers::statusBadge((string)($manual['status'] ?? 'active')) ?>
        </div>
        <form method="POST" class="space-y-3 text-xs">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="save_manual">
            <div>
                <label class="block font-bold uppercase text-slate-500 mb-1">Method Title</label>
                <input type="text" name="name" value="<?= Validator::e($manual['name'] ?? 'Manual Bank Transfer / UPI') ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
            </div>
            <div>
                <label class="block font-bold uppercase text-slate-500 mb-1">Bank / UPI Instructions</label>
                <textarea name="instructions" rows="4" class="w-full px-3 py-2 rounded-xl border border-purple-200"><?= Validator::e($manual['instructions'] ?? '') ?></textarea>
            </div>
            <div class="grid grid-cols-3 gap-2">
                <input type="number" step="0.01" name="min_amount" value="<?= Validator::e((string)($manual['min_amount'] ?? '50')) ?>" class="px-3 py-2 rounded-xl border border-purple-200">
                <input type="number" step="0.01" name="max_amount" value="<?= Validator::e((string)($manual['max_amount'] ?? '100000')) ?>" class="px-3 py-2 rounded-xl border border-purple-200">
                <select name="status" class="px-3 py-2 rounded-xl border border-purple-200">
                    <option value="active" <?= ($manual['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= ($manual['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <button type="submit" class="px-5 py-2.5 rounded-xl bg-purple-600 text-white font-bold">Save Manual Method</button>
        </form>
    </div>
</div>

<!-- Payment Requests & History -->
<div class="smm-card rounded-3xl p-6">
    <h2 class="text-base font-extrabold text-slate-900 mb-4">Payment Requests &amp; Transactions</h2>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead><tr class="border-b border-purple-100 uppercase text-slate-400"><th class="py-2.5 pr-3">ID / Ref</th><th class="py-2.5 pr-3">User</th><th class="py-2.5 pr-3">Gateway</th><th class="py-2.5 pr-3">Amount</th><th class="py-2.5 pr-3">Proof</th><th class="py-2.5 pr-3">Status</th><th class="py-2.5 text-right">Review</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($payments as $p): ?>
                <tr>
                    <td class="py-3 pr-3"><div class="font-bold">#<?= (int)$p['id'] ?></div><div class="text-slate-500"><?= Validator::e($p['transaction_ref']) ?></div></td>
                    <td class="py-3 pr-3 font-bold">@<?= Validator::e($p['username']) ?></td>
                    <td class="py-3 pr-3"><?= Validator::e($p['gateway_name']) ?></td>
                    <td class="py-3 pr-3 font-bold text-purple-700 tabular-nums"><?= Currency::formatRaw((string)$p['amount'], (string)($p['currency'] ?: Currency::getBaseCode()), 2, true) ?></td>
                    <td class="py-3 pr-3">
                        <?php if ($p['proof_path']): ?><a href="<?= Validator::e($p['proof_path']) ?>" target="_blank" class="text-purple-600 underline font-bold">View Proof</a><?php else: ?>-<?php endif; ?>
                    </td>
                    <td class="py-3 pr-3"><?= Helpers::statusBadge((string)$p['status']) ?></td>
                    <td class="py-3 text-right">
                        <?php if ($p['status'] === 'pending' && $p['gateway_code'] !== 'razorpay'): ?>
                        <form method="POST" class="inline-flex gap-1">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="review_payment">
                            <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                            <button type="submit" name="decision" value="approve" class="px-2.5 py-1 rounded-lg bg-emerald-600 text-white font-bold">Approve</button>
                            <button type="submit" name="decision" value="reject" class="px-2.5 py-1 rounded-lg bg-rose-100 text-rose-700 font-bold">Reject</button>
                        </form>
                        <?php else: ?>
                            <span class="text-slate-400"><?= date('M j, H:i', strtotime($p['created_at'])) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($payments)) echo '<tr><td colspan="7" class="py-8 text-center text-slate-500">No payments recorded yet.</td></tr>'; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
