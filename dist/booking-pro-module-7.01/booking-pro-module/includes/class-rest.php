<?php
if (!defined('ABSPATH')) exit;

class SBDP_REST {
  public static function init(){
    add_action('rest_api_init',[__CLASS__,'routes']);
  }

  public static function routes(){
    register_rest_route('sbdp/v1','/services',[
      'methods'  => 'GET',
      'permission_callback' => '__return_true',
      'callback' => [__CLASS__,'get_services']
    ]);

    register_rest_route('sbdp/v1','/compose_booking',[
      'methods'  => 'POST',
      'permission_callback' => [__CLASS__,'verify_public_rest_access'],
      'callback' => [__CLASS__,'compose_booking']
    ]);

    register_rest_route('sbdp/v1','/availability/rules',[
      'methods'  => 'GET',
      'permission_callback' => function(){ return current_user_can('manage_woocommerce'); },
      'callback' => [__CLASS__,'get_rules']
    ]);

    register_rest_route('sbdp/v1','/availability/rules',[
      'methods'  => 'POST',
      'permission_callback' => function(){ return current_user_can('manage_woocommerce'); },
      'callback' => [__CLASS__,'save_rules']
    ]);

    register_rest_route('sbdp/v1','/availability/preview',[
      'methods'  => 'POST',
      'permission_callback' => function(){ return current_user_can('manage_woocommerce'); },
      'callback' => [__CLASS__,'preview_availability']
    ]);

    register_rest_route('sbdp/v1','/availability/plan',[
      'methods'  => 'GET',
      'permission_callback' => '__return_true',
      'callback' => [__CLASS__,'plan_availability']
    ]);

    register_rest_route('sbdp/v1','/resources',[
      'methods'  => 'GET',
      'permission_callback' => function(){ return current_user_can('manage_woocommerce'); },
      'callback' => [__CLASS__,'get_resources']
    ]);

    register_rest_route('sbdp/v1','/pricing/rules',[
      'methods'  => 'GET',
      'permission_callback' => function(){ return current_user_can('manage_woocommerce'); },
      'callback' => [__CLASS__,'get_pricing_rules']
    ]);

    register_rest_route('sbdp/v1','/pricing/rules',[
      'methods'  => 'POST',
      'permission_callback' => function(){ return current_user_can('manage_woocommerce'); },
      'callback' => [__CLASS__,'save_pricing_rules']
    ]);

    register_rest_route('sbdp/v1','/pricing/preview',[
      'methods'  => 'POST',
      'permission_callback' => [__CLASS__,'verify_public_rest_access'],
      'callback' => [__CLASS__,'preview_pricing']
    ]);
  }

  public static function get_services(WP_REST_Request $request){
    $q = new WP_Query([
      'post_type'      => 'product',
      'post_status'    => 'publish',
      'posts_per_page' => -1,
      'tax_query'      => [[
        'taxonomy' => 'product_type',
        'field'    => 'slug',
        'terms'    => ['bookable_service']
      ]]
    ]);

    $services = [];
    while($q->have_posts()){
      $q->the_post();
      $pid = get_the_ID();
      $services[] = [
        'id'          => $pid,
        'name'        => get_the_title(),
        'price'       => (float) get_post_meta($pid,'_price',true),
        'duration'    => (int) (get_post_meta($pid,'_sbdp_duration',true) ?: 60),
        'resource_id' => (int) get_post_meta($pid,'_sbdp_resource_id',true),
        'thumb'       => get_the_post_thumbnail_url($pid,'thumbnail'),
        'excerpt'     => wp_strip_all_tags(get_the_excerpt($pid))
      ];
    }
    wp_reset_postdata();

    return rest_ensure_response($services);
  }

