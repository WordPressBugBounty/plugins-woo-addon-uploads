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

		public function __construct() {

			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';

			$this->load_scripts();
			add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'addon_uploads_section' ), 999 );

			add_filter( 'woocommerce_add_cart_item_data', array( $this, 'wau_add_cart_item_data' ), 10, 1 );
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
			if ( get_option( 'wau_legacy_upload_migration_complete' ) ) {
				return;
			}

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
					if ( filesize( $source ) === filesize( $destination ) ) {
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
			$site_key       = $this->wau_get_site_storage_key();
			$configured_dir = defined( 'WAU_PRIVATE_UPLOAD_DIR' ) ? WAU_PRIVATE_UPLOAD_DIR : '';
			$configured_dir = apply_filters( 'wau_private_upload_dir', $configured_dir, $site_key );
			$candidates     = array();

			if ( ! empty( $configured_dir ) ) {
				$configured_dir = trailingslashit( $configured_dir );
				$candidates[]   = $site_key === basename( untrailingslashit( wp_normalize_path( $configured_dir ) ) ) ? $configured_dir : $configured_dir . $site_key . '/';
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

				if ( $this->wau_prepare_upload_directory( $candidate ) ) {
					update_option( 'wau_private_uploads_available', 'yes', false );
					update_option( 'wau_private_uploads_path', $candidate, false );

					return array(
						'path' => $candidate,
						'url'  => '',
					);
				}
			}

			update_option( 'wau_private_uploads_available', 'no', false );

			return array(
				'path' => '',
				'url'  => '',
			);
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

			$legacy_upload_dir = $this->wau_get_legacy_upload_directory();
			$this->wau_ensure_upload_directory_protection( true );

			return array(
				'path'    => $legacy_upload_dir['path'],
				'url'     => $legacy_upload_dir['url'],
				'storage' => 'legacy',
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

			$public_root = $document_root ? realpath( $document_root ) : false;
			$public_root = $public_root ? $public_root : realpath( ABSPATH );

			if ( ! $public_root ) {
				return '';
			}

			$base_dir      = dirname( $public_root );
			$web_root_names = array( 'public_html', 'htdocs', 'httpdocs', 'www', 'wwwroot', 'html', 'public' );
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

			// Get addon settings.
			$addon_settings = get_option( 'wau_addon_settings' );

			// Allow filtering of product IDs where the upload should be enabled.
			// phpcs:ignore.
			$product_ids = apply_filters( 'wau_include_product_ids', array() );

			// Allow category-based conditions to be filtered.
			// phpcs:ignore.
			$category_passed = apply_filters( 'wau_category_checks', true, $product );

			$enabled = false;
			if ( ( is_array( $product_ids ) && empty( $product_ids ) ) || in_array( $product->get_id(), $product_ids, true ) ) {
				$enabled = true;
			}

			// Check if the addon feature is enabled and if conditions are met.
			if ( isset( $addon_settings['wau_enable_addon'] ) && '1' === $addon_settings['wau_enable_addon'] && $enabled && $category_passed ) {
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
		 * @return array Updated cart item metadata with uploaded file details.
		 */
		public function wau_add_cart_item_data( $cart_item_meta ) {
			global $wp_filesystem, $post;

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
			if ( isset( $post_file['wau_file_addon'] ) && ! empty( $post_file['wau_file_addon']['name'] ) ) {

				if (
					! isset( $postdata['wau_file_upload_nonce'] ) ||
					! wp_verify_nonce( sanitize_text_field( wp_unslash( $postdata['wau_file_upload_nonce'] ) ), 'wau_file_upload' )
				) {
					wc_add_notice( __( 'Security check failed. Please try again.', 'woo-addon-uploads' ), 'error' );
					return $cart_item_meta;
				}

				$file = $post_file['wau_file_addon'];

				// Apply filter to allow custom file types.
				// phpcs:ignore.
				$allowed_types = apply_filters( 'wau_allowed_file_types', array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ) );

				// Validate file type.
				$file_info = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
				$file_ext  = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

				if ( ! in_array( $file_ext, $allowed_types, true ) || ! $file_info['ext'] ) {
					wc_add_notice( __( 'Invalid file type. Only JPG, PNG, GIF, and WebP files are allowed.', 'woo-addon-uploads' ), 'error' );
					return $cart_item_meta;
				}

				// Get the best available upload storage location.
				$storage_dir = $this->wau_get_upload_storage_directory();
				$custom_dir  = $storage_dir['path'];
				$custom_url  = $storage_dir['url'];

				// Ensure directory exists.
				if ( ! $this->wau_prepare_upload_directory( $custom_dir ) ) {
					wc_add_notice( __( 'Failed to create upload directory.', 'woo-addon-uploads' ), 'error' );
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

				// Generate unique sanitized file name.
				$file_name          = sanitize_file_name( $file['name'] );
				$file_name          = time() . '-' . $file_name;
				$crypto_strong_hash = bin2hex( random_bytes( 16 ) );
				$file_name          = $crypto_strong_hash . '-' . $file_name;
				$new_file_path      = $custom_dir . $file_name;
				$new_file_url       = $custom_url ? $custom_url . $file_name : '';

				// Move file using WP_Filesystem.
				if ( $wp_filesystem->move( $uploaded_file['file'], $new_file_path, true ) ) {
					// Store file information.
					$addon_id                          = array(
						'file_path' => $new_file_path, // Absolute file path.
						'file_url'  => esc_url_raw( $new_file_url ), // Legacy public URL when private storage is unavailable.
						'file_name' => esc_html( $file_name ), // File Name.
						'storage'   => sanitize_key( $storage_dir['storage'] ),
					);
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
			if ( isset( $values['wau_addon_ids'] ) ) {
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
			if ( isset( $cart_item['wau_addon_ids'] ) ) {
				foreach ( $cart_item['wau_addon_ids'] as $addon_id ) {
					$block_present = $this->is_woocommerce_block_present();
					$image_url     = add_query_arg(
						array(
							'action' => 'wau_secure_download',
							'file'   => esc_html( $addon_id['file_name'] ),
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
			if ( empty( $values['wau_addon_ids'] ) ) {
				return;
			}

			// Loop through uploaded files and add them as metadata.
			foreach ( $values['wau_addon_ids'] as $addon_id ) {
				if ( isset( $addon_id['file_name'] ) ) {
					$download_url = add_query_arg(
						array(
							'action'   => 'wau_secure_download',
							'file'     => esc_html( $addon_id['file_name'] ),
							'nonce'    => wp_create_nonce( 'wau_secure_download' ),
							'download' => '1',
						),
						admin_url( 'admin-post.php' )
					);
					$item->add_meta_data( __( 'Uploaded Media', 'woo-addon-uploads' ), '<a href="' . esc_url( $download_url ) . '" download>' . esc_html( $addon_id['file_name'] ) . '</a>', true );
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
			if ( false === strpos( (string) $display_value, 'wau-uploads/' ) ) {
				return $display_value;
			}

			return preg_replace_callback(
				'#https?://[^\'"\s<>]+/wau-uploads/([^\'"\s<>?]+)(?:\?[^\'"\s<>]*)?#i',
				function ( $matches ) {
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
							),
							admin_url( 'admin-post.php' )
						)
					);
				},
				$display_value
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

			// Check if the removed item has an uploaded file.
			if ( isset( $removed_item['wau_addon_ids'][0]['file_path'] ) && ! empty( $removed_item['wau_addon_ids'][0]['file_path'] ) ) {
				$file_name = $removed_item['wau_addon_ids'][0]['file_path'];

				// Call function to delete the uploaded file.
				$this->wau_delete_uploaded_file( $file_name );
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
		 * This function verifies the nonce, ensures the requested file exists,
		 * and then serves it as a downloadable file. It prevents direct access
		 * to the uploaded files and only allows secure downloads via a generated link.
		 *
		 * @since 1.7.2
		 */
		public function wau_secure_file_download() {
			$getdata = wp_unslash( $_GET );

			$has_valid_nonce = (
				isset( $getdata['nonce'] ) &&
				wp_verify_nonce( $getdata['nonce'], 'wau_secure_download' )
			);

			$is_admin_allowed = (
				is_user_logged_in() &&
				current_user_can( 'manage_woocommerce' )
			);

			// Allow if nonce is valid OR admin user.
			if ( isset( $getdata['file'] ) && ( $has_valid_nonce || $is_admin_allowed ) ) {

				// 1. Force strict basename isolation to prevent directory traversal updates (e.g., ../../../wp-config.php)
				$safe_filename = basename( $getdata['file'] );
				$file_path     = $this->wau_locate_uploaded_file( $safe_filename );

				// 2. Clear any active output buffers to prevent file corruption/whitespace injections
				if ( ob_get_level() ) {
					ob_end_clean();
				}

				if ( $file_path && file_exists( $file_path ) ) {
					$filetype  = wp_check_filetype( $file_path );
					$mime_type = $filetype['type'] ? $filetype['type'] : 'application/octet-stream';

					// Serve images inline so they render inside <img> tags, and force download as attachment for other file types or when explicit download requested.
					$is_image    = ( strpos( $mime_type, 'image/' ) === 0 );
					$disposition = ( $is_image && ! isset( $getdata['download'] ) ) ? 'inline' : 'attachment';

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
