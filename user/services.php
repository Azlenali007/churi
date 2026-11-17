<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = Auth::requireUser();
ThemeManager::renderUserView('services', get_defined_vars());
