<?php

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../DebugLogger.php';
require_once __DIR__ . '/../FieldMapper.php';

/**
 * WCImporter reads product data from a WooCommerce / WordPress database.
 *
 * Supported WooCommerce versions: 3.x, 4.x, 5.x, 6.x, 7.x, 8.x
 * (All share the same core schema; newer versions add lookup tables but the
 * canonical data remains in the classic wp_posts / wp_postmeta tables.)
 */
class WCImporter
{
    private Database    $db;
    private string      $p;     // table prefix (e.g. "wp_")
    private DebugLogger $log;

    public function __construct(Database $db, DebugLogger $log)
    {
        $this->db  = $db;
        $this->p   = $db->getPrefix();
        $this->log = $log;
    }

    // -----------------------------------------------------------------------
    // Analysis helpers
    // -----------------------------------------------------------------------

    public function countCategories(): int
    {
        $row = $this->db->queryOne(
            "SELECT COUNT(*) AS n FROM `{$this->p}term_taxonomy` WHERE taxonomy = 'product_cat'"
        );
        return (int) ($row['n'] ?? 0);
    }

    public function countProducts(): int
    {
        $row = $this->db->queryOne(
            "SELECT COUNT(*) AS n FROM `{$this->p}posts`
             WHERE post_type = 'product' AND post_status IN ('publish','draft')"
        );
        return (int) ($row['n'] ?? 0);
    }

    public function countVariations(): int
    {
        $row = $this->db->queryOne(
            "SELECT COUNT(*) AS n FROM `{$this->p}posts`
             WHERE post_type = 'product_variation' AND post_status IN ('publish','private')"
        );
        return (int) ($row['n'] ?? 0);
    }

    public function countAttributes(): int
    {
        $row = $this->db->queryOne(
            "SELECT COUNT(*) AS n FROM `{$this->p}woocommerce_attribute_taxonomies`"
        );
        return (int) ($row['n'] ?? 0);
    }

    // -----------------------------------------------------------------------
    // Categories
    // -----------------------------------------------------------------------

    /**
     * Return all product categories ordered so parents come before children.
     *
     * @return array<int, array{term_id:int, name:string, slug:string, parent:int, description:string}>
     */
    public function getCategories(): array
    {
        $rows = $this->db->query(
            "SELECT t.term_id, t.name, t.slug,
                    tt.parent, tt.description
             FROM `{$this->p}terms` t
             JOIN `{$this->p}term_taxonomy` tt ON t.term_id = tt.term_id
             WHERE tt.taxonomy = 'product_cat'
             ORDER BY tt.parent ASC, t.term_id ASC"
        );

        $cats = [];
        foreach ($rows as $row) {
            $cats[(int) $row['term_id']] = [
                'term_id'     => (int) $row['term_id'],
                'name'        => $row['name'],
                'slug'        => $row['slug'],
                'parent'      => (int) $row['parent'],
                'description' => FieldMapper::stripHtml($row['description'] ?? ''),
            ];
        }
        return $cats;
    }

    // -----------------------------------------------------------------------
    // Attributes / Attribute taxonomies
    // -----------------------------------------------------------------------

