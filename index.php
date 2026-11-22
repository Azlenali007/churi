<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (!StoreManager::isSmmEnabled()) {
    if (StoreManager::isOtpEnabled()) {
        Helpers::redirect('/otp/');
    }
    StoreManager::renderStoreUnavailable('smm');
}

$cmsRows = Database::fetchAll("SELECT * FROM cms_blocks");
$cms = [];
foreach ($cmsRows as $row) {
    $row['data'] = json_decode((string)$row['content_json'], true) ?: [];
    $cms[$row['section_key']] = $row;
}

$categories = Database::fetchAll(
    "SELECT c.*, COUNT(s.id) AS service_count
     FROM categories c
     LEFT JOIN services s ON s.category_id = c.id AND s.status = 'active'
     WHERE c.status = 'active'
     GROUP BY c.id
     HAVING service_count > 0 OR c.id > 1
     ORDER BY c.sort_order ASC, c.id ASC
     LIMIT 12"
);

$featuredServices = Database::fetchAll(
    "SELECT s.*, c.name AS category_name
     FROM services s
     JOIN categories c ON c.id = s.category_id
     WHERE s.status = 'active' AND c.status = 'active'
     ORDER BY s.sort_order ASC, s.id ASC
     LIMIT 8"
);

$hero = $cms['hero'] ?? ['title' => 'Accelerate Your Social Growth', 'subtitle' => 'Automated SMM fulfillment.', 'data' => []];
$whyUs = $cms['why_choose_us'] ?? ['title' => 'Why Choose Us', 'subtitle' => '', 'data' => []];
$howItWorks = $cms['how_it_works'] ?? ['title' => 'How It Works', 'subtitle' => '', 'data' => []];
$features = $cms['features'] ?? ['title' => 'Features', 'subtitle' => '', 'data' => []];
$testimonials = $cms['testimonials'] ?? ['title' => 'Testimonials', 'subtitle' => '', 'data' => []];
$faq = $cms['faq'] ?? ['title' => 'FAQ', 'subtitle' => '', 'data' => []];

$popupContext = 'public';
require __DIR__ . '/includes/public_header.php';
?>

