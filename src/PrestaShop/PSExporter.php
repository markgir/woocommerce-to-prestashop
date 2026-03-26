<?php

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../DebugLogger.php';
require_once __DIR__ . '/../FieldMapper.php';

/**
 * PSExporter writes data into a PrestaShop database.
 *
 * Supported PrestaShop versions: 1.6.x, 1.7.x, 8.x
 * (A $version flag adjusts behaviour for schema differences.)
 */
class PSExporter
{
    private Database    $db;
    private string      $p;          // table prefix (e.g. "ps_")
    private DebugLogger $log;
    private string      $version;    // "1.6", "1.7", "8"
    private int         $idLang;     // default language id
    private int         $idShop;     // default shop id

    public function __construct(
        Database    $db,
        DebugLogger $log,
        string      $version = '1.7',
        int         $idLang  = 1,
        int         $idShop  = 1
    ) {
        $this->db      = $db;
        $this->p       = $db->getPrefix();
        $this->log     = $log;
        $this->version = $version;
        $this->idLang  = $idLang;
        $this->idShop  = $idShop;
    }

    // -----------------------------------------------------------------------
    // PrestaShop info
    // -----------------------------------------------------------------------

    public function getDefaultLanguageId(): int
    {
        $row = $this->db->queryOne(
            "SELECT value FROM `{$this->p}configuration` WHERE name = 'PS_LANG_DEFAULT' LIMIT 1"
        );
        return $row ? (int) $row['value'] : 1;
    }

    public function getDefaultShopId(): int
    {
        $row = $this->db->queryOne(
            "SELECT id_shop FROM `{$this->p}shop` ORDER BY id_shop ASC LIMIT 1"
        );
        return $row ? (int) $row['id_shop'] : 1;
    }

    public function getPrestaShopVersion(): string
    {
        $row = $this->db->queryOne(
            "SELECT value FROM `{$this->p}configuration` WHERE name = 'PS_VERSION_DB' LIMIT 1"
        );
        return $row ? $row['value'] : 'unknown';
    }

    public function getRootCategoryId(): int
    {
        $row = $this->db->queryOne(
            "SELECT value FROM `{$this->p}configuration` WHERE name = 'PS_ROOT_CATEGORY' LIMIT 1"
        );
        return $row ? (int) $row['value'] : 1;
    }

    public function getHomeCategoryId(): int
    {
        $row = $this->db->queryOne(
            "SELECT value FROM `{$this->p}configuration` WHERE name = 'PS_HOME_CATEGORY' LIMIT 1"
        );
        return $row ? (int) $row['value'] : 2;
    }

    // -----------------------------------------------------------------------
    // Categories
    // -----------------------------------------------------------------------

    /**
     * Insert a category and return its new PS id_category.
     * $parentPsId must already exist in the PS database.
     *
     * @param array $wcCat  {name, slug, description}
     * @param int   $parentPsId
     * @return int  new id_category
     */
    public function insertCategory(array $wcCat, int $parentPsId): int
    {
        $slug  = FieldMapper::slugify($wcCat['name']);
        $depth = $this->getCategoryDepth($parentPsId) + 1;

        // Calculate nested-set left/right values by appending after last child
        $rightBound = $this->getCategoryRightBound($parentPsId);

        // Shift existing nodes to make room
        $this->db->execute(
            "UPDATE `{$this->p}category` SET nleft  = nleft  + 2 WHERE nleft  >= ?",
            [$rightBound]
        );
        $this->db->execute(
            "UPDATE `{$this->p}category` SET nright = nright + 2 WHERE nright >= ?",
            [$rightBound]
        );

        // Expand parent's right bound
        $this->db->execute(
            "UPDATE `{$this->p}category` SET nright = nright + 2 WHERE id_category = ?",
            [$parentPsId]
        );

        $nleft  = $rightBound;
        $nright = $rightBound + 1;

        $id = $this->db->execute(
            "INSERT INTO `{$this->p}category`
                 (id_parent, level_depth, nleft, nright, active, date_add, date_upd, position, is_root_category)
             VALUES (?, ?, ?, ?, 1, NOW(), NOW(), 0, 0)",
            [$parentPsId, $depth, $nleft, $nright]
        );

        // Insert category_lang
        $this->db->execute(
            "INSERT INTO `{$this->p}category_lang`
                 (id_category, id_shop, id_lang, name, description, link_rewrite,
                  meta_title, meta_keywords, meta_description)
             VALUES (?, ?, ?, ?, ?, ?, '', '', '')",
            [$id, $this->idShop, $this->idLang,
             mb_substr($wcCat['name'], 0, 128),
             $wcCat['description'] ?? '',
             mb_substr($slug, 0, 128)]
        );

        // Insert category_shop
        if ($this->db->tableExists($this->p . 'category_shop')) {
            $this->db->execute(
                "INSERT IGNORE INTO `{$this->p}category_shop` (id_category, id_shop)
                 VALUES (?, ?)",
                [$id, $this->idShop]
            );
        }

        return $id;
    }