  public static function compose_booking(WP_REST_Request $request){
    $payload      = $request->get_json_params();
    $mode         = sanitize_text_field($payload['mode'] ?? 'pay');
    if(!in_array($mode, ['pay','request'], true)){
      $mode = 'pay';
    }
    $participants = max(1, intval($payload['participants'] ?? 1));
    $items        = self::sanitize_items($payload['items'] ?? []);

    if(empty($items)){
      return new WP_Error('sbdp_no_items',__('Geen geldige items ontvangen.','sbdp'),['status'=>400]);
    }

    $validation = self::validate_items($items,$participants);
    if(is_wp_error($validation)){
      return $validation;
    }

    if($mode === 'pay'){
      if(!function_exists('WC')){
        return new WP_Error('sbdp_no_wc',__('WooCommerce niet beschikbaar.','sbdp'),['status'=>500]);
      }

      self::ensure_cart_session();
      if(!WC()->cart){
        return new WP_Error('sbdp_no_cart',__('Winkelwagen kon niet worden geopend.','sbdp'),['status'=>500]);
      }

      WC()->cart->empty_cart();
      $added = false;

      foreach($items as $item){
        $product = wc_get_product($item['product_id']);
        if(!$product){
          return new WP_Error('sbdp_invalid_product',__('Ongeldige productreferentie.','sbdp'),['status'=>400]);
        }

        $resource_id = intval($item['resource_id'] ?? get_post_meta($item['product_id'],'_sbdp_resource_id',true));
        $resource_label = $resource_id ? sanitize_text_field(get_the_title($resource_id)) : '';
        $pricing = self::calculate_pricing_for_item($product,$resource_id,$item['start'],$participants);

        $cart_key = WC()->cart->add_to_cart(
          $item['product_id'],
          $participants,
          0,
          [],
          [
            'sbdp_meta' => [
              'sbdp_start'          => $item['start'],
              'sbdp_end'            => $item['end'],
              'sbdp_participants'   => $participants,
              'sbdp_resource_id'    => $resource_id,
              'sbdp_resource_label' => $resource_label
            ]
          ]
        );

        if($cart_key){
          $added = true;
          if(isset(WC()->cart->cart_contents[$cart_key])){
            $cart_item = WC()->cart->cart_contents[$cart_key];
            if(isset($cart_item['data']) && $cart_item['data'] instanceof WC_Product){
              $cart_item['data']->set_price($pricing['unit_price']);
            }
            $cart_item['sbdp_pricing'] = $pricing;
            WC()->cart->cart_contents[$cart_key] = $cart_item;
          }
        }
      }

      if(!$added){
        return new WP_Error('sbdp_cart_failed',__('Kon geen items aan de winkelwagen toevoegen.','sbdp'),['status'=>500]);
      }

      if(WC()->cart){
        WC()->cart->calculate_totals();
      }

      return rest_ensure_response(['ok'=>true,'redirect'=>wc_get_checkout_url()]);
    }

    $order = wc_create_order();
    if(is_wp_error($order)){
      return $order;
    }

    $has_items = false;
    foreach($items as $item){
      $product = wc_get_product($item['product_id']);
      if(!$product){
        continue;
      }

      $qty     = $participants;
      $item_id = $order->add_product($product,$qty);
      if($item_id){
        $has_items = true;
        $resource_id = intval($item['resource_id'] ?? get_post_meta($item['product_id'],'_sbdp_resource_id',true));
        $resource_label = $resource_id ? sanitize_text_field(get_the_title($resource_id)) : '';
        $pricing = self::calculate_pricing_for_item($product,$resource_id,$item['start'],$participants);
        wc_add_order_item_meta($item_id,'sbdp_start',$item['start']);
        wc_add_order_item_meta($item_id,'sbdp_end',$item['end']);
        wc_add_order_item_meta($item_id,'sbdp_participants',$qty);
        wc_add_order_item_meta($item_id,'sbdp_resource_id',$resource_id);
        if($resource_label){
          wc_add_order_item_meta($item_id,'sbdp_resource_label',$resource_label);
        }
        wc_add_order_item_meta($item_id,'_sbdp_pricing',$pricing);

        $order_item = $order->get_item($item_id);
        if($order_item instanceof WC_Order_Item_Product){
          $line_total = round($pricing['unit_price'] * $qty, 2);
          $order_item->set_subtotal($line_total);
          $order_item->set_total($line_total);
          $order_item->save();
        }
      }
    }

    if(!$has_items){
      return new WP_Error('sbdp_order_failed',__('Kon geen items aan de order toevoegen.','sbdp'),['status'=>500]);
    }

    $order->calculate_totals();
    $order->update_status('on-hold','Concept programma via planner');
    $order->update_meta_data('sbdp_mode','request');
    $order->save();

    return rest_ensure_response(['ok'=>true,'redirect'=>$order->get_view_order_url()]);
  }

