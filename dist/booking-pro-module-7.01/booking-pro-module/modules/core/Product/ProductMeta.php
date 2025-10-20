<?php

declare(strict_types=1);

namespace BSPModule\Core\Product;

use WP_Post;

final class ProductMeta {

	/**
	 * @var array<string,string>
	 */
	private const LABEL_FIELDS = array(
		'start'        => '_sbdp_label_map_start',
		'end'          => '_sbdp_label_map_end',
		'participants' => '_sbdp_label_map_participants',
		'resource'     => '_sbdp_label_map_resource',
	);

	/**
	 * @var array<string,string>
	 */
	private const DEFAULT_LABELS = array(
		'start'        => 'Starttijd',
		'end'          => 'Eindtijd',
		'participants' => 'Deelnemers',
		'resource'     => 'Resource',
	);

	public static function get_label( int $product_id, string $key ): string {
		if ( $product_id <= 0 ) {
			return self::get_default_label( $key );
		}

		$map = self::get_label_map( $product_id, $key );
		if ( $map === array() ) {
			return self::get_default_label( $key );
		}

		$locale = self::get_locale();
		if ( isset( $map[ $locale ] ) ) {
			return $map[ $locale ];
		}

		$short = substr( $locale, 0, 2 );
		foreach ( $map as $loc => $label ) {
			if ( ! is_string( $loc ) ) {
				if ( is_scalar( $loc ) ) {
					$loc = (string) $loc;
				} else {
					continue;
				}
			}

			$loc = trim( $loc );
			if ( $loc === '' ) {
				continue;
			}

			if ( strpos( $loc, $short ) === 0 ) {
				return $label;
			}
		}
		$first = reset( $map );
		if ( is_string( $first ) && $first !== '' ) {
			return $first;
		}

		return self::get_default_label( $key );
	}

	/**
	 * @return array<string,string>
	 */
	public static function get_label_map( int $product_id, string $key ): array {
		$meta_key = self::LABEL_FIELDS[ $key ] ?? '';
		if ( $meta_key === '' ) {
			return array();
		}

		$raw = get_post_meta( $product_id, $meta_key, true );

		return self::parse_label_map( $raw );
	}

	/**
	 * @return array<string,string>
	 */
	public static function get_frontend_labels( int $product_id ): array {
		$out = array();
		foreach ( array_keys( self::LABEL_FIELDS ) as $key ) {
			$out[ $key ] = self::get_label( $product_id, $key );
		}

		return $out;
	}

	public static function get_primary_resource_id( int $product_id ): int {
		$ids = self::get_resource_ids( $product_id );
		if ( $ids !== array() ) {
			return (int) $ids[0];
		}

		return (int) get_post_meta( $product_id, '_sbdp_resource_id', true );
	}

	/**
	 * @return int[]
	 */
	public static function get_resource_ids( int $product_id ): array {
		$stored = get_post_meta( $product_id, '_sbdp_resource_ids', true );
		if ( empty( $stored ) ) {
			return array();
		}

		if ( is_array( $stored ) ) {
			return array_values( array_filter( array_map( 'intval', $stored ) ) );
		}

		if ( is_string( $stored ) ) {
			$decoded = json_decode( $stored, true );
			if ( is_array( $decoded ) ) {
				return array_values( array_filter( array_map( 'intval', $decoded ) ) );
			}
		}

		return array();
	}

	/**
	 * @return array<int, array{id:int,title:string,capacity:int}>
	 */
	public static function get_resources_payload( int $product_id ): array {
		$ids = self::get_resource_ids( $product_id );
		if ( $ids === array() ) {
			$primary = (int) get_post_meta( $product_id, '_sbdp_resource_id', true );
			if ( $primary > 0 ) {
				$ids[] = $primary;
			}
		}

		$out = array();
		foreach ( $ids as $resource_id ) {
			$post = get_post( $resource_id );
			if ( ! $post instanceof WP_Post || $post->post_type !== 'bookable_resource' ) {
				continue;
			}

			$capacity = (int) get_post_meta( $resource_id, '_sbdp_resource_capacity', true );
			if ( $capacity < 0 ) {
				$capacity = 0;
			}

			$out[] = array(
				'id'       => (int) $resource_id,
				'title'    => get_the_title( $resource_id ),
				'capacity' => $capacity,
			);
		}

		return $out;
	}

	/**
	 * @return array<string,string>
	 */
	public static function get_label_payload( int $product_id ): array {
		$payload = array();
		foreach ( array_keys( self::LABEL_FIELDS ) as $key ) {
			$payload[ $key ] = self::get_label( $product_id, $key );
		}

		return $payload;
	}

	/**
	 * @param mixed $input
	 * @return int[]
	 */
	public static function sanitize_resource_ids( $input ): array {
		if ( empty( $input ) ) {
			return array();
		}

		if ( ! is_array( $input ) ) {
			$input = array( $input );
		}

		return array_values( array_filter( array_map( 'intval', $input ) ) );
	}

	/**
	 * @param mixed $raw
	 * @return array<string,string>
	 */
	private static function parse_label_map( $raw ): array {
		if ( empty( $raw ) ) {
			return array();
		}

		if ( is_array( $raw ) ) {
			$out = array();
			foreach ( $raw as $locale => $label ) {
				$locale = trim( (string) $locale );
				$label  = self::clean_label( $label );
				if ( $locale !== '' && $label !== '' ) {
					$out[ $locale ] = $label;
				}
			}

			return $out;
		}

		if ( is_string( $raw ) ) {
			$raw     = trim( $raw );
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return self::parse_label_map( $decoded );
			}

			$out    = array();
			$chunks = preg_split( '/[\r\n\|]+/', $raw ) ?: array();
			foreach ( $chunks as $chunk ) {
				if ( strpos( $chunk, '=' ) === false && strpos( $chunk, ':' ) === false ) {
					continue;
				}

				$parts = preg_split( '/[:=]/', $chunk, 2 ) ?: array();
				if ( count( $parts ) !== 2 ) {
					continue;
				}

				$locale = trim( (string) $parts[0] );
				$label  = self::clean_label( $parts[1] );
				if ( $locale !== '' && $label !== '' ) {
					$out[ $locale ] = $label;
				}
			}

			return $out;
		}

		return array();
	}

	/**
	 * @param mixed $value
	 */
	private static function clean_label( $value ): string {
		return trim( wp_strip_all_tags( (string) $value ) );
	}

	private static function get_locale(): string {
		if ( function_exists( 'determine_locale' ) ) {
			return determine_locale();
		}

		return (string) get_locale();
	}

	private static function get_default_label( string $key ): string {
		switch ( $key ) {
			case 'start':
				return __( 'Starttijd', 'sbdp' );
			case 'end':
				return __( 'Eindtijd', 'sbdp' );
			case 'participants':
				return __( 'Deelnemers', 'sbdp' );
			case 'resource':
				return __( 'Resource', 'sbdp' );
			default:
				return self::DEFAULT_LABELS[ $key ] ?? $key;
		}
	}
}
