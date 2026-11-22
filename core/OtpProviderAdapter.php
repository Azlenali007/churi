<?php
/**
 * Real OTP Provider Integration Engine
 * - Built-in 5SIM API1 Adapter (Exact Protocol: http://api1.5sim.net/stubs/handler_api.php)
 * - Configurable Custom JSON/REST Provider Adapter (kept strictly separate from 5SIM)
 * - Extensible Custom PHP Provider Adapter Loader (/core/otp_adapters/*.php)
 *
 * Security:
 * - API credentials stay strictly server-side (AES-256-GCM encrypted in DB).
 * - Sensitive headers, query params, and secrets are redacted before saving to otp_provider_logs.
 * - URL validation blocks non-HTTP schemes, metadata endpoints, and private/local IPs in production.
 */

interface OtpProviderInterface
{
    public function testConnection(): array;
    public function getBalance(): array;
    public function getCountries(): array;
    public function getPrices(?string $countryCode = null, ?string $serviceCode = null): array;
    public function getNumbersStatus(?string $countryCode = null, ?string $operator = null): array;
    public function getAvailabilityAndPrice(string $countryCode, string $serviceCode, string $operator = 'any'): array;
    public function buyActivation(string $countryCode, string $serviceCode, string $operator = 'any', ?int $activationId = null): array;
    public function checkActivation(string $providerOrderId, ?int $activationId = null): array;
    public function setActivationStatus(string $providerOrderId, int $statusCode, ?int $activationId = null): array;
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
     * Execute HTTP request via cURL, measure response time in ms, and persist redacted log to otp_provider_logs.
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
        $providerId = (int)($provider['id'] ?? 0);
        if (!function_exists('curl_init')) {
            $err = 'PHP cURL extension is not enabled on this server.';
            $logId = self::logCall($providerId, $activationId, $action, $url, 0, 0, $body, '', 'error', $err, $secretToRedact);
            return [
                'ok'               => false,
                'uncertain'        => false,
                'http_code'        => 0,
                'response_time_ms' => 0,
                'log_id'           => $logId,
                'error'            => $err,
                'error_code'       => 'CURL_UNAVAILABLE',
                'error_category'   => 'config',
                'raw'              => '',
                'data'             => null,
            ];
        }
        $urlErr = self::validateUrl($url);
        if ($urlErr !== null) {
            $logId = self::logCall($providerId, $activationId, $action, $url, 0, 0, $body, '', 'error', $urlErr, $secretToRedact);
            return [
                'ok'               => false,
                'uncertain'        => false,
                'http_code'        => 0,
                'response_time_ms' => 0,
                'log_id'           => $logId,
                'error'            => $urlErr,
                'error_code'       => 'INVALID_PROVIDER_URL',
                'error_category'   => 'config',
                'raw'              => '',
                'data'             => null,
            ];
        }

        $baseTimeout = max(5, min(60, (int)($provider['timeout_seconds'] ?? Helpers::setting('otp_api_timeout', '20'))));
        $timeout = ($action === 'getPrices' || $action === 'pricing') ? max(35, $baseTimeout) : $baseTimeout;
        $method = strtoupper($method);
        $curlHeaders = empty($headers) ? ['Accept: */*'] : $headers;

        $currentUrl = $url;
        $maxRedirects = 2;
        $hops = 0;
        $startedAt = microtime(true);
        $raw = false;
        $curlErrno = 0;
        $curlErr = '';
        $httpCode = 0;

        while (true) {
            $ch = curl_init($currentUrl);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_ENCODING       => '', // Accept gzip/deflate for large getPrices JSON responses
                CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => $curlHeaders,
                CURLOPT_USERAGENT      => 'DualStore-OTP-Client/2.1',
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
            $redirectUrl = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);

