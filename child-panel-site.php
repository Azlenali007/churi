<?php
/**
 * Isolated Child Panel Storefront (Multi-Tenant Isolated by child_panel_id)
 * Supports accessing by ?panel_id=ID or matching HTTP_HOST against child_panels.domain.
 */
require_once __DIR__ . '/includes/bootstrap.php';

$host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
$panelId = (int)($_GET['panel_id'] ?? 0);

$panel = $panelId > 0
    ? Database::fetch("SELECT * FROM child_panels WHERE id = ? LIMIT 1", [$panelId])
    : Database::fetch("SELECT * FROM child_panels WHERE LOWER(domain) = ? LIMIT 1", [$host]);

if (!$panel || $panel['status'] !== 'active') {
    http_response_code(404);
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Panel Unavailable</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-slate-50 min-h-screen flex items-center justify-center p-6"><div class="bg-white rounded-2xl p-8 shadow text-center max-w-md"><h1 class="text-lg font-bold text-slate-900 mb-2">Child Panel Not Active</h1><p class="text-sm text-slate-500">This child panel is pending approval, suspended, or not found.</p></div></body></html>');
}

Auth::startSession('user');
$cpSessionKey = 'cp_user_' . (int)$panel['id'];
$cpUser = null;
if (!empty($_SESSION[$cpSessionKey])) {
    // Strictly enforce child_panel_id isolation
    $cpUser = Database::fetch(
        "SELECT * FROM child_panel_users WHERE id = ? AND child_panel_id = ? AND status = 'active' LIMIT 1",
        [(int)$_SESSION[$cpSessionKey], (int)$panel['id']]
    );
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $act = $_POST['action'] ?? '';
    if ($act === 'cp_register') {
        $uname = Validator::username($_POST['username'] ?? '');
        $email = Validator::email($_POST['email'] ?? '');
        $name = Validator::str($_POST['full_name'] ?? '', 120);
        $pass = (string)($_POST['password'] ?? '');
        if (!$uname || !$email || $name === '' || strlen($pass) < 6) {
            $error = 'Enter valid details (password min 6 chars).';
        } elseif (Database::fetch("SELECT id FROM child_panel_users WHERE child_panel_id = ? AND (email = ? OR username = ?) LIMIT 1", [$panel['id'], $email, $uname])) {
            $error = 'Username or email already registered on this panel.';
        } else {
            $uid = Database::insert(
                "INSERT INTO child_panel_users (child_panel_id, username, email, password_hash, full_name, api_key) VALUES (?, ?, ?, ?, ?, ?)",
                [$panel['id'], $uname, $email, password_hash($pass, PASSWORD_DEFAULT), $name, Auth::generateApiKey()]
            );
            $_SESSION[$cpSessionKey] = $uid;
            Helpers::redirect('/child-panel-site.php?panel_id=' . (int)$panel['id']);
        }
    } elseif ($act === 'cp_login') {
        $login = Validator::str($_POST['login'] ?? '', 150);
        $pass = (string)($_POST['password'] ?? '');
        $u = Database::fetch(
            "SELECT * FROM child_panel_users WHERE child_panel_id = ? AND (email = ? OR username = ?) LIMIT 1",
            [$panel['id'], strtolower($login), $login]
        );
        if ($u && password_verify($pass, $u['password_hash']) && $u['status'] === 'active') {
            $_SESSION[$cpSessionKey] = (int)$u['id'];
            Helpers::redirect('/child-panel-site.php?panel_id=' . (int)$panel['id']);
        }
        $error = 'Invalid credentials for this panel.';
    } elseif ($act === 'cp_logout') {
        unset($_SESSION[$cpSessionKey]);
        Helpers::redirect('/child-panel-site.php?panel_id=' . (int)$panel['id']);
    }
}

