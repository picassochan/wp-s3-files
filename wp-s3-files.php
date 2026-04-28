<?php
/**
 * Plugin Name: WP S3 Files
 * Plugin URI: https://example.com
 * Description: Offload WordPress media uploads to S3-compatible object storage with async processing and migration.
 * Version: 0.1.5
 * Author: WP S3 Files
 * License: GPL-2.0-or-later
 * Text Domain: wp-s3-files
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}
require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/picassochan/wp-s3-files',
    __FILE__,
    'wp-s3-files'
);
$updateChecker->getVcsApi()->enableReleaseAssets();

define('WPS3F_VERSION', '0.1.5');
define('WPS3F_PLUGIN_FILE', __FILE__);
define('WPS3F_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WPS3F_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WPS3F_PLUGIN_PATH . 'includes/class-wps3f-plugin.php';

/**
 * Bootstraps plugin singleton.
 *
 * @return WPS3F_Plugin
 */
function wps3f_plugin() {
    static $instance = null;

    if (null === $instance) {
        $instance = new WPS3F_Plugin();
    }

    return $instance;
}

add_action('plugins_loaded', 'wps3f_plugin');
register_activation_hook(__FILE__, array('WPS3F_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('WPS3F_Plugin', 'deactivate'));
