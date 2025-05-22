<?php
/**
 * Plugin Name: WooCommerce Loyalty & Discount System
 * Description: Complete loyalty points, discount programs, and coupon management system for WooCommerce
 * Version: 1.0.0
 * Author: Your Store
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WC_LOYALTY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_LOYALTY_PLUGIN_PATH', plugin_dir_path(__FILE__));

class WC_Loyalty_System {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        register_activation_hook(__FILE__, array($this, 'activate'));
    }
    
    public function init() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        $this->includes();
        $this->init_hooks();
    }
    
    public function includes() {
        // All classes are defined in this single file
        // No separate includes needed
    }
    
    public function init_hooks() {
        new WC_Loyalty_Points();
        new WC_Discount_Programs();
        new WC_Coupon_Generator();
        new WC_Loyalty_Dashboard();
    }
    
    public function activate() {
        $this->create_tables();
        $this->set_default_options();
    }
    
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Points table
        $points_table = $wpdb->prefix . 'wc_loyalty_points';
        $points_sql = "CREATE TABLE $points_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            points int(11) NOT NULL DEFAULT 0,
            earned_points int(11) NOT NULL DEFAULT 0,
            used_points int(11) NOT NULL DEFAULT 0,
            last_updated datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id)
        ) $charset_collate;";
        
        // Programs table
        $programs_table = $wpdb->prefix . 'wc_loyalty_programs';
        $programs_sql = "CREATE TABLE $programs_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            type varchar(50) NOT NULL,
            status varchar(20) DEFAULT 'active',
            settings longtext,
            start_date datetime,
            end_date datetime,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        // Program stats table
        $stats_table = $wpdb->prefix . 'wc_loyalty_stats';
        $stats_sql = "CREATE TABLE $stats_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            program_id mediumint(9) NOT NULL,
            order_id bigint(20) NOT NULL,
            product_id bigint(20),
            discount_amount decimal(10,2),
            points_used int(11),
            date_created datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($points_sql);
        dbDelta($programs_sql);
        dbDelta($stats_sql);
    }
    
    public function set_default_options() {
        add_option('wc_loyalty_points_rate', 5000); // 5000đ = 1 point
        add_option('wc_loyalty_redemption_rate', 1000); // 1 point = 1000đ
        add_option('wc_loyalty_b2b_prefix', 'B2B');
    }
    
    public function enqueue_scripts() {
        wp_enqueue_script('wc-loyalty-frontend', WC_LOYALTY_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), '1.0.0', true);
        wp_enqueue_style('wc-loyalty-frontend', WC_LOYALTY_PLUGIN_URL . 'assets/css/frontend.css', array(), '1.0.0');
    }
    
    public function admin_enqueue_scripts() {
        wp_enqueue_script('wc-loyalty-admin', WC_LOYALTY_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), '1.0.0', true);
        wp_enqueue_style('wc-loyalty-admin', WC_LOYALTY_PLUGIN_URL . 'assets/css/admin.css', array(), '1.0.0');
    }
    
    public function woocommerce_missing_notice() {
        echo '<div class="notice notice-error"><p>WooCommerce Loyalty System requires WooCommerce to be installed and active.</p></div>';
    }
}

// Initialize the plugin
new WC_Loyalty_System();

/**
 * Loyalty Points Management Class
 */
class WC_Loyalty_Points {
    
    public function __construct() {
        add_action('woocommerce_order_status_completed', array($this, 'award_points'));
        add_action('woocommerce_checkout_order_processed', array($this, 'use_points_for_discount'));
        add_action('wp_ajax_apply_loyalty_points', array($this, 'apply_points_ajax'));
        add_action('wp_ajax_nopriv_apply_loyalty_points', array($this, 'apply_points_ajax'));
        add_action('woocommerce_cart_calculate_fees', array($this, 'add_points_discount'));
        add_shortcode('loyalty_points_balance', array($this, 'display_points_balance'));
    }
    
    public function award_points($order_id) {
        $order = wc_get_order($order_id);
        $user_id = $order->get_user_id();
        
        if (!$user_id) return;
        
        $points_rate = get_option('wc_loyalty_points_rate', 5000);
        $order_total = $order->get_total();
        $points_earned = floor($order_total / $points_rate);
        
        $this->add_points($user_id, $points_earned);
        
        // Add order note
        $order->add_order_note(sprintf('Customer earned %d loyalty points', $points_earned));
    }
    
