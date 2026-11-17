<?php
/**
 * Precision Decimal Arithmetic for Financial & Pricing Calculations
 * Avoids IEEE-754 floating point inaccuracies. Uses bcmath when available,
 * with exact integer-string arithmetic fallback.
 */

class Currency
{
    public const SCALE = 6;
    public const MONEY_SCALE = 4;

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
            return self::normalize(bcmul(self::normalize($a, 6), self::normalize($b, 6), $scale), $scale);
        }
        $normA = self::normalize($a, 6);
        $normB = self::normalize($b, 6);
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
        // Product has 12 decimal digits (6 + 6)
        $prodStr = str_pad($prodStr, 13, '0', STR_PAD_LEFT);
        $intPart = substr($prodStr, 0, -12) ?: '0';
        $fracPart = substr($prodStr, -12);
        $combined = ($neg ? '-' : '') . $intPart . '.' . $fracPart;
        return self::normalize($combined, $scale);
    }

    public static function div(string $a, string $b, int $scale = self::MONEY_SCALE): string
    {
        if (self::cmp($b, '0', 6) === 0) {
            return self::normalize('0', $scale);
        }
        if (function_exists('bcdiv')) {
            return self::normalize(bcdiv(self::normalize($a, 6), self::normalize($b, 6), $scale), $scale);
        }
        $s = 4;
        $intA = self::toScaledInt($a, $s + $scale);
        $intB = self::toScaledInt($b, $s);
        if ($intB === 0) {
            return self::normalize('0', $scale);
        }
        return self::fromScaledInt(intdiv($intA, $intB), $scale);
    }

    public static function cmp(string $a, string $b, int $scale = self::MONEY_SCALE): int
    {
        if (function_exists('bccomp')) {
            return bccomp(self::normalize($a, $scale), self::normalize($b, $scale), $scale);
        }
        $intA = self::toScaledInt($a, $scale);
        $intB = self::toScaledInt($b, $scale);
        return $intA <=> $intB;
    }

    /**
     * Convert provider rate (per 1000) into system base currency with profit margin.
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
        // percent discount
        $pct = self::div($discountValue, '100', 6);
        $factor = self::sub('1', $pct, 6);
        if (self::cmp($factor, '0', 6) <= 0) {
            return '0.0001';
        }
        return self::mul($rate, $factor, 4);
    }

    public static function format(string $amount, ?string $symbol = null, int $decimals = 2): string
    {
        $sym = $symbol ?? Helpers::setting('currency_symbol', '₹');
        $norm = self::normalize($amount, $decimals);
        $neg = str_starts_with($norm, '-');
        if ($neg) {
            $norm = substr($norm, 1);
        }
        $parts = explode('.', $norm);
        $intFormatted = number_format((float)$parts[0], 0, '.', ',');
        $formatted = $intFormatted . (isset($parts[1]) ? '.' . $parts[1] : '');
        return ($neg ? '-' : '') . $sym . $formatted;
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
