<?php
/**
 * Centralized Activity, API, Payment & Error Logger
 */

class Logger
{
    public static function activity(string $actorType, ?int $actorId, string $action, ?string $entityType = null, ?string $entityId = null, ?string $details = null): void
    {
        try {
            Database::insert(
                "INSERT INTO activity_logs (actor_type, actor_id, action, entity_type, entity_id, details, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $actorType,
                    $actorId,
                    substr($action, 0, 100),
                    $entityType ? substr($entityType, 0, 60) : null,
                    $entityId ? substr($entityId, 0, 60) : null,
                    $details,
                    Helpers::clientIp(),
                    substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                ]
            );
        } catch (Throwable $e) {
            self::errorFile('Activity log DB failure: ' . $e->getMessage());
        }
    }

    public static function api(string $logType, ?int $providerId, ?int $userId, string $endpoint, $request, $response, ?int $httpCode = null, string $status = 'ok'): void
    {
        try {
            $reqStr = is_string($request) ? $request : json_encode($request, JSON_UNESCAPED_SLASHES);
            $resStr = is_string($response) ? $response : json_encode($response, JSON_UNESCAPED_SLASHES);
            // Redact sensitive API keys or secrets before storing
            $reqStr = preg_replace('/("key"\s*:\s*)"[^"]+"/i', '$1"[REDACTED]"', (string)$reqStr);
            $reqStr = preg_replace('/(key=)[^&]+/i', '$1[REDACTED]', (string)$reqStr);

            Database::insert(
                "INSERT INTO api_logs (log_type, provider_id, user_id, endpoint, request_payload, response_payload, http_code, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $logType,
                    $providerId,
                    $userId,
                    substr($endpoint, 0, 255),
                    mb_substr((string)$reqStr, 0, 10000, 'UTF-8'),
                    mb_substr((string)$resStr, 0, 20000, 'UTF-8'),
                    $httpCode,
                    substr($status, 0, 40),
                ]
            );
        } catch (Throwable $e) {
            self::errorFile('API log DB failure: ' . $e->getMessage());
        }
    }

    public static function errorFile(string $message): void
    {
        if (!is_dir(LOGS_PATH)) {
            @mkdir(LOGS_PATH, 0755, true);
        }
        $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), Helpers::clientIp(), $message);
        @file_put_contents(LOGS_PATH . '/php_errors.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function workflowLog(string $channel, string $message, array $context = []): void
    {
        if (!is_dir(LOGS_PATH)) {
            @mkdir(LOGS_PATH, 0755, true);
        }
        $redacted = [];
        $blockedKeys = ['api_key', 'api_key_enc', 'key', 'password', 'password_hash', '_csrf_token', 'token', 'secret', 'session_id', 'razorpay_key_secret'];
        foreach ($context as $k => $v) {
            $lk = strtolower((string)$k);
            if (in_array($lk, $blockedKeys, true) || str_contains($lk, 'secret') || str_contains($lk, 'password') || str_contains($lk, 'token')) {
                $redacted[$k] = '[REDACTED]';
            } else {
                $redacted[$k] = $v;
            }
        }
        $ctxStr = !empty($redacted) ? ' | ' . json_encode($redacted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $line = sprintf("[%s] [%s] [%s] %s%s\n", date('Y-m-d H:i:s'), strtoupper($channel), Helpers::clientIp(), $message, $ctxStr);
        @file_put_contents(LOGS_PATH . '/workflow_debug.log', $line, FILE_APPEND | LOCK_EX);
    }
}