            // Safely follow up to 2 same-host or validated HTTP->HTTPS redirects (e.g. http://api1.5sim.net -> https://api1.5sim.net)
            if ($curlErrno === 0 && in_array($httpCode, [301, 302, 303, 307, 308], true) && $redirectUrl !== '' && $hops < $maxRedirects) {
                $redirErr = self::validateUrl($redirectUrl);
                if ($redirErr !== null) {
                    $elapsedMs = max(1, (int)round((microtime(true) - $startedAt) * 1000));
                    $logId = self::logCall($providerId, $activationId, $action, $url, $httpCode, $elapsedMs, $body, '', 'error', 'Blocked unsafe redirect: ' . $redirErr, $secretToRedact);
                    return [
                        'ok'               => false,
                        'uncertain'        => false,
                        'http_code'        => $httpCode,
                        'response_time_ms' => $elapsedMs,
                        'log_id'           => $logId,
                        'error'            => 'Blocked unsafe provider redirect.',
                        'raw'              => '',
                        'data'             => null,
                    ];
                }
                $currentUrl = $redirectUrl;
                if ($httpCode === 303) {
                    $method = 'GET';
                    $body = null;
                }
                $hops++;
                continue;
            }
            break;
        }

        $elapsedMs = max(1, (int)round((microtime(true) - $startedAt) * 1000));

        if ($raw === false || $curlErrno !== 0) {
            $isUncertain = in_array($curlErrno, [CURLE_OPERATION_TIMEDOUT, CURLE_GOT_NOTHING, CURLE_RECV_ERROR], true);
            $classified = ProviderErrorHandler::classifyHttpFailure(
                $httpCode,
                $curlErrno,
                $curlErr,
                '',
                null,
                $secretToRedact ? [$secretToRedact] : []
            );
            $errMsg = $classified['message'];
            $logId = self::logCall($providerId, $activationId, $action, $url, $httpCode, $elapsedMs, $body, '', 'error', $errMsg, $secretToRedact);
            return [
                'ok'               => false,
                'uncertain'        => $isUncertain,
                'http_code'        => $httpCode,
                'response_time_ms' => $elapsedMs,
                'log_id'           => $logId,
                'error'            => $errMsg,
                'error_code'       => $classified['error_code'],
                'error_category'   => $classified['category'],
                'raw'              => '',
                'data'             => null,
            ];
        }

        $rawStr = (string)$raw;
        $decoded = json_decode($rawStr, true);
        $isHttpOk = ($httpCode >= 200 && $httpCode < 300);

        $logStatus = $isHttpOk ? 'ok' : 'error';
        $rawErrMsg = $isHttpOk ? null : self::extractErrorMessage($rawStr, $decoded, $httpCode);
        $classified = $isHttpOk
            ? null
            : ProviderErrorHandler::classifyHttpFailure(
                $httpCode,
                0,
                '',
                $rawStr,
                $rawErrMsg,
                $secretToRedact ? [$secretToRedact] : []
            );
        $errMsg = $classified ? $classified['message'] : null;
        $logId = self::logCall($providerId, $activationId, $action, $url, $httpCode, $elapsedMs, $body, $rawStr, $logStatus, $errMsg, $secretToRedact);

        if (!$isHttpOk) {
            return [
                'ok'               => false,
                'uncertain'        => ($httpCode >= 500 && $httpCode !== 501),
                'http_code'        => $httpCode,
                'response_time_ms' => $elapsedMs,
                'log_id'           => $logId,
                'error'            => $errMsg,
                'error_code'       => $classified['error_code'] ?? 'PROVIDER_HTTP_ERROR',
                'error_category'   => $classified['category'] ?? 'http',
                'raw'              => $rawStr,
                'data'             => is_array($decoded) ? $decoded : null,
            ];
        }

        return [
            'ok'               => true,
            'uncertain'        => false,
            'http_code'        => $httpCode,
            'response_time_ms' => $elapsedMs,
            'log_id'           => $logId,
            'error'            => null,
            'error_code'       => null,
            'error_category'   => null,
            'raw'              => $rawStr,
            'data'             => is_array($decoded) ? $decoded : $rawStr,
        ];
    }

    /**
     * Update a previously logged call when an HTTP 200 response actually contains an application-level API error
     * (e.g., 5SIM plain-text BAD_KEY, NO_NUMBERS, NO_BALANCE).
     */
    public static function markLogError(int $logId, int $providerId, string $errorMessage, ?string $secretToRedact = null): void
    {
        $clean = self::redactString($errorMessage, $secretToRedact);
        try {
            if ($logId > 0) {
                Database::execute(
                    "UPDATE otp_provider_logs SET status = 'error', error_message = ? WHERE id = ?",
                    [substr($clean, 0, 255), $logId]
                );
            }
            if ($providerId > 0) {
                Database::execute(
                    "UPDATE otp_providers SET last_error = ? WHERE id = ?",
                    [substr($clean, 0, 500), $providerId]
                );
            }
        } catch (Throwable $e) {
            Logger::errorFile('OtpHttpClient::markLogError error: ' . $e->getMessage());
        }
    }

    public static function redactString(string $s, ?string $secretToRedact = null): string
    {
        if ($secretToRedact !== null && $secretToRedact !== '') {
            $s = str_replace($secretToRedact, '[REDACTED]', $s);
            $encoded = rawurlencode($secretToRedact);
            if ($encoded !== $secretToRedact) {
                $s = str_replace($encoded, '[REDACTED]', $s);
            }
            $encodedPlus = urlencode($secretToRedact);
            if ($encodedPlus !== $secretToRedact && $encodedPlus !== $encoded) {
                $s = str_replace($encodedPlus, '[REDACTED]', $s);
            }
        }
        $s = (string)preg_replace('/(api[_-]?key|apikey|token|secret|password|authorization)=([^&\s"\']+)/i', '$1=[REDACTED]', $s);
        $s = (string)preg_replace('/("(?:api[_-]?key|apikey|token|secret|password)"\s*:\s*")([^"]+)(")/i', '$1[REDACTED]$3', $s);
        $s = (string)preg_replace('/(Bearer\s+)([A-Za-z0-9._\-]+)/i', '$1[REDACTED]', $s);
        return $s;
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
        int $responseTimeMs,
        $requestPayload,
        string $responsePayload,
        string $status,
        ?string $errorMessage = null,
        ?string $secretToRedact = null
    ): int {
        try {
            $reqStr = is_array($requestPayload) ? json_encode($requestPayload) : (string)$requestPayload;
            $safeEndpoint = substr(self::redactString($endpoint, $secretToRedact), 0, 255);
            $safeReq = substr(self::redactString($reqStr, $secretToRedact), 0, 8000);
            $safeResp = substr(self::redactString($responsePayload, $secretToRedact), 0, 12000);
            $safeErr = $errorMessage !== null ? substr(self::redactString($errorMessage, $secretToRedact), 0, 255) : null;

            $logId = (int)Database::insert(
                "INSERT INTO otp_provider_logs (provider_id, activation_id, action, endpoint, http_code, response_time_ms, request_payload, response_payload, status, error_message)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $providerId > 0 ? $providerId : null,
                    $activationId,
                    substr($action, 0, 60),
                    $safeEndpoint,
                    $httpCode ?: null,
                    $responseTimeMs >= 0 ? $responseTimeMs : null,
                    $safeReq !== '' ? $safeReq : null,
                    $safeResp,
                    $status === 'ok' ? 'ok' : 'error',
                    $safeErr,
                ]
            );
            if ($providerId > 0 && $status === 'error' && $safeErr) {
                Database::execute("UPDATE otp_providers SET last_error = ? WHERE id = ?", [substr($safeErr, 0, 500), $providerId]);
            }
            return $logId;
        } catch (Throwable $e) {
            Logger::errorFile('OtpHttpClient::logCall error: ' . $e->getMessage());
            return 0;
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
 * Standard 5SIM API1 / SMS-Activate Protocol Reference Dictionary
 * Used ONLY to supply readable display names, ISO codes, flags, and icons for country/service IDs
 * that are ACTUALLY returned by the live provider getPrices / getNumbersStatus responses.
 * Never fabricates or injects services or countries not present in live API output.
 */
class FiveSimCatalogReference
{
    public const COUNTRIES = [
        '0'   => ['name' => 'Russia',         'iso' => 'RU', 'dial' => '+7',   'flag' => '🇷🇺'],
        '1'   => ['name' => 'Ukraine',        'iso' => 'UA', 'dial' => '+380', 'flag' => '🇺🇦'],
        '2'   => ['name' => 'Kazakhstan',     'iso' => 'KZ', 'dial' => '+7',   'flag' => '🇰🇿'],
        '3'   => ['name' => 'China',          'iso' => 'CN', 'dial' => '+86',  'flag' => '🇨🇳'],
        '4'   => ['name' => 'Philippines',    'iso' => 'PH', 'dial' => '+63',  'flag' => '🇵🇭'],
        '5'   => ['name' => 'Myanmar',        'iso' => 'MM', 'dial' => '+95',  'flag' => '🇲🇲'],
        '6'   => ['name' => 'Indonesia',      'iso' => 'ID', 'dial' => '+62',  'flag' => '🇮🇩'],
        '7'   => ['name' => 'Malaysia',       'iso' => 'MY', 'dial' => '+60',  'flag' => '🇲🇾'],
        '8'   => ['name' => 'Kenya',          'iso' => 'KE', 'dial' => '+254', 'flag' => '🇰🇪'],
        '9'   => ['name' => 'Tanzania',       'iso' => 'TZ', 'dial' => '+255', 'flag' => '🇹🇿'],
        '10'  => ['name' => 'Vietnam',        'iso' => 'VN', 'dial' => '+84',  'flag' => '🇻🇳'],
        '11'  => ['name' => 'Kyrgyzstan',     'iso' => 'KG', 'dial' => '+996', 'flag' => '🇰🇬'],
        '12'  => ['name' => 'USA (Virtual)',  'iso' => 'USV','dial' => '+1',   'flag' => '🇺🇸'],
        '13'  => ['name' => 'Israel',         'iso' => 'IL', 'dial' => '+972', 'flag' => '🇮🇱'],
        '14'  => ['name' => 'Hong Kong',      'iso' => 'HK', 'dial' => '+852', 'flag' => '🇭🇰'],
        '15'  => ['name' => 'Poland',         'iso' => 'PL', 'dial' => '+48',  'flag' => '🇵🇱'],
        '16'  => ['name' => 'United Kingdom', 'iso' => 'GB', 'dial' => '+44',  'flag' => '🇬🇧'],
        '19'  => ['name' => 'Nigeria',        'iso' => 'NG', 'dial' => '+234', 'flag' => '🇳🇬'],
        '21'  => ['name' => 'Egypt',          'iso' => 'EG', 'dial' => '+20',  'flag' => '🇪🇬'],
        '22'  => ['name' => 'India',          'iso' => 'IN', 'dial' => '+91',  'flag' => '🇮🇳'],
        '23'  => ['name' => 'Ireland',        'iso' => 'IE', 'dial' => '+353', 'flag' => '🇮🇪'],
        '24'  => ['name' => 'Cambodia',       'iso' => 'KH', 'dial' => '+855', 'flag' => '🇰🇭'],
        '31'  => ['name' => 'South Africa',   'iso' => 'ZA', 'dial' => '+27',  'flag' => '🇿🇦'],
        '32'  => ['name' => 'Romania',        'iso' => 'RO', 'dial' => '+40',  'flag' => '🇷🇴'],
        '33'  => ['name' => 'Colombia',       'iso' => 'CO', 'dial' => '+57',  'flag' => '🇨🇴'],
        '34'  => ['name' => 'Estonia',        'iso' => 'EE', 'dial' => '+372', 'flag' => '🇪🇪'],
        '36'  => ['name' => 'Canada',         'iso' => 'CA', 'dial' => '+1',   'flag' => '🇨🇦'],
        '37'  => ['name' => 'Morocco',        'iso' => 'MA', 'dial' => '+212', 'flag' => '🇲🇦'],
        '39'  => ['name' => 'Argentina',      'iso' => 'AR', 'dial' => '+54',  'flag' => '🇦🇷'],
        '40'  => ['name' => 'Uzbekistan',     'iso' => 'UZ', 'dial' => '+998', 'flag' => '🇺🇿'],
        '43'  => ['name' => 'Germany',        'iso' => 'DE', 'dial' => '+49',  'flag' => '🇩🇪'],
        '44'  => ['name' => 'Lithuania',      'iso' => 'LT', 'dial' => '+370', 'flag' => '🇱🇹'],
        '46'  => ['name' => 'Sweden',         'iso' => 'SE', 'dial' => '+46',  'flag' => '🇸🇪'],
        '48'  => ['name' => 'Netherlands',    'iso' => 'NL', 'dial' => '+31',  'flag' => '🇳🇱'],
        '49'  => ['name' => 'Latvia',         'iso' => 'LV', 'dial' => '+371', 'flag' => '🇱🇻'],
        '50'  => ['name' => 'Austria',        'iso' => 'AT', 'dial' => '+43',  'flag' => '🇦🇹'],
        '52'  => ['name' => 'Thailand',       'iso' => 'TH', 'dial' => '+66',  'flag' => '🇹🇭'],
        '53'  => ['name' => 'Saudi Arabia',   'iso' => 'SA', 'dial' => '+966', 'flag' => '🇸🇦'],
        '54'  => ['name' => 'Mexico',         'iso' => 'MX', 'dial' => '+52',  'flag' => '🇲🇽'],
        '55'  => ['name' => 'Taiwan',         'iso' => 'TW', 'dial' => '+886', 'flag' => '🇹🇼'],
        '56'  => ['name' => 'Spain',          'iso' => 'ES', 'dial' => '+34',  'flag' => '🇪🇸'],
        '58'  => ['name' => 'Algeria',        'iso' => 'DZ', 'dial' => '+213', 'flag' => '🇩🇿'],
        '60'  => ['name' => 'Bangladesh',     'iso' => 'BD', 'dial' => '+880', 'flag' => '🇧🇩'],
        '62'  => ['name' => 'Turkey',         'iso' => 'TR', 'dial' => '+90',  'flag' => '🇹🇷'],
        '63'  => ['name' => 'Czech Republic', 'iso' => 'CZ', 'dial' => '+420', 'flag' => '🇨🇿'],
        '64'  => ['name' => 'Sri Lanka',      'iso' => 'LK', 'dial' => '+94',  'flag' => '🇱🇰'],
        '65'  => ['name' => 'Peru',           'iso' => 'PE', 'dial' => '+51',  'flag' => '🇵🇪'],
        '66'  => ['name' => 'Pakistan',       'iso' => 'PK', 'dial' => '+92',  'flag' => '🇵🇰'],
        '67'  => ['name' => 'New Zealand',    'iso' => 'NZ', 'dial' => '+64',  'flag' => '🇳🇿'],
        '73'  => ['name' => 'Brazil',         'iso' => 'BR', 'dial' => '+55',  'flag' => '🇧🇷'],
        '78'  => ['name' => 'France',         'iso' => 'FR', 'dial' => '+33',  'flag' => '🇫🇷'],
        '82'  => ['name' => 'Belgium',        'iso' => 'BE', 'dial' => '+32',  'flag' => '🇧🇪'],
        '83'  => ['name' => 'Bulgaria',       'iso' => 'BG', 'dial' => '+359', 'flag' => '🇧🇬'],
        '84'  => ['name' => 'Hungary',        'iso' => 'HU', 'dial' => '+36',  'flag' => '🇭🇺'],
        '86'  => ['name' => 'Italy',          'iso' => 'IT', 'dial' => '+39',  'flag' => '🇮🇹'],
        '95'  => ['name' => 'UAE',            'iso' => 'AE', 'dial' => '+971', 'flag' => '🇦🇪'],
        '117' => ['name' => 'Portugal',       'iso' => 'PT', 'dial' => '+351', 'flag' => '🇵🇹'],
        '129' => ['name' => 'Greece',         'iso' => 'GR', 'dial' => '+30',  'flag' => '🇬🇷'],
        '151' => ['name' => 'Chile',          'iso' => 'CL', 'dial' => '+56',  'flag' => '🇨🇱'],
        '172' => ['name' => 'Denmark',        'iso' => 'DK', 'dial' => '+45',  'flag' => '🇩🇰'],
        '173' => ['name' => 'Switzerland',    'iso' => 'CH', 'dial' => '+41',  'flag' => '🇨🇭'],
        '174' => ['name' => 'Norway',         'iso' => 'NO', 'dial' => '+47',  'flag' => '🇳🇴'],
        '175' => ['name' => 'Australia',      'iso' => 'AU', 'dial' => '+61',  'flag' => '🇦🇺'],
        '182' => ['name' => 'Japan',          'iso' => 'JP', 'dial' => '+81',  'flag' => '🇯🇵'],
        '187' => ['name' => 'United States',  'iso' => 'US', 'dial' => '+1',   'flag' => '🇺🇸'],
        '190' => ['name' => 'South Korea',    'iso' => 'KR', 'dial' => '+82',  'flag' => '🇰🇷'],
        '196' => ['name' => 'Singapore',      'iso' => 'SG', 'dial' => '+65',  'flag' => '🇸🇬'],
        // Also support slug country identifiers if returned by API
        'russia'      => ['name' => 'Russia',         'iso' => 'RU', 'dial' => '+7',   'flag' => '🇷🇺'],
        'india'       => ['name' => 'India',          'iso' => 'IN', 'dial' => '+91',  'flag' => '🇮🇳'],
        'usa'         => ['name' => 'United States',  'iso' => 'US', 'dial' => '+1',   'flag' => '🇺🇸'],
        'england'     => ['name' => 'United Kingdom', 'iso' => 'GB', 'dial' => '+44',  'flag' => '🇬🇧'],
        'uk'          => ['name' => 'United Kingdom', 'iso' => 'GB', 'dial' => '+44',  'flag' => '🇬🇧'],
        'indonesia'   => ['name' => 'Indonesia',      'iso' => 'ID', 'dial' => '+62',  'flag' => '🇮🇩'],
        'philippines' => ['name' => 'Philippines',    'iso' => 'PH', 'dial' => '+63',  'flag' => '🇵🇭'],
        'vietnam'     => ['name' => 'Vietnam',        'iso' => 'VN', 'dial' => '+84',  'flag' => '🇻🇳'],
        'germany'     => ['name' => 'Germany',        'iso' => 'DE', 'dial' => '+49',  'flag' => '🇩🇪'],
        'france'      => ['name' => 'France',         'iso' => 'FR', 'dial' => '+33',  'flag' => '🇫🇷'],
        'brazil'      => ['name' => 'Brazil',         'iso' => 'BR', 'dial' => '+55',  'flag' => '🇧🇷'],
        'canada'      => ['name' => 'Canada',         'iso' => 'CA', 'dial' => '+1',   'flag' => '🇨🇦'],
        'netherlands' => ['name' => 'Netherlands',    'iso' => 'NL', 'dial' => '+31',  'flag' => '🇳🇱'],
        'poland'      => ['name' => 'Poland',         'iso' => 'PL', 'dial' => '+48',  'flag' => '🇵🇱'],
        'turkey'      => ['name' => 'Turkey',         'iso' => 'TR', 'dial' => '+90',  'flag' => '🇹🇷'],
    ];

    public const SERVICES = [
        'wa'        => ['name' => 'WhatsApp',          'icon' => 'whatsapp',  'popular' => 1],
        'whatsapp'  => ['name' => 'WhatsApp',          'icon' => 'whatsapp',  'popular' => 1],
        'tg'        => ['name' => 'Telegram',          'icon' => 'telegram',  'popular' => 1],
        'telegram'  => ['name' => 'Telegram',          'icon' => 'telegram',  'popular' => 1],
        'ig'        => ['name' => 'Instagram',         'icon' => 'instagram', 'popular' => 1],
        'instagram' => ['name' => 'Instagram',         'icon' => 'instagram', 'popular' => 1],
        'go'        => ['name' => 'Google / YouTube',  'icon' => 'google',    'popular' => 1],
        'google'    => ['name' => 'Google / YouTube',  'icon' => 'google',    'popular' => 1],
        'fb'        => ['name' => 'Facebook',          'icon' => 'facebook',  'popular' => 1],
        'facebook'  => ['name' => 'Facebook',          'icon' => 'facebook',  'popular' => 1],
        'tw'        => ['name' => 'Twitter / X',       'icon' => 'twitter',   'popular' => 1],
        'twitter'   => ['name' => 'Twitter / X',       'icon' => 'twitter',   'popular' => 1],
        'lf'        => ['name' => 'TikTok',            'icon' => 'tiktok',    'popular' => 1],
        'tiktok'    => ['name' => 'TikTok',            'icon' => 'tiktok',    'popular' => 1],
        'ds'        => ['name' => 'Discord',           'icon' => 'discord',   'popular' => 1],
        'discord'   => ['name' => 'Discord',           'icon' => 'discord',   'popular' => 1],
        'am'        => ['name' => 'Amazon',            'icon' => 'amazon',    'popular' => 1],
        'amazon'    => ['name' => 'Amazon',            'icon' => 'amazon',    'popular' => 1],
        'wx'        => ['name' => 'Apple',             'icon' => 'apple',     'popular' => 0],
        'apple'     => ['name' => 'Apple',             'icon' => 'apple',     'popular' => 0],
        'mm'        => ['name' => 'Microsoft',         'icon' => 'microsoft', 'popular' => 0],
        'microsoft' => ['name' => 'Microsoft',         'icon' => 'microsoft', 'popular' => 0],
        'ub'        => ['name' => 'Uber',              'icon' => 'uber',      'popular' => 0],
        'uber'      => ['name' => 'Uber',              'icon' => 'uber',      'popular' => 0],
        'fu'        => ['name' => 'Snapchat',          'icon' => 'snapchat',  'popular' => 0],
        'snapchat'  => ['name' => 'Snapchat',          'icon' => 'snapchat',  'popular' => 0],
        'vi'        => ['name' => 'Viber',             'icon' => 'general',   'popular' => 0],
        'oi'        => ['name' => 'Tinder',            'icon' => 'general',   'popular' => 0],
        'mb'        => ['name' => 'Yahoo',             'icon' => 'general',   'popular' => 0],
        'vk'        => ['name' => 'VKontakte',         'icon' => 'general',   'popular' => 0],
        'ok'        => ['name' => 'Odnoklassniki',     'icon' => 'general',   'popular' => 0],
        'ya'        => ['name' => 'Yandex',            'icon' => 'general',   'popular' => 0],
        'dr'        => ['name' => 'OpenAI / ChatGPT',  'icon' => 'general',   'popular' => 1],
        'openai'    => ['name' => 'OpenAI / ChatGPT',  'icon' => 'general',   'popular' => 1],
        'tn'        => ['name' => 'LinkedIn',          'icon' => 'general',   'popular' => 0],
        'nf'        => ['name' => 'Netflix',           'icon' => 'general',   'popular' => 0],
        'pp'        => ['name' => 'PayPal',            'icon' => 'general',   'popular' => 0],
        'ka'        => ['name' => 'Shopee',            'icon' => 'general',   'popular' => 0],
        'hw'        => ['name' => 'Alipay',            'icon' => 'general',   'popular' => 0],
        'me'        => ['name' => 'Line Messenger',    'icon' => 'general',   'popular' => 0],
        'ot'        => ['name' => 'Other / Any App',   'icon' => 'general',   'popular' => 0],
    ];

    public static function describeCountry(string $providerCountryCode): array
    {
        $key = strtolower(trim($providerCountryCode));
        if (isset(self::COUNTRIES[$key])) {
            $info = self::COUNTRIES[$key];
            return [
                'code'          => $providerCountryCode,
                'provider_code' => $providerCountryCode,
                'name'          => $info['name'],
                'iso_code'      => $info['iso'],
                'dial_code'     => $info['dial'],
                'flag_emoji'    => $info['flag'],
            ];
        }
        $cleanIso = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $providerCountryCode));
        if ($cleanIso === '') {
            $cleanIso = 'UNK';
        }
        $iso = ctype_digit($cleanIso) ? ('C' . substr($cleanIso, 0, 9)) : substr($cleanIso, 0, 10);
        return [
            'code'          => $providerCountryCode,
            'provider_code' => $providerCountryCode,
            'name'          => ctype_digit($providerCountryCode) ? ('Country #' . $providerCountryCode) : ucwords(str_replace(['_', '-'], ' ', $providerCountryCode)),
            'iso_code'      => $iso,
            'dial_code'     => '',
            'flag_emoji'    => '🌐',
        ];
    }

    public static function describeService(string $providerServiceCode): array
    {
        $key = strtolower(trim($providerServiceCode));
        if (isset(self::SERVICES[$key])) {
            $info = self::SERVICES[$key];
            return [
                'provider_code' => $providerServiceCode,
                'name'          => $info['name'],
                'slug'          => self::makeSlug($info['name'], $providerServiceCode),
                'icon_key'      => $info['icon'],
                'is_popular'    => (int)$info['popular'],
            ];
        }
        $label = ucwords(str_replace(['_', '-'], ' ', $providerServiceCode));
        return [
            'provider_code' => $providerServiceCode,
            'name'          => strlen($providerServiceCode) <= 3 ? ('Service ' . strtoupper($providerServiceCode)) : $label,
            'slug'          => self::makeSlug($providerServiceCode, $providerServiceCode),
            'icon_key'      => 'general',
            'is_popular'    => 0,
        ];
    }

    public static function makeSlug(string $name, string $fallbackCode): string
    {
        $s = strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($name)));
        $s = trim($s, '_');
        if ($s === '') {
            $s = 'svc_' . strtolower(preg_replace('/[^a-z0-9]+/i', '', $fallbackCode));
        }
        return substr($s, 0, 90);
    }
}

