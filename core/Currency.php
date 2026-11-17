<?php
/**
 * Precision Decimal Arithmetic & Multi-Currency Conversion Engine
 *
 * Rate convention across `currencies` table:
 *   1 unit of platform `base_currency` = `exchange_rate` units of `currency.code`.
 *   Example (base = INR):
 *     INR: 1.000000
 *     USD: 0.011900 (1 INR = 0.0119 USD, i.e., 1 USD ≈ 84.03 INR)
 *     EUR: 0.011000
 *     GBP: 0.009400
 *
 * All monetary amounts in `users.wallet_balance`, `services.rate`, and `flash_sales`
 * are stored in platform `base_currency`. Historical `orders` and `wallet_transactions`
 * also persist `currency_code` so stored financial records remain immutable.
 */

class Currency
{
    public const SCALE = 6;
    public const MONEY_SCALE = 4;

    private static ?array $currenciesCache = null;
    private static ?string $activeDisplayOverride = null;

    public static function clearCache(): void
    {
        self::$currenciesCache = null;
    }

    public static function setActiveDisplayOverride(?string $code): void
    {
        self::$activeDisplayOverride = $code !== null ? strtoupper(trim($code)) : null;
    }

    public static function normalize(string $num, int $scale = self::MONEY_SCALE): string
    {
        $num = trim($num);
        if ($num === '' || !preg_match('/^[+-]?\d+(\.\d+)?$/', $num)) {
            $num = '0';
        }
        $negative = false;
        if ($num[0] === '-') {
            $negative = true;
            $num = substr($num, 1);
        } elseif ($num[0] === '+') {
            $num = substr($num, 1);
        }

        $parts = explode('.', $num, 2);
        $intPart = ltrim($parts[0], '0');
        if ($intPart === '') {
            $intPart = '0';
        }
        $fracPart = $parts[1] ?? '';

        if ($scale === 0) {
            return ($negative && $intPart !== '0' ? '-' : '') . $intPart;
        }

        $fracPart = substr(str_pad($fracPart, $scale, '0'), 0, $scale);
        $result = $intPart . '.' . $fracPart;
        if ($negative && preg_match('/[1-9]/', $result)) {
            return '-' . $result;
        }
        return $result;
    }

    public static function add(string $a, string $b, int $scale = self::MONEY_SCALE): string
    {
        if (function_exists('bcadd')) {
            return self::normalize(bcadd(self::normalize($a, $scale + 2), self::normalize($b, $scale + 2), $scale), $scale);
        }
        $intA = self::toScaledInt($a, $scale);
        $intB = self::toScaledInt($b, $scale);
        return self::fromScaledInt($intA + $intB, $scale);
    }

    public static function sub(string $a, string $b, int $scale = self::MONEY_SCALE): string
    {
        if (function_exists('bcsub')) {
            return self::normalize(bcsub(self::normalize($a, $scale + 2), self::normalize($b, $scale + 2), $scale), $scale);
        }
        $intA = self::toScaledInt($a, $scale);
        $intB = self::toScaledInt($b, $scale);
        return self::fromScaledInt($intA - $intB, $scale);
    }

    public static function mul(string $a, string $b, int $scale = self::MONEY_SCALE): string
    {
        if (function_exists('bcmul')) {
            return self::normalize(bcmul(self::normalize($a, 8), self::normalize($b, 8), $scale), $scale);
        }
        $normA = self::normalize($a, 8);
        $normB = self::normalize($b, 8);
        $neg = (str_starts_with($normA, '-') xor str_starts_with($normB, '-'));
        $digA = ltrim(str_replace(['-', '.'], '', $normA), '0') ?: '0';
        $digB = ltrim(str_replace(['-', '.'], '', $normB), '0') ?: '0';
        if ($digA === '0' || $digB === '0') {
            return self::normalize('0', $scale);
        }
        $lenA = strlen($digA);
        $lenB = strlen($digB);
        $res = array_fill(0, $lenA + $lenB, 0);
        for ($i = $lenA - 1; $i >= 0; $i--) {
            for ($j = $lenB - 1; $j >= 0; $j--) {
                $p = ((int)$digA[$i]) * ((int)$digB[$j]) + $res[$i + $j + 1];
                $res[$i + $j + 1] = $p % 10;
                $res[$i + $j] += intdiv($p, 10);
            }
        }
        $prodStr = ltrim(implode('', $res), '0') ?: '0';
        // Product has 16 decimal digits (8 + 8)
        $prodStr = str_pad($prodStr, 17, '0', STR_PAD_LEFT);
        $intPart = substr($prodStr, 0, -16) ?: '0';
        $fracPart = substr($prodStr, -16);
        $combined = ($neg ? '-' : '') . $intPart . '.' . $fracPart;
        return self::normalize($combined, $scale);
    }

