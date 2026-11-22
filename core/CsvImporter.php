<?php
/**
 * Secure CSV File Importer for SMM Services, OTP Services & OTP Countries
 * - Strict upload validation (.csv only, max size, binary/PHP payload rejection, UTF-8 + BOM handling)
 * - Auto header detection + interactive column mapping + preview + duplicate detection
 * - Prepared-statement transactional AJAX batch processing (never gets stuck)
 * - Sample CSV templates for Services & Countries
 */

class CsvImporter
{
    public const MAX_FILE_BYTES = 10485760; // 10 MB
    public const MAX_ROWS = 25000;
    public const STALE_SECONDS = 7200; // 2 hours
    public const VALID_TARGETS = ['smm_services', 'otp_services', 'countries'];
    public const VALID_DUP_MODES = ['skip', 'update', 'report'];

    /**
     * Target schema definitions for column mapping UI and validation.
     */
    public static function getTargetFields(string $target): array
    {
        if ($target === 'smm_services') {
            return [
                'name'                => ['label' => 'Service Name',        'required' => true,  'aliases' => ['service_name', 'name', 'title', 'service', 'service title']],
                'category'            => ['label' => 'Category',            'required' => false, 'aliases' => ['category', 'category_name', 'cat', 'group', 'platform_category']],
                'price'               => ['label' => 'Price / Rate',        'required' => true,  'aliases' => ['price', 'rate', 'selling_price', 'cost', 'rate_per_1000', 'amount']],
                'min_qty'             => ['label' => 'Min Quantity',        'required' => false, 'aliases' => ['min_qty', 'min', 'minimum', 'min_quantity', 'min_order']],
                'max_qty'             => ['label' => 'Max Quantity',        'required' => false, 'aliases' => ['max_qty', 'max', 'maximum', 'max_quantity', 'max_order']],
                'service_type'        => ['label' => 'Service Type',        'required' => false, 'aliases' => ['service_type', 'type']],
                'description'         => ['label' => 'Description',         'required' => false, 'aliases' => ['description', 'desc', 'details', 'notes']],
                'service_id'          => ['label' => 'Service ID',          'required' => false, 'aliases' => ['service_id', 'id', 'local_id']],
                'provider_service_id' => ['label' => 'Provider Service ID', 'required' => false, 'aliases' => ['provider_service_id', 'external_id', 'provider_code', 'ext_id', 'api_service_id']],
                'provider_name'       => ['label' => 'Provider Name',       'required' => false, 'aliases' => ['provider_name', 'provider', 'supplier']],
                'status'              => ['label' => 'Active Status',       'required' => false, 'aliases' => ['status', 'active', 'enabled', 'is_active']],
            ];
        }

        if ($target === 'otp_services') {
            return [
                'name'                => ['label' => 'Service Name',        'required' => true,  'aliases' => ['service_name', 'name', 'title', 'service', 'app']],
                'price'               => ['label' => 'Selling Price',       'required' => true,  'aliases' => ['price', 'selling_price', 'rate', 'cost', 'amount']],
                'provider_service_id' => ['label' => 'Provider Service ID', 'required' => false, 'aliases' => ['provider_service_id', 'service_code', 'provider_code', 'code', 'slug']],
                'service_id'          => ['label' => 'Service ID',          'required' => false, 'aliases' => ['service_id', 'id']],
                'category'            => ['label' => 'Category / Icon Key', 'required' => false, 'aliases' => ['category', 'icon_key', 'icon', 'group', 'service_type', 'type']],
                'description'         => ['label' => 'Description',         'required' => false, 'aliases' => ['description', 'desc', 'details']],
                'provider_name'       => ['label' => 'Provider Name',       'required' => false, 'aliases' => ['provider_name', 'provider', 'supplier']],
                'is_popular'          => ['label' => 'Popular (1/0)',       'required' => false, 'aliases' => ['is_popular', 'popular', 'featured']],
                'status'              => ['label' => 'Active Status',       'required' => false, 'aliases' => ['status', 'active', 'enabled', 'is_active']],
            ];
        }

        // countries
        return [
            'name'                => ['label' => 'Country Name',        'required' => true,  'aliases' => ['country_name', 'name', 'country', 'title']],
            'iso_code'            => ['label' => 'ISO Code',            'required' => true,  'aliases' => ['iso_code', 'iso', 'iso2', 'alpha2', 'country_iso']],
            'country_code'        => ['label' => 'Country / Dial Code', 'required' => false, 'aliases' => ['country_code', 'dial_code', 'phone_code', 'calling_code', 'prefix']],
            'flag_emoji'          => ['label' => 'Country Flag',        'required' => false, 'aliases' => ['country_flag', 'flag_emoji', 'flag', 'emoji']],
            'provider_country_id' => ['label' => 'Provider Country ID', 'required' => false, 'aliases' => ['provider_country_id', 'default_provider_code', 'provider_code', 'country_id', 'external_id', 'code']],
            'country_id'          => ['label' => 'Country ID',          'required' => false, 'aliases' => ['id', 'local_id']],
            'provider_name'       => ['label' => 'Provider Name',       'required' => false, 'aliases' => ['provider_name', 'provider']],
            'status'              => ['label' => 'Active Status',       'required' => false, 'aliases' => ['status', 'active', 'enabled', 'is_active']],
        ];
    }