  public static function get_rules(WP_REST_Request $request){
    $product_id  = intval($request->get_param('product_id'));
    $resource_id = intval($request->get_param('resource_id'));

    if(!$product_id){
      return new WP_Error('bad_request','product_id required',['status'=>400]);
    }

    $key   = $resource_id ? "_sbdp_av_rules_res_{$resource_id}" : '_sbdp_av_rules';
    $rules = get_post_meta($product_id,$key,true);

    $context = self::resolve_capacity($product_id,$resource_id);

    if(!is_array($rules)){
      $rules = [
        'default'          => 'open',
        'exclude_weekdays' => [],
        'exclude_months'   => [],
        'exclude_times'    => [],
        'overrides'        => []
      ];
    }

    return [
      'rules'             => $rules,
      'capacity'          => $context['capacity'],
      'capacity_source'   => $context['source'],
      'resource_capacity' => $context['resource_capacity']
    ];
  }

  public static function save_rules(WP_REST_Request $request){
    $payload     = $request->get_json_params();
    $product_id  = intval($payload['product_id'] ?? 0);
    $resource_id = intval($payload['resource_id'] ?? 0);
    $rules       = $payload['rules'] ?? null;
    $capacity    = intval($payload['capacity'] ?? 1);
    $capacity_mode = sanitize_text_field($payload['capacity_mode'] ?? 'product');
    if($capacity < 0){
      $capacity = 0;
    }
    if(!in_array($capacity_mode,['resource','product'],true)){
      $capacity_mode = 'product';
    }
    if(!$resource_id && 'resource' === $capacity_mode){
      $capacity_mode = 'product';
    }

    if(!$product_id || !is_array($rules)){
      return new WP_Error('bad_request','product_id & rules required',['status'=>400]);
    }

    $key = $resource_id ? "_sbdp_av_rules_res_{$resource_id}" : '_sbdp_av_rules';
    update_post_meta($product_id,$key,$rules);

    $cap_key = $resource_id ? "_sbdp_capacity_res_{$resource_id}" : '_sbdp_capacity_default';
    if($resource_id && 'resource' === $capacity_mode){
      delete_post_meta($product_id,$cap_key);
    } else {
      update_post_meta($product_id,$cap_key,$capacity);
    }

    $context = self::resolve_capacity($product_id,$resource_id);

    return [
      'ok'               => true,
      'capacity'         => $context['capacity'],
      'capacity_source'  => $context['source'],
      'resource_capacity'=> $context['resource_capacity']
    ];
  }

  public static function preview_availability(WP_REST_Request $request){
    $payload     = $request->get_json_params();
    $product_id  = intval($payload['product_id'] ?? 0);
    $resource_id = intval($payload['resource_id'] ?? 0);
    $date        = sanitize_text_field($payload['date'] ?? '');

    if(!$product_id || !$date){
      return new WP_Error('bad_request','product_id & date required',['status'=>400]);
    }

    $key   = $resource_id ? "_sbdp_av_rules_res_{$resource_id}" : '_sbdp_av_rules';
    $rules = get_post_meta($product_id,$key,true);
    if(!is_array($rules)){
      $rules = [];
    }

    $blocks = self::blocks_for_date($date,$rules);

    $context = self::resolve_capacity($product_id,$resource_id);

    return [
      'blocks'            => $blocks,
      'capacity'          => $context['capacity'],
      'capacity_source'   => $context['source'],
      'resource_capacity' => $context['resource_capacity']
    ];
  }

