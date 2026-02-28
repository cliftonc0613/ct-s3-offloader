# Architecture Patterns: WordPress S3 Media Offloader

**Domain:** WordPress Plugin (Media Storage Offloading)
**Researched:** 2026-02-27

## Recommended Architecture

The plugin follows a **hook-driven interceptor pattern**: it does not replace WordPress media handling but wraps around it, intercepting at known hook points to copy files to S3 and rewrite URLs at render time.

```
                    WordPress Core Upload Pipeline
                    ==============================

 Browser Upload
      |
      v
 _wp_handle_upload()          <-- wp_handle_upload_prefilter (validation only)
      |
      v
 wp_insert_attachment()       <-- add_attachment action (hook: record tracking)
      |
      v
 wp_generate_attachment_metadata()   <-- wp_generate_attachment_metadata filter
      |                                   (hook: UPLOAD TO S3 here, after all
      |                                    thumbnails are generated)
      v
 wp_update_attachment_metadata()     <-- wp_update_attachment_metadata filter
      |                                   (WARNING: fires multiple times in WP 5.3+
      |                                    as each sub-size is created)
      v
 Attachment ready in Media Library


                    URL Rewriting Pipeline
                    ======================

 wp_get_attachment_url()      <-- wp_get_attachment_url filter
      |                            (rewrite local URL -> CDN URL)
      v
 wp_get_attachment_image_src() <-- uses wp_get_attachment_url internally
      |
      v
 the_content filter           <-- content filter for URLs embedded in post HTML
      |
      v
 Browser receives CDN URLs


                    Deletion Pipeline
                    =================

 wp_delete_attachment()
      |
      v
 delete_attachment action     <-- hook: DELETE FROM S3 here
      |
      v
 WordPress removes local files + meta
```

### Component Boundaries

| Component | Responsibility | Communicates With |
|-----------|---------------|-------------------|
| **Plugin Bootstrap** (`ct-s3-offloader.php`) | Loads components, checks PHP/WP version, defines constants | All components |
| **Settings Manager** | Admin page, option storage/retrieval, credential validation | WordPress Settings API, S3 Client |
| **S3 Client Wrapper** | Abstracts AWS SDK calls (put, delete, list, head) | AWS SDK, Settings Manager |
| **Upload Handler** | Hooks into upload pipeline, triggers S3 push after thumbnail generation | WordPress hooks, S3 Client, Tracker |
| **URL Rewriter** | Filters `wp_get_attachment_url` and `the_content` to serve CDN URLs | WordPress filters, Settings Manager, Tracker |
| **Deletion Handler** | Hooks `delete_attachment`, removes S3 objects | WordPress hooks, S3 Client, Tracker |
| **Tracker** | Records which attachments are offloaded (attachment meta) | WordPress postmeta API |
| **WP-CLI Commands** | Bulk migrate, sync, verify commands | S3 Client, Tracker, WordPress Query |

### Data Flow: New Upload

```
1. User uploads image via Media Library or editor
2. WordPress saves file to /wp-content/uploads/YYYY/MM/filename.jpg
3. WordPress calls wp_insert_attachment() -> fires add_attachment action
4. WordPress calls wp_generate_attachment_metadata()
   - Creates thumbnail sizes (thumbnail, medium, large, etc.)
   - All sizes saved to local disk first
5. OUR HOOK (wp_generate_attachment_metadata filter):
   a. Read metadata (has list of all generated sizes + file paths)
   b. Upload original file to S3: s3://bucket/wp-content/uploads/YYYY/MM/filename.jpg
   c. Upload each thumbnail to S3 (iterate metadata['sizes'])
   d. Mark attachment as offloaded in postmeta (_ct_s3_offloaded = true)
   e. Optionally delete local files (configurable setting)
   f. Return metadata unchanged
6. WordPress saves metadata to _wp_attachment_metadata postmeta
```

### Data Flow: URL Request

```
1. Theme/plugin calls wp_get_attachment_url($attachment_id)
2. WordPress generates local URL: https://site.com/wp-content/uploads/2026/02/photo.jpg
3. OUR HOOK (wp_get_attachment_url filter):
   a. Check if attachment is offloaded (read _ct_s3_offloaded meta)
   b. If yes: replace domain with CDN domain from settings
      https://cdn.example.com/wp-content/uploads/2026/02/photo.jpg
   c. If no: return original URL unchanged
4. For post content (the_content filter):
   a. Regex or str_replace to swap upload URLs -> CDN URLs in HTML
   b. Handles <img src="">, <a href="">, srcset attributes
```