/**
 * Built-in 5SIM API1 Adapter
 * Exact Base URL: http://api1.5sim.net/stubs/handler_api.php
 * Exact Supported Actions (GET / POST, Plain-Text + JSON parsing):
 *  - getBalance:       ?action=getBalance&api_key=API_KEY                      -> ACCESS_BALANCE:<amount>
 *  - getNumbersStatus: ?action=getNumbersStatus&api_key=API_KEY&country=COUNTRY -> JSON {"wa_0": 150, ...}
 *  - getNumber:        ?action=getNumber&api_key=API_KEY&service=S&operator=O&country=C -> ACCESS_NUMBER:<id>:<phone>
 *  - setStatus:        ?action=setStatus&api_key=API_KEY&status=STATUS&id=ID    -> ACCESS_READY / ACCESS_RETRY_GET / ACCESS_ACTIVATION / ACCESS_CANCEL
 *  - getStatus:        ?action=getStatus&api_key=API_KEY&id=ID                  -> STATUS_WAIT_CODE / STATUS_WAIT_RETRY:... / STATUS_CANCEL / STATUS_OK:<code>
 *  - getPrices:        ?action=getPrices&api_key=API_KEY[&country=C][&service=S] -> JSON {"<country>":{"<service>":{"cost":...,"count":...}}}
 */
class FiveSimAdapter implements OtpProviderInterface
{
    public const DEFAULT_BASE_URL = 'http://api1.5sim.net/stubs/handler_api.php';

    private array $provider;
    private string $baseUrl;
    private string $apiKey;
    private string $requestMethod;

