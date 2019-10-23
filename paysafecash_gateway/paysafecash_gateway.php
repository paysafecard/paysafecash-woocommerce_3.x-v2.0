<?php
/*
 * Plugin Name: Paysafecash
 * Plugin URI: https://www.paysafecash.com/en/
 * Description: Take paysafecash payments on your store.
 * Author: Paysafecash
 * Text Domain: paysafecash
 * Author URI: https://www.paysafecash.com/en/
 * Version: 1.0.8
 *
*/
use phpseclib\Crypt\RSA;

include( plugin_dir_path( __FILE__ ) . 'libs/PaymentClass.php' );
include( plugin_dir_path( __FILE__ ) . 'vendor/autoload.php' );


if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	add_filter( 'woocommerce_payment_gateways', 'paysafecash_add_gateway_class' );
	add_action( 'plugins_loaded', 'paysafecash_init_gateway_class' );
}

function paysafecash_add_gateway_class( $methods ) {
	$methods[] = 'WC_Paysafecash_Gateway';

	return $methods;
}

function paysafecash_country_restriction( $available_gateways ) {
	global $woocommerce;

	$options    = get_option( 'woocommerce_paysafecash_settings' );
	$is_diabled = true;

	foreach ( $options["country"] AS $country ) {
		if ( WC()->customer != null ) {
			if ( WC()->customer->get_shipping_country() == $country ) {
				$is_diabled = false;
			}
		} else {
			$is_diabled = false;
		}
	}

	if ( $is_diabled == true ) {
		unset( $available_gateways["paysafecash"] );
	}

	return $available_gateways;

}

