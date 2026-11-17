<?php
$pageTitle = 'Support Tickets';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/sidebar.php';
require __DIR__ . '/partials/header.php';

$statusFilter = Validator::str($_GET['status'] ?? 'all', 20);
$viewId = (int)($_GET['id'] ?? 0);

$sql = "SELECT * FROM support_tickets WHERE user_id = ?";
$params = [$user['id']];
if ($statusFilter === 'open') {
    $sql .= " AND status IN ('open','pending','answered')";
} elseif ($statusFilter === 'closed') {
    $sql .= " AND status = 'closed'";
}
$sql .= " ORDER BY id DESC";
$tickets = Database::fetchAll($sql, $params);

$activeTicket = null;
$replies = [];
if ($viewId > 0) {
    $activeTicket = Database::fetch("SELECT * FROM support_tickets WHERE id = ? AND user_id = ? LIMIT 1", [$viewId, $user['id']]);
    if ($activeTicket) {
        $replies = Database::fetchAll("SELECT * FROM ticket_replies WHERE ticket_id = ? ORDER BY id ASC", [$activeTicket['id']]);
    }
}
?>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
    <!-- Create Ticket + Ticket List -->
    <div class="lg:col-span-5 space-y-6 min-w-0">
        <div class="swipex-card rounded-3xl p-6">
            <h1 class="text-lg font-extrabold text-slate-900 mb-4">Open New Support Ticket</h1>
            <form method="POST" action="/user/tickets.php" enctype="multipart/form-data" class="space-y-3">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="create_ticket">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-500 uppercase mb-1">Department</label>
                        <select name="department" class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-blue-600 text-xs font-semibold bg-white">
                            <?php foreach (TICKET_DEPARTMENTS as $dk => $dl): ?>
                                <option value="<?= $dk ?>"><?= Validator::e($dl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-500 uppercase mb-1">Priority</label>
                        <select name="priority" class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-blue-600 text-xs font-semibold bg-white">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 uppercase mb-1">Subject</label>
                    <input type="text" name="subject" required placeholder="Brief summary of your issue" class="w-full px-3.5 py-2 rounded-xl border border-slate-200 focus:border-blue-600 text-sm bg-white">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 uppercase mb-1">Related Order ID (Optional)</label>
                    <input type="number" name="order_id" placeholder="e.g. 1024" class="w-full px-3.5 py-2 rounded-xl border border-slate-200 focus:border-blue-600 text-sm bg-white">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 uppercase mb-1">Message</label>
                    <textarea name="message" rows="4" required placeholder="Describe your request clearly..." class="w-full px-3.5 py-2 rounded-xl border border-slate-200 focus:border-blue-600 text-sm bg-white"></textarea>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 uppercase mb-1">Attachment (Optional, Image/PDF)</label>
                    <input type="file" name="attachment" accept="image/*,application/pdf" class="w-full text-xs">
                </div>
                <button type="submit" class="w-full py-2.5 rounded-full bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs shadow transition">Submit Ticket</button>
            </form>
        </div>

        <div class="swipex-card rounded-3xl p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-extrabold text-slate-900">Your Tickets</h2>
                <div class="flex gap-1 text-[11px] font-bold">
                    <a href="/user/tickets.php?status=all" class="px-2.5 py-1 rounded-full <?= $statusFilter === 'all' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600' ?>">All</a>
                    <a href="/user/tickets.php?status=open" class="px-2.5 py-1 rounded-full <?= $statusFilter === 'open' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600' ?>">Open</a>
                    <a href="/user/tickets.php?status=closed" class="px-2.5 py-1 rounded-full <?= $statusFilter === 'closed' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600' ?>">Closed</a>
                </div>
            </div>
            <?php if (empty($tickets)): ?>
                <p class="text-xs text-slate-500 text-center py-6">No support tickets found.</p>
            <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($tickets as $t): ?>
                    <a href="/user/tickets.php?id=<?= (int)$t['id'] ?>&status=<?= urlencode($statusFilter) ?>"
                       class="block p-3.5 rounded-2xl border transition <?= $activeTicket && (int)$activeTicket['id'] === (int)$t['id'] ? 'border-blue-600 bg-blue-50/70' : 'border-slate-200 hover:bg-slate-50' ?>">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-bold text-xs text-blue-600"><?= Validator::e($t['ticket_number']) ?></span>
                            <?= Helpers::statusBadge((string)$t['status']) ?>
                        </div>
                        <div class="font-bold text-sm text-slate-900 mt-1 truncate"><?= Validator::e($t['subject']) ?></div>
                        <div class="text-[11px] text-slate-400 mt-0.5"><?= ucfirst($t['department']) ?> &bull; <?= Helpers::timeAgo($t['created_at']) ?></div>
                    </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Conversation Thread -->
    <div class="lg:col-span-7 min-w-0">
        <?php if (!$activeTicket): ?>
            <div class="swipex-card rounded-3xl p-12 text-center text-sm text-slate-500">
                Select a ticket from your list to view replies and continue the conversation.
            </div>
        <?php else: ?>
            <div class="swipex-card rounded-3xl p-6 flex flex-col h-full">
                <div class="flex items-start justify-between gap-3 pb-4 border-b border-slate-100 mb-5">
                    <div class="min-w-0">
                        <div class="text-xs font-bold text-blue-600"><?= Validator::e($activeTicket['ticket_number']) ?> &bull; <?= Validator::e(ucfirst($activeTicket['priority'])) ?> Priority</div>
                        <h2 class="text-lg font-extrabold text-slate-900 break-words"><?= Validator::e($activeTicket['subject']) ?></h2>
                    </div>
                    <?= Helpers::statusBadge((string)$activeTicket['status']) ?>
                </div>

                <div class="space-y-4 flex-1 max-h-[460px] overflow-y-auto mb-5 pr-1">
                    <?php foreach ($replies as $rep): $isMe = $rep['sender_type'] === 'user'; ?>
                    <div class="flex <?= $isMe ? 'justify-end' : 'justify-start' ?>">
                        <div class="max-w-[82%] rounded-2xl p-4 text-xs <?= $isMe ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-800' ?>">
                            <div class="font-bold mb-1 <?= $isMe ? 'text-blue-100' : 'text-blue-700' ?>">
                                <?= $isMe ? 'You' : 'Support Staff' ?> &bull; <?= date('M j, H:i', strtotime($rep['created_at'])) ?>
                            </div>
                            <div class="whitespace-pre-line leading-relaxed break-words"><?= Validator::e($rep['message']) ?></div>
                            <?php if (!empty($rep['attachment_path'])): ?>
                                <div class="mt-2">
                                    <a href="<?= Validator::e($rep['attachment_path']) ?>" target="_blank" class="underline font-bold">View Attachment</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($activeTicket['status'] !== 'closed'): ?>
                <form method="POST" action="/user/tickets.php" enctype="multipart/form-data" class="space-y-3 border-t border-slate-100 pt-4">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="reply_ticket">
                    <input type="hidden" name="ticket_id" value="<?= (int)$activeTicket['id'] ?>">
                    <textarea name="message" rows="3" required placeholder="Write your reply..." class="w-full px-4 py-2.5 rounded-2xl border border-slate-200 text-sm outline-none focus:border-blue-600 bg-white"></textarea>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <input type="file" name="attachment" accept="image/*,application/pdf" class="text-xs">
                        <button type="submit" class="px-5 py-2.5 rounded-full bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs shadow transition">Send Reply</button>
                    </div>
                </form>
                <?php else: ?>
                    <div class="p-3 rounded-xl bg-slate-100 text-center text-xs text-slate-600 font-semibold">This ticket is closed.</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
