<?php
/*
Plugin Name: WooCommerce Getpaid Payment Gateway
Plugin URI: http://www.gp.com
Description: getpaid Payment gateway for woocommerce
Version: 1.2
Author: Software developers ltd kenya 
Author URI: http://www.getpaid.co.ke
*/
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

  // Hooks for adding/ removing the database table, and the wpcron to check them
  register_activation_hook( __FILE__, 'create_gp_background_checks' );
  register_deactivation_hook( __FILE__, 'remove_gp_background_checks' );
  register_uninstall_hook( __FILE__, 'gp_uninstall' );



  // cron interval for ever 5 minuites
  add_filter('cron_schedules','gp_cron_definer');

  function gp_cron_definer($schedules){
    $schedules['fivemins'] = array(
        'interval'=> 60,
        'display'=>  __('Once Every 5 minuites'),
    );
    return $schedules;
  }

  /**
   * Activation, create processing order table, and table version option
   * @return void
   */
  function create_gp_background_checks()
  {
    // Wp_cron checks Pending payments in the background
    wp_schedule_event(time(), 'fivemins', 'getpaid_background_payment_checks');

    //Get the table name with the WP database prefix
    global $wpdb;
    $db_version = "1.0";
    $table_name = $wpdb->prefix . "getpaid_queue";

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
      order_id mediumint(9) NOT NULL,
      tracking_id varchar(36) NOT NULL,
      time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
      PRIMARY KEY (order_id, tracking_id)
    );";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    add_option('getpaid_db_version', $db_version);
  } 


if ( ! wp_next_scheduled( 'getpaid_background_payment_checks' ) ) {
    wp_schedule_event( time(), 'fivemins', 'getpaid_background_payment_checks' );
}


  function remove_gp_background_checks()
  {
    $next_sheduled = wp_next_scheduled( 'getpaid_background_payment_checks' );
    wp_unschedule_event($next_sheduled, 'getpaid_background_payment_checks');
  }

  /**
   * Clean up table and options on uninstall
   * @return [type] [description]
   */
  function gp_uninstall()
  {
    // Clean up i.e. delete the table, wp_cron already removed on deacivate
    delete_option('getpaid_db_version');

    global $wpdb;

    $table_name = $wpdb->prefix . "getpaid_queue";

    $wpdb->query("DROP TABLE IF EXISTS $table_name");
  } 

add_action('plugins_loaded', 'woocommerce_gp_getpaid_init', 0);

