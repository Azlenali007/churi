<?php
/**
 * Admin OTP Refunds Manager — Exact-Once Shared Wallet Refund Verification & History
 */
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('otp.refunds');
$pageTitle = 'OTP Refunds';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = (string)($_POST['action'] ?? '');
    $actId = (int)($_POST['activation_id'] ?? 0);
    $note = Validator::str($_POST['admin_note'] ?? '', 250);

    if ($actId > 0) {
        if ($action === 'check_provider') {
            $sync = OtpService::syncActivationStatus($actId, true);
            Helpers::setFlash(!empty($sync['ok']) ? 'success' : 'error', !empty($sync['ok']) ? 'Provider status refreshed before refund decision.' : ($sync['error'] ?? 'Provider sync failed.'));
        } elseif ($action === 'approve') {
            $res = OtpService::issueRefund($actId, 'admin', (int)$admin['id'], $note ?: 'Approved by administrator');
            if (!empty($res['ok'])) {
                Logger::activity('admin', (int)$admin['id'], 'otp_refund.approve', 'otp_activation', (string)$actId, $note);
                Helpers::setFlash('success', 'Refund approved and credited to shared wallet.');
            } else {
                Helpers::setFlash('error', $res['error'] ?? 'Unable to process refund.');
            }
        } elseif ($action === 'reject') {
            $res = OtpService::rejectRefund($actId, (int)$admin['id'], $note ?: 'Rejected after provider review');
            if (!empty($res['ok'])) {
                Logger::activity('admin', (int)$admin['id'], 'otp_refund.reject', 'otp_activation', (string)$actId, $note);
                Helpers::setFlash('success', 'Refund request rejected.');
            } else {
                Helpers::setFlash('error', $res['error'] ?? 'Unable to reject refund.');
            }
        }
    }
    Helpers::redirect('/admin/otp-refunds.php');
}

$statusFilter = Validator::str($_GET['status'] ?? 'all', 20);
$where = "1=1";
$params = [];
if (in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
    $where = "r.status = ?";
    $params[] = $statusFilter;
}

$refunds = Database::fetchAll(
    "SELECT r.*, u.username, a.activation_code, a.phone_number, a.otp_code, a.status AS act_status,
            a.provider_status, s.name AS service_name, c.name AS country_name
     FROM otp_refunds r
     JOIN users u ON u.id = r.user_id
     JOIN otp_activations a ON a.id = r.activation_id
     JOIN otp_services s ON s.id = a.service_id
     JOIN otp_countries c ON c.id = a.country_id
     WHERE {$where}
     ORDER BY FIELD(r.status,'pending','approved','rejected'), r.id DESC LIMIT 120",
    $params
);

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="flex flex-wrap items-center justify-between gap-3 mb-5">
    <div class="flex gap-2 text-xs font-extrabold">
        <?php foreach (['all' => 'All', 'pending' => 'Pending Review', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $k => $lbl): ?>
        <a href="?status=<?= $k ?>" class="px-4 py-2 rounded-xl <?= $statusFilter === $k ? 'bg-purple-600 text-white' : 'bg-white border border-purple-100 text-slate-700' ?>"><?= $lbl ?></a>
        <?php endforeach; ?>
    </div>
    <div class="text-xs text-slate-500 font-semibold">Exact-once database lock prevents duplicate refunds on any activation.</div>
</div>

<div class="smm-card rounded-3xl overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead class="bg-[#FAF8FF] text-slate-500 uppercase text-[10px] font-extrabold">
                <tr>
                    <th class="px-4 py-3">Activation / User</th>
                    <th class="px-4 py-3">Service / Phone / OTP</th>
                    <th class="px-4 py-3">Amount</th>
                    <th class="px-4 py-3">Reason &amp; Source</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-purple-50">
                <?php if (empty($refunds)): ?>
                    <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No OTP refund records found.</td></tr>
                <?php else: foreach ($refunds as $rf): ?>
                <tr class="hover:bg-purple-50/30">
                    <td class="px-4 py-3">
                        <a href="/admin/otp-orders.php?view=<?= (int)$rf['activation_id'] ?>" class="font-mono font-bold text-purple-700 hover:underline"><?= Validator::e($rf['activation_code']) ?></a>
                        <div class="text-[11px] text-slate-500">@<?= Validator::e($rf['username']) ?> &middot; <?= date('M d, H:i', strtotime($rf['created_at'])) ?></div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-bold text-slate-900"><?= Validator::e($rf['service_name']) ?> (<?= Validator::e($rf['country_name']) ?>)</div>
                        <div class="font-mono text-[11px] text-slate-600">
                            <?= Validator::e($rf['phone_number'] ?: '—') ?>
                            <?php if (!empty($rf['otp_code'])): ?>
                            <span class="ml-1 px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 font-bold">OTP: <?= Validator::e($rf['otp_code']) ?></span>
                            <?php else: ?>
                            <span class="ml-1 text-slate-400">No OTP</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-4 py-3 font-extrabold tabular-nums"><?= Currency::display((string)$rf['amount'], null, 2, true) ?></td>
                    <td class="px-4 py-3 max-w-xs">
                        <div class="text-slate-800 font-semibold"><?= Validator::e($rf['reason'] ?: '—') ?></div>
                        <div class="text-[10px] text-slate-400 uppercase">By: <?= Validator::e($rf['requested_by_type']) ?> · Prov: <?= Validator::e($rf['provider_status'] ?: '—') ?></div>
                    </td>
                    <td class="px-4 py-3"><?= Helpers::statusBadge((string)$rf['status']) ?></td>
                    <td class="px-4 py-3 text-right">
                        <?php if ($rf['status'] === 'pending'): ?>
                        <form method="POST" class="inline-flex flex-wrap items-center justify-end gap-1.5">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="activation_id" value="<?= (int)$rf['activation_id'] ?>">
                            <input type="text" name="admin_note" placeholder="Note..." class="px-2.5 py-1 rounded-lg border border-purple-200 text-[11px] w-32">
                            <button type="submit" name="action" value="check_provider" class="px-2.5 py-1.5 rounded-lg bg-slate-100 text-slate-700 font-bold">Sync</button>
                            <button type="submit" name="action" value="approve" class="px-2.5 py-1.5 rounded-lg bg-emerald-600 text-white font-bold">Approve</button>
                            <button type="submit" name="action" value="reject" class="px-2.5 py-1.5 rounded-lg bg-rose-600 text-white font-bold">Reject</button>
                        </form>
                        <?php else: ?>
                        <span class="text-[11px] text-slate-400"><?= $rf['processed_at'] ? date('M d, H:i', strtotime($rf['processed_at'])) : 'Processed' ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
