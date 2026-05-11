<# =====================================================================
   Build Bookable Product (All-in-One)
   - Settings
   - People (+ types)
   - Costs & Discounts & Extra fees
   - Availability rules
   - Services (Combi deals)
   - Resources
   Compatible: WooCommerce Bookings & YITH Booking (hybride metakey mapping)
   Author: Codex CLI companion
   Run: pwsh -File scripts\build-bookable-product.ps1 -WpPath "C:\path\to\wordpress" [-ConfigPath ".\configs\product.json"]
===================================================================== #>

param(
  [Parameter(Mandatory = $true)]
  [string]$WpPath,

  [string]$ConfigPath
)

$resolvedWpPath = (Resolve-Path $WpPath).Path
$wpLoadPath = Join-Path $resolvedWpPath 'wp-load.php'
if (-not (Test-Path $wpLoadPath)) {
  throw "wp-load.php not found under '$resolvedWpPath'. Provide the WordPress root via -WpPath."
}

$script:PhpExecutable = (Get-Command -Name php -ErrorAction Stop).Source

function ConvertTo-Hashtable {
  param($InputObject)

  if ($InputObject -is [System.Collections.IDictionary]) {
    $result = @{}
    foreach ($key in $InputObject.Keys) {
      $result[$key] = ConvertTo-Hashtable $InputObject[$key]
    }
    return $result
  }

  if ($InputObject -is [System.Management.Automation.PSObject]) {
    $result = @{}
    foreach ($prop in $InputObject.PSObject.Properties) {
      $result[$prop.Name] = ConvertTo-Hashtable $prop.Value
    }
    return $result
  }

  if ($InputObject -is [System.Collections.IEnumerable] -and $InputObject -isnot [string]) {
    $collection = @()
    foreach ($item in $InputObject) {
      $collection += ,(ConvertTo-Hashtable $item)
    }
    return ,$collection
  }

  return $InputObject
}

function Merge-Config {
  param(
    [hashtable]$Base,
    [hashtable]$Override
  )

  foreach ($key in $Override.Keys) {
    if ($Base.ContainsKey($key) -and ($Base[$key] -is [hashtable]) -and ($Override[$key] -is [hashtable])) {
      Merge-Config -Base $Base[$key] -Override $Override[$key]
    }
    else {
      $Base[$key] = $Override[$key]
    }
  }
}

function Get-DefaultConfig {
  return @{
    Product = @{
      name        = "Dagje Den Bosch  Avondtour + Diner"
      slug        = "avondtour-den-bosch-diner"
      description = "Sfeervolle avondtour door Den Bosch met optioneel diner of borrel."
      base_price  = 89.00
      virtual     = $true
      status      = "publish"
    }
    Booking = @{
      duration              = 1          # 1 dag
      duration_unit         = "day"
      enable_range_picker   = $true
      whole_day             = $true
      requires_confirmation = $false
      user_can_cancel       = $true
      max_per_unit          = 5
      min_duration          = 1
      max_duration          = 7
      min_advance_days      = 0
      max_advance_days      = 365
      buffer_days           = 0
      checkin               = ""         # "09:00" optioneel
      checkout              = ""         # "17:00" optioneel
    }
    People = @{
      enabled           = $true
      count_as_separate = $false
      min               = 1
      max               = 12
      types_enabled     = $true
      types = @(
        @{ name = "Volwassene"; slug = "adult";     cost = 0;   desc = "18+" },
        @{ name = "Kind";      slug = "child";     cost = -20; desc = "t/m 12 jaar" },
        @{ name = "2-persoons kano"; slug = "duo-kano"; cost = 50; desc = "Prijs per kano (2p)" }
      )
    }
    Costs = @{
      base_cost_per_unit     = 89.00      # basisprijs per dag
      multiply_by_persons    = $true
      extra_price_per_person = 10.00
      fixed_base_cost        = 15.00
      weekly_discount_pct    = 10
      monthly_discount_pct   = 15
      lastminute_pct         = 5
      lastminute_days        = 2
      extra_costs = @(
        @{ title = "Boekingskosten"; amount = 4.95; apply = "per_booking" } # per_booking | per_person | per_duration
      )
    }
    Availability = @{
      default_bookable = "yes" # yes|no
      days_bookable    = @("friday","saturday","sunday") # of @("monday"..)
      exclude_dates    = @("2025-12-25","2025-12-26","2026-01-01")
      special_ranges   = @(
        @{ from = "2025-06-01"; to = "2025-09-01"; bookable = "yes" }
      )
    }
    Services = @(
      @{ name = "3-gangendiner bij Roels"; cost = 35; optional = $true;  desc = "Heerlijk diner bij Roels" },
      @{ name = "BBQ arrangement";         cost = 29; optional = $true;  desc = "BBQ op locatie" },
      @{ name = "Borrel arrangement";      cost = 19; optional = $true;  desc = "Drankjes en hapjes" },
      @{ name = "Bierproeverij";           cost = 25; optional = $true;  desc = "Lokale speciaalbieren" }
    )
    Resources = @(
      @{ name = "Gids Tiny"; qty = 1;  base_cost = 0; desc = "Lokale gids" },
      @{ name = "E-chopper set"; qty = 10; base_cost = 0; desc = "Voertuigen set" }
    )
  }
}

