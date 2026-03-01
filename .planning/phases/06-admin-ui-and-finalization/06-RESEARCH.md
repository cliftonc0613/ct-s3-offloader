# Phase 6: Admin UI and Finalization - Research

**Researched:** 2026-02-28
**Domain:** WordPress Admin UI (Media Library columns, settings page, admin notices, uninstall cleanup)
**Confidence:** HIGH

## Summary

Phase 6 adds three admin UI features and a cleanup mechanism to the CT S3 Offloader plugin: a custom Media Library column showing per-file offload status, a storage statistics dashboard on the existing settings page, dismissible admin notices for misconfiguration warnings, and a comprehensive uninstall routine. All four requirements use well-documented, stable WordPress APIs that have been available since WordPress 2.5+ (columns) through 6.4+ (wp_admin_notice).

The existing codebase already provides the data layer needed: `S3MO_Tracker` has `is_offloaded()`, `get_offload_info()`, and `get_s3_key()` methods; `S3MO_Bulk_Migrator::get_status_counts()` returns total/offloaded/pending counts; the settings page class (`S3MO_Settings_Page`) already registers AJAX handlers and enqueues scripts; and `uninstall.php` exists as a skeleton deleting two options and one meta key but needs expansion.

**Primary recommendation:** Build four discrete components -- a Media Library column class, a stats dashboard section injected into the existing settings page render, an admin notices class checking credential/connection state, and a complete uninstall.php -- all using native WordPress APIs with no external dependencies.

## Standard Stack

### Core (WordPress APIs -- no additional libraries needed)

| API / Hook | Since | Purpose | Why Standard |
|------------|-------|---------|--------------|
| `manage_media_columns` filter | WP 2.5 | Register custom column in Media list table | Official hook for adding Media Library columns |
| `manage_media_custom_column` action | WP 2.5 | Render custom column content per attachment | Companion to manage_media_columns |
| `admin_notices` action | WP 3.1 | Display warning/error notices in admin | Standard WordPress admin notice mechanism |
| `wp_admin_notice()` function | WP 6.4 | Programmatic notice rendering | Modern replacement for manual HTML; use if WP >= 6.4 |
| `wp_ajax_{action}` action | WP 2.1 | AJAX endpoint for stats refresh | Standard WordPress AJAX pattern |
| `delete_post_meta_by_key()` | WP 2.3 | Bulk delete all postmeta with a given key | Purpose-built for uninstall cleanup |
| `delete_option()` | WP 1.2 | Remove plugin options | Standard options cleanup |
| `delete_transient()` | WP 2.8 | Remove cached transients | Standard transient cleanup |
| WordPress Settings API | WP 2.7 | Settings registration (already in use) | Already used by S3MO_Settings_Page |

### Supporting

| Tool | Purpose | When to Use |
|------|---------|-------------|
| `wp_localize_script()` | Pass PHP data (nonce, AJAX URL) to JS | Already used in admin.js for connection test |
| `size_format()` | Convert bytes to human-readable (KB/MB/GB) | Display total storage size in stats dashboard |
| `human_time_diff()` | "5 minutes ago" style timestamps | Display last offload timestamp |
| `wp_cache_set()` / transients | Cache expensive stat queries | Cache get_status_counts() result |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Manual HTML notices | `wp_admin_notice()` (WP 6.4+) | Cleaner API but requires WP 6.4+; plugin requires PHP 8.1 so likely fine |
| `$wpdb->query()` for meta cleanup | `delete_post_meta_by_key()` | Direct SQL is faster for huge datasets but WordPress function is safer and handles cache invalidation |
| React/block-based settings | PHP settings page | Plugin already uses PHP settings page; no reason to add JS framework |

**Installation:** No additional packages needed. All APIs are WordPress core.

## Architecture Patterns

### Recommended File Structure

