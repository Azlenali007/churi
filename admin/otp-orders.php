<?php
/**
 * Admin OTP Orders & Activations Manager
 * Search/filter activations, inspect provider logs & wallet transactions, Force Sync, Cancel, Complete, or Approve Refund.
 */
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('otp.manage');
$pageTitle = 'OTP Orders & Activations';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = (string)($_POST['action'] ?? '');
    $aid = (int)($_POST['activation_id'] ?? 0);

    if ($aid > 0) {
        if ($action === 'force_sync') {
            $res = OtpService::syncActivationStatus($aid, true);
            Helpers::setFlash(!empty($res['ok']) ? 'success' : 'error', !empty($res['ok']) ? 'Provider status synced.' : ($res['error'] ?? 'Sync failed.'));
        } elseif ($action === 'cancel') {
            $res = OtpService::cancelActivation($aid, (int)$admin['id'], true);
            Helpers::setFlash(!empty($res['ok']) ? 'success' : 'error', !empty($res['ok']) ? 'Activation cancelled.' : ($res['error'] ?? 'Cancel failed.'));
        } elseif ($action === 'complete') {
            $res = OtpService::finishActivation($aid, (int)$admin['id'], true);
            Helpers::setFlash(!empty($res['ok']) ? 'success' : 'error', !empty($res['ok']) ? 'Activation marked completed.' : ($res['error'] ?? 'Complete failed.'));
        } elseif ($action === 'refund') {
            admin_guard('otp.refunds');
            $reason = Validator::str($_POST['reason'] ?? 'Admin manual refund', 250);
            $res = OtpService::issueRefund($aid, 'admin', (int)$admin['id'], $reason);
            if (!empty($res['ok'])) {
                Logger::activity('admin', (int)$admin['id'], 'otp.refund', 'otp_activation', (string)$aid, $reason);
                Helpers::setFlash('success', 'Exact-once refund credited to user shared wallet.');
            } else {
                Helpers::setFlash('error', $res['error'] ?? 'Refund failed.');
            }
        }
    }
    Helpers::redirect('/admin/otp-orders.php?view=' . $aid);
}

$q = Validator::str($_GET['q'] ?? '', 80);
$status = Validator::str($_GET['status'] ?? 'all', 30);
$serviceId = (int)($_GET['service_id'] ?? 0);
$countryId = (int)($_GET['country_id'] ?? 0);
$providerId = (int)($_GET['provider_id'] ?? 0);
$viewId = (int)($_GET['view'] ?? 0);

$where = ["1=1"];
$params = [];
if ($q !== '') {
    $where[] = "(a.activation_code LIKE ? OR u.username LIKE ? OR a.phone_number LIKE ? OR a.provider_order_id LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($status !== 'all' && in_array($status, OTP_ACTIVATION_STATUSES, true)) {
    $where[] = "a.status = ?";
    $params[] = $status;
}
if ($serviceId > 0) {
    $where[] = "a.service_id = ?";
    $params[] = $serviceId;
}
if ($countryId > 0) {
    $where[] = "a.country_id = ?";
    $params[] = $countryId;
}
if ($providerId > 0) {
    $where[] = "a.provider_id = ?";
    $params[] = $providerId;
}

$orders = Database::fetchAll(
    "SELECT a.*, u.username, s.name AS service_name, c.name AS country_name, c.flag_emoji, p.name AS provider_name
     FROM otp_activations a
     JOIN users u ON u.id = a.user_id
     JOIN otp_services s ON s.id = a.service_id
     JOIN otp_countries c ON c.id = a.country_id
     LEFT JOIN otp_providers p ON p.id = a.provider_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY a.id DESC LIMIT 100",
    $params
);

