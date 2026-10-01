<?php
/**
 * WooCommerce Addon Uploads Admin Settings Class
 *
 * Contains all admin settings functions and hooks
 *
 * @author      Dhruvin Shah
 * @package     WooCommerce Addon Uploads
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'wau_front_end_class' ) ) {

	/**
	 * Class for handling front-end functionality for WooCommerce product pages.
	 *
	 * This class is responsible for enqueuing front-end scripts and styles, handling file uploads,
	 * and managing other front-end related tasks for the WooCommerce product pages.
	 * It includes methods for conditionally adding scripts and styles, and dealing with custom
	 * fields related to the file upload feature.
	 */
	class wau_front_end_class {

		/**
		 * Resolved private upload directory for the current request.
		 *
		 * @var array|null
		 */
		private $private_upload_directory_cache = null;

		public function __construct() {

			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';

			$this->load_scripts();
			add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'addon_uploads_section' ), 999 );

			add_filter( 'woocommerce_add_cart_item_data', array( $this, 'wau_add_cart_item_data' ), 10, 4 );
			add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'wau_get_cart_item_from_session' ), 10, 2 );
			add_filter( 'woocommerce_get_item_data', array( $this, 'wau_get_item_data' ), 10, 2 );
			add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'wau_add_item_meta_url' ), 10, 3 );
			add_filter( 'woocommerce_order_item_display_meta_value', array( $this, 'wau_filter_order_item_uploaded_media_meta_value' ), 10, 3 );

			add_filter( 'wau_category_checks', array( $this, 'wau_check_category_allowed' ), 10, 2 );

			add_action( 'woocommerce_cart_item_removed', array( $this, 'wau_remove_cart_action' ), 10, 2 );

			add_action( 'admin_post_wau_secure_download', array( $this, 'wau_secure_file_download' ) );
			add_action( 'admin_post_nopriv_wau_secure_download', array( $this, 'wau_secure_file_download' ) );
			add_action( 'init', array( $this, 'wau_ensure_upload_directory_protection' ) );
			add_action( 'init', array( $this, 'wau_maybe_migrate_legacy_uploads' ), 20 );
			add_action( 'init', array( $this, 'wau_maybe_cleanup_abandoned_uploads' ), 30 );
		}

		/**
		 * Register front-end scripts and styles for the product page.
		 *
		 * This function hooks the custom JavaScript and CSS functions into the `woocommerce_before_single_product` action hook,
		 * ensuring that they are loaded on the product page before the product content.
		 *
		 * @return void
		 */
		public function load_scripts() {
			add_action( 'woocommerce_before_single_product', array( $this, 'wau_front_end_scripts_js' ) );
			add_action( 'woocommerce_before_single_product', array( $this, 'wau_front_end_scripts_css' ) );
		}

		/**
		 * Enqueue frontend JavaScript for the product page.
		 *
		 * This function is responsible for enqueuing custom JavaScript for the WooCommerce product page.
		 * Currently, the function is commented out but can be used to enqueue a script and localize data for AJAX requests.
		 *
		 * @return void
		 */
		public function wau_front_end_scripts_js() {
			if ( is_product() ) {
				// Enqueue custom JavaScript and localize AJAX URL (currently commented out).
				// wp_enqueue_script( 'wau_upload_js', plugins_url('../assets/js/wau_upload_script.js', __FILE__), '', '', false);
				// wp_localize_script( 'wau_upload_js', 'ajax_object', array( 'ajax_url' => admin_url( 'admin-ajax.php' ) ) );
			}
		}

		/**
		 * Enqueue frontend CSS for the product page.
		 *
		 * This function enqueues a custom CSS file for the frontend of the WooCommerce product page.
		 * The CSS file is loaded only when viewing a product page using the `is_product()` conditional tag.
		 *
		 * @return void
		 */
		public function wau_front_end_scripts_css() {

			if ( is_product() ) {
				wp_enqueue_style(
					'wau_upload_css',
					plugins_url( '../assets/css/wau_styles.css', __FILE__ ),
					array(), // Dependencies (if any).
					'1.0.0', // Version number.
					'all' // Media type.
				);
			}
		}

		/**
		 * Ensure the customer uploads directory has deny-by-default protection files.
		 *
		 * @since 1.7.5
		 *
		 * @param bool $create_directory Whether to create the legacy directory if missing.
		 * @return bool True when protection files are present or updated, false on failure.
		 */
		public function wau_ensure_upload_directory_protection( $create_directory = false ) {
			global $wp_filesystem;

			// Initialize WP Filesystem API.
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();

			if ( ! $wp_filesystem ) {
				return false;
			}

			$legacy_upload_dir = $this->wau_get_legacy_upload_directory();
			if ( empty( $legacy_upload_dir['path'] ) ) {
				return false;
			}

			$custom_dir = $legacy_upload_dir['path'];

			if ( ! $create_directory && ! is_dir( $custom_dir ) ) {
				return false;
			}

			if ( ! $this->wau_prepare_upload_directory( $custom_dir ) ) {
				return false;
			}

			$htaccess_path    = $custom_dir . '.htaccess';
			$htaccess_content = $this->wau_get_htaccess_content();

			if ( ! file_exists( $htaccess_path ) || $wp_filesystem->get_contents( $htaccess_path ) !== $htaccess_content ) {
				$wp_filesystem->put_contents( $htaccess_path, $htaccess_content, FS_CHMOD_FILE );
			}

			$web_config_path    = $custom_dir . 'web.config';
			$web_config_content = $this->wau_get_web_config_content();

			if ( ! file_exists( $web_config_path ) || $wp_filesystem->get_contents( $web_config_path ) !== $web_config_content ) {
				$wp_filesystem->put_contents( $web_config_path, $web_config_content, FS_CHMOD_FILE );
			}

			if ( ! file_exists( $custom_dir . 'index.php' ) ) {
				$wp_filesystem->put_contents( $custom_dir . 'index.php', '<?php // Silence is golden', FS_CHMOD_FILE );
			}

			return true;
		}

		/**
		 * Move legacy public uploads into private storage in small batches.
		 *
		 * @since 1.7.5
		 *
		 * @return void
		 */
		public function wau_maybe_migrate_legacy_uploads() {
			$private_upload_dir = $this->wau_get_private_upload_directory();
			$legacy_upload_dir  = $this->wau_get_legacy_upload_directory();

			if ( empty( $private_upload_dir['path'] ) || empty( $legacy_upload_dir['path'] ) ) {
				return;
			}

			$private_path = $private_upload_dir['path'];
			$legacy_path  = $legacy_upload_dir['path'];

			if ( $this->wau_paths_match( $private_path, $legacy_path ) || ! is_dir( $legacy_path ) ) {
				update_option( 'wau_legacy_upload_migration_complete', current_time( 'mysql' ), false );
				return;
			}

			global $wp_filesystem;

			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();

			if ( ! $wp_filesystem || ! $this->wau_prepare_upload_directory( $private_path ) ) {
				return;
			}

			$batch_size = absint( apply_filters( 'wau_legacy_upload_migration_batch_size', 25 ) );
			$batch_size = $batch_size > 0 ? $batch_size : 25;
			$migrated   = 0;
			$remaining  = false;

			try {
				$directory = new DirectoryIterator( $legacy_path );
			} catch ( Exception $exception ) {
				return;
			}

			foreach ( $directory as $file_info ) {
				if ( $file_info->isDot() || ! $file_info->isFile() ) {
					continue;
				}

				$file_name = $file_info->getFilename();

				if ( in_array( strtolower( $file_name ), array( '.htaccess', 'web.config', 'index.php' ), true ) ) {
					continue;
				}

				if ( $migrated >= $batch_size ) {
					$remaining = true;
					break;
				}

				$source      = trailingslashit( $legacy_path ) . $file_name;
				$destination = trailingslashit( $private_path ) . $file_name;

				if ( file_exists( $destination ) ) {
					$source_hash      = is_readable( $source ) ? hash_file( 'sha256', $source ) : false;
					$destination_hash = is_readable( $destination ) ? hash_file( 'sha256', $destination ) : false;

					if ( $source_hash && $destination_hash && hash_equals( $source_hash, $destination_hash ) ) {
						wp_delete_file( $source );
					} else {
						$remaining = true;
					}
					continue;
				}

				if ( $wp_filesystem->move( $source, $destination, false ) ) {
					$migrated++;
				} else {
					$remaining = true;
				}
			}

			if ( ! $remaining && $migrated < $batch_size ) {
				update_option( 'wau_legacy_upload_migration_complete', current_time( 'mysql' ), false );
			}
		}

		/**
		 * Get Apache access-control content for uploaded customer files.
		 *
		 * @since 1.7.5
		 *
		 * @return string
		 */
		private function wau_get_htaccess_content() {
			return "# Prevent direct access to files\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder Deny,Allow\n\tDeny from all\n</IfModule>\n";
		}

		/**
		 * Get IIS access-control content for uploaded customer files.
		 *
		 * @since 1.7.5
		 *
		 * @return string
		 */
		private function wau_get_web_config_content() {
			return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n";
		}

		/**
		 * Add defense-in-depth protection files to a storage directory.
		 *
		 * @param string $directory Directory path.
		 * @return void
		 */
		private function wau_write_storage_protection_files( $directory ) {
			global $wp_filesystem;

			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			WP_Filesystem();

			if ( ! $wp_filesystem || ! is_dir( $directory ) ) {
				return;
			}

			$directory = trailingslashit( $directory );
			$htaccess = $directory . '.htaccess';
			$web_config = $directory . 'web.config';

			if ( ! file_exists( $htaccess ) || $wp_filesystem->get_contents( $htaccess ) !== $this->wau_get_htaccess_content() ) {
				$wp_filesystem->put_contents( $htaccess, $this->wau_get_htaccess_content(), FS_CHMOD_FILE );
			}

			if ( ! file_exists( $web_config ) || $wp_filesystem->get_contents( $web_config ) !== $this->wau_get_web_config_content() ) {
				$wp_filesystem->put_contents( $web_config, $this->wau_get_web_config_content(), FS_CHMOD_FILE );
			}

			if ( ! file_exists( $directory . 'index.php' ) ) {
				$wp_filesystem->put_contents( $directory . 'index.php', '<?php // Silence is golden', FS_CHMOD_FILE );
			}
		}

		/**
		 * Get the public legacy upload directory.
		 *
		 * @since 1.7.5
		 *
		 * @return array
		 */
		private function wau_get_legacy_upload_directory() {
			$upload_dir = wp_upload_dir();

			if ( ! empty( $upload_dir['error'] ) ) {
				return array(
					'path' => '',
					'url'  => '',
				);
			}

			return array(
				'path' => trailingslashit( $upload_dir['basedir'] ) . 'wau-uploads/',
				'url'  => trailingslashit( $upload_dir['baseurl'] ) . 'wau-uploads/',
			);
		}

		/**
		 * Get the preferred private upload directory for this site.
		 *
		 * @since 1.7.5
		 *
		 * @return array
		 */
		private function wau_get_private_upload_directory() {
			if ( is_array( $this->private_upload_directory_cache ) ) {
				return $this->private_upload_directory_cache;
			}

			$site_key       = $this->wau_get_site_storage_key();
			$configured_dir = defined( 'WAU_PRIVATE_UPLOAD_DIR' ) ? WAU_PRIVATE_UPLOAD_DIR : '';
			$configured_dir = apply_filters( 'wau_private_upload_dir', $configured_dir, $site_key );
			$candidates     = array();

			if ( ! empty( $configured_dir ) ) {
				$configured_dir = trailingslashit( $configured_dir );

				if ( $this->wau_is_absolute_path( $configured_dir ) ) {
					$candidates[] = $site_key === basename( untrailingslashit( wp_normalize_path( $configured_dir ) ) ) ? $configured_dir : $configured_dir . $site_key . '/';
				}
			}

			$default_base_dir = $this->wau_get_default_private_upload_base();
			if ( ! empty( $default_base_dir ) ) {
				$candidates[] = trailingslashit( $default_base_dir ) . 'wau-private-uploads/' . $site_key . '/';
			}

			$candidates = array_unique( array_filter( $candidates ) );

			foreach ( $candidates as $candidate ) {
				$candidate = trailingslashit( wp_normalize_path( $candidate ) );

				if ( $this->wau_is_public_path( $candidate ) ) {
					continue;
				}

				if ( $this->wau_prepare_upload_directory( $candidate ) && ! $this->wau_is_public_path( $candidate ) ) {
					$this->wau_write_storage_protection_files( $candidate );
					update_option( 'wau_private_uploads_available', 'yes', false );
					update_option( 'wau_private_uploads_path', $candidate, false );

					$this->private_upload_directory_cache = array(
						'path' => $candidate,
						'url'  => '',
					);

					return $this->private_upload_directory_cache;
				}
			}

			update_option( 'wau_private_uploads_available', 'no', false );
			update_option( 'wau_private_uploads_path', '', false );

			$this->private_upload_directory_cache = array(
				'path' => '',
				'url'  => '',
			);

			return $this->private_upload_directory_cache;
		}

		/**
		 * Get the upload directory to use for new files.
		 *
		 * @since 1.7.5
		 *
		 * @return array
		 */
		private function wau_get_upload_storage_directory() {
			$private_upload_dir = $this->wau_get_private_upload_directory();

			if ( ! empty( $private_upload_dir['path'] ) ) {
				return array(
					'path'    => $private_upload_dir['path'],
					'url'     => '',
					'storage' => 'private',
				);
			}

			return array(
				'path'    => '',
				'url'     => '',
				'storage' => 'none',
			);
		}

		/**
		 * Create an upload directory and defensive access-control files.
		 *
		 * @since 1.7.5
		 *
		 * @param string $directory Directory path.
		 * @return bool
		 */
		private function wau_prepare_upload_directory( $directory ) {
			if ( empty( $directory ) || ! wp_mkdir_p( $directory ) ) {
				return false;
			}

			if ( ! wp_is_writable( $directory ) ) {
				return false;
			}

			return true;
		}

		/**
		 * Check whether a filesystem path is absolute.
		 *
		 * @param string $path Filesystem path.
		 * @return bool
		 */
		private function wau_is_absolute_path( $path ) {
			$path = wp_normalize_path( (string) $path );

			return 1 === preg_match( '#^(?:[a-zA-Z]:/|/)#', $path );
		}

		/**
		 * Build a site-specific storage key so subdomains do not share private uploads.
		 *
		 * @since 1.7.5
		 *
		 * @return string
		 */
		private function wau_get_site_storage_key() {
			$home_url = home_url( '/' );
			$host     = wp_parse_url( $home_url, PHP_URL_HOST );
			$host     = $host ? strtolower( $host ) : 'site';
			$host     = preg_replace( '/[^a-z0-9._-]/', '-', $host );
			$host     = str_replace( '.', '-', $host );
			$blog_id  = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1;
			$hash     = substr( hash( 'sha256', $home_url . '|' . ABSPATH . '|' . $blog_id ), 0, 12 );

			return sanitize_file_name( $host . '-' . $blog_id . '-' . $hash );
		}

		/**
		 * Get a default private base directory outside the current document root.
		 *
		 * @since 1.7.5
		 *
		 * @return string
		 */
		private function wau_get_default_private_upload_base() {
			$document_root = '';

			if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
				$document_root = sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) );
			}

			if ( empty( $document_root ) ) {
				return '';
			}

			$public_root = realpath( $document_root );

			if ( ! $public_root ) {
				return '';
			}

			$base_dir       = dirname( $public_root );
			$web_root_names = array( 'public_html', 'htdocs', 'httpdocs', 'wwwroot', 'public' );
			$base_name     = strtolower( basename( wp_normalize_path( $base_dir ) ) );

			if ( in_array( $base_name, $web_root_names, true ) ) {
				$base_dir = dirname( $base_dir );
			}

			if ( empty( $base_dir ) || $this->wau_paths_match( $base_dir, $public_root ) ) {
				return '';
			}

			return trailingslashit( wp_normalize_path( $base_dir ) );
		}

		/**
		 * Check whether a path sits inside a known public web path.
		 *
		 * @since 1.7.5
		 *
		 * @param string $path Path to check.
		 * @return bool
		 */
		private function wau_is_public_path( $path ) {
			$public_paths = array();

			if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
				$public_paths[] = sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) );
			}

			$upload_dir = wp_upload_dir();
			if ( empty( $upload_dir['error'] ) ) {
				$public_paths[] = $upload_dir['basedir'];
			}

			$public_paths[] = ABSPATH;

			foreach ( array_filter( $public_paths ) as $public_path ) {
				if ( $this->wau_path_is_inside( $path, $public_path ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Check whether one path is inside another.
		 *
		 * @since 1.7.5
		 *
		 * @param string $path Path to check.
		 * @param string $base Base path.
		 * @return bool
		 */
		private function wau_path_is_inside( $path, $base ) {
			$path = realpath( $path ) ? realpath( $path ) : $path;
			$base = realpath( $base ) ? realpath( $base ) : $base;
			$path = trailingslashit( strtolower( wp_normalize_path( $path ) ) );
			$base = trailingslashit( strtolower( wp_normalize_path( $base ) ) );

			return 0 === strpos( $path, $base );
		}

		/**
		 * Compare normalized paths.
		 *
		 * @since 1.7.5
		 *
		 * @param string $path_a First path.
		 * @param string $path_b Second path.
		 * @return bool
		 */
		private function wau_paths_match( $path_a, $path_b ) {
			$path_a = realpath( $path_a ) ? realpath( $path_a ) : $path_a;
			$path_b = realpath( $path_b ) ? realpath( $path_b ) : $path_b;

			return trailingslashit( strtolower( wp_normalize_path( $path_a ) ) ) === trailingslashit( strtolower( wp_normalize_path( $path_b ) ) );
		}

		/**
		 * Mark a new upload as pending until it is attached to an order.
		 *
		 * @param string $file_path Uploaded file path.
		 * @return void
		 */
		private function wau_mark_upload_pending( $file_path ) {
			global $wp_filesystem;

			if ( $wp_filesystem && $this->wau_is_allowed_upload_path( $file_path ) ) {
				$wp_filesystem->put_contents( $file_path . '.wau-pending', (string) time(), FS_CHMOD_FILE );
			}
		}

		/**
		 * Remove the pending marker once an upload belongs to an order.
		 *
		 * @param string $file_path Uploaded file path.
		 * @return void
		 */
		private function wau_clear_upload_pending_marker( $file_path ) {
			$marker_path = $file_path . '.wau-pending';

			if ( file_exists( $marker_path ) && $this->wau_is_allowed_upload_path( $file_path ) ) {
				wp_delete_file( $marker_path );
			}
		}

		/**
		 * Periodically remove abandoned uploads that were never attached to an order.
		 *
		 * @return void
		 */
		public function wau_maybe_cleanup_abandoned_uploads() {
			if ( get_transient( 'wau_abandoned_upload_cleanup_lock' ) ) {
				return;
			}

			$private_upload_dir = $this->wau_get_private_upload_directory();
			if ( empty( $private_upload_dir['path'] ) || ! is_dir( $private_upload_dir['path'] ) ) {
				return;
			}

			$cleanup_interval = absint( apply_filters( 'wau_abandoned_upload_cleanup_interval', 12 * HOUR_IN_SECONDS ) );
			$cleanup_interval = $cleanup_interval > 0 ? $cleanup_interval : 12 * HOUR_IN_SECONDS;
			set_transient( 'wau_abandoned_upload_cleanup_lock', 1, $cleanup_interval );

			$retention = absint( apply_filters( 'wau_abandoned_upload_retention', 2 * DAY_IN_SECONDS ) );
			$retention = $retention > 0 ? $retention : 2 * DAY_IN_SECONDS;
			$cutoff    = time() - $retention;

			try {
				$directory = new DirectoryIterator( $private_upload_dir['path'] );
			} catch ( Exception $exception ) {
				return;
			}

			foreach ( $directory as $file_info ) {
				$file_name = $file_info->getFilename();

				if ( $file_info->isDot() || ! $file_info->isFile() || '.wau-pending' !== substr( $file_name, -12 ) ) {
					continue;
				}

				$marker_path = $file_info->getPathname();
				if ( $file_info->getMTime() > $cutoff ) {
					continue;
				}

				$upload_path = substr( $marker_path, 0, -12 );
				if ( file_exists( $upload_path ) && $this->wau_is_allowed_upload_path( $upload_path ) ) {
					wp_delete_file( $upload_path );
				}

				wp_delete_file( $marker_path );
			}
		}

		/**
		 * Apply a modest per-client upload rate limit.
		 *
		 * @return bool
		 */
		private function wau_upload_rate_limit_allows_request() {
			$window = absint( apply_filters( 'wau_upload_rate_limit_window', 10 * MINUTE_IN_SECONDS ) );
			$window = $window > 0 ? $window : 10 * MINUTE_IN_SECONDS;
			$limits = array();

			if ( get_current_user_id() ) {
				$limits[ 'user:' . get_current_user_id() ] = absint( apply_filters( 'wau_upload_rate_limit', 30 ) );
			} elseif ( function_exists( 'WC' ) && WC()->session ) {
				$session_id = WC()->session->get_customer_id();
				if ( $session_id ) {
					$limits[ 'session:' . $session_id ] = absint( apply_filters( 'wau_upload_rate_limit', 30 ) );
				}
			}

			$remote_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			if ( $remote_address ) {
				$limits[ 'ip:' . $remote_address ] = absint( apply_filters( 'wau_upload_ip_rate_limit', 100 ) );
			}

			$rate_entries = array();
			foreach ( $limits as $client_id => $limit ) {
				if ( 0 === $limit ) {
					continue;
				}

				$transient_key = 'wau_upload_rate_' . substr( hash_hmac( 'sha256', $client_id, wp_salt( 'nonce' ) ), 0, 32 );
				$attempts      = absint( get_transient( $transient_key ) );

				if ( $attempts >= $limit ) {
					return false;
				}

				$rate_entries[ $transient_key ] = $attempts;
			}

			foreach ( $rate_entries as $transient_key => $attempts ) {
				set_transient( $transient_key, $attempts + 1, $window );
			}

			return true;
		}

		/**
		 * Displays the file upload section on WooCommerce product pages.
		 *
		 * This function checks if the file upload option is enabled in the plugin settings.
		 * and verifies product/category conditions before displaying the upload field.
		 *
		 * @since 1.0.0
		 */
		public function addon_uploads_section() {
			global $product;

			$allowed_tags = array(
				'div'   => array( 'class' => array() ),
				'label' => array( 'for' => array() ),
				'input' => array(
					'type'   => array(),
					'name'   => array(),
					'id'     => array(),
					'class'  => array(),
					'accept' => array(),
					'value'  => array(),
				),
			);

			if ( $product && $this->wau_upload_is_enabled_for_product( $product->get_id() ) ) {
				$upload_label = __( 'Upload an image: ', 'woo-addon-uploads' );

				// Generate file upload field.
				$file_upload_template = sprintf(
					'<div class="wau_wrapper_div">
						<label for="wau_file_addon">%s</label>
						<input type="file" name="wau_file_addon" id="wau_file_addon" accept="image/*" class="wau-auto-width wau-files" />
						%s
					</div>',
					esc_html( $upload_label ),
					wp_nonce_field( 'wau_file_upload', 'wau_file_upload_nonce', true, false )
				);

				echo wp_kses( $file_upload_template, $allowed_tags ); // Allows safe HTML while keeping input elements.
			}
		}

		/**
		 * Check the configured upload rules for a product on the server side.
		 *
		 * @param int $product_id Product ID.
		 * @return bool
		 */
		private function wau_upload_is_enabled_for_product( $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return false;
			}

			$addon_settings = get_option( 'wau_addon_settings' );
			if ( empty( $addon_settings['wau_enable_addon'] ) || '1' !== (string) $addon_settings['wau_enable_addon'] ) {
				return false;
			}

			$product_ids = apply_filters( 'wau_include_product_ids', array() );
			if ( ! is_array( $product_ids ) || ( ! empty( $product_ids ) && ! in_array( $product->get_id(), array_map( 'absint', $product_ids ), true ) ) ) {
				return false;
			}

			return (bool) apply_filters( 'wau_category_checks', true, $product );
		}

		/**
		 * Adds uploaded file data to WooCommerce cart item metadata.
		 *
		 * This function securely handles file uploads, validates file types,
		 * sanitizes filenames, and stores the uploaded file in a custom directory
		 * within the WordPress uploads folder.
		 *
		 * @since 1.0.0
		 * @since 1.7.2
		 *
		 * @param array $cart_item_meta The cart item metadata.
		 * @param int   $product_id     Product ID.
		 * @param int   $variation_id   Variation ID.
		 * @param int   $quantity       Quantity.
		 * @return array Updated cart item metadata with uploaded file details.
		 */
		public function wau_add_cart_item_data( $cart_item_meta, $product_id = 0, $variation_id = 0, $quantity = 1 ) {
			global $wp_filesystem;

			// Initialize WP Filesystem API.
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();

			if ( ! $wp_filesystem ) {
				wc_add_notice( __( 'File upload failed. Please try again.', 'woo-addon-uploads' ), 'error' );
				return $cart_item_meta;
			}

			// Check if file is uploaded.
			$post_file = wp_unslash( $_FILES );
			$postdata  = wp_unslash( $_POST );
			if ( isset( $post_file['wau_file_addon'] ) ) {
				$file = $post_file['wau_file_addon'];

				if (
					! is_array( $file ) ||
					! isset( $file['name'], $file['tmp_name'], $file['error'], $file['size'] ) ||
					! is_scalar( $file['name'] ) ||
					! is_scalar( $file['tmp_name'] ) ||
					! is_scalar( $file['error'] ) ||
					! is_scalar( $file['size'] )
				) {
					wc_add_notice( __( 'Invalid file upload request.', 'woo-addon-uploads' ), 'error' );
					return $cart_item_meta;
				}

				if ( '' === (string) $file['name'] ) {
					return $cart_item_meta;
				}

				if (
					! isset( $postdata['wau_file_upload_nonce'] ) ||
					! is_scalar( $postdata['wau_file_upload_nonce'] ) ||
					! wp_verify_nonce( sanitize_text_field( wp_unslash( $postdata['wau_file_upload_nonce'] ) ), 'wau_file_upload' )
				) {
					wc_add_notice( __( 'Security check failed. Please try again.', 'woo-addon-uploads' ), 'error' );
					return $cart_item_meta;
				}

				if ( ! $this->wau_upload_is_enabled_for_product( $product_id ) ) {
					wc_add_notice( __( 'File uploads are not enabled for this product.', 'woo-addon-uploads' ), 'error' );
					return $cart_item_meta;
				}

				if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
					wc_add_notice( __( 'File upload failed. Please try again.', 'woo-addon-uploads' ), 'error' );
					return $cart_item_meta;
				}

				$tmp_name = (string) $file['tmp_name'];
				if ( empty( $tmp_name ) || ! is_uploaded_file( $tmp_name ) ) {
					wc_add_notice( __( 'Invalid file upload request.', 'woo-addon-uploads' ), 'error' );
					return $cart_item_meta;
				}

				$wordpress_max = wp_max_upload_size();
				$default_max   = $wordpress_max > 0 ? min( $wordpress_max, 10 * MB_IN_BYTES ) : 10 * MB_IN_BYTES;
				$maximum_size  = absint( apply_filters( 'wau_max_upload_size', $default_max, $product_id ) );
				$actual_size   = filesize( $tmp_name );

				if ( ! $actual_size || ( $maximum_size > 0 && $actual_size > $maximum_size ) ) {
					wc_add_notice(
						sprintf(
							/* translators: %s: Maximum upload size. */
							__( 'The uploaded file must be smaller than %s.', 'woo-addon-uploads' ),
							size_format( $maximum_size )
						),
						'error'
					);
					return $cart_item_meta;
				}

				if ( ! $this->wau_upload_rate_limit_allows_request() ) {
					wc_add_notice( __( 'Too many upload attempts. Please wait a few minutes and try again.', 'woo-addon-uploads' ), 'error' );
					return $cart_item_meta;
				}

				// Apply filter to allow custom file types.
				$allowed_types = apply_filters( 'wau_allowed_file_types', array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ) );
				$allowed_types = is_array( $allowed_types ) ? array_map( 'sanitize_key', $allowed_types ) : array();

				// Validate file type.
				$file_info = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
				$file_ext  = ! empty( $file_info['ext'] ) ? strtolower( $file_info['ext'] ) : '';

				if ( empty( $file_ext ) || ! in_array( $file_ext, $allowed_types, true ) ) {
					wc_add_notice( __( 'Invalid file type. Only JPG, PNG, GIF, and WebP files are allowed.', 'woo-addon-uploads' ), 'error' );
					return $cart_item_meta;
				}

				// Get the best available upload storage location.
				$storage_dir = $this->wau_get_upload_storage_directory();
				$custom_dir  = $storage_dir['path'];
				$custom_url  = $storage_dir['url'];

				// Ensure directory exists.
				if ( empty( $custom_dir ) || ! $this->wau_prepare_upload_directory( $custom_dir ) ) {
					wc_add_notice( __( 'File upload is temporarily unavailable because private upload storage could not be created. Please contact the store administrator.', 'woo-addon-uploads' ), 'error' );
					return $cart_item_meta;
				}

				$minimum_free_space = absint( apply_filters( 'wau_minimum_free_disk_space', 100 * MB_IN_BYTES ) );
				$free_space         = function_exists( 'disk_free_space' ) ? @disk_free_space( $custom_dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false !== $free_space && $free_space - $actual_size < $minimum_free_space ) {
					wc_add_notice( __( 'File upload is temporarily unavailable because the server is low on storage space.', 'woo-addon-uploads' ), 'error' );
					return $cart_item_meta;
				}

				$upload_dir_filter = function ( $directories ) use ( $custom_dir, $custom_url ) {
					$directories['path']    = untrailingslashit( $custom_dir );
					$directories['basedir'] = untrailingslashit( $custom_dir );
					$directories['url']     = untrailingslashit( $custom_url );
					$directories['baseurl'] = untrailingslashit( $custom_url );
					$directories['subdir']  = '';

					return $directories;
				};

				add_filter( 'upload_dir', $upload_dir_filter );

				// Handle file upload using WordPress function.
				$upload_overrides = array( 'test_form' => false );
				$uploaded_file    = wp_handle_sideload( $file, $upload_overrides );

				remove_filter( 'upload_dir', $upload_dir_filter );

				if ( isset( $uploaded_file['error'] ) ) {
					wc_add_notice( __( 'File upload failed: ', 'woo-addon-uploads' ) . esc_html( $uploaded_file['error'] ), 'error' );
					return $cart_item_meta;
				}

				// Generate unique sanitized file name using WordPress's validated filename.
				$file_name          = sanitize_file_name( basename( $uploaded_file['file'] ) );
				$file_name          = time() . '-' . $file_name;

				try {
					$crypto_strong_hash = bin2hex( random_bytes( 16 ) );
				} catch ( Throwable $exception ) {
					wp_delete_file( $uploaded_file['file'] );
					wc_add_notice( __( 'File upload failed. Please try again.', 'woo-addon-uploads' ), 'error' );
					return $cart_item_meta;
				}

				$file_name          = $crypto_strong_hash . '-' . $file_name;
				$access_key         = wp_generate_password( 32, false, false );
				$new_file_path      = trailingslashit( $custom_dir ) . $file_name;
				$new_file_url       = $custom_url ? $custom_url . $file_name : '';

				// Move file using WP_Filesystem.
				if ( $wp_filesystem->move( $uploaded_file['file'], $new_file_path, true ) ) {
					// Store file information.
					$addon_id                          = array(
						'file_path'  => $new_file_path, // Absolute file path.
						'file_url'   => esc_url_raw( $new_file_url ),
						'file_name'  => $file_name,
						'storage'    => sanitize_key( $storage_dir['storage'] ),
						'access_key' => $access_key,
					);
					$this->wau_mark_upload_pending( $new_file_path );
					$cart_item_meta['wau_addon_ids'][] = $addon_id;
				} else {
					wp_delete_file( $uploaded_file['file'] );
					wc_add_notice( __( 'Failed to move file to custom folder.', 'woo-addon-uploads' ), 'error' );
				}
			}

			return $cart_item_meta;
		}

		/**
		 * Restores uploaded file data from session to WooCommerce cart.
		 *
		 * This function ensures that the uploaded file metadata is retained.
		 * when the cart is restored from the session.
		 *
		 * @since 1.0.0
		 * @since 1.7.2
		 *
		 * @param array $cart_item The cart item data.
		 * @param array $values    The stored cart item values from the session.
		 * @return array The updated cart item data.
		 */
		public function wau_get_cart_item_from_session( $cart_item, $values ) {
			// Check if the cart item has uploaded file metadata and restore it.
			if ( isset( $values['wau_addon_ids'] ) && is_array( $values['wau_addon_ids'] ) ) {
				$cart_item['wau_addon_ids'] = $values['wau_addon_ids'];
			}

			return $cart_item;
		}

		/**
		 * Check if WooCommerce block is present in the current post.
		 *
		 * This function checks if a WooCommerce block (either 'woocommerce/cart' or 'woocommerce/checkout') is present
		 * in the post content. It also handles cases for AJAX requests on the checkout page where the post content may be null.
		 *
		 * @since 1.0.0
		 * @since 1.7.2
		 *
		 * @return bool True if a WooCommerce block is present, false otherwise.
		 */
		public function is_woocommerce_block_present() {
			$post = get_post();

			// This condition will appear for ajax calls on the checkout page.
			if ( is_null( $post ) ) {
				return true;
			}

			if ( ! has_blocks( $post->post_content ) ) {
				return false;
			}
			$blocks      = parse_blocks( $post->post_content );
			$block_names = array_map(
				function ( $block ) {
					return $block['blockName'];
				},
				$blocks
			);

			return in_array(
				'woocommerce/cart',
				$block_names,
				true
			) ||
			in_array(
				'woocommerce/checkout',
				$block_names,
				true
			);
		}

		/**
		 * Add custom item data to the cart item for file uploads.
		 *
		 * @since 1.0.0
		 * @since 1.7.2
		 *
		 * @param array $other_data Array of other cart item data.
		 * @param array $cart_item The cart item data array.
		 *
		 * @return array Modified array of cart item data, including uploaded file details.
		 */
		public function wau_get_item_data( $other_data, $cart_item ) {
			if ( isset( $cart_item['wau_addon_ids'] ) && is_array( $cart_item['wau_addon_ids'] ) ) {
				foreach ( $cart_item['wau_addon_ids'] as $addon_id ) {
					if ( ! is_array( $addon_id ) || empty( $addon_id['file_name'] ) || ! is_scalar( $addon_id['file_name'] ) ) {
						continue;
					}

					$block_present = $this->is_woocommerce_block_present();
					$image_url     = add_query_arg(
						array(
							'action' => 'wau_secure_download',
							'file'   => esc_html( $addon_id['file_name'] ),
							'key'    => isset( $addon_id['access_key'] ) && is_scalar( $addon_id['access_key'] ) ? sanitize_text_field( $addon_id['access_key'] ) : '',
							'nonce'  => wp_create_nonce( 'wau_secure_download' ),
						),
						admin_url( 'admin-post.php' )
					);
					if ( $block_present ) {
						$name    = __( 'Uploaded File', 'woo-addon-uploads' );
						$display = '&#9989;';
					} else {
						$name    = __( 'Uploaded File', 'woo-addon-uploads' );
						$display = '<img src="' . esc_url( $image_url ) . '" alt="' . esc_attr( $name ) . '" class="wau-upload-img" style="width:150px;height:150px;" />'; //phpcs:ignore
					}

					$other_data[] = array(
						'name'    => $name,
						'display' => $display,
					);
				}
			}

			return $other_data;
		}

		/**
		 * Adds uploaded file URL as order item metadata in WooCommerce.
		 *
		 * This function retrieves the uploaded file URL from cart item metadata.
		 * and saves it as order item metadata when an order is created.
		 *
		 * @since 1.0.0
		 * @since 1.7.2
		 *
		 * @param WC_Order_Item $item          The order item object.
		 * @param string        $cart_item_key The cart item key.
		 * @param array         $values        The cart item data.
		 */
		public function wau_add_item_meta_url( $item, $cart_item_key, $values ) {
			// Check if there are uploaded files.
			if ( empty( $values['wau_addon_ids'] ) || ! is_array( $values['wau_addon_ids'] ) ) {
				return;
			}

			// Loop through uploaded files and add them as metadata.
			foreach ( $values['wau_addon_ids'] as $addon_id ) {
				if ( is_array( $addon_id ) && isset( $addon_id['file_name'] ) && is_scalar( $addon_id['file_name'] ) ) {
					$file_name  = sanitize_file_name( $addon_id['file_name'] );
					$access_key = isset( $addon_id['access_key'] ) && is_scalar( $addon_id['access_key'] ) ? sanitize_text_field( $addon_id['access_key'] ) : '';
					$download_url = add_query_arg(
						array(
							'action'   => 'wau_secure_download',
							'file'     => $file_name,
							'key'      => $access_key,
							'nonce'    => wp_create_nonce( 'wau_secure_download' ),
							'download' => '1',
						),
						admin_url( 'admin-post.php' )
					);
					$item->add_meta_data(
						'_wau_upload_reference',
						array(
							'file_name'  => $file_name,
							'access_key' => $access_key,
						),
						false
					);
					$item->add_meta_data( __( 'Uploaded Media', 'woo-addon-uploads' ), '<a href="' . esc_url( $download_url ) . '" download>' . esc_html( $file_name ) . '</a>', true );

					if ( ! empty( $addon_id['file_path'] ) ) {
						$this->wau_clear_upload_pending_marker( $addon_id['file_path'] );
					}
				}
			}
		}

		/**
		 * Rewrite legacy direct upload URLs in order-item metadata to secure handler URLs.
		 *
		 * @since 1.7.5
		 *
		 * @param string $display_value Displayed metadata value.
		 * @param object $meta          Metadata object.
		 * @param object $item          Order item object.
		 * @return string
		 */
		public function wau_filter_order_item_uploaded_media_meta_value( $display_value, $meta, $item ) {
			if ( ! is_scalar( $display_value ) ) {
				return $display_value;
			}

			if ( false === strpos( (string) $display_value, 'wau-uploads/' ) && false === strpos( (string) $display_value, 'wau_secure_download' ) ) {
				return $display_value;
			}

			$order_id  = is_object( $item ) && method_exists( $item, 'get_order_id' ) ? absint( $item->get_order_id() ) : 0;
			$order     = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
			$order_key = $order && 0 === (int) $order->get_customer_id() ? $order->get_order_key() : '';
			$value    = preg_replace_callback(
				'#https?://[^\'"\s<>]+admin-post\.php\?[^\'"\s<>]*action=wau_secure_download[^\'"\s<>]*#i',
				function ( $matches ) use ( $order_id, $order_key ) {
					if ( ! $order_id ) {
						return $matches[0];
					}

					$download_url = html_entity_decode( $matches[0], ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );

					return esc_url(
						add_query_arg(
							array(
								'order_id'  => $order_id,
								'order_key' => $order_key,
							),
							$download_url
						)
					);
				},
				$display_value
			);

			return preg_replace_callback(
				'#https?://[^\'"\s<>]+/wau-uploads/([^\'"\s<>?]+)(?:\?[^\'"\s<>]*)?#i',
				function ( $matches ) use ( $order_id, $order_key ) {
					$file_name = sanitize_file_name( basename( rawurldecode( $matches[1] ) ) );

					if ( empty( $file_name ) ) {
						return $matches[0];
					}

					return esc_url(
						add_query_arg(
							array(
								'action'   => 'wau_secure_download',
								'file'     => $file_name,
								'nonce'    => wp_create_nonce( 'wau_secure_download' ),
								'download' => '1',
								'order_id' => $order_id,
								'order_key' => $order_key,
							),
							admin_url( 'admin-post.php' )
						)
					);
				},
				$value
			);
		}

		/**
		 * Deletes an uploaded file when an item is removed from the WooCommerce cart.
		 *
		 * This function checks if the removed cart item contains an uploaded file and deletes it
		 * from the server using the `wau_delete_uploaded_file()` function.
		 *
		 * @since 1.0.0
		 * @since 1.7.2
		 *
		 * @param string  $cart_item_key The unique cart item key.
		 * @param WC_Cart $cart          The WooCommerce cart object.
		 */
		public function wau_remove_cart_action( $cart_item_key, $cart ) {
			// Get the removed cart item details.
			$removed_item = $cart->removed_cart_contents[ $cart_item_key ] ?? null;

			if ( ! is_array( $removed_item ) || empty( $removed_item['wau_addon_ids'] ) || ! is_array( $removed_item['wau_addon_ids'] ) ) {
				return;
			}

			foreach ( $removed_item['wau_addon_ids'] as $addon_id ) {
				if ( is_array( $addon_id ) && ! empty( $addon_id['file_path'] ) && is_scalar( $addon_id['file_path'] ) ) {
					$this->wau_delete_uploaded_file( (string) $addon_id['file_path'] );
				}
			}
		}

		/**
		 * Deletes an uploaded file from the server.
		 *
		 * This function securely deletes a file from the server while preventing.
		 * directory traversal attacks. It verifies that the file exists before attempting deletion.
		 *
		 * @since 1.7.2
		 *
		 * @param string $file_name The absolute file path of the file to be deleted.
		 * @return string|WP_Error Success message on successful deletion, or WP_Error on failure.
		 */
		private function wau_delete_uploaded_file( $file_name ) {
			$file_path = $file_name;

			// Security check: Prevent directory traversal attacks.
			if ( ! file_exists( $file_path ) ) {
				$file_path = $this->wau_locate_uploaded_file( basename( $file_name ) );
			}

			if ( ! $file_path || ! file_exists( $file_path ) || ! $this->wau_is_allowed_upload_path( $file_path ) ) {
				return new WP_Error( 'invalid_file', __( 'Invalid file or file does not exist.', 'woo-addon-uploads' ) );
			}

			// Attempt to delete the file.
			if ( ! wp_delete_file( $file_path ) ) {
				return new WP_Error( 'delete_failed', __( 'Failed to delete the file.', 'woo-addon-uploads' ) );
			}

			$this->wau_clear_upload_pending_marker( $file_path );
		}

		/**
		 * Check whether the current request can download a specific uploaded file.
		 *
		 * @since 1.7.5
		 *
		 * @param string $file_name Uploaded file name.
		 * @param array  $getdata   Request query data.
		 * @return bool
		 */
		private function wau_current_request_can_download_file( $file_name, $getdata ) {
			if ( is_user_logged_in() && current_user_can( 'manage_woocommerce' ) ) {
				return true;
			}

			$access_key      = isset( $getdata['key'] ) && is_scalar( $getdata['key'] ) ? sanitize_text_field( wp_unslash( $getdata['key'] ) ) : '';
			$has_valid_nonce = (
				isset( $getdata['nonce'] ) &&
				is_scalar( $getdata['nonce'] ) &&
				wp_verify_nonce( sanitize_text_field( wp_unslash( $getdata['nonce'] ) ), 'wau_secure_download' )
			);

			if ( $this->wau_cart_has_uploaded_file( $file_name, $access_key, $has_valid_nonce ) ) {
				return true;
			}

			$order_id  = isset( $getdata['order_id'] ) && is_scalar( $getdata['order_id'] ) ? absint( $getdata['order_id'] ) : 0;
			$order_key = isset( $getdata['order_key'] ) && is_scalar( $getdata['order_key'] ) ? sanitize_text_field( wp_unslash( $getdata['order_key'] ) ) : '';
			if ( $order_id && $this->wau_order_request_has_uploaded_file( $order_id, $file_name, $access_key, $order_key ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Check whether the current WooCommerce cart session owns the uploaded file.
		 *
		 * @since 1.7.5
		 *
		 * @param string $file_name       Uploaded file name.
		 * @param string $access_key      Per-file access key.
		 * @param bool   $has_valid_nonce Whether the legacy nonce is valid.
		 * @return bool
		 */
		private function wau_cart_has_uploaded_file( $file_name, $access_key, $has_valid_nonce ) {
			if ( ! function_exists( 'WC' ) ) {
				return false;
			}

			if ( ! WC()->cart && function_exists( 'wc_load_cart' ) ) {
				wc_load_cart();
			}

			if ( ! WC()->cart ) {
				return false;
			}

			foreach ( WC()->cart->get_cart() as $cart_item ) {
				if ( empty( $cart_item['wau_addon_ids'] ) || ! is_array( $cart_item['wau_addon_ids'] ) ) {
					continue;
				}

				foreach ( $cart_item['wau_addon_ids'] as $addon_id ) {
					if ( ! is_array( $addon_id ) || empty( $addon_id['file_name'] ) || ! is_scalar( $addon_id['file_name'] ) || ! hash_equals( (string) $addon_id['file_name'], $file_name ) ) {
						continue;
					}

					if ( isset( $addon_id['access_key'] ) && ! is_scalar( $addon_id['access_key'] ) ) {
						return false;
					}

					if ( ! empty( $addon_id['access_key'] ) && is_scalar( $addon_id['access_key'] ) ) {
						return ! empty( $access_key ) && hash_equals( (string) $addon_id['access_key'], $access_key );
					}

					return $has_valid_nonce;
				}
			}

			return false;
		}

		/**
		 * Check whether an authorized customer order contains the exact uploaded file.
		 *
		 * @since 1.7.5
		 *
		 * @param int    $order_id   Order ID.
		 * @param string $file_name  Uploaded file name.
		 * @param string $access_key Per-file access key.
		 * @param string $order_key  WooCommerce order key for guest orders.
		 * @return bool
		 */
		private function wau_order_request_has_uploaded_file( $order_id, $file_name, $access_key, $order_key ) {
			if ( ! function_exists( 'wc_get_order' ) ) {
				return false;
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return false;
			}

			$customer_id       = (int) $order->get_customer_id();
			$logged_in_owner   = is_user_logged_in() && $customer_id > 0 && $customer_id === get_current_user_id();
			$valid_guest_order = 0 === $customer_id && ! empty( $order_key ) && hash_equals( (string) $order->get_order_key(), $order_key );

			if ( ! $logged_in_owner && ! $valid_guest_order ) {
				return false;
			}

			$allowed_meta_keys = apply_filters(
				'wau_uploaded_media_meta_keys',
				array_unique( array( '_wau_upload_reference', 'Uploaded Media', __( 'Uploaded Media', 'woo-addon-uploads' ) ) )
			);

			foreach ( $order->get_items() as $item ) {
				foreach ( $item->get_meta_data() as $meta ) {
					if ( ! in_array( (string) $meta->key, $allowed_meta_keys, true ) ) {
						continue;
					}

					$reference = $this->wau_get_upload_reference_from_meta( $meta->value );

					if ( empty( $reference['file_name'] ) || ! hash_equals( (string) $reference['file_name'], $file_name ) ) {
						continue;
					}

					if ( ! empty( $reference['access_key'] ) ) {
						return ! empty( $access_key ) && hash_equals( (string) $reference['access_key'], $access_key );
					}

					return true;
				}
			}

			return false;
		}

		/**
		 * Parse one exact upload reference from structured or legacy order metadata.
		 *
		 * @param mixed $value Order-item metadata value.
		 * @return array
		 */
		private function wau_get_upload_reference_from_meta( $value ) {
			if ( is_array( $value ) ) {
				return array(
					'file_name'  => isset( $value['file_name'] ) && is_scalar( $value['file_name'] ) ? sanitize_file_name( basename( (string) $value['file_name'] ) ) : '',
					'access_key' => isset( $value['access_key'] ) && is_scalar( $value['access_key'] ) ? sanitize_text_field( (string) $value['access_key'] ) : '',
				);
			}

			if ( ! is_scalar( $value ) ) {
				return array();
			}

			$stored_value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
			$url          = $stored_value;

			if ( preg_match( '/href\s*=\s*(["\'])(.*?)\1/i', $stored_value, $matches ) ) {
				$url = $matches[2];
			}

			$url_parts = wp_parse_url( $url );
			if ( ! is_array( $url_parts ) ) {
				return array();
			}

			$query = array();
			if ( ! empty( $url_parts['query'] ) ) {
				wp_parse_str( $url_parts['query'], $query );
			}

			if ( isset( $query['action'], $query['file'] ) && 'wau_secure_download' === $query['action'] && is_scalar( $query['file'] ) ) {
				return array(
					'file_name'  => sanitize_file_name( basename( rawurldecode( (string) $query['file'] ) ) ),
					'access_key' => isset( $query['key'] ) && is_scalar( $query['key'] ) ? sanitize_text_field( (string) $query['key'] ) : '',
				);
			}

			$path = isset( $url_parts['path'] ) ? rawurldecode( $url_parts['path'] ) : '';
			if ( preg_match( '#/wau-uploads/([^/]+)$#i', $path, $matches ) ) {
				return array(
					'file_name'  => sanitize_file_name( basename( $matches[1] ) ),
					'access_key' => '',
				);
			}

			return array();
		}

		/**
		 * Locate an uploaded file by filename, checking private storage before legacy storage.
		 *
		 * @since 1.7.5
		 *
		 * @param string $file_name Uploaded file name.
		 * @return string|false
		 */
		private function wau_locate_uploaded_file( $file_name ) {
			$safe_filename = sanitize_file_name( basename( $file_name ) );

			if ( empty( $safe_filename ) ) {
				return false;
			}

			$private_upload_dir = $this->wau_get_private_upload_directory();
			$legacy_upload_dir  = $this->wau_get_legacy_upload_directory();
			$directories        = array();

			if ( ! empty( $private_upload_dir['path'] ) ) {
				$directories[] = $private_upload_dir['path'];
			}

			if ( ! empty( $legacy_upload_dir['path'] ) ) {
				$directories[] = $legacy_upload_dir['path'];
			}

			foreach ( $directories as $directory ) {
				$file_path = trailingslashit( $directory ) . $safe_filename;

				if ( file_exists( $file_path ) && is_file( $file_path ) && $this->wau_is_allowed_upload_path( $file_path ) ) {
					return $file_path;
				}
			}

			return false;
		}

		/**
		 * Check whether a file path is inside one of this plugin's upload directories.
		 *
		 * @since 1.7.5
		 *
		 * @param string $file_path File path.
		 * @return bool
		 */
		private function wau_is_allowed_upload_path( $file_path ) {
			$private_upload_dir = $this->wau_get_private_upload_directory();
			$legacy_upload_dir  = $this->wau_get_legacy_upload_directory();
			$directories        = array();

			if ( ! empty( $private_upload_dir['path'] ) ) {
				$directories[] = $private_upload_dir['path'];
			}

			if ( ! empty( $legacy_upload_dir['path'] ) ) {
				$directories[] = $legacy_upload_dir['path'];
			}

			foreach ( $directories as $directory ) {
				if ( $this->wau_path_is_inside( $file_path, $directory ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Check if part of allowed categories.
		 *
		 * @param bool       $allowed
		 * @param WC_Product $product
		 * @return bool
		 */
		public function wau_check_category_allowed( $allowed, $product ) {

			$addon_settings     = get_option( 'wau_addon_settings' );
			$allowed_categories = isset( $addon_settings['wau_settings_categories'] ) ? $addon_settings['wau_settings_categories'] : array();
			$product_cats       = $product->get_category_ids();

			if ( empty( $allowed_categories ) || in_array( 'all', $allowed_categories, true ) ) {
				return true;
			}

			$match_cats = array_intersect( $product_cats, $allowed_categories );

			if ( empty( $match_cats ) ) {
				return false;
			} else {
				return true;
			}
		}

		/**
		 * Handles secure file downloads for uploaded media.
		 *
		 * This function verifies that the current cart session, order owner, or a
		 * WooCommerce manager/admin is requesting the file, ensures the requested
		 * file exists, and then serves it as a downloadable file.
		 *
		 * @since 1.7.2
		 */
		public function wau_secure_file_download() {
			$getdata = wp_unslash( $_GET );

			if ( isset( $getdata['file'] ) && is_scalar( $getdata['file'] ) ) {

				// 1. Force strict basename isolation to prevent directory traversal updates (e.g., ../../../wp-config.php)
				$safe_filename = sanitize_file_name( basename( $getdata['file'] ) );

				if ( empty( $safe_filename ) || ! $this->wau_current_request_can_download_file( $safe_filename, $getdata ) ) {
					wp_die( esc_html__( 'Unauthorized access.', 'woo-addon-uploads' ) );
				}

				$file_path     = $this->wau_locate_uploaded_file( $safe_filename );

				// 2. Clear any active output buffers to prevent file corruption/whitespace injections
				if ( ob_get_level() ) {
					ob_end_clean();
				}

				if ( $file_path && file_exists( $file_path ) ) {
					$filetype  = wp_check_filetype( $file_path );
					$mime_type = $filetype['type'] ? $filetype['type'] : 'application/octet-stream';

					// Only known passive raster formats may render inline. Other formats are downloads.
					$inline_mimes = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
					$is_image    = in_array( $mime_type, $inline_mimes, true );
					$disposition = ( $is_image && ! isset( $getdata['download'] ) ) ? 'inline' : 'attachment';

					send_nosniff_header();
					header( 'Content-Type: ' . $mime_type );
					header( 'Content-Disposition: ' . $disposition . '; filename="' . basename( $file_path ) . '"' );
					header( 'Content-Length: ' . filesize( $file_path ) );
					readfile( $file_path ); // phpcs:ignore
					exit;
				} else {
					wp_die( esc_html__( 'File does not exist.', 'woo-addon-uploads' ) );
				}
			}

			wp_die( esc_html__( 'Unauthorized access.', 'woo-addon-uploads' ) );
		}
	}
}
