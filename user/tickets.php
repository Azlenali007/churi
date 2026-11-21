<?php
/**
 * Unified User Support Tickets Controller (SMM Store + OTP Store)
 * One ticket system for both stores with store_type tagging, category selection,
 * linked SMM order or OTP activation, attachments, close/reopen, and auto refund-request linkage.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$user = Auth::requireUser();
$userId = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_ticket') {
        $storeType = (($_POST['store_type'] ?? 'smm') === 'otp') ? 'otp' : 'smm';
        $priority = in_array($_POST['priority'] ?? '', ['low', 'medium', 'high', 'urgent'], true) ? $_POST['priority'] : 'medium';
        $subject = Validator::str($_POST['subject'] ?? '', 200);
        $message = Validator::str($_POST['message'] ?? '', 5000);

        if ($storeType === 'otp') {
            $rawCat = (string)($_POST['otp_category'] ?? ($_POST['ticket_category'] ?? 'general_otp'));
            $ticketCat = isset(OTP_TICKET_CATEGORIES[$rawCat]) ? $rawCat : 'general_otp';
            $dept = ($ticketCat === 'cancel_refund' || $ticketCat === 'wallet_billing') ? 'billing' : 'order';
        } else {
            $rawDept = (string)($_POST['department'] ?? 'general');
            $dept = isset(TICKET_DEPARTMENTS[$rawDept]) ? $rawDept : 'general';
            $ticketCat = $dept;
        }

        // Validate ownership of linked SMM order or OTP activation
        $orderId = null;
        $otpActivationId = null;
        if ($storeType === 'smm' && !empty($_POST['order_id'])) {
            $oid = (int)$_POST['order_id'];
            $ownedOrder = Database::fetchColumn("SELECT id FROM orders WHERE id = ? AND user_id = ? LIMIT 1", [$oid, $userId]);
            $orderId = $ownedOrder ? (int)$ownedOrder : null;
        } elseif ($storeType === 'otp' && !empty($_POST['otp_activation_id'])) {
            $aid = (int)$_POST['otp_activation_id'];
            $ownedAct = Database::fetchColumn("SELECT id FROM otp_activations WHERE id = ? AND user_id = ? LIMIT 1", [$aid, $userId]);
            $otpActivationId = $ownedAct ? (int)$ownedAct : null;
        }

        if ($subject === '' || $message === '') {
            Helpers::setFlash('error', 'Subject and message are required.');
            Helpers::redirect('/user/tickets.php?store=' . $storeType);
        }

        $attachPath = null;
        if (!empty($_FILES['attachment']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $up = Uploader::uploadProofOrAttachment($_FILES['attachment'], 'tickets');
            if (!$up['ok']) {
                Helpers::setFlash('error', $up['error']);
                Helpers::redirect('/user/tickets.php?store=' . $storeType);
            }
            $attachPath = $up['path'];
        }

        $ticketNum = 'TKT-' . strtoupper(bin2hex(random_bytes(3)));
        $tid = Database::insert(
            "INSERT INTO support_tickets
             (user_id, ticket_number, store_type, department, ticket_category, priority, subject, order_id, otp_activation_id, status, last_reply_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', NOW())",
            [$userId, $ticketNum, $storeType, $dept, $ticketCat, $priority, $subject, $orderId, $otpActivationId]
        );

        Database::insert(
            "INSERT INTO ticket_replies (ticket_id, sender_type, sender_id, message, attachment_path) VALUES (?, 'user', ?, ?, ?)",
            [$tid, $userId, $message, $attachPath]
        );

        // If user filed an OTP cancellation/refund ticket tied to an activation, register a pending refund review request
        if ($storeType === 'otp' && $otpActivationId && $ticketCat === 'cancel_refund') {
            OtpService::createPendingRefundRequest($otpActivationId, $userId, 'Ticket ' . $ticketNum . ': ' . $subject, (int)$tid);
        }

        Helpers::setFlash('success', 'Support ticket ' . $ticketNum . ' created.');
        Helpers::redirect('/user/tickets.php?id=' . $tid);
    } elseif ($action === 'reply_ticket') {
        $tid = (int)($_POST['ticket_id'] ?? 0);
        $ticket = Database::fetch("SELECT * FROM support_tickets WHERE id = ? AND user_id = ? LIMIT 1", [$tid, $userId]);
        $message = Validator::str($_POST['message'] ?? '', 5000);
        if ($ticket && $ticket['status'] !== 'closed' && $message !== '') {
            $attachPath = null;
            if (!empty($_FILES['attachment']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $up = Uploader::uploadProofOrAttachment($_FILES['attachment'], 'tickets');
                if (!$up['ok']) {
                    Helpers::setFlash('error', $up['error']);
                    Helpers::redirect('/user/tickets.php?id=' . $tid);
                }
                $attachPath = $up['path'];
            }
            Database::insert(
                "INSERT INTO ticket_replies (ticket_id, sender_type, sender_id, message, attachment_path) VALUES (?, 'user', ?, ?, ?)",
                [$tid, $userId, $message, $attachPath]
            );
            Database::execute("UPDATE support_tickets SET status = 'open', last_reply_at = NOW() WHERE id = ?", [$tid]);
            Helpers::setFlash('success', 'Reply sent.');
        }
        Helpers::redirect('/user/tickets.php?id=' . $tid);
    } elseif ($action === 'close_ticket') {
        $tid = (int)($_POST['ticket_id'] ?? 0);
        Database::execute("UPDATE support_tickets SET status = 'closed' WHERE id = ? AND user_id = ?", [$tid, $userId]);
        Helpers::setFlash('success', 'Ticket closed.');
        Helpers::redirect('/user/tickets.php?id=' . $tid);
    } elseif ($action === 'reopen_ticket') {
        $tid = (int)($_POST['ticket_id'] ?? 0);
        Database::execute("UPDATE support_tickets SET status = 'open' WHERE id = ? AND user_id = ? AND status IN ('closed','resolved')", [$tid, $userId]);
        Helpers::setFlash('success', 'Ticket reopened.');
        Helpers::redirect('/user/tickets.php?id=' . $tid);
    }
}

$preselectStore = (($_GET['store'] ?? '') === 'otp' || !empty($_GET['otp_activation_id']) || !StoreManager::isSmmEnabled()) ? 'otp' : 'smm';
$preselectOtpActId = (int)($_GET['otp_activation_id'] ?? 0);
$preselectOrderId = (int)($_GET['order_id'] ?? 0);

$userOrders = Database::fetchAll(
    "SELECT id, service_name, status, created_at FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 30",
    [$userId]
);
$userOtpActivations = Database::fetchAll(
    "SELECT a.id, a.activation_code, a.phone_number, a.status, s.name AS service_name, c.name AS country_name
     FROM otp_activations a
     JOIN otp_services s ON s.id = a.service_id
     JOIN otp_countries c ON c.id = a.country_id
     WHERE a.user_id = ?
     ORDER BY a.id DESC LIMIT 30",
    [$userId]
);

ThemeManager::renderUserView('tickets', get_defined_vars());
