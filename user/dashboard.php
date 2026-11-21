<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = Auth::requireUser();
if (!StoreManager::isSmmEnabled()) {
    if (StoreManager::isOtpEnabled()) {
        Helpers::redirect('/otp/dashboard.php');
    }
    StoreManager::requireSmmEnabled();
}
ThemeManager::renderUserView('dashboard', get_defined_vars());
