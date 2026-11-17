<?php
require_once __DIR__ . '/includes/bootstrap.php';
$info = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $email = Validator::email($_POST['email'] ?? '');
    if (!$email) {
        $error = 'Please enter a valid email address.';
    } else {
        $user = Database::fetch("SELECT id, email, full_name FROM users WHERE email = ? LIMIT 1", [$email]);
        if ($user) {
            $token = bin2hex(random_bytes(24));
            Database::execute(
                "UPDATE users SET reset_token = ?, reset_expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?",
                [$token, $user['id']]
            );
            $appUrl = rtrim((string)($GLOBALS['APP_CONFIG']['app']['url'] ?? ''), '/');
            $link = $appUrl . '/reset-password.php?token=' . urlencode($token);
            $mailRes = Mailer::send(
                $user['email'],
                'Password Reset — ' . Helpers::setting('site_name', 'SMM PRO'),
                '<p>Hello ' . Validator::e($user['full_name']) . ',</p><p>Click the link below to reset your password (valid for 1 hour):</p><p><a href="' . Validator::e($link) . '">' . Validator::e($link) . '</a></p>'
            );
            if (!$mailRes['ok']) {
                $error = 'Email dispatch failed: ' . ($mailRes['error'] ?? 'SMTP not configured.');
            } else {
                $info = 'If an account matches that email, a password reset link has been sent.';
            }
        } else {
            $info = 'If an account matches that email, a password reset link has been sent.';
        }
    }
}

$pageTitle = 'Forgot Password — ' . Helpers::setting('site_name', 'SMM PRO');
require __DIR__ . '/includes/public_header.php';
?>
<main class="max-w-md mx-auto px-4 py-14">
    <div class="smm-card rounded-3xl p-8">
        <h1 class="text-2xl font-extrabold text-slate-900 mb-2">Forgot Password</h1>
        <p class="text-sm text-slate-500 mb-6">Enter your registered email address to receive a password reset link.</p>
        <?php if ($info): ?><div class="mb-4 p-3.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-medium"><?= Validator::e($info) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="mb-4 p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm font-medium"><?= Validator::e($error) ?></div><?php endif; ?>
        <form method="POST" class="space-y-4">
            <?= CSRF::field() ?>
            <div>
                <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Email Address</label>
                <input type="email" name="email" required class="w-full px-4 py-3 rounded-xl border border-purple-200 focus:border-purple-600 outline-none text-sm">
            </div>
            <button type="submit" class="w-full py-3 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-bold text-sm">Send Reset Link</button>
        </form>
        <div class="text-center mt-5"><a href="/login.php" class="text-sm font-semibold text-purple-600">Back to Sign In</a></div>
    </div>
</main>
<?php require __DIR__ . '/includes/public_footer.php'; ?>