function paysafecash_init_gateway_class() {

	load_plugin_textdomain( 'paysafecash', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

	class WC_Paysafecash_Gateway extends WC_Payment_Gateway {

		public function __construct() {
			$this->id                 = 'paysafecash';
			$this->icon               = '';
			$this->has_fields         = true;
			$this->method_title       = 'Paysafecash';
			$this->method_description = __( 'PAY WITH CASH: Generate a barcode and go to a <a href="https://www.paysafecash.com/pos" target="blank">payment point near you</a> to complete the payment.', 'paysafecash' );
			$this->description        = $this->method_description;
			$this->version            = "1.0.8";
			$this->supports           = array(
				'products',
				'refunds'
			);

			$this->init_form_fields();
			$this->init_settings();
			$this->title                  = "Paysafecash";
			$this->description            = __( 'PAY WITH CASH: Generate a barcode and go to a <a href="https://www.paysafecash.com/pos" target="blank">payment point near you</a> to complete the payment.', 'paysafecash' );
			$this->enabled                = $this->get_option( 'enabled' );
			$this->testmode               = 'yes' === $this->get_option( 'testmode' );
			$this->private_key            = $this->testmode ? $this->get_option( 'api_test_key' ) : $this->get_option( 'api_test_key' );
			$this->publishable_key        = $this->testmode ? $this->get_option( 'test_publishable_key' ) : $this->get_option( 'publishable_key' );
			$this->customer_data_takeover = $this->get_option( 'customer_data_takeover' );
			$this->variable_timeout       = $this->get_option( 'variable_timeout' );

			$this->ressources_url = "http://";

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
				$this,
				'process_admin_options'
			) );

			add_action( 'plugins_loaded', 'paysafecash_textdomain' );

			add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'callback_handler' ) );

			add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

			add_action( 'woocommerce_thankyou_paysafecash', array( $this, 'check_response' ) );
			add_action( 'woocommerce_order_needs_payment', array( $this, 'check_response' ) );

			add_filter( 'woocommerce_available_payment_gateways', 'paysafecash_country_restriction' );

		}

		function paysafecash_textdomain() {
			load_plugin_textdomain( 'paysafecash', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
		}

		/**
		 * Plugin options, we deal with it in Step 3 too
		 */
		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'                => array(
					'title'   => 'Enable/Disable',
					'label'   => 'Enable Paysafecash',
					'type'    => 'checkbox',
					'default' => 'no'
				),
				'testmode'               => array(
					'title'       => 'Test mode',
					'label'       => 'Enable Test Mode',
					'type'        => 'checkbox',
					'description' => __( 'If the test mode is enabled you are making transactions against paysafecash test environment. Therefore the test environment API key is necessary to be set.', 'paysafecash' ),
					'default'     => 'yes',
					'desc_tip'    => false,
				),
				'api_key'                => array(
					'title'       => 'API Key',
					'description' => __( 'This key is provided by the paysafecash support team. There is one key for the test- and one for production environment.', 'paysafecash' ),
					'type'        => 'password'
				),
				'webhook_key'            => array(
					'title'       => 'Webhook RSA Key',
					'description' => __( 'This key is provided by the paysafecash support team. There is one key for the test- and one for production environment.', 'paysafecash' ),
					'type'        => 'password'
				),
				'submerchant_id'         => array(
					'title'       => 'Submerchant ID',
					'description' => __( 'This field specifies the used Reporting Criteria. You can use this parameter to distinguish your transactions per brand/URL. Use this field only if agreed beforehand with the paysafecash support team. The value has to be configured in both systems.', 'paysafecash' ),
					'type'        => 'text'
				),
				'customer_data_takeover' => array(
					'title'       => 'Customer Data Takeover',
					'description' => __( 'Provides the possibility to send customer data during the payment creation, so the Paysafecash registration form is prefilled. This has the sole purpose to make the registration of the customer easier. ', 'paysafecash' ),
					'type'        => 'checkbox'
				),
				'variable_timeout'       => array(
					'title'       => 'Variable Transaction Timeout',
					'description' => __( 'The time frame the customer is given to go to a payment point and pay for the transaction. Minimum: 1 day – Maximum: 14 days', 'paysafecash' ),
					'type'        => 'text'
				),
				'country'                => array(
					'title'       => 'Countries',
					'description' => __( 'Please select all countries where paysafecash is live and your webshop is live. For details about the available countries for paysafecash, please align with the paysafecash support team.', 'paysafecash' ),
					'type'        => 'multiselect',
					'options'     => json_decode( file_get_contents( __DIR__ . "/countrys.json" ) )
				),
				'debugmode'              => array(
					'title'       => 'Debug mode',
					'label'       => 'Enable remote debugging',
					'type'        => 'checkbox',
					'description' => __( 'If the debugging mode is enabled', 'paysafecash' ),
					'default'     => 'false',
					'desc_tip'    => false,
				),
			);
		}

		public function admin_options() {

			echo '<h3>' . __( 'Paysafecash', 'paysafecash' ) . '</h3>';
			echo '<p>' . __( 'Paysafecash is a cash payment option. Generate a QR/barcode and pay at a nearby shop. More information and our payment points can be found at <a href=\"https://www.paysafecash.com\" target=\"_blank\">www.paysafecash.com</a>', 'paysafecash' ) . '</p>';
			echo '<span>The Installation is ok!</span><br>';
			echo '<span>Current Version: ' . $this->version . ' (no update needed)</span>';
			echo '<table class="form-table">';
			$this->generate_settings_html();
			echo '</table>';





			echo '<style> #woocommerce_paysafecash_country{ min-height: 150px}</style>';

		}

		/**
		 * You will need it if you want your custom credit card form, Step 4 is about it
		 */
		public function payment_fields() {
			global $woocommerce;
			if ( $this->description ) {
				if ( $this->testmode ) {
					$this->description .= ' TEST MODE ENABLED';
					$this->description = trim( $this->description );
				}
				echo wpautop( wp_kses_post( $this->description ) );
			}
		}

		public function process_payment( $order_id ) {
			global $woocommerce;

			$this->init_settings();
			$this->api_key                = $this->settings['api_key'];
			$this->submerchant_id         = $this->settings['submerchant_id'];
			$this->testmode               = $this->settings['testmode'];
			$this->customer_data_takeover = $this->settings['customer_data_takeover'];
			$this->time_limit             = $this->settings['variable_timeout'];
			if ( empty( $this->time_limit ) ) {
				$this->time_limit = 4320;
			}

			$order = wc_get_order( $order_id );

			if ( $this->testmode == "yes" ) {
				$env = "TEST";
			} else {
				$env = "PRODUCTION";
			}

			$pscpayment       = new PaysafecardCashController( $this->api_key, $env );
			$success_url      = $order->get_checkout_order_received_url() . "&paysafecash=true&success=true&order_id=" . $order->get_order_number() . "&payment_id={payment_id}";
			$failure_url      = $order->get_checkout_payment_url() . "&paysafecash=false&failed=true&payment_id={payment_id}";
			$notification_url = $this->get_return_url( $order ) . "&wc-api=wc_paysafecash_gateway";


			$customerhash = "";

			if ( empty( $order->get_customer_id() ) ) {
				$customerhash = md5( $order->get_billing_email() );
			} else {
				$customerhash = md5( $order->get_customer_id() );
			}

			if ( $this->customer_data_takeover == "yes" ) {
				$customer_data = [
					"first_name"   => $order->get_billing_first_name(),
					"last_name"    => $order->get_billing_last_name(),
					"address1"     => $order->get_billing_address_1(),
					"postcode"     => $order->get_billing_postcode(),
					"city"         => $order->get_billing_city(),
					"phone_number" => $order->get_billing_phone(),
					"email"        => $order->get_billing_email()
				];
			} else {
				$customer_data = array();
			}

			$response = $pscpayment->initiatePayment( $order->get_total(), $order->get_currency(), $customerhash, $order->get_customer_ip_address(), $success_url, $failure_url, $notification_url, $customer_data, $this->time_limit, $correlation_id = "", $country_restriction = "", $kyc_restriction = "", $min_age = "", $shop_id = "Woocommerce: " . $woocommerce->version . " | " . $this->version, $this->submerchant_id );
			if ( isset( $response["object"] ) ) {
				$order->add_order_note( sprintf( __( '%s Transaction ID: %s', 'paysafecash' ), $this->title, $response["id"] ) );

				return array(
					'result'   => 'success',
					'redirect' => $response["redirect"]['auth_url']
				);

			}
		}

		public function checkVersion() {
			$versions = json_decode( file_get_contents( $this->ressources_url . "/versions.json" ) );
			if ( empty( $this->settings['country_revision'] ) ) {
				$this->settings['country_revision'] = 0;
			}
			if ( empty( $this->settings['language_revision'] ) ) {
				$this->settings['country_revision'] = 0;
			}

			if ( $versions["country_revision"] > $this->settings['country_revision'] ) {

			}
		}

		public function process_refund( $order_id, $amount = null, $reason = '' ) {

			global $woocommerce;

			$this->init_settings();
			$this->api_key        = $this->settings['api_key'];
			$this->submerchant_id = $this->settings['submerchant_id'];
			$this->testmode       = $this->settings['testmode'];

			$order = wc_get_order( $order_id );

			if ( $this->testmode ) {
				$env = "TEST";
			} else {
				$env = "PRODUCTION";
			}

			$pscpayment = new PaysafecardCashController( $this->api_key, $env );

			if ( empty( $order->get_customer_id() ) ) {
				$customerhash = md5( $order->get_billing_email() );
			} else {
				$customerhash = md5( $order->get_customer_id() );
			}

			$currency   = $order->get_currency();
			$payment_id = $order->get_transaction_id();

			$response = $pscpayment->captureRefund( $payment_id, $amount, $currency, $customerhash, $order->get_billing_email(), "", $this->submerchant_id, "Woocommerce: " . $woocommerce->version . " | " . $this->version );

			if ( $response == false || isset( $response['number'] ) ) {
				$error = new WP_Error();
				$error->add( $response['number'], $response['message'] );

				return $error;

			} else if ( isset( $response["object"] ) ) {
				if ( $response["status"] == "SUCCESS" ) {
					return true;
				} else {
					$error = new WP_Error();
					$error->add( $response['number'], $response['message'] );

					return $error;
				}
			}

			return false;
		}

		public function check_response() {
			global $woocommerce;
			global $wp;

			if ( isset( $_GET['paysafecash'] ) ) {

				$payment_id = $_GET['payment_id'];
				if ( isset( $wp->query_vars['order-pay'] ) ) {
					$order_id = $wp->query_vars['order-pay'];
					if(isset($_GET['failed'])){
						echo "<h4>". __("Payment was canceled by Customer")."</h4>";
					}
				} else {
					$order_id = $wp->query_vars['order-received'];
				}
				$order = wc_get_order( $order_id );

				if ( $order_id == 0 || $order_id == '' ) {
					return;
				}

				if ( isset( $_GET["failed"] ) ) {
					$order = new WC_Order( $order_id );
					$order->update_status( 'cancelled', sprintf( __( '%s payment cancelled! Transaction ID: %d', 'paysafecash' ), $this->title, $payment_id ) );
					return array(
						'result'   => 'failed',
						'redirect' => $order->get_cancel_order_url_raw()
					);
				}

				if ( $this->testmode ) {
					$env = "TEST";
				} else {
					$env = "PRODUCTION";
				}

				$this->init_settings();
				$this->api_key = $this->settings['api_key'];
				$pscpayment    = new PaysafecardCashController( $this->api_key, $env );
				$response      = $pscpayment->retrievePayment( $payment_id );

				if ( $response == false ) {
					wc_add_notice( 'Error Request' . var_dump( $response ), 'error' );

					return array(
						'result'   => 'failed',
						'redirect' => ''
					);

				} else if ( isset( $response["object"] ) ) {
					if ( $response["status"] == "SUCCESS" ) {
						$order->payment_complete( $payment_id );

						$woocommerce->cart->empty_cart();

						return array(
							'result'   => 'failed',
							'redirect' => ''
						);
					} else if ( $response["status"] == "INITIATED" ) {
						wc_add_notice( __( 'Thank you, please go to the Point of Sales and pay the transaction', 'paysafecash' ), 'info' );
					} else if ( $response["status"] == "REDIRECTED" ) {
						wc_add_notice( __( 'Thank you, please go to the Point of Sales and pay the transaction', 'paysafecash' ), 'info' );
					} else if ( $response["status"] == "EXPIRED" ) {
						wc_add_notice( __( 'Unfortunately, your payment failed. Please try again', 'paysafecash' ), 'error' );
					}
				}


			}
		}

		public function payment_scripts() {
		}

		public function validate_fields() {
		}

		public function callback_handler() {
			global $woocommerce;
			global $wp;

			if ( ! function_exists( 'apache_request_headers' ) ) {
				function apache_request_headers() {
					$headers = array();
					foreach ( $_SERVER as $key => $value ) {
						if ( substr( $key, 0, 5 ) == 'HTTP_' ) {
							$headers[ str_replace( ' ', '-', ucwords( str_replace( '_', ' ', strtolower( substr( $key, 5 ) ) ) ) ) ] = $value;
						}
					}

					return $headers;
				}
			}

			$signature   = str_replace( '"', '', str_replace( 'signature="', '', explode( ",", apache_request_headers()["Authorization"] )[2] ) );
			$payment_str = file_get_contents( "php://input" );
			$order_id    = $wp->query_vars['order-received'];
			$order       = new WC_Order( $order_id );

			if ( empty( apache_request_headers()["Authorization"] ) ) {
				$order->add_order_note( sprintf( __( '%s plugin error. Auth header is missing!', 'paysafecash' ), $this->title ) );
			}

			$this->init_settings();
			$rsa = new RSA();
			$rsa->loadKey("-----BEGIN RSA PUBLIC KEY-----\n". str_replace(" ", "", $this->settings['webhook_key']). "\n-----END RSA PUBLIC KEY-----");
			$pubkey         = openssl_pkey_get_public( $rsa->getPublicKey() );
			$signatur_check = openssl_verify( $payment_str, base64_decode( $signature ), $pubkey, OPENSSL_ALGO_SHA256 );

			openssl_free_key( $pubkey );

			$payment_str = json_decode( $payment_str );
			$payment_id  = $payment_str->data->mtid;


			if ( $signatur_check == 1 ) {
				if ( $payment_str->eventType == "PAYMENT_CAPTURED" ) {
					$order->add_order_note( sprintf( __( '%s payment completed! Transaction ID: %s', 'paysafecash' ), $this->title, $payment_id ) );
					$order->payment_complete( $payment_id );
					$order->set_payment_method( "paysafecash" );
					$order->add_payment_token( new WC_Payment_Token_CC( $payment_id ) );
					$order->set_status( 'pending', 'Payment Approved.' );
				} elseif ( $payment_str->eventType == "PAYMENT_CAPTURED" ) {
					$order->add_order_note( sprintf( __( '%s Order was canceled by Customer ID: %s', 'paysafecash' ), $this->title, $payment_id ) );
				}
			} elseif ( $signatur_check == 0 ) {
				$order->add_order_note( sprintf( __( '%s webhook failed! Transaction ID: %s Please check your Merchant Service Center to see if the transaction was successful, and change the order status accordingly. If the webhook fails repeatedly, please contact paysafecard tech support: techsupport@paysafecard.com', 'paysafecash' ), $this->title, $payment_id ) );
			} else {
				$order->add_order_note( sprintf( __( '%s webhook failed! Transaction ID: %s Please check your Merchant Service Center to see if the transaction was successful, and change the order status accordingly. If the webhook fails repeatedly, please contact paysafecard tech support: techsupport@paysafecard.com', 'paysafecash' ), $this->title, $payment_id ) );
			}
		}


	}
}
