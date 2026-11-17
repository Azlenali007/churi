<?php
/**
 * Free Exchange Rate Auto-Update & Caching Service
 *
 * Primary Source: Frankfurter API (https://api.frankfurter.app)
 *   - 100% free, zero API key required, powered by European Central Bank (ECB) reference rates.
 *   - Updated daily (~16:00 CET on ECB working days), unlimited reasonable server requests.
 *
 * Secondary Fallback Source: ExchangeRate-API Open Access (https://open.er-api.com/v6/latest/{BASE})
 *   - Free open-access JSON endpoint, zero API key required, supports 160+ world currencies.
 *   - Updated once every 24h; attribution-friendly free tier.
 *
 * Never relies on fake rates, never crashes page loads if external API is unreachable,
 * preserves last cached rates on error, and respects manual admin overrides.
 */

class ExchangeRateService
{
    public const SOURCE_FRANKFURTER = 'frankfurter';
    public const SOURCE_OPEN_ER_API = 'open_er_api';

    /**
     * Syncs exchange rates from free API into `currencies` table.
     *
     * @param string $triggeredBy 'manual' | 'cron' | 'fallback'
     * @param int|null $adminId Admin ID when triggered manually
     * @param bool $respectManualOverrides Skip rows with is_manual_override = 1
     * @param string|null $mockJsonOverride Optional test payload override (only via unit tests, never in HTTP)
     */
    public static function syncRates(
        string $triggeredBy = 'manual',
        ?int $adminId = null,
        bool $respectManualOverrides = true,
        ?string $mockJsonOverride = null
    ): array {
        $baseCurrency = Currency::getBaseCurrency();
        $baseCode = strtoupper((string)$baseCurrency['code']);
        $allCurrencies = Currency::getAllCurrencies(true);

        $targets = [];
        foreach ($allCurrencies as $code => $row) {
            if ($code !== $baseCode) {
                $targets[] = $code;
            }
        }

        $nowStr = date('Y-m-d H:i:s');
        Helpers::setSetting('exchange_rate_last_attempt_at', $nowStr, 'currency');

        if (empty($targets)) {
            Database::execute(
                "UPDATE currencies SET exchange_rate = 1.000000, rate_source = 'base', update_status = 'ok', last_error = NULL, last_updated_at = ? WHERE code = ?",
                [$nowStr, $baseCode]
            );
            Helpers::setSetting('exchange_rate_last_success_at', $nowStr, 'currency');
            Helpers::setSetting('exchange_rate_last_status', 'ok', 'currency');
            Helpers::setSetting('exchange_rate_last_error', '', 'currency');
            Currency::clearCache();
            return ['ok' => true, 'source' => 'base', 'updated' => [], 'skipped_manual' => [], 'rates' => [$baseCode => '1.000000']];
        }

        $preferredProvider = Helpers::setting('exchange_rate_provider', self::SOURCE_FRANKFURTER);
        $fetchResult = $mockJsonOverride !== null
            ? self::parseExternalPayload($mockJsonOverride, $baseCode, 'mock_test')
            : self::fetchWithFallback($baseCode, $targets, $preferredProvider);

        if (!$fetchResult['ok']) {
            $errMsg = Validator::str((string)($fetchResult['error'] ?? 'Exchange rate API unavailable.'), 240);
            Helpers::setSetting('exchange_rate_last_status', 'failed', 'currency');
            Helpers::setSetting('exchange_rate_last_error', $errMsg, 'currency');

            Database::execute(
                "UPDATE currencies SET update_status = CASE WHEN is_manual_override = 1 THEN 'manual' ELSE 'failed' END, last_error = ? WHERE code != ?",
                [$errMsg, $baseCode]
            );

            self::recordLog($baseCode, (string)($fetchResult['source'] ?? $preferredProvider), null, 'failed', $errMsg, $triggeredBy, $adminId);
            Currency::clearCache();

            return ['ok' => false, 'error' => $errMsg, 'cached_preserved' => true];
        }

        $rates = $fetchResult['rates'];
        $sourceUsed = (string)$fetchResult['source'];

        // Ensure base currency always stays exact 1.000000
        Database::execute(
            "UPDATE currencies SET exchange_rate = 1.000000, is_base = 1, rate_source = 'base', update_status = 'ok', last_error = NULL, last_updated_at = ? WHERE code = ?",
            [$nowStr, $baseCode]
        );

        $updated = [];
        $skippedManual = [];
        $missingFromApi = [];

        foreach ($targets as $code) {
            $row = $allCurrencies[$code];
            if ($respectManualOverrides && (int)($row['is_manual_override'] ?? 0) === 1) {
                $skippedManual[] = $code;
                continue;
            }
            if (!isset($rates[$code])) {
                $missingFromApi[] = $code;
                continue;
            }
            $validatedRate = self::validateRateValue($rates[$code]);
            if ($validatedRate === null) {
                $missingFromApi[] = $code;
                continue;
            }

            Database::execute(
                "UPDATE currencies
                 SET exchange_rate = ?, rate_source = ?, update_status = 'ok', last_error = NULL, last_updated_at = ?
                 WHERE code = ?",
                [$validatedRate, $sourceUsed, $nowStr, $code]
            );
            $updated[$code] = $validatedRate;
        }

        if (empty($updated) && empty($skippedManual)) {
            $errMsg = 'API response did not contain valid positive rates for configured currencies (' . implode(', ', $targets) . ').';
            Helpers::setSetting('exchange_rate_last_status', 'failed', 'currency');
            Helpers::setSetting('exchange_rate_last_error', $errMsg, 'currency');
            self::recordLog($baseCode, $sourceUsed, $rates, 'failed', $errMsg, $triggeredBy, $adminId);
            Currency::clearCache();
            return ['ok' => false, 'error' => $errMsg, 'cached_preserved' => true];
        }

        Helpers::setSetting('exchange_rate_last_success_at', $nowStr, 'currency');
        Helpers::setSetting('exchange_rate_last_status', 'ok', 'currency');
        Helpers::setSetting('exchange_rate_last_error', '', 'currency');
        self::recordLog($baseCode, $sourceUsed, $updated, 'success', !empty($missingFromApi) ? ('Missing: ' . implode(',', $missingFromApi)) : null, $triggeredBy, $adminId);

        if ($adminId !== null) {
            Logger::activity('admin', $adminId, 'exchange_rates.sync', 'currency', $baseCode, "Synced via {$sourceUsed}: " . count($updated) . " updated");
        }

        Currency::clearCache();
        return [
            'ok'             => true,
            'source'         => $sourceUsed,
            'updated'        => $updated,
            'skipped_manual' => $skippedManual,
            'missing'        => $missingFromApi,
            'timestamp'      => $nowStr,
        ];
    }