    /**
     * Ensure private staging directory exists and clean stale staged jobs.
     */
    public static function getStagingDir(): string
    {
        $dir = APP_ROOT . '/storage/tmp_csv';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\nphp_flag engine off\n");
        }
        self::cleanupStaleFiles($dir);
        return $dir;
    }

    private static function cleanupStaleFiles(string $dir): void
    {
        $now = time();
        $entries = @scandir($dir);
        if (!is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.htaccess') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_file($path) && ($now - (int)@filemtime($path)) > self::STALE_SECONDS) {
                @unlink($path);
            }
        }
    }

    private static function resolveJobPaths(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $baseDir = realpath(self::getStagingDir());
        if ($baseDir === false) {
            return null;
        }
        $csvPath = $baseDir . DIRECTORY_SEPARATOR . 'job_' . $token . '.csv';
        $metaPath = $baseDir . DIRECTORY_SEPARATOR . 'job_' . $token . '.json';
        if (!str_starts_with($csvPath, $baseDir . DIRECTORY_SEPARATOR) || !str_starts_with($metaPath, $baseDir . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return ['csv' => $csvPath, 'meta' => $metaPath];
    }

    /**
     * Validate and stage an uploaded CSV file safely.
     */
    public static function stageUpload(array $file, string $target, int $adminId): array
    {
        if (!in_array($target, self::VALID_TARGETS, true)) {
            return ['ok' => false, 'error' => 'Invalid import target selected.'];
        }

        $uploadErr = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadErr !== UPLOAD_ERR_OK) {
            $errMap = [
                UPLOAD_ERR_INI_SIZE   => 'Uploaded file exceeds the server upload limit.',
                UPLOAD_ERR_FORM_SIZE  => 'Uploaded file exceeds the maximum allowed size.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded. Please try again.',
                UPLOAD_ERR_NO_FILE    => 'Please select a CSV file to upload.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server temporary upload folder is missing.',
                UPLOAD_ERR_CANT_WRITE => 'Server failed to store the uploaded file.',
            ];
            return ['ok' => false, 'error' => $errMap[$uploadErr] ?? 'File upload failed.'];
        }

        $origName = (string)($file['name'] ?? '');
        $tmpPath = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);

        if ($size <= 0) {
            return ['ok' => false, 'error' => 'The uploaded CSV file is empty.'];
        }
        if ($size > self::MAX_FILE_BYTES) {
            return ['ok' => false, 'error' => 'CSV file exceeds maximum size limit of 10 MB.'];
        }

        // Strict extension & filename validation (block double extensions like shell.php.csv)
        $baseName = basename($origName);
        if (str_contains($baseName, "\0") || !preg_match('/\.csv$/i', $baseName)) {
            return ['ok' => false, 'error' => 'Only valid .csv files are accepted.'];
        }
        if (preg_match('/\.(php\d?|phtml|phar|exe|sh|js|html?|svg|jsp|asp|aspx)\./i', $baseName)) {
            return ['ok' => false, 'error' => 'Invalid filename detected. Please upload a plain .csv file.'];
        }

        if ($tmpPath === '' || (!is_uploaded_file($tmpPath) && PHP_SAPI !== 'cli')) {
            return ['ok' => false, 'error' => 'Invalid file upload source.'];
        }

        $raw = @file_get_contents($tmpPath);
        if ($raw === false || $raw === '') {
            return ['ok' => false, 'error' => 'Unable to read uploaded CSV file.'];
        }

        // Reject binary payloads / NUL bytes / executable headers / embedded PHP tags
        if (str_contains($raw, "\0")) {
            return ['ok' => false, 'error' => 'Uploaded file appears to be binary rather than a plain text CSV file.'];
        }
        $prefix4 = substr($raw, 0, 4);
        if (in_array($prefix4, ["PK\x03\x04", "%PDF", "\x89PNG", "GIF8", "\x7fELF", "MZ\x90\x00"], true)) {
            return ['ok' => false, 'error' => 'Uploaded file format is not a valid CSV text file.'];
        }
        if (preg_match('/<\?(php|=)/i', $raw)) {
            return ['ok' => false, 'error' => 'Uploaded CSV contains disallowed executable code tags.'];
        }

        // Strip UTF-8 BOM & ensure valid UTF-8
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $converted = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252,ISO-8859-1,UTF-8');
            if ($converted !== false && $converted !== '') {
                $raw = $converted;
            }
        }
        // Normalize line endings
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        if (trim($raw) === '') {
            return ['ok' => false, 'error' => 'The CSV file has no content.'];
        }

        $delimiter = self::detectDelimiter($raw);
        $token = bin2hex(random_bytes(16));
        $paths = self::resolveJobPaths($token);
        if (!$paths) {
            return ['ok' => false, 'error' => 'Failed to initialize CSV staging storage.'];
        }

        if (@file_put_contents($paths['csv'], $raw, LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'Failed to store uploaded CSV file for processing.'];
        }

        // Parse headers, row count & sample rows
        $parsed = self::inspectCsvFile($paths['csv'], $delimiter);
        if (!$parsed['ok']) {
            @unlink($paths['csv']);
            return $parsed;
        }

        if ($parsed['total_rows'] === 0) {
            @unlink($paths['csv']);
            return ['ok' => false, 'error' => 'The CSV file contains headers but zero data rows.'];
        }
        if ($parsed['total_rows'] > self::MAX_ROWS) {
            @unlink($paths['csv']);
            return ['ok' => false, 'error' => 'CSV exceeds maximum supported limit of ' . number_format(self::MAX_ROWS) . ' rows per file.'];
        }

        $suggestedMapping = self::autoMapHeaders($parsed['headers'], $target);

        $meta = [
            'token'       => $token,
            'admin_id'    => $adminId,
            'target'      => $target,
            'filename'    => substr($baseName, 0, 140),
            'delimiter'   => $delimiter,
            'headers'     => $parsed['headers'],
            'total_rows'  => $parsed['total_rows'],
            'created_at'  => time(),
            'mapping'     => $suggestedMapping,
            'dup_mode'    => 'skip',
            'provider_id' => 0,
            'stats'       => [
                'processed' => 0,
                'imported'  => 0,
                'updated'   => 0,
                'skipped'   => 0,
                'failed'    => 0,
                'errors'    => [],
            ],
        ];
        @file_put_contents($paths['meta'], json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

        return [
            'ok'                => true,
            'token'             => $token,
            'target'            => $target,
            'filename'          => $meta['filename'],
            'delimiter'         => $delimiter === "\t" ? 'TAB' : $delimiter,
            'headers'           => $parsed['headers'],
            'total_rows'        => $parsed['total_rows'],
            'sample_rows'       => $parsed['sample_rows'],
            'fields'            => self::getTargetFields($target),
            'suggested_mapping' => $suggestedMapping,
        ];
    }

    /**
     * Validate mapping & produce preview rows, missing-field warnings, and duplicate detection.
     */
    public static function previewMapped(string $token, int $adminId, array $mapping, string $dupMode = 'skip', int $providerId = 0): array
    {
        $job = self::loadJob($token, $adminId);
        if (!$job['ok']) {
            return $job;
        }
        $meta = $job['meta'];
        $paths = $job['paths'];
        $target = (string)$meta['target'];
        $dupMode = in_array($dupMode, self::VALID_DUP_MODES, true) ? $dupMode : 'skip';

        $fields = self::getTargetFields($target);
        $cleanMap = [];
        $headersCount = count($meta['headers']);
        foreach ($fields as $fKey => $fDef) {
            $colIdx = $mapping[$fKey] ?? '';
            if ($colIdx !== '' && $colIdx !== null && is_numeric($colIdx)) {
                $idx = (int)$colIdx;
                if ($idx >= 0 && $idx < $headersCount) {
                    $cleanMap[$fKey] = $idx;
                }
            }
        }

        // Check required columns mapped
        $missingCols = [];
        foreach ($fields as $fKey => $fDef) {
            if (!empty($fDef['required']) && !isset($cleanMap[$fKey])) {
                $missingCols[] = $fDef['label'];
            }
        }
        if (!empty($missingCols)) {
            return ['ok' => false, 'error' => 'Please map required column(s): ' . implode(', ', $missingCols)];
        }

        // Persist validated mapping + options into job metadata
        $meta['mapping'] = $cleanMap;
        $meta['dup_mode'] = $dupMode;
        $meta['provider_id'] = max(0, $providerId);
        $meta['stats'] = [
            'processed' => 0,
            'imported'  => 0,
            'updated'   => 0,
            'skipped'   => 0,
            'failed'    => 0,
            'errors'    => [],
        ];
        @file_put_contents($paths['meta'], json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

        $lookup = self::buildExistingLookup($target, $meta['provider_id']);
        $previewRows = [];
        $summary = [
            'total_rows' => 0,
            'valid_new'  => 0,
            'duplicates' => 0,
            'invalid'    => 0,
            'issues'     => [],
        ];

        $fh = @fopen($paths['csv'], 'r');
        if (!$fh) {
            return ['ok' => false, 'error' => 'Staged CSV file could not be opened. Please upload again.'];
        }

        $delim = (string)$meta['delimiter'];
        // Skip header row
        fgetcsv($fh, 0, $delim, '"', '\\');
        $rowNum = 1;
        $seenKeys = [];

        while (($rawRow = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
            if (self::isRowBlank($rawRow)) {
                continue;
            }
            $rowNum++;
            $summary['total_rows']++;

            $extracted = self::extractRow($rawRow, $cleanMap);
            $val = self::validateAndNormalizeRow($target, $extracted, $meta['provider_id'], $lookup);

            $status = 'new';
            $note = '';
            if (!$val['ok']) {
                $status = 'invalid';
                $note = $val['error'];
                $summary['invalid']++;
                if (count($summary['issues']) < 100) {
                    $summary['issues'][] = ['row' => $rowNum, 'reason' => $val['error']];
                }
            } else {
                $dupKey = $val['dup_key'];
                $isDbDup = self::findExistingRecord($target, $val['data'], $lookup) !== null;
                $isFileDup = $dupKey !== '' && isset($seenKeys[$dupKey]);
                if ($dupKey !== '') {
                    $seenKeys[$dupKey] = true;
                }
                if ($isDbDup || $isFileDup) {
                    $status = 'duplicate';
                    $note = $isDbDup ? 'Matches existing database record' : 'Duplicate row inside CSV file';
                    $summary['duplicates']++;
                } else {
                    $summary['valid_new']++;
                }
            }

            if (count($previewRows) < 25) {
                $previewRows[] = [
                    'row_number' => $rowNum,
                    'status'     => $status,
                    'note'       => $note,
                    'data'       => $val['ok'] ? $val['preview'] : $extracted,
                ];
            }
        }
        fclose($fh);

        return [
            'ok'           => true,
            'target'       => $target,
            'dup_mode'     => $dupMode,
            'summary'      => $summary,
            'preview_rows' => $previewRows,
        ];
    }

    /**
     * Process a chunk of rows inside a DB transaction for smooth AJAX progress reporting.
     */
    public static function processBatch(string $token, int $adminId, int $offset = 0, int $limit = 75): array
    {
        $job = self::loadJob($token, $adminId);
        if (!$job['ok']) {
            return $job;
        }
        $meta = $job['meta'];
        $paths = $job['paths'];
        $target = (string)$meta['target'];
        $mapping = (array)($meta['mapping'] ?? []);
        $dupMode = in_array($meta['dup_mode'] ?? 'skip', self::VALID_DUP_MODES, true) ? (string)$meta['dup_mode'] : 'skip';
        $providerId = (int)($meta['provider_id'] ?? 0);

        if (empty($mapping)) {
            return ['ok' => false, 'error' => 'Please preview and confirm column mapping before importing.'];
        }

        $offset = max(0, $offset);
        $limit = max(10, min(200, $limit));
        if ($offset === 0) {
            $meta['stats'] = [
                'processed' => 0,
                'imported'  => 0,
                'updated'   => 0,
                'skipped'   => 0,
                'failed'    => 0,
                'errors'    => [],
            ];
        }

        $fh = @fopen($paths['csv'], 'r');
        if (!$fh) {
            return ['ok' => false, 'error' => 'Staged CSV file expired or missing. Please upload again.'];
        }

        $delim = (string)$meta['delimiter'];
        // Skip header row
        fgetcsv($fh, 0, $delim, '"', '\\');

        $batchRows = [];
        $dataIdx = 0;
        $rowNum = 1;
        $hasMore = false;

        while (($rawRow = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
            if (self::isRowBlank($rawRow)) {
                continue;
            }
            $rowNum++;
            if ($dataIdx < $offset) {
                $dataIdx++;
                continue;
            }
            if (count($batchRows) < $limit) {
                $batchRows[] = ['row_number' => $rowNum, 'raw' => $rawRow];
                $dataIdx++;
            } else {
                $hasMore = true;
                break;
            }
        }
        fclose($fh);

        $lookup = self::buildExistingLookup($target, $providerId);
        $stats = $meta['stats'];

        try {
            Database::beginTransaction();
            foreach ($batchRows as $item) {
                $rNum = (int)$item['row_number'];
                $extracted = self::extractRow($item['raw'], $mapping);
                $val = self::validateAndNormalizeRow($target, $extracted, $providerId, $lookup);
                $stats['processed']++;

                if (!$val['ok']) {
                    $stats['failed']++;
                    if (count($stats['errors']) < 200) {
                        $stats['errors'][] = ['row' => $rNum, 'reason' => $val['error']];
                    }
                    continue;
                }

                try {
                    $res = self::upsertRecord($target, $val['data'], $dupMode, $lookup);
                    if ($res['action'] === 'imported') {
                        $stats['imported']++;
                    } elseif ($res['action'] === 'updated') {
                        $stats['updated']++;
                    } elseif ($res['action'] === 'skipped') {
                        $stats['skipped']++;
                        if ($dupMode === 'report' && !empty($res['reason']) && count($stats['errors']) < 200) {
                            $stats['errors'][] = ['row' => $rNum, 'reason' => $res['reason']];
                        }
                    } else {
                        $stats['failed']++;
                        if (count($stats['errors']) < 200) {
                            $stats['errors'][] = ['row' => $rNum, 'reason' => $res['reason'] ?? 'Failed to save row.'];
                        }
                    }
                } catch (Throwable $rowEx) {
                    $stats['failed']++;
                    Logger::errorFile('CsvImporter row #' . $rNum . ' error: ' . ProviderErrorHandler::redact($rowEx->getMessage()));
                    if (count($stats['errors']) < 200) {
                        $stats['errors'][] = ['row' => $rNum, 'reason' => 'Database constraint error while saving row.'];
                    }
                }
            }
            Database::commit();
        } catch (Throwable $txEx) {
            if (Database::pdo()->inTransaction()) {
                Database::rollBack();
            }
            Logger::errorFile('CsvImporter transaction failure: ' . ProviderErrorHandler::redact($txEx->getMessage()));
            return ['ok' => false, 'error' => 'A database error interrupted this batch. No partial records were saved.'];
        }

        $meta['stats'] = $stats;
        $nextOffset = $offset + count($batchRows);
        $totalRows = max(1, (int)$meta['total_rows']);
        $completed = !$hasMore || $nextOffset >= (int)$meta['total_rows'];
        $percent = $completed ? 100 : min(99, (int)round(($stats['processed'] / $totalRows) * 100));

        if ($completed) {
            Logger::activity(
                'admin',
                $adminId,
                'csv.import.' . $target,
                'csv_import',
                $target,
                sprintf(
                    'CSV import (%s): imported=%d, updated=%d, skipped=%d, failed=%d',
                    $target,
                    $stats['imported'],
                    $stats['updated'],
                    $stats['skipped'],
                    $stats['failed']
                )
            );
            // Clean up staged files immediately after successful completion
            @unlink($paths['csv']);
            @unlink($paths['meta']);
        } else {
            @file_put_contents($paths['meta'], json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        }

        return [
            'ok'          => true,
            'completed'   => $completed,
            'next_offset' => $nextOffset,
            'total_rows'  => (int)$meta['total_rows'],
            'percent'     => $percent,
            'stats'       => $stats,
        ];
    }

    /**
     * Cancel and remove a staged CSV import job.
     */
    public static function cancelJob(string $token, int $adminId): array
    {
        $paths = self::resolveJobPaths($token);
        if (!$paths) {
            return ['ok' => true];
        }
        if (is_file($paths['meta'])) {
            $meta = json_decode((string)@file_get_contents($paths['meta']), true);
            if (is_array($meta) && (int)($meta['admin_id'] ?? 0) !== $adminId) {
                return ['ok' => false, 'error' => 'Unauthorized CSV job token.'];
            }
            @unlink($paths['meta']);
        }
        if (is_file($paths['csv'])) {
            @unlink($paths['csv']);
        }
        return ['ok' => true];
    }

    /**
     * Generate clean downloadable Sample CSV templates.
     */
    public static function getSampleCsv(string $target): array
    {
        if ($target === 'smm_services') {
            $headers = ['service_id', 'service_name', 'category', 'description', 'price', 'min_qty', 'max_qty', 'service_type', 'provider_service_id', 'provider_name', 'status'];
            $rows = [
                ['', 'Instagram Followers [Real & High Quality]', 'Instagram Followers', 'Fast delivery with 30-day refill guarantee, works on public profiles.', '85.00', '100', '50000', 'default', '1042', '', 'active'],
                ['', 'YouTube Views [High Retention]', 'YouTube Views', 'Organic retention views with instant start.', '120.50', '500', '100000', 'default', '2081', '', 'active'],
                ['', 'Telegram Channel Members [Non-Drop]', 'Telegram Members', 'Stable channel subscribers, public channels only.', '64.00', '100', '25000', 'default', '3115', '', 'active'],
            ];
            return ['filename' => 'sample_smm_services.csv', 'content' => self::buildCsvString($headers, $rows)];
        }

        if ($target === 'otp_services') {
            $headers = ['service_id', 'service_name', 'category', 'description', 'price', 'provider_service_id', 'provider_name', 'is_popular', 'status'];
            $rows = [
                ['', 'WhatsApp', 'whatsapp', 'Instant one-time SMS verification for WhatsApp accounts.', '25.00', 'whatsapp', '', '1', 'active'],
                ['', 'Telegram', 'telegram', 'Fast SMS verification code for Telegram.', '22.00', 'telegram', '', '1', 'active'],
                ['', 'Google / Gmail', 'google', 'One-time verification code for Google services.', '18.50', 'google', '', '1', 'active'],
                ['', 'Instagram', 'instagram', 'Instant verification SMS for Instagram.', '16.00', 'instagram', '', '0', 'active'],
            ];
            return ['filename' => 'sample_otp_services.csv', 'content' => self::buildCsvString($headers, $rows)];
        }

        // countries
        $headers = ['country_id', 'country_name', 'country_code', 'iso_code', 'country_flag', 'provider_country_id', 'provider_name', 'status'];
        $rows = [
            ['', 'India', '+91', 'IN', '🇮🇳', 'india', '', 'active'],
            ['', 'United States', '+1', 'US', '🇺🇸', 'usa', '', 'active'],
            ['', 'United Kingdom', '+44', 'GB', '🇬🇧', 'england', '', 'active'],
            ['', 'Indonesia', '+62', 'ID', '🇮🇩', 'indonesia', '', 'active'],
        ];
        return ['filename' => 'sample_otp_countries.csv', 'content' => self::buildCsvString($headers, $rows)];
    }

    private static function buildCsvString(array $headers, array $rows): string
    {
        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, $headers);
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        rewind($fp);
        $csv = (string)stream_get_contents($fp);
        fclose($fp);
        return "\xEF\xBB\xBF" . $csv;
    }

    private static function loadJob(string $token, int $adminId): array
    {
        $paths = self::resolveJobPaths($token);
        if (!$paths || !is_file($paths['meta']) || !is_file($paths['csv'])) {
            return ['ok' => false, 'error' => 'CSV upload session expired or not found. Please upload the file again.'];
        }
        $meta = json_decode((string)@file_get_contents($paths['meta']), true);
        if (!is_array($meta) || (int)($meta['admin_id'] ?? 0) !== $adminId) {
            return ['ok' => false, 'error' => 'Unauthorized CSV import session.'];
        }
        return ['ok' => true, 'meta' => $meta, 'paths' => $paths];
    }

    private static function detectDelimiter(string $raw): string
    {
        $firstLine = strtok($raw, "\n") ?: '';
        $candidates = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($candidates);
        $best = array_key_first($candidates);
        return ($candidates[$best] > 0) ? (string)$best : ',';
    }

    private static function inspectCsvFile(string $csvPath, string $delimiter): array
    {
        $fh = @fopen($csvPath, 'r');
        if (!$fh) {
            return ['ok' => false, 'error' => 'Could not open staged CSV file.'];
        }
        $headers = fgetcsv($fh, 0, $delimiter, '"', '\\');
        if (!is_array($headers) || count($headers) === 0) {
            fclose($fh);
            return ['ok' => false, 'error' => 'CSV header row is missing or unreadable.'];
        }
        $headers = array_map(static fn($h) => trim((string)$h), $headers);
        $nonEmptyHeaders = array_filter($headers, static fn($h) => $h !== '');
        if (empty($nonEmptyHeaders)) {
            fclose($fh);
            return ['ok' => false, 'error' => 'CSV header row contains no column names.'];
        }

        $totalRows = 0;
        $sampleRows = [];
        while (($row = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
            if (self::isRowBlank($row)) {
                continue;
            }
            $totalRows++;
            if (count($sampleRows) < 5) {
                $sampleRows[] = array_map(static fn($v) => mb_substr(trim((string)$v), 0, 120), $row);
            }
            if ($totalRows > self::MAX_ROWS + 1) {
                break;
            }
        }
        fclose($fh);

        return [
            'ok'          => true,
            'headers'     => $headers,
            'total_rows'  => $totalRows,
            'sample_rows' => $sampleRows,
        ];
    }

    private static function autoMapHeaders(array $headers, string $target): array
    {
        $fields = self::getTargetFields($target);
        $normalized = [];
        foreach ($headers as $idx => $h) {
            $norm = strtolower((string)preg_replace('/[^a-z0-9]+/', '_', trim($h)));
            $norm = trim($norm, '_');
            $normalized[$idx] = $norm;
        }

        $map = [];
        $usedCols = [];
        foreach ($fields as $fKey => $fDef) {
            foreach ($fDef['aliases'] as $alias) {
                $aliasNorm = strtolower((string)preg_replace('/[^a-z0-9]+/', '_', $alias));
                foreach ($normalized as $idx => $hNorm) {
                    if (isset($usedCols[$idx])) {
                        continue;
                    }
                    if ($hNorm === $aliasNorm) {
                        $map[$fKey] = $idx;
                        $usedCols[$idx] = true;
                        break 2;
                    }
                }
            }
        }
        return $map;
    }

    private static function isRowBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string)$cell) !== '') {
                return false;
            }
        }
        return true;
    }

    private static function extractRow(array $rawRow, array $mapping): array
    {
        $out = [];
        foreach ($mapping as $field => $idx) {
            $out[$field] = isset($rawRow[$idx]) ? trim((string)$rawRow[$idx]) : '';
        }
        return $out;
    }

    private static function parseBoolStatus(string $val, string $default = 'active'): string
    {
        $v = strtolower(trim($val));
        if ($v === '') {
            return $default;
        }
        if (in_array($v, ['0', 'false', 'no', 'inactive', 'disabled', 'off'], true)) {
            return 'inactive';
        }
        return 'active';
    }

    private static function parsePrice(string $val): ?string
    {
        $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', trim($val)));
        if ($clean === '' || !is_numeric($clean)) {
            return null;
        }
        $num = (float)$clean;
        if ($num < 0 || !is_finite($num)) {
            return null;
        }
        return Currency::normalize((string)$num, 4);
    }

    private static function buildExistingLookup(string $target, int $defaultProviderId): array
    {
        $lookup = [
            'default_provider_id' => $defaultProviderId,
            'providers_by_name'   => [],
            'categories_by_name'  => [],
            'categories_by_slug'  => [],
            'existing_by_id'      => [],
            'existing_by_key'     => [],
            'existing_by_name'    => [],
        ];

        if ($target === 'smm_services') {
            foreach (Database::fetchAll("SELECT id, name FROM providers") as $p) {
                $lookup['providers_by_name'][strtolower(trim((string)$p['name']))] = (int)$p['id'];
            }
            foreach (Database::fetchAll("SELECT id, name, slug FROM categories") as $c) {
                $lookup['categories_by_name'][strtolower(trim((string)$c['name']))] = (int)$c['id'];
                $lookup['categories_by_slug'][strtolower(trim((string)$c['slug']))] = (int)$c['id'];
            }
            foreach (Database::fetchAll("SELECT id, name, provider_id, provider_service_id FROM services") as $s) {
                $sid = (int)$s['id'];
                $lookup['existing_by_id'][$sid] = $sid;
                if (!empty($s['provider_id']) && !empty($s['provider_service_id'])) {
                    $lookup['existing_by_key'][(int)$s['provider_id'] . '|' . strtolower(trim((string)$s['provider_service_id']))] = $sid;
                }
                $lookup['existing_by_name'][strtolower(trim((string)$s['name']))] = $sid;
            }
        } elseif ($target === 'otp_services') {
            foreach (Database::fetchAll("SELECT id, name, slug FROM otp_providers") as $p) {
                $lookup['providers_by_name'][strtolower(trim((string)$p['name']))] = (int)$p['id'];
                $lookup['providers_by_name'][strtolower(trim((string)$p['slug']))] = (int)$p['id'];
            }
            foreach (Database::fetchAll("SELECT id, name, slug, provider_id, provider_service_code FROM otp_services") as $s) {
                $sid = (int)$s['id'];
                $lookup['existing_by_id'][$sid] = $sid;
                $lookup['existing_by_key'][strtolower(trim((string)$s['slug']))] = $sid;
                if (!empty($s['provider_service_code'])) {
                    $pKey = ((int)($s['provider_id'] ?? 0)) . '|' . strtolower(trim((string)$s['provider_service_code']));
                    $lookup['existing_by_key'][$pKey] = $sid;
                }
                $lookup['existing_by_name'][strtolower(trim((string)$s['name']))] = $sid;
            }
        } else {
            foreach (Database::fetchAll("SELECT id, name, iso_code, default_provider_code FROM otp_countries") as $c) {
                $cid = (int)$c['id'];
                $lookup['existing_by_id'][$cid] = $cid;
                $lookup['existing_by_key'][strtoupper(trim((string)$c['iso_code']))] = $cid;
                $lookup['existing_by_name'][strtolower(trim((string)$c['name']))] = $cid;
            }
        }

        return $lookup;
    }

    private static function validateAndNormalizeRow(string $target, array $row, int $defaultProviderId, array $lookup): array
    {
        if ($target === 'smm_services') {
            $name = Validator::str($row['name'] ?? '', 255);
            if ($name === '') {
                return ['ok' => false, 'error' => 'Missing required Service Name.'];
            }
            $price = self::parsePrice((string)($row['price'] ?? ''));
            if ($price === null) {
                return ['ok' => false, 'error' => 'Invalid or missing Price/Rate value.'];
            }
            $minQty = isset($row['min_qty']) && $row['min_qty'] !== '' ? max(1, (int)$row['min_qty']) : 10;
            $maxQty = isset($row['max_qty']) && $row['max_qty'] !== '' ? max($minQty, (int)$row['max_qty']) : max($minQty, 10000);
            $rawType = strtolower(trim((string)($row['service_type'] ?? 'default')));
            $rawType = str_replace([' ', '-'], '_', $rawType);
            $validTypes = ['default', 'custom_comments', 'package', 'drip_feed', 'subscriptions'];
            $svcType = in_array($rawType, $validTypes, true) ? $rawType : 'default';

            $catName = Validator::str($row['category'] ?? '', 150) ?: 'General Services';
            $desc = Validator::str($row['description'] ?? '', 4000);
            $provSvcId = Validator::str($row['provider_service_id'] ?? '', 80);
            $provName = Validator::str($row['provider_name'] ?? '', 120);
            $localId = isset($row['service_id']) && ctype_digit((string)$row['service_id']) ? (int)$row['service_id'] : 0;
            $status = self::parseBoolStatus((string)($row['status'] ?? 'active'));

            $provId = $defaultProviderId > 0 ? $defaultProviderId : null;
            if ($provName !== '' && isset($lookup['providers_by_name'][strtolower($provName)])) {
                $provId = $lookup['providers_by_name'][strtolower($provName)];
            }

            $dupKey = ($provId && $provSvcId !== '')
                ? ('p:' . $provId . ':' . strtolower($provSvcId))
                : ('n:' . strtolower($name));

            return [
                'ok'      => true,
                'dup_key' => $dupKey,
                'data'    => [
                    'service_id'          => $localId,
                    'name'                => $name,
                    'category_name'       => $catName,
                    'description'         => $desc,
                    'rate'                => $price,
                    'min_qty'             => $minQty,
                    'max_qty'             => $maxQty,
                    'type'                => $svcType,
                    'provider_id'         => $provId,
                    'provider_service_id' => $provSvcId !== '' ? $provSvcId : null,
                    'status'              => $status,
                ],
                'preview' => [
                    'Name'     => $name,
                    'Category' => $catName,
                    'Price'    => $price,
                    'Min/Max'  => $minQty . ' / ' . $maxQty,
                    'Status'   => ucfirst($status),
                ],
            ];
        }

        if ($target === 'otp_services') {
            $name = Validator::str($row['name'] ?? '', 120);
            if ($name === '') {
                return ['ok' => false, 'error' => 'Missing required Service Name.'];
            }
            $price = self::parsePrice((string)($row['price'] ?? ''));
            if ($price === null) {
                return ['ok' => false, 'error' => 'Invalid or missing Selling Price value.'];
            }
            $provCode = Validator::str($row['provider_service_id'] ?? '', 100);
            $slugBase = $provCode !== '' ? $provCode : $name;
            $slug = trim(strtolower((string)preg_replace('/[^a-z0-9]+/i', '_', $slugBase)), '_');
            if ($slug === '') {
                $slug = 'svc_' . substr(md5($name), 0, 8);
            }
            $slug = substr($slug, 0, 100);

            $iconKey = trim(strtolower((string)preg_replace('/[^a-z0-9_]+/i', '', (string)($row['category'] ?? ''))));
            if ($iconKey === '') {
                $iconKey = substr($slug, 0, 40) ?: 'general';
            }
            $desc = Validator::str($row['description'] ?? '', 2000);
            $provName = Validator::str($row['provider_name'] ?? '', 120);
            $localId = isset($row['service_id']) && ctype_digit((string)$row['service_id']) ? (int)$row['service_id'] : 0;
            $isPopRaw = strtolower(trim((string)($row['is_popular'] ?? '0')));
            $isPopular = in_array($isPopRaw, ['1', 'true', 'yes', 'popular'], true) ? 1 : 0;
            $status = self::parseBoolStatus((string)($row['status'] ?? 'active'));

            $provId = $defaultProviderId > 0 ? $defaultProviderId : null;
            if ($provName !== '' && isset($lookup['providers_by_name'][strtolower($provName)])) {
                $provId = $lookup['providers_by_name'][strtolower($provName)];
            }

            return [
                'ok'      => true,
                'dup_key' => 'otp_svc:' . $slug,
                'data'    => [
                    'service_id'            => $localId,
                    'name'                  => $name,
                    'slug'                  => $slug,
                    'icon_key'              => substr($iconKey, 0, 60),
                    'description'           => $desc,
                    'selling_price'         => $price,
                    'provider_id'           => $provId,
                    'provider_service_code' => $provCode !== '' ? $provCode : $slug,
                    'is_popular'            => $isPopular,
                    'status'                => $status,
                ],
                'preview' => [
                    'Name'   => $name,
                    'Code'   => $provCode !== '' ? $provCode : $slug,
                    'Price'  => $price,
                    'Status' => ucfirst($status),
                ],
            ];
        }

        // countries
        $name = Validator::str($row['name'] ?? '', 120);
        if ($name === '') {
            return ['ok' => false, 'error' => 'Missing required Country Name.'];
        }
        $iso = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', (string)($row['iso_code'] ?? '')));
        if ($iso === '' || strlen($iso) < 2 || strlen($iso) > 10) {
            return ['ok' => false, 'error' => 'Invalid or missing ISO Code (e.g. IN, US, GB).'];
        }
        $dial = Validator::str($row['country_code'] ?? '', 15);
        if ($dial !== '' && $dial[0] !== '+' && ctype_digit($dial)) {
            $dial = '+' . $dial;
        }
        $flag = Validator::str($row['flag_emoji'] ?? '', 16);
        if ($flag === '' && strlen($iso) === 2 && ctype_alpha($iso)) {
            $flag = self::isoToFlag($iso);
        }
        $provCountry = Validator::str($row['provider_country_id'] ?? '', 80);
        if ($provCountry === '') {
            $provCountry = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '', $name)));
        }
        $localId = isset($row['country_id']) && ctype_digit((string)$row['country_id']) ? (int)$row['country_id'] : 0;
        $status = self::parseBoolStatus((string)($row['status'] ?? 'active'));

        return [
            'ok'      => true,
            'dup_key' => 'country:' . $iso,
            'data'    => [
                'country_id'            => $localId,
                'name'                  => $name,
                'iso_code'              => $iso,
                'dial_code'             => $dial !== '' ? $dial : null,
                'flag_emoji'            => $flag !== '' ? $flag : null,
                'default_provider_code' => $provCountry,
                'status'                => $status,
            ],
            'preview' => [
                'Country' => ($flag ? $flag . ' ' : '') . $name,
                'ISO'     => $iso,
                'Dial'    => $dial ?: '—',
                'Code'    => $provCountry,
                'Status'  => ucfirst($status),
            ],
        ];
    }

    private static function findExistingRecord(string $target, array $data, array $lookup): ?int
    {
        if ($target === 'smm_services') {
            if (!empty($data['service_id']) && isset($lookup['existing_by_id'][$data['service_id']])) {
                return $lookup['existing_by_id'][$data['service_id']];
            }
            if (!empty($data['provider_id']) && !empty($data['provider_service_id'])) {
                $k = (int)$data['provider_id'] . '|' . strtolower((string)$data['provider_service_id']);
                if (isset($lookup['existing_by_key'][$k])) {
                    return $lookup['existing_by_key'][$k];
                }
            }
            $nameKey = strtolower((string)$data['name']);
            return $lookup['existing_by_name'][$nameKey] ?? null;
        }

        if ($target === 'otp_services') {
            if (!empty($data['service_id']) && isset($lookup['existing_by_id'][$data['service_id']])) {
                return $lookup['existing_by_id'][$data['service_id']];
            }
            $slugKey = strtolower((string)$data['slug']);
            if (isset($lookup['existing_by_key'][$slugKey])) {
                return $lookup['existing_by_key'][$slugKey];
            }
            if (!empty($data['provider_service_code'])) {
                $pKey = ((int)($data['provider_id'] ?? 0)) . '|' . strtolower((string)$data['provider_service_code']);
                if (isset($lookup['existing_by_key'][$pKey])) {
                    return $lookup['existing_by_key'][$pKey];
                }
            }
            return $lookup['existing_by_name'][strtolower((string)$data['name'])] ?? null;
        }

        // countries
        if (!empty($data['country_id']) && isset($lookup['existing_by_id'][$data['country_id']])) {
            return $lookup['existing_by_id'][$data['country_id']];
        }
        $isoKey = strtoupper((string)$data['iso_code']);
        if (isset($lookup['existing_by_key'][$isoKey])) {
            return $lookup['existing_by_key'][$isoKey];
        }
        return $lookup['existing_by_name'][strtolower((string)$data['name'])] ?? null;
    }

    private static function upsertRecord(string $target, array $data, string $dupMode, array &$lookup): array
    {
        $existingId = self::findExistingRecord($target, $data, $lookup);

        if ($existingId !== null) {
            if ($dupMode === 'skip') {
                return ['action' => 'skipped', 'reason' => 'Duplicate skipped.'];
            }
            if ($dupMode === 'report') {
                return ['action' => 'skipped', 'reason' => 'Duplicate detected (existing record #' . $existingId . ').'];
            }

            // update mode
            if ($target === 'smm_services') {
                $catId = self::ensureSmmCategory($data['category_name'], $lookup);
                Database::execute(
                    "UPDATE services
                     SET category_id = ?, provider_id = COALESCE(?, provider_id), provider_service_id = COALESCE(?, provider_service_id),
                         name = ?, description = ?, type = ?, rate = ?, min_qty = ?, max_qty = ?, status = ?
                     WHERE id = ?",
                    [
                        $catId,
                        $data['provider_id'],
                        $data['provider_service_id'],
                        $data['name'],
                        $data['description'] !== '' ? $data['description'] : null,
                        $data['type'],
                        $data['rate'],
                        $data['min_qty'],
                        $data['max_qty'],
                        $data['status'],
                        $existingId,
                    ]
                );
                if (!empty($data['provider_id']) && !empty($data['provider_service_id'])) {
                    self::syncSmmProviderMapping((int)$data['provider_id'], $existingId, (string)$data['provider_service_id'], $data['category_name'], $data['rate']);
                }
                return ['action' => 'updated', 'id' => $existingId];
            }

            if ($target === 'otp_services') {
                Database::execute(
                    "UPDATE otp_services
                     SET name = ?, icon_key = ?, description = ?, provider_id = COALESCE(?, provider_id),
                         provider_service_code = COALESCE(?, provider_service_code), selling_price = ?, is_popular = ?, status = ?
                     WHERE id = ?",
                    [
                        $data['name'],
                        $data['icon_key'],
                        $data['description'] !== '' ? $data['description'] : null,
                        $data['provider_id'],
                        $data['provider_service_code'],
                        $data['selling_price'],
                        $data['is_popular'],
                        $data['status'],
                        $existingId,
                    ]
                );
                return ['action' => 'updated', 'id' => $existingId];
            }

            // countries update
            Database::execute(
                "UPDATE otp_countries
                 SET name = ?, dial_code = COALESCE(?, dial_code), flag_emoji = COALESCE(?, flag_emoji),
                     default_provider_code = ?, status = ?
                 WHERE id = ?",
                [
                    $data['name'],
                    $data['dial_code'],
                    $data['flag_emoji'],
                    $data['default_provider_code'],
                    $data['status'],
                    $existingId,
                ]
            );
            return ['action' => 'updated', 'id' => $existingId];
        }

        // Insert new record
        if ($target === 'smm_services') {
            $catId = self::ensureSmmCategory($data['category_name'], $lookup);
            $platform = self::detectPlatformKey($data['category_name'] . ' ' . $data['name']);
            $newId = (int)Database::insert(
                "INSERT INTO services
                 (category_id, provider_id, provider_service_id, provider_category_raw, name, description, type, platform, icon_key, rate, min_qty, max_qty, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $catId,
                    $data['provider_id'],
                    $data['provider_service_id'],
                    $data['category_name'],
                    $data['name'],
                    $data['description'] !== '' ? $data['description'] : null,
                    $data['type'],
                    $platform,
                    $platform,
                    $data['rate'],
                    $data['min_qty'],
                    $data['max_qty'],
                    $data['status'],
                ]
            );
            $lookup['existing_by_id'][$newId] = $newId;
            $lookup['existing_by_name'][strtolower((string)$data['name'])] = $newId;
            if (!empty($data['provider_id']) && !empty($data['provider_service_id'])) {
                $lookup['existing_by_key'][(int)$data['provider_id'] . '|' . strtolower((string)$data['provider_service_id'])] = $newId;
                self::syncSmmProviderMapping((int)$data['provider_id'], $newId, (string)$data['provider_service_id'], $data['category_name'], $data['rate']);
            }
            return ['action' => 'imported', 'id' => $newId];
        }

        if ($target === 'otp_services') {
            $slug = $data['slug'];
            if (isset($lookup['existing_by_key'][$slug])) {
                $slug = substr($slug, 0, 88) . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
            }
            $newId = (int)Database::insert(
                "INSERT INTO otp_services
                 (name, slug, icon_key, description, provider_id, provider_service_code, selling_price, is_popular, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $data['name'],
                    $slug,
                    $data['icon_key'],
                    $data['description'] !== '' ? $data['description'] : null,
                    $data['provider_id'],
                    $data['provider_service_code'],
                    $data['selling_price'],
                    $data['is_popular'],
                    $data['status'],
                ]
            );
            $lookup['existing_by_id'][$newId] = $newId;
            $lookup['existing_by_key'][strtolower($slug)] = $newId;
            $lookup['existing_by_name'][strtolower((string)$data['name'])] = $newId;
            return ['action' => 'imported', 'id' => $newId];
        }

        // countries insert
        $newId = (int)Database::insert(
            "INSERT INTO otp_countries (name, iso_code, dial_code, flag_emoji, default_provider_code, status)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $data['name'],
                $data['iso_code'],
                $data['dial_code'],
                $data['flag_emoji'],
                $data['default_provider_code'],
                $data['status'],
            ]
        );
        $lookup['existing_by_id'][$newId] = $newId;
        $lookup['existing_by_key'][strtoupper((string)$data['iso_code'])] = $newId;
        $lookup['existing_by_name'][strtolower((string)$data['name'])] = $newId;
        return ['action' => 'imported', 'id' => $newId];
    }

    private static function ensureSmmCategory(string $categoryName, array &$lookup): int
    {
        $norm = strtolower(trim($categoryName));
        if (isset($lookup['categories_by_name'][$norm])) {
            return $lookup['categories_by_name'][$norm];
        }
        $slug = trim(strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', $categoryName)), '-');
        if ($slug === '') {
            $slug = 'general-services';
        }
        if (isset($lookup['categories_by_slug'][$slug])) {
            $id = $lookup['categories_by_slug'][$slug];
            $lookup['categories_by_name'][$norm] = $id;
            return $id;
        }
        $platform = self::detectPlatformKey($categoryName);
        $catId = (int)Database::insert(
            "INSERT INTO categories (name, slug, platform, icon_svg_key, status) VALUES (?, ?, ?, ?, 'active')",
            [$categoryName, $slug, $platform, $platform]
        );
        $lookup['categories_by_name'][$norm] = $catId;
        $lookup['categories_by_slug'][$slug] = $catId;
        return $catId;
    }

    private static function syncSmmProviderMapping(int $providerId, int $serviceId, string $extId, string $catName, string $rate): void
    {
        Database::execute(
            "INSERT INTO provider_service_mappings (provider_id, service_id, external_service_id, external_category_name, external_rate, last_synced_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE service_id = VALUES(service_id), external_category_name = VALUES(external_category_name), external_rate = VALUES(external_rate), last_synced_at = NOW()",
            [$providerId, $serviceId, $extId, $catName, $rate]
        );
    }

    private static function detectPlatformKey(string $text): string
    {
        $t = strtolower($text);
        $map = [
            'instagram' => 'instagram',
            'youtube'   => 'youtube',
            'tiktok'    => 'tiktok',
            'telegram'  => 'telegram',
            'facebook'  => 'facebook',
            'twitter'   => 'twitter',
            'spotify'   => 'spotify',
            'whatsapp'  => 'whatsapp',
            'discord'   => 'discord',
            'linkedin'  => 'linkedin',
            'snapchat'  => 'snapchat',
        ];
        foreach ($map as $needle => $key) {
            if (str_contains($t, $needle)) {
                return $key;
            }
        }
        return 'general';
    }

    private static function isoToFlag(string $iso2): string
    {
        $iso2 = strtoupper($iso2);
        if (strlen($iso2) !== 2) {
            return '';
        }
        $c1 = 0x1F1E6 + (ord($iso2[0]) - ord('A'));
        $c2 = 0x1F1E6 + (ord($iso2[1]) - ord('A'));
        return mb_chr($c1, 'UTF-8') . mb_chr($c2, 'UTF-8');
    }
}
