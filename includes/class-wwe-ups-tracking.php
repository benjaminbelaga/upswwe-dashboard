<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * WWE_UPS_Tracking Class.
 *
 * Handles display of WWE tracking information.
 */
if (!class_exists('WWE_UPS_Tracking')) {
    class WWE_UPS_Tracking {

        public function __construct() {
            // Add tracking info to customer account order view
            add_action('woocommerce_view_order', array($this, 'display_wwe_tracking_info_customer'), 20); // After order details table
            // Add tracking info to emails (optional, can be enabled/disabled)
            // add_action( 'woocommerce_email_before_order_table', array( $this, 'add_wwe_tracking_info_to_email' ), 15, 4 );
        }

        /**
         * Display tracking info on the customer's "View Order" page.
         *
         * @param int $order_id
         */
        public function display_wwe_tracking_info_customer($order_id) {
            $order = wc_get_order($order_id);
            if (!$order) return;

            $tracking_number = $order->get_meta('_wwe_ups_tracking_number', true);

            if ($tracking_number) {
                // Check if this order actually used WWE
                 $used_wwe = false;
                 $shipping_methods = $order->get_shipping_methods();
                 foreach ($shipping_methods as $shipping_method) {
                     if (strpos($shipping_method->get_method_id(), WWE_UPS_ID) === 0) {
                         $used_wwe = true;
                         break;
                     }
                 }
                 if (!$used_wwe) return; // Only show if WWE was used

                ?>
                <h2><?php _e('UPS i-parcel Tracking', 'wwe-ups-woocommerce-shipping'); ?></h2>
                <p>
                    <?php _e('Your tracking number:', 'wwe-ups-woocommerce-shipping'); ?>
                    <a href="<?php echo esc_url('https://tracking.i-parcel.com/Home/Index?trackingnumber=' . urlencode($tracking_number)); ?>" target="_blank">
                        <?php echo esc_html($tracking_number); ?>
                    </a>
                </p>
                <?php
            }
        }

        /**
         * Add tracking info to WooCommerce emails.
         * (Optional - Uncomment add_action in constructor to enable)
         *
         * @param WC_Order $order
         * @param bool $sent_to_admin
         * @param bool $plain_text
         * @param WC_Email $email
         */
        /*
        public function add_wwe_tracking_info_to_email( $order, $sent_to_admin, $plain_text = false, $email = null ) {
            if ( ! $order instanceof WC_Order ) return;

            // Only add to specific emails (e.g., completed order) and if not plain text
            if ( $plain_text || ! in_array( $email->id, array( 'customer_completed_order' ) ) ) {
                return;
            }

            $tracking_number = $order->get_meta( '_wwe_ups_tracking_number', true );

            if ( $tracking_number ) {
                 // Check if this order actually used WWE
                 $used_wwe = false;
                 $shipping_methods = $order->get_shipping_methods();
                 foreach ($shipping_methods as $shipping_method) {
                     if (strpos($shipping_method->get_method_id(), WWE_UPS_ID) === 0) {
                         $used_wwe = true;
                         break;
                     }
                 }
                 if (!$used_wwe) return;

                 echo '<h2>' . esc_html__( 'UPS Worldwide Economy Tracking', 'wwe-ups-woocommerce-shipping' ) . '</h2>';
                 echo '<p>' . esc_html__( 'Your tracking number:', 'wwe-ups-woocommerce-shipping' ) . ' <a href="' . esc_url( 'https://www.ups.com/track?loc=en_US&tracknum=' . urlencode( $tracking_number ) . '&requester=WT/trackdetails' ) . '" target="_blank">' . esc_html( $tracking_number ) . '</a></p>';
            }
        }
        */

    } // End class WWE_UPS_Tracking
}