<?php
/**
 * Shared Animated Promotional Popup Modal (Public & User Dashboard)
 * Respects Admin settings: position, frequency, mobile/desktop visibility, dates.
 */

$popupContext = $popupContext ?? 'public';
$activePopup = Popup::getActiveForContext($popupContext);
if ($activePopup):
    $pid = (int)$activePopup['id'];
    $freq = (string)$activePopup['frequency'];
    $showMobile = (int)$activePopup['show_mobile'] === 1;
    $showDesktop = (int)$activePopup['show_desktop'] === 1;
    $pos = (string)$activePopup['position'];
    $visibilityClass = ($showMobile && $showDesktop)
        ? ''
        : ($showDesktop ? 'hidden md:flex' : ($showMobile ? 'flex md:hidden' : 'hidden'));
?>
<div x-data="promoPopup(<?= $pid ?>, '<?= Validator::e($freq) ?>')"
     x-show="open"
     x-cloak
     x-transition.opacity.duration.250ms
     class="fixed inset-0 z-50 <?= $visibilityClass ?> <?= $pos === 'bottom_right' ? 'items-end justify-end p-4 sm:p-6 pointer-events-none' : ($pos === 'top_banner' ? 'items-start justify-center p-4 pointer-events-none' : 'items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm') ?>">

    <div @click.away="close(false)"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 scale-95 translate-y-4"
         x-transition:enter-end="opacity-100 scale-100 translate-y-0"
         class="pointer-events-auto relative w-full <?= $pos === 'bottom_right' ? 'max-w-sm' : 'max-w-xl' ?> bg-white rounded-3xl shadow-2xl border border-purple-100 overflow-hidden">

        <button type="button" @click="close(false)"
                aria-label="Close popup"
                class="absolute top-3.5 right-3.5 z-20 w-8 h-8 rounded-full bg-white/90 hover:bg-purple-50 text-slate-700 flex items-center justify-center shadow-sm transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg>
        </button>

        <div class="grid grid-cols-1 <?= empty($activePopup['image_path']) ? '' : 'sm:grid-cols-12' ?>">
            <?php if (!empty($activePopup['image_path'])): ?>
            <div class="sm:col-span-5 bg-gradient-to-br from-purple-100 via-indigo-50 to-purple-200 flex items-center justify-center p-4">
                <img src="<?= Validator::e($activePopup['image_path']) ?>" alt="<?= Validator::e($activePopup['title']) ?>"
                     class="w-full h-44 sm:h-full object-cover rounded-2xl shadow-md">
            </div>
            <?php endif; ?>

            <div class="<?= empty($activePopup['image_path']) ? 'p-7 sm:p-8 text-center' : 'sm:col-span-7 p-6 sm:p-7' ?> flex flex-col justify-center">
                <?php if (!empty($activePopup['badge_text'])): ?>
                <div class="mb-3">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-gradient-to-r from-purple-600 to-violet-600 text-white shadow-sm">
                        <svg class="w-3.5 h-3.5 text-amber-300" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 16.8l-6.2 4.5 2.4-7.4L2 9.4h7.6z"/></svg>
                        <?= Validator::e($activePopup['badge_text']) ?>
                    </span>
                </div>
                <?php endif; ?>

                <h3 class="text-2xl font-extrabold text-slate-900 leading-tight mb-2">
                    <?= Validator::e($activePopup['title']) ?>
                </h3>

                <?php if (!empty($activePopup['description'])): ?>
                <p class="text-sm text-slate-600 leading-relaxed mb-5">
                    <?= nl2br(Validator::e($activePopup['description'])) ?>
                </p>
                <?php endif; ?>

                <?php if (!empty($activePopup['cta_text']) && !empty($activePopup['cta_link'])): ?>
                <div>
                    <a href="<?= Validator::e($activePopup['cta_link']) ?>"
                       @click="close(false)"
                       class="inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-gradient-to-r from-purple-600 to-violet-600 hover:from-purple-700 hover:to-violet-700 text-white font-bold text-sm shadow-lg shadow-purple-500/25 transition">
                        <?= Validator::e($activePopup['cta_text']) ?>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="px-6 py-3 bg-slate-50 border-t border-slate-100 flex items-center justify-center">
            <label class="inline-flex items-center gap-2 text-xs text-slate-500 cursor-pointer select-none">
                <input type="checkbox" x-model="dontShowToday" @change="if(dontShowToday) close(true)"
                       class="rounded border-slate-300 text-purple-600 focus:ring-purple-500">
                <span>Don't show again today</span>
            </label>
        </div>
    </div>
</div>
<?php endif; ?>
