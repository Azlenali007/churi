<?php
$pageTitle = 'Notifications & Announcements';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/sidebar.php';
require __DIR__ . '/partials/header.php';

$notifications = Database::fetchAll(
    "SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 60",
    [$user['id']]
);
$announcements = Database::fetchAll(
    "SELECT * FROM announcements WHERE status = 'active' ORDER BY id DESC"
);
?>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
    <div class="lg:col-span-8 swipex-card rounded-3xl p-6 min-w-0">
        <div class="flex items-center justify-between gap-3 mb-5">
            <div>
                <h1 class="text-xl font-extrabold text-slate-900">Your Notifications</h1>
                <p class="text-xs text-slate-500">Order updates, wallet credits, and ticket replies.</p>
            </div>
            <?php if (!empty($notifications)): ?>
            <form method="POST" action="/user/notifications.php">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="px-4 py-2 rounded-full bg-blue-50 hover:bg-blue-100 text-blue-700 text-xs font-bold transition">Mark All Read</button>
            </form>
            <?php endif; ?>
        </div>

        <?php if (empty($notifications)): ?>
            <p class="text-sm text-slate-500 py-10 text-center">You have no notifications yet.</p>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($notifications as $n): ?>
                <div class="p-4 rounded-2xl border <?= !$n['is_read'] ? 'bg-blue-50/70 border-blue-200' : 'bg-white border-slate-100' ?> flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-blue-100 text-blue-700 shrink-0"><?= Validator::e($n['type']) ?></span>
                            <h3 class="text-sm font-bold text-slate-900 truncate"><?= Validator::e($n['title']) ?></h3>
                        </div>
                        <p class="text-xs text-slate-600 mt-1 break-words"><?= Validator::e($n['message']) ?></p>
                        <div class="text-[11px] text-slate-400 mt-1.5"><?= Helpers::timeAgo($n['created_at']) ?></div>
                    </div>
                    <?php if (!empty($n['link'])): ?>
                        <a href="<?= Validator::e($n['link']) ?>" class="px-3.5 py-1.5 rounded-full bg-white border border-slate-200 text-xs font-bold text-blue-600 shrink-0">Open</a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="lg:col-span-4 swipex-card rounded-3xl p-6 min-w-0">
        <h2 class="text-base font-extrabold text-slate-900 mb-4">Platform Announcements</h2>
        <?php if (empty($announcements)): ?>
            <p class="text-xs text-slate-500">No announcements posted.</p>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($announcements as $a): ?>
                <div class="p-4 rounded-2xl bg-blue-50/60 border border-blue-100">
                    <div class="text-xs font-bold text-blue-900"><?= Validator::e($a['title']) ?></div>
                    <div class="text-xs text-slate-600 mt-1 leading-relaxed break-words"><?= nl2br(Validator::e($a['content'])) ?></div>
                    <div class="text-[10px] text-slate-400 mt-2"><?= date('M j, Y', strtotime($a['created_at'])) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
