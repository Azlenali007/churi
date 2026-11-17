<?php
require_once __DIR__ . '/includes/bootstrap.php';
$pageTitle = 'FAQ — ' . Helpers::setting('site_name', 'SMM PRO');
$faqRow = Database::fetch("SELECT * FROM cms_blocks WHERE section_key = 'faq' LIMIT 1");
$faqData = $faqRow ? (json_decode((string)$faqRow['content_json'], true) ?: []) : [];
require __DIR__ . '/includes/public_header.php';
?>
<main class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12" x-data="{ openIdx: 0 }">
    <div class="text-center mb-10">
        <span class="text-xs font-bold uppercase tracking-wider text-purple-600">Help Center</span>
        <h1 class="text-3xl font-extrabold text-slate-900 mt-1"><?= Validator::e($faqRow['title'] ?? 'Frequently Asked Questions') ?></h1>
        <p class="text-sm text-slate-600 mt-2"><?= Validator::e($faqRow['subtitle'] ?? '') ?></p>
    </div>
    <div class="space-y-3">
        <?php foreach (($faqData['items'] ?? []) as $idx => $item): ?>
        <div class="smm-card rounded-2xl overflow-hidden">
            <button type="button" @click="openIdx = (openIdx === <?= $idx ?> ? -1 : <?= $idx ?>)"
                    class="w-full px-6 py-4 text-left flex items-center justify-between font-bold text-slate-900 text-sm">
                <span><?= Validator::e($item['q'] ?? '') ?></span>
                <svg class="w-5 h-5 text-purple-600 transition-transform" :class="openIdx === <?= $idx ?> ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div x-show="openIdx === <?= $idx ?>" x-cloak class="px-6 pb-5 text-sm text-slate-600 leading-relaxed border-t border-slate-100 pt-3">
                <?= Validator::e($item['a'] ?? '') ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</main>
<?php require __DIR__ . '/includes/public_footer.php'; ?>
