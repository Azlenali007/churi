<?php
/**
 * CSRF Protection Utility
 */

class CSRF
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function field(): string
    {
        $token = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_csrf_token" value="' . $token . '">';
    }

    public static function verify(?string $token = null): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        $sessionToken = $_SESSION[self::SESSION_KEY] ?? '';
        if ($sessionToken === '') {
            return false;
        }
        $candidate = $token ?? ($_POST['_csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!is_string($candidate) || $candidate === '') {
            return false;
        }
        return hash_equals($sessionToken, $candidate);
    }

    public static function requireValid(): void
    {
        if (!self::verify()) {
            http_response_code(419);
            if (self::isAjax()) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'Invalid or expired security token. Please refresh the page.']);
                exit;
            }
            die('Security verification failed (CSRF token mismatch). Please go back, refresh the page, and try again.');
        }
    }

    private static function isAjax(): bool
    {
        return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/');
    }
}
