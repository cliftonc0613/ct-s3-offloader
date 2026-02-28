# Phase 2: S3 Upload Pipeline - Research

**Researched:** 2026-02-27
**Domain:** WordPress media upload hooks, AWS SDK ObjectUploader, S3 object metadata, attachment postmeta tracking
**Confidence:** HIGH

## Summary

Phase 2 adds the core upload pipeline: when a user uploads media through WordPress, the plugin copies the original file and all generated thumbnails to S3 with correct Content-Type and Cache-Control headers. The implementation hooks into `wp_generate_attachment_metadata` (fires once after all thumbnails are generated), iterates the metadata `sizes` array, and uploads each file using the AWS SDK `ObjectUploader` class (auto-selects single PUT vs multipart based on 16MB threshold).

The existing `S3MO_Client` class from Phase 1 needs `upload_object()` and `delete_object()` methods added. Two new classes are required: `S3MO_Upload_Handler` (hooks WordPress, orchestrates uploads) and `S3MO_Tracker` (reads/writes attachment postmeta for offload status). Error handling wraps each S3 upload in try/catch, logs failures via `error_log()`, and returns the metadata unchanged so WordPress media flow is never disrupted.

Critical implementation details: (1) thumbnail `file` values in metadata are filename-only, not paths -- the directory must be derived from the original file's path; (2) no ACL parameter should be passed to ObjectUploader since the bucket uses OAC; (3) Content-Type comes from `wp_check_filetype()` and the metadata `mime-type` key; (4) Cache-Control should be `public, max-age=31536000, immutable` for media assets.

**Primary recommendation:** Build three components in order: extend S3MO_Client with upload method, create S3MO_Tracker for postmeta, then create S3MO_Upload_Handler to wire them together on the `wp_generate_attachment_metadata` filter.

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| AWS SDK ObjectUploader | 3.x (bundled) | Upload files to S3 with auto single/multipart | Official AWS SDK class, handles files of any size automatically |
| WordPress Media API | Core | `wp_generate_attachment_metadata` filter, `get_attached_file()`, `wp_check_filetype()` | Native WordPress hooks for media lifecycle |
| WordPress Postmeta API | Core | `update_post_meta()`, `get_post_meta()`, `delete_post_meta()` | Standard WordPress data storage for per-attachment tracking |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `wp_upload_dir()` | Core | Get uploads base directory for path resolution | Resolving thumbnail absolute paths from relative filenames |
| `wp_check_filetype()` | Core | MIME type detection from filename | Setting Content-Type header on S3 objects |
| `get_post_mime_type()` | Core | Get MIME type from attachment post record | Fallback MIME detection for original file |
| `error_log()` | PHP | Error logging for failed uploads | Recording S3 upload failures without disrupting WordPress |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| ObjectUploader | Direct `putObject()` call | putObject works for small files but fails for files > 5GB. ObjectUploader auto-selects and is required by UPLD-07 |
| `error_log()` | Custom log table or WP_Error | error_log is simple, no schema needed. Custom logging can be added later |
| Postmeta tracking | Custom database table | Postmeta is simpler, auto-deletes with attachment, works with WP export/import. Custom table is overkill for v1 |

## Architecture Patterns

### Recommended Project Structure (New Files for Phase 2)
```
ct-s3-offloader/
  includes/
    class-s3mo-client.php          # MODIFY: Add upload_object() and delete_object()
    class-s3mo-upload-handler.php  # NEW: Hook handler for wp_generate_attachment_metadata
    class-s3mo-tracker.php         # NEW: Attachment postmeta read/write
  ct-s3-offloader.php             # MODIFY: Instantiate and register new components
```

### Pattern 1: Hook-Driven Upload Interceptor

**What:** Filter `wp_generate_attachment_metadata` to copy files to S3 as a side effect, returning metadata unchanged.
**When to use:** Every new media upload through WordPress.

