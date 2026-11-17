<?php
/**
 * Lightweight PHP Built-in Web Server Router (used only when running `php -S`)
 * On cPanel / Apache / Nginx, requests route directly to the PHP files.
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$fullPath = __DIR__ . $uri;

// Block direct HTTP access to protected folders even on php -S
if (preg_match('#^/(config|core|includes|cron|database)/#', $uri) || preg_match('#\.(sql|log|env|ini|sh|bak)$#i', $uri)) {
    http_response_code(403);
    exit('403 Forbidden');
}

if ($uri !== '/' && file_exists($fullPath) && is_file($fullPath)) {
    return false; // Let PHP built-in server serve static file or PHP script
}

if ($uri !== '/' && is_dir($fullPath) && file_exists($fullPath . '/index.php')) {
    require $fullPath . '/index.php';
    return true;
}

require __DIR__ . '/index.php';
