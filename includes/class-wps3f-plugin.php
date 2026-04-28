<?php
/**
 * Main plugin composition root.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once WPS3F_PLUGIN_PATH . 'includes/class-wps3f-options.php';
require_once WPS3F_PLUGIN_PATH . 'includes/class-wps3f-logger.php';
require_once WPS3F_PLUGIN_PATH . 'includes/class-wps3f-key-builder.php';
require_once WPS3F_PLUGIN_PATH . 'includes/class-wps3f-s3-client.php';
require_once WPS3F_PLUGIN_PATH . 'includes/class-wps3f-offloader.php';
require_once WPS3F_PLUGIN_PATH . 'includes/class-wps3f-migration-service.php';
require_once WPS3F_PLUGIN_PATH . 'includes/class-wps3f-storage-backfill-service.php';
require_once WPS3F_PLUGIN_PATH . 'includes/class-wps3f-media-library.php';
require_once WPS3F_PLUGIN_PATH . 'includes/class-wps3f-admin.php';

class WPS3F_Plugin {
    /**
     * @var WPS3F_Options
     */
    private $options;

    /**
     * @var WPS3F_Logger
     */
    private $logger;

    /**
     * @var WPS3F_S3_Client
     */
    private $client;

    /**
     * @var WPS3F_Offloader
     */
    private $offloader;

    /**
     * @var WPS3F_Migration_Service
     */
    private $migration;

    /**
     * @var WPS3F_Storage_Backfill_Service
     */
    private $storage_backfill;

    /**
     * @var WPS3F_Media_Library
     */
    private $media_library;

    /**
     * @var WPS3F_Admin
     */
    private $admin;

    public function __construct() {
        $this->options   = new WPS3F_Options();
        $this->logger    = new WPS3F_Logger();
        $this->client    = new WPS3F_S3_Client($this->options, $this->logger);
        $this->offloader = new WPS3F_Offloader($this->options, $this->client, $this->logger);
        $this->migration = new WPS3F_Migration_Service($this->offloader, $this->logger);
        $this->storage_backfill = new WPS3F_Storage_Backfill_Service($this->offloader, $this->logger);
        $this->media_library    = new WPS3F_Media_Library($this->offloader);
        $this->admin     = new WPS3F_Admin(
            $this->options,
            $this->offloader,
            $this->client,
            $this->migration,
            $this->storage_backfill,
            $this->logger
        );

        $this->register_hooks();
    }

    /**
     * Plugin activation lifecycle hook.
     */
    public static function activate() {
        WPS3F_Options::ensure_option_exists();
    }

    /**
     * Plugin deactivation lifecycle hook.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook(WPS3F_Offloader::CRON_HOOK);
        wp_clear_scheduled_hook(WPS3F_Migration_Service::CRON_HOOK);
        wp_clear_scheduled_hook(WPS3F_Storage_Backfill_Service::CRON_HOOK);
    }

    /**
     * Wire all actions and filters.
     */
    private function register_hooks() {
        add_action('init', array($this, 'load_textdomain'));

        add_action('add_attachment', array($this->offloader, 'queue_attachment_offload'));
        add_filter('wp_generate_attachment_metadata', array($this->offloader, 'queue_attachment_offload_from_metadata'), 20, 2);
        add_action(WPS3F_Offloader::CRON_HOOK, array($this->offloader, 'process_offload_job'));
        add_action('delete_attachment', array($this->offloader, 'maybe_delete_remote_objects'));

        add_filter('wp_get_attachment_url', array($this->offloader, 'filter_attachment_url'), 20, 2);
        add_filter('wp_get_attachment_image_src', array($this->offloader, 'filter_attachment_image_src'), 20, 4);
        add_filter('wp_calculate_image_srcset', array($this->offloader, 'filter_image_srcset'), 20, 5);

        add_action(WPS3F_Migration_Service::CRON_HOOK, array($this->migration, 'run_batch'));
        add_action(WPS3F_Storage_Backfill_Service::CRON_HOOK, array($this->storage_backfill, 'run_batch'));

        add_action('admin_menu', array($this->admin, 'register_menu'));
        add_action('admin_init', array($this->admin, 'register_settings'));
        add_action('admin_notices', array($this->admin, 'render_admin_notices'));
        add_action('admin_post_wps3f_start_migration', array($this->admin, 'handle_start_migration'));
        add_action('admin_post_wps3f_stop_migration', array($this->admin, 'handle_stop_migration'));
        add_action('admin_post_wps3f_retry_failed_migration', array($this->admin, 'handle_retry_failed_migration'));
        add_action('admin_post_wps3f_retry_attachment', array($this->admin, 'handle_retry_single_attachment'));
        add_action('admin_post_wps3f_start_storage_backfill', array($this->admin, 'handle_start_storage_backfill'));
        add_action('admin_post_wps3f_stop_storage_backfill', array($this->admin, 'handle_stop_storage_backfill'));
        add_action('admin_post_wps3f_retry_failed_storage_backfill', array($this->admin, 'handle_retry_failed_storage_backfill'));
        add_action('admin_post_wps3f_clear_debug_log', array($this->admin, 'handle_clear_debug_log'));

        add_action('restrict_manage_posts', array($this->media_library, 'render_upload_storage_filter'));
        add_action('pre_get_posts', array($this->media_library, 'apply_upload_query_storage_filter'));
        add_filter('ajax_query_attachments_args', array($this->media_library, 'apply_modal_query_storage_filter'));
        add_filter('manage_upload_columns', array($this->media_library, 'add_storage_column'));
        add_action('manage_media_custom_column', array($this->media_library, 'render_storage_column'), 10, 2);
        add_action('admin_enqueue_scripts', array($this->media_library, 'enqueue_media_modal_filter_assets'));
        add_filter('wp_prepare_attachment_for_js', array($this->media_library, 'inject_attachment_js_meta'), 10, 3);
        add_action('admin_notices', array($this->media_library, 'render_upload_summary_notice'));
        add_action('wp_ajax_wps3f_poll_status', array($this->media_library, 'ajax_poll_status'));
    }

    /**
     * Load plugin translations from /languages directory.
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'wp-s3-files',
            false,
            dirname(plugin_basename(WPS3F_PLUGIN_FILE)) . '/languages'
        );
    }
}