```php
// Source: WordPress Developer Reference + AWS SDK docs
add_filter('wp_generate_attachment_metadata', [$this, 'handle_upload'], 10, 3);

public function handle_upload(array $metadata, int $attachment_id, string $context): array {
    // Only act on new uploads, not updates
    if ($context !== 'create') {
        return $metadata;
    }

    // Upload original file
    $local_path = get_attached_file($attachment_id);
    $s3_key = $this->build_s3_key($metadata['file']);
    $this->upload_file($local_path, $s3_key, $attachment_id);

    // Upload each thumbnail size
    if (!empty($metadata['sizes'])) {
        $upload_dir = wp_upload_dir();
        $base_dir = trailingslashit($upload_dir['basedir']);
        // Thumbnails are in the same directory as the original
        $original_dir = dirname($metadata['file']);

        foreach ($metadata['sizes'] as $size_name => $size_data) {
            $thumb_local = $base_dir . trailingslashit($original_dir) . $size_data['file'];
            $thumb_s3_key = $this->build_s3_key(
                trailingslashit($original_dir) . $size_data['file']
            );
            $this->upload_file($thumb_local, $thumb_s3_key, $attachment_id);
        }
    }

    // Track offload status
    $this->tracker->mark_as_offloaded($attachment_id, $s3_key);

    return $metadata; // MUST return unchanged
}
```

### Pattern 2: ObjectUploader with Params for Headers

**What:** Use ObjectUploader with `params` array to set Content-Type and Cache-Control on each S3 object.
**When to use:** Every file upload to S3.

```php
// Source: AWS SDK PHP docs - ObjectUploader class
use Aws\S3\ObjectUploader;
use Aws\Exception\MultipartUploadException;

public function upload_object(string $local_path, string $s3_key, string $content_type): array {
    $body = fopen($local_path, 'rb');

    if ($body === false) {
        throw new \RuntimeException("Cannot open file: {$local_path}");
    }

    try {
        $uploader = new ObjectUploader(
            $this->s3,
            $this->bucket,
            $s3_key,
            $body,
            'private',  // ACL - private because OAC handles access
            [
                'params' => [
                    'ContentType'  => $content_type,
                    'CacheControl' => 'public, max-age=31536000, immutable',
                ],
            ]
        );

        $result = $uploader->upload();
        return [
            'success' => true,
            'key'     => $s3_key,
            'url'     => $result['ObjectURL'] ?? '',
        ];
    } catch (MultipartUploadException $e) {
        throw $e; // Let caller handle
    } finally {
        if (is_resource($body)) {
            fclose($body);
        }
    }
}
```

### Pattern 3: Postmeta Tracker

**What:** Store offload status, S3 key, and timestamp in attachment postmeta.
**When to use:** After every successful upload, during status checks.

```php
// Meta key constants
const META_OFFLOADED    = '_s3mo_offloaded';
const META_KEY          = '_s3mo_key';
const META_BUCKET       = '_s3mo_bucket';
const META_OFFLOADED_AT = '_s3mo_offloaded_at';

public function mark_as_offloaded(int $attachment_id, string $s3_key): void {
    update_post_meta($attachment_id, self::META_OFFLOADED, '1');
    update_post_meta($attachment_id, self::META_KEY, $s3_key);
    update_post_meta($attachment_id, self::META_BUCKET, $this->bucket);
    update_post_meta($attachment_id, self::META_OFFLOADED_AT, gmdate('c'));
}

public function is_offloaded(int $attachment_id): bool {
    return (bool) get_post_meta($attachment_id, self::META_OFFLOADED, true);
}

public function get_s3_key(int $attachment_id): string {
    return (string) get_post_meta($attachment_id, self::META_KEY, true);
}
```

### Anti-Patterns to Avoid

- **Hooking `wp_update_attachment_metadata` instead of `wp_generate_attachment_metadata`:** In WordPress 5.3+, `wp_update_attachment_metadata` fires multiple times per upload (once per sub-size). This causes duplicate S3 uploads. Use `wp_generate_attachment_metadata` which fires once after all sizes are generated.

- **Passing `'ACL' => 'public-read'` to ObjectUploader:** S3 buckets created after April 2023 have ACLs disabled by default ("Bucket owner enforced"). Passing an ACL parameter throws `AccessControlListNotSupported`. Since we use CloudFront OAC, objects stay private and OAC grants CloudFront access.