    public function __construct(array $provider)
    {
        $this->provider = $provider;
        $rawUrl = trim((string)($provider['base_url'] ?? ''));
        $this->baseUrl = $rawUrl !== '' ? $rawUrl : self::DEFAULT_BASE_URL;
        $this->apiKey = Helpers::decryptSecret((string)($provider['api_key_enc'] ?? ''));
        $this->requestMethod = strtoupper((string)($provider['request_method'] ?? 'GET')) === 'POST' ? 'POST' : 'GET';
    }

    /**
     * Dispatch a server-side request to the configured 5SIM handler_api.php URL.
     */
    private function callHandler(string $action, array $params = [], ?int $activationId = null): array
    {
        if ($this->apiKey === '') {
            return [
                'ok'        => false,
                'uncertain' => false,
                'http_code' => 0,
                'log_id'    => 0,
                'error'     => '5SIM API key is not configured.',
                'raw'       => '',
                'data'      => null,
            ];
        }

        $query = array_merge(['action' => $action, 'api_key' => $this->apiKey], $params);

        if ($this->requestMethod === 'POST') {
            return OtpHttpClient::request(
                $this->provider,
                $action,
                'POST',
                $this->baseUrl,
                ['Accept: application/json, text/plain, */*'],
                $query,
                $activationId,
                $this->apiKey
            );
        }

        $sep = str_contains($this->baseUrl, '?') ? '&' : '?';
        $url = $this->baseUrl . $sep . http_build_query($query);
        return OtpHttpClient::request(
            $this->provider,
            $action,
            'GET',
            $url,
            ['Accept: application/json, text/plain, */*'],
            null,
            $activationId,
            $this->apiKey
        );
    }

    /**
     * Map well-known 5SIM handler_api.php plain-text error tokens to clear messages.
     */
    private function detectPlainTextError(string $raw): ?string
    {
        $txt = trim($raw);
        if ($txt === '') {
            return '5SIM API returned an empty response.';
        }
        // Reject HTML error pages (e.g. Cloudflare / 502 / wrong URL)
        if (str_starts_with(strtolower($txt), '<!doctype') || str_starts_with(strtolower($txt), '<html')) {
            return '5SIM API returned HTML instead of an API response. Verify the endpoint URL.';
        }
        $upper = strtoupper($txt);
        if (str_starts_with($upper, 'BANNED:')) {
            return 'Account temporarily restricted by 5SIM: ' . $txt;
        }
        $known = [
            'BAD_KEY'             => 'Invalid 5SIM API key (BAD_KEY).',
            'BAD_ACTION'          => 'Invalid API action parameter (BAD_ACTION).',
            'BAD_SERVICE'         => 'Invalid or unsupported service identifier (BAD_SERVICE).',
            'BAD_STATUS'          => 'Invalid activation status code (BAD_STATUS).',
            'BAD_COUNTRY'         => 'Invalid or unsupported country identifier (BAD_COUNTRY).',
            'WRONG_OPERATOR'      => 'Invalid operator identifier (WRONG_OPERATOR).',
            'NO_KEY'              => 'API key parameter missing (NO_KEY).',
            'NO_BALANCE'          => 'Insufficient balance on 5SIM account (NO_BALANCE).',
            'NO_NUMBERS'          => 'No numbers currently available for this service and country (NO_NUMBERS).',
            'NO_ACTIVATION'       => 'Activation ID not found on 5SIM (NO_ACTIVATION).',
            'ERROR_SQL'           => 'Upstream 5SIM database error (ERROR_SQL).',
            'EARLY_CANCEL_DENIED' => 'Cancellation is not allowed yet (EARLY_CANCEL_DENIED).',
            'WRONG_EXCEPTION_PHONE' => 'Unsupported phone prefix exclusion (WRONG_EXCEPTION_PHONE).',
            'NO_BALANCE_FORWARD'  => 'Insufficient balance for call forwarding (NO_BALANCE_FORWARD).',
        ];
        if (isset($known[$upper])) {
            return $known[$upper];
        }
        if (str_starts_with($upper, 'ERROR') || str_starts_with($upper, 'BAD_') || str_starts_with($upper, 'WRONG_')) {
            return '5SIM API error: ' . $txt;
        }
        return null;
    }

    private function failWithLog(array $res, string $error): array
    {
        OtpHttpClient::markLogError((int)($res['log_id'] ?? 0), (int)($this->provider['id'] ?? 0), $error, $this->apiKey);
        return [
            'ok'               => false,
            'uncertain'        => !empty($res['uncertain']),
            'http_code'        => (int)($res['http_code'] ?? 0),
            'response_time_ms' => (int)($res['response_time_ms'] ?? 0),
            'error'            => $error,
            'raw'              => (string)($res['raw'] ?? ''),
        ];
    }

    /**
     * Real connection test: invokes getBalance and strictly requires ACCESS_BALANCE:<numeric>.
     */
    public function testConnection(): array
    {
        return $this->getBalance();
    }

    /**
     * A. Balance Check: ?action=getBalance&api_key=API_KEY -> ACCESS_BALANCE:<amount>
     */
    public function getBalance(): array
    {
        $res = $this->callHandler('getBalance');
        if (!$res['ok']) {
            return [
                'ok'               => false,
                'http_code'        => (int)($res['http_code'] ?? 0),
                'response_time_ms' => (int)($res['response_time_ms'] ?? 0),
                'error'            => $res['error'] ?: 'Unable to connect to 5SIM getBalance.',
                'raw'              => (string)($res['raw'] ?? ''),
            ];
        }

        $raw = trim((string)$res['raw']);
        if (preg_match('/^ACCESS_BALANCE:([+-]?\d+(?:\.\d+)?)$/i', $raw, $m)) {
            return [
                'ok'               => true,
                'balance'          => Currency::normalize($m[1], 4),
                'currency'         => (string)($this->provider['currency_code'] ?: 'RUB'),
                'http_code'        => (int)$res['http_code'],
                'response_time_ms' => (int)$res['response_time_ms'],
                'raw'              => $raw,
            ];
        }

        $plainErr = $this->detectPlainTextError($raw)
            ?? ('Unexpected getBalance response from 5SIM: ' . substr(strip_tags($raw), 0, 120));
        return $this->failWithLog($res, $plainErr);
    }

    /**
     * B. Available Numbers Status: ?action=getNumbersStatus&api_key=API_KEY&country=COUNTRY[&operator=OPERATOR]
     * Response: JSON {"wa_0":"150","tg_0":84,...}
     */
    public function getNumbersStatus(?string $countryCode = null, ?string $operator = null): array
    {
        $params = [];
        if ($countryCode !== null && trim($countryCode) !== '') {
            $params['country'] = trim($countryCode);
        }
        if ($operator !== null && trim($operator) !== '' && strtolower(trim($operator)) !== 'any') {
            $params['operator'] = trim($operator);
        }

        $res = $this->callHandler('getNumbersStatus', $params);
        if (!$res['ok']) {
            return [
                'ok'               => false,
                'http_code'        => (int)($res['http_code'] ?? 0),
                'response_time_ms' => (int)($res['response_time_ms'] ?? 0),
                'error'            => $res['error'] ?: 'Unable to fetch numbers availability from 5SIM.',
                'counts'           => [],
            ];
        }

        $raw = trim((string)$res['raw']);
        if (!is_array($res['data'])) {
            $plainErr = $this->detectPlainTextError($raw)
                ?? ('Unexpected getNumbersStatus response (expected JSON map): ' . substr(strip_tags($raw), 0, 120));
            return array_merge($this->failWithLog($res, $plainErr), ['counts' => []]);
        }

        $counts = [];
        foreach ($res['data'] as $rawKey => $rawVal) {
            if (!is_scalar($rawVal)) {
                continue;
            }
            $keyStr = (string)$rawKey;
            $cnt = max(0, (int)$rawVal);
            // Strip standard _0 (no forwarding) or _1 (with forwarding) suffixes
            $baseSvc = preg_replace('/_[01]$/', '', $keyStr);
            if ($baseSvc === null || $baseSvc === '') {
                $baseSvc = $keyStr;
            }
            // Prefer standard _0 count when both _0 and _1 appear
            if (str_ends_with($keyStr, '_0') || !isset($counts[$baseSvc])) {
                $counts[$baseSvc] = $cnt;
            }
        }

        return [
            'ok'               => true,
            'country'          => $countryCode,
            'counts'           => $counts,
            'http_code'        => (int)$res['http_code'],
            'response_time_ms' => (int)$res['response_time_ms'],
            'raw'              => $res['data'],
        ];
    }

