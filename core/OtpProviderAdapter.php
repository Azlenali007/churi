<?php
/**
 * Real OTP Provider Integration Engine
 * - Built-in Official 5SIM v1 API Adapter
 * - Configurable Custom JSON Provider Adapter
 * - Extensible Custom PHP Provider Adapter Loader (/core/otp_adapters/*.php)
 *
 * Security:
 * - API credentials stay strictly server-side (AES-256-GCM encrypted in DB).
 * - Sensitive headers/params redacted before logging to otp_provider_logs.
 * - URL validation blocks non-HTTP schemes and metadata/internal addresses in production.
 */

interface OtpProviderInterface
{
    public function testConnection(): array;
    public function getBalance(): array;
    public function getCountries(): array;
    public function getAvailabilityAndPrice(string $countryCode, string $serviceCode, string $operator = 'any'): array;
    public function buyActivation(string $countryCode, string $serviceCode, string $operator = 'any', ?int $activationId = null): array;
    public function checkActivation(string $providerOrderId, ?int $activationId = null): array;
    public function cancelActivation(string $providerOrderId, ?int $activationId = null): array;
    public function finishActivation(string $providerOrderId, ?int $activationId = null): array;
}

class OtpHttpClient
{
    /**
     * Validate outbound provider URL against unsafe schemes & SSRF targets.
     */
    public static function validateUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return 'Invalid provider API URL.';
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return 'Only HTTP and HTTPS provider URLs are permitted.';
        }
        $host = strtolower(trim($parts['host'], '[]'));

        // Always block cloud metadata endpoints
        if ($host === '169.254.169.254' || $host === 'metadata.google.internal') {
            return 'Target host is blocked for security reasons.';
        }

        $cfg = $GLOBALS['APP_CONFIG']['app'] ?? [];
        $allowLocal = !empty($cfg['allow_local_providers']) || (($cfg['env'] ?? 'production') === 'testing');

        if (!$allowLocal) {
            if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || str_ends_with($host, '.local')) {
                return 'Loopback and local provider URLs are blocked in production.';
            }
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return 'Private or reserved IP provider URLs are blocked in production.';
                }
            } else {
                $resolved = @gethostbyname($host);
                if ($resolved && filter_var($resolved, FILTER_VALIDATE_IP)) {
                    if (!filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return 'Provider hostname resolved to a private or reserved IP address.';
                    }
                }
            }
        }
        return null;
    }

    /**
     * Execute HTTP request via cURL and persist redacted log to otp_provider_logs.
     */
    public static function request(
        array $provider,
        string $action,
        string $method,
        string $url,
        array $headers = [],
        $body = null,
        ?int $activationId = null,
        ?string $secretToRedact = null
    ): array {
        $urlErr = self::validateUrl($url);
        if ($urlErr !== null) {
            self::logCall((int)($provider['id'] ?? 0), $activationId, $action, $url, 0, $body, '', 'error', $urlErr, $secretToRedact);
            return ['ok' => false, 'uncertain' => false, 'http_code' => 0, 'error' => $urlErr, 'raw' => '', 'data' => null];
        }

        $timeout = max(5, min(60, (int)($provider['timeout_seconds'] ?? Helpers::setting('otp_api_timeout', '20'))));
        $ch = curl_init($url);
        $method = strtoupper($method);

        $curlHeaders = array_merge(['Accept: application/json'], $headers);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_USERAGENT      => 'DualStore-OTP-Client/1.0',
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            if (is_array($body)) {
                $opts[CURLOPT_POSTFIELDS] = http_build_query($body);
            } elseif (is_string($body)) {
                $opts[CURLOPT_POSTFIELDS] = $body;
            }
        } elseif ($method !== 'GET') {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = is_array($body) ? http_build_query($body) : (string)$body;
            }
        }

        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlErr = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $curlErrno !== 0) {
            // Network timeout or mid-flight connection reset -> uncertain outcome if buying
            $isUncertain = in_array($curlErrno, [CURLE_OPERATION_TIMEDOUT, CURLE_GOT_NOTHING, CURLE_RECV_ERROR], true);
            $errMsg = 'Provider connection error: ' . ($curlErr ?: 'Unable to reach provider API');
            self::logCall((int)($provider['id'] ?? 0), $activationId, $action, $url, $httpCode, $body, '', 'error', $errMsg, $secretToRedact);
            return [
                'ok'        => false,
                'uncertain' => $isUncertain,
                'http_code' => $httpCode,
                'error'     => $errMsg,
                'raw'       => '',
                'data'      => null,
            ];
        }

        $rawStr = (string)$raw;
        $decoded = json_decode($rawStr, true);
        $isHttpOk = ($httpCode >= 200 && $httpCode < 300);

        $logStatus = $isHttpOk ? 'ok' : 'error';
        $errMsg = $isHttpOk ? null : self::extractErrorMessage($rawStr, $decoded, $httpCode);
        self::logCall((int)($provider['id'] ?? 0), $activationId, $action, $url, $httpCode, $body, $rawStr, $logStatus, $errMsg, $secretToRedact);

        if (!$isHttpOk) {
            return [
                'ok'        => false,
                'uncertain' => ($httpCode >= 500 && $httpCode !== 501),
                'http_code' => $httpCode,
                'error'     => $errMsg,
                'raw'       => $rawStr,
                'data'      => is_array($decoded) ? $decoded : null,
            ];
        }

        return [
            'ok'        => true,
            'uncertain' => false,
            'http_code' => $httpCode,
            'error'     => null,
            'raw'       => $rawStr,
            'data'      => is_array($decoded) ? $decoded : $rawStr,
        ];
    }

    private static function extractErrorMessage(string $raw, $decoded, int $httpCode): string
    {
        if (is_array($decoded)) {
            foreach (['error', 'message', 'detail', 'msg'] as $k) {
                if (!empty($decoded[$k]) && is_string($decoded[$k])) {
                    return trim($decoded[$k]);
                }
            }
        }
        $plain = trim(strip_tags($raw));
        if ($plain !== '' && strlen($plain) <= 180) {
            return $plain;
        }
        return 'Provider returned HTTP ' . $httpCode;
    }

    public static function logCall(
        int $providerId,
        ?int $activationId,
        string $action,
        string $endpoint,
        int $httpCode,
        $requestPayload,
        string $responsePayload,
        string $status,
        ?string $errorMessage = null,
        ?string $secretToRedact = null
    ): void {
        try {
            $redact = function (string $s) use ($secretToRedact): string {
                if ($secretToRedact !== null && $secretToRedact !== '') {
                    $s = str_replace($secretToRedact, '[REDACTED]', $s);
                }
                $s = preg_replace('/(api[_-]?key|token|secret|password|authorization)=([^&\s"]+)/i', '$1=[REDACTED]', $s);
                return substr($s, 0, 12000);
            };
            $reqStr = is_array($requestPayload) ? json_encode($requestPayload) : (string)$requestPayload;
            Database::insert(
                "INSERT INTO otp_provider_logs (provider_id, activation_id, action, endpoint, http_code, request_payload, response_payload, status, error_message)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $providerId > 0 ? $providerId : null,
                    $activationId,
                    substr($action, 0, 60),
                    substr($redact($endpoint), 0, 255),
                    $httpCode ?: null,
                    $redact($reqStr),
                    $redact($responsePayload),
                    $status === 'ok' ? 'ok' : 'error',
                    $errorMessage !== null ? substr($redact($errorMessage), 0, 255) : null,
                ]
            );
            if ($providerId > 0 && $status === 'error' && $errorMessage) {
                Database::execute("UPDATE otp_providers SET last_error = ? WHERE id = ?", [substr($errorMessage, 0, 500), $providerId]);
            }
        } catch (Throwable $e) {
            Logger::errorFile('OtpHttpClient::logCall error: ' . $e->getMessage());
        }
    }

    public static function extractDotPath($data, string $path)
    {
        if ($path === '' || !is_array($data)) {
            return null;
        }
        $segments = explode('.', $path);
        $cur = $data;
        foreach ($segments as $seg) {
            if (is_array($cur) && array_key_exists($seg, $cur)) {
                $cur = $cur[$seg];
            } elseif (is_array($cur) && ctype_digit($seg) && array_key_exists((int)$seg, $cur)) {
                $cur = $cur[(int)$seg];
            } else {
                return null;
            }
        }
        return $cur;
    }

    public static function extractOtpFromText(string $text): ?string
    {
        if (preg_match('/\b(\d{4,8})\b/', $text, $m)) {
            return $m[1];
        }
        return null;
    }
}

