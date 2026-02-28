# Phase 5: Bulk Migration - Research

**Researched:** 2026-02-28
**Domain:** WP-CLI command development, batch processing, memory management
**Confidence:** HIGH

## Summary

This phase implements three WP-CLI commands (`offload`, `status`, `reset`) under the `ct-s3` namespace for migrating an existing media library to S3. The existing codebase provides solid building blocks: `S3MO_Upload_Handler` contains the file-building and upload logic, `S3MO_Tracker` provides idempotent offload tracking via postmeta, and `S3MO_Client` wraps the AWS SDK upload/delete operations.

The primary technical challenges are: (1) efficiently querying and iterating over potentially thousands of attachments, (2) managing PHP memory during long-running batch operations, (3) providing fault tolerance with per-file retry and graceful shutdown handling, and (4) following WP-CLI output conventions (colorized output, table formatting, progress counters).

**Primary recommendation:** Extract the file-list-building and single-attachment-upload logic from `S3MO_Upload_Handler` into a reusable method (or a new `S3MO_Bulk_Migrator` class), then build the WP-CLI command class as a thin orchestration layer that handles batching, progress output, and error aggregation.

## Standard Stack

### Core (Already in Project)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| WP-CLI | 2.12.0 | CLI framework (already installed) | WordPress standard CLI |
| AWS SDK for PHP | bundled | S3 uploads via ObjectUploader | Already integrated in plugin |
| S3MO_Client | n/a | S3 client wrapper | Existing plugin class |
| S3MO_Tracker | n/a | Postmeta-based offload tracking | Existing plugin class |
| S3MO_Upload_Handler | n/a | Upload logic (file list + S3 upload) | Existing plugin class |

### WP-CLI Built-in Utilities (No Installation Required)

| Utility | Purpose | When to Use |
|---------|---------|-------------|
| `WP_CLI::add_command()` | Register commands | Command registration |
| `WP_CLI::log()` | Info output (respects --quiet) | Per-file progress lines |
| `WP_CLI::success()` | Green "Success:" prefix | Completion message |
| `WP_CLI::warning()` | Yellow "Warning:" prefix | Skip/retry notices |
| `WP_CLI::error()` | Red "Error:" + halt | Fatal errors only |
| `WP_CLI::colorize()` | Color tokens in strings | Custom colored output |
| `WP_CLI::confirm()` | Interactive y/n prompt | Reset command confirmation |
| `\WP_CLI\Utils\format_items()` | Table/CSV/JSON output | Dry-run table, status output |
| `\WP_CLI\Utils\make_progress_bar()` | Progress bar with tick/finish | NOT USED (per-file line output chosen instead) |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `WP_CLI\Utils\make_progress_bar()` | Per-file line output with counter | Progress bar hides individual file names; per-file output is more informative for debugging. User decision: per-file lines. |
| Direct SQL for attachment queries | `WP_Query` / `get_posts()` | Direct SQL is faster but bypasses WP caching and filters. `get_posts()` with `no_found_rows` is fast enough and safer. |
| VIP `vip_inmemory_cleanup()` | Manual `wp_cache_flush()` + `$wpdb->queries = []` | VIP helper not available outside VIP; implement equivalent manually. |

## Architecture Patterns

### Recommended File Structure

```
includes/
  class-s3mo-cli-command.php     # WP-CLI command class (offload, status, reset)
  class-s3mo-bulk-migrator.php   # Batch processing engine (decoupled from CLI)
  class-s3mo-client.php          # (existing)
  class-s3mo-tracker.php         # (existing)
  class-s3mo-upload-handler.php  # (existing)
```

### Pattern 1: WP-CLI Command Registration via `cli_init` Hook

**What:** Register commands only when WP-CLI is running using the `cli_init` action hook.
**When to use:** Always for WP-CLI commands in plugins.
**Why:** Avoids loading command classes during web requests.

```php
// In ct-s3-offloader.php (main plugin file)
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('ct-s3', 'S3MO_CLI_Command');
}
```

### Pattern 2: Class-Based Command with Subcommands

**What:** A single class where each public method is a subcommand.
**When to use:** When grouping related commands under one namespace.

