<?php
/**
 * OTP Provider Webhook Receiver
 * Supports HMAC-SHA256 signature verification or shared secret header verification per provider.
 * Endpoint URL format: /api/otp-webhook.php?provider_id=1  (or ?slug=5sim)
 */
require_once __DIR__ . '/../includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Helpers::jsonResponse(['ok' => false, 'error' => 'POST required.'], 405);
}

$providerId = (int)($_GET['provider_id'] ?? 0);
$slug = Validator::str($_GET['slug'] ?? '', 80);

$provider = null;
if ($providerId > 0) {
    $provider = Database::fetch("SELECT * FROM otp_providers WHERE id = ? LIMIT 1", [$providerId]);
} elseif ($slug !== '') {
    $provider = Database::fetch("SELECT * FROM otp_providers WHERE slug = ? LIMIT 1", [$slug]);
}

if (!$provider) {
    Helpers::jsonResponse(['ok' => false, 'error' => 'Provider not found.'], 404);
}

$secret = !empty($provider['webhook_secret_enc']) ? Helpers::decryptSecret((string)$provider['webhook_secret_enc']) : '';
if ($secret === '') {
    Helpers::jsonResponse(['ok' => false, 'error' => 'Webhook secret not configured for this provider.'], 403);
}

$rawBody = (string)file_get_contents('php://input');
if (strlen($rawBody) > 262144) {
    Helpers::jsonResponse(['ok' => false, 'error' => 'Payload too large.'], 413);
}

// Verify HMAC signature or secret header (timing-safe)
$sigHeader = (string)($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? ($_SERVER['HTTP_X_SIGNATURE'] ?? ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '')));
$secHeader = (string)($_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '');
$authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$bearer = stripos($authHeader, 'Bearer ') === 0 ? trim(substr($authHeader, 7)) : '';

$verified = false;
if ($sigHeader !== '') {
    $cleanSig = preg_replace('/^sha256=/i', '', trim($sigHeader));
    $expectedHmac = hash_hmac('sha256', $rawBody, $secret);
    $verified = hash_equals($expectedHmac, (string)$cleanSig);
} elseif ($secHeader !== '') {
    $verified = hash_equals($secret, trim($secHeader));
} elseif ($bearer !== '') {
    $verified = hash_equals($secret, $bearer);
}

if (!$verified) {
    Database::insert(
        "INSERT INTO otp_provider_logs (provider_id, action, endpoint, http_code, request_payload, response_payload, status, error_message)
         VALUES (?, 'webhook', '/api/otp-webhook.php', 401, ?, '', 'error', 'Invalid webhook signature')",
        [(int)$provider['id'], substr($rawBody, 0, 2000)]
    );
    Helpers::jsonResponse(['ok' => false, 'error' => 'Invalid webhook signature.'], 401);
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = $_POST;
}
if (!is_array($payload) || empty($payload)) {
    Helpers::jsonResponse(['ok' => false, 'error' => 'Invalid JSON payload.'], 400);
}

// Extract fields using provider's response_map_json with sensible fallbacks
$respMap = json_decode((string)($provider['response_map_json'] ?? '{}'), true);
if (!is_array($respMap)) {
    $respMap = [];
}

$dotGet = static function (array $arr, string $path) {
    if ($path === '') {
        return null;
    }
    $cur = $arr;
    foreach (explode('.', $path) as $seg) {
        if (is_array($cur) && array_key_exists($seg, $cur)) {
            $cur = $cur[$seg];
        } else {
            return null;
        }
    }
    return $cur;
};

$provOrderId = (string)($dotGet($payload, (string)($respMap['order_id'] ?? ''))
    ?? $payload['order_id'] ?? $payload['id'] ?? $payload['activation_id'] ?? '');
$rawStatus = strtoupper(trim((string)($dotGet($payload, (string)($respMap['status'] ?? ''))
    ?? $payload['status'] ?? '')));
$otpCode = (string)($dotGet($payload, (string)($respMap['otp_code'] ?? ''))
    ?? $payload['otp_code'] ?? $payload['code'] ?? $payload['otp'] ?? '');
$smsText = (string)($dotGet($payload, (string)($respMap['sms_text'] ?? ''))
    ?? $payload['sms_text'] ?? $payload['text'] ?? $payload['message'] ?? '');

// Support 5SIM sms array format
if ($otpCode === '' && !empty($payload['sms']) && is_array($payload['sms'])) {
    $lastSms = end($payload['sms']);
    if (is_array($lastSms)) {
        $otpCode = (string)($lastSms['code'] ?? '');
        $smsText = (string)($lastSms['text'] ?? $smsText);
    }
}

if ($provOrderId === '') {
    Helpers::jsonResponse(['ok' => false, 'error' => 'Missing provider order identifier.'], 400);
}

$act = Database::fetch(
    "SELECT a.*, s.name AS service_name FROM otp_activations a
     JOIN otp_services s ON s.id = a.service_id
     WHERE a.provider_id = ? AND a.provider_order_id = ? LIMIT 1",
    [(int)$provider['id'], $provOrderId]
);

