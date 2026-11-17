<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = Auth::requireUser();
ThemeManager::renderUserView('dashboard', get_defined_vars());
