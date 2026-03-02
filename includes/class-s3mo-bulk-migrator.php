<?php
/**
 * S3MO_Bulk_Migrator — Batch processing engine for bulk media offloading.
 *
 * Provides methods to query un-offloaded attachments, build file key lists,
 * upload with retry logic, and manage memory between batches. Designed to be
 * consumed by CLI commands or future admin UI — no output/formatting here.
 *
 * @package CT_S3_Offloader
 */

defined('ABSPATH') || exit;

class S3MO_Bulk_Migrator {

    /** @var S3MO_Client */
    private S3MO_Client $client;

    /**
     * @param S3MO_Client $client Configured S3 client instance.
     */
    public function __construct(S3MO_Client $client) {
        $this->client = $client;
    }

    /**
     * Count attachments eligible for offloading.
     *
     * When $force is false, excludes attachments already marked as offloaded.
     * When $mime_type is provided, filters to that MIME type.
     *
     * @param string|null $mime_type Optional MIME type filter (e.g. 'image/jpeg').
     * @param bool        $force     When true, include already-offloaded attachments.
     *
     * @return int Number of matching attachments.
     */
    public function count_attachments(?string $mime_type, bool $force): int {
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'posts_per_page' => -1,
        ];

        if (! $force) {
            $args['meta_query'] = [
                'relation' => 'OR',
                [
                    'key'     => S3MO_Tracker::META_OFFLOADED,
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => S3MO_Tracker::META_OFFLOADED,
                    'value'   => '1',
                    'compare' => '!=',
                ],
            ];
        }

        if ($mime_type !== null) {
            $args['post_mime_type'] = $mime_type;
        }

        $query = new \WP_Query($args);

