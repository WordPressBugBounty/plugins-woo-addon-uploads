<?php
/**
 * Plugin Name: File Uploads Addon for WooCommerce
 * Plugin URI: https://imaginate-solutions.com/downloads/woocommerce-addon-uploads/
 * Description: WooCommerce addon to upload additional files before adding product to cart
 * Version: 1.7.5
 * Author: Imaginate Solutions
 * Author URI: https://imaginate-solutions.com
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0.0
 * Text Domain: woo-addon-uploads
 * Domain Path: /i18n/languages/
 *
 * Requires PHP: 7.4
 * WC requires at least: 3.0.0
 * WC tested up to: 11
 *
 * @package WooCommerce Addon Uploads
 * @author Dhruvin Shah
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Woo_Add_Uplds' ) ) {

	/**
	 * Addon Uploads Class.
	 */
	class Woo_Add_Uplds {

		/**
		 * WooCommerce Addon Uploads.
		 *
		 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
		 */
		protected $plugin_name = 'WooCommerce Addon Uploads';

		/**
		 * Version 1.0.0
		 *
		 * @var      string    $version    The current version of the plugin.
		 */
		protected $version = '1.7.5';

		/**
		 * Default construtor function.
		 */
		public function __construct() {
			if (
				! $this->is_plugin_active( 'woocommerce/woocommerce.php' ) ||
				( 'woocommerce-addon-uploads.php' === basename( __FILE__ ) && $this->is_plugin_active( 'woocommerce-addon-uploads-pro/woocommerce-addon-uploads-pro.php' ) )
				) {
				return;
			}
			$this->define_constants();
			$this->load_dependencies();
			$this->set_locale();

			$this->load_admin_settings();
			$this->front_end_actions();
		}

		/**
		 * Is plugin active.
		 *
		 * @param   string $plugin Plugin Name.
		 * @return  bool
		 * @version 1.6.0
		 * @since   1.6.0
		 */
		public function is_plugin_active( $plugin ) {

			$active_plugins = (array) get_option( 'active_plugins', array() );

			if ( in_array( $plugin, $active_plugins, true ) ) {
				return true;
			}

			if ( is_multisite() ) {
				$network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );

				return isset( $network_plugins[ $plugin ] );
			}

			return false;
		}

		/**
		 * Define constants
		 */
		private function define_constants() {
			$upload_dir = wp_upload_dir();
			$this->define( 'WAU_PLUGIN_FILE', __FILE__ );
			$this->define( 'WAU_DIR_NAME', dirname( plugin_basename( __FILE__ ) ) );
		}

		/**
		 * Define constant if not already set.
		 *
		 * @param string      $name Name.
		 * @param string|bool $value Value.
		 */
		private function define( $name, $value ) {
			if ( ! defined( $name ) ) {
				// phpcs:ignore.
				define( $name, $value );
			}
		}

		/**
		 * Load dependencies
		 */
		private function load_dependencies() {
			require_once 'includes/class-wau-admin.php';
			require_once 'includes/class-wau-front-end.php';
			require_once 'includes/class-wau-pro-features.php';
		}

		/**
		 * Set Locale
		 */
		private function set_locale() {
			load_plugin_textdomain( 'woo-addon-uploads', false, dirname( plugin_basename( __FILE__ ) ) . '/i18n/languages/' );
		}

		/**
		 * Load Admin Settings
		 */
		private function load_admin_settings() {

			$admin_class = new wau_admin_class();

			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'action_links' ) );

			add_action( 'before_woocommerce_init', array( $this, 'wau_declare_hpos_compatibility' ) );
		}

		/**
		 * Declare HPOS compatibility
		 */
		public function wau_declare_hpos_compatibility() {
			if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			}
		}

		/**
		 * Add custom links on plugin page
		 *
		 * @param array $links Current Set of links.
		 * @return array
		 */
		public function action_links( $links ) {
			$custom_links = array();
			if ( 'woocommerce-addon-uploads.php' === basename( __FILE__ ) ) {
				$custom_links[] = '<a target="_blank" style="color: #1da867; font-weight: 600" href="https://imaginate-solutions.com/downloads/woocommerce-addon-uploads/?utm_source=lite&utm_medium=fua&utm_campaign=upgrade">' .
				__( 'Upgrade to Pro', 'woo-addon-uploads' ) . '</a>';
			}
			$custom_links[] = '<a href="' . admin_url( 'admin.php?page=addon_settings_page' ) . '">' . __( 'Settings', 'woo-addon-uploads' ) . '</a>';
			return array_merge( $custom_links, $links );
		}

		/**
		 * Load Front End Actions
		 */
		private function front_end_actions() {

			$front_end_class = new wau_front_end_class();
		}
	}

}

new Woo_Add_Uplds();
