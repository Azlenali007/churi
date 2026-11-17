<?php
/**
 * Critical Provider Category Import & Auto-Mapping Engine
 * Preserves original provider categories, matches existing categories,
 * auto-creates missing ones, and supports Skip & Update duplicate modes.
 */

class CategoryMapper
{
    /**
     * Imports services from a provider with automatic category preservation & mapping.
     *
     * @param array $provider Provider DB row
     * @param string $duplicateMode 'skip' | 'update'
     * @param float|null $overrideMargin Optional margin percentage override
     * @param int $fallbackCategoryId Fallback category ID when provider omits category
     * @param array|null $selectedExternalIds Optional filter of specific external service IDs
     */
    public static function importFromProvider(
        array $provider,
        string $duplicateMode = 'update',
        ?string $overrideMargin = null,
        int $fallbackCategoryId = 1,
        ?array $selectedExternalIds = null
    ): array {
        $apiRes = ProviderAPI::getServices($provider);
        if (!$apiRes['ok']) {
            return ['ok' => false, 'error' => $apiRes['error'] ?? 'Failed to fetch services from provider.'];
        }

        $items = $apiRes['data'];
        if (!is_array($items) || empty($items)) {
            return ['ok' => false, 'error' => 'Provider returned an empty or invalid service list.'];
        }

        // Verify fallback category exists
        $fallbackCat = Database::fetch("SELECT id FROM categories WHERE id = ? LIMIT 1", [$fallbackCategoryId]);
        if (!$fallbackCat) {
            $fallbackCat = Database::fetch("SELECT id FROM categories ORDER BY id ASC LIMIT 1");
            $fallbackCategoryId = $fallbackCat ? (int)$fallbackCat['id'] : 0;
        }

        $margin = $overrideMargin !== null
            ? Currency::normalize($overrideMargin, 2)
            : Currency::normalize((string)$provider['default_profit_margin'], 2);
        $providerCurrency = strtoupper(trim((string)($provider['currency_code'] ?: Currency::getBaseCode())));
        $rateMode = (string)($provider['rate_mode'] ?? 'auto');
        $manualExchangeRate = Currency::normalize((string)($provider['exchange_rate'] ?? '1'), 6);
        $providerId = (int)$provider['id'];

        $stats = [
            'total_fetched'      => count($items),
            'imported'           => 0,
            'updated'            => 0,
            'skipped'            => 0,
            'categories_created' => 0,
            'categories_reused'  => 0,
            'used_fallback_cat'  => 0,
            'missing_cat_warning'=> false,
        ];

        $reusedCategoryCache = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $extId = trim((string)($item['service'] ?? ($item['id'] ?? '')));
            $name = Validator::str((string)($item['name'] ?? ''), 250);
            if ($extId === '' || $name === '') {
                continue;
            }

            if ($selectedExternalIds !== null && !in_array($extId, $selectedExternalIds, true)) {
                continue;
            }

            // Read original provider category honestly
            $rawCat = trim((string)($item['category'] ?? ($item['category_name'] ?? '')));
            if ($rawCat === '') {
                $stats['missing_cat_warning'] = true;
                $stats['used_fallback_cat']++;
                $categoryId = $fallbackCategoryId;
                $platform = Helpers::detectPlatform($name);
            } else {
                $resolved = self::resolveOrCreateCategory($rawCat);
                $categoryId = $resolved['id'];
                $platform = $resolved['platform'];
                if ($resolved['created']) {
                    $stats['categories_created']++;
                } elseif (!isset($reusedCategoryCache[$categoryId])) {
                    $reusedCategoryCache[$categoryId] = true;
                    $stats['categories_reused']++;
                }
            }

            $providerRate = Currency::normalize((string)($item['rate'] ?? '0'), 4);
            $calc = Currency::calculateProviderToBase(
                $providerRate,
                $providerCurrency,
                $margin,
                $rateMode,
                $manualExchangeRate
            );
            $sellingRate = $calc['selling_rate_base'];
            $minQty = max(1, (int)($item['min'] ?? 10));
            $maxQty = max($minQty, (int)($item['max'] ?? 10000));
            $description = Validator::str((string)($item['desc'] ?? ($item['description'] ?? '')), 5000);
            $type = self::normalizeServiceType((string)($item['type'] ?? 'default'));
            $refill = !empty($item['refill']) ? 1 : 0;
            $cancel = !empty($item['cancel']) ? 1 : 0;
            $dripfeed = !empty($item['dripfeed']) ? 1 : 0;
            $avgTime = Validator::str((string)($item['average_time'] ?? ''), 80);

            // Duplicate check via provider_service_mappings or services table
            $existingMap = Database::fetch(
                "SELECT psm.id AS map_id, psm.service_id
                 FROM provider_service_mappings psm
                 WHERE psm.provider_id = ? AND psm.external_service_id = ? LIMIT 1",
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
                if ($duplicateMode === 'skip') {
                    $stats['skipped']++;
                    continue;
                }

                // Update mode: updates category, rate, provider_currency, and metadata
                $serviceId = (int)$existingMap['service_id'];
                Database::execute(
                    "UPDATE services
                     SET category_id = ?, provider_category_raw = ?, name = ?, description = ?,
                         type = ?, platform = ?, icon_key = ?, provider_rate = ?, provider_currency = ?, rate = ?,
                         min_qty = ?, max_qty = ?, drip_feed_support = ?, refill_support = ?,
                         cancel_support = ?, avg_time = COALESCE(NULLIF(?, ''), avg_time)
                     WHERE id = ?",
                    [
                        $categoryId, $rawCat !== '' ? $rawCat : null, $name, $description,
                        $type, $platform, $platform, $providerRate, $providerCurrency, $sellingRate,
                        $minQty, $maxQty, $dripfeed, $refill, $cancel, $avgTime, $serviceId
                    ]
                );
                self::upsertMapping($providerId, $serviceId, $extId, $rawCat, $providerRate, $providerCurrency);
                $stats['updated']++;
            } else {
                $serviceId = Database::insert(
                    "INSERT INTO services
                     (category_id, provider_id, provider_service_id, provider_category_raw, name, description,
                      type, platform, icon_key, provider_rate, provider_currency, rate, min_qty, max_qty,
                      drip_feed_support, refill_support, cancel_support, avg_time, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')",
                    [
                        $categoryId, $providerId, $extId, $rawCat !== '' ? $rawCat : null,
                        $name, $description, $type, $platform, $platform,
                        $providerRate, $providerCurrency, $sellingRate, $minQty, $maxQty,
                        $dripfeed, $refill, $cancel, $avgTime ?: null
                    ]
                );
                self::upsertMapping($providerId, $serviceId, $extId, $rawCat, $providerRate, $providerCurrency);
                $stats['imported']++;
            }
        }

        Database::execute("UPDATE providers SET last_sync_at = NOW() WHERE id = ?", [$providerId]);
        return ['ok' => true, 'stats' => $stats];
    }

    /**
     * Matches existing category by case-insensitive name or slug, or creates missing category.
     */
    public static function resolveOrCreateCategory(string $rawCategoryName): array
    {
        $cleanName = Validator::str($rawCategoryName, 145);
        $slug = Validator::slug($cleanName);
        $platform = Helpers::detectPlatform($cleanName);

        // 1. Exact case-insensitive name match
        $existing = Database::fetch(
            "SELECT id, platform FROM categories WHERE LOWER(name) = LOWER(?) OR slug = ? LIMIT 1",
            [$cleanName, $slug]
        );
        if ($existing) {
            return ['id' => (int)$existing['id'], 'platform' => $existing['platform'], 'created' => false];
        }

        // Ensure unique slug on insert
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
