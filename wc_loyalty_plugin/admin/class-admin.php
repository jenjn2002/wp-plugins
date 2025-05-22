<?php
/**
 * WooCommerce Loyalty Plugin Admin
 *
 * @package WC_Loyalty_Plugin
 * @subpackage Admin
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * WC_Loyalty_Admin Class
 */
class WC_Loyalty_Admin {
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Add menu items to WordPress admin
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Loyalty Programs', 'wc-loyalty'),
            __('Loyalty Programs', 'wc-loyalty'),
            'manage_woocommerce',
            'wc-loyalty-programs',
            array($this, 'render_programs_page'),
            'dashicons-awards',
            56
        );

        add_submenu_page(
            'wc-loyalty-programs',
            __('Programs', 'wc-loyalty'),
            __('Programs', 'wc-loyalty'),
            'manage_woocommerce',
            'wc-loyalty-programs',
            array($this, 'render_programs_page')
        );

        add_submenu_page(
            'wc-loyalty-programs',
            __('Coupons', 'wc-loyalty'),
            __('Coupons', 'wc-loyalty'),
            'manage_woocommerce',
            'wc-loyalty-coupons',
            array($this, 'render_coupons_page')
        );

        add_submenu_page(
            'wc-loyalty-programs',
            __('Reports', 'wc-loyalty'),
            __('Reports', 'wc-loyalty'),
            'manage_woocommerce',
            'wc-loyalty-reports',
            array($this, 'render_reports_page')
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts() {
        $screen = get_current_screen();
        if (strpos($screen->id, 'wc-loyalty') !== false) {
            wp_enqueue_style('wc-loyalty-admin', plugin_dir_url(__FILE__) . 'css/admin.css', array(), '1.0.0');
            wp_enqueue_script('wc-loyalty-admin', plugin_dir_url(__FILE__) . 'js/admin.js', array('jquery'), '1.0.0', true);
        }
    }

    /**
     * Render the programs page
     */
    public function render_programs_page() {
        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // Get programs from database
        $programs = $this->get_loyalty_programs();

        // Include the view template
        include plugin_dir_path(__FILE__) . 'views/programs-page.php';
    }

    /**
     * Render the coupons page
     */
    public function render_coupons_page() {
        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // Get coupons from database
        $coupons = $this->get_loyalty_coupons();

        // Include the view template
        include plugin_dir_path(__FILE__) . 'views/coupons-page.php';
    }

    /**
     * Render the reports page
     */
    public function render_reports_page() {
        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // Get report data
        $report_data = $this->get_loyalty_reports();

        // Include the view template
        include plugin_dir_path(__FILE__) . 'views/reports-page.php';
    }

    /**
     * Get loyalty programs from database
     *
     * @return array
     */
    private function get_loyalty_programs() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_loyalty_programs';
        
        return $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY id DESC", ARRAY_A);
    }

    /**
     * Get loyalty coupons from database
     *
     * @return array
     */
    private function get_loyalty_coupons() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_loyalty_coupons';
        
        return $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY id DESC", ARRAY_A);
    }

    /**
     * Get loyalty reports data
     *
     * @return array
     */
    private function get_loyalty_reports() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_loyalty_points';
        
        return array(
            'total_points' => $wpdb->get_var("SELECT SUM(points) FROM {$table_name}"),
            'total_users' => $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$table_name}"),
            'recent_activities' => $wpdb->get_results(
                "SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT 10",
                ARRAY_A
            )
        );
    }
}