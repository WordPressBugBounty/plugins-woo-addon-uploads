<?php
/**
 * WooCommerce Addon Uploads Admin Class
 *
 * Loads and executes all admin functions and hooks
 *
 * @author      Dhruvin Shah
 * @package     WooCommerce Addon Uploads
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'wau_admin_class' ) ) {

	/**
	 * Addon Uploads Admin Class.
	 */
	class wau_admin_class {

		/*
		* WAU Admin setting class.
		*/
		private $wau_admin_settings_class; // property decelared.
		/**
		 * Default constructor function.
		 */
		public function __construct() {

			$this->load_admin_dependencies();

			// WordPress Administration Menu.
			add_action( 'admin_menu', array( $this, 'addon_upload_settings_menu' ) );
		}

		/**
		 * Functions
		 */

		/**
		 * Load dependencies
		 */
		public function load_admin_dependencies() {

			require_once 'class-wau-admin-settings.php';

			$this->wau_admin_settings_class = new wau_admin_settings_class();
		}

		/**
		 * Addon Settings Menu in admin
		 */
		public function addon_upload_settings_menu() {

			add_menu_page(
				'Addon Upload Settings',
				'Addon Upload Settings',
				'manage_woocommerce',
				'addon_settings_page'
			);
			add_submenu_page(
				'addon_settings_page',
				__( 'Addon Upload Settings', 'woo-addon-uploads' ),
				__( 'Addon Upload Settings', 'woo-addon-uploads' ),
				'manage_woocommerce',
				'addon_settings_page',
				array( $this, 'addon_settings_page' )
			);
			add_submenu_page(
				'addon_settings_page',
				__( 'System Status', 'woo-addon-uploads' ),
				__( 'System Status', 'woo-addon-uploads' ),
				'manage_woocommerce',
				'addon_system_status_page',
				array( $this, 'addon_system_status_page' )
			);
			add_submenu_page(
				'addon_settings_page',
				__( 'Upgrade to Pro', 'woo-addon-uploads' ),
				__( 'Upgrade to Pro', 'woo-addon-uploads' ),
				'manage_woocommerce',
				'addon_pro_page',
				array( $this, 'addon_pro_page' )
			);
		}

		/**
		 * Render navigation tabs.
		 *
		 * @param string $active_page Active page slug.
		 */
		private function render_admin_tabs( $active_page ) {
			$tabs = array(
				'addon_settings_page'      => __( 'Addon Upload Settings', 'woo-addon-uploads' ),
				'addon_system_status_page' => __( 'System Status', 'woo-addon-uploads' ),
				'addon_pro_page'           => __( 'Upgrade to Pro', 'woo-addon-uploads' ),
			);
			?>
				<h2 class="nav-tab-wrapper woo-nav-tab-wrapper">
					<?php foreach ( $tabs as $page => $label ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page ) ); ?>" class="nav-tab <?php echo esc_attr( $active_page === $page ? 'nav-tab-active' : '' ); ?>">
							<?php echo esc_html( $label ); ?>
						</a>
					<?php endforeach; ?>
				</h2>
			<?php
		}

		/**
		 * Addon Settings Page
		 */
		public function addon_settings_page() {
			?>
				<?php $this->render_admin_tabs( 'addon_settings_page' ); ?>

				<?php settings_errors(); ?>

				<?php
				// Detect servers that do not reliably honor .htaccess protection.
				$server_software                  = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
				$uses_nginx_location_rules        = ( false !== strpos( $server_software, 'nginx' ) || false !== strpos( $server_software, 'openlitespeed' ) );
				$requires_server_level_protection = ( $uses_nginx_location_rules || false !== strpos( $server_software, 'caddy' ) );
				$private_uploads_available        = ( 'yes' === get_option( 'wau_private_uploads_available' ) );

				if ( $requires_server_level_protection && ! $private_uploads_available ) {
					$upload_dir    = wp_upload_dir();
					$baseurl_path  = wp_parse_url( $upload_dir['baseurl'], PHP_URL_PATH );
					$relative_path = trailingslashit( $baseurl_path ? $baseurl_path : '/wp-content/uploads' ) . 'wau-uploads/';
					?>
					<div class="notice notice-warning" style="margin: 20px 0; padding: 15px; border-left: 4px solid #ffb900; background: #fff; box-shadow: 0 1px 1px 0 rgba(0,0,0,.1);">
						<p style="font-weight: bold; font-size: 14px; margin-top: 0; color: #b57c00;">
							<?php esc_html_e( 'Additional Upload Protection Required', 'woo-addon-uploads' ); ?>
						</p>
						<p>
							<?php esc_html_e( 'Private upload storage could not be created automatically, and this server may not honor directory-level .htaccess protection.', 'woo-addon-uploads' ); ?>
							<?php esc_html_e( 'To prevent users from directly accessing legacy uploaded files by their URLs, please block web access to the upload path below in your server configuration, then reload/restart the server.', 'woo-addon-uploads' ); ?>
						</p>
						<?php if ( $uses_nginx_location_rules ) : ?>
							<pre style="background: #f6f6f6; padding: 12px; border: 1px solid #ccc; overflow-x: auto; font-family: monospace; font-size: 13px; line-height: 1.5; color: #333; border-radius: 4px;">
							<?php
								echo 'location ~* ' . esc_html( $relative_path ) . " {\n";
								echo "    deny all;\n";
								echo '}';
							?>
							</pre>
						<?php else : ?>
							<pre style="background: #f6f6f6; padding: 12px; border: 1px solid #ccc; overflow-x: auto; font-family: monospace; font-size: 13px; line-height: 1.5; color: #333; border-radius: 4px;"><?php echo esc_html( $relative_path ); ?></pre>
						<?php endif; ?>
						<p style="font-size: 12px; color: #666; margin-bottom: 0;">
							<?php esc_html_e( 'Note: This rule secures your files from direct public access. The plugin will continue to securely stream images and files to authorized users via the secure download handler.', 'woo-addon-uploads' ); ?>
						</p>
					</div>
					<?php
				}
				?>

				<form action='options.php' method='post'>

					<h2><?php esc_html_e( 'Settings', 'woo-addon-uploads' ); ?></h2>

					<?php $this->wau_admin_settings_class->load_addon_settings(); ?>

				</form>
			<?php
		}

		/**
		 * System Status Page.
		 */
		public function addon_system_status_page() {
			$status       = $this->get_system_status();
			$action_items = $this->get_system_status_action_items( $status );
			?>
				<?php $this->render_admin_tabs( 'addon_system_status_page' ); ?>

				<div class="wrap">
					<h1><?php esc_html_e( 'System Status', 'woo-addon-uploads' ); ?></h1>
					<p>
						<?php esc_html_e( 'Use this page to confirm where uploaded files are stored, whether legacy public uploads are protected, and what actions may be needed on this hosting environment.', 'woo-addon-uploads' ); ?>
					</p>

					<h2><?php esc_html_e( 'Upload Storage', 'woo-addon-uploads' ); ?></h2>
					<table class="widefat striped" style="max-width: 1100px;">
						<tbody>
							<?php $this->render_status_row( __( 'Private upload storage', 'woo-addon-uploads' ), $status['private_storage_label'], $status['private_storage_ok'] ? 'success' : 'warning' ); ?>
							<?php $this->render_status_row( __( 'Private upload path', 'woo-addon-uploads' ), $status['private_path'] ? $status['private_path'] : __( 'Not available', 'woo-addon-uploads' ), $status['private_storage_ok'] ? 'info' : 'warning' ); ?>
							<?php $this->render_status_row( __( 'Manual override', 'woo-addon-uploads' ), "define( 'WAU_PRIVATE_UPLOAD_DIR', '/absolute/private/path/' );", 'info' ); ?>
							<?php $this->render_status_row( __( 'Legacy upload path', 'woo-addon-uploads' ), $status['legacy_path'], 'info' ); ?>
							<?php $this->render_status_row( __( 'Legacy upload URL path', 'woo-addon-uploads' ), $status['legacy_url_path'], 'info' ); ?>
							<?php $this->render_status_row( __( 'Legacy folder exists', 'woo-addon-uploads' ), $status['legacy_exists'] ? __( 'Yes', 'woo-addon-uploads' ) : __( 'No', 'woo-addon-uploads' ), $status['legacy_exists'] ? 'info' : 'success' ); ?>
							<?php $this->render_status_row( __( 'Legacy file count', 'woo-addon-uploads' ), (string) $status['legacy_file_count'], $status['legacy_file_count'] > 0 ? 'warning' : 'success' ); ?>
						</tbody>
					</table>

					<h2><?php esc_html_e( 'Access Protection', 'woo-addon-uploads' ); ?></h2>
					<table class="widefat striped" style="max-width: 1100px;">
						<tbody>
							<?php $this->render_status_row( __( '.htaccess protection', 'woo-addon-uploads' ), $status['htaccess_label'], $status['htaccess_ok'] ? 'success' : 'warning' ); ?>
							<?php $this->render_status_row( __( 'web.config protection', 'woo-addon-uploads' ), $status['web_config_label'], $status['web_config_ok'] ? 'success' : 'warning' ); ?>
							<?php $this->render_status_row( __( 'Server software', 'woo-addon-uploads' ), $status['server_software'] ? $status['server_software'] : __( 'Unknown', 'woo-addon-uploads' ), 'info' ); ?>
							<?php $this->render_status_row( __( 'Server-level protection needed', 'woo-addon-uploads' ), $status['server_rule_needed'] ? __( 'Yes, because private storage is unavailable and this server may ignore .htaccess files.', 'woo-addon-uploads' ) : __( 'No immediate server action detected.', 'woo-addon-uploads' ), $status['server_rule_needed'] ? 'warning' : 'success' ); ?>
						</tbody>
					</table>

					<h2><?php esc_html_e( 'Migration', 'woo-addon-uploads' ); ?></h2>
					<table class="widefat striped" style="max-width: 1100px;">
						<tbody>
							<?php $this->render_status_row( __( 'Legacy migration', 'woo-addon-uploads' ), $status['migration_label'], $status['migration_complete'] ? 'success' : 'warning' ); ?>
							<?php $this->render_status_row( __( 'Migration batch size', 'woo-addon-uploads' ), (string) $status['migration_batch_size'], 'info' ); ?>
						</tbody>
					</table>

					<h2><?php esc_html_e( 'Action Items', 'woo-addon-uploads' ); ?></h2>
					<div class="notice notice-<?php echo esc_attr( $status['has_required_action'] ? 'warning' : 'success' ); ?>" style="max-width: 1060px; margin-left: 0;">
						<?php if ( $status['has_required_action'] ) : ?>
							<ul style="list-style: disc; margin-left: 20px;">
								<?php foreach ( $action_items as $action_item ) : ?>
									<li><?php echo wp_kses_post( $action_item ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php else : ?>
							<p><?php esc_html_e( 'No immediate action is required. New uploads are using private storage and legacy files are either migrated or not present.', 'woo-addon-uploads' ); ?></p>
						<?php endif; ?>
					</div>

					<?php if ( $status['server_rule_needed'] ) : ?>
						<h2><?php esc_html_e( 'Server Rule', 'woo-addon-uploads' ); ?></h2>
						<p><?php esc_html_e( 'Share the following path with your host and ask them to block direct public access to it. Secure download links will continue to work through WordPress.', 'woo-addon-uploads' ); ?></p>
						<?php if ( $status['uses_nginx_location_rules'] ) : ?>
							<pre style="max-width: 1060px; background: #f6f6f6; padding: 12px; border: 1px solid #ccc; overflow-x: auto;">location ~* <?php echo esc_html( $status['legacy_url_path'] ); ?> {
    deny all;
}</pre>
						<?php else : ?>
							<pre style="max-width: 1060px; background: #f6f6f6; padding: 12px; border: 1px solid #ccc; overflow-x: auto;"><?php echo esc_html( $status['legacy_url_path'] ); ?></pre>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php
		}

		/**
		 * Get upload/storage system status.
		 *
		 * @return array
		 */
		private function get_system_status() {
			$upload_dir       = wp_upload_dir();
			$legacy_path      = empty( $upload_dir['error'] ) ? trailingslashit( $upload_dir['basedir'] ) . 'wau-uploads/' : '';
			$baseurl_path     = empty( $upload_dir['error'] ) ? wp_parse_url( $upload_dir['baseurl'], PHP_URL_PATH ) : '';
			$legacy_url_path  = trailingslashit( $baseurl_path ? $baseurl_path : '/wp-content/uploads' ) . 'wau-uploads/';
			$legacy_exists    = $legacy_path && is_dir( $legacy_path );
			$private_status   = get_option( 'wau_private_uploads_available' );
			$private_path     = get_option( 'wau_private_uploads_path' );
			$private_ok       = ( 'yes' === $private_status && ! empty( $private_path ) && is_dir( $private_path ) && wp_is_writable( $private_path ) );
			$server_software  = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
			$server_lower     = strtolower( $server_software );
			$uses_nginx_rules = ( false !== strpos( $server_lower, 'nginx' ) || false !== strpos( $server_lower, 'openlitespeed' ) );
			$server_sensitive = ( $uses_nginx_rules || false !== strpos( $server_lower, 'caddy' ) );
			$migration_done   = (bool) get_option( 'wau_legacy_upload_migration_complete' );
			$legacy_count     = $legacy_exists ? $this->count_legacy_upload_files( $legacy_path ) : 0;
			$htaccess_status  = $this->get_protection_file_status( $legacy_path . '.htaccess', $this->get_expected_htaccess_content(), $legacy_exists );
			$web_conf_status  = $this->get_protection_file_status( $legacy_path . 'web.config', $this->get_expected_web_config_content(), $legacy_exists );
			$batch_size       = absint( apply_filters( 'wau_legacy_upload_migration_batch_size', 25 ) );
			$batch_size       = $batch_size > 0 ? $batch_size : 25;
			$server_needed    = ( ! $private_ok && $server_sensitive );

			return array(
				'private_storage_ok'       => $private_ok,
				'private_storage_label'    => $private_ok ? __( 'Available and writable', 'woo-addon-uploads' ) : __( 'Not available or not writable', 'woo-addon-uploads' ),
				'private_path'             => $private_path,
				'legacy_path'              => $legacy_path ? $legacy_path : __( 'Unavailable', 'woo-addon-uploads' ),
				'legacy_url_path'          => $legacy_url_path,
				'legacy_exists'            => $legacy_exists,
				'legacy_file_count'        => $legacy_count,
				'htaccess_ok'              => $htaccess_status['ok'],
				'htaccess_label'           => $htaccess_status['label'],
				'web_config_ok'            => $web_conf_status['ok'],
				'web_config_label'         => $web_conf_status['label'],
				'server_software'          => $server_software,
				'server_rule_needed'       => $server_needed,
				'uses_nginx_location_rules' => $uses_nginx_rules,
				'migration_complete'       => $migration_done || ( 0 === $legacy_count && $private_ok ),
				'migration_label'          => $this->get_migration_label( $private_ok, $migration_done, $legacy_count ),
				'migration_batch_size'     => $batch_size,
				'has_required_action'      => ( ! $private_ok || $server_needed || ( $legacy_exists && ( ! $htaccess_status['ok'] || ! $web_conf_status['ok'] ) ) || ( $private_ok && $legacy_count > 0 && ! $migration_done ) ),
			);
		}

		/**
		 * Render one status table row.
		 *
		 * @param string $label Label.
		 * @param string $value Value.
		 * @param string $type  Status type.
		 */
		private function render_status_row( $label, $value, $type = 'info' ) {
			$colors = array(
				'success' => '#008a20',
				'warning' => '#b26200',
				'info'    => '#1d2327',
			);
			$color  = isset( $colors[ $type ] ) ? $colors[ $type ] : $colors['info'];
			?>
				<tr>
					<th scope="row" style="width: 260px;"><?php echo esc_html( $label ); ?></th>
					<td style="color: <?php echo esc_attr( $color ); ?>;"><code><?php echo esc_html( $value ); ?></code></td>
				</tr>
			<?php
		}

		/**
		 * Get action items for the status page.
		 *
		 * @param array $status Status data.
		 * @return array
		 */
		private function get_system_status_action_items( $status ) {
			$items = array();

			if ( ! $status['private_storage_ok'] ) {
				$items[] = sprintf(
					/* translators: %s: PHP constant example. */
					__( 'Ask your host for a writable private directory outside the public web root, then add %s to wp-config.php with that absolute path.', 'woo-addon-uploads' ),
					'<code>define( \'WAU_PRIVATE_UPLOAD_DIR\', \'/absolute/private/path/\' );</code>'
				);
			}

			if ( $status['server_rule_needed'] ) {
				$items[] = sprintf(
					/* translators: %s: Upload URL path. */
					__( 'Ask your host to block direct public access to %s. Secure download links will continue to work through WordPress.', 'woo-addon-uploads' ),
					'<code>' . esc_html( $status['legacy_url_path'] ) . '</code>'
				);
			}

			if ( $status['legacy_exists'] && ! $status['htaccess_ok'] ) {
				$items[] = __( 'The legacy .htaccess file is missing or outdated. The plugin will try to update it automatically; if it remains outdated, check file permissions on the legacy upload folder.', 'woo-addon-uploads' );
			}

			if ( $status['legacy_exists'] && ! $status['web_config_ok'] ) {
				$items[] = __( 'The legacy web.config file is missing or outdated. The plugin will try to update it automatically; if it remains outdated, check file permissions on the legacy upload folder.', 'woo-addon-uploads' );
			}

			if ( $status['private_storage_ok'] && $status['legacy_file_count'] > 0 && ! $status['migration_complete'] ) {
				$items[] = __( 'Legacy files are still being migrated to private storage in small batches. Keep the plugin active and allow normal site traffic or admin page visits to continue the migration.', 'woo-addon-uploads' );
			}

			return $items;
		}

		/**
		 * Count customer files in the legacy public upload folder.
		 *
		 * @param string $legacy_path Legacy path.
		 * @return int
		 */
		private function count_legacy_upload_files( $legacy_path ) {
			$count = 0;

			try {
				$directory = new DirectoryIterator( $legacy_path );
			} catch ( Exception $exception ) {
				return 0;
			}

			foreach ( $directory as $file_info ) {
				if ( $file_info->isDot() || ! $file_info->isFile() ) {
					continue;
				}

				if ( in_array( strtolower( $file_info->getFilename() ), array( '.htaccess', 'web.config', 'index.php' ), true ) ) {
					continue;
				}

				$count++;
			}

			return $count;
		}

		/**
		 * Get protection file status.
		 *
		 * @param string $path            File path.
		 * @param string $expected        Expected content.
		 * @param bool   $legacy_exists   Whether the legacy directory exists.
		 * @return array
		 */
		private function get_protection_file_status( $path, $expected, $legacy_exists ) {
			if ( ! $legacy_exists ) {
				return array(
					'ok'    => true,
					'label' => __( 'Not needed because the legacy folder does not exist', 'woo-addon-uploads' ),
				);
			}

			if ( ! file_exists( $path ) ) {
				return array(
					'ok'    => false,
					'label' => __( 'Missing', 'woo-addon-uploads' ),
				);
			}

			if ( ! is_readable( $path ) ) {
				return array(
					'ok'    => false,
					'label' => __( 'Present but not readable', 'woo-addon-uploads' ),
				);
			}

			$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( $expected === $content ) {
				return array(
					'ok'    => true,
					'label' => __( 'Present and current', 'woo-addon-uploads' ),
				);
			}

			return array(
				'ok'    => false,
				'label' => __( 'Present but outdated', 'woo-addon-uploads' ),
			);
		}

		/**
		 * Get migration label.
		 *
		 * @param bool $private_ok     Whether private storage is available.
		 * @param bool $migration_done Whether migration is marked complete.
		 * @param int  $legacy_count   Legacy file count.
		 * @return string
		 */
		private function get_migration_label( $private_ok, $migration_done, $legacy_count ) {
			if ( ! $private_ok ) {
				return __( 'Waiting for private storage', 'woo-addon-uploads' );
			}

			if ( $migration_done || 0 === $legacy_count ) {
				return __( 'Complete or no legacy files found', 'woo-addon-uploads' );
			}

			return __( 'In progress', 'woo-addon-uploads' );
		}

		/**
		 * Expected Apache access-control content.
		 *
		 * @return string
		 */
		private function get_expected_htaccess_content() {
			return "# Prevent direct access to files\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder Deny,Allow\n\tDeny from all\n</IfModule>\n";
		}

		/**
		 * Expected IIS access-control content.
		 *
		 * @return string
		 */
		private function get_expected_web_config_content() {
			return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n";
		}

		/**
		 * Addon Settings Page
		 */
		public function addon_pro_page() {
			?>
				<?php $this->render_admin_tabs( 'addon_pro_page' ); ?>

			<?php

			Wau_Pro_Features::pro_features_callback();
		}
	}

}