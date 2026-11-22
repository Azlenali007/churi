<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = Auth::requireUser();
$storeContext = StoreManager::resolveStoreContext('smm');
$selfUrl = '/user/notifications.php' . ($storeContext === 'otp' ? '?store=otp' : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    CSRF::requireValid();
    Database::execute("UPDATE notifications SET is_read = 1 WHERE user_id = ?", [$user['id']]);
    Helpers::setFlash('success', 'All notifications marked as read.');
    Helpers::redirect($selfUrl);
}

ThemeManager::renderUserView('notifications', get_defined_vars());
