<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (Auth::user()) {
    Helpers::redirect('/user/dashboard.php');
}

if (Helpers::setting('registration_enabled', '1') !== '1') {
    Helpers::setFlash('error', 'New registrations are currently disabled by the administrator.');
    Helpers::redirect('/login.php');
}

$refCodeParam = Validator::str($_GET['ref'] ?? ($_POST['ref_code'] ?? ''), 32);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $fullName = Validator::str($_POST['full_name'] ?? '', 120);
    $username = Validator::username($_POST['username'] ?? '');
    $email = Validator::email($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirm'] ?? '');

    if ($fullName === '' || !$username || !$email) {
        $error = 'Please enter a valid full name, username (3-50 alphanumeric), and email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (Database::fetch("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1", [$username, $email])) {
        $error = 'An account with this username or email already exists.';
    } else {
        $referrerId = null;
        if ($refCodeParam !== '') {
            $refUser = Database::fetch("SELECT id FROM users WHERE referral_code = ? AND status = 'active' LIMIT 1", [$refCodeParam]);
            if ($refUser) {
                $referrerId = (int)$refUser['id'];
            }
        }

        $verifyRequired = Helpers::setting('email_verification_required', '0') === '1';
        $verifyToken = $verifyRequired ? bin2hex(random_bytes(24)) : null;
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $apiKey = Auth::generateApiKey();
        $myRefCode = Auth::generateReferralCode();

        $userId = Database::insert(
            "INSERT INTO users (username, email, password_hash, full_name, api_key, referral_code, referred_by, email_verified_at, email_verify_token, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')",
            [
                $username,
                $email,
                $hash,
                $fullName,
                $apiKey,
                $myRefCode,
                $referrerId,
                $verifyRequired ? null : date('Y-m-d H:i:s'),
                $verifyToken,
            ]
        );

        if ($referrerId !== null) {
            $commRate = Helpers::setting('referral_commission_percent', '5.00');
            Database::insert(
                "INSERT INTO referrals (referrer_user_id, referred_user_id, commission_rate) VALUES (?, ?, ?)",
                [$referrerId, $userId, $commRate]
            );
        }

        Logger::activity('user', $userId, 'user.register', 'user', (string)$userId, 'New user registered');

        if ($verifyRequired && $verifyToken) {
            $appUrl = rtrim((string)($GLOBALS['APP_CONFIG']['app']['url'] ?? ''), '/');
            $link = $appUrl . '/verify-email.php?token=' . urlencode($verifyToken);
            Mailer::send($email, 'Verify your account — ' . Helpers::setting('site_name', 'SMM PRO'), '<p>Click below to verify your email:</p><p><a href="' . Validator::e($link) . '">' . Validator::e($link) . '</a></p>');
            Helpers::setFlash('success', 'Registration successful! Please verify your email address before signing in.');
            Helpers::redirect('/login.php');
        }

        Auth::loginUserSession($userId);
        Helpers::setFlash('success', 'Welcome! Your account has been created.');
        Helpers::redirect('/user/dashboard.php');
    }
}

$pageTitle = 'Create Account — ' . Helpers::setting('site_name', 'SMM PRO');
require __DIR__ . '/includes/public_header.php';
?>
<main class="max-w-lg mx-auto px-4 py-12">
    <div class="smm-card rounded-3xl p-8">
        <div class="text-center mb-6">
            <h1 class="text-2xl font-extrabold text-slate-900">Create Your Account</h1>
            <p class="text-sm text-slate-500 mt-1">Start placing orders and scaling campaigns in seconds.</p>
        </div>

        <?php if ($error): ?>
        <div class="mb-5 p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm font-medium">
            <?= Validator::e($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <?= CSRF::field() ?>
            <input type="hidden" name="ref_code" value="<?= Validator::e($refCodeParam) ?>">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Full Name</label>
                    <input type="text" name="full_name" value="<?= Validator::e($_POST['full_name'] ?? '') ?>" required class="w-full px-4 py-2.5 rounded-xl border border-purple-200 focus:border-purple-600 outline-none text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Username</label>
                    <input type="text" name="username" value="<?= Validator::e($_POST['username'] ?? '') ?>" required class="w-full px-4 py-2.5 rounded-xl border border-purple-200 focus:border-purple-600 outline-none text-sm">
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Email Address</label>
                <input type="email" name="email" value="<?= Validator::e($_POST['email'] ?? '') ?>" required class="w-full px-4 py-2.5 rounded-xl border border-purple-200 focus:border-purple-600 outline-none text-sm">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Password</label>
                    <input type="password" name="password" required minlength="8" class="w-full px-4 py-2.5 rounded-xl border border-purple-200 focus:border-purple-600 outline-none text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Confirm Password</label>
                    <input type="password" name="password_confirm" required minlength="8" class="w-full px-4 py-2.5 rounded-xl border border-purple-200 focus:border-purple-600 outline-none text-sm">
                </div>
            </div>
            <?php if ($refCodeParam !== ''): ?>
            <div class="px-3.5 py-2 rounded-xl bg-purple-50 text-purple-800 text-xs font-semibold">
                Referral Code Applied: <strong><?= Validator::e($refCodeParam) ?></strong>
            </div>
            <?php endif; ?>
            <div class="text-xs text-slate-500">
                By creating an account, you agree to our <a href="/terms.php" class="text-purple-600 underline">Terms & Conditions</a> and <a href="/privacy.php" class="text-purple-600 underline">Privacy Policy</a>.
            </div>
            <button type="submit" class="w-full py-3 rounded-xl bg-gradient-to-r from-purple-600 to-violet-600 hover:from-purple-700 hover:to-violet-700 text-white font-bold text-sm shadow-lg shadow-purple-500/25 transition">
                Create Account
            </button>
        </form>

        <p class="text-center text-sm text-slate-500 mt-6">
            Already registered? <a href="/login.php" class="font-bold text-purple-600 hover:text-purple-800">Sign In</a>
        </p>
    </div>
</main>
<?php require __DIR__ . '/includes/public_footer.php'; ?>