/**
 * Official 5SIM v1 API Adapter
 * Verified endpoints:
 *  - GET /v1/user/profile
 *  - GET /v1/guest/countries
 *  - GET /v1/guest/products/{country}/{operator}
 *  - GET /v1/guest/prices?country={country}&product={product}
 *  - GET /v1/user/buy/activation/{country}/{operator}/{product}
 *  - GET /v1/user/check/{id}
 *  - GET /v1/user/cancel/{id}
 *  - GET /v1/user/finish/{id}
 *  - GET /v1/user/ban/{id}
 */
class FiveSimAdapter implements OtpProviderInterface
{
    private array $provider;
    private string $baseUrl;
    private string $apiKey;

    public function __construct(array $provider)
    {
        $this->provider = $provider;
        $this->baseUrl = rtrim((string)($provider['base_url'] ?: 'https://5sim.net'), '/');
        $this->apiKey = Helpers::decryptSecret((string)($provider['api_key_enc'] ?? ''));
    }

    private function authHeaders(): array
    {
        $h = ['Accept: application/json'];
        if ($this->apiKey !== '') {
            $h[] = 'Authorization: Bearer ' . $this->apiKey;
        }
        return $h;
    }

    public function testConnection(): array
    {
        return $this->getBalance();
    }

    public function getBalance(): array
    {
        if ($this->apiKey === '') {
            return ['ok' => false, 'error' => '5SIM API token is not configured.'];
        }
        $res = OtpHttpClient::request(
            $this->provider,
            'balance',
            'GET',
            $this->baseUrl . '/v1/user/profile',
            $this->authHeaders(),
            null,
            null,
            $this->apiKey
        );
        if (!$res['ok'] || !is_array($res['data'])) {
            return ['ok' => false, 'error' => $res['error'] ?: 'Invalid profile response from 5SIM.'];
        }
        $bal = isset($res['data']['balance']) ? (string)$res['data']['balance'] : null;
        if ($bal === null) {
            return ['ok' => false, 'error' => '5SIM profile response did not contain a balance field.'];
        }
        return [
            'ok'       => true,
            'balance'  => Currency::normalize($bal, 4),
            'currency' => (string)($this->provider['currency_code'] ?: 'RUB'),
            'email'    => (string)($res['data']['email'] ?? ''),
            'rating'   => $res['data']['rating'] ?? null,
            'raw'      => $res['data'],
        ];
    }