    /**
     * Fetches from primary free API and automatically tries secondary free API if primary fails
     * or misses a target currency.
     */
    private static function fetchWithFallback(string $baseCode, array $targets, string $preferredProvider): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'source' => $preferredProvider, 'error' => 'PHP cURL extension is required for automatic rate updates.'];
        }

        $order = $preferredProvider === self::SOURCE_OPEN_ER_API
            ? [self::SOURCE_OPEN_ER_API, self::SOURCE_FRANKFURTER]
            : [self::SOURCE_FRANKFURTER, self::SOURCE_OPEN_ER_API];

        $lastError = 'No exchange rate providers responded.';
        $mergedRates = [];
        $primaryUsed = '';

        foreach ($order as $source) {
            if ($source === self::SOURCE_FRANKFURTER) {
                $toParam = implode(',', array_map('urlencode', $targets));
                $url = 'https://api.frankfurter.app/latest?from=' . urlencode($baseCode) . '&to=' . $toParam;
            } else {
                $url = 'https://open.er-api.com/v6/latest/' . urlencode($baseCode);
            }

            $http = self::httpGet($url);
            if (!$http['ok']) {
                $lastError = ucfirst($source) . ': ' . $http['error'];
                continue;
            }

            $parsed = self::parseExternalPayload($http['body'], $baseCode, $source);
            if (!$parsed['ok']) {
                $lastError = ucfirst($source) . ': ' . $parsed['error'];
                continue;
            }

            if ($primaryUsed === '') {
                $primaryUsed = $source;
            }
            foreach ($parsed['rates'] as $code => $val) {
                if (!isset($mergedRates[$code])) {
                    $mergedRates[$code] = $val;
                }
            }

            // Check if all targets are satisfied
            $allSatisfied = true;
            foreach ($targets as $t) {
                if (!isset($mergedRates[$t])) {
                    $allSatisfied = false;
                    break;
                }
            }
            if ($allSatisfied) {
                break;
            }
        }

        if (empty($mergedRates)) {
            return ['ok' => false, 'source' => $order[0], 'error' => $lastError];
        }

        return ['ok' => true, 'source' => $primaryUsed ?: $order[0], 'rates' => $mergedRates];
    }

    public static function parseExternalPayload(string $jsonRaw, string $expectedBase, string $sourceName): array
    {
        $decoded = json_decode($jsonRaw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'source' => $sourceName, 'error' => 'Invalid JSON payload received from API.'];
        }

        if (isset($decoded['result']) && $decoded['result'] === 'error') {
            return ['ok' => false, 'source' => $sourceName, 'error' => 'API returned error: ' . ($decoded['error-type'] ?? 'unknown')];
        }

        $rates = $decoded['rates'] ?? ($decoded['conversion_rates'] ?? null);
        if (!is_array($rates) || empty($rates)) {
            return ['ok' => false, 'source' => $sourceName, 'error' => 'API response missing rates dictionary.'];
        }

        $apiBase = strtoupper((string)($decoded['base'] ?? ($decoded['base_code'] ?? $expectedBase)));
        if ($apiBase !== $expectedBase) {
            return ['ok' => false, 'source' => $sourceName, 'error' => "Base currency mismatch (expected {$expectedBase}, got {$apiBase})."];
        }

        $cleanRates = [];
        foreach ($rates as $code => $val) {
            $normCode = strtoupper(trim((string)$code));
            $valid = self::validateRateValue($val);
            if ($normCode !== '' && $valid !== null) {
                $cleanRates[$normCode] = $valid;
            }
        }

        if (empty($cleanRates)) {
            return ['ok' => false, 'source' => $sourceName, 'error' => 'No valid positive numeric rates in API payload.'];
        }

        return ['ok' => true, 'source' => $sourceName, 'rates' => $cleanRates];
    }

    public static function validateRateValue($val): ?string
    {
        if (is_bool($val) || (!is_int($val) && !is_float($val) && !is_string($val))) {
            return null;
        }
        $floatVal = (float)$val;
        if (!is_finite($floatVal) || $floatVal <= 0.0) {
            return null;
        }
        $str = number_format($floatVal, 6, '.', '');
        if (Currency::cmp($str, '0', 6) <= 0) {
            return null;
        }
        return $str;
    }

    /**
     * Safe opportunistic check for environments without cPanel cron.
     * Should ONLY be invoked from authenticated admin views (never on every user page request).
     */
    public static function maybeOpportunisticSync(): ?array
    {
        if (Helpers::setting('exchange_rate_auto_update', '1') !== '1') {
            return null;
        }
        $intervalHours = max(1, (int)Helpers::setting('exchange_rate_interval_hours', '12'));
        $lastAttempt = Helpers::setting('exchange_rate_last_attempt_at', '');
        if ($lastAttempt !== '') {
            $elapsed = time() - (int)strtotime($lastAttempt);
            if ($elapsed < ($intervalHours * 3600)) {
                return null;
            }
        }
        return self::syncRates('fallback', null, true);
    }

    public static function isRatesOutdated(): bool
    {
        $lastSuccess = Helpers::setting('exchange_rate_last_success_at', '');
        if ($lastSuccess === '') {
            return true;
        }
        $intervalHours = max(1, (int)Helpers::setting('exchange_rate_interval_hours', '12'));
        $maxAgeSeconds = max(86400, $intervalHours * 3600 * 2);
        return (time() - (int)strtotime($lastSuccess)) > $maxAgeSeconds;
    }

    /**
     * Switches platform base currency cleanly and re-normalizes stored rates relative to the new base.
     */
    public static function switchBaseCurrency(string $newBaseCode, int $adminId): array
    {
        $newBaseCode = strtoupper(trim($newBaseCode));
        $target = Currency::getCurrency($newBaseCode);
        if (!$target) {
            return ['ok' => false, 'error' => 'Selected currency does not exist.'];
        }

        $oldBase = Currency::getBaseCurrency();
        if ($oldBase['code'] === $newBaseCode) {
            return ['ok' => true, 'message' => "{$newBaseCode} is already the platform base currency."];
        }

        $divisor = (string)$target['exchange_rate'];
        if (Currency::cmp($divisor, '0', 6) <= 0) {
            return ['ok' => false, 'error' => 'Target currency must have a positive exchange rate before setting it as base.'];
        }

        Database::beginTransaction();
        try {
            $all = Currency::getAllCurrencies(true);
            foreach ($all as $code => $row) {
                if ($code === $newBaseCode) {
                    Database::execute(
                        "UPDATE currencies SET is_base = 1, exchange_rate = 1.000000, status = 'active', rate_source = 'base', update_status = 'ok' WHERE code = ?",
                        [$code]
                    );
                } else {
                    $rebased = Currency::div((string)$row['exchange_rate'], $divisor, 6);
                    if (Currency::cmp($rebased, '0', 6) <= 0) {
                        $rebased = '1.000000';
                    }
                    Database::execute(
                        "UPDATE currencies SET is_base = 0, exchange_rate = ? WHERE code = ?",
                        [$rebased, $code]
                    );
                }
            }
            Helpers::setSetting('base_currency', $newBaseCode, 'currency');
            Helpers::setSetting('currency_symbol', (string)$target['symbol'], 'currency');
            Database::commit();
            Currency::clearCache();
            Logger::activity('admin', $adminId, 'currency.switch_base', 'currency', $newBaseCode, "Switched base currency from {$oldBase['code']} to {$newBaseCode}");
            return ['ok' => true];
        } catch (Throwable $e) {
            Database::rollBack();
            Logger::errorFile('ExchangeRateService::switchBaseCurrency error: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Failed to switch base currency.'];
        }
    }

    private static function httpGet(string $url): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'SMMPRO-ExchangeRateSync/1.0',
        ]);
        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err !== '') {
            return ['ok' => false, 'error' => 'Network/cURL error: ' . ($err ?: 'Empty response')];
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            return ['ok' => false, 'error' => 'HTTP status ' . $httpCode];
        }
        return ['ok' => true, 'body' => (string)$body];
    }

    private static function recordLog(
        string $baseCode,
        string $source,
        ?array $rates,
        string $status,
        ?string $errorMessage,
        string $triggeredBy,
        ?int $adminId
    ): void {
        try {
            Database::insert(
                "INSERT INTO exchange_rate_logs (base_currency, provider_source, rates_json, status, error_message, triggered_by, admin_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $baseCode,
                    substr($source, 0, 60),
                    $rates !== null ? json_encode($rates) : null,
                    $status,
                    $errorMessage !== null ? substr($errorMessage, 0, 255) : null,
                    $triggeredBy,
                    $adminId,
                ]
            );
        } catch (Throwable $e) {
            Logger::errorFile('ExchangeRateService::recordLog error: ' . $e->getMessage());
        }
    }
}
