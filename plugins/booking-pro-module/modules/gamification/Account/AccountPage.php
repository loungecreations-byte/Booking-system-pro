<?php
declare(strict_types=1);
namespace BSP\Gamification\Account;

final class AccountPage
{
    public const ENDPOINT = 'mijn-voortgang';
    public static function register(): void
    {
        add_action('init',array(__CLASS__,'endpoint'));
        add_filter('woocommerce_account_menu_items',array(__CLASS__,'menu'),30);
        add_action('woocommerce_account_'.self::ENDPOINT.'_endpoint',array(__CLASS__,'render'));
        add_action('wp_enqueue_scripts',array(__CLASS__,'assets'));
        add_filter('wp_robots',array(__CLASS__,'robots'));
    }
    public static function endpoint(): void { add_rewrite_endpoint(self::ENDPOINT,EP_ROOT|EP_PAGES); }
    public static function menu(array $items): array
    {
        $output = array();
        foreach ($items as $key=>$label) { $output[$key]=$label; if ($key==='dashboard') { $output[self::ENDPOINT]=__('Mijn voortgang','sbdp'); } }
        return $output;
    }
    public static function render(): void
    {
        echo '<section class="bsp-progress-account" aria-labelledby="bsp-progress-title"><h2 id="bsp-progress-title">'.esc_html__('Mijn voortgang','sbdp').'</h2><div id="bsp-gamification-progress" data-rest-url="'.esc_url(rest_url('bsp/v1/me/progress')).'" data-rest-nonce="'.esc_attr(wp_create_nonce('wp_rest')).'"></div></section>';
    }
    public static function assets(): void
    {
        if (! function_exists('is_wc_endpoint_url') || ! is_wc_endpoint_url(self::ENDPOINT)) { return; }
        $version = defined('SBDP_VER') ? SBDP_VER : '1.0.0';
        wp_enqueue_style('bsp-gamification',SBDP_URL.'modules/gamification/assets/gamification.css',array(),$version);
        $src = SBDP_URL.'build/js/gamificationProgress.js';
        if (function_exists('wp_enqueue_script_module')) { wp_enqueue_script_module('bsp-gamification-progress',$src,array(),$version); }
        else { wp_enqueue_script('bsp-gamification-progress',$src,array(),$version,true); add_filter('script_loader_tag',array(__CLASS__,'moduleTag'),10,2); }
    }
    public static function moduleTag(string $tag,string $handle): string { return $handle==='bsp-gamification-progress' ? str_replace('<script ','<script type="module" ',$tag) : $tag; }

    public static function robots(array $robots): array
    {
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url(self::ENDPOINT)) { $robots['noindex'] = true; }
        return $robots;
    }
}
