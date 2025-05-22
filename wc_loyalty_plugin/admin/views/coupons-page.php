<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php echo esc_html__('Loyalty Coupons', 'wc-loyalty'); ?></h1>
    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-loyalty-coupons&action=add')); ?>" class="page-title-action">
        <?php echo esc_html__('Add New', 'wc-loyalty'); ?>
    </a>
    <hr class="wp-header-end">

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('ID', 'wc-loyalty'); ?></th>
                <th scope="col"><?php esc_html_e('Code', 'wc-loyalty'); ?></th>
                <th scope="col"><?php esc_html_e('Discount', 'wc-loyalty'); ?></th>
                <th scope="col"><?php esc_html_e('Points Required', 'wc-loyalty'); ?></th>
                <th scope="col"><?php esc_html_e('Usage / Limit', 'wc-loyalty'); ?></th>
                <th scope="col"><?php esc_html_e('Expiry Date', 'wc-loyalty'); ?></th>
                <th scope="col"><?php esc_html_e('Actions', 'wc-loyalty'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($coupons)): ?>
                <?php foreach ($coupons as $coupon): ?>
                    <tr>
                        <td><?php echo esc_html($coupon['id']); ?></td>
                        <td><?php echo esc_html($coupon['code']); ?></td>
                        <td><?php echo esc_html($coupon['discount_amount'] . ' ' . $coupon['discount_type']); ?></td>
                        <td><?php echo esc_html($coupon['points_required']); ?></td>
                        <td><?php echo esc_html($coupon['usage_count'] . ' / ' . $coupon['usage_limit']); ?></td>
                        <td><?php echo esc_html($coupon['expiry_date']); ?></td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=wc-loyalty-coupons&action=edit&id=' . $coupon['id'])); ?>">
                                <?php esc_html_e('Edit', 'wc-loyalty'); ?>
                            </a>
                            |
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=wc-loyalty-coupons&action=delete&id=' . $coupon['id']), 'delete-coupon')); ?>" 
                               onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this coupon?', 'wc-loyalty'); ?>');">
                                <?php esc_html_e('Delete', 'wc-loyalty'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7"><?php esc_html_e('No coupons found.', 'wc-loyalty'); ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>