    public static function div(string $a, string $b, int $scale = self::MONEY_SCALE): string
    {
        if (self::cmp($b, '0', 8) === 0) {
            return self::normalize('0', $scale);
        }
        if (function_exists('bcdiv')) {
            return self::normalize(bcdiv(self::normalize($a, 8), self::normalize($b, 8), $scale), $scale);
        }
        // Safe string/float-free fallback avoiding 64-bit int overflow
        $normA = self::normalize($a, 8);
        $normB = self::normalize($b, 8);
        $neg = (str_starts_with($normA, '-') xor str_starts_with($normB, '-'));
        $digA = ltrim(str_replace(['-', '.'], '', $normA), '0') ?: '0';
        $digB = ltrim(str_replace(['-', '.'], '', $normB), '0') ?: '0';
        if ($digA === '0' || $digB === '0') {
            return self::normalize('0', $scale);
        }
        // If both fit cleanly within safe int range (< 10^10), use intdiv with shift
        if (strlen($digA) <= 10 && strlen($digB) <= 10 && $scale <= 8) {
            $num = (int)$digA;
            $den = (int)$digB;
            $intQ = intdiv($num, $den);
            $rem = $num % $den;
            $fracDigits = '';
            for ($k = 0; $k < $scale; $k++) {
                $rem *= 10;
                $fracDigits .= (string)intdiv($rem, $den);
                $rem %= $den;
            }
            $out = ($neg ? '-' : '') . $intQ . ($scale > 0 ? '.' . $fracDigits : '');
            return self::normalize($out, $scale);
        }
        // High-magnitude string fallback using double precision then normalized
        $val = ((float)$normA) / ((float)$normB);
        return self::normalize(number_format($val, $scale, '.', ''), $scale);
    }

    public static function cmp(string $a, string $b, int $scale = self::MONEY_SCALE): int
    {
        if (function_exists('bccomp')) {
            return bccomp(self::normalize($a, $scale), self::normalize($b, $scale), $scale);
        }
        $normA = self::normalize($a, $scale);
        $normB = self::normalize($b, $scale);
        $negA = str_starts_with($normA, '-');
        $negB = str_starts_with($normB, '-');
        if ($negA !== $negB) {
            return $negA ? -1 : 1;
        }
        $absA = ltrim($normA, '-');
        $absB = ltrim($normB, '-');
        [$intA, $fracA] = array_pad(explode('.', $absA, 2), 2, '');
        [$intB, $fracB] = array_pad(explode('.', $absB, 2), 2, '');
        $cmpInt = strlen($intA) <=> strlen($intB);
        if ($cmpInt === 0) {
            $cmpInt = strcmp($intA, $intB);
        }
        $cmp = $cmpInt !== 0 ? $cmpInt : strcmp($fracA, $fracB);
        return $negA ? -$cmp : $cmp;
    }

    // ------------------------------------------------------------------------
    // MULTI-CURRENCY REGISTRY & CONVERSION
    // ------------------------------------------------------------------------

