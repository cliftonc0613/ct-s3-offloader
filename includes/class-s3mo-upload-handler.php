<?php
/**
 * S3MO_Upload_Handler — Intercepts WordPress media uploads and offloads to S3.
 *
 * Hooks into wp_generate_attachment_metadata to upload the original file and
 * all generated thumbnails to S3 after WordPress processes the upload.
 *
 * @package CT_S3_Offloader
 */

defined('ABSPATH') || exit;

class S3MO_Upload_Handler {

    /** @var S3MO_Client */
    private S3MO_Client $client;

    /**
     * @param S3MO_Client $client Configured S3 client instance.
     */
    public function __construct(S3MO_Client $client) {
        $this->client = $client;
    }

    /**
     * Register WordPress hooks for upload interception.
     */
    public function register_hooks(): void {
        add_filter('wp_generate_attachment_metadata', [$this, 'handle_upload'], 10, 3);
    }

    /**
     * Handle a media upload by offloading files to S3.
     *
     * Fires on wp_generate_attachment_metadata filter. MUST always return
     * $metadata unchanged — it is a filter, not an action.
     *
     * @param array  $metadata      Attachment metadata array.
     * @param int    $attachment_id  WordPress attachment post ID.
     * @param string $context        Upload context ('create', 'update', etc.).
     *
     * @return array Unmodified $metadata.
     */
    public function handle_upload(array $metadata, int $attachment_id, string $context): array {
        // Guard: Only process new uploads.
        if ($context !== 'create') {
            return $metadata;
        }

        // Guard: Must have a file path in metadata.
        if (empty($metadata['file'])) {
            return $metadata;
        }

        // Guard: Already offloaded (idempotency).
        if (S3MO_Tracker::is_offloaded($attachment_id)) {
            return $metadata;
        }

        // Build list of files to upload.
        $upload_dir = wp_get_upload_dir();
        $prefix     = get_option('s3mo_path_prefix', 'wp-content/uploads');
        $mime       = get_post_mime_type($attachment_id);
        $files      = [];

        // Original file.
        $files[] = [
            'local' => $upload_dir['basedir'] . '/' . $metadata['file'],
            'key'   => $prefix . '/' . $metadata['file'],
            'mime'  => $mime,
        ];

        // Thumbnails — file value is FILENAME ONLY, directory from original.
        if (! empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $subdir = dirname($metadata['file']);

            foreach ($metadata['sizes'] as $size_data) {
                $files[] = [
                    'local' => $upload_dir['basedir'] . '/' . $subdir . '/' . $size_data['file'],
                    'key'   => $prefix . '/' . $subdir . '/' . $size_data['file'],
                    'mime'  => $size_data['mime-type'],
                ];
            }
        }

        // Upload each file independently.
        $errors        = [];
        $success_count = 0;

        foreach ($files as $file) {
            try {
                if (! file_exists($file['local'])) {
                    $errors[] = 'File not found: ' . $file['local'];
                    continue;
                }

                $result = $this->client->upload_object($file['key'], $file['local'], $file['mime']);

                if ($result['success']) {
                    $success_count++;
                } else {
                    $errors[] = $file['key'] . ' — ' . ($result['error'] ?? 'Unknown error');
                }
            } catch (\Throwable $e) {
                $errors[] = $file['key'] . ' — ' . $e->getMessage();
            }
        }

        // Track result.
        $total = count($files);

        if ($success_count === $total) {
            // All files uploaded successfully.
            S3MO_Tracker::mark_as_offloaded($attachment_id, $files[0]['key'], $this->client->get_bucket());
        } elseif ($success_count > 0) {
            // Partial upload — log but do NOT mark as offloaded.
            error_log(
                'CT S3 Offloader: Partial upload for attachment ' . $attachment_id
                . ' (' . $success_count . '/' . $total . '). Errors: '
                . implode('; ', $errors)
            );
        } else {
            // Complete failure — log but do NOT mark as offloaded.
            error_log(
                'CT S3 Offloader: Failed to upload attachment ' . $attachment_id
                . '. Errors: ' . implode('; ', $errors)
            );
        }

        // Log individual upload failures.
        foreach ($errors as $error) {
            error_log('CT S3 Offloader: Failed to upload ' . $error);
        }

        return $metadata;
    }
}
