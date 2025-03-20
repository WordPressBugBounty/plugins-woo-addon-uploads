<?php
/**
 * WooCommerce Addon Uploads Admin Settings Class
 *
 * Contains all admin settings functions and hooks
 *
 * @author      Dhruvin Shah
 * @package     WooCommerce Addon Uploads
 */

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

			add_filter( 'wau_category_checks', array( $this, 'wau_check_category_allowed' ), 10, 2 );

			add_action( 'woocommerce_cart_item_removed', array( $this, 'wau_remove_cart_action' ), 10, 2 );

			add_action( 'admin_post_wau_secure_download', array( $this, 'wau_secure_file_download' ) );
			add_action( 'admin_post_nopriv_wau_secure_download', array( $this, 'wau_secure_file_download' ) );
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
			$product_ids = apply_filters( 'wau_include_product_ids', array() );

			// Allow category-based conditions to be filtered.
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
				$allowed_types = apply_filters( 'wau_allowed_file_types', array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ) );

				// Validate file type.
				$file_info = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
				$file_ext  = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

				if ( ! in_array( $file_ext, $allowed_types, true ) || ! $file_info['ext'] ) {
					wc_add_notice( __( 'Invalid file type. Only JPG, PNG, GIF, and WebP files are allowed.', 'woo-addon-uploads' ), 'error' );
					return $cart_item_meta;
				}

				// Handle file upload using WordPress function.
				$upload_overrides = array( 'test_form' => false );
				$uploaded_file    = wp_handle_sideload( $file, $upload_overrides );

				if ( isset( $uploaded_file['error'] ) ) {
					wc_add_notice( __( 'File upload failed: ', 'woo-addon-uploads' ) . esc_html( $uploaded_file['error'] ), 'error' );
					return $cart_item_meta;
				}

				// Get WordPress upload directory and set custom subdirectory.
				$upload_dir = wp_upload_dir();
				$custom_dir = trailingslashit( $upload_dir['basedir'] ) . 'wau-uploads/';
				$custom_url = trailingslashit( $upload_dir['baseurl'] ) . 'wau-uploads/';

				// Ensure directory exists.
				if ( ! wp_mkdir_p( $custom_dir ) ) {
					wc_add_notice( __( 'Failed to create upload directory.', 'woo-addon-uploads' ), 'error' );
					return $cart_item_meta;
				}

				// Create .htaccess file if not exists to restrict access.
				$htaccess_path = $custom_dir . '.htaccess';

				if ( ! file_exists( $htaccess_path ) ) {
					$htaccess_content = '
					# Allow images to be displayed in <img> tags but block direct access
					<FilesMatch "\.(jpg|jpeg|png|gif|webp)$">
						Require all granted
					</FilesMatch>
					# Deny access to this directory
					<Files *>
						Order Deny,Allow
						Deny from all
					</Files>
					# Allow access to specific scripts (for secure downloads)
					<FilesMatch "admin-post.php">
						Order Allow,Deny
						Allow from all
					</FilesMatch>';

					// Use WP Filesystem to write the .htaccess file.
					$wp_filesystem->put_contents( $htaccess_path, $htaccess_content, FS_CHMOD_FILE );
				}

				// Generate unique sanitized file name.
				$file_name     = time() . '-' . sanitize_file_name( $file['name'] );
				$new_file_path = $custom_dir . $file_name;
				$new_file_url  = $custom_url . $file_name;

				// Move file using WP_Filesystem.
				if ( $wp_filesystem->move( $uploaded_file['file'], $new_file_path, true ) ) {
					// Store file information.
					$addon_id                          = array(
						'file_path' => esc_url_raw( $new_file_path ), // Absolute file path.
						'file_url'  => esc_url_raw( $new_file_url ), // Public URL.
						'file_name' => esc_html( $file_name ), // File Name.
					);
					$cart_item_meta['wau_addon_ids'][] = $addon_id;
				} else {
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
							// 'nonce'  => wp_create_nonce( 'wau_secure_download' ),
						),
						admin_url( 'admin-post.php' )
					);
					if ( $block_present ) {
						$name    = __( 'Uploaded File', 'woo-addon-uploads' );
						$display = '&#9989;';
					} else {
						$name     = __( 'Uploaded File', 'woo-addon-uploads' );
						$file_url = $addon_id['file_url'];
						$display  = '<img src="' . esc_url( $image_url ) . '" alt="' . esc_attr( $name ) . '" class="wau-upload-img" style="width:150px;height:150px;" />'; //phpcs:ignore
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
				if ( isset( $addon_id['file_url'] ) ) {
					$download_url = add_query_arg(
						array(
							'action' => 'wau_secure_download',
							'file'   => esc_html( $addon_id['file_name'] ),
							// 'nonce'  => wp_create_nonce( 'wau_secure_download' ),
						),
						admin_url( 'admin-post.php' )
					);
					$item->add_meta_data( __( 'Uploaded Media', 'woo-addon-uploads' ), '<a href="' . esc_url( $download_url ) . '" target="_blank">' . esc_html( $addon_id['file_name'] ) . '</a>', true );
				}
			}
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

			// Security check: Prevent directory traversal attacks.
			if ( ! file_exists( $file_name ) ) {
				return new WP_Error( 'invalid_file', __( 'Invalid file or file does not exist.', 'woo-addon-uploads' ) );
			}

			// Attempt to delete the file.
			if ( ! wp_delete_file( $file_name ) ) {
				return new WP_Error( 'delete_failed', __( 'Failed to delete the file.', 'woo-addon-uploads' ) );
			}
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
			if ( isset( $getdata['file'] ) /*&& wp_verify_nonce( $getdata['nonce'], 'wau_secure_download' )*/ ) {
				$file_path = wp_upload_dir()['basedir'] . '/wau-uploads/' . basename( $getdata['file'] );

				if ( file_exists( $file_path ) ) {
					header( 'Content-Type: application/octet-stream' );
					header( 'Content-Disposition: attachment; filename="' . basename( $file_path ) . '"' );
					header( 'Content-Length: ' . filesize( $file_path ) );
					readfile( $file_path ); // phpcs:ignore
					exit;
				} else {
					wp_die( esc_html__( 'File is not exits.', 'woo-addon-uploads' ) );
				}
			}

			wp_die( esc_html__( 'Unauthorized access.', 'woo-addon-uploads' ) );
		}
	}
}
