</main>
</div><!-- end swipex-main-wrap -->
</div><!-- end swipex-shell -->

<?php $bottomScript = basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php'); ?>
<!-- SwipeX Sticky Bottom Navigation Dock (Matches Reference Screenshot) -->
<nav aria-label="SwipeX Bottom Navigation" class="swipex-bottom-nav px-3 pt-2 pb-2.5">
    <a href="/user/dashboard.php" class="flex flex-col items-center gap-0.5 min-w-[54px] text-[11px] font-bold <?= $bottomScript === 'dashboard.php' ? 'text-blue-600' : 'text-slate-400 hover:text-slate-600' ?>">
        <span class="w-11 h-8 rounded-xl flex items-center justify-center transition <?= $bottomScript === 'dashboard.php' ? 'bg-blue-50 text-blue-600' : '' ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        </span>
        <span>Home</span>
    </a>
    <a href="/user/services.php" class="flex flex-col items-center gap-0.5 min-w-[54px] text-[11px] font-bold <?= $bottomScript === 'services.php' ? 'text-blue-600' : 'text-slate-400 hover:text-slate-600' ?>">
        <span class="w-11 h-8 rounded-xl flex items-center justify-center transition <?= $bottomScript === 'services.php' ? 'bg-blue-50 text-blue-600' : '' ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
        </span>
        <span>Services</span>
    </a>
    <a href="/user/new-order.php" class="flex flex-col items-center -mt-7">
        <span class="w-[52px] h-[52px] rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-600 text-white shadow-lg shadow-blue-600/35 ring-4 ring-white flex items-center justify-center transition-transform active:scale-95">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2.6" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
        </span>
        <span class="text-[11px] font-extrabold mt-1 text-blue-600">Order</span>
    </a>
    <a href="/user/orders.php" class="flex flex-col items-center gap-0.5 min-w-[54px] text-[11px] font-bold <?= $bottomScript === 'orders.php' ? 'text-blue-600' : 'text-slate-400 hover:text-slate-600' ?>">
        <span class="w-11 h-8 rounded-xl flex items-center justify-center transition <?= $bottomScript === 'orders.php' ? 'bg-blue-50 text-blue-600' : '' ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg>
        </span>
        <span>Orders</span>
    </a>
    <a href="/user/profile.php" class="flex flex-col items-center gap-0.5 min-w-[54px] text-[11px] font-bold <?= $bottomScript === 'profile.php' ? 'text-blue-600' : 'text-slate-400 hover:text-slate-600' ?>">
        <span class="w-11 h-8 rounded-xl flex items-center justify-center transition <?= $bottomScript === 'profile.php' ? 'bg-blue-50 text-blue-600' : '' ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </span>
        <span>Profile</span>
    </a>
</nav>

<script src="/assets/js/app.js"></script>
<?php
$popupContext = 'dashboard';
require APP_ROOT . '/includes/popup_modal.php';
?>
</body>
</html>
