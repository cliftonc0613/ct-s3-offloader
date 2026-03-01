<?php
/**
 * S3MO_Stats — Storage statistics for CT S3 Offloader.
 *
 * Calculates and caches aggregate offload metrics: total files on S3,
 * total size, pending count, and last offload timestamp.
 *
 * All methods are static — same pattern as S3MO_Tracker.
 *
 * @package CT_S3_Offloader
 */

defined('ABSPATH') || exit;

class S3MO_Stats {

    /** @var string Transient key for cached stats. */
    private const CACHE_KEY = 's3mo_stats_cache';

    /** @var int Cache expiry in seconds (1 hour). */
    private const CACHE_TTL = HOUR_IN_SECONDS;

    /**
     * Calculate storage statistics from the database.
     *
     * @return array{total_files: int, total_size: int, pending: int, last_offloaded: string}
     */
    public static function calculate(): array {
        global $wpdb;

        /* Count offloaded attachments. */
        $offloaded_query = new WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'meta_query'     => [
                [
                    'key'   => '_s3mo_offloaded',
                    'value' => '1',
                ],
            ],
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ]);
        $offloaded_ids = $offloaded_query->posts;
        $total_files   = count($offloaded_ids);

        /* Count total attachments. */
        $total_query = new WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ]);
        $total_attachments = count($total_query->posts);
        $pending           = $total_attachments - $total_files;

        /* Sum file sizes from attachment metadata (best-effort). */
        $total_size = 0;
        $batches    = array_chunk($offloaded_ids, 100);

        foreach ($batches as $batch) {
            foreach ($batch as $attachment_id) {
                $metadata = wp_get_attachment_metadata($attachment_id);
                if (is_array($metadata) && isset($metadata['filesize'])) {
                    $total_size += (int) $metadata['filesize'];
                }
            }
        }

        /* Most recent offload timestamp. */
        $last_offloaded = (string) $wpdb->get_var(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_s3mo_offloaded_at'
             ORDER BY meta_value DESC LIMIT 1"
        );

        return [
            'total_files'    => $total_files,
            'total_size'     => $total_size,
            'pending'        => max(0, $pending),
            'last_offloaded' => $last_offloaded ?: '',
        ];
    }

    /**
     * Return cached stats, calculating if cache is empty.
     *
     * @return array{total_files: int, total_size: int, pending: int, last_offloaded: string}
     */
    public static function get_cached(): array {
        $cached = get_transient(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $stats = self::calculate();
        set_transient(self::CACHE_KEY, $stats, self::CACHE_TTL);

        return $stats;
    }

    /**
     * Force-refresh stats: delete cache, recalculate, and re-cache.
     *
     * @return array{total_files: int, total_size: int, pending: int, last_offloaded: string}
     */
    public static function refresh(): array {
        delete_transient(self::CACHE_KEY);

        $stats = self::calculate();
        set_transient(self::CACHE_KEY, $stats, self::CACHE_TTL);

        return $stats;
    }
}