    public function getCountries(): array
    {
        $res = OtpHttpClient::request(
            $this->provider,
            'countries',
            'GET',
            $this->baseUrl . '/v1/guest/countries',
            ['Accept: application/json']
        );
        if (!$res['ok'] || !is_array($res['data'])) {
            return ['ok' => false, 'error' => $res['error'] ?: 'Could not fetch countries from 5SIM.'];
        }
        $list = [];
        foreach ($res['data'] as $code => $info) {
            $iso = '';
            if (!empty($info['iso']) && is_array($info['iso'])) {
                $iso = strtoupper((string)array_key_first($info['iso']));
            }
            $dial = '';
            if (!empty($info['prefix']) && is_array($info['prefix'])) {
                $dial = (string)array_key_first($info['prefix']);
            }
            $list[] = [
                'provider_code' => (string)$code,
                'name'          => (string)($info['text_en'] ?? ucfirst((string)$code)),
                'iso_code'      => $iso,
                'dial_code'     => $dial,
            ];
        }
        return ['ok' => true, 'countries' => $list];
    }

    public function getAvailabilityAndPrice(string $countryCode, string $serviceCode, string $operator = 'any'): array
    {
        $c = strtolower(trim($countryCode));
        $p = strtolower(trim($serviceCode));
        $url = $this->baseUrl . '/v1/guest/prices?country=' . rawurlencode($c) . '&product=' . rawurlencode($p);
        $res = OtpHttpClient::request($this->provider, 'pricing', 'GET', $url, ['Accept: application/json']);
        if (!$res['ok'] || !is_array($res['data'])) {
            return ['ok' => false, 'available' => false, 'count' => 0, 'cost' => null, 'error' => $res['error'] ?: 'Unable to fetch 5SIM pricing.'];
        }

        // 5SIM /v1/guest/prices returns { "{country}": { "{product}": { "{operator}": { "cost": 12.5, "count": 45, "rate": 98.1 } } } }
        $operators = $res['data'][$c][$p] ?? null;
        if (!is_array($operators) || empty($operators)) {
            return ['ok' => true, 'available' => false, 'count' => 0, 'cost' => null, 'operator' => $operator, 'currency' => 'RUB'];
        }

        $bestOp = $operator;
        $bestCost = null;
        $totalCount = 0;

        if ($operator !== '' && $operator !== 'any' && isset($operators[$operator]) && is_array($operators[$operator])) {
            $info = $operators[$operator];
            $totalCount = (int)($info['count'] ?? 0);
            $bestCost = isset($info['cost']) ? (string)$info['cost'] : null;
        } else {
            foreach ($operators as $opName => $info) {
                if (!is_array($info)) continue;
                $cnt = (int)($info['count'] ?? 0);
                $cost = isset($info['cost']) ? (float)$info['cost'] : null;
                if ($cnt > 0) {
                    $totalCount += $cnt;
                    if ($cost !== null && ($bestCost === null || $cost < (float)$bestCost)) {
                        $bestCost = (string)$cost;
                        $bestOp = (string)$opName;
                    }
                }
            }
        }

        return [
            'ok'        => true,
            'available' => $totalCount > 0 && $bestCost !== null,
            'count'     => $totalCount,
            'cost'      => $bestCost !== null ? Currency::normalize($bestCost, 4) : null,
            'operator'  => $bestOp ?: 'any',
            'currency'  => (string)($this->provider['currency_code'] ?: 'RUB'),
        ];
    }

