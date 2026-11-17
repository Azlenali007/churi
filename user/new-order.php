<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = Auth::requireUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $serviceId = (int)($_POST['service_id'] ?? 0);
    $link = (string)($_POST['link'] ?? '');
    $quantity = (int)($_POST['quantity'] ?? 0);
    $comments = trim((string)($_POST['comments'] ?? ''));
    $dripFeed = !empty($_POST['drip_feed']);
    $runs = $dripFeed ? max(2, (int)($_POST['runs'] ?? 2)) : null;
    $interval = $dripFeed ? max(5, (int)($_POST['interval'] ?? 60)) : null;

    if ($comments !== '') {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $comments))));
        if (!empty($lines)) {
            $quantity = count($lines);
        }
    }

    $effectiveQty = ($dripFeed && $runs) ? $quantity * $runs : $quantity;
    $res = OrderService::placeOrder((int)$user['id'], $serviceId, $link, $effectiveQty, [
        'comments' => $comments !== '' ? $comments : null,
        'runs'     => $runs,
        'interval' => $interval,
    ]);

    if ($res['ok']) {
        Helpers::setFlash('success', 'Order #' . $res['order_id'] . ' placed! Charged ' . Currency::display($res['charge'], null, 2, true) . '.');
        Helpers::redirect('/user/orders.php');
    } else {
        Helpers::setFlash('error', $res['error'] ?? 'Could not place order.');
        Helpers::redirect('/user/new-order.php?service_id=' . $serviceId);
    }
}

require APP_ROOT . '/themes/classic/new-order.php';
