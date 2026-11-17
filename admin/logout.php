<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
Auth::logoutAdmin();
Helpers::redirect('/admin/login.php');