```php
/**
 * Manage S3 media offloading.
 *
 * ## EXAMPLES
 *
 *     wp ct-s3 offload
 *     wp ct-s3 offload --dry-run
 *     wp ct-s3 status
 *     wp ct-s3 reset
 */
class S3MO_CLI_Command extends WP_CLI_Command {

    /**
     * Offload media library files to S3.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Show what would be uploaded without making changes.
     *
     * [--force]
     * : Re-upload files already marked as offloaded.
     *
     * [--batch-size=<number>]
     * : Files per batch. Default 100.
     * ---
     * default: 100
     * ---
     *
     * [--sleep=<seconds>]
     * : Seconds to pause between batches.
     * ---
     * default: 0
     * ---
     *
     * [--mime-type=<type>]
     * : Filter by MIME type (e.g., image/jpeg).
     *
     * [--limit=<number>]
     * : Maximum number of files to process.
     *
     * ## EXAMPLES
     *
     *     wp ct-s3 offload
     *     wp ct-s3 offload --dry-run
     *     wp ct-s3 offload --batch-size=25 --sleep=2
     *     wp ct-s3 offload --mime-type=image/jpeg --limit=50
     *
     * @when after_wp_load
     */
    public function offload($args, $assoc_args) {
        // Implementation
    }

    /**
     * Show offload status of the media library.
     *
     * ## OPTIONS
     *
     * [--verbose]
     * : Show per-file table instead of summary.
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
     * @when after_wp_load
     */
    public function status($args, $assoc_args) {
        // Implementation
    }

    /**
     * Reset offload tracking metadata.
     *
     * ## OPTIONS
     *
     * [--delete-remote]
     * : Also delete S3 objects (not just metadata).
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * @when after_wp_load
     */
    public function reset($args, $assoc_args) {
        // Implementation
    }
}
```

### Pattern 3: Separation of CLI Layer from Processing Engine

**What:** The CLI command class handles argument parsing, output formatting, and progress display. A separate `S3MO_Bulk_Migrator` class handles the actual batch logic.
**When to use:** When processing logic may be reused (e.g., future admin UI bulk action).
**Why:** Keeps CLI concerns (colorized output, table formatting) separate from business logic (querying attachments, uploading files, tracking state).

```php
// S3MO_Bulk_Migrator handles:
//   - Querying un-offloaded attachments
//   - Building file lists per attachment (reuse upload handler logic)
//   - Uploading with retry
//   - Tracking results (success/fail/skip counts)
//   - Memory cleanup between batches

// S3MO_CLI_Command handles:
//   - Argument parsing ($assoc_args)
//   - Calling migrator methods
//   - Formatting output (colorized lines, tables, summary)
//   - Log file writing
//   - Shutdown handler registration
```

### Pattern 4: Attachment Query for Batch Iteration

**What:** Use `get_posts()` with `no_found_rows => true` and `post_status => 'inherit'` to query attachments in batches.
**When to use:** Querying media library attachments.

```php
$query_args = [
    'post_type'      => 'attachment',
    'post_status'    => 'inherit',
    'posts_per_page' => $batch_size,
    'offset'         => $offset,
    'orderby'        => 'ID',
    'order'          => 'ASC',
    'no_found_rows'  => true,         // Skip SQL_CALC_FOUND_ROWS
    'fields'         => 'ids',        // Return IDs only (memory efficient)
];

// Filter: exclude already-offloaded (unless --force)
if (!$force) {
    $query_args['meta_query'] = [
        'relation' => 'OR',
        [
            'key'     => '_s3mo_offloaded',
            'compare' => 'NOT EXISTS',
        ],
        [
            'key'     => '_s3mo_offloaded',
            'value'   => '1',
            'compare' => '!=',
        ],
    ];
}

// Filter: MIME type
if ($mime_type) {
    $query_args['post_mime_type'] = $mime_type;
}

$attachment_ids = get_posts($query_args);
```

**Important note on `post_status`:** Attachments use `inherit` status, not `publish`. Forgetting this is a common bug that returns zero results.

### Pattern 5: Memory Cleanup Between Batches

**What:** Clear WordPress object cache and wpdb query log between batches to prevent memory exhaustion.
**When to use:** After each batch of 50-100 items in long-running operations.

```php
private function cleanup_memory(): void {
    global $wpdb, $wp_object_cache;

    // Clear the WordPress object cache (in-memory only)
    wp_cache_flush();

    // Clear the stored query log (if SAVEQUERIES is enabled)
    $wpdb->queries = [];

    // Force PHP garbage collection
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }
}
```

