<?php
/**
 * Cron: Sync Active Order Statuses & Process Automatic Refunds
 * Run every 5 minutes on cPanel: php /path/to/cron/sync_orders.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once __DIR__ . '/../includes/bootstrap.php';

$orders = Database::fetchAll(
    "SELECT id FROM orders
     WHERE provider_order_id IS NOT NULL
       AND status IN ('pending','in_progress','processing')
     ORDER BY updated_at ASC LIMIT 50"
);

$synced = 0;
foreach ($orders as $o) {
    $res = OrderService::syncOrderStatus((int)$o['id']);
    if ($res['ok']) {
        $synced++;
    }
}
echo "Synced {$synced} of " . count($orders) . " active orders.\n";
