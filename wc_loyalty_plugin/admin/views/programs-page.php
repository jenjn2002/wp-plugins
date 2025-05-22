<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php echo esc_html__('Loyalty Programs', 'wc-loyalty'); ?></h1>
    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-loyalty-programs&action=add')); ?>" class="page-title-action">
        <?php echo esc_html__('Add New', 'wc-loyalty'); ?>
    </a>
    <hr class="wp-header-end">

    <?php
    if (isset($_GET['message']) && $_GET['message'] == '1'): ?>
        <div class="updated notice is-dismissible">
            <p><?php esc_html_e('Program updated successfully.', 'wc-loyalty'); ?></p>
        </div>
    <?php endif; ?>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('ID', 'wc-loyalty'); ?></th>
                <th scope="col"><?php esc_html_e('Name', 'wc-loyalty'); ?></th>
                <th scope="col"><?php esc_html_e('Description', 'wc-loyalty'); ?></th>
                <th scope="col"><?php esc_html_e('Points', 'wc-loyalty'); ?></th>
                <th scope="col"><?php esc_html_e('Status', 'wc-loyalty'); ?></th>
                <th scope="col"><?php esc_html_e('Actions', 'wc-loyalty'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($programs)): ?>
                <?php foreach ($programs as $program): ?>
                    <tr>
                        <td><?php echo esc_html($program['id']); ?></td>
                        <td><?php echo esc_html($program['name']); ?></td>
                        <td><?php echo esc_html($program['description']); ?></td>
                        <td><?php echo esc_html($program['points']); ?></td>
                        <td><?php echo esc_html($program['status']); ?></td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=wc-loyalty-programs&action=edit&id=' . $program['id'])); ?>">
                                <?php esc_html_e('Edit', 'wc-loyalty'); ?>
                            </a>
                            |
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=wc-loyalty-programs&action=delete&id=' . $program['id']), 'delete-program')); ?>" 
                               onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this program?', 'wc-loyalty'); ?>');">
                                <?php esc_html_e('Delete', 'wc-loyalty'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6"><?php esc_html_e('No programs found.', 'wc-loyalty'); ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>