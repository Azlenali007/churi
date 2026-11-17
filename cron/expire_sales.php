<?php
/**
 * Cron: Expire Finished Flash Sales & Expired Child Panels
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once __DIR__ . '/../includes/bootstrap.php';

FlashSale::expireFinishedSales();
$cpExpired = Database::execute(
    "UPDATE child_panels SET status = 'expired' WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= NOW()"
);
echo "Flash sales checked. Child panels expired: {$cpExpired}\n";