### Data Flow: Deletion

```
1. User deletes attachment from Media Library
2. WordPress fires delete_attachment action with $post_id
3. OUR HOOK (delete_attachment action):
   a. Check if attachment is offloaded
   b. If yes: get metadata for all file sizes
   c. Delete original from S3
   d. Delete each thumbnail from S3
   e. Clean up tracking meta
4. WordPress proceeds with local file deletion + DB cleanup
```

## Component Detail

### 1. Plugin Bootstrap

**File:** `ct-s3-offloader.php`

```php
/*
Plugin Name: CT S3 Offloader
*/

// Version and path constants
define('CT_S3_VERSION', '1.0.0');
define('CT_S3_PATH', plugin_dir_path(__FILE__));
define('CT_S3_URL', plugin_dir_url(__FILE__));

// Require bundled AWS SDK (no Composer)
require_once CT_S3_PATH . 'vendor/aws/aws-autoloader.php';

// Load components
require_once CT_S3_PATH . 'includes/class-ct-s3-settings.php';
require_once CT_S3_PATH . 'includes/class-ct-s3-client.php';
require_once CT_S3_PATH . 'includes/class-ct-s3-upload-handler.php';
require_once CT_S3_PATH . 'includes/class-ct-s3-url-rewriter.php';
require_once CT_S3_PATH . 'includes/class-ct-s3-deletion-handler.php';
require_once CT_S3_PATH . 'includes/class-ct-s3-tracker.php';

// Conditional CLI loading
if (defined('WP_CLI') && WP_CLI) {
    require_once CT_S3_PATH . 'includes/class-ct-s3-cli.php';
}

// Initialize on plugins_loaded
add_action('plugins_loaded', 'ct_s3_init');
```

**Key decision:** Load on `plugins_loaded` (not immediately) so other plugins can hook in first. CLI commands only load in CLI context.

### 2. S3 Client Wrapper

**File:** `includes/class-ct-s3-client.php`

Wraps the AWS SDK S3Client with domain-specific methods. This is the ONLY component that touches the AWS SDK directly.

```
CT_S3_Client
  - __construct(settings)          // Lazy-init S3Client from settings
  - upload(local_path, s3_key)     // PutObject with ContentType detection
  - delete(s3_key)                 // DeleteObject
  - delete_multiple(s3_keys[])     // DeleteObjects (batch, for thumbnails)
  - exists(s3_key)                 // HeadObject
  - get_url(s3_key)                // Generate CDN or S3 URL
  - get_client()                   // Return raw S3Client for advanced use
```

**Why wrapper vs direct SDK:** Isolates AWS SDK version coupling. If SDK changes, only this file changes. Also enables unit testing with mock client.

**Why NOT stream wrapper approach (like S3-Uploads):** Stream wrappers (`s3://` protocol) are elegant but fragile. They break with any plugin that uses `realpath()`, `is_file()`, or direct filesystem checks. The interceptor approach (copy to S3 after local save) is more compatible and debuggable. Local files can optionally be kept as fallback.

### 3. Upload Handler

**File:** `includes/class-ct-s3-upload-handler.php`

**Primary hook:** `wp_generate_attachment_metadata` (filter, priority 10)

```
CT_S3_Upload_Handler
  - __construct(client, tracker, settings)
  - handle_upload(metadata, attachment_id)     // Main hook callback
  - upload_file(local_path, s3_key)            // Upload single file
  - upload_thumbnails(metadata, attachment_id)  // Upload all sizes
  - get_s3_key(local_path)                     // Convert local path to S3 key
```

**Critical detail -- why `wp_generate_attachment_metadata` and not `add_attachment`:**

- `add_attachment` fires BEFORE thumbnails are generated. If you upload to S3 here, you only get the original -- no thumbnails.
- `wp_generate_attachment_metadata` fires AFTER all thumbnail sizes are created. The `$metadata` array contains every generated size with its filename.
- `wp_update_attachment_metadata` fires multiple times in WordPress 5.3+ (once per sub-size being created). Using it as "upload complete" triggers duplicate S3 uploads. Avoid.

