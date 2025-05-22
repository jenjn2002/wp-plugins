<?php
if (!defined('ABSPATH')) {
    exit;
}

class WC_Loyalty_Points {
    /**
     * Constructor
     */
    public function __construct() {
        add_action('woocommerce_order_status_completed', array($this, 'award_points'));
        add_action('woocommerce_checkout_order_processed', array($this, 'use_points_for_discount'));
        add_action('wp_ajax_apply_loyalty_points', array($this, 'apply_points_ajax'));
        add_action('wp_ajax_nopriv_apply_loyalty_points', array($this, 'apply_points_ajax'));
        add_action('woocommerce_cart_calculate_fees', array($this, 'add_points_discount'));
        add_shortcode('loyalty_points_balance', array($this, 'display_points_balance'));
    }

    /**
     * Award points for completed orders
     */
    public function award_points($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $user_id = $order->get_user_id();
        if (!$user_id) return;

        $points_rate = get_option('wc_loyalty_points_rate', 5000);
        $order_total = $order->get_total();
        $points_earned = floor($order_total / $points_rate);

        if ($points_earned > 0) {
            $this->add_points($user_id, $points_earned);
            $order->add_order_note(
                sprintf(__('Customer earned %d loyalty points', 'wc-loyalty'), $points_earned)
            );
        }
    }

    /**
     * Add points to user account
     */
    public function add_points($user_id, $points) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_loyalty_points';

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d",
            $user_id
        ));

        if ($existing) {
            $wpdb->update(
                $table,
                array(
                    'points' => $existing->points + $points,
                    'earned_points' => $existing->earned_points + $points
                ),
                array('user_id' => $user_id)
            );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'user_id' => $user_id,
                    'points' => $points,
                    'earned_points' => $points
                )
            );
        }

        do_action('wc_loyalty_points_added', $user_id, $points);
    }

    /**
     * Get user's current points balance
     */
    public function get_user_points($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_loyalty_points';
        
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT points FROM $table WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * Use points from user's account
     */
    public function use_points($user_id, $points) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_loyalty_points';
        
        $current_points = $this->get_user_points($user_id);
        
        if ($current_points >= $points) {
            $wpdb->update(
                $table,
                array(
                    'points' => $current_points - $points,
                    'used_points' => $wpdb->get_var($wpdb->prepare(
                        "SELECT used_points FROM $table WHERE user_id = %d",
                        $user_id
                    )) + $points
                ),
                array('user_id' => $user_id)
            );
            
            do_action('wc_loyalty_points_used', $user_id, $points);
            return true;
        }
        
        return false;
    }

    /**
     * Apply points discount to cart
     */
    public function add_points_discount() {
        if (!is_user_logged_in()) return;

        $points_to_use = WC()->session->get('loyalty_points_to_use', 0);
        if ($points_to_use > 0) {
            $redemption_rate = get_option('wc_loyalty_redemption_rate', 1000);
            $discount_amount = $points_to_use * $redemption_rate;

            WC()->cart->add_fee(
                __('Loyalty Points Discount', 'wc-loyalty'),
                -$discount_amount
            );
        }
    }

    /**
     * AJAX handler for applying points
     */
    public function apply_points_ajax() {
        check_ajax_referer('loyalty_points_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(__('Please log in to use points', 'wc-loyalty'));
        }

        $points = intval($_POST['points']);
        $user_id = get_current_user_id();
        $available_points = $this->get_user_points($user_id);

        if ($points > $available_points) {
            wp_send_json_error(__('Insufficient points', 'wc-loyalty'));
        }

        WC()->session->set('loyalty_points_to_use', $points);
        wp_send_json_success(__('Points applied successfully', 'wc-loyalty'));
    }

    /**
     * Display points balance shortcode
     */
    public function display_points_balance($atts) {
        if (!is_user_logged_in()) {
            return '<p>' . __('Please log in to view your points balance.', 'wc-loyalty') . '</p>';
        }

        $user_id = get_current_user_id();
        $points = $this->get_user_points($user_id);
        $redemption_rate = get_option('wc_loyalty_redemption_rate', 1000);
        $value = $points * $redemption_rate;

        ob_start();
        ?>
        <div class="loyalty-points-balance">
            <h3><?php _e('Your Loyalty Points', 'wc-loyalty'); ?></h3>
            <p><strong><?php _e('Points:', 'wc-loyalty'); ?></strong> <?php echo number_format($points); ?></p>
            <p><strong><?php _e('Value:', 'wc-loyalty'); ?></strong> <?php echo wc_price($value); ?></p>
            <div class="points-usage">
                <input type="number" id="points-to-use" max="<?php echo esc_attr($points); ?>" min="0" 
                       placeholder="<?php esc_attr_e('Points to use', 'wc-loyalty'); ?>">
                <button id="apply-points" class="button"><?php _e('Apply Points', 'wc-loyalty'); ?></button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}