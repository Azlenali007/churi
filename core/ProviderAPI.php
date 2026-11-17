<?php
/**
 * Upstream SMM Provider API v2 cURL Client
 * Never returns fake responses; logs errors and updates provider status cleanly.
 */

class ProviderAPI
{
    public static function call(array $provider, string $action, array $params = []): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'PHP cURL extension is not enabled on this server.'];
        }

        $apiUrl = trim((string)($provider['api_url'] ?? ''));
        $apiKey = Helpers::decryptSecret((string)($provider['api_key_enc'] ?? ''));
        if ($apiUrl === '' || $apiKey === '') {
            return ['ok' => false, 'error' => 'Provider API URL or API key is not configured.'];
        }

        $postData = array_merge(['key' => $apiKey, 'action' => $action], $params);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $apiUrl,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'SMMPRO-Client/1.0',
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $curlErr !== '') {
            $errMsg = 'cURL error: ' . ($curlErr ?: 'Empty response');
            Logger::api('provider_out', (int)($provider['id'] ?? 0), null, $apiUrl . '?action=' . $action, $postData, $errMsg, $httpCode, 'error');
            if (!empty($provider['id'])) {
                Database::execute("UPDATE providers SET last_error = ? WHERE id = ?", [$errMsg, $provider['id']]);
            }
            return ['ok' => false, 'error' => $errMsg];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $errMsg = 'Invalid JSON returned by provider (HTTP ' . $httpCode . ').';
            Logger::api('provider_out', (int)($provider['id'] ?? 0), null, $apiUrl . '?action=' . $action, $postData, substr($raw, 0, 2000), $httpCode, 'error');
            if (!empty($provider['id'])) {
                Database::execute("UPDATE providers SET last_error = ? WHERE id = ?", [$errMsg, $provider['id']]);
            }
            return ['ok' => false, 'error' => $errMsg];
        }

        if (isset($decoded['error'])) {
            $errMsg = (string)$decoded['error'];
            Logger::api('provider_out', (int)($provider['id'] ?? 0), null, $apiUrl . '?action=' . $action, $postData, $decoded, $httpCode, 'error');
            if (!empty($provider['id'])) {
                Database::execute("UPDATE providers SET last_error = ? WHERE id = ?", [$errMsg, $provider['id']]);
            }
            return ['ok' => false, 'error' => $errMsg, 'data' => $decoded];
        }

        Logger::api('provider_out', (int)($provider['id'] ?? 0), null, $apiUrl . '?action=' . $action, $postData, is_array($decoded) && count($decoded) > 20 ? ('[' . count($decoded) . ' items]') : $decoded, $httpCode, 'ok');
        if (!empty($provider['id'])) {
            Database::execute("UPDATE providers SET last_error = NULL WHERE id = ?", [$provider['id']]);
        }

        return ['ok' => true, 'data' => $decoded];
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
