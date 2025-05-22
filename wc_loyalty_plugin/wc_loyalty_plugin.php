<?php
/**
 * Plugin Name: WooCommerce Loyalty & Discount System
 * Description: Complete loyalty points, discount programs, and coupon management system for WooCommerce
 * Version: 1.0.0
 * Author: Your Store
 * Author URI: https://yourstore.com
 * Text Domain: wc-loyalty
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WC_LOYALTY_VERSION', '1.0.0');
define('WC_LOYALTY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_LOYALTY_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WC_LOYALTY_MIN_WP_VERSION', '5.8');
define('WC_LOYALTY_MIN_WC_VERSION', '5.0');

/**
 * Main plugin class
 */
class WC_Loyalty_System {
    /**
     * @var WC_Loyalty_System Single instance of this class
     */
    private static $instance = null;

    /**
     * Main instance
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Core plugin initialization
        add_action('plugins_loaded', array($this, 'init'));

        // Register activation hook
        register_activation_hook(__FILE__, array($this, 'activate'));

        // Register deactivation hook
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Check dependencies
        if (!$this->check_dependencies()) {
            return;
        }

        // Load text domain
        load_plugin_textdomain('wc-loyalty', false, dirname(plugin_basename(__FILE__)) . '/languages');

        // Initialize components
        $this->init_components();

        // Admin hooks
        if (is_admin()) {
            $this->init_admin();
        }

        // Frontend hooks
        if (!is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            $this->init_frontend();
        }
    }

    /**
     * Check plugin dependencies
     */
    private function check_dependencies() {
        // Check WordPress version
        if (version_compare(get_bloginfo('version'), WC_LOYALTY_MIN_WP_VERSION, '<')) {
            add_action('admin_notices', array($this, 'wordpress_version_notice'));
            return false;
        }

        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return false;
        }

        // Check WooCommerce version
        if (defined('WC_VERSION') && version_compare(WC_VERSION, WC_LOYALTY_MIN_WC_VERSION, '<')) {
            add_action('admin_notices', array($this, 'woocommerce_version_notice'));
            return false;
        }

        return true;
    }

    /**
     * Initialize plugin components
     */
    private function init_components() {
        require_once WC_LOYALTY_PLUGIN_PATH . 'includes/class-points.php';
        require_once WC_LOYALTY_PLUGIN_PATH . 'includes/class-programs.php';
        require_once WC_LOYALTY_PLUGIN_PATH . 'includes/class-coupons.php';
        require_once WC_LOYALTY_PLUGIN_PATH . 'includes/class-dashboard.php';

        // Initialize components
        new WC_Loyalty_Points();
        new WC_Loyalty_Programs();
        new WC_Loyalty_Coupons();
        new WC_Loyalty_Dashboard();
    }

    /**
     * Initialize admin functionality
     */
    private function init_admin() {
        require_once WC_LOYALTY_PLUGIN_PATH . 'admin/class-admin.php';
        new WC_Loyalty_Admin();
    }

    /**
     * Initialize frontend functionality
     */
    private function init_frontend() {
        require_once WC_LOYALTY_PLUGIN_PATH . 'frontend/class-frontend.php';
        new WC_Loyalty_Frontend();
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables
        $this->create_tables();

        // Set default options
        $this->set_default_options();

        // Clear any cached data
        wp_cache_flush();

        // Trigger action for other components
        do_action('wc_loyalty_activated');
    }

    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $tables = array(
            // Points table
            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wc_loyalty_points (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NOT NULL,
                points int(11) NOT NULL DEFAULT 0,
                earned_points int(11) NOT NULL DEFAULT 0,
                used_points int(11) NOT NULL DEFAULT 0,
                last_updated datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY user_id (user_id)
            ) $charset_collate;",

            // Programs table
            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wc_loyalty_programs (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                name varchar(255) NOT NULL,
                type varchar(50) NOT NULL,
                status varchar(20) DEFAULT 'active',
                settings longtext,
                start_date datetime DEFAULT NULL,
                end_date datetime DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) $charset_collate;",

            // Program stats table
            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wc_loyalty_stats (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                program_id bigint(20) unsigned NOT NULL,
                order_id bigint(20) unsigned NOT NULL,
                product_id bigint(20) unsigned DEFAULT NULL,
                discount_amount decimal(10,2) DEFAULT 0.00,
                points_used int(11) DEFAULT 0,
                date_created datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY program_id (program_id),
                KEY order_id (order_id)
            ) $charset_collate;"
        );

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        foreach ($tables as $table) {
            dbDelta($table);
        }
    }

    /**
     * Set default options
     */
    private function set_default_options() {
        add_option('wc_loyalty_points_rate', 5000);
        add_option('wc_loyalty_redemption_rate', 1000);
        add_option('wc_loyalty_version', WC_LOYALTY_VERSION);
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        wp_clear_scheduled_hooks('wc_loyalty_daily_maintenance');
        do_action('wc_loyalty_deactivated');
    }

    /**
     * Admin notice for WordPress version requirement
     */
    public function wordpress_version_notice() {
        echo '<div class="error"><p>';
        echo sprintf(
            __('WooCommerce Loyalty System requires WordPress version %s or higher. Please upgrade WordPress to continue using the plugin.', 'wc-loyalty'),
            WC_LOYALTY_MIN_WP_VERSION
        );
        echo '</p></div>';
    }

    /**
     * Admin notice for WooCommerce dependency
     */
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p>';
        echo __('WooCommerce Loyalty System requires WooCommerce to be installed and active.', 'wc-loyalty');
        echo '</p></div>';
    }

    /**
     * Admin notice for WooCommerce version requirement
     */
    public function woocommerce_version_notice() {
        echo '<div class="error"><p>';
        echo sprintf(
            __('WooCommerce Loyalty System requires WooCommerce version %s or higher. Please upgrade WooCommerce to continue using the plugin.', 'wc-loyalty'),
            WC_LOYALTY_MIN_WC_VERSION
        );
        echo '</p></div>';
    }
}

/**
 * Returns the main instance of WC_Loyalty_System
 */
function WC_Loyalty() {
    return WC_Loyalty_System::instance();
}

// Initialize the plugin
WC_Loyalty();