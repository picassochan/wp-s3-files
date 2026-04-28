<?php
/**
 * Integration with plugin-update-checker for online updates.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPS3F_Updater {
    /**
     * @var WPS3F_Logger
     */
    private $logger;

    /**
     * @var object|null
     */
    private $update_checker;

    public function __construct(WPS3F_Logger $logger) {
        $this->logger = $logger;
    }

    /**
     * Initialize update checker when source URL is configured.
     */
    public function init() {
        $source_url = apply_filters('wps3f_update_source_url', $this->get_update_source_url());
        if (empty($source_url)) {
            return;
        }

        $library_file = WPS3F_PLUGIN_PATH . 'plugin-update-checker/plugin-update-checker.php';
        if (!file_exists($library_file)) {
            $this->logger->log_error('wps3f_update_checker_missing', 'plugin-update-checker library not found.');
            return;
        }

        require_once $library_file;

        if (!class_exists('\YahnisElsts\PluginUpdateChecker\v5p6\PucFactory')) {
            $this->logger->log_error('wps3f_update_checker_class_missing', 'PucFactory class is unavailable.');
            return;
        }

        $slug = apply_filters('wps3f_update_slug', 'wp-s3-files');
        $this->update_checker = \YahnisElsts\PluginUpdateChecker\v5p6\PucFactory::buildUpdateChecker(
            (string) $source_url,
            WPS3F_PLUGIN_FILE,
            (string) $slug
        );

        $branch = apply_filters('wps3f_update_branch', $this->get_update_branch());
        if (!empty($branch) && method_exists($this->update_checker, 'setBranch')) {
            $this->update_checker->setBranch((string) $branch);
        }

        $token = apply_filters('wps3f_update_token', $this->get_update_token());
        if (!empty($token) && method_exists($this->update_checker, 'setAuthentication')) {
            $this->update_checker->setAuthentication((string) $token);
        }
    }

    /**
     * @return string
     */
    private function get_update_source_url() {
        return defined('WPS3F_UPDATE_SOURCE_URL') ? (string) WPS3F_UPDATE_SOURCE_URL : '';
    }

    /**
     * @return string
     */
    private function get_update_branch() {
        return defined('WPS3F_UPDATE_BRANCH') ? (string) WPS3F_UPDATE_BRANCH : 'main';
    }

    /**
     * @return string
     */
    private function get_update_token() {
        return defined('WPS3F_UPDATE_TOKEN') ? (string) WPS3F_UPDATE_TOKEN : '';
    }
}
