<?php
/**
 * Media Library integration (filters, default scope, and storage status column).
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPS3F_Media_Library {
    const STORAGE_PARAM = 'wps3f_storage';
    const STORAGE_ALL   = 'all';
    const STORAGE_S3    = 's3';
    const STORAGE_LOCAL = 'local';

    /**
     * @var WPS3F_Offloader
     */
    private $offloader;

    public function __construct(WPS3F_Offloader $offloader) {
        $this->offloader = $offloader;
    }

    /**
     * Add storage filter on upload.php.
     *
     * @param string $post_type
     */
    public function render_upload_storage_filter($post_type) {
        if ('attachment' !== $post_type) {
            return;
        }

        $current = $this->resolve_storage_filter(isset($_GET[self::STORAGE_PARAM]) ? wp_unslash((string) $_GET[self::STORAGE_PARAM]) : self::STORAGE_S3);
        ?>
        <label class="screen-reader-text" for="filter-by-wps3f-storage"><?php echo esc_html__('Filter by Storage', 'wp-s3-files'); ?></label>
        <select id="filter-by-wps3f-storage" name="<?php echo esc_attr(self::STORAGE_PARAM); ?>">
            <option value="<?php echo esc_attr(self::STORAGE_ALL); ?>" <?php selected($current, self::STORAGE_ALL); ?>>
                <?php echo esc_html__('All Storage', 'wp-s3-files'); ?>
            </option>
            <option value="<?php echo esc_attr(self::STORAGE_S3); ?>" <?php selected($current, self::STORAGE_S3); ?>>
                <?php echo esc_html__('S3', 'wp-s3-files'); ?>
            </option>
            <option value="<?php echo esc_attr(self::STORAGE_LOCAL); ?>" <?php selected($current, self::STORAGE_LOCAL); ?>>
                <?php echo esc_html__('Local', 'wp-s3-files'); ?>
            </option>
        </select>
        <?php
    }

    /**
     * Apply storage filter to media list main query with default S3.
     *
     * @param WP_Query $query
     */
    public function apply_upload_query_storage_filter($query) {
        if (!($query instanceof WP_Query) || !is_admin() || !$query->is_main_query()) {
            return;
        }

        global $pagenow;
        if ('upload.php' !== $pagenow) {
            return;
        }

        $storage = $this->resolve_storage_filter(isset($_GET[self::STORAGE_PARAM]) ? wp_unslash((string) $_GET[self::STORAGE_PARAM]) : self::STORAGE_S3);
        $this->apply_meta_query_filter($query, $storage);
        $query->set(self::STORAGE_PARAM, $storage);
    }

    /**
     * Apply storage filter to media modal AJAX query with default S3.
     *
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public function apply_modal_query_storage_filter(array $query) {
        $storage = self::STORAGE_S3;
        if (isset($_REQUEST['query']) && is_array($_REQUEST['query']) && isset($_REQUEST['query'][self::STORAGE_PARAM])) {
            $storage = $this->resolve_storage_filter(wp_unslash((string) $_REQUEST['query'][self::STORAGE_PARAM]));
        } elseif (isset($_REQUEST[self::STORAGE_PARAM])) {
            $storage = $this->resolve_storage_filter(wp_unslash((string) $_REQUEST[self::STORAGE_PARAM]));
        }

        $query = $this->apply_meta_query_filter_to_args($query, $storage);
        $query[self::STORAGE_PARAM] = $storage;

        return $query;
    }

    /**
     * Add storage status column to media list table.
     *
     * @param array<string,string> $columns
     * @return array<string,string>
     */
    public function add_storage_column(array $columns) {
        $columns['wps3f_storage'] = __('Storage', 'wp-s3-files');

        return $columns;
    }

    /**
     * Render storage status column value.
     *
     * @param string $column_name
     * @param int    $attachment_id
     */
    public function render_storage_column($column_name, $attachment_id) {
        if ('wps3f_storage' !== $column_name) {
            return;
        }

        $flags = $this->offloader->get_storage_flags((int) $attachment_id);
        $is_s3 = !empty($flags['is_s3']);
        $is_local = !empty($flags['has_local_copy']);

        if ($is_s3 && $is_local) {
            echo esc_html__('S3 + Local', 'wp-s3-files');
            return;
        }

        if ($is_s3) {
            echo esc_html__('S3', 'wp-s3-files');
            return;
        }

        if ($is_local) {
            echo esc_html__('Local', 'wp-s3-files');
            return;
        }

        echo '&mdash;';
    }

    /**
     * Add media modal storage filters and default S3 query scope.
     *
     * @param string $hook_suffix
     */
    public function enqueue_media_modal_filter_assets($hook_suffix) {
        if (!is_admin()) {
            return;
        }

        $allowed_hooks = array('post.php', 'post-new.php', 'upload.php');
        if (!in_array($hook_suffix, $allowed_hooks, true)) {
            return;
        }

        wp_enqueue_media();

        $labels = array(
            'all'   => __('All Storage', 'wp-s3-files'),
            's3'    => __('S3', 'wp-s3-files'),
            'local' => __('Local', 'wp-s3-files'),
        );
        $labels_json = wp_json_encode($labels);

        $script = <<<JS
(function($){
    if (window.wps3fStorageFilterLoaded) {
        return;
    }
    window.wps3fStorageFilterLoaded = true;

    if (typeof wp === 'undefined' || !wp.media || !wp.media.view || !wp.media.view.AttachmentFilters || !wp.media.view.AttachmentsBrowser) {
        return;
    }

    var labels = $labels_json || {};
    var baseCreateToolbar = wp.media.view.AttachmentsBrowser.prototype.createToolbar;

    var StorageFilters = wp.media.view.AttachmentFilters.extend({
        id: 'media-attachment-wps3f-storage-filters',
        createFilters: function() {
            this.filters = {
                all: {
                    text: labels.all || 'All Storage',
                    props: { wps3f_storage: 'all' },
                    priority: 10
                },
                s3: {
                    text: labels.s3 || 'S3',
                    props: { wps3f_storage: 's3' },
                    priority: 20
                },
                local: {
                    text: labels.local || 'Local',
                    props: { wps3f_storage: 'local' },
                    priority: 30
                }
            };

            if (!this.model.get('wps3f_storage')) {
                this.model.set('wps3f_storage', 's3');
            }
        }
    });

    wp.media.view.AttachmentsBrowser = wp.media.view.AttachmentsBrowser.extend({
        createToolbar: function() {
            baseCreateToolbar.apply(this, arguments);
            this.toolbar.set(
                'Wps3fStorageFilters',
                new StorageFilters({
                    controller: this.controller,
                    model: this.collection.props,
                    priority: -80
                }).render()
            );
        }
    });
})(jQuery);
JS;

        wp_add_inline_script('media-views', $script);
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function resolve_storage_filter($value) {
        $value = sanitize_key((string) $value);
        if (in_array($value, array(self::STORAGE_ALL, self::STORAGE_S3, self::STORAGE_LOCAL), true)) {
            return $value;
        }

        return self::STORAGE_S3;
    }

    /**
     * @param WP_Query $query
     * @param string   $storage
     */
    private function apply_meta_query_filter($query, $storage) {
        if (self::STORAGE_ALL === $storage) {
            return;
        }

        $meta_query = $query->get('meta_query');
        if (!is_array($meta_query)) {
            $meta_query = array();
        }

        if (self::STORAGE_S3 === $storage) {
            $meta_query[] = array(
                'key'     => WPS3F_Offloader::META_IS_S3,
                'value'   => '1',
                'compare' => '=',
            );
        } elseif (self::STORAGE_LOCAL === $storage) {
            $meta_query[] = array(
                'key'     => WPS3F_Offloader::META_HAS_LOCAL_COPY,
                'value'   => '1',
                'compare' => '=',
            );
        }

        $query->set('meta_query', $meta_query);
    }

    /**
     * @param array<string,mixed> $query_args
     * @param string              $storage
     * @return array<string,mixed>
     */
    private function apply_meta_query_filter_to_args(array $query_args, $storage) {
        if (self::STORAGE_ALL === $storage) {
            return $query_args;
        }

        $meta_query = array();
        if (!empty($query_args['meta_query']) && is_array($query_args['meta_query'])) {
            $meta_query = $query_args['meta_query'];
        }

        if (self::STORAGE_S3 === $storage) {
            $meta_query[] = array(
                'key'     => WPS3F_Offloader::META_IS_S3,
                'value'   => '1',
                'compare' => '=',
            );
        } elseif (self::STORAGE_LOCAL === $storage) {
            $meta_query[] = array(
                'key'     => WPS3F_Offloader::META_HAS_LOCAL_COPY,
                'value'   => '1',
                'compare' => '=',
            );
        }

        $query_args['meta_query'] = $meta_query;

        return $query_args;
    }
}
