<?php
/**
 * S3MO_CLI_Command — WP-CLI commands for CT S3 Offloader.
 *
 * Provides the `wp ct-s3` command namespace with subcommands for bulk
 * offloading, status checking, and tracking reset.
 *
 * @package CT_S3_Offloader
 */

defined('ABSPATH') || exit;

class S3MO_CLI_Command extends WP_CLI_Command {

    /** @var S3MO_Client */
    private S3MO_Client $client;

    /** @var S3MO_Bulk_Migrator */
    private S3MO_Bulk_Migrator $migrator;

    /** @var string|null Emergency memory buffer freed on shutdown. */
    private ?string $memory_buffer = null;

    /**
     * @param S3MO_Client $client Configured S3 client instance.
     */
    public function __construct(S3MO_Client $client) {
        $this->client   = $client;
        $this->migrator = new S3MO_Bulk_Migrator($client);
    }

    /**
     * Offload media attachments to S3.
     *
     * Uploads all un-offloaded media attachments to the configured S3 bucket.
     * Processes files in configurable batches with memory cleanup between each
     * batch. Failed files are retried twice with exponential backoff.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Show what would be uploaded without uploading anything.
     *
     * [--force]
     * : Re-upload files that are already marked as offloaded.
     *
     * [--batch-size=<number>]
     * : Number of attachments per batch. Default 50.
     * ---
     * default: 50
     * ---
     *
     * [--sleep=<seconds>]
     * : Seconds to pause between batches. Default 0.
     * ---
     * default: 0
     * ---
     *
     * [--mime-type=<type>]
     * : Only process attachments of this MIME type (e.g. image/jpeg).
     *
     * [--limit=<number>]
     * : Maximum number of attachments to process. Default 0 (unlimited).
     * ---
     * default: 0
     * ---
     *
     * ## EXAMPLES
     *
     *     # Dry run to see what would be uploaded
     *     $ wp ct-s3 offload --dry-run
     *
     *     # Offload all images in batches of 25
     *     $ wp ct-s3 offload --mime-type=image --batch-size=25
     *
     *     # Re-offload everything, limit to first 100
     *     $ wp ct-s3 offload --force --limit=100
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Associative arguments from flags.
     */
    public function offload(array $args, array $assoc_args): void {
        $dry_run    = \WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false);
        $force      = \WP_CLI\Utils\get_flag_value($assoc_args, 'force', false);
        $batch_size = (int) \WP_CLI\Utils\get_flag_value($assoc_args, 'batch-size', 50);
        $sleep_secs = (int) \WP_CLI\Utils\get_flag_value($assoc_args, 'sleep', 0);
        $mime_type  = \WP_CLI\Utils\get_flag_value($assoc_args, 'mime-type', null);
        $limit      = (int) \WP_CLI\Utils\get_flag_value($assoc_args, 'limit', 0);

        $this->register_shutdown_handler();

        // Count total eligible attachments.
        $total = $this->migrator->count_attachments($mime_type, $force);

        if ($total === 0) {
            WP_CLI::success('No attachments to offload.');
            return;
        }

        // Apply limit.
        if ($limit > 0 && $limit < $total) {
            $total = $limit;
        }

        WP_CLI::log(sprintf('Found %d attachment(s) to process.', $total));

        // Dry run — show table and exit.
        if ($dry_run) {
            $this->show_dry_run_table($mime_type, $force, $total);
            return;
        }

        // Live offload.
        $start_time = microtime(true);
        $processed  = 0;
        $success    = 0;
        $failed     = 0;
        $skipped    = 0;

        while ($processed < $total) {
            $remaining  = $total - $processed;
            $fetch_size = ($limit > 0) ? min($batch_size, $remaining) : $batch_size;
            $batch      = $this->migrator->get_next_batch($fetch_size, $mime_type, $force);

            if (empty($batch)) {
                break;
            }

            foreach ($batch as $attachment_id) {
                if ($processed >= $total) {
                    break;
                }

                $processed++;
                $info   = $this->migrator->get_attachment_info($attachment_id);
                $label  = $info['filename'] ?: "(ID: {$attachment_id})";

                WP_CLI::log(sprintf(
                    '[%d/%d] Uploading %s... ',
                    $processed,
                    $total,
                    $label
                ));

                $result = $this->migrator->upload_attachment($attachment_id, 2, $force);

                switch ($result['status']) {
                    case 'success':
                        $success++;
                        $file_count = $result['files'] ?? 1;
                        WP_CLI::log(WP_CLI::colorize("%GOK%n ({$file_count} file(s))"));
                        break;

                    case 'skip':
                        $skipped++;
                        $reason = $result['error'] ?? 'Skipped';
                        WP_CLI::log(WP_CLI::colorize("%YSkipped%n — {$reason}"));
                        break;

                    case 'fail':
                        $failed++;
                        $error = $result['error'] ?? 'Unknown error';
                        WP_CLI::log(WP_CLI::colorize("%RFAILED%n — {$error}"));
                        $this->log_to_file(sprintf(
                            'FAILED attachment %d (%s): %s',
                            $attachment_id,
                            $label,
                            $error
                        ));
                        break;
                }
            }

            // Memory cleanup between batches.
            $this->migrator->cleanup_memory();

            // Sleep between batches if configured.
            if ($sleep_secs > 0 && $processed < $total) {
                sleep($sleep_secs);
            }
        }

