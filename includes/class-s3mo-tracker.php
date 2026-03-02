<?php
/**
 * S3MO_Tracker — Attachment offload tracking via WordPress postmeta.
 *
 * Persists S3 offload state for media attachments using five meta keys:
 *   _s3mo_offloaded    — boolean flag (1 or 0)
 *   _s3mo_key          — the S3 object key for the original file
 *   _s3mo_bucket       — bucket name at time of upload
 *   _s3mo_offloaded_at — ISO 8601 timestamp of when offload occurred
 *   _s3mo_error        — error message from last failed operation
 *
 * Also provides shared utility methods for building file lists from
 * attachment metadata, used by both Upload_Handler and Bulk_Migrator.
 *
 * All methods are static — no instance state is needed.
 *
 * @package CT_S3_Offloader
 */

defined('ABSPATH') || exit;

class S3MO_Tracker {

    /** @var string Meta key for offloaded boolean flag. */
    public const META_OFFLOADED = '_s3mo_offloaded';

    /** @var string Meta key for S3 object key. */
    public const META_KEY = '_s3mo_key';

    /** @var string Meta key for bucket name. */
    public const META_BUCKET = '_s3mo_bucket';

    /** @var string Meta key for offload timestamp. */
    public const META_OFFLOADED_AT = '_s3mo_offloaded_at';

    /** @var string Meta key for error message. */
    public const META_ERROR = '_s3mo_error';

    /**
     * Mark an attachment as offloaded to S3.
     *
     * Stores the S3 key, bucket name, offloaded flag, and current timestamp
     * as postmeta on the attachment.
     *
     * @param int    $attachment_id WordPress attachment post ID.
     * @param string $s3_key        The S3 object key.
     * @param string $bucket        The S3 bucket name.
     */
    public static function mark_as_offloaded(int $attachment_id, string $s3_key, string $bucket): void {
        update_post_meta($attachment_id, self::META_OFFLOADED, '1');
        update_post_meta($attachment_id, self::META_KEY, $s3_key);
        update_post_meta($attachment_id, self::META_BUCKET, $bucket);
        update_post_meta($attachment_id, self::META_OFFLOADED_AT, gmdate('c'));
    }

    /**
     * Check whether an attachment has been offloaded to S3.
     *
     * @param int $attachment_id WordPress attachment post ID.
     *
     * @return bool True if the attachment is marked as offloaded.
     */
    public static function is_offloaded(int $attachment_id): bool {
        return (bool) get_post_meta($attachment_id, self::META_OFFLOADED, true);
    }

    /**
     * Get the S3 object key for an offloaded attachment.
     *
     * @param int $attachment_id WordPress attachment post ID.
     *
     * @return string The S3 key, or empty string if not set.
     */
    public static function get_s3_key(int $attachment_id): string {
        return (string) get_post_meta($attachment_id, self::META_KEY, true);
    }

    /**
     * Get all offload information for an attachment.
     *
     * @param int $attachment_id WordPress attachment post ID.
     *
     * @return array{offloaded: string, key: string, bucket: string, offloaded_at: string}
     */
    public static function get_offload_info(int $attachment_id): array {
        return [
            'offloaded'    => (string) get_post_meta($attachment_id, self::META_OFFLOADED, true),
            'key'          => (string) get_post_meta($attachment_id, self::META_KEY, true),
            'bucket'       => (string) get_post_meta($attachment_id, self::META_BUCKET, true),
            'offloaded_at' => (string) get_post_meta($attachment_id, self::META_OFFLOADED_AT, true),
        ];
    }

    /**
     * Clear all offload metadata for an attachment.
     *
     * Removes all four meta keys, effectively marking the attachment as
     * no longer offloaded.
     *
     * @param int $attachment_id WordPress attachment post ID.
     */
    public static function clear_offload_status(int $attachment_id): void {
        delete_post_meta($attachment_id, self::META_OFFLOADED);
        delete_post_meta($attachment_id, self::META_KEY);
        delete_post_meta($attachment_id, self::META_BUCKET);
        delete_post_meta($attachment_id, self::META_OFFLOADED_AT);
        delete_post_meta($attachment_id, self::META_ERROR);
    }

    /**
     * Build the list of local file paths and S3 keys from attachment metadata.
     *
     * Returns entries for the original file and all generated thumbnails.
     * Shared utility used by S3MO_Upload_Handler and S3MO_Bulk_Migrator to
     * eliminate duplicated key-building logic.
     *
     * @param array $metadata Attachment metadata from wp_get_attachment_metadata().
     *
     * @return array<int, array{local: string, key: string}> File list, empty if no file in metadata.
     */
    public static function build_file_list(array $metadata): array {
        if (empty($metadata['file'])) {
            return [];
        }

        $prefix     = get_option('s3mo_path_prefix', 'wp-content/uploads');
        $upload_dir = wp_get_upload_dir();
        $files      = [];

        // Original file.
        $files[] = [
            'local' => $upload_dir['basedir'] . '/' . $metadata['file'],
            'key'   => $prefix . '/' . $metadata['file'],
        ];

        // Thumbnails — filename only, directory derived from original.
        if (! empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $subdir = dirname($metadata['file']);

            foreach ($metadata['sizes'] as $size_data) {
                $files[] = [
                    'local' => $upload_dir['basedir'] . '/' . $subdir . '/' . $size_data['file'],
                    'key'   => $prefix . '/' . $subdir . '/' . $size_data['file'],
                ];
            }
        }

        return $files;
    }

    /**
     * Store an error message for an attachment.
     *
     * @param int    $attachment_id WordPress attachment post ID.
     * @param string $message       Error message to store.
     */
    public static function set_error(int $attachment_id, string $message): void {
        update_post_meta($attachment_id, self::META_ERROR, $message);
    }

    /**
     * Clear any stored error for an attachment.
     *
     * @param int $attachment_id WordPress attachment post ID.
     */
    public static function clear_error(int $attachment_id): void {
        delete_post_meta($attachment_id, self::META_ERROR);
    }
}
