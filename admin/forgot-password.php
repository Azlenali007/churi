<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';

$info = '';
$error = '';
$token = Validator::str($_GET['token'] ?? ($_POST['token'] ?? ''), 128);

if ($token !== '' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset') {
    CSRF::requireValid();
    $pass = (string)($_POST['password'] ?? '');
    $adm = Database::fetch("SELECT id FROM admins WHERE reset_token = ? AND reset_expires_at > NOW() LIMIT 1", [$token]);
    if (!$adm) {
        $error = 'Invalid or expired reset token.';
    } elseif (strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        Database::execute("UPDATE admins SET password_hash = ?, reset_token = NULL, reset_expires_at = NULL WHERE id = ?", [password_hash($pass, PASSWORD_DEFAULT), $adm['id']]);
        Helpers::redirect('/admin/login.php');
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $email = Validator::email($_POST['email'] ?? '');
    if ($email) {
        $adm = Database::fetch("SELECT id, email, full_name FROM admins WHERE email = ? LIMIT 1", [$email]);
        if ($adm) {
            $tok = bin2hex(random_bytes(24));
            Database::execute("UPDATE admins SET reset_token = ?, reset_expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?", [$tok, $adm['id']]);
            $appUrl = rtrim((string)($GLOBALS['APP_CONFIG']['app']['url'] ?? ''), '/');
            $link = $appUrl . '/admin/forgot-password.php?token=' . urlencode($tok);
            Mailer::send($adm['email'], 'Admin Password Reset', '<p>Reset link: <a href="' . Validator::e($link) . '">' . Validator::e($link) . '</a></p>');
        }
        $info = 'If an admin account exists for that email, a reset link was dispatched.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Forgot Password</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#1B1145] min-h-screen flex items-center justify-center p-4">
<div class="max-w-md w-full bg-white rounded-3xl shadow-2xl p-8">
    <h1 class="text-xl font-extrabold text-slate-900 mb-2">Admin Password Recovery</h1>
    <?php if ($info): ?><div class="mb-4 p-3 rounded-xl bg-emerald-50 text-emerald-700 text-xs font-semibold"><?= Validator::e($info) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="mb-4 p-3 rounded-xl bg-rose-50 text-rose-700 text-xs font-semibold"><?= Validator::e($error) ?></div><?php endif; ?>
    <?php if ($token !== ''): ?>
        <form method="POST" class="space-y-4">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="token" value="<?= Validator::e($token) ?>">
            <input type="password" name="password" required minlength="8" placeholder="New Admin Password" class="w-full px-4 py-3 rounded-xl border border-purple-200 text-sm">
            <button type="submit" class="w-full py-3 rounded-xl bg-purple-600 text-white font-bold text-sm">Reset Password</button>
        </form>
    <?php else: ?>
        <form method="POST" class="space-y-4">
            <?= CSRF::field() ?>
            <input type="email" name="email" required placeholder="Admin Email Address" class="w-full px-4 py-3 rounded-xl border border-purple-200 text-sm">
            <button type="submit" class="w-full py-3 rounded-xl bg-purple-600 text-white font-bold text-sm">Send Reset Link</button>
        </form>
    <?php endif; ?>
    <div class="text-center mt-4"><a href="/admin/login.php" class="text-xs font-bold text-purple-600">Back to Admin Login</a></div>
</div>
</body>
</html>
