# Phase 4: Deletion Sync - Research

**Researched:** 2026-02-28
**Domain:** WordPress attachment deletion hooks, AWS S3 object deletion
**Confidence:** HIGH

## Summary

This phase requires a single new class (`S3MO_Delete_Handler`) that hooks into WordPress's `delete_attachment` action to remove S3 objects (original + all thumbnails) before WordPress deletes the attachment record and local files. The critical insight is that `delete_attachment` fires **before** any postmeta or files are removed, so all metadata (S3 key, attachment metadata with thumbnail filenames) remains fully accessible inside the hook handler.

The existing `S3MO_Client::delete_object()` method handles individual object deletion with try/catch. For attachments with many thumbnails, the AWS SDK's `deleteObjects()` batch API can delete up to 1,000 objects in a single request, which is more efficient. However, given that a typical WordPress attachment has fewer than 15 thumbnail sizes, calling `delete_object()` in a loop is simpler and matches the existing upload pattern. The batch API is an optimization that can be added later.

**Primary recommendation:** Hook `delete_attachment` at priority 10, gather all S3 keys (original + thumbnails) from existing metadata, delete each from S3, then clear tracker metadata. Wrap all S3 calls in try/catch so failures log but never prevent WordPress from completing the deletion.

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| WordPress `delete_attachment` hook | WP 2.8+ | Fires before attachment deletion begins | Only reliable hook for pre-deletion cleanup of attachments |
| `wp_get_attachment_metadata()` | WP core | Get thumbnail sizes and file paths | Standard way to access attachment image sizes |
| `S3MO_Client::delete_object()` | Existing | Delete individual S3 object | Already implemented with error handling |
| `S3MO_Tracker` static methods | Existing | Read/clear offload metadata | Already implemented |

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| AWS S3 `deleteObjects()` | SDK v3 | Batch delete up to 1000 objects | Future optimization if needed; not required for Phase 4 |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `delete_attachment` hook | `before_delete_post` hook | `before_delete_post` does NOT fire for attachments; `delete_attachment` is the correct equivalent |
| Individual `deleteObject` calls | `deleteObjects` batch API | Batch is more efficient but adds complexity; typical attachment has <15 files, loop is fine |
| `delete_attachment` (action) | `pre_delete_attachment` (filter) | `pre_delete_attachment` can cancel deletion; we only want cleanup, not cancellation |

## Architecture Patterns

### Recommended Project Structure

```
includes/
├── class-s3mo-client.php            # Existing - has delete_object()
├── class-s3mo-tracker.php           # Existing - has get_s3_key(), clear_offload_status()
├── class-s3mo-upload-handler.php    # Existing - pattern to follow
├── class-s3mo-delete-handler.php    # NEW - deletion hook handler
└── class-s3mo-url-rewriter.php      # Existing
```

### Pattern 1: Delete Handler (mirrors Upload Handler)

**What:** A dedicated class that registers the `delete_attachment` hook and handles S3 cleanup.
**When to use:** Always -- follows the same constructor-injection + `register_hooks()` pattern as `S3MO_Upload_Handler`.

**Example:**
```php
// Source: WordPress developer.wordpress.org/reference/hooks/delete_attachment/
class S3MO_Delete_Handler {

    private S3MO_Client $client;

    public function __construct(S3MO_Client $client) {
        $this->client = $client;
    }

    public function register_hooks(): void {
        add_action('delete_attachment', [$this, 'handle_delete'], 10, 2);
    }

    public function handle_delete(int $post_id, \WP_Post $post): void {
        // 1. Guard: only process offloaded attachments
        if (!S3MO_Tracker::is_offloaded($post_id)) {
            return;
        }

        // 2. Collect all S3 keys (original + thumbnails)
        $keys = $this->collect_s3_keys($post_id);

        // 3. Delete each from S3, logging failures
        foreach ($keys as $key) {
            $result = $this->client->delete_object($key);
            if (!$result['success']) {
                error_log('CT S3 Offloader: Failed to delete S3 object: ' . $key
                    . ' — ' . ($result['error'] ?? 'Unknown error'));
            }
        }

        // 4. Clear tracker metadata
        S3MO_Tracker::clear_offload_status($post_id);
    }
}
```

### Pattern 2: Collecting All S3 Keys (Original + Thumbnails)

**What:** Derive thumbnail S3 keys from the original key's directory path + thumbnail filenames from `wp_get_attachment_metadata()`.
**When to use:** This mirrors exactly how the upload handler builds the file list.