    /**
     * F. Get Prices: ?action=getPrices&api_key=API_KEY[&country=COUNTRY][&service=SERVICE]
     * Parses actual nested JSON returned by the API without fabricating countries or services.
     * Supports:
     *  - All countries/services: { "<country_id>": { "<service_code>": { "cost": 15, "count": 100 } } }
     *  - Operator-nested variants: { "<country_id>": { "<service_code>": { "<operator>": { "cost": 15, "count": 100 } } } }
     *  - Country-only filtered responses where top-level is country or service map.
     */
    public function getPrices(?string $countryCode = null, ?string $serviceCode = null): array
    {
        $params = [];
        if ($countryCode !== null && trim($countryCode) !== '') {
            $params['country'] = trim($countryCode);
        }
        if ($serviceCode !== null && trim($serviceCode) !== '') {
            $params['service'] = trim($serviceCode);
        }

        $res = $this->callHandler('getPrices', $params);
        if (!$res['ok']) {
            return [
                'ok'               => false,
                'http_code'        => (int)($res['http_code'] ?? 0),
                'response_time_ms' => (int)($res['response_time_ms'] ?? 0),
                'error'            => $res['error'] ?: 'Unable to fetch prices from 5SIM.',
                'items'            => [],
                'countries'        => [],
            ];
        }

        $raw = trim((string)$res['raw']);
        if (!is_array($res['data'])) {
            $plainErr = $this->detectPlainTextError($raw)
                ?? ('Unexpected getPrices response (expected JSON): ' . substr(strip_tags($raw), 0, 120));
            return array_merge($this->failWithLog($res, $plainErr), ['items' => [], 'countries' => []]);
        }

        $data = $res['data'];
        $currency = (string)($this->provider['currency_code'] ?: 'RUB');
        $items = [];
        $countriesMap = [];

        // Helper to normalize a price leaf node
        $extractCostCount = static function ($node): ?array {
            if (!is_array($node)) {
                return null;
            }
            if (array_key_exists('cost', $node) || array_key_exists('price', $node)) {
                $c = $node['cost'] ?? $node['price'] ?? null;
                $cnt = isset($node['count']) ? max(0, (int)$node['count']) : (isset($node['quantity']) ? max(0, (int)$node['quantity']) : null);
                if ($c !== null && is_numeric((string)$c)) {
                    return ['cost' => Currency::normalize((string)$c, 4), 'count' => $cnt, 'operator' => 'any'];
                }
            }
            // Check if node is a map of operators -> {cost, count}
            $bestCost = null;
            $bestOp = 'any';
            $totalCnt = 0;
            $foundAny = false;
            foreach ($node as $opKey => $opVal) {
                if (!is_array($opVal)) {
                    continue;
                }
                if (array_key_exists('cost', $opVal) || array_key_exists('price', $opVal)) {
                    $c = $opVal['cost'] ?? $opVal['price'] ?? null;
                    $cnt = max(0, (int)($opVal['count'] ?? 0));
                    if ($c !== null && is_numeric((string)$c)) {
                        $foundAny = true;
                        $totalCnt += $cnt;
                        if ($bestCost === null || ($cnt > 0 && (float)$c < (float)$bestCost)) {
                            $bestCost = Currency::normalize((string)$c, 4);
                            $bestOp = (string)$opKey;
                        }
                    }
                }
            }
            if ($foundAny && $bestCost !== null) {
                return ['cost' => $bestCost, 'count' => $totalCnt, 'operator' => $bestOp];
            }
            return null;
        };

        // Handle flat root-level single-pair response { "cost": ..., "count": ... } when both country & service were requested
        if ((array_key_exists('cost', $data) || array_key_exists('price', $data))
            && $countryCode !== null && trim($countryCode) !== ''
            && $serviceCode !== null && trim($serviceCode) !== ''
        ) {
            $rootLeaf = $extractCostCount($data);
            if ($rootLeaf !== null) {
                $cCode = trim($countryCode);
                $sCode = trim($serviceCode);
                $cInfo = FiveSimCatalogReference::describeCountry($cCode);
                $sInfo = FiveSimCatalogReference::describeService($sCode);
                $countriesMap[$cCode] = $cInfo;
                $items[] = [
                    'country_code' => $cCode,
                    'country_name' => $cInfo['name'],
                    'country_iso'  => $cInfo['iso_code'],
                    'country_dial' => $cInfo['dial_code'],
                    'country_flag' => $cInfo['flag_emoji'],
                    'service_code' => $sCode,
                    'service_name' => $sInfo['name'],
                    'service_slug' => $sInfo['slug'],
                    'icon_key'     => $sInfo['icon_key'],
                    'is_popular'   => $sInfo['is_popular'],
                    'operator'     => $rootLeaf['operator'],
                    'cost'         => $rootLeaf['cost'],
                    'count'        => $rootLeaf['count'],
                    'currency'     => $currency,
                ];
                return [
                    'ok'               => true,
                    'items'            => $items,
                    'countries'        => array_values($countriesMap),
                    'currency'         => $currency,
                    'http_code'        => (int)$res['http_code'],
                    'response_time_ms' => (int)$res['response_time_ms'],
                ];
            }
        }

        $trimmedCountryFilter = $countryCode !== null ? trim($countryCode) : '';

        foreach ($data as $topKey => $topVal) {
            if (!is_array($topVal)) {
                continue;
            }
            // Case 1: Flat single-country response { "<service>": { "cost": ..., "count": ... } }
            // Only match when topKey is NOT the requested country code itself AND topVal directly holds cost/price keys.
            $isDirectCostLeaf = array_key_exists('cost', $topVal) || array_key_exists('price', $topVal);
            if ($isDirectCostLeaf && $trimmedCountryFilter !== '' && strcasecmp((string)$topKey, $trimmedCountryFilter) !== 0) {
                $directLeaf = $extractCostCount($topVal);
                if ($directLeaf !== null) {
                    $cCode = $trimmedCountryFilter;
                    $sCode = (string)$topKey;
                    $cInfo = FiveSimCatalogReference::describeCountry($cCode);
                    $sInfo = FiveSimCatalogReference::describeService($sCode);
                    $countriesMap[$cCode] = $cInfo;
                    $items[] = [
                        'country_code' => $cCode,
                        'country_name' => $cInfo['name'],
                        'country_iso'  => $cInfo['iso_code'],
                        'country_dial' => $cInfo['dial_code'],
                        'country_flag' => $cInfo['flag_emoji'],
                        'service_code' => $sCode,
                        'service_name' => $sInfo['name'],
                        'service_slug' => $sInfo['slug'],
                        'icon_key'     => $sInfo['icon_key'],
                        'is_popular'   => $sInfo['is_popular'],
                        'operator'     => $directLeaf['operator'],
                        'cost'         => $directLeaf['cost'],
                        'count'        => $directLeaf['count'],
                        'currency'     => $currency,
                    ];
                    continue;
                }
            }

            $cCode = (string)$topKey;
            $cInfo = FiveSimCatalogReference::describeCountry($cCode);
            foreach ($topVal as $svcKey => $svcVal) {
                $leaf = $extractCostCount($svcVal);
                if ($leaf === null) {
                    continue;
                }
                $sCode = (string)$svcKey;
                $sInfo = FiveSimCatalogReference::describeService($sCode);
                $countriesMap[$cCode] = $cInfo;
                $items[] = [
                    'country_code' => $cCode,
                    'country_name' => $cInfo['name'],
                    'country_iso'  => $cInfo['iso_code'],
                    'country_dial' => $cInfo['dial_code'],
                    'country_flag' => $cInfo['flag_emoji'],
                    'service_code' => $sCode,
                    'service_name' => $sInfo['name'],
                    'service_slug' => $sInfo['slug'],
                    'icon_key'     => $sInfo['icon_key'],
                    'is_popular'   => $sInfo['is_popular'],
                    'operator'     => $leaf['operator'],
                    'cost'         => $leaf['cost'],
                    'count'        => $leaf['count'],
                    'currency'     => $currency,
                ];
            }
        }

        return [
            'ok'               => true,
            'items'            => $items,
            'countries'        => array_values($countriesMap),
            'currency'         => $currency,
            'http_code'        => (int)$res['http_code'],
            'response_time_ms' => (int)$res['response_time_ms'],
        ];
    }

    /**
     * Discover countries present in the live getPrices response (never invents countries).
     */
    public function getCountries(): array
    {
        $prices = $this->getPrices();
        if (empty($prices['ok'])) {
            return ['ok' => false, 'error' => $prices['error'] ?? 'Could not discover countries from 5SIM getPrices.'];
        }
        return ['ok' => true, 'countries' => $prices['countries']];
    }

    /**
     * Live availability & price verification for a single country + service before purchase.
     * Queries getPrices (for exact cost) and getNumbersStatus (for verified real-time availability).
     */
    public function getAvailabilityAndPrice(string $countryCode, string $serviceCode, string $operator = 'any'): array
    {
        $c = trim($countryCode);
        $s = trim($serviceCode);
        $op = trim($operator) ?: 'any';

        $priceRes = $this->getPrices($c, $s);
        if (empty($priceRes['ok'])) {
            return [
                'ok'        => false,
                'available' => false,
                'count'     => 0,
                'cost'      => null,
                'error'     => $priceRes['error'] ?? 'Unable to fetch 5SIM pricing.',
            ];
        }

        $matched = null;
        foreach ($priceRes['items'] as $it) {
            if (strcasecmp((string)$it['country_code'], $c) === 0 && strcasecmp((string)$it['service_code'], $s) === 0) {
                $matched = $it;
                break;
            }
        }
        if ($matched === null && count($priceRes['items']) === 1) {
            $matched = $priceRes['items'][0];
        }

        $cost = $matched['cost'] ?? null;
        $count = isset($matched['count']) && $matched['count'] !== null ? (int)$matched['count'] : 0;

        // Verify live stock via getNumbersStatus
        $statusRes = $this->getNumbersStatus($c, $op);
        $verified = false;
        if (!empty($statusRes['ok'])) {
            $verified = true;
            $counts = $statusRes['counts'] ?? [];
            if (array_key_exists($s, $counts)) {
                $count = (int)$counts[$s];
            } elseif (array_key_exists(strtolower($s), $counts)) {
                $count = (int)$counts[strtolower($s)];
            } else {
                $count = 0;
            }
        }

        return [
            'ok'        => true,
            'available' => $verified ? ($count > 0 && $cost !== null) : ($count > 0 && $cost !== null),
            'verified'  => $verified,
            'count'     => $count,
            'cost'      => $cost,
            'operator'  => $op,
            'currency'  => (string)($this->provider['currency_code'] ?: 'RUB'),
        ];
    }

    /**
     * C. Purchase Number:
     * ?action=getNumber&api_key=API_KEY&service=SERVICE&operator=OPERATOR&country=COUNTRY
     * Success: ACCESS_NUMBER:<activation_id>:<phone_number>
     */
    public function buyActivation(string $countryCode, string $serviceCode, string $operator = 'any', ?int $activationId = null): array
    {
        $params = [
            'service'  => trim($serviceCode),
            'operator' => trim($operator) !== '' ? trim($operator) : 'any',
            'country'  => trim($countryCode),
        ];

        $res = $this->callHandler('getNumber', $params, $activationId);
        if (!$res['ok']) {
            return [
                'ok'        => false,
                'uncertain' => !empty($res['uncertain']),
                'error'     => $res['error'] ?: '5SIM getNumber request failed.',
                'raw'       => (string)($res['raw'] ?? ''),
            ];
        }

        $raw = trim((string)$res['raw']);
        if (preg_match('/^ACCESS_NUMBER:([^:\s]+):([^\s]+)$/i', $raw, $m)) {
            $extId = trim($m[1]);
            $phone = trim($m[2]);
            $timeoutMins = max(2, (int)Helpers::setting('otp_default_timeout_minutes', '15'));
            return [
                'ok'                => true,
                'provider_order_id' => $extId,
                'phone_number'      => $phone,
                'operator'          => $params['operator'],
                'provider_cost'     => '0.0000', // handler_api getNumber does not return price; OtpService uses live getPrices quote
                'provider_currency' => (string)($this->provider['currency_code'] ?: 'RUB'),
                'provider_status'   => 'STATUS_WAIT_CODE',
                'status'            => 'waiting_otp',
                'expires_at'        => date('Y-m-d H:i:s', time() + ($timeoutMins * 60)),
                'raw'               => ['response' => $raw, 'id' => $extId, 'phone' => $phone],
            ];
        }

        $plainErr = $this->detectPlainTextError($raw)
            ?? ('Unexpected getNumber response from 5SIM: ' . substr(strip_tags($raw), 0, 140));
        return $this->failWithLog($res, $plainErr);
    }