    public function buyActivation(string $countryCode, string $serviceCode, string $operator = 'any', ?int $activationId = null): array
    {
        if ($this->apiKey === '') {
            return ['ok' => false, 'uncertain' => false, 'error' => '5SIM API token is not configured.'];
        }
        $c = rawurlencode(strtolower(trim($countryCode)));
        $op = rawurlencode(strtolower(trim($operator ?: 'any')));
        $p = rawurlencode(strtolower(trim($serviceCode)));
        $url = "{$this->baseUrl}/v1/user/buy/activation/{$c}/{$op}/{$p}";

        $res = OtpHttpClient::request($this->provider, 'buy', 'GET', $url, $this->authHeaders(), null, $activationId, $this->apiKey);
        if (!$res['ok']) {
            return [
                'ok'        => false,
                'uncertain' => !empty($res['uncertain']),
                'error'     => $res['error'] ?: '5SIM activation purchase failed.',
                'raw'       => $res['raw'],
            ];
        }

        // 5SIM sometimes responds 200 with plain text "no free phones" or "not enough user balance"
        if (!is_array($res['data'])) {
            $txt = trim((string)$res['raw']);
            return [
                'ok'        => false,
                'uncertain' => false,
                'error'     => $txt !== '' ? $txt : 'Provider did not return an activation object.',
                'raw'       => $res['raw'],
            ];
        }

        $d = $res['data'];
        if (empty($d['id']) || empty($d['phone'])) {
            return [
                'ok'        => false,
                'uncertain' => false,
                'error'     => '5SIM response did not contain a valid order ID and phone number.',
                'raw'       => $res['raw'],
            ];
        }

        return [
            'ok'                => true,
            'provider_order_id' => (string)$d['id'],
            'phone_number'      => (string)$d['phone'],
            'operator'          => (string)($d['operator'] ?? $operator),
            'provider_cost'     => isset($d['price']) ? Currency::normalize((string)$d['price'], 4) : '0.0000',
            'provider_currency' => (string)($this->provider['currency_code'] ?: 'RUB'),
            'provider_status'   => (string)($d['status'] ?? 'PENDING'),
            'status'            => self::mapStatus((string)($d['status'] ?? 'PENDING'), !empty($d['sms'])),
            'expires_at'        => self::parseIsoDate($d['expires'] ?? null),
            'raw'               => $d,
        ];
    }

    public function checkActivation(string $providerOrderId, ?int $activationId = null): array
    {
        if ($this->apiKey === '') {
            return ['ok' => false, 'error' => '5SIM API token is not configured.'];
        }
        $url = $this->baseUrl . '/v1/user/check/' . rawurlencode($providerOrderId);
        $res = OtpHttpClient::request($this->provider, 'check', 'GET', $url, $this->authHeaders(), null, $activationId, $this->apiKey);
        if (!$res['ok'] || !is_array($res['data'])) {
            return ['ok' => false, 'error' => $res['error'] ?: 'Unable to check activation status on 5SIM.'];
        }
        $d = $res['data'];
        $smsList = is_array($d['sms'] ?? null) ? $d['sms'] : [];
        $latestSms = !empty($smsList) ? end($smsList) : null;
        $otpCode = null;
        $smsText = null;
        $smsTime = null;
        if (is_array($latestSms)) {
            $otpCode = !empty($latestSms['code']) ? (string)$latestSms['code'] : null;
            $smsText = !empty($latestSms['text']) ? (string)$latestSms['text'] : null;
            if ($otpCode === null && $smsText !== null) {
                $otpCode = OtpHttpClient::extractOtpFromText($smsText);
            }
            $smsTime = self::parseIsoDate($latestSms['date'] ?? $latestSms['created_at'] ?? null);
        }

        $provStatus = (string)($d['status'] ?? 'PENDING');
        return [
            'ok'              => true,
            'provider_status' => $provStatus,
            'status'          => self::mapStatus($provStatus, $otpCode !== null || $smsText !== null),
            'phone_number'    => !empty($d['phone']) ? (string)$d['phone'] : null,
            'otp_code'        => $otpCode,
            'sms_text'        => $smsText,
            'sms_received_at' => $smsTime,
            'expires_at'      => self::parseIsoDate($d['expires'] ?? null),
            'raw'             => $d,
        ];
    }