function Invoke-SbdpPhpTask {
  param(
    [string]$WpRoot,
    [hashtable]$Config
  )

  $tempDir = [System.IO.Path]::GetTempPath()
  $payloadPath = Join-Path $tempDir ("sbdp-config-" + [System.Guid]::NewGuid().ToString() + ".json")
  $scriptPath = Join-Path $tempDir ("sbdp-runner-" + [System.Guid]::NewGuid().ToString() + ".php")

  $phpCode = @'
<?php
declare(strict_types=1);

function sbdp_fail(string $message): void {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$root = $argv[1] ?? '';
$payloadFile = $argv[2] ?? '';
if ($root === '' || $payloadFile === '') {
    sbdp_fail('Missing required arguments.');
}
$root = rtrim($root, "/\\");
$wpLoad = $root . DIRECTORY_SEPARATOR . 'wp-load.php';
if (!file_exists($wpLoad)) {
    sbdp_fail('Cannot locate wp-load.php under ' . $root);
}
$rawPayload = file_get_contents($payloadFile);
if ($rawPayload === false) {
    sbdp_fail('Unable to read configuration payload.');
}
$config = json_decode($rawPayload, true);
if (!is_array($config)) {
    sbdp_fail('Configuration payload is not valid JSON.');
}

require_once $wpLoad;

if (!function_exists('wc_get_product')) {
    sbdp_fail('WooCommerce is not loaded. Activate WooCommerce before running this script.');
}
if (!function_exists('wp_insert_post')) {
    sbdp_fail('WordPress functions are unavailable after loading wp-load.php.');
}

function sbdp_out(string $message): void {
    fwrite(STDOUT, $message . PHP_EOL);
}

function sbdp_update_meta(int $postId, array $keys, $value): void {
    foreach ($keys as $metaKey) {
        update_post_meta($postId, $metaKey, $value);
    }
}

function sbdp_flag($value): string {
    return $value ? 'yes' : 'no';
}

function sbdp_apply_meta(array $map, int $postId, string $group, $value): void {
    if (!isset($map[$group])) {
        return;
    }
    sbdp_update_meta($postId, $map[$group], $value);
}

function sbdp_json($value): string {
    if (function_exists('wp_json_encode')) {
        $encoded = wp_json_encode($value);
    } else {
        $encoded = json_encode($value);
    }
    return is_string($encoded) ? $encoded : '[]';
}

$map = array(
    'has_persons' => array('_wc_booking_has_persons', '_booking_has_persons', '_yith_booking_has_people'),
    'min_persons' => array('_wc_booking_min_persons', '_booking_min_persons', '_yith_booking_min_people'),
    'max_persons' => array('_wc_booking_max_persons', '_booking_max_persons', '_yith_booking_max_people'),
    'person_types_enabled' => array('_wc_booking_person_types_enabled', '_booking_person_types_enabled', '_yith_booking_people_types_enabled'),
    'persons_separate_bookings' => array('_wc_booking_persons_count_as_separate_bookings', '_booking_persons_count_as_separate_bookings', '_yith_booking_people_sum_as_separate'),
    'duration' => array('_wc_booking_duration', '_booking_duration', '_yith_booking_duration'),
    'duration_unit' => array('_wc_booking_duration_unit', '_booking_duration_unit', '_yith_booking_duration_unit'),
    'range_picker' => array('_wc_booking_enable_range_picker', '_booking_enable_range_picker', '_yith_booking_enable_calendar_range'),
    'whole_day' => array('_wc_booking_all_day', '_booking_all_day', '_yith_booking_full_day'),
    'requires_confirmation' => array('_wc_booking_requires_confirmation', '_booking_requires_confirmation', '_yith_booking_requires_confirmation'),
    'user_can_cancel' => array('_wc_booking_user_can_cancel', '_booking_user_can_cancel', '_yith_booking_user_can_cancel'),
    'max_per_unit' => array('_wc_booking_max_bookings_per_block', '_booking_max_bookings_per_unit', '_yith_booking_max_bookings_per_unit'),
    'min_duration' => array('_wc_booking_min_duration', '_booking_min_duration', '_yith_booking_min_duration'),
    'max_duration' => array('_wc_booking_max_duration', '_booking_max_duration', '_yith_booking_max_duration'),
    'min_advance_days' => array('_wc_booking_min_date', '_booking_min_date', '_yith_booking_min_advance_reservation'),
    'max_advance_days' => array('_wc_booking_max_date', '_booking_max_date', '_yith_booking_max_advance_reservation'),
    'buffer_days' => array('_wc_booking_buffer', '_booking_buffer', '_yith_booking_buffer_days'),
    'checkin' => array('_wc_booking_checkin', '_booking_checkin', '_yith_booking_checkin_time'),
    'checkout' => array('_wc_booking_checkout', '_booking_checkout', '_yith_booking_checkout_time'),
    'base_cost' => array('_wc_booking_base_cost', '_booking_base_cost', '_yith_booking_price_base'),
    'multiply_by_persons' => array('_wc_booking_cost_multiply_persons', '_booking_cost_multiply_persons', '_yith_booking_multiply_by_people'),
    'person_extra_cost' => array('_wc_booking_person_cost', '_booking_person_cost', '_yith_booking_extra_price_per_person'),
    'fixed_base_cost' => array('_wc_booking_fixed_base_cost', '_booking_fixed_base_cost', '_yith_booking_fixed_base_cost'),
    'weekly_discount' => array('_wc_booking_weekly_discount', '_booking_weekly_discount', '_yith_booking_weekly_discount'),
    'monthly_discount' => array('_wc_booking_monthly_discount', '_booking_monthly_discount', '_yith_booking_monthly_discount'),
    'last_minute_discount' => array('_wc_booking_last_minute_discount', '_booking_last_minute_discount', '_yith_booking_last_minute_discount'),
    'last_minute_days' => array('_wc_booking_last_minute_days', '_booking_last_minute_days', '_yith_booking_last_minute_days'),
    'default_availability' => array('_wc_booking_default_availability', '_booking_default_availability', '_yith_booking_default_availability'),
    'booking_services' => array('_wc_booking_services', '_booking_services', '_yith_booking_services'),
    'booking_resources' => array('_wc_booking_resources', '_booking_resources', '_yith_booking_resources'),
);

$productCfg = $config['Product'] ?? array();
$bookingCfg = $config['Booking'] ?? array();
$peopleCfg = $config['People'] ?? array();
$costCfg = $config['Costs'] ?? array();
$availabilityCfg = $config['Availability'] ?? array();
$servicesCfg = $config['Services'] ?? array();
$resourcesCfg = $config['Resources'] ?? array();

sbdp_out('=== STEP 1: Create product ===');
$existing = null;
if (!empty($productCfg['slug'])) {
    $existing = get_page_by_path(sanitize_title($productCfg['slug']), OBJECT, 'product');
}
if (!$existing && !empty($productCfg['name'])) {
    $existing = get_page_by_title($productCfg['name'], OBJECT, 'product');
}

if ($existing instanceof WP_Post) {
    $productId = (int) $existing->ID;
    sbdp_out('Updating existing product ID: ' . $productId);
    $update = array(
        'ID' => $productId,
        'post_title' => $productCfg['name'] ?? '',
        'post_content' => $productCfg['description'] ?? '',
        'post_status' => $productCfg['status'] ?? 'publish',
    );
    if (!empty($productCfg['slug'])) {
        $update['post_name'] = sanitize_title($productCfg['slug']);
    }
    $result = wp_update_post($update, true);
    if (is_wp_error($result)) {
        sbdp_fail('Failed to update product: ' . $result->get_error_message());
    }
} else {
    $insert = array(
        'post_type' => 'product',
        'post_status' => $productCfg['status'] ?? 'publish',
        'post_title' => $productCfg['name'] ?? 'Automated Bookable Product',
        'post_content' => $productCfg['description'] ?? '',
        'post_name' => sanitize_title($productCfg['slug'] ?? ($productCfg['name'] ?? uniqid('booking-'))),
    );
    $productId = wp_insert_post($insert, true);
    if (is_wp_error($productId)) {
        sbdp_fail('Failed to create product: ' . $productId->get_error_message());
    }
    sbdp_out('Created product ID: ' . $productId);
}

update_post_meta($productId, '_regular_price', $productCfg['base_price'] ?? 0);
update_post_meta($productId, '_price', $productCfg['base_price'] ?? 0);
update_post_meta($productId, '_virtual', !empty($productCfg['virtual']) ? 'yes' : 'no');

$termResult = wp_insert_term('booking', 'product_type');
if (is_wp_error($termResult) && $termResult->get_error_code() !== 'term_exists') {
    sbdp_fail('Failed to ensure booking product type exists: ' . $termResult->get_error_message());
}
$setTerm = wp_set_object_terms($productId, 'booking', 'product_type');
if (is_wp_error($setTerm)) {
    sbdp_fail('Failed to set product type: ' . $setTerm->get_error_message());
}

sbdp_out('Product ID: ' . $productId);

sbdp_out("\n=== STEP 2: Booking settings ===");
sbdp_apply_meta($map, $productId, 'duration', $bookingCfg['duration'] ?? 1);
sbdp_apply_meta($map, $productId, 'duration_unit', $bookingCfg['duration_unit'] ?? 'day');
sbdp_apply_meta($map, $productId, 'range_picker', sbdp_flag($bookingCfg['enable_range_picker'] ?? false));
sbdp_apply_meta($map, $productId, 'whole_day', sbdp_flag($bookingCfg['whole_day'] ?? false));
sbdp_apply_meta($map, $productId, 'requires_confirmation', sbdp_flag($bookingCfg['requires_confirmation'] ?? false));
sbdp_apply_meta($map, $productId, 'user_can_cancel', sbdp_flag($bookingCfg['user_can_cancel'] ?? false));
sbdp_apply_meta($map, $productId, 'max_per_unit', $bookingCfg['max_per_unit'] ?? 0);
sbdp_apply_meta($map, $productId, 'min_duration', $bookingCfg['min_duration'] ?? 1);
sbdp_apply_meta($map, $productId, 'max_duration', $bookingCfg['max_duration'] ?? 1);
sbdp_apply_meta($map, $productId, 'min_advance_days', $bookingCfg['min_advance_days'] ?? 0);
sbdp_apply_meta($map, $productId, 'max_advance_days', $bookingCfg['max_advance_days'] ?? 0);
sbdp_apply_meta($map, $productId, 'buffer_days', $bookingCfg['buffer_days'] ?? 0);
if (!empty($bookingCfg['checkin'])) {
    sbdp_apply_meta($map, $productId, 'checkin', $bookingCfg['checkin']);
}
if (!empty($bookingCfg['checkout'])) {
    sbdp_apply_meta($map, $productId, 'checkout', $bookingCfg['checkout']);
}

sbdp_out("\n=== STEP 3: People ===");
sbdp_apply_meta($map, $productId, 'has_persons', sbdp_flag($peopleCfg['enabled'] ?? false));
sbdp_apply_meta($map, $productId, 'min_persons', $peopleCfg['min'] ?? 0);
sbdp_apply_meta($map, $productId, 'max_persons', $peopleCfg['max'] ?? 0);
sbdp_apply_meta($map, $productId, 'person_types_enabled', sbdp_flag($peopleCfg['types_enabled'] ?? false));
sbdp_apply_meta($map, $productId, 'persons_separate_bookings', sbdp_flag($peopleCfg['count_as_separate'] ?? false));

if (!empty($peopleCfg['types_enabled']) && !empty($peopleCfg['enabled']) && !empty($peopleCfg['types']) && is_array($peopleCfg['types'])) {
    foreach ($peopleCfg['types'] as $typeCfg) {
        $personPost = array(
            'post_type'   => 'bookable_person',
            'post_status' => 'publish',
            'post_title'  => $typeCfg['name'] ?? 'Person type',
            'post_parent' => $productId,
        );
        $personId = wp_insert_post($personPost, true);
        if (is_wp_error($personId)) {
            sbdp_fail('Failed to create person type "' . ($typeCfg['name'] ?? '') . '": ' . $personId->get_error_message());
        }
        update_post_meta($personId, '_person_type_base_cost', $typeCfg['cost'] ?? 0);
        update_post_meta($personId, '_person_type_description', $typeCfg['desc'] ?? '');
        update_post_meta($personId, '_person_type_slug', $typeCfg['slug'] ?? '');
        sbdp_out('  + Person type: ' . ($typeCfg['name'] ?? ('ID ' . $personId)));
    }
}

sbdp_out("\n=== STEP 4: Costs & discounts ===");
sbdp_apply_meta($map, $productId, 'base_cost', $costCfg['base_cost_per_unit'] ?? 0);
sbdp_apply_meta($map, $productId, 'multiply_by_persons', sbdp_flag($costCfg['multiply_by_persons'] ?? false));
sbdp_apply_meta($map, $productId, 'person_extra_cost', $costCfg['extra_price_per_person'] ?? 0);
sbdp_apply_meta($map, $productId, 'fixed_base_cost', $costCfg['fixed_base_cost'] ?? 0);
sbdp_apply_meta($map, $productId, 'weekly_discount', $costCfg['weekly_discount_pct'] ?? 0);
sbdp_apply_meta($map, $productId, 'monthly_discount', $costCfg['monthly_discount_pct'] ?? 0);
sbdp_apply_meta($map, $productId, 'last_minute_discount', $costCfg['lastminute_pct'] ?? 0);
sbdp_apply_meta($map, $productId, 'last_minute_days', $costCfg['lastminute_days'] ?? 0);

if (!empty($costCfg['extra_costs']) && is_array($costCfg['extra_costs'])) {
    foreach ($costCfg['extra_costs'] as $extraCfg) {
        $costPost = array(
            'post_type'   => 'bookable_cost',
            'post_status' => 'publish',
            'post_title'  => $extraCfg['title'] ?? 'Extra cost',
            'post_parent' => $productId,
        );
        $costId = wp_insert_post($costPost, true);
        if (is_wp_error($costId)) {
            sbdp_fail('Failed to create extra cost "' . ($extraCfg['title'] ?? '') . '": ' . $costId->get_error_message());
        }
        update_post_meta($costId, '_bookable_cost_amount', $extraCfg['amount'] ?? 0);
        update_post_meta($costId, '_bookable_cost_apply_to', $extraCfg['apply'] ?? 'per_booking');
        sbdp_out('  + Extra cost: ' . ($extraCfg['title'] ?? ('ID ' . $costId)));
    }
}

sbdp_out("\n=== STEP 5: Availability ===");
sbdp_apply_meta($map, $productId, 'default_availability', $availabilityCfg['default_bookable'] ?? 'yes');
$dayIndex = array(
    'sunday' => 0,
    'monday' => 1,
    'tuesday' => 2,
    'wednesday' => 3,
    'thursday' => 4,
    'friday' => 5,
    'saturday' => 6,
);
if (!empty($availabilityCfg['days_bookable']) && is_array($availabilityCfg['days_bookable'])) {
    foreach ($availabilityCfg['days_bookable'] as $day) {
        $key = strtolower((string) $day);
        if (!array_key_exists($key, $dayIndex)) {
            sbdp_out('  ! Unknown day: ' . $day);
            continue;
        }
        $availPost = array(
            'post_type'   => 'bookable_availability',
            'post_status' => 'publish',
            'post_parent' => $productId,
        );
        $availId = wp_insert_post($availPost, true);
        if (is_wp_error($availId)) {
            sbdp_fail('Failed to create availability for day "' . $day . '": ' . $availId->get_error_message());
        }
        update_post_meta($availId, '_bookable_availability_type', 'days');
        update_post_meta($availId, '_bookable_availability_day', $dayIndex[$key]);
        update_post_meta($availId, '_bookable_availability_bookable', 'yes');
        sbdp_out('  + Day bookable: ' . $day);
    }
}
if (!empty($availabilityCfg['exclude_dates']) && is_array($availabilityCfg['exclude_dates'])) {
    foreach ($availabilityCfg['exclude_dates'] as $dateValue) {
        $availPost = array(
            'post_type'   => 'bookable_availability',
            'post_status' => 'publish',
            'post_parent' => $productId,
        );
        $availId = wp_insert_post($availPost, true);
        if (is_wp_error($availId)) {
            sbdp_fail('Failed to create exclusion for ' . $dateValue . ': ' . $availId->get_error_message());
        }
        update_post_meta($availId, '_bookable_availability_type', 'custom');
        update_post_meta($availId, '_bookable_availability_from', $dateValue);
        update_post_meta($availId, '_bookable_availability_to', $dateValue);
        update_post_meta($availId, '_bookable_availability_bookable', 'no');
        sbdp_out('  - Exclude date: ' . $dateValue);
    }
}
if (!empty($availabilityCfg['special_ranges']) && is_array($availabilityCfg['special_ranges'])) {
    foreach ($availabilityCfg['special_ranges'] as $rangeCfg) {
        $availPost = array(
            'post_type'   => 'bookable_availability',
            'post_status' => 'publish',
            'post_parent' => $productId,
        );
        $availId = wp_insert_post($availPost, true);
        if (is_wp_error($availId)) {
            sbdp_fail('Failed to create special range: ' . $availId->get_error_message());
        }
        update_post_meta($availId, '_bookable_availability_type', 'range');
        update_post_meta($availId, '_bookable_availability_from', $rangeCfg['from'] ?? '');
        update_post_meta($availId, '_bookable_availability_to', $rangeCfg['to'] ?? '');
        update_post_meta($availId, '_bookable_availability_bookable', $rangeCfg['bookable'] ?? 'yes');
        sbdp_out('  * Special range: ' . ($rangeCfg['from'] ?? '') . ' -> ' . ($rangeCfg['to'] ?? '') . ' (' . ($rangeCfg['bookable'] ?? '') . ')');
    }
}

sbdp_out("\n=== STEP 6: Services ===");
$serviceIds = array();
if (!empty($servicesCfg) && is_array($servicesCfg)) {
    foreach ($servicesCfg as $serviceCfg) {
        $servicePost = array(
            'post_type'   => 'bookable_service',
            'post_status' => 'publish',
            'post_title'  => $serviceCfg['name'] ?? 'Service',
        );
        $serviceId = wp_insert_post($servicePost, true);
        if (is_wp_error($serviceId)) {
            sbdp_fail('Failed to create service "' . ($serviceCfg['name'] ?? '') . '": ' . $serviceId->get_error_message());
        }
        update_post_meta($serviceId, '_service_cost', $serviceCfg['cost'] ?? 0);
        update_post_meta($serviceId, '_service_optional', !empty($serviceCfg['optional']) ? 'yes' : 'no');
        update_post_meta($serviceId, '_service_description', $serviceCfg['desc'] ?? '');
        $serviceIds[] = (int) $serviceId;
        sbdp_out('  + Service: ' . ($serviceCfg['name'] ?? ('ID ' . $serviceId)));
    }
}
if (!empty($serviceIds)) {
    sbdp_apply_meta($map, $productId, 'booking_services', sbdp_json($serviceIds));
    update_post_meta($productId, '_booking_services_ids', implode(',', $serviceIds));
}

sbdp_out("\n=== STEP 7: Resources ===");
$resourceIds = array();
if (!empty($resourcesCfg) && is_array($resourcesCfg)) {
    foreach ($resourcesCfg as $resourceCfg) {
        $resourcePost = array(
            'post_type'   => 'bookable_resource',
            'post_status' => 'publish',
            'post_title'  => $resourceCfg['name'] ?? 'Resource',
            'post_parent' => $productId,
        );
        $resourceId = wp_insert_post($resourcePost, true);
        if (is_wp_error($resourceId)) {
            sbdp_fail('Failed to create resource "' . ($resourceCfg['name'] ?? '') . '": ' . $resourceId->get_error_message());
        }
        update_post_meta($resourceId, '_bookable_resource_qty', $resourceCfg['qty'] ?? 0);
        update_post_meta($resourceId, '_bookable_resource_base_cost', $resourceCfg['base_cost'] ?? 0);
        update_post_meta($resourceId, '_bookable_resource_description', $resourceCfg['desc'] ?? '');
        $resourceIds[] = (int) $resourceId;
        sbdp_out('  + Resource: ' . ($resourceCfg['name'] ?? ('ID ' . $resourceId)));
    }
}
if (!empty($resourceIds)) {
    sbdp_apply_meta($map, $productId, 'booking_resources', sbdp_json($resourceIds));
    update_post_meta($productId, '_booking_resources_ids', implode(',', $resourceIds));
}

sbdp_out("\nKlaar! Boekbaar product aangemaakt en volledig geconfigureerd. Product ID: " . $productId);
exit(0);
?>
'@

  try {
    $Config | ConvertTo-Json -Depth 10 | Set-Content -Path $payloadPath -Encoding UTF8
    Set-Content -Path $scriptPath -Encoding UTF8 -Value $phpCode
    $arguments = @($scriptPath, $WpRoot, $payloadPath)
    $output = & $script:PhpExecutable @arguments 2>&1
    $exitCode = $LASTEXITCODE
    $output | ForEach-Object { Write-Host $_ }
    if ($exitCode -ne 0) {
      throw "PHP runner exited with code $exitCode."
    }
  }
  finally {
    if (Test-Path $payloadPath) { Remove-Item $payloadPath -Force -ErrorAction SilentlyContinue }
    if (Test-Path $scriptPath) { Remove-Item $scriptPath -Force -ErrorAction SilentlyContinue }
  }
}

# -------------------------
# Config (pas aan naar wens)
# -------------------------
$Config = Get-DefaultConfig
if ($ConfigPath) {
  if (-not (Test-Path $ConfigPath)) {
    throw "Config file not found: $ConfigPath"
  }
  $jsonContent = Get-Content -Path $ConfigPath -Raw -ErrorAction Stop
  $overrideObject = ConvertFrom-Json -InputObject $jsonContent -Depth 50
  $override = ConvertTo-Hashtable $overrideObject
  if ($override -isnot [hashtable]) {
    throw "Configuration must resolve to a JSON object."
  }
  Merge-Config -Base $Config -Override $override
}

Invoke-SbdpPhpTask -WpRoot $resolvedWpPath -Config $Config
