<?php
/**
 * Lightweight logger and admin-facing error buffer.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPS3F_Logger {
    const ERROR_LOG_OPTION = 'wps3f_recent_errors';
    const MAX_ERROR_ITEMS  = 50;

    /**
     * Log structured error to both PHP error log and plugin error buffer.
     *
     * @param string               $code
     * @param string               $message
     * @param array<string, mixed> $context
     */
    public function log_error($code, $message, array $context = array()) {
        $entry = array(
            'time'    => gmdate('c'),
            'code'    => (string) $code,
            'message' => (string) $message,
            'context' => $context,
        );

        error_log('[WPS3F] ' . wp_json_encode($entry));

        $existing = get_option(self::ERROR_LOG_OPTION, array());
        if (!is_array($existing)) {
            $existing = array();
        }
        $existing[] = $entry;

        if (count($existing) > self::MAX_ERROR_ITEMS) {
            $existing = array_slice($existing, -1 * self::MAX_ERROR_ITEMS);
        }

        update_option(self::ERROR_LOG_OPTION, $existing, false);
    }

    /**
     * Convenience method for attachment-specific failures.
     *
     * @param int    $attachment_id
     * @param string $code
     * @param string $message
     */
    public function record_attachment_error($attachment_id, $code, $message) {
        $attachment_id = (int) $attachment_id;

        update_post_meta($attachment_id, WPS3F_Offloader::META_ERROR, sanitize_text_field($message));
        update_post_meta($attachment_id, WPS3F_Offloader::META_STATE, WPS3F_Offloader::STATE_FAILED);

        $this->log_error($code, $message, array('attachment_id' => $attachment_id));
    }

    /**
     * Clear attachment error metadata when recovered.
     *
     * @param int $attachment_id
     */
    public function clear_attachment_error($attachment_id) {
        delete_post_meta((int) $attachment_id, WPS3F_Offloader::META_ERROR);
    }

    /**
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function get_recent_errors($limit = 20) {
        $limit  = max(1, (int) $limit);
        $errors = get_option(self::ERROR_LOG_OPTION, array());
        if (!is_array($errors)) {
            return array();
        }

        $errors = array_reverse($errors);

        return array_slice($errors, 0, $limit);
    }
}