```
ct-s3-offloader/
├── admin/
│   ├── class-s3mo-settings-page.php     # EXISTING - add stats dashboard section
│   ├── class-s3mo-media-column.php      # NEW - Media Library status column
│   └── class-s3mo-admin-notices.php     # NEW - Configuration warning notices
├── assets/
│   ├── css/
│   │   └── admin.css                    # NEW - Styles for column indicators, stats
│   └── js/
│       └── admin.js                     # EXISTING - extend with stats refresh AJAX
├── includes/
│   ├── class-s3mo-tracker.php           # EXISTING - data source for column
│   ├── class-s3mo-bulk-migrator.php     # EXISTING - get_status_counts() for stats
│   └── class-s3mo-stats.php             # NEW - Storage statistics calculation/caching
├── uninstall.php                        # EXISTING - expand with full cleanup
└── ct-s3-offloader.php                  # EXISTING - wire new classes in init
```

### Pattern 1: Media Library Custom Column

**What:** Add a custom column to the Media Library list view using WordPress's `manage_media_columns` / `manage_media_custom_column` hook pair.

**When to use:** Any time you need to show per-attachment data in the Media list table.

**Example:**

```php
// Source: WordPress Developer Reference - manage_media_columns, manage_media_custom_column

class S3MO_Media_Column {

    public function register_hooks(): void {
        add_filter('manage_media_columns', [$this, 'add_column']);
        add_action('manage_media_custom_column', [$this, 'render_column'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_styles']);
    }

    public function add_column(array $columns): array {
        // Insert after 'title' column for visibility
        $new_columns = [];
        foreach ($columns as $key => $label) {
            $new_columns[$key] = $label;
            if ($key === 'title') {
                $new_columns['s3mo_status'] = __('Offload', 'ct-s3-offloader');
            }
        }
        return $new_columns;
    }

    public function render_column(string $column_name, int $post_id): void {
        if ($column_name !== 's3mo_status') {
            return;
        }

        $info = S3MO_Tracker::get_offload_info($post_id);

        if ($info['offloaded'] === '1') {
            printf(
                '<span class="s3mo-status s3mo-status--s3" title="%s">%s %s</span>',
                esc_attr($info['key']),
                '<span class="s3mo-dot s3mo-dot--green"></span>',
                esc_html__('S3', 'ct-s3-offloader')
            );
        } else {
            printf(
                '<span class="s3mo-status s3mo-status--local">%s %s</span>',
                '<span class="s3mo-dot s3mo-dot--yellow"></span>',
                esc_html__('Local', 'ct-s3-offloader')
            );
        }
    }
}
```

### Pattern 2: AJAX Stats Refresh

**What:** Render stats from cached data on page load; provide a "Refresh" button that triggers an AJAX call to recalculate and update stats without full page reload.

**When to use:** When stats computation is expensive (queries all attachments) but display needs to be current on demand.

**Example:**

```php
// Source: WordPress Plugin Handbook - AJAX

// PHP handler
public function ajax_refresh_stats(): void {
    check_ajax_referer('s3mo_stats_nonce');

    if (! current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }

    $counts = $this->get_fresh_stats(); // Recalculate and cache
    wp_send_json_success($counts);
}

// JS handler (extend existing admin.js)
$(document).on('click', '#s3mo-refresh-stats', function(e) {
    e.preventDefault();
    var $btn = $(this);
    $btn.prop('disabled', true).text('Refreshing...');

    $.post(s3moAdmin.ajaxUrl, {
        action: 's3mo_refresh_stats',
        _ajax_nonce: s3moAdmin.statsNonce
    }, function(response) {
        if (response.success) {
            $('#s3mo-stat-total').text(response.data.offloaded);
            $('#s3mo-stat-size').text(response.data.total_size);
            $('#s3mo-stat-pending').text(response.data.pending);
            $('#s3mo-stat-last').text(response.data.last_offload);
        }
        $btn.prop('disabled', false).text('Refresh Stats');
    });
});
```

### Pattern 3: Admin Notices for Misconfiguration

**What:** Check for misconfiguration conditions on `admin_notices` hook and display appropriate warnings. Use session-based dismissibility (CSS class `is-dismissible`).