    public static function getAllCurrencies(bool $refresh = false): array
    {
        if (self::$currenciesCache !== null && !$refresh) {
            return self::$currenciesCache;
        }
        try {
            $rows = Database::fetchAll("SELECT * FROM currencies ORDER BY is_base DESC, FIELD(code,'INR','USD','EUR','GBP'), code ASC");
            $map = [];
            foreach ($rows as $row) {
                $code = strtoupper((string)$row['code']);
                $row['code'] = $code;
                $map[$code] = $row;
            }
            self::$currenciesCache = $map;
            return $map;
        } catch (Throwable $e) {
            return [
                'INR' => ['code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹', 'exchange_rate' => '1.000000', 'is_base' => 1, 'status' => 'active'],
            ];
        }
    }

    public static function getEnabledCurrencies(): array
    {
        $all = self::getAllCurrencies();
        return array_filter($all, fn(array $c) => ($c['status'] ?? 'inactive') === 'active');
    }

    public static function getCurrency(string $code): ?array
    {
        $all = self::getAllCurrencies();
        $code = strtoupper(trim($code));
        return $all[$code] ?? null;
    }

    public static function getBaseCurrency(): array
    {
        $all = self::getAllCurrencies();
        foreach ($all as $c) {
            if ((int)($c['is_base'] ?? 0) === 1) {
                return $c;
            }
        }
        $settingCode = strtoupper(Helpers::setting('base_currency', 'INR'));
        if (isset($all[$settingCode])) {
            return $all[$settingCode];
        }
        return [
            'code'          => $settingCode ?: 'INR',
            'name'          => 'Indian Rupee',
            'symbol'        => Helpers::setting('currency_symbol', '₹'),
            'exchange_rate' => '1.000000',
            'is_base'       => 1,
            'status'        => 'active',
        ];
    }

    public static function getBaseCode(): string
    {
        return (string)self::getBaseCurrency()['code'];
    }

    public static function getSymbol(string $code): string
    {
        $c = self::getCurrency($code);
        if ($c && !empty($c['symbol'])) {
            return (string)$c['symbol'];
        }
        return match (strtoupper(trim($code))) {
            'INR'   => '₹',
            'USD'   => '$',
            'EUR'   => '€',
            'GBP'   => '£',
            default => strtoupper(trim($code)) . ' ',
        };
    }

