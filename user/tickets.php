<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = Auth::requireUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_ticket') {
        $dept = isset(TICKET_DEPARTMENTS[$_POST['department'] ?? '']) ? $_POST['department'] : 'general';
        $priority = in_array($_POST['priority'] ?? '', ['low','medium','high','urgent'], true) ? $_POST['priority'] : 'medium';
        $subject = Validator::str($_POST['subject'] ?? '', 200);
        $message = Validator::str($_POST['message'] ?? '', 5000);
        $orderId = !empty($_POST['order_id']) ? (int)$_POST['order_id'] : null;

        if ($subject === '' || $message === '') {
            Helpers::setFlash('error', 'Subject and message are required.');
            Helpers::redirect('/user/tickets.php');
        }

        $attachPath = null;
        if (!empty($_FILES['attachment']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $up = Uploader::uploadProofOrAttachment($_FILES['attachment'], 'tickets');
            if (!$up['ok']) {
                Helpers::setFlash('error', $up['error']);
                Helpers::redirect('/user/tickets.php');
            }
            $attachPath = $up['path'];
        }

        $ticketNum = 'TKT-' . strtoupper(bin2hex(random_bytes(3)));
        $tid = Database::insert(
            "INSERT INTO support_tickets (user_id, ticket_number, department, priority, subject, order_id, status, last_reply_at)
             VALUES (?, ?, ?, ?, ?, ?, 'open', NOW())",
            [$user['id'], $ticketNum, $dept, $priority, $subject, $orderId]
        );
        Database::insert(
            "INSERT INTO ticket_replies (ticket_id, sender_type, sender_id, message, attachment_path) VALUES (?, 'user', ?, ?, ?)",
            [$tid, $user['id'], $message, $attachPath]
        );
        Helpers::setFlash('success', 'Support ticket ' . $ticketNum . ' created.');
        Helpers::redirect('/user/tickets.php?id=' . $tid);
    } elseif ($action === 'reply_ticket') {
        $tid = (int)($_POST['ticket_id'] ?? 0);
        $ticket = Database::fetch("SELECT * FROM support_tickets WHERE id = ? AND user_id = ? LIMIT 1", [$tid, $user['id']]);
        $message = Validator::str($_POST['message'] ?? '', 5000);
        if ($ticket && $ticket['status'] !== 'closed' && $message !== '') {
            $attachPath = null;
            if (!empty($_FILES['attachment']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $up = Uploader::uploadProofOrAttachment($_FILES['attachment'], 'tickets');
                if ($up['ok']) {
                    $attachPath = $up['path'];
                }
            }
            Database::insert(
                "INSERT INTO ticket_replies (ticket_id, sender_type, sender_id, message, attachment_path) VALUES (?, 'user', ?, ?, ?)",
                [$tid, $user['id'], $message, $attachPath]
            );
            Database::execute("UPDATE support_tickets SET status = 'open', last_reply_at = NOW() WHERE id = ?", [$tid]);
            Helpers::setFlash('success', 'Reply sent.');
        }
        Helpers::redirect('/user/tickets.php?id=' . $tid);
    }
}

require APP_ROOT . '/themes/classic/tickets.php';
