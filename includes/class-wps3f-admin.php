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
     * @var WPS3F_S3_Client
     */
    private $s3_client;

    /**
     * @var WPS3F_Migration_Service
     */
    private $migration;

    /**
     * @var WPS3F_Storage_Backfill_Service
     */
    private $storage_backfill;

    /**
     * @var WPS3F_Logger
     */
    private $logger;

    public function __construct(
        WPS3F_Options $options,
        WPS3F_Offloader $offloader,
        WPS3F_S3_Client $s3_client,
        WPS3F_Migration_Service $migration,
        WPS3F_Storage_Backfill_Service $storage_backfill,
        WPS3F_Logger $logger
    ) {
        $this->options         = $options;
        $this->offloader       = $offloader;
        $this->s3_client       = $s3_client;
        $this->migration       = $migration;
        $this->storage_backfill = $storage_backfill;
        $this->logger          = $logger;
    }

    /**
     * Register plugin options page.
     */
    public function register_menu() {
        add_options_page(
            __('WP S3 Files', 'wp-s3-files'),
            __('WP S3 Files', 'wp-s3-files'),
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
        $this->logger->clear_debug_cache();

        add_settings_error('wps3f_messages', 'wps3f_saved', __('Settings saved.', 'wp-s3-files'), 'updated');

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
        $message = isset($entry['message']) ? (string) $entry['message'] : __('Unknown error', 'wp-s3-files');
        $link = admin_url('options-general.php?page=wps3f-settings');

        printf(
            '<div class="notice notice-warning"><p><strong>%s:</strong> %s <a href="%s">%s</a></p></div>',
            esc_html__('WP S3 Files', 'wp-s3-files'),
            esc_html($message),
            esc_url($link),
            esc_html__('View details', 'wp-s3-files')
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
     * Start storage classification backfill.
     */
    public function handle_start_storage_backfill() {
        $this->assert_admin_and_nonce('wps3f_storage_backfill_action');
        $this->storage_backfill->start();
        $this->redirect_with_notice('storage_backfill_started');
    }

    /**
     * Stop storage classification backfill.
     */
    public function handle_stop_storage_backfill() {
        $this->assert_admin_and_nonce('wps3f_storage_backfill_action');
        $this->storage_backfill->stop();
        $this->redirect_with_notice('storage_backfill_stopped');
    }

    /**
     * Retry failed storage classification items.
     */
    public function handle_retry_failed_storage_backfill() {
        $this->assert_admin_and_nonce('wps3f_storage_backfill_action');
        $this->storage_backfill->retry_failed();
        $this->redirect_with_notice('storage_backfill_retry_done');
    }

    /**
     * Retry a single attachment.
     */
    public function handle_retry_single_attachment() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'wp-s3-files'));
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
        $storage_backfill_state = $this->storage_backfill->get_state();
        $recent       = $this->logger->get_recent_errors(20);

        $notices = array(
            'migration_started'         => __('Migration started.', 'wp-s3-files'),
            'migration_stopped'         => __('Migration stopped.', 'wp-s3-files'),
            'migration_retry_scheduled' => __('Retry for failed migration items has been scheduled.', 'wp-s3-files'),
            'attachment_retry_scheduled'=> __('Attachment retry has been scheduled.', 'wp-s3-files'),
            'storage_backfill_started'  => __('Storage classification backfill started.', 'wp-s3-files'),
            'storage_backfill_stopped'  => __('Storage classification backfill stopped.', 'wp-s3-files'),
            'storage_backfill_retry_done' => __('Storage classification retry finished.', 'wp-s3-files'),
        );

        if (!empty($_GET['wps3f_notice'])) {
            $notice_key = sanitize_text_field(wp_unslash((string) $_GET['wps3f_notice']));
            if (isset($notices[$notice_key])) {
                printf('<div class="notice notice-success"><p>%s</p></div>', esc_html($notices[$notice_key]));
            }
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('WP S3 Files', 'wp-s3-files'); ?></h1>
            <p><?php echo esc_html__('Offload WordPress media to S3-compatible storage. Failed uploads stay local and can be retried later.', 'wp-s3-files'); ?></p>
            <p><em><?php echo esc_html__('Tip:', 'wp-s3-files'); ?></em> <?php echo esc_html__('Admin-saved settings are stored in DB and take priority. wp-config.php constants are only fallback values for missing DB fields.', 'wp-s3-files'); ?></p>

            <form method="post" action="options.php">
                <?php settings_fields('wps3f_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Enable Offload', 'wp-s3-files'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr(WPS3F_Options::OPTION_NAME); ?>[enabled]" value="1" <?php checked(1, (int) $options['enabled']); ?> />
                                    <?php echo esc_html__('Enable automatic S3 offload', 'wp-s3-files'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wps3f_bucket"><?php echo esc_html__('Bucket', 'wp-s3-files'); ?></label></th>
                            <td><input id="wps3f_bucket" class="regular-text" type="text" name="<?php echo esc_attr(WPS3F_Options::OPTION_NAME); ?>[bucket]" value="<?php echo esc_attr((string) $options['bucket']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wps3f_region"><?php echo esc_html__('Region', 'wp-s3-files'); ?></label></th>
                            <td><input id="wps3f_region" class="regular-text" type="text" name="<?php echo esc_attr(WPS3F_Options::OPTION_NAME); ?>[region]" value="<?php echo esc_attr((string) $options['region']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wps3f_endpoint"><?php echo esc_html__('Endpoint', 'wp-s3-files'); ?></label></th>
                            <td>
                                <input id="wps3f_endpoint" class="regular-text" type="url" name="<?php echo esc_attr(WPS3F_Options::OPTION_NAME); ?>[endpoint]" value="<?php echo esc_attr((string) $options['endpoint']); ?>" />
                                <p class="description"><?php echo esc_html__('Leave empty for AWS default endpoint. Required when Region is empty. For S3-compatible providers, set a full URL like https://minio.example.com.', 'wp-s3-files'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wps3f_access_key"><?php echo esc_html__('Access Key', 'wp-s3-files'); ?></label></th>
                            <td><input id="wps3f_access_key" class="regular-text" type="text" name="<?php echo esc_attr(WPS3F_Options::OPTION_NAME); ?>[access_key]" value="<?php echo esc_attr((string) $options['access_key']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wps3f_secret_key"><?php echo esc_html__('Secret Key', 'wp-s3-files'); ?></label></th>
                            <td>
                                <input id="wps3f_secret_key" class="regular-text" type="password" name="<?php echo esc_attr(WPS3F_Options::OPTION_NAME); ?>[secret_key]" value="<?php echo esc_attr($masked_secret); ?>" autocomplete="new-password" />
                                <p class="description"><?php echo esc_html__('Leave as ******** to keep the current secret.', 'wp-s3-files'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wps3f_custom_domain"><?php echo esc_html__('Custom CDN Domain', 'wp-s3-files'); ?></label></th>
                            <td>
                                <input id="wps3f_custom_domain" class="regular-text" type="text" name="<?php echo esc_attr(WPS3F_Options::OPTION_NAME); ?>[custom_domain]" value="<?php echo esc_attr((string) $options['custom_domain']); ?>" />
                                <p class="description"><?php echo esc_html__('Optional. Example: https://cdn.example.com', 'wp-s3-files'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wps3f_path_prefix"><?php echo esc_html__('Path Prefix', 'wp-s3-files'); ?></label></th>
                            <td><input id="wps3f_path_prefix" class="regular-text" type="text" name="<?php echo esc_attr(WPS3F_Options::OPTION_NAME); ?>[path_prefix]" value="<?php echo esc_attr((string) $options['path_prefix']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wps3f_max_size"><?php echo esc_html__('Max Offload File Size (MB)', 'wp-s3-files'); ?></label></th>
                            <td>
                                <input id="wps3f_max_size" class="small-text" type="number" min="1" step="1" name="<?php echo esc_attr(WPS3F_Options::OPTION_NAME); ?>[max_offload_size_mb]" value="<?php echo esc_attr((string) $options['max_offload_size_mb']); ?>" />
                                <p class="description"><?php echo esc_html__('Files larger than this limit remain local and are marked as failed for retry or manual handling.', 'wp-s3-files'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Local Backup', 'wp-s3-files'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr(WPS3F_Options::OPTION_NAME); ?>[keep_local_backup]" value="1" <?php checked(1, (int) $options['keep_local_backup']); ?> />
                                    <?php echo esc_html__('Keep local media files after successful offload', 'wp-s3-files'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Delete Sync', 'wp-s3-files'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr(WPS3F_Options::OPTION_NAME); ?>[delete_remote_on_delete]" value="1" <?php checked(1, (int) $options['delete_remote_on_delete']); ?> />
                                    <?php echo esc_html__('Delete remote objects when attachment is deleted', 'wp-s3-files'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Offload Mode', 'wp-s3-files'); ?></th>
                            <td>
                                <select id="wps3f_sync_mode" name="<?php echo esc_attr(WPS3F_Options::OPTION_NAME); ?>[sync_mode]">
                                    <option value="sync_first" <?php selected('sync_first', (string) $options['sync_mode']); ?>>
                                        <?php echo esc_html__('Sync first (recommended)', 'wp-s3-files'); ?>
                                    </option>
                                    <option value="async_only" <?php selected('async_only', (string) $options['sync_mode']); ?>>
                                        <?php echo esc_html__('Async only', 'wp-s3-files'); ?>
                                    </option>
                                </select>
                                <p class="description">
                                    <?php echo esc_html__('Sync first: upload to S3 immediately after the file is created, fall back to background cron on failure. Async only: always defer to WP-Cron (lower upload latency in the editor, but files are not on S3 until cron runs).', 'wp-s3-files'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Debug Mode', 'wp-s3-files'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr(WPS3F_Options::OPTION_NAME); ?>[debug]" value="1" <?php checked(1, (int) $options['debug']); ?> />
                                    <?php echo esc_html__('Enable debug logging (S3 requests/responses, offload pipeline, URL filters)', 'wp-s3-files'); ?>
                                </label>
                                <p class="description"><?php echo esc_html__('When enabled, detailed operation logs are written to the debug log and visible in the Debug Panel below. Increases storage usage.', 'wp-s3-files'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(__('Save Settings', 'wp-s3-files')); ?>
            </form>

            <hr />

            <h2><?php echo esc_html__('Historical Migration', 'wp-s3-files'); ?></h2>
            <p><?php echo esc_html__('One-click migration for existing attachments. Runs in background via WP-Cron in batches of', 'wp-s3-files'); ?> <?php echo esc_html((string) WPS3F_Migration_Service::DEFAULT_BATCH); ?>.</p>
            <p>
                <strong><?php echo esc_html__('Status:', 'wp-s3-files'); ?></strong>
                <?php echo !empty($state['running']) ? esc_html__('Running', 'wp-s3-files') : esc_html__('Idle', 'wp-s3-files'); ?> |
                <strong><?php echo esc_html__('Processed:', 'wp-s3-files'); ?></strong> <?php echo esc_html((string) (int) $state['processed']); ?> / <?php echo esc_html((string) (int) $state['total']); ?> |
                <strong><?php echo esc_html__('Failed:', 'wp-s3-files'); ?></strong> <?php echo esc_html((string) count((array) $state['failed_ids'])); ?>
            </p>

            <div style="display:flex; gap:12px; margin: 12px 0;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wps3f_migration_action'); ?>
                    <input type="hidden" name="action" value="wps3f_start_migration" />
                    <?php submit_button(__('Start Migration', 'wp-s3-files'), 'secondary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wps3f_migration_action'); ?>
                    <input type="hidden" name="action" value="wps3f_stop_migration" />
                    <?php submit_button(__('Stop Migration', 'wp-s3-files'), 'secondary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wps3f_migration_action'); ?>
                    <input type="hidden" name="action" value="wps3f_retry_failed_migration" />
                    <?php submit_button(__('Retry Failed', 'wp-s3-files'), 'secondary', 'submit', false); ?>
                </form>
            </div>

            <hr />

            <h2><?php echo esc_html__('Storage Classification Backfill', 'wp-s3-files'); ?></h2>
            <p><?php echo esc_html__('Backfill S3/Local classification flags for existing attachments in background batches.', 'wp-s3-files'); ?></p>
            <p>
                <strong><?php echo esc_html__('Status:', 'wp-s3-files'); ?></strong>
                <?php echo !empty($storage_backfill_state['running']) ? esc_html__('Running', 'wp-s3-files') : esc_html__('Idle', 'wp-s3-files'); ?> |
                <strong><?php echo esc_html__('Processed:', 'wp-s3-files'); ?></strong> <?php echo esc_html((string) (int) $storage_backfill_state['processed']); ?> / <?php echo esc_html((string) (int) $storage_backfill_state['total']); ?> |
                <strong><?php echo esc_html__('Failed:', 'wp-s3-files'); ?></strong> <?php echo esc_html((string) count((array) $storage_backfill_state['failed_ids'])); ?>
            </p>

            <div style="display:flex; gap:12px; margin: 12px 0;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wps3f_storage_backfill_action'); ?>
                    <input type="hidden" name="action" value="wps3f_start_storage_backfill" />
                    <?php submit_button(__('Start Backfill', 'wp-s3-files'), 'secondary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wps3f_storage_backfill_action'); ?>
                    <input type="hidden" name="action" value="wps3f_stop_storage_backfill" />
                    <?php submit_button(__('Stop Backfill', 'wp-s3-files'), 'secondary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wps3f_storage_backfill_action'); ?>
                    <input type="hidden" name="action" value="wps3f_retry_failed_storage_backfill" />
                    <?php submit_button(__('Retry Failed Backfill', 'wp-s3-files'), 'secondary', 'submit', false); ?>
                </form>
            </div>

            <?php if (!empty($state['failed_ids']) && is_array($state['failed_ids'])) : ?>
                <h3><?php echo esc_html__('Failed Attachment IDs', 'wp-s3-files'); ?></h3>
                <p><?php echo esc_html__('Click retry to requeue individual items.', 'wp-s3-files'); ?></p>
                <table class="widefat striped" style="max-width: 700px;">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Attachment ID', 'wp-s3-files'); ?></th>
                            <th><?php echo esc_html__('Action', 'wp-s3-files'); ?></th>
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
                                <a class="button button-small" href="<?php echo esc_url($retry_url); ?>"><?php echo esc_html__('Retry', 'wp-s3-files'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (!empty($storage_backfill_state['failed_ids']) && is_array($storage_backfill_state['failed_ids'])) : ?>
                <h3><?php echo esc_html__('Backfill Failed Attachment IDs', 'wp-s3-files'); ?></h3>
                <table class="widefat striped" style="max-width: 700px;">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Attachment ID', 'wp-s3-files'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_slice($storage_backfill_state['failed_ids'], 0, 50) as $attachment_id) : ?>
                        <tr>
                            <td><?php echo esc_html((string) (int) $attachment_id); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <hr />
            <h2><?php echo esc_html__('Recent Errors', 'wp-s3-files'); ?></h2>
            <?php if (empty($recent)) : ?>
                <p><?php echo esc_html__('No errors recorded.', 'wp-s3-files'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Time (UTC)', 'wp-s3-files'); ?></th>
                            <th><?php echo esc_html__('Code', 'wp-s3-files'); ?></th>
                            <th><?php echo esc_html__('Message', 'wp-s3-files'); ?></th>
                            <th><?php echo esc_html__('Attachment', 'wp-s3-files'); ?></th>
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

            <hr />
            <h2><?php echo esc_html__('Debug Panel', 'wp-s3-files'); ?></h2>

            <h3><?php echo esc_html__('Configuration Snapshot', 'wp-s3-files'); ?></h3>
            <table class="widefat striped" style="max-width: 700px;">
                <tbody>
                <?php
                $snap_fields = array(
                    'enabled'                 => __('Enabled', 'wp-s3-files'),
                    'bucket'                  => __('Bucket', 'wp-s3-files'),
                    'region'                  => __('Region', 'wp-s3-files'),
                    'endpoint'                => __('Endpoint', 'wp-s3-files'),
                    'access_key'              => __('Access Key', 'wp-s3-files'),
                    'custom_domain'           => __('Custom Domain', 'wp-s3-files'),
                    'path_prefix'             => __('Path Prefix', 'wp-s3-files'),
                    'max_offload_size_mb'     => __('Max Size (MB)', 'wp-s3-files'),
                    'keep_local_backup'       => __('Keep Local', 'wp-s3-files'),
                    'delete_remote_on_delete' => __('Delete Sync', 'wp-s3-files'),
                    'debug'                   => __('Debug Mode', 'wp-s3-files'),
                );
                foreach ($snap_fields as $key => $label) :
                    $val = isset($options[$key]) ? $options[$key] : '-';
                    if ('access_key' === $key && '' !== (string) $val) {
                        $val = substr((string) $val, 0, 4) . '...';
                    }
                    if ('secret_key' === $key) {
                        $val = $val ? '••••' : '(empty)';
                    }
                    ?>
                    <tr>
                        <td style="width:200px;"><strong><?php echo esc_html($label); ?></strong></td>
                        <td><?php echo esc_html((string) $val); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h3><?php echo esc_html__('WP-Cron Status', 'wp-s3-files'); ?></h3>
            <?php
            $cron_hooks = array(
                WPS3F_Offloader::CRON_HOOK                => __('Offload queue', 'wp-s3-files'),
                WPS3F_Migration_Service::CRON_HOOK        => __('Migration batch', 'wp-s3-files'),
                WPS3F_Storage_Backfill_Service::CRON_HOOK  => __('Storage backfill', 'wp-s3-files'),
            );
            $any_cron = false;
            ?>
            <table class="widefat striped" style="max-width: 700px;">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Hook', 'wp-s3-files'); ?></th>
                        <th><?php echo esc_html__('Label', 'wp-s3-files'); ?></th>
                        <th><?php echo esc_html__('Next Run', 'wp-s3-files'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($cron_hooks as $hook => $label) :
                    $next = wp_next_scheduled($hook);
                    $any_cron = $any_cron || (bool) $next;
                    ?>
                    <tr>
                        <td><code><?php echo esc_html($hook); ?></code></td>
                        <td><?php echo esc_html($label); ?></td>
                        <td><?php echo $next ? esc_html(gmdate('Y-m-d H:i:s', (int) $next) . ' UTC') : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h3><?php echo esc_html__('S3 Connection Test', 'wp-s3-files'); ?></h3>
            <?php
            $test_result = null;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if (!empty($_GET['wps3f_test_connection'])) {
                $test_result = $this->s3_client->test_connection();
            }
            ?>
            <p>
                <a class="button" href="<?php echo esc_url(add_query_arg('wps3f_test_connection', '1', admin_url('options-general.php?page=wps3f-settings'))); ?>">
                    <?php echo esc_html__('Test Connection', 'wp-s3-files'); ?>
                </a>
            </p>
            <?php if (null !== $test_result) : ?>
                <?php if (true === $test_result) : ?>
                    <div class="notice notice-success inline"><p><?php echo esc_html__('Connection test passed.', 'wp-s3-files'); ?></p></div>
                <?php else : ?>
                    <div class="notice notice-error inline"><p><strong><?php echo esc_html__('Connection test failed:', 'wp-s3-files'); ?></strong> <?php echo esc_html($test_result->get_error_message()); ?></p></div>
                <?php endif; ?>
            <?php endif; ?>

            <h3><?php echo esc_html__('Debug Log', 'wp-s3-files'); ?></h3>
            <?php
            $debug_logs = $this->logger->get_recent_debug(100);
            if (!empty($debug_logs)) :
                ?>
                <p>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wps3f_clear_debug_log'), 'wps3f_clear_debug_log')); ?>">
                        <?php echo esc_html__('Clear Debug Log', 'wp-s3-files'); ?>
                    </a>
                </p>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width:170px;"><?php echo esc_html__('Time (UTC)', 'wp-s3-files'); ?></th>
                            <th><?php echo esc_html__('Message', 'wp-s3-files'); ?></th>
                            <th><?php echo esc_html__('Context', 'wp-s3-files'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($debug_logs as $entry) : ?>
                        <tr>
                            <td style="white-space:nowrap;"><?php echo esc_html(isset($entry['time']) ? (string) $entry['time'] : ''); ?></td>
                            <td><?php echo esc_html(isset($entry['message']) ? (string) $entry['message'] : ''); ?></td>
                            <td><pre style="margin:0;white-space:pre-wrap;font-size:11px;"><?php echo esc_html(isset($entry['context']) ? wp_json_encode($entry['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : ''); ?></pre></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><?php echo esc_html__('No debug log entries. Enable debug mode and perform some operations to generate logs.', 'wp-s3-files'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Clear the debug log.
     */
    public function handle_clear_debug_log() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'wp-s3-files'));
        }
        check_admin_referer('wps3f_clear_debug_log');

        $this->logger->clear_debug_log();

        wp_safe_redirect(add_query_arg(
            array('page' => 'wps3f-settings'),
            admin_url('options-general.php')
        ));
        exit;
    }

    /**
     * @param string $nonce_action
     */
    private function assert_admin_and_nonce($nonce_action) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'wp-s3-files'));
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
