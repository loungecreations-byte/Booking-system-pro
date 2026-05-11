<?php

declare(strict_types=1);

namespace BSP\DayPlanner\PostType;

final class PlanPostType
{
    public const POST_TYPE = 'sbdp_plan';

    public static function register(): void
    {
        if (! \function_exists('register_post_type')) {
            return;
        }

        $labels = [
            'name'               => __('Day Plans', 'sbdp'),
            'singular_name'      => __('Day Plan', 'sbdp'),
            'menu_name'          => __('Day Planner', 'sbdp'),
            'add_new'            => __('Add Plan', 'sbdp'),
            'add_new_item'       => __('Create day plan', 'sbdp'),
            'edit_item'          => __('Edit day plan', 'sbdp'),
            'new_item'           => __('New day plan', 'sbdp'),
            'view_item'          => __('View day plan', 'sbdp'),
            'search_items'       => __('Search day plans', 'sbdp'),
            'not_found'          => __('No day plans found', 'sbdp'),
            'not_found_in_trash' => __('No day plans found in trash', 'sbdp'),
        ];

        $args = [
            'labels'              => $labels,
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => false,
            'supports'            => ['title', 'author'],
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'hierarchical'        => false,
            'rewrite'             => false,
            'query_var'           => false,
            'show_in_rest'        => false,
            'description'         => __('Serialized day planner itineraries and participants.', 'sbdp'),
        ];

        \register_post_type(self::POST_TYPE, $args);
    }
}
