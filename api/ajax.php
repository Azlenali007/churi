<?php
/**
 * Authenticated Frontend AJAX Endpoints (Razorpay Checkout, Price Quote)
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = Auth::user();
if (!$user) {
    Helpers::jsonResponse(['ok' => false, 'error' => 'Authentication required.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
}

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

if ($action === 'rzp_create') {
    $amount = (string)($_POST['amount'] ?? '0');
    $res = PaymentService::createRazorpayOrder((int)$user['id'], $amount);
    Helpers::jsonResponse($res, $res['ok'] ? 200 : 400);
}

if ($action === 'rzp_verify') {
    $rzpOrderId = Validator::str($_POST['razorpay_order_id'] ?? '', 120);
    $rzpPaymentId = Validator::str($_POST['razorpay_payment_id'] ?? '', 120);
    $rzpSignature = Validator::str($_POST['razorpay_signature'] ?? '', 255);
    $res = PaymentService::verifyRazorpayPayment((int)$user['id'], $rzpOrderId, $rzpPaymentId, $rzpSignature);
    Helpers::jsonResponse($res, $res['ok'] ? 200 : 400);
}

if ($action === 'service_price') {
    $sid = (int)($_GET['service_id'] ?? 0);
    $qty = max(1, (int)($_GET['quantity'] ?? 100));
    $svc = Database::fetch("SELECT * FROM services WHERE id = ? AND status = 'active' LIMIT 1", [$sid]);
    if (!$svc) {
        Helpers::jsonResponse(['ok' => false, 'error' => 'Service not found.'], 404);
    }
    $pricing = FlashSale::getServicePricing($svc);
    $total = Currency::calculateOrderCharge($pricing['effective_rate'], $qty);
    Helpers::jsonResponse(['ok' => true, 'pricing' => $pricing, 'total' => $total]);
}

Helpers::jsonResponse(['ok' => false, 'error' => 'Unknown action.'], 400);
