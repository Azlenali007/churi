<?php
/**
 * Upstream SMM Provider API v2 cURL Client
 * - Server-side SSRF URL validation
 * - Centralized error classification & secret redaction via ProviderErrorHandler
 * - Never returns fake responses; logs sanitized errors and updates provider status cleanly
 */

class ProviderAPI
{
    public static function call(array $provider, string $action, array $params = []): array
    {
        $providerId = (int)($provider['id'] ?? 0);
        if (!function_exists('curl_init')) {
            Logger::workflowLog('provider_api', 'PHP cURL extension missing', ['provider_id' => $providerId, 'action' => $action]);
            return [
                'ok'             => false,
                'http_code'      => 0,
                'error_category' => 'config',
                'error_code'     => 'curl_missing',
                'error'          => 'PHP cURL extension is not enabled on this server.',
            ];
        }

        $apiUrl = trim((string)($provider['api_url'] ?? ''));
        $apiKey = Helpers::decryptSecret((string)($provider['api_key_enc'] ?? ''));
        if ($apiUrl === '' || $apiKey === '') {
            $errMsg = $apiUrl === ''
                ? 'Provider API URL is not configured.'
                : 'Provider API key is not configured or could not be decrypted.';
            Logger::workflowLog('provider_api', 'Missing provider URL or API key', ['provider_id' => $providerId, 'action' => $action]);
            if ($providerId > 0) {
                ProviderErrorHandler::recordProviderError('smm', $providerId, $errMsg, false, []);
            }
            return [
                'ok'             => false,
                'http_code'      => 0,
                'error_category' => 'config',
                'error_code'     => 'missing_credentials',
                'error'          => $errMsg,
            ];
        }

        $urlErr = OtpHttpClient::validateUrl($apiUrl);
        if ($urlErr !== null) {
            Logger::workflowLog('provider_api', 'Blocked invalid provider URL', ['provider_id' => $providerId, 'action' => $action, 'reason' => $urlErr]);
            if ($providerId > 0) {
                ProviderErrorHandler::recordProviderError('smm', $providerId, $urlErr, false, [$apiKey]);
            }
            return [
                'ok'             => false,
                'http_code'      => 0,
                'error_category' => 'config',
                'error_code'     => 'invalid_url',
                'error'          => $urlErr,
            ];
        }

        $secrets = [$apiKey];
        $postData = array_merge(['key' => $apiKey, 'action' => $action], $params);
        $safePostData = $postData;
        $safePostData['key'] = '[REDACTED]';

        $startMs = (int)round(microtime(true) * 1000);
        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $apiUrl,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 35,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'SMMPRO-Client/1.0',
        ];
        if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        $elapsedMs = max(0, (int)round(microtime(true) * 1000) - $startMs);

        if ($raw === false || $curlErrno !== 0 || $curlErr !== '') {
            $classified = ProviderErrorHandler::classifyHttpFailure($httpCode, $curlErrno, $curlErr, '', $secrets);
            $errMsg = $classified['message'];
            Logger::api('provider_out', $providerId, null, $apiUrl . '?action=' . $action, $safePostData, $errMsg, $httpCode, 'error');
            Logger::workflowLog('provider_api', 'cURL request failed', [
                'provider_id' => $providerId,
                'action'      => $action,
                'http_code'   => $httpCode,
                'curl_errno'  => $curlErrno,
                'category'    => $classified['category'],
                'error'       => $errMsg,
            ]);
            if ($providerId > 0) {
                ProviderErrorHandler::recordProviderError('smm', $providerId, $errMsg, false, $secrets);
            }
            return [
                'ok'               => false,
                'http_code'        => $httpCode,
                'response_time_ms' => $elapsedMs,
                'error_category'   => $classified['category'],
                'error_code'       => $classified['code'],
                'error'            => $errMsg,
            ];
        }

        $rawStr = (string)$raw;
        $cleanRaw = (string)preg_replace('/^\xEF\xBB\xBF/', '', $rawStr);
        $decoded = json_decode($cleanRaw, true);

        if (!is_array($decoded)) {
            $classified = ProviderErrorHandler::classifyHttpFailure($httpCode, 0, '', $cleanRaw, $secrets);
            $errMsg = $classified['message'];
            if (trim($cleanRaw) !== '' && !preg_match('/<!doctype|<html/i', $cleanRaw) && $httpCode >= 200 && $httpCode < 300) {
                $errMsg = 'Invalid JSON returned by provider (HTTP ' . $httpCode . ': ' . json_last_error_msg() . ').';
            }
            $safeSnippet = ProviderErrorHandler::redact(substr($rawStr, 0, 1500), $secrets);
            Logger::api('provider_out', $providerId, null, $apiUrl . '?action=' . $action, $safePostData, $safeSnippet, $httpCode, 'error');
            Logger::workflowLog('provider_api', 'JSON decode failed', [
                'provider_id' => $providerId,
                'action'      => $action,
                'http_code'   => $httpCode,
                'category'    => $classified['category'],
                'json_error'  => json_last_error_msg(),
            ]);
            if ($providerId > 0) {
                ProviderErrorHandler::recordProviderError('smm', $providerId, $errMsg, false, $secrets);
            }
            return [
                'ok'               => false,
                'http_code'        => $httpCode,
                'response_time_ms' => $elapsedMs,
                'error_category'   => $classified['category'],
                'error_code'       => $classified['code'],
                'error'            => $errMsg,
            ];
        }

