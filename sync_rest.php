<?php
$file = 'modules/core/Rest/RestService.php';
$code = file_get_contents($file);
$target = "\t\treturn self::SERVICES_CACHE_KEY . '_' . md5( $locale );\n\t}\n\n";
$pos = strpos($code, $target);
if ($pos === false) {
    fwrite(STDERR, "target not found\n");
    exit(1);
}
$pos += strlen($target);
$block = <<<'PHP'
	public static function handle_product_saved( int $post_id, ?WP_Post $post = null ): void {
		$post_type = $post instanceof WP_Post ? $post->post_type : get_post_type( $post_id );

		if ( 'product' !== $post_type ) {
			return;
		}

		self::clear_services_cache();
	}

	public static function handle_deleted_post( int $post_id ): void {
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}

		self::clear_services_cache();
	}

	public static function handle_terms_set( int $object_id, $terms, $tt_ids, string $taxonomy, $append, $old_tt_ids ): void {
		if ( 'product' === get_post_type( $object_id ) || 'product_type' === $taxonomy ) {
			self::clear_services_cache();
		}
	}

	private static function clear_services_cache(): void {
		$locales = array();

		if ( function_exists( 'determine_locale' ) ) {
			$locales[] = determine_locale();
		}

		if ( function_exists( 'get_locale' ) ) {
			$locales[] = get_locale();
		}

		$locales[] = 'default';

		foreach ( array_unique( array_filter( $locales, static fn( $value ) => '' !== (string) $value ) ) as $locale ) {
			$key = self::build_services_cache_key( (string) $locale );
			wp_cache_delete( $key, 'sbdp_core' );
			delete_transient( $key );
		}
	}

PHP;
$code = substr_replace($code, $block, $pos, 0);
file_put_contents($file, $code);
