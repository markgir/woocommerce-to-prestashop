<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/DebugLogger.php';
require_once __DIR__ . '/FieldMapper.php';
require_once __DIR__ . '/WooCommerce/WCImporter.php';
require_once __DIR__ . '/PrestaShop/PSExporter.php';

/**
 * Migrator orchestrates the full WooCommerce → PrestaShop migration.
 *
 * Progress is stored as a JSON file under migration_progress/ so that
 * the migration can be resumed after a failure or page reload.
 */
class Migrator
{
    private WCImporter  $wc;
    private PSExporter  $ps;
    private DebugLogger $log;
    private string      $progressFile;

    /** Migration state (loaded from / saved to JSON). */
    private array $state;

    /** Batch size for product migration. */
    private int $batchSize;

    public function __construct(
        WCImporter  $wc,
        PSExporter  $ps,
        DebugLogger $log,
        string      $sessionId,
        int         $batchSize = 20
    ) {
        $this->wc          = $wc;
        $this->ps          = $ps;
        $this->log         = $log;
        $this->batchSize   = $batchSize;
        $dir               = __DIR__ . '/../migration_progress';
        $this->progressFile= $dir . '/progress_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) . '.json';

        $this->loadState();
    }

    // -----------------------------------------------------------------------
    // State persistence
    // -----------------------------------------------------------------------

    private function loadState(): void
    {
        if (file_exists($this->progressFile)) {
            $data        = json_decode(file_get_contents($this->progressFile), true) ?? [];
            $this->state = $data;
        } else {
            $this->state = $this->defaultState();
        }
    }

