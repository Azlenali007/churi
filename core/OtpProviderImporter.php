<?php
/**
 * OTP Provider Automatic Service & Country Import, Price Sync & Availability Sync Engine
 * - Discovers available services & countries from live provider getPrices() responses (never fabricates data)
 * - Database-backed batch import jobs (`otp_import_jobs`) prevent PHP timeouts & browser freezes on large datasets
 * - Preserves real progress across browser reloads and prevents concurrent conflicting jobs
 * - Calculates all selling prices strictly server-side with explicit FX conversion
 * - Verifies actual number stock via getNumbersStatus() with API rate-limit throttling
 */

class OtpProviderImporter
{
    private const MAX_AVAILABILITY_COUNTRIES_PER_RUN = 25;
    private const DEFAULT_BATCH_SIZE = 100;
    private const STALE_JOB_SECONDS = 300; // 5 minutes without progress = stale lock

    /**
     * Fetch live getPrices() once, stage compact raw items in `otp_import_jobs` (status='ready'),
     * and return a fast paginated preview slice so huge 5SIM catalogs never hang PHP or the browser DOM.
     */
    public static function startPreviewAndStage(
        int $providerId,
        ?string $countryCode = null,
        ?string $serviceCode = null,
        array $pricingOpts = [],
        ?int $adminId = null,
        int $page = 1,
        int $perPage = 100
    ): array {
        $provider = Database::fetch("SELECT * FROM otp_providers WHERE id = ? LIMIT 1", [$providerId]);
        if (!$provider) {
            return ['ok' => false, 'error' => 'OTP provider not found.'];
        }

        // Clean up old staged preview jobs (> 2 hours) to keep DB tidy
        self::pruneOldStagedPayloads();

        try {
            $adapter = OtpProviderFactory::make($provider);
            $res = $adapter->getPrices($countryCode, $serviceCode);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Provider adapter error: ' . $e->getMessage()];
        }

        if (empty($res['ok'])) {
            $errMsg = (string)($res['error'] ?? 'Failed to fetch services/prices from provider API.');
            Database::execute(
                "UPDATE otp_providers SET last_error = ? WHERE id = ?",
                [substr($errMsg, 0, 500), $providerId]
            );
            return [
                'ok'               => false,
                'error'            => $errMsg,
                'http_code'        => (int)($res['http_code'] ?? 0),
                'response_time_ms' => (int)($res['response_time_ms'] ?? 0),
            ];
        }

        $rawItems = is_array($res['items'] ?? null) ? $res['items'] : [];
        $countries = is_array($res['countries'] ?? null) ? $res['countries'] : [];

        // Deduplicate & sort countries alphabetically
        usort($countries, static fn($a, $b) => strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));

        // Build compact staged records for fast JSON storage & pagination
        $staged = [];
        $uniqueServices = [];
        $uniqueCountries = [];
        $unsupportedCount = 0;

