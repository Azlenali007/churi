<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = Auth::requireUser();
$storeContext = StoreManager::resolveStoreContext('smm');
$selfUrl = '/user/add-funds.php' . ($storeContext === 'otp' ? '?store=otp' : '');

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
            Helpers::redirect($selfUrl);
        }
        $proofPath = $up['path'];
    }

    $displayCode = strtoupper(trim((string)($_POST['display_currency'] ?? Currency::getActiveDisplayCode($user))));
    $res = PaymentService::submitManualPayment((int)$user['id'], $gatewayId, $amount, $txRef, $payerNote, $proofPath, $displayCode);
    if ($res['ok']) {
        Helpers::setFlash('success', 'Manual payment request submitted! Funds will be credited after verification.');
    } else {
        Helpers::setFlash('error', $res['error'] ?? 'Could not submit payment.');
    }
    Helpers::redirect($selfUrl);
}

ThemeManager::renderUserView('add-funds', get_defined_vars());
