<?php
/**
 * Core Application Bootstrap
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/CSRF.php';
require_once __DIR__ . '/../core/Validator.php';
require_once __DIR__ . '/../core/Currency.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Wallet.php';
require_once __DIR__ . '/../core/FlashSale.php';
require_once __DIR__ . '/../core/Popup.php';
require_once __DIR__ . '/../core/Uploader.php';
require_once __DIR__ . '/../core/Mailer.php';
require_once __DIR__ . '/../core/ProviderAPI.php';
require_once __DIR__ . '/../core/CategoryMapper.php';
require_once __DIR__ . '/../core/OrderService.php';
require_once __DIR__ . '/../core/PaymentService.php';

// Secure error handling: log detailed errors, never display raw credentials or blank pages
error_reporting(E_ALL);
ini_set('display_errors', '0');

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    Logger::errorFile("PHP Error [$severity]: $message in $file:$line");
    return false;
});

set_exception_handler(function (Throwable $e): void {
    Logger::errorFile('Uncaught Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'An internal server error occurred. Please check system logs.']);
        exit;
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Service Error</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-slate-50 min-h-screen flex items-center justify-center p-6"><div class="max-w-md w-full bg-white rounded-2xl shadow-lg border border-purple-100 p-8 text-center"><div class="w-12 h-12 mx-auto mb-4 rounded-full bg-rose-50 text-rose-600 flex items-center justify-center"><svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg></div><h1 class="text-xl font-bold text-slate-900 mb-2">Something went wrong</h1><p class="text-sm text-slate-600 mb-6">An unexpected server error occurred. The incident has been logged securely.</p><a href="/" class="inline-flex items-center px-5 py-2.5 rounded-xl bg-purple-600 text-white text-sm font-semibold hover:bg-purple-700">Return Home</a></div></body></html>';
    exit;
});

$configFile = APP_ROOT . '/config/config.php';
$isInstaller = str_starts_with($_SERVER['SCRIPT_NAME'] ?? '', '/install/') || str_contains($_SERVER['REQUEST_URI'] ?? '', '/install');

if (!file_exists($configFile)) {
    if (!$isInstaller) {
        header('Location: /install/index.php');
        exit;
    }
    return;
}

$APP_CONFIG = require $configFile;
$GLOBALS['APP_CONFIG'] = $APP_CONFIG;

try {
    Database::connect($APP_CONFIG['db']);
} catch (Throwable $e) {
    Logger::errorFile('Database connection error: ' . $e->getMessage());
    if (!$isInstaller) {
        http_response_code(503);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Database Unavailable</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-slate-50 min-h-screen flex items-center justify-center p-6"><div class="max-w-md w-full bg-white rounded-2xl shadow-lg border border-purple-100 p-8 text-center"><h1 class="text-xl font-bold text-slate-900 mb-2">Database Connection Unavailable</h1><p class="text-sm text-slate-600 mb-4">Unable to connect to MySQL. Please verify your database configuration or run the installer.</p><a href="/install/index.php" class="inline-flex px-5 py-2.5 rounded-xl bg-purple-600 text-white text-sm font-semibold">Open Installer</a></div></body></html>';
        exit;
    }
}

// Configure timezone from settings and sync MySQL session time_zone
$tz = Helpers::setting('timezone', 'Asia/Kolkata');
if (@date_default_timezone_set($tz) === false) {
    date_default_timezone_set('UTC');
}
try {
    $tzOffset = date('P');
    Database::pdo()->exec("SET time_zone = " . Database::pdo()->quote($tzOffset));
} catch (Throwable $e) {
    // Ignore if DB connection was unavailable
}

// Maintenance mode check for non-admin pages
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$isAdminRoute = str_starts_with($uri, '/admin') || str_starts_with($_SERVER['SCRIPT_NAME'] ?? '', '/admin/');
$isApiRoute = str_starts_with($uri, '/api') || str_starts_with($_SERVER['SCRIPT_NAME'] ?? '', '/api/');

if (!$isAdminRoute && !$isApiRoute && !$isInstaller && Helpers::setting('maintenance_mode', '0') === '1') {
    if (!Auth::admin()) {
        http_response_code(503);
        $msg = Helpers::setting('maintenance_message', 'Scheduled maintenance in progress.');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Maintenance</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-gradient-to-br from-[#1B1145] to-purple-900 min-h-screen flex items-center justify-center p-6 text-white"><div class="max-w-lg w-full bg-white/10 backdrop-blur-lg rounded-3xl p-8 text-center border border-white/20"><div class="w-16 h-16 mx-auto mb-5 rounded-2xl bg-purple-500/30 flex items-center justify-center"><svg class="w-8 h-8 text-purple-200" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></div><h1 class="text-2xl font-bold mb-3">' . Validator::e(Helpers::setting('site_name', 'SMM PRO')) . ' — Maintenance</h1><p class="text-purple-100 text-sm leading-relaxed">' . Validator::e($msg) . '</p></div></body></html>';
        exit;
    }
}
