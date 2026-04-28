<?php
/**
 * Minimal S3/S3-compatible client using WordPress HTTP API + SigV4.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPS3F_S3_Client {
    /**
     * @var WPS3F_Options
     */
    private $options;

    /**
     * @var WPS3F_Logger
     */
    private $logger;

    public function __construct(WPS3F_Options $options, WPS3F_Logger $logger) {
        $this->options = $options;
        $this->logger  = $logger;
    }

    /**
     * Upload file to object storage.
     *
     * @param string $key
     * @param string $file_path
     * @param string $content_type
     * @return array<string,string>|WP_Error
     */
    public function put_object_from_file($key, $file_path, $content_type = 'application/octet-stream') {
        if (!is_readable($file_path)) {
            return new WP_Error('wps3f_unreadable_file', __('Local file is not readable.', 'wp-s3-files'));
        }

        $body = file_get_contents($file_path);
        if (false === $body) {
            return new WP_Error('wps3f_read_failed', __('Unable to read file contents.', 'wp-s3-files'));
        }

        $headers = array(
            'content-type' => $content_type,
        );

        $response = $this->signed_request('PUT', $key, $body, $headers);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return $this->http_error('wps3f_upload_http_error', $code, $response);
        }

        $etag = wp_remote_retrieve_header($response, 'etag');
        if (!is_string($etag)) {
            $etag = '';
        }

        return array(
            'etag' => trim($etag, '"'),
        );
    }

    /**
     * Delete object from storage.
     *
     * @param string $key
     * @return true|WP_Error
     */
    public function delete_object($key) {
        $response = $this->signed_request('DELETE', $key, '', array());
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return $this->http_error('wps3f_delete_http_error', $code, $response);
        }

        return true;
    }

    /**
     * Build publicly consumable URL for an object key.
     *
     * @param string $key
     * @return string
     */
    public function build_public_url($key) {
        $custom_domain = (string) $this->options->get('custom_domain');
        $encoded_key   = WPS3F_Key_Builder::encode_path($key);

        if ('' !== $custom_domain) {
            $base = untrailingslashit($custom_domain);

            return $base . '/' . $encoded_key;
        }

        $bucket   = (string) $this->options->get('bucket');
        $endpoint = (string) $this->options->get('endpoint');
        $region   = (string) $this->options->get('region');

        if ('' === $endpoint) {
            $endpoint = 'https://s3.' . $region . '.amazonaws.com';
        }

        $endpoint = untrailingslashit($endpoint);

        return $endpoint . '/' . rawurlencode($bucket) . '/' . $encoded_key;
    }

    /**
     * @param string               $method
     * @param string               $key
     * @param string               $payload
     * @param array<string,string> $headers
     * @return array<string,mixed>|WP_Error
     */
    private function signed_request($method, $key, $payload, array $headers) {
        $credentials = $this->resolve_credentials();
        if (is_wp_error($credentials)) {
            return $credentials;
        }

        $method  = strtoupper($method);
        $bucket  = $credentials['bucket'];
        $region  = $credentials['region'];
        $host    = $credentials['host'];
        $url     = $credentials['url_base'] . '/' . WPS3F_Key_Builder::encode_path($key);
        $uri     = '/' . rawurlencode($bucket) . '/' . WPS3F_Key_Builder::encode_path($key);

        $amz_date      = gmdate('Ymd\THis\Z');
        $date_stamp    = gmdate('Ymd');
        $payload_hash  = hash('sha256', $payload);
        $header_values = array_change_key_case($headers, CASE_LOWER);
        $header_values['host']                 = $host;
        $header_values['x-amz-content-sha256'] = $payload_hash;
        $header_values['x-amz-date']           = $amz_date;

        ksort($header_values);

        $canonical_headers = '';
        foreach ($header_values as $name => $value) {
            $canonical_headers .= strtolower($name) . ':' . trim((string) $value) . "\n";
        }
        $signed_headers = implode(';', array_keys($header_values));

        $canonical_request = implode(
            "\n",
            array(
                $method,
                $uri,
                '',
                $canonical_headers,
                $signed_headers,
                $payload_hash,
            )
        );

        $credential_scope = $date_stamp . '/' . $region . '/s3/aws4_request';
        $string_to_sign   = implode(
            "\n",
            array(
                'AWS4-HMAC-SHA256',
                $amz_date,
                $credential_scope,
                hash('sha256', $canonical_request),
            )
        );

        $signing_key = $this->get_signature_key($credentials['secret_key'], $date_stamp, $region, 's3');
        $signature   = hash_hmac('sha256', $string_to_sign, $signing_key);

        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $credentials['access_key'],
            $credential_scope,
            $signed_headers,
            $signature
        );

        $http_headers = array(
            'Authorization'          => $authorization,
            'x-amz-content-sha256'   => $payload_hash,
            'x-amz-date'             => $amz_date,
            'host'                   => $host,
        );
        foreach ($headers as $key_name => $value) {
            $http_headers[$key_name] = $value;
        }

        return wp_remote_request(
            $url,
            array(
                'method'  => $method,
                'headers' => $http_headers,
                'body'    => $payload,
                'timeout' => 120,
            )
        );
    }

    /**
     * @return array<string,string>|WP_Error
     */
    private function resolve_credentials() {
        $bucket     = trim((string) $this->options->get('bucket'));
        $region     = trim((string) $this->options->get('region'));
        $access_key = trim((string) $this->options->get('access_key'));
        $secret_key = trim((string) $this->options->get('secret_key'));
        $endpoint   = trim((string) $this->options->get('endpoint'));

        if ('' === $bucket || '' === $region || '' === $access_key || '' === $secret_key) {
            return new WP_Error('wps3f_missing_credentials', __('S3 settings are incomplete.', 'wp-s3-files'));
        }

        if ('' === $endpoint) {
            $endpoint = 'https://s3.' . $region . '.amazonaws.com';
        }

        $endpoint = untrailingslashit($endpoint);
        $host     = (string) wp_parse_url($endpoint, PHP_URL_HOST);
        if ('' === $host) {
            return new WP_Error('wps3f_invalid_endpoint', __('S3 endpoint is invalid.', 'wp-s3-files'));
        }

        return array(
            'bucket'     => $bucket,
            'region'     => $region,
            'access_key' => $access_key,
            'secret_key' => $secret_key,
            'endpoint'   => $endpoint,
            'host'       => $host,
            'url_base'   => $endpoint . '/' . rawurlencode($bucket),
        );
    }

    /**
     * @param string               $code
     * @param int                  $http_code
     * @param array<string,mixed>  $response
     * @return WP_Error
     */
    private function http_error($code, $http_code, array $response) {
        $body       = (string) wp_remote_retrieve_body($response);
        $truncated  = substr(trim($body), 0, 400);
        $message    = sprintf(__('Remote request failed with HTTP %d.', 'wp-s3-files'), (int) $http_code);
        if ('' !== $truncated) {
            $message .= ' ' . $truncated;
        }

        return new WP_Error($code, $message);
    }

    /**
     * @param string $key
     * @param string $date
     * @param string $region
     * @param string $service
     * @return string
     */
    private function get_signature_key($key, $date, $region, $service) {
        $k_date    = hash_hmac('sha256', $date, 'AWS4' . $key, true);
        $k_region  = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', $service, $k_region, true);

        return hash_hmac('sha256', 'aws4_request', $k_service, true);
    }
}
