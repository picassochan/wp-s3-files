<?php
/**
 * Key and path helpers used for object storage.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPS3F_Key_Builder {
    /**
     * Build normalized object key from prefix and relative upload path.
     *
     * @param string $path_prefix
     * @param string $relative_path
     * @return string
     */
    public static function build_key($path_prefix, $relative_path) {
        $path_prefix   = trim(str_replace('\\', '/', (string) $path_prefix), '/');
        $relative_path = trim(str_replace('\\', '/', (string) $relative_path), '/');

        if ('' === $path_prefix) {
            return $relative_path;
        }

        if ('' === $relative_path) {
            return $path_prefix;
        }

        return $path_prefix . '/' . $relative_path;
    }

    /**
     * URL-encode each path segment.
     *
     * @param string $key
     * @return string
     */
    public static function encode_path($key) {
        $segments = explode('/', str_replace('\\', '/', (string) $key));
        $encoded  = array();

        foreach ($segments as $segment) {
            $encoded[] = rawurlencode($segment);
        }

        return implode('/', $encoded);
    }
}
