<?php
/**
 * S3MO_Tracker — Attachment offload tracking via WordPress postmeta.
 *
 * Persists S3 offload state for media attachments using four meta keys:
 *   _s3mo_offloaded    — boolean flag (1 or 0)
 *   _s3mo_key          — the S3 object key for the original file
 *   _s3mo_bucket       — bucket name at time of upload
 *   _s3mo_offloaded_at — ISO 8601 timestamp of when offload occurred
 *
 * All methods are static — no instance state is needed.
 *
 * @package CT_S3_Offloader
 */

defined('ABSPATH') || exit;

class S3MO_Tracker {

    /** @var string Meta key for offloaded boolean flag. */
    private const META_OFFLOADED = '_s3mo_offloaded';

    /** @var string Meta key for S3 object key. */
    private const META_KEY = '_s3mo_key';

    /** @var string Meta key for bucket name. */
    private const META_BUCKET = '_s3mo_bucket';

    /** @var string Meta key for offload timestamp. */
    private const META_OFFLOADED_AT = '_s3mo_offloaded_at';

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
    }
}