  public static function plan_availability(WP_REST_Request $request){
    $product_id  = intval($request->get_param('product_id'));
    $resource_id = intval($request->get_param('resource_id'));
    $date        = sanitize_text_field($request->get_param('date'));

    if(!$product_id || !$date){
      return new WP_Error('bad_request','product_id & date required',['status'=>400]);
    }

    $key   = $resource_id ? "_sbdp_av_rules_res_{$resource_id}" : '_sbdp_av_rules';
    $rules = get_post_meta($product_id,$key,true);
    if(!is_array($rules)){
      $rules = [];
    }

    $blocks = self::blocks_for_date($date,$rules);

    $context = self::resolve_capacity($product_id,$resource_id);

    return [
      'blocks'            => $blocks,
      'capacity'          => $context['capacity'],
      'capacity_source'   => $context['source'],
      'resource_capacity' => $context['resource_capacity']
    ];
  }

  public static function get_resources(WP_REST_Request $request){
    $resources = get_posts([
      'post_type'      => 'bookable_resource',
      'post_status'    => 'publish',
      'posts_per_page' => -1,
      'orderby'        => 'title',
      'order'          => 'ASC'
    ]);

    $out = [];
    foreach($resources as $resource){
      $resource_capacity = (int) get_post_meta($resource->ID,'_sbdp_resource_capacity',true);
      if($resource_capacity < 0){
        $resource_capacity = 0;
      }
      $out[] = [
        'id'       => (int) $resource->ID,
        'title'    => get_the_title($resource),
        'capacity' => $resource_capacity
      ];
    }

    return rest_ensure_response($out);
  }

  public static function get_pricing_rules(WP_REST_Request $request){
    $product_id  = intval($request->get_param('product_id'));
    $resource_id = intval($request->get_param('resource_id'));

    if(!$product_id){
      return new WP_Error('bad_request','product_id required',['status'=>400]);
    }

    $key   = $resource_id ? "_sbdp_price_rules_res_{$resource_id}" : '_sbdp_price_rules';
    $rules = get_post_meta($product_id,$key,true);

    if(!is_array($rules)){
      $rules = [];
    }

    return rest_ensure_response(['rules'=>$rules]);
  }

  public static function save_pricing_rules(WP_REST_Request $request){
    $payload     = $request->get_json_params();
    $product_id  = intval($payload['product_id'] ?? 0);
    $resource_id = intval($payload['resource_id'] ?? 0);
    $rules_raw   = $payload['rules'] ?? [];

    if(!$product_id || !is_array($rules_raw)){
      return new WP_Error('bad_request','product_id & rules required',['status'=>400]);
    }

    $rules = self::sanitize_price_rules($rules_raw);

    $key = $resource_id ? "_sbdp_price_rules_res_{$resource_id}" : '_sbdp_price_rules';
    update_post_meta($product_id,$key,$rules);

    return rest_ensure_response(['ok'=>true]);
  }

  public static function preview_pricing(WP_REST_Request $request){
    $payload      = $request->get_json_params();
    $product_id   = intval($payload['product_id'] ?? 0);
    $resource_id  = intval($payload['resource_id'] ?? 0);
    $participants = max(1, intval($payload['participants'] ?? 1));
    $start        = sanitize_text_field($payload['start'] ?? '');

    if(!$product_id || !$start){
      return new WP_Error('bad_request','product_id & start required',['status'=>400]);
    }

    $product = wc_get_product($product_id);
    if(!$product){
      return new WP_Error('sbdp_invalid_product',__('Ongeldige productreferentie.','sbdp'),['status'=>400]);
    }

    $pricing = self::calculate_pricing_for_item($product,$resource_id,$start,$participants);

    return rest_ensure_response($pricing);
  }
  private static function sanitize_price_rules($rules){
    $out = [];

    foreach($rules as $rule){
      $clean = [
        'label'      => sanitize_text_field($rule['label'] ?? ''),
        'type'       => sanitize_text_field($rule['type'] ?? 'fixed'),
        'amount'     => (float) ($rule['amount'] ?? 0),
        'apply_to'   => sanitize_text_field($rule['apply_to'] ?? 'booking'),
        'weekdays'   => [],
        'time_from'  => '',
        'time_to'    => '',
        'date_from'  => '',
        'date_to'    => ''
      ];

      if(!in_array($clean['type'],['fixed','percent'],true)){
        $clean['type'] = 'fixed';
      }

      if(!in_array($clean['apply_to'],['booking','participant'],true)){
        $clean['apply_to'] = 'booking';
      }

      if(isset($rule['weekdays']) && is_array($rule['weekdays'])){
        foreach($rule['weekdays'] as $weekday){
          $wd = (int) $weekday;
          if($wd >= 0 && $wd <= 6){
            $clean['weekdays'][] = $wd;
          }
        }
      }

      if(!empty($rule['time_from'])){
        $clean['time_from'] = preg_replace('/[^0-9:]/','',substr($rule['time_from'],0,5));
      }

      if(!empty($rule['time_to'])){
        $clean['time_to'] = preg_replace('/[^0-9:]/','',substr($rule['time_to'],0,5));
      }

      if(!empty($rule['date_from'])){
        $clean['date_from'] = sanitize_text_field($rule['date_from']);
      }

      if(!empty($rule['date_to'])){
        $clean['date_to'] = sanitize_text_field($rule['date_to']);
      }

      $out[] = $clean;
    }

    return $out;
  }

