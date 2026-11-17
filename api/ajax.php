<?php
/**
 * Authenticated & Currency AJAX Endpoints (Currency Switcher, Razorpay Checkout, Price Quote)
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$action = (string)($_POST['action'] ?? ($_GET['action'] ?? ''));
$user = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
}

// Allow currency preference change for authenticated users (and session guests on public storefront)
if ($action === 'set_currency') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Helpers::jsonResponse(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }
    $code = strtoupper(trim((string)($_POST['currency'] ?? '')));
    $ok = Currency::setUserDisplayCurrency($code, $user ? (int)$user['id'] : null);
    if (!$ok) {
        Helpers::jsonResponse(['ok' => false, 'error' => 'Selected currency is not supported or currently disabled.'], 400);
    }
    $curr = Currency::getCurrency($code);
    Helpers::jsonResponse([
        'ok'       => true,
        'currency' => [
            'code'          => $curr['code'],
            'name'          => $curr['name'],
            'symbol'        => $curr['symbol'],
            'exchange_rate' => $curr['exchange_rate'],
        ],
    ]);
}

if (!$user) {
    Helpers::jsonResponse(['ok' => false, 'error' => 'Authentication required.'], 401);
}

if ($action === 'rzp_create') {
    $amount = (string)($_POST['amount'] ?? '0');
    $dispCode = strtoupper(trim((string)($_POST['display_currency'] ?? Currency::getActiveDisplayCode($user))));
    $res = PaymentService::createRazorpayOrder((int)$user['id'], $amount, $dispCode);
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
    $totalBase = Currency::calculateOrderCharge($pricing['effective_rate'], $qty);
    $disp = Currency::getActiveDisplayCurrency($user);
    Helpers::jsonResponse([
        'ok'             => true,
        'pricing'        => $pricing,
        'total'          => $totalBase,
        'display_code'   => $disp['code'],
        'display_symbol' => $disp['symbol'],
        'display_total'  => Currency::display($totalBase, $disp['code'], 2, true),
        'display_rate'   => Currency::displayRate($pricing['effective_rate'], $disp['code'], true),
    ]);
}

Helpers::jsonResponse(['ok' => false, 'error' => 'Unknown action.'], 400);
