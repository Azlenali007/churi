<?php
$pageTitle = 'Support Tickets';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/sidebar.php';
require __DIR__ . '/partials/header.php';

$statusFilter = Validator::str($_GET['status'] ?? 'all', 20);
$storeFilter = Validator::str($_GET['filter_store'] ?? 'all', 10);
$viewId = (int)($_GET['id'] ?? 0);

$sql = "SELECT * FROM support_tickets WHERE user_id = ?";
$params = [$user['id']];
if ($storeFilter === 'smm' || $storeFilter === 'otp') {
    $sql .= " AND store_type = ?";
    $params[] = $storeFilter;
}
if ($statusFilter === 'open') {
    $sql .= " AND status IN ('open','pending','answered','waiting_user')";
} elseif ($statusFilter === 'closed') {
    $sql .= " AND status IN ('closed','resolved')";
}
$sql .= " ORDER BY id DESC";
$tickets = Database::fetchAll($sql, $params);

$activeTicket = null;
$replies = [];
$linkedOrder = null;
$linkedOtp = null;
if ($viewId > 0) {
    $activeTicket = Database::fetch("SELECT * FROM support_tickets WHERE id = ? AND user_id = ? LIMIT 1", [$viewId, $user['id']]);
    if ($activeTicket) {
        $replies = Database::fetchAll("SELECT * FROM ticket_replies WHERE ticket_id = ? ORDER BY id ASC", [$activeTicket['id']]);
        if (!empty($activeTicket['order_id'])) {
            $linkedOrder = Database::fetch("SELECT id, service_name, status, charge, created_at FROM orders WHERE id = ? AND user_id = ? LIMIT 1", [$activeTicket['order_id'], $user['id']]);
        }
        if (!empty($activeTicket['otp_activation_id'])) {
            $linkedOtp = OtpService::getActivationForUser((int)$activeTicket['otp_activation_id'], (int)$user['id']);
        }
    }
}
?>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
    <!-- Create Ticket + Ticket List -->
    <div class="lg:col-span-5 space-y-6">
        <div class="smm-card rounded-3xl p-6" x-data="{ storeType: '<?= Validator::e($preselectStore ?? 'smm') ?>' }">
            <h1 class="text-lg font-extrabold text-slate-900 mb-4">Open New Support Ticket</h1>
            <form method="POST" action="/user/tickets.php" enctype="multipart/form-data" class="space-y-3">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="create_ticket">
                <input type="hidden" name="store_type" :value="storeType">

                <!-- Store Type Toggle -->
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1.5">Support Store</label>
                    <div class="grid grid-cols-2 gap-2 p-1 rounded-2xl bg-[#F3F1FC] border border-purple-100">
                        <button type="button" @click="storeType = 'smm'"
                                :class="storeType === 'smm' ? 'bg-purple-600 text-white shadow-sm' : 'text-slate-600 hover:text-purple-700'"
                                class="py-2 rounded-xl text-xs font-extrabold transition">SMM Support</button>
                        <button type="button" @click="storeType = 'otp'"
                                :class="storeType === 'otp' ? 'bg-purple-600 text-white shadow-sm' : 'text-slate-600 hover:text-purple-700'"
                                class="py-2 rounded-xl text-xs font-extrabold transition">OTP Support</button>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div x-show="storeType === 'smm'">
                        <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Category</label>
                        <select name="department" class="w-full px-3 py-2 rounded-xl border border-purple-200 text-xs font-semibold">
                            <?php foreach (TICKET_DEPARTMENTS as $dk => $dl): ?>
                                <option value="<?= $dk ?>"><?= Validator::e($dl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div x-show="storeType === 'otp'" x-cloak>
                        <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">OTP Category</label>
                        <select name="otp_category" class="w-full px-3 py-2 rounded-xl border border-purple-200 text-xs font-semibold">
                            <?php foreach (OTP_TICKET_CATEGORIES as $ok => $ol): ?>
                                <option value="<?= $ok ?>"><?= Validator::e($ol) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Priority</label>
                        <select name="priority" class="w-full px-3 py-2 rounded-xl border border-purple-200 text-xs font-semibold">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Subject</label>
                    <input type="text" name="subject" required placeholder="Brief summary of your issue" class="w-full px-3.5 py-2 rounded-xl border border-purple-200 text-sm">
                </div>

                <div x-show="storeType === 'smm'">
                    <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Related SMM Order (Optional)</label>
                    <select name="order_id" class="w-full px-3 py-2 rounded-xl border border-purple-200 text-xs font-semibold">
                        <option value="">None / General</option>
                        <?php foreach (($userOrders ?? []) as $uo): ?>
                        <option value="<?= (int)$uo['id'] ?>" <?= (int)($preselectOrderId ?? 0) === (int)$uo['id'] ? 'selected' : '' ?>>
                            #<?= (int)$uo['id'] ?> — <?= Validator::e(mb_strimwidth((string)$uo['service_name'], 0, 38, '…')) ?> (<?= Validator::e($uo['status']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div x-show="storeType === 'otp'" x-cloak>
                    <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Related OTP Activation (Optional)</label>
                    <select name="otp_activation_id" class="w-full px-3 py-2 rounded-xl border border-purple-200 text-xs font-semibold">
                        <option value="">None / General OTP</option>
                        <?php foreach (($userOtpActivations ?? []) as $ua): ?>
                        <option value="<?= (int)$ua['id'] ?>" <?= (int)($preselectOtpActId ?? 0) === (int)$ua['id'] ? 'selected' : '' ?>>
                            <?= Validator::e($ua['activation_code']) ?> — <?= Validator::e($ua['service_name']) ?> (<?= Validator::e($ua['country_name']) ?>) [<?= Validator::e($ua['status']) ?>]
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Message</label>
                    <textarea name="message" rows="4" required placeholder="Describe your request clearly..." class="w-full px-3.5 py-2 rounded-xl border border-purple-200 text-sm"></textarea>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Attachment (Optional, Image/PDF)</label>
                    <input type="file" name="attachment" accept="image/*,application/pdf" class="w-full text-xs">
                </div>
                <button type="submit" class="w-full py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs shadow">Submit Ticket</button>
            </form>
        </div>

        <div class="smm-card rounded-3xl p-6">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                <h2 class="text-base font-extrabold text-slate-900">Your Tickets</h2>
                <div class="flex gap-1 text-[11px] font-bold">
                    <a href="/user/tickets.php?status=all&filter_store=<?= urlencode($storeFilter) ?>" class="px-2.5 py-1 rounded-lg <?= $statusFilter === 'all' ? 'bg-purple-600 text-white' : 'bg-slate-100 text-slate-600' ?>">All</a>
                    <a href="/user/tickets.php?status=open&filter_store=<?= urlencode($storeFilter) ?>" class="px-2.5 py-1 rounded-lg <?= $statusFilter === 'open' ? 'bg-purple-600 text-white' : 'bg-slate-100 text-slate-600' ?>">Open</a>
                    <a href="/user/tickets.php?status=closed&filter_store=<?= urlencode($storeFilter) ?>" class="px-2.5 py-1 rounded-lg <?= $statusFilter === 'closed' ? 'bg-purple-600 text-white' : 'bg-slate-100 text-slate-600' ?>">Closed</a>
                </div>
            </div>
            <div class="flex gap-1.5 text-[11px] font-bold mb-4">
                <a href="/user/tickets.php?status=<?= urlencode($statusFilter) ?>&filter_store=all" class="px-2.5 py-1 rounded-lg <?= $storeFilter === 'all' ? 'bg-slate-900 text-white' : 'bg-purple-50 text-purple-700' ?>">All Stores</a>
                <a href="/user/tickets.php?status=<?= urlencode($statusFilter) ?>&filter_store=smm" class="px-2.5 py-1 rounded-lg <?= $storeFilter === 'smm' ? 'bg-slate-900 text-white' : 'bg-purple-50 text-purple-700' ?>">SMM</a>
                <a href="/user/tickets.php?status=<?= urlencode($statusFilter) ?>&filter_store=otp" class="px-2.5 py-1 rounded-lg <?= $storeFilter === 'otp' ? 'bg-slate-900 text-white' : 'bg-purple-50 text-purple-700' ?>">OTP</a>
            </div>
            <?php if (empty($tickets)): ?>
                <p class="text-xs text-slate-500 text-center py-6">No support tickets found.</p>
            <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($tickets as $t):
                        $stType = ($t['store_type'] ?? 'smm') === 'otp' ? 'otp' : 'smm';
                        $catLabel = $stType === 'otp'
                            ? (OTP_TICKET_CATEGORIES[$t['ticket_category'] ?? ''] ?? 'OTP Support')
                            : (TICKET_DEPARTMENTS[$t['department'] ?? ''] ?? ucfirst((string)$t['department']));
                    ?>
                    <a href="/user/tickets.php?id=<?= (int)$t['id'] ?>&status=<?= urlencode($statusFilter) ?>&filter_store=<?= urlencode($storeFilter) ?>"
                       class="block p-3.5 rounded-2xl border transition <?= $activeTicket && (int)$activeTicket['id'] === (int)$t['id'] ? 'border-purple-600 bg-purple-50/70' : 'border-purple-100 hover:bg-slate-50' ?>">
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-1.5">
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-extrabold uppercase <?= $stType === 'otp' ? 'bg-fuchsia-100 text-fuchsia-700' : 'bg-indigo-100 text-indigo-700' ?>"><?= strtoupper($stType) ?></span>
                                <span class="font-bold text-xs text-purple-700"><?= Validator::e($t['ticket_number']) ?></span>
                            </div>
                            <?= Helpers::statusBadge((string)$t['status']) ?>
                        </div>
                        <div class="font-bold text-sm text-slate-900 mt-1 truncate"><?= Validator::e($t['subject']) ?></div>
                        <div class="text-[11px] text-slate-400 mt-0.5"><?= Validator::e($catLabel) ?> &bull; <?= Helpers::timeAgo($t['created_at']) ?></div>
                    </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Conversation Thread -->
    <div class="lg:col-span-7">
        <?php if (!$activeTicket): ?>
            <div class="smm-card rounded-3xl p-12 text-center text-sm text-slate-500">
                Select a ticket from your list to view replies and continue the conversation.
            </div>
        <?php else:
            $actStore = ($activeTicket['store_type'] ?? 'smm') === 'otp' ? 'otp' : 'smm';
        ?>
            <div class="smm-card rounded-3xl p-6 flex flex-col h-full">
                <div class="flex flex-wrap items-start justify-between gap-3 pb-4 border-b border-purple-100 mb-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2 text-xs font-bold text-purple-600">
                            <span class="px-2 py-0.5 rounded-md text-[10px] font-extrabold uppercase <?= $actStore === 'otp' ? 'bg-fuchsia-100 text-fuchsia-700' : 'bg-indigo-100 text-indigo-700' ?>"><?= strtoupper($actStore) ?> Store</span>
                            <span><?= Validator::e($activeTicket['ticket_number']) ?> &bull; <?= Validator::e(ucfirst($activeTicket['priority'])) ?> Priority</span>
                        </div>
                        <h2 class="text-lg font-extrabold text-slate-900 mt-1"><?= Validator::e($activeTicket['subject']) ?></h2>
                    </div>
                    <div class="flex items-center gap-2">
                        <?= Helpers::statusBadge((string)$activeTicket['status']) ?>
                        <?php if ($activeTicket['status'] !== 'closed'): ?>
                        <form method="POST" action="/user/tickets.php">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="close_ticket">
                            <input type="hidden" name="ticket_id" value="<?= (int)$activeTicket['id'] ?>">
                            <button type="submit" class="px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold">Close</button>
                        </form>
                        <?php else: ?>
                        <form method="POST" action="/user/tickets.php">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="reopen_ticket">
                            <input type="hidden" name="ticket_id" value="<?= (int)$activeTicket['id'] ?>">
                            <button type="submit" class="px-3 py-1.5 rounded-xl bg-purple-100 hover:bg-purple-200 text-purple-800 text-xs font-bold">Reopen</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($linkedOrder): ?>
                <div class="mb-4 p-3.5 rounded-2xl bg-indigo-50/70 border border-indigo-100 text-xs flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <span class="font-extrabold text-indigo-800">Linked SMM Order #<?= (int)$linkedOrder['id'] ?>:</span>
                        <span class="text-slate-700 font-semibold"><?= Validator::e($linkedOrder['service_name']) ?></span>
                    </div>
                    <?= Helpers::statusBadge((string)$linkedOrder['status']) ?>
                </div>
                <?php endif; ?>

                <?php if ($linkedOtp): ?>
                <div class="mb-4 p-3.5 rounded-2xl bg-purple-50 border border-purple-200/80 text-xs flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <span class="font-extrabold text-purple-800">Linked OTP Activation <?= Validator::e($linkedOtp['activation_code']) ?>:</span>
                        <span class="text-slate-700 font-semibold"><?= Validator::e($linkedOtp['service_name']) ?> (<?= Validator::e($linkedOtp['country_name']) ?>)</span>
                        <?php if (!empty($linkedOtp['phone_number'])): ?>
                        <span class="ml-1 font-mono text-slate-600">&middot; <?= Validator::e($linkedOtp['phone_number']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?= Helpers::statusBadge((string)$linkedOtp['status']) ?>
                </div>
                <?php endif; ?>

                <div class="space-y-4 flex-1 max-h-[460px] overflow-y-auto mb-5 pr-1">
                    <?php foreach ($replies as $rep): $isMe = $rep['sender_type'] === 'user'; ?>
                    <div class="flex <?= $isMe ? 'justify-end' : 'justify-start' ?>">
                        <div class="max-w-[82%] rounded-2xl p-4 text-xs <?= $isMe ? 'bg-purple-600 text-white' : 'bg-[#F3F1FC] text-slate-800' ?>">
                            <div class="font-bold mb-1 <?= $isMe ? 'text-purple-100' : 'text-purple-700' ?>">
                                <?= $isMe ? 'You' : 'Support Staff' ?> &bull; <?= date('M j, H:i', strtotime($rep['created_at'])) ?>
                            </div>
                            <div class="whitespace-pre-line leading-relaxed"><?= Validator::e($rep['message']) ?></div>
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
                    <textarea name="message" rows="3" required placeholder="Write your reply..." class="w-full px-4 py-2.5 rounded-2xl border border-purple-200 text-sm outline-none focus:border-purple-600"></textarea>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <input type="file" name="attachment" accept="image/*,application/pdf" class="text-xs">
                        <button type="submit" class="px-5 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs">Send Reply</button>
                    </div>
                </form>
                <?php else: ?>
                    <div class="p-3 rounded-xl bg-slate-100 text-center text-xs text-slate-600 font-semibold">This ticket is closed. Click Reopen above if you still need assistance.</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
