<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Core_Schema {
    public const POST_TYPE = 'ddb_spot';
    
    public const CACHE_VERSION_OPTION = 'ddb_spots_cache_version';
    
    public const CACHE_TTL = 300;

    public const TAX = array(
        'type'     => 'ddb_spot_type',
        'area'     => 'ddb_area',
        'tag'      => 'ddb_tag',
        'category' => 'ddb_category',
    );

    public const META = array(
        'spot_type_primary'       => '_ddb_spot_type_primary',
        'priority'                => '_ddb_priority',
        'price_level'             => '_ddb_price_level',
        'booking_provider'        => '_ddb_booking_provider',
        'formitable_embed'        => '_ddb_formitable_embed',
        'cta_url'                 => '_ddb_cta_url',
        'source'                  => '_ddb_source',
        'google_place_id'         => '_ddb_google_place_id',
        'google_last_synced_at'   => '_ddb_google_last_synced_at',
        'google_opening_hours'    => '_ddb_google_opening_hours_json',
        'google_opening_periods_json' => '_ddb_google_opening_periods_json',
        'google_phone'            => '_ddb_google_phone',
        'google_website'          => '_ddb_google_website',
        'google_maps_url'         => '_ddb_google_maps_url',
        'google_rating'           => '_ddb_google_rating',
        'google_user_ratings_total' => '_ddb_google_user_ratings_total',
        'google_business_status'  => '_ddb_google_business_status',
        'google_editorial_summary' => '_ddb_google_editorial_summary',
        'google_reviews_json'     => '_ddb_google_reviews_json',
        'google_place_types_json' => '_ddb_google_place_types_json',
        'address'                 => '_ddb_address',
        'city'                    => '_ddb_city',
        'region'                  => '_ddb_region',
        'country'                 => '_ddb_country',
        'lat'                     => '_ddb_lat',
        'lng'                     => '_ddb_lng',
        'google_photo_refs_json'  => '_ddb_google_photo_refs_json',
        'google_photo_media_map_json' => '_ddb_google_photo_media_map_json',
        'google_attribution_json' => '_ddb_google_attribution_json',
        'google_autosync'         => '_ddb_google_autosync',
        'opening_hours_json'      => '_ddb_opening_hours_json',
        'highlights_json'         => '_ddb_highlights_json',
        'gallery_ids'             => '_ddb_gallery_ids',
        'logo_id'                 => '_ddb_logo_id',
        'image_hero_id'           => '_ddb_image_hero_id',
        'image_sfeer_id'          => '_ddb_image_sfeer_id',
        'image_eten_id'           => '_ddb_image_eten_id',
        'group_max'               => '_ddb_group_max',
        'duration_hint'           => '_ddb_duration_hint',
        'best_time_slot'          => '_ddb_best_time_slot',
        'weather_compatibility'   => '_ddb_weather_compatibility',
        'group_fit_score'         => '_ddb_group_fit_score',
        'walk_distance_to_core'   => '_ddb_walk_distance_to_core',
        'suitability_json'        => '_ddb_suitability_json',
        'near_spots_json'         => '_ddb_near_spots_json',
        'bundles_json'            => '_ddb_bundles_json',
        'informational_only'      => '_ddb_informational_only',
        'formitable_venue_id'    => '_ddb_formitable_venue_id',
        'formitable_widget_id'   => '_ddb_formitable_widget_id',
        'formitable_embed_raw'   => '_ddb_formitable_embed_raw',
        'restaurant_booking_url' => '_ddb_restaurant_booking_url',
        'event_date'             => '_ddb_event_date',
        'event_ticket_url'       => '_ddb_event_ticket_url',
        'hotel_booking_url'      => '_ddb_hotel_booking_url',
        'generic_cta_url'        => '_ddb_spot_cta_url',
        'lock_title'             => '_ddb_lock_title',
        'lock_excerpt'           => '_ddb_lock_excerpt',
        'lock_cta'               => '_ddb_lock_cta',
        'lock_location'          => '_ddb_lock_location',
        'lock_contact'           => '_ddb_lock_contact',
        'lock_hours'             => '_ddb_lock_hours',
        'parent_business_id'     => '_ddb_parent_business_id',
    );
}
