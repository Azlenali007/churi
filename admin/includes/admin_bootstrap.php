<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

function admin_guard(?string $permission = null): array
{
    return Auth::requireAdmin($permission);
}
