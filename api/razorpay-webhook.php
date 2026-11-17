<?php
/**
 * Razorpay Server-Side Webhook Listener
 * Strictly verifies X-Razorpay-Signature before crediting wallet.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$rawBody = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

$rzp = PaymentService::getRazorpayConfig();
if (!$rzp || empty($rzp['webhook_secret'])) {
    Logger::api('payment_webhook', null, null, '/api/razorpay-webhook.php', $rawBody, 'Webhook secret not configured', 503, 'error');
    Helpers::jsonResponse(['ok' => false, 'error' => 'Razorpay webhook is not configured.'], 503);
}

$expected = hash_hmac('sha256', (string)$rawBody, $rzp['webhook_secret']);
if (!hash_equals($expected, (string)$sigHeader)) {
    Logger::api('payment_webhook', null, null, '/api/razorpay-webhook.php', $rawBody, 'Invalid webhook signature', 400, 'error');
    Helpers::jsonResponse(['ok' => false, 'error' => 'Invalid signature'], 400);
}

$event = json_decode((string)$rawBody, true);
$eventType = $event['event'] ?? '';

if (in_array($eventType, ['payment.captured', 'order.paid'], true)) {
    $payEntity = $event['payload']['payment']['entity'] ?? [];
    $orderId = (string)($payEntity['order_id'] ?? '');
    $paymentId = (string)($payEntity['id'] ?? '');
    if ($orderId !== '' && $paymentId !== '') {
        $res = PaymentService::finalizeGatewayCredit($orderId, $paymentId, (string)$rawBody);
        Logger::api('payment_webhook', null, null, '/api/razorpay-webhook.php', $eventType, $res, 200, $res['ok'] ? 'ok' : 'error');
        Helpers::jsonResponse($res, $res['ok'] ? 200 : 400);
    }
}

Helpers::jsonResponse(['ok' => true, 'ignored' => $eventType]);