**Example:**
```php
// Source: Mirrors S3MO_Upload_Handler::handle_upload() line 74-84
private function collect_s3_keys(int $post_id): array {
    $keys = [];
    $s3_key = S3MO_Tracker::get_s3_key($post_id);

    if (empty($s3_key)) {
        return $keys;
    }

    // Original file
    $keys[] = $s3_key;

    // Thumbnails: same directory as original, different filenames
    $metadata = wp_get_attachment_metadata($post_id);

    if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
        $s3_dir = dirname($s3_key);

        foreach ($metadata['sizes'] as $size_data) {
            $keys[] = $s3_dir . '/' . $size_data['file'];
        }
    }

    return $keys;
}
```

### Pattern 3: Bootstrap Registration

**What:** Instantiate and register the delete handler in `ct-s3-offloader.php` alongside the upload handler.
**When to use:** Plugin initialization.

**Example:**
```php
// In ct-s3-offloader.php plugins_loaded callback, after upload handler:
$delete_handler = new S3MO_Delete_Handler($client);
$delete_handler->register_hooks();
```

### Anti-Patterns to Avoid

- **Hooking `before_delete_post`:** This hook does NOT fire for attachments. WordPress uses `delete_attachment` as the equivalent.
- **Trying to read metadata after deletion:** The `deleted_post` hook fires AFTER postmeta is purged. Never use it for S3 cleanup.
- **Throwing exceptions on S3 failure:** This would prevent WordPress from completing the media deletion, violating DEL-04.
- **Deleting tracker meta before S3 deletion:** Must read the S3 key first, delete from S3, then clear metadata.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| S3 object deletion | Raw HTTP calls to S3 API | `S3MO_Client::delete_object()` | Already wraps AWS SDK with error handling |
| Finding thumbnail filenames | Manual file path construction | `wp_get_attachment_metadata()['sizes']` | WordPress already tracks all generated sizes |
| S3 key for original file | Re-deriving from upload path | `S3MO_Tracker::get_s3_key()` | Already stored in postmeta at upload time |
| Clearing offload status | Manual `delete_post_meta()` calls | `S3MO_Tracker::clear_offload_status()` | Already handles all 4 meta keys |

**Key insight:** Every building block for this phase already exists. The delete handler is primarily orchestration logic connecting existing methods.

## Common Pitfalls

### Pitfall 1: Using the Wrong Hook

**What goes wrong:** Using `before_delete_post` or `delete_post` instead of `delete_attachment` means the hook never fires for media deletions.
**Why it happens:** WordPress documentation is confusing -- `before_delete_post` explicitly states it fires "before a post is deleted" but it does NOT fire for attachments.
**How to avoid:** Always use `delete_attachment` for media/attachment cleanup.
**Warning signs:** S3 objects are never deleted when media is removed from the library.

### Pitfall 2: Metadata Unavailable at Hook Time

**What goes wrong:** Trying to read attachment metadata too late in the deletion process.
**Why it happens:** After `delete_attachment` fires, WordPress deletes all postmeta before deleting files.
**How to avoid:** The `delete_attachment` hook fires BEFORE any postmeta deletion. All metadata is safe to read here.
**Warning signs:** Empty S3 keys or missing thumbnail info in the handler.

### Pitfall 3: S3 Deletion Failure Blocking WordPress

**What goes wrong:** An unhandled exception from the S3 SDK propagates up and prevents WordPress from completing the media deletion.
**Why it happens:** Not wrapping S3 calls in try/catch, or re-throwing exceptions.
**How to avoid:** Wrap every S3 deletion call. Log failures. Never throw. The `delete_object()` method already returns `['success' => false]` on failure, but wrap the handler call too for safety.
**Warning signs:** Media items stuck in "deleting" state or PHP fatal errors on media deletion.

### Pitfall 4: Orphaned Thumbnails in S3

**What goes wrong:** Original file is deleted from S3 but thumbnails remain because only the tracked key (original) was deleted.
**Why it happens:** Only deleting `S3MO_Tracker::get_s3_key()` without also processing `wp_get_attachment_metadata()['sizes']`.
**How to avoid:** Always iterate through ALL sizes in attachment metadata and build the complete key list before deleting.
**Warning signs:** S3 bucket slowly fills with orphaned thumbnail files.

### Pitfall 5: Duplicate Thumbnail Filenames

**What goes wrong:** Multiple sizes may share the same physical file (e.g., when source image is smaller than some registered sizes, WordPress reuses the same file).
**Why it happens:** WordPress does not generate a thumbnail if the source is already at or below that size.
**How to avoid:** Deduplicate the keys array with `array_unique()` before deleting. Deleting the same S3 key twice is harmless (S3 returns success for already-deleted keys), but deduplication avoids unnecessary API calls.
**Warning signs:** Unnecessary S3 API calls, confusing error logs with duplicate entries.

