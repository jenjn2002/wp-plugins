<?php
if (!defined('ABSPATH')) {
    exit;
}

class WC_Loyalty_Programs {
    /**
     * Constructor
     */
    public function __construct() {
        add_action('woocommerce_cart_calculate_fees', array($this, 'apply_discount_programs'));
        add_filter('woocommerce_get_price_html', array($this, 'add_program_info_to_price'), 10, 2);
    }

    /**
     * Apply active discount programs to cart
     */
    public function apply_discount_programs() {
        if (!is_admin() || defined('DOING_AJAX')) {
            global $wpdb;
            
            $programs_table = $wpdb->prefix . 'wc_loyalty_programs';
            $active_programs = $wpdb->get_results("
                SELECT * FROM {$programs_table} 
                WHERE status = 'active' 
                AND (start_date IS NULL OR start_date <= NOW())
                AND (end_date IS NULL OR end_date >= NOW())
            ");

            foreach ($active_programs as $program) {
                $this->apply_program_discount($program);
            }
        }
    }

    /**
     * Apply specific program discount
     */
    private function apply_program_discount($program) {
        $settings = json_decode($program->settings, true);
        $cart = WC()->cart;

        if (!$cart) return;

        switch ($program->type) {
            case 'buy_x_get_y':
                $this->apply_bxgy_discount($program, $settings, $cart);
                break;
            case 'percentage_discount':
                $this->apply_percentage_discount($program, $settings, $cart);
                break;
            case 'fixed_amount_discount':
                $this->apply_fixed_discount($program, $settings, $cart);
                break;
            case 'bulk_discount':
                $this->apply_bulk_discount($program, $settings, $cart);
                break;
        }
    }

    /**
     * Apply Buy X Get Y discount
     */
    private function apply_bxgy_discount($program, $settings, $cart) {
        $buy_qty = intval($settings['buy_quantity']);
        $get_qty = intval($settings['get_quantity']);
        $applicable_products = isset($settings['applicable_products']) ? $settings['applicable_products'] : array();

        $eligible_items = 0;
        $cheapest_price = PHP_INT_MAX;

        foreach ($cart->get_cart() as $cart_item) {
            if (empty($applicable_products) || in_array($cart_item['product_id'], $applicable_products)) {
                $eligible_items += $cart_item['quantity'];
                $price = $cart_item['data']->get_price();
                if ($price < $cheapest_price) {
                    $cheapest_price = $price;
                }
            }
        }

        $free_items = floor($eligible_items / ($buy_qty + $get_qty)) * $get_qty;

        if ($free_items > 0) {
            $discount_amount = $free_items * $cheapest_price;
            $cart->add_fee(
                sprintf(__('%s (Buy %d Get %d Free)', 'wc-loyalty'), 
                    $program->name, $buy_qty, $get_qty),
                -$discount_amount
            );

            $this->log_program_usage($program->id, $discount_amount);
        }
    }

    /**
     * Apply percentage discount
     */
    private function apply_percentage_discount($program, $settings, $cart) {
        $percentage = floatval($settings['percentage']);
        $applicable_products = isset($settings['applicable_products']) ? $settings['applicable_products'] : array();

        $discount_amount = 0;

        foreach ($cart->get_cart() as $cart_item) {
            if (empty($applicable_products) || in_array($cart_item['product_id'], $applicable_products)) {
                $line_total = $cart_item['line_total'];
                $discount_amount += $line_total * ($percentage / 100);
            }
        }

        if ($discount_amount > 0) {
            $cart->add_fee(
                sprintf(__('%s (%d%% Off)', 'wc-loyalty'), 
                    $program->name, $percentage),
                -$discount_amount
            );

            $this->log_program_usage($program->id, $discount_amount);
        }
    }

    /**
     * Apply fixed amount discount
     */
    private function apply_fixed_discount($program, $settings, $cart) {
        $discount_amount = floatval($settings['fixed_amount']);
        
        if ($discount_amount > 0 && $cart->get_subtotal() >= $discount_amount) {
            $cart->add_fee(
                sprintf(__('%s (%s Off)', 'wc-loyalty'), 
                    $program->name, wc_price($discount_amount)),
                -$discount_amount
            );

            $this->log_program_usage($program->id, $discount_amount);
        }
    }

    /**
     * Apply bulk discount
     */
    private function apply_bulk_discount($program, $settings, $cart) {
        $min_qty = intval($settings['bulk_min_qty']);
        $percentage = floatval($settings['bulk_percentage']);
        $applicable_products = isset($settings['applicable_products']) ? $settings['applicable_products'] : array();

        $total_qty = 0;
        $eligible_total = 0;

        foreach ($cart->get_cart() as $cart_item) {
            if (empty($applicable_products) || in_array($cart_item['product_id'], $applicable_products)) {
                $total_qty += $cart_item['quantity'];
                $eligible_total += $cart_item['line_total'];
            }
        }

        if ($total_qty >= $min_qty) {
            $discount_amount = $eligible_total * ($percentage / 100);
            $cart->add_fee(
                sprintf(__('%s (Bulk %d%% Off)', 'wc-loyalty'), 
                    $program->name, $percentage),
                -$discount_amount
            );

            $this->log_program_usage($program->id, $discount_amount);
        }
    }

    /**
     * Log program usage
     */
    private function log_program_usage($program_id, $discount_amount) {
        global $wpdb;
        
        $stats_table = $wpdb->prefix . 'wc_loyalty_stats';
        $wpdb->insert(
            $stats_table,
            array(
                'program_id' => $program_id,
                'order_id' => 0, // Will be updated when order is created
                'discount_amount' => $discount_amount,
                'date_created' => current_time('mysql')
            )
        );
    }

    /**
     * Add program info to product price display
     */
    public function add_program_info_to_price($price_html, $product) {
        global $wpdb;
        
        if (is_admin()) return $price_html;

        $programs_table = $wpdb->prefix . 'wc_loyalty_programs';
        $active_programs = $wpdb->get_results("
            SELECT * FROM {$programs_table} 
            WHERE status = 'active' 
            AND (start_date IS NULL OR start_date <= NOW())
            AND (end_date IS NULL OR end_date >= NOW())
        ");

        $program_info = array();

        foreach ($active_programs as $program) {
            $settings = json_decode($program->settings, true);
            $applicable_products = isset($settings['applicable_products']) ? $settings['applicable_products'] : array();

            if (empty($applicable_products) || in_array($product->get_id(), $applicable_products)) {
                switch ($program->type) {
                    case 'buy_x_get_y':
                        $program_info[] = sprintf(
                            __('Buy %d Get %d Free', 'wc-loyalty'),
                            $settings['buy_quantity'],
                            $settings['get_quantity']
                        );
                        break;
                    case 'percentage_discount':
                        $program_info[] = sprintf(
                            __('%d%% Off', 'wc-loyalty'),
                            $settings['percentage']
                        );
                        break;
                    case 'fixed_amount_discount':
                        $program_info[] = sprintf(
                            __('%s Off', 'wc-loyalty'),
                            wc_price($settings['fixed_amount'])
                        );
                        break;
                }
            }
        }

        if (!empty($program_info)) {
            $price_html .= '<div class="program-offers">' . implode(' | ', $program_info) . '</div>';
        }

        return $price_html;
    }
}