<?php

declare(strict_types=1);

namespace SBDP\Modules\Arrangements\Domain;

final class ArrangementSchema
{
    public const POST_TYPE = 'sbdp_arrangement';
    public const TAXONOMY_CATEGORY = 'sbdp_arrangement_category';
    public const TAXONOMY_TAG = 'sbdp_arrangement_tag';

    public const META_SPEC = '_sbdp_arrangement_spec';
    public const META_SEGMENTS = '_sbdp_arrangement_segments';
    public const META_RULES = '_sbdp_arrangement_rules';
    public const META_TEMPLATE_ID = '_sbdp_arrangement_template_id';
    public const META_SALES_PRODUCT_ID = '_sbdp_arrangement_sales_product_id';
    public const META_PRICE_STRATEGY = '_sbdp_arrangement_price_strategy';
    public const META_BASE_PRICE = '_sbdp_arrangement_base_price';
    public const META_CURRENCY = '_sbdp_arrangement_currency';
    public const META_DURATION_TOTAL = '_sbdp_arrangement_duration_total';
    public const META_DAYPART = '_sbdp_arrangement_daypart';
    public const META_CREATION_MODE = '_sbdp_arrangement_creation_mode';
    public const META_ARRANGEMENT_TYPE = '_sbdp_arrangement_type';
    public const META_VISIBILITY = '_sbdp_arrangement_visibility';
    public const META_FEATURED = '_sbdp_arrangement_featured';
    public const META_SORT_ORDER = '_sbdp_arrangement_sort_order';
    public const META_IMAGE_ID = '_sbdp_arrangement_image_id';
    public const META_GALLERY_IDS = '_sbdp_arrangement_gallery_ids';
    public const META_LEGACY_SOURCE = '_sbdp_arrangement_legacy_source';
    public const META_LEGACY_BUNDLE_ID = '_sbdp_arrangement_legacy_bundle_id';

    public const TYPES = array('fixed', 'dynamic', 'customized', 'template');
    public const SEGMENT_TYPES = array('reception', 'activity', 'food_drink', 'transport', 'free_time', 'addon', 'optional_upgrade');
    public const SEGMENT_ROLES = array('anchor', 'pre', 'post');
    public const TIMING_MODES = array('fixed_start', 'flexible_window', 'after_previous', 'before_next', 'scheduler_decides');
    public const PRICE_STRATEGIES = array('sum_children', 'sum_children_minus_discount', 'fixed_bundle_price', 'base_plus_options');
    public const VISIBILITIES = array('public', 'internal', 'hidden', 'archived');

    private function __construct()
    {
    }
}
