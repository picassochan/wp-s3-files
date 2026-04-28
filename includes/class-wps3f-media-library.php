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
    const UPLOAD_STATS_TRANSIENT = 'wps3f_upload_stats';

    /**
     * @var WPS3F_Offloader
     */
    private $offloader;

    public function __construct(WPS3F_Offloader $offloader) {
        $this->offloader = $offloader;
    }

    /**
     * Inject S3 storage state into the media modal AJAX response.
     *
     * @param array<string,mixed> $response
     * @param WP_Post             $attachment
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    public function inject_attachment_js_meta(array $response, $attachment, $meta) {
        $id     = (int) $attachment->ID;
        $flags  = $this->offloader->get_storage_flags($id);
        $state  = (string) get_post_meta($id, WPS3F_Offloader::META_STATE, true);
        $error  = (string) get_post_meta($id, WPS3F_Offloader::META_ERROR, true);

        $label = '—';
        if ($flags['is_s3'] && $flags['has_local_copy']) {
            $label = __('S3 + Local', 'wp-s3-files');
        } elseif ($flags['is_s3']) {
            $label = __('S3', 'wp-s3-files');
        } elseif ($flags['has_local_copy']) {
            $label = __('Local', 'wp-s3-files');
        }

        $response['wps3f'] = array(
            'state'         => $state,
            'is_s3'         => $flags['is_s3'],
            'has_local'     => $flags['has_local_copy'],
            'label'         => $label,
            'error'         => $error,
        );

        return $response;
    }

    /**
     * Record an offload result for the upload summary notice.
     *
     * @param int    $attachment_id
     * @param bool   $success
     * @param string $mode 'sync' or 'async'.
     */
    public static function record_upload_result($attachment_id, $success, $mode = 'sync') {
        $stats = get_transient(self::UPLOAD_STATS_TRANSIENT);
        if (!is_array($stats)) {
            $stats = array('uploaded' => array(), 'failed' => array());
        }

        if ($success) {
            $stats['uploaded'][] = array(
                'id'        => (int) $attachment_id,
                'mode'      => $mode,
                'timestamp' => time(),
            );
        } else {
            $stats['failed'][] = array(
                'id'        => (int) $attachment_id,
                'mode'      => $mode,
                'timestamp' => time(),
            );
        }

        // Keep only results from last 5 minutes.
        $cutoff = time() - 300;
        $stats['uploaded'] = array_values(array_filter($stats['uploaded'], function ($e) use ($cutoff) {
            return $e['timestamp'] > $cutoff;
        }));
        $stats['failed'] = array_values(array_filter($stats['failed'], function ($e) use ($cutoff) {
            return $e['timestamp'] > $cutoff;
        }));

        set_transient(self::UPLOAD_STATS_TRANSIENT, $stats, 300);
    }

    /**
     * Show upload summary notice on upload.php.
     */
    public function render_upload_summary_notice() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        global $pagenow;
        if ('upload.php' !== $pagenow && 'post.php' !== $pagenow && 'post-new.php' !== $pagenow) {
            return;
        }

        $stats = get_transient(self::UPLOAD_STATS_TRANSIENT);
        if (!is_array($stats)) {
            return;
        }

        $uploaded = count($stats['uploaded']);
        $failed   = count($stats['failed']);

        if (0 === $uploaded && 0 === $failed) {
            return;
        }

        $messages = array();
        if ($uploaded > 0) {
            $messages[] = sprintf(
                __('S3: %d file(s) uploaded successfully.', 'wp-s3-files'),
                $uploaded
            );
        }
        if ($failed > 0) {
            $messages[] = sprintf(
                __('S3: %d file(s) failed to upload. You can retry from the settings page.', 'wp-s3-files'),
                $failed
            );
        }

        $type = $failed > 0 ? 'warning' : 'success';
        printf(
            '<div class="notice notice-%s is-dismissible wps3f-upload-notice"><p><strong>%s</strong> %s</p></div>',
            esc_attr($type),
            esc_html__('WP S3 Files', 'wp-s3-files'),
            implode(' ', array_map('esc_html', $messages))
        );
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

        // CSS for S3 status badge on attachment thumbnails.
        $css = <<<'CSS'