    public function cancelActivation(string $providerOrderId, ?int $activationId = null): array
    {
        if ($this->apiKey === '') {
            return ['ok' => false, 'error' => '5SIM API token is not configured.'];
        }
        $url = $this->baseUrl . '/v1/user/cancel/' . rawurlencode($providerOrderId);
        $res = OtpHttpClient::request($this->provider, 'cancel', 'GET', $url, $this->authHeaders(), null, $activationId, $this->apiKey);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?: '5SIM rejected cancellation.'];
        }
        $d = is_array($res['data']) ? $res['data'] : [];
        return [
            'ok'              => true,
            'provider_status' => (string)($d['status'] ?? 'CANCELED'),
            'status'          => 'canceled',
            'raw'             => $d,
        ];
    }

    public function finishActivation(string $providerOrderId, ?int $activationId = null): array
    {
        if ($this->apiKey === '') {
            return ['ok' => false, 'error' => '5SIM API token is not configured.'];
        }
        $url = $this->baseUrl . '/v1/user/finish/' . rawurlencode($providerOrderId);
        $res = OtpHttpClient::request($this->provider, 'finish', 'GET', $url, $this->authHeaders(), null, $activationId, $this->apiKey);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?: '5SIM rejected completion request.'];
        }
        $d = is_array($res['data']) ? $res['data'] : [];
        return [
            'ok'              => true,
            'provider_status' => (string)($d['status'] ?? 'FINISHED'),
            'status'          => 'completed',
            'raw'             => $d,
        ];
    }

    public static function mapStatus(string $providerStatus, bool $hasSms = false): string
    {
        $s = strtoupper(trim($providerStatus));
        return match ($s) {
            'RECEIVED' => $hasSms ? 'otp_received' : 'waiting_otp',
            'FINISHED' => 'completed',
            'TIMEOUT'  => 'expired',
            'CANCELED', 'BANNED' => 'canceled',
            'PENDING'  => $hasSms ? 'otp_received' : 'waiting_otp',
            default    => $hasSms ? 'otp_received' : 'waiting_otp',
        };
    }

    public static function parseIsoDate(?string $val): ?string
    {
        if (!$val) {
            return null;
        }
        $ts = strtotime($val);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}

/**
 * Configurable Custom JSON/REST OTP Provider Adapter
 * Supports arbitrary provider endpoints, auth methods, parameter templates,
 * dot-path JSON response mappings, and provider status mappings.
 */
class CustomOtpProviderAdapter implements OtpProviderInterface
{
    private array $provider;
    private string $baseUrl;
    private string $apiKey;
    private array $endpoints;
    private array $reqParams;
    private array $respMap;
    private array $statusMap;

    public function __construct(array $provider)
    {
        $this->provider = $provider;
        $this->baseUrl = rtrim((string)($provider['base_url'] ?? ''), '/');
        $this->apiKey = Helpers::decryptSecret((string)($provider['api_key_enc'] ?? ''));
        $this->endpoints = json_decode((string)($provider['endpoints_json'] ?? '{}'), true) ?: [];
        $this->reqParams = json_decode((string)($provider['request_params_json'] ?? '{}'), true) ?: [];
        $this->respMap = json_decode((string)($provider['response_map_json'] ?? '{}'), true) ?: [];
        $this->statusMap = json_decode((string)($provider['status_map_json'] ?? '{}'), true) ?: [];
    }

