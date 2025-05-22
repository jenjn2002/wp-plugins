<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1><?php echo esc_html__('Loyalty Program Reports', 'wc-loyalty'); ?></h1>

    <div class="loyalty-reports-summary">
        <div class="loyalty-report-card">
            <h3><?php esc_html_e('Total Points Issued', 'wc-loyalty'); ?></h3>
            <p class="loyalty-big-number"><?php echo esc_html(number_format($report_data['total_points'])); ?></p>
        </div>

        <div class="loyalty-report-card">
            <h3><?php esc_html_e('Total Active Users', 'wc-loyalty'); ?></h3>
            <p class="loyalty-big-number"><?php echo esc_html(number_format($report_data['total_users'])); ?></p>
        </div>
    </div>

    <h3><?php esc_html_e('Recent Activities', 'wc-loyalty'); ?></h3>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Date', 'wc-loyalty'); ?></th>
                <th scope="col"><?php esc_html_e('User', 'wc-loyalty'); ?></th>
                <th scope="col"><?php esc_html_e('Action', 'wc-loyalty'); ?></th>
                <th scope="col"><?php esc_html_e('Points', 'wc-loyalty'); ?></th>
                <th scope="col"><?php esc_html_e('Balance', 'wc-loyalty'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($report_data['recent_activities'])): ?>
                <?php foreach ($report_data['recent_activities'] as $activity): ?>
                    <tr>
                        <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($activity['created_at']))); ?></td>
                        <td><?php echo esc_html(get_user_by('id', $activity['user_id'])->display_name); ?></td>
                        <td><?php echo esc_html($activity['action']); ?></td>
                        <td><?php echo esc_html($activity['points']); ?></td>
                        <td><?php echo esc_html($activity['balance']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5"><?php esc_html_e('No recent activities found.', 'wc-loyalty'); ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>