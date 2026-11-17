<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = Auth::requireUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = $_POST['action'] ?? '';
    $orderId = (int)($_POST['order_id'] ?? 0);

    $order = Database::fetch(
        "SELECT o.*, s.refill_support, s.cancel_support, p.api_url, p.api_key_enc
         FROM orders o
         JOIN services s ON s.id = o.service_id
         LEFT JOIN providers p ON p.id = o.provider_id
         WHERE o.id = ? AND o.user_id = ? LIMIT 1",
        [$orderId, $user['id']]
    );

    if (!$order) {
        Helpers::setFlash('error', 'Order not found.');
    } elseif ($action === 'refill' && $order['refill_support'] && $order['status'] === 'completed' && $order['refill_status'] === 'none') {
        if (!empty($order['provider_id']) && !empty($order['provider_order_id'])) {
            $res = ProviderAPI::requestRefill($order, (string)$order['provider_order_id']);
            if ($res['ok']) {
                Database::execute("UPDATE orders SET refill_status = 'requested' WHERE id = ?", [$orderId]);
                Database::insert("INSERT INTO order_logs (order_id, actor_type, actor_id, action, details) VALUES (?, 'user', ?, 'refill_requested', 'Refill sent to provider')", [$orderId, $user['id']]);
                Helpers::setFlash('success', 'Refill requested for order #' . $orderId . '.');
            } else {
                Helpers::setFlash('error', 'Provider refill failed: ' . ($res['error'] ?? 'Unknown error'));
            }
        } else {
            Database::execute("UPDATE orders SET refill_status = 'requested' WHERE id = ?", [$orderId]);
            Database::insert("INSERT INTO order_logs (order_id, actor_type, actor_id, action, details) VALUES (?, 'user', ?, 'refill_requested', 'Queued for manual admin refill')", [$orderId, $user['id']]);
            Helpers::setFlash('success', 'Refill request submitted for order #' . $orderId . '.');
        }
    } elseif ($action === 'cancel' && $order['cancel_support'] && in_array($order['status'], ['pending','in_progress','processing'], true)) {
        if (!empty($order['provider_id']) && !empty($order['provider_order_id'])) {
            $res = ProviderAPI::requestCancel($order, (string)$order['provider_order_id']);
            if ($res['ok']) {
                Database::execute("UPDATE orders SET cancel_status = 'requested' WHERE id = ?", [$orderId]);
                Database::insert("INSERT INTO order_logs (order_id, actor_type, actor_id, action, details) VALUES (?, 'user', ?, 'cancel_requested', 'Cancellation sent to provider')", [$orderId, $user['id']]);
                Helpers::setFlash('success', 'Cancellation requested for order #' . $orderId . '.');
            } else {
                Helpers::setFlash('error', 'Provider cancel failed: ' . ($res['error'] ?? 'Unknown error'));
            }
        } else {
            Database::execute("UPDATE orders SET cancel_status = 'requested' WHERE id = ?", [$orderId]);
            Helpers::setFlash('success', 'Cancellation request submitted for order #' . $orderId . '.');
        }
    }
    Helpers::redirect('/user/orders.php');
}

ThemeManager::renderUserView('orders', get_defined_vars());
