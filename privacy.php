<?php
require_once __DIR__ . '/includes/bootstrap.php';
$page = Database::fetch("SELECT * FROM cms_pages WHERE slug = 'privacy' LIMIT 1");
$pageTitle = ($page['meta_title'] ?? 'Privacy Policy') . ' — ' . Helpers::setting('site_name', 'SMM PRO');
require __DIR__ . '/includes/public_header.php';
?>
<main class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    <div class="smm-card rounded-3xl p-8 sm:p-10 prose prose-slate max-w-none">
        <h1 class="text-3xl font-extrabold text-slate-900 mb-6"><?= Validator::e($page['title'] ?? 'Privacy Policy') ?></h1>
        <div class="space-y-4 text-sm text-slate-700 leading-relaxed">
            <?= $page['content_html'] ?? '<p>Privacy Policy content not configured.</p>' ?>
        </div>
    </div>
</main>
<?php require __DIR__ . '/includes/public_footer.php'; ?>
