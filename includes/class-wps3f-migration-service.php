<?php
/**
 * Background migration for existing attachments.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPS3F_Migration_Service {
    const CRON_HOOK     = 'wps3f_run_migration_batch';
    const OPTION_STATE  = 'wps3f_migration_state';
    const DEFAULT_BATCH = 20;

    /**
     * @var WPS3F_Offloader
     */
    private $offloader;

    /**
     * @var WPS3F_Logger
     */
    private $logger;

    public function __construct(WPS3F_Offloader $offloader, WPS3F_Logger $logger) {
        $this->offloader = $offloader;
        $this->logger    = $logger;
    }

    /**
     * Start full migration from admin.
     */
    public function start() {
        $total = $this->count_attachments();

        $state = array(
            'running'     => true,
            'offset'      => 0,
            'processed'   => 0,
            'total'       => $total,
            'failed_ids'  => array(),
            'batch_size'  => self::DEFAULT_BATCH,
            'started_at'  => gmdate('c'),
            'updated_at'  => gmdate('c'),
        );

        update_option(self::OPTION_STATE, $state, false);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 3, self::CRON_HOOK);
        }
    }

    /**
     * Stop active migration.
     */
    public function stop() {
        $state = $this->get_state();
        $state['running']    = false;
        $state['updated_at'] = gmdate('c');
        update_option(self::OPTION_STATE, $state, false);

        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Retry all failed items collected by migration.
     */
    public function retry_failed() {
        $state = $this->get_state();

        if (!empty($state['failed_ids']) && is_array($state['failed_ids'])) {
            foreach ($state['failed_ids'] as $attachment_id) {
                $this->offloader->retry_attachment_offload((int) $attachment_id);
            }
        }

        $state['failed_ids'] = array();
        $state['updated_at'] = gmdate('c');
        update_option(self::OPTION_STATE, $state, false);
    }

    /**
     * Cron callback to migrate one batch.
     */
    public function run_batch() {
        $state = $this->get_state();
        if (empty($state['running'])) {
            return;
        }

        $batch_size = max(1, (int) $state['batch_size']);
        $offset     = max(0, (int) $state['offset']);

        $query = new WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => $batch_size,
                'offset'         => $offset,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
            )
        );

        $ids = is_array($query->posts) ? $query->posts : array();
        if (empty($ids)) {
            $state['running']    = false;
            $state['updated_at'] = gmdate('c');
            update_option(self::OPTION_STATE, $state, false);
            wp_clear_scheduled_hook(self::CRON_HOOK);
            return;
        }

        foreach ($ids as $attachment_id) {
            $ok = $this->offloader->offload_attachment((int) $attachment_id, false);
            if (!$ok) {
                $state['failed_ids'][] = (int) $attachment_id;
            }
        }

        $state['failed_ids'] = array_values(array_unique(array_map('intval', $state['failed_ids'])));
        $state['offset']      = $offset + count($ids);
        $state['processed']   = (int) $state['processed'] + count($ids);
        $state['updated_at']  = gmdate('c');

        if ($state['offset'] >= (int) $state['total']) {
            $state['running'] = false;
            wp_clear_scheduled_hook(self::CRON_HOOK);
        } else {
            wp_schedule_single_event(time() + 30, self::CRON_HOOK);
        }

        update_option(self::OPTION_STATE, $state, false);
    }

    /**
     * @return array<string,mixed>
     */
    public function get_state() {
        $stored = get_option(self::OPTION_STATE, array());
        if (!is_array($stored)) {
            $stored = array();
        }

        return wp_parse_args(
            $stored,
            array(
                'running'    => false,
                'offset'     => 0,
                'processed'  => 0,
                'total'      => 0,
                'failed_ids' => array(),
                'batch_size' => self::DEFAULT_BATCH,
                'started_at' => '',
                'updated_at' => '',
            )
        );
    }

    /**
     * @return int
     */
    private function count_attachments() {
        $counts = wp_count_posts('attachment');
        if (!is_object($counts)) {
            return 0;
        }

        $total = 0;
        foreach (get_object_vars($counts) as $count) {
            $total += (int) $count;
        }

        return $total;
    }
}