    private function getCategoryDepth(int $catId): int
    {
        $row = $this->db->queryOne(
            "SELECT level_depth FROM `{$this->p}category` WHERE id_category = ? LIMIT 1",
            [$catId]
        );
        return (int) ($row['level_depth'] ?? 0);
    }

    private function getCategoryRightBound(int $catId): int
    {
        $row = $this->db->queryOne(
            "SELECT nright FROM `{$this->p}category` WHERE id_category = ? LIMIT 1",
            [$catId]
        );
        return (int) ($row['nright'] ?? 2);
    }

    // -----------------------------------------------------------------------
    // Attribute groups and attribute values
    // -----------------------------------------------------------------------

    /**
     * Insert an attribute group (e.g. "Color") and return its id.
     */
    public function insertAttributeGroup(string $name): int
    {
        $id = $this->db->execute(
            "INSERT INTO `{$this->p}attribute_group`
                 (is_color_group, group_type, position)
             VALUES (0, 'select', 0)"
        );

        $slug = FieldMapper::slugify($name);
        $this->db->execute(
            "INSERT INTO `{$this->p}attribute_group_lang`
                 (id_attribute_group, id_lang, name, public_name)
             VALUES (?, ?, ?, ?)",
            [$id, $this->idLang, mb_substr($name, 0, 128), mb_substr($name, 0, 128)]
        );

        // attribute_group_shop
        if ($this->db->tableExists($this->p . 'attribute_group_shop')) {
            $this->db->execute(
                "INSERT IGNORE INTO `{$this->p}attribute_group_shop` (id_attribute_group, id_shop)
                 VALUES (?, ?)",
                [$id, $this->idShop]
            );
        }

        return $id;
    }

    /**
     * Insert an attribute value (e.g. "Red") linked to a group, return its id.
     */
    public function insertAttribute(string $name, int $groupId): int
    {
        $id = $this->db->execute(
            "INSERT INTO `{$this->p}attribute`
                 (id_attribute_group, color, position)
             VALUES (?, '', 0)",
            [$groupId]
        );

        $this->db->execute(
            "INSERT INTO `{$this->p}attribute_lang`
                 (id_attribute, id_lang, name)
             VALUES (?, ?, ?)",
            [$id, $this->idLang, mb_substr($name, 0, 128)]
        );

        // attribute_shop
        if ($this->db->tableExists($this->p . 'attribute_shop')) {
            $this->db->execute(
                "INSERT IGNORE INTO `{$this->p}attribute_shop` (id_attribute, id_shop)
                 VALUES (?, ?)",
                [$id, $this->idShop]
            );
        }

        return $id;
    }

    // -----------------------------------------------------------------------
    // Products
    // -----------------------------------------------------------------------