- **Using `$metadata['sizes'][...]['file']` as a full path:** The `file` value in each size entry is a filename only (e.g., `photo-150x150.jpg`), NOT a relative path. The directory must be derived from the original file's path (`dirname($metadata['file'])`).

- **Breaking the filter chain by not returning `$metadata`:** The `wp_generate_attachment_metadata` hook is a filter. You MUST return `$metadata` unchanged. Failing to return it or returning a modified version will corrupt the attachment metadata WordPress saves.

- **Uploading synchronously without error isolation:** Each S3 upload must be wrapped in try/catch. A failed thumbnail upload must NOT prevent other thumbnails from uploading, and must NOT break the WordPress media flow.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Single vs multipart upload decision | Custom size-checking logic | `Aws\S3\ObjectUploader` | Handles threshold detection, multipart chunking, retry on partial failure automatically |
| MIME type detection | Manual extension-to-MIME mapping | `wp_check_filetype()` or `$size_data['mime-type']` | WordPress maintains a comprehensive mapping; thumbnails already have mime-type in metadata |
| File path resolution | Hardcoded upload paths | `get_attached_file()` + `wp_upload_dir()` | Handles custom upload directories, multisite paths, filtered paths |
| Attachment tracking state | Custom database table | WordPress `update_post_meta()` / `get_post_meta()` | Auto-cleanup on attachment delete, works with WP export/import, no migration needed |

**Key insight:** WordPress already provides the metadata structure with all file paths and MIME types. The upload handler just needs to iterate it and pass values to the S3 client. No file inspection or path guessing is needed.

## Common Pitfalls

### Pitfall 1: Thumbnail Path Assembly Error
**What goes wrong:** Code treats `$metadata['sizes']['thumbnail']['file']` as a relative path from the uploads base, but it is actually just the filename. The original `$metadata['file']` IS a relative path (e.g., `2026/02/photo.jpg`) but size entries contain only filenames (e.g., `photo-150x150.jpg`).
**Why it happens:** The two `file` keys have different formats, which is not obvious from the metadata structure.
**How to avoid:** Always derive the thumbnail directory from the original file path:
```php
$original_dir = dirname($metadata['file']); // "2026/02"
$thumb_path = trailingslashit($original_dir) . $size_data['file']; // "2026/02/photo-150x150.jpg"
```
**Warning signs:** S3 objects appearing in the bucket root instead of year/month subdirectories.

### Pitfall 2: Not Checking $context Parameter
**What goes wrong:** The `wp_generate_attachment_metadata` filter fires with `$context = 'create'` for new uploads AND `$context = 'update'` for image edits (crop, rotate, regenerate thumbnails). Without checking context, the handler re-uploads on every metadata regeneration.
**Why it happens:** Developers assume the filter only fires on new uploads.
**How to avoid:** Check `$context === 'create'` for initial uploads only. For `'update'` context, decide whether to re-upload (for image edits) or skip (for metadata-only updates). For Phase 2, handling only `'create'` is safest.
**Warning signs:** Duplicate S3 uploads appearing when editing images in Media Library.

### Pitfall 3: Stream Resource Not Closed on Error
**What goes wrong:** `fopen()` opens the file for upload, but if `ObjectUploader::upload()` throws, the file handle leaks. Over many uploads (bulk migration), this exhausts file descriptors.
**Why it happens:** Error path does not close the stream.
**How to avoid:** Use `try/finally` to ensure `fclose()` runs regardless of success or failure.
**Warning signs:** "Too many open files" errors during bulk operations.

### Pitfall 4: Missing Content-Type on Thumbnails
**What goes wrong:** Code sets Content-Type on the original file using `get_post_mime_type()` but forgets to set it on thumbnails. S3 defaults to `application/octet-stream`, causing browsers to download images instead of displaying them.
**Why it happens:** Developers handle the original file carefully but loop through thumbnails carelessly.
**How to avoid:** Each thumbnail size in `$metadata['sizes']` has a `mime-type` key. Use it:
```php
$content_type = $size_data['mime-type']; // "image/jpeg", "image/png", etc.
```
**Warning signs:** Images loading correctly from S3 in some places but downloading as files in others.

