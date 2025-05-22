<?php
/**
 * Dashboard Management Class
 *
 * @package WC_Loyalty_System
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * WC_Loyalty_Dashboard Class
 */
class WC_Loyalty_Dashboard {
    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widgets'));
        add_action('admin_init', array($this, 'export_report'));
        add_action('wp_ajax_get_loyalty_chart_data', array($this, 'get_chart_data_ajax'));
        add_action('wp_ajax_get_loyalty_stats', array($this, 'get_stats_ajax'));
        add_filter('woocommerce_admin_reports', array($this, 'add_loyalty_reports_tab'));
    }

    /**
     * Add dashboard widgets
     */
    public function add_dashboard_widgets() {
        if (current_user_can('manage_woocommerce')) {
            wp_add_dashboard_widget(
                'wc_loyalty_stats',
                __('Loyalty Program Statistics', 'wc-loyalty'),
                array($this, 'display_dashboard_widget')
            );
        }
    }

    /**
     * Display dashboard widget
     */
    public function display_dashboard_widget() {
        global $wpdb;

        // Get last 7 days stats
        $points_table = $wpdb->prefix . 'wc_loyalty_points';
        $stats_table = $wpdb->prefix . 'wc_loyalty_stats';
        $current_date = current_time('mysql');

        $stats = $this->get_period_stats('7days');

        ?>
        <div class="loyalty-dashboard-widget">
            <div class="stats-header">
                <h4><?php _e('Last 7 Days Overview', 'wc-loyalty'); ?></h4>
                <select id="stats-period" class="stats-period-selector">
                    <option value="7days"><?php _e('Last 7 Days', 'wc-loyalty'); ?></option>
                    <option value="30days"><?php _e('Last 30 Days', 'wc-loyalty'); ?></option>
                    <option value="90days"><?php _e('Last 90 Days', 'wc-loyalty'); ?></option>
                </select>
            </div>

            <div class="stats-grid">
                <div class="stat-box points-earned">
                    <span class="stat-label"><?php _e('Points Earned', 'wc-loyalty'); ?></span>
                    <span class="stat-value"><?php echo number_format($stats['points_earned']); ?></span>
                    <?php $this->display_trend_indicator($stats['points_trend']); ?>
                </div>

                <div class="stat-box points-redeemed">
                    <span class="stat-label"><?php _e('Points Redeemed', 'wc-loyalty'); ?></span>
                    <span class="stat-value"><?php echo number_format($stats['points_redeemed']); ?></span>
                    <?php $this->display_trend_indicator($stats['redemption_trend']); ?>
                </div>

                <div class="stat-box total-discounts">
                    <span class="stat-label"><?php _e('Total Discounts', 'wc-loyalty'); ?></span>
                    <span class="stat-value"><?php echo wc_price($stats['total_discounts']); ?></span>
                    <?php $this->display_trend_indicator($stats['discount_trend']); ?>
                </div>

                <div class="stat-box active-users">
                    <span class="stat-label"><?php _e('Active Members', 'wc-loyalty'); ?></span>
                    <span class="stat-value"><?php echo number_format($stats['active_users']); ?></span>
                    <?php $this->display_trend_indicator($stats['users_trend']); ?>
                </div>
            </div>

            <div class="chart-container">
                <canvas id="loyaltyChart"></canvas>
            </div>

            <div class="widget-footer">
                <a href="<?php echo admin_url('admin.php?page=wc-loyalty-programs&tab=reports'); ?>" class="button button-secondary">
                    <?php _e('View Detailed Reports', 'wc-loyalty'); ?>
                </a>
                <button type="button" class="button button-link export-report">
                    <?php _e('Export Data', 'wc-loyalty'); ?>
                </button>
            </div>
        </div>
        <?php
        $this->enqueue_dashboard_scripts();
    }

    /**
     * Get statistics for a specific period
     * 
     * @param string $period Period to get stats for (7days, 30days, 90days)
     * @return array Statistics data
     */
    private function get_period_stats($period) {
        global $wpdb;
        
        $points_table = $wpdb->prefix . 'wc_loyalty_points';
        $stats_table = $wpdb->prefix . 'wc_loyalty_stats';
        $days = $this->get_period_days($period);
        
        $current_date = current_time('mysql');
        $comparison_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        // Current period stats
        $current_stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COALESCE(SUM(p.earned_points), 0) as points_earned,
                COALESCE(SUM(p.used_points), 0) as points_redeemed,
                COALESCE(SUM(s.discount_amount), 0) as total_discounts,
                COUNT(DISTINCT p.user_id) as active_users
            FROM {$points_table} p
            LEFT JOIN {$stats_table} s ON s.date_created >= %s
            WHERE p.last_updated >= %s
        ", $comparison_date, $comparison_date));

        // Previous period stats for trend calculation
        $previous_start = date('Y-m-d H:i:s', strtotime("-" . ($days * 2) . " days"));
        $previous_stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COALESCE(SUM(p.earned_points), 0) as points_earned,
                COALESCE(SUM(p.used_points), 0) as points_redeemed,
                COALESCE(SUM(s.discount_amount), 0) as total_discounts,
                COUNT(DISTINCT p.user_id) as active_users
            FROM {$points_table} p
            LEFT JOIN {$stats_table} s ON s.date_created >= %s AND s.date_created < %s
            WHERE p.last_updated >= %s AND p.last_updated < %s
        ", $previous_start, $comparison_date, $previous_start, $comparison_date));

        return array(
            'points_earned' => (int)$current_stats->points_earned,
            'points_redeemed' => (int)$current_stats->points_redeemed,
            'total_discounts' => (float)$current_stats->total_discounts,
            'active_users' => (int)$current_stats->active_users,
            'points_trend' => $this->calculate_trend($current_stats->points_earned, $previous_stats->points_earned),
            'redemption_trend' => $this->calculate_trend($current_stats->points_redeemed, $previous_stats->points_redeemed),
            'discount_trend' => $this->calculate_trend($current_stats->total_discounts, $previous_stats->total_discounts),
            'users_trend' => $this->calculate_trend($current_stats->active_users, $previous_stats->active_users)
        );
    }

    /**
     * Calculate trend percentage
     * 
     * @param float $current Current value
     * @param float $previous Previous value
     * @return float Trend percentage
     */
    private function calculate_trend($current, $previous) {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }
        return (($current - $previous) / $previous) * 100;
    }

    /**
     * Display trend indicator
     * 
     * @param float $trend Trend percentage
     */
    private function display_trend_indicator($trend) {
        $class = $trend >= 0 ? 'trend-up' : 'trend-down';
        $icon = $trend >= 0 ? '↑' : '↓';
        ?>
        <span class="trend-indicator <?php echo esc_attr($class); ?>">
            <?php echo $icon . ' ' . abs(round($trend, 1)) . '%'; ?>
        </span>
        <?php
    }

    /**
     * Get chart data via AJAX
     */
    public function get_chart_data_ajax() {
        check_ajax_referer('loyalty_dashboard', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'wc-loyalty'));
        }

        $period = isset($_POST['period']) ? sanitize_text_field($_POST['period']) : '7days';
        $days = $this->get_period_days($period);

        global $wpdb;
        $stats_table = $wpdb->prefix . 'wc_loyalty_stats';
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                DATE(date_created) as date,
                SUM(discount_amount) as discount_amount,
                SUM(points_used) as points_used
            FROM {$stats_table}
            WHERE date_created >= DATE_SUB(NOW(), INTERVAL %d DAY)
            GROUP BY DATE(date_created)
            ORDER BY date ASC
        ", $days));

        $data = array(
            'labels' => array(),
            'discounts' => array(),
            'points' => array()
        );

        foreach ($results as $row) {
            $data['labels'][] = date_i18n(get_option('date_format'), strtotime($row->date));
            $data['discounts'][] = round($row->discount_amount, 2);
            $data['points'][] = (int)$row->points_used;
        }

        wp_send_json_success($data);
    }

    /**
     * Get stats via AJAX
     */
    public function get_stats_ajax() {
        check_ajax_referer('loyalty_dashboard', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'wc-loyalty'));
        }

        $period = isset($_POST['period']) ? sanitize_text_field($_POST['period']) : '7days';
        $stats = $this->get_period_stats($period);

        wp_send_json_success($stats);
    }

    /**
     * Export report
     */
    public function export_report() {
        if (!isset($_GET['wc_loyalty_export']) || !current_user_can('manage_woocommerce')) {
            return;
        }

        $period = isset($_GET['period']) ? sanitize_text_field($_GET['period']) : '30days';
        $days = $this->get_period_days($period);

        global $wpdb;
        $stats_table = $wpdb->prefix . 'wc_loyalty_stats';

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                DATE(date_created) as date,
                COUNT(DISTINCT order_id) as orders,
                SUM(discount_amount) as discount_amount,
                SUM(points_used) as points_used
            FROM {$stats_table}
            WHERE date_created >= DATE_SUB(NOW(), INTERVAL %d DAY)
            GROUP BY DATE(date_created)
            ORDER BY date ASC
        ", $days));

        $filename = 'loyalty-report-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');
        fputcsv($output, array(
            __('Date', 'wc-loyalty'),
            __('Orders', 'wc-loyalty'),
            __('Discounts', 'wc-loyalty'),
            __('Points Used', 'wc-loyalty')
        ));

        foreach ($results as $row) {
            fputcsv($output, array(
                $row->date,
                $row->orders,
                $row->discount_amount,
                $row->points_used
            ));
        }

        fclose($output);
        exit;
    }

    /**
     * Add loyalty reports tab
     * 
     * @param array $reports Existing reports
     * @return array Modified reports array
     */
    public function add_loyalty_reports_tab($reports) {
        $reports['loyalty'] = array(
            'title' => __('Loyalty Program', 'wc-loyalty'),
            'reports' => array(
                'overview' => array(
                    'title' => __('Overview', 'wc-loyalty'),
                    'description' => __('View loyalty program performance metrics.', 'wc-loyalty'),
                    'callback' => array($this, 'get_overview_report')
                ),
                'points' => array(
                    'title' => __('Points Activity', 'wc-loyalty'),
                    'description' => __('Analyze points earning and redemption patterns.', 'wc-loyalty'),
                    'callback' => array($this, 'get_points_report')
                )
            )
        );

        return $reports;
    }

    /**
     * Get period days
     * 
     * @param string $period Period identifier
     * @return int Number of days
     */
    private function get_period_days($period) {
        switch ($period) {
            case '30days':
                return 30;
            case '90days':
                return 90;
            case '7days':
            default:
                return 7;
        }
    }

    /**
     * Enqueue dashboard scripts
     */
    private function enqueue_dashboard_scripts() {
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '3.7.0', true);
        
        wp_enqueue_script(
            'wc-loyalty-dashboard',
            WC_LOYALTY_PLUGIN_URL . 'assets/js/dashboard.js',
            array('jquery', 'chart-js'),
            WC_LOYALTY_VERSION,
            true
        );

        wp_localize_script('wc-loyalty-dashboard', 'wcLoyaltyDashboard', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('loyalty_dashboard'),
            'i18n' => array(
                'points' => __('Points', 'wc-loyalty'),
                'discounts' => __('Discounts', 'wc-loyalty'),
                'loading' => __('Loading...', 'wc-loyalty'),
                'currency' => get_woocommerce_currency_symbol()
            )
        ));
    }
}