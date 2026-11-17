<?php
/**
 * Shared Flash Sale Banner Component (Public Homepage & User Dashboard)
 * Matches reference UI: vibrant purple-magenta gradient, discount badge,
 * strikethrough original price, sale price, live JS countdown, Order Now CTA.
 */

$activeSale = FlashSale::getFeaturedActiveSale();
if ($activeSale):
    $sample = $activeSale['sample_service'] ?? null;
    $discountBadge = $activeSale['discount_type'] === 'percent'
        ? rtrim(rtrim(number_format((float)$activeSale['discount_value'], 2, '.', ''), '0'), '.') . '% OFF'
        : 'SPECIAL SALE';
    $ctaHref = $sample
        ? (Auth::user() ? '/user/new-order.php?service_id=' . (int)$sample['id'] : '/register.php?service_id=' . (int)$sample['id'])
        : (Auth::user() ? '/user/new-order.php' : '/services.php');
    $endTs = strtotime($activeSale['ends_at']) * 1000;
?>
<div class="relative overflow-hidden rounded-2xl shadow-lg shadow-purple-500/15 mb-6 bg-gradient-to-r from-[#3B1D8F] via-[#7C3AED] to-[#DB2777] text-white"
     x-data="flashCountdown(<?= (int)$endTs ?>)"
     x-show="!expired"
     x-transition>
    <?php if (!empty($activeSale['banner_image'])): ?>
        <img src="<?= Validator::e($activeSale['banner_image']) ?>" alt="" class="absolute inset-0 w-full h-full object-cover opacity-20 pointer-events-none">
    <?php endif; ?>
    <div class="absolute -right-12 -top-12 w-52 h-52 bg-pink-400/20 rounded-full blur-2xl pointer-events-none"></div>
    <div class="absolute left-1/3 -bottom-16 w-64 h-64 bg-indigo-400/20 rounded-full blur-2xl pointer-events-none"></div>

    <div class="relative z-10 px-5 py-4 sm:px-7 sm:py-5 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <!-- Left Promo Title -->
        <div class="flex items-center gap-4 min-w-0">
            <div class="w-12 h-12 rounded-2xl bg-white/15 backdrop-blur flex items-center justify-center shrink-0 border border-white/20 shadow-inner">
                <svg class="w-7 h-7 text-amber-300" viewBox="0 0 24 24" fill="currentColor"><path d="M13 2L3 14h8l-1 8 10-12h-8l1-8z"/></svg>
            </div>
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xl sm:text-2xl font-extrabold tracking-tight uppercase italic"><?= Validator::e($activeSale['title']) ?></span>
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-gradient-to-r from-orange-500 to-rose-500 text-white shadow">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2c.5 4-2 6-2 9a4 4 0 0 0 8 0c0-3-2-5-2-9 2.5 2 5 5 5 9a9 9 0 1 1-18 0c0-4 2.5-7 5-9-.5 2.5 1 4 2 5 .5-2 1-3.5 2-5z"/></svg>
                        <?= Validator::e($discountBadge) ?>
                    </span>
                </div>
                <p class="text-xs sm:text-sm text-purple-100 truncate mt-0.5">
                    <?= Validator::e($activeSale['description'] ?: ($sample ? $sample['name'] : 'Limited Time Offer — Grab discounted rates before timer expires!')) ?>
                </p>
            </div>
        </div>

        <!-- Middle Price Comparison (Real DB service price) -->
        <?php if ($sample): ?>
        <div class="flex items-center gap-3 bg-white/10 backdrop-blur-sm rounded-xl px-4 py-2 border border-white/15 shrink-0">
            <?= Helpers::platformIcon((string)$sample['platform'], 'w-8 h-8') ?>
            <div>
                <div class="text-[11px] text-purple-100 font-medium truncate max-w-[160px]"><?= Validator::e($sample['name']) ?></div>
                <div class="flex items-baseline gap-2 tabular-nums">
                    <span class="text-xs line-through text-purple-200/80"><?= Currency::displayRate((string)$activeSale['original_price'], null, true) ?></span>
                    <span class="text-lg font-extrabold text-amber-300"><?= Currency::displayRate((string)$activeSale['sale_price'], null, true) ?></span>
                    <span class="text-[10px] text-purple-200">/1k</span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Right Countdown + CTA -->
        <div class="flex flex-wrap items-center justify-between sm:justify-end gap-3 shrink-0">
            <div class="flex items-center gap-1.5 sm:gap-2">
                <div class="w-12 py-1.5 rounded-xl bg-[#1A103D]/70 backdrop-blur border border-white/15 text-center">
                    <div class="text-sm sm:text-base font-extrabold leading-none" x-text="days">00</div>
                    <div class="text-[10px] text-purple-200 mt-1">Days</div>
                </div>
                <div class="w-12 py-1.5 rounded-xl bg-[#1A103D]/70 backdrop-blur border border-white/15 text-center">
                    <div class="text-sm sm:text-base font-extrabold leading-none" x-text="hours">00</div>
                    <div class="text-[10px] text-purple-200 mt-1">Hours</div>
                </div>
                <div class="w-12 py-1.5 rounded-xl bg-[#1A103D]/70 backdrop-blur border border-white/15 text-center">
                    <div class="text-sm sm:text-base font-extrabold leading-none" x-text="minutes">00</div>
                    <div class="text-[10px] text-purple-200 mt-1">Min</div>
                </div>
                <div class="w-12 py-1.5 rounded-xl bg-[#1A103D]/70 backdrop-blur border border-white/15 text-center">
                    <div class="text-sm sm:text-base font-extrabold leading-none" x-text="seconds">00</div>
                    <div class="text-[10px] text-purple-200 mt-1">Sec</div>
                </div>
            </div>

            <a href="<?= Validator::e($ctaHref) ?>"
               class="inline-flex items-center gap-1.5 px-5 py-2.5 rounded-xl bg-white text-purple-900 font-bold text-sm shadow-md hover:bg-purple-50 transition">
                Order Now
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
            </a>
        </div>
    </div>
</div>
<?php endif; ?>