### Pitfall 5: Error in One Thumbnail Aborts All Uploads
**What goes wrong:** Upload handler iterates thumbnails in a loop. One thumbnail fails (file missing, S3 error), exception propagates, and remaining thumbnails are never uploaded.
**Why it happens:** No per-file error isolation in the loop.
**How to avoid:** Wrap each individual upload in try/catch. Log the error. Continue with remaining files. Track partial upload status.
**Warning signs:** Attachments on S3 with some sizes missing.

## Code Examples

### Complete Upload Handler Registration (Plugin Bootstrap)
```php
// In ct-s3-offloader.php, inside plugins_loaded callback
if (empty($missing)) {
    $client  = new S3MO_Client();
    $tracker = new S3MO_Tracker($client->get_bucket());

    // Register upload handler (fires on all uploads, front and back end)
    $upload_handler = new S3MO_Upload_Handler($client, $tracker);
    $upload_handler->register_hooks();

    if (is_admin()) {
        $settings = new S3MO_Settings_Page($client);
        $settings->register_hooks();
    }
}
```

### S3 Key Construction from WordPress Path
```php
// Source: WordPress wp_upload_dir() documentation
public function build_s3_key(string $relative_path): string {
    $prefix = get_option('s3mo_path_prefix', 'wp-content/uploads');
    $prefix = trim($prefix, '/');

    // $relative_path is like "2026/02/photo.jpg" (from metadata['file'])
    return $prefix . '/' . ltrim($relative_path, '/');
}
// Result: "wp-content/uploads/2026/02/photo.jpg"
```

### MIME Type Resolution for Upload
```php
// For original file: use get_post_mime_type() or wp_check_filetype()
$original_mime = get_post_mime_type($attachment_id);
if (!$original_mime) {
    $filetype = wp_check_filetype(basename($local_path));
    $original_mime = $filetype['type'] ?: 'application/octet-stream';
}

// For thumbnails: use the mime-type from metadata sizes array
$thumb_mime = $size_data['mime-type']; // Already available in metadata
```

### Error-Isolated Thumbnail Upload Loop
```php
// Source: Project architecture research
$errors = [];
foreach ($metadata['sizes'] as $size_name => $size_data) {
    try {
        $thumb_local = $base_dir . trailingslashit($original_dir) . $size_data['file'];

        if (!file_exists($thumb_local)) {
            $errors[] = "File not found for size '{$size_name}': {$thumb_local}";
            continue;
        }

        $thumb_key = $this->build_s3_key(
            trailingslashit($original_dir) . $size_data['file']
        );

        $this->client->upload_object(
            $thumb_local,
            $thumb_key,
            $size_data['mime-type']
        );
    } catch (\Exception $e) {
        $errors[] = "Failed to upload size '{$size_name}': " . $e->getMessage();
        error_log(
            "[CT S3 Offloader] Upload failed for attachment {$attachment_id}, "
            . "size '{$size_name}': " . $e->getMessage()
        );
    }
}
```