**The correct hook is `wp_generate_attachment_metadata` as a filter.** Return `$metadata` unchanged after performing S3 upload side effects.

### 4. URL Rewriter

**File:** `includes/class-ct-s3-url-rewriter.php`

Two-layer rewriting strategy:

**Layer 1: Attachment URL filter** (handles programmatic URL requests)
- Hook: `wp_get_attachment_url` filter
- Intercepts ALL calls to `wp_get_attachment_url()`, which is used internally by `wp_get_attachment_image_src()`, `wp_get_attachment_image()`, and most theme/plugin image functions
- Simple string replacement: local upload URL prefix -> CDN prefix

**Layer 2: Content filter** (handles URLs stored in post HTML)
- Hook: `the_content` filter (priority 99, run late)
- Regex replacement on rendered post content
- Catches `<img src="...">`, `srcset` attributes, `<a href="...">` pointing to uploads
- Necessary because some URLs are stored as literal strings in post_content (Gutenberg blocks, classic editor)

```
CT_S3_URL_Rewriter
  - __construct(settings, tracker)
  - filter_attachment_url(url, attachment_id)     // wp_get_attachment_url hook
  - filter_content(content)                        // the_content hook
  - get_cdn_url(local_url)                         // URL transformation logic
  - get_upload_base_url()                          // Cached local upload URL prefix
  - get_cdn_base_url()                             // CDN URL prefix from settings
```

**Why both layers:**
- `wp_get_attachment_url` alone misses URLs hardcoded in post content (especially Gutenberg image blocks that store full URLs in HTML)
- `the_content` alone misses programmatic usage (featured images, widgets, REST API responses, custom queries)
- Together they cover nearly all URL exposure points

**Additional filter points to consider (Phase 2+):**
- `wp_calculate_image_srcset` -- for responsive image srcset rewriting
- `wp_get_attachment_image_attributes` -- for image tag attributes
- `the_content` on REST API responses if serving headless

### 5. Deletion Handler

**File:** `includes/class-ct-s3-deletion-handler.php`

**Primary hook:** `delete_attachment` action

```
CT_S3_Deletion_Handler
  - __construct(client, tracker)
  - handle_delete(attachment_id)        // delete_attachment hook
  - delete_all_sizes(attachment_id)     // Delete original + all thumbnails
```

**Important:** Hook on `delete_attachment` (fires before WordPress removes local files and metadata). At this point, `_wp_attachment_metadata` still exists in the database, so we can read the list of thumbnail files to delete from S3. If we hooked later, that metadata would already be gone.

### 6. Tracker (Database Strategy)

**File:** `includes/class-ct-s3-tracker.php`

**Decision: Attachment postmeta vs custom table.**

**Recommendation: Use attachment postmeta.** Reasons:

| Approach | Pros | Cons |
|----------|------|------|
| **Postmeta (recommended)** | No schema migration, works with WP export/import, automatic cleanup on attachment delete, simple queries | Slightly slower for bulk "list all offloaded" queries |
| **Custom table** | Fast bulk queries, can store extra columns | Requires activation/deactivation hooks for table creation, not included in WP export, orphan rows if attachment deleted without plugin active |

For 1000+ files, postmeta performance is fine. Custom tables are overkill and add maintenance burden.

**Meta keys:**

```
_ct_s3_offloaded    => (bool) true/false
_ct_s3_key          => (string) 'wp-content/uploads/2026/02/photo.jpg'
_ct_s3_bucket       => (string) bucket name (enables bucket migration)
_ct_s3_offloaded_at => (string) ISO datetime
```

```
CT_S3_Tracker
  - is_offloaded(attachment_id)          // Check _ct_s3_offloaded meta
  - mark_offloaded(attachment_id, key)   // Set meta values
  - mark_local(attachment_id)            // Remove offload meta
  - get_s3_key(attachment_id)            // Read stored S3 key
  - get_unoffloaded_ids(limit, offset)   // WP_Query for bulk operations
  - get_offloaded_count()                // Count for progress display
  - get_total_count()                    // Total attachments
```

### 7. Settings Manager

**File:** `includes/class-ct-s3-settings.php`

Uses WordPress Settings API for admin page under Settings menu.

**Option structure** (single option row, serialized array):