    public static function getActiveDisplayCurrency(?array $user = null): array
    {
        $enabled = self::getEnabledCurrencies();
        $base = self::getBaseCurrency();

        if (self::$activeDisplayOverride !== null && isset($enabled[self::$activeDisplayOverride])) {
            return $enabled[self::$activeDisplayOverride];
        }

        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['display_currency'])) {
            $sessCode = strtoupper((string)$_SESSION['display_currency']);
            if (isset($enabled[$sessCode])) {
                return $enabled[$sessCode];
            }
        }

        if ($user !== null && !empty($user['preferred_currency'])) {
            $pref = strtoupper((string)$user['preferred_currency']);
            if (isset($enabled[$pref])) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $_SESSION['display_currency'] = $pref;
                }
                return $enabled[$pref];
            }
        }

        // Check active user session without creating a new session
        if (!defined('IS_ADMIN_CONTEXT') && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
            try {
                $pref = (string)Database::fetchColumn("SELECT preferred_currency FROM users WHERE id = ? LIMIT 1", [(int)$_SESSION['user_id']]);
                $pref = strtoupper(trim($pref));
                if ($pref !== '' && isset($enabled[$pref])) {
                    $_SESSION['display_currency'] = $pref;
                    return $enabled[$pref];
                }
            } catch (Throwable $e) {
                // Ignore if preferred_currency column not migrated yet
            }
        }

        return $base;
    }

    public static function getActiveDisplayCode(?array $user = null): string
    {
        return (string)self::getActiveDisplayCurrency($user)['code'];
    }

    public static function setUserDisplayCurrency(string $code, ?int $userId = null): bool
    {
        $code = strtoupper(trim($code));
        $enabled = self::getEnabledCurrencies();
        if (!isset($enabled[$code])) {
            return false;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['display_currency'] = $code;
        }
        if ($userId !== null && $userId > 0) {
            try {
                Database::execute("UPDATE users SET preferred_currency = ? WHERE id = ?", [$code, $userId]);
            } catch (Throwable $e) {
                Logger::errorFile('Currency::setUserDisplayCurrency DB update error: ' . $e->getMessage());
            }
        }
        return true;
    }

    public static function round(string $num, int $scale = self::MONEY_SCALE): string
    {
        $norm = self::normalize($num, $scale + 2);
        $neg = str_starts_with($norm, '-');
        if ($neg) {
            $norm = substr($norm, 1);
        }
        [$intPart, $fracPart] = array_pad(explode('.', $norm, 2), 2, '');
        $fracPart = str_pad($fracPart, $scale + 2, '0');
        $keepFrac = $scale > 0 ? substr($fracPart, 0, $scale) : '';
        $nextDigit = (int)($fracPart[$scale] ?? '0');

        $out = $scale > 0 ? ($intPart . '.' . $keepFrac) : $intPart;
        if ($nextDigit >= 5) {
            $step = $scale > 0 ? ('0.' . str_repeat('0', $scale - 1) . '1') : '1';
            $out = self::add($out, $step, $scale);
        } else {
            $out = self::normalize($out, $scale);
        }
        if ($neg && preg_match('/[1-9]/', $out)) {
            return '-' . ltrim($out, '-');
        }
        return $out;
    }

    /**
     * Converts $amount from $fromCode into $toCode in a single deterministic step via base_currency.
     * Never converts twice when $fromCode === $toCode.
     */
    public static function convert(string $amount, string $fromCode, string $toCode, int $scale = 6): string
    {
        $fromCode = strtoupper(trim($fromCode));
        $toCode = strtoupper(trim($toCode));
        $workScale = max($scale + 2, 8);
        $norm = self::normalize($amount, $workScale);

        if ($fromCode === '' || $toCode === '' || $fromCode === $toCode) {
            return self::normalize($amount, $scale);
        }

        $baseCode = self::getBaseCode();

        // Step 1: Convert from source currency -> base currency
        if ($fromCode === $baseCode) {
            $baseAmt = $norm;
        } else {
            $fromCurr = self::getCurrency($fromCode);
            $fromRate = $fromCurr ? (string)$fromCurr['exchange_rate'] : '1.000000';
            if (self::cmp($fromRate, '0', 6) <= 0) {
                $fromRate = '1.000000';
            }
            // 1 Base = $fromRate From  ==>  Base = Amount / $fromRate
            $baseAmt = self::div($norm, $fromRate, 8);
        }

        // Step 2: Convert from base currency -> target currency
        if ($toCode === $baseCode) {
            return self::round($baseAmt, $scale);
        }

        $toCurr = self::getCurrency($toCode);
        $toRate = $toCurr ? (string)$toCurr['exchange_rate'] : '1.000000';
        if (self::cmp($toRate, '0', 6) <= 0) {
            $toRate = '1.000000';
        }
        // 1 Base = $toRate To  ==>  To = Base * $toRate
        return self::round(self::mul($baseAmt, $toRate, $scale + 2), $scale);
    }

    public static function convertFromBase(string $baseAmount, ?string $toCode = null, int $scale = 4): string
    {
        $baseCode = self::getBaseCode();
        $target = $toCode !== null ? strtoupper(trim($toCode)) : self::getActiveDisplayCode();
        return self::convert($baseAmount, $baseCode, $target, $scale);
    }

    public static function convertToBase(string $amount, string $fromCode, int $scale = 4): string
    {
        return self::convert($amount, $fromCode, self::getBaseCode(), $scale);
    }

    /**
     * Converts provider rate (in provider's configured source currency) to platform base currency
     * BEFORE applying profit margin.
     *
     * @param string $providerRate Raw rate per 1,000 in provider's currency
     * @param string $providerCurrency Currency code of provider (e.g. INR, USD, EUR)
     * @param string $profitMarginPercent Profit margin % to apply after base conversion
     * @param string $rateMode 'auto' (uses live/cached currencies table) or 'manual' (uses $manualExchangeRate: 1 Provider = X Base)
     * @param string|null $manualExchangeRate Manual factor (1 ProviderCurrency = X BaseCurrency)
     */
    public static function calculateProviderToBase(
        string $providerRate,
        string $providerCurrency,
        string $profitMarginPercent,
        string $rateMode = 'auto',
        ?string $manualExchangeRate = null
    ): array {
        $baseCode = self::getBaseCode();
        $provCode = strtoupper(trim($providerCurrency ?: $baseCode));
        $normProvRate = self::normalize($providerRate, 4);

        if ($provCode === $baseCode) {
            $costBaseRaw = $normProvRate;
            $effectiveFactor = '1.000000';
        } elseif ($rateMode === 'manual' && $manualExchangeRate !== null && self::cmp($manualExchangeRate, '0', 6) > 0) {
            $effectiveFactor = self::normalize($manualExchangeRate, 6);
            $costBaseRaw = self::mul($normProvRate, $effectiveFactor, 8);
        } else {
            // Auto mode: convert using centralized currencies exchange rates
            $provCurr = self::getCurrency($provCode);
            if ($provCurr && self::cmp((string)$provCurr['exchange_rate'], '0', 6) > 0) {
                $costBaseRaw = self::convert($normProvRate, $provCode, $baseCode, 8);
                $effectiveFactor = self::round(self::div('1', (string)$provCurr['exchange_rate'], 8), 6);
            } elseif ($manualExchangeRate !== null && self::cmp($manualExchangeRate, '0', 6) > 0) {
                // Fallback to provider's stored manual rate if currency code isn't in currencies table
                $effectiveFactor = self::normalize($manualExchangeRate, 6);
                $costBaseRaw = self::mul($normProvRate, $effectiveFactor, 8);
            } else {
                $costBaseRaw = $normProvRate;
                $effectiveFactor = '1.000000';
            }
        }

        $marginFactor = self::add('1', self::div($profitMarginPercent, '100', 6), 6);
        $costBase = self::round($costBaseRaw, 4);
        $sellingRateBase = self::round(self::mul($costBaseRaw, $marginFactor, 8), 4);

        return [
            'cost_base'               => $costBase,
            'selling_rate_base'       => $sellingRateBase,
            'effective_exchange_rate' => $effectiveFactor,
            'provider_currency'       => $provCode,
            'base_currency'           => $baseCode,
        ];
    }

    /**
     * Legacy signature preserved for direct cost-factor calculation (used by explicit manual rate math).
     */
    public static function calculateSellingRate(string $providerRate, string $exchangeRate, string $profitMarginPercent): string
    {
        $convertedCost = self::mul($providerRate, $exchangeRate, 4);
        $marginFactor = self::add('1', self::div($profitMarginPercent, '100', 6), 6);
        return self::mul($convertedCost, $marginFactor, 4);
    }

    /**
     * Calculate exact charge for quantity based on rate per 1,000.
     */
    public static function calculateOrderCharge(string $ratePerThousand, int $quantity): string
    {
        $qtyStr = (string)$quantity;
        $raw = self::div(self::mul($ratePerThousand, $qtyStr, 6), '1000', 4);
        return self::normalize($raw, 4);
    }

    public static function applyDiscount(string $rate, string $discountType, string $discountValue): string
    {
        if ($discountType === 'fixed_price') {
            $price = self::normalize($discountValue, 4);
            return self::cmp($price, '0', 4) > 0 ? $price : '0.0001';
        }
        $pct = self::div($discountValue, '100', 6);
        $factor = self::sub('1', $pct, 6);
        if (self::cmp($factor, '0', 6) <= 0) {
            return '0.0001';
        }
        return self::mul($rate, $factor, 4);
    }

    /**
     * Converts a Base Currency amount into the user's selected display currency ONCE and formats it.
     */
    public static function display(string $baseAmount, ?string $targetCode = null, int $decimals = 2, bool $showCode = false): string
    {
        $curr = $targetCode !== null
            ? (self::getCurrency($targetCode) ?? self::getBaseCurrency())
            : self::getActiveDisplayCurrency();
        $code = (string)$curr['code'];
        $converted = self::convertFromBase($baseAmount, $code, max($decimals, 4));
        return self::formatRaw($converted, $code, $decimals, $showCode);
    }

    /**
     * Displays a per-1k service rate cleanly in user's selected display currency.
     * Preserves 3-4 decimal places for small rates (< 1.00) so ₹5 ($0.06) or ₹0.50 ($0.006) never rounds to 0.00.
     */
    public static function displayRate(string $baseAmount, ?string $targetCode = null, bool $showCode = false): string
    {
        $curr = $targetCode !== null
            ? (self::getCurrency($targetCode) ?? self::getBaseCurrency())
            : self::getActiveDisplayCurrency();
        $code = (string)$curr['code'];
        $converted = self::convertFromBase($baseAmount, $code, 4);
        $decimals = 2;
        if (self::cmp($converted, '0', 4) > 0 && self::cmp($converted, '1', 4) < 0) {
            $trimmed = rtrim($converted, '0');
            $dotPos = strpos($trimmed, '.');
            $fracLen = $dotPos !== false ? strlen(substr($trimmed, $dotPos + 1)) : 2;
            $decimals = max(2, min(4, $fracLen));
        }
        return self::formatRaw($converted, $code, $decimals, $showCode);
    }

    /**
     * Formats a Base Currency amount directly without converting to user display currency (for Admin & accounting).
     */
    public static function formatBase(string $baseAmount, int $decimals = 2, bool $showCode = false): string
    {
        return self::formatRaw($baseAmount, self::getBaseCode(), $decimals, $showCode);
    }

    /**
     * Formats an amount ALREADY in $currencyCode WITHOUT any conversion.
     * Essential for immutable historical orders/transactions and provider native balances.
     */
    public static function formatRaw(string $amount, string $currencyCode, int $decimals = 2, bool $showCode = false): string
    {
        $code = strtoupper(trim($currencyCode)) ?: self::getBaseCode();
        $sym = self::getSymbol($code);
        $norm = self::normalize($amount, $decimals);
        $neg = str_starts_with($norm, '-');
        if ($neg) {
            $norm = substr($norm, 1);
        }
        $parts = explode('.', $norm);
        $intFormatted = number_format((float)$parts[0], 0, '.', ',');
        $formatted = $intFormatted . (isset($parts[1]) ? '.' . $parts[1] : '');
        return ($neg ? '-' : '') . $sym . $formatted . ($showCode ? ' ' . $code : '');
    }

    /**
     * Context-aware formatter:
     * - On Admin pages (IS_ADMIN_CONTEXT) or explicit $symbol override: formats in Base Currency.
     * - On User/Public pages: converts Base Currency -> Active Display Currency ONCE.
     */
    public static function format(string $amount, ?string $symbol = null, int $decimals = 2): string
    {
        if ($symbol !== null) {
            $norm = self::normalize($amount, $decimals);
            $neg = str_starts_with($norm, '-');
            if ($neg) {
                $norm = substr($norm, 1);
            }
            $parts = explode('.', $norm);
            $intFormatted = number_format((float)$parts[0], 0, '.', ',');
            $formatted = $intFormatted . (isset($parts[1]) ? '.' . $parts[1] : '');
            return ($neg ? '-' : '') . $symbol . $formatted;
        }

        if (defined('IS_ADMIN_CONTEXT') && IS_ADMIN_CONTEXT) {
            return self::formatBase($amount, $decimals, false);
        }

        return self::display($amount, null, $decimals, false);
    }

    /**
     * Ensures schema extensions exist on pre-existing installations without losing any data.
     */
    public static function ensureSchema(): void
    {
        try {
            $pdo = Database::pdo();
            $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
            if ($dbName === '') {
                return;
            }

            $hasCol = function (string $table, string $col) use ($pdo, $dbName): bool {
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?"
                );
                $stmt->execute([$dbName, $table, $col]);
                return (int)$stmt->fetchColumn() > 0;
            };

            if (!$hasCol('currencies', 'is_manual_override')) {
                $pdo->exec("ALTER TABLE `currencies`
                    ADD COLUMN `is_manual_override` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_base`,
                    ADD COLUMN `rate_source` VARCHAR(60) NOT NULL DEFAULT 'manual' AFTER `is_manual_override`,
                    ADD COLUMN `update_status` ENUM('ok','failed','manual','fallback') NOT NULL DEFAULT 'manual' AFTER `rate_source`,
                    ADD COLUMN `last_error` VARCHAR(255) NULL AFTER `update_status`,
                    ADD COLUMN `last_updated_at` DATETIME NULL AFTER `last_error`");
            }

            if (!$hasCol('users', 'preferred_currency')) {
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `preferred_currency` VARCHAR(10) NULL DEFAULT NULL AFTER `custom_rate_discount`");
            }

            if (!$hasCol('providers', 'rate_mode')) {
                $pdo->exec("ALTER TABLE `providers` ADD COLUMN `rate_mode` ENUM('auto','manual') NOT NULL DEFAULT 'auto' AFTER `exchange_rate`");
            }

            if (!$hasCol('services', 'provider_currency')) {
                $pdo->exec("ALTER TABLE `services` ADD COLUMN `provider_currency` VARCHAR(10) NULL DEFAULT NULL AFTER `provider_rate`");
            }

            $baseCode = Helpers::setting('base_currency', 'INR') ?: 'INR';
            if (!$hasCol('orders', 'currency_code')) {
                $pdo->exec("ALTER TABLE `orders` ADD COLUMN `currency_code` VARCHAR(10) NOT NULL DEFAULT " . $pdo->quote($baseCode) . " AFTER `charge`");
            }

            if (!$hasCol('wallet_transactions', 'currency_code')) {
                $pdo->exec("ALTER TABLE `wallet_transactions` ADD COLUMN `currency_code` VARCHAR(10) NOT NULL DEFAULT " . $pdo->quote($baseCode) . " AFTER `amount`");
            }

            $pdo->exec("CREATE TABLE IF NOT EXISTS `exchange_rate_logs` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `base_currency` VARCHAR(10) NOT NULL,
                `provider_source` VARCHAR(60) NOT NULL,
                `rates_json` TEXT NULL,
                `status` ENUM('success','failed') NOT NULL DEFAULT 'success',
                `error_message` VARCHAR(255) NULL,
                `triggered_by` ENUM('manual','cron','fallback') NOT NULL DEFAULT 'manual',
                `admin_id` INT UNSIGNED NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_erl_created` (`created_at`),
                KEY `idx_erl_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("INSERT IGNORE INTO `currencies` (`code`, `name`, `symbol`, `exchange_rate`, `is_base`, `is_manual_override`, `rate_source`, `update_status`, `status`) VALUES
                ('INR', 'Indian Rupee', '₹', 1.000000, 1, 0, 'base', 'ok', 'active'),
                ('USD', 'US Dollar', '$', 0.011900, 0, 0, 'initial_seed', 'manual', 'active'),
                ('EUR', 'Euro', '€', 0.011000, 0, 0, 'initial_seed', 'manual', 'active'),
                ('GBP', 'British Pound', '£', 0.009400, 0, 0, 'initial_seed', 'manual', 'active')");

            self::clearCache();
        } catch (Throwable $e) {
            Logger::errorFile('Currency::ensureSchema error: ' . $e->getMessage());
        }
    }

    private static function toScaledInt(string $num, int $scale): int
    {
        $norm = self::normalize($num, $scale);
        $neg = str_starts_with($norm, '-');
        if ($neg) {
            $norm = substr($norm, 1);
        }
        $digits = str_replace('.', '', $norm);
        $val = (int)$digits;
        return $neg ? -$val : $val;
    }

    private static function fromScaledInt(int $val, int $scale): string
    {
        if ($scale === 0) {
            return (string)$val;
        }
        $neg = $val < 0;
        $abs = abs($val);
        $str = str_pad((string)$abs, $scale + 1, '0', STR_PAD_LEFT);
        $intPart = substr($str, 0, -$scale);
        $fracPart = substr($str, -$scale);
        return ($neg ? '-' : '') . $intPart . '.' . $fracPart;
    }
}