**When to use:** Alert administrators to problems that prevent the plugin from functioning.

**Example:**

```php
// Source: WordPress Developer Reference - admin_notices

class S3MO_Admin_Notices {

    public function register_hooks(): void {
        add_action('admin_notices', [$this, 'check_configuration']);
    }

    public function check_configuration(): void {
        if (! current_user_can('manage_options')) {
            return;
        }

        // Check missing credentials
        $required = ['S3MO_BUCKET', 'S3MO_REGION', 'S3MO_KEY', 'S3MO_SECRET'];
        $missing  = array_filter($required, fn($c) => ! defined($c));

        if (! empty($missing)) {
            $list = implode(', ', array_map(fn($c) => '<code>' . esc_html($c) . '</code>', $missing));
            printf(
                '<div class="notice notice-error is-dismissible"><p><strong>%s:</strong> %s %s</p></div>',
                esc_html__('CT S3 Offloader', 'ct-s3-offloader'),
                esc_html__('Missing credentials in wp-config.php:', 'ct-s3-offloader'),
                $list
            );
            return; // Don't show other notices if credentials missing
        }

        // Check connection status (use transient to avoid repeated API calls)
        $status = get_transient('s3mo_connection_status');
        if ($status === 'failed') {
            printf(
                '<div class="notice notice-warning is-dismissible"><p><strong>%s:</strong> %s</p></div>',
                esc_html__('CT S3 Offloader', 'ct-s3-offloader'),
                esc_html__('S3 connection test failed. Check your credentials and bucket configuration.', 'ct-s3-offloader')
            );
        }
    }
}
```

### Pattern 4: Uninstall Cleanup

**What:** Comprehensive cleanup in `uninstall.php` removing all postmeta (by key), options, transients, and optionally the log file. Optionally delete S3 objects based on a stored setting.

**Example:**

```php
// Source: WordPress Plugin Handbook - Uninstall Methods

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

// 1. Remove all _s3mo_ prefixed postmeta
$meta_keys = [
    '_s3mo_offloaded',
    '_s3mo_key',
    '_s3mo_bucket',
    '_s3mo_offloaded_at',
];

foreach ($meta_keys as $key) {
    delete_post_meta_by_key($key);
}

// 2. Remove plugin options
delete_option('s3mo_path_prefix');
delete_option('s3mo_delete_local');
delete_option('s3mo_delete_s3_on_uninstall');

// 3. Remove transients
delete_transient('s3mo_connection_status');
delete_transient('s3mo_storage_stats');

// 4. Remove log file
$log_file = WP_CONTENT_DIR . '/ct-s3-migration.log';
if (file_exists($log_file)) {
    @unlink($log_file);
}
```

### Anti-Patterns to Avoid

- **Live S3 API calls in column render:** Never call S3 API per-row in Media Library. Use local postmeta exclusively. Each page load renders 20+ rows; API calls would cause timeouts.
- **Calculating stats on every page load:** Cache stats in a transient. Only recalculate on manual refresh or after bulk operations.
- **Using `$wpdb->query("DELETE FROM...")` for meta cleanup:** Use `delete_post_meta_by_key()` which handles object cache invalidation automatically.
- **Running full cleanup on deactivation:** WordPress convention: deactivation clears transients only. Full data removal happens only on uninstall (plugin deletion).
- **Inline styles in PHP:** Use a separate CSS file enqueued via `admin_enqueue_scripts`. The existing settings page already uses `wp_add_inline_style` which should be migrated to a proper CSS file.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Human-readable file sizes | Custom byte formatter | `size_format()` | WordPress core function handles KB/MB/GB/TB with proper localization |
| Relative timestamps | Custom date math | `human_time_diff()` | Handles edge cases, localized output |
| AJAX nonce verification | Manual nonce check | `check_ajax_referer()` | Standard WordPress AJAX security |
| Admin notice HTML | Custom markup | Standard notice classes + `is-dismissible` | WordPress core JS handles dismiss button automatically |
| Column insertion order | Append to end | Splice after 'title' key | Better UX to place status column near the filename |
| Bulk meta deletion | Loop with delete_post_meta | `delete_post_meta_by_key()` | Single query, handles cache flush |