<main>
    <!-- Hero Section -->
    <section class="relative overflow-hidden bg-gradient-to-b from-white via-[#F6F4FC] to-[#F6F4FC] pt-10 pb-16">
        <div class="absolute top-0 right-0 w-96 h-96 bg-purple-300/20 rounded-full blur-3xl pointer-events-none"></div>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">

            <?php require __DIR__ . '/includes/flash_sale_banner.php'; ?>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 items-center py-6">
                <div class="lg:col-span-7">
                    <span class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-purple-100 text-purple-800 text-xs font-bold mb-5">
                        <span class="w-2 h-2 rounded-full bg-purple-600 animate-pulse"></span>
                        Automated SMM Fulfillment & Reseller Infrastructure
                    </span>
                    <h1 class="text-4xl sm:text-5xl font-extrabold text-slate-900 tracking-tight leading-[1.12] mb-5">
                        <?= Validator::e($hero['title']) ?>
                    </h1>
                    <p class="text-base sm:text-lg text-slate-600 leading-relaxed mb-8 max-w-2xl">
                        <?= Validator::e($hero['subtitle']) ?>
                    </p>
                    <div class="flex flex-wrap items-center gap-3.5">
                        <a href="<?= Validator::e($hero['data']['cta_primary_link'] ?? '/register.php') ?>"
                           class="inline-flex items-center gap-2 px-7 py-3.5 rounded-2xl bg-gradient-to-r from-purple-600 to-violet-600 hover:from-purple-700 hover:to-violet-700 text-white font-bold text-sm shadow-xl shadow-purple-500/25 transition">
                            <?= Validator::e($hero['data']['cta_primary_text'] ?? 'Get Started') ?>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                        </a>
                        <a href="<?= Validator::e($hero['data']['cta_secondary_link'] ?? '/services.php') ?>"
                           class="inline-flex items-center gap-2 px-7 py-3.5 rounded-2xl bg-white border border-purple-200 hover:border-purple-400 text-purple-900 font-bold text-sm shadow-sm transition">
                            <?= Validator::e($hero['data']['cta_secondary_text'] ?? 'Explore Services') ?>
                        </a>
                        <?= StoreManager::renderPublicOtpPill('py-3.5 px-6') ?>
                    </div>
                </div>

                <!-- Platform Showcase Card -->
                <div class="lg:col-span-5">
                    <div class="smm-card rounded-3xl p-6 sm:p-7">
                        <div class="flex items-center justify-between mb-5">
                            <div>
                                <div class="text-xs font-bold uppercase tracking-wider text-purple-600">Supported Networks</div>
                                <h3 class="text-lg font-extrabold text-slate-900">Multi-Platform Catalog</h3>
                            </div>
                            <a href="/services.php" class="text-xs font-bold text-purple-600 hover:text-purple-800">View All &rarr;</a>
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <?php foreach (['instagram' => 'Instagram', 'youtube' => 'YouTube', 'telegram' => 'Telegram', 'tiktok' => 'TikTok', 'twitter' => 'X / Twitter', 'facebook' => 'Facebook', 'spotify' => 'Spotify', 'discord' => 'Discord', 'linkedin' => 'LinkedIn'] as $pkey => $plabel): ?>
                            <a href="/services.php?platform=<?= urlencode($pkey) ?>"
                               class="flex flex-col items-center gap-2 p-3.5 rounded-2xl bg-[#F6F4FC] hover:bg-purple-100/70 border border-purple-100/70 transition text-center">
                                <?= Helpers::platformIcon($pkey, 'w-9 h-9') ?>
                                <span class="text-xs font-bold text-slate-800"><?= Validator::e($plabel) ?></span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Dynamic Platform Categories -->
    <?php if (!empty($categories)): ?>
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 mb-8">
            <div>
                <span class="text-xs font-bold uppercase tracking-wider text-purple-600">Browse By Category</span>
                <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-1">Platform Categories</h2>
            </div>
            <a href="/services.php" class="text-sm font-bold text-purple-700 hover:text-purple-900">Full Service Directory &rarr;</a>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <?php foreach ($categories as $cat): ?>
            <a href="/services.php?category_id=<?= (int)$cat['id'] ?>"
               class="smm-card rounded-2xl p-5 flex items-center gap-4 hover:border-purple-300 hover:-translate-y-0.5 transition">
                <?= Helpers::platformIcon((string)$cat['icon_svg_key'], 'w-11 h-11') ?>
                <div class="min-w-0">
                    <div class="font-bold text-slate-900 truncate"><?= Validator::e($cat['name']) ?></div>
                    <div class="text-xs text-slate-500 mt-0.5"><?= (int)$cat['service_count'] ?> active services</div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- Live Pricing & Services Overview -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="smm-card rounded-3xl p-6 sm:p-8">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div>
                    <span class="text-xs font-bold uppercase tracking-wider text-purple-600">Transparent Rates</span>
                    <h2 class="text-2xl font-extrabold text-slate-900 mt-1">Services & Pricing Overview</h2>
                </div>
                <a href="/services.php" class="px-5 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-sm font-bold transition">Browse All Services</a>
            </div>

            <?php if (empty($featuredServices)): ?>
                <div class="text-center py-10 text-sm text-slate-500">
                    Our service catalog is currently being updated. Please check back shortly.
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-purple-100 text-xs uppercase text-slate-500">
                                <th class="py-3 pr-4">ID</th>
                                <th class="py-3 pr-4">Service</th>
                                <th class="py-3 pr-4">Category</th>
                                <th class="py-3 pr-4">Rate / 1,000</th>
                                <th class="py-3 pr-4">Min / Max</th>
                                <th class="py-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($featuredServices as $svc):
                                $pricing = FlashSale::getServicePricing($svc);
                            ?>
                            <tr class="hover:bg-purple-50/40 transition">
                                <td class="py-3.5 pr-4 font-bold text-slate-500">#<?= (int)$svc['id'] ?></td>
                                <td class="py-3.5 pr-4">
                                    <div class="flex items-center gap-3">
                                        <?= Helpers::platformIcon((string)$svc['platform'], 'w-7 h-7') ?>
                                        <span class="font-semibold text-slate-900"><?= Validator::e($svc['name']) ?></span>
                                    </div>
                                </td>
                                <td class="py-3.5 pr-4 text-slate-600"><?= Validator::e($svc['category_name']) ?></td>
                                <td class="py-3.5 pr-4 font-bold text-purple-700 tabular-nums whitespace-nowrap">
                                    <?php if ($pricing['on_sale']): ?>
                                        <span class="line-through text-slate-400 text-xs mr-1"><?= Currency::displayRate($pricing['original_rate'], null, true) ?></span>
                                        <span class="text-rose-600"><?= Currency::displayRate($pricing['effective_rate'], null, true) ?></span>
                                    <?php else: ?>
                                        <?= Currency::displayRate($pricing['effective_rate'], null, true) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3.5 pr-4 text-xs text-slate-500"><?= number_format((int)$svc['min_qty']) ?> - <?= number_format((int)$svc['max_qty']) ?></td>
                                <td class="py-3.5 text-right">
                                    <a href="<?= $currentUser ? '/user/new-order.php?service_id=' . (int)$svc['id'] : '/register.php' ?>"
                                       class="inline-flex px-3.5 py-1.5 rounded-lg bg-purple-100 hover:bg-purple-600 text-purple-800 hover:text-white text-xs font-bold transition">
                                        Order
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Why Choose Us -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="text-center max-w-2xl mx-auto mb-10">
            <span class="text-xs font-bold uppercase tracking-wider text-purple-600">Why Choose Us</span>
            <h2 class="text-3xl font-extrabold text-slate-900 mt-1"><?= Validator::e($whyUs['title']) ?></h2>
            <p class="text-slate-600 text-sm mt-2"><?= Validator::e($whyUs['subtitle']) ?></p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <?php foreach (($whyUs['data']['items'] ?? []) as $item): ?>
            <div class="smm-card rounded-2xl p-6">
                <div class="w-11 h-11 rounded-xl bg-purple-100 text-purple-700 flex items-center justify-center mb-4">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                </div>
                <h3 class="font-bold text-slate-900 mb-2"><?= Validator::e($item['title'] ?? '') ?></h3>
                <p class="text-sm text-slate-600 leading-relaxed"><?= Validator::e($item['desc'] ?? '') ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- How It Works -->
    <section id="how-it-works" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="bg-[#1B1145] text-white rounded-3xl p-8 sm:p-12 shadow-2xl">
            <div class="text-center max-w-2xl mx-auto mb-10">
                <span class="text-xs font-bold uppercase tracking-wider text-purple-300">Workflow</span>
                <h2 class="text-3xl font-extrabold mt-1"><?= Validator::e($howItWorks['title']) ?></h2>
                <p class="text-purple-200 text-sm mt-2"><?= Validator::e($howItWorks['subtitle']) ?></p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <?php foreach (($howItWorks['data']['steps'] ?? []) as $st): ?>
                <div class="bg-white/10 border border-white/10 rounded-2xl p-6">
                    <div class="text-2xl font-extrabold text-purple-300 mb-3"><?= Validator::e($st['step'] ?? '') ?></div>
                    <h3 class="font-bold text-lg mb-2"><?= Validator::e($st['title'] ?? '') ?></h3>
                    <p class="text-sm text-purple-100/80 leading-relaxed"><?= Validator::e($st['desc'] ?? '') ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Features & Testimonials -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 grid grid-cols-1 lg:grid-cols-12 gap-8">
        <div class="lg:col-span-5 smm-card rounded-3xl p-7">
            <span class="text-xs font-bold uppercase tracking-wider text-purple-600">Capabilities</span>
            <h2 class="text-2xl font-extrabold text-slate-900 mt-1 mb-4"><?= Validator::e($features['title']) ?></h2>
            <ul class="space-y-3">
                <?php foreach (($features['data']['items'] ?? []) as $feat): ?>
                <li class="flex items-center gap-3 text-sm font-semibold text-slate-700">
                    <span class="w-6 h-6 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>
                    </span>
                    <?= Validator::e($feat) ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="lg:col-span-7 space-y-4">
            <div>
                <span class="text-xs font-bold uppercase tracking-wider text-purple-600">Client Feedback</span>
                <h2 class="text-2xl font-extrabold text-slate-900 mt-1"><?= Validator::e($testimonials['title']) ?></h2>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <?php foreach (($testimonials['data']['items'] ?? []) as $t): ?>
                <div class="smm-card rounded-2xl p-5 flex flex-col justify-between">
                    <p class="text-sm text-slate-600 leading-relaxed mb-4">"<?= Validator::e($t['quote'] ?? '') ?>"</p>
                    <div>
                        <div class="font-bold text-slate-900 text-sm"><?= Validator::e($t['name'] ?? '') ?></div>
                        <div class="text-xs text-purple-600 font-medium"><?= Validator::e($t['role'] ?? '') ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- FAQ Preview -->
    <section class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12" x-data="{ openIdx: 0 }">
        <div class="text-center mb-8">
            <span class="text-xs font-bold uppercase tracking-wider text-purple-600">Support</span>
            <h2 class="text-3xl font-extrabold text-slate-900 mt-1"><?= Validator::e($faq['title']) ?></h2>
        </div>
        <div class="space-y-3">
            <?php foreach (($faq['data']['items'] ?? []) as $idx => $item): ?>
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
    </section>
</main>

<?php require __DIR__ . '/includes/public_footer.php'; ?>
