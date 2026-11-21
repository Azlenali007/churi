<?php
/**
 * OTP Provider Automatic Service Import, Price Sync & Availability Sync Engine
 * - Discovers available services & countries from live provider getPrices() responses (never fabricates data)
 * - Supports all 7 import/sync modes with duplicate prevention & MySQL transactions
 * - Calculates all selling prices strictly server-side with explicit FX conversion
 * - Verifies actual number stock via getNumbersStatus() with API rate-limit throttling
 */

class OtpProviderImporter
{
    private const MAX_AVAILABILITY_COUNTRIES_PER_RUN = 25;

    /**
     * Fetch live getPrices preview for a provider, annotated with existing local DB match info
     * and server-side calculated selling prices.
     */
    public static function fetchPreview(
        int $providerId,
        ?string $countryCode = null,
        ?string $serviceCode = null,
        array $pricingOpts = []
    ): array {
        $provider = Database::fetch("SELECT * FROM otp_providers WHERE id = ? LIMIT 1", [$providerId]);
        if (!$provider) {
            return ['ok' => false, 'error' => 'OTP provider not found.'];
        }

        try {
            $adapter = OtpProviderFactory::make($provider);
            $res = $adapter->getPrices($countryCode, $serviceCode);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Provider adapter error: ' . $e->getMessage()];
        }

        if (empty($res['ok'])) {
            return [
                'ok'    => false,
                'error' => (string)($res['error'] ?? 'Failed to fetch services/prices from provider API.'),
            ];
        }

        $rawItems = is_array($res['items'] ?? null) ? $res['items'] : [];
        $countries = is_array($res['countries'] ?? null) ? $res['countries'] : [];

        // Index existing mappings and services for this provider to show accurate import status
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

        $pricing = self::normalizePricingOptions($pricingOpts);
        $baseCode = Currency::getBaseCode();
        $provCurr = (string)($provider['currency_code'] ?: 'RUB');

        $preview = [];
        $newCount = 0;
        $existingCount = 0;

        foreach ($rawItems as $it) {
            $cCode = (string)$it['country_code'];
            $sCode = (string)$it['service_code'];
            $pairKey = strtolower(trim($cCode)) . '|' . strtolower(trim($sCode));
            $existing = $existingMappings[$pairKey] ?? null;
            if ($existing) {
                $existingCount++;
            } else {
                $newCount++;
            }

            $calc = self::computeServerPrice($provider, (string)$it['cost'], $pricing);

            $preview[] = [
                'key'                      => $cCode . '|' . $sCode,
                'country_code'             => $cCode,
                'country_name'             => (string)$it['country_name'],
                'country_iso'              => (string)$it['country_iso'],
                'country_flag'             => (string)$it['country_flag'],
                'service_code'             => $sCode,
                'service_name'             => (string)$it['service_name'],
                'service_slug'             => (string)$it['service_slug'],
                'icon_key'                 => (string)$it['icon_key'],
                'operator'                 => (string)($it['operator'] ?? 'any'),
                'provider_cost'            => (string)$it['cost'],
                'provider_currency'        => $provCurr,
                'provider_cost_base'       => $calc['cost_base'],
                'exchange_rate'            => $calc['exchange_rate'],
                'calculated_selling_price' => $calc['selling_price'],
                'estimated_profit'         => $calc['profit'],
                'base_currency'            => $baseCode,
                'api_count'                => $it['count'],
                'exists_locally'           => $existing !== null,
                'existing_mapping_id'      => $existing ? (int)$existing['id'] : null,
                'existing_service_id'      => $existing ? (int)$existing['service_id'] : null,
                'existing_selling_price'   => $existing ? ($existing['custom_selling_price'] ?: $existing['svc_selling_price']) : null,
                'availability_verified'    => $existing ? ((int)$existing['availability_verified'] === 1) : false,
                'verified_count'           => $existing ? (int)$existing['available_count'] : 0,
            ];
        }

        // Sort countries alphabetically for clean filter dropdown
        usort($countries, static fn($a, $b) => strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));

