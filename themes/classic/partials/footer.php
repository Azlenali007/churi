</main>
</div><!-- end flex-1 -->
</div><!-- end min-h-screen -->

<?php $bottomScript = basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php'); ?>
<!-- Mobile Sticky Bottom Navigation (Matches Reference UI) -->
<nav aria-label="Mobile Navigation" class="lg:hidden fixed bottom-0 inset-x-0 z-40 smm-bottom-nav px-3 pt-2 pb-2.5 flex items-end justify-around">
    <a href="/user/dashboard.php" class="flex flex-col items-center gap-0.5 text-[11px] font-bold <?= $bottomScript === 'dashboard.php' ? 'text-purple-700' : 'text-slate-500' ?>">
        <svg class="w-5 h-5" fill="<?= $bottomScript === 'dashboard.php' ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
        <span>Home</span>
    </a>
    <a href="/user/services.php" class="flex flex-col items-center gap-0.5 text-[11px] font-bold <?= $bottomScript === 'services.php' ? 'text-purple-700' : 'text-slate-500' ?>">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
        <span>Services</span>
    </a>
    <a href="/user/new-order.php" class="flex flex-col items-center -mt-6">
        <span class="w-12 h-12 rounded-full bg-gradient-to-br from-indigo-600 via-purple-600 to-violet-600 text-white shadow-lg shadow-purple-500/40 ring-4 ring-white flex items-center justify-center">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2.6" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
        </span>
        <span class="text-[11px] font-extrabold mt-0.5 <?= $bottomScript === 'new-order.php' ? 'text-purple-700' : 'text-slate-700' ?>">Order</span>
    </a>
    <a href="/user/orders.php" class="flex flex-col items-center gap-0.5 text-[11px] font-bold <?= $bottomScript === 'orders.php' ? 'text-purple-700' : 'text-slate-500' ?>">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg>
        <span>Orders</span>
    </a>
    <a href="/user/profile.php" class="flex flex-col items-center gap-0.5 text-[11px] font-bold <?= $bottomScript === 'profile.php' ? 'text-purple-700' : 'text-slate-500' ?>">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
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