if (!$act) {
    Database::insert(
        "INSERT INTO otp_provider_logs (provider_id, action, endpoint, http_code, request_payload, response_payload, status, error_message)
         VALUES (?, 'webhook', '/api/otp-webhook.php', 404, ?, '', 'error', 'Activation not found for provider order ID')",
        [(int)$provider['id'], substr($rawBody, 0, 4000)]
    );
    Helpers::jsonResponse(['ok' => false, 'error' => 'Activation not found.'], 404);
}

$activationId = (int)$act['id'];
$terminal = ['completed', 'expired', 'canceled', 'refunded', 'failed'];
if (in_array($act['status'], $terminal, true)) {
    Helpers::jsonResponse(['ok' => true, 'ignored' => true, 'status' => $act['status']]);
}

// Map provider status to platform status
$statusMap = json_decode((string)($provider['status_map_json'] ?? '{}'), true);
$mappedStatus = (string)$act['status'];
if (is_array($statusMap) && $rawStatus !== '') {
    foreach ($statusMap as $k => $v) {
        if (strtoupper((string)$k) === $rawStatus) {
            $mappedStatus = (string)$v;
            break;
        }
    }
}
if ($mappedStatus === $act['status'] && $rawStatus !== '') {
    if (in_array($rawStatus, ['RECEIVED', 'SMS_RECEIVED', 'OTP_RECEIVED', 'OK'], true)) {
        $mappedStatus = 'otp_received';
    } elseif (in_array($rawStatus, ['FINISHED', 'COMPLETED', 'DONE'], true)) {
        $mappedStatus = 'completed';
    } elseif (in_array($rawStatus, ['CANCELED', 'CANCELLED', 'BANNED'], true)) {
        $mappedStatus = 'canceled';
    } elseif (in_array($rawStatus, ['TIMEOUT', 'EXPIRED'], true)) {
        $mappedStatus = 'expired';
    } elseif (in_array($rawStatus, ['PENDING', 'WAITING', 'ACTIVE'], true)) {
        $mappedStatus = 'waiting_otp';
    }
}
if ($otpCode !== '' && in_array($mappedStatus, ['processing', 'active', 'waiting_otp'], true)) {
    $mappedStatus = 'otp_received';
}

$allowedStatuses = ['processing', 'active', 'waiting_otp', 'otp_received', 'completed', 'expired', 'canceled', 'failed'];
if (!in_array($mappedStatus, $allowedStatuses, true)) {
    $mappedStatus = (string)$act['status'];
}

$wasOtp = !empty($act['otp_code']);
$finalOtp = $otpCode !== '' ? $otpCode : ($act['otp_code'] ?? null);
$finalSms = $smsText !== '' ? $smsText : ($act['sms_text'] ?? null);
$smsAt = (!$wasOtp && $finalOtp) ? date('Y-m-d H:i:s') : $act['sms_received_at'];
$completedAt = ($mappedStatus === 'completed' && empty($act['completed_at'])) ? date('Y-m-d H:i:s') : $act['completed_at'];

Database::execute(
    "UPDATE otp_activations
     SET status = ?, provider_status = ?, otp_code = ?, sms_text = ?, sms_received_at = ?,
         completed_at = ?, last_checked_at = NOW(), provider_response_json = ?
     WHERE id = ?",
    [
        $mappedStatus,
        $rawStatus ?: (string)$act['provider_status'],
        $finalOtp,
        $finalSms,
        $smsAt,
        $completedAt,
        substr($rawBody, 0, 16000),
        $activationId,
    ]
);

Database::insert(
    "INSERT INTO otp_provider_logs (provider_id, activation_id, action, endpoint, http_code, request_payload, response_payload, status)
     VALUES (?, ?, 'webhook', '/api/otp-webhook.php', 200, ?, '{\"ok\":true}', 'ok')",
    [(int)$provider['id'], $activationId, substr($rawBody, 0, 4000)]
);

if (!$wasOtp && !empty($finalOtp)) {
    OtpService::notifyUser(
        (int)$act['user_id'],
        'otp',
        'OTP Received for ' . $act['service_name'],
        'Verification code ' . $finalOtp . ' received for activation #' . $act['activation_code'] . '.',
        '/otp/activations.php?id=' . $activationId
    );
} elseif ($mappedStatus === 'expired' && empty($finalOtp) && Helpers::setting('otp_auto_refund_expired', '1') === '1') {
    OtpService::issueRefund($activationId, 'system', null, 'Automatic refund via provider webhook: activation expired without OTP.');
} elseif ($mappedStatus === 'canceled' && empty($finalOtp) && Helpers::setting('otp_auto_refund_canceled', '1') === '1') {
    OtpService::issueRefund($activationId, 'system', null, 'Automatic refund via provider webhook: activation cancelled before OTP delivery.');
}

Helpers::jsonResponse(['ok' => true, 'activation_id' => $activationId, 'status' => $mappedStatus]);