### Anti-Patterns to Avoid

- **Loading all attachments at once:** Never query all 1000+ attachments in a single `get_posts()` call. Always paginate with offset + batch_size.
- **Using `fields => '*'` (default):** Returns full WP_Post objects consuming memory. Use `fields => 'ids'` and load metadata only when processing each file.
- **Relying on `paged` parameter with `offset`:** Setting both `offset` and `paged` causes WP_Query to ignore `paged`. Use `offset` exclusively and increment manually.
- **Calling `WP_CLI::error()` for non-fatal issues:** This halts the script. Use `WP_CLI::warning()` for per-file failures and continue processing.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Table output | Custom sprintf formatting | `\WP_CLI\Utils\format_items('table', $items, $columns)` | Handles column widths, alignment, CSV/JSON output modes |
| Confirmation prompt | Custom readline/fgets | `WP_CLI::confirm('message', $assoc_args)` | Respects `--yes` flag automatically |
| Color output | ANSI escape codes | `WP_CLI::colorize('%g text %n')` | Cross-platform, respects `--no-color` flag |
| Argument defaults | Manual isset checks | `wp_parse_args($assoc_args, $defaults)` | Standard WP pattern, clean and readable |
| File MIME type | Custom detection | `get_post_mime_type($id)` | WordPress already stores this in posts table |
| Attachment metadata | Direct DB queries | `wp_get_attachment_metadata($id)` | Returns parsed array with sizes, file path |
| Upload directory paths | Hardcoded paths | `wp_get_upload_dir()` | Handles multisite, custom upload dirs |

**Key insight:** The existing `S3MO_Upload_Handler::handle_upload()` already contains the complete logic for building the file list (original + thumbnails) and uploading each file. The bulk migrator should extract and reuse this pattern rather than reimplementing it.

## Common Pitfalls

### Pitfall 1: Attachment post_status is 'inherit', not 'publish'

**What goes wrong:** Query returns zero attachments.
**Why it happens:** `get_posts()` defaults to `post_status => 'publish'`, but attachments use `inherit`.
**How to avoid:** Always set `'post_status' => 'inherit'` when querying attachments.
**Warning signs:** "0 files found" when media library clearly has files.

### Pitfall 2: Memory Exhaustion on Large Libraries

**What goes wrong:** PHP fatal error after processing several hundred files.
**Why it happens:** WordPress object cache and wpdb query log accumulate memory across the entire request.
**How to avoid:** Call `wp_cache_flush()`, reset `$wpdb->queries`, and `gc_collect_cycles()` between every batch.
**Warning signs:** Gradually increasing memory usage; PHP "Allowed memory size exhausted" error.

### Pitfall 3: Metadata Sizes Array Contains Filenames Only, Not Full Paths

**What goes wrong:** File not found errors when uploading thumbnails.
**Why it happens:** `$metadata['sizes']['thumbnail']['file']` is just the filename (e.g., `photo-150x150.jpg`), not a path. The directory must be derived from `$metadata['file']` using `dirname()`.
**How to avoid:** Follow the exact pattern in `S3MO_Upload_Handler::handle_upload()` which correctly handles this.
**Warning signs:** "File not found" errors for thumbnail sizes.

### Pitfall 4: WP_CLI::error() Kills the Entire Script

**What goes wrong:** One failed file terminates the entire migration.
**Why it happens:** `WP_CLI::error()` calls `exit(1)` by default.
**How to avoid:** Use `WP_CLI::warning()` for per-file failures. Reserve `WP_CLI::error()` for unrecoverable situations (missing credentials, no S3 connection). You can also pass `false` as the second argument: `WP_CLI::error($msg, false)` to NOT exit.
**Warning signs:** Migration stops after first error instead of continuing.

### Pitfall 5: Shutdown Handler Does Not Catch Out-of-Memory Errors

**What goes wrong:** Memory exhaustion kills the process with no summary output.
**Why it happens:** `register_shutdown_function()` catches most fatal errors, but out-of-memory fatals may not have enough memory to execute the handler.
**How to avoid:** Reserve a small memory buffer at script start: `$reserved_memory = str_repeat('x', 1024 * 256);` and free it in the shutdown handler. Also, proper batch-level memory cleanup prevents this scenario.
**Warning signs:** Script silently dies with no output during large migrations.