**Key insight:** Every UI component in this phase has a dedicated WordPress API. The plugin should use zero external libraries for the admin UI.

## Common Pitfalls

### Pitfall 1: Media Column N+1 Query Problem

**What goes wrong:** Rendering the offload status for each row triggers a separate `get_post_meta()` call per attachment, resulting in 20+ queries per page.
**Why it happens:** WordPress does not automatically prime postmeta for Media Library list queries.
**How to avoid:** WordPress actually does prime metadata for posts in the main query via `update_post_meta_cache()`. Verify that the Media list table query includes `update_post_meta_cache => true` (it does by default). If custom queries are used, call `update_meta_cache('post', $ids)` to batch-load.
**Warning signs:** Slow Media Library page load, high query count in Query Monitor.

### Pitfall 2: Stats Transient Stale After Bulk Operations

**What goes wrong:** Storage statistics show outdated counts after a bulk migration or upload.
**Why it happens:** Stats are cached in a transient but bulk operations don't invalidate it.
**How to avoid:** Delete the stats transient after bulk operations complete. Add `delete_transient('s3mo_storage_stats')` in `S3MO_Upload_Handler` and `S3MO_Bulk_Migrator` after successful uploads.
**Warning signs:** Stats don't change until manual refresh is clicked.

### Pitfall 3: Admin Notices on Every Page

**What goes wrong:** Configuration warning notices appear on every admin page, annoying users who are not on the plugin's settings page.
**Why it happens:** The `admin_notices` hook fires on ALL admin pages.
**How to avoid:** For credential-missing warnings, show on all pages (this is critical). For less critical warnings (connection test failed), consider showing only on the plugin settings page or Media pages by checking `get_current_screen()`.
**Warning signs:** User complaints about excessive notices.

### Pitfall 4: Uninstall Deleting S3 Objects Without Confirmation

**What goes wrong:** User deletes plugin and loses all S3 objects.
**Why it happens:** No safeguard or opt-in for destructive S3 cleanup.
**How to avoid:** Add a setting `s3mo_delete_s3_on_uninstall` (default: false). Only delete S3 objects during uninstall if this setting is explicitly enabled. Document this clearly in the settings page.
**Warning signs:** Data loss reports from users.

### Pitfall 5: Forgetting the Error State in Column

**What goes wrong:** Column only shows "S3" or "Local" but doesn't surface upload errors.
**Why it happens:** Only checking `_s3mo_offloaded` meta, not tracking error state.
**How to avoid:** Either add a `_s3mo_error` meta key in the upload handler for failed uploads, or define "error" as: has `_s3mo_offloaded = 0` AND has `_s3mo_key` set (attempted but failed). The context specifies red dot "Error" as a state, so the tracker needs to support this.
**Warning signs:** Users see "Local" for files that actually failed to upload.

### Pitfall 6: Total Size Calculation Performance

**What goes wrong:** Calculating total size of offloaded files is slow because it requires reading `wp_get_attachment_metadata()` for every offloaded attachment.
**Why it happens:** File size isn't stored in offload metadata, only in attachment metadata.
**How to avoid:** Two options: (1) Store file size in `_s3mo_filesize` meta during offload, or (2) use `$wpdb` to query `wp_postmeta` for `_attached_file` and sum file sizes. Option 1 is cleaner. Cache the result in a transient.
**Warning signs:** Stats refresh taking 10+ seconds on sites with thousands of media files.

## Code Examples

### Complete Media Column CSS

