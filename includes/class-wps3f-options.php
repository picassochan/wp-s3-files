<?php
/**
 * Settings/options model with defaults and optional constant fallbacks.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPS3F_Options {
    const OPTION_NAME = 'wps3f_options';
    const MASKED_SECRET = '********';

    /**
     * @var array<string,mixed>|null
     */
    private $cache;

    /**
     * Default settings.
     *
     * @return array<string,mixed>
     */
    public static function defaults() {
        return array(
            'enabled'                  => 1,
            'bucket'                   => '',
            'region'                   => '',
            'endpoint'                 => '',
            'access_key'               => '',
            'secret_key'               => '',
            'custom_domain'            => '',
            'keep_local_backup'        => 0,
            'delete_remote_on_delete'  => 1,
            'path_prefix'              => 'wp-content/uploads',
            'max_offload_size_mb'      => 200,
            'debug'                    => 0,
        );
    }

    /**
     * Ensure option exists with autoload disabled.
     */
    public static function ensure_option_exists() {
        if (false === get_option(self::OPTION_NAME, false)) {
            add_option(self::OPTION_NAME, self::defaults(), '', 'no');
        }
    }

    /**
     * Fetch effective options.
     *
     * @return array<string,mixed>
     */
    public function get_all() {
        if (null !== $this->cache) {
            return $this->cache;
        }

        $stored = get_option(self::OPTION_NAME, array());
        if (!is_array($stored)) {
            $stored = array();
        }

        $options = wp_parse_args($stored, self::defaults());
        $options = $this->apply_constant_fallbacks($options, $stored);
        $options = $this->normalize($options);

        $this->cache = $options;

        return $options;
    }

    /**
     * Fetch one option key.
     *
     * @param string $key Option key.
     * @return mixed|null
     */
    public function get($key) {
        $all = $this->get_all();

        return array_key_exists($key, $all) ? $all[$key] : null;
    }

    /**
     * Reset option cache.
     */
    public function clear_cache() {
        $this->cache = null;
    }

    /**
     * Sanitize settings payload for storage.
     *
     * @param mixed                  $raw      Input from settings form.
     * @param array<string,mixed>|null $current Previously stored options.
     * @return array<string,mixed>
     */
    public function sanitize_for_storage($raw, $current = null) {
        if (is_array($current)) {
            $current = wp_parse_args($current, self::defaults());
        } else {
            $stored = get_option(self::OPTION_NAME, array());
            if (!is_array($stored)) {
                $stored = array();
            }
            $current = wp_parse_args($stored, self::defaults());
        }
        $raw     = is_array($raw) ? $raw : array();

        $sanitized = array(
            'enabled'                 => empty($raw['enabled']) ? 0 : 1,
            'bucket'                  => $this->sanitize_plain_text(isset($raw['bucket']) ? $raw['bucket'] : ''),
            'region'                  => $this->sanitize_plain_text(isset($raw['region']) ? $raw['region'] : ''),
            'endpoint'                => $this->sanitize_endpoint(isset($raw['endpoint']) ? $raw['endpoint'] : ''),
            'access_key'              => $this->sanitize_plain_text(isset($raw['access_key']) ? $raw['access_key'] : ''),
            'custom_domain'           => $this->sanitize_domain(isset($raw['custom_domain']) ? $raw['custom_domain'] : ''),
            'keep_local_backup'       => empty($raw['keep_local_backup']) ? 0 : 1,
            'delete_remote_on_delete' => empty($raw['delete_remote_on_delete']) ? 0 : 1,
            'path_prefix'             => $this->sanitize_path_prefix(isset($raw['path_prefix']) ? $raw['path_prefix'] : ''),
            'max_offload_size_mb'     => $this->sanitize_size_limit(isset($raw['max_offload_size_mb']) ? $raw['max_offload_size_mb'] : 200),
            'debug'                   => empty($raw['debug']) ? 0 : 1,
        );

        $secret = isset($raw['secret_key']) ? trim((string) $raw['secret_key']) : '';
        if ('' === $secret || self::MASKED_SECRET === $secret) {
            $sanitized['secret_key'] = isset($current['secret_key']) ? (string) $current['secret_key'] : '';
        } else {
            $sanitized['secret_key'] = $secret;
        }

        if ('' === $sanitized['bucket']) {
            $sanitized['enabled'] = 0;
        }

        return wp_parse_args($sanitized, self::defaults());
    }

    /**
     * Return mask if secret key already exists.
     *
     * @return string
     */
    public function get_masked_secret_for_form() {
        $raw = get_option(self::OPTION_NAME, array());
        if (!is_array($raw) || empty($raw['secret_key'])) {
            return '';
        }

        return self::MASKED_SECRET;
    }

    /**
     * @param array<string,mixed> $options Raw options.
     * @return array<string,mixed>
     */
    private function normalize(array $options) {
        $options['enabled']                 = empty($options['enabled']) ? 0 : 1;
        $options['keep_local_backup']       = empty($options['keep_local_backup']) ? 0 : 1;
        $options['delete_remote_on_delete'] = empty($options['delete_remote_on_delete']) ? 0 : 1;
        $options['debug']                   = empty($options['debug']) ? 0 : 1;
        $options['max_offload_size_mb']     = $this->sanitize_size_limit($options['max_offload_size_mb']);

        $options['bucket']        = (string) $options['bucket'];
        $options['region']        = (string) $options['region'];
        $options['endpoint']      = (string) $options['endpoint'];
        $options['access_key']    = (string) $options['access_key'];
        $options['secret_key']    = (string) $options['secret_key'];
        $options['custom_domain'] = (string) $options['custom_domain'];
        $options['path_prefix']   = $this->sanitize_path_prefix($options['path_prefix']);

        return $options;
    }

    /**
     * Apply wp-config.php constant fallbacks only when database value is missing.
     *
     * @param array<string,mixed> $options
     * @param array<string,mixed> $stored Raw DB option array.
     * @return array<string,mixed>
     */
    private function apply_constant_fallbacks(array $options, array $stored) {
        $map = array(
            'enabled'                  => 'WPS3F_ENABLED',
            'bucket'                   => 'WPS3F_BUCKET',
            'region'                   => 'WPS3F_REGION',
            'endpoint'                 => 'WPS3F_ENDPOINT',
            'access_key'               => 'WPS3F_ACCESS_KEY',
            'secret_key'               => 'WPS3F_SECRET_KEY',
            'custom_domain'            => 'WPS3F_CUSTOM_DOMAIN',
            'keep_local_backup'        => 'WPS3F_KEEP_LOCAL_BACKUP',
            'delete_remote_on_delete'  => 'WPS3F_DELETE_REMOTE_ON_DELETE',
            'path_prefix'              => 'WPS3F_PATH_PREFIX',
            'max_offload_size_mb'      => 'WPS3F_MAX_OFFLOAD_SIZE_MB',
            'debug'                    => 'WPS3F_DEBUG',
        );

        foreach ($map as $option_key => $constant_name) {
            if (defined($constant_name) && !$this->has_stored_value($stored, $option_key)) {
                $options[$option_key] = constant($constant_name);
            }
        }

        return $options;
    }

    /**
     * @param array<string,mixed> $stored
     * @param string              $key
     * @return bool
     */
    private function has_stored_value(array $stored, $key) {
        return array_key_exists($key, $stored);
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function sanitize_plain_text($value) {
        return trim(sanitize_text_field((string) $value));
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function sanitize_endpoint($value) {
        $value = trim((string) $value);
        if ('' === $value) {
            return '';
        }

        $value = esc_url_raw($value);

        return untrailingslashit($value);
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function sanitize_domain($value) {
        $value = trim((string) $value);
        if ('' === $value) {
            return '';
        }

        if (0 !== strpos($value, 'http://') && 0 !== strpos($value, 'https://')) {
            $value = 'https://' . $value;
        }

        return untrailingslashit(esc_url_raw($value));
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function sanitize_path_prefix($value) {
        $value = trim((string) $value);
        $value = str_replace('\\', '/', $value);
        $value = preg_replace('#/+#', '/', $value);
        $value = trim((string) $value, '/');

        if ('' === $value) {
            return 'wp-content/uploads';
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return int
     */
    private function sanitize_size_limit($value) {
        $value = (int) $value;
        if ($value < 1) {
            return 200;
        }

        return $value;
    }
}
