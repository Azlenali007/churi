<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = Auth::requireUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manual_deposit') {
    CSRF::requireValid();
    $gatewayId = (int)($_POST['gateway_id'] ?? 0);
    $amount = (string)($_POST['amount'] ?? '0');
    $txRef = (string)($_POST['transaction_ref'] ?? '');
    $payerNote = Validator::str($_POST['payer_note'] ?? '', 250);

    $proofPath = null;
    if (!empty($_FILES['proof']) && ($_FILES['proof']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $up = Uploader::uploadProofOrAttachment($_FILES['proof'], 'proofs');
        if (!$up['ok']) {
            Helpers::setFlash('error', $up['error']);
            Helpers::redirect('/user/add-funds.php');
        }
        $proofPath = $up['path'];
    }

    $res = PaymentService::submitManualPayment((int)$user['id'], $gatewayId, $amount, $txRef, $payerNote, $proofPath);
    if ($res['ok']) {
        Helpers::setFlash('success', 'Manual payment request submitted! Funds will be credited after admin verification.');
    } else {
        Helpers::setFlash('error', $res['error'] ?? 'Could not submit payment.');
    }
    Helpers::redirect('/user/add-funds.php');
}

require APP_ROOT . '/themes/classic/add-funds.php';