  private static function get_local_datetime($iso){
    try {
      $dt = new DateTimeImmutable($iso);
    } catch(Exception $e){
      return null;
    }

    try {
      $timezone = wp_timezone();
      return $dt->setTimezone($timezone);
    } catch(Exception $e){
      return $dt;
    }
  }

  private static function check_item_rules($product_id,$resource_id,$start,$end,$participants){
    $start_dt = self::get_local_datetime($start);
    $end_dt   = self::get_local_datetime($end);

    if(!$start_dt || !$end_dt){
      return new WP_Error('sbdp_bad_time',__('Ongeldige datum of tijd ontvangen.','sbdp'),['status'=>400]);
    }

    $date = $start_dt->format('Y-m-d');

    $rules_key = $resource_id ? "_sbdp_av_rules_res_{$resource_id}" : '_sbdp_av_rules';
    $rules = get_post_meta($product_id,$rules_key,true);
    if(!is_array($rules)){
      $rules = [];
    }

    $blocks = self::blocks_for_date($date,$rules);
    foreach($blocks as $block){
      $block_start = $block['start'] ?? '';
      $block_end   = $block['end'] ?? '';
      if(self::ranges_overlap($start,$end,$block_start,$block_end)){
        return new WP_Error('sbdp_conflict',__('De geselecteerde tijd is niet beschikbaar.','sbdp'),['status'=>400]);
      }
    }

    $context = self::resolve_capacity($product_id,$resource_id);
    $capacity = $context['capacity'];
    if($capacity && $participants > $capacity){
      return new WP_Error(
        'sbdp_capacity',
        sprintf(__('Maximaal %d deelnemers toegestaan voor dit product.','sbdp'),$capacity),
        ['status'=>400]
      );
    }

    return true;
  }

  private static function ranges_overlap($start,$end,$block_start,$block_end){
    $start_ts = strtotime($start);
    $end_ts   = strtotime($end);
    $block_start_ts = strtotime($block_start);
    $block_end_ts   = strtotime($block_end);

    if(!$start_ts || !$end_ts || !$block_start_ts || !$block_end_ts){
      return false;
    }

    return ($block_end_ts > $start_ts) && ($block_start_ts < $end_ts);
  }

  private static function resolve_capacity(
    int $product_id,
    int $resource_id
  ){
    $capacity_key   = $resource_id ? "_sbdp_capacity_res_{$resource_id}" : '_sbdp_capacity_default';
    $raw_capacity   = get_post_meta($product_id,$capacity_key,true);

    $resource_capacity = 0;
    if ($resource_id) {
      $resource_capacity = (int) get_post_meta($resource_id,'_sbdp_resource_capacity',true);
      if ($resource_capacity < 0) {
        $resource_capacity = 0;
      }
    }

    $capacity = 0;
    $source   = 'product';

    if ($resource_id && ('' === $raw_capacity || null === $raw_capacity)) {
      $capacity = $resource_capacity;
      $source   = 'resource';
    } else {
      $capacity = (int) $raw_capacity;
      if ($capacity < 0) {
        $capacity = 0;
      }
    }

    return [
      'capacity'          => $capacity,
      'source'            => $source,
      'resource_capacity' => $resource_capacity
    ];
  }
  private static function get_price_rules_for($product_id,$resource_id){
    $rules = [];
    $global_rules = get_post_meta($product_id,'_sbdp_price_rules',true);
    if(is_array($global_rules)){
      $rules = array_merge($rules,$global_rules);
    }

    if($resource_id){
      $resource_rules = get_post_meta($product_id,"_sbdp_price_rules_res_{$resource_id}",true);
      if(is_array($resource_rules)){
        $rules = array_merge($rules,$resource_rules);
      }
    }

    return $rules;
  }

