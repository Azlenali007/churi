<?php
/**
 * Cron: Sync Active OTP Activations, Detect Expiry & Process Automatic Refunds
 * Run every 1 minute on cPanel:
 *   * * * * * php /home/username/public_html/cron/sync_otp_activations.php
 *
 * Works even when OTP Store is turned OFF so active activations always finish or refund safely.
 */
$isCli = (php_sapi_name() === 'cli');
require_once __DIR__ . '/../includes/bootstrap.php';

if (!$isCli) {
    $cronKey = (string)Helpers::setting('cron_secret_key', '');
    $supplied = (string)($_GET['key'] ?? '');
    if ($cronKey === '' || $supplied === '' || !hash_equals($cronKey, $supplied)) {
        http_response_code(403);
        exit('CLI or valid cron key required.');
    }
}

$active = Database::fetchAll(
    "SELECT id FROM otp_activations
     WHERE status IN ('processing','active','waiting_otp','otp_received')
     ORDER BY COALESCE(last_checked_at, '1970-01-01 00:00:00') ASC
     LIMIT 60"
);

$synced = 0;
$errors = 0;

foreach ($active as $row) {
    try {
        $res = OtpService::syncActivationStatus((int)$row['id'], true);
        if (!empty($res['ok'])) {
            $synced++;
        } else {
            $errors++;
        }
    } catch (Throwable $e) {
        $errors++;
        Logger::errorFile('cron/sync_otp_activations error on #' . (int)$row['id'] . ': ' . $e->getMessage());
    }
}

echo "OTP Sync Complete: checked " . count($active) . " activations ({$synced} ok, {$errors} errors).\n";
