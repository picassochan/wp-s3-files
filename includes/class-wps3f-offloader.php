<?php
/**
 * Core offload behavior: queue, upload, URL overrides, and delete sync.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPS3F_Offloader {
    const CRON_HOOK     = 'wps3f_offload_attachment';
    const META_STATE    = '_wps3f_state';
    const META_OBJECTS  = '_wps3f_objects';
    const META_ERROR    = '_wps3f_error';
    const META_IS_S3 = '_wps3f_is_s3';
    const META_HAS_LOCAL_COPY = '_wps3f_has_local_copy';

    const STATE_PENDING  = 'pending';
    const STATE_OFFLOADED = 'offloaded';
    const STATE_FAILED   = 'failed';

    /**
     * @var WPS3F_Options
     */
    private $options;

    /**
     * @var WPS3F_S3_Client
     */
    private $client;

    /**
     * @var WPS3F_Logger
     */
    private $logger;

    public function __construct(WPS3F_Options $options, WPS3F_S3_Client $client, WPS3F_Logger $logger) {
        $this->options = $options;
        $this->client  = $client;
        $this->logger  = $logger;
    }

    /**
     * Queue attachment for async offload.
     *
     * @param int $attachment_id
     */
    public function queue_attachment_offload($attachment_id) {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0 || 'attachment' !== get_post_type($attachment_id)) {
            return;
        }

        if (!$this->is_enabled()) {
            $this->logger->debug('Offload skipped: plugin disabled', array('attachment_id' => $attachment_id));
            return;
        }

        update_post_meta($attachment_id, self::META_STATE, self::STATE_PENDING);
        $this->set_storage_flags($attachment_id, 0, $this->has_local_copy_for_attachment($attachment_id) ? 1 : 0);

        $this->logger->debug('Queued attachment for offload', array('attachment_id' => $attachment_id));

        if (!wp_next_scheduled(self::CRON_HOOK, array($attachment_id))) {
            wp_schedule_single_event(time() + 5, self::CRON_HOOK, array($attachment_id));
        }
    }

    /**
     * Queue (or requeue) upload when metadata is generated, ensuring image sizes are available.
     *
     * @param mixed $metadata
     * @param int   $attachment_id
     * @return mixed
     */
    public function queue_attachment_offload_from_metadata($metadata, $attachment_id) {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id > 0 && $this->is_enabled()) {
            update_post_meta($attachment_id, self::META_STATE, self::STATE_PENDING);
            $this->set_storage_flags($attachment_id, 0, $this->has_local_copy_for_attachment($attachment_id) ? 1 : 0);

            if (!wp_next_scheduled(self::CRON_HOOK, array($attachment_id))) {
                wp_schedule_single_event(time() + 5, self::CRON_HOOK, array($attachment_id));
            }
        }

        return $metadata;
    }

    /**
     * WP-Cron callback to process queued offload.
     *
     * @param int $attachment_id
     */
    public function process_offload_job($attachment_id) {
        $this->offload_attachment((int) $attachment_id);
    }

    /**
     * Main offload pipeline for one attachment.
     *
     * @param int  $attachment_id
     * @param bool $force_retry
     * @return bool
     */
    public function offload_attachment($attachment_id, $force_retry = false) {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0 || 'attachment' !== get_post_type($attachment_id)) {
            return false;
        }

        if (!$this->is_enabled()) {
            $this->logger->debug('Offload skipped: plugin disabled', array('attachment_id' => $attachment_id));
            return false;
        }

        if (!$force_retry && self::STATE_OFFLOADED === get_post_meta($attachment_id, self::META_STATE, true)) {
            $this->logger->debug('Offload skipped: already offloaded', array('attachment_id' => $attachment_id));
            $this->refresh_storage_flags($attachment_id);
            return true;
        }

        $files = $this->collect_attachment_files($attachment_id);
        if (is_wp_error($files)) {
            $this->mark_failed($attachment_id, 'wps3f_collect_files_failed', $files->get_error_message());
            return false;
        }

        $this->logger->debug('Starting offload', array(
            'attachment_id' => $attachment_id,
            'file_count'    => count($files),
        ));

        $max_bytes = $this->max_offload_bytes();
        foreach ($files as $file_info) {
            if ($file_info['size'] > $max_bytes) {
                $message = sprintf(
                    __('File size exceeds max offload size (%dMB): %s', 'wp-s3-files'),
                    (int) $this->options->get('max_offload_size_mb'),
                    basename($file_info['path'])
                );
                $this->mark_failed($attachment_id, 'wps3f_file_size_limit', $message);
                return false;
            }
        }

        $objects = array(
            'original'    => array(),
            'sizes'       => array(),
            'by_basename' => array(),
        );
        $uploaded_keys = array();

        foreach ($files as $file_info) {
            $content_type = $this->resolve_content_type($file_info['path']);
            $result       = $this->upload_with_retries($file_info['key'], $file_info['path'], $content_type);

            if (is_wp_error($result)) {
                $this->delete_remote_keys($uploaded_keys);
                $this->mark_failed($attachment_id, 'wps3f_upload_failed', $result->get_error_message());
                return false;
            }

            $object_entry = array(
                'key'  => $file_info['key'],
                'url'  => $this->client->build_public_url($file_info['key']),
                'etag' => isset($result['etag']) ? $result['etag'] : '',
            );

            if ('original' === $file_info['kind']) {
                $objects['original'] = $object_entry;
            } else {
                $objects['sizes'][$file_info['label']] = $object_entry;
            }

            $objects['by_basename'][basename($file_info['path'])] = $file_info['key'];
            $uploaded_keys[] = $file_info['key'];
        }

        update_post_meta($attachment_id, self::META_OBJECTS, $objects);
        update_post_meta($attachment_id, self::META_STATE, self::STATE_OFFLOADED);
        $this->logger->clear_attachment_error($attachment_id);

        $has_local_copy = true;
        if (!$this->keep_local_backup()) {
            $this->delete_local_files($files);
            $has_local_copy = $this->has_local_copy_from_files($files);
        }
        $this->set_storage_flags($attachment_id, 1, $has_local_copy ? 1 : 0);

        $this->logger->debug('Offload complete', array(
            'attachment_id'   => $attachment_id,
            'files_uploaded'  => count($uploaded_keys),
            'has_local_copy'  => $has_local_copy,
        ));

        return true;
    }

    /**
     * Offload retry entrypoint (used from admin actions).
     *
     * @param int $attachment_id
     */
    public function retry_attachment_offload($attachment_id) {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) {
            return;
        }

        update_post_meta($attachment_id, self::META_STATE, self::STATE_PENDING);
        $this->set_storage_flags($attachment_id, 0, $this->has_local_copy_for_attachment($attachment_id) ? 1 : 0);
        if (!wp_next_scheduled(self::CRON_HOOK, array($attachment_id))) {
            wp_schedule_single_event(time() + 3, self::CRON_HOOK, array($attachment_id));
        }
    }

    /**
     * Keep S3 objects in sync on attachment deletion.
     *
     * @param int $attachment_id
     */
    public function maybe_delete_remote_objects($attachment_id) {
        if (!$this->should_delete_remote_on_delete()) {
            return;
        }

        $objects = get_post_meta((int) $attachment_id, self::META_OBJECTS, true);
        if (!is_array($objects)) {
            return;
        }

        $keys = $this->extract_all_keys($objects);
        $this->logger->debug('Deleting remote objects', array(
            'attachment_id' => (int) $attachment_id,
            'key_count'     => count($keys),
        ));

        foreach ($keys as $key) {
            $result = $this->client->delete_object($key);
            if (is_wp_error($result)) {
                $this->logger->log_error(
                    'wps3f_delete_remote_failed',
                    $result->get_error_message(),
                    array(
                        'attachment_id' => (int) $attachment_id,
                        'key'           => $key,
                    )
                );
            }
        }
    }

    /**
     * Replace primary attachment URL with remote URL when offloaded.
     *
     * @param string $url
     * @param int    $attachment_id
     * @return string
     */
    public function filter_attachment_url($url, $attachment_id) {
        $objects = $this->get_object_map($attachment_id);
        if (empty($objects['original']['key'])) {
            return $url;
        }

        $s3_url = $this->client->build_public_url($objects['original']['key']);
        $this->logger->debug('filter_attachment_url', array(
            'attachment_id' => (int) $attachment_id,
            'original_url'  => $url,
            's3_url'        => $s3_url,
        ));

        return $s3_url;
    }

    /**
     * Replace image URL returned by wp_get_attachment_image_src.
     *
     * @param array<int,mixed>|false $image
     * @param int                    $attachment_id
     * @param string|int[]           $size
     * @param bool                   $icon
     * @return array<int,mixed>|false
     */
    public function filter_attachment_image_src($image, $attachment_id, $size, $icon) {
        if (!is_array($image) || empty($image[0]) || $icon) {
            return $image;
        }

        $objects = $this->get_object_map($attachment_id);
        if (!$objects) {
            return $image;
        }

        $target_key = '';
        if (is_string($size) && !empty($objects['sizes'][$size]['key'])) {
            $target_key = (string) $objects['sizes'][$size]['key'];
        }

        if ('' === $target_key) {
            $filename = basename((string) $image[0]);
            if (!empty($objects['by_basename'][$filename])) {
                $target_key = (string) $objects['by_basename'][$filename];
            }
        }

        if ('' === $target_key && !empty($objects['original']['key'])) {
            $target_key = (string) $objects['original']['key'];
        }

        if ('' !== $target_key) {
            $image[0] = $this->client->build_public_url($target_key);
        }

        return $image;
    }

    /**
     * Replace srcset URLs when images are offloaded.
     *
     * @param array<string,array<string,mixed>> $sources
     * @param int[]                             $size_array
     * @param string                            $image_src
     * @param array<string,mixed>               $image_meta
     * @param int                               $attachment_id
     * @return array<string,array<string,mixed>>
     */
    public function filter_image_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        $objects = $this->get_object_map($attachment_id);
        if (!$objects) {
            return $sources;
        }

        foreach ($sources as $descriptor => $source) {
            if (empty($source['url'])) {
                continue;
            }
            $filename = basename((string) $source['url']);
            if (!empty($objects['by_basename'][$filename])) {
                $sources[$descriptor]['url'] = $this->client->build_public_url($objects['by_basename'][$filename]);
            } elseif (!empty($objects['original']['key'])) {
                $sources[$descriptor]['url'] = $this->client->build_public_url($objects['original']['key']);
            }
        }

        return $sources;
    }

    /**
     * @param int $attachment_id
     * @return bool
     */
    public function is_attachment_offloaded($attachment_id) {
        return self::STATE_OFFLOADED === get_post_meta((int) $attachment_id, self::META_STATE, true);
    }

    /**
     * @param int $attachment_id
     * @return array<string,mixed>
     */
    public function get_attachment_status($attachment_id) {
        $attachment_id = (int) $attachment_id;

        return array(
            'state'  => (string) get_post_meta($attachment_id, self::META_STATE, true),
            'error'  => (string) get_post_meta($attachment_id, self::META_ERROR, true),
            'object' => get_post_meta($attachment_id, self::META_OBJECTS, true),
            'is_s3'  => (int) get_post_meta($attachment_id, self::META_IS_S3, true),
            'has_local_copy' => (int) get_post_meta($attachment_id, self::META_HAS_LOCAL_COPY, true),
        );
    }

    /**
     * Refresh S3/local classification flags for an attachment.
     *
     * @param int $attachment_id
     * @return bool
     */
    public function refresh_storage_flags($attachment_id) {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0 || 'attachment' !== get_post_type($attachment_id)) {
            return false;
        }

        $state = (string) get_post_meta($attachment_id, self::META_STATE, true);
        $objects = get_post_meta($attachment_id, self::META_OBJECTS, true);
        $has_remote_object = self::STATE_OFFLOADED === $state;
        if (!$has_remote_object && is_array($objects) && !empty($objects['original']['key'])) {
            $has_remote_object = true;
        }

        $has_local_copy = $this->has_local_copy_for_attachment($attachment_id);
        $this->set_storage_flags($attachment_id, $has_remote_object ? 1 : 0, $has_local_copy ? 1 : 0);

        return true;
    }

    /**
     * Get storage flags for media list rendering.
     *
     * @param int $attachment_id
     * @return array<string,bool>
     */
    public function get_storage_flags($attachment_id) {
        $attachment_id = (int) $attachment_id;
        $is_s3 = (int) get_post_meta($attachment_id, self::META_IS_S3, true);
        $has_local_copy = (int) get_post_meta($attachment_id, self::META_HAS_LOCAL_COPY, true);

        if ('' === get_post_meta($attachment_id, self::META_IS_S3, true) || '' === get_post_meta($attachment_id, self::META_HAS_LOCAL_COPY, true)) {
            $this->refresh_storage_flags($attachment_id);
            $is_s3 = (int) get_post_meta($attachment_id, self::META_IS_S3, true);
            $has_local_copy = (int) get_post_meta($attachment_id, self::META_HAS_LOCAL_COPY, true);
        }

        return array(
            'is_s3' => 1 === $is_s3,
            'has_local_copy' => 1 === $has_local_copy,
        );
    }

    /**
     * @return bool
     */
    private function is_enabled() {
        return 1 === (int) $this->options->get('enabled');
    }

    /**
     * @return bool
     */
    private function keep_local_backup() {
        return 1 === (int) $this->options->get('keep_local_backup');
    }

    /**
     * @return bool
     */
    private function should_delete_remote_on_delete() {
        return 1 === (int) $this->options->get('delete_remote_on_delete');
    }

    /**
     * @return int
     */
    private function max_offload_bytes() {
        $mb = (int) $this->options->get('max_offload_size_mb');
        if ($mb < 1) {
            $mb = 200;
        }

        return $mb * MB_IN_BYTES;
    }

    /**
     * @param int $attachment_id
     * @return array<int,array<string,mixed>>|WP_Error
     */
    private function collect_attachment_files($attachment_id) {
        $attachment_id = (int) $attachment_id;
        $original_path = get_attached_file($attachment_id);
        if (empty($original_path) || !is_readable($original_path)) {
            return new WP_Error('wps3f_missing_original', __('Original attachment file is missing.', 'wp-s3-files'));
        }

        $uploads = wp_get_upload_dir();
        if (empty($uploads['basedir'])) {
            return new WP_Error('wps3f_uploads_unavailable', __('Uploads directory is unavailable.', 'wp-s3-files'));
        }

        $base_dir       = wp_normalize_path($uploads['basedir']);
        $metadata       = wp_get_attachment_metadata($attachment_id);
        $original_path  = wp_normalize_path($original_path);
        $relative_base  = '';
        $metadata_file  = '';
        $files          = array();
        $seen_paths     = array();

        if (is_array($metadata) && !empty($metadata['file'])) {
            $metadata_file = ltrim(str_replace('\\', '/', (string) $metadata['file']), '/');
            $relative_base = '.' !== dirname($metadata_file) ? dirname($metadata_file) : '';
        }

        $original_relative = $this->relative_to_uploads($original_path, $base_dir);
        if ('' === $original_relative) {
            if ('' !== $metadata_file) {
                $original_relative = $metadata_file;
            } else {
                $original_relative = basename($original_path);
            }
        }

        $files[] = array(
            'kind'          => 'original',
            'label'         => 'original',
            'path'          => $original_path,
            'relative_path' => $original_relative,
            'key'           => WPS3F_Key_Builder::build_key((string) $this->options->get('path_prefix'), $original_relative),
            'size'          => (int) filesize($original_path),
        );
        $seen_paths[$original_path] = true;

        if (!empty($metadata['original_image']) && '' !== $relative_base) {
            $original_image_rel = $relative_base . '/' . ltrim((string) $metadata['original_image'], '/');
            $original_image_abs = wp_normalize_path(trailingslashit($base_dir) . $original_image_rel);

            if (is_readable($original_image_abs) && empty($seen_paths[$original_image_abs])) {
                $files[] = array(
                    'kind'          => 'size',
                    'label'         => 'original_image',
                    'path'          => $original_image_abs,
                    'relative_path' => $original_image_rel,
                    'key'           => WPS3F_Key_Builder::build_key((string) $this->options->get('path_prefix'), $original_image_rel),
                    'size'          => (int) filesize($original_image_abs),
                );
                $seen_paths[$original_image_abs] = true;
            }
        }

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_name => $size_data) {
                if (empty($size_data['file'])) {
                    continue;
                }

                $relative = '' !== $relative_base
                    ? $relative_base . '/' . ltrim((string) $size_data['file'], '/')
                    : ltrim((string) $size_data['file'], '/');

                $absolute = wp_normalize_path(trailingslashit($base_dir) . $relative);
                if (!is_readable($absolute) || !empty($seen_paths[$absolute])) {
                    continue;
                }

                $files[] = array(
                    'kind'          => 'size',
                    'label'         => sanitize_key((string) $size_name),
                    'path'          => $absolute,
                    'relative_path' => $relative,
                    'key'           => WPS3F_Key_Builder::build_key((string) $this->options->get('path_prefix'), $relative),
                    'size'          => (int) filesize($absolute),
                );
                $seen_paths[$absolute] = true;
            }
        }

        return $files;
    }

    /**
     * @param string $path
     * @return string
     */
    private function resolve_content_type($path) {
        $result = wp_check_filetype($path);
        if (!empty($result['type'])) {
            return (string) $result['type'];
        }

        return 'application/octet-stream';
    }

    /**
     * @param string $key
     * @param string $path
     * @param string $content_type
     * @return array<string,string>|WP_Error
     */
    private function upload_with_retries($key, $path, $content_type) {
        $attempt    = 0;
        $max_retry  = 3;
        $last_error = null;

        while ($attempt < $max_retry) {
            $attempt++;
            $result = $this->client->put_object_from_file($key, $path, $content_type);
            if (!is_wp_error($result)) {
                return $result;
            }

            $last_error = $result;
            if ($attempt < $max_retry) {
                sleep($attempt);
            }
        }

        if (null === $last_error) {
            return new WP_Error('wps3f_upload_failed', __('Upload failed after retries.', 'wp-s3-files'));
        }

        return $last_error;
    }

    /**
     * @param int    $attachment_id
     * @param string $code
     * @param string $message
     */
    private function mark_failed($attachment_id, $code, $message) {
        $attachment_id = (int) $attachment_id;
        $message       = (string) $message;

        $this->logger->record_attachment_error($attachment_id, $code, $message);
        $this->set_storage_flags($attachment_id, 0, 1);
    }

    /**
     * @param array<int,array<string,mixed>> $files
     */
    private function delete_local_files(array $files) {
        foreach ($files as $file_info) {
            if (empty($file_info['path'])) {
                continue;
            }

            $path = (string) $file_info['path'];
            if (!file_exists($path)) {
                continue;
            }

            wp_delete_file($path);
        }
    }

    /**
     * @param array<int,string> $keys
     */
    private function delete_remote_keys(array $keys) {
        foreach (array_unique($keys) as $key) {
            $key = (string) $key;
            if ('' === $key) {
                continue;
            }
            $result = $this->client->delete_object($key);
            if (is_wp_error($result)) {
                $this->logger->log_error(
                    'wps3f_cleanup_remote_failed',
                    $result->get_error_message(),
                    array('key' => $key)
                );
            }
        }
    }

    /**
     * @param int $attachment_id
     * @return array<string,mixed>
     */
    private function get_object_map($attachment_id) {
        $objects = get_post_meta((int) $attachment_id, self::META_OBJECTS, true);
        if (!is_array($objects) || empty($objects)) {
            return array();
        }

        return $objects;
    }

    /**
     * @param string $file_path
     * @param string $uploads_basedir
     * @return string
     */
    private function relative_to_uploads($file_path, $uploads_basedir) {
        $file_path       = wp_normalize_path($file_path);
        $uploads_basedir = trailingslashit(wp_normalize_path($uploads_basedir));

        if (0 === strpos($file_path, $uploads_basedir)) {
            return ltrim(substr($file_path, strlen($uploads_basedir)), '/');
        }

        return '';
    }

    /**
     * @param array<string,mixed> $objects
     * @return array<int,string>
     */
    private function extract_all_keys(array $objects) {
        $keys = array();

        if (!empty($objects['original']['key'])) {
            $keys[] = (string) $objects['original']['key'];
        }

        if (!empty($objects['sizes']) && is_array($objects['sizes'])) {
            foreach ($objects['sizes'] as $size_meta) {
                if (!empty($size_meta['key'])) {
                    $keys[] = (string) $size_meta['key'];
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param int $attachment_id
     * @param int $is_s3
     * @param int $has_local_copy
     */
    private function set_storage_flags($attachment_id, $is_s3, $has_local_copy) {
        update_post_meta((int) $attachment_id, self::META_IS_S3, (int) $is_s3 ? 1 : 0);
        update_post_meta((int) $attachment_id, self::META_HAS_LOCAL_COPY, (int) $has_local_copy ? 1 : 0);
    }

    /**
     * @param int $attachment_id
     * @return bool
     */
    private function has_local_copy_for_attachment($attachment_id) {
        $attachment_id = (int) $attachment_id;
        $path = get_attached_file($attachment_id);

        return !empty($path) && file_exists((string) $path);
    }

    /**
     * @param array<int,array<string,mixed>> $files
     * @return bool
     */
    private function has_local_copy_from_files(array $files) {
        foreach ($files as $file_info) {
            if (!empty($file_info['path']) && file_exists((string) $file_info['path'])) {
                return true;
            }
        }

        return false;
    }
}
