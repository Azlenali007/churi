<?php
require_once __DIR__ . '/includes/bootstrap.php';
$token = Validator::str($_GET['token'] ?? '', 128);
if ($token !== '') {
    $user = Database::fetch("SELECT id FROM users WHERE email_verify_token = ? LIMIT 1", [$token]);
    if ($user) {
        Database::execute("UPDATE users SET email_verified_at = NOW(), email_verify_token = NULL WHERE id = ?", [$user['id']]);
        Helpers::setFlash('success', 'Email address verified successfully! You can now sign in.');
        Helpers::redirect('/login.php');
    }
}
Helpers::setFlash('error', 'Invalid or expired email verification token.');
Helpers::redirect('/login.php');