$detail = null;
$detailLogs = [];
$detailTxs = [];
$detailRefund = null;
if ($viewId > 0) {
    $detail = Database::fetch(
        "SELECT a.*, u.username, u.email, s.name AS service_name, c.name AS country_name, c.flag_emoji, p.name AS provider_name
         FROM otp_activations a
         JOIN users u ON u.id = a.user_id
         JOIN otp_services s ON s.id = a.service_id
         JOIN otp_countries c ON c.id = a.country_id
         LEFT JOIN otp_providers p ON p.id = a.provider_id
         WHERE a.id = ? LIMIT 1",
        [$viewId]
    );
    if ($detail) {
        $detailLogs = Database::fetchAll("SELECT * FROM otp_provider_logs WHERE activation_id = ? ORDER BY id DESC LIMIT 20", [$viewId]);
        $detailTxs = Database::fetchAll("SELECT * FROM wallet_transactions WHERE reference_type = 'otp_activation' AND reference_id = ? ORDER BY id DESC", [(string)$viewId]);
        $detailRefund = Database::fetch("SELECT * FROM otp_refunds WHERE activation_id = ? LIMIT 1", [$viewId]);
    }
}

$services = Database::fetchAll("SELECT id, name FROM otp_services ORDER BY name ASC");
$countries = Database::fetchAll("SELECT id, name FROM otp_countries ORDER BY name ASC");
$providers = Database::fetchAll("SELECT id, name FROM otp_providers ORDER BY name ASC");

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<!-- Filters -->
<form method="GET" class="smm-card rounded-2xl p-4 mb-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-2.5 text-xs">
    <input type="text" name="q" value="<?= Validator::e($q) ?>" placeholder="Code, user, phone, ref..." class="px-3 py-2 rounded-xl border border-purple-200">
    <select name="status" class="px-3 py-2 rounded-xl border border-purple-200 font-semibold">
        <option value="all">All Statuses</option>
        <?php foreach (OTP_ACTIVATION_STATUSES as $st): ?>
        <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $st)) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="service_id" class="px-3 py-2 rounded-xl border border-purple-200 font-semibold">
        <option value="0">All Services</option>
        <?php foreach ($services as $sv): ?><option value="<?= (int)$sv['id'] ?>" <?= $serviceId === (int)$sv['id'] ? 'selected' : '' ?>><?= Validator::e($sv['name']) ?></option><?php endforeach; ?>
    </select>
    <select name="country_id" class="px-3 py-2 rounded-xl border border-purple-200 font-semibold">
        <option value="0">All Countries</option>
        <?php foreach ($countries as $ct): ?><option value="<?= (int)$ct['id'] ?>" <?= $countryId === (int)$ct['id'] ? 'selected' : '' ?>><?= Validator::e($ct['name']) ?></option><?php endforeach; ?>
    </select>
    <select name="provider_id" class="px-3 py-2 rounded-xl border border-purple-200 font-semibold">
        <option value="0">All Providers</option>
        <?php foreach ($providers as $pr): ?><option value="<?= (int)$pr['id'] ?>" <?= $providerId === (int)$pr['id'] ? 'selected' : '' ?>><?= Validator::e($pr['name']) ?></option><?php endforeach; ?>
    </select>
    <div class="flex gap-1.5">
        <button type="submit" class="flex-1 px-4 py-2 rounded-xl bg-purple-600 text-white font-bold">Filter</button>
        <a href="/admin/otp-orders.php" class="px-3 py-2 rounded-xl bg-slate-100 text-slate-700 font-bold">Reset</a>
    </div>
</form>