### Pitfall 6: Re-running offload Uploads Duplicates When Using offset

**What goes wrong:** After a partial failure, re-running with offset-based pagination skips different files because the query results shift.
**Why it happens:** If 50 of 100 files were offloaded, re-running the same offset=0 query now returns different IDs (already-offloaded ones are filtered out).
**How to avoid:** The `meta_query` filtering approach (exclude `_s3mo_offloaded = 1`) inherently handles this. Each run only queries un-offloaded files, so offset always starts at 0 for each batch query.
**Warning signs:** Files being skipped or re-uploaded unexpectedly.

## Code Examples

### Querying Un-Offloaded Attachments in Batches

```php
/**
 * Get the next batch of attachment IDs that need offloading.
 *
 * @param int         $batch_size  Number of attachments per batch.
 * @param string|null $mime_type   Optional MIME type filter.
 * @param bool        $force       If true, include already-offloaded files.
 *
 * @return int[] Array of attachment post IDs.
 */
private function get_next_batch(int $batch_size, ?string $mime_type, bool $force): array {
    $args = [
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => $batch_size,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'no_found_rows'  => true,
        'fields'         => 'ids',
    ];

    if (!$force) {
        $args['meta_query'] = [
            'relation' => 'OR',
            [
                'key'     => '_s3mo_offloaded',
                'compare' => 'NOT EXISTS',
            ],
            [
                'key'     => '_s3mo_offloaded',
                'value'   => '1',
                'compare' => '!=',
            ],
        ];
    }

    if ($mime_type) {
        $args['post_mime_type'] = $mime_type;
    }

    return get_posts($args);
}
```

### Per-File Upload with Retry

```php
/**
 * Upload a single attachment to S3 with retry logic.
 *
 * @param int $attachment_id  The attachment post ID.
 * @param int $max_retries    Maximum retry attempts (default 2, so 3 total).
 *
 * @return array{status: string, error?: string}
 */
private function upload_attachment(int $attachment_id, int $max_retries = 2): array {
    $metadata = wp_get_attachment_metadata($attachment_id);

    if (empty($metadata['file'])) {
        return ['status' => 'skip', 'error' => 'No file in metadata'];
    }

    // Build file list (original + thumbnails) — same logic as Upload_Handler
    $upload_dir = wp_get_upload_dir();
    $prefix     = get_option('s3mo_path_prefix', 'wp-content/uploads');
    $mime       = get_post_mime_type($attachment_id);
    $files      = [];

    $files[] = [
        'local' => $upload_dir['basedir'] . '/' . $metadata['file'],
        'key'   => $prefix . '/' . $metadata['file'],
        'mime'  => $mime,
    ];

    if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
        $subdir = dirname($metadata['file']);
        foreach ($metadata['sizes'] as $size_data) {
            $files[] = [
                'local' => $upload_dir['basedir'] . '/' . $subdir . '/' . $size_data['file'],
                'key'   => $prefix . '/' . $subdir . '/' . $size_data['file'],
                'mime'  => $size_data['mime-type'],
            ];
        }
    }

    // Upload with retry
    $attempt = 0;
    $last_error = '';

    while ($attempt <= $max_retries) {
        $errors = [];
        $success_count = 0;

        foreach ($files as $file) {
            if (!file_exists($file['local'])) {
                $errors[] = 'File not found: ' . $file['local'];
                continue;
            }

            $result = $this->client->upload_object($file['key'], $file['local'], $file['mime']);

            if ($result['success']) {
                $success_count++;
            } else {
                $errors[] = $file['key'] . ' - ' . ($result['error'] ?? 'Unknown');
            }
        }

        if ($success_count === count($files)) {
            S3MO_Tracker::mark_as_offloaded($attachment_id, $files[0]['key'], $this->client->get_bucket());
            return ['status' => 'success'];
        }

        $last_error = implode('; ', $errors);
        $attempt++;

        if ($attempt <= $max_retries) {
            // Exponential backoff: 1s, 2s
            sleep(pow(2, $attempt - 1));
        }
    }

    return ['status' => 'fail', 'error' => $last_error];
}
```

### Shutdown Handler for Partial Summary