    /**
     * E. Check Activation / OTP Status:
     * ?action=getStatus&api_key=API_KEY&id=ACTIVATION_ID
     * Responses:
     *  - STATUS_WAIT_CODE
     *  - STATUS_WAIT_RETRY:<last_code>
     *  - STATUS_WAIT_RESEND
     *  - STATUS_CANCEL
     *  - STATUS_OK:<code_or_sms_text>
     */
    public function checkActivation(string $providerOrderId, ?int $activationId = null): array
    {
        $res = $this->callHandler('getStatus', ['id' => trim($providerOrderId)], $activationId);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?: 'Unable to check activation status on 5SIM.'];
        }

        $raw = trim((string)$res['raw']);
        $upper = strtoupper($raw);

        if ($upper === 'STATUS_WAIT_CODE' || $upper === 'STATUS_WAIT_RESEND') {
            return [
                'ok'              => true,
                'provider_status' => $upper,
                'status'          => 'waiting_otp',
                'phone_number'    => null,
                'otp_code'        => null,
                'sms_text'        => null,
                'sms_received_at' => null,
                'expires_at'      => null,
                'raw'             => ['response' => $raw],
            ];
        }

        if (str_starts_with($upper, 'STATUS_WAIT_RETRY:')) {
            $payload = trim(substr($raw, strlen('STATUS_WAIT_RETRY:')));
            $otpCode = OtpHttpClient::extractOtpFromText($payload) ?? ($payload !== '' ? $payload : null);
            return [
                'ok'              => true,
                'provider_status' => 'STATUS_WAIT_RETRY',
                'status'          => 'waiting_otp',
                'phone_number'    => null,
                'otp_code'        => $otpCode,
                'sms_text'        => $payload !== '' ? $payload : null,
                'sms_received_at' => $payload !== '' ? date('Y-m-d H:i:s') : null,
                'expires_at'      => null,
                'raw'             => ['response' => $raw],
            ];
        }

        if ($upper === 'STATUS_CANCEL') {
            return [
                'ok'              => true,
                'provider_status' => 'STATUS_CANCEL',
                'status'          => 'canceled',
                'phone_number'    => null,
                'otp_code'        => null,
                'sms_text'        => null,
                'sms_received_at' => null,
                'expires_at'      => null,
                'raw'             => ['response' => $raw],
            ];
        }

        if (str_starts_with($upper, 'STATUS_OK:')) {
            $payload = trim(substr($raw, strlen('STATUS_OK:')));
            $otpCode = OtpHttpClient::extractOtpFromText($payload) ?? ($payload !== '' ? $payload : null);
            return [
                'ok'              => true,
                'provider_status' => 'STATUS_OK',
                'status'          => 'otp_received',
                'phone_number'    => null,
                'otp_code'        => $otpCode,
                'sms_text'        => $payload !== '' ? $payload : null,
                'sms_received_at' => date('Y-m-d H:i:s'),
                'expires_at'      => null,
                'raw'             => ['response' => $raw, 'code' => $otpCode, 'text' => $payload],
            ];
        }

        $plainErr = $this->detectPlainTextError($raw)
            ?? ('Unexpected getStatus response from 5SIM: ' . substr(strip_tags($raw), 0, 120));
        return $this->failWithLog($res, $plainErr);
    }

    /**
     * D. Change Activation Status:
     * ?action=setStatus&api_key=API_KEY&status=STATUS&id=ACTIVATION_ID
     * Status codes: 1 = SMS sent/ready, 3 = request another SMS, 6 = complete, 8 = cancel
     */
    public function setActivationStatus(string $providerOrderId, int $statusCode, ?int $activationId = null): array
    {
        $res = $this->callHandler('setStatus', [
            'status' => (string)$statusCode,
            'id'     => trim($providerOrderId),
        ], $activationId);

        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?: '5SIM setStatus request failed.'];
        }

        $raw = trim((string)$res['raw']);
        $upper = strtoupper($raw);
        $validOkTokens = ['ACCESS_READY', 'ACCESS_RETRY_GET', 'ACCESS_ACTIVATION', 'ACCESS_CANCEL'];

        if (in_array($upper, $validOkTokens, true)) {
            $mapped = match ($upper) {
                'ACCESS_ACTIVATION' => 'completed',
                'ACCESS_CANCEL'     => 'canceled',
                default             => 'waiting_otp',
            };
            return [
                'ok'              => true,
                'provider_status' => $upper,
                'status'          => $mapped,
                'raw'             => ['response' => $raw],
            ];
        }

        $plainErr = $this->detectPlainTextError($raw)
            ?? ('Unexpected setStatus response from 5SIM: ' . substr(strip_tags($raw), 0, 120));
        return $this->failWithLog($res, $plainErr);
    }

    public function cancelActivation(string $providerOrderId, ?int $activationId = null): array
    {
        return $this->setActivationStatus($providerOrderId, 8, $activationId);
    }

    public function finishActivation(string $providerOrderId, ?int $activationId = null): array
    {
        return $this->setActivationStatus($providerOrderId, 6, $activationId);
    }
}