```css
/* admin.css */
.s3mo-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 4px;
    vertical-align: middle;
}

.s3mo-dot--green { background-color: #00a32a; }
.s3mo-dot--yellow { background-color: #dba617; }
.s3mo-dot--red { background-color: #d63638; }

.s3mo-status {
    display: inline-flex;
    align-items: center;
    font-size: 13px;
    cursor: default;
}

.s3mo-status--s3 { color: #00a32a; }
.s3mo-status--local { color: #996800; }
.s3mo-status--error { color: #d63638; }

/* Stats dashboard */
.s3mo-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.s3mo-stat-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 16px;
    text-align: center;
}

.s3mo-stat-card .s3mo-stat-value {
    font-size: 28px;
    font-weight: 600;
    line-height: 1.2;
    color: #1d2327;
}

.s3mo-stat-card .s3mo-stat-label {
    font-size: 13px;
    color: #646970;
    margin-top: 4px;
}
```

### Stats Dashboard HTML (in settings page render)

```php
// Source: Existing S3MO_Settings_Page::render_page() pattern

private function render_stats_dashboard(): void {
    $stats = get_transient('s3mo_storage_stats');

    if ($stats === false) {
        $stats = $this->calculate_stats();
        set_transient('s3mo_storage_stats', $stats, HOUR_IN_SECONDS);
    }

    ?>
    <h2><?php esc_html_e('Storage Statistics', 'ct-s3-offloader'); ?></h2>
    <div class="s3mo-stats-grid">
        <div class="s3mo-stat-card">
            <div class="s3mo-stat-value" id="s3mo-stat-total"><?php echo esc_html($stats['offloaded']); ?></div>
            <div class="s3mo-stat-label"><?php esc_html_e('Files on S3', 'ct-s3-offloader'); ?></div>
        </div>
        <div class="s3mo-stat-card">
            <div class="s3mo-stat-value" id="s3mo-stat-size"><?php echo esc_html(size_format($stats['total_size'])); ?></div>
            <div class="s3mo-stat-label"><?php esc_html_e('Total Size', 'ct-s3-offloader'); ?></div>
        </div>
        <div class="s3mo-stat-card">
            <div class="s3mo-stat-value" id="s3mo-stat-pending"><?php echo esc_html($stats['pending']); ?></div>
            <div class="s3mo-stat-label"><?php esc_html_e('Pending', 'ct-s3-offloader'); ?></div>
        </div>
        <div class="s3mo-stat-card">
            <div class="s3mo-stat-value" id="s3mo-stat-last"><?php echo $stats['last_offload'] ? esc_html(human_time_diff(strtotime($stats['last_offload']))) . ' ago' : '—'; ?></div>
            <div class="s3mo-stat-label"><?php esc_html_e('Last Offload', 'ct-s3-offloader'); ?></div>
        </div>
    </div>
    <p>
        <button type="button" class="button button-secondary" id="s3mo-refresh-stats">
            <?php esc_html_e('Refresh Stats', 'ct-s3-offloader'); ?>
        </button>
    </p>
    <?php
}
```

### Uninstall Cleanup (Complete)

