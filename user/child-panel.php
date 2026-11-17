<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = Auth::requireUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_panel') {
        $name = Validator::str($_POST['panel_name'] ?? '', 120);
        $domain = strtolower(trim((string)($_POST['domain'] ?? '')));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim((string)$domain, '/');
        $margin = Currency::normalize((string)($_POST['profit_margin'] ?? '20'), 2);
        $fee = Currency::normalize(Helpers::setting('child_panel_price', '999.00'), 4);

        if ($name === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
            Helpers::setFlash('error', 'Please enter a valid panel name and domain (e.g. panel.yourdomain.com).');
            Helpers::redirect('/user/child-panel.php');
        }
        if (Database::fetch("SELECT id FROM child_panels WHERE domain = ? LIMIT 1", [$domain])) {
            Helpers::setFlash('error', 'This domain is already registered to another panel.');
            Helpers::redirect('/user/child-panel.php');
        }

        Database::beginTransaction();
        try {
            if (Currency::cmp($fee, '0', 4) > 0) {
                $debit = Wallet::debit((int)$user['id'], $fee, 'debit', 'Child Panel Setup Fee (' . $domain . ')', 'child_panel', $domain, 'cp_setup_' . $domain . '_' . time(), true);
                if (!$debit['ok']) {
                    Database::rollBack();
                    Helpers::setFlash('error', $debit['error']);
                    Helpers::redirect('/user/child-panel.php');
                }
            }
            $autoApprove = Helpers::setting('child_panel_auto_approve', '0') === '1';
            $panelId = Database::insert(
                "INSERT INTO child_panels (owner_user_id, panel_name, domain, verification_token, profit_margin, api_key, status, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))",
                [
                    $user['id'],
                    $name,
                    $domain,
                    'smmpro-verify=' . bin2hex(random_bytes(12)),
                    $margin,
                    Auth::generateApiKey(),
                    $autoApprove ? 'active' : 'pending',
                ]
            );
            Database::commit();
            Helpers::setFlash('success', 'Child panel created! Status: ' . ($autoApprove ? 'Active' : 'Pending Admin Approval'));
            Helpers::redirect('/user/child-panel.php?panel_id=' . $panelId);
        } catch (Throwable $e) {
            Database::rollBack();
            Helpers::setFlash('error', 'Failed to create child panel.');
            Helpers::redirect('/user/child-panel.php');
        }
    }

    $panelId = (int)($_POST['panel_id'] ?? 0);
    $panel = Database::fetch("SELECT * FROM child_panels WHERE id = ? AND owner_user_id = ? LIMIT 1", [$panelId, $user['id']]);
    if (!$panel) {
        Helpers::setFlash('error', 'Child panel not found or access denied.');
        Helpers::redirect('/user/child-panel.php');
    }

    if ($action === 'verify_domain') {
        $records = @dns_get_record($panel['domain'], DNS_TXT);
        $verified = false;
        if (is_array($records)) {
            foreach ($records as $rec) {
                if (!empty($rec['txt']) && str_contains($rec['txt'], $panel['verification_token'])) {
                    $verified = true;
                    break;
                }
            }
        }
        if ($verified) {
            Database::execute("UPDATE child_panels SET domain_verified = 1 WHERE id = ?", [$panelId]);
            Helpers::setFlash('success', 'Domain DNS TXT record verified successfully!');
        } else {
            Helpers::setFlash('error', 'TXT record (' . $panel['verification_token'] . ') was not detected on ' . $panel['domain'] . ' yet. DNS propagation may take a few minutes.');
        }
    } elseif ($action === 'add_service') {
        $parentSvcId = (int)($_POST['parent_service_id'] ?? 0);
        $rawCustomRate = Currency::normalize((string)($_POST['custom_rate'] ?? '0'), 4);
        $dispCode = Currency::getActiveDisplayCode($user);
        $customRate = ($dispCode !== Currency::getBaseCode())
            ? Currency::convertToBase($rawCustomRate, $dispCode, 4)
            : $rawCustomRate;
        $ps = Database::fetch("SELECT * FROM services WHERE id = ? AND status = 'active' LIMIT 1", [$parentSvcId]);
        if ($ps && Currency::cmp($customRate, '0', 4) > 0) {
            Database::execute(
                "INSERT INTO child_panel_services (child_panel_id, parent_service_id, custom_name, custom_rate, status)
                 VALUES (?, ?, ?, ?, 'active')
                 ON DUPLICATE KEY UPDATE custom_rate = VALUES(custom_rate), status = 'active'",
                [$panelId, $parentSvcId, $ps['name'], $customRate]
            );
            Helpers::setFlash('success', 'Child panel service pricing updated.');
        } else {
            Helpers::setFlash('error', 'Invalid service or rate.');
        }
    } elseif ($action === 'update_branding') {
        $pName = Validator::str($_POST['panel_name'] ?? $panel['panel_name'], 120);
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['primary_color'] ?? '')) ? $_POST['primary_color'] : '#7C3AED';
        $margin = Currency::normalize((string)($_POST['profit_margin'] ?? $panel['profit_margin']), 2);
        $logoPath = $panel['logo_path'];
        if (!empty($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $up = Uploader::uploadImage($_FILES['logo'], 'child_logos');
            if ($up['ok']) {
                $logoPath = $up['path'];
            } else {
                Helpers::setFlash('error', $up['error']);
                Helpers::redirect('/user/child-panel.php?panel_id=' . $panelId);
            }
        }
        Database::execute(
            "UPDATE child_panels SET panel_name = ?, primary_color = ?, profit_margin = ?, logo_path = ? WHERE id = ?",
            [$pName, $color, $margin, $logoPath, $panelId]
        );
        Helpers::setFlash('success', 'Child panel branding & margin saved.');
    }
    Helpers::redirect('/user/child-panel.php?panel_id=' . $panelId);
}

ThemeManager::renderUserView('child-panel', get_defined_vars());