        foreach ($rawItems as $it) {
            $cCode = trim((string)($it['country_code'] ?? ''));
            $sCode = trim((string)($it['service_code'] ?? ''));
            if ($cCode === '' || $sCode === '') {
                $unsupportedCount++;
                continue;
            }
            $uniqueCountries[strtolower($cCode)] = true;
            $uniqueServices[strtolower($sCode)] = true;
            $staged[] = [
                'cc'   => $cCode,
                'cn'   => (string)($it['country_name'] ?? ucfirst($cCode)),
                'ci'   => (string)($it['country_iso'] ?? strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $cCode), 0, 2) ?: 'XX')),
                'cf'   => (string)($it['country_flag'] ?? ''),
                'cd'   => (string)($it['country_dial'] ?? ''),
                'sc'   => $sCode,
                'sn'   => (string)($it['service_name'] ?? ucfirst($sCode)),
                'ss'   => (string)($it['service_slug'] ?? strtolower(preg_replace('/[^a-z0-9]+/', '_', $sCode))),
                'ik'   => (string)($it['icon_key'] ?? 'general'),
                'pop'  => (int)($it['is_popular'] ?? 0),
                'op'   => (string)($it['operator'] ?? 'any'),
                'cost' => (string)($it['cost'] ?? '0'),
                'cnt'  => $it['count'] ?? null,
            ];
        }

        $existingIndex = self::loadExistingMappingIndex($providerId);
        $newCount = 0;
        $existingCount = 0;
        foreach ($staged as $st) {
            $pairKey = strtolower($st['cc']) . '|' . strtolower($st['sc']);
            if (isset($existingIndex[$pairKey])) {
                $existingCount++;
            } else {
                $newCount++;
            }
        }

        $zeroReason = null;
        if (empty($staged)) {
            $zeroReason = 'The provider getPrices API returned an empty catalog for the selected filters. Verify your provider API key, country/service filter, or provider stock.';
        }

        $payload = [
            'items'     => $staged,
            'countries' => $countries,
        ];
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $jobId = (int)Database::insert(
            "INSERT INTO otp_import_jobs
             (provider_id, admin_id, job_type, mode, status, stage_message, options_json, staged_items_json,
              total_records, processed_records, batch_offset,
              total_countries_found, total_services_found, unsupported_records, zero_reason, started_at)
             VALUES (?, ?, 'services', 'preview', 'ready', ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, NOW())",
            [
                $providerId,
                $adminId,
                empty($staged) ? 'No services returned by provider.' : 'Preview staged (' . count($staged) . ' combinations ready)',
                json_encode([
                    'country_code' => $countryCode,
                    'service_code' => $serviceCode,
                ], JSON_UNESCAPED_UNICODE),
                $encodedPayload,
                count($staged),
                count($uniqueCountries),
                count($uniqueServices),
                $unsupportedCount,
                $zeroReason,
            ]
        );

        Database::execute(
            "UPDATE otp_providers SET connection_status = 'connected', last_error = NULL WHERE id = ?",
            [$providerId]
        );

        $pageResult = self::sliceStagedPreview($provider, $staged, $existingIndex, $pricingOpts, $page, $perPage, '', '', 'all');

        return [
            'ok'                    => true,
            'staged_job_id'         => $jobId,
            'provider_id'           => $providerId,
            'provider_name'         => (string)$provider['name'],
            'provider_currency'     => (string)($provider['currency_code'] ?: Currency::getBaseCode()),
            'base_currency'         => Currency::getBaseCode(),
            'total_found'           => count($staged),
            'total_countries_found' => count($uniqueCountries),
            'total_services_found'  => count($uniqueServices),
            'unsupported_records'   => $unsupportedCount,
            'new_count'             => $newCount,
            'existing_count'        => $existingCount,
            'countries'             => $countries,
            'items'                 => $pageResult['items'],
            'pagination'            => $pageResult['pagination'],
            'zero_reason'           => $zeroReason,
            'http_code'             => (int)($res['http_code'] ?? 200),
            'response_time_ms'      => (int)($res['response_time_ms'] ?? 0),
        ];
    }

    /**
     * Serve a filtered & paginated slice from an already staged preview job without hitting 5SIM again.
     */
    public static function getPreviewPage(
        int $providerId,
        int $stagedJobId,
        int $page = 1,
        int $perPage = 100,
        string $search = '',
        string $countryFilter = '',
        string $statusFilter = 'all',
        array $pricingOpts = []
    ): array {
        $provider = Database::fetch("SELECT * FROM otp_providers WHERE id = ? LIMIT 1", [$providerId]);
        if (!$provider) {
            return ['ok' => false, 'error' => 'OTP provider not found.'];
        }
        $job = Database::fetch(
            "SELECT id, provider_id, staged_items_json, total_countries_found, total_services_found
             FROM otp_import_jobs WHERE id = ? AND provider_id = ? LIMIT 1",
            [$stagedJobId, $providerId]
        );
        if (!$job || empty($job['staged_items_json'])) {
            return ['ok' => false, 'error' => 'Preview session expired. Please click "Fetch Live Services Preview" again.'];
        }
        $decoded = json_decode((string)$job['staged_items_json'], true);
        $staged = is_array($decoded['items'] ?? null) ? $decoded['items'] : [];
        $existingIndex = self::loadExistingMappingIndex($providerId);

        $slice = self::sliceStagedPreview($provider, $staged, $existingIndex, $pricingOpts, $page, $perPage, $search, $countryFilter, $statusFilter);
        return [
            'ok'                    => true,
            'staged_job_id'         => $stagedJobId,
            'provider_id'           => $providerId,
            'provider_currency'     => (string)($provider['currency_code'] ?: Currency::getBaseCode()),
            'base_currency'         => Currency::getBaseCode(),
            'total_found'           => count($staged),
            'total_countries_found' => (int)$job['total_countries_found'],
            'total_services_found'  => (int)$job['total_services_found'],
            'items'                 => $slice['items'],
            'pagination'            => $slice['pagination'],
        ];
    }

    /**
     * Start a persistent database-backed batch import job (`job_type` = 'services' or 'countries').
     * Prevents concurrent conflicting jobs on the same provider and reuses a staged preview payload if available.
     */
    public static function startBatchJob(
        int $providerId,
        ?int $adminId,
        string $jobType,
        string $mode,
        array $selectedKeys = [],
        array $options = [],
        ?int $reuseStagedJobId = null
    ): array {
        $provider = Database::fetch("SELECT * FROM otp_providers WHERE id = ? LIMIT 1", [$providerId]);
        if (!$provider) {
            return ['ok' => false, 'error' => 'OTP provider not found.'];
        }

        $jobType = ($jobType === 'countries') ? 'countries' : 'services';

        // Mark stale running jobs (> 5 min idle) as failed so an abandoned browser tab doesn't block forever
        Database::execute(
            "UPDATE otp_import_jobs
             SET status = 'failed', stage_message = 'Job timed out before completion (stale lock released).',
                 completed_at = NOW()
             WHERE provider_id = ?
               AND status IN ('queued','fetching_countries','fetching_services','processing','importing')
               AND updated_at < DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$providerId, self::STALE_JOB_SECONDS]
        );

        // Guard against concurrent conflicting active import jobs for the same provider
        $active = Database::fetch(
            "SELECT id, job_type, mode, status, processed_records, total_records, stage_message
             FROM otp_import_jobs
             WHERE provider_id = ?
               AND status IN ('queued','fetching_countries','fetching_services','processing','importing')
             ORDER BY id DESC LIMIT 1",
            [$providerId]
        );
        if ($active) {
            return [
                'ok'          => false,
                'conflict'    => true,
                'active_job'  => self::formatJobSummary($active),
                'error'       => 'Another import job (#' . (int)$active['id'] . ' - ' . $active['status'] . ') is currently running for this provider. Wait for it to finish or cancel it first.',
            ];
        }

        $filterCountry = isset($options['country_code']) && trim((string)$options['country_code']) !== ''
            ? trim((string)$options['country_code']) : null;
        $filterService = isset($options['service_code']) && trim((string)$options['service_code']) !== ''
            ? trim((string)$options['service_code']) : null;

        if ($jobType === 'services') {
            if ($mode === 'import_by_country' && $filterCountry === null) {
                return ['ok' => false, 'error' => 'Please select a country for country-wise import.'];
            }
            if ($mode === 'import_selected' && empty($selectedKeys)) {
                return ['ok' => false, 'error' => 'Please select at least one service/country item to import.'];
            }
        }

        $initialStatus = ($jobType === 'countries') ? 'fetching_countries' : 'fetching_services';
        $initialStage = ($jobType === 'countries') ? 'Fetching Countries' : 'Fetching Services';

        $normalizedOptions = [
            'country_code'            => $filterCountry,
            'service_code'            => $filterService,
            'pricing'                 => self::normalizePricingOptions($options),
            'import_status'           => (($options['import_status'] ?? 'active') === 'inactive') ? 'inactive' : 'active',
            'link_services'           => !empty($options['link_services']),
            'selected_keys'           => array_values(array_unique(array_map(static fn($k) => strtolower(trim((string)$k)), $selectedKeys))),
            'batch_size'              => max(25, min(250, (int)($options['batch_size'] ?? self::DEFAULT_BATCH_SIZE))),
        ];

        $jobId = (int)Database::insert(
            "INSERT INTO otp_import_jobs
             (provider_id, admin_id, job_type, mode, status, stage_message, options_json, started_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $providerId,
                $adminId,
                $jobType,
                $mode,
                $initialStatus,
                $initialStage,
                json_encode($normalizedOptions, JSON_UNESCAPED_UNICODE),
            ]
        );

        // Step 1: Obtain dataset — reuse valid staged preview payload if it matches filters, otherwise call live 5SIM API
        $stagedItems = null;
        $stagedCountries = null;
        $unsupportedCount = 0;

        if ($jobType === 'services' && $reuseStagedJobId !== null && $reuseStagedJobId > 0) {
            $previewJob = Database::fetch(
                "SELECT id, options_json, staged_items_json, unsupported_records
                 FROM otp_import_jobs
                 WHERE id = ? AND provider_id = ? AND status = 'ready' AND staged_items_json IS NOT NULL
                 LIMIT 1",
                [$reuseStagedJobId, $providerId]
            );
            if ($previewJob) {
                $pOpts = json_decode((string)$previewJob['options_json'], true) ?: [];
                $prevCountry = $pOpts['country_code'] ?? null;
                $prevService = $pOpts['service_code'] ?? null;
                // Reuse when preview already covers the requested scope (e.g. unfiltered preview covers any country filter)
                $countryCovers = ($prevCountry === null || ($filterCountry !== null && strcasecmp((string)$prevCountry, $filterCountry) === 0));
                $serviceCovers = ($prevService === null || ($filterService !== null && strcasecmp((string)$prevService, $filterService) === 0));
                if ($countryCovers && $serviceCovers) {
                    $decoded = json_decode((string)$previewJob['staged_items_json'], true);
                    if (is_array($decoded['items'] ?? null)) {
                        $stagedItems = $decoded['items'];
                        $stagedCountries = is_array($decoded['countries'] ?? null) ? $decoded['countries'] : [];
                        $unsupportedCount = (int)$previewJob['unsupported_records'];
                    }
                }
            }
        }

        if ($stagedItems === null) {
            try {
                $adapter = OtpProviderFactory::make($provider);
            } catch (Throwable $e) {
                $err = 'Provider adapter error: ' . ProviderErrorHandler::redact($e->getMessage());
                ProviderErrorHandler::recordProviderError('otp', $providerId, $err, false);
                self::failJob($jobId, $err, [$err]);
                return ['ok' => false, 'job_id' => $jobId, 'error' => $err, 'job' => self::getJobStatus($jobId)];
            }

            if ($jobType === 'countries') {
                try {
                    $cRes = $adapter->getCountries();
                } catch (Throwable $e) {
                    $err = 'Provider country fetch error: ' . ProviderErrorHandler::redact($e->getMessage());
                    ProviderErrorHandler::recordProviderError('otp', $providerId, $err, false);
                    self::failJob($jobId, $err, [$err]);
                    return ['ok' => false, 'job_id' => $jobId, 'error' => $err, 'job' => self::getJobStatus($jobId)];
                }

                if (empty($cRes['ok'])) {
                    $err = ProviderErrorHandler::redact((string)($cRes['error'] ?? 'Failed to fetch countries from provider API.'));
                    ProviderErrorHandler::recordProviderError('otp', $providerId, $err, false);
                    self::failJob($jobId, $err, [$err]);
                    return ['ok' => false, 'job_id' => $jobId, 'error' => $err, 'job' => self::getJobStatus($jobId)];
                }

                $stagedCountries = is_array($cRes['countries'] ?? null) ? $cRes['countries'] : [];
                $rawItems = is_array($cRes['items'] ?? null) ? $cRes['items'] : [];

                // If admin opted to also link countries to imported services and getCountries() didn't return items, fetch pricing safely
                if (!empty($normalizedOptions['link_services']) && empty($rawItems)) {
                    try {
                        $pRes = $adapter->getPrices($filterCountry, $filterService);
                        if (!empty($pRes['ok']) && is_array($pRes['items'] ?? null)) {
                            $rawItems = $pRes['items'];
                        }
                    } catch (Throwable $ignored) {
                        // Country import continues even if provider does not expose a separate pricing endpoint
                    }
                }
            } else {
                try {
                    $res = $adapter->getPrices($filterCountry, $filterService);
                } catch (Throwable $e) {
                    $err = 'Provider adapter error: ' . ProviderErrorHandler::redact($e->getMessage());
                    ProviderErrorHandler::recordProviderError('otp', $providerId, $err, false);
                    self::failJob($jobId, $err, [$err]);
                    return ['ok' => false, 'job_id' => $jobId, 'error' => $err, 'job' => self::getJobStatus($jobId)];
                }

                if (empty($res['ok'])) {
                    $err = ProviderErrorHandler::redact((string)($res['error'] ?? 'Failed to fetch data from provider API.'));
                    ProviderErrorHandler::recordProviderError('otp', $providerId, $err, false);
                    self::failJob($jobId, $err, [$err]);
                    return ['ok' => false, 'job_id' => $jobId, 'error' => $err, 'job' => self::getJobStatus($jobId)];
                }

                $rawItems = is_array($res['items'] ?? null) ? $res['items'] : [];
                $stagedCountries = is_array($res['countries'] ?? null) ? $res['countries'] : [];
            }

            $stagedItems = [];
            foreach ($rawItems as $it) {
                $cCode = trim((string)($it['country_code'] ?? ''));
                $sCode = trim((string)($it['service_code'] ?? ''));
                if ($cCode === '' || $sCode === '') {
                    $unsupportedCount++;
                    continue;
                }
                $stagedItems[] = [
                    'cc'   => $cCode,
                    'cn'   => (string)($it['country_name'] ?? ucfirst($cCode)),
                    'ci'   => (string)($it['country_iso'] ?? strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $cCode), 0, 2) ?: 'XX')),
                    'cf'   => (string)($it['country_flag'] ?? ''),
                    'cd'   => (string)($it['country_dial'] ?? ''),
                    'sc'   => $sCode,
                    'sn'   => (string)($it['service_name'] ?? ucfirst($sCode)),
                    'ss'   => (string)($it['service_slug'] ?? strtolower(preg_replace('/[^a-z0-9]+/', '_', $sCode))),
                    'ik'   => (string)($it['icon_key'] ?? 'general'),
                    'pop'  => (int)($it['is_popular'] ?? 0),
                    'op'   => (string)($it['operator'] ?? 'any'),
                    'cost' => (string)($it['cost'] ?? '0'),
                    'cnt'  => $it['count'] ?? null,
                ];
            }
        }

        // Compute unique countries & services discovered from the live provider payload
        $uniqueCountriesMap = [];
        $uniqueServicesMap = [];
        foreach ($stagedItems as $it) {
            $cKey = strtolower(trim((string)$it['cc']));
            if ($cKey !== '' && !isset($uniqueCountriesMap[$cKey])) {
                $uniqueCountriesMap[$cKey] = [
                    'code'      => (string)$it['cc'],
                    'name'      => (string)$it['cn'],
                    'iso'       => (string)$it['ci'],
                    'flag'      => (string)$it['cf'],
                    'dial_code' => (string)$it['cd'],
                ];
            }
            $sKey = strtolower(trim((string)$it['sc']));
            if ($sKey !== '') {
                $uniqueServicesMap[$sKey] = true;
            }
        }

        // Also include any country entries explicitly returned in $stagedCountries if not already captured
        foreach ((array)$stagedCountries as $ct) {
            $cCode = trim((string)($ct['provider_code'] ?? $ct['code'] ?? ''));
            if ($cCode === '') {
                continue;
            }
            $cKey = strtolower($cCode);
            if (!isset($uniqueCountriesMap[$cKey])) {
                $uniqueCountriesMap[$cKey] = [
                    'code'      => $cCode,
                    'name'      => (string)($ct['name'] ?? ucfirst($cCode)),
                    'iso'       => (string)($ct['iso_code'] ?? $ct['iso'] ?? strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $cCode), 0, 2) ?: 'XX')),
                    'flag'      => (string)($ct['flag_emoji'] ?? $ct['flag'] ?? ''),
                    'dial_code' => (string)($ct['dial_code'] ?? ''),
                ];
            }
        }

        $totalCountriesFound = count($uniqueCountriesMap);
        $totalServicesFound = count($uniqueServicesMap);

        // Prepare the exact records to process in batches
        $skippedPreFilter = 0;
        if ($jobType === 'countries') {
            $recordsToProcess = array_values($uniqueCountriesMap);
            // If link_services is enabled, also keep compact service-country links
            $payloadToStage = [
                'countries'     => $recordsToProcess,
                'service_links' => !empty($normalizedOptions['link_services']) ? $stagedItems : [],
            ];
            $totalToProcess = count($recordsToProcess);
        } else {
            // Apply mode pre-filtering for selected/country so progress bar reflects actual work accurately
            $selectedLookup = array_fill_keys($normalizedOptions['selected_keys'], true);
            $filtered = [];
            foreach ($stagedItems as $it) {
                $pairKey = strtolower($it['cc'] . '|' . $it['sc']);
                if ($mode === 'import_selected' && empty($selectedLookup[$pairKey])) {
                    $skippedPreFilter++;
                    continue;
                }
                if ($mode === 'import_by_country' && $filterCountry !== null && strcasecmp($it['cc'], $filterCountry) !== 0) {
                    $skippedPreFilter++;
                    continue;
                }
                $filtered[] = $it;
            }
            $payloadToStage = ['items' => $filtered];
            $totalToProcess = count($filtered);
        }

        // If 0 records found, finish cleanly with explicit zero_reason (never silently claim success with empty data)
        if ($totalToProcess === 0) {
            $zeroReason = $jobType === 'countries'
                ? 'Provider API returned 0 supported countries. Verify your provider configuration, API key, or country endpoint.'
                : ($skippedPreFilter > 0
                    ? 'None of the fetched provider records matched the selected filter or selection criteria.'
                    : 'Provider API returned 0 service/country pricing records.');
            Database::execute(
                "UPDATE otp_import_jobs
                 SET status = 'completed', stage_message = 'Completed',
                     total_records = 0, processed_records = 0,
                     total_countries_found = ?, total_services_found = ?,
                     unsupported_records = ?, skipped_records = ?,
                     zero_reason = ?, completed_at = NOW()
                 WHERE id = ?",
                [$totalCountriesFound, $totalServicesFound, $unsupportedCount, $skippedPreFilter, $zeroReason, $jobId]
            );
            return [
                'ok'     => true,
                'job_id' => $jobId,
                'done'   => true,
                'job'    => self::getJobStatus($jobId),
            ];
        }

        Database::execute(
            "UPDATE otp_import_jobs
             SET status = 'processing',
                 stage_message = 'Processing Records',
                 staged_items_json = ?,
                 total_records = ?,
                 processed_records = 0,
                 batch_offset = 0,
                 total_countries_found = ?,
                 total_services_found = ?,
                 unsupported_records = ?,
                 skipped_records = ?
             WHERE id = ?",
            [
                json_encode($payloadToStage, JSON_UNESCAPED_UNICODE),
                $totalToProcess,
                $totalCountriesFound,
                $totalServicesFound,
                $unsupportedCount,
                $skippedPreFilter,
                $jobId,
            ]
        );

        return [
            'ok'     => true,
            'job_id' => $jobId,
            'done'   => false,
            'job'    => self::getJobStatus($jobId),
        ];
    }

    /**
     * Process the next batch of records for a running `otp_import_jobs` row.
     * Safe for repeated AJAX calls; updates real counters atomically and frees `staged_items_json` on completion.
     */
    public static function processNextBatch(int $jobId, ?int $batchSizeOverride = null): array
    {
        $job = Database::fetch("SELECT * FROM otp_import_jobs WHERE id = ? LIMIT 1", [$jobId]);
        if (!$job) {
            return ['ok' => false, 'error' => 'Import job not found.'];
        }

        if (in_array($job['status'], ['completed', 'failed', 'cancelled'], true)) {
            return ['ok' => true, 'done' => true, 'job' => self::formatJobSummary($job)];
        }

        $provider = Database::fetch("SELECT * FROM otp_providers WHERE id = ? LIMIT 1", [(int)$job['provider_id']]);
        if (!$provider) {
            self::failJob($jobId, 'OTP provider no longer exists.', ['OTP provider not found.']);
            return ['ok' => false, 'error' => 'OTP provider not found.', 'job' => self::getJobStatus($jobId)];
        }

        $options = json_decode((string)$job['options_json'], true) ?: [];
        $staged = json_decode((string)$job['staged_items_json'], true) ?: [];
        $batchSize = max(20, min(250, (int)($batchSizeOverride ?: ($options['batch_size'] ?? self::DEFAULT_BATCH_SIZE))));
        $offset = (int)$job['batch_offset'];

        $errors = json_decode((string)($job['errors_json'] ?: '[]'), true);
        if (!is_array($errors)) {
            $errors = [];
        }

        if ($job['job_type'] === 'countries') {
            return self::processCountryBatch($job, $provider, $options, $staged, $offset, $batchSize, $errors);
        }

        return self::processServiceBatch($job, $provider, $options, $staged, $offset, $batchSize, $errors);
    }

    /**
     * Process a batch of countries (and optionally link them to existing imported services).
     */
    private static function processCountryBatch(
        array $job,
        array $provider,
        array $options,
        array $staged,
        int $offset,
        int $batchSize,
        array $errors
    ): array {
        $jobId = (int)$job['id'];
        $providerId = (int)$provider['id'];
        $countries = is_array($staged['countries'] ?? null) ? $staged['countries'] : [];
        $serviceLinks = is_array($staged['service_links'] ?? null) ? $staged['service_links'] : [];
        $total = count($countries);
        $slice = array_slice($countries, $offset, $batchSize);

        if (empty($slice)) {
            return self::finalizeJob($jobId, $providerId, 'countries');
        }

        Database::execute(
            "UPDATE otp_import_jobs SET status = 'importing', stage_message = 'Importing Records' WHERE id = ?",
            [$jobId]
        );

        // Pre-load existing countries
        $byCode = [];
        $byIso = [];
        foreach (Database::fetchAll("SELECT * FROM otp_countries") as $cRow) {
            $codeKey = strtolower(trim((string)$cRow['default_provider_code']));
            if ($codeKey !== '') {
                $byCode[$codeKey] = $cRow;
            }
            $isoKey = strtoupper(trim((string)$cRow['iso_code']));
            if ($isoKey !== '') {
                $byIso[$isoKey] = $cRow;
            }
        }

        // If linking services, load existing local services and mappings for this provider
        $linkServices = !empty($options['link_services']) && !empty($serviceLinks);
        $servicesByProvCode = [];
        $existingMappings = [];
        $linksByCountry = [];
        if ($linkServices) {
            foreach (Database::fetchAll("SELECT * FROM otp_services WHERE provider_id = ?", [$providerId]) as $sRow) {
                $sc = strtolower(trim((string)$sRow['provider_service_code']));
                if ($sc !== '') {
                    $servicesByProvCode[$sc] = $sRow;
                }
            }
            foreach (Database::fetchAll("SELECT id, service_id, country_id, provider_price, custom_selling_price, pricing_mode, auto_price_update FROM otp_service_provider_mappings WHERE provider_id = ?", [$providerId]) as $mRow) {
                $existingMappings[(int)$mRow['service_id'] . ':' . (int)$mRow['country_id']] = $mRow;
            }
            foreach ($serviceLinks as $sl) {
                $ck = strtolower(trim((string)($sl['cc'] ?? '')));
                if ($ck !== '') {
                    $linksByCountry[$ck][] = $sl;
                }
            }
        }

        $pricing = self::normalizePricingOptions($options['pricing'] ?? []);
        $newCountries = 0;
        $updatedCountries = 0;
        $newServicesLinked = 0;
        $updatedServicesLinked = 0;
        $pricesUpdated = 0;
        $unsupported = 0;
        $skipped = 0;
        $failed = 0;
        $hadUpdates = false;

        Database::beginTransaction();
        try {
            foreach ($slice as $ct) {
                $code = trim((string)($ct['code'] ?? $ct['provider_code'] ?? ''));
                $name = trim((string)($ct['name'] ?? ''));
                $iso = strtoupper(trim((string)($ct['iso'] ?? '')));
                $flag = trim((string)($ct['flag'] ?? ''));
                $dial = trim((string)($ct['dial_code'] ?? ''));

                if ($code === '') {
                    $unsupported++;
                    continue;
                }
                if ($name === '') {
                    $name = ucwords(str_replace(['_', '-'], ' ', $code));
                }
                if ($iso === '') {
                    $iso = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $code), 0, 2) ?: 'XX');
                }

                try {
                    $codeLow = strtolower($code);
                    $existing = $byCode[$codeLow] ?? ($byIso[$iso] ?? null);

                    if ($existing === null) {
                        $uniqueIso = self::ensureUniqueIso($iso, $byIso);
                        $newId = (int)Database::insert(
                            "INSERT INTO otp_countries (name, iso_code, dial_code, flag_emoji, default_provider_code, status)
                             VALUES (?, ?, ?, ?, ?, 'active')",
                            [
                                substr($name, 0, 120),
                                $uniqueIso,
                                $dial !== '' ? substr($dial, 0, 15) : null,
                                $flag !== '' ? substr($flag, 0, 16) : null,
                                substr($code, 0, 80),
                            ]
                        );
                        $countryRow = [
                            'id'                    => $newId,
                            'name'                  => $name,
                            'iso_code'              => $uniqueIso,
                            'default_provider_code' => $code,
                        ];
                        $byCode[$codeLow] = $countryRow;
                        $byIso[$uniqueIso] = $countryRow;
                        $newCountries++;
                    } else {
                        // Update missing or improved metadata (name/flag/dial_code/default_provider_code) without creating duplicates
                        $countryRow = $existing;
                        $nextName = (string)($existing['name'] ?: $name);
                        $nextFlag = $flag !== '' ? $flag : ($existing['flag_emoji'] ?: null);
                        $nextDial = $dial !== '' ? $dial : ($existing['dial_code'] ?: null);
                        $nextCode = trim((string)($existing['default_provider_code'] ?: $code));
                        Database::execute(
                            "UPDATE otp_countries
                             SET name = ?, dial_code = COALESCE(?, dial_code), flag_emoji = COALESCE(?, flag_emoji),
                                 default_provider_code = ?
                             WHERE id = ?",
                            [
                                substr($nextName, 0, 120),
                                $nextDial !== null ? substr((string)$nextDial, 0, 15) : null,
                                $nextFlag !== null ? substr((string)$nextFlag, 0, 16) : null,
                                substr($nextCode, 0, 80),
                                (int)$existing['id'],
                            ]
                        );
                        $updatedCountries++;
                        $hadUpdates = true;
                    }

                    // Optionally link this country to already-imported services for this provider
                    if ($linkServices && !empty($linksByCountry[$codeLow])) {
                        $cntryId = (int)$countryRow['id'];
                        foreach ($linksByCountry[$codeLow] as $sl) {
                            $sCodeLow = strtolower(trim((string)($sl['sc'] ?? '')));
                            if ($sCodeLow === '' || !isset($servicesByProvCode[$sCodeLow])) {
                                continue;
                            }
                            $svcRow = $servicesByProvCode[$sCodeLow];
                            $svcId = (int)$svcRow['id'];
                            $mapKey = $svcId . ':' . $cntryId;
                            $cost = (string)($sl['cost'] ?? '0');
                            $calc = self::computeServerPrice($provider, $cost, $pricing);
                            $op = substr((string)($sl['op'] ?: 'any'), 0, 80);

                            if (!isset($existingMappings[$mapKey])) {
                                $newMapId = (int)Database::insert(
                                    "INSERT INTO otp_service_provider_mappings
                                     (service_id, country_id, provider_id, provider_service_code, provider_country_code,
                                      provider_operator, provider_price, provider_cost_base, custom_selling_price,
                                      pricing_mode, profit_margin, fixed_profit, auto_price_update,
                                      is_available, available_count, availability_verified, last_price_sync_at, status)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'auto', ?, ?, 1, 1, 0, 0, NOW(), 'active')",
                                    [
                                        $svcId,
                                        $cntryId,
                                        $providerId,
                                        substr((string)$sl['sc'], 0, 100),
                                        substr($code, 0, 100),
                                        $op,
                                        $cost,
                                        $calc['cost_base'],
                                        $calc['selling_price'],
                                        $svcRow['profit_margin'] ?? $pricing['profit_margin'],
                                        $svcRow['fixed_profit'] ?? $pricing['fixed_profit'],
                                    ]
                                );
                                $existingMappings[$mapKey] = ['id' => $newMapId];
                                $newServicesLinked++;
                                $pricesUpdated++;
                            } else {
                                $exM = $existingMappings[$mapKey];
                                Database::execute(
                                    "UPDATE otp_service_provider_mappings
                                     SET provider_price = ?, provider_cost_base = ?, last_price_sync_at = NOW()
                                     WHERE id = ?",
                                    [$cost, $calc['cost_base'], (int)$exM['id']]
                                );
                                $updatedServicesLinked++;
                            }
                        }
                    }
                } catch (Throwable $rowErr) {
                    $failed++;
                    if (count($errors) < 50) {
                        $errors[] = "Country {$code}: " . $rowErr->getMessage();
                    }
                }
            }
            Database::commit();
        } catch (Throwable $txErr) {
            Database::rollBack();
            $msg = 'Database batch error while importing countries: ' . $txErr->getMessage();
            $errors[] = $msg;
            self::failJob($jobId, $msg, $errors);
            return ['ok' => false, 'error' => $msg, 'job' => self::getJobStatus($jobId)];
        }

        $nextOffset = $offset + count($slice);
        $nextStage = $hadUpdates && $newCountries === 0 ? 'Updating Existing Records' : 'Importing Records';

        Database::execute(
            "UPDATE otp_import_jobs
             SET processed_records = ?,
                 batch_offset = ?,
                 new_countries = new_countries + ?,
                 updated_countries = updated_countries + ?,
                 new_services = new_services + ?,
                 updated_services = updated_services + ?,
                 prices_updated = prices_updated + ?,
                 unsupported_records = unsupported_records + ?,
                 skipped_records = skipped_records + ?,
                 failed_records = failed_records + ?,
                 errors_json = ?,
                 stage_message = ?
             WHERE id = ?",
            [
                min($total, $nextOffset),
                $nextOffset,
                $newCountries,
                $updatedCountries,
                $newServicesLinked,
                $updatedServicesLinked,
                $pricesUpdated,
                $unsupported,
                $skipped,
                $failed,
                json_encode($errors, JSON_UNESCAPED_UNICODE),
                $nextStage,
                $jobId,
            ]
        );

        if ($nextOffset >= $total) {
            return self::finalizeJob($jobId, $providerId, 'countries');
        }

        return ['ok' => true, 'done' => false, 'job' => self::getJobStatus($jobId)];
    }

    /**
     * Process a batch of service×country pricing items.
     */
    private static function processServiceBatch(
        array $job,
        array $provider,
        array $options,
        array $staged,
        int $offset,
        int $batchSize,
        array $errors
    ): array {
        $jobId = (int)$job['id'];
        $providerId = (int)$provider['id'];
        $mode = (string)$job['mode'];
        $items = is_array($staged['items'] ?? null) ? $staged['items'] : [];
        $total = count($items);
        $slice = array_slice($items, $offset, $batchSize);

        if (empty($slice)) {
            return self::finalizeJob($jobId, $providerId, 'services');
        }

        Database::execute(
            "UPDATE otp_import_jobs SET status = 'importing', stage_message = 'Importing Records' WHERE id = ?",
            [$jobId]
        );

        $pricing = self::normalizePricingOptions($options['pricing'] ?? []);
        $importStatus = (($options['import_status'] ?? 'active') === 'inactive') ? 'inactive' : 'active';
        $provCurr = (string)($provider['currency_code'] ?: Currency::getBaseCode());

        // Load caches
        $countryCacheByCode = [];
        $countryCacheByIso = [];
        foreach (Database::fetchAll("SELECT * FROM otp_countries") as $cRow) {
            $ck = strtolower(trim((string)$cRow['default_provider_code']));
            if ($ck !== '') $countryCacheByCode[$ck] = $cRow;
            $ik = strtoupper(trim((string)$cRow['iso_code']));
            if ($ik !== '') $countryCacheByIso[$ik] = $cRow;
        }

        $serviceCacheByProvCode = [];
        $serviceCacheBySlug = [];
        foreach (Database::fetchAll("SELECT * FROM otp_services") as $sRow) {
            if ((int)$sRow['provider_id'] === $providerId && !empty($sRow['provider_service_code'])) {
                $serviceCacheByProvCode[strtolower(trim((string)$sRow['provider_service_code']))] = $sRow;
            }
            $serviceCacheBySlug[strtolower(trim((string)$sRow['slug']))] = $sRow;
        }

        $mappingCache = [];
        foreach (Database::fetchAll("SELECT * FROM otp_service_provider_mappings WHERE provider_id = ?", [$providerId]) as $mRow) {
            $mappingCache[(int)$mRow['service_id'] . ':' . (int)$mRow['country_id']] = $mRow;
        }

        $newCountries = 0;
        $updatedCountries = 0;
        $newServices = 0;
        $updatedServices = 0;
        $pricesUpdated = 0;
        $unsupported = 0;
        $skipped = 0;
        $failed = 0;
        $touchedCountryIds = [];
        $serviceLowestCost = [];

        Database::beginTransaction();
        try {
            foreach ($slice as $it) {
                $cCode = trim((string)($it['cc'] ?? ''));
                $sCode = trim((string)($it['sc'] ?? ''));
                if ($cCode === '' || $sCode === '') {
                    $unsupported++;
                    continue;
                }

                try {
                    $cCodeLow = strtolower($cCode);
                    $cIsoUp = strtoupper(trim((string)($it['ci'] ?? '')));
                    $countryRow = $countryCacheByCode[$cCodeLow] ?? ($countryCacheByIso[$cIsoUp] ?? null);

                    $sCodeLow = strtolower($sCode);
                    $slugLow = strtolower(trim((string)($it['ss'] ?? $sCodeLow)));
                    $serviceRow = $serviceCacheByProvCode[$sCodeLow] ?? ($serviceCacheBySlug[$slugLow] ?? null);

                    $existingMap = ($serviceRow && $countryRow)
                        ? ($mappingCache[(int)$serviceRow['id'] . ':' . (int)$countryRow['id']] ?? null)
                        : null;

                    if ($mode === 'update_existing' && $existingMap === null) {
                        $skipped++;
                        continue;
                    }
                    if ($mode === 'import_missing_only' && $existingMap !== null) {
                        $skipped++;
                        continue;
                    }

                    // 1. Resolve or create country
                    if ($countryRow === null) {
                        $uniqueIso = self::ensureUniqueIso($cIsoUp, $countryCacheByIso);
                        $newCountryId = (int)Database::insert(
                            "INSERT INTO otp_countries (name, iso_code, dial_code, flag_emoji, default_provider_code, status)
                             VALUES (?, ?, ?, ?, ?, 'active')",
                            [
                                substr((string)($it['cn'] ?? ucfirst($cCode)), 0, 120),
                                $uniqueIso,
                                substr((string)($it['cd'] ?? ''), 0, 15) ?: null,
                                substr((string)($it['cf'] ?? ''), 0, 16) ?: null,
                                substr($cCode, 0, 80),
                            ]
                        );
                        $countryRow = [
                            'id'                    => $newCountryId,
                            'name'                  => (string)($it['cn'] ?? ucfirst($cCode)),
                            'iso_code'              => $uniqueIso,
                            'default_provider_code' => $cCode,
                        ];
                        $countryCacheByCode[$cCodeLow] = $countryRow;
                        $countryCacheByIso[$uniqueIso] = $countryRow;
                        $newCountries++;
                        $touchedCountryIds[$newCountryId] = true;
                    } elseif (empty($touchedCountryIds[(int)$countryRow['id']])) {
                        // Backfill flag/dial if previously empty
                        $needsBackfill = (empty($countryRow['flag_emoji']) && !empty($it['cf']))
                            || (empty($countryRow['dial_code']) && !empty($it['cd']));
                        if ($needsBackfill) {
                            Database::execute(
                                "UPDATE otp_countries
                                 SET flag_emoji = COALESCE(NULLIF(flag_emoji, ''), ?),
                                     dial_code = COALESCE(NULLIF(dial_code, ''), ?)
                                 WHERE id = ?",
                                [
                                    substr((string)($it['cf'] ?? ''), 0, 16) ?: null,
                                    substr((string)($it['cd'] ?? ''), 0, 15) ?: null,
                                    (int)$countryRow['id'],
                                ]
                            );
                            $updatedCountries++;
                        }
                        $touchedCountryIds[(int)$countryRow['id']] = true;
                    }

                    $cost = (string)($it['cost'] ?? '0');
                    $calc = self::computeServerPrice($provider, $cost, $pricing);

                    // 2. Resolve or create service
                    if ($serviceRow === null) {
                        $uniqueSlug = self::ensureUniqueSlug($slugLow, $serviceCacheBySlug);
                        $svcName = (string)($it['sn'] ?? ucfirst($sCode));
                        $newSvcId = (int)Database::insert(
                            "INSERT INTO otp_services
                             (name, slug, icon_key, description, provider_id, provider_service_code,
                              provider_price, provider_currency, selling_price, profit_margin, fixed_profit,
                              min_price, max_price, auto_price_update, last_price_sync_at, is_popular, status)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)",
                            [
                                substr($svcName, 0, 120),
                                $uniqueSlug,
                                substr((string)($it['ik'] ?? 'general'), 0, 60),
                                'One-time SMS verification for ' . $svcName,
                                $providerId,
                                substr($sCode, 0, 100),
                                $cost,
                                $provCurr,
                                $calc['selling_price'],
                                $pricing['profit_margin'],
                                $pricing['fixed_profit'],
                                $pricing['min_price'],
                                $pricing['max_price'],
                                $pricing['auto_price_update'],
                                (int)($it['pop'] ?? 0),
                                $importStatus,
                            ]
                        );
                        $serviceRow = [
                            'id'                    => $newSvcId,
                            'name'                  => $svcName,
                            'slug'                  => $uniqueSlug,
                            'provider_id'           => $providerId,
                            'provider_service_code' => $sCode,
                            'selling_price'         => $calc['selling_price'],
                            'profit_margin'         => $pricing['profit_margin'],
                            'fixed_profit'          => $pricing['fixed_profit'],
                            'min_price'             => $pricing['min_price'],
                            'max_price'             => $pricing['max_price'],
                            'auto_price_update'     => $pricing['auto_price_update'],
                        ];
                        $serviceCacheByProvCode[$sCodeLow] = $serviceRow;
                        $serviceCacheBySlug[$uniqueSlug] = $serviceRow;
                    } elseif (empty($serviceRow['provider_id'])) {
                        Database::execute(
                            "UPDATE otp_services SET provider_id = ?, provider_service_code = COALESCE(NULLIF(provider_service_code,''), ?) WHERE id = ?",
                            [$providerId, $sCode, (int)$serviceRow['id']]
                        );
                        $serviceRow['provider_id'] = $providerId;
                    }

                    $svcId = (int)$serviceRow['id'];
                    $cntryId = (int)$countryRow['id'];
                    $mapKey = $svcId . ':' . $cntryId;

                    if (!isset($serviceLowestCost[$svcId]) || Currency::cmp($cost, $serviceLowestCost[$svcId]['cost'], 4) < 0) {
                        $serviceLowestCost[$svcId] = [
                            'cost'          => $cost,
                            'selling_price' => $calc['selling_price'],
                        ];
                    }

                    $existingMap = $mappingCache[$mapKey] ?? null;
                    $operator = substr((string)($it['op'] ?: 'any'), 0, 80);

                    if ($existingMap === null) {
                        $newMapId = (int)Database::insert(
                            "INSERT INTO otp_service_provider_mappings
                             (service_id, country_id, provider_id, provider_service_code, provider_country_code,
                              provider_operator, provider_price, provider_cost_base, custom_selling_price,
                              pricing_mode, profit_margin, fixed_profit, min_price, max_price, auto_price_update,
                              is_available, available_count, availability_verified, last_price_sync_at, status)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, 0, NOW(), ?)",
                            [
                                $svcId,
                                $cntryId,
                                $providerId,
                                substr($sCode, 0, 100),
                                substr($cCode, 0, 100),
                                $operator,
                                $cost,
                                $calc['cost_base'],
                                $calc['selling_price'],
                                $pricing['pricing_mode'],
                                $pricing['profit_margin'],
                                $pricing['fixed_profit'],
                                $pricing['min_price'],
                                $pricing['max_price'],
                                $pricing['auto_price_update'],
                                $importStatus,
                            ]
                        );
                        $mappingCache[$mapKey] = [
                            'id'                   => $newMapId,
                            'provider_price'       => $cost,
                            'custom_selling_price' => $calc['selling_price'],
                        ];
                        $newServices++;
                        $pricesUpdated++;
                    } else {
                        $mapMode = (string)($existingMap['pricing_mode'] ?? 'auto');
                        $mapAuto = isset($existingMap['auto_price_update']) ? ((int)$existingMap['auto_price_update'] === 1) : true;
                        $shouldUpdateSelling = ($pricing['override_existing_rules'] || ($mapMode === 'auto' && $mapAuto));

                        $newSelling = $existingMap['custom_selling_price'];
                        if ($shouldUpdateSelling) {
                            $effPricing = $pricing['override_existing_rules'] ? $pricing : [
                                'pricing_mode'        => $mapMode,
                                'fixed_selling_price' => $existingMap['custom_selling_price'],
                                'profit_margin'       => $existingMap['profit_margin'] ?? $serviceRow['profit_margin'] ?? $pricing['profit_margin'],
                                'fixed_profit'        => $existingMap['fixed_profit'] ?? $serviceRow['fixed_profit'] ?? $pricing['fixed_profit'],
                                'min_price'           => $existingMap['min_price'] ?? $serviceRow['min_price'] ?? $pricing['min_price'],
                                'max_price'           => $existingMap['max_price'] ?? $serviceRow['max_price'] ?? $pricing['max_price'],
                            ];
                            $recalc = self::computeServerPrice($provider, $cost, $effPricing);
                            $newSelling = $recalc['selling_price'];
                        }

                        $priceChanged = (
                            $existingMap['provider_price'] === null
                            || Currency::cmp((string)$existingMap['provider_price'], $cost, 4) !== 0
                            || ($newSelling !== null && ($existingMap['custom_selling_price'] === null || Currency::cmp((string)$existingMap['custom_selling_price'], (string)$newSelling, 4) !== 0))
                        );

                        if ($pricing['override_existing_rules']) {
                            Database::execute(
                                "UPDATE otp_service_provider_mappings
                                 SET provider_service_code = ?, provider_country_code = ?, provider_operator = ?,
                                     provider_price = ?, provider_cost_base = ?, custom_selling_price = ?,
                                     pricing_mode = ?, profit_margin = ?, fixed_profit = ?, min_price = ?, max_price = ?,
                                     auto_price_update = ?, last_price_sync_at = NOW()
                                 WHERE id = ?",
                                [
                                    substr($sCode, 0, 100),
                                    substr($cCode, 0, 100),
                                    $operator,
                                    $cost,
                                    $calc['cost_base'],
                                    $newSelling,
                                    $pricing['pricing_mode'],
                                    $pricing['profit_margin'],
                                    $pricing['fixed_profit'],
                                    $pricing['min_price'],
                                    $pricing['max_price'],
                                    $pricing['auto_price_update'],
                                    (int)$existingMap['id'],
                                ]
                            );
                        } else {
                            Database::execute(
                                "UPDATE otp_service_provider_mappings
                                 SET provider_service_code = ?, provider_country_code = ?,
                                     provider_price = ?, provider_cost_base = ?, custom_selling_price = ?,
                                     last_price_sync_at = NOW()
                                 WHERE id = ?",
                                [
                                    substr($sCode, 0, 100),
                                    substr($cCode, 0, 100),
                                    $cost,
                                    $calc['cost_base'],
                                    $newSelling,
                                    (int)$existingMap['id'],
                                ]
                            );
                        }
                        $updatedServices++;
                        if ($priceChanged) {
                            $pricesUpdated++;
                        }
                    }
                } catch (Throwable $itemErr) {
                    $failed++;
                    if (count($errors) < 50) {
                        $errors[] = "{$cCode}/{$sCode}: " . $itemErr->getMessage();
                    }
                }
            }

            foreach ($serviceLowestCost as $svcId => $lowInfo) {
                Database::execute(
                    "UPDATE otp_services
                     SET provider_price = ?, provider_currency = ?,
                         selling_price = CASE WHEN auto_price_update = 1 THEN ? ELSE selling_price END,
                         last_price_sync_at = NOW()
                     WHERE id = ?",
                    [$lowInfo['cost'], $provCurr, $lowInfo['selling_price'], $svcId]
                );
            }

            Database::commit();
        } catch (Throwable $txErr) {
            Database::rollBack();
            $msg = 'Database batch error during service import: ' . $txErr->getMessage();
            $errors[] = $msg;
            self::failJob($jobId, $msg, $errors);
            return ['ok' => false, 'error' => $msg, 'job' => self::getJobStatus($jobId)];
        }

        $nextOffset = $offset + count($slice);
        $nextStage = ($updatedServices > 0 && $newServices === 0) ? 'Updating Existing Records' : 'Importing Records';

        Database::execute(
            "UPDATE otp_import_jobs
             SET processed_records = ?,
                 batch_offset = ?,
                 new_countries = new_countries + ?,
                 updated_countries = updated_countries + ?,
                 new_services = new_services + ?,
                 updated_services = updated_services + ?,
                 prices_updated = prices_updated + ?,
                 unsupported_records = unsupported_records + ?,
                 skipped_records = skipped_records + ?,
                 failed_records = failed_records + ?,
                 errors_json = ?,
                 stage_message = ?
             WHERE id = ?",
            [
                min($total, $nextOffset),
                $nextOffset,
                $newCountries,
                $updatedCountries,
                $newServices,
                $updatedServices,
                $pricesUpdated,
                $unsupported,
                $skipped,
                $failed,
                json_encode($errors, JSON_UNESCAPED_UNICODE),
                $nextStage,
                $jobId,
            ]
        );

        if ($nextOffset >= $total) {
            return self::finalizeJob($jobId, $providerId, 'services');
        }

        return ['ok' => true, 'done' => false, 'job' => self::getJobStatus($jobId)];
    }

    /**
     * Finalize a completed batch job, compute `zero_reason` if nothing was imported/updated,
     * update provider sync timestamp, and release `staged_items_json` to keep MySQL compact.
     */
    private static function finalizeJob(int $jobId, int $providerId, string $jobType): array
    {
        $job = Database::fetch("SELECT * FROM otp_import_jobs WHERE id = ? LIMIT 1", [$jobId]);
        $zeroReason = null;
        if ($job) {
            $touched = (int)$job['new_countries'] + (int)$job['updated_countries'] + (int)$job['new_services'] + (int)$job['updated_services'];
            if ($touched === 0) {
                if ((int)$job['failed_records'] > 0) {
                    $zeroReason = 'All records failed during database import. Review the detailed error messages below.';
                } elseif ((int)$job['skipped_records'] > 0) {
                    $zeroReason = $job['mode'] === 'import_missing_only'
                        ? 'All fetched records already exist in your local database (0 missing records to add).'
                        : ($job['mode'] === 'update_existing'
                            ? 'No existing local services matched the fetched provider records.'
                            : 'All records were skipped based on the selected mode/filter.');
                } else {
                    $zeroReason = $jobType === 'countries'
                        ? 'No supported country records were found in the provider response.'
                        : 'No supported service pricing records were found in the provider response.';
                }
            }
        }

        Database::execute(
            "UPDATE otp_import_jobs
             SET status = 'completed',
                 stage_message = 'Completed',
                 staged_items_json = NULL,
                 zero_reason = COALESCE(zero_reason, ?),
                 completed_at = NOW()
             WHERE id = ?",
            [$zeroReason, $jobId]
        );

        Database::execute(
            "UPDATE otp_providers SET last_sync_at = NOW(), connection_status = 'connected', last_error = NULL WHERE id = ?",
            [$providerId]
        );

        return ['ok' => true, 'done' => true, 'job' => self::getJobStatus($jobId)];
    }

    private static function failJob(int $jobId, string $message, array $errors = []): void
    {
        Database::execute(
            "UPDATE otp_import_jobs
             SET status = 'failed',
                 stage_message = ?,
                 staged_items_json = NULL,
                 errors_json = ?,
                 zero_reason = ?,
                 completed_at = NOW()
             WHERE id = ?",
            [
                substr('Failed: ' . $message, 0, 250),
                json_encode(array_slice($errors, 0, 50), JSON_UNESCAPED_UNICODE),
                substr($message, 0, 250),
                $jobId,
            ]
        );
    }

    public static function cancelJob(int $jobId, int $providerId): array
    {
        $job = Database::fetch("SELECT * FROM otp_import_jobs WHERE id = ? AND provider_id = ? LIMIT 1", [$jobId, $providerId]);
        if (!$job) {
            return ['ok' => false, 'error' => 'Import job not found.'];
        }
        Database::execute(
            "UPDATE otp_import_jobs
             SET status = 'cancelled', stage_message = 'Cancelled by admin', staged_items_json = NULL, completed_at = NOW()
             WHERE id = ?",
            [$jobId]
        );
        return ['ok' => true, 'job' => self::getJobStatus($jobId)];
    }

    public static function getJobStatus(int $jobId): ?array
    {
        $row = Database::fetch(
            "SELECT id, provider_id, admin_id, job_type, mode, status, stage_message,
                    total_records, processed_records, batch_offset,
                    total_countries_found, new_countries, updated_countries,
                    total_services_found, new_services, updated_services,
                    prices_updated, unsupported_records, skipped_records, failed_records,
                    errors_json, zero_reason, started_at, completed_at, created_at, updated_at
             FROM otp_import_jobs WHERE id = ? LIMIT 1",
            [$jobId]
        );
        return $row ? self::formatJobSummary($row) : null;
    }

    public static function getActiveOrLatestJob(int $providerId, ?string $jobType = null): ?array
    {
        $sql = "SELECT id, provider_id, admin_id, job_type, mode, status, stage_message,
                       total_records, processed_records, batch_offset,
                       total_countries_found, new_countries, updated_countries,
                       total_services_found, new_services, updated_services,
                       prices_updated, unsupported_records, skipped_records, failed_records,
                       errors_json, zero_reason, started_at, completed_at, created_at, updated_at
                FROM otp_import_jobs
                WHERE provider_id = ? AND mode <> 'preview'";
        $params = [$providerId];
        if ($jobType !== null) {
            $sql .= " AND job_type = ?";
            $params[] = $jobType;
        }
        $sql .= " ORDER BY FIELD(status, 'importing', 'processing', 'fetching_services', 'fetching_countries', 'queued', 'completed', 'failed', 'cancelled'), id DESC LIMIT 1";
        $row = Database::fetch($sql, $params);
        return $row ? self::formatJobSummary($row) : null;
    }

    private static function formatJobSummary(array $row): array
    {
        $total = (int)($row['total_records'] ?? 0);
        $processed = (int)($row['processed_records'] ?? 0);
        $percent = 0;
        if (($row['status'] ?? '') === 'completed') {
            $percent = 100;
        } elseif ($total > 0) {
            $percent = min(100, (int)floor(($processed / $total) * 100));
        }
        $errors = json_decode((string)($row['errors_json'] ?? '[]'), true);
        if (!is_array($errors)) {
            $errors = [];
        }

        return [
            'id'                    => (int)$row['id'],
            'provider_id'           => (int)($row['provider_id'] ?? 0),
            'job_type'              => (string)($row['job_type'] ?? 'services'),
            'mode'                  => (string)($row['mode'] ?? ''),
            'status'                => (string)($row['status'] ?? 'queued'),
            'stage_message'         => (string)($row['stage_message'] ?? ''),
            'total_records'         => $total,
            'processed_records'     => $processed,
            'progress_percent'      => $percent,
            'total_countries_found' => (int)($row['total_countries_found'] ?? 0),
            'new_countries'         => (int)($row['new_countries'] ?? 0),
            'updated_countries'     => (int)($row['updated_countries'] ?? 0),
            'total_services_found'  => (int)($row['total_services_found'] ?? 0),
            'new_services'          => (int)($row['new_services'] ?? 0),
            'updated_services'      => (int)($row['updated_services'] ?? 0),
            'prices_updated'        => (int)($row['prices_updated'] ?? 0),
            'unsupported_records'   => (int)($row['unsupported_records'] ?? 0),
            'skipped_records'       => (int)($row['skipped_records'] ?? 0),
            'failed_records'        => (int)($row['failed_records'] ?? 0),
            'errors'                => $errors,
            'error_count'           => count($errors),
            'zero_reason'           => $row['zero_reason'] ?? null,
            'started_at'            => $row['started_at'] ?? null,
            'completed_at'          => $row['completed_at'] ?? null,
            'updated_at'            => $row['updated_at'] ?? null,
            // Backwards-compatible aliases for legacy UI consumers
            'total_found'           => $total,
            'new_imported'          => (int)($row['new_services'] ?? 0),
            'existing_updated'      => (int)($row['updated_services'] ?? 0),
            'skipped'               => (int)($row['skipped_records'] ?? 0),
        ];
    }

    private static function loadExistingMappingIndex(int $providerId): array
    {
        $existingMappings = [];
        $mapRows = Database::fetchAll(
            "SELECT m.id, m.service_id, m.country_id, m.provider_service_code, m.provider_country_code,
                    m.provider_price, m.custom_selling_price, m.pricing_mode, m.is_available,
                    m.available_count, m.availability_verified,
                    s.name AS service_name, s.provider_service_code AS svc_code, s.selling_price AS svc_selling_price,
                    c.default_provider_code AS cntry_code
             FROM otp_service_provider_mappings m
             JOIN otp_services s ON s.id = m.service_id
             JOIN otp_countries c ON c.id = m.country_id
             WHERE m.provider_id = ?",
            [$providerId]
        );
        foreach ($mapRows as $mr) {
            $cKey = strtolower(trim((string)($mr['provider_country_code'] ?: $mr['cntry_code'])));
            $sKey = strtolower(trim((string)($mr['provider_service_code'] ?: $mr['svc_code'])));
            $existingMappings[$cKey . '|' . $sKey] = $mr;
        }
        return $existingMappings;
    }

    private static function sliceStagedPreview(
        array $provider,
        array $staged,
        array $existingIndex,
        array $pricingOpts,
        int $page,
        int $perPage,
        string $search,
        string $countryFilter,
        string $statusFilter
    ): array {
        $pricing = self::normalizePricingOptions($pricingOpts);
        $baseCode = Currency::getBaseCode();
        $provCurr = (string)($provider['currency_code'] ?: $baseCode);
        $q = strtolower(trim($search));
        $cf = strtolower(trim($countryFilter));

        $matched = [];
        foreach ($staged as $st) {
            $pairKey = strtolower($st['cc']) . '|' . strtolower($st['sc']);
            $exists = isset($existingIndex[$pairKey]);
            if ($statusFilter === 'new' && $exists) continue;
            if ($statusFilter === 'existing' && !$exists) continue;
            if ($cf !== '' && strtolower($st['cc']) !== $cf) continue;
            if ($q !== '') {
                $hay = strtolower($st['sn'] . ' ' . $st['sc'] . ' ' . $st['cn'] . ' ' . $st['cc'] . ' ' . $st['ci']);
                if (!str_contains($hay, $q)) continue;
            }
            $matched[] = $st;
        }

        $totalFiltered = count($matched);
        $perPage = max(25, min(250, $perPage));
        $totalPages = max(1, (int)ceil($totalFiltered / $perPage));
        $page = max(1, min($totalPages, $page));
        $offset = ($page - 1) * $perPage;
        $pageSlice = array_slice($matched, $offset, $perPage);

        $preview = [];
        foreach ($pageSlice as $st) {
            $pairKey = strtolower($st['cc']) . '|' . strtolower($st['sc']);
            $existing = $existingIndex[$pairKey] ?? null;
            $calc = self::computeServerPrice($provider, (string)$st['cost'], $pricing);

            $preview[] = [
                'key'                      => $st['cc'] . '|' . $st['sc'],
                'country_code'             => $st['cc'],
                'country_name'             => $st['cn'],
                'country_iso'              => $st['ci'],
                'country_flag'             => $st['cf'],
                'service_code'             => $st['sc'],
                'service_name'             => $st['sn'],
                'service_slug'             => $st['ss'],
                'icon_key'                 => $st['ik'],
                'operator'                 => $st['op'],
                'provider_cost'            => (string)$st['cost'],
                'provider_currency'        => $provCurr,
                'provider_cost_base'       => $calc['cost_base'],
                'exchange_rate'            => $calc['exchange_rate'],
                'calculated_selling_price' => $calc['selling_price'],
                'estimated_profit'         => $calc['profit'],
                'base_currency'            => $baseCode,
                'api_count'                => $st['cnt'],
                'exists_locally'           => $existing !== null,
                'existing_mapping_id'      => $existing ? (int)$existing['id'] : null,
                'existing_service_id'      => $existing ? (int)$existing['service_id'] : null,
                'existing_selling_price'   => $existing ? ($existing['custom_selling_price'] ?: $existing['svc_selling_price']) : null,
                'availability_verified'    => $existing ? ((int)$existing['availability_verified'] === 1) : false,
                'verified_count'           => $existing ? (int)$existing['available_count'] : 0,
            ];
        }

        return [
            'items'      => $preview,
            'pagination' => [
                'page'           => $page,
                'per_page'       => $perPage,
                'total_filtered' => $totalFiltered,
                'total_pages'    => $totalPages,
            ],
        ];
    }

    private static function pruneOldStagedPayloads(): void
    {
        try {
            Database::execute(
                "UPDATE otp_import_jobs
                 SET staged_items_json = NULL
                 WHERE status = 'ready' AND staged_items_json IS NOT NULL
                   AND updated_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)"
            );
        } catch (Throwable $e) {
            // Non-critical housekeeping
        }
    }

    /**
     * Backwards-compatible wrapper for live preview (now delegates to fast staged preview).
     */
    public static function fetchPreview(
        int $providerId,
        ?string $countryCode = null,
        ?string $serviceCode = null,
        array $pricingOpts = []
    ): array {
        return self::startPreviewAndStage($providerId, $countryCode, $serviceCode, $pricingOpts, null, 1, 150);
    }

    /**
     * Synchronous wrapper preserved for CLI/backwards compatibility (executes batch job end-to-end).
     */
    public static function runImport(
        int $providerId,
        string $mode,
        array $selectedKeys = [],
        array $options = []
    ): array {
        if ($mode === 'sync_availability') {
            $scope = (string)($options['scope'] ?? 'all_enabled');
            $countryId = !empty($options['country_id']) ? (int)$options['country_id'] : null;
            $serviceIds = array_values(array_filter(array_map('intval', (array)($options['service_ids'] ?? []))));
            $disableUnavailable = !empty($options['disable_unavailable']);
            return self::syncAvailability($providerId, $scope, $countryId, $serviceIds, $disableUnavailable);
        }

        if ($mode === 'sync_prices') {
            $countryCode = isset($options['country_code']) && trim((string)$options['country_code']) !== ''
                ? trim((string)$options['country_code']) : null;
            $serviceIds = array_values(array_filter(array_map('intval', (array)($options['service_ids'] ?? []))));
            return self::syncPrices($providerId, $countryCode, $serviceIds);
        }

        $start = self::startBatchJob($providerId, null, 'services', $mode, $selectedKeys, $options, null);
        if (empty($start['ok'])) {
            return $start;
        }
        $jobId = (int)$start['job_id'];
        $guard = 0;
        $last = $start;
        while (empty($last['done']) && $guard < 500) {
            $last = self::processNextBatch($jobId, 200);
            if (empty($last['ok'])) {
                return $last;
            }
            $guard++;
        }
        $job = $last['job'] ?? self::getJobStatus($jobId);
        return array_merge(['ok' => true], $job ?: []);
    }

    /**
     * Sync latest provider prices from getPrices() and recalculate selling prices
     * for mappings/services with auto_price_update = 1 and pricing_mode = 'auto'.
     * Never modifies existing otp_activations historical snapshots.
     */
    public static function syncPrices(int $providerId, ?string $countryCode = null, array $serviceIds = []): array
    {
        $provider = Database::fetch("SELECT * FROM otp_providers WHERE id = ? LIMIT 1", [$providerId]);
        if (!$provider) {
            return ['ok' => false, 'error' => 'OTP provider not found.'];
        }

        $singleServiceCode = null;
        if (count($serviceIds) === 1) {
            $singleServiceCode = Database::fetchColumn(
                "SELECT provider_service_code FROM otp_services WHERE id = ? LIMIT 1",
                [$serviceIds[0]]
            ) ?: null;
        }

        try {
            $adapter = OtpProviderFactory::make($provider);
            $res = $adapter->getPrices($countryCode, $singleServiceCode);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Provider adapter error: ' . $e->getMessage()];
        }

        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => (string)($res['error'] ?? 'Failed to fetch latest prices from provider.')];
        }

        $priceMap = [];
        $uniqueCountries = [];
        $uniqueServices = [];
        foreach (($res['items'] ?? []) as $it) {
            $cc = strtolower(trim((string)$it['country_code']));
            $sc = strtolower(trim((string)$it['service_code']));
            $uniqueCountries[$cc] = true;
            $uniqueServices[$sc] = true;
            $priceMap[$cc . '|' . $sc] = $it;
        }

        $sql = "SELECT m.*, s.provider_service_code AS svc_code, s.profit_margin AS svc_margin,
                       s.fixed_profit AS svc_fixed_profit, s.min_price AS svc_min, s.max_price AS svc_max,
                       s.auto_price_update AS svc_auto_update, c.default_provider_code AS cntry_code
                FROM otp_service_provider_mappings m
                JOIN otp_services s ON s.id = m.service_id
                JOIN otp_countries c ON c.id = m.country_id
                WHERE m.provider_id = ?";
        $params = [$providerId];
        if (!empty($serviceIds)) {
            $ph = implode(',', array_fill(0, count($serviceIds), '?'));
            $sql .= " AND m.service_id IN ({$ph})";
            $params = array_merge($params, $serviceIds);
        }
        $mappings = Database::fetchAll($sql, $params);

        $report = [
            'ok'                    => true,
            'mode'                  => 'sync_prices',
            'total_found'           => count($res['items'] ?? []),
            'total_countries_found' => count($uniqueCountries),
            'new_countries'         => 0,
            'updated_countries'     => 0,
            'total_services_found'  => count($uniqueServices),
            'new_services'          => 0,
            'updated_services'      => 0,
            'new_imported'          => 0,
            'existing_updated'      => 0,
            'prices_updated'        => 0,
            'unsupported_records'   => 0,
            'skipped_records'       => 0,
            'skipped'               => 0,
            'failed_records'        => 0,
            'errors'                => [],
            'error_count'           => 0,
            'zero_reason'           => null,
        ];

        $serviceLowest = [];
        Database::beginTransaction();
        try {
            foreach ($mappings as $m) {
                $cCode = strtolower(trim((string)($m['provider_country_code'] ?: $m['cntry_code'])));
                $sCode = strtolower(trim((string)($m['provider_service_code'] ?: $m['svc_code'])));
                $k = $cCode . '|' . $sCode;
                if (!isset($priceMap[$k])) {
                    $report['skipped']++;
                    $report['skipped_records']++;
                    continue;
                }

                $it = $priceMap[$k];
                $newProvCost = Currency::normalize((string)$it['cost'], 4);
                $effPricing = [
                    'pricing_mode'        => (string)($m['pricing_mode'] ?? 'auto'),
                    'fixed_selling_price' => $m['custom_selling_price'],
                    'profit_margin'       => $m['profit_margin'] ?? $m['svc_margin'] ?? Helpers::setting('otp_default_profit_margin', '25.00'),
                    'fixed_profit'        => $m['fixed_profit'] ?? $m['svc_fixed_profit'] ?? '0',
                    'min_price'           => $m['min_price'] ?? $m['svc_min'],
                    'max_price'           => $m['max_price'] ?? $m['svc_max'],
                ];
                $calc = self::computeServerPrice($provider, $newProvCost, $effPricing);

                $autoUpdate = ((int)($m['auto_price_update'] ?? 1) === 1) && ((string)($m['pricing_mode'] ?? 'auto') === 'auto');
                $newSelling = $autoUpdate ? $calc['selling_price'] : $m['custom_selling_price'];

                $changed = ($m['provider_price'] === null
                    || Currency::cmp((string)$m['provider_price'], $newProvCost, 4) !== 0
                    || ($newSelling !== null && ($m['custom_selling_price'] === null || Currency::cmp((string)$m['custom_selling_price'], (string)$newSelling, 4) !== 0)));

                Database::execute(
                    "UPDATE otp_service_provider_mappings
                     SET provider_price = ?, provider_cost_base = ?, custom_selling_price = ?, last_price_sync_at = NOW()
                     WHERE id = ?",
                    [$newProvCost, $calc['cost_base'], $newSelling, (int)$m['id']]
                );

                $report['existing_updated']++;
                $report['updated_services']++;
                if ($changed) {
                    $report['prices_updated']++;
                }

                $svcId = (int)$m['service_id'];
                if (!isset($serviceLowest[$svcId]) || Currency::cmp($newProvCost, $serviceLowest[$svcId]['cost'], 4) < 0) {
                    $serviceLowest[$svcId] = [
                        'cost'          => $newProvCost,
                        'selling_price' => $calc['selling_price'],
                    ];
                }
            }

            foreach ($serviceLowest as $svcId => $low) {
                Database::execute(
                    "UPDATE otp_services
                     SET provider_price = ?, provider_currency = ?,
                         selling_price = CASE WHEN auto_price_update = 1 THEN ? ELSE selling_price END,
                         last_price_sync_at = NOW()
                     WHERE id = ?",
                    [$low['cost'], (string)($provider['currency_code'] ?: Currency::getBaseCode()), $low['selling_price'], $svcId]
                );
            }

            Database::execute(
                "UPDATE otp_providers SET last_sync_at = NOW(), connection_status = 'connected', last_error = NULL WHERE id = ?",
                [$providerId]
            );
            Database::commit();
        } catch (Throwable $e) {
            Database::rollBack();
            return ['ok' => false, 'error' => 'Failed updating prices: ' . $e->getMessage()];
        }

        if ($report['existing_updated'] === 0) {
            $report['zero_reason'] = empty($mappings)
                ? 'No local services are imported for this provider yet. Import services first.'
                : 'None of your locally mapped services matched the returned provider price list.';
        }
        $report['error_count'] = count($report['errors']);
        return $report;
    }

    /**
     * Synchronize real number availability using getNumbersStatus() per mapped country.
     */
    public static function syncAvailability(
        int $providerId,
        string $scope = 'all_enabled',
        ?int $countryId = null,
        array $serviceIds = [],
        bool $disableUnavailable = false
    ): array {
        $provider = Database::fetch("SELECT * FROM otp_providers WHERE id = ? LIMIT 1", [$providerId]);
        if (!$provider) {
            return ['ok' => false, 'error' => 'OTP provider not found.'];
        }

        $sql = "SELECT m.id, m.service_id, m.country_id, m.provider_service_code, m.provider_country_code,
                       m.provider_operator, m.is_available, s.provider_service_code AS svc_code,
                       c.default_provider_code AS cntry_code, c.name AS country_name
                FROM otp_service_provider_mappings m
                JOIN otp_services s ON s.id = m.service_id
                JOIN otp_countries c ON c.id = m.country_id
                WHERE m.provider_id = ? AND m.status = 'active'";
        $params = [$providerId];

        if ($scope === 'all_enabled') {
            $sql .= " AND s.status = 'active' AND c.status = 'active'";
        } elseif ($scope === 'selected_country' && $countryId !== null && $countryId > 0) {
            $sql .= " AND m.country_id = ?";
            $params[] = $countryId;
        } elseif ($scope === 'selected_services' && !empty($serviceIds)) {
            $ph = implode(',', array_fill(0, count($serviceIds), '?'));
            $sql .= " AND m.service_id IN ({$ph})";
            $params = array_merge($params, $serviceIds);
        }

        $mappings = Database::fetchAll($sql, $params);
        if (empty($mappings)) {
            return [
                'ok'    => false,
                'error' => 'No matching mapped services found for this provider and scope. Import services first.',
            ];
        }

        $byCountry = [];
        foreach ($mappings as $m) {
            $provCountry = trim((string)($m['provider_country_code'] ?: $m['cntry_code']));
            if ($provCountry === '') continue;
            $byCountry[$provCountry][] = $m;
        }

        try {
            $adapter = OtpProviderFactory::make($provider);
        } catch (Throwable $e) {
            $err = 'Provider adapter error: ' . ProviderErrorHandler::redact($e->getMessage());
            ProviderErrorHandler::recordProviderError('otp', $providerId, $err, false);
            return ['ok' => false, 'error' => $err];
        }
        $report = [
            'ok'                        => true,
            'mode'                      => 'sync_availability',
            'scope'                     => $scope,
            'countries_checked'         => 0,
            'countries_skipped_limit'   => 0,
            'mappings_checked'          => 0,
            'available_services'        => 0,
            'unavailable_services'      => 0,
            'disabled_count'            => 0,
            'enabled_count'             => 0,
            'errors'                    => [],
            'error_count'               => 0,
            'last_availability_sync_at' => date('Y-m-d H:i:s'),
        ];

        $countryKeys = array_keys($byCountry);
        if (count($countryKeys) > self::MAX_AVAILABILITY_COUNTRIES_PER_RUN) {
            $report['countries_skipped_limit'] = count($countryKeys) - self::MAX_AVAILABILITY_COUNTRIES_PER_RUN;
            $countryKeys = array_slice($countryKeys, 0, self::MAX_AVAILABILITY_COUNTRIES_PER_RUN);
        }

        foreach ($countryKeys as $provCountry) {
            $group = $byCountry[$provCountry];
            $res = $adapter->getNumbersStatus($provCountry, null);
            if (empty($res['ok'])) {
                $report['errors'][] = "Country {$provCountry}: " . ($res['error'] ?? 'Failed to fetch availability');
                continue;
            }
            $report['countries_checked']++;
            $counts = is_array($res['counts'] ?? null) ? $res['counts'] : [];
            $countsLower = array_change_key_case($counts, CASE_LOWER);

            foreach ($group as $m) {
                $svcCode = strtolower(trim((string)($m['provider_service_code'] ?: $m['svc_code'])));
                $stock = isset($countsLower[$svcCode]) ? max(0, (int)$countsLower[$svcCode]) : 0;
                $report['mappings_checked']++;

                $nextAvail = (int)$m['is_available'];
                if ($stock > 0) {
                    $report['available_services']++;
                    if ($disableUnavailable && $nextAvail === 0) {
                        $nextAvail = 1;
                        $report['enabled_count']++;
                    }
                } else {
                    $report['unavailable_services']++;
                    if ($disableUnavailable && $nextAvail === 1) {
                        $nextAvail = 0;
                        $report['disabled_count']++;
                    }
                }

                Database::execute(
                    "UPDATE otp_service_provider_mappings
                     SET available_count = ?, availability_verified = 1, is_available = ?, last_availability_sync_at = NOW()
                     WHERE id = ?",
                    [$stock, $nextAvail, (int)$m['id']]
                );
            }

            if (count($countryKeys) > 3) {
                usleep(120000);
            }
        }

        if ($report['countries_checked'] > 0) {
            Database::execute(
                "UPDATE otp_providers SET last_availability_sync_at = NOW(), connection_status = 'connected' WHERE id = ?",
                [$providerId]
            );
        }

        $report['error_count'] = count($report['errors']);
        return $report;
    }

    /**
     * Normalize server-side pricing rule inputs.
     */
    public static function normalizePricingOptions(array $opts): array
    {
        $pricingMode = (($opts['pricing_mode'] ?? 'auto') === 'fixed') ? 'fixed' : 'auto';
        $fixedSelling = isset($opts['fixed_selling_price']) && trim((string)$opts['fixed_selling_price']) !== ''
            ? Currency::normalize((string)$opts['fixed_selling_price'], 4) : null;
        $margin = isset($opts['profit_margin']) && trim((string)$opts['profit_margin']) !== ''
            ? Currency::normalize((string)$opts['profit_margin'], 2)
            : Currency::normalize((string)Helpers::setting('otp_default_profit_margin', '25.00'), 2);
        $fixedProfit = isset($opts['fixed_profit']) && trim((string)$opts['fixed_profit']) !== ''
            ? Currency::normalize((string)$opts['fixed_profit'], 4) : '0.0000';
        $minPrice = isset($opts['min_price']) && trim((string)$opts['min_price']) !== ''
            ? Currency::normalize((string)$opts['min_price'], 4) : null;
        $maxPrice = isset($opts['max_price']) && trim((string)$opts['max_price']) !== ''
            ? Currency::normalize((string)$opts['max_price'], 4) : null;
        $autoUpdate = !array_key_exists('auto_price_update', $opts) || !empty($opts['auto_price_update']) ? 1 : 0;
        $overrideExisting = !empty($opts['override_existing_rules']);

        return [
            'pricing_mode'            => $pricingMode,
            'fixed_selling_price'     => $fixedSelling,
            'profit_margin'           => $margin,
            'fixed_profit'            => $fixedProfit,
            'min_price'               => $minPrice,
            'max_price'               => $maxPrice,
            'auto_price_update'       => $autoUpdate,
            'override_existing_rules' => $overrideExisting,
        ];
    }

    /**
     * Authoritative server-side price formula.
     */
    public static function computeServerPrice(array $provider, string $rawProviderCost, array $pricing): array
    {
        $provCurr = (string)($provider['currency_code'] ?: 'RUB');
        $rateMode = (string)($provider['rate_mode'] ?? 'auto');
        $exRate = (string)($provider['exchange_rate'] ?? '1');
        $margin = (string)($pricing['profit_margin'] ?? '25.00');
        $fixedProfit = (string)($pricing['fixed_profit'] ?? '0.0000');

        $conv = Currency::calculateProviderToBase($rawProviderCost, $provCurr, $margin, $rateMode, $exRate);
        $costBase = $conv['cost_base'];
        $effectiveRate = $conv['effective_exchange_rate'];

        if (($pricing['pricing_mode'] ?? 'auto') === 'fixed' && !empty($pricing['fixed_selling_price']) && Currency::cmp((string)$pricing['fixed_selling_price'], '0', 4) > 0) {
            $selling = Currency::normalize((string)$pricing['fixed_selling_price'], 4);
        } else {
            $selling = Currency::add($conv['selling_rate_base'], $fixedProfit, 4);
        }

        if (!empty($pricing['min_price']) && Currency::cmp((string)$pricing['min_price'], '0', 4) > 0 && Currency::cmp($selling, (string)$pricing['min_price'], 4) < 0) {
            $selling = Currency::normalize((string)$pricing['min_price'], 4);
        }
        if (!empty($pricing['max_price']) && Currency::cmp((string)$pricing['max_price'], '0', 4) > 0 && Currency::cmp($selling, (string)$pricing['max_price'], 4) > 0) {
            $selling = Currency::normalize((string)$pricing['max_price'], 4);
        }

        return [
            'cost_base'     => $costBase,
            'exchange_rate' => $effectiveRate,
            'selling_price' => $selling,
            'profit'        => Currency::sub($selling, $costBase, 4),
        ];
    }

    private static function ensureUniqueIso(string $iso, array $existingMap): string
    {
        $base = strtoupper(preg_replace('/[^A-Z0-9]/', '', $iso)) ?: 'XX';
        $base = substr($base, 0, 10);
        if (!isset($existingMap[$base])) {
            return $base;
        }
        $i = 1;
        while ($i < 999) {
            $cand = substr($base, 0, 7) . $i;
            if (!isset($existingMap[$cand])) {
                return $cand;
            }
            $i++;
        }
        return substr('C' . bin2hex(random_bytes(4)), 0, 10);
    }

    private static function ensureUniqueSlug(string $slug, array $existingMap): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9_]+/', '_', $slug));
        $base = trim($base, '_') ?: 'otp_service';
        $base = substr($base, 0, 90);
        if (!isset($existingMap[$base])) {
            return $base;
        }
        $i = 2;
        while ($i < 999) {
            $cand = substr($base, 0, 84) . '_' . $i;
            if (!isset($existingMap[$cand])) {
                return $cand;
            }
            $i++;
        }
        return substr($base, 0, 80) . '_' . bin2hex(random_bytes(3));
    }
}
