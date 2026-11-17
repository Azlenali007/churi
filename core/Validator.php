<?php
/**
 * Input Validation & XSS Output Escaping
 */

class Validator
{
    public static function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function str(?string $value, int $maxLength = 5000): string
    {
        $clean = trim((string)$value);
        $clean = str_replace("\0", '', $clean);
        if (mb_strlen($clean, 'UTF-8') > $maxLength) {
            $clean = mb_substr($clean, 0, $maxLength, 'UTF-8');
        }
        return $clean;
    }

    public static function email(?string $email): ?string
    {
        $clean = strtolower(trim((string)$email));
        return filter_var($clean, FILTER_VALIDATE_EMAIL) ? $clean : null;
    }

    public static function username(?string $username): ?string
    {
        $clean = trim((string)$username);
        return preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $clean) ? $clean : null;
    }

    public static function url(?string $url): ?string
    {
        $clean = trim((string)$url);
        if ($clean === '') {
            return null;
        }
        if (filter_var($clean, FILTER_VALIDATE_URL)) {
            $scheme = strtolower((string)parse_url($clean, PHP_URL_SCHEME));
            if (in_array($scheme, ['http', 'https'], true)) {
                return $clean;
            }
        }
        return null;
    }

    public static function linkOrHandle(?string $input): ?string
    {
        $clean = self::str($input, 500);
        if ($clean === '') {
            return null;
        }
        // Reject obvious script injection schemes
        if (preg_match('/^(javascript|data|vbscript):/i', $clean)) {
            return null;
        }
        return $clean;
    }

    public static function positiveInt($value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit(trim($value))) {
            $int = (int)trim($value);
            return $int > 0 ? $int : null;
        }
        return null;
    }

    public static function decimal($value, int $scale = 4): ?string
    {
        $raw = trim((string)$value);
        if (!preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
            return null;
        }
        return Currency::normalize($raw, $scale);
    }

    public static function slug(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim((string)$slug, '-');
        return $slug !== '' ? $slug : 'item';
    }
}
