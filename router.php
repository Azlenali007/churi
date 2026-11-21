<?php
/**
 * Lightweight PHP Built-in Web Server Router (used only when running `php -S`)
 * On cPanel / Apache / Nginx, requests route directly to the PHP files via .htaccess.
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$fullPath = __DIR__ . $uri;

// Block direct HTTP access to protected folders even on php -S
if (preg_match('#^/(config|core|includes|cron|database)/#', $uri) || preg_match('#\.(sql|log|env|ini|sh|bak)$#i', $uri)) {
    http_response_code(403);
    exit('403 Forbidden');
}

// Route versioned OTP Reseller REST API (/api/otp/v1/*)
if (preg_match('#^/api/otp/v1(?:/(.*))?$#', $uri, $matches)) {
    $_GET['route'] = trim((string)($matches[1] ?? ''), '/');
    require __DIR__ . '/api/otp/v1/index.php';
    return true;
}

if ($uri !== '/' && file_exists($fullPath) && is_file($fullPath)) {
    return false; // Let PHP built-in server serve static file or PHP script
}

if ($uri !== '/' && is_dir($fullPath) && file_exists($fullPath . '/index.php')) {
    require $fullPath . '/index.php';
    return true;
}

require __DIR__ . '/index.php';
