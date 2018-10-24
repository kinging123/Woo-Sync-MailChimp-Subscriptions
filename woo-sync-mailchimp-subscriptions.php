<?php
/*
Plugin Name: Sync WooCommerce Subscriptions with MailChimp
Version: 1.0
Author: Reuven Karasik
Description: Sync your WooCommerce active subscriptions with a MailChimp list. Made for <a href="http://alefalefalef.com">AlefAlefAlef</a>
Text Domain: wsms
License: GPLv3
*/



class WSMS {
	protected $mailchimp_api_key = '';
	protected $mailchimp_list = '';

	public static function init() {
		global $wsms_instance;
		$class = __CLASS__;
		$wsms_instance = new $class;
	}

	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		// Checks if WooCommerce is installed.
		if ( class_exists( 'WC_Integration' ) ) {
			// Include our integration class.
			include_once 'class-admin-notice.php';
			add_action( 'admin_notices', [ new AdminNotice(), 'displayAdminNotice' ] );

			include_once 'class-wc-integration-wsms.php';
			// Register the integration.
			add_filter( 'woocommerce_integrations', array( $this, 'add_wc_integration' ) );

		} else {
			add_action( 'admin_notices', function() {
				?>
				<div class="error notice">
					<p><?php _e( 'The WSMS plugin requires WooCommerce', 'wsms' ); ?></p>
				</div>
				<?php
			} );
		}
	}

	/**
	 * Add a new integration to WooCommerce.
	 */
	public function add_wc_integration( $integrations ) {
		$integrations[] = 'WC_Integration_WSMS';
		return $integrations;
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'wsms', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}
}

add_action( 'plugins_loaded', array( 'WSMS', 'init' ) );
