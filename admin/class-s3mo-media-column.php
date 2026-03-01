<?php
/**
 * S3MO_Media_Column — Offload status column in Media Library list view.
 *
 * Adds a custom "Offload" column to the Media Library list table showing
 * whether each attachment is on S3 or local, with a clickable detail popup
 * for offloaded items.
 *
 * @package CT_S3_Offloader
 */

defined('ABSPATH') || exit;

class S3MO_Media_Column {

    /**
     * Register all hooks for the media column.
     */
    public function register_hooks(): void {
        add_filter('manage_media_columns', [$this, 'add_column']);
        add_action('manage_media_custom_column', [$this, 'render_column'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Add the "Offload" column header to Media Library list view.
     *
     * @param array $columns Existing columns.
     * @return array Modified columns.
     */
    public function add_column(array $columns): array {
        $columns['s3mo_status'] = 'Offload';
        return $columns;
    }

    /**
     * Render the offload status for each attachment row.
     *
     * @param string $column_name Current column key.
     * @param int    $post_id     Attachment post ID.
     */
    public function render_column(string $column_name, int $post_id): void {
        if ($column_name !== 's3mo_status') {
            return;
        }

        $info = S3MO_Tracker::get_offload_info($post_id);

        $error = get_post_meta($post_id, S3MO_Tracker::META_ERROR, true);

        if (! empty($error)) {
            $this->render_error_status();
        } elseif ($info['offloaded'] === '1') {
            $this->render_s3_status($post_id, $info);
        } else {
            $this->render_local_status();
        }
    }

    /**
     * Render the S3 offloaded status with detail popup.
     *
     * @param int   $post_id Attachment post ID.
     * @param array $info    Offload info from tracker.
     */
    private function render_s3_status(int $post_id, array $info): void {
        $time_ago = '';
        if (! empty($info['offloaded_at'])) {
            $time_ago = human_time_diff(
                strtotime($info['offloaded_at']),
                current_time('timestamp')
            ) . ' ago';
        }

        ?>
        <button type="button" class="s3mo-status-toggle" data-id="<?php echo esc_attr($post_id); ?>">
            <span class="s3mo-status-dot s3mo-status-s3"></span>S3
        </button>
        <div class="s3mo-details" id="s3mo-details-<?php echo esc_attr($post_id); ?>">
            <strong>Key:</strong> <?php echo esc_html($info['key']); ?><br>
            <strong>Bucket:</strong> <?php echo esc_html($info['bucket']); ?><br>
            <?php if ($time_ago) : ?>
                <strong>Uploaded:</strong> <?php echo esc_html($time_ago); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the local (not offloaded) status.
     */
    private function render_local_status(): void {
        echo '<span class="s3mo-status-dot s3mo-status-local"></span>Local';
    }

    /**
     * Render the error status.
     */
    private function render_error_status(): void {
        echo '<span class="s3mo-status-dot s3mo-status-error"></span>Error';
    }

    /**
     * Enqueue admin CSS on the upload (Media Library) page.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueue_assets(string $hook): void {
        if ($hook !== 'upload.php') {
            return;
        }

        wp_enqueue_style(
            's3mo-admin',
            S3MO_PLUGIN_URL . 'assets/css/admin.css',
            [],
            S3MO_VERSION
        );

        wp_enqueue_script(
            's3mo-admin',
            S3MO_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            S3MO_VERSION,
            true
        );
    }
}
