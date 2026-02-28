<?php
/**
 * S3MO_Delete_Handler — Removes S3 objects when attachments are deleted from WordPress.
 *
 * Hooks into delete_attachment to delete the original file and all thumbnail
 * sizes from S3 before WordPress removes the attachment metadata.
 *
 * @package CT_S3_Offloader
 */

defined('ABSPATH') || exit;

class S3MO_Delete_Handler {

    /** @var S3MO_Client */
    private S3MO_Client $client;

    /**
     * @param S3MO_Client $client Configured S3 client instance.
     */
    public function __construct(S3MO_Client $client) {
        $this->client = $client;
    }

    /**
     * Register WordPress hooks for attachment deletion interception.
     */
    public function register_hooks(): void {
        add_action('delete_attachment', [$this, 'handle_delete'], 10, 2);
    }

    /**
     * Handle attachment deletion by removing all corresponding S3 objects.
     *
     * Fires on delete_attachment action BEFORE WordPress removes postmeta and
     * files, so the S3 key is still accessible via the tracker.
     *
     * Failed S3 deletions are logged but never thrown — WordPress must be
     * allowed to complete the media deletion regardless (DEL-04).
     *
     * @param int      $post_id The attachment post ID being deleted.
     * @param \WP_Post $post    The attachment post object.
     */
    public function handle_delete(int $post_id, \WP_Post $post): void {
        // Guard: Skip non-offloaded attachments — no S3 interaction needed.
        if (! S3MO_Tracker::is_offloaded($post_id)) {
            return;
        }

        // Collect all S3 keys (original + thumbnails).
        $keys = $this->collect_s3_keys($post_id);
        $keys = array_unique($keys);

        // Delete each S3 object independently.
        foreach ($keys as $key) {
            $result = $this->client->delete_object($key);

            if (! $result['success']) {
                error_log(
                    'CT S3 Offloader: Failed to delete S3 object: ' . $key
                    . ' - ' . ($result['error'] ?? 'Unknown error')
                );
            }
        }

        // Clear tracker metadata AFTER S3 deletions (need the key to delete first).
        S3MO_Tracker::clear_offload_status($post_id);
    }

    /**
     * Collect all S3 object keys for an attachment (original + thumbnails).
     *
     * Uses S3MO_Tracker::get_s3_key() for the original (includes full path prefix),
     * then derives thumbnail keys from the S3 key directory + metadata size filenames.
     *
     * @param int $post_id The attachment post ID.
     *
     * @return array List of S3 object keys.
     */
    private function collect_s3_keys(int $post_id): array {
        $s3_key = S3MO_Tracker::get_s3_key($post_id);

        if (empty($s3_key)) {
            return [];
        }

        $keys = [$s3_key];

        // Derive thumbnail keys from attachment metadata.
        $metadata = wp_get_attachment_metadata($post_id);

        if (! empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $s3_dir = dirname($s3_key);

            foreach ($metadata['sizes'] as $size_data) {
                $keys[] = $s3_dir . '/' . $size_data['file'];
            }
        }

        return $keys;
    }
}
