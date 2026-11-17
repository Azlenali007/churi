<?php
/**
 * Provider Catalog Fetch, Selection, Category Mapping & Bulk Operations Engine
 * - Separates catalog fetching (cached to storage/cache JSON) from database importing.
 * - Preserves original provider categories, matches existing categories, auto-creates missing ones.
 * - Executes shared-hosting-safe chunked batch imports & local bulk operations with full DB logging.
 */

class CategoryMapper
{
    public const CACHE_DIR = APP_ROOT . '/storage/cache';
    private static bool $schemaChecked = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;
        try {
            $pdo = Database::pdo();
            $pdo->exec("CREATE TABLE IF NOT EXISTS `provider_import_logs` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `provider_id` INT UNSIGNED NULL,
                `admin_id` INT UNSIGNED NULL,
                `operation_type` VARCHAR(60) NOT NULL,
                `total_selected` INT NOT NULL DEFAULT 0,
                `imported_count` INT NOT NULL DEFAULT 0,
                `updated_count` INT NOT NULL DEFAULT 0,
                `skipped_count` INT NOT NULL DEFAULT 0,
                `failed_count` INT NOT NULL DEFAULT 0,
                `categories_created` INT NOT NULL DEFAULT 0,
                `details_json` TEXT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_pil_provider` (`provider_id`),
                KEY `idx_pil_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $hasOpType = $pdo->query("SHOW COLUMNS FROM `provider_import_logs` LIKE 'operation_type'")->fetch();
            if (!$hasOpType) {
                $hasOp = $pdo->query("SHOW COLUMNS FROM `provider_import_logs` LIKE 'operation'")->fetch();
                if ($hasOp) {
                    $pdo->exec("ALTER TABLE `provider_import_logs` CHANGE COLUMN `operation` `operation_type` VARCHAR(60) NOT NULL");
                } else {
                    $pdo->exec("ALTER TABLE `provider_import_logs` ADD COLUMN `operation_type` VARCHAR(60) NOT NULL AFTER `admin_id`");
                }
            }
        } catch (Throwable $e) {
            Logger::errorFile('CategoryMapper::ensureSchema error: ' . $e->getMessage());
        }
    }

    public static function getCacheFilePath(int $providerId): string
    {
        return self::CACHE_DIR . '/provider_fetch_' . $providerId . '.json';
    }

    /**
     * Fetches services from provider API and caches them locally WITHOUT modifying any DB service/category.
     */
    public static function fetchAndCacheCatalog(array $provider, ?int $adminId = null): array
    {
        self::ensureSchema();
        $apiRes = ProviderAPI::getServices($provider);
        if (!$apiRes['ok']) {
            return ['ok' => false, 'error' => $apiRes['error'] ?? 'Failed to fetch services from provider.'];
        }

        $items = $apiRes['data'];
        if (!is_array($items) || empty($items)) {
            return ['ok' => false, 'error' => 'Provider returned an empty or invalid service list.'];
        }

        if (!is_dir(self::CACHE_DIR)) {
            @mkdir(self::CACHE_DIR, 0755, true);
        }

        $providerId = (int)$provider['id'];
        $providerCurrency = strtoupper(trim((string)($provider['currency_code'] ?: Currency::getBaseCode())));
        $margin = Currency::normalize((string)($provider['default_profit_margin'] ?? '30.00'), 2);
        $rateMode = (string)($provider['rate_mode'] ?? 'auto');
        $manualExRate = Currency::normalize((string)($provider['exchange_rate'] ?? '1.0'), 6);

        $normalized = [];
        $seenExt = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $extId = trim((string)($item['service'] ?? ($item['id'] ?? '')));
            $name = Validator::str((string)($item['name'] ?? ''), 250);
            if ($extId === '' || $name === '' || isset($seenExt[$extId])) {
                continue;
            }
            $seenExt[$extId] = true;

            $rawCat = trim((string)($item['category'] ?? ($item['category_name'] ?? '')));
            $provRate = Currency::normalize((string)($item['rate'] ?? '0'), 4);
            $calc = Currency::calculateProviderToBase($provRate, $providerCurrency, $margin, $rateMode, $manualExRate);
            $minQty = max(1, (int)($item['min'] ?? 10));
            $maxQty = max($minQty, (int)($item['max'] ?? 10000));

            $normalized[] = [
                'external_id'       => $extId,
                'name'              => $name,
                'description'       => Validator::str((string)($item['desc'] ?? ($item['description'] ?? '')), 5000),
                'raw_category'      => $rawCat !== '' ? $rawCat : 'Uncategorized',
                'has_raw_category'  => $rawCat !== '',
                'type'              => self::normalizeServiceType((string)($item['type'] ?? 'default')),
                'platform'          => Helpers::detectPlatform($rawCat !== '' ? $rawCat : $name),
                'provider_rate'     => $provRate,
                'provider_currency' => $providerCurrency,
                'cost_base'         => $calc['cost_base'],
                'preview_rate_base' => $calc['selling_rate_base'],
                'min_qty'           => $minQty,
                'max_qty'           => $maxQty,
                'refill_support'    => !empty($item['refill']) ? 1 : 0,
                'cancel_support'    => !empty($item['cancel']) ? 1 : 0,
                'drip_feed_support' => !empty($item['dripfeed']) ? 1 : 0,
                'avg_time'          => Validator::str((string)($item['average_time'] ?? ''), 80),
            ];
        }