    private function executeEndpoint(string $action, string $endpointKey, array $vars = [], ?int $activationId = null): array
    {
        $ep = trim((string)($this->endpoints[$endpointKey] ?? ''));
        if ($ep === '') {
            return ['ok' => false, 'uncertain' => false, 'error' => "Endpoint '{$endpointKey}' is not configured for provider {$this->provider['name']}."];
        }

        $vars['api_key'] = $this->apiKey;
        $replacedEp = $ep;
        foreach ($vars as $k => $v) {
            $replacedEp = str_replace('{' . $k . '}', rawurlencode((string)$v), $replacedEp);
        }
        $url = str_starts_with($replacedEp, 'http://') || str_starts_with($replacedEp, 'https://')
            ? $replacedEp
            : $this->baseUrl . '/' . ltrim($replacedEp, '/');

        $method = strtoupper((string)($this->provider['request_method'] ?? 'GET'));
        $authType = (string)($this->provider['auth_type'] ?? 'bearer');
        $headers = ['Accept: application/json'];
        $queryParams = [];
        $postBody = [];

        if ($this->apiKey !== '') {
            if ($authType === 'bearer') {
                $headers[] = 'Authorization: Bearer ' . $this->apiKey;
            } elseif ($authType === 'header') {
                $hName = trim((string)($this->provider['auth_header_name'] ?: 'X-API-Key'));
                $headers[] = $hName . ': ' . $this->apiKey;
            } elseif ($authType === 'query') {
                $pName = trim((string)($this->provider['auth_param_name'] ?: 'api_key'));
                $queryParams[$pName] = $this->apiKey;
            } elseif ($authType === 'post_body') {
                $pName = trim((string)($this->provider['auth_param_name'] ?: 'api_key'));
                $postBody[$pName] = $this->apiKey;
            }
        }

        // Apply configured request parameter template for this endpoint
        $tpl = $this->reqParams[$endpointKey] ?? null;
        if (is_array($tpl)) {
            foreach ($tpl as $k => $v) {
                $val = (string)$v;
                foreach ($vars as $vk => $vv) {
                    $val = str_replace('{' . $vk . '}', (string)$vv, $val);
                }
                if ($method === 'POST') {
                    $postBody[$k] = $val;
                } else {
                    $queryParams[$k] = $val;
                }
            }
        }

        if (!empty($queryParams)) {
            $sep = str_contains($url, '?') ? '&' : '?';
            $url .= $sep . http_build_query($queryParams);
        }

        return OtpHttpClient::request(
            $this->provider,
            $action,
            $method,
            $url,
            $headers,
            $method === 'POST' ? $postBody : null,
            $activationId,
            $this->apiKey
        );
    }

    private function mapVal($data, string $key, array $fallbacks = [])
    {
        if (!is_array($data)) {
            return null;
        }
        $configured = (string)($this->respMap[$key] ?? '');
        if ($configured !== '') {
            $v = OtpHttpClient::extractDotPath($data, $configured);
            if ($v !== null) return $v;
        }
        foreach ($fallbacks as $fb) {
            $v = OtpHttpClient::extractDotPath($data, $fb);
            if ($v !== null) return $v;
        }
        return null;
    }

    private function mapProviderStatus(string $rawStatus, bool $hasOtp = false): string
    {
        $s = trim($rawStatus);
        foreach ($this->statusMap as $provKey => $internalStatus) {
            if (strcasecmp((string)$provKey, $s) === 0) {
                return (string)$internalStatus;
            }
        }
        $low = strtolower($s);
        return match (true) {
            $hasOtp || str_contains($low, 'received') || str_contains($low, 'sms') => 'otp_received',
            str_contains($low, 'finish') || str_contains($low, 'complete')         => 'completed',
            str_contains($low, 'timeout') || str_contains($low, 'expir')           => 'expired',
            str_contains($low, 'cancel') || str_contains($low, 'ban')              => 'canceled',
            str_contains($low, 'refund')                                           => 'refunded',
            str_contains($low, 'fail') || str_contains($low, 'error')              => 'failed',
            default                                                                => 'waiting_otp',
        };
    }

    public function testConnection(): array
    {
        return $this->getBalance();
    }

    public function getBalance(): array
    {
        $res = $this->executeEndpoint('balance', 'balance');
        if (!$res['ok'] || !is_array($res['data'])) {
            return ['ok' => false, 'error' => $res['error'] ?: 'Invalid balance response from custom provider.'];
        }
        $bal = $this->mapVal($res['data'], 'balance', ['balance', 'data.balance', 'wallet', 'credit']);
        if ($bal === null || !is_numeric((string)$bal)) {
            return ['ok' => false, 'error' => 'Balance field could not be read from provider response. Check response JSON mapping.'];
        }
        return [
            'ok'       => true,
            'balance'  => Currency::normalize((string)$bal, 4),
            'currency' => (string)($this->provider['currency_code'] ?: 'USD'),
            'raw'      => $res['data'],
        ];
    }

