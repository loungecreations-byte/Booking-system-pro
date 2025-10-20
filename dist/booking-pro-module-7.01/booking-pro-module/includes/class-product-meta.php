<?php
if (!defined('ABSPATH')) exit;

class SBDP_Product_Meta {
  private const LABEL_FIELDS = [
    'start'        => '_sbdp_label_map_start',
    'end'          => '_sbdp_label_map_end',
    'participants' => '_sbdp_label_map_participants',
    'resource'     => '_sbdp_label_map_resource'
  ];

  private static $defaults = [
    'start'        => 'Starttijd',
    'end'          => 'Eindtijd',
    'participants' => 'Deelnemers',
    'resource'     => 'Resource'
  ];

  public static function get_label($product_id, $key){
    $product_id = (int) $product_id;
    if ($product_id <= 0) {
      return self::get_default_label($key);
    }
    $map = self::get_label_map($product_id, $key);
    if (empty($map)) {
      return self::get_default_label($key);
    }
    $locale = self::get_locale();
    if (isset($map[$locale])) {
      return $map[$locale];
    }
    $short = substr($locale, 0, 2);
    foreach ($map as $loc => $label){
      if (strpos($loc, $short) === 0) {
        return $label;
      }
    }
    return reset($map) ?: self::get_default_label($key);
  }

  public static function get_label_map($product_id, $key){
    $meta_key = self::LABEL_FIELDS[$key] ?? '';
    if (!$meta_key) {
      return [];
    }
    $raw = get_post_meta($product_id, $meta_key, true);
    return self::parse_label_map($raw);
  }

  public static function get_frontend_labels($product_id){
    $keys = array_keys(self::LABEL_FIELDS);
    $out = [];
    foreach ($keys as $key){
      $out[$key] = self::get_label($product_id,$key);
    }
    return $out;
  }

  public static function get_primary_resource_id($product_id){
    $ids = self::get_resource_ids($product_id);
    if (!empty($ids)) {
      return (int) $ids[0];
    }
    return (int) get_post_meta($product_id,'_sbdp_resource_id',true);
  }

  public static function get_resource_ids($product_id){
    $stored = get_post_meta($product_id,'_sbdp_resource_ids',true);
    if (empty($stored)) {
      return [];
    }
    if (is_array($stored)) {
      return array_values(array_filter(array_map('intval',$stored)));
    }
    if (is_string($stored)) {
      $decoded = json_decode($stored,true);
      if (is_array($decoded)) {
        return array_values(array_filter(array_map('intval',$decoded)));
      }
    }
    return [];
  }

  public static function get_resources_payload($product_id){
    $ids = self::get_resource_ids($product_id);
    if (empty($ids)) {
      $primary = (int) get_post_meta($product_id,'_sbdp_resource_id',true);
      if ($primary) {
        $ids[] = $primary;
      }
    }
    $out = [];
    foreach ($ids as $resource_id){
      $post = get_post($resource_id);
      if (!$post || 'bookable_resource' !== $post->post_type) {
        continue;
      }
      $capacity = (int) get_post_meta($resource_id,'_sbdp_resource_capacity',true);
      if ($capacity < 0) {
        $capacity = 0;
      }
      $out[] = [
        'id'        => $resource_id,
        'title'     => get_the_title($resource_id),
        'capacity'  => $capacity
      ];
    }
    return $out;
  }

  public static function get_label_payload($product_id){
    $payload = [];
    foreach (array_keys(self::LABEL_FIELDS) as $key){
      $payload[$key] = self::get_label($product_id,$key);
    }
    return $payload;
  }

  public static function sanitize_resource_ids($input){
    if (empty($input)) {
      return [];
    }
    if (!is_array($input)) {
      $input = [$input];
    }
    return array_values(array_filter(array_map('intval',$input)));
  }

  private static function parse_label_map($raw){
    if (empty($raw)) {
      return [];
    }
    if (is_array($raw)) {
      $out = [];
      foreach ($raw as $locale => $label){
        $locale = trim($locale);
        $label = self::clean_label($label);
        if ($locale !== '' && $label !== '') {
          $out[$locale] = $label;
        }
      }
      return $out;
    }
    if (is_string($raw)) {
      $raw = trim($raw);
      $decoded = json_decode($raw, true);
      if (is_array($decoded)) {
        return self::parse_label_map($decoded);
      }
      $out = [];
      $chunks = preg_split('/[\r\n\|]+/', $raw);
      foreach ($chunks as $chunk){
        if (strpos($chunk,'=') === false && strpos($chunk,':') === false) {
          continue;
        }
        $parts = preg_split('/[:=]/',$chunk,2);
        if (count($parts) !== 2) {
          continue;
        }
        $locale = trim($parts[0]);
        $label = self::clean_label($parts[1]);
        if ($locale !== '' && $label !== '') {
          $out[$locale] = $label;
        }
      }
      return $out;
    }
    return [];
  }

  private static function clean_label($value){
    return trim(wp_strip_all_tags((string) $value));
  }

  private static function get_locale(){
    if (function_exists('determine_locale')) {
      return determine_locale();
    }
    return get_locale();
  }

  private static function get_default_label($key){
    switch ($key) {
      case 'start':
        return __('Starttijd','sbdp');
      case 'end':
        return __('Eindtijd','sbdp');
      case 'participants':
        return __('Deelnemers','sbdp');
      case 'resource':
        return __('Resource','sbdp');
      default:
        return isset(self::$defaults[$key]) ? self::$defaults[$key] : $key;
    }
  }
}


