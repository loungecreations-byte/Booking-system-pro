<?php

declare(strict_types=1);

namespace BSPModule\Core\PostType;

final class BookablePostTypes {

	private static bool $booted = false;

	public static function init(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		if ( did_action( 'init' ) ) {
			self::register();
		} else {
			add_action( 'init', array( __CLASS__, 'register' ) );
		}
	}

	public static function register(): void {
		register_post_type(
			'bookable_item',
			array(
				'label'           => __( 'Bookable Items', 'sbdp' ),
				'labels'          => array(
					'name'          => __( 'Bookable Items', 'sbdp' ),
					'singular_name' => __( 'Bookable Item', 'sbdp' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'show_in_rest'    => true,
				'supports'        => array( 'title', 'editor', 'thumbnail' ),
				'capability_type' => 'post',
				'hierarchical'    => false,
				'rewrite'         => false,
				'has_archive'     => false,
				'menu_position'   => null,
			)
		);

		register_post_type(
			'bookable_resource',
			array(
				'label'           => __( 'Resources', 'sbdp' ),
				'labels'          => array(
					'name'          => __( 'Resources', 'sbdp' ),
					'singular_name' => __( 'Resource', 'sbdp' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'show_in_rest'    => true,
				'supports'        => array( 'title', 'thumbnail' ),
				'capability_type' => 'post',
				'hierarchical'    => false,
				'rewrite'         => false,
				'has_archive'     => false,
				'menu_position'   => null,
			)
		);
	}
}
