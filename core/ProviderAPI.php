<?php
/**
 * Upstream SMM Provider API v2 cURL Client
 * Never returns fake responses; logs errors and updates provider status cleanly.
 */

class ProviderAPI
{
    public static function call(array $provider, string $action, array $params = []): array
    {
        $providerId = (int)($provider['id'] ?? 0);
        if (!function_exists('curl_init')) {
            Logger::workflowLog('provider_api', 'PHP cURL extension missing', ['provider_id' => $providerId, 'action' => $action]);
            return ['ok' => false, 'http_code' => 0, 'error' => 'PHP cURL extension is not enabled on this server.'];
        }

        $apiUrl = trim((string)($provider['api_url'] ?? ''));
        $apiKey = Helpers::decryptSecret((string)($provider['api_key_enc'] ?? ''));
        if ($apiUrl === '' || $apiKey === '') {
            Logger::workflowLog('provider_api', 'Missing provider URL or API key', ['provider_id' => $providerId, 'action' => $action]);
            return ['ok' => false, 'http_code' => 0, 'error' => 'Provider API URL or API key is not configured or could not be decrypted.'];
        }

        $postData = array_merge(['key' => $apiKey, 'action' => $action], $params);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $apiUrl,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 35,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'SMMPRO-Client/1.0',
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $curlErr !== '') {
            $errMsg = 'cURL error: ' . ($curlErr ?: 'Empty response');
            Logger::api('provider_out', $providerId, null, $apiUrl . '?action=' . $action, $postData, $errMsg, $httpCode, 'error');
            Logger::workflowLog('provider_api', 'cURL request failed', ['provider_id' => $providerId, 'action' => $action, 'http_code' => $httpCode, 'error' => $errMsg]);
            if ($providerId > 0) {
                Database::execute("UPDATE providers SET last_error = ? WHERE id = ?", [$errMsg, $providerId]);
            }
            return ['ok' => false, 'http_code' => $httpCode, 'error' => $errMsg];
        }

        // Strip UTF-8 BOM if present before decoding
        $cleanRaw = preg_replace('/^\xEF\xBB\xBF/', '', (string)$raw);
        $decoded = json_decode((string)$cleanRaw, true);
        if (!is_array($decoded)) {
            $jsonErr = json_last_error_msg();
            $errMsg = 'Invalid JSON returned by provider (HTTP ' . $httpCode . ': ' . $jsonErr . ').';
            Logger::api('provider_out', $providerId, null, $apiUrl . '?action=' . $action, $postData, substr((string)$raw, 0, 2000), $httpCode, 'error');
            Logger::workflowLog('provider_api', 'JSON decode failed', ['provider_id' => $providerId, 'action' => $action, 'http_code' => $httpCode, 'json_error' => $jsonErr]);
            if ($providerId > 0) {
                Database::execute("UPDATE providers SET last_error = ? WHERE id = ?", [$errMsg, $providerId]);
            }
            return ['ok' => false, 'http_code' => $httpCode, 'error' => $errMsg];
        }

        if ($httpCode >= 400 && !isset($decoded['error'])) {
            $errMsg = 'Provider HTTP error ' . $httpCode . '.';
            Logger::api('provider_out', $providerId, null, $apiUrl . '?action=' . $action, $postData, $decoded, $httpCode, 'error');
            Logger::workflowLog('provider_api', 'HTTP error response', ['provider_id' => $providerId, 'action' => $action, 'http_code' => $httpCode]);
            if ($providerId > 0) {
                Database::execute("UPDATE providers SET last_error = ? WHERE id = ?", [$errMsg, $providerId]);
            }
            return ['ok' => false, 'http_code' => $httpCode, 'error' => $errMsg, 'data' => $decoded];
        }

        if (isset($decoded['error'])) {
            $errMsg = is_string($decoded['error']) ? $decoded['error'] : json_encode($decoded['error']);
            Logger::api('provider_out', $providerId, null, $apiUrl . '?action=' . $action, $postData, $decoded, $httpCode, 'error');
            Logger::workflowLog('provider_api', 'Provider returned API error', ['provider_id' => $providerId, 'action' => $action, 'http_code' => $httpCode, 'error' => $errMsg]);
            if ($providerId > 0) {
                Database::execute("UPDATE providers SET last_error = ? WHERE id = ?", [$errMsg, $providerId]);
            }
            return ['ok' => false, 'http_code' => $httpCode, 'error' => $errMsg, 'data' => $decoded];
        }

        Logger::api('provider_out', $providerId, null, $apiUrl . '?action=' . $action, $postData, is_array($decoded) && count($decoded) > 20 ? ('[' . count($decoded) . ' items]') : $decoded, $httpCode, 'ok');
        Logger::workflowLog('provider_api', 'Provider API request succeeded', ['provider_id' => $providerId, 'action' => $action, 'http_code' => $httpCode, 'top_keys' => is_array($decoded) ? count($decoded) : 0]);
        if ($providerId > 0) {
            Database::execute("UPDATE providers SET last_error = NULL WHERE id = ?", [$providerId]);
        }

        return ['ok' => true, 'http_code' => $httpCode, 'data' => $decoded];
    }

    public static function getBalance(array $provider): array
    {
        $res = self::call($provider, 'balance');
        if ($res['ok'] && isset($res['data']['balance'])) {
            $bal = Currency::normalize((string)$res['data']['balance'], 4);
            Database::execute(
                "UPDATE providers SET balance = ?, last_balance_check = NOW() WHERE id = ?",
                [$bal, $provider['id']]
            );
            $res['balance'] = $bal;
            $res['currency'] = $res['data']['currency'] ?? ($provider['currency_code'] ?? 'USD');
        }
        return $res;
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
