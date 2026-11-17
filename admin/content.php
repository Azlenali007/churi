<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('content.manage');
$pageTitle = 'Website Content & Announcements CMS';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_hero') {
        $json = json_encode([
            'cta_primary_text'   => Validator::str($_POST['cta_primary_text'] ?? 'Get Started', 60),
            'cta_primary_link'   => Validator::str($_POST['cta_primary_link'] ?? '/register.php', 120),
            'cta_secondary_text' => Validator::str($_POST['cta_secondary_text'] ?? 'Explore Services', 60),
            'cta_secondary_link' => Validator::str($_POST['cta_secondary_link'] ?? '/services.php', 120),
        ]);
        Database::execute(
            "UPDATE cms_blocks SET title = ?, subtitle = ?, content_json = ? WHERE section_key = 'hero'",
            [Validator::str($_POST['title'] ?? '', 200), Validator::str($_POST['subtitle'] ?? '', 500), $json]
        );
        Helpers::setFlash('success', 'Homepage Hero updated.');
    } elseif ($action === 'save_page') {
        $slug = $_POST['slug'] ?? '';
        if (in_array($slug, ['terms', 'privacy'], true)) {
            Database::execute(
                "UPDATE cms_pages SET title = ?, content_html = ?, meta_title = ?, meta_description = ? WHERE slug = ?",
                [Validator::str($_POST['title'] ?? '', 180), (string)($_POST['content_html'] ?? ''), Validator::str($_POST['meta_title'] ?? '', 180), Validator::str($_POST['meta_description'] ?? '', 300), $slug]
            );
            Helpers::setFlash('success', ucfirst($slug) . ' page updated.');
        }
    } elseif ($action === 'save_seo_social') {
        Helpers::setSetting('seo_title', Validator::str($_POST['seo_title'] ?? '', 180), 'seo');
        Helpers::setSetting('seo_description', Validator::str($_POST['seo_description'] ?? '', 320), 'seo');
        Helpers::setSetting('seo_keywords', Validator::str($_POST['seo_keywords'] ?? '', 320), 'seo');
        Helpers::setSetting('social_instagram', Validator::str($_POST['social_instagram'] ?? '', 255), 'social');
        Helpers::setSetting('social_telegram', Validator::str($_POST['social_telegram'] ?? '', 255), 'social');
        Helpers::setSetting('social_youtube', Validator::str($_POST['social_youtube'] ?? '', 255), 'social');
        Helpers::setSetting('social_twitter', Validator::str($_POST['social_twitter'] ?? '', 255), 'social');
        Helpers::setFlash('success', 'SEO & Social media links saved.');
    } elseif ($action === 'add_announcement') {
        Database::insert(
            "INSERT INTO announcements (title, content, type, status) VALUES (?, ?, ?, 'active')",
            [Validator::str($_POST['title'] ?? '', 160), Validator::str($_POST['content'] ?? '', 2000), in_array($_POST['type'] ?? '', ['info','warning','success','promo'], true) ? $_POST['type'] : 'info']
        );
        Helpers::setFlash('success', 'Announcement published.');
    } elseif ($action === 'delete_announcement') {
        Database::execute("DELETE FROM announcements WHERE id = ?", [(int)($_POST['id'] ?? 0)]);
        Helpers::setFlash('success', 'Announcement deleted.');
    }

    Helpers::redirect('/admin/content.php');
}