/**
 * Configurable Custom JSON/REST OTP Provider Adapter
 * Kept strictly separate from the built-in 5SIM API1 adapter.
 * Supports configurable endpoints (balance, purchase, pricing, availability, status, cancel, complete, countries),
 * authentication methods (bearer, header, query, post_body), request parameter templates,
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

    private function resolveEndpointKey(array $candidates): ?string
    {
        foreach ($candidates as $k) {
            if (!empty($this->endpoints[$k]) && trim((string)$this->endpoints[$k]) !== '') {
                return $k;
            }
        }
        return null;
    }

    private function executeEndpoint(string $action, array $endpointCandidates, array $vars = [], ?int $activationId = null): array
    {
        $endpointKey = $this->resolveEndpointKey($endpointCandidates);
        if ($endpointKey === null) {
            $primary = $endpointCandidates[0] ?? $action;
            return [
                'ok'             => false,
                'uncertain'      => false,
                'error_code'     => 'ENDPOINT_NOT_CONFIGURED',
                'error_category' => 'config',
                'error'          => "Endpoint '{$primary}' is not configured for provider {$this->provider['name']}.",
            ];
        }

        $ep = trim((string)$this->endpoints[$endpointKey]);
        if (!str_starts_with($ep, 'http://') && !str_starts_with($ep, 'https://') && $this->baseUrl === '') {
            return [
                'ok'             => false,
                'uncertain'      => false,
                'error_code'     => 'INVALID_CONFIG',
                'error_category' => 'config',
                'error'          => 'Provider Base URL is missing. Edit provider settings and set a valid HTTP(S) base URL.',
            ];
        }
        $vars['api_key'] = $this->apiKey;
        if (isset($vars['id']) && !isset($vars['order_id'])) {
            $vars['order_id'] = $vars['id'];
        }
        if (isset($vars['order_id']) && !isset($vars['id'])) {
            $vars['id'] = $vars['order_id'];
        }

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
        $tpl = null;
        foreach ($endpointCandidates as $cand) {
            if (isset($this->reqParams[$cand]) && is_array($this->reqParams[$cand])) {
                $tpl = $this->reqParams[$cand];
                break;
            }
        }
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
        if ($this->baseUrl === '') {
            return [
                'ok'             => false,
                'error_code'     => 'INVALID_CONFIG',
                'error_category' => 'config',
                'error'          => 'Provider Base URL is not configured.',
            ];
        }
        return $this->getBalance();
    }

    public function getBalance(): array
    {
        $res = $this->executeEndpoint('balance', ['balance']);
        if (!$res['ok']) {
            return [
                'ok'               => false,
                'http_code'        => (int)($res['http_code'] ?? 0),
                'response_time_ms' => (int)($res['response_time_ms'] ?? 0),
                'error_code'       => $res['error_code'] ?? 'PROVIDER_ERROR',
                'error_category'   => $res['error_category'] ?? 'provider',
                'error'            => $res['error'] ?: 'Invalid balance response from custom provider.',
            ];
        }

        $rawStr = trim((string)($res['raw'] ?? ''));
        if ($rawStr === '') {
            $err = 'Provider returned an empty response body for balance check.';
            OtpHttpClient::markLogError((int)($res['log_id'] ?? 0), (int)($this->provider['id'] ?? 0), $err, $this->apiKey);
            return [
                'ok'               => false,
                'http_code'        => (int)($res['http_code'] ?? 200),
                'response_time_ms' => (int)($res['response_time_ms'] ?? 0),
                'error_code'       => 'EMPTY_RESPONSE',
                'error_category'   => 'parse',
                'error'            => $err,
            ];
        }

        // Handle plain-text numeric or ACCESS_BALANCE:amount responses cleanly
        if (!is_array($res['data'])) {
            if (str_starts_with(strtoupper($rawStr), 'ACCESS_BALANCE:')) {
                $amt = trim(substr($rawStr, strlen('ACCESS_BALANCE:')));
                if (is_numeric($amt)) {
                    return [
                        'ok'               => true,
                        'balance'          => Currency::normalize($amt, 4),
                        'currency'         => (string)($this->provider['currency_code'] ?: Currency::getBaseCode()),
                        'http_code'        => (int)($res['http_code'] ?? 200),
                        'response_time_ms' => (int)($res['response_time_ms'] ?? 0),
                        'raw'              => $rawStr,
                    ];
                }
            }
            if (is_numeric($rawStr)) {
                return [
                    'ok'               => true,
                    'balance'          => Currency::normalize($rawStr, 4),
                    'currency'         => (string)($this->provider['currency_code'] ?: Currency::getBaseCode()),
                    'http_code'        => (int)($res['http_code'] ?? 200),
                    'response_time_ms' => (int)($res['response_time_ms'] ?? 0),
                    'raw'              => $rawStr,
                ];
            }

            $classified = ProviderErrorHandler::classifyHttpFailure(200, 0, '', $rawStr, null, [$this->apiKey]);
            $err = $classified['category'] === 'auth'
                ? $classified['message']
                : 'Provider balance response is not valid JSON or a numeric balance value.';
            OtpHttpClient::markLogError((int)($res['log_id'] ?? 0), (int)($this->provider['id'] ?? 0), $err, $this->apiKey);
            return [
                'ok'               => false,
                'http_code'        => (int)($res['http_code'] ?? 200),
                'response_time_ms' => (int)($res['response_time_ms'] ?? 0),
                'error_code'       => $classified['category'] === 'auth' ? 'PROVIDER_AUTH_FAILED' : 'INVALID_RESPONSE_FORMAT',
                'error_category'   => $classified['category'] === 'auth' ? 'auth' : 'parse',
                'error'            => $err,
            ];
        }

        // Detect application-level error objects returned with HTTP 200
        $d = $res['data'];
        if ((isset($d['ok']) && $d['ok'] === false)
            || (isset($d['success']) && $d['success'] === false)
            || (isset($d['status']) && in_array(strtolower((string)$d['status']), ['error', 'fail', 'failed'], true))
            || (!empty($d['error']) && !isset($d['balance']))
        ) {
            $provMsg = (string)($d['error'] ?? $d['message'] ?? $d['detail'] ?? 'Provider rejected the balance request.');
            $classified = ProviderErrorHandler::classifyHttpFailure(200, 0, '', $rawStr, $provMsg, [$this->apiKey]);
            OtpHttpClient::markLogError((int)($res['log_id'] ?? 0), (int)($this->provider['id'] ?? 0), $classified['message'], $this->apiKey);
            return [
                'ok'               => false,
                'http_code'        => (int)($res['http_code'] ?? 200),
                'response_time_ms' => (int)($res['response_time_ms'] ?? 0),
                'error_code'       => $classified['error_code'],
                'error_category'   => $classified['category'],
                'error'            => $classified['message'],
            ];
        }

        $bal = $this->mapVal($d, 'balance', ['balance', 'data.balance', 'wallet', 'credit', 'amount', 'result.balance']);
        if ($bal === null || !is_numeric((string)$bal)) {
            $err = 'Balance field could not be read from provider response. Check response JSON mapping.';
            OtpHttpClient::markLogError((int)($res['log_id'] ?? 0), (int)($this->provider['id'] ?? 0), $err, $this->apiKey);
            return [
                'ok'               => false,
                'http_code'        => (int)($res['http_code'] ?? 200),
                'response_time_ms' => (int)($res['response_time_ms'] ?? 0),
                'error_code'       => 'BALANCE_FIELD_MISSING',
                'error_category'   => 'parse',
                'error'            => $err,
            ];
        }
        $respCurrency = $this->mapVal($d, 'currency', ['currency', 'data.currency']);
        return [
            'ok'               => true,
            'balance'          => Currency::normalize((string)$bal, 4),
            'currency'         => is_string($respCurrency) && trim($respCurrency) !== '' ? strtoupper(trim($respCurrency)) : (string)($this->provider['currency_code'] ?: Currency::getBaseCode()),
            'http_code'        => (int)($res['http_code'] ?? 200),
            'response_time_ms' => (int)($res['response_time_ms'] ?? 0),
            'raw'              => $d,
        ];
    }

    public function getCountries(): array
    {
        $epKey = $this->resolveEndpointKey(['countries', 'countries_services']);
        if ($epKey === null) {
            // Fall back to country discovery via pricing endpoint if no dedicated countries endpoint is configured
            $prices = $this->getPrices(null, null);
            if (empty($prices['ok'])) {
                return [
                    'ok'             => false,
                    'error_code'     => $prices['error_code'] ?? 'ENDPOINT_NOT_CONFIGURED',
                    'error_category' => $prices['error_category'] ?? 'config',
                    'error'          => $prices['error'] ?? 'Neither countries nor pricing endpoint is configured for this provider.',
                    'countries'      => [],
                ];
            }
            return [
                'ok'        => true,
                'countries' => $prices['countries'] ?? [],
                'items'     => $prices['items'] ?? [],
            ];
        }

        $res = $this->executeEndpoint('countries', [$epKey]);
        if (!$res['ok'] || !is_array($res['data'])) {
            return [
                'ok'             => false,
                'error_code'     => $res['error_code'] ?? 'PROVIDER_ERROR',
                'error_category' => $res['error_category'] ?? 'provider',
                'error'          => $res['error'] ?: 'Unable to fetch countries from provider.',
                'countries'      => [],
            ];
        }

        $payload = $res['data'];
        if ((isset($payload['ok']) && $payload['ok'] === false) || (isset($payload['status']) && strtolower((string)$payload['status']) === 'error')) {
            $errMsg = (string)($payload['error'] ?? $payload['message'] ?? 'Provider rejected countries request.');
            return ['ok' => false, 'error' => ProviderErrorHandler::redact($errMsg, [$this->apiKey]), 'countries' => []];
        }

        $source = $payload;
        foreach (['countries', 'data', 'items', 'list', 'result'] as $wk) {
            if (isset($payload[$wk]) && is_array($payload[$wk])) {
                $source = $payload[$wk];
                break;
            }
        }

        $normalized = [];
        $seen = [];
        $isList = array_is_list($source);

        foreach ($source as $k => $row) {
            if (is_array($row)) {
                $code = trim((string)($row['provider_code'] ?? $row['code'] ?? $row['id'] ?? $row['iso'] ?? $row['iso_code'] ?? ($isList ? '' : $k)));
                if ($code === '') {
                    continue;
                }
                $cInfo = FiveSimCatalogReference::describeCountry($code);
                $name = trim((string)($row['name'] ?? $row['country_name'] ?? $row['title'] ?? $row['eng'] ?? $cInfo['name']));
                $iso = strtoupper(trim((string)($row['iso_code'] ?? $row['iso'] ?? $row['alpha2'] ?? $cInfo['iso_code'])));
                $dial = trim((string)($row['dial_code'] ?? $row['prefix'] ?? $row['phone_code'] ?? $cInfo['dial_code']));
                $flag = trim((string)($row['flag_emoji'] ?? $row['flag'] ?? $cInfo['flag_emoji']));
            } elseif (is_string($row) && trim($row) !== '') {
                $code = trim((string)($isList ? $row : $k));
                $cInfo = FiveSimCatalogReference::describeCountry($code);
                $name = trim($isList ? $cInfo['name'] : $row);
                $iso = $cInfo['iso_code'];
                $dial = $cInfo['dial_code'];
                $flag = $cInfo['flag_emoji'];
            } else {
                continue;
            }

            $keyLow = strtolower($code);
            if (isset($seen[$keyLow])) {
                continue;
            }
            $seen[$keyLow] = true;
            $normalized[] = [
                'code'          => $code,
                'provider_code' => $code,
                'name'          => $name !== '' ? $name : ucwords(str_replace(['_', '-'], ' ', $code)),
                'iso'           => $iso,
                'iso_code'      => $iso,
                'dial_code'     => $dial,
                'flag'          => $flag,
                'flag_emoji'    => $flag,
            ];
        }

        return [
            'ok'               => true,
            'countries'        => $normalized,
            'http_code'        => (int)($res['http_code'] ?? 200),
            'response_time_ms' => (int)($res['response_time_ms'] ?? 0),
        ];
    }

    public function getPrices(?string $countryCode = null, ?string $serviceCode = null): array
    {
        $res = $this->executeEndpoint('pricing', ['pricing', 'prices'], [
            'country' => (string)($countryCode ?? ''),
            'service' => (string)($serviceCode ?? ''),
        ]);
        if (!$res['ok'] || !is_array($res['data'])) {
            return [
                'ok'             => false,
                'error_code'     => $res['error_code'] ?? 'PROVIDER_ERROR',
                'error_category' => $res['error_category'] ?? 'provider',
                'error'          => $res['error'] ?: 'Unable to fetch pricing list from custom provider.',
                'items'          => [],
                'countries'      => [],
            ];
        }

        $currency = (string)($this->provider['currency_code'] ?: Currency::getBaseCode());
        $items = [];
        $countriesMap = [];
        $payload = $res['data'];

        if ((isset($payload['ok']) && $payload['ok'] === false) || (isset($payload['status']) && strtolower((string)$payload['status']) === 'error')) {
            $errMsg = (string)($payload['error'] ?? $payload['message'] ?? 'Provider rejected pricing request.');
            return ['ok' => false, 'error' => ProviderErrorHandler::redact($errMsg, [$this->apiKey]), 'items' => [], 'countries' => []];
        }

        // Unwrap common containers (items / services / prices / data)
        if (isset($payload['prices']) && is_array($payload['prices'])) {
            $payload = $payload['prices'];
        } elseif (isset($payload['data']) && is_array($payload['data']) && !isset($payload['items']) && !isset($payload['services'])) {
            $payload = $payload['data'];
        }

        // Support array-of-objects list: [{"country": "IN", "service": "whatsapp", "price": 0.50, "count": 20}, ...]
        $list = isset($payload['items']) && is_array($payload['items'])
            ? $payload['items']
            : (isset($payload['services']) && is_array($payload['services']) ? $payload['services'] : (array_is_list($payload) ? $payload : null));

        if (is_array($list)) {
            foreach ($list as $row) {
                if (!is_array($row)) continue;
                $cCode = (string)($row['country_code'] ?? $row['country'] ?? ($countryCode ?? ''));
                $sCode = (string)($row['service_code'] ?? $row['service'] ?? ($serviceCode ?? ''));
                $cost = $row['price'] ?? $row['cost'] ?? null;
                if ($cCode === '' || $sCode === '' || $cost === null || !is_numeric((string)$cost)) continue;
                if ($countryCode !== null && $countryCode !== '' && strcasecmp($cCode, $countryCode) !== 0) continue;
                if ($serviceCode !== null && $serviceCode !== '' && strcasecmp($sCode, $serviceCode) !== 0) continue;
                $cInfo = FiveSimCatalogReference::describeCountry($cCode);
                $sInfo = FiveSimCatalogReference::describeService($sCode);
                if (!empty($row['country_name'])) $cInfo['name'] = (string)$row['country_name'];
                if (!empty($row['service_name'])) $sInfo['name'] = (string)$row['service_name'];
                $countriesMap[$cCode] = $cInfo;
                $items[] = [
                    'country_code' => $cCode,
                    'country_name' => $cInfo['name'],
                    'country_iso'  => $cInfo['iso_code'],
                    'country_dial' => $cInfo['dial_code'],
                    'country_flag' => $cInfo['flag_emoji'],
                    'service_code' => $sCode,
                    'service_name' => $sInfo['name'],
                    'service_slug' => FiveSimCatalogReference::makeSlug($sInfo['name'], $sCode),
                    'icon_key'     => $sInfo['icon_key'],
                    'is_popular'   => $sInfo['is_popular'],
                    'operator'     => (string)($row['operator'] ?? 'any'),
                    'cost'         => Currency::normalize((string)$cost, 4),
                    'count'        => isset($row['count']) ? max(0, (int)$row['count']) : (isset($row['stock']) ? max(0, (int)$row['stock']) : null),
                    'currency'     => $currency,
                ];
            }
        } else {
            // Support nested country -> service -> {cost/price, count} map
            foreach ($payload as $cKey => $svcs) {
                if (!is_array($svcs)) continue;
                if ($countryCode !== null && $countryCode !== '' && strcasecmp((string)$cKey, $countryCode) !== 0) continue;
                $cInfo = FiveSimCatalogReference::describeCountry((string)$cKey);
                foreach ($svcs as $sKey => $info) {
                    if (!is_array($info)) continue;
                    if ($serviceCode !== null && $serviceCode !== '' && strcasecmp((string)$sKey, $serviceCode) !== 0) continue;
                    $cost = $info['cost'] ?? $info['price'] ?? null;
                    if ($cost === null || !is_numeric((string)$cost)) continue;
                    $sInfo = FiveSimCatalogReference::describeService((string)$sKey);
                    $countriesMap[(string)$cKey] = $cInfo;
                    $items[] = [
                        'country_code' => (string)$cKey,
                        'country_name' => $cInfo['name'],
                        'country_iso'  => $cInfo['iso_code'],
                        'country_dial' => $cInfo['dial_code'],
                        'country_flag' => $cInfo['flag_emoji'],
                        'service_code' => (string)$sKey,
                        'service_name' => $sInfo['name'],
                        'service_slug' => $sInfo['slug'],
                        'icon_key'     => $sInfo['icon_key'],
                        'is_popular'   => $sInfo['is_popular'],
                        'operator'     => (string)($info['operator'] ?? 'any'),
                        'cost'         => Currency::normalize((string)$cost, 4),
                        'count'        => isset($info['count']) ? max(0, (int)$info['count']) : (isset($info['stock']) ? max(0, (int)$info['stock']) : null),
                        'currency'     => $currency,
                    ];
                }
            }
        }

        return [
            'ok'               => true,
            'items'            => $items,
            'countries'        => array_values($countriesMap),
            'currency'         => $currency,
            'http_code'        => (int)($res['http_code'] ?? 200),
            'response_time_ms' => (int)($res['response_time_ms'] ?? 0),
        ];
    }

    public function getNumbersStatus(?string $countryCode = null, ?string $operator = null): array
    {
        $res = $this->executeEndpoint('availability', ['availability', 'pricing', 'prices'], [
            'country'  => (string)($countryCode ?? ''),
            'operator' => (string)($operator ?? 'any'),
        ]);
        if (!$res['ok'] || !is_array($res['data'])) {
            return ['ok' => false, 'error' => $res['error'] ?: 'Availability check failed.', 'counts' => []];
        }
        $counts = [];
        foreach ($res['data'] as $k => $v) {
            if (is_numeric($v)) {
                $base = (string)preg_replace('/_[01]$/', '', (string)$k);
                $counts[$base] = max(0, (int)$v);
            } elseif (is_array($v) && (isset($v['count']) || isset($v['stock']))) {
                $counts[(string)$k] = max(0, (int)($v['count'] ?? $v['stock']));
            }
        }
        return ['ok' => true, 'country' => $countryCode, 'counts' => $counts, 'raw' => $res['data']];
    }

    public function getAvailabilityAndPrice(string $countryCode, string $serviceCode, string $operator = 'any'): array
    {
        $epKey = $this->resolveEndpointKey(['availability', 'pricing', 'prices']);
        if ($epKey === null) {
            return [
                'ok'        => true,
                'available' => true,
                'verified'  => false,
                'count'     => null,
                'cost'      => null,
                'operator'  => $operator ?: 'any',
                'currency'  => (string)($this->provider['currency_code'] ?: 'USD'),
            ];
        }
        $res = $this->executeEndpoint('pricing', [$epKey], [
            'country'  => $countryCode,
            'service'  => $serviceCode,
            'operator' => $operator ?: 'any',
        ]);
        if (!$res['ok'] || !is_array($res['data'])) {
            return ['ok' => false, 'available' => false, 'count' => 0, 'cost' => null, 'error' => $res['error'] ?: 'Pricing lookup failed.'];
        }
        $payload = $res['data'];
        $target = $payload;
        $svcLow = strtolower($serviceCode);
        $ctyLow = strtolower($countryCode);

        // Support country-keyed or service-keyed availability maps (e.g. {"whatsapp": 118} or {"in": {"whatsapp": {"cost": 0.35, "count": 118}}})
        if (isset($payload[$countryCode]) && is_array($payload[$countryCode])) {
            $payload = $payload[$countryCode];
        } elseif (isset($payload[$ctyLow]) && is_array($payload[$ctyLow])) {
            $payload = $payload[$ctyLow];
        }

        $matchedCount = null;
        $matchedCost = null;
        foreach ([$serviceCode, $svcLow, $serviceCode . '_0', $svcLow . '_0'] as $sKey) {
            if (array_key_exists($sKey, $payload)) {
                $entry = $payload[$sKey];
                if (is_numeric($entry)) {
                    $matchedCount = max(0, (int)$entry);
                } elseif (is_array($entry)) {
                    $target = $entry;
                }
                break;
            }
        }

        $cost = $this->mapVal($target, 'cost', ['price', 'cost', 'data.price', 'data.cost']);
        $count = $matchedCount !== null
            ? $matchedCount
            : $this->mapVal($target, 'stock', ['count', 'stock', 'available', 'quantity', 'data.count']);
        $availFlag = $matchedCount !== null
            ? ($matchedCount > 0)
            : $this->mapVal($target, 'available', ['available', 'in_stock', 'data.available']);
        $isAvail = $availFlag !== null
            ? (bool)$availFlag
            : ($count !== null ? ((int)$count > 0) : ($cost !== null));
        return [
            'ok'        => true,
            'available' => $isAvail,
            'verified'  => true,
            'count'     => $count !== null ? (int)$count : ($isAvail ? 1 : 0),
            'cost'      => ($cost !== null && is_numeric((string)$cost)) ? Currency::normalize((string)$cost, 4) : null,
            'operator'  => $operator ?: 'any',
            'currency'  => (string)($this->provider['currency_code'] ?: Currency::getBaseCode()),
        ];
    }

    public function buyActivation(string $countryCode, string $serviceCode, string $operator = 'any', ?int $activationId = null): array
    {
        $res = $this->executeEndpoint('buy', ['purchase', 'buy'], [
            'country'  => $countryCode,
            'service'  => $serviceCode,
            'operator' => $operator ?: 'any',
        ], $activationId);

        if (!$res['ok'] || !is_array($res['data'])) {
            return [
                'ok'        => false,
                'uncertain' => !empty($res['uncertain']),
                'error'     => $res['error'] ?: 'Custom provider activation purchase failed.',
                'raw'       => $res['raw'] ?? '',
            ];
        }

        $d = $res['data'];
        $extId = $this->mapVal($d, 'order_id', ['id', 'activation_id', 'order_id', 'data.id', 'data.activation_id']);
        $phone = $this->mapVal($d, 'phone', ['phone', 'number', 'phone_number', 'data.phone', 'data.number']);
        $cost = $this->mapVal($d, 'cost', ['price', 'cost', 'data.price', 'data.cost']);
        $provStatus = (string)($this->mapVal($d, 'status', ['status', 'state', 'data.status']) ?? 'WAITING');
        $expiresRaw = $this->mapVal($d, 'expires', ['expires', 'expires_at', 'expire_at', 'data.expires_at']);

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
        $res = $this->executeEndpoint('check', ['status', 'sms', 'check'], ['id' => $providerOrderId, 'order_id' => $providerOrderId], $activationId);
        if (!$res['ok'] || !is_array($res['data'])) {
            return ['ok' => false, 'error' => $res['error'] ?: 'Status check failed.'];
        }
        $d = $res['data'];
        $provStatus = (string)($this->mapVal($d, 'status', ['status', 'state', 'data.status']) ?? 'WAITING');
        $otpCode = $this->mapVal($d, 'otp_code', ['otp_code', 'code', 'otp', 'sms.0.code', 'data.code', 'data.otp']);
        $smsText = $this->mapVal($d, 'sms_text', ['sms_text', 'text', 'message', 'sms.0.text', 'data.text']);
        if (($otpCode === null || $otpCode === '') && is_string($smsText) && $smsText !== '') {
            $otpCode = OtpHttpClient::extractOtpFromText($smsText);
        }
        $expiresRaw = $this->mapVal($d, 'expires', ['expires', 'expires_at', 'data.expires_at']);
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

    public function setActivationStatus(string $providerOrderId, int $statusCode, ?int $activationId = null): array
    {
        if ($statusCode === 8) {
            return $this->cancelActivation($providerOrderId, $activationId);
        }
        if ($statusCode === 6) {
            return $this->finishActivation($providerOrderId, $activationId);
        }
        return ['ok' => true, 'provider_status' => 'UPDATED', 'status' => 'waiting_otp', 'raw' => []];
    }

    public function cancelActivation(string $providerOrderId, ?int $activationId = null): array
    {
        if ($this->resolveEndpointKey(['cancel']) === null) {
            return ['ok' => false, 'error' => 'Cancellation endpoint is not configured for this provider.'];
        }
        $res = $this->executeEndpoint('cancel', ['cancel'], ['id' => $providerOrderId, 'order_id' => $providerOrderId], $activationId);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?: 'Provider rejected cancellation.'];
        }
        return ['ok' => true, 'provider_status' => 'CANCELED', 'status' => 'canceled', 'raw' => $res['data']];
    }

    public function finishActivation(string $providerOrderId, ?int $activationId = null): array
    {
        if ($this->resolveEndpointKey(['complete', 'finish']) === null) {
            return ['ok' => true, 'provider_status' => 'COMPLETED', 'status' => 'completed', 'raw' => []];
        }
        $res = $this->executeEndpoint('finish', ['complete', 'finish'], ['id' => $providerOrderId, 'order_id' => $providerOrderId], $activationId);
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
        $type = trim((string)($provider['adapter_type'] ?? 'custom_json'));
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
        if ($type === 'custom_json' || $type === '') {
            return new CustomOtpProviderAdapter($provider);
        }
        throw new InvalidArgumentException("Unsupported OTP provider adapter type: {$type}");
    }
}
