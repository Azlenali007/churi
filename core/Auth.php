<?php
/**
 * Centralized Authentication, Rate Limiting, RBAC & TOTP 2FA
 * Keeps User and Admin sessions strictly separated.
 */

class Auth
{
    public static function startSession(string $type = 'user'): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $config = $GLOBALS['APP_CONFIG']['app'] ?? [];
        $name = $type === 'admin'
            ? ($config['admin_session'] ?? 'SMMPRO_ADMIN_SESSID')
            : ($config['session_name'] ?? 'SMMPRO_SESSID');

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => $isHttps ? 'None' : 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();
    }

    public static function isRateLimited(string $identifier, string $userType = 'user'): bool
    {
        $ip = Helpers::clientIp();
        $count = (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM login_attempts
             WHERE user_type = ? AND success = 0
               AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
               AND (ip_address = ? OR identifier = ?)",
            [$userType, $ip, strtolower($identifier)]
        );
        return $count >= 5;
    }

    public static function recordAttempt(string $identifier, string $userType, bool $success): void
    {
        Database::insert(
            "INSERT INTO login_attempts (identifier, ip_address, user_type, success, user_agent)
             VALUES (?, ?, ?, ?, ?)",
            [
                strtolower(substr($identifier, 0, 150)),
                Helpers::clientIp(),
                $userType,
                $success ? 1 : 0,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]
        );
    }

    public static function attemptUserLogin(string $login, string $password): array
    {
        if (self::isRateLimited($login, 'user')) {
            return ['ok' => false, 'error' => 'Too many failed login attempts. Please wait 15 minutes and try again.'];
        }

        $user = Database::fetch(
            "SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1",
            [strtolower(trim($login)), trim($login)]
        );

        if (!$user || !password_verify($password, $user['password_hash'])) {
            self::recordAttempt($login, 'user', false);
            return ['ok' => false, 'error' => 'Invalid username/email or password.'];
        }

        if ($user['status'] === 'suspended') {
            self::recordAttempt($login, 'user', false);
            return ['ok' => false, 'error' => 'Your account has been suspended. Please contact support.'];
        }

        if (Helpers::setting('email_verification_required', '0') === '1' && empty($user['email_verified_at'])) {
            return ['ok' => false, 'error' => 'Please verify your email address before signing in.'];
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Database::execute("UPDATE users SET password_hash = ? WHERE id = ?", [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }

        self::recordAttempt($login, 'user', true);
        self::loginUserSession((int)$user['id']);
        Database::execute("UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?", [Helpers::clientIp(), $user['id']]);
        Logger::activity('user', (int)$user['id'], 'user.login', 'user', (string)$user['id'], 'User logged in');

        return ['ok' => true, 'user' => $user];
    }

    public static function loginUserSession(int $userId): void
    {
        self::startSession('user');
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_logged_at'] = time();
    }

    public static function user(): ?array
    {
        self::startSession('user');
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            return null;
        }
        $user = Database::fetch("SELECT * FROM users WHERE id = ? LIMIT 1", [$uid]);
        if (!$user || $user['status'] === 'suspended') {
            unset($_SESSION['user_id']);
            return null;
        }
        return $user;
    }

    public static function requireUser(): array
    {
        $user = self::user();
        if (!$user) {
            Helpers::setFlash('error', 'Please log in to access your dashboard.');
            Helpers::redirect('/login.php');
        }
        return $user;
    }

    public static function logoutUser(): void
    {
        self::startSession('user');
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid > 0) {
            Logger::activity('user', $uid, 'user.logout', 'user', (string)$uid, 'User logged out');
        }
        $_SESSION = [];
        session_destroy();
    }

    // ---------------- ADMIN AUTHENTICATION ----------------

    public static function attemptAdminLogin(string $login, string $password, string $totpCode = ''): array
    {
        if (self::isRateLimited($login, 'admin')) {
            return ['ok' => false, 'error' => 'Too many failed admin login attempts. Locked for 15 minutes.'];
        }

        $admin = Database::fetch(
            "SELECT a.*, r.name AS role_name, r.slug AS role_slug, r.permissions_json
             FROM admins a
             JOIN roles r ON r.id = a.role_id
             WHERE a.email = ? OR a.username = ? LIMIT 1",
            [strtolower(trim($login)), trim($login)]
        );

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            self::recordAttempt($login, 'admin', false);
            return ['ok' => false, 'error' => 'Invalid administrator credentials.'];
        }

        if ($admin['status'] !== 'active') {
            self::recordAttempt($login, 'admin', false);
            return ['ok' => false, 'error' => 'Administrator account is suspended.'];
        }

        if ((int)$admin['two_factor_enabled'] === 1 && !empty($admin['two_factor_secret'])) {
            if ($totpCode === '') {
                return ['ok' => false, 'requires_2fa' => true, 'error' => 'Enter your 6-digit authenticator code.'];
            }
            if (!self::verifyTotp($admin['two_factor_secret'], $totpCode)) {
                self::recordAttempt($login, 'admin', false);
                return ['ok' => false, 'requires_2fa' => true, 'error' => 'Invalid 2FA verification code.'];
            }
        }

        self::recordAttempt($login, 'admin', true);
        self::startSession('admin');
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$admin['id'];
        $_SESSION['admin_logged_at'] = time();

        Database::execute("UPDATE admins SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?", [Helpers::clientIp(), $admin['id']]);
        Logger::activity('admin', (int)$admin['id'], 'admin.login', 'admin', (string)$admin['id'], 'Admin signed in');

        return ['ok' => true, 'admin' => $admin];
    }

    public static function admin(): ?array
    {
        self::startSession('admin');
        $aid = (int)($_SESSION['admin_id'] ?? 0);
        if ($aid <= 0) {
            return null;
        }
        $admin = Database::fetch(
            "SELECT a.*, r.name AS role_name, r.slug AS role_slug, r.permissions_json
             FROM admins a
             JOIN roles r ON r.id = a.role_id
             WHERE a.id = ? LIMIT 1",
            [$aid]
        );
        if (!$admin || $admin['status'] !== 'active') {
            unset($_SESSION['admin_id']);
            return null;
        }
        $admin['permissions'] = json_decode((string)$admin['permissions_json'], true) ?: [];
        return $admin;
    }

    public static function requireAdmin(?string $permission = null): array
    {
        $admin = self::admin();
        if (!$admin) {
            Helpers::redirect('/admin/login.php');
        }
        if ($permission !== null && !self::adminHasPermission($admin, $permission)) {
            http_response_code(403);
            die('Access Denied: You do not have permission (' . Validator::e($permission) . ') for this section.');
        }
        return $admin;
    }

    public static function adminHasPermission(array $admin, string $permission): bool
    {
        $perms = $admin['permissions'] ?? [];
        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    public static function logoutAdmin(): void
    {
        self::startSession('admin');
        $aid = (int)($_SESSION['admin_id'] ?? 0);
        if ($aid > 0) {
            Logger::activity('admin', $aid, 'admin.logout', 'admin', (string)$aid, 'Admin signed out');
        }
        $_SESSION = [];
        session_destroy();
    }

    // ---------------- PURE PHP RFC 6238 TOTP 2FA ----------------

    public static function generateTotpSecret(int $length = 16): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }
        return $secret;
    }

    public static function verifyTotp(string $base32Secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', (string)$code)) {
            return false;
        }
        $key = self::base32Decode($base32Secret);
        if ($key === '') {
            return false;
        }
        $timeSlice = (int)floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            $calc = self::calcTotp($key, $timeSlice + $i);
            if (hash_equals($calc, $code)) {
                return true;
            }
        }
        return false;
    }

    private static function calcTotp(string $key, int $counter): string
    {
        $binCounter = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $binCounter, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $part = substr($hash, $offset, 4);
        $val = unpack('N', $part)[1] & 0x7FFFFFFF;
        return str_pad((string)($val % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret));
        $binary = '';
        for ($i = 0, $len = strlen($secret); $i < $len; $i++) {
            $pos = strpos($alphabet, $secret[$i]);
            if ($pos === false) continue;
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    public static function generateApiKey(): string
    {
        return bin2hex(random_bytes(24));
    }

    public static function generateReferralCode(): string
    {
        return strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
    }
}
