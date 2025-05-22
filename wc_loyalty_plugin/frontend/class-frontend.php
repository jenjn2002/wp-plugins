<?php
if (!defined('ABSPATH')) {
    exit;
}

class WC_Loyalty_Frontend {
    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('woocommerce_before_add_to_cart_button', array($this, 'display_program_info'));
        add_action('woocommerce_cart_totals_after_order_total', array($this, 'display_cart_points'));
        add_action('woocommerce_review_order_after_order_total', array($this, 'display_checkout_points'));
    }

    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_scripts() {
        if (!is_woocommerce() && !is_cart() && !is_checkout()) {
            return;
        }

        wp_enqueue_style(
            'wc-loyalty-frontend',
            WC_LOYALTY_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            WC_LOYALTY_VERSION
        );

        wp_enqueue_script(
            'wc-loyalty-frontend',
            WC_LOYALTY_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            WC_LOYALTY_VERSION,
            true
        );

        wp_localize_script('wc-loyalty-frontend', 'wcLoyalty', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_loyalty_frontend'),
            'i18n' => array(
                'pointsApplied' => __('Points applied successfully!', 'wc-loyalty'),
                'error' => __('Error applying points. Please try again.', 'wc-loyalty')
            )
        ));
    }

    /**
     * Display program info on product page
     */
    public function display_program_info() {
        global $product;
        
        if (!$product) return;

        $points_rate = get_option('wc_loyalty_points_rate', 5000);
        $points_earned = floor($product->get_price() / $points_rate);

        if ($points_earned > 0) {
            ?>
            <div class="loyalty-points-info">
                <span class="points-icon">⭐</span>
                <span class="points-text">
                    <?php
                    printf(
                        __('Earn %d loyalty points with this purchase!', 'wc-loyalty'),
                        $points_earned
                    );
                    ?>
                </span>
            </div>
            <?php
        }

        // Display active program info
        $active_programs = $this->get_active_programs_for_product($product->get_id());
        if (!empty($active_programs)) {
            ?>
            <div class="loyalty-program-info">
                <h4><?php _e('Special Offers', 'wc-loyalty'); ?></h4>
                <?php foreach ($active_programs as $program): ?>
                    <div class="program-offer">
                        <?php echo $this->get_program_display_text($program); ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php
        }
    }

    /**
     * Display points information in cart
     */
    public function display_cart_points() {
        if (!is_user_logged_in()) return;

        $user_id = get_current_user_id();
        $points = $this->get_user_points($user_id);
        $redemption_rate = get_option('wc_loyalty_redemption_rate', 1000);
        
        if ($points > 0) {
            ?>
            <tr class="loyalty-points-row">
                <th><?php _e('Available Points', 'wc-loyalty'); ?></th>
                <td>
                    <?php
                    printf(
                        __('%d points (%s value)', 'wc-loyalty'),
                        $points,
                        wc_price($points * $redemption_rate)
                    );
                    ?>
                </td>
            </tr>
            <tr class="use-points-row">
                <th><?php _e('Use Points', 'wc-loyalty'); ?></th>
                <td>
                    <input type="number" id="points-to-use" 
                           max="<?php echo esc_attr($points); ?>" min="0" 
                           placeholder="<?php esc_attr_e('Enter points', 'wc-loyalty'); ?>">
                    <button type="button" id="apply-points" class="button">
                        <?php _e('Apply', 'wc-loyalty'); ?>
                    </button>
                </td>
            </tr>
            <?php
        }
    }

    /**
     * Display points information at checkout
     */
    public function display_checkout_points() {
        $this->display_cart_points();
    }

    /**
     * Get active programs for a product
     */
    private function get_active_programs_for_product($product_id) {
        global $wpdb;
        
        $programs_table = $wpdb->prefix . 'wc_loyalty_programs';
        $programs = $wpdb->get_results("
            SELECT * FROM {$programs_table}
            WHERE status = 'active'
            AND (start_date IS NULL OR start_date <= NOW())
            AND (end_date IS NULL OR end_date >= NOW())
        ");

        $applicable_programs = array();

        foreach ($programs as $program) {
            $settings = json_decode($program->settings, true);
            $applicable_products = isset($settings['applicable_products']) ? $settings['applicable_products'] : array();

            if (empty($applicable_products) || in_array($product_id, $applicable_products)) {
                $applicable_programs[] = $program;
            }
        }

        return $applicable_programs;
    }

    /**
     * Get program display text
     */
    private function get_program_display_text($program) {
        $settings = json_decode($program->settings, true);

        switch ($program->type) {
            case 'buy_x_get_y':
                return sprintf(
                    __('Buy %d Get %d Free!', 'wc-loyalty'),
                    $settings['buy_quantity'],
                    $settings['get_quantity']
                );
            case 'percentage_discount':
                return sprintf(
                    __('%d%% Off!', 'wc-loyalty'),
                    $settings['percentage']
                );
            case 'fixed_amount_discount':
                return sprintf(
                    __('%s Off!', 'wc-loyalty'),
                    wc_price($settings['fixed_amount'])
                );
            case 'bulk_discount':
                return sprintf(
                    __('Buy %d or more: %d%% Off!', 'wc-loyalty'),
                    $settings['bulk_min_qty'],
                    $settings['bulk_percentage']
                );
            default:
                return esc_html($program->name);
        }
    }

    /**
     * Get user points
     */
    private function get_user_points($user_id) {
        global $wpdb;
        
        $points_table = $wpdb->prefix . 'wc_loyalty_points';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT points FROM {$points_table} WHERE user_id = %d",
            $user_id
        ));
    }
}