.wps3f-badge{position:absolute;bottom:4px;right:4px;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:600;color:#fff;z-index:10;pointer-events:none;line-height:1.4;text-shadow:0 1px 1px rgba(0,0,0,.2)}.wps3f-badge--offloaded{background:#00a32a}.wps3f-badge--pending{background:#dba617}.wps3f-badge--failed{background:#d63638}.wps3f-badge--local{background:#646970}.wps3f-badge--detail{margin-left:8px;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;color:#fff}.attachment-info .wps3f-detail-row{margin-top:8px;font-size:12px;color:#50575e}
CSS;
        wp_add_inline_style('media-views', $css);

        // JS to render S3 status badges on media modal thumbnails.
        $badge_script = <<<'JS'
(function($){
    if (window.wps3fBadgeLoaded) return;
    window.wps3fBadgeLoaded = true;

    function badgeClass(state) {
        if (state === 'offloaded') return 'wps3f-badge wps3f-badge--offloaded';
        if (state === 'pending') return 'wps3f-badge wps3f-badge--pending';
        if (state === 'failed') return 'wps3f-badge wps3f-badge--failed';
        return 'wps3f-badge wps3f-badge--local';
    }

    function badgeText(wps3f) {
        if (!wps3f) return 'Local';
        if (wps3f.is_s3 && wps3f.has_local) return 'S3 + Local';
        if (wps3f.is_s3) return 'S3';
        if (wps3f.state === 'failed') return 'Failed';
        if (wps3f.state === 'pending') return 'Pending';
        return 'Local';
    }

    function addBadge($el, model) {
        $el.find('.wps3f-badge').remove();
        var wps3f = model.get('wps3f');
        if (!wps3f) return;
        var $thumb = $el.find('.thumbnail');
        if ($thumb.length) {
            $thumb.css('position','relative');
            $('<span class="' + badgeClass(wps3f.state) + '">' + badgeText(wps3f) + '</span>').appendTo($thumb);
        }
    }

    function addDetailBadge($el, model) {
        $el.find('.wps3f-detail-row').remove();
        var wps3f = model.get('wps3f');
        if (!wps3f) return;
        var cls = badgeClass(wps3f.state).replace('wps3f-badge', 'wps3f-badge--detail');
        var html = '<div class="wps3f-detail-row"><strong>S3:</strong> <span class="' + cls + '">' + badgeText(wps3f) + '</span>';
        if (wps3f.error) {
            html += ' <span style="color:#d63638;margin-left:4px;">' + $('<span>').text(wps3f.error).html() + '</span>';
        }
        html += '</div>';
        $el.find('.attachment-info .details').append(html);
    }

    $(document).on('attachment:ready', function(e, model) {
        var $el = $('.attachment[data-id="' + model.get('id') + '"]');
        if ($el.length) addBadge($el, model);
    });

    // Also hook into the Attachment Details sidebar view.
    var origRender = wp.media.view.Attachment.Details.prototype.render;
    wp.media.view.Attachment.Details.prototype.render = function() {
        var result = origRender.apply(this, arguments);
        if (this.model && this.model.get('wps3f')) {
            addDetailBadge(this.$el, this.model);
        }
        return result;
    };

    // Re-render badges when selection changes (sidebar detail view).
    if (wp.media && wp.media.frame) {
        wp.media.frame.on('selection:toggle', function() {
            var model = wp.media.frame.state().get('selection').first();
            if (model) {
                var $detail = $('.attachment-details');
                if ($detail.length) addDetailBadge($detail, model);
            }
        });
    }
})(jQuery);
JS;
        wp_add_inline_script('media-views', $badge_script);
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