### ObjectUploader with No ACL (OAC Pattern)
```php
// IMPORTANT: Do not pass 'public-read' ACL.
// The 5th parameter to ObjectUploader is ACL. Pass 'private' (default)
// because CloudFront OAC handles public access.
$uploader = new ObjectUploader(
    $this->s3,            // S3Client instance
    $this->bucket,        // Bucket name
    $s3_key,              // Object key
    $body,                // fopen() stream
    'private',            // ACL - keep private, OAC grants CloudFront access
    [
        'params' => [
            'ContentType'  => $content_type,
            'CacheControl' => 'public, max-age=31536000, immutable',
        ],
    ]
);
$result = $uploader->upload();
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `'ACL' => 'public-read'` on uploads | No ACL, use OAC for CloudFront access | April 2023 (AWS default change) | Must not pass ACL parameter on new buckets |
| Hook `wp_update_attachment_metadata` | Hook `wp_generate_attachment_metadata` | WordPress 5.3 (Nov 2019) | `wp_update_attachment_metadata` fires multiple times; `wp_generate_attachment_metadata` fires once |
| Manual size threshold for multipart | ObjectUploader auto-selects | AWS SDK 3.x | No need to check file size manually |
| `$context` parameter not available | `$context` is 3rd parameter ('create'/'update') | WordPress 5.3 | Can distinguish new uploads from edits |

**Deprecated/outdated:**
- `wp_get_attachment_thumb_file()`: Deprecated, only works with very old metadata format. Use `$metadata['sizes']` array instead.
- Public ACLs on S3: Disabled by default on new buckets since April 2023. Use bucket policies or OAC.

## Open Questions

1. **Should `$context === 'update'` trigger re-upload?**
   - What we know: The `'update'` context fires when images are cropped/rotated in Media Library, or when thumbnails are regenerated. These operations create new files that should go to S3.
   - What's unclear: Whether re-uploading on every `'update'` is safe or could cause excessive uploads in edge cases.
   - Recommendation: For Phase 2, handle ONLY `'create'`. Add `'update'` support in Phase 4 (Deletion Sync) where we also handle file replacement logic.

2. **Should partial upload failure mark the attachment as offloaded?**
   - What we know: If the original uploads but 2 of 5 thumbnails fail, the attachment is partially on S3.
   - What's unclear: Whether to mark as offloaded (risk serving broken thumbnails) or not (risk never retrying).
   - Recommendation: Only mark as offloaded if the original AND all thumbnails succeed. Log partial failures for manual review. This is safest for Phase 2.

3. **Cache-Control `immutable` directive browser support**
   - What we know: `immutable` is supported by Firefox, Chrome 100+, Safari 16+. It prevents revalidation requests for cached assets.
   - What's unclear: Whether any edge cases exist with WordPress image editing (crop creates new filename, so cache is naturally busted).
   - Recommendation: Use `public, max-age=31536000, immutable`. Since WordPress generates unique filenames for edits, immutable is safe.

## Sources

### Primary (HIGH confidence)
- AWS SDK ObjectUploader source code: `/aws-sdk/Aws/S3/ObjectUploader.php` in bundled SDK -- verified constructor signature, params array, upload() method
- [AWS SDK PHP ObjectUploader API Reference](https://docs.aws.amazon.com/aws-sdk-php/v3/api/class-Aws.S3.ObjectUploader.html) -- constructor params, options array structure
- [AWS SDK PHP Multipart Upload Guide](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/s3-multipart-upload.html) -- ObjectUploader usage examples, params for ContentType/CacheControl
- [WordPress Developer Reference: wp_generate_attachment_metadata hook](https://developer.wordpress.org/reference/hooks/wp_generate_attachment_metadata/) -- filter signature, $context parameter, when it fires
- [WordPress Developer Reference: wp_get_attachment_metadata()](https://developer.wordpress.org/reference/functions/wp_get_attachment_metadata/) -- metadata array structure, sizes format
- [WordPress Developer Reference: get_attached_file()](https://developer.wordpress.org/reference/functions/get_attached_file/) -- absolute path retrieval
- [WordPress Developer Reference: wp_check_filetype()](https://developer.wordpress.org/reference/functions/wp_check_filetype/) -- MIME type detection

### Secondary (MEDIUM confidence)
- [WordPress Core dev note: wp_update_attachment_metadata multiple-fire behavior](https://make.wordpress.org/core/2019/11/05/use-of-the-wp_update_attachment_metadata-filter-as-upload-is-complete-hook/) -- confirmed wp_update_attachment_metadata fires multiple times in WP 5.3+
- Project research documents: `.planning/research/ARCHITECTURE.md`, `.planning/research/PITFALLS.md` -- verified data flow, component boundaries, pitfall catalog

### Tertiary (LOW confidence)
- None. All findings verified against primary sources.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - AWS SDK ObjectUploader verified from bundled source code; WordPress hooks verified from official developer reference
- Architecture: HIGH - Upload handler pattern verified from project research and WordPress core documentation; metadata structure verified from official docs
- Pitfalls: HIGH - Hook timing, ACL issues, and path assembly confirmed across multiple authoritative sources

**Research date:** 2026-02-27
**Valid until:** 2026-03-27 (stable domain -- WordPress media API and AWS SDK ObjectUploader are mature, unlikely to change)
