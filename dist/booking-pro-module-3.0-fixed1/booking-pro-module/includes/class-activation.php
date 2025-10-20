<?php
if (!defined('ABSPATH')) exit;

class SBDP_Activation {
  const OPTION_SHOULD_SEED = 'sbdp_needs_demo_setup';

  public static function bootstrap(){
    add_action('admin_init',[__CLASS__,'maybe_seed_demo']);
  }

  public static function activate(){
    add_option(self::OPTION_SHOULD_SEED,'1');

    require_once SBDP_DIR.'includes/class-cpt.php';
    if (class_exists('SBDP_CPT')) {
      SBDP_CPT::register();
    }
    flush_rewrite_rules();
  }

  public static function deactivate(){
    delete_option(self::OPTION_SHOULD_SEED);
    flush_rewrite_rules();
  }

  public static function uninstall(){
    delete_option(self::OPTION_SHOULD_SEED);
  }

  public static function maybe_seed_demo(){
    if ('1' !== get_option(self::OPTION_SHOULD_SEED)) {
      return;
    }

    if (!class_exists('WooCommerce')) {
      return;
    }

    self::create_demo_products();
    self::ensure_planner_page();

    update_option(self::OPTION_SHOULD_SEED,'0');
  }

  private static function create_demo_products(){
    $products = [
      [
        'title'    => __('Stadswandeling','sbdp'),
        'slug'     => 'stadswandeling',
        'excerpt'  => __('Ontdek Den Bosch met gids','sbdp'),
        'price'    => 20,
        'duration' => 90
      ],
      [
        'title'    => __('Lunchpakket','sbdp'),
        'slug'     => 'lunchpakket',
        'excerpt'  => __('Smaakvolle lunch to go','sbdp'),
        'price'    => 15,
        'duration' => 60
      ],
      [
        'title'    => __('Avondeten','sbdp'),
        'slug'     => 'avondeten',
        'excerpt'  => __('Gezellig diner in de stad','sbdp'),
        'price'    => 29.95,
        'duration' => 90
      ],
    ];

    foreach ($products as $product_data){
      self::create_demo_product($product_data);
    }
  }

  private static function create_demo_product($product_data){
    $existing = get_page_by_path($product_data['slug'], OBJECT, 'product');
    if ($existing) {
      return (int) $existing->ID;
    }

    $product_id = wp_insert_post([
      'post_type'   => 'product',
      'post_status' => 'publish',
      'post_title'  => $product_data['title'],
      'post_name'   => $product_data['slug'],
      'post_excerpt'=> $product_data['excerpt']
    ]);

    if (!$product_id || is_wp_error($product_id)) {
      return 0;
    }

    wp_set_object_terms($product_id, 'bookable_service', 'product_type');
    update_post_meta($product_id, '_regular_price', $product_data['price']);
    update_post_meta($product_id, '_price', $product_data['price']);
    update_post_meta($product_id, '_sbdp_duration', $product_data['duration']);

    return (int) $product_id;
  }

  private static function ensure_planner_page(){
    $page = get_page_by_title(__('Plan je dag','sbdp'));
    if ($page) {
      return (int) $page->ID;
    }

    $page_id = wp_insert_post([
      'post_type'   => 'page',
      'post_status' => 'publish',
      'post_title'  => __('Plan je dag','sbdp'),
      'post_name'   => 'plan-je-dag',
      'post_content'=> '[sbdp_dayplanner]'
    ]);

    return (int) $page_id;
  }
}