        return count($query->posts);
    }

    /**
     * Get the next batch of attachment IDs to process.
     *
     * Returns up to $batch_size attachment IDs ordered by ID ascending.
     * Uses the same filtering logic as count_attachments().
     *
     * @param int         $batch_size Number of attachments to fetch.
     * @param string|null $mime_type  Optional MIME type filter.
     * @param bool        $force      When true, include already-offloaded attachments.
     *
     * @return int[] Array of attachment post IDs.
     */
    public function get_next_batch(int $batch_size, ?string $mime_type, bool $force): array {
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'posts_per_page' => $batch_size,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ];

        if (! $force) {
            $args['meta_query'] = [
                'relation' => 'OR',
                [
                    'key'     => S3MO_Tracker::META_OFFLOADED,
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => S3MO_Tracker::META_OFFLOADED,
                    'value'   => '1',
                    'compare' => '!=',
                ],
            ];
        }

        if ($mime_type !== null) {
            $args['post_mime_type'] = $mime_type;
        }

        $query = new \WP_Query($args);

        return array_map('intval', $query->posts);
    }

    /**
     * Build the list of local file paths and S3 keys for an attachment.
     *
     * Includes the original file and all generated thumbnails. Uses the same
     * path-building logic as S3MO_Upload_Handler::handle_upload().
     *
     * @param int $attachment_id WordPress attachment post ID.
     *
     * @return array<int, array{local: string, key: string}> File list, empty if no metadata.
     */
    public function build_file_key_list(int $attachment_id): array {
        $metadata = wp_get_attachment_metadata($attachment_id);

        if (! is_array($metadata) || empty($metadata['file'])) {
            return [];
        }

        return S3MO_Tracker::build_file_list($metadata);
    }

    /**
     * Upload a single attachment (all sizes) to S3 with retry logic.
     *
     * Retries failed uploads up to $max_retries times with exponential backoff.
     * On full success, marks the attachment as offloaded via S3MO_Tracker.
     *
     * @param int  $attachment_id WordPress attachment post ID.
     * @param int  $max_retries   Number of retry attempts after first failure (default 2).
     * @param bool $force         When true, re-upload even if already offloaded.
     *
     * @return array{status: string, files?: int, error?: string}
     */
    public function upload_attachment(int $attachment_id, int $max_retries = 2, bool $force = false): array {
        // Skip already-offloaded unless forced.
        if (! $force && S3MO_Tracker::is_offloaded($attachment_id)) {
            return ['status' => 'skip', 'error' => 'Already offloaded'];
        }

        $files = $this->build_file_key_list($attachment_id);

        if (empty($files)) {
            return ['status' => 'skip', 'error' => 'No file in metadata'];
        }

        $last_error  = '';
        $max_attempts = 1 + $max_retries;

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $all_success = true;
            $last_error  = '';

            foreach ($files as $file) {
                if (! file_exists($file['local'])) {
                    $last_error  = 'File not found: ' . $file['local'];
                    $all_success = false;
                    break;
                }

                $content_type = mime_content_type($file['local']) ?: 'application/octet-stream';

                $result = $this->client->upload_object($file['key'], $file['local'], $content_type);

                if (! $result['success']) {
                    $last_error  = $result['error'] ?? 'Unknown upload error';
                    $all_success = false;
                    break;
                }
            }

            if ($all_success) {
                S3MO_Tracker::mark_as_offloaded(
                    $attachment_id,
                    $files[0]['key'],
                    $this->client->get_bucket()
                );
                S3MO_Tracker::clear_error($attachment_id);

                // DEBT-01: Delete local files after successful S3 upload.
                if (get_option('s3mo_delete_local', false)) {
                    foreach ($files as $file) {
                        if (file_exists($file['local'])) {
                            if (! @unlink($file['local'])) {
                                error_log('CT S3 Offloader: Failed to delete local file ' . $file['local']);
                            }
                        }
                    }
                }

                return ['status' => 'success', 'files' => count($files)];
            }

            // Exponential backoff before retry (skip on last attempt).
            if ($attempt < $max_attempts) {
                sleep(pow(2, $attempt - 1));
            }
        }

        // DEBT-03: Write error postmeta on failure.
        S3MO_Tracker::set_error($attachment_id, $last_error);

        return ['status' => 'fail', 'error' => $last_error];
    }

    /**
     * Free memory between batch iterations.
     *
     * Flushes the WordPress object cache, clears logged queries,
     * and runs PHP garbage collection.
     */
    public function cleanup_memory(): void {
        global $wpdb;

        wp_cache_flush();
        $wpdb->queries = [];
        gc_collect_cycles();
    }

    /**
     * Get summary counts of offloaded, pending, and total attachments.
     *
     * @param string|null $mime_type Optional MIME type filter (e.g. 'image/jpeg').
     *
     * @return array{total: int, offloaded: int, pending: int}
     */
    public function get_status_counts(?string $mime_type = null): array {
        // Total attachments.
        $total_args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'posts_per_page' => -1,
        ];

        if ($mime_type !== null) {
            $total_args['post_mime_type'] = $mime_type;
        }

        $total_query = new \WP_Query($total_args);
        $total       = count($total_query->posts);

        // Offloaded attachments.
        $offloaded_args = $total_args;
        $offloaded_args['meta_query'] = [
            [
                'key'     => S3MO_Tracker::META_OFFLOADED,
                'value'   => '1',
                'compare' => '=',
            ],
        ];

        $offloaded_query = new \WP_Query($offloaded_args);
        $offloaded       = count($offloaded_query->posts);

        return [
            'total'     => $total,
            'offloaded' => $offloaded,
            'pending'   => $total - $offloaded,
        ];
    }

    /**
     * Get per-file status for all attachments.
     *
     * Returns an array of status rows with ID, Filename, MIME, Status, and
     * S3 Key for each attachment. Processes in batches of 100 with memory
     * cleanup between batches.
     *
     * @param string|null $mime_type Optional MIME type filter.
     *
     * @return array<int, array{ID: int, Filename: string, MIME: string, Status: string, 'S3 Key': string}>
     */
    public function get_all_attachment_statuses(?string $mime_type = null): array {
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ];

        if ($mime_type !== null) {
            $args['post_mime_type'] = $mime_type;
        }

        $query = new \WP_Query($args);
        $ids   = array_map('intval', $query->posts);

        $statuses = [];
        $count    = 0;

        foreach ($ids as $attachment_id) {
            $metadata  = wp_get_attachment_metadata($attachment_id);
            $filename  = ! empty($metadata['file']) ? basename($metadata['file']) : '(no file)';
            $mime      = (string) get_post_mime_type($attachment_id);
            $offloaded = S3MO_Tracker::is_offloaded($attachment_id);
            $s3_key    = $offloaded ? S3MO_Tracker::get_s3_key($attachment_id) : '';

            $statuses[] = [
                'ID'     => $attachment_id,
                'Filename' => $filename,
                'MIME'   => $mime,
                'Status' => $offloaded ? 'offloaded' : 'pending',
                'S3 Key' => $s3_key,
            ];

            $count++;

            if ($count % 100 === 0) {
                $this->cleanup_memory();
            }
        }

        return $statuses;
    }

    /**
     * Reset offload tracking metadata for attachments.
     *
     * Clears the S3MO_Tracker metadata for all offloaded attachments. When
     * $delete_remote is true, deletes S3 objects (original + thumbnails)
     * before clearing metadata.
     *
     * @param string|null $mime_type      Optional MIME type filter.
     * @param bool        $delete_remote  When true, delete S3 objects before clearing metadata.
     *
     * @return array{cleared: int, deleted: int, delete_errors: int}
     */
    public function reset_tracking(?string $mime_type = null, bool $delete_remote = false): array {
        // Query offloaded attachments.
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_query'     => [
                [
                    'key'     => S3MO_Tracker::META_OFFLOADED,
                    'value'   => '1',
                    'compare' => '=',
                ],
            ],
        ];

        if ($mime_type !== null) {
            $args['post_mime_type'] = $mime_type;
        }

        $query = new \WP_Query($args);
        $ids   = array_map('intval', $query->posts);

        $cleared       = 0;
        $deleted       = 0;
        $delete_errors = 0;
        $count         = 0;

        foreach ($ids as $attachment_id) {
            // Delete remote S3 objects if requested.
            if ($delete_remote) {
                $files = $this->build_file_key_list($attachment_id);

                // Fall back to tracker key if metadata is missing.
                if (empty($files)) {
                    $tracker_key = S3MO_Tracker::get_s3_key($attachment_id);

                    if (! empty($tracker_key)) {
                        $result = $this->client->delete_object($tracker_key);

                        if ($result['success']) {
                            $deleted++;
                        } else {
                            $delete_errors++;
                        }
                    }
                } else {
                    foreach ($files as $file) {
                        $result = $this->client->delete_object($file['key']);

                        if ($result['success']) {
                            $deleted++;
                        } else {
                            $delete_errors++;
                        }
                    }
                }
            }

            S3MO_Tracker::clear_offload_status($attachment_id);
            $cleared++;

            $count++;

            if ($count % 100 === 0) {
                $this->cleanup_memory();
            }
        }

        return [
            'cleared'       => $cleared,
            'deleted'       => $deleted,
            'delete_errors' => $delete_errors,
        ];
    }

    /**
     * Get display information for an attachment.
     *
     * @param int $attachment_id WordPress attachment post ID.
     *
     * @return array{id: int, filename: string, size: int, mime: string}
     */
    public function get_attachment_info(int $attachment_id): array {
        $metadata = wp_get_attachment_metadata($attachment_id);
        $filename = '';
        $size     = 0;

        if (! empty($metadata['file'])) {
            $filename   = basename($metadata['file']);
            $upload_dir = wp_get_upload_dir();
            $local_path = $upload_dir['basedir'] . '/' . $metadata['file'];

            if (file_exists($local_path)) {
                $size = (int) filesize($local_path);
            }
        }

        return [
            'id'       => $attachment_id,
            'filename' => $filename,
            'size'     => $size,
            'mime'     => (string) get_post_mime_type($attachment_id),
        ];
    }
}
