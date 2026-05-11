<?php

/**
 * Elementor theme builder document for private tours.
 *
 * @package Booking_Pro_Module
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Provides a dedicated Site Editor template type for private tours.
 */
class SBDP_Elementor_Private_Tour_Document extends \ElementorPro\Modules\ThemeBuilder\Documents\Single_Base
{
    public static function get_type()
    {
        return 'single-private-tour';
    }

    public static function get_title()
    {
        return __('Prive tour', 'sbdp');
    }

    public static function get_plural_title()
    {
        return __('Prive tours', 'sbdp');
    }

    public static function get_sub_type()
    {
        return SBDP_Private_Tours::POST_TYPE_TOUR;
    }

    protected static function get_site_editor_icon()
    {
        return 'eicon-single-post';
    }

    protected static function get_site_editor_thumbnail_url()
    {
        return ELEMENTOR_ASSETS_URL . 'images/app/site-editor/single-post.svg';
    }

    protected static function get_site_editor_tooltip_data()
    {
        return array(
            'title'     => esc_html__('Private tour template', 'sbdp'),
            'content'   => esc_html__('Design the layout for all private tour pages.', 'sbdp'),
            'tip'       => esc_html__('You can create multiple templates and target different tours.', 'sbdp'),
            'docs'      => 'https://go.elementor.com/app-theme-builder-post',
            'video_url' => 'https://www.youtube.com/embed/8Fk-Edu7DL0',
        );
    }

    protected function get_remote_library_config()
    {
        $config = parent::get_remote_library_config();
        $config['category'] = 'single private tour';

        return $config;
    }
}
