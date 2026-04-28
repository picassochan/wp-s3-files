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
require_once WPS3F_PLUGIN_PATH . 'includes/class-wps3f-admin.php';
require_once WPS3F_PLUGIN_PATH . 'includes/class-wps3f-updater.php';

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
     * @var WPS3F_Admin
     */
    private $admin;

    /**
     * @var WPS3F_Updater
     */
    private $updater;

    public function __construct() {
        $this->options   = new WPS3F_Options();
        $this->logger    = new WPS3F_Logger();
        $this->client    = new WPS3F_S3_Client($this->options, $this->logger);
        $this->offloader = new WPS3F_Offloader($this->options, $this->client, $this->logger);
        $this->migration = new WPS3F_Migration_Service($this->offloader, $this->logger);
        $this->admin     = new WPS3F_Admin($this->options, $this->offloader, $this->migration, $this->logger);
        $this->updater   = new WPS3F_Updater($this->logger);

        $this->register_hooks();
        $this->updater->init();
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
    }

    /**
     * Wire all actions and filters.
     */
    private function register_hooks() {
        add_action('add_attachment', array($this->offloader, 'queue_attachment_offload'));
        add_filter('wp_generate_attachment_metadata', array($this->offloader, 'queue_attachment_offload_from_metadata'), 20, 2);
        add_action(WPS3F_Offloader::CRON_HOOK, array($this->offloader, 'process_offload_job'));
        add_action('delete_attachment', array($this->offloader, 'maybe_delete_remote_objects'));

        add_filter('wp_get_attachment_url', array($this->offloader, 'filter_attachment_url'), 20, 2);
        add_filter('wp_get_attachment_image_src', array($this->offloader, 'filter_attachment_image_src'), 20, 4);
        add_filter('wp_calculate_image_srcset', array($this->offloader, 'filter_image_srcset'), 20, 5);

        add_action(WPS3F_Migration_Service::CRON_HOOK, array($this->migration, 'run_batch'));

        add_action('admin_menu', array($this->admin, 'register_menu'));
        add_action('admin_init', array($this->admin, 'register_settings'));
        add_action('admin_notices', array($this->admin, 'render_admin_notices'));
        add_action('admin_post_wps3f_start_migration', array($this->admin, 'handle_start_migration'));
        add_action('admin_post_wps3f_stop_migration', array($this->admin, 'handle_stop_migration'));
        add_action('admin_post_wps3f_retry_failed_migration', array($this->admin, 'handle_retry_failed_migration'));
        add_action('admin_post_wps3f_retry_attachment', array($this->admin, 'handle_retry_single_attachment'));
    }
}
