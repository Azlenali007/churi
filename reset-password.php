<?php
require_once __DIR__ . '/includes/bootstrap.php';
$token = Validator::str($_GET['token'] ?? ($_POST['token'] ?? ''), 128);
$error = '';
$user = $token !== '' ? Database::fetch("SELECT id FROM users WHERE reset_token = ? AND reset_expires_at > NOW() LIMIT 1", [$token]) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $pass = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirm'] ?? '');
    if (!$user) {
        $error = 'This password reset link is invalid or expired.';
    } elseif (strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pass !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        Database::execute(
            "UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires_at = NULL WHERE id = ?",
            [password_hash($pass, PASSWORD_DEFAULT), $user['id']]
        );
        Helpers::setFlash('success', 'Your password has been updated. You may now sign in.');
        Helpers::redirect('/login.php');
    }
}

$pageTitle = 'Reset Password — ' . Helpers::setting('site_name', 'SMM PRO');
require __DIR__ . '/includes/public_header.php';
?>
<main class="max-w-md mx-auto px-4 py-14">
    <div class="smm-card rounded-3xl p-8">
        <h1 class="text-2xl font-extrabold text-slate-900 mb-2">Set New Password</h1>
        <?php if ($error): ?><div class="mb-4 p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm font-medium"><?= Validator::e($error) ?></div><?php endif; ?>
        <?php if (!$user): ?>
            <p class="text-sm text-rose-600 mb-4">This reset token is invalid or has expired.</p>
            <a href="/forgot-password.php" class="inline-flex px-5 py-2.5 rounded-xl bg-purple-600 text-white text-sm font-bold">Request New Link</a>
        <?php else: ?>
            <form method="POST" class="space-y-4">
                <?= CSRF::field() ?>
                <input type="hidden" name="token" value="<?= Validator::e($token) ?>">
                <div>
                    <label class="block text-xs font-bold text-slate-600 uppercase mb-1">New Password</label>
                    <input type="password" name="password" required minlength="8" class="w-full px-4 py-3 rounded-xl border border-purple-200 focus:border-purple-600 outline-none text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Confirm Password</label>
                    <input type="password" name="password_confirm" required minlength="8" class="w-full px-4 py-3 rounded-xl border border-purple-200 focus:border-purple-600 outline-none text-sm">
                </div>
                <button type="submit" class="w-full py-3 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-bold text-sm">Update Password</button>
            </form>
        <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/includes/public_footer.php'; ?>
