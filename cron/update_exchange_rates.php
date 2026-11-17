<?php
// Cron: Automatic Exchange Rate Update
// Usage in cPanel Cron Jobs (recommended: twice daily):
//   0 */12 * * * /usr/local/bin/php /home/USER/public_html/cron/update_exchange_rates.php >/dev/null 2>&1
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once __DIR__ . '/../includes/bootstrap.php';

if (Helpers::setting('exchange_rate_auto_update', '1') !== '1') {
    echo "Automatic exchange rate updates are disabled in Admin settings.\n";
    exit(0);
}

$res = ExchangeRateService::syncRates('cron', null, true);
if ($res['ok']) {
    echo "Exchange rates synced via {$res['source']} at {$res['timestamp']}.\n";
    foreach ($res['updated'] as $code => $rate) {
        echo "  {$code}: {$rate}\n";
    }
    if (!empty($res['skipped_manual'])) {
        echo "  Skipped (manual override): " . implode(', ', $res['skipped_manual']) . "\n";
    }
} else {
    echo "Exchange rate sync failed: " . ($res['error'] ?? 'Unknown error') . " (last cached rates preserved)\n";
    exit(1);
}