```php
/**
 * Register a shutdown handler to print partial results on fatal error.
 */
private function register_shutdown_handler(array &$counters): void {
    // Reserve memory so shutdown handler can execute even on OOM
    $reserved = str_repeat('x', 256 * 1024); // 256KB

    register_shutdown_function(function () use (&$counters, &$reserved) {
        // Free reserved memory
        $reserved = null;

        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR])) {
            fwrite(STDERR, "\n" . WP_CLI::colorize("%RFatal error encountered. Partial results:%n") . "\n");
            fwrite(STDERR, sprintf(
                "  Success: %d | Failed: %d | Skipped: %d\n",
                $counters['success'],
                $counters['failed'],
                $counters['skipped']
            ));
            fwrite(STDERR, "  Error: {$error['message']}\n");
            fwrite(STDERR, "  Re-run 'wp ct-s3 offload' to resume from where it stopped.\n");
        }
    });
}
```

### WP-CLI Colorized Per-File Output

```php
// Success (green)
WP_CLI::log(WP_CLI::colorize(
    sprintf("[%d/%d] Uploading %s... %%gOK%%n", $current, $total, $filename)
));

// Skip (yellow)
WP_CLI::log(WP_CLI::colorize(
    sprintf("[%d/%d] %s... %%ySkipped (already offloaded)%%n", $current, $total, $filename)
));

// Fail (red)
WP_CLI::log(WP_CLI::colorize(
    sprintf("[%d/%d] Uploading %s... %%rFAILED%%n - %s", $current, $total, $filename, $error)
));
```

### Dry-Run Table Output

```php
// Collect items for table display
$items = [];
foreach ($attachment_ids as $id) {
    $metadata = wp_get_attachment_metadata($id);
    $items[] = [
        'ID'       => $id,
        'Filename' => basename($metadata['file'] ?? '(unknown)'),
        'Size'     => size_format(filesize($upload_dir['basedir'] . '/' . $metadata['file'])),
        'MIME'     => get_post_mime_type($id),
    ];
}

\WP_CLI\Utils\format_items('table', $items, ['ID', 'Filename', 'Size', 'MIME']);
WP_CLI::log(sprintf("\n%d files would be uploaded.", count($items)));
```

### Completion Summary

```php
$elapsed = microtime(true) - $start_time;
$speed   = $counters['success'] > 0
    ? round($counters['success'] / $elapsed, 1)
    : 0;

WP_CLI::log('');
WP_CLI::log('--- Migration Summary ---');
WP_CLI::success(sprintf('%d files uploaded successfully', $counters['success']));

if ($counters['failed'] > 0) {
    WP_CLI::warning(sprintf('%d files failed', $counters['failed']));
}
if ($counters['skipped'] > 0) {
    WP_CLI::log(sprintf('%d files skipped (already offloaded)', $counters['skipped']));
}

WP_CLI::log(sprintf('Elapsed: %s | Speed: %s files/sec', gmdate('H:i:s', (int)$elapsed), $speed));
```

### Log File Writing

```php
/**
 * Append a line to the migration log file.
 */
private function log_to_file(string $message): void {
    $log_path = WP_CONTENT_DIR . '/ct-s3-migration.log';
    $timestamp = gmdate('Y-m-d H:i:s');
    file_put_contents($log_path, "[{$timestamp}] {$message}\n", FILE_APPEND | LOCK_EX);
}
```

### Reset Command with Confirmation

