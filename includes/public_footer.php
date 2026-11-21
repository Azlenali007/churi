<?php
$siteName = Helpers::setting('site_name', 'SMM PRO');
$siteTagline = Helpers::setting('site_tagline', 'Grow Faster, Smarter');
$socialLinks = [
    'Instagram' => Helpers::setting('social_instagram', ''),
    'Telegram'  => Helpers::setting('social_telegram', ''),
    'YouTube'   => Helpers::setting('social_youtube', ''),
    'X/Twitter' => Helpers::setting('social_twitter', ''),
];
?>
<footer class="bg-[#1B1145] text-purple-100 mt-20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-14 grid grid-cols-1 md:grid-cols-4 gap-10">
        <div class="md:col-span-2">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-xl bg-purple-600 text-white flex items-center justify-center">
                    <svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor"><path d="M5 16L3 5l5.5 5L12 4l3.5 6L21 5l-2 11H5zm0 2h14v2H5v-2z"/></svg>
                </div>
                <div>
                    <div class="font-extrabold text-lg text-white leading-none"><?= Validator::e($siteName) ?></div>
                    <div class="text-xs text-purple-300 mt-0.5"><?= Validator::e($siteTagline) ?></div>
                </div>
            </div>
            <p class="text-sm text-purple-200/80 max-w-md leading-relaxed">
                <?= Validator::e(Helpers::setting('seo_description', 'Automated social media marketing panel built for agencies, creators, and resellers.')) ?>
            </p>
            <div class="flex flex-wrap gap-3 mt-5">
                <?php foreach ($socialLinks as $label => $href): if ($href !== ''): ?>
                    <a href="<?= Validator::e($href) ?>" target="_blank" rel="noopener"
                       class="px-3 py-1.5 rounded-lg bg-white/10 hover:bg-purple-600 text-xs font-semibold text-white transition">
                        <?= Validator::e($label) ?>
                    </a>
                <?php endif; endforeach; ?>
            </div>
        </div>

        <div>
            <h4 class="font-bold text-white text-sm uppercase tracking-wider mb-4">Platform</h4>
            <ul class="space-y-2.5 text-sm text-purple-200/80">
                <?php if (StoreManager::isSmmEnabled()): ?>
                    <li><a href="/" class="hover:text-white transition">SMM Store Home</a></li>
                    <li><a href="/services.php" class="hover:text-white transition">SMM Services &amp; Pricing</a></li>
                <?php endif; ?>
                <?php if (StoreManager::isOtpEnabled()): ?>
                    <li><a href="/otp/" class="hover:text-white transition">OTP Activation Store</a></li>
                <?php endif; ?>
                <li><a href="/faq.php" class="hover:text-white transition">FAQ</a></li>
                <li><a href="/contact.php" class="hover:text-white transition">Contact Us</a></li>
            </ul>
        </div>

        <div>
            <h4 class="font-bold text-white text-sm uppercase tracking-wider mb-4">Account & Legal</h4>
            <ul class="space-y-2.5 text-sm text-purple-200/80">
                <li><a href="/login.php" class="hover:text-white transition">User Login</a></li>
                <li><a href="/register.php" class="hover:text-white transition">Create Account</a></li>
                <li><a href="/terms.php" class="hover:text-white transition">Terms & Conditions</a></li>
                <li><a href="/privacy.php" class="hover:text-white transition">Privacy Policy</a></li>
            </ul>
        </div>
    </div>

    <div class="border-t border-white/10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-5 flex flex-col sm:flex-row items-center justify-between gap-2 text-xs text-purple-300">
            <span>&copy; <?= date('Y') ?> <?= Validator::e($siteName) ?>. All rights reserved.</span>
            <span>Support: <?= Validator::e(Helpers::setting('support_email', 'support@example.com')) ?></span>
        </div>
    </div>
</footer>

<?php if (StoreManager::isOtpEnabled() && !str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/otp')): ?>
<!-- Mobile Floating OTP Store Switcher Pill -->
<div class="md:hidden fixed bottom-4 left-1/2 -translate-x-1/2 z-40">
    <?= StoreManager::renderPublicOtpPill('shadow-2xl ring-4 ring-white/80') ?>
</div>
<?php endif; ?>

<script src="/assets/js/app.js"></script>
<?php
$popupContext = $popupContext ?? 'public';
require __DIR__ . '/popup_modal.php';
?>
</body>
</html>
