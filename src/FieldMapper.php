<?php

declare(strict_types=1);

/**
 * FieldMapper defines the correspondence between WooCommerce meta keys and
 * PrestaShop product/category columns, and provides helpers to transform values.
 */
class FieldMapper
{
    /**
     * WooCommerce product meta keys → PrestaShop ps_product column.
     * Keys that map to ps_product_lang (text fields) are noted separately.
     */
    public const PRODUCT_META_MAP = [
        '_regular_price'          => 'price',
        '_sku'                    => 'reference',
        '_weight'                 => 'weight',
        '_length'                 => 'depth',
        '_width'                  => 'width',
        '_height'                 => 'height',
        '_stock_quantity'         => 'quantity',   // goes to ps_stock_available
        '_manage_stock'           => 'advanced_stock_management',
    ];

    /**
     * WooCommerce post fields → PrestaShop ps_product_lang text columns.
     */
    public const PRODUCT_TEXT_MAP = [
        'post_title'   => 'name',
        'post_content' => 'description',
        'post_excerpt' => 'description_short',
    ];

    /**
     * Default PrestaShop product field values used when WC data is absent.
     */
    public const PRODUCT_DEFAULTS = [
        'id_tax_rules_group'        => 1,
        'id_category_default'       => 2,   // "Home" in PS default install
        'active'                    => 1,
        'available_for_order'       => 1,
        'show_price'                => 1,
        'indexed'                   => 1,
        'visibility'                => 'both',
        'condition'                 => 'new',
        'minimal_quantity'          => 1,
        'out_of_stock'              => 2,   // use global setting
        'redirect_type'             => '404',
        'on_sale'                   => 0,
        'online_only'               => 0,
        'ecotax'                    => 0,
        'unit_price_ratio'          => 0,
        'additional_shipping_cost'  => 0,
        'customizable'              => 0,
        'uploadable_files'          => 0,
        'text_fields'               => 0,
        'is_virtual'                => 0,
        'cache_is_pack'             => 0,
        'cache_has_attachments'     => 0,
        'pack_stock_type'           => 3,
    ];

    /**
     * Strip all HTML from a string and decode HTML entities.
     */
    public static function stripHtml(string $html): string
    {
        // Replace block-level tags with newlines for readability
        // preg_replace can return null on error, so we coalesce to empty string
        $html = preg_replace('/<(br|p|div|h[1-6]|li|tr|td|th|blockquote|pre)[^>]*>/i', "\n", $html) ?? '';
        // Remove remaining tags
        $text = strip_tags($html);
        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Normalise whitespace (preg_replace can return null on error)
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/(\r\n|\r|\n){3,}/', "\n\n", $text) ?? $text;
        return trim($text);
    }

    /**
     * Convert a product title / category name to a URL-friendly link_rewrite.
     */
    public static function slugify(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        // Transliterate accented characters
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        return $text ?: 'product';
    }

    /**
     * Safely cast a value to float, defaulting to 0.
     */
    public static function toFloat($value): float
    {
        return (float) str_replace(',', '.', (string) ($value ?? 0));
    }

    /**
     * Map a WooCommerce stock status to a PrestaShop out_of_stock value.
     *   'instock'    → 1  (allow orders)
     *   'onbackorder'→ 1  (allow orders)
     *   'outofstock' → 0  (deny orders)
     *   default      → 2  (use global setting)
     */
    public static function mapStockStatus(string $status): int
    {
        return match ($status) {
            'instock', 'onbackorder' => 1,
            'outofstock'             => 0,
            default                  => 2,
        };
    }

    /**
     * Return a human-readable label for a WooCommerce product type.
     */
    public static function mapProductType(string $wcType): string
    {
        return match ($wcType) {
            'variable'  => 'variable',
            'grouped'   => 'grouped',
            'external'  => 'external',
            default     => 'simple',
        };
    }
}