```php
public function reset($args, $assoc_args) {
    $delete_remote = \WP_CLI\Utils\get_flag_value($assoc_args, 'delete-remote', false);

    $message = $delete_remote
        ? 'This will clear ALL offload tracking AND delete ALL S3 objects. Continue?'
        : 'This will clear ALL offload tracking metadata. Continue?';

    WP_CLI::confirm($message, $assoc_args); // Respects --yes flag

    // ... perform reset
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `stop_the_insanity()` | Manual `wp_cache_flush()` + `$wpdb->queries = []` | VIP deprecated function | Same effect, no VIP dependency |
| `SQL_CALC_FOUND_ROWS` in WP_Query | `no_found_rows => true` | WordPress 6.1+ optimizations | Significant performance improvement for batch queries |
| `paged` parameter for batch iteration | `offset` with manual increment | Always | `paged` + `offset` conflict; use `offset` exclusively |
| Loading full WP_Post objects | `fields => 'ids'` | Always for batch ops | Massive memory savings |

**Deprecated/outdated:**
- `stop_the_insanity()`: Deprecated in VIP platform 2.0, replaced by `vip_inmemory_cleanup()`. Neither is available outside VIP hosting. Implement the same pattern manually.

## Discretion Decisions (Claude's Recommendations)

### Status and Reset Filter Support

**Recommendation:** Support `--mime-type` on `status` and `reset` commands but NOT `--limit`. Rationale: filtering by MIME type is useful for inspecting specific media types, but `--limit` on a reset/status command is confusing and error-prone.

### Retry Backoff Strategy

**Recommendation:** Exponential backoff: 1 second after first failure, 2 seconds after second failure. This balances speed with giving transient AWS issues time to resolve.

### Log File Format

**Recommendation:** Simple timestamped format:
```
[2026-02-28 14:30:15] START offload (batch_size=100, dry_run=false)
[2026-02-28 14:30:16] OK attachment:1234 photo-2024.jpg (5 files)
[2026-02-28 14:30:17] FAIL attachment:1235 video.mp4 - AccessDenied (attempt 3/3)
[2026-02-28 14:31:05] COMPLETE success=95 failed=2 skipped=3 elapsed=50s
```

### Memory Cleanup Implementation

**Recommendation:** Three-step cleanup between batches:
1. `wp_cache_flush()` - Clear WordPress object cache
2. `$wpdb->queries = []` - Clear query log (relevant when SAVEQUERIES enabled)
3. `gc_collect_cycles()` - Force PHP garbage collection

Call after every batch (default 100 items). This is the equivalent of VIP's `vip_inmemory_cleanup()` without the VIP dependency.

## Open Questions

1. **Extracting upload logic from Upload_Handler**
   - What we know: `handle_upload()` contains file-list building and upload logic that the bulk migrator needs.
   - What's unclear: Whether to refactor `handle_upload()` to call a shared method, or duplicate the logic in the migrator.
   - Recommendation: Extract a `public static function build_file_list(int $attachment_id): array` method on `S3MO_Upload_Handler` (or a new shared utility) that both the filter hook handler and the bulk migrator can call. This avoids duplication without changing existing behavior.

2. **Total count for progress display**
   - What we know: The per-file output format `[45/120]` requires knowing the total count upfront.
   - What's unclear: Whether to run a separate count query or discover total during iteration.
   - Recommendation: Run a single `wp_count_posts('attachment')` or a lightweight count query at the start. This is fast (single SQL query) and provides the total needed for progress display. For filtered queries (--mime-type, un-offloaded only), run a specific count query with the same meta_query.

## Sources

### Primary (HIGH confidence)
- Existing plugin source code: `class-s3mo-upload-handler.php`, `class-s3mo-tracker.php`, `class-s3mo-client.php` - read directly
- WP-CLI GitHub source: `class-wp-cli.php` - all public API methods verified
- WP-CLI 2.12.0 - version confirmed via `wp cli version`

### Secondary (MEDIUM confidence)
- [WP-CLI Commands Cookbook](https://make.wordpress.org/cli/handbook/guides/commands-cookbook/) - official handbook
- [WP-CLI Internal API](https://make.wordpress.org/cli/handbook/internal-api/) - official reference
- [WP-CLI colorize documentation](https://github.com/wp-cli/handbook/blob/main/internal-api/wp-cli-colorize.md) - color tokens verified
- [WordPress VIP CLI Best Practices](https://docs.wpvip.com/vip-cli/wp-cli-with-vip-cli/wp-cli-commands-on-vip/) - memory management patterns
- [WordPress VIP CLI at Scale](https://docs.wpvip.com/vip-cli/wp-cli-with-vip-cli/cli-commands-at-scale/) - batch processing patterns

### Tertiary (LOW confidence)
- [rudrastyh.com WP-CLI tutorial](https://rudrastyh.com/wordpress/custom-wp-cli-commands.html) - general patterns confirmed by official docs

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - all libraries already in project, WP-CLI APIs verified via source
- Architecture: HIGH - patterns well-established in WP-CLI ecosystem, existing code reviewed
- Pitfalls: HIGH - known WordPress/PHP issues, verified via multiple sources
- Code examples: HIGH - based on existing plugin patterns and verified WP-CLI API

**Research date:** 2026-02-28
**Valid until:** 2026-03-28 (stable domain, WP-CLI API rarely changes)