$cpServices = Database::fetchAll(
    "SELECT cps.*, s.min_qty, s.max_qty, s.platform
     FROM child_panel_services cps
     JOIN services s ON s.id = cps.parent_service_id
     WHERE cps.child_panel_id = ? AND cps.status = 'active' AND s.status = 'active'",
    [$panel['id']]
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= Validator::e($panel['panel_name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#F6F4FC] min-h-screen text-slate-900">
<header class="bg-white border-b border-purple-100">
    <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <?php if ($panel['logo_path']): ?>
                <img src="<?= Validator::e($panel['logo_path']) ?>" class="h-9 w-9 rounded-xl object-cover">
            <?php endif; ?>
            <span class="font-extrabold text-lg" style="color: <?= Validator::e($panel['primary_color']) ?>"><?= Validator::e($panel['panel_name']) ?></span>
        </div>
        <?php if ($cpUser): ?>
            <div class="flex items-center gap-4 text-sm">
                <span class="font-bold">Balance: <?= Currency::format((string)$cpUser['wallet_balance']) ?></span>
                <form method="POST"><input type="hidden" name="action" value="cp_logout"><?= CSRF::field() ?><button class="text-rose-600 font-bold">Logout</button></form>
            </div>
        <?php endif; ?>
    </div>
</header>

<main class="max-w-6xl mx-auto px-4 py-10 space-y-8">
    <?php if ($error): ?>
        <div class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm font-semibold"><?= Validator::e($error) ?></div>
    <?php endif; ?>

    <?php if (!$cpUser): ?>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-white rounded-3xl p-7 shadow-sm border border-purple-100">
            <h2 class="text-lg font-extrabold mb-4">Sign In to <?= Validator::e($panel['panel_name']) ?></h2>
            <form method="POST" class="space-y-3">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="cp_login">
                <input type="text" name="login" required placeholder="Username or Email" class="w-full px-4 py-2.5 rounded-xl border border-purple-200 text-sm">
                <input type="password" name="password" required placeholder="Password" class="w-full px-4 py-2.5 rounded-xl border border-purple-200 text-sm">
                <button type="submit" class="w-full py-2.5 rounded-xl text-white font-bold text-sm" style="background: <?= Validator::e($panel['primary_color']) ?>">Sign In</button>
            </form>
        </div>
        <div class="bg-white rounded-3xl p-7 shadow-sm border border-purple-100">
            <h2 class="text-lg font-extrabold mb-4">Create Customer Account</h2>
            <form method="POST" class="space-y-3">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="cp_register">
                <input type="text" name="full_name" required placeholder="Full Name" class="w-full px-4 py-2.5 rounded-xl border border-purple-200 text-sm">
                <input type="text" name="username" required placeholder="Username" class="w-full px-4 py-2.5 rounded-xl border border-purple-200 text-sm">
                <input type="email" name="email" required placeholder="Email" class="w-full px-4 py-2.5 rounded-xl border border-purple-200 text-sm">
                <input type="password" name="password" required placeholder="Password" class="w-full px-4 py-2.5 rounded-xl border border-purple-200 text-sm">
                <button type="submit" class="w-full py-2.5 rounded-xl text-white font-bold text-sm" style="background: <?= Validator::e($panel['primary_color']) ?>">Register</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="bg-white rounded-3xl p-7 shadow-sm border border-purple-100">
        <h2 class="text-lg font-extrabold mb-4">Available Services</h2>
        <?php if (empty($cpServices)): ?>
            <p class="text-sm text-slate-500">No services configured on this child panel yet.</p>
        <?php else: ?>
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b text-xs uppercase text-slate-400"><th class="py-2">Service</th><th class="py-2">Rate / 1k</th><th class="py-2">Min / Max</th></tr></thead>
                <tbody class="divide-y">
                    <?php foreach ($cpServices as $cs): ?>
                    <tr>
                        <td class="py-3 font-semibold flex items-center gap-2"><?= Helpers::platformIcon((string)$cs['platform'], 'w-6 h-6') ?> <?= Validator::e($cs['custom_name'] ?: ('Service #' . $cs['parent_service_id'])) ?></td>
                        <td class="py-3 font-bold"><?= Currency::format((string)$cs['custom_rate']) ?></td>
                        <td class="py-3 text-xs text-slate-500"><?= (int)$cs['min_qty'] ?> - <?= (int)$cs['max_qty'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
