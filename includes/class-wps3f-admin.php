<?php
/**
 * Admin settings UI, migration controls, and notices.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPS3F_Admin {
    /**
     * @var WPS3F_Options
     */
    private $options;

    /**
     * @var WPS3F_Offloader
     */
    private $offloader;

    /**
     * @var WPS3F_Migration_Service
     */
    private $migration;

    /**
     * @var WPS3F_Logger
     */
    private $logger;

    public function __construct(WPS3F_Options $options, WPS3F_Offloader $offloader, WPS3F_Migration_Service $migration, WPS3F_Logger $logger) {
        $this->options   = $options;
        $this->offloader = $offloader;
        $this->migration = $migration;
        $this->logger    = $logger;
    }

    /**
     * Register plugin options page.
     */
    public function register_menu() {
        add_options_page(
            'WP S3 Files',
            'WP S3 Files',
            'manage_options',
            'wps3f-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register settings.
     */
    public function register_settings() {
        register_setting(
            'wps3f_settings_group',
            WPS3F_Options::OPTION_NAME,
            array($this, 'sanitize_settings_payload')
        );
    }

    /**
     * Settings sanitize callback.
     *
     * @param mixed $input
     * @return array<string,mixed>
     */
    public function sanitize_settings_payload($input) {
        $current   = get_option(WPS3F_Options::OPTION_NAME, array());
        $sanitized = $this->options->sanitize_for_storage($input, is_array($current) ? $current : array());
        $this->options->clear_cache();

        add_settings_error('wps3f_messages', 'wps3f_saved', 'Settings saved.', 'updated');

        return $sanitized;
    }

    /**
     * Surface latest errors to administrators.
     */
    public function render_admin_notices() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!empty($_GET['page']) && 'wps3f-settings' === sanitize_text_field(wp_unslash((string) $_GET['page']))) {
            settings_errors('wps3f_messages');
        }

        $latest = $this->logger->get_recent_errors(1);
        if (empty($latest)) {
            return;
        }

        $entry = $latest[0];
        $message = isset($entry['message']) ? (string) $entry['message'] : 'Unknown error';
        $link = admin_url('options-general.php?page=wps3f-settings');

        printf(
            '<div class="notice notice-warning"><p><strong>WP S3 Files:</strong> %s <a href="%s">View details</a></p></div>',
            esc_html($message),
            esc_url($link)
        );
    }

    /**
     * Start migration action handler.
     */
    public function handle_start_migration() {
        $this->assert_admin_and_nonce('wps3f_migration_action');
        $this->migration->start();
        $this->redirect_with_notice('migration_started');
    }

    /**
     * Stop migration action handler.
     */
    public function handle_stop_migration() {
        $this->assert_admin_and_nonce('wps3f_migration_action');
        $this->migration->stop();
        $this->redirect_with_notice('migration_stopped');
    }

    /**
     * Retry migration failures.
     */
    public function handle_retry_failed_migration() {
        $this->assert_admin_and_nonce('wps3f_migration_action');
        $this->migration->retry_failed();
        $this->redirect_with_notice('migration_retry_scheduled');
    }

    /**
     * Retry a single attachment.
     */
    public function handle_retry_single_attachment() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $attachment_id = isset($_GET['attachment_id']) ? (int) $_GET['attachment_id'] : 0;
        check_admin_referer('wps3f_retry_attachment_' . $attachment_id);

        $this->offloader->retry_attachment_offload($attachment_id);

        $this->redirect_with_notice('attachment_retry_scheduled');
    }

    /**
     * Render settings and migration page.
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options      = $this->options->get_all();
        $masked_secret = $this->options->get_masked_secret_for_form();
        $state        = $this->migration->get_state();
        $recent       = $this->logger->get_recent_errors(20);

        $notices = array(
            'migration_started'         => 'Migration started.',
            'migration_stopped'         => 'Migration stopped.',
            'migration_retry_scheduled' => 'Retry for failed migration items has been scheduled.',
            'attachment_retry_scheduled'=> 'Attachment retry has been scheduled.',
        );

        if (!empty($_GET['wps3f_notice'])) {
            $notice_key = sanitize_text_field(wp_unslash((string) $_GET['wps3f_notice']));
            if (isset($notices[$notice_key])) {
                printf('<div class="notice notice-success"><p>%s</p></div>', esc_html($notices[$notice_key]));
            }
        }
        ?>
        <div class="wrap">
            <h1>WP S3 Files</h1>
            <p>Offload WordPress media to S3-compatible storage. Failed uploads stay local and can be retried later.</p>
            <p><em>Tip:</em> S3 credentials and update-checker values can be overridden via <code>wp-config.php</code> constants.</p>

            <form method="post" action="options.php">
                <?php settings_fields('wps3f_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">Enable Offload</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr(WPS3F_Options::OPTION_NAME); ?>[enabled]" value="1" <?php checked(1, (int) $options['enabled']); ?> />
                                    Enable automatic S3 offload
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wps3f_bucket">Bucket</label></th>
                            <td><input id="wps3f_bucket" class="regular-text" type="text" name="<?php echo esc_attr(WPS3F_Options::OPTION_NAME); ?>[bucket]" value="<?php echo esc_attr((string) $options['bucket']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wps3f_region">Region</label></th>
                            <td><input id="wps3f_region" class="regular-text" type="text" name="<?php echo esc_attr(WPS3F_Options::OPTION_NAME); ?>[region]" value="<?php echo esc_attr((string) $options['region']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wps3f_endpoint">Endpoint</label></th>
                            <td>
                                <input id="wps3f_endpoint" class="regular-text" type="url" name="<?php echo esc_attr(WPS3F_Options::OPTION_NAME); ?>[endpoint]" value="<?php echo esc_attr((string) $options['endpoint']); ?>" />
                                <p class="description">Leave empty for AWS default endpoint. For S3-compatible providers, set a full URL like https://minio.example.com.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wps3f_access_key">Access Key</label></th>
                            <td><input id="wps3f_access_key" class="regular-text" type="text" name="<?php echo esc_attr(WPS3F_Options::OPTION_NAME); ?>[access_key]" value="<?php echo esc_attr((string) $options['access_key']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wps3f_secret_key">Secret Key</label></th>
                            <td>
                                <input id="wps3f_secret_key" class="regular-text" type="password" name="<?php echo esc_attr(WPS3F_Options::OPTION_NAME); ?>[secret_key]" value="<?php echo esc_attr($masked_secret); ?>" autocomplete="new-password" />
                                <p class="description">Leave as ******** to keep the current secret.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wps3f_custom_domain">Custom CDN Domain</label></th>
                            <td>
                                <input id="wps3f_custom_domain" class="regular-text" type="text" name="<?php echo esc_attr(WPS3F_Options::OPTION_NAME); ?>[custom_domain]" value="<?php echo esc_attr((string) $options['custom_domain']); ?>" />
                                <p class="description">Optional. Example: https://cdn.example.com</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wps3f_path_prefix">Path Prefix</label></th>
                            <td><input id="wps3f_path_prefix" class="regular-text" type="text" name="<?php echo esc_attr(WPS3F_Options::OPTION_NAME); ?>[path_prefix]" value="<?php echo esc_attr((string) $options['path_prefix']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wps3f_max_size">Max Offload File Size (MB)</label></th>
                            <td>
                                <input id="wps3f_max_size" class="small-text" type="number" min="1" step="1" name="<?php echo esc_attr(WPS3F_Options::OPTION_NAME); ?>[max_offload_size_mb]" value="<?php echo esc_attr((string) $options['max_offload_size_mb']); ?>" />
                                <p class="description">Files larger than this limit remain local and are marked as failed for retry or manual handling.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Local Backup</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr(WPS3F_Options::OPTION_NAME); ?>[keep_local_backup]" value="1" <?php checked(1, (int) $options['keep_local_backup']); ?> />
                                    Keep local media files after successful offload
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Delete Sync</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr(WPS3F_Options::OPTION_NAME); ?>[delete_remote_on_delete]" value="1" <?php checked(1, (int) $options['delete_remote_on_delete']); ?> />
                                    Delete remote objects when attachment is deleted
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>

            <hr />

            <h2>Historical Migration</h2>
            <p>One-click migration for existing attachments. Runs in background via WP-Cron in batches of <?php echo esc_html((string) WPS3F_Migration_Service::DEFAULT_BATCH); ?>.</p>
            <p>
                <strong>Status:</strong>
                <?php echo !empty($state['running']) ? 'Running' : 'Idle'; ?> |
                <strong>Processed:</strong> <?php echo esc_html((string) (int) $state['processed']); ?> / <?php echo esc_html((string) (int) $state['total']); ?> |
                <strong>Failed:</strong> <?php echo esc_html((string) count((array) $state['failed_ids'])); ?>
            </p>

            <div style="display:flex; gap:12px; margin: 12px 0;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wps3f_migration_action'); ?>
                    <input type="hidden" name="action" value="wps3f_start_migration" />
                    <?php submit_button('Start Migration', 'secondary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wps3f_migration_action'); ?>
                    <input type="hidden" name="action" value="wps3f_stop_migration" />
                    <?php submit_button('Stop Migration', 'secondary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wps3f_migration_action'); ?>
                    <input type="hidden" name="action" value="wps3f_retry_failed_migration" />
                    <?php submit_button('Retry Failed', 'secondary', 'submit', false); ?>
                </form>
            </div>

            <?php if (!empty($state['failed_ids']) && is_array($state['failed_ids'])) : ?>
                <h3>Failed Attachment IDs</h3>
                <p>Click retry to requeue individual items.</p>
                <table class="widefat striped" style="max-width: 700px;">
                    <thead>
                        <tr>
                            <th>Attachment ID</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_slice($state['failed_ids'], 0, 50) as $attachment_id) : ?>
                        <tr>
                            <td><?php echo esc_html((string) (int) $attachment_id); ?></td>
                            <td>
                                <?php
                                $retry_url = wp_nonce_url(
                                    admin_url(
                                        'admin-post.php?action=wps3f_retry_attachment&attachment_id=' . (int) $attachment_id
                                    ),
                                    'wps3f_retry_attachment_' . (int) $attachment_id
                                );
                                ?>
                                <a class="button button-small" href="<?php echo esc_url($retry_url); ?>">Retry</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <hr />
            <h2>Recent Errors</h2>
            <?php if (empty($recent)) : ?>
                <p>No errors recorded.</p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Time (UTC)</th>
                            <th>Code</th>
                            <th>Message</th>
                            <th>Attachment</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recent as $entry) : ?>
                        <?php $attachment_id = isset($entry['context']['attachment_id']) ? (int) $entry['context']['attachment_id'] : 0; ?>
                        <tr>
                            <td><?php echo esc_html(isset($entry['time']) ? (string) $entry['time'] : ''); ?></td>
                            <td><code><?php echo esc_html(isset($entry['code']) ? (string) $entry['code'] : ''); ?></code></td>
                            <td><?php echo esc_html(isset($entry['message']) ? (string) $entry['message'] : ''); ?></td>
                            <td><?php echo $attachment_id > 0 ? esc_html((string) $attachment_id) : '-'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param string $nonce_action
     */
    private function assert_admin_and_nonce($nonce_action) {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer($nonce_action);
    }

    /**
     * @param string $notice
     */
    private function redirect_with_notice($notice) {
        $url = add_query_arg(
            array(
                'page'         => 'wps3f-settings',
                'wps3f_notice' => sanitize_key($notice),
            ),
            admin_url('options-general.php')
        );
        wp_safe_redirect($url);
        exit;
    }
}