        if (isset($decoded['error'])) {
            $rawErr = is_string($decoded['error']) ? trim($decoded['error']) : json_encode($decoded['error']);
            $errMsg = ProviderErrorHandler::redact((string)$rawErr, $secrets);
            $category = preg_match('/key|auth|unauthori|forbidden|token|invalid.*api/i', $errMsg) ? 'auth' : 'provider';
            Logger::api('provider_out', $providerId, null, $apiUrl . '?action=' . $action, $safePostData, $decoded, $httpCode, 'error');
            Logger::workflowLog('provider_api', 'Provider returned API error', [
                'provider_id' => $providerId,
                'action'      => $action,
                'http_code'   => $httpCode,
                'category'    => $category,
                'error'       => $errMsg,
            ]);
            if ($providerId > 0) {
                ProviderErrorHandler::recordProviderError('smm', $providerId, $errMsg, false, $secrets);
            }
            return [
                'ok'               => false,
                'http_code'        => $httpCode,
                'response_time_ms' => $elapsedMs,
                'error_category'   => $category,
                'error_code'       => $category === 'auth' ? 'invalid_api_key' : 'provider_error',
                'error'            => $errMsg,
                'data'             => $decoded,
            ];
        }

        if ($httpCode >= 400) {
            $classified = ProviderErrorHandler::classifyHttpFailure($httpCode, 0, '', $cleanRaw, $secrets);
            $errMsg = $classified['message'];
            Logger::api('provider_out', $providerId, null, $apiUrl . '?action=' . $action, $safePostData, $decoded, $httpCode, 'error');
            Logger::workflowLog('provider_api', 'HTTP error response', [
                'provider_id' => $providerId,
                'action'      => $action,
                'http_code'   => $httpCode,
                'category'    => $classified['category'],
            ]);
            if ($providerId > 0) {
                ProviderErrorHandler::recordProviderError('smm', $providerId, $errMsg, false, $secrets);
            }
            return [
                'ok'               => false,
                'http_code'        => $httpCode,
                'response_time_ms' => $elapsedMs,
                'error_category'   => $classified['category'],
                'error_code'       => $classified['code'],
                'error'            => $errMsg,
                'data'             => $decoded,
            ];
        }

        Logger::api(
            'provider_out',
            $providerId,
            null,
            $apiUrl . '?action=' . $action,
            $safePostData,
            is_array($decoded) && count($decoded) > 20 ? ('[' . count($decoded) . ' items]') : $decoded,
            $httpCode,
            'ok'
        );
        Logger::workflowLog('provider_api', 'Provider API request succeeded', [
            'provider_id'      => $providerId,
            'action'           => $action,
            'http_code'        => $httpCode,
            'response_time_ms' => $elapsedMs,
            'top_keys'         => is_array($decoded) ? count($decoded) : 0,
        ]);
        if ($providerId > 0) {
            try {
                Database::execute("UPDATE providers SET last_error = NULL WHERE id = ?", [$providerId]);
            } catch (Throwable $e) {
                // Non-fatal
            }
        }

        return [
            'ok'               => true,
            'http_code'        => $httpCode,
            'response_time_ms' => $elapsedMs,
            'data'             => $decoded,
        ];
    }

    public static function getBalance(array $provider): array
    {
        $res = self::call($provider, 'balance');
        if (empty($res['ok'])) {
            return $res;
        }

        $rawBal = $res['data']['balance'] ?? null;
        if ($rawBal === null || !is_numeric((string)$rawBal)) {
            $errMsg = 'Provider response did not include a valid numeric balance field.';
            $providerId = (int)($provider['id'] ?? 0);
            if ($providerId > 0) {
                ProviderErrorHandler::recordProviderError('smm', $providerId, $errMsg, false, []);
            }
            return [
                'ok'               => false,
                'http_code'        => (int)($res['http_code'] ?? 0),
                'response_time_ms' => (int)($res['response_time_ms'] ?? 0),
                'error_category'   => 'parse',
                'error_code'       => 'missing_balance',
                'error'            => $errMsg,
                'data'             => $res['data'] ?? null,
            ];
        }

        $bal = Currency::normalize((string)$rawBal, 4);
        $providerId = (int)($provider['id'] ?? 0);
        if ($providerId > 0) {
            Database::execute(
                "UPDATE providers SET balance = ?, last_balance_check = NOW(), last_error = NULL WHERE id = ?",
                [$bal, $providerId]
            );
        }
        $res['balance'] = $bal;
        $res['currency'] = strtoupper(trim((string)($res['data']['currency'] ?? ($provider['currency_code'] ?? Currency::getBaseCode()))));
        return $res;
    }

    public static function testConnection(array $provider): array
    {
        return self::getBalance($provider);
    }

    public static function getServices(array $provider): array
    {
        return self::call($provider, 'services');
    }

    public static function addOrder(array $provider, array $payload): array
    {
        return self::call($provider, 'add', $payload);
    }

    public static function getOrderStatus(array $provider, string $externalOrderId): array
    {
        return self::call($provider, 'status', ['order' => $externalOrderId]);
    }

    public static function requestRefill(array $provider, string $externalOrderId): array
    {
        return self::call($provider, 'refill', ['order' => $externalOrderId]);
    }

    public static function requestCancel(array $provider, string $externalOrderId): array
    {
        return self::call($provider, 'cancel', ['orders' => $externalOrderId]);
    }
}
