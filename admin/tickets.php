<?php
/**
 * Unified Admin Support Ticket Queue (SMM Store + OTP Store)
 * Features: Store tabs (All / SMM / OTP), status/category/search filters, auto-linked SMM Order & OTP Activation cards,
 * staff replies with attachments, internal notes, and exact-once OTP refund approval/rejection.
 */
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('tickets.manage');
$pageTitle = 'Unified Support Queue';

$validStatuses = ['open', 'pending', 'answered', 'waiting_user', 'resolved', 'closed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = (string)($_POST['action'] ?? '');
    $tid = (int)($_POST['ticket_id'] ?? 0);

    if ($action === 'reply' && $tid > 0) {
        $msg = Validator::str($_POST['message'] ?? '', 5000);
        if ($msg !== '') {
            $attachPath = null;
            if (!empty($_FILES['attachment']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $up = Uploader::uploadProofOrAttachment($_FILES['attachment'], 'tickets');
                if ($up['ok']) {
                    $attachPath = $up['path'];
                }
            }
            Database::insert(
                "INSERT INTO ticket_replies (ticket_id, sender_type, sender_id, message, attachment_path) VALUES (?, 'admin', ?, ?, ?)",
                [$tid, $admin['id'], $msg, $attachPath]
            );
            Database::execute(
                "UPDATE support_tickets SET status = 'answered', assigned_admin_id = ?, last_reply_at = NOW() WHERE id = ?",
                [$admin['id'], $tid]
            );
            $t = Database::fetch("SELECT user_id, ticket_number FROM support_tickets WHERE id = ?", [$tid]);
            if ($t) {
                Database::insert(
                    "INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, 'ticket', ?, ?, ?)",
                    [$t['user_id'], 'Ticket Reply: ' . $t['ticket_number'], 'Support staff replied to your ticket.', '/user/tickets.php?id=' . $tid]
                );
            }
            Helpers::setFlash('success', 'Reply sent to user.');
        }
    } elseif ($action === 'update_ticket' && $tid > 0) {
        $st = in_array($_POST['status'] ?? '', $validStatuses, true) ? $_POST['status'] : 'open';
        $assign = !empty($_POST['assigned_admin_id']) ? (int)$_POST['assigned_admin_id'] : null;
        $notes = Validator::str($_POST['internal_notes'] ?? '', 4000);
        Database::execute(
            "UPDATE support_tickets SET status = ?, assigned_admin_id = ?, internal_notes = ? WHERE id = ?",
            [$st, $assign, $notes, $tid]
        );
        Helpers::setFlash('success', 'Ticket metadata and internal notes updated.');
    } elseif ($action === 'approve_otp_refund' && $tid > 0) {
        admin_guard('otp.refunds');
        $t = Database::fetch("SELECT * FROM support_tickets WHERE id = ? LIMIT 1", [$tid]);
        $reason = Validator::str($_POST['refund_reason'] ?? 'Approved via support ticket', 250);
        if ($t && !empty($t['otp_activation_id'])) {
            $res = OtpService::issueRefund((int)$t['otp_activation_id'], 'admin', (int)$admin['id'], $reason, $tid);
            if (!empty($res['ok'])) {
                Database::insert(
                    "INSERT INTO ticket_replies (ticket_id, sender_type, sender_id, message) VALUES (?, 'admin', ?, ?)",
                    [$tid, $admin['id'], 'Refund approved and credited to your shared wallet (' . Currency::display((string)$res['amount'], null, 2, true) . '). Note: ' . $reason]
                );
                Database::execute("UPDATE support_tickets SET status = 'resolved', last_reply_at = NOW() WHERE id = ?", [$tid]);
                Logger::activity('admin', (int)$admin['id'], 'otp_refund_approved_ticket', 'otp_activation', (string)$t['otp_activation_id'], 'Approved via ticket #' . $tid . ' amount ' . $res['amount']);
                Helpers::setFlash('success', 'OTP refund processed and credited to user shared wallet.');
            } else {
                Helpers::setFlash('error', $res['error'] ?? 'Unable to approve refund.');
            }
        }
    } elseif ($action === 'reject_otp_refund' && $tid > 0) {
        admin_guard('otp.refunds');
        $t = Database::fetch("SELECT * FROM support_tickets WHERE id = ? LIMIT 1", [$tid]);
        $reason = Validator::str($_POST['refund_reason'] ?? 'Not eligible for refund', 250);
        if ($t && !empty($t['otp_activation_id'])) {
            $res = OtpService::rejectRefund((int)$t['otp_activation_id'], (int)$admin['id'], $reason, $tid);
            if (!empty($res['ok'])) {
                Database::insert(
                    "INSERT INTO ticket_replies (ticket_id, sender_type, sender_id, message) VALUES (?, 'admin', ?, ?)",
                    [$tid, $admin['id'], 'Refund request reviewed and declined: ' . $reason]
                );
                Database::execute("UPDATE support_tickets SET status = 'answered', last_reply_at = NOW() WHERE id = ?", [$tid]);
                Logger::activity('admin', (int)$admin['id'], 'otp_refund_rejected_ticket', 'otp_activation', (string)$t['otp_activation_id'], 'Rejected via ticket #' . $tid);
                Helpers::setFlash('success', 'Refund request marked rejected.');
            } else {
                Helpers::setFlash('error', $res['error'] ?? 'Could not reject refund.');
            }
        }
    }
    Helpers::redirect('/admin/tickets.php?id=' . $tid);
}

$storeTab = Validator::str($_GET['store'] ?? 'all', 10);
$statusFilter = Validator::str($_GET['status'] ?? 'all', 20);
$search = Validator::str($_GET['q'] ?? '', 80);
$viewId = (int)($_GET['id'] ?? 0);

$where = ["1=1"];
$params = [];
if ($storeTab === 'smm' || $storeTab === 'otp') {
    $where[] = "st.store_type = ?";
    $params[] = $storeTab;
}
if ($statusFilter !== 'all' && in_array($statusFilter, $validStatuses, true)) {
    $where[] = "st.status = ?";
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where[] = "(st.ticket_number LIKE ? OR st.subject LIKE ? OR u.username LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}

$tickets = Database::fetchAll(
    "SELECT st.*, u.username, a.username AS assigned_username
     FROM support_tickets st
     JOIN users u ON u.id = st.user_id
     LEFT JOIN admins a ON a.id = st.assigned_admin_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY FIELD(st.status,'open','pending','waiting_user','answered','resolved','closed'), st.id DESC LIMIT 120",
    $params
);

$counts = Database::fetch(
    "SELECT COUNT(*) AS all_cnt,
            SUM(CASE WHEN store_type = 'smm' THEN 1 ELSE 0 END) AS smm_cnt,
            SUM(CASE WHEN store_type = 'otp' THEN 1 ELSE 0 END) AS otp_cnt
     FROM support_tickets"
) ?: ['all_cnt' => 0, 'smm_cnt' => 0, 'otp_cnt' => 0];

$activeTicket = $viewId > 0 ? Database::fetch(
    "SELECT st.*, u.username, u.email, u.wallet_balance FROM support_tickets st JOIN users u ON u.id = st.user_id WHERE st.id = ?",
    [$viewId]
) : null;
$replies = $activeTicket ? Database::fetchAll("SELECT * FROM ticket_replies WHERE ticket_id = ? ORDER BY id ASC", [$viewId]) : [];
$staffList = Database::fetchAll("SELECT id, username, full_name FROM admins WHERE status = 'active'");

$linkedOrder = ($activeTicket && !empty($activeTicket['order_id']))
    ? Database::fetch("SELECT * FROM orders WHERE id = ? LIMIT 1", [$activeTicket['order_id']])
    : null;
$linkedOtp = ($activeTicket && !empty($activeTicket['otp_activation_id']))
    ? OtpService::getActivationById((int)$activeTicket['otp_activation_id'])
    : null;
$linkedOtpRefund = $linkedOtp
    ? Database::fetch("SELECT * FROM otp_refunds WHERE activation_id = ? LIMIT 1", [$linkedOtp['id']])
    : null;

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<!-- Filter Bar -->
<div class="smm-card rounded-3xl p-4 mb-6 flex flex-wrap items-center justify-between gap-3">
    <div class="flex flex-wrap items-center gap-2 text-xs font-extrabold">
        <a href="?store=all&status=<?= urlencode($statusFilter) ?>&q=<?= urlencode($search) ?>"
           class="px-3.5 py-2 rounded-xl <?= $storeTab === 'all' ? 'bg-purple-600 text-white' : 'bg-slate-100 text-slate-700' ?>">
            All (<?= (int)$counts['all_cnt'] ?>)
        </a>
        <a href="?store=smm&status=<?= urlencode($statusFilter) ?>&q=<?= urlencode($search) ?>"
           class="px-3.5 py-2 rounded-xl <?= $storeTab === 'smm' ? 'bg-indigo-600 text-white' : 'bg-indigo-50 text-indigo-700' ?>">
            SMM Store (<?= (int)$counts['smm_cnt'] ?>)
        </a>
        <a href="?store=otp&status=<?= urlencode($statusFilter) ?>&q=<?= urlencode($search) ?>"
           class="px-3.5 py-2 rounded-xl <?= $storeTab === 'otp' ? 'bg-fuchsia-600 text-white' : 'bg-fuchsia-50 text-fuchsia-700' ?>">
            OTP Store (<?= (int)$counts['otp_cnt'] ?>)
        </a>
    </div>

    <form method="GET" class="flex flex-wrap items-center gap-2 text-xs">
        <input type="hidden" name="store" value="<?= Validator::e($storeTab) ?>">
        <select name="status" class="px-3 py-2 rounded-xl border border-purple-200 font-semibold">
            <option value="all">All Statuses</option>
            <?php foreach ($validStatuses as $vs): ?>
            <option value="<?= $vs ?>" <?= $statusFilter === $vs ? 'selected' : '' ?>><?= Validator::e(TICKET_STATUS_LABELS[$vs] ?? ucfirst($vs)) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="q" value="<?= Validator::e($search) ?>" placeholder="Ticket # or username..."
               class="px-3 py-2 rounded-xl border border-purple-200 w-44">
        <button type="submit" class="px-4 py-2 rounded-xl bg-slate-900 text-white font-bold">Filter</button>
    </form>
</div>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
    <!-- Ticket List -->
    <div class="lg:col-span-5 smm-card rounded-3xl p-5">
        <h2 class="text-sm font-extrabold text-slate-900 mb-3">Support Queue (<?= count($tickets) ?>)</h2>
        <div class="space-y-2 max-h-[680px] overflow-y-auto pr-1">
            <?php foreach ($tickets as $t):
                $stType = ($t['store_type'] ?? 'smm') === 'otp' ? 'otp' : 'smm';
            ?>
            <a href="/admin/tickets.php?id=<?= (int)$t['id'] ?>&store=<?= urlencode($storeTab) ?>&status=<?= urlencode($statusFilter) ?>"
               class="block p-3.5 rounded-2xl border transition <?= $activeTicket && (int)$activeTicket['id'] === (int)$t['id'] ? 'border-purple-600 bg-purple-50/70' : 'border-purple-100 hover:bg-slate-50' ?>">
                <div class="flex justify-between items-center gap-2 text-xs">
                    <div class="flex items-center gap-1.5 min-w-0">
                        <span class="px-1.5 py-0.5 rounded text-[10px] font-extrabold uppercase <?= $stType === 'otp' ? 'bg-fuchsia-100 text-fuchsia-700' : 'bg-indigo-100 text-indigo-700' ?>"><?= strtoupper($stType) ?></span>
                        <span class="font-bold text-purple-700 truncate"><?= Validator::e($t['ticket_number']) ?> &bull; @<?= Validator::e($t['username']) ?></span>
                    </div>
                    <?= Helpers::statusBadge((string)$t['status']) ?>
                </div>
                <div class="font-bold text-sm text-slate-900 mt-1 truncate"><?= Validator::e($t['subject']) ?></div>
                <div class="text-[11px] text-slate-400 mt-0.5">
                    <?= ucfirst($t['priority']) ?> &bull; <?= Validator::e($t['ticket_category'] ?: $t['department']) ?>
                    <?= $t['assigned_username'] ? ' · Assigned: ' . Validator::e($t['assigned_username']) : '' ?>
                </div>
            </a>
            <?php endforeach; ?>
            <?php if (empty($tickets)) echo '<p class="text-xs text-slate-500 text-center py-6">No tickets matched your filter.</p>'; ?>
        </div>
    </div>

    <!-- Ticket Detail & Actions -->
    <div class="lg:col-span-7">
        <?php if (!$activeTicket): ?>
            <div class="smm-card rounded-3xl p-12 text-center text-sm text-slate-500">Select a ticket from the queue to inspect linked order/activation details and reply.</div>
        <?php else:
            $actStore = ($activeTicket['store_type'] ?? 'smm') === 'otp' ? 'otp' : 'smm';
        ?>
            <div class="smm-card rounded-3xl p-6 space-y-5">
                <div class="flex flex-wrap items-start justify-between gap-3 pb-4 border-b border-purple-100">
                    <div>
                        <div class="flex items-center gap-2 text-xs font-bold text-purple-600">
                            <span class="px-2 py-0.5 rounded text-[10px] font-extrabold uppercase <?= $actStore === 'otp' ? 'bg-fuchsia-100 text-fuchsia-700' : 'bg-indigo-100 text-indigo-700' ?>"><?= strtoupper($actStore) ?> Store</span>
                            <span><?= Validator::e($activeTicket['ticket_number']) ?> &bull; @<?= Validator::e($activeTicket['username']) ?></span>
                            <span class="text-slate-400">(Wallet: <?= Currency::display((string)$activeTicket['wallet_balance'], null, 2, true) ?>)</span>
                        </div>
                        <h3 class="text-lg font-extrabold text-slate-900 mt-1"><?= Validator::e($activeTicket['subject']) ?></h3>
                    </div>
                    <?= Helpers::statusBadge((string)$activeTicket['status']) ?>
                </div>

                <!-- Linked SMM Order Card -->
                <?php if ($linkedOrder): ?>
                <div class="p-4 rounded-2xl bg-indigo-50/70 border border-indigo-200 text-xs space-y-1">
                    <div class="flex items-center justify-between">
                        <span class="font-extrabold text-indigo-900">Linked SMM Order #<?= (int)$linkedOrder['id'] ?></span>
                        <?= Helpers::statusBadge((string)$linkedOrder['status']) ?>
                    </div>
                    <div class="text-slate-700 font-semibold"><?= Validator::e($linkedOrder['service_name']) ?> &middot; Qty: <?= (int)$linkedOrder['quantity'] ?> &middot; Charge: <?= Currency::display((string)$linkedOrder['charge'], null, 2, true) ?></div>
                    <div class="text-slate-500 truncate">Link: <?= Validator::e($linkedOrder['link']) ?></div>
                </div>
                <?php endif; ?>

                <!-- Linked OTP Activation Card + Exact-Once Refund Controls -->
                <?php if ($linkedOtp): ?>
                <div class="p-4 rounded-2xl bg-purple-50 border border-purple-200 text-xs space-y-2.5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-extrabold text-purple-900">Linked OTP Activation <?= Validator::e($linkedOtp['activation_code']) ?></span>
                        <div class="flex items-center gap-2">
                            <?= Helpers::statusBadge((string)$linkedOtp['status']) ?>
                            <a href="/admin/otp-orders.php?view=<?= (int)$linkedOtp['id'] ?>" class="underline font-bold text-purple-700">Inspect Logs</a>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 text-[11px]">
                        <div><span class="text-slate-500">Service:</span> <strong class="text-slate-900"><?= Validator::e($linkedOtp['service_name']) ?></strong></div>
                        <div><span class="text-slate-500">Country:</span> <strong class="text-slate-900"><?= Validator::e($linkedOtp['country_name']) ?></strong></div>
                        <div><span class="text-slate-500">Price:</span> <strong class="text-slate-900"><?= Currency::display((string)$linkedOtp['selling_price'], null, 2, true) ?></strong></div>
                        <div><span class="text-slate-500">Phone:</span> <strong class="font-mono text-slate-900"><?= Validator::e($linkedOtp['phone_number'] ?: '—') ?></strong></div>
                        <div><span class="text-slate-500">OTP Code:</span> <strong class="font-mono text-emerald-700"><?= Validator::e($linkedOtp['otp_code'] ?: 'Not received') ?></strong></div>
                        <div><span class="text-slate-500">Provider Ref:</span> <strong class="font-mono text-slate-800"><?= Validator::e($linkedOtp['provider_order_id'] ?: '—') ?></strong></div>
                    </div>

                    <?php if (Auth::adminHasPermission($admin, 'otp.refunds')): ?>
                    <div class="pt-2.5 border-t border-purple-200/80">
                        <?php if (Currency::cmp((string)$linkedOtp['refunded_amount'], '0', 4) > 0 || ($linkedOtpRefund && $linkedOtpRefund['status'] === 'approved')): ?>
                            <span class="inline-flex items-center px-3 py-1 rounded-lg bg-emerald-100 text-emerald-800 font-extrabold">
                                Already Refunded (<?= Currency::display((string)$linkedOtp['refunded_amount'], null, 2, true) ?>)
                            </span>
                        <?php else: ?>
                            <div class="flex flex-wrap items-center gap-2">
                                <form method="POST" class="flex flex-wrap items-center gap-2">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="ticket_id" value="<?= (int)$activeTicket['id'] ?>">
                                    <input type="text" name="refund_reason" placeholder="Refund decision note..." value="Reviewed via ticket <?= Validator::e($activeTicket['ticket_number']) ?>"
                                           class="px-3 py-1.5 rounded-xl border border-purple-200 bg-white text-xs w-56">
                                    <button type="submit" name="action" value="approve_otp_refund"
                                            class="px-3.5 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold">Approve Refund</button>
                                    <button type="submit" name="action" value="reject_otp_refund"
                                            class="px-3.5 py-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-extrabold">Reject Refund</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Status, Assignment & Internal Notes -->
                <form method="POST" class="p-4 rounded-2xl bg-slate-50 border border-slate-200 space-y-2.5 text-xs">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="update_ticket">
                    <input type="hidden" name="ticket_id" value="<?= (int)$activeTicket['id'] ?>">
                    <div class="grid sm:grid-cols-2 gap-2.5">
                        <select name="assigned_admin_id" class="px-3 py-2 rounded-xl border border-purple-200 bg-white font-semibold">
                            <option value="">Unassigned</option>
                            <?php foreach ($staffList as $sf): ?>
                            <option value="<?= (int)$sf['id'] ?>" <?= (int)$activeTicket['assigned_admin_id'] === (int)$sf['id'] ? 'selected' : '' ?>><?= Validator::e($sf['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="status" class="px-3 py-2 rounded-xl border border-purple-200 bg-white font-semibold">
                            <?php foreach ($validStatuses as $st): ?>
                            <option value="<?= $st ?>" <?= $activeTicket['status'] === $st ? 'selected' : '' ?>><?= Validator::e(TICKET_STATUS_LABELS[$st] ?? ucfirst($st)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <textarea name="internal_notes" rows="2" placeholder="Internal staff notes (never shown to user)..."
                              class="w-full px-3 py-2 rounded-xl border border-purple-200 bg-white text-xs"><?= Validator::e((string)($activeTicket['internal_notes'] ?? '')) ?></textarea>
                    <button type="submit" class="px-4 py-1.5 rounded-xl bg-slate-900 text-white font-bold">Save Status &amp; Internal Notes</button>
                </form>

                <!-- Thread Replies -->
                <div class="space-y-3 max-h-96 overflow-y-auto pr-1">
                    <?php foreach ($replies as $r): $isAdm = $r['sender_type'] === 'admin'; ?>
                    <div class="p-3.5 rounded-2xl text-xs <?= $isAdm ? 'bg-purple-600 text-white ml-8' : 'bg-[#F3F1FC] text-slate-800 mr-8' ?>">
                        <div class="font-bold mb-1"><?= $isAdm ? 'Staff Reply' : '@' . Validator::e($activeTicket['username']) ?> &bull; <?= date('M j, H:i', strtotime($r['created_at'])) ?></div>
                        <div class="whitespace-pre-line"><?= Validator::e($r['message']) ?></div>
                        <?php if (!empty($r['attachment_path'])): ?><a href="<?= Validator::e($r['attachment_path']) ?>" target="_blank" class="underline font-bold mt-1 inline-block">View Attachment</a><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Staff Reply Form -->
                <form method="POST" enctype="multipart/form-data" class="space-y-3 pt-3 border-t border-slate-100">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="reply">
                    <input type="hidden" name="ticket_id" value="<?= (int)$activeTicket['id'] ?>">
                    <textarea name="message" rows="3" required placeholder="Type your official support reply..." class="w-full px-4 py-2.5 rounded-2xl border border-purple-200 text-sm"></textarea>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <input type="file" name="attachment" accept="image/*,application/pdf" class="text-xs">
                        <button type="submit" class="px-5 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs">Send Staff Reply</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