<?php if ($detail): ?>
<!-- Activation Inspector Panel -->
<div class="smm-card rounded-3xl p-6 mb-6 border-2 border-purple-200 space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3 pb-4 border-b border-purple-100">
        <div>
            <div class="text-xs font-mono font-bold text-purple-600"><?= Validator::e($detail['activation_code']) ?> &middot; @<?= Validator::e($detail['username']) ?></div>
            <h2 class="text-lg font-extrabold text-slate-900"><?= Validator::e($detail['service_name']) ?> — <?= Validator::e(($detail['flag_emoji'] ? $detail['flag_emoji'] . ' ' : '') . $detail['country_name']) ?></h2>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <?= Helpers::statusBadge((string)$detail['status']) ?>
            <a href="/admin/otp-orders.php" class="px-3 py-1.5 rounded-xl bg-slate-100 font-bold text-slate-700">Close Detail</a>
        </div>
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
        <div class="p-3 rounded-2xl bg-[#FAF8FF] border border-purple-100">
            <div class="text-[10px] font-bold uppercase text-slate-400">Phone Number</div>
            <div class="font-mono font-extrabold text-slate-900 mt-1"><?= Validator::e($detail['phone_number'] ?: '—') ?></div>
        </div>
        <div class="p-3 rounded-2xl bg-[#FAF8FF] border border-purple-100">
            <div class="text-[10px] font-bold uppercase text-slate-400">OTP Code / SMS</div>
            <div class="font-mono font-extrabold text-emerald-700 mt-1"><?= Validator::e($detail['otp_code'] ?: 'Not received') ?></div>
            <?php if (!empty($detail['sms_text'])): ?><div class="text-[11px] text-slate-600 mt-0.5"><?= Validator::e($detail['sms_text']) ?></div><?php endif; ?>
        </div>
        <div class="p-3 rounded-2xl bg-[#FAF8FF] border border-purple-100">
            <div class="text-[10px] font-bold uppercase text-slate-400">Provider &amp; Ref</div>
            <div class="font-bold text-slate-900 mt-1"><?= Validator::e($detail['provider_name'] ?: '—') ?></div>
            <div class="font-mono text-[11px] text-slate-500">Ref: <?= Validator::e($detail['provider_order_id'] ?: '—') ?> (<?= Validator::e($detail['provider_status'] ?: '—') ?>)</div>
        </div>
        <div class="p-3 rounded-2xl bg-[#FAF8FF] border border-purple-100">
            <div class="text-[10px] font-bold uppercase text-slate-400">Financials</div>
            <div class="font-extrabold text-slate-900 mt-1">Sell: <?= Currency::display((string)$detail['selling_price'], null, 2, true) ?></div>
            <div class="text-[11px] text-slate-500">Cost: <?= Currency::display((string)$detail['provider_cost'], null, 2, true) ?> · Refund: <?= Currency::display((string)$detail['refunded_amount'], null, 2, true) ?></div>
        </div>
    </div>

    <!-- Admin Controls -->
    <div class="flex flex-wrap items-center gap-2 pt-2 text-xs">
        <form method="POST" class="inline">
            <?= CSRF::field() ?>
            <input type="hidden" name="activation_id" value="<?= (int)$detail['id'] ?>">
            <button type="submit" name="action" value="force_sync" class="px-3.5 py-2 rounded-xl bg-purple-100 text-purple-800 font-extrabold">Force Sync Provider</button>
        </form>
        <?php if (in_array($detail['status'], ['active', 'waiting_otp'], true)): ?>
        <form method="POST" class="inline">
            <?= CSRF::field() ?>
            <input type="hidden" name="activation_id" value="<?= (int)$detail['id'] ?>">
            <button type="submit" name="action" value="cancel" class="px-3.5 py-2 rounded-xl bg-amber-100 text-amber-800 font-extrabold">Cancel Activation</button>
        </form>
        <?php endif; ?>
        <?php if (in_array($detail['status'], ['active', 'waiting_otp', 'otp_received'], true)): ?>
        <form method="POST" class="inline">
            <?= CSRF::field() ?>
            <input type="hidden" name="activation_id" value="<?= (int)$detail['id'] ?>">
            <button type="submit" name="action" value="complete" class="px-3.5 py-2 rounded-xl bg-emerald-600 text-white font-extrabold">Mark Completed</button>
        </form>
        <?php endif; ?>
        <?php if (Auth::adminHasPermission($admin, 'otp.refunds') && Currency::cmp((string)$detail['refunded_amount'], '0', 4) <= 0): ?>
        <form method="POST" class="inline-flex items-center gap-1.5">
            <?= CSRF::field() ?>
            <input type="hidden" name="activation_id" value="<?= (int)$detail['id'] ?>">
            <input type="text" name="reason" value="Admin verified refund" class="px-3 py-1.5 rounded-xl border border-purple-200 text-xs w-48">
            <button type="submit" name="action" value="refund" class="px-3.5 py-2 rounded-xl bg-rose-600 text-white font-extrabold">Approve Refund</button>
        </form>
        <?php endif; ?>
    </div>

    <!-- Wallet Transactions & Provider Logs -->
    <div class="grid lg:grid-cols-2 gap-4 pt-3 border-t border-purple-100">
        <div>
            <h3 class="text-xs font-extrabold uppercase text-slate-500 mb-2">Linked Shared Wallet Transactions</h3>
            <?php if (empty($detailTxs)): ?>
                <p class="text-xs text-slate-400">None found.</p>
            <?php else: foreach ($detailTxs as $tx): ?>
                <div class="p-2.5 mb-1.5 rounded-xl bg-slate-50 border border-slate-200 text-[11px] flex justify-between">
                    <span><strong>#<?= (int)$tx['id'] ?></strong> <?= Validator::e($tx['type']) ?> — <?= Validator::e($tx['description']) ?></span>
                    <strong class="tabular-nums"><?= Currency::display((string)$tx['amount'], null, 2, true) ?></strong>
                </div>
            <?php endforeach; endif; ?>
        </div>
        <div>
            <h3 class="text-xs font-extrabold uppercase text-slate-500 mb-2">Provider API Logs (Redacted)</h3>
            <div class="max-h-48 overflow-y-auto space-y-1.5">
                <?php if (empty($detailLogs)): ?>
                    <p class="text-xs text-slate-400">No provider API logs for this activation.</p>
                <?php else: foreach ($detailLogs as $lg): ?>
                    <div class="p-2.5 rounded-xl border text-[11px] <?= $lg['status'] === 'ok' ? 'bg-emerald-50/40 border-emerald-200' : 'bg-rose-50/60 border-rose-200' ?>">
                        <div class="flex justify-between font-bold">
                            <span><?= Validator::e($lg['action']) ?> (HTTP <?= (int)$lg['http_code'] ?>)</span>
                            <span class="text-slate-400"><?= date('H:i:s', strtotime($lg['created_at'])) ?></span>
                        </div>
                        <div class="font-mono text-[10px] text-slate-600 truncate mt-0.5"><?= Validator::e((string)$lg['response_payload']) ?></div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Orders Table -->
