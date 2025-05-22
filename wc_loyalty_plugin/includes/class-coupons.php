<?php
if (!defined('ABSPATH')) {
    exit;
}

class WC_Loyalty_Coupons {
    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_ajax_generate_b2b_coupons', array($this, 'generate_coupons'));
        add_action('woocommerce_coupon_options', array($this, 'add_coupon_options'));
        add_action('woocommerce_coupon_options_save', array($this, 'save_coupon_options'));
    }

    /**
     * Generate B2B coupons
     */
    public function generate_coupons() {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'wc-loyalty'));
        }

        $count = intval($_POST['coupon_count']);
        $prefix = sanitize_text_field($_POST['coupon_prefix']);
        $discount_type = sanitize_text_field($_POST['discount_type']);
        $discount_amount = floatval($_POST['discount_amount']);

        $generated_coupons = array();

        for ($i = 0; $i < $count; $i++) {
            $coupon_code = $prefix . '_' . strtoupper(wp_generate_password(8, false));

            $coupon = new WC_Coupon();
            $coupon->set_code($coupon_code);
            $coupon->set_discount_type($discount_type);
            $coupon->set_amount($discount_amount);
            $coupon->set_individual_use(true);
            $coupon->set_usage_limit(1);
            $coupon->set_description(__('Auto-generated B2B coupon', 'wc-loyalty'));
            $coupon->set_date_expires(strtotime('+30 days'));

            $coupon->save();

            $generated_coupons[] = array(
                'code' => $coupon_code,
                'discount' => $discount_amount,
                'type' => $discount_type,
                'expires' => date('Y-m-d', strtotime('+30 days'))
            );
        }

        wp_send_json_success($generated_coupons);
    }

    /**
     * Add custom coupon options
     */
    public function add_coupon_options() {
        woocommerce_wp_checkbox(array(
            'id' => 'is_loyalty_coupon',
            'label' => __('Loyalty Program Coupon', 'wc-loyalty'),
            'description' => __('Check if this coupon is part of the loyalty program', 'wc-loyalty')
        ));

        woocommerce_wp_text_input(array(
            'id' => 'points_required',
            'label' => __('Points Required', 'wc-loyalty'),
            'description' => __('Number of loyalty points required to use this coupon', 'wc-loyalty'),
            'type' => 'number',
            'custom_attributes' => array(
                'min' => '0',
                'step' => '1'
            )
        ));
    }

    /**
     * Save custom coupon options
     */
    public function save_coupon_options($post_id) {
        $is_loyalty_coupon = isset($_POST['is_loyalty_coupon']) ? 'yes' : 'no';
        update_post_meta($post_id, 'is_loyalty_coupon', $is_loyalty_coupon);

        if (isset($_POST['points_required'])) {
            update_post_meta($post_id, 'points_required', absint($_POST['points_required']));
        }
    }
}