  private static function price_rule_applies($rule,DateTimeImmutable $moment){
    $weekday = (int) $moment->format('w');
    $date    = $moment->format('Y-m-d');
    $time    = $moment->format('H:i');

    if(!empty($rule['weekdays']) && is_array($rule['weekdays'])){
      if(!in_array($weekday,array_map('intval',$rule['weekdays']),true)){
        return false;
      }
    }

    if(!empty($rule['date_from']) && $date < $rule['date_from']){
      return false;
    }
    if(!empty($rule['date_to']) && $date > $rule['date_to']){
      return false;
    }

    if(!empty($rule['time_from']) && $time < $rule['time_from']){
      return false;
    }
    if(!empty($rule['time_to']) && $time > $rule['time_to']){
      return false;
    }

    return true;
  }

  private static function calculate_pricing_for_item($product,$resource_id,$start,$participants){
    $base_price = (float) $product->get_price();
    $moment     = self::get_local_datetime($start);

    $breakdown = [
      'base_price'         => round($base_price,2),
      'unit_price'         => round($base_price,2),
      'booking_adjustment' => 0.0,
      'applied_rules'      => [],
      'participants'       => $participants,
      'total'              => round($base_price * $participants,2)
    ];

    if(!$moment){
      return $breakdown;
    }

    $rules = self::get_price_rules_for($product->get_id(),$resource_id);
    if(empty($rules)){
      return $breakdown;
    }

    $unit_price = $base_price;
    $booking_adjustment = 0.0;

    foreach($rules as $rule){
      if(!self::price_rule_applies($rule,$moment)){
        continue;
      }

      $type      = $rule['type'] ?? 'fixed';
      $scope     = $rule['apply_to'] ?? 'booking';
      $amount    = (float) ($rule['amount'] ?? 0);
      $applied   = 0.0;

      if('percent' === $type){
        if('participant' === $scope){
          $applied = $base_price * ($amount / 100);
          $unit_price += $applied;
        } else {
          $applied = ($base_price * $participants) * ($amount / 100);
          $booking_adjustment += $applied;
        }
      } else {
        if('participant' === $scope){
          $applied = $amount;
          $unit_price += $applied;
        } else {
          $applied = $amount;
          $booking_adjustment += $applied;
        }
      }

      $breakdown['applied_rules'][] = [
        'label'      => $rule['label'],
        'scope'      => $scope,
        'type'       => $type,
        'amount'     => round($applied,2)
      ];
    }

    if($booking_adjustment !== 0 && $participants > 0){
      $unit_price += ($booking_adjustment / $participants);
    }

    $unit_price = max(0, $unit_price);
    $breakdown['unit_price'] = round($unit_price,2);
    $breakdown['booking_adjustment'] = round($booking_adjustment,2);
    $breakdown['total'] = round($breakdown['unit_price'] * $participants,2);

    return $breakdown;
  }

  private static function validate_items($items,$participants){
    if(empty($items)){
      return true;
    }

    foreach($items as $item){
      $start = strtotime($item['start']);
      $end   = strtotime($item['end']);
      if(!$start || !$end){
        return new WP_Error('sbdp_bad_time',__('Ongeldige datum of tijd ontvangen.','sbdp'),['status'=>400]);
      }
      if($end <= $start){
        return new WP_Error('sbdp_bad_range',__('Eindtijd moet later zijn dan starttijd.','sbdp'),['status'=>400]);
      }
      if($start < current_time('timestamp')){
        return new WP_Error('sbdp_past_time',__('De geselecteerde tijd mag niet in het verleden liggen.','sbdp'),['status'=>400]);
      }

      $check = self::check_item_rules(
        intval($item['product_id']),
        intval($item['resource_id'] ?? 0),
        $item['start'],
        $item['end'],
        $participants
      );
      if(is_wp_error($check)){
        return $check;
      }
    }

    return true;
  }

