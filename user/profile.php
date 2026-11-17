<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = Auth::requireUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $fullName = Validator::str($_POST['full_name'] ?? '', 120);
        $email = Validator::email($_POST['email'] ?? '');
        $phone = Validator::str($_POST['phone'] ?? '', 30);
        if ($fullName === '' || !$email) {
            Helpers::setFlash('error', 'Please enter a valid full name and email.');
        } elseif (Database::fetch("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1", [$email, $user['id']])) {
            Helpers::setFlash('error', 'That email address is already in use.');
        } else {
            $prefs = json_encode([
                'order_updates'  => !empty($_POST['pref_orders']),
                'payment_alerts' => !empty($_POST['pref_payments']),
                'promo_emails'   => !empty($_POST['pref_promo']),
            ]);
            Database::execute(
                "UPDATE users SET full_name = ?, email = ?, phone = ?, notification_prefs_json = ? WHERE id = ?",
                [$fullName, $email, $phone, $prefs, $user['id']]
            );
            Helpers::setFlash('success', 'Profile & notification settings updated.');
        }
    } elseif ($action === 'change_password') {
        $curr = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $conf = (string)($_POST['confirm_password'] ?? '');
        if (!password_verify($curr, $user['password_hash'])) {
            Helpers::setFlash('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 8 || $new !== $conf) {
            Helpers::setFlash('error', 'New password must be at least 8 characters and match confirmation.');
        } else {
            Database::execute("UPDATE users SET password_hash = ? WHERE id = ?", [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            Logger::activity('user', (int)$user['id'], 'user.password_changed', 'user', (string)$user['id']);
            Helpers::setFlash('success', 'Password updated successfully.');
        }
    } elseif ($action === 'toggle_api') {
        $next = $user['api_status'] === 'active' ? 'disabled' : 'active';
        Database::execute("UPDATE users SET api_status = ? WHERE id = ?", [$next, $user['id']]);
        Helpers::setFlash('success', 'API status changed to ' . $next . '.');
    }
    Helpers::redirect('/user/profile.php');
}

require APP_ROOT . '/themes/classic/profile.php';
