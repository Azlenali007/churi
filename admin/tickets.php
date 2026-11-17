<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('tickets.manage');
$pageTitle = 'Support Ticket Queue';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = $_POST['action'] ?? '';
    $tid = (int)($_POST['ticket_id'] ?? 0);

    if ($action === 'reply' && $tid > 0) {
        $msg = Validator::str($_POST['message'] ?? '', 5000);
        if ($msg !== '') {
            Database::insert("INSERT INTO ticket_replies (ticket_id, sender_type, sender_id, message) VALUES (?, 'admin', ?, ?)", [$tid, $admin['id'], $msg]);
            Database::execute("UPDATE support_tickets SET status = 'answered', assigned_admin_id = ?, last_reply_at = NOW() WHERE id = ?", [$admin['id'], $tid]);
            $t = Database::fetch("SELECT user_id, ticket_number FROM support_tickets WHERE id = ?", [$tid]);
            if ($t) {
                Database::insert("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, 'ticket', ?, ?, ?)", [$t['user_id'], 'Ticket Reply: ' . $t['ticket_number'], 'Support staff replied to your ticket.', '/user/tickets.php?id=' . $tid]);
            }
            Helpers::setFlash('success', 'Reply sent to user.');
        }
    } elseif ($action === 'update_ticket' && $tid > 0) {
        $st = in_array($_POST['status'] ?? '', ['open','pending','answered','closed'], true) ? $_POST['status'] : 'open';
        $assign = !empty($_POST['assigned_admin_id']) ? (int)$_POST['assigned_admin_id'] : null;
        Database::execute("UPDATE support_tickets SET status = ?, assigned_admin_id = ? WHERE id = ?", [$st, $assign, $tid]);
        Helpers::setFlash('success', 'Ticket updated.');
    }
    Helpers::redirect('/admin/tickets.php?id=' . $tid);
}

$viewId = (int)($_GET['id'] ?? 0);
$tickets = Database::fetchAll(
    "SELECT st.*, u.username, a.username AS assigned_username
     FROM support_tickets st
     JOIN users u ON u.id = st.user_id
     LEFT JOIN admins a ON a.id = st.assigned_admin_id
     ORDER BY FIELD(st.status,'open','pending','answered','closed'), st.id DESC LIMIT 100"
);
$activeTicket = $viewId > 0 ? Database::fetch("SELECT st.*, u.username FROM support_tickets st JOIN users u ON u.id = st.user_id WHERE st.id = ?", [$viewId]) : null;
$replies = $activeTicket ? Database::fetchAll("SELECT * FROM ticket_replies WHERE ticket_id = ? ORDER BY id ASC", [$viewId]) : [];
$staffList = Database::fetchAll("SELECT id, username, full_name FROM admins WHERE status = 'active'");

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
    <div class="lg:col-span-5 smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-4">All Support Tickets (<?= count($tickets) ?>)</h2>
        <div class="space-y-2 max-h-[600px] overflow-y-auto">
            <?php foreach ($tickets as $t): ?>
            <a href="/admin/tickets.php?id=<?= (int)$t['id'] ?>" class="block p-3.5 rounded-2xl border <?= $activeTicket && (int)$activeTicket['id'] === (int)$t['id'] ? 'border-purple-600 bg-purple-50/70' : 'border-purple-100 hover:bg-slate-50' ?>">
                <div class="flex justify-between items-center text-xs">
                    <span class="font-bold text-purple-700"><?= Validator::e($t['ticket_number']) ?> &bull; @<?= Validator::e($t['username']) ?></span>
                    <?= Helpers::statusBadge((string)$t['status']) ?>
                </div>
                <div class="font-bold text-sm text-slate-900 mt-1 truncate"><?= Validator::e($t['subject']) ?></div>
                <div class="text-[11px] text-slate-400 mt-0.5"><?= ucfirst($t['priority']) ?> &bull; <?= ucfirst($t['department']) ?> <?= $t['assigned_username'] ? '• Assigned: ' . Validator::e($t['assigned_username']) : '' ?></div>
            </a>
            <?php endforeach; ?>
            <?php if (empty($tickets)) echo '<p class="text-xs text-slate-500 text-center py-6">No tickets submitted yet.</p>'; ?>
        </div>
    </div>

    <div class="lg:col-span-7">
        <?php if (!$activeTicket): ?>
            <div class="smm-card rounded-3xl p-12 text-center text-sm text-slate-500">Select a ticket to review and reply.</div>
        <?php else: ?>
            <div class="smm-card rounded-3xl p-6 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 pb-4 border-b border-purple-100">
                    <div>
                        <div class="text-xs font-bold text-purple-600"><?= Validator::e($activeTicket['ticket_number']) ?> &bull; @<?= Validator::e($activeTicket['username']) ?></div>
                        <h3 class="text-lg font-extrabold text-slate-900"><?= Validator::e($activeTicket['subject']) ?></h3>
                    </div>
                    <form method="POST" class="flex gap-2 text-xs">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="update_ticket">
                        <input type="hidden" name="ticket_id" value="<?= (int)$activeTicket['id'] ?>">
                        <select name="assigned_admin_id" class="px-2.5 py-1.5 rounded-xl border border-purple-200">
                            <option value="">Unassigned</option>
                            <?php foreach ($staffList as $sf): ?><option value="<?= (int)$sf['id'] ?>" <?= (int)$activeTicket['assigned_admin_id'] === (int)$sf['id'] ? 'selected' : '' ?>><?= Validator::e($sf['username']) ?></option><?php endforeach; ?>
                        </select>
                        <select name="status" class="px-2.5 py-1.5 rounded-xl border border-purple-200">
                            <?php foreach (['open','pending','answered','closed'] as $st): ?><option value="<?= $st ?>" <?= $activeTicket['status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option><?php endforeach; ?>
                        </select>
                        <button type="submit" class="px-3 py-1.5 rounded-xl bg-slate-800 text-white font-bold">Update</button>
                    </form>
                </div>

                <div class="space-y-3 max-h-96 overflow-y-auto">
                    <?php foreach ($replies as $r): $isAdm = $r['sender_type'] === 'admin'; ?>
                    <div class="p-3.5 rounded-2xl text-xs <?= $isAdm ? 'bg-purple-600 text-white ml-8' : 'bg-[#F3F1FC] text-slate-800 mr-8' ?>">
                        <div class="font-bold mb-1"><?= $isAdm ? 'Staff Reply' : '@' . Validator::e($activeTicket['username']) ?> &bull; <?= date('M j, H:i', strtotime($r['created_at'])) ?></div>
                        <div class="whitespace-pre-line"><?= Validator::e($r['message']) ?></div>
                        <?php if ($r['attachment_path']): ?><a href="<?= Validator::e($r['attachment_path']) ?>" target="_blank" class="underline font-bold mt-1 inline-block">View Attachment</a><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <form method="POST" class="space-y-3 pt-3 border-t border-slate-100">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="reply">
                    <input type="hidden" name="ticket_id" value="<?= (int)$activeTicket['id'] ?>">
                    <textarea name="message" rows="3" required placeholder="Type your official support reply..." class="w-full px-4 py-2.5 rounded-2xl border border-purple-200 text-sm"></textarea>
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-purple-600 text-white font-bold text-xs">Send Staff Reply</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