```php
$defaults = [
    'aws_access_key'     => '',
    'aws_secret_key'     => '',      // Encrypted at rest
    'bucket'             => '',
    'region'             => 'us-east-1',
    'cdn_url'            => '',       // CloudFront distribution URL
    'remove_local'       => false,    // Delete local files after upload
    'path_prefix'        => 'wp-content/uploads',  // S3 key prefix
    'enabled'            => false,    // Master on/off switch
];
```

**Why single option:** One `get_option()` call vs eight. WordPress autoloads options, so this is one DB row loaded on every page.

**Credential security:** Recommend supporting `wp-config.php` constants as override:

```php
define('CT_S3_ACCESS_KEY', '...');
define('CT_S3_SECRET_KEY', '...');
```

Constants take precedence over DB-stored values. Settings page shows "Defined in wp-config.php" for constant-overridden fields. This prevents credentials from appearing in DB exports.

### 8. WP-CLI Commands

**File:** `includes/class-ct-s3-cli.php`

```
wp ct-s3 offload [--batch=50] [--dry-run] [--verbose]
    Bulk upload all un-offloaded attachments to S3

wp ct-s3 sync [--direction=up|down] [--dry-run]
    Verify S3 state matches WordPress state

wp ct-s3 verify [--fix]
    Check that all offloaded files actually exist in S3

wp ct-s3 status
    Show offload stats (X of Y offloaded, bucket, region)
```

**Batch processing pattern:**

```php
// Get total count for progress bar
$total = $tracker->get_unoffloaded_count();
$progress = WP_CLI\Utils\make_progress_bar('Offloading', $total);

$offset = 0;
$batch_size = $args['batch'] ?? 50;

while ($ids = $tracker->get_unoffloaded_ids($batch_size, $offset)) {
    foreach ($ids as $id) {
        $metadata = wp_get_attachment_metadata($id);
        $file = get_attached_file($id);

        if ($dry_run) {
            WP_CLI::log("Would offload: $file");
        } else {
            $handler->handle_upload($metadata, $id);
        }

        $progress->tick();
    }
    // Don't increment offset if processing linearly (items get marked offloaded)
    // But DO sleep between batches to avoid rate limiting
    sleep(1);
}

$progress->finish();
```

**Key WP-CLI patterns:**
- Use `WP_CLI\Utils\make_progress_bar()` for progress display
- `--dry-run` flag is essential for production confidence
- Process in batches to avoid memory exhaustion
- Call `wp_cache_flush()` every N batches to prevent object cache bloat
- Respect S3 rate limits (~3,500 PUT/s per prefix, but be conservative)

## Suggested File Structure

```
ct-s3-offloader/
  ct-s3-offloader.php                 # Plugin bootstrap, constants, requires
  uninstall.php                       # Clean up options + meta on uninstall
  includes/
    class-ct-s3-settings.php          # Settings page + option management
    class-ct-s3-client.php            # AWS SDK wrapper
    class-ct-s3-upload-handler.php    # Upload pipeline hooks
    class-ct-s3-url-rewriter.php      # URL filtering (attachment + content)
    class-ct-s3-deletion-handler.php  # Deletion sync
    class-ct-s3-tracker.php           # Attachment offload tracking (postmeta)
    class-ct-s3-cli.php               # WP-CLI commands
  vendor/
    aws/                              # Bundled AWS SDK (no Composer)
      aws-autoloader.php
      Aws/                            # SDK classes
  assets/
    css/
      admin.css                       # Settings page styles
    js/
      admin.js                        # Settings page JS (connection test button)
```

## Anti-Patterns to Avoid

### Anti-Pattern 1: Stream Wrapper Replacement
**What:** Register `s3://` protocol and change `upload_dir` to point to S3 directly (the S3-Uploads approach).
**Why bad for this project:** Breaks plugins that use `realpath()`, `file_exists()`, `is_file()` on upload paths. High compatibility risk with other plugins. Harder to debug. No local fallback.
**Instead:** Copy-to-S3-after-local-save interceptor pattern. WordPress handles files normally; we mirror to S3 as a side effect.

### Anti-Pattern 2: Storing CDN URLs in Database
**What:** Rewrite `_wp_attached_file` or `post_content` to contain S3/CDN URLs.
**Why bad:** If CDN domain changes, every URL in DB is wrong. If plugin is deactivated, all images break. Cannot switch between local/CDN without DB migration.
**Instead:** Store local paths in DB (WordPress default). Rewrite URLs at render time via filters. Plugin deactivation = instant fallback to local URLs.

