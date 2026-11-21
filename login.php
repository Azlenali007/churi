<?php
require_once __DIR__ . '/includes/bootstrap.php';

$resolvePostLoginUrl = function (): string {
    $next = trim((string)($_POST['next'] ?? $_GET['next'] ?? ''));
    if ($next !== '' && str_starts_with($next, '/') && !str_starts_with($next, '//') && !str_contains($next, "\n") && !str_contains($next, "\r")) {
        return $next;
    }
    if (StoreManager::isSmmEnabled()) {
        return '/user/dashboard.php';
    }
    if (StoreManager::isOtpEnabled()) {
        return '/otp/dashboard.php';
    }
    return '/user/profile.php';
};

if (Auth::user()) {
    Helpers::redirect($resolvePostLoginUrl());
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $login = Validator::str($_POST['login'] ?? '', 150);
    $password = (string)($_POST['password'] ?? '');

    if ($login === '' || $password === '') {
        $error = 'Please enter your username/email and password.';
    } else {
        $res = Auth::attemptUserLogin($login, $password);
        if ($res['ok']) {
            Helpers::redirect($resolvePostLoginUrl());
        }
        $error = $res['error'] ?? 'Login failed.';
    }
}

$pageTitle = 'Sign In — ' . Helpers::setting('site_name', 'SMM PRO');
require __DIR__ . '/includes/public_header.php';
?>
<main class="max-w-md mx-auto px-4 py-14">
    <div class="smm-card rounded-3xl p-8">
        <div class="text-center mb-6">
            <h1 class="text-2xl font-extrabold text-slate-900">Welcome Back</h1>
            <p class="text-sm text-slate-500 mt-1">Sign in to manage orders, wallet funds, and API keys.</p>
        </div>

        <?php if ($error): ?>
        <div class="mb-5 p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm font-medium">
            <?= Validator::e($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <?= CSRF::field() ?>
            <?php if (!empty($_GET['next']) || !empty($_POST['next'])): ?>
                <input type="hidden" name="next" value="<?= Validator::e((string)($_POST['next'] ?? $_GET['next'] ?? '')) ?>">
            <?php endif; ?>
            <div>
                <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Username or Email</label>
                <input type="text" name="login" value="<?= Validator::e($_POST['login'] ?? '') ?>" required autofocus
                       class="w-full px-4 py-3 rounded-xl border border-purple-200 focus:border-purple-600 focus:ring-2 focus:ring-purple-100 outline-none text-sm">
            </div>
            <div>
                <div class="flex items-center justify-between mb-1">
                    <label class="block text-xs font-bold text-slate-600 uppercase">Password</label>
                    <a href="/forgot-password.php" class="text-xs font-semibold text-purple-600 hover:text-purple-800">Forgot Password?</a>
                </div>
                <input type="password" name="password" required
                       class="w-full px-4 py-3 rounded-xl border border-purple-200 focus:border-purple-600 focus:ring-2 focus:ring-purple-100 outline-none text-sm">
            </div>
            <button type="submit" class="w-full py-3 rounded-xl bg-gradient-to-r from-purple-600 to-violet-600 hover:from-purple-700 hover:to-violet-700 text-white font-bold text-sm shadow-lg shadow-purple-500/25 transition">
                Sign In to Dashboard
            </button>
        </form>

        <p class="text-center text-sm text-slate-500 mt-6">
            New here? <a href="/register.php" class="font-bold text-purple-600 hover:text-purple-800">Create an account</a>
        </p>
    </div>
</main>
<?php require __DIR__ . '/includes/public_footer.php'; ?>