    /**
     * Insert a product and return its new id_product.
     *
     * @param array $wcProduct  Normalised product data from WCImporter.
     * @param int   $defaultCategoryId  PrestaShop category id.
     * @return int  new id_product
     */
    public function insertProduct(array $wcProduct, int $defaultCategoryId): int
    {
        $meta     = $wcProduct['meta'];
        $price    = FieldMapper::toFloat($meta['_regular_price'] ?? 0);
        $salePrice= FieldMapper::toFloat($meta['_sale_price'] ?? 0);
        $weight   = FieldMapper::toFloat($meta['_weight'] ?? 0);
        $width    = FieldMapper::toFloat($meta['_width'] ?? 0);
        $height   = FieldMapper::toFloat($meta['_height'] ?? 0);
        $depth    = FieldMapper::toFloat($meta['_length'] ?? 0);
        $ref      = mb_substr((string) ($meta['_sku'] ?? ''), 0, 64);
        $active   = $wcProduct['status'] === 'publish' ? 1 : 0;

        $id = $this->db->execute(
            "INSERT INTO `{$this->p}product`
                 (id_supplier, id_manufacturer, id_category_default,
                  id_shop_default, id_tax_rules_group,
                  on_sale, online_only, ean13, isbn, upc,
                  ecotax, quantity, minimal_quantity,
                  price, wholesale_price, unity, unit_price_ratio,
                  additional_shipping_cost, reference,
                  supplier_reference, location,
                  width, height, depth, weight,
                  out_of_stock, quantity_discount,
                  customizable, uploadable_files, text_fields,
                  active, redirect_type, id_product_redirected,
                  available_for_order, available_date,
                  show_condition, condition, show_price, indexed,
                  visibility, cache_is_pack, cache_has_attachments,
                  is_virtual, cache_default_attribute,
                  date_add, date_upd, pack_stock_type)
             VALUES
                 (0, 0, ?,
                  ?, 1,
                  0, 0, '', '', '',
                  0.000000, 0, 1,
                  ?, 0.000000, '', 0,
                  0.000000, ?,
                  '', '',
                  ?, ?, ?, ?,
                  2, 0,
                  0, 0, 0,
                  ?, '404', 0,
                  1, '0000-00-00',
                  0, 'new', 1, 1,
                  'both', 0, 0,
                  0, 0,
                  ?, ?, 3)",
            [
                $defaultCategoryId,
                $this->idShop,
                $price,
                $ref,
                $width, $height, $depth, $weight,
                $active,
                $wcProduct['date_add'],
                $wcProduct['date_upd'],
            ]
        );

        // ps_product_lang
        $linkRewrite = FieldMapper::slugify($wcProduct['title']);
        $this->db->execute(
            "INSERT INTO `{$this->p}product_lang`
                 (id_product, id_shop, id_lang,
                  description, description_short, link_rewrite,
                  meta_description, meta_keywords, meta_title,
                  name, available_now, available_later,
                  delivery_in_stock, delivery_out_stock)
             VALUES (?, ?, ?, ?, ?, ?, '', '', ?, ?, '', '', '', '')",
            [
                $id, $this->idShop, $this->idLang,
                $wcProduct['description'],
                mb_substr($wcProduct['short_desc'], 0, 800),
                mb_substr($linkRewrite, 0, 128),
                mb_substr($wcProduct['title'], 0, 128),
                mb_substr($wcProduct['title'], 0, 128),
            ]
        );

        // ps_product_shop
        if ($this->db->tableExists($this->p . 'product_shop')) {
            $this->db->execute(
                "INSERT IGNORE INTO `{$this->p}product_shop`
                     (id_product, id_shop, id_category_default, id_tax_rules_group,
                      on_sale, online_only, ecotax, minimal_quantity,
                      price, wholesale_price, unity, unit_price_ratio,
                      additional_shipping_cost, customizable, uploadable_files,
                      text_fields, active, redirect_type, id_product_redirected,
                      available_for_order, available_date, show_condition,
                      condition, show_price, indexed, visibility,
                      cache_default_attribute, advanced_stock_management,
                      date_add, date_upd, pack_stock_type)
                 VALUES (?, ?, ?, 1,
                         0, 0, 0, 1,
                         ?, 0, '', 0,
                         0, 0, 0,
                         0, ?, '404', 0,
                         1, '0000-00-00', 0,
                         'new', 1, 1, 'both',
                         0, 0,
                         ?, ?, 3)",
                [
                    $id, $this->idShop, $defaultCategoryId,
                    $price,
                    $active,
                    $wcProduct['date_add'],
                    $wcProduct['date_upd'],
                ]
            );
        }

        // ps_category_product
        $this->db->execute(
            "INSERT IGNORE INTO `{$this->p}category_product` (id_category, id_product, position)
             VALUES (?, ?, 0)",
            [$defaultCategoryId, $id]
        );

        // ps_stock_available
        $qty = (int) ($meta['_stock_quantity'] ?? 0);
        $this->insertStockAvailable($id, 0, $qty);

        // sale price → specific_price
        if ($salePrice > 0 && $salePrice < $price) {
            $this->insertSpecificPrice($id, $salePrice);
        }

        return $id;
    }

