<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = Auth::requireUser();

$massResults = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $raw = (string)($_POST['mass_orders'] ?? '');
    $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $raw))));
    $batchId = 'MASS_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));

    foreach ($lines as $idx => $line) {
        $lineNum = $idx + 1;
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) < 3) {
            $massResults[] = ['line' => $lineNum, 'ok' => false, 'message' => 'Invalid format. Expected: service_id | link | quantity'];
            continue;
        }
        $sid = (int)$parts[0];
        $link = $parts[1];
        $qty = (int)$parts[2];

        $res = OrderService::placeOrder((int)$user['id'], $sid, $link, $qty, [
            'is_mass_order'    => true,
            'mass_order_batch' => $batchId,
        ]);
        if ($res['ok']) {
            $massResults[] = ['line' => $lineNum, 'ok' => true, 'message' => 'Order #' . $res['order_id'] . ' placed (' . Currency::format($res['charge']) . ')'];
        } else {
            $massResults[] = ['line' => $lineNum, 'ok' => false, 'message' => $res['error'] ?? 'Failed'];
        }
    }
    // Refresh user balance after batch
    $user = Auth::requireUser();
}

require APP_ROOT . '/themes/classic/mass-order.php';
