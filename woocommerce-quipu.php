<?php
/**
 * Plugin Name: WooCommerce Quipu
 * Plugin URI: https://github.com/quipuapp/quipu-woocommerce
 * Description: WooCommerce integration with Quipu service
 * Author: Shadi Manna
 * Author URI: http://progressusmarketing.com/
 * Version: 1.0
 * Text Domain: quipu-accounting-for-woocommerce
 * Domain Path: /lang
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

if ( ! class_exists( 'WC_Quipu' ) ) :

class WC_Quipu {

	/**
	 * @var WC_Quipu_Integration
	 */
	private $integration;

	/**
	* Construct the plugin.
	*/
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	* Initialize the plugin.
	*/
	public function init() {

		load_plugin_textdomain( 'quipu-accounting-for-woocommerce', false, dirname( plugin_basename(__FILE__) ) . '/lang/' );
		
		// Checks if WooCommerce is installed.
		if ( class_exists( 'WC_Integration' ) ) {
			// Include our integration class.
			include_once 'includes/class-wc-quipu-integration.php';

			// Register the integration.
			add_filter( 'woocommerce_integrations', array( $this, 'add_integration' ) );
		} else {
			// throw an admin error if you like
			add_action( 'admin_notices', array( $this, 'notice_wc_required' ) );
		}
	}

	/**
	 * Add a new integration to WooCommerce.
	 */
	public function add_integration( $integrations ) {
		$integrations[] = 'WC_Quipu_Integration';
		return $integrations;
	}

	/**
	 * Admin error notifying user that WC is required
	 */
	public function notice_wc_required() {
	?>
	<div class="error">
		<p><?php _e( 'WooCommerce Quipu Integration requires WooCommerce to be installed and activated!', 'quipu-accounting-for-woocommerce' ); ?></p>
	</div>
	<?php
	}
}

$WC_Quipu = new WC_Quipu( __FILE__ );

endif;