    private function saveState(): void
    {
        file_put_contents(
            $this->progressFile,
            json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    private function defaultState(): array
    {
        return [
            'status'            => 'idle',     // idle | running | paused | completed | error
            'step'              => '',          // categories | attributes | products | done
            'options'           => [],
            // Category migration
            'total_categories'  => 0,
            'done_categories'   => 0,
            'category_id_map'   => [],          // WC term_id (string) → PS id_category
            // Attribute migration
            'total_attrs'       => 0,
            'done_attrs'        => 0,
            'attr_group_map'    => [],          // WC attribute_name → PS id_attribute_group
            'attr_value_map'    => [],          // "attrName:termSlug" → PS id_attribute
            // Product migration
            'total_products'    => 0,
            'done_products'     => 0,
            'last_wc_product_id'=> 0,
            'product_id_map'    => [],          // WC post ID (string) → PS id_product
            // Errors
            'errors'            => [],
        ];
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /** Return the current progress state. */
    public function getProgress(): array
    {
        return $this->state;
    }

    /** Reset state and log, ready to start fresh. */
    public function reset(): void
    {
        $this->state = $this->defaultState();
        $this->saveState();
        $this->log->clearLog();
    }

    /**
     * Run one migration step (or resume where we left off).
     * Returns after completing up to $batchSize products so the HTTP response
     * stays fast.  The caller should keep polling until status === 'completed'.
     *
     * @param array $options  {migrate_categories, migrate_attributes, migrate_products, migrate_images, ps_root_path}
     * @return array  Current state after the step.
     */
    public function runStep(array $options = []): array
    {
        // Merge options into state if this is the first call
        if ($this->state['status'] === 'idle') {
            $this->state['options']           = $options;
            $this->state['total_categories']  = $this->wc->countCategories();
            $this->state['total_products']    = $this->wc->countProducts();
            $this->state['total_attrs']       = $this->wc->countAttributes();
            $this->state['status']            = 'running';
            $this->state['step']              = 'categories';
            $this->saveState();
            $this->log->info('Migration started', [
                'categories' => $this->state['total_categories'],
                'products'   => $this->state['total_products'],
                'attributes' => $this->state['total_attrs'],
            ]);
        }

        $opts = $this->state['options'];

        try {
            // Step 1: Categories
            if ($this->state['step'] === 'categories') {
                if ($opts['migrate_categories'] ?? true) {
                    $this->migrateCategories();
                } else {
                    $this->log->info('Category migration skipped.');
                }
                $this->state['step'] = 'attributes';
                $this->saveState();
            }

            // Step 2: Attributes
            if ($this->state['step'] === 'attributes') {
                if ($opts['migrate_attributes'] ?? true) {
                    $this->migrateAttributes();
                } else {
                    $this->log->info('Attribute migration skipped.');
                }
                $this->state['step'] = 'products';
                $this->saveState();
            }

            // Step 3: Products (batched)
            if ($this->state['step'] === 'products') {
                if ($opts['migrate_products'] ?? true) {
                    $done = $this->migrateProductsBatch($opts);
                    if (!$done) {
                        // More products remaining – caller must poll again
                        $this->saveState();
                        return $this->state;
                    }
                } else {
                    $this->log->info('Product migration skipped.');
                }
                $this->state['step']   = 'done';
                $this->state['status'] = 'completed';
                $this->saveState();
                $this->log->success('Migration completed successfully!');
            }
        } catch (\Throwable $e) {
            $this->state['status'] = 'error';
            $this->state['errors'][] = [
                'step'    => $this->state['step'],
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ];
            $this->saveState();
            $this->log->error('Migration error: ' . $e->getMessage());
        }

        return $this->state;
    }

    // -----------------------------------------------------------------------
    // Categories
    // -----------------------------------------------------------------------

    private function migrateCategories(): void
    {
        $this->log->info('Starting category migration…');
        $homeCatId = $this->ps->getHomeCategoryId();
        $categories= $this->wc->getCategories();

        $this->state['total_categories'] = count($categories);
        $map = &$this->state['category_id_map'];

        // Process in order: parents before children (WCImporter already orders by parent ASC)
        foreach ($categories as $wcId => $cat) {
            if (isset($map[(string) $wcId])) {
                continue; // already migrated
            }

            // Resolve PS parent id
            $wcParentId = $cat['parent'];
            if ($wcParentId === 0) {
                $psParentId = $homeCatId;
            } elseif (isset($map[(string) $wcParentId])) {
                $psParentId = $map[(string) $wcParentId];
            } else {
                $psParentId = $homeCatId;
                $this->log->warning("Parent category {$wcParentId} not yet migrated; using home category.");
            }

            try {
                $psId = $this->ps->insertCategory($cat, $psParentId);
                $map[(string) $wcId] = $psId;
                $this->state['done_categories']++;
                $this->log->success("Category '{$cat['name']}' → PS id {$psId}");
            } catch (\Throwable $e) {
                $this->state['errors'][] = [
                    'step'    => 'categories',
                    'wc_id'   => $wcId,
                    'name'    => $cat['name'],
                    'message' => $e->getMessage(),
                ];
                $this->log->error("Category '{$cat['name']}' failed: " . $e->getMessage());
            }
        }

        $this->log->info("Category migration complete. {$this->state['done_categories']} categories migrated.");
    }

    // -----------------------------------------------------------------------
    // Attributes
    // -----------------------------------------------------------------------

    private function migrateAttributes(): void
    {
        $this->log->info('Starting attribute migration…');
        $taxonomies = $this->wc->getAttributeTaxonomies();

        $this->state['total_attrs'] = count($taxonomies);

        foreach ($taxonomies as $wcAttrId => $attrType) {
            $attrName = $attrType['attribute_label'];
            $taxonomy = 'pa_' . $attrType['attribute_name'];

            // Insert attribute group
            try {
                $psGroupId = $this->ps->insertAttributeGroup($attrName);
                $this->state['attr_group_map'][$attrType['attribute_name']] = $psGroupId;
                $this->log->success("Attribute group '{$attrName}' → PS id {$psGroupId}");
            } catch (\Throwable $e) {
                $this->log->error("Attribute group '{$attrName}' failed: " . $e->getMessage());
                continue;
            }

            // Insert individual attribute values
            $terms = $this->wc->getAttributeTerms($taxonomy);
            foreach ($terms as $term) {
                try {
                    $psAttrId = $this->ps->insertAttribute($term['name'], $psGroupId);
                    $lookupKey = strtolower($attrType['attribute_name'] . ':' . $term['slug']);
                    $this->state['attr_value_map'][$lookupKey] = $psAttrId;
                    $this->log->success("  Attribute value '{$term['name']}' → PS id {$psAttrId}");
                } catch (\Throwable $e) {
                    $this->log->error("  Attribute value '{$term['name']}' failed: " . $e->getMessage());
                }
            }

            $this->state['done_attrs']++;
        }

        $this->log->info("Attribute migration complete. {$this->state['done_attrs']} attribute groups migrated.");
    }

    // -----------------------------------------------------------------------
    // Products (batched)
    // -----------------------------------------------------------------------

    /**
     * Migrate one batch of products.
     * @return bool  true when all products have been migrated.
     */
    private function migrateProductsBatch(array $opts): bool
    {
        $psRootPath = $opts['ps_root_path'] ?? '';
        $doImages   = $opts['migrate_images'] ?? false;

        $homeCatId = $this->ps->getHomeCategoryId();
        $catMap    = &$this->state['category_id_map'];
        $prodMap   = &$this->state['product_id_map'];
        $attrMap   = $this->state['attr_value_map'];

        $afterId = $this->state['last_wc_product_id'];
        $products= $this->wc->getProductsBatch($afterId, $this->batchSize);

        if (empty($products)) {
            return true; // All done
        }

        foreach ($products as $wcId => $product) {
            // Determine default PS category
            $defaultCatId = $homeCatId;
            if (!empty($product['category_ids'])) {
                $firstCat = (string) $product['category_ids'][0];
                if (isset($catMap[$firstCat])) {
                    $defaultCatId = $catMap[$firstCat];
                }
            }

            try {
                $psId = $this->ps->insertProduct($product, $defaultCatId);
                $prodMap[(string) $wcId] = $psId;

                // Assign all categories
                $psCatIds = [];
                foreach ($product['category_ids'] as $wcCatId) {
                    if (isset($catMap[(string) $wcCatId])) {
                        $psCatIds[] = $catMap[(string) $wcCatId];
                    }
                }
                if (!empty($psCatIds)) {
                    $this->ps->assignProductCategories($psId, $psCatIds);
                }

                // Variations (variable products)
                if ($product['type'] === 'variable' && !empty($product['variations'])) {
                    foreach ($product['variations'] as $variation) {
                        try {
                            $this->ps->insertCombination($psId, $variation, $attrMap);
                        } catch (\Throwable $e) {
                            $this->log->warning("Variation {$variation['id']} of product {$wcId} failed: " . $e->getMessage());
                        }
                    }
                }

                // Images
                if ($doImages && !empty($product['images'])) {
                    $isCover = true;
                    foreach ($product['images'] as $imgUrl) {
                        try {
                            $this->ps->insertImage($psId, $imgUrl, $isCover, $psRootPath);
                            $isCover = false;
                        } catch (\Throwable $e) {
                            $this->log->warning("Image '{$imgUrl}' for product {$wcId} failed: " . $e->getMessage());
                        }
                    }
                }

                $this->state['done_products']++;
                $this->state['last_wc_product_id'] = $wcId;
                $this->log->success("Product '{$product['title']}' (WC #{$wcId}) → PS #{$psId}");

            } catch (\Throwable $e) {
                $this->state['errors'][] = [
                    'step'    => 'products',
                    'wc_id'   => $wcId,
                    'title'   => $product['title'],
                    'message' => $e->getMessage(),
                ];
                $this->log->error("Product '{$product['title']}' (WC #{$wcId}) failed: " . $e->getMessage());
                // Continue with next product
                $this->state['last_wc_product_id'] = $wcId;
            }
        }

        // Check whether all products are done
        return $this->state['done_products'] >= $this->state['total_products'];
    }
}