## Code Examples

### Complete Delete Handler Class

```php
// Source: Combines WordPress delete_attachment hook with existing S3MO_Client/Tracker
<?php
defined('ABSPATH') || exit;

class S3MO_Delete_Handler {

    private S3MO_Client $client;

    public function __construct(S3MO_Client $client) {
        $this->client = $client;
    }

    public function register_hooks(): void {
        add_action('delete_attachment', [$this, 'handle_delete'], 10, 2);
    }

    public function handle_delete(int $post_id, \WP_Post $post): void {
        if (!S3MO_Tracker::is_offloaded($post_id)) {
            return;
        }

        $keys   = $this->collect_s3_keys($post_id);
        $keys   = array_unique($keys);
        $errors = [];

        foreach ($keys as $key) {
            try {
                $result = $this->client->delete_object($key);
                if (!$result['success']) {
                    $errors[] = $key . ' -- ' . ($result['error'] ?? 'Unknown error');
                }
            } catch (\Throwable $e) {
                $errors[] = $key . ' -- ' . $e->getMessage();
            }
        }

        if (!empty($errors)) {
            error_log(
                'CT S3 Offloader: Failed to delete some S3 objects for attachment '
                . $post_id . ': ' . implode('; ', $errors)
            );
        }

        S3MO_Tracker::clear_offload_status($post_id);
    }

    private function collect_s3_keys(int $post_id): array {
        $keys   = [];
        $s3_key = S3MO_Tracker::get_s3_key($post_id);

        if (empty($s3_key)) {
            return $keys;
        }

        $keys[] = $s3_key;

        $metadata = wp_get_attachment_metadata($post_id);

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $s3_dir = dirname($s3_key);
            foreach ($metadata['sizes'] as $size_data) {
                $keys[] = $s3_dir . '/' . $size_data['file'];
            }
        }

        return $keys;
    }
}
```

### Bootstrap Registration Addition

```php
// In ct-s3-offloader.php, inside plugins_loaded callback, after line 87:
$delete_handler = new S3MO_Delete_Handler($client);
$delete_handler->register_hooks();
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `delete_attachment` fired after deletion (WP <2.8) | `delete_attachment` fires before deletion (WP 2.8+) | WordPress 2.8, changeset #10400 | Metadata is safely available in the hook |
| Individual `deleteObject` calls | `deleteObjects` batch API (up to 1000 per request) | Always available in SDK v3 | Optimization for bulk operations, not needed for single-attachment deletion |

**Deprecated/outdated:**
- None relevant. The `delete_attachment` hook has been stable since WordPress 2.8.

## Open Questions

1. **Non-image attachments (PDFs, videos, etc.)**
   - What we know: Non-image attachments have no `sizes` array in metadata -- only the original file.
   - What's unclear: Nothing. The `collect_s3_keys()` method handles this correctly because it checks `!empty($metadata['sizes'])`.
   - Recommendation: No special handling needed; the code naturally handles this case.

2. **Bulk deletion from Media Library**
   - What we know: WordPress's bulk delete calls `wp_delete_attachment()` in a loop, so `delete_attachment` fires for each item individually.
   - What's unclear: Performance with many deletions (each fires separate S3 API calls).
   - Recommendation: Accept individual deletion for Phase 4. Batch optimization is a future enhancement.

## Sources

### Primary (HIGH confidence)
- [WordPress `wp_delete_attachment()` source code](https://developer.wordpress.org/reference/functions/wp_delete_attachment/) - Full function source showing hook order
- [WordPress `delete_attachment` hook](https://developer.wordpress.org/reference/hooks/delete_attachment/) - Hook fires before deletion, metadata available
- Existing codebase: `S3MO_Client::delete_object()`, `S3MO_Tracker`, `S3MO_Upload_Handler` -- read directly

### Secondary (MEDIUM confidence)
- [AWS S3 DeleteObjects API](https://docs.aws.amazon.com/AmazonS3/latest/API/API_DeleteObjects.html) - Batch delete capability (up to 1000 objects)
- [wp-kama.com delete_attachment documentation](https://wp-kama.com/hook/delete_attachment) - Additional hook documentation

### Tertiary (LOW confidence)
- None. All findings verified with official sources.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - WordPress hooks verified via official source code; S3 client already exists in codebase
- Architecture: HIGH - Directly mirrors existing Upload Handler pattern already in the codebase
- Pitfalls: HIGH - Hook ordering verified against WordPress source code; `before_delete_post` not-for-attachments confirmed in official docs

**Research date:** 2026-02-28
**Valid until:** 2026-06-28 (stable WordPress hooks, no changes expected)