        $payload = [
            'provider_id'   => $providerId,
            'provider_name' => (string)$provider['name'],
            'fetched_at'    => date('Y-m-d H:i:s'),
            'total_count'   => count($normalized),
            'items'         => $normalized,
        ];

        $written = @file_put_contents(self::getCacheFilePath($providerId), json_encode($payload, JSON_UNESCAPED_UNICODE));
        if ($written === false) {
            return ['ok' => false, 'error' => 'Could not save fetched catalog cache to storage/cache. Check folder permissions.'];
        }

        self::logImportOperation($providerId, $adminId, 'fetch_catalog', count($normalized), 0, 0, 0, 0, 0, [
            'provider_name' => $provider['name'],
            'total_fetched' => count($normalized),
        ]);

        return [
            'ok'            => true,
            'fetched_at'    => $payload['fetched_at'],
            'total_fetched' => count($normalized),
        ];
    }

    public static function loadCachedCatalog(int $providerId): ?array
    {
        $path = self::getCacheFilePath($providerId);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!$raw) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
            return null;
        }
        return $data;
    }

    /**
     * Enriches cached catalog items against live DB mappings & categories cleanly without mutating DB.
     */
    public static function getEnrichedCatalog(int $providerId): array
    {
        $cache = self::loadCachedCatalog($providerId);
        if (!$cache) {
            return ['ok' => false, 'error' => 'No fetched catalog found. Click "Fetch Services" first.'];
        }

        // Load existing categories for instant lookup
        $catRows = Database::fetchAll("SELECT id, name, slug, platform FROM categories");
        $catByLower = [];
        $catBySlug = [];
        foreach ($catRows as $cr) {
            $catByLower[mb_strtolower(trim((string)$cr['name']))] = $cr;
            $catBySlug[(string)$cr['slug']] = $cr;
        }

        // Load existing imported services for this provider
        $existingMap = [];
        $svcRows = Database::fetchAll(
            "SELECT s.id, s.provider_service_id, s.category_id, c.name AS local_cat_name, s.name, s.provider_rate, s.provider_currency, s.rate, s.min_qty, s.max_qty, s.status, s.refill_support, s.cancel_support
             FROM services s
             JOIN categories c ON c.id = s.category_id
             WHERE s.provider_id = ? AND s.provider_service_id IS NOT NULL AND s.provider_service_id != ''",
            [$providerId]
        );
        foreach ($svcRows as $sr) {
            $existingMap[(string)$sr['provider_service_id']] = $sr;
        }

        $mapRows = Database::fetchAll(
            "SELECT psm.external_service_id, s.id, s.category_id, c.name AS local_cat_name, s.name, s.provider_rate, s.provider_currency, s.rate, s.min_qty, s.max_qty, s.status, s.refill_support, s.cancel_support
             FROM provider_service_mappings psm
             JOIN services s ON s.id = psm.service_id
             JOIN categories c ON c.id = s.category_id
             WHERE psm.provider_id = ?",
            [$providerId]
        );
        foreach ($mapRows as $mr) {
            $existingMap[(string)$mr['external_service_id']] = $mr;
        }

        $categoryStats = [];
        $counts = ['all' => 0, 'new' => 0, 'updated' => 0, 'imported' => 0, 'not_imported' => 0];
        $enriched = [];

        foreach ($cache['items'] as $item) {
            $extId = (string)$item['external_id'];
            $rawCat = (string)$item['raw_category'];
            $lowerCat = mb_strtolower(trim($rawCat));
            $slugCat = Validator::slug($rawCat);

            $mappedCat = $catByLower[$lowerCat] ?? ($catBySlug[$slugCat] ?? null);
            $mappedCategoryName = $mappedCat ? (string)$mappedCat['name'] : $rawCat;
            $mappedCategoryId = $mappedCat ? (int)$mappedCat['id'] : 0;
            $willCreateCategory = !$mappedCat && !empty($item['has_raw_category']);

            $local = $existingMap[$extId] ?? null;
            $importState = 'new';
            $localServiceId = null;
            $localStatus = null;
            $localSellingRate = null;
            $changeReasons = [];

            if ($local) {
                $localServiceId = (int)$local['id'];
                $localStatus = (string)$local['status'];
                $localSellingRate = (string)$local['rate'];
                $importState = 'imported';

                if (Currency::cmp((string)$local['provider_rate'], (string)$item['provider_rate'], 4) !== 0) {
                    $changeReasons[] = 'Rate changed';
                }
                if ((int)$local['min_qty'] !== (int)$item['min_qty'] || (int)$local['max_qty'] !== (int)$item['max_qty']) {
                    $changeReasons[] = 'Min/Max changed';
                }
                if (trim((string)$local['name']) !== trim((string)$item['name'])) {
                    $changeReasons[] = 'Name changed';
                }
                if (!empty($changeReasons)) {
                    $importState = 'updated';
                }
            }

            $counts['all']++;
            if ($importState === 'new') {
                $counts['new']++;
                $counts['not_imported']++;
            } elseif ($importState === 'updated') {
                $counts['updated']++;
                $counts['imported']++;
            } else {
                $counts['imported']++;
            }

            if (!isset($categoryStats[$rawCat])) {
                $categoryStats[$rawCat] = [
                    'name'           => $rawCat,
                    'total'          => 0,
                    'new'            => 0,
                    'updated'        => 0,
                    'imported'       => 0,
                    'mapped_name'    => $mappedCategoryName,
                    'will_create'    => $willCreateCategory,
                ];
            }
            $categoryStats[$rawCat]['total']++;
            if ($importState === 'new') {
                $categoryStats[$rawCat]['new']++;
            } elseif ($importState === 'updated') {
                $categoryStats[$rawCat]['updated']++;
                $categoryStats[$rawCat]['imported']++;
            } else {
                $categoryStats[$rawCat]['imported']++;
            }

            $enriched[] = array_merge($item, [
                'import_state'         => $importState,
                'change_reasons'       => $changeReasons,
                'local_service_id'     => $localServiceId,
                'local_status'         => $localStatus,
                'local_selling_rate'   => $localSellingRate,
                'mapped_category_id'   => $mappedCategoryId,
                'mapped_category_name' => $mappedCategoryName,
                'will_create_category' => $willCreateCategory,
            ]);
        }

        return [
            'ok'             => true,
            'provider_id'    => $providerId,
            'provider_name'  => $cache['provider_name'] ?? '',
            'fetched_at'     => $cache['fetched_at'] ?? '',
            'counts'         => $counts,
            'categories'     => array_values($categoryStats),
            'items'          => $enriched,
        ];
    }

    /**
     * Filters and paginates the enriched catalog for the workbench table.
     */
    public static function queryFetchedCatalog(
        int $providerId,
        string $search = '',
        string $providerCategory = '',
        string $statusFilter = 'all',
        int $page = 1,
        int $perPage = 25
    ): array {
        $enriched = self::getEnrichedCatalog($providerId);
        if (!$enriched['ok']) {
            return $enriched;
        }

        $filtered = self::filterItems($enriched['items'], $search, $providerCategory, $statusFilter);
        $totalFiltered = count($filtered);
        $perPage = max(10, min(100, $perPage));
        $totalPages = max(1, (int)ceil($totalFiltered / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($filtered, $offset, $perPage);

        return [
            'ok'             => true,
            'fetched_at'     => $enriched['fetched_at'],
            'counts'         => $enriched['counts'],
            'categories'     => $enriched['categories'],
            'total_filtered' => $totalFiltered,
            'page'           => $page,
            'per_page'       => $perPage,
            'total_pages'    => $totalPages,
            'items'          => $slice,
        ];
    }

    /**
     * Returns all external IDs matching filter criteria (or a list of provider categories).
     */
    public static function getFilteredExternalIds(
        int $providerId,
        string $search = '',
        string $providerCategory = '',
        string $statusFilter = 'all',
        array $providerCategories = []
    ): array {
        $enriched = self::getEnrichedCatalog($providerId);
        if (!$enriched['ok']) {
            return $enriched;
        }

        $items = self::filterItems($enriched['items'], $search, $providerCategory, $statusFilter);
        if (!empty($providerCategories)) {
            $catSet = array_flip($providerCategories);
            $items = array_values(array_filter($items, fn(array $it) => isset($catSet[$it['raw_category']])));
        }

        $ids = array_values(array_map(fn(array $it) => (string)$it['external_id'], $items));
        return ['ok' => true, 'ids' => $ids, 'count' => count($ids)];
    }

    /**
     * Generates a pre-import summary preview for the selected external IDs.
     */
    public static function previewSelection(
        array $provider,
        array $externalIds,
        ?string $overrideMargin = null,
        ?int $assignCategoryId = null,
        bool $skipExisting = false
    ): array {
        $providerId = (int)$provider['id'];
        $enriched = self::getEnrichedCatalog($providerId);
        if (!$enriched['ok']) {
            return $enriched;
        }

        $idSet = array_flip(array_map('strval', $externalIds));
        $margin = ($overrideMargin !== null && trim($overrideMargin) !== '')
            ? Currency::normalize($overrideMargin, 2)
            : Currency::normalize((string)$provider['default_profit_margin'], 2);
        $providerCurrency = strtoupper(trim((string)($provider['currency_code'] ?: Currency::getBaseCode())));
        $rateMode = (string)($provider['rate_mode'] ?? 'auto');
        $manualExRate = Currency::normalize((string)($provider['exchange_rate'] ?? '1.0'), 6);

        $assignedCatName = null;
        if ($assignCategoryId && $assignCategoryId > 0) {
            $assignedCatName = (string)Database::fetchColumn("SELECT name FROM categories WHERE id = ? LIMIT 1", [$assignCategoryId]);
        }

        $summary = [
            'selected_total'     => 0,
            'new_count'          => 0,
            'existing_count'     => 0,
            'will_skip_count'    => 0,
            'new_categories'     => [],
            'sample_items'       => [],
            'margin_percent'     => $margin,
            'assigned_category'  => $assignedCatName,
        ];

        $newCatsMap = [];
        foreach ($enriched['items'] as $it) {
            if (!isset($idSet[(string)$it['external_id']])) {
                continue;
            }
            $summary['selected_total']++;
            $isExisting = ($it['import_state'] !== 'new');
            if ($isExisting) {
                $summary['existing_count']++;
                if ($skipExisting) {
                    $summary['will_skip_count']++;
                }
            } else {
                $summary['new_count']++;
            }
            if (!$assignedCatName && !empty($it['will_create_category'])) {
                $newCatsMap[$it['raw_category']] = true;
            }
            if (count($summary['sample_items']) < 12) {
                $calc = Currency::calculateProviderToBase((string)$it['provider_rate'], $providerCurrency, $margin, $rateMode, $manualExRate);
                $summary['sample_items'][] = [
                    'external_id'   => $it['external_id'],
                    'name'          => $it['name'],
                    'category'      => $assignedCatName ?: $it['mapped_category_name'],
                    'import_state'  => $it['import_state'],
                    'provider_rate' => Currency::formatRaw((string)$it['provider_rate'], $providerCurrency, 2, true),
                    'selling_rate'  => Currency::formatBase($calc['selling_rate_base'], 2, true),
                ];
            }
        }
        $summary['new_categories'] = array_keys($newCatsMap);

        return ['ok' => true, 'preview' => $summary];
    }

    /**
     * Imports or updates ONLY the provided chunk of external IDs from the cached catalog.
     */
    public static function executeBulkImportBatch(
        array $provider,
        array $externalIds,
        array $options = [],
        ?int $adminId = null
    ): array {
        self::ensureSchema();
        $providerId = (int)$provider['id'];
        $cache = self::loadCachedCatalog($providerId);
        if (!$cache) {
            return ['ok' => false, 'error' => 'Cached provider catalog not found. Please click Fetch Services again.'];
        }

        $duplicateMode = ($options['duplicate_mode'] ?? 'update') === 'skip' ? 'skip' : 'update';
        $onlyExisting = !empty($options['only_existing']);
        $overrideMargin = isset($options['margin_override']) && trim((string)$options['margin_override']) !== ''
            ? Currency::normalize((string)$options['margin_override'], 2)
            : null;
        $margin = $overrideMargin ?? Currency::normalize((string)$provider['default_profit_margin'], 2);
        $assignCategoryId = !empty($options['assign_category_id']) ? (int)$options['assign_category_id'] : 0;
        $fallbackCategoryId = !empty($options['fallback_category_id']) ? (int)$options['fallback_category_id'] : 1;
        $targetStatus = ($options['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $fixedSellingPrice = isset($options['fixed_selling_price']) && trim((string)$options['fixed_selling_price']) !== ''
            ? Currency::normalize((string)$options['fixed_selling_price'], 4)
            : null;

        // Validate fallback category
        $fallbackCat = Database::fetch("SELECT id, platform FROM categories WHERE id = ? LIMIT 1", [$fallbackCategoryId]);
        if (!$fallbackCat) {
            $fallbackCat = Database::fetch("SELECT id, platform FROM categories ORDER BY id ASC LIMIT 1");
            $fallbackCategoryId = $fallbackCat ? (int)$fallbackCat['id'] : 0;
        }

        // Validate forced assign category if set
        $forcedCat = null;
        if ($assignCategoryId > 0) {
            $forcedCat = Database::fetch("SELECT id, platform FROM categories WHERE id = ? LIMIT 1", [$assignCategoryId]);
        }

        $providerCurrency = strtoupper(trim((string)($provider['currency_code'] ?: Currency::getBaseCode())));
        $rateMode = (string)($provider['rate_mode'] ?? 'auto');
        $manualExchangeRate = Currency::normalize((string)($provider['exchange_rate'] ?? '1'), 6);

        // Build fast lookup index of cached items
        $catalogByExt = [];
        foreach ($cache['items'] as $ci) {
            $catalogByExt[(string)$ci['external_id']] = $ci;
        }

        $stats = [
            'processed'          => 0,
            'imported'           => 0,
            'updated'            => 0,
            'skipped'            => 0,
            'failed'             => 0,
            'categories_created' => 0,
            'local_service_ids'  => [],
        ];

        $createdCatNames = [];

        foreach ($externalIds as $rawExtId) {
            $extId = trim((string)$rawExtId);
            if ($extId === '' || !isset($catalogByExt[$extId])) {
                $stats['failed']++;
                continue;
            }
            $item = $catalogByExt[$extId];
            $stats['processed']++;

            // Resolve category
            $rawCat = !empty($item['has_raw_category']) ? (string)$item['raw_category'] : '';
            if ($forcedCat) {
                $categoryId = (int)$forcedCat['id'];
                $platform = (string)$forcedCat['platform'];
            } elseif ($rawCat === '') {
                $categoryId = $fallbackCategoryId;
                $platform = Helpers::detectPlatform((string)$item['name']);
            } else {
                $resolved = self::resolveOrCreateCategory($rawCat);
                $categoryId = (int)$resolved['id'];
                $platform = (string)$resolved['platform'];
                if ($resolved['created'] && !isset($createdCatNames[$categoryId])) {
                    $createdCatNames[$categoryId] = true;
                    $stats['categories_created']++;
                }
            }

            $providerRate = Currency::normalize((string)$item['provider_rate'], 4);
            $calc = Currency::calculateProviderToBase($providerRate, $providerCurrency, $margin, $rateMode, $manualExchangeRate);
            $sellingRate = ($fixedSellingPrice !== null && Currency::cmp($fixedSellingPrice, '0', 4) > 0)
                ? $fixedSellingPrice
                : $calc['selling_rate_base'];

            $existingMap = Database::fetch(
                "SELECT psm.service_id FROM provider_service_mappings psm WHERE psm.provider_id = ? AND psm.external_service_id = ? LIMIT 1",
                [$providerId, $extId]
            );
            if (!$existingMap) {
                $existingSvc = Database::fetch(
                    "SELECT id FROM services WHERE provider_id = ? AND provider_service_id = ? LIMIT 1",
                    [$providerId, $extId]
                );
                if ($existingSvc) {
                    $existingMap = ['service_id' => $existingSvc['id']];
                }
            }

            if ($existingMap) {
                $serviceId = (int)$existingMap['service_id'];
                if ($duplicateMode === 'skip') {
                    $stats['skipped']++;
                    continue;
                }
                Database::execute(
                    "UPDATE services
                     SET category_id = ?, provider_category_raw = ?, name = ?, description = ?,
                         type = ?, platform = ?, icon_key = ?, provider_rate = ?, provider_currency = ?, rate = ?,
                         min_qty = ?, max_qty = ?, drip_feed_support = ?, refill_support = ?,
                         cancel_support = ?, avg_time = COALESCE(NULLIF(?, ''), avg_time), status = ?
                     WHERE id = ?",
                    [
                        $categoryId,
                        $rawCat !== '' ? $rawCat : null,
                        (string)$item['name'],
                        (string)$item['description'],
                        (string)$item['type'],
                        $platform,
                        $platform,
                        $providerRate,
                        $providerCurrency,
                        $sellingRate,
                        (int)$item['min_qty'],
                        (int)$item['max_qty'],
                        (int)$item['drip_feed_support'],
                        (int)$item['refill_support'],
                        (int)$item['cancel_support'],
                        (string)$item['avg_time'],
                        $targetStatus,
                        $serviceId,
                    ]
                );
                self::upsertMapping($providerId, $serviceId, $extId, $rawCat, $providerRate, $providerCurrency);
                $stats['updated']++;
                $stats['local_service_ids'][] = $serviceId;
            } else {
                if ($onlyExisting) {
                    $stats['skipped']++;
                    continue;
                }
                $serviceId = Database::insert(
                    "INSERT INTO services
                     (category_id, provider_id, provider_service_id, provider_category_raw, name, description,
                      type, platform, icon_key, provider_rate, provider_currency, rate, min_qty, max_qty,
                      drip_feed_support, refill_support, cancel_support, avg_time, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $categoryId,
                        $providerId,
                        $extId,
                        $rawCat !== '' ? $rawCat : null,
                        (string)$item['name'],
                        (string)$item['description'],
                        (string)$item['type'],
                        $platform,
                        $platform,
                        $providerRate,
                        $providerCurrency,
                        $sellingRate,
                        (int)$item['min_qty'],
                        (int)$item['max_qty'],
                        (int)$item['drip_feed_support'],
                        (int)$item['refill_support'],
                        (int)$item['cancel_support'],
                        (string)$item['avg_time'] ?: null,
                        $targetStatus,
                    ]
                );
                self::upsertMapping($providerId, $serviceId, $extId, $rawCat, $providerRate, $providerCurrency);
                $stats['imported']++;
                $stats['local_service_ids'][] = $serviceId;
            }
        }

        Database::execute("UPDATE providers SET last_sync_at = NOW() WHERE id = ?", [$providerId]);
        return ['ok' => true, 'stats' => $stats];
    }

    /**
     * Executes bulk operations on already-imported local services (either by local service IDs or provider + external IDs).
     */
    public static function bulkOperateLocalServices(
        string $operation,
        array $localServiceIds,
        array $params = [],
        ?int $providerId = null,
        ?int $adminId = null
    ): array {
        self::ensureSchema();
        $ids = array_values(array_unique(array_filter(array_map('intval', $localServiceIds), fn($v) => $v > 0)));
        if (empty($ids)) {
            return ['ok' => false, 'error' => 'No imported local services selected for this operation.'];
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $updated = 0;
        $skipped = 0;
        $deleted = 0;

        if ($operation === 'enable' || ($operation === 'set_status' && ($params['status'] ?? '') === 'active')) {
            $updated = Database::execute("UPDATE services SET status = 'active' WHERE id IN ($ph)", $ids);
        } elseif ($operation === 'disable' || ($operation === 'set_status' && ($params['status'] ?? '') === 'inactive')) {
            $updated = Database::execute("UPDATE services SET status = 'inactive' WHERE id IN ($ph)", $ids);
        } elseif ($operation === 'assign_category') {
            $catId = (int)($params['category_id'] ?? 0);
            $cat = Database::fetch("SELECT id, platform FROM categories WHERE id = ? LIMIT 1", [$catId]);
            if (!$cat) {
                return ['ok' => false, 'error' => 'Target platform category not found.'];
            }
            $updated = Database::execute(
                "UPDATE services SET category_id = ?, platform = ?, icon_key = ? WHERE id IN ($ph)",
                array_merge([(int)$cat['id'], (string)$cat['platform'], (string)$cat['platform']], $ids)
            );
        } elseif ($operation === 'update_margin') {
            $marginVal = Currency::normalize((string)($params['margin_percent'] ?? '0'), 2);
            if (Currency::cmp($marginVal, '0', 2) < 0) {
                return ['ok' => false, 'error' => 'Profit margin percentage cannot be negative.'];
            }
            $rows = Database::fetchAll(
                "SELECT s.id, s.provider_id, s.provider_rate, s.provider_currency, p.currency_code AS prov_currency, p.rate_mode, p.exchange_rate
                 FROM services s
                 LEFT JOIN providers p ON p.id = s.provider_id
                 WHERE s.id IN ($ph)",
                $ids
            );
            foreach ($rows as $row) {
                if (Currency::cmp((string)$row['provider_rate'], '0', 4) <= 0) {
                    $skipped++;
                    continue;
                }
                $pCurr = strtoupper(trim((string)($row['provider_currency'] ?: ($row['prov_currency'] ?: Currency::getBaseCode()))));
                $rMode = (string)($row['rate_mode'] ?? 'auto');
                $exRate = (string)($row['exchange_rate'] ?? '1.000000');
                $calc = Currency::calculateProviderToBase((string)$row['provider_rate'], $pCurr, $marginVal, $rMode, $exRate);
                Database::execute("UPDATE services SET rate = ? WHERE id = ?", [$calc['selling_rate_base'], (int)$row['id']]);
                $updated++;
            }
        } elseif ($operation === 'update_selling_price') {
            $priceMode = (string)($params['price_mode'] ?? 'fixed');
            $priceValue = Currency::normalize((string)($params['price_value'] ?? '0'), 4);
            if ($priceMode === 'fixed') {
                if (Currency::cmp($priceValue, '0', 4) <= 0) {
                    return ['ok' => false, 'error' => 'Selling price must be greater than zero.'];
                }
                $updated = Database::execute("UPDATE services SET rate = ? WHERE id IN ($ph)", array_merge([$priceValue], $ids));
            } elseif ($priceMode === 'percent_adjust') {
                $factor = Currency::add('1', Currency::div($priceValue, '100', 6), 6);
                if (Currency::cmp($factor, '0', 6) <= 0) {
                    return ['ok' => false, 'error' => 'Resulting price multiplier must be positive.'];
                }
                $rows = Database::fetchAll("SELECT id, rate FROM services WHERE id IN ($ph)", $ids);
                foreach ($rows as $row) {
                    $newRate = Currency::round(Currency::mul((string)$row['rate'], $factor, 6), 4);
                    if (Currency::cmp($newRate, '0.0001', 4) < 0) {
                        $newRate = '0.0001';
                    }
                    Database::execute("UPDATE services SET rate = ? WHERE id = ?", [$newRate, (int)$row['id']]);
                    $updated++;
                }
            } else {
                return ['ok' => false, 'error' => 'Invalid price update mode.'];
            }
        } elseif ($operation === 'delete') {
            foreach ($ids as $sid) {
                $orderCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM orders WHERE service_id = ?", [$sid]);
                if ($orderCount > 0) {
                    Database::execute("UPDATE services SET status = 'inactive' WHERE id = ?", [$sid]);
                    $skipped++;
                } else {
                    try {
                        Database::execute("DELETE FROM provider_service_mappings WHERE service_id = ?", [$sid]);
                        Database::execute("DELETE FROM flash_sale_services WHERE service_id = ?", [$sid]);
                        Database::execute("DELETE FROM child_panel_services WHERE parent_service_id = ?", [$sid]);
                        Database::execute("DELETE FROM services WHERE id = ?", [$sid]);
                        $deleted++;
                    } catch (Throwable $e) {
                        Database::execute("UPDATE services SET status = 'inactive' WHERE id = ?", [$sid]);
                        $skipped++;
                    }
                }
            }
        } else {
            return ['ok' => false, 'error' => 'Unsupported bulk operation.'];
        }

        self::logImportOperation($providerId, $adminId, 'bulk_' . $operation, count($ids), 0, $updated, $skipped, 0, 0, [
            'deleted' => $deleted,
            'params'  => $params,
        ]);

        return [
            'ok'      => true,
            'total'   => count($ids),
            'updated' => $updated,
            'deleted' => $deleted,
            'skipped' => $skipped,
        ];
    }

    /**
     * Resolves an array of provider external_ids to their local imported service IDs for this provider.
     */
    public static function resolveLocalServiceIdsForExternal(int $providerId, array $externalIds): array
    {
        $clean = array_values(array_unique(array_filter(array_map('strval', $externalIds), fn($v) => $v !== '')));
        if (empty($clean)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($clean), '?'));
        $rows = Database::fetchAll(
            "SELECT id FROM services WHERE provider_id = ? AND provider_service_id IN ($ph)",
            array_merge([$providerId], $clean)
        );
        $ids = array_map(fn($r) => (int)$r['id'], $rows);

        $mapRows = Database::fetchAll(
            "SELECT service_id FROM provider_service_mappings WHERE provider_id = ? AND external_service_id IN ($ph)",
            array_merge([$providerId], $clean)
        );
        foreach ($mapRows as $mr) {
            $ids[] = (int)$mr['service_id'];
        }
        return array_values(array_unique(array_filter($ids, fn($v) => $v > 0)));
    }

    public static function logImportOperation(
        ?int $providerId,
        ?int $adminId,
        string $operationType,
        int $totalSelected,
        int $importedCount,
        int $updatedCount,
        int $skippedCount,
        int $failedCount = 0,
        int $categoriesCreated = 0,
        array $details = []
    ): void {
        self::ensureSchema();
        try {
            Database::insert(
                "INSERT INTO provider_import_logs
                 (provider_id, admin_id, operation_type, total_selected, imported_count, updated_count, skipped_count, failed_count, categories_created, details_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $providerId ?: null,
                    $adminId ?: null,
                    Validator::str($operationType, 60),
                    $totalSelected,
                    $importedCount,
                    $updatedCount,
                    $skippedCount,
                    $failedCount,
                    $categoriesCreated,
                    !empty($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
                ]
            );
        } catch (Throwable $e) {
            Logger::errorFile('CategoryMapper::logImportOperation error: ' . $e->getMessage());
        }
    }

    /**
     * Direct import helper retained for backwards compatibility when specific IDs or full batch import is triggered.
     */
    public static function importFromProvider(
        array $provider,
        string $duplicateMode = 'update',
        ?string $overrideMargin = null,
        int $fallbackCategoryId = 1,
        ?array $selectedExternalIds = null
    ): array {
        $fetchRes = self::fetchAndCacheCatalog($provider);
        if (!$fetchRes['ok']) {
            return $fetchRes;
        }
        $cache = self::loadCachedCatalog((int)$provider['id']);
        $allIds = array_map(fn($it) => (string)$it['external_id'], $cache['items'] ?? []);
        $targetIds = $selectedExternalIds !== null ? $selectedExternalIds : $allIds;

        $batchRes = self::executeBulkImportBatch($provider, $targetIds, [
            'duplicate_mode'       => $duplicateMode,
            'margin_override'      => $overrideMargin,
            'fallback_category_id' => $fallbackCategoryId,
        ]);
        if (!$batchRes['ok']) {
            return $batchRes;
        }
        $st = $batchRes['stats'];
        self::logImportOperation((int)$provider['id'], null, 'direct_import', count($targetIds), $st['imported'], $st['updated'], $st['skipped'], $st['failed'], $st['categories_created']);
        return [
            'ok'    => true,
            'stats' => [
                'total_fetched'      => count($allIds),
                'imported'           => $st['imported'],
                'updated'            => $st['updated'],
                'skipped'            => $st['skipped'],
                'categories_created' => $st['categories_created'],
                'categories_reused'  => 0,
                'used_fallback_cat'  => 0,
                'missing_cat_warning'=> false,
            ],
        ];
    }

    /**
     * Matches existing category by case-insensitive name or slug, or creates missing category.
     */
    public static function resolveOrCreateCategory(string $rawCategoryName): array
    {
        $cleanName = Validator::str($rawCategoryName, 145);
        $slug = Validator::slug($cleanName);
        $platform = Helpers::detectPlatform($cleanName);

        $existing = Database::fetch(
            "SELECT id, platform FROM categories WHERE LOWER(name) = LOWER(?) OR slug = ? LIMIT 1",
            [$cleanName, $slug]
        );
        if ($existing) {
            return ['id' => (int)$existing['id'], 'platform' => $existing['platform'], 'created' => false];
        }

        $uniqueSlug = $slug;
        $counter = 1;
        while (Database::fetch("SELECT id FROM categories WHERE slug = ? LIMIT 1", [$uniqueSlug])) {
            $uniqueSlug = $slug . '-' . (++$counter);
        }

        $maxSort = (int)Database::fetchColumn("SELECT COALESCE(MAX(sort_order), 0) FROM categories WHERE sort_order < 900");
        $newId = Database::insert(
            "INSERT INTO categories (name, slug, platform, icon_svg_key, sort_order, status)
             VALUES (?, ?, ?, ?, ?, 'active')",
            [$cleanName, $uniqueSlug, $platform, $platform, $maxSort + 1]
        );

        return ['id' => $newId, 'platform' => $platform, 'created' => true];
    }

    private static function filterItems(array $items, string $search, string $providerCategory, string $statusFilter): array
    {
        $search = mb_strtolower(trim($search));
        $providerCategory = trim($providerCategory);
        $statusFilter = trim($statusFilter);

        return array_values(array_filter($items, function (array $it) use ($search, $providerCategory, $statusFilter): bool {
            if ($providerCategory !== '' && $it['raw_category'] !== $providerCategory) {
                return false;
            }
            if ($statusFilter === 'new' || $statusFilter === 'not_imported') {
                if ($it['import_state'] !== 'new') {
                    return false;
                }
            } elseif ($statusFilter === 'updated') {
                if ($it['import_state'] !== 'updated') {
                    return false;
                }
            } elseif ($statusFilter === 'imported') {
                if ($it['import_state'] === 'new') {
                    return false;
                }
            }
            if ($search !== '') {
                $hayName = mb_strtolower((string)$it['name']);
                $hayCat = mb_strtolower((string)$it['raw_category']);
                $hayId = mb_strtolower((string)$it['external_id']);
                if (!str_contains($hayName, $search) && !str_contains($hayId, $search) && !str_contains($hayCat, $search)) {
                    return false;
                }
            }
            return true;
        }));
    }

    private static function upsertMapping(int $providerId, int $serviceId, string $extId, string $rawCat, string $extRate, string $currency): void
    {
        Database::execute(
            "INSERT INTO provider_service_mappings (provider_id, service_id, external_service_id, external_category_name, external_rate, external_currency, last_synced_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               service_id = VALUES(service_id),
               external_category_name = VALUES(external_category_name),
               external_rate = VALUES(external_rate),
               external_currency = VALUES(external_currency),
               last_synced_at = NOW()",
            [$providerId, $serviceId, $extId, $rawCat !== '' ? $rawCat : null, $extRate, $currency]
        );
    }

    private static function normalizeServiceType(string $raw): string
    {
        $t = strtolower(trim($raw));
        if (str_contains($t, 'comment')) return 'custom_comments';
        if (str_contains($t, 'package')) return 'package';
        if (str_contains($t, 'drip')) return 'drip_feed';
        if (str_contains($t, 'sub')) return 'subscriptions';
        return 'default';
    }
}
