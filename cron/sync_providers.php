<?php
/**
 * Cron: Sync Provider Balances & Service Prices
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once __DIR__ . '/../includes/bootstrap.php';

$providers = Database::fetchAll("SELECT * FROM providers WHERE status = 'active'");
foreach ($providers as $p) {
    ProviderAPI::getBalance($p);
    $res = CategoryMapper::importFromProvider($p, 'update');
    echo "Provider {$p['name']}: " . ($res['ok'] ? 'Synced' : ($res['error'] ?? 'Error')) . "\n";
}