    public function getCountries(): array
    {
        if (empty($this->endpoints['countries_services'])) {
            return ['ok' => false, 'error' => 'Countries/Services endpoint is not configured for this provider.'];
        }
        $res = $this->executeEndpoint('countries', 'countries_services');
        if (!$res['ok'] || !is_array($res['data'])) {
            return ['ok' => false, 'error' => $res['error'] ?: 'Unable to fetch countries.'];
        }
        return ['ok' => true, 'countries' => $res['data']];
    }

    public function getAvailabilityAndPrice(string $countryCode, string $serviceCode, string $operator = 'any'): array
    {
        if (empty($this->endpoints['pricing'])) {
            return [
                'ok'        => true,
                'available' => true,
                'count'     => null,
                'cost'      => null,
                'operator'  => $operator ?: 'any',
                'currency'  => (string)($this->provider['currency_code'] ?: 'USD'),
            ];
        }
        $res = $this->executeEndpoint('pricing', 'pricing', [
            'country'  => $countryCode,
            'service'  => $serviceCode,
            'operator' => $operator ?: 'any',
        ]);
        if (!$res['ok'] || !is_array($res['data'])) {
            return ['ok' => false, 'available' => false, 'count' => 0, 'cost' => null, 'error' => $res['error'] ?: 'Pricing lookup failed.'];
        }
        $cost = $this->mapVal($res['data'], 'price', ['price', 'cost', 'data.price', 'data.cost']);
        $count = $this->mapVal($res['data'], 'stock', ['count', 'stock', 'available', 'quantity', 'data.count']);
        $isAvail = $count !== null ? ((int)$count > 0) : ($cost !== null);
        return [
            'ok'        => true,
            'available' => $isAvail,
            'count'     => $count !== null ? (int)$count : ($isAvail ? 1 : 0),
            'cost'      => ($cost !== null && is_numeric((string)$cost)) ? Currency::normalize((string)$cost, 4) : null,
            'operator'  => $operator ?: 'any',
            'currency'  => (string)($this->provider['currency_code'] ?: 'USD'),
        ];
    }

    public function buyActivation(string $countryCode, string $serviceCode, string $operator = 'any', ?int $activationId = null): array
    {
        $res = $this->executeEndpoint('buy', 'purchase', [
            'country'  => $countryCode,
            'service'  => $serviceCode,
            'operator' => $operator ?: 'any',
        ], $activationId);

        if (!$res['ok'] || !is_array($res['data'])) {
            return [
                'ok'        => false,
                'uncertain' => !empty($res['uncertain']),
                'error'     => $res['error'] ?: 'Custom provider activation purchase failed.',
                'raw'       => $res['raw'],
            ];
        }

        $d = $res['data'];
        $extId = $this->mapVal($d, 'order_id', ['id', 'activation_id', 'order_id', 'data.id', 'data.activation_id']);
        $phone = $this->mapVal($d, 'phone', ['phone', 'number', 'phone_number', 'data.phone', 'data.number']);
        $cost = $this->mapVal($d, 'price', ['price', 'cost', 'data.price', 'data.cost']);
        $provStatus = (string)($this->mapVal($d, 'status', ['status', 'state', 'data.status']) ?? 'WAITING');
        $expiresRaw = $this->mapVal($d, 'expires_at', ['expires', 'expires_at', 'expire_at', 'data.expires_at']);

        if (empty($extId) || empty($phone)) {
            return [
                'ok'        => false,
                'uncertain' => false,
                'error'     => 'Provider response missing activation ID or phone number.',
                'raw'       => $d,
            ];
        }

        $expiresAt = null;
        if (is_numeric($expiresRaw) && (int)$expiresRaw > 1000000000) {
            $expiresAt = date('Y-m-d H:i:s', (int)$expiresRaw);
        } elseif (is_string($expiresRaw) && strtotime($expiresRaw)) {
            $expiresAt = date('Y-m-d H:i:s', strtotime($expiresRaw));
        }

        return [
            'ok'                => true,
            'provider_order_id' => (string)$extId,
            'phone_number'      => (string)$phone,
            'operator'          => $operator ?: 'any',
            'provider_cost'     => ($cost !== null && is_numeric((string)$cost)) ? Currency::normalize((string)$cost, 4) : '0.0000',
            'provider_currency' => (string)($this->provider['currency_code'] ?: 'USD'),
            'provider_status'   => $provStatus,
            'status'            => $this->mapProviderStatus($provStatus, false),
            'expires_at'        => $expiresAt,
            'raw'               => $d,
        ];
    }

