<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * DDB Core: Performance Cache
 * - Anonymous full-page HTML cache for safe frontend routes.
 * - Browser/proxy cache headers.
 * - Automatic cache invalidation on content changes.
 */
if ( ! class_exists( 'DDB_Core_Performance_Cache' ) ) {
	final class DDB_Core_Performance_Cache {
		private const CACHE_VERSION_OPTION = 'ddb_core_page_cache_version';
		private const CACHE_KEY_PREFIX = 'ddb_core_page_';
		private const PAGE_CACHE_TTL = 600;
		private const BROWSER_MAX_AGE = 120;
		private const SHARED_MAX_AGE = 600;
		private const CACHE_SAFE_COOKIES = array(
			'sbdp_funnel_session',
		);

		private static bool $buffer_started = false;
		private static bool $request_cacheable = false;
		private static string $request_cache_key = '';

		public static function boot(): void {
			add_action( 'init', array( self::class, 'register_invalidation_hooks' ), 5 );
			add_action( 'init', array( self::class, 'maybe_flush_from_query' ), 6 );

			add_action( 'template_redirect', array( self::class, 'maybe_serve_page_cache' ), 0 );
			add_action( 'template_redirect', array( self::class, 'start_page_buffer' ), PHP_INT_MAX );
			add_action( 'send_headers', array( self::class, 'send_cache_headers' ), 20 );

			add_filter( 'rest_post_dispatch', array( self::class, 'rest_cache_headers' ), 20, 3 );
		}

		public static function maybe_serve_page_cache(): void {
			if ( ! self::is_cacheable_page_request() ) {
				return;
			}

			$cache_key = self::build_cache_key();
			$payload = get_transient( $cache_key );

			if ( ! is_array( $payload ) || empty( $payload['body'] ) ) {
				return;
			}

			if ( headers_sent() ) {
				return;
			}

			self::send_public_headers();
			header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
			header( 'X-DDB-Page-Cache: HIT' );

			echo (string) $payload['body'];
			exit;
		}

		public static function start_page_buffer(): void {
			if ( self::$buffer_started || ! self::is_cacheable_page_request() ) {
				return;
			}

			self::$request_cacheable = true;
			self::$request_cache_key = self::build_cache_key();
			self::$buffer_started = true;

			ob_start( array( self::class, 'store_buffered_page' ) );
		}

		public static function store_buffered_page( string $html ): string {
			if ( ! self::$request_cacheable || self::$request_cache_key === '' ) {
				return $html;
			}

			if ( http_response_code() !== 200 ) {
				return $html;
			}

			$content_type = self::current_content_type();
			if ( $content_type !== '' && stripos( $content_type, 'text/html' ) === false ) {
				return $html;
			}

			// Never store responses that set cookies or include nonce fields.
			if ( self::has_disqualifying_set_cookie_header() || stripos( $html, '_wpnonce' ) !== false ) {
				return $html;
			}

			$trimmed = trim( $html );
			if ( strlen( $trimmed ) < 256 || stripos( $trimmed, '<html' ) === false ) {
				return $html;
			}

			set_transient(
				self::$request_cache_key,
				array(
					'body' => $html,
					'generated_at' => time(),
				),
				self::PAGE_CACHE_TTL
			);

			if ( ! headers_sent() ) {
				header( 'X-DDB-Page-Cache: MISS' );
			}

			return $html;
		}

		public static function send_cache_headers(): void {
			if ( headers_sent() || ! self::is_frontend_html_request() ) {
				return;
			}

			if ( self::is_cacheable_page_request() ) {
				self::send_public_headers();
				return;
			}

			// Keep dynamic/personal routes uncached.
			self::send_no_cache_headers();
		}

		public static function rest_cache_headers( $response, $server, $request ) {
			unset( $server );

			if ( ! $request instanceof WP_REST_Request ) {
				return $response;
			}

			$method = strtoupper( (string) $request->get_method() );
			if ( $method !== 'GET' ) {
				if ( $response instanceof WP_REST_Response ) {
					$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
				}
				return $response;
			}

			if ( is_user_logged_in() ) {
				if ( $response instanceof WP_REST_Response ) {
					$response->header( 'Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0' );
				}
				return $response;
			}

			if ( $response instanceof WP_REST_Response ) {
				$response->header( 'Cache-Control', 'public, max-age=60, s-maxage=120, stale-while-revalidate=30' );
				$response->header( 'Vary', 'Accept-Encoding' );
			}

			return $response;
		}

		public static function register_invalidation_hooks(): void {
			add_action( 'save_post', array( self::class, 'invalidate_from_post' ), 20, 1 );
			add_action( 'deleted_post', array( self::class, 'bump_cache_version' ) );
			add_action( 'switch_theme', array( self::class, 'bump_cache_version' ) );
			add_action( 'customize_save_after', array( self::class, 'bump_cache_version' ) );
			add_action( 'wp_update_nav_menu', array( self::class, 'bump_cache_version' ) );
			add_action( 'edited_term', array( self::class, 'bump_cache_version' ) );
			add_action( 'created_term', array( self::class, 'bump_cache_version' ) );
			add_action( 'delete_term', array( self::class, 'bump_cache_version' ) );
		}

		public static function invalidate_from_post( int $post_id ): void {
			if ( $post_id <= 0 || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
				return;
			}

			self::bump_cache_version();
		}

		public static function maybe_flush_from_query(): void {
			if ( is_admin() || ! current_user_can( 'manage_options' ) ) {
				return;
			}

			if ( empty( $_GET['ddb_flush_cache'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}

			self::bump_cache_version();
		}

		public static function bump_cache_version(): void {
			$current = (int) get_option( self::CACHE_VERSION_OPTION, 1 );
			update_option( self::CACHE_VERSION_OPTION, max( 1, $current + 1 ), false );
		}

		private static function is_cacheable_page_request(): bool {
			if ( ! self::is_frontend_html_request() ) {
				return false;
			}

			if ( is_singular( 'sbdp_private_tour' ) ) {
				return false;
			}

			if ( is_user_logged_in() ) {
				return false;
			}

			if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
				return false;
			}

			$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
			if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
				return false;
			}

			if ( is_preview() || is_customize_preview() || is_search() || is_feed() || is_trackback() || is_robots() ) {
				return false;
			}

			if ( function_exists( 'is_cart' ) && is_cart() ) {
				return false;
			}
			if ( function_exists( 'is_product' ) && is_product() ) {
				return false;
			}
			if ( function_exists( 'is_checkout' ) && is_checkout() ) {
				return false;
			}
			if ( function_exists( 'is_account_page' ) && is_account_page() ) {
				return false;
			}

			$uri = strtolower( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) );
			$blocked_path_parts = array(
				'/wp-admin/',
				'/wp-login.php',
				'/wp-json/',
				'/cart',
				'/checkout',
				'/my-account',
				'/product/',
				'wc-ajax=',
				'add-to-cart=',
				'elementor-preview=',
				'preview=true',
			);
			foreach ( $blocked_path_parts as $needle ) {
				if ( strpos( $uri, $needle ) !== false ) {
					return false;
				}
			}

			return true;
		}

		private static function is_frontend_html_request(): bool {
			if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
				return false;
			}

			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return false;
			}

			if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
				return false;
			}

			$accept = strtolower( (string) ( $_SERVER['HTTP_ACCEPT'] ?? '' ) );
			if ( $accept !== '' && strpos( $accept, 'text/html' ) === false && strpos( $accept, '*/*' ) === false ) {
				return false;
			}

			return true;
		}

		private static function build_cache_key(): string {
			$version = (int) get_option( self::CACHE_VERSION_OPTION, 1 );
			$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
			$theme = self::normalize_theme( (string) ( $_COOKIE['ddb_theme'] ?? 'system' ) );
			$locale = get_locale();
			$mobile = function_exists( 'wp_is_mobile' ) && wp_is_mobile() ? 'mobile' : 'desktop';
			$ssl = is_ssl() ? 'https' : 'http';

			$hash = md5( implode( '|', array( $version, $ssl, $mobile, $locale, $theme, $uri ) ) );
			return self::CACHE_KEY_PREFIX . $hash;
		}

		private static function normalize_theme( string $theme ): string {
			$theme = strtolower( trim( $theme ) );
			return in_array( $theme, array( 'light', 'dark', 'system' ), true ) ? $theme : 'system';
		}

		private static function current_content_type(): string {
			foreach ( headers_list() as $header ) {
				if ( stripos( $header, 'Content-Type:' ) === 0 ) {
					return trim( substr( $header, 13 ) );
				}
			}
			return '';
		}

		private static function has_disqualifying_set_cookie_header(): bool {
			foreach ( headers_list() as $header ) {
				if ( stripos( $header, 'Set-Cookie:' ) === 0 ) {
					$cookie_meta = trim( substr( $header, 11 ) );
					$cookie_name = strtolower( trim( (string) strtok( $cookie_meta, '=' ) ) );
					if ( $cookie_name === '' ) {
						return true;
					}

					if ( in_array( $cookie_name, self::CACHE_SAFE_COOKIES, true ) ) {
						continue;
					}

					return true;
				}
			}
			return false;
		}

		private static function send_public_headers(): void {
			header_remove( 'Pragma' );
			header(
				'Cache-Control: public, max-age=' . self::BROWSER_MAX_AGE .
				', s-maxage=' . self::SHARED_MAX_AGE .
				', stale-while-revalidate=60'
			);
			header( 'Vary: Accept-Encoding, Cookie, User-Agent' );
		}

		private static function send_no_cache_headers(): void {
			header_remove( 'Pragma' );
			header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'Pragma: no-cache' );
			header( 'Vary: Accept-Encoding, Cookie, User-Agent' );
		}
	}
}

DDB_Core_Performance_Cache::boot();
