<?php
/**
 * WooCommerce Quipu Integration.
 *
 * @package  WC_Quipu_Integration
 * @category Integration
 * @author   Shadi Manna
 */

if ( ! class_exists( 'WC_Quipu_Integration' ) ) :

class WC_Quipu_Integration extends WC_Integration {

	/**
	 * Init and hook in the integration.
	 */
	public function __construct() {
		global $woocommerce;
		
		if ( ! class_exists( 'WC_Quipu_Logger' ) ) {
			include_once( 'class-wc-quipu-logger.php' );
		}

		if ( ! class_exists( 'Quipu_Api_Connection' ) ) {
			include_once( 'quipu-api/class-quipu-api-connection.php' );
		}

		if ( ! class_exists( 'Quipu_Api_Numeration' ) ) {
			include_once( 'quipu-api/class-quipu-api-numeration.php' );
		}

		if ( ! class_exists( 'Quipu_Api_Contact' ) ) {
			include_once( 'quipu-api/class-quipu-api-contact.php' );
		}

		if ( ! class_exists( 'Quipu_Api_Invoice' ) ) {
			include_once( 'quipu-api/class-quipu-api-invoice.php' );
		}

		if ( ! defined( 'WOOCOMMERCE_QUIPU_SETTINGS_URL' ) ) {
			define( 'WOOCOMMERCE_QUIPU_SETTINGS_URL', 'admin.php?page=wc-settings&tab=integration&section=quipu-integration' );
		}
		
		$this->id                 = 'quipu-integration';
		$this->method_title       = __( 'Quipu', 'quipu-accounting-for-woocommerce' );
		$this->method_description = __( 'Quipu accounting integration with WooCommerce. If you do not have a Quipu account try it <a href="https://getquipu.com/woocommerce-programa-facturacion-impuestos?utm_source=pluginwoocommerce&utm_medium=link&utm_campaign=ecommerce" target="_blank">here</a>.', 'quipu-accounting-for-woocommerce' );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables.
		$this->api_key          	= $this->get_option( 'api_key' );
		$this->api_secret       	= $this->get_option( 'api_secret' );
		$this->num_series       	= $this->get_option( 'num_series' );
		$this->refund_num_series    = $this->get_option( 'refund_num_series' );
		$this->debug            	= $this->get_option( 'debug' );
		// Check if sync completed already
		$this->sync_num_series   	= $this->get_option( 'sync_num_series' );
		
		// Quipu_Api_Connection class to connect to Quipu
		$this->api_connection 		= null;
		// Logger class
		$this->logger = new WC_Quipu_Logger( $this->debug );

		// Actions.
		add_action( 'admin_notices', array( $this, 'checks' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'my_enqueue' ) );
		add_action( 'wp_ajax_sync_orders', array( $this, 'sync_orders_callback' ) );

		add_action( 'woocommerce_update_options_integration_' .  $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'order_competed' ) );
		add_action( 'woocommerce_refund_created', array( $this, 'refunded_created' ), 10, 2 );		
	}


	public function my_enqueue($hook) {
		// error_log('my_enqueue');
		// error_log($hook );
	    if( 'woocommerce_page_wc-settings' != $hook ) {
			// Only applies to WC Settings panel
			return;
	    }
	    	        
		wp_enqueue_script( 'ajax-script-quipu', plugins_url( '../assets/js/sync.js', __FILE__ ), array('jquery') );
		wp_enqueue_style( 'quipu-css', plugins_url( '../assets/css/woocommerce-quipu.css', __FILE__ ) );

		// in JavaScript, object properties are accessed as ajax_object.ajax_url, ajax_object.we_value
		wp_localize_script( 'ajax-script-quipu', 'ajax_object', array( 'ajax_url' => admin_url( 'admin-ajax.php' ) ) );
	}

	/**
	 * Get message
	 * @return string Error
	 */
	private function get_message( $message, $type = 'error' ) {
		ob_start();

		?>
		<div class="<?php echo $type ?>">
			<p><?php echo $message ?></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Check if the user has enabled the plugin functionality, but hasn't provided an api key
	 **/
	public function checks() {
		// Check required fields
		if ( empty($this->api_key) || empty($this->api_secret) ) {
			// Show notice
			echo $this->get_message( sprintf( __( 'WooCommerce Quipu: Plugin is enabled but no api key or secret provided. Please enter your api key and secret <a href="%s">here</a>.', 'quipu-accounting-for-woocommerce' ), WOOCOMMERCE_QUIPU_SETTINGS_URL ) );
		}
	}

	/**
	 * Initialize integration settings form fields.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'api_key' => array(
				'title'             => __( 'API Key', 'quipu-accounting-for-woocommerce' ),
				'type'              => 'text',
				'description'       => __( 'Enter your Quipu API Key. You can find this in your Quipu account in Settings -> Integrations -> App ID', 'quipu-accounting-for-woocommerce' ),
				'desc_tip'          => true,
				'default'           => ''
			),
			'api_secret' => array(
				'title'             => __( 'API Secret', 'quipu-accounting-for-woocommerce' ),
				'type'              => 'text',
				'description'       => __( 'Enter your Quipu API Secret. You can find this in your Quipu account in Settings -> Integrations -> App secret', 'quipu-accounting-for-woocommerce' ),
				'desc_tip'          => true,
				'default'           => ''
			),
			'num_series' => array(
				'title'             => __( 'Numbering Series', 'quipu-accounting-for-woocommerce' ),
				'type'              => 'text',
				'description'       => __( 'Enter a numeration series for your Quipu invoices e.g. "WC" to generate invoices WC-1, WC-2 etc.', 'quipu-accounting-for-woocommerce' ),
				'desc_tip'          => true,
				'default'           => ''
			),
			'refund_num_series' => array(
				'title'             => __( 'Refund Numbering Series', 'quipu-accounting-for-woocommerce' ),
				'type'              => 'text',
				'description'       => __( 'Enter a refund numeration series for your Quipu invoices e.g. "RF" to generate refund invoices RF-1, RF-2 etc.', 'quipu-accounting-for-woocommerce' ),
				'desc_tip'          => true,
				'default'           => ''
			),
			'cust_title' => array( 
					'title' => __( 'Customer Import', 'quipu-accounting-for-woocommerce' ), 
					'type' => 'title', 
					'description' => __( 'All customers will be automtically added to your Quipu account. To avoid duplication use the <a href="https://www.woothemes.com/products/eu-vat-number/" target="_blank">EU VAT number</a> plugin to match against existing customers in Quipu.', 'quipu-accounting-for-woocommerce' )
			),
			'debug' => array(
				'title'             => __( 'Debug Log', 'quipu-accounting-for-woocommerce' ),
				'type'              => 'checkbox',
				'label'             => __( 'Enable logging', 'quipu-accounting-for-woocommerce' ),
				'default'           => 'no',
				'description'       => __( 'Log events such as API requests', 'quipu-accounting-for-woocommerce' ),
			)
		);

		
		$wc_quipu_sync = get_option('_wc_quipu_sync');

		if (!$wc_quipu_sync) {
			$sync_section = array(
				'sync_title' => array( 
					'title' => __( 'Synchoronize Orders', 'quipu-accounting-for-woocommerce' ), 
					'type' => 'title', 
					'description' => __( 'Synchronize previous orders in "Completed" status with your Quipu account.', 'quipu-accounting-for-woocommerce' )
				),
				'sync_num_series' => array(
					'title'             => __( 'Sync Numbering Series', 'quipu-accounting-for-woocommerce' ),
					'type'              => 'text',
					'description'       => __( 'Enter a numeration series for previous orders and "Save Changes" before syncronizing.', 'quipu-accounting-for-woocommerce' )
				),
				'customize_button' => array(
					'title'             => __( 'Sync', 'quipu-accounting-for-woocommerce' ),
					'type'              => 'button',
					'custom_attributes' => array(
						'onclick' => "syncOrdersQuipu();",
					),
					'description'       => __( 'Sync orders', 'quipu-accounting-for-woocommerce' ),
					'desc_tip'          => true,
				),
				'sync_message' => array( 
					'title' => __( ' ', 'quipu-accounting-for-woocommerce' ), 
					'type' => 'title', 
					'description' => __( '<i>Note: Once the sync is completed, this option will disapear from the Quipu settings menu!</i>', 'quipu-accounting-for-woocommerce' )
				),			
			);

			$this->form_fields = array_merge($this->form_fields, $sync_section);		
			
		}	
	}

	/**
	 * Generate Button HTML.
	 *
	 * @access public
	 * @param mixed $key
	 * @param mixed $data
	 * @since 1.0.0
	 * @return string
	 */
	public function generate_button_html( $key, $data ) {
		$field    = $this->plugin_id . $this->id . '_' . $key;
		$defaults = array(
			'class'             => 'button-secondary',
			'css'               => '',
			'custom_attributes' => array(),
			'desc_tip'          => false,
			'description'       => '',
			'title'             => '',
		);

		$data = wp_parse_args( $data, $defaults );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
				<?php echo $this->get_tooltip_html( $data ); ?>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
					<button class="<?php echo esc_attr( $data['class'] ); ?>" type="button" name="<?php echo esc_attr( $field ); ?>" id="<?php echo esc_attr( $field ); ?>" style="<?php echo esc_attr( $data['css'] ); ?>" <?php echo $this->get_custom_attribute_html( $data ); ?>><?php echo wp_kses_post( $data['title'] ); ?></button>
					<?php echo $this->get_description_html( $data ); ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Validate the API key
	 * @see validate_settings_fields()
	 */
	public function validate_api_key_field( $key ) {
		// get the posted value
		$value = $_POST[ $this->plugin_id . $this->id . '_' . $key ];
		
		// Check if the Sync Numbering Series is empty
		if ( empty($value) ) {
			$this->errors['api_keys'] = $key;
		}	

		return $value;
	}

	/**
	 * Validate the API secret
	 * @see validate_settings_fields()
	 */
	public function validate_api_secret_field( $key ) {
		// get the posted value
		$value = $_POST[ $this->plugin_id . $this->id . '_' . $key ];
		
		// Check if the Sync Numbering Series is empty
		if ( empty($value) ) {
			$this->errors['api_keys'] = $key;
		}	

		return $value;
	}


	/**
	 * Display errors by overriding the display_errors() method
	 * @see display_errors()
	 */
	public function display_errors( ) {

		// loop through each error and display it
		foreach ( $this->errors as $key => $value ) {
			switch ($key) {
				case 'sync_num':
					?>
					<div class="error">
						<p><?php _e( 'Cannot sync with Quipu with an empty "Sync Numbering Series". Please enter a "Sync Numbering Series" to sync previous orders.', 'quipu-accounting-for-woocommerce' ); ?></p>
					</div>
					<?php
					break;
				case 'api_keys':
					?>
					<div class="error">
						<p><?php _e( 'The API key or secret are empty. Enter API key or secret to use WooCommerce Quipu plugin. ', 'quipu-accounting-for-woocommerce' ); ?></p>
					</div>
					<?php
					break;
				case 'same_num':
					?>
					<div class="error">
						<p><?php _e( 'The "Numbering Series" and "Sync Numbering Series" cannot be the same. ', 'quipu-accounting-for-woocommerce' ); ?></p>
					</div>
					<?php
					break;
				case 'sync_num_exists':
					?>
					<div class="error">
						<p><?php _e( 'The "Sync Numbering Series" already exists in Quipu. Enter a new numbering series to sync previous orders.', 'quipu-accounting-for-woocommerce' ); ?></p>
					</div>
					<?php
					break;
				default:
					?>
					<div class="error">
						<p><?php _e( 'Error, could not save settings.', 'quipu-accounting-for-woocommerce' ); ?></p>
					</div>
					<?php
					break;
			}
			
		}
	}

	public function sync_orders_callback() {
		// error_log(print_r($_POST, true));
		$sync_num = $_POST['sync_num'];

		if(empty($sync_num)) {
			echo _e( 'Please enter a "Sync Numbering Series" before syncing previous orders.', 'quipu-accounting-for-woocommerce' );
			wp_die();
		}

		if(empty($this->api_key) || empty($this->api_secret) ){
			echo _e( 'Please save your API key and secret before syncing previous orders.', 'quipu-accounting-for-woocommerce' );
			wp_die();
		}
		
		// Check if Numbering Series already exists in Quipu
		$this->api_connection = Quipu_Api_Connection::get_instance($this->api_key, $this->api_secret);	
		
		$quipu_num = new Quipu_Api_Numeration($this->api_connection);

		$this->logger->write("Sync Numbering Series: ".$sync_num);
		$quipu_num->get_series($sync_num);
		$this->logger->write("Sync Numbering Response: ".print_r($quipu_num->get_response(), true));	
		$num_id = $quipu_num->get_id();

		if(!empty($num_id)) {
			echo _e( 'The "Sync Numbering Series" already exists in Quipu. Enter a new numbering series to sync previous orders.', 'quipu-accounting-for-woocommerce' );
			wp_die();
		}						


		$orders = get_posts( array(
	        			'post_type'   		=> 'shop_order',
	        			'post_status' 		=> array( 'wc-processing', 'wc-completed' ),
	        			'posts_per_page' 	=> -1, // get all orders
		) );
		// error_log(print_r($orders, true));

		// Get "Completed" date not order date
		foreach ($orders as $order) {
			// error_log(print_r($order->ID, true));
			$completed_date = get_post_meta($order->ID, '_completed_date', true);
			if(empty($completed_date)) {
				$orders_comp[$order->ID] = $order->post_date;
			} else {
				$orders_comp[$order->ID] = $completed_date;	
			}				
		}
		
		// error_log(print_r($orders_comp, true));
		// Order by ascending date. Even though is string will be ordered correctly since format YYYY-mm-dd
		asort($orders_comp);
		// error_log(print_r($orders_comp, true));

		foreach ($orders_comp as $key => $value) {
			// $this->order_competed($key);
			$this->create_quipu_invoice( $key, $sync_num, $value);
		}

		update_option('_wc_quipu_sync', 'yes');
		echo _e( 'All previous orders have been synced with your Quipu account.', 'quipu-accounting-for-woocommerce' );;
		wp_die();
	}


	/**
	 * Create a Quipu invoice, for a given order, prefix and completed date
	 */
	public function create_quipu_invoice( $order_id , $prefix, $completed_date) {

		$quipu_invoice_id = get_post_meta($order_id, "_quipu_invoice_id", true);

		// If no Quipu id exists then create Quipu invoice, otherwise it was already accounted for
		// Potential issue, if status was "Completed" then "Pending" and stuck there, since in Quipu it is registered as an invoice still.  Solution, should probably be that it gets deleted in Quipu.
		if( empty($quipu_invoice_id) ) {

			try {
				$this->api_connection = Quipu_Api_Connection::get_instance($this->api_key, $this->api_secret);

				$quipu_num = new Quipu_Api_Numeration($this->api_connection);

				$this->logger->write("Invoice Numbering Series: ".$prefix);
				$quipu_num->create_series($prefix);
				$this->logger->write("Numbering Response: ".print_r($quipu_num->get_response(), true));

				$quipu_contact = new Quipu_Api_Contact($this->api_connection);

				$contact = array(
				    		"name" => get_post_meta($order_id, '_billing_first_name', true)." ".get_post_meta($order_id, '_billing_last_name', true)." ".get_post_meta($order_id, '_billing_company', true),
				            "tax_id" => get_post_meta($order_id, '_vat_number', true),
				            "phone" => get_post_meta($order_id, '_billing_phone', true),
				            "email" => get_post_meta($order_id, '_billing_email', true),
				            "address" => get_post_meta($order_id, '_billing_address_1', true).",".get_post_meta($order_id, '_billing_address_2', true),
				            "town" => get_post_meta($order_id, '_billing_city', true),
				            "zip_code" => get_post_meta($order_id, '_billing_postcode', true),
				            "country_code" => get_post_meta($order_id, '_billing_country', true)
						);

				$this->logger->write("Contact: ".print_r($contact, true));
				$quipu_contact->create_contact($contact);
				$this->logger->write("Contact Response: ".print_r($quipu_contact->get_response(), true));

				$quipu_invoice = new Quipu_Api_Invoice($this->api_connection);

				$order = new WC_Order( $order_id );
				// error_log(print_r($order, true));
				$ordered_items = $order->get_items();
				// error_log(print_r($ordered_items, true));
				$shipping_items = $order->get_items('shipping');
				// error_log(print_r($shipping_items, true));
				// Add shipping "item"
				// $shipping_total = $order->get_shipping_tax();
				// error_log($shipping_total);
				
				// $wc_order_date = date('Y-m-d', strtotime($order->order_date));
				// Use "_completed_date" instead of order date!
				// $completed_date = get_post_meta($order_id, '_completed_date', true);
				$wc_order_date = date('Y-m-d', strtotime($completed_date));
				// error_log($wc_order_date);

				$wc_payment_method = get_post_meta($order_id, '_payment_method', true);
				// The below are the default payment methods that come with WC
				// These Quipu options were not set; "direct_debit", "factoring"
				switch ($wc_payment_method) {
					case 'cod':
						$quipu_payment_method = "cash";
						break;
					case 'cheque':
						$quipu_payment_method = "check";
						break;
					case 'paypal':
						$quipu_payment_method = "paypal";
						break;
					case 'bacs':
						$quipu_payment_method = "bank_transfer";
						break;
					default:
						$quipu_payment_method = "bank_card";
						break;
				}

				$order = array(
				    		"payment_method" => "$quipu_payment_method",
				            "issue_date" => "$wc_order_date"
				            );

				foreach ($ordered_items as $value) {
					$vat_per = round( ($value['line_tax']*100)/($value['line_total']), 4);
					$product_cost = ($value['line_total'])/($value['qty']);

		            $item = array(
				            	"product" => "$value[name] (#$order_id)",
				            	"cost" => "$product_cost",
				            	"quantity" => "$value[qty]",
				            	"vat_per" => "$vat_per"
				            );
					
					$order['items'][] = $item;
				}
				
				foreach ($shipping_items as $value) {
					$shipping_name = 'Shipping: '.$value['name'];
					$shipping_total = $value['cost'];
					$shipping_tax = $value['taxes'];

					if( is_serialized( $shipping_tax )) { 
						$shipping_tax = maybe_unserialize($shipping_tax);
						// error_log(print_r($shipping_tax, true));
						$shipping_tax = $shipping_tax[1];
						// error_log($shipping_tax);
					}

					$shipping_tax_per = round( (($shipping_tax*100)/$shipping_total), 4);

					$shipping = array(
				            	"product" => "$shipping_name",
				            	"cost" => "$shipping_total",
				            	"quantity" => "1",
				            	"vat_per" => "$shipping_tax_per"
				            );

					$order['items'][] = $shipping;
				}				

				$quipu_invoice->set_contact($quipu_contact);
				$quipu_invoice->set_numeration($quipu_num);

				$this->logger->write("Order: ".print_r($order, true));
				$quipu_invoice->create_invoice($order);
				$this->logger->write("Order Response: ".print_r($quipu_invoice->get_response(), true));

				if(!add_post_meta($order_id, "_quipu_invoice_id", $quipu_invoice->get_id(), true)) {
					$this->logger->write("Couldn't save Quipu ID");
				} else {
					$this->logger->write("Quipu Invoice ID: ".$quipu_invoice->get_id());	
				}
			} catch (Exception $e) {
				$this->logger->write($e->getMessage());
			}
		} else {
			$this->logger->write("Order $order_id already Sent to Quipu");
		}
	}

	/**
	 * The status of an order has been set to "Completed"
	 */
	public function order_competed( $order_id ) {
		$date = date('Y-m-d');
		$this->create_quipu_invoice( $order_id , $this->num_series, $date);
	}
	
	public function refunded_created( $refund_id, $args ) {
		// error_log(print_r($args, true));

		$quipu_invoice_id = get_post_meta($args['order_id'], "_quipu_invoice_id", true);

		if( !empty($quipu_invoice_id) ) {
			$refund_amount = $args['amount'];
			// error_log("Refund $: ".$refund_amount);
			$refund_reason = $args['reason'];
			// error_log($refund_reason);
			
			$order = new WC_Order( $args['order_id'] );
			// error_log(print_r($order, true));
			$order_items = $order->get_items();
			// error_log(print_r($order_items, true));
			$order_amount = $order->get_total();
			// error_log("Order $: ".$order_amount);

			try {
				$this->api_connection = Quipu_Api_Connection::get_instance($this->api_key, $this->api_secret);
				
				$quipu_num = new Quipu_Api_Numeration($this->api_connection);
				
				$this->logger->write("Refund Numbering Series: ".$this->refund_num_series);
				$quipu_num->create_refund_series($this->refund_num_series);
				$this->logger->write("Refund Numbering Response: ".print_r($quipu_num->get_response(), true));
				

				$quipu_invoice = new Quipu_Api_Invoice($this->api_connection);
				$quipu_invoice->set_numeration($quipu_num);

				$refund_date = date('Y-m-d', time());
				$refund = array(
							"invoice_id" => "$quipu_invoice_id",
							"refund_date" => "$refund_date"
							);

				// Partial refund if the order amount is NOT the same as the refund amount!
				if ($refund_amount != $order_amount) {
						
					$items_total = 0;
					foreach ($args['line_items'] as $key => $value) {					
						
						if( (isset($value['refund_total'])) && ($value['refund_total']) ) {

							$items_total += $value['refund_total'];

							if( !empty($value['refund_tax'][1]) ) {
								$items_total += $value['refund_tax'][1];
							}

							// Quipu API needs to have at least 1 for quantity, even for partial refunds
							if( empty($value['qty']) ) {
								$value['qty'] = 1;
							}

							$product_cost = ($value['refund_total'])/($value['qty']);
							$product_cost = $product_cost*-1; // Needs to be negative number
							$vat_per = round( ($value['refund_tax'][1]*100)/($value['refund_total']), 4);				
							// Make sure name + key is set, otherwise assume it's a shipping item
							if( isset($order_items[$key]['name']) ) {
								$refund_name = $order_items[$key]['name'];	
							} else {
								$refund_name = "Shipping";
							}
							
							if( !empty($refund_reason) ) {
								$refund_name .= " - Refund Reason: $refund_reason";
							}

				            $item = array(
						            	"product" => "$refund_name",
						            	"cost" => "$product_cost", 
						            	"quantity" => "$value[qty]",
						            	"vat_per" => "$vat_per"
						            );
							
							$refund['items'][] = $item;
						}
					}

					if($items_total != $refund_amount) {
						// Calculate amount left to place in "dummy" refund item
						$product_cost = $refund_amount - $items_total;
						$product_cost = $product_cost*-1; // Needs to be negative number

						$refund_name = '';
						if( !empty($refund_reason) ) {
							$refund_name = "Refund Item - Refund Reason: $refund_reason";
						} else {
							$refund_name = "Refund Item";
						}

						$item = array(
					            	"product" => "$refund_name",
					            	"cost" => "$product_cost",
					            	"quantity" => "1",
					            	"vat_per" => "0"
					            );
						
						$refund['items'][] = $item;
					}

					$this->logger->write("Partial Refund: ".print_r($refund, true));
				} else {
					$this->logger->write("Full Refund: ".print_r($refund, true));
				}				
				
				$quipu_invoice->refund_invoice($refund);
				$this->logger->write("Refund Invoice Response: ".print_r($quipu_invoice->get_response(), true));

			} catch (Exception $e) {
				$this->logger->write($e->getMessage());
			}				
		}
	}
}

endif;