    public function checkActivation(string $providerOrderId, ?int $activationId = null): array
    {
        $epKey = !empty($this->endpoints['sms']) ? 'sms' : 'status';
        $res = $this->executeEndpoint('check', $epKey, ['id' => $providerOrderId], $activationId);
        if (!$res['ok'] || !is_array($res['data'])) {
            return ['ok' => false, 'error' => $res['error'] ?: 'Status check failed.'];
        }
        $d = $res['data'];
        $provStatus = (string)($this->mapVal($d, 'status', ['status', 'state', 'data.status']) ?? 'WAITING');
        $otpCode = $this->mapVal($d, 'sms_code', ['code', 'otp', 'sms.0.code', 'data.code', 'data.otp']);
        $smsText = $this->mapVal($d, 'sms_text', ['text', 'sms_text', 'message', 'sms.0.text', 'data.text']);
        if (($otpCode === null || $otpCode === '') && is_string($smsText) && $smsText !== '') {
            $otpCode = OtpHttpClient::extractOtpFromText($smsText);
        }
        $expiresRaw = $this->mapVal($d, 'expires_at', ['expires', 'expires_at', 'data.expires_at']);
        $expiresAt = (is_string($expiresRaw) && strtotime($expiresRaw)) ? date('Y-m-d H:i:s', strtotime($expiresRaw)) : null;

        return [
            'ok'              => true,
            'provider_status' => $provStatus,
            'status'          => $this->mapProviderStatus($provStatus, !empty($otpCode) || !empty($smsText)),
            'phone_number'    => $this->mapVal($d, 'phone', ['phone', 'number', 'data.phone']),
            'otp_code'        => $otpCode !== null && $otpCode !== '' ? (string)$otpCode : null,
            'sms_text'        => $smsText !== null && $smsText !== '' ? (string)$smsText : null,
            'sms_received_at' => (!empty($otpCode) || !empty($smsText)) ? date('Y-m-d H:i:s') : null,
            'expires_at'      => $expiresAt,
            'raw'             => $d,
        ];
    }

    public function cancelActivation(string $providerOrderId, ?int $activationId = null): array
    {
        if (empty($this->endpoints['cancel'])) {
            return ['ok' => false, 'error' => 'Cancellation is not supported by this provider configuration.'];
        }
        $res = $this->executeEndpoint('cancel', 'cancel', ['id' => $providerOrderId], $activationId);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?: 'Provider rejected cancellation.'];
        }
        return ['ok' => true, 'provider_status' => 'CANCELED', 'status' => 'canceled', 'raw' => $res['data']];
    }

    public function finishActivation(string $providerOrderId, ?int $activationId = null): array
    {
        if (empty($this->endpoints['complete'])) {
            return ['ok' => true, 'provider_status' => 'COMPLETED', 'status' => 'completed', 'raw' => []];
        }
        $res = $this->executeEndpoint('finish', 'complete', ['id' => $providerOrderId], $activationId);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?: 'Provider rejected completion.'];
        }
        return ['ok' => true, 'provider_status' => 'COMPLETED', 'status' => 'completed', 'raw' => $res['data']];
    }
}

class OtpProviderFactory
{
    public static function make(array $provider): OtpProviderInterface
    {
        $type = (string)($provider['adapter_type'] ?? 'fivesim');
        if ($type === 'fivesim') {
            return new FiveSimAdapter($provider);
        }
        if ($type === 'custom_php') {
            $cls = trim((string)($provider['custom_adapter_class'] ?? ''));
            if (preg_match('/^[A-Za-z][A-Za-z0-9_]{2,60}$/', $cls)) {
                $adapterDir = realpath(APP_ROOT . '/core/otp_adapters');
                $file = $adapterDir ? realpath($adapterDir . '/' . $cls . '.php') : false;
                if ($adapterDir && $file && str_starts_with($file, $adapterDir . DIRECTORY_SEPARATOR)) {
                    require_once $file;
                    if (class_exists($cls) && in_array(OtpProviderInterface::class, class_implements($cls) ?: [], true)) {
                        return new $cls($provider);
                    }
                }
            }
            throw new RuntimeException('Custom PHP adapter class not found or does not implement OtpProviderInterface.');
        }
        return new CustomOtpProviderAdapter($provider);
    }
}
