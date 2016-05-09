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
		$this->method_title       = __( 'Quipu Integration', 'woocommerce-quipu-integration' );
		$this->method_description = __( 'Quipu accounting integration with WooCommerce.', 'woocommerce-quipu-integration' );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables.
		$this->api_key          	= $this->get_option( 'api_key' );
		$this->api_secret       	= $this->get_option( 'api_secret' );
		$this->num_series       	= $this->get_option( 'num_series' );
		$this->refund_num_series    = $this->get_option( 'refund_num_series' );
		$this->debug            	= $this->get_option( 'debug' );
		// Quipu_Api_Connection class to connect to Quipu
		$this->api_connection 		= null;

		// Actions.
		add_action( 'admin_notices', array( $this, 'checks' ) );
		add_action( 'woocommerce_update_options_integration_' .  $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'order_competed' ) );
		add_action( 'woocommerce_refund_created', array( $this, 'refunded_created' ), 10, 2 );		
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
	function checks() {
		// Check required fields
		if ( empty($this->api_key) || empty($this->api_secret) ) {
			// Show notice
			echo $this->get_message( sprintf( __( 'WooCommerce Quipu error: Plugin is enabled but no api key or secret provided. Please enter your api key and secret <a href="%s">here</a>.', 'woocommerce-quipu-integration' ), WOOCOMMERCE_QUIPU_SETTINGS_URL ) );
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
				'title'             => __( 'API Key', 'woocommerce-quipu-integration' ),
				'type'              => 'text',
				'description'       => __( 'Enter your Quipu API Key. You can find this in ...', 'woocommerce-quipu-integration' ),
				'desc_tip'          => true,
				'default'           => ''
			),
			'api_secret' => array(
				'title'             => __( 'API Secret', 'woocommerce-quipu-integration' ),
				'type'              => 'text',
				'description'       => __( 'Enter your Quipu API Secret. You can find this ...', 'woocommerce-quipu-integration' ),
				'desc_tip'          => true,
				'default'           => ''
			),
			'num_series' => array(
				'title'             => __( 'Numbering Series', 'woocommerce-quipu-integration' ),
				'type'              => 'text',
				'description'       => __( 'Enter a numeration series for your Quipu invoices', 'woocommerce-quipu-integration' ),
				'desc_tip'          => true,
				'default'           => ''
			),
			'refund_num_series' => array(
				'title'             => __( 'Refund Numbering Series', 'woocommerce-quipu-integration' ),
				'type'              => 'text',
				'description'       => __( 'Enter a refund numeration series for your Quipu invoices', 'woocommerce-quipu-integration' ),
				'desc_tip'          => true,
				'default'           => ''
			),
			'debug' => array(
				'title'             => __( 'Debug Log', 'woocommerce-quipu-integration' ),
				'type'              => 'checkbox',
				'label'             => __( 'Enable logging', 'woocommerce-quipu-integration' ),
				'default'           => 'no',
				'description'       => __( 'Log events such as API requests', 'woocommerce-quipu-integration' ),
			)
		);
	}

	/**
	 * The status of an order has been set to "Completed"
	 */
	public function order_competed( $order_id ) {

		$quipu_invoice_id = get_post_meta($order_id, "_quipu_invoice_id", true);

		// If no Quipu id exists then create Quipu invoice, otherwise it was already accounted for
		// Potential issue, if status was "Completed" then "Pending" and stuck there, since in Quipu it is registered as an invoice still.  Solution, should probably be that it gets deleted in Quipu.
		if( empty($quipu_invoice_id) ) {

			$logger = new WC_Quipu_Logger( $this->debug );
			
			try {
				$this->api_connection = Quipu_Api_Connection::get_instance($this->api_key, $this->api_secret);


				$quipu_num = new Quipu_Api_Numeration($this->api_connection);

				$logger->write("Invoice Numbering Series: ".$this->num_series);
				$quipu_num->create_series($this->num_series);
				$logger->write("Numbering Response: ".print_r($quipu_num->get_response(), true));

				$quipu_contact = new Quipu_Api_Contact($this->api_connection);

				$contact = array(
				    		"name" => get_post_meta($order_id, '_billing_first_name', true)." ".get_post_meta($order_id, '_billing_last_name', true)." ".get_post_meta($order_id, '_billing_company', true),
				            "tax_id" => get_post_meta($order_id, '_vat_number', true),
				            "phone" => get_post_meta($order_id, '_billing_phone', true),
				            "email" => get_post_meta($order_id, '_billing_email', true),
				            "address" => get_post_meta($order_id, '_billing_address_1', true).",".get_post_meta($order_id, '_billing_address_2', true),
				            "town" => get_post_meta($order_id, '_billing_city', true),
				            "zip_code" => get_post_meta($order_id, '_billing_country', true),
				            "country_code" => get_post_meta($order_id, '_billing_country', true)
						);

				$logger->write("Contact: ".print_r($contact, true));
				$quipu_contact->create_contact($contact);
				$logger->write("Contact Response: ".print_r($quipu_contact->get_response(), true));

				$quipu_invoice = new Quipu_Api_Invoice($this->api_connection);

				$order = new WC_Order( $order_id );
				$ordered_items = $order->get_items();

				$wc_order_date = date('Y-m-d', strtotime($order->order_date));
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
				            	"product" => "$value[name]",
				            	"cost" => "$product_cost",
				            	"quantity" => "$value[qty]",
				            	"vat_per" => "$vat_per"
				            );
					
					$order['items'][] = $item;
				}
				

				$quipu_invoice->set_contact($quipu_contact);
				$quipu_invoice->set_numeration($quipu_num);

				$logger->write("Order: ".print_r($order, true));
				$quipu_invoice->create_invoice($order);
				$logger->write("Order Response: ".print_r($quipu_invoice->get_response(), true));

				if(!add_post_meta($order_id, "_quipu_invoice_id", $quipu_invoice->get_id(), true)) {
					$logger->write("Couldn't save Quipu ID");
				} else {
					$logger->write("Quipu Invoice ID: ".$quipu_invoice->get_id());	
				}
			} catch (Exception $e) {
				$logger->write($e->getMessage());
			}
		}
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
			
			$logger = new WC_Quipu_Logger( $this->debug );

			try {
				$this->api_connection = Quipu_Api_Connection::get_instance($this->api_key, $this->api_secret);
				
				$quipu_num = new Quipu_Api_Numeration($this->api_connection);
				
				$logger->write("Refund Numbering Series: ".$this->refund_num_series);
				$quipu_num->create_refund_series($this->refund_num_series);
				$logger->write("Refund Numbering Response: ".print_r($quipu_num->get_response(), true));
				

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
							$refund_name = $order_items[$key]['name'];
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

					$logger->write("Partial Refund: ".print_r($refund, true));
				} else {
					$logger->write("Full Refund: ".print_r($refund, true));
				}				
				
				$quipu_invoice->refund_invoice($refund);
				$logger->write("Refund Invoice Response: ".print_r($quipu_invoice->get_response(), true));

			} catch (Exception $e) {
				$logger->write($e->getMessage());
			}				
		}
	}
}

endif;