$hero = Database::fetch("SELECT * FROM cms_blocks WHERE section_key = 'hero' LIMIT 1");
$heroData = $hero ? (json_decode((string)$hero['content_json'], true) ?: []) : [];
$terms = Database::fetch("SELECT * FROM cms_pages WHERE slug = 'terms' LIMIT 1");
$privacy = Database::fetch("SELECT * FROM cms_pages WHERE slug = 'privacy' LIMIT 1");
$announcements = Database::fetchAll("SELECT * FROM announcements ORDER BY id DESC");

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- Hero CMS -->
    <div class="smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-4">Homepage Hero Section</h2>
        <form method="POST" class="space-y-3 text-xs">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="save_hero">
            <div>
                <label class="block font-bold uppercase text-slate-500 mb-1">Hero Headline</label>
                <input type="text" name="title" value="<?= Validator::e($hero['title'] ?? '') ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 text-sm">
            </div>
            <div>
                <label class="block font-bold uppercase text-slate-500 mb-1">Hero Subtitle</label>
                <textarea name="subtitle" rows="2" class="w-full px-3.5 py-2 rounded-xl border border-purple-200"><?= Validator::e($hero['subtitle'] ?? '') ?></textarea>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <input type="text" name="cta_primary_text" value="<?= Validator::e($heroData['cta_primary_text'] ?? 'Get Started') ?>" placeholder="Primary CTA" class="px-3 py-2 rounded-xl border border-purple-200">
                <input type="text" name="cta_primary_link" value="<?= Validator::e($heroData['cta_primary_link'] ?? '/register.php') ?>" placeholder="Primary URL" class="px-3 py-2 rounded-xl border border-purple-200">
                <input type="text" name="cta_secondary_text" value="<?= Validator::e($heroData['cta_secondary_text'] ?? 'Explore Services') ?>" placeholder="Secondary CTA" class="px-3 py-2 rounded-xl border border-purple-200">
                <input type="text" name="cta_secondary_link" value="<?= Validator::e($heroData['cta_secondary_link'] ?? '/services.php') ?>" placeholder="Secondary URL" class="px-3 py-2 rounded-xl border border-purple-200">
            </div>
            <button type="submit" class="px-5 py-2.5 rounded-xl bg-purple-600 text-white font-bold">Save Hero</button>
        </form>
    </div>

    <!-- SEO & Social Links -->
    <div class="smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-4">SEO &amp; Social Media Links</h2>
        <form method="POST" class="space-y-3 text-xs">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="save_seo_social">
            <input type="text" name="seo_title" value="<?= Validator::e(Helpers::setting('seo_title')) ?>" placeholder="SEO Meta Title" class="w-full px-3 py-2 rounded-xl border border-purple-200">
            <textarea name="seo_description" rows="2" placeholder="SEO Meta Description" class="w-full px-3 py-2 rounded-xl border border-purple-200"><?= Validator::e(Helpers::setting('seo_description')) ?></textarea>
            <input type="text" name="seo_keywords" value="<?= Validator::e(Helpers::setting('seo_keywords')) ?>" placeholder="SEO Keywords" class="w-full px-3 py-2 rounded-xl border border-purple-200">
            <div class="grid grid-cols-2 gap-2">
                <input type="text" name="social_instagram" value="<?= Validator::e(Helpers::setting('social_instagram')) ?>" placeholder="Instagram URL" class="px-3 py-2 rounded-xl border border-purple-200">
                <input type="text" name="social_telegram" value="<?= Validator::e(Helpers::setting('social_telegram')) ?>" placeholder="Telegram URL" class="px-3 py-2 rounded-xl border border-purple-200">
                <input type="text" name="social_youtube" value="<?= Validator::e(Helpers::setting('social_youtube')) ?>" placeholder="YouTube URL" class="px-3 py-2 rounded-xl border border-purple-200">
                <input type="text" name="social_twitter" value="<?= Validator::e(Helpers::setting('social_twitter')) ?>" placeholder="X/Twitter URL" class="px-3 py-2 rounded-xl border border-purple-200">
            </div>
            <button type="submit" class="px-5 py-2.5 rounded-xl bg-purple-600 text-white font-bold">Save SEO &amp; Social</button>
        </form>
    </div>
</div>

<!-- Announcements & Legal Pages -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="smm-card rounded-3xl p-6 space-y-4">
        <h2 class="text-base font-extrabold text-slate-900">Announcements</h2>
        <form method="POST" class="space-y-2.5 text-xs">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="add_announcement">
            <input type="text" name="title" required placeholder="Announcement title" class="w-full px-3 py-2 rounded-xl border border-purple-200">
            <textarea name="content" rows="2" required placeholder="Message shown on user dashboards..." class="w-full px-3 py-2 rounded-xl border border-purple-200"></textarea>
            <button type="submit" class="px-4 py-2 rounded-xl bg-purple-600 text-white font-bold">Publish</button>
        </form>
        <div class="space-y-2 pt-2 border-t text-xs">
            <?php foreach ($announcements as $a): ?>
            <div class="p-3 rounded-xl bg-slate-50 flex justify-between gap-2">
                <div><strong><?= Validator::e($a['title']) ?></strong><div class="text-slate-500"><?= Validator::e($a['content']) ?></div></div>
                <form method="POST"><?= CSRF::field() ?><input type="hidden" name="action" value="delete_announcement"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="text-rose-600 font-bold">Del</button></form>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php foreach (['terms' => $terms, 'privacy' => $privacy] as $slug => $pg): ?>
    <div class="smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-3">Edit <?= ucfirst($slug) ?> Page</h2>
        <form method="POST" class="space-y-3 text-xs">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="save_page">
            <input type="hidden" name="slug" value="<?= $slug ?>">
            <input type="text" name="title" value="<?= Validator::e($pg['title'] ?? '') ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
            <input type="text" name="meta_title" value="<?= Validator::e($pg['meta_title'] ?? '') ?>" placeholder="Meta Title" class="w-full px-3 py-2 rounded-xl border border-purple-200">
            <input type="hidden" name="meta_description" value="<?= Validator::e($pg['meta_description'] ?? '') ?>">
            <textarea name="content_html" rows="6" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono"><?= Validator::e($pg['content_html'] ?? '') ?></textarea>
            <button type="submit" class="px-4 py-2 rounded-xl bg-purple-600 text-white font-bold">Save <?= ucfirst($slug) ?></button>
        </form>
    </div>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
