<?php
/**
 * Admin CSV Import AJAX Endpoint
 * Handles sample CSV downloads, secure CSV uploads, column mapping preview,
 * transactional batch imports, and job cancellation with centralized error handling.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$action = (string)($_POST['action'] ?? ($_GET['action'] ?? ''));

// Handle Sample CSV Download (GET)
if ($action === 'download_sample') {
    $admin = Auth::admin();
    if (!$admin) {
        http_response_code(401);
        exit('Admin login required.');
    }
    $canManage = Auth::adminHasPermission($admin, 'otp.providers')
        || Auth::adminHasPermission($admin, 'providers.manage')
        || Auth::adminHasPermission($admin, 'otp.manage')
        || Auth::adminHasPermission($admin, 'services.manage');
    if (!$canManage) {
        http_response_code(403);
        exit('Permission denied.');
    }
    $target = (string)($_GET['target'] ?? 'smm_services');
    if (!in_array($target, CsvImporter::VALID_TARGETS, true)) {
        $target = 'smm_services';
    }
    $sample = CsvImporter::getSampleCsv($target);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $sample['filename'] . '"');
    header('X-Content-Type-Options: nosniff');
    echo $sample['content'];
    exit;
}

ProviderErrorHandler::startAjaxGuard();

$admin = Auth::admin();
if (!$admin) {
    ProviderErrorHandler::jsonError('Admin session expired. Please sign in again.', 401, 'UNAUTHORIZED', 'auth');
}
$canManage = Auth::adminHasPermission($admin, 'otp.providers')
    || Auth::adminHasPermission($admin, 'providers.manage')
    || Auth::adminHasPermission($admin, 'otp.manage')
    || Auth::adminHasPermission($admin, 'services.manage');
if (!$canManage) {
    ProviderErrorHandler::jsonError('You do not have permission to import CSV files.', 403, 'FORBIDDEN', 'auth');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    ProviderErrorHandler::jsonError('Method not allowed.', 405, 'METHOD_NOT_ALLOWED', 'validation');
}

CSRF::requireValid();
$adminId = (int)$admin['id'];

try {
    if ($action === 'upload_csv') {
        $target = (string)($_POST['target'] ?? 'smm_services');
        if (empty($_FILES['csv_file'])) {
            ProviderErrorHandler::jsonError('Please choose a .csv file to upload.', 422, 'MISSING_FILE', 'validation');
        }
        $res = CsvImporter::stageUpload($_FILES['csv_file'], $target, $adminId);
        if (empty($res['ok'])) {
            ProviderErrorHandler::jsonError($res['error'] ?? 'Could not parse CSV file.', 422, 'CSV_VALIDATION_ERROR', 'validation');
        }
        ProviderErrorHandler::jsonSuccess($res);
    }

    if ($action === 'preview_csv') {
        $token = trim((string)($_POST['token'] ?? ''));
        $mappingRaw = (string)($_POST['mapping_json'] ?? '{}');
        $mapping = json_decode($mappingRaw, true);
        if (!is_array($mapping)) {
            ProviderErrorHandler::jsonError('Invalid column mapping payload.', 422, 'INVALID_MAPPING', 'validation');
        }
        $dupMode = (string)($_POST['duplicate_mode'] ?? 'skip');
        $providerId = (int)($_POST['provider_id'] ?? 0);

        $res = CsvImporter::previewMapped($token, $adminId, $mapping, $dupMode, $providerId);
        if (empty($res['ok'])) {
            ProviderErrorHandler::jsonError($res['error'] ?? 'Could not generate preview.', 422, 'PREVIEW_FAILED', 'validation');
        }
        ProviderErrorHandler::jsonSuccess($res);
    }

    if ($action === 'process_batch') {
        $token = trim((string)($_POST['token'] ?? ''));
        $offset = max(0, (int)($_POST['offset'] ?? 0));
        $limit = max(10, min(200, (int)($_POST['limit'] ?? 75)));

        $res = CsvImporter::processBatch($token, $adminId, $offset, $limit);
        if (empty($res['ok'])) {
            ProviderErrorHandler::jsonError($res['error'] ?? 'Batch import failed.', 422, 'BATCH_FAILED', 'database');
        }
        ProviderErrorHandler::jsonSuccess($res);
    }

    if ($action === 'cancel_job') {
        $token = trim((string)($_POST['token'] ?? ''));
        $res = CsvImporter::cancelJob($token, $adminId);
        ProviderErrorHandler::jsonSuccess($res);
    }

    ProviderErrorHandler::jsonError('Unsupported CSV importer action.', 400, 'INVALID_ACTION', 'validation');
} catch (Throwable $e) {
    $classified = ProviderErrorHandler::classifyException($e);
    Logger::errorFile('admin-csv-import exception: ' . ProviderErrorHandler::redact($e->getMessage()));
    ProviderErrorHandler::jsonError($classified['user_message'], 200, $classified['error_code'], $classified['category']);
}