    /**
     * Return global WooCommerce product attribute types.
     *
     * @return array<int, array{attribute_id:int, attribute_name:string, attribute_label:string, attribute_type:string}>
     */
    public function getAttributeTaxonomies(): array
    {
        $rows = $this->db->query(
            "SELECT attribute_id, attribute_name, attribute_label, attribute_type
             FROM `{$this->p}woocommerce_attribute_taxonomies`
             ORDER BY attribute_id ASC"
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['attribute_id']] = [
                'attribute_id'    => (int) $row['attribute_id'],
                'attribute_name'  => $row['attribute_name'],
                'attribute_label' => $row['attribute_label'],
                'attribute_type'  => $row['attribute_type'],
            ];
        }
        return $result;
    }

    /**
     * Return all term values for a given attribute taxonomy (e.g. "pa_color").
     *
     * @return array<int, array{term_id:int, name:string, slug:string}>
     */
    public function getAttributeTerms(string $taxonomy): array
    {
        $rows = $this->db->query(
            "SELECT t.term_id, t.name, t.slug
             FROM `{$this->p}terms` t
             JOIN `{$this->p}term_taxonomy` tt ON t.term_id = tt.term_id
             WHERE tt.taxonomy = ?
             ORDER BY t.term_order ASC, t.term_id ASC",
            [$taxonomy]
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['term_id']] = [
                'term_id' => (int) $row['term_id'],
                'name'    => $row['name'],
                'slug'    => $row['slug'],
            ];
        }
        return $result;
    }

    // -----------------------------------------------------------------------
    // Products (batch)
    // -----------------------------------------------------------------------

    /**
     * Return a batch of products, ordering by ID ascending.
     *
     * @param int $afterId  Only return products with ID > $afterId (for paging).
     * @param int $limit    Max rows per batch.
     * @return array<int, array>
     */
    public function getProductsBatch(int $afterId = 0, int $limit = 20): array
    {
        $rows = $this->db->query(
            "SELECT ID, post_title, post_content, post_excerpt, post_status,
                    post_date, post_modified
             FROM `{$this->p}posts`
             WHERE post_type = 'product'
               AND post_status IN ('publish', 'draft', 'pending')
               AND ID > ?
             ORDER BY ID ASC
             LIMIT ?",
            [$afterId, $limit]
        );

        $products = [];
        foreach ($rows as $row) {
            $id              = (int) $row['ID'];
            $meta            = $this->getProductMeta($id);
            $categories      = $this->getProductCategoryIds($id);
            $images          = $this->getProductImages($id, $meta);
            $type            = $meta['_product_type'] ?? 'simple';

            $products[$id] = [
                'id'           => $id,
                'title'        => $row['post_title'],
                'description'  => FieldMapper::stripHtml($row['post_content'] ?? ''),
                'short_desc'   => FieldMapper::stripHtml($row['post_excerpt'] ?? ''),
                'status'       => $row['post_status'],
                'date_add'     => $row['post_date'],
                'date_upd'     => $row['post_modified'],
                'type'         => $type,
                'meta'         => $meta,
                'category_ids' => $categories,
                'images'       => $images,
            ];

            // Add variations for variable products
            if ($type === 'variable') {
                $products[$id]['variations'] = $this->getVariations($id);
            }
        }
        return $products;
    }

    // -----------------------------------------------------------------------
    // Product meta
    // -----------------------------------------------------------------------

    private function getProductMeta(int $productId): array
    {
        $rows = $this->db->query(
            "SELECT meta_key, meta_value
             FROM `{$this->p}postmeta`
             WHERE post_id = ?",
            [$productId]
        );

        $meta = [];
        foreach ($rows as $row) {
            $key = $row['meta_key'];
            $val = $row['meta_value'];

            // Unserialize complex meta values
            if ($val && strlen($val) > 1 && ($val[0] === 'a' || $val[0] === 'O') && $val[1] === ':') {
                $unserialized = @unserialize($val);
                if ($unserialized !== false) {
                    $val = $unserialized;
                }
            }
            $meta[$key] = $val;
        }

        // Resolve _product_type from taxonomy if not in meta
        if (!isset($meta['_product_type'])) {
            $typeRow = $this->db->queryOne(
                "SELECT t.slug
                 FROM `{$this->p}terms` t
                 JOIN `{$this->p}term_taxonomy` tt ON t.term_id = tt.term_id
                 JOIN `{$this->p}term_relationships` tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 WHERE tr.object_id = ? AND tt.taxonomy = 'product_type'
                 LIMIT 1",
                [$productId]
            );
            $meta['_product_type'] = $typeRow['slug'] ?? 'simple';
        }

        return $meta;
    }

    // -----------------------------------------------------------------------
    // Product categories
    // -----------------------------------------------------------------------

    private function getProductCategoryIds(int $productId): array
    {
        $rows = $this->db->query(
            "SELECT t.term_id
             FROM `{$this->p}terms` t
             JOIN `{$this->p}term_taxonomy` tt ON t.term_id = tt.term_id
             JOIN `{$this->p}term_relationships` tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = ? AND tt.taxonomy = 'product_cat'",
            [$productId]
        );
        return array_column($rows, 'term_id');
    }

    // -----------------------------------------------------------------------
    // Product images
    // -----------------------------------------------------------------------

    /**
     * Return image URLs for a product (thumbnail first, then gallery).
     *
     * @return string[]
     */
    public function getProductImages(int $productId, array $meta = []): array
    {
        $images = [];

        // Main thumbnail
        $thumbnailId = isset($meta['_thumbnail_id']) ? (int) $meta['_thumbnail_id'] : null;
        if ($thumbnailId) {
            $url = $this->getAttachmentUrl($thumbnailId);
            if ($url) {
                $images[] = $url;
            }
        }

        // Gallery
        $galleryIds = $meta['_product_image_gallery'] ?? '';
        if ($galleryIds && is_string($galleryIds)) {
            foreach (explode(',', $galleryIds) as $gid) {
                $gid = (int) trim($gid);
                if ($gid && $gid !== $thumbnailId) {
                    $url = $this->getAttachmentUrl($gid);
                    if ($url) {
                        $images[] = $url;
                    }
                }
            }
        }

        return $images;
    }

    private function getAttachmentUrl(int $attachmentId): ?string
    {
        $row = $this->db->queryOne(
            "SELECT guid FROM `{$this->p}posts`
             WHERE ID = ? AND post_type = 'attachment'
             LIMIT 1",
            [$attachmentId]
        );
        return $row ? ($row['guid'] ?: null) : null;
    }

    // -----------------------------------------------------------------------
    // Product variations
    // -----------------------------------------------------------------------

    /**
     * Return all variations for a variable product.
     *
     * @return array<int, array>
     */
    public function getVariations(int $parentId): array
    {
        $rows = $this->db->query(
            "SELECT ID, post_title, post_status, menu_order
             FROM `{$this->p}posts`
             WHERE post_parent = ? AND post_type = 'product_variation'
             ORDER BY menu_order ASC, ID ASC",
            [$parentId]
        );

        $variations = [];
        foreach ($rows as $row) {
            $vid   = (int) $row['ID'];
            $vmeta = $this->getVariationMeta($vid);
            $variations[$vid] = [
                'id'     => $vid,
                'status' => $row['post_status'],
                'meta'   => $vmeta,
                'attrs'  => $this->extractVariationAttributes($vmeta),
            ];
        }
        return $variations;
    }

    private function getVariationMeta(int $variationId): array
    {
        $rows = $this->db->query(
            "SELECT meta_key, meta_value
             FROM `{$this->p}postmeta`
             WHERE post_id = ?",
            [$variationId]
        );
        $meta = [];
        foreach ($rows as $row) {
            $meta[$row['meta_key']] = $row['meta_value'];
        }
        return $meta;
    }

    /**
     * Extract attribute slug→value pairs from variation meta.
     * Keys look like "attribute_pa_color" → "red"
     *
     * @return array<string, string>
     */
    private function extractVariationAttributes(array $meta): array
    {
        $attrs = [];
        foreach ($meta as $key => $value) {
            if (str_starts_with($key, 'attribute_')) {
                $attrSlug          = str_replace('attribute_pa_', '', $key);
                $attrSlug          = str_replace('attribute_', '', $attrSlug);
                $attrs[$attrSlug]  = $value;
            }
        }
        return $attrs;
    }

    // -----------------------------------------------------------------------
    // Misc
    // -----------------------------------------------------------------------

    public function getSiteUrl(): string
    {
        $row = $this->db->queryOne(
            "SELECT option_value FROM `{$this->p}options` WHERE option_name = 'siteurl' LIMIT 1"
        );
        return $row ? rtrim($row['option_value'], '/') : '';
    }

    public function getWooCommerceVersion(): string
    {
        $row = $this->db->queryOne(
            "SELECT option_value FROM `{$this->p}options` WHERE option_name = 'woocommerce_version' LIMIT 1"
        );
        return $row ? $row['option_value'] : 'unknown';
    }
}
