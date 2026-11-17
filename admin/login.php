<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';

if (Auth::admin()) {
    Helpers::redirect('/admin/index.php');
}

$error = '';
$needs2fa = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $login = Validator::str($_POST['login'] ?? '', 150);
    $password = (string)($_POST['password'] ?? '');
    $totp = Validator::str($_POST['totp_code'] ?? '', 10);

    $res = Auth::attemptAdminLogin($login, $password, $totp);
    if ($res['ok']) {
        Helpers::redirect('/admin/index.php');
    }
    $error = $res['error'] ?? 'Authentication failed.';
    $needs2fa = !empty($res['requires_2fa']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Sign In — <?= Validator::e(Helpers::setting('site_name', 'SMM PRO')) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-[#1B1145] min-h-screen flex items-center justify-center p-4">
<div class="max-w-md w-full bg-white rounded-3xl shadow-2xl p-8 border border-purple-100">
    <div class="text-center mb-6">
        <div class="w-12 h-12 mx-auto mb-3 rounded-2xl bg-gradient-to-br from-purple-600 to-indigo-700 text-white flex items-center justify-center shadow-lg">
            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor"><path d="M5 16L3 5l5.5 5L12 4l3.5 6L21 5l-2 11H5zm0 2h14v2H5v-2z"/></svg>
        </div>
        <h1 class="text-2xl font-extrabold text-slate-900">Administrator Console</h1>
        <p class="text-xs text-slate-500 mt-1">Secured Admin Authentication &amp; RBAC</p>
    </div>

    <?php if ($error): ?>
    <div class="mb-4 p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold">
        <?= Validator::e($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
        <?= CSRF::field() ?>
        <div>
            <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Admin Username or Email</label>
            <input type="text" name="login" value="<?= Validator::e($_POST['login'] ?? '') ?>" required autofocus class="w-full px-4 py-3 rounded-xl border border-purple-200 focus:border-purple-600 outline-none text-sm">
        </div>
        <div>
            <div class="flex items-center justify-between mb-1">
                <label class="block text-xs font-bold text-slate-600 uppercase">Password</label>
                <a href="/admin/forgot-password.php" class="text-xs font-semibold text-purple-600">Forgot?</a>
            </div>
            <input type="password" name="password" value="<?= Validator::e($needs2fa ? ($_POST['password'] ?? '') : '') ?>" required class="w-full px-4 py-3 rounded-xl border border-purple-200 focus:border-purple-600 outline-none text-sm">
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-600 uppercase mb-1">2FA Code (If Enabled)</label>
            <input type="text" name="totp_code" placeholder="6-digit code" maxlength="6" class="w-full px-4 py-2.5 rounded-xl border border-purple-200 focus:border-purple-600 outline-none text-sm">
        </div>
        <button type="submit" class="w-full py-3 rounded-xl bg-gradient-to-r from-purple-600 to-violet-600 hover:from-purple-700 hover:to-violet-700 text-white font-bold text-sm shadow-lg shadow-purple-500/25">
            Sign In to Admin Panel
        </button>
    </form>
</div>
</body>
</html>
