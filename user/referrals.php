<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = Auth::requireUser();
ThemeManager::renderUserView('referrals', get_defined_vars());