```php
<?php
// uninstall.php
defined('WP_UNINSTALL_PLUGIN') || exit;

// 1. Remove all s3mo_ postmeta keys
$meta_keys = [
    '_s3mo_offloaded',
    '_s3mo_key',
    '_s3mo_bucket',
    '_s3mo_offloaded_at',
    '_s3mo_error',      // If error tracking is added
    '_s3mo_filesize',   // If size caching is added
];

foreach ($meta_keys as $meta_key) {
    delete_post_meta_by_key($meta_key);
}

// 2. Remove plugin options
$options = [
    's3mo_path_prefix',
    's3mo_delete_local',
    's3mo_delete_s3_on_uninstall',
];

foreach ($options as $option) {
    delete_option($option);
}

// 3. Remove transients
$transients = [
    's3mo_connection_status',
    's3mo_storage_stats',
];

foreach ($transients as $transient) {
    delete_transient($transient);
}

// 4. Remove log file
$log_file = WP_CONTENT_DIR . '/ct-s3-migration.log';
if (file_exists($log_file)) {
    @unlink($log_file);
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Manual `<div class="notice">` HTML | `wp_admin_notice()` function | WP 6.4 (Nov 2023) | Cleaner API, standardized args array |
| `$wpdb->delete()` for meta | `delete_post_meta_by_key()` | Available since WP 2.3 | Handles object cache invalidation |
| `wp_add_inline_style()` for admin CSS | Separate enqueued CSS file | Best practice | Better caching, easier maintenance |

**Note on wp_admin_notice():** Since the plugin requires PHP 8.1, the WordPress version is likely 6.4+. However, to be safe, either check `function_exists('wp_admin_notice')` or use the traditional HTML approach which works on all versions.

## Open Questions

1. **Error state tracking**
   - What we know: Context specifies red dot "Error" as a column state. Current tracker has no error meta key.
   - What's unclear: Should errors be tracked as a separate meta key (`_s3mo_error`) or derived from state (has key but not offloaded)?
   - Recommendation: Add `_s3mo_error` meta key with error message. Set it in upload handler on failure. Clear it on successful retry. This is more explicit and enables showing error details in the popup.

2. **Total size calculation**
   - What we know: Context says "no live S3 API calls" for stats. Attachment metadata has filesize in `$metadata['filesize']` (WordPress 6.0+).
   - What's unclear: Whether `wp_get_attachment_metadata()` reliably includes 'filesize' for all attachments, or if we need to fall back to `filesize()` on disk.
   - Recommendation: Store `_s3mo_filesize` during offload for reliable size tracking independent of local files or attachment metadata.

3. **Details popup on column click**
   - What we know: Context says "clicking the status indicator opens a details popup showing S3 key, bucket, and upload timestamp."
   - What's unclear: Whether this should be a Thickbox modal, a custom JS popup, or inline expand.
   - Recommendation: Use a simple CSS tooltip/popover or WordPress Thickbox. A lightweight `<div>` positioned on click with the three metadata values is simplest and avoids additional dependencies.

## Sources

### Primary (HIGH confidence)
- [WordPress Developer Reference - manage_media_columns](https://developer.wordpress.org/reference/hooks/manage_media_columns/) - Hook signature, parameters, since WP 2.5
- [WordPress Developer Reference - manage_media_custom_column](https://developer.wordpress.org/reference/hooks/manage_media_custom_column/) - Column rendering hook
- [WordPress Developer Reference - admin_notices](https://developer.wordpress.org/reference/hooks/admin_notices/) - Notice hook and HTML structure
- [WordPress Developer Reference - wp_admin_notice()](https://developer.wordpress.org/reference/functions/wp_admin_notice/) - WP 6.4+ notice function
- [WordPress Developer Reference - delete_post_meta_by_key()](https://developer.wordpress.org/reference/functions/delete_post_meta_by_key/) - Bulk meta deletion
- Existing codebase: `S3MO_Tracker`, `S3MO_Settings_Page`, `S3MO_Bulk_Migrator`, `uninstall.php`

### Secondary (MEDIUM confidence)
- [WordPress Make Blog - Admin Notice Functions in 6.4](https://make.wordpress.org/core/2023/10/16/introducing-admin-notice-functions-in-wordpress-6-4/) - wp_admin_notice introduction
- [Dig WP - WordPress Uninstall Guide](https://digwp.com/2019/11/wordpress-uninstall-php/) - Uninstall best practices
- [WordPress Plugin Handbook - AJAX](https://developer.wordpress.org/plugins/javascript/ajax/) - AJAX patterns

### Tertiary (LOW confidence)
- [Alexandros Georgiou - Persistent Dismissible Notices](https://www.alexgeorgiou.gr/persistently-dismissible-notices-wordpress/) - Persistent dismissal pattern (not needed for this phase per context)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - All WordPress core APIs, well-documented, stable since WP 2.5+
- Architecture: HIGH - Follows existing plugin patterns, extends existing classes
- Pitfalls: HIGH - Based on common WordPress plugin development patterns and codebase analysis
- Code examples: HIGH - Based on official WordPress documentation and existing plugin code

**Research date:** 2026-02-28
**Valid until:** 2026-03-30 (stable WordPress APIs, unlikely to change)