  private static function sanitize_items($items){
    $out = [];
    if(!is_array($items)){
      return $out;
    }

    foreach($items as $entry){
      $pid      = isset($entry['product_id']) ? (int) $entry['product_id'] : 0;
      $start    = isset($entry['start']) ? sanitize_text_field($entry['start']) : '';
      $end      = isset($entry['end']) ? sanitize_text_field($entry['end']) : '';
      $resource = isset($entry['resource_id']) ? (int) $entry['resource_id'] : 0;

      if(!$pid || !$start || !$end){
        continue;
      }

      if(!$resource){
        $resource = (int) get_post_meta($pid,'_sbdp_resource_id',true);
      }

      $out[] = [
        'product_id' => $pid,
        'start'      => $start,
        'end'        => $end,
        'resource_id'=> $resource
      ];
    }

    return $out;
  }

  private static function ensure_cart_session(){
    if(!function_exists('WC')){
      return;
    }

    if(null === WC()->session && method_exists(WC(),'initialize_session')){
      WC()->initialize_session();
    }

    if(function_exists('wc_load_cart')){
      if(null === WC()->cart || !WC()->cart){
        wc_load_cart();
      }
    } elseif(null === WC()->cart && class_exists('WC_Cart')){
      WC()->cart = new WC_Cart();
    }
  }

  private static function blocks_for_date($date,$rules){
    $blocks = [];
    $start  = $date.'T10:00:00';
    $end    = $date.'T24:00:00';

    $default = $rules['default'] ?? 'open';
    if('closed' === $default){
      $blocks[] = ['start'=>$start,'end'=>$end,'display'=>'background','color'=>'#fee2e2'];
    }

    if(!empty($rules['exclude_weekdays'])){
      $dow = (int) date('w',strtotime($date));
      if(in_array($dow,array_map('intval',$rules['exclude_weekdays']),true)){
        $blocks[] = ['start'=>$start,'end'=>$end,'display'=>'background','color'=>'#fecaca'];
      }
    }

    if(!empty($rules['exclude_months'])){
      $month = (int) date('n',strtotime($date));
      if(in_array($month,array_map('intval',$rules['exclude_months']),true)){
        $blocks[] = ['start'=>$start,'end'=>$end,'display'=>'background','color'=>'#fecaca'];
      }
    }

    if(!empty($rules['exclude_times']) && is_array($rules['exclude_times'])){
      foreach($rules['exclude_times'] as $time){
        $s = $date.'T'.sanitize_text_field($time['start'] ?? '00:00').':00';
        $e = $date.'T'.sanitize_text_field($time['end'] ?? '00:00').':00';
        $blocks[] = ['start'=>$s,'end'=>$e,'display'=>'background','color'=>'#fca5a5'];
      }
    }

    if(!empty($rules['overrides']) && is_array($rules['overrides'])){
      $midday = strtotime($date.' 12:00');
      foreach($rules['overrides'] as $override){
        $from = strtotime(($override['from'] ?? '').' 00:00');
        $to   = strtotime(($override['to'] ?? '').' 23:59');
        if(!$from || !$to){
          continue;
        }
        if($midday >= $from && $midday <= $to){
          $mode = $override['mode'] ?? 'closed';
          if('closed' === $mode){
            $blocks[] = ['start'=>$start,'end'=>$end,'display'=>'background','color'=>'#f87171'];
          } else {
            $blocks = [];
          }
        }
      }
    }

    return $blocks;
  }

  public static function verify_public_rest_access( WP_REST_Request $request ) {
    $nonce = $request->get_header( 'X-WP-Nonce' );
    if ( ! $nonce && isset( $_REQUEST['_wpnonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
      $nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    }

    if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
      return true;
    }

    if ( is_user_logged_in() ) {
      return current_user_can( 'read' );
    }

    return new WP_Error(
      'sbdp_rest_forbidden',
      __( 'Authentication failed. Refresh the page and try again.', 'sbdp' ),
      [ 'status' => 401 ]
    );
  }
}


