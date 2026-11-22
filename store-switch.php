<?php
/**
 * Dual Store Switcher Route
 * Validates target store ON/OFF state, persists active store in session,
 * and redirects cleanly to the destination store without cross-store mixing.
 */
require_once __DIR__ . '/includes/bootstrap.php';

$to = strtolower(trim((string)($_GET['to'] ?? '')));
if ($to !== 'otp' && $to !== 'smm') {
    $to = StoreManager::isSmmEnabled() ? 'smm' : (StoreManager::isOtpEnabled() ? 'otp' : 'smm');
}

$user = Auth::user();

if ($to === 'otp') {
    StoreManager::requireOtpEnabled(false);
    StoreManager::setActiveStore('otp');
    Helpers::redirect($user ? '/otp/dashboard.php' : '/otp/');
}

StoreManager::requireSmmEnabled(false);
StoreManager::setActiveStore('smm');
Helpers::redirect($user ? '/user/dashboard.php' : '/');