        // Completion summary.
        $elapsed = microtime(true) - $start_time;
        $this->show_summary($success, $failed, $skipped, $elapsed);
    }

    /**
     * Show migration status.
     *
     * Displays summary counts of offloaded, pending, and total attachments.
     * Use --verbose for a per-file table with offload status for each attachment.
     *
     * ## OPTIONS
     *
     * [--verbose]
     * : Show per-file status table instead of summary counts.
     *
     * [--mime-type=<type>]
     * : Filter by MIME type (e.g. image/jpeg).
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     # Show summary counts
     *     $ wp ct-s3 status
     *
     *     # Show per-file status table
     *     $ wp ct-s3 status --verbose
     *
     *     # Show only images in CSV format
     *     $ wp ct-s3 status --verbose --mime-type=image/jpeg --format=csv
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Associative arguments.
     */
    public function status(array $args, array $assoc_args): void {
        $verbose   = \WP_CLI\Utils\get_flag_value($assoc_args, 'verbose', false);
        $mime_type = \WP_CLI\Utils\get_flag_value($assoc_args, 'mime-type', null);
        $format    = \WP_CLI\Utils\get_flag_value($assoc_args, 'format', 'table');

        if ($verbose) {
            $statuses = $this->migrator->get_all_attachment_statuses($mime_type);

            if (empty($statuses)) {
                WP_CLI::log('No attachments found.');
                return;
            }

            \WP_CLI\Utils\format_items($format, $statuses, ['ID', 'Filename', 'MIME', 'Status', 'S3 Key']);
        } else {
            $counts = $this->migrator->get_status_counts($mime_type);

            $items = [
                ['Metric' => 'Total',     'Count' => $counts['total']],
                ['Metric' => 'Offloaded', 'Count' => $counts['offloaded']],
                ['Metric' => 'Pending',   'Count' => $counts['pending']],
            ];

            \WP_CLI\Utils\format_items($format, $items, ['Metric', 'Count']);
        }
    }

    /**
     * Reset offload tracking metadata.
     *
     * Clears all offload tracking metadata for attachments. Optionally deletes
     * the corresponding S3 objects before clearing metadata.
     *
     * ## OPTIONS
     *
     * [--delete-remote]
     * : Also delete S3 objects before clearing metadata.
     *
     * [--mime-type=<type>]
     * : Only reset attachments of this MIME type.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     # Reset tracking with confirmation prompt
     *     $ wp ct-s3 reset
     *
     *     # Reset and delete remote S3 objects
     *     $ wp ct-s3 reset --delete-remote
     *
     *     # Skip confirmation
     *     $ wp ct-s3 reset --yes
     *
     *     # Reset only images, delete remote, skip confirmation
     *     $ wp ct-s3 reset --mime-type=image/jpeg --delete-remote --yes
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Associative arguments.
     */
    public function reset(array $args, array $assoc_args): void {
        $delete_remote = \WP_CLI\Utils\get_flag_value($assoc_args, 'delete-remote', false);
        $mime_type     = \WP_CLI\Utils\get_flag_value($assoc_args, 'mime-type', null);

        // Check how many are offloaded before proceeding.
        $counts = $this->migrator->get_status_counts($mime_type);

        if ($counts['offloaded'] === 0) {
            WP_CLI::success('No offloaded attachments to reset.');
            return;
        }

        $message = sprintf(
            'This will clear offload tracking for %d attachment(s).',
            $counts['offloaded']
        );

        if ($delete_remote) {
            $message .= ' S3 objects will also be DELETED.';
        }

        WP_CLI::confirm($message, $assoc_args);

        $result = $this->migrator->reset_tracking($mime_type, $delete_remote);

        WP_CLI::log('');
        WP_CLI::log('--- Reset Summary ---');
        WP_CLI::log(sprintf('  Cleared: %d', $result['cleared']));

        if ($delete_remote) {
            WP_CLI::log(sprintf('  S3 objects deleted: %d', $result['deleted']));

            if ($result['delete_errors'] > 0) {
                WP_CLI::log(sprintf('  Delete errors: %d', $result['delete_errors']));
            }
        }

        WP_CLI::log('');

        if ($result['delete_errors'] > 0) {
            WP_CLI::warning(sprintf(
                '%d S3 object(s) could not be deleted.',
                $result['delete_errors']
            ));
        }

        WP_CLI::success(sprintf('Reset complete. %d attachment(s) cleared.', $result['cleared']));
    }

    /**
     * Display a dry-run table of files that would be uploaded.
     *
     * @param string|null $mime_type MIME type filter.
     * @param bool        $force     Include already-offloaded files.
     * @param int         $total     Total count to display.
     */
    private function show_dry_run_table(?string $mime_type, bool $force, int $total): void {
        WP_CLI::log('');
        WP_CLI::log(WP_CLI::colorize('%YDry run%n — no files will be uploaded.'));
        WP_CLI::log('');

        $items = [];
        $batch = $this->migrator->get_next_batch($total, $mime_type, $force);

        foreach ($batch as $attachment_id) {
            $info    = $this->migrator->get_attachment_info($attachment_id);
            $files   = $this->migrator->build_file_key_list($attachment_id);
            $items[] = [
                'ID'       => $info['id'],
                'Filename' => $info['filename'] ?: '(no file)',
                'MIME'     => $info['mime'],
                'Size'     => $this->format_bytes($info['size']),
                'Files'    => count($files),
            ];
        }

        WP_CLI\Utils\format_items('table', $items, ['ID', 'Filename', 'MIME', 'Size', 'Files']);

        WP_CLI::log('');
        WP_CLI::log(sprintf('Total: %d attachment(s) would be uploaded.', $total));
    }

    /**
     * Display the completion summary with counts and elapsed time.
     *
     * @param int   $success Number of successfully offloaded attachments.
     * @param int   $failed  Number of failed attachments.
     * @param int   $skipped Number of skipped attachments.
     * @param float $elapsed Elapsed time in seconds.
     */
    private function show_summary(int $success, int $failed, int $skipped, float $elapsed): void {
        WP_CLI::log('');
        WP_CLI::log('--- Migration Summary ---');
        WP_CLI::log(sprintf('  Success: %d', $success));
        WP_CLI::log(sprintf('  Failed:  %d', $failed));
        WP_CLI::log(sprintf('  Skipped: %d', $skipped));
        WP_CLI::log(sprintf('  Elapsed: %s', $this->format_elapsed($elapsed)));
        WP_CLI::log('');

        if ($failed > 0) {
            WP_CLI::warning(sprintf(
                '%d file(s) failed. Check log: wp-content/ct-s3-migration.log',
                $failed
            ));
        }

        if ($failed === 0 && $skipped === 0) {
            WP_CLI::success('All attachments offloaded successfully.');
        } elseif ($failed === 0) {
            WP_CLI::success('Offload complete (some files skipped).');
        }
    }

    /**
     * Append a line to the migration log file.
     *
     * @param string $message Log message.
     */
    private function log_to_file(string $message): void {
        $log_path = WP_CONTENT_DIR . '/ct-s3-migration.log';
        $line     = sprintf('[%s] %s' . PHP_EOL, gmdate('Y-m-d H:i:s'), $message);

        file_put_contents($log_path, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Register a shutdown handler with emergency memory buffer.
     *
     * Reserves 256KB of memory that is freed on shutdown, ensuring the
     * error handler can execute even when memory is exhausted.
     */
    private function register_shutdown_handler(): void {
        // Reserve 256KB emergency buffer.
        $this->memory_buffer = str_repeat('x', 256 * 1024);

        register_shutdown_function(function (): void {
            // Free the buffer so we have memory to work with.
            $this->memory_buffer = null;

            $error = error_get_last();

            if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $this->log_to_file(sprintf(
                    'FATAL: %s in %s on line %d',
                    $error['message'],
                    $error['file'],
                    $error['line']
                ));

                WP_CLI::error(sprintf(
                    'Fatal error during migration: %s (see ct-s3-migration.log)',
                    $error['message']
                ));
            }
        });
    }

    /**
     * Format bytes into a human-readable string.
     *
     * @param int $bytes Number of bytes.
     *
     * @return string Formatted size (e.g. "1.5 MB").
     */
    private function format_bytes(int $bytes): string {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i     = (int) floor(log($bytes, 1024));
        $i     = min($i, count($units) - 1);

        return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
    }

    /**
     * Format elapsed seconds into a human-readable duration.
     *
     * @param float $seconds Elapsed time in seconds.
     *
     * @return string Formatted duration (e.g. "2m 35s").
     */
    private function format_elapsed(float $seconds): string {
        $seconds = (int) round($seconds);

        if ($seconds < 60) {
            return $seconds . 's';
        }

        $minutes = (int) floor($seconds / 60);
        $secs    = $seconds % 60;

        if ($minutes < 60) {
            return sprintf('%dm %ds', $minutes, $secs);
        }

        $hours = (int) floor($minutes / 60);
        $mins  = $minutes % 60;

        return sprintf('%dh %dm %ds', $hours, $mins, $secs);
    }
}