    /**
     * Assign extra categories to a product (many-to-many).
     */
    public function assignProductCategories(int $psProductId, array $psCategoryIds): void
    {
        foreach ($psCategoryIds as $catId) {
            $this->db->execute(
                "INSERT IGNORE INTO `{$this->p}category_product` (id_category, id_product, position)
                 VALUES (?, ?, 0)",
                [$catId, $psProductId]
            );
        }
    }

    // -----------------------------------------------------------------------
    // Stock
    // -----------------------------------------------------------------------

    private function insertStockAvailable(int $productId, int $attributeId, int $qty): void
    {
        $this->db->execute(
            "INSERT INTO `{$this->p}stock_available`
                 (id_product, id_product_attribute, id_shop, id_shop_group,
                  quantity, depends_on_stock, out_of_stock, location)
             VALUES (?, ?, ?, 0, ?, 0, 2, '')
             ON DUPLICATE KEY UPDATE quantity = ?",
            [$productId, $attributeId, $this->idShop, $qty, $qty]
        );
    }

    // -----------------------------------------------------------------------
    // Specific price (sale)
    // -----------------------------------------------------------------------

    private function insertSpecificPrice(int $productId, float $salePrice): void
    {
        if (!$this->db->tableExists($this->p . 'specific_price')) {
            return;
        }
        $this->db->execute(
            "INSERT INTO `{$this->p}specific_price`
                 (id_specific_price_rule, id_cart, id_product, id_shop, id_shop_group,
                  id_currency, id_country, id_group, id_customer, id_product_attribute,
                  price, from_quantity, reduction, reduction_tax, reduction_type,
                  `from`, `to`)
             VALUES
                 (0, 0, ?, ?, 0,
                  0, 0, 1, 0, 0,
                  ?, 1, 0, 1, 'amount',
                  '0000-00-00 00:00:00', '0000-00-00 00:00:00')",
            [$productId, $this->idShop, $salePrice]
        );
    }

    // -----------------------------------------------------------------------
    // Product combinations (variations)
    // -----------------------------------------------------------------------

    /**
     * Insert a product combination and return its id_product_attribute.
     *
     * @param int   $psProductId
     * @param array $variation    Normalised variation from WCImporter.
     * @param array $attrMap      WC term_slug → PS id_attribute
     * @return int  id_product_attribute
     */
    public function insertCombination(int $psProductId, array $variation, array $attrMap): int
    {
        $meta      = $variation['meta'];
        $price     = FieldMapper::toFloat($meta['_regular_price'] ?? 0);
        $ref       = mb_substr((string) ($meta['_sku'] ?? ''), 0, 64);
        $weight    = FieldMapper::toFloat($meta['_weight'] ?? 0);

        $id = $this->db->execute(
            "INSERT INTO `{$this->p}product_attribute`
                 (id_product, reference, supplier_reference, location,
                  ean13, isbn, upc, wholesale_price, price, ecotax,
                  quantity, weight, unit_price_impact,
                  minimal_quantity, low_stock_threshold,
                  low_stock_alert, default_on)
             VALUES (?, ?, '', '', '', '', '', 0, ?, 0, 0, ?, 0, 1, 0, 0, 0)",
            [$psProductId, $ref, $price, $weight]
        );

        // Link attributes to combination
        foreach ($variation['attrs'] as $attrSlug => $attrValue) {
            $lookupKey = strtolower(trim($attrSlug . ':' . $attrValue));
            if (isset($attrMap[$lookupKey])) {
                $idAttribute = $attrMap[$lookupKey];
                $this->db->execute(
                    "INSERT IGNORE INTO `{$this->p}product_attribute_combination`
                         (id_product_attribute, id_attribute)
                     VALUES (?, ?)",
                    [$id, $idAttribute]
                );
            }
        }

        // Stock for combination
        $qty = (int) ($meta['_stock_quantity'] ?? 0);
        $this->insertStockAvailable($psProductId, $id, $qty);

        // product_attribute_shop
        if ($this->db->tableExists($this->p . 'product_attribute_shop')) {
            $this->db->execute(
                "INSERT IGNORE INTO `{$this->p}product_attribute_shop`
                     (id_product_attribute, id_shop, wholesale_price, price, ecotax,
                      weight, unit_price_impact, default_on, minimal_quantity,
                      low_stock_threshold, low_stock_alert)
                 VALUES (?, ?, 0, ?, 0, ?, 0, 0, 1, 0, 0)",
                [$id, $this->idShop, $price, $weight]
            );
        }

        return $id;
    }