<div class="smm-card rounded-3xl overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead class="bg-[#FAF8FF] text-slate-500 uppercase text-[10px] font-extrabold">
                <tr>
                    <th class="px-4 py-3">Activation</th>
                    <th class="px-4 py-3">User</th>
                    <th class="px-4 py-3">Service / Country</th>
                    <th class="px-4 py-3">Provider / Ref</th>
                    <th class="px-4 py-3">Phone &amp; OTP</th>
                    <th class="px-4 py-3">Price</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 text-right">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-purple-50">
                <?php if (empty($orders)): ?>
                    <tr><td colspan="8" class="px-4 py-8 text-center text-slate-500">No OTP activations found.</td></tr>
                <?php else: foreach ($orders as $o): ?>
                <tr class="hover:bg-purple-50/30">
                    <td class="px-4 py-3 font-mono font-bold text-slate-900">
                        <?= Validator::e($o['activation_code']) ?>
                        <div class="text-[10px] font-sans text-slate-400"><?= date('M d, H:i', strtotime($o['created_at'])) ?></div>
                    </td>
                    <td class="px-4 py-3 font-bold text-purple-700">@<?= Validator::e($o['username']) ?></td>
                    <td class="px-4 py-3">
                        <div class="font-bold text-slate-900"><?= Validator::e($o['service_name']) ?></div>
                        <div class="text-[10px] text-slate-500"><?= Validator::e(($o['flag_emoji'] ? $o['flag_emoji'] . ' ' : '') . $o['country_name']) ?></div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-semibold"><?= Validator::e($o['provider_name'] ?: '—') ?></div>
                        <div class="text-[10px] font-mono text-slate-400"><?= Validator::e($o['provider_order_id'] ?: '—') ?></div>
                    </td>
                    <td class="px-4 py-3 font-mono">
                        <div><?= Validator::e($o['phone_number'] ?: '—') ?></div>
                        <?php if (!empty($o['otp_code'])): ?><span class="px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-800 font-extrabold"><?= Validator::e($o['otp_code']) ?></span><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 font-bold tabular-nums"><?= Currency::display((string)$o['selling_price'], null, 2, true) ?></td>
                    <td class="px-4 py-3"><?= Helpers::statusBadge((string)$o['status']) ?></td>
                    <td class="px-4 py-3 text-right">
                        <a href="/admin/otp-orders.php?view=<?= (int)$o['id'] ?>" class="px-3 py-1.5 rounded-xl bg-purple-50 hover:bg-purple-100 text-purple-700 font-bold">Inspect</a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