### Anti-Pattern 3: Hooking wp_update_attachment_metadata for Upload
**What:** Using `wp_update_attachment_metadata` filter as the "upload complete" trigger.
**Why bad:** In WordPress 5.3+, this filter fires MULTIPLE times during a single upload (once per generated sub-size). Causes duplicate S3 uploads, race conditions, and wasted bandwidth.
**Instead:** Use `wp_generate_attachment_metadata` filter which fires once, after all sizes are generated.

### Anti-Pattern 4: Synchronous S3 Upload in Admin Request
**What:** Uploading to S3 synchronously during the HTTP request that handles the media upload.
**Why problematic:** Adds 1-5 seconds to every upload. For large files or slow connections, can timeout.
**Mitigation for v1:** Accept the latency for simplicity. For v2, consider background processing via `wp_schedule_single_event()` or Action Scheduler. However, background processing adds complexity (tracking pending state, retry logic, user feedback).

## Scalability Considerations

| Concern | At 100 files | At 1,000 files | At 10,000+ files |
|---------|-------------|----------------|-------------------|
| Bulk migration | Minutes, single CLI run | 10-30 min, batch with progress | Hours, needs --batch + sleep |
| URL rewriting perf | Negligible | Negligible (meta is autoloaded per-request) | Cache CDN base URL, avoid per-attachment DB hit on content filter |
| S3 costs | Pennies | ~$0.50/month | $2-10/month depending on access patterns |
| Postmeta bloat | Unnoticeable | Fine | Fine (4 meta rows per attachment = 40K rows, trivial for MySQL) |

## Build Order (Dependency Chain)

Components should be built in this order because each layer depends on the previous:

```
Phase 1: Foundation (no S3 interaction yet)
  1. Plugin Bootstrap + Constants
  2. Settings Manager (admin page, option storage)
  3. Tracker (postmeta read/write)

Phase 2: Core S3 Operations
  4. S3 Client Wrapper (needs Settings for credentials)
  5. Upload Handler (needs S3 Client + Tracker)
  6. Deletion Handler (needs S3 Client + Tracker)

Phase 3: URL Serving
  7. URL Rewriter - Layer 1: wp_get_attachment_url (needs Settings for CDN URL + Tracker)
  8. URL Rewriter - Layer 2: the_content filter

Phase 4: Bulk Operations
  9. WP-CLI Commands (needs all above components)

Phase 5: Hardening
  10. Connection test AJAX endpoint
  11. Error handling / retry logic
  12. Uninstall cleanup
```

**Rationale:** Settings and Tracker are pure WordPress (no AWS dependency) -- build and test first. S3 Client is the first AWS-touching code -- isolate and validate. Upload/Delete handlers are the core value. URL rewriting makes it user-visible. CLI is for migration, built last because it orchestrates everything else.

## Sources

- WordPress Core source code (`wp-includes/post.php`, `wp-includes/media.php`, `wp-admin/includes/image.php`, `wp-admin/includes/file.php`) -- verified directly from local WordPress installation (HIGH confidence)
- [S3-Uploads by Human Made](https://github.com/humanmade/S3-Uploads) -- stream wrapper architecture reference via [DeepWiki overview](https://deepwiki.com/humanmade/S3-Uploads/1-overview) (HIGH confidence)
- [WP Offload Media by Delicious Brains](https://deliciousbrains.com/wp-offload-media/) -- URL rewriting approach: runtime filtering, not DB storage (MEDIUM confidence, from search results)
- [Advanced Media Offloader](https://wordpress.org/plugins/advanced-media-offloader/) -- WP-CLI command patterns and hook structure (MEDIUM confidence)
- [WordPress 5.3 wp_update_attachment_metadata changes](https://make.wordpress.org/core/2019/11/05/use-of-the-wp_update_attachment_metadata-filter-as-upload-is-complete-hook/) -- multiple-fire behavior documentation (MEDIUM confidence, URL found but content extraction failed; behavior verified from core source)
- WordPress Developer Reference for [wp_generate_attachment_metadata](https://developer.wordpress.org/reference/hooks/wp_generate_attachment_metadata/) and [wp_update_attachment_metadata](https://developer.wordpress.org/reference/hooks/wp_update_attachment_metadata/) (HIGH confidence)
