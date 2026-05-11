<?php

declare(strict_types=1);

namespace SBDP\Modules\Arrangements\PostType;

use SBDP\Modules\Arrangements\Domain\ArrangementSchema;

use function add_filter;
use function register_post_type;
use function register_taxonomy;

final class ArrangementPostType
{
    public static function register(): void
    {
        if (! function_exists('register_post_type')) {
            return;
        }

        // The arrangement edit screen is a fully custom PHP meta-box UI.
        // Gutenberg saves via REST and never sends the $_POST nonce/data this form needs.
        add_filter('use_block_editor_for_post_type', static function (bool $use, string $postType): bool {
            if ($postType === ArrangementSchema::POST_TYPE) {
                return false;
            }
            return $use;
        }, 10, 2);

        register_post_type(
            ArrangementSchema::POST_TYPE,
            array(
                'label' => __('Arrangements', 'sbdp'),
                'labels' => array(
                    'name' => __('Arrangements', 'sbdp'),
                    'singular_name' => __('Arrangement', 'sbdp'),
                    'add_new_item' => __('Nieuw arrangement', 'sbdp'),
                    'edit_item' => __('Bewerk arrangement', 'sbdp'),
                ),
                'public' => false,
                'show_ui' => true,
                'show_in_menu' => false,
                'show_in_rest' => true,
                'supports' => array('title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'page-attributes'),
                'hierarchical' => false,
                'has_archive' => false,
                'rewrite' => false,
                'menu_position' => 56,
            )
        );

        register_taxonomy(
            ArrangementSchema::TAXONOMY_CATEGORY,
            array(ArrangementSchema::POST_TYPE),
            array(
                'label' => __('Arrangement categories', 'sbdp'),
                'hierarchical' => true,
                'show_ui' => true,
                'show_in_rest' => true,
                'rewrite' => false,
            )
        );

        register_taxonomy(
            ArrangementSchema::TAXONOMY_TAG,
            array(ArrangementSchema::POST_TYPE),
            array(
                'label' => __('Arrangement tags', 'sbdp'),
                'hierarchical' => false,
                'show_ui' => true,
                'show_in_rest' => true,
                'rewrite' => false,
            )
        );
    }
}