        return [
            'ok'               => true,
            'provider_id'      => $providerId,
            'provider_name'    => (string)$provider['name'],
            'provider_currency'=> $provCurr,
            'base_currency'    => $baseCode,
            'total_found'      => count($preview),
            'new_count'        => $newCount,
            'existing_count'   => $existingCount,
            'countries'        => $countries,
            'items'            => $preview,
            'http_code'        => (int)($res['http_code'] ?? 200),
            'response_time_ms' => (int)($res['response_time_ms'] ?? 0),
        ];
    }

    /**
     * Execute one of the 7 automatic import / update modes cleanly inside a MySQL transaction.
     * Re-fetches live prices from provider API server-side (never trusts client-submitted prices).
     *
     * Supported modes:
     *  1. import_all
     *  2. import_by_country
     *  3. import_selected
     *  4. update_existing
     *  5. sync_prices
     *  6. sync_availability
     *  7. import_missing_only
     */
    public static function runImport(
        int $providerId,
        string $mode,
        array $selectedKeys = [],
        array $options = []
    ): array {
        $provider = Database::fetch("SELECT * FROM otp_providers WHERE id = ? LIMIT 1", [$providerId]);
        if (!$provider) {
            return ['ok' => false, 'error' => 'OTP provider not found.'];
        }

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

        $filterCountry = isset($options['country_code']) && trim((string)$options['country_code']) !== ''
            ? trim((string)$options['country_code']) : null;
        $filterService = isset($options['service_code']) && trim((string)$options['service_code']) !== ''
            ? trim((string)$options['service_code']) : null;

        if ($mode === 'import_by_country' && $filterCountry === null) {
            return ['ok' => false, 'error' => 'Please select a country for country-wise import.'];
        }

        $selectedLookup = [];
        foreach ($selectedKeys as $k) {
            $selectedLookup[strtolower(trim((string)$k))] = true;
        }
        if ($mode === 'import_selected' && empty($selectedLookup)) {
            return ['ok' => false, 'error' => 'Please select at least one service/country item to import.'];
        }

        try {
            $adapter = OtpProviderFactory::make($provider);
            $res = $adapter->getPrices($filterCountry, $filterService);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Provider adapter error: ' . $e->getMessage()];
        }

        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => (string)($res['error'] ?? 'Failed to fetch services from provider API.')];
        }

        $items = is_array($res['items'] ?? null) ? $res['items'] : [];
        $pricing = self::normalizePricingOptions($options);
        $importStatus = (($options['import_status'] ?? 'active') === 'inactive') ? 'inactive' : 'active';
        $provCurr = (string)($provider['currency_code'] ?: 'RUB');

        $report = [
            'ok'               => true,
            'mode'             => $mode,
            'total_found'      => count($items),
            'new_imported'     => 0,
            'existing_updated' => 0,
            'prices_updated'   => 0,
            'skipped'          => 0,
            'errors'           => [],
            'error_count'      => 0,
        ];

        if (empty($items)) {
            return $report;
        }

        // Pre-load local countries and services caches to avoid duplicate inserts
        $countryCacheByCode = [];
        $countryCacheByIso = [];
        foreach (Database::fetchAll("SELECT * FROM otp_countries") as $cRow) {
            $countryCacheByCode[strtolower(trim((string)$cRow['default_provider_code']))] = $cRow;
            $countryCacheByIso[strtoupper(trim((string)$cRow['iso_code']))] = $cRow;
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

        Database::beginTransaction();
        try {
            $serviceLowestCost = [];

            foreach ($items as $it) {
                $cCode = trim((string)$it['country_code']);
                $sCode = trim((string)$it['service_code']);
                $pairKey = strtolower($cCode . '|' . $sCode);

                if ($mode === 'import_selected' && empty($selectedLookup[$pairKey])) {
                    $report['skipped']++;
                    continue;
                }
                if ($mode === 'import_by_country' && $filterCountry !== null && strcasecmp($cCode, $filterCountry) !== 0) {
                    $report['skipped']++;
                    continue;
                }

                try {
                    // 1. Resolve or create Country
                    $cCodeLow = strtolower($cCode);
                    $cIsoUp = strtoupper(trim((string)$it['country_iso']));
                    $countryRow = $countryCacheByCode[$cCodeLow] ?? ($countryCacheByIso[$cIsoUp] ?? null);

                    // Check if mapping already exists when in update_existing or import_missing_only mode
                    $sCodeLow = strtolower($sCode);
                    $slugLow = strtolower(trim((string)$it['service_slug']));
                    $serviceRow = $serviceCacheByProvCode[$sCodeLow] ?? ($serviceCacheBySlug[$slugLow] ?? null);
                    $existingMap = ($serviceRow && $countryRow)
                        ? ($mappingCache[(int)$serviceRow['id'] . ':' . (int)$countryRow['id']] ?? null)
                        : null;

                    if ($mode === 'update_existing' && $existingMap === null) {
                        $report['skipped']++;
                        continue;
                    }
                    if ($mode === 'import_missing_only' && $existingMap !== null) {
                        $report['skipped']++;
                        continue;
                    }

                    if ($countryRow === null) {
                        $uniqueIso = self::ensureUniqueIso($cIsoUp, $countryCacheByIso);
                        $newCountryId = (int)Database::insert(
                            "INSERT INTO otp_countries (name, iso_code, dial_code, flag_emoji, default_provider_code, status)
                             VALUES (?, ?, ?, ?, ?, 'active')",
                            [
                                substr((string)$it['country_name'], 0, 120),
                                $uniqueIso,
                                substr((string)($it['country_dial'] ?? ''), 0, 15) ?: null,
                                substr((string)($it['country_flag'] ?? ''), 0, 16) ?: null,
                                substr($cCode, 0, 80),
                            ]
                        );
                        $countryRow = [
                            'id'                    => $newCountryId,
                            'name'                  => (string)$it['country_name'],
                            'iso_code'              => $uniqueIso,
                            'default_provider_code' => $cCode,
                        ];
                        $countryCacheByCode[$cCodeLow] = $countryRow;
                        $countryCacheByIso[$uniqueIso] = $countryRow;
                    }

                    $calc = self::computeServerPrice($provider, (string)$it['cost'], $pricing);

                    // 2. Resolve or create Service
                    if ($serviceRow === null) {
                        $uniqueSlug = self::ensureUniqueSlug($slugLow, $serviceCacheBySlug);
                        $newSvcId = (int)Database::insert(
                            "INSERT INTO otp_services
                             (name, slug, icon_key, description, provider_id, provider_service_code,
                              provider_price, provider_currency, selling_price, profit_margin, fixed_profit,
                              min_price, max_price, auto_price_update, last_price_sync_at, is_popular, status)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)",
                            [
                                substr((string)$it['service_name'], 0, 120),
                                $uniqueSlug,
                                substr((string)($it['icon_key'] ?? 'general'), 0, 60),
                                'One-time SMS verification for ' . (string)$it['service_name'],
                                $providerId,
                                substr($sCode, 0, 100),
                                (string)$it['cost'],
                                $provCurr,
                                $calc['selling_price'],
                                $pricing['profit_margin'],
                                $pricing['fixed_profit'],
                                $pricing['min_price'],
                                $pricing['max_price'],
                                $pricing['auto_price_update'],
                                (int)($it['is_popular'] ?? 0),
                                $importStatus,
                            ]
                        );
                        $serviceRow = [
                            'id'                    => $newSvcId,
                            'name'                  => (string)$it['service_name'],
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
                    } else {
                        // Ensure service is linked to provider if unassigned
                        if (empty($serviceRow['provider_id'])) {
                            Database::execute(
                                "UPDATE otp_services SET provider_id = ?, provider_service_code = COALESCE(NULLIF(provider_service_code,''), ?) WHERE id = ?",
                                [$providerId, $sCode, (int)$serviceRow['id']]
                            );
                            $serviceRow['provider_id'] = $providerId;
                        }
                    }

                    $svcId = (int)$serviceRow['id'];
                    $cntryId = (int)$countryRow['id'];
                    $mapKey = $svcId . ':' . $cntryId;

                    // Track lowest provider cost per service to keep otp_services.provider_price accurate
                    if (!isset($serviceLowestCost[$svcId]) || Currency::cmp((string)$it['cost'], $serviceLowestCost[$svcId]['cost'], 4) < 0) {
                        $serviceLowestCost[$svcId] = [
                            'cost'          => (string)$it['cost'],
                            'selling_price' => $calc['selling_price'],
                        ];
                    }

                    $existingMap = $mappingCache[$mapKey] ?? null;
                    $operator = substr((string)($it['operator'] ?: 'any'), 0, 80);

                    if ($existingMap === null) {
                        // Do NOT assume verified stock just from getPrices; mark availability_verified = 0 until getNumbersStatus verifies it
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
                                (string)$it['cost'],
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
                        $mappingCache[$mapKey] = ['id' => $newMapId, 'provider_price' => (string)$it['cost'], 'custom_selling_price' => $calc['selling_price']];
                        $report['new_imported']++;
                        $report['prices_updated']++;
                    } else {
                        // Update existing mapping without duplicating; respect auto_price_update / fixed lock unless explicitly overridden
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
                            $recalc = self::computeServerPrice($provider, (string)$it['cost'], $effPricing);
                            $newSelling = $recalc['selling_price'];
                        }

                        $priceChanged = (
                            $existingMap['provider_price'] === null
                            || Currency::cmp((string)$existingMap['provider_price'], (string)$it['cost'], 4) !== 0
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
                                    (string)$it['cost'],
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
                                    (string)$it['cost'],
                                    $calc['cost_base'],
                                    $newSelling,
                                    (int)$existingMap['id'],
                                ]
                            );
                        }
                        $report['existing_updated']++;
                        if ($priceChanged) {
                            $report['prices_updated']++;
                        }
                    }
                } catch (Throwable $itemErr) {
                    $report['errors'][] = "{$cCode}/{$sCode}: " . $itemErr->getMessage();
                }
            }

            // Refresh service-level reference provider_price & selling_price (where auto_price_update = 1)
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

            Database::execute(
                "UPDATE otp_providers SET last_sync_at = NOW(), connection_status = 'connected', last_error = NULL WHERE id = ?",
                [$providerId]
            );

            Database::commit();
        } catch (Throwable $e) {
            Database::rollBack();
            Logger::errorFile('OtpProviderImporter::runImport error: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Database transaction failed during import: ' . $e->getMessage()];
        }

        $report['error_count'] = count($report['errors']);
        return $report;
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
        foreach (($res['items'] ?? []) as $it) {
            $k = strtolower(trim((string)$it['country_code'])) . '|' . strtolower(trim((string)$it['service_code']));
            $priceMap[$k] = $it;
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
            'ok'               => true,
            'mode'             => 'sync_prices',
            'total_found'      => count($res['items'] ?? []),
            'new_imported'     => 0,
            'existing_updated' => 0,
            'prices_updated'   => 0,
            'skipped'          => 0,
            'errors'           => [],
            'error_count'      => 0,
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
                    [$low['cost'], (string)($provider['currency_code'] ?: 'RUB'), $low['selling_price'], $svcId]
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

        $report['error_count'] = count($report['errors']);
        return $report;
    }

    /**
     * Synchronize real number availability using getNumbersStatus() per mapped country.
     * Enforces reasonable API request limits (max 25 countries per call) and marks availability_verified = 1
     * ONLY after receiving an actual verified provider response.
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
                'ok'                 => false,
                'error'              => 'No matching mapped services found for this provider and scope. Import services first.',
            ];
        }

        // Group mappings by provider country code so we make 1 getNumbersStatus call per country
        $byCountry = [];
        foreach ($mappings as $m) {
            $provCountry = trim((string)($m['provider_country_code'] ?: $m['cntry_code']));
            if ($provCountry === '') continue;
            $byCountry[$provCountry][] = $m;
        }

        $adapter = OtpProviderFactory::make($provider);
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

            // Gentle pause between country requests to avoid hitting upstream API rate limits
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
     * Authoritative server-side price formula:
     * 1. Convert raw provider cost from provider currency -> base currency using explicit FX rate.
     * 2. Apply percentage profit margin + fixed profit amount (or fixed selling price if pricing_mode = 'fixed').
     * 3. Enforce min_price / max_price bounds.
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
