<?php
/**
 * CT S3 Offloader — Uninstall
 *
 * Removes all plugin data when deleted through the WordPress admin.
 * Optionally deletes S3 objects if the user enabled that setting.
 *
 * @package CT_S3_Offloader
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

/* ─── Optional: Delete S3 Objects ──────────────────────────────────── */

if (get_option('s3mo_delete_s3_on_uninstall')) {
    $plugin_dir = plugin_dir_path(__FILE__);
    $sdk_autoloader = $plugin_dir . 'aws-sdk/aws-autoloader.php';

    $can_delete_s3 = defined('S3MO_BUCKET')
        && defined('S3MO_REGION')
        && defined('S3MO_KEY')
        && defined('S3MO_SECRET')
        && file_exists($sdk_autoloader);

    if ($can_delete_s3) {
        require_once $sdk_autoloader;

        try {
            $s3 = new Aws\S3\S3Client([
                'version'     => 'latest',
                'region'      => S3MO_REGION,
                'credentials' => [
                    'key'    => S3MO_KEY,
                    'secret' => S3MO_SECRET,
                ],
            ]);

            $offset = 0;
            $batch_size = 100;

            while (true) {
                $query = new WP_Query([
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'meta_query'     => [
                        [
                            'key'   => '_s3mo_offloaded',
                            'value' => '1',
                        ],
                    ],
                    'fields'         => 'ids',
                    'posts_per_page' => $batch_size,
                    'offset'         => $offset,
                    'no_found_rows'  => true,
                ]);

                if (empty($query->posts)) {
                    break;
                }

                $objects = [];

                foreach ($query->posts as $attachment_id) {
                    $s3_key = get_post_meta($attachment_id, '_s3mo_key', true);
                    if ($s3_key) {
                        $objects[] = ['Key' => $s3_key];
                    }

                    /* Also delete thumbnail keys. */
                    $metadata = wp_get_attachment_metadata($attachment_id);
                    if (is_array($metadata) && ! empty($metadata['sizes'])) {
                        $base_dir = dirname($s3_key);
                        foreach ($metadata['sizes'] as $size_data) {
                            if (! empty($size_data['file'])) {
                                $objects[] = ['Key' => $base_dir . '/' . $size_data['file']];
                            }
                        }
                    }
                }

                if (! empty($objects)) {
                    $s3->deleteObjects([
                        'Bucket' => S3MO_BUCKET,
                        'Delete' => [
                            'Objects' => $objects,
                            'Quiet'   => true,
                        ],
                    ]);
                }

                $offset += $batch_size;
                wp_cache_flush();
            }
        } catch (Exception $e) {
            /* Log error but do not halt uninstall. */
            error_log('CT S3 Offloader uninstall — S3 deletion error: ' . $e->getMessage());
        }
    }
}

/* ─── Remove All Postmeta ──────────────────────────────────────────── */

delete_post_meta_by_key('_s3mo_offloaded');
delete_post_meta_by_key('_s3mo_key');
delete_post_meta_by_key('_s3mo_bucket');
delete_post_meta_by_key('_s3mo_offloaded_at');
delete_post_meta_by_key('_s3mo_error');

/* ─── Remove Plugin Options ────────────────────────────────────────── */

delete_option('s3mo_delete_local');
delete_option('s3mo_path_prefix');
delete_option('s3mo_delete_s3_on_uninstall');

/* ─── Remove Transients ────────────────────────────────────────────── */

delete_transient('s3mo_connection_status');
delete_transient('s3mo_stats_cache');

/* ─── Remove Log File ──────────────────────────────────────────────── */

$log_file = WP_CONTENT_DIR . '/ct-s3-migration.log';
if (file_exists($log_file)) {
    @unlink($log_file);
}