function woocommerce_gp_getpaid_init(){

  class WC_Getpaid_gateway extends WC_Payment_Gateway{

    public function __construct(){

      $this ->id = 'getpaid';
      $this ->medthod_title = __('getpaid', 'woocommerce');;
      $this ->has_fields = false;
      $this ->icon = 'http://geeks.co.ke/test/wp-content/uploads/2016/01/woo-logos.jpg';
      $this ->method_description = 'Pay by M-Pesa, debit or credit card';

      if ( 'yes' == $this->debug ) {
        if(class_exists('WC_Logger')){
          $this->log = new WC_Logger();
        } else {
          $this->log = $woocommerce->logger();
        }
        
      }

      $this->consumer_key     = $this->get_option('consumerkey');
      $this->consumer_secret  = $this->get_option('secretkey');
      $this->token            = $this->get_option('token');

      $this->consumer                         = new OAuthConsumer($this->consumer_key, $this->consumer_secret);
      $this->signature_method                 = new OAuthSignatureMethod_HMAC_SHA1();
      
      $this->params           = NULL;
      
      $api                    = 'http://159.203.84.166/';


      // Gateway payment URLs
      $this->gatewayURL             =$api.'api/checkout/';
      $this->QueryPaymentStatus       = $api.'api/QueryPaymentStatus/';
     $this->QueryPaymentStatusByMerchantRef  = $api.'API/QueryPaymentStatusByMerchantRef';
     $this->querypaymentdetails       = $api.'api/QueryPaymentDetails';

      // IPN Request URL
      $this->notify_url   = str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'WC_Getpaid_gateway', home_url( '/' ) ) );
      $this->init_form_fields();
      $this->init_settings();
      
      // Settings
      $this->title      = $this->get_option('title');
      $this->description    = $this->get_option('description');
      $this->ipn       = ($this->get_option('ipn') === 'yes') ? true : false;
      
      // Actions
      add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
      add_action('woocommerce_receipt_getpaid', array(&$this, 'payment_page'));
      add_action('before_woocommerce_pay', array(&$this, 'before_pay'));
      add_action('woocommerce_thankyou_getpaid', array(&$this, 'thankyou_page'));
      add_action('getpaid_background_payment_checks', array($this, 'background_check_payment_status'));
      add_action( 'woocommerce_api_WC_Getpaid_gateway', array( $this, 'ipn_response' ) );
      add_action('pesapal_process_valid_ipn_request', array($this, 'process_valid_ipn_request'));

      //add_action('woocommerce_receipt_getpaid', array($this, 'receipt_page'));
   }
    function init_form_fields(){

       $this -> form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'gp'),
                    'type' => 'checkbox',
                    'label' => __('Accept M-Pesa and Card payments via GetPaid.', 'gp'),
                    'default' => 'no'),
                'title' => array(
                    'title' => __('Title:', 'gp'),
                    'type'=> 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'gp'),
                    'default' => __('getpaid', 'gp')),
                'description' => array(
                    'title' => __('Description:', 'gp'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'gp'),
                    'default' => __('Pay securely by M-Pesa, Credit or Debit card or internet banking.', 'gp')),
                'consumerkey' => array(
                  'title' => __( 'getpaid Consumer Key', 'woothemes' ),
                  'type' => 'text',
                  'description' => __( 'Your GetPaid consumer key which you generated.', 'woothemes' ),
                  'default' => ''
                ),
                'secretkey' => array(
                  'title' => __( 'getpaid Secret Key', 'woothemes' ),
                  'type' => 'text',
                  'description' => __( 'Your Getpaid secret key which you generated.', 'woothemes' ),
                  'default' => ''
                ),
                'token' => array(
                  'title' => __( 'getpaid access token', 'woothemes' ),
                  'type' => 'text',
                  'description' => __( 'Your Getpaid access token which you generated.', 'woothemes' ),
                  'default' => ''
                
                ),

                'ipn' => array(
                    'title' => __( 'Use IPN', 'woothemes' ),
                    'type' => 'checkbox',
                    'label' => __( 'Use IPN', 'woothemes' ),
                    'description' => __( 'getpaid has the ability to send your site an Instant Payment Notification whenever there is an order update. It is highly reccomended that you enable this, as there are some issues with the "background" status checking. It is disabled by default because the IPN URL needs to be entered in the getpaid control panel.', 'woothemes' ),
                    'default' => 'no'
                 ),
                'ipnurl' => array(
                  'title' => __( 'IPN URL', 'woothemes' ),
                  'type' => 'text',
                  'description' => __( 'This is the IPN URL that you must enter in the getpaid control panel. (This is not editable)', 'woothemes' ),
                  'default' => $this->notify_url
                ),
                'debug' => array(
                  'title' => __( 'Debug Log', 'woocommerce' ),
                  'type' => 'checkbox',
                  'label' => __( 'Enable logging', 'woocommerce' ),
                  'default' => 'no',
                  'description' => sprintf( __( 'Log getpaid events, such as IPN requests, inside <code>woocommerce/logs/getpaid-%s.txt</code>', 'woocommerce' ), sanitize_file_name( wp_hash( 'getpaid' ) ) ),
                  ),

            );
    }

        public function admin_options() { ?>
        
          <h3><?php _e('Pesapal Payment', 'woothemes'); ?></h3>
          <p>
            <?php _e('Allows use of the GetPaid Payment Gateway, all you need is an account at getpaid.co.ke and your consumer and secret key.<br />', 'woothemes'); ?>

          </p>
          <table class="form-table">
          <?php
            // Generate the HTML For the settings form.
            $this->generate_settings_html();
          ?>
          </table>
          <script type="text/javascript">
          jQuery(function(){
            var testMode = jQuery("#woocommerce_getpaid_testmode");
            var ipn = jQuery("#woocommerce_getpaid_ipn");
            var ipnurl = jQuery("#woocommerce_getpaid_ipnurl");
            var consumer = jQuery("#woocommerce_getpaid_testconsumerkey");
            var secrect = jQuery("#woocommerce_getpaid_testsecretkey");
            
            if (testMode.is(":not(:checked)")){
              consumer.parents("tr").css("display","none");
              secrect.parents("tr").css("display","none");
            }
            
            if (ipn.is(":not(:checked)")){
              ipnurl.parents("tr").css("display","none");
            } 

            // Add onclick handler to checkbox w/id checkme
            testMode.click(function(){            
              // If checked
              if (testMode.is(":checked")) {
                //show the hidden div
                consumer.parents("tr").show("fast");
                secrect.parents("tr").show("fast");
              } else {
                //otherwise, hide it
                consumer.parents("tr").hide("fast");
                secrect.parents("tr").hide("fast");
              }
            });

            ipn.click(function(){            
              // If checked
              if (ipn.is(":checked")) {
                //show the hidden div
                ipnurl.parents("tr").show("fast");
              } else {
                //otherwise, hide it
                ipnurl.parents("tr").hide("fast");
              }
            });

          });
          </script>
          <?php
        } // End admin_options()


    function thankyou_page($order_id) {
      global $woocommerce;
      
      $order = new WC_Order( $order_id );
      
      // Remove cart
      $woocommerce->cart->empty_cart();
      
      // Empty awaiting payment session
      unset($_SESSION['order_awaiting_payment']);
            
    }
  

    function process_payment( $order_id ) {
      global $woocommerce;
    
      $order = &new WC_Order( $order_id );
    
      // Redirect to payment page
      return array(
        'result'    => 'success',
        'redirect'  => add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, get_permalink(woocommerce_get_page_id('pay'))))
      );
      }

      function payment_page($order_id){
        $url = $this->create_url($order_id);
        ?>
        <iframe src="<?php echo $url; ?>" width="100%" height="700px"  scrolling="yes" frameBorder="0">
          <p>Browser unable to load iFrame</p>
        </iframe>
        <?php
      }


    function background_check_payment_status()
      {
        global $wpdb;
        $table_name = $wpdb->prefix . 'getpaid_queue';

        $checks = $wpdb->get_results("SELECT order_id, tracking_id FROM $table_name");

        if ($wpdb->num_rows > 0) {

          foreach($checks as $check){
          
            $order = &new WC_Order( $check->order_id );
  
            
            $status = $this->checkTransactionStatus($check->tracking_id, $check->order_id);

          
            switch ($status['status']) {
              case 'Success':
                // hooray payment complete
                $order->add_order_note( __('Payment confirmed.', 'woothemes') );
                $order->payment_complete(); 
                $wpdb->query("DELETE FROM $table_name WHERE order_id = $check->order_id");
                break;
              case 'Failed':
                // aw, payment Failed
                $order->update_status('Failed',  __('Payment denied by gateway.', 'woocommerce'));
                $wpdb->query("DELETE FROM $table_name WHERE order_id = $check->order_id");
                break;
            }
            

          }
        }
      }

    function before_pay()
    {

      // if we have come from the gateway do some stuff
      if(isset($_GET['getpaid_tracking_id'])){
        
        $order_id = $_GET['order'];
        $order    = &new WC_Order( $order_id );
        $getpaidMerchantReference = $_GET['merchantref'];
        $getpaidTrackingId        = $_GET['getpaid_tracking_id'];
        
        //$status         = $this->checkTransactionStatus($pesapalMerchantReference);
        //$status           = $this->checkTransactionStatus($pesapalMerchantReference,$pesapalTrackingId);
        $transactionDetails = $this->getTransactionDetails($getpaidMerchantReference,$getpaidTrackingId);
   
        $order->add_order_note( __('Payment accepted, awaiting confirmation.', 'woothemes') );
        add_post_meta( $order_id, '_order_pesapal_transaction_tracking_id', $transactionDetails['getpaid_transaction_tracking_id']);
        add_post_meta( $order_id, '_order_pesapal_payment_method', $transactionDetails['payment_method']);
        
        
        $dbUpdateSuccessful = add_post_meta( $order_id, '_order_payment_method', $transactionDetails['payment_method']);
        global $wpdb;
        $table_name = $wpdb->prefix . 'getpaid2_queue';
        $wpdb->insert($table_name, array('order_id' => $order_id, 'tracking_id' => $getpaidTrackingId, 'time' => current_time('mysql')), array('%d', '%s', '%s'));
      

        wp_redirect(add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, get_permalink(woocommerce_get_page_id('thanks')))));

        
      }
    }


    function create_url($order_id){
      $order            = &new WC_Order( $order_id );
      $order_xml        = $this->getpaid_xml($order_id);
      $callback_url     = add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, get_permalink(woocommerce_get_page_id('pay'))));

      $url = OAuthRequest::from_consumer_and_token($this->consumer, $this->token, "GET", $this->gatewayURL, $this->params);
      $url->set_parameter("oauth_callback", $callback_url);
      $url->set_parameter("getpaid_request_data", $order_xml);
      $url->set_parameter("oauth_token", $this->token);

      $url->sign_request($this->signature_method, $this->consumer, $this->token);
      

      return $url;
    }

    function getpaid_xml($order_id) {
          
          $order                      = &new WC_Order( $order_id );
          $getpaid_args['total']      = $order->get_total();
          $getpaid_args['reference']  = $order_id;
          $getpaid_args['first_name'] = $order->billing_first_name;
          $getpaid_args['last_name']  = $order->billing_last_name;
          $getpaid_args['email']      = $order->billing_email;
          $getpaid_args['phone']      = $order->billing_phone;
          
          $i = 0;
          foreach($order->get_items() as $item){
            $product = $order->get_product_from_item($item);
            
            $cart[$i] = array(
              'id' => ($product->get_sku() ? $product->get_sku() : $product->id),
              'particulars' => $cart_row['name'],
              'quantity' => $item['qty'],
              'unitcost' => $product->regular_price,
              'subtotal' => $order->get_item_total($item, true)
            );
            $i++;
          }

          
          return json_encode($getpaid_args);
        }
        
   /**function status_request($transaction_id, $merchant_ref){

            $request_status   = OAuthRequest::from_consumer_and_token($this->consumer, $this->token, "GET", $this->gatewayURL, $this->params);
            $request_status->set_parameter("getpaid_merchant_reference", $merchant_ref);
            $request_status->set_parameter("getpaid_transaction_tracking_id", $transaction_id);
            $request_status->sign_request($this->signature_method, $this->consumer, $this->token);
            
            return $this->checkTransactionStatus($transaction_id, $merchant_ref);
            //return $this->checkTransactionStatus($merchant_ref,$transaction_id);
            //return $this->getTransactionDetails($merchant_ref,$transaction_id);
            
          
          }
        **/
        /**
         * Check Transaction status
         *
         * @return Pending/Failed/Invalid
         * @author getpaid
         **/
    function checkTransactionStatus($getpaid_transaction_tracking_id,$order_id){

        $queryURL = $this->QueryPaymentStatus;

          
        //get transaction status
        $request_status = OAuthRequest::from_consumer_and_token(
                            $this->consumer, 
                            $this->token, 
                            "GET", 
                            $queryURL, 
                            $this->params
                          );
        
        $request_status->set_parameter("getpaid_transaction_tracking_id", $getpaid_transaction_tracking_id);
        $request_status->set_parameter("oauth_token", $this->token);
        $request_status->set_parameter("order_id",$order_id);
          
        $request_status->sign_request($this->signature_method, $this->consumer, $this->token);
      
        return $this->curlRequest($request_status);
}
  
        /**
         * Check Transaction status
         *
         * @return Pending/Failed/Invalid
         * @author getpaid
         **/
    function getTransactionDetails($getpaidMerchantReference,$getpaidTrackingId){

              $request_status = OAuthRequest::from_consumer_and_token(
                                  $this->consumer, 
                                  $this->token, 
                                  "GET", 
                                  $this->querypaymentdetails, 
                                  $this->params
                                );
              
              $request_status->set_parameter("merchantref", $getpaidMerchantReference);
              $request_status->set_parameter("transactionref",$getpaidTrackingId);
              $request_status->sign_request($this->signature_method, $this->consumer, $this->token);


              $responseData = $this->curlRequest($request_status);
                    
              //$getpaidResponse = explode(",", $responseData);
              //$getpaidResponseArray=array('getpaid_transaction_tracking_id'=>$responseData->getpaid_transaction_tracking_id,
                                          //'payment_method'=>$responseData->payment_method,
                                          //'status'=>$responseData->status,
                                          // UTARUDI HAPA
                                          //'getpaid_merchant_reference'=>$responseData->
                                        //);
                                       
              return $responseData;
              
   } 
  
        /**
         * Check Transaction status
         *
         * @return ARRAY
         * @author getpaid
         **/
    function curlRequest($request_status){

      $json = file_get_contents($request_status);
      $json=str_replace('},
      ]',"}
      ]",$json);
      $arrdata = json_decode($json, true);
      return $arrdata;
    }
    
 

        
        /**
         * IPN Response
         *
         * @return null
         * @author Jake Lee Kennedy
         **/
    function ipn_response(){
      
 
      
      $getpaidTrackingId    = '';
      $getpaidNotification    = '';
      $getpaidMerchantReference = '';

      if(isset($_GET['getpaidMerchantReference']))
              $getpaidMerchantReference = $_GET['getpaidMerchantReference'];
              
      if(isset($_GET['getpaid_tracking_id']))
              $getpaidTrackingId = $_GET['getpaid_tracking_id'];
              
      if(isset($_GET['getpaid_notification_type']))
              $getpaidNotification=$_GET['getpaid_notification_type'];

              
              
      /** check status of the transaction made
        *There are 3 available API
        *checkStatusUsingTrackingIdandMerchantRef() - returns Status only. 
        *checkStatusByMerchantRef() - returns status only.
        *getMoreDetails() - returns status, payment method, merchant reference and pesapal tracking id
      */
              
      //$status         = $this->checkTransactionStatus($pesapalMerchantReference);
      //$status           = $this->checkTransactionStatus($pesapalMerchantReference,$pesapalTrackingId);
      $transactionDetails = $this->getTransactionDetails($getpaidMerchantReference,$getpaidTrackingId);
      $order                = &new WC_Order($getpaidMerchantReference);
       
      // We are here so lets check status and do actions
      switch ( $transactionDetails['status'] ) {
          case 'Complete' :
          case 'Pending' :

            // Check order not already Complete
            if ( $order->status == 'Complete' ) {
               if ( 'yes' == $this->debug )
                $this->log->add( 'getpaid', 'Aborting, Order #' . $order->id . ' is already complete.' );
               exit;
            }

              if ( $transactionDetails['status'] == 'Complete' ) {
                $order->add_order_note( __( 'IPN payment Complete', 'woocommerce' ) );
                $order->payment_complete();
              } else {
                $order->update_status( 'on-hold', sprintf( __( 'Payment Pending: %s', 'woocommerce' ), 'Waiting getpaid confirmation' ) );
              }

            if ( 'yes' == $this->debug )
                $this->log->add( 'getpaid', 'Payment complete.' );

          break;
          case 'Invalid' :
          case 'Failed' :
              // Order Failed
              $order->update_status( 'Failed', sprintf( __( 'Payment %s via IPN.', 'woocommerce' ), strtolower( $transactionDetails['status'] ) ) );
          break;

          default :
            // No action
          break;
      }

      $order      = &new WC_Order($getpaidMerchantReference);
      $newstatus  = $order->status;


      if($transactionDetails['status']  == $newstatus) $dbupdated = "True"; else  $dbupdated = 'False';

      if($getpaidNotification =="CHANGE" && $dbupdated && $transactionDetails['status'] != "Pending"){    
              
            $resp = "getpaid_notification_type=$getpaidNotification".   
                      "&getpaid_transaction_tracking_id=$getpaidTrackingId".
                      "&getpaid_merchant_reference=$getpaidMerchantReference";
                                        
            ob_start();
            echo $resp;
            ob_flush();
            exit;
      }      
    }

          
      } // END WC_Pesapal_Gateway Class
    
  } // END init_woo_pesapal_gateway()
/**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_gp_getpaid_gateway($methods) {
        $methods[] = 'WC_Getpaid_gateway';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_gp_getpaid_gateway' );

}