    public function add_points($user_id, $points) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wc_loyalty_points';
        
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d", $user_id
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
    }
    
    public function get_user_points($user_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wc_loyalty_points';
        $points = $wpdb->get_var($wpdb->prepare(
            "SELECT points FROM $table WHERE user_id = %d", $user_id
        ));
        
        return $points ? intval($points) : 0;
    }
    
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
                        "SELECT used_points FROM $table WHERE user_id = %d", $user_id
                    )) + $points
                ),
                array('user_id' => $user_id)
            );
            return true;
        }
        
        return false;
    }
    
    public function add_points_discount() {
        if (!is_user_logged_in()) return;
        
        $points_to_use = WC()->session->get('loyalty_points_to_use', 0);
        if ($points_to_use > 0) {
            $redemption_rate = get_option('wc_loyalty_redemption_rate', 1000);
            $discount_amount = $points_to_use * $redemption_rate;
            
            WC()->cart->add_fee('Loyalty Points Discount', -$discount_amount);
        }
    }
    
    public function apply_points_ajax() {
        $points = intval($_POST['points']);
        $user_id = get_current_user_id();
        
        if (!$user_id) {
            wp_die('Please log in to use points');
        }
        
        $available_points = $this->get_user_points($user_id);
        
        if ($points > $available_points) {
            wp_die('Insufficient points');
        }
        
        WC()->session->set('loyalty_points_to_use', $points);
        wp_die('Points applied successfully');
    }
    
    public function display_points_balance($atts) {
        if (!is_user_logged_in()) {
            return '<p>Please log in to view your points balance.</p>';
        }
        
        $user_id = get_current_user_id();
        $points = $this->get_user_points($user_id);
        $redemption_rate = get_option('wc_loyalty_redemption_rate', 1000);
        $value = $points * $redemption_rate;
        
        return sprintf(
            '<div class="loyalty-points-balance">
                <h3>Your Loyalty Points</h3>
                <p><strong>Points:</strong> %d</p>
                <p><strong>Value:</strong> %s</p>
                <div class="points-usage">
                    <input type="number" id="points-to-use" max="%d" min="0" placeholder="Points to use">
                    <button id="apply-points">Apply Points</button>
                </div>
            </div>',
            $points,
            wc_price($value),
            $points
        );
    }
}

/**
 * Discount Programs Management Class
 */
class WC_Discount_Programs {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_save_discount_program', array($this, 'save_program'));
        add_action('woocommerce_cart_calculate_fees', array($this, 'apply_discount_programs'));
        add_filter('woocommerce_coupon_discount_amount_html', array($this, 'modify_discount_display'), 10, 2);
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Loyalty Programs',
            'Loyalty Programs',
            'manage_woocommerce',
            'wc-loyalty-programs',
            array($this, 'admin_page')
        );
    }
    
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>Loyalty & Discount Programs</h1>
            
            <div class="loyalty-admin-tabs">
                <button class="tab-button active" data-tab="programs">Programs</button>
                <button class="tab-button" data-tab="coupons">Coupons</button>
                <button class="tab-button" data-tab="dashboard">Dashboard</button>
            </div>
            
            <div id="programs-tab" class="tab-content active">
                <h2>Create New Program</h2>
                <form id="discount-program-form">
                    <table class="form-table">
                        <tr>
                            <th><label for="program_name">Program Name</label></th>
                            <td><input type="text" id="program_name" name="program_name" required></td>
                        </tr>
                        <tr>
                            <th><label for="program_type">Program Type</label></th>
                            <td>
                                <select id="program_type" name="program_type">
                                    <option value="buy_x_get_y">Buy X Get Y</option>
                                    <option value="percentage_discount">Percentage Discount</option>
                                    <option value="fixed_amount_discount">Fixed Amount Discount</option>
                                    <option value="bulk_discount">Bulk Discount</option>
                                </select>
                            </td>
                        </tr>
                        <tr id="bxgy_settings" class="program-settings">
                            <th>Buy X Get Y Settings</th>
                            <td>
                                <label>Buy: <input type="number" name="buy_quantity" min="1" value="1"></label>
                                <label>Get: <input type="number" name="get_quantity" min="1" value="1"></label>
                                <label>Free/Discounted: 
                                    <select name="discount_type">
                                        <option value="free">Free</option>
                                        <option value="percentage">Percentage Off</option>
                                    </select>
                                </label>
                            </td>
                        </tr>
                        <tr id="percentage_settings" class="program-settings" style="display:none;">
                            <th>Percentage Discount</th>
                            <td>
                                <label>Discount %: <input type="number" name="percentage" min="1" max="100" step="0.01" value="10"></label>
                            </td>
                        </tr>
                        <tr id="fixed_amount_discount_settings" class="program-settings" style="display:none;">
                            <th>Fixed Amount Discount</th>
                            <td>
                                <label>Discount Amount (VND): <input type="number" name="fixed_amount" min="1000" step="1000" value="50000"></label>
                            </td>
                        </tr>
                        <tr id="bulk_discount_settings" class="program-settings" style="display:none;">
                            <th>Bulk Discount Settings</th>
                            <td>
                                <label>Minimum Quantity: <input type="number" name="bulk_min_qty" min="2" value="5"></label><br>
                                <label>Discount %: <input type="number" name="bulk_percentage" min="1" max="50" step="0.01" value="15"></label>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="applicable_products">Applicable Products</label></th>
                            <td>
                                <select id="applicable_products" name="applicable_products[]" multiple>
                                    <?php 
                                    $products = wc_get_products(array('limit' => -1));
                                    foreach($products as $product) {
                                        echo '<option value="' . $product->get_id() . '">' . $product->get_name() . '</option>';
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="start_date">Start Date</label></th>
                            <td><input type="datetime-local" id="start_date" name="start_date"></td>
                        </tr>
                        <tr>
                            <th><label for="end_date">End Date</label></th>
                            <td><input type="datetime-local" id="end_date" name="end_date"></td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" class="button-primary" value="Save Program">
                    </p>
                </form>
                
                <h2>Existing Programs</h2>
                <div id="programs-list">
                    <?php $this->display_programs_list(); ?>
                </div>
            </div>
            
            <div id="coupons-tab" class="tab-content">
                <h2>Auto-Generated B2B Coupons</h2>
                <form id="generate-coupons-form">
                    <table class="form-table">
                        <tr>
                            <th><label for="coupon_count">Number of Coupons</label></th>
                            <td><input type="number" id="coupon_count" name="coupon_count" min="1" max="100" value="10"></td>
                        </tr>
                        <tr>
                            <th><label for="coupon_prefix">Prefix</label></th>
                            <td><input type="text" id="coupon_prefix" name="coupon_prefix" value="B2B" maxlength="10"></td>
                        </tr>
                        <tr>
                            <th><label for="discount_type">Discount Type</label></th>
                            <td>
                                <select id="discount_type" name="discount_type">
                                    <option value="percent">Percentage</option>
                                    <option value="fixed_cart">Fixed Amount</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="discount_amount">Discount Amount</label></th>
                            <td><input type="number" id="discount_amount" name="discount_amount" step="0.01" min="0"></td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" class="button-primary" value="Generate Coupons">
                    </p>
                </form>
            </div>
            
            <div id="dashboard-tab" class="tab-content">
                <?php $this->display_dashboard(); ?>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            console.log('Loyalty admin script loaded');
            
            // Tab switching functionality
            $('.tab-button').click(function(e) {
                e.preventDefault();
                var tab = $(this).data('tab');
                console.log('Switching to tab:', tab);
                
                $('.tab-button').removeClass('active');
                $('.tab-content').removeClass('active').hide();
                
                $(this).addClass('active');
                $('#' + tab + '-tab').addClass('active').show();
            });
            
            // Show first tab by default
            $('.tab-button').first().click();
            
            // Program type settings visibility
            $('#program_type').change(function() {
                var selectedType = $(this).val();
                console.log('Program type changed:', selectedType);
                
                $('.program-settings').hide();
                $('#' + selectedType + '_settings').show();
            });
            
            // Show initial program settings
            $('#program_type').trigger('change');
            
            // Form submission
            $('#discount-program-form').on('submit', function(e) {
                e.preventDefault();
                console.log('Form submitted');
                
                var formData = $(this).serialize();
                console.log('Form data:', formData);
                
                $.post(ajaxurl, {
                    action: 'save_discount_program',
                    ...Object.fromEntries(new URLSearchParams(formData))
                })
                .done(function(response) {
                    console.log('Response:', response);
                    alert('Program saved successfully!');
                    location.reload();
                })
                .fail(function(xhr, status, error) {
                    console.error('Error:', error);
                    alert('Error saving program: ' + error);
                });
            });
            
            // Generate coupons form
            $('#generate-coupons-form').on('submit', function(e) {
                e.preventDefault();
                console.log('Generating coupons');
                
                var formData = $(this).serialize();
                
                $.post(ajaxurl, {
                    action: 'generate_b2b_coupons',
                    ...Object.fromEntries(new URLSearchParams(formData))
                })
                .done(function(response) {
                    console.log('Coupons response:', response);
                    if (response.success) {
                        var coupons = response.data;
                        var html = '<div class="notice notice-success"><h3>Generated Coupons:</h3><ul>';
                        
                        coupons.forEach(function(coupon) {
                            html += '<li><strong>' + coupon.code + '</strong> - ' + 
                                   coupon.discount + (coupon.type === 'percent' ? '%' : ' VND') + 
                                   ' off (Expires: ' + coupon.expires + ')</li>';
                        });
                        
                        html += '</ul></div>';
                        $('#coupons-tab').prepend(html);
                    }
                })
                .fail(function(xhr, status, error) {
                    console.error('Error generating coupons:', error);
                    alert('Error generating coupons: ' + error);
                });
            });
            
            // Delete program functionality
            $(document).on('click', '.delete-program', function() {
                if (confirm('Are you sure you want to delete this program?')) {
                    var programId = $(this).data('id');
                    // Add delete functionality here
                    console.log('Delete program:', programId);
                }
            });
            
            // Edit program functionality
            $(document).on('click', '.edit-program', function() {
                var programId = $(this).data('id');
                // Add edit functionality here
                console.log('Edit program:', programId);
            });
        });
        </script>
        
        <style>
        .wrap {
            margin: 20px;
        }
        .loyalty-admin-tabs {
            margin: 20px 0;
            border-bottom: 1px solid #ccc;
        }
        .tab-button {
            background: #f1f1f1;
            border: 1px solid #ccc;
            border-bottom: none;
            padding: 12px 20px;
            cursor: pointer;
            margin-right: 5px;
            display: inline-block;
            text-decoration: none;
            color: #333;
            border-radius: 4px 4px 0 0;
        }
        .tab-button.active {
            background: #0073aa;
            color: white;
            border-color: #0073aa;
        }
        .tab-button:hover {
            background: #e0e0e0;
        }
        .tab-button.active:hover {
            background: #005a87;
        }
        .tab-content {
            display: none;
            padding: 20px;
            background: white;
            border: 1px solid #ccc;
            border-top: none;
            min-height: 400px;
        }
        .tab-content.active {
            display: block;
        }
        .form-table th {
            width: 200px;
            padding: 15px 10px;
            vertical-align: top;
        }
        .form-table td {
            padding: 15px 10px;
        }
        .form-table input[type="text"],
        .form-table input[type="number"],
        .form-table input[type="datetime-local"],
        .form-table select {
            width: 100%;
            max-width: 300px;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .form-table select[multiple] {
            height: 120px;
        }
        .program-settings {
            background: #f9f9f9;
            padding: 10px;
            border-left: 4px solid #0073aa;
        }
        .program-settings label {
            display: inline-block;
            margin-right: 15px;
            margin-bottom: 5px;
        }
        .program-settings input,
        .program-settings select {
            width: auto;
            min-width: 80px;
            margin-left: 5px;
        }
        .button-primary {
            background: #0073aa;
            border-color: #0073aa;
            color: white;
            padding: 8px 20px;
            border-radius: 4px;
            cursor: pointer;
        }
        .button-primary:hover {
            background: #005a87;
        }
        .wp-list-table {
            margin-top: 20px;
        }
        .wp-list-table th,
        .wp-list-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .wp-list-table th {
            background: #f1f1f1;
            font-weight: bold;
        }
        .wp-list-table tr:hover {
            background: #f9f9f9;
        }
        .loyalty-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin: 20px 0;
        }
        .stat-box {
            background: #f9f9f9;
            padding: 20px;
            border: 1px solid #ddd;
            text-align: center;
            border-radius: 6px;
        }
        .stat-box h4 {
            margin: 0 0 10px 0;
            color: #0073aa;
            font-size: 14px;
        }
        .stat-box p {
            font-size: 24px;
            font-weight: bold;
            margin: 0;
            color: #333;
        }
        .notice {
            background: #fff;
            border-left: 4px solid #00a0d2;
            padding: 12px;
            margin: 15px 0;
        }
        .notice.notice-success {
            border-left-color: #46b450;
        }
        .notice.notice-error {
            border-left-color: #dc3232;
        }
        </style>
        <?php
    }
    
    public function display_programs_list() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wc_loyalty_programs';
        $programs = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");
        
        if ($programs) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>Name</th><th>Type</th><th>Status</th><th>Start Date</th><th>End Date</th><th>Actions</th></tr></thead>';
            echo '<tbody>';
            
            foreach ($programs as $program) {
                echo '<tr>';
                echo '<td>' . esc_html($program->name) . '</td>';
                echo '<td>' . ucwords(str_replace('_', ' ', $program->type)) . '</td>';
                echo '<td>' . ucfirst($program->status) . '</td>';
                echo '<td>' . ($program->start_date ? date('Y-m-d H:i', strtotime($program->start_date)) : 'N/A') . '</td>';
                echo '<td>' . ($program->end_date ? date('Y-m-d H:i', strtotime($program->end_date)) : 'N/A') . '</td>';
                echo '<td><button class="button edit-program" data-id="' . $program->id . '">Edit</button> ';
                echo '<button class="button delete-program" data-id="' . $program->id . '">Delete</button></td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        } else {
            echo '<p>No programs created yet.</p>';
        }
    }
    
    public function display_dashboard() {
        global $wpdb;
        
        $stats_table = $wpdb->prefix . 'wc_loyalty_stats';
        $programs_table = $wpdb->prefix . 'wc_loyalty_programs';
        
        // Get program statistics
        $program_stats = $wpdb->get_results("
            SELECT p.name, p.type, 
                   COUNT(s.id) as total_uses,
                   SUM(s.discount_amount) as total_discount,
                   COUNT(DISTINCT s.order_id) as total_orders,
                   COUNT(DISTINCT s.product_id) as unique_products
            FROM {$programs_table} p
            LEFT JOIN {$stats_table} s ON p.id = s.program_id
            GROUP BY p.id
            ORDER BY total_uses DESC
        ");
        
        echo '<h2>Program Performance Dashboard</h2>';
        
        if ($program_stats) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>Program Name</th><th>Type</th><th>Total Uses</th><th>Total Discount</th><th>Orders</th><th>Unique Products</th></tr></thead>';
            echo '<tbody>';
            
            foreach ($program_stats as $stat) {
                echo '<tr>';
                echo '<td>' . esc_html($stat->name) . '</td>';
                echo '<td>' . ucwords(str_replace('_', ' ', $stat->type)) . '</td>';
                echo '<td>' . intval($stat->total_uses) . '</td>';
                echo '<td>' . wc_price($stat->total_discount) . '</td>';
                echo '<td>' . intval($stat->total_orders) . '</td>';
                echo '<td>' . intval($stat->unique_products) . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        } else {
            echo '<p>No program data available yet.</p>';
        }
        
        // Points summary
        $points_table = $wpdb->prefix . 'wc_loyalty_points';
        $points_stats = $wpdb->get_row("
            SELECT COUNT(*) as total_users,
                   SUM(points) as total_active_points,
                   SUM(earned_points) as total_earned_points,
                   SUM(used_points) as total_used_points
            FROM {$points_table}
        ");
        
        if ($points_stats) {
            echo '<h3>Loyalty Points Summary</h3>';
            echo '<div class="loyalty-stats-grid">';
            echo '<div class="stat-box"><h4>Total Users</h4><p>' . number_format($points_stats->total_users) . '</p></div>';
            echo '<div class="stat-box"><h4>Active Points</h4><p>' . number_format($points_stats->total_active_points) . '</p></div>';
            echo '<div class="stat-box"><h4>Total Earned</h4><p>' . number_format($points_stats->total_earned_points) . '</p></div>';
            echo '<div class="stat-box"><h4>Total Used</h4><p>' . number_format($points_stats->total_used_points) . '</p></div>';
            echo '</div>';
        }
        
        echo '<style>
        .loyalty-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin: 20px 0;
        }
        .stat-box {
            background: #f9f9f9;
            padding: 20px;
            border: 1px solid #ddd;
            text-align: center;
        }
        .stat-box h4 {
            margin: 0 0 10px 0;
            color: #0073aa;
        }
        .stat-box p {
            font-size: 24px;
            font-weight: bold;
            margin: 0;
        }
        </style>';
    }
    
    public function save_program() {
        global $wpdb;
        
        $program_data = array(
            'name' => sanitize_text_field($_POST['program_name']),
            'type' => sanitize_text_field($_POST['program_type']),
            'settings' => json_encode($_POST),
            'start_date' => $_POST['start_date'] ? date('Y-m-d H:i:s', strtotime($_POST['start_date'])) : null,
            'end_date' => $_POST['end_date'] ? date('Y-m-d H:i:s', strtotime($_POST['end_date'])) : null,
            'status' => 'active'
        );
        
        $table = $wpdb->prefix . 'wc_loyalty_programs';
        $wpdb->insert($table, $program_data);
        
        wp_die('Program saved successfully!');
    }
    
    public function apply_discount_programs() {
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
    
    private function apply_program_discount($program) {
        $settings = json_decode($program->settings, true);
        $cart = WC()->cart;
        
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
        }
    }
    
    private function apply_bxgy_discount($program, $settings, $cart) {
        $buy_qty = intval($settings['buy_quantity']);
        $get_qty = intval($settings['get_quantity']);
        $applicable_products = isset($settings['applicable_products']) ? $settings['applicable_products'] : array();
        
        $eligible_items = 0;
        
        foreach ($cart->get_cart() as $cart_item) {
            if (empty($applicable_products) || in_array($cart_item['product_id'], $applicable_products)) {
                $eligible_items += $cart_item['quantity'];
            }
        }
        
        $free_items = floor($eligible_items / $buy_qty) * $get_qty;
        
        if ($free_items > 0) {
            // Find the cheapest applicable item for discount
            $cheapest_price = PHP_INT_MAX;
            foreach ($cart->get_cart() as $cart_item) {
                if (empty($applicable_products) || in_array($cart_item['product_id'], $applicable_products)) {
                    $price = $cart_item['data']->get_price();
                    if ($price < $cheapest_price) {
                        $cheapest_price = $price;
                    }
                }
            }
            
            $discount_amount = min($free_items, $eligible_items) * $cheapest_price;
            $cart->add_fee($program->name . ' (Buy ' . $buy_qty . ' Get ' . $get_qty . ')', -$discount_amount);
        }
    }
    
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
            $cart->add_fee($program->name . ' (' . $percentage . '% off)', -$discount_amount);
        }
    }
}

/**
 * Coupon Generator Class
 */
class WC_Coupon_Generator {
    
    public function __construct() {
        add_action('wp_ajax_generate_b2b_coupons', array($this, 'generate_coupons'));
    }
    
    public function generate_coupons() {
        $count = intval($_POST['coupon_count']);
        $prefix = sanitize_text_field($_POST['coupon_prefix']);
        $discount_type = sanitize_text_field($_POST['discount_type']);
        $discount_amount = floatval($_POST['discount_amount']);
        
        $generated_coupons = array();
        
        for ($i = 0; $i < $count; $i++) {
            $coupon_code = $prefix . '_' . strtoupper(wp_generate_password(8, false));
            
            // Create WooCommerce coupon
            $coupon = new WC_Coupon();
            $coupon->set_code($coupon_code);
            $coupon->set_discount_type($discount_type);
            $coupon->set_amount($discount_amount);
            $coupon->set_individual_use(true);
            $coupon->set_usage_limit(1);
            $coupon->set_description('Auto-generated B2B coupon');
            
            // Set expiry date (30 days from now)
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
    
    public function get_coupon_usage_stats() {
        global $wpdb;
        
        $results = $wpdb->get_results("
            SELECT p.post_title as coupon_code,
                   pm1.meta_value as discount_type,
                   pm2.meta_value as coupon_amount,
                   pm3.meta_value as usage_count,
                   pm4.meta_value as usage_limit
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = 'discount_type'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = 'coupon_amount'
            LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = 'usage_count'
            LEFT JOIN {$wpdb->postmeta} pm4 ON p.ID = pm4.post_id AND pm4.meta_key = 'usage_limit'
            WHERE p.post_type = 'shop_coupon'
            AND p.post_title LIKE 'B2B_%'
            ORDER BY p.post_date DESC
        ");
        
        return $results;
    }
}

/**
 * Admin Dashboard Class
 */
class WC_Loyalty_Dashboard {
    
    public function __construct() {
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widgets'));
        add_action('wp_ajax_get_loyalty_chart_data', array($this, 'get_chart_data'));
    }
    
    public function add_dashboard_widgets() {
        wp_add_dashboard_widget(
            'wc_loyalty_stats',
            'Loyalty Program Statistics',
            array($this, 'display_dashboard_widget')
        );
    }
    
    public function display_dashboard_widget() {
        global $wpdb;
        
        // Get recent stats
        $points_table = $wpdb->prefix . 'wc_loyalty_points';
        $recent_points = $wpdb->get_var("
            SELECT SUM(earned_points) 
            FROM {$points_table} 
            WHERE last_updated >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        
        $stats_table = $wpdb->prefix . 'wc_loyalty_stats';
        $recent_discount = $wpdb->get_var("
            SELECT SUM(discount_amount) 
            FROM {$stats_table} 
            WHERE date_created >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        
        echo '<div class="loyalty-dashboard-widget">';
        echo '<h4>Last 7 Days</h4>';
        echo '<p><strong>Points Earned:</strong> ' . number_format($recent_points ?: 0) . '</p>';
        echo '<p><strong>Discounts Applied:</strong> ' . wc_price($recent_discount ?: 0) . '</p>';
        echo '<p><a href="' . admin_url('admin.php?page=wc-loyalty-programs') . '" class="button">View Full Dashboard</a></p>';
        echo '</div>';
    }
    
    public function get_chart_data() {
        global $wpdb;
        
        $stats_table = $wpdb->prefix . 'wc_loyalty_stats';
        
        // Get daily statistics for the last 30 days
        $daily_stats = $wpdb->get_results("
            SELECT DATE(date_created) as date,
                   COUNT(DISTINCT order_id) as orders,
                   SUM(discount_amount) as total_discount,
                   SUM(points_used) as points_used
            FROM {$stats_table}
            WHERE date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(date_created)
            ORDER BY date ASC
        ");
        
        wp_send_json_success($daily_stats);
    }
}

// Frontend shortcodes and hooks
add_action('woocommerce_single_product_summary', 'wc_loyalty_display_program_info', 25);
add_action('woocommerce_cart_totals_after_order_total', 'wc_loyalty_display_cart_points');
add_action('woocommerce_review_order_after_order_total', 'wc_loyalty_display_checkout_points');

function wc_loyalty_display_program_info() {
    global $product, $wpdb;
    
    $programs_table = $wpdb->prefix . 'wc_loyalty_programs';
    $active_programs = $wpdb->get_results("
        SELECT * FROM {$programs_table} 
        WHERE status = 'active' 
        AND (start_date IS NULL OR start_date <= NOW())
        AND (end_date IS NULL OR end_date >= NOW())
    ");
    
    $applicable_programs = array();
    
    foreach ($active_programs as $program) {
        $settings = json_decode($program->settings, true);
        $applicable_products = isset($settings['applicable_products']) ? $settings['applicable_products'] : array();
        
        if (empty($applicable_products) || in_array($product->get_id(), $applicable_products)) {
            $applicable_programs[] = $program;
        }
    }
    
    if (!empty($applicable_programs)) {
        echo '<div class="loyalty-program-info">';
        echo '<h4>🎉 Special Offers Available!</h4>';
        
        foreach ($applicable_programs as $program) {
            $settings = json_decode($program->settings, true);
            
            switch ($program->type) {
                case 'buy_x_get_y':
                    echo '<p class="program-offer">Buy ' . $settings['buy_quantity'] . ' Get ' . $settings['get_quantity'] . ' Free!</p>';
                    break;
                case 'percentage_discount':
                    echo '<p class="program-offer">' . $settings['percentage'] . '% Off!</p>';
                    break;
                case 'fixed_amount_discount':
                    echo '<p class="program-offer">' . wc_price($settings['amount']) . ' Off!</p>';
                    break;
            }
        }
        
        echo '</div>';
    }
    
    // Display points earning info
    $points_rate = get_option('wc_loyalty_points_rate', 5000);
    $product_price = $product->get_price();
    $points_earned = floor($product_price / $points_rate);
    
    if ($points_earned > 0) {
        echo '<div class="loyalty-points-info">';
        echo '<p>⭐ Earn <strong>' . $points_earned . ' loyalty points</strong> with this purchase!</p>';
        echo '</div>';
    }
}

function wc_loyalty_display_cart_points() {
    if (!is_user_logged_in()) return;
    
    $loyalty_points = new WC_Loyalty_Points();
    $user_points = $loyalty_points->get_user_points(get_current_user_id());
    $redemption_rate = get_option('wc_loyalty_redemption_rate', 1000);
    
    if ($user_points > 0) {
        echo '<tr class="loyalty-points-row">';
        echo '<th>Available Loyalty Points:</th>';
        echo '<td>' . $user_points . ' points (' . wc_price($user_points * $redemption_rate) . ' value)</td>';
        echo '</tr>';
        
        echo '<tr class="use-points-row">';
        echo '<th>Use Points:</th>';
        echo '<td>';
        echo '<input type="number" id="cart-points-input" max="' . $user_points . '" min="0" placeholder="Points to use">';
        echo ' <button type="button" id="apply-cart-points" class="button">Apply</button>';
        echo '</td>';
        echo '</tr>';
    }
}

function wc_loyalty_display_checkout_points() {
    wc_loyalty_display_cart_points();
}

// AJAX handlers for frontend
add_action('wp_ajax_apply_cart_points', 'wc_loyalty_apply_cart_points');
add_action('wp_ajax_nopriv_apply_cart_points', 'wc_loyalty_apply_cart_points');

function wc_loyalty_apply_cart_points() {
    if (!is_user_logged_in()) {
        wp_send_json_error('Please log in to use points');
    }
    
    $points = intval($_POST['points']);
    $loyalty_points = new WC_Loyalty_Points();
    $user_points = $loyalty_points->get_user_points(get_current_user_id());
    
    if ($points > $user_points) {
        wp_send_json_error('Insufficient points');
    }
    
    WC()->session->set('loyalty_points_to_use', $points);
    wp_send_json_success('Points applied successfully');
}

// Add CSS and JS files content
function wc_loyalty_add_inline_styles() {
    ?>
    <style>
    .loyalty-program-info {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 15px;
        border-radius: 8px;
        margin: 15px 0;
    }
    
    .loyalty-program-info h4 {
        margin: 0 0 10px 0;
        font-size: 16px;
    }
    
    .program-offer {
        margin: 5px 0;
        font-weight: bold;
        font-size: 14px;
    }
    
    .loyalty-points-info {
        background: #f8f9fa;
        border: 1px solid #e9ecef;
        padding: 12px;
        border-radius: 6px;
        margin: 10px 0;
    }
    
    .loyalty-points-info p {
        margin: 0;
        color: #495057;
    }
    
    .loyalty-points-row th,
    .use-points-row th {
        color: #0073aa;
        font-weight: bold;
    }
    
    .loyalty-points-balance {
        background: white;
        border: 2px solid #0073aa;
        border-radius: 8px;
        padding: 20px;
        margin: 20px 0;
    }
    
    .loyalty-points-balance h3 {
        color: #0073aa;
        margin-top: 0;
    }
    
    .points-usage {
        display: flex;
        gap: 10px;
        align-items: center;
        margin-top: 15px;
    }
    
    .points-usage input {
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 4px;
        width: 150px;
    }
    
    .points-usage button,
    #apply-cart-points {
        background: #0073aa;
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 4px;
        cursor: pointer;
        font-weight: bold;
    }
    
    .points-usage button:hover,
    #apply-cart-points:hover {
        background: #005a87;
    }
    
    #cart-points-input {
        width: 100px;
        padding: 5px;
        margin-right: 10px;
    }
    
    .loyalty-admin-container {
        max-width: 1200px;
    }
    
    .program-card {
        background: white;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        margin: 15px 0;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .program-card h3 {
        margin-top: 0;
        color: #0073aa;
    }
    
    .program-status {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: bold;
        text-transform: uppercase;
    }
    
    .program-status.active {
        background: #d4edda;
        color: #155724;
    }
    
    .program-status.inactive {
        background: #f8d7da;
        color: #721c24;
    }
    </style>
    <?php
}
add_action('wp_head', 'wc_loyalty_add_inline_styles');

function wc_loyalty_add_inline_scripts() {
    ?>
    <script>
    jQuery(document).ready(function($) {
        // Apply points functionality
        $(document).on('click', '#apply-points, #apply-cart-points', function(e) {
            e.preventDefault();
            
            var pointsInput = $(this).siblings('input[type="number"]').first();
            if (pointsInput.length === 0) {
                pointsInput = $('#points-to-use, #cart-points-input');
            }
            
            var points = pointsInput.val();
            
            if (!points || points <= 0) {
                alert('Please enter a valid number of points');
                return;
            }
            
            $.post(wc_checkout_params.ajax_url, {
                action: 'apply_loyalty_points',
                points: points,
                nonce: '<?php echo wp_create_nonce("loyalty_points_nonce"); ?>'
            }, function(response) {
                if (response.success) {
                    alert('Points applied successfully! Refreshing page...');
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            }).fail(function() {
                alert('Error applying points. Please try again.');
            });
        });
        
        // Admin form submissions
        $('#discount-program-form').on('submit', function(e) {
            e.preventDefault();
            
            var formData = $(this).serialize();
            
            $.post(ajaxurl, {
                action: 'save_discount_program',
                ...Object.fromEntries(new URLSearchParams(formData))
            }, function(response) {
                alert('Program saved successfully!');
                location.reload();
            }).fail(function() {
                alert('Error saving program. Please try again.');
            });
        });
        
        $('#generate-coupons-form').on('submit', function(e) {
            e.preventDefault();
            
            var formData = $(this).serialize();
            
            $.post(ajaxurl, {
                action: 'generate_b2b_coupons',
                ...Object.fromEntries(new URLSearchParams(formData))
            }, function(response) {
                if (response.success) {
                    var coupons = response.data;
                    var html = '<h3>Generated Coupons:</h3><ul>';
                    
                    coupons.forEach(function(coupon) {
                        html += '<li><strong>' + coupon.code + '</strong> - ' + 
                               coupon.discount + (coupon.type === 'percent' ? '%' : ' VND') + 
                               ' off (Expires: ' + coupon.expires + ')</li>';
                    });
                    
                    html += '</ul>';
                    $('#coupons-tab').append('<div class="generated-coupons">' + html + '</div>');
                }
            }).fail(function() {
                alert('Error generating coupons. Please try again.');
            });
        });
    });
    </script>
    <?php
}
add_action('wp_footer', 'wc_loyalty_add_inline_scripts');

// Utility functions
function wc_loyalty_get_user_program_usage($user_id, $program_id) {
    global $wpdb;
    
    $stats_table = $wpdb->prefix . 'wc_loyalty_stats';
    
    return $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM {$stats_table} s
        JOIN {$wpdb->prefix}posts p ON s.order_id = p.ID
        JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id
        WHERE pm.meta_key = '_customer_user' 
        AND pm.meta_value = %d
        AND s.program_id = %d
    ", $user_id, $program_id));
}

function wc_loyalty_log_program_usage($program_id, $order_id, $product_id, $discount_amount, $points_used = 0) {
    global $wpdb;
    
    $stats_table = $wpdb->prefix . 'wc_loyalty_stats';
    
    $wpdb->insert($stats_table, array(
        'program_id' => $program_id,
        'order_id' => $order_id,
        'product_id' => $product_id,
        'discount_amount' => $discount_amount,
        'points_used' => $points_used,
        'date_created' => current_time('mysql')
    ));
}

// Hook to log program usage when orders are completed
add_action('woocommerce_order_status_completed', 'wc_loyalty_log_order_programs');

function wc_loyalty_log_order_programs($order_id) {
    $order = wc_get_order($order_id);
    
    // Log points usage if any
    $points_used = WC()->session->get('loyalty_points_to_use', 0);
    if ($points_used > 0) {
        $redemption_rate = get_option('wc_loyalty_redemption_rate', 1000);
        $discount_amount = $points_used * $redemption_rate;
        
        wc_loyalty_log_program_usage(0, $order_id, 0, $discount_amount, $points_used);
        
        // Actually deduct the points
        $loyalty_points = new WC_Loyalty_Points();
        $loyalty_points->use_points($order->get_user_id(), $points_used);
        
        // Clear session
        WC()->session->set('loyalty_points_to_use', 0);
    }
}

?>