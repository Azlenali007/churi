<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('settings.manage');
$pageTitle = 'Theme Management';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = (string)($_POST['action'] ?? '');
    $themeKey = strtolower(trim((string)($_POST['theme_key'] ?? '')));

    if ($action === 'activate') {
        if (ThemeManager::activate($themeKey, (int)$admin['id'])) {
            $themes = ThemeManager::getThemes();
            Helpers::setFlash('success', ($themes[$themeKey]['name'] ?? $themeKey) . ' theme is now active for the User Dashboard!');
        } else {
            Helpers::setFlash('error', 'Selected theme could not be activated.');
        }
    } elseif ($action === 'deactivate') {
        ThemeManager::deactivateToDefault((int)$admin['id']);
        Helpers::setFlash('success', 'Theme deactivated. Reverted cleanly to Classic Purple.');
    }
    Helpers::redirect('/admin/themes.php');
}

$themes = ThemeManager::getThemes();
$activeTheme = ThemeManager::getActiveThemeKey();

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">
    <div class="smm-card rounded-3xl p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-xl font-extrabold text-slate-900">User Dashboard Themes</h1>
                <span class="px-2.5 py-0.5 rounded-full bg-purple-100 text-purple-800 text-[11px] font-bold">
                    Active: <?= Validator::e($themes[$activeTheme]['name'] ?? 'Classic Purple') ?>
                </span>
            </div>
            <p class="text-xs sm:text-sm text-slate-500 mt-1">
                Switch the logged-in User Dashboard theme between <strong>Classic Purple</strong> and <strong>SwipeX</strong> without affecting your Admin Console, Public Website, Orders, Wallet, or Provider data.
            </p>
        </div>
        <a href="/user/dashboard.php" target="_blank" class="px-4 py-2.5 rounded-xl bg-slate-900 text-white text-xs font-bold shrink-0">
            Preview User Dashboard &rarr;
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <?php foreach ($themes as $key => $th):
            $isActive = ($key === $activeTheme);
        ?>
        <div class="smm-card rounded-3xl p-6 flex flex-col justify-between border-2 <?= $isActive ? 'border-purple-500 shadow-xl shadow-purple-500/10' : 'border-transparent' ?>">
            <div>
                <!-- Mini Visual Theme Mockup Preview -->
                <?php if ($key === 'classic'): ?>
                <div class="rounded-2xl overflow-hidden border border-purple-100 bg-[#F6F4FC] p-3.5 mb-5">
                    <div class="flex gap-2.5 h-44">
                        <div class="w-16 rounded-xl bg-[#1B1145] p-2 flex flex-col gap-1.5">
                            <div class="h-3 w-10 rounded bg-purple-500/70"></div>
                            <div class="h-2 w-11 rounded bg-purple-600 mt-2"></div>
                            <div class="h-2 w-9 rounded bg-white/20"></div>
                            <div class="h-2 w-10 rounded bg-white/20"></div>
                            <div class="h-2 w-8 rounded bg-white/20"></div>
                        </div>
                        <div class="flex-1 flex flex-col gap-2">
                            <div class="h-7 rounded-xl bg-white border border-purple-100 px-2 flex items-center justify-between">
                                <div class="h-2 w-16 rounded bg-purple-100"></div>
                                <div class="w-4 h-4 rounded-full bg-purple-600"></div>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div class="h-14 rounded-xl bg-white border border-purple-100 p-2">
                                    <div class="h-2 w-10 bg-slate-200 rounded mb-1"></div>
                                    <div class="h-3 w-14 bg-slate-800 rounded"></div>
                                </div>
                                <div class="h-14 rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 p-2 text-white">
                                    <div class="h-2 w-10 bg-white/60 rounded mb-1"></div>
                                    <div class="h-3 w-16 bg-white rounded"></div>
                                </div>
                            </div>
                            <div class="flex-1 rounded-xl bg-white border border-purple-100 p-2 flex items-center justify-between">
                                <div class="space-y-1">
                                    <div class="h-2 w-24 bg-slate-200 rounded"></div>
                                    <div class="h-2 w-16 bg-purple-200 rounded"></div>
                                </div>
                                <div class="h-6 w-14 rounded-lg bg-purple-600"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="rounded-2xl overflow-hidden border border-slate-200 bg-[#F8FAFC] p-3.5 mb-5">
                    <div class="flex flex-col gap-2 h-44">
                        <div class="h-7 rounded-xl bg-white border border-slate-100 px-2.5 flex items-center justify-between">
                            <div class="flex items-center gap-1.5">
                                <div class="w-4 h-4 rounded-md bg-blue-600"></div>
                                <div class="h-2 w-14 rounded bg-slate-800"></div>
                            </div>
                            <div class="flex items-center gap-1">
                                <div class="h-3.5 w-9 rounded-full border border-slate-200"></div>
                                <div class="w-4 h-4 rounded-full bg-blue-600"></div>
                            </div>
                        </div>
                        <div class="h-11 rounded-xl bg-white border border-slate-100 px-3 flex items-center justify-between">
                            <div>
                                <div class="h-1.5 w-10 bg-slate-300 rounded mb-1"></div>
                                <div class="h-3 w-16 bg-slate-900 rounded"></div>
                            </div>
                            <div class="h-5 w-16 rounded-full bg-blue-600"></div>
                        </div>
                        <div class="flex-1 flex items-center justify-center gap-2 overflow-hidden px-2">
                            <div class="w-12 h-16 rounded-xl bg-gradient-to-br from-rose-500 to-pink-600 opacity-75"></div>
                            <div class="w-28 h-20 rounded-2xl bg-gradient-to-br from-blue-600 to-violet-600 shadow-lg flex flex-col items-center justify-center gap-1.5 p-2">
                                <div class="w-5 h-5 rounded-full bg-white/25"></div>
                                <div class="h-2 w-14 bg-white rounded"></div>
                                <div class="h-3.5 w-12 bg-white rounded-full"></div>
                            </div>
                            <div class="w-12 h-16 rounded-xl bg-gradient-to-br from-purple-600 to-fuchsia-600 opacity-75"></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="flex items-center justify-between gap-2 mb-2">
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-extrabold text-slate-900"><?= Validator::e($th['name']) ?></h2>
                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600">v<?= Validator::e($th['version']) ?></span>
                    </div>
                    <?php if ($isActive): ?>
                        <span class="px-3 py-1 rounded-full text-xs font-extrabold bg-emerald-100 text-emerald-700">Active Theme</span>
                    <?php else: ?>
                        <span class="px-3 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-500">Inactive</span>
                    <?php endif; ?>
                </div>
                <p class="text-xs text-slate-600 leading-relaxed mb-4"><?= Validator::e($th['description']) ?></p>

                <ul class="space-y-1.5 mb-5 text-xs text-slate-700">
                    <?php foreach ($th['highlights'] as $hl): ?>
                    <li class="flex items-center gap-2">
                        <span class="w-4 h-4 rounded-full bg-purple-100 text-purple-700 flex items-center justify-center shrink-0">
                            <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        </span>
                        <span><?= Validator::e($hl) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="flex items-center gap-2.5 pt-4 border-t border-slate-100">
                <?php if (!$isActive): ?>
                    <form method="POST" class="flex-1">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="activate">
                        <input type="hidden" name="theme_key" value="<?= Validator::e($key) ?>">
                        <button type="submit" class="w-full py-2.5 rounded-xl bg-gradient-to-r from-purple-600 to-violet-600 hover:opacity-95 text-white text-xs font-bold shadow-md">
                            Activate <?= Validator::e($th['name']) ?>
                        </button>
                    </form>
                <?php elseif ($key !== 'classic'): ?>
                    <form method="POST" class="flex-1">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="deactivate">
                        <button type="submit" class="w-full py-2.5 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-bold border border-rose-200">
                            Deactivate (Revert to Classic)
                        </button>
                    </form>
                <?php else: ?>
                    <div class="flex-1 py-2.5 text-center rounded-xl bg-emerald-50 text-emerald-700 text-xs font-bold border border-emerald-200">
                        Currently Active Default Theme
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