    // -----------------------------------------------------------------------
    // Images
    // -----------------------------------------------------------------------

    /**
     * Register an image URL for a product in the PS database and optionally
     * download the file to the PS image directory.
     *
     * @param int    $psProductId
     * @param string $imageUrl    Public URL of the original image.
     * @param bool   $isCover     Whether this is the cover image.
     * @param string $psRootPath  File-system root of the PS install (may be empty).
     * @return int   id_image (0 on failure)
     */
    public function insertImage(
        int    $psProductId,
        string $imageUrl,
        bool   $isCover   = false,
        string $psRootPath = ''
    ): int {
        $position = $this->getNextImagePosition($psProductId);

        $id = $this->db->execute(
            "INSERT INTO `{$this->p}image`
                 (id_product, position, cover)
             VALUES (?, ?, ?)",
            [$psProductId, $position, $isCover ? 1 : 0]
        );

        // image_lang (PS 1.6 only, but safe to try for 1.7 too)
        if ($this->db->tableExists($this->p . 'image_lang')) {
            $this->db->execute(
                "INSERT IGNORE INTO `{$this->p}image_lang`
                     (id_image, id_lang, legend)
                 VALUES (?, ?, '')",
                [$id, $this->idLang]
            );
        }

        // image_shop
        if ($this->db->tableExists($this->p . 'image_shop')) {
            $this->db->execute(
                "INSERT IGNORE INTO `{$this->p}image_shop`
                     (id_image, id_shop, cover)
                 VALUES (?, ?, ?)",
                [$id, $this->idShop, $isCover ? 1 : 0]
            );
        }

        // Optionally download image to PS file system
        if ($psRootPath !== '' && filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            $this->downloadImage($id, $imageUrl, $psRootPath);
        }

        return $id;
    }

    private function getNextImagePosition(int $productId): int
    {
        $row = $this->db->queryOne(
            "SELECT COALESCE(MAX(position), 0) + 1 AS pos
             FROM `{$this->p}image`
             WHERE id_product = ?",
            [$productId]
        );
        return (int) ($row['pos'] ?? 1);
    }

    /**
     * Download an image and save it in PrestaShop's directory structure.
     * PS stores images under /img/p/{d1}/{d2}/.../{id}.jpg
     */
    private function downloadImage(int $imageId, string $url, string $psRoot): void
    {
        try {
            $imgDir = $psRoot . '/img/p/' . $this->buildImagePath($imageId);
            if (!is_dir($imgDir)) {
                mkdir($imgDir, 0755, true);
            }

            // Determine extension from URL
            $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                $ext = 'jpg';
            }

            $destFile = $imgDir . '/' . $imageId . '.' . $ext;

            $ctx  = stream_context_create(['http' => ['timeout' => 15]]);
            $data = @file_get_contents($url, false, $ctx);
            if ($data !== false) {
                file_put_contents($destFile, $data);
            }
        } catch (\Throwable $e) {
            $this->log->warning("Could not download image {$url}: " . $e->getMessage());
        }
    }

    /** Build the PS directory path component for a given image id. */
    private function buildImagePath(int $id): string
    {
        $digits = str_split((string) $id);
        return implode('/', $digits);
    }
}
