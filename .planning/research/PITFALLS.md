# Domain Pitfalls: WordPress S3 Media Offloader

**Domain:** WordPress plugin - S3 media offloading with CloudFront CDN
**Researched:** 2026-02-27
**Confidence:** HIGH (verified against WP Offload Media issues, AWS docs, WordPress Core docs)

---

## Critical Pitfalls

Mistakes that cause data loss, broken sites, or require rewrites.

---

### Pitfall 1: Hooking Into the Wrong Upload Lifecycle Event

**What goes wrong:** Plugin hooks into `add_attachment` or `wp_handle_upload` to trigger S3 upload, but thumbnails have not been generated yet. The original file gets uploaded to S3, but all generated sizes (thumbnail, medium, large, custom) are missed. Alternatively, hooking too late means the file is already served locally and URL references are stale.

**Why it happens:** WordPress media upload is a multi-step pipeline:
1. `wp_handle_upload` - file lands on disk
2. `wp_insert_attachment` - DB record created
3. `wp_generate_attachment_metadata()` called - thumbnails generated
4. `wp_update_attachment_metadata` filter fires - metadata saved

Most S3 plugins hook into `wp_update_attachment_metadata` because it fires AFTER all thumbnails exist. But this is a **filter**, not an action -- it is semantically a data-transformation hook, not a "upload complete" signal. WordPress Core explicitly warned about this misuse in a 2019 dev note.

**Consequences:**
- Missing thumbnails on S3 (only original uploaded)
- Broken responsive images (srcset references missing sizes)
- Race conditions if metadata is updated multiple times (image editing, regeneration)

**Prevention:**
- Hook into `wp_update_attachment_metadata` filter (priority 10 or later) as the practical "upload complete" signal -- this is what every major S3 plugin does despite the semantic mismatch, because no better alternative exists
- Upload ALL files listed in `$metadata['sizes']` plus the original `$metadata['file']`
- Re-fire on `wp_update_attachment_metadata` for subsequent calls (image edits, regeneration)
- Always verify files exist on disk before uploading to S3

**Detection:** After uploading an image, check S3 bucket -- if only the original exists without thumbnail/medium/large variants, this hook is wrong.

**Phase:** Phase 1 (Upload Pipeline) -- get this right from the start.

**Sources:**
- [WordPress Core dev note on wp_update_attachment_metadata misuse](https://make.wordpress.org/core/2019/11/05/use-of-the-wp_update_attachment_metadata-filter-as-upload-is-complete-hook/)
- [WP Offload Media Issue #340: Programmatic uploads not sent to S3](https://github.com/deliciousbrains/wp-amazon-s3-and-cloudfront/issues/340)

---

### Pitfall 2: Serialized Data Corruption During URL Rewriting

**What goes wrong:** WordPress stores serialized PHP data in `wp_options`, `wp_postmeta`, and widget configurations. When you do a find-and-replace from local URLs to S3/CloudFront URLs (during migration or in output filtering), serialized strings break because PHP serialization includes character counts: `s:45:"http://local.test/wp-content/uploads/img.jpg"`. Changing the URL length without updating the count corrupts the data.

**Why it happens:** Local URLs (e.g., `http://clemsonsportsmedia.local/wp-content/uploads/2026/02/photo.jpg`) are shorter/longer than CloudFront URLs (e.g., `https://d1234.cloudfront.net/uploads/2026/02/photo.jpg`). A naive `str_replace` breaks every serialized string containing that URL.

**Consequences:**
- Widgets disappear or show errors
- Plugin settings corrupted
- Options table entries silently break (site behaves unpredictably)
- Gutenberg block attributes stored as JSON-in-serialized-data double-break

**Prevention:**
- NEVER do raw `str_replace` on the database for URL migration
- For runtime URL rewriting: filter at the output layer using `wp_get_attachment_url` and `wp_get_attachment_image_src` filters, which handle individual URLs safely
- For migration: use `wp search-replace` with `--precise` flag (handles serialized data) or a dedicated serialization-aware tool
- For Gutenberg blocks: URLs are stored as JSON in `post_content` (not serialized), so standard string replacement works there -- but test with blocks that contain nested serialized attributes

**Detection:** After any URL migration, run `wp search-replace --dry-run` to check for serialized data mismatches. Look for `unserialize()` warnings in error logs.

**Phase:** Phase 2 (URL Rewriting) -- core architecture decision that is hard to fix later.

**Sources:**
- [WPEngine: Serialized Data in WordPress](https://wpengine.com/support/wordpress-serialized-data/)
- [WP Offload Media changelog: serialized data bug fixes](https://deliciousbrains.com/wp-offload-media/doc/changelog/)

---

### Pitfall 3: AWS SDK Autoloader Conflicts with Other Plugins

**What goes wrong:** Your plugin bundles AWS SDK v3. Another active plugin (backup plugin, email plugin, another S3 plugin) also bundles AWS SDK but a different version. PHP autoloaders collide -- classes from the wrong version get loaded, causing fatal errors.

**Why it happens:** WordPress has no dependency management. Two plugins can each include their own `vendor/autoload.php` with the `Aws\` namespace. Whichever plugin loads first wins, and the second plugin gets the wrong version's classes. Guzzle version conflicts are especially common (v6 vs v7 breaking changes).

**Consequences:**
- Fatal errors: `Call to undefined method` or `Class not found`
- White screen of death
- Errors only appear when specific plugin combinations are active (hard to reproduce)
- Users blame your plugin

**Prevention:**
- **Namespace-prefix the AWS SDK** using a tool like `PHP-Scoper` or `Mozart` to rename `Aws\S3\S3Client` to `CTS3Offloader\Aws\S3\S3Client` -- this is the gold standard
- Alternatively: only bundle the S3-specific SDK classes (S3Client, Credentials, few dependencies) rather than the full 67MB SDK
- Check if `class_exists('Aws\Sdk')` before loading your autoloader and gracefully handle version mismatches
- Consider the lightweight approach: skip the SDK entirely and use direct REST API calls with pre-signed requests (reduces plugin from ~67MB to ~2.5MB)

**Detection:** Test with common plugins that also bundle AWS SDK: UpdraftPlus, WP Mail SMTP (SES), other backup plugins. Fatal errors on activation.

**Phase:** Phase 1 (Foundation) -- architectural decision that affects entire plugin structure.

**Sources:**
- [AWS for WordPress: Force-loading vendor autoload causes dependency conflicts](https://github.com/awslabs/aws-for-wordpress/issues/38)
- [The 67MB Problem: Building a Lightweight WordPress Media Offload Alternative](https://dev.to/dmitryrechkin/the-67mb-problem-building-a-lightweight-wordpress-media-offload-alternative-4cbh)
- [WordPress.org: Third party libraries/SDK manage conflicts](https://wordpress.org/support/topic/third-party-libraries-sdk-manage-conflicts/)

---

### Pitfall 4: Race Condition When Deleting Local Files After S3 Upload

**What goes wrong:** Plugin uploads file to S3 and immediately deletes the local copy. But WordPress is still generating thumbnails (or another plugin is optimizing the image), and those processes need the local file. Result: failed thumbnail generation, broken optimization, or corrupted metadata.

**Why it happens:** `wp_update_attachment_metadata` fires after the initial thumbnail generation, but:
- Image optimization plugins (ShortPixel, Imagify, Smush) process asynchronously or on a later hook
- WordPress image editing (crop/rotate in Media Library) re-triggers metadata updates
- Regenerate Thumbnails plugin needs the original file on disk
- `wp_update_attachment_metadata` can fire MULTIPLE times for a single upload

**Consequences:**
- Missing or corrupted thumbnails
- Image optimizer processes empty/missing files
- "Could not read image size" errors during thumbnail regeneration
- Users cannot crop/rotate images in the Media Library

**Prevention:**
- NEVER delete local files immediately after S3 upload in Phase 1 -- add "remove local" as a separate, later feature
- When implementing local deletion: use a deferred queue (cron job or shutdown hook) rather than inline deletion
- Before deleting local file, verify: (a) S3 upload succeeded, (b) all sizes exist, (c) no pending optimization jobs
- Support "copy back from S3" for operations that need the local file (image editing, thumbnail regeneration)
- Add a filter to let other plugins prevent local deletion: `apply_filters('ct_s3_should_delete_local', true, $attachment_id)`

**Detection:** Upload an image, wait 30 seconds, check if thumbnails are correct. Test with an image optimizer active. Try editing (crop/rotate) an image after offload.

**Phase:** Phase 3 or later -- do NOT attempt local file removal until core upload/download is rock solid.

**Sources:**
- [WP Offload Media Issue #300: Cannot regenerate thumbnails if originals deleted](https://github.com/deliciousbrains/wp-amazon-s3-and-cloudfront/issues/300)
- [WP Offload Media Issue #487: Regenerate Thumbnails "Could not read image size"](https://github.com/deliciousbrains/wp-amazon-s3-and-cloudfront/issues/487)

---

### Pitfall 5: S3 Bucket ACL Configuration Failures

**What goes wrong:** Plugin tries to set ACLs on uploaded objects (e.g., `public-read`) but fails because AWS disabled ACLs by default on all new S3 buckets created after April 2023. The upload silently succeeds with wrong permissions, or throws an `AccessControlListNotSupported` error.

**Why it happens:** AWS changed S3 defaults. New buckets have "Bucket owner enforced" as the Object Ownership setting, which disables ACLs entirely. Most S3 offloader tutorials and older code examples still show `'ACL' => 'public-read'` in `PutObject` calls.

**Consequences:**
- `AccessControlListNotSupported` errors blocking all uploads
- Files uploaded as private (403 Forbidden when accessed via URL)
- Plugin works on old buckets but fails on new ones (confusing for users)

**Prevention:**
- Do NOT use ACLs at all -- use an S3 Bucket Policy for public access instead
- For CloudFront setups: use Origin Access Control (OAC) so the bucket stays fully private and only CloudFront can access it (this is the modern best practice)
- Make ACL usage configurable with a default of "disabled"
- Test with a brand-new bucket, not a legacy one

**Detection:** Create a fresh S3 bucket with default settings. Try uploading with `'ACL' => 'public-read'`. If you get `AccessControlListNotSupported`, your code assumes legacy ACL behavior.

**Phase:** Phase 1 (S3 Integration) -- must be correct from day one.

**Sources:**
- [WordPress.org: WP Offload Media plugin reviews mentioning ACL issues](https://wordpress.org/plugins/amazon-s3-and-cloudfront/)
- [AWS Documentation: S3 Object Ownership](https://docs.aws.amazon.com/AmazonS3/latest/userguide/about-object-ownership.html)

---

## Moderate Pitfalls

Mistakes that cause delays, technical debt, or poor user experience.

---

### Pitfall 6: Gutenberg Block and srcset URL Rewriting Gaps

**What goes wrong:** URLs in Gutenberg block markup (`post_content`) are not rewritten. The plugin filters `wp_get_attachment_url` and `wp_get_attachment_image_src` correctly, but Gutenberg blocks store absolute URLs directly in the HTML:

```html
<!-- wp:image {"id":123} -->
<figure class="wp-block-image">
  <img src="http://local.test/wp-content/uploads/2026/02/photo.jpg" />
</figure>
<!-- /wp:image -->
```

Filtering attachment functions does not affect these hardcoded URLs.

**Why it happens:** Classic editor uses `[caption]` shortcodes that resolve URLs dynamically. Gutenberg stores the final rendered HTML with absolute URLs baked in. The `srcset` attribute may be rewritten (generated dynamically) while the `src` attribute is not (stored statically).

**Prevention:**
- Add a `the_content` filter that does string replacement on `wp-content/uploads/` URLs in rendered output
- Also filter `wp_calculate_image_srcset` for srcset URLs
- Handle both `http://` and `https://` variants of the site URL
- Consider filtering at the REST API response level for headless/decoupled setups
- Test with: Image blocks, Gallery blocks, Cover blocks, Media & Text blocks

**Detection:** Create a post with Gutenberg image blocks. View page source -- if `src` still points to local/origin server while `srcset` points to CloudFront, this gap exists.

**Phase:** Phase 2 (URL Rewriting) -- must be addressed alongside core URL rewriting.

**Sources:**
- [S3-Uploads Issue #324: Problem with URL rewriting](https://github.com/humanmade/S3-Uploads/issues/324)
- [WordPress/Gutenberg Issue #54262: Relative scheme images in editor](https://github.com/WordPress/gutenberg/issues/54262)

---

### Pitfall 7: CloudFront Cache Invalidation Cost Explosion

**What goes wrong:** Plugin invalidates CloudFront cache on every media update/delete. With bulk operations (migration, regeneration), this creates thousands of invalidation requests. First 1,000/month are free, then $0.005 per path. A migration of 1,000+ files could trigger 5,000+ invalidation paths (original + all sizes).

**Why it happens:** Developers think "file changed, must invalidate cache" without understanding CloudFront pricing or alternatives.

**Consequences:**
- Unexpected AWS bills during migration
- Invalidation requests take 10-15 minutes to propagate (not instant)
- Rate limits: max 3,000 invalidation paths in progress at once
- Performance degradation as CloudFront re-fetches from S3 origin

**Prevention:**
- Use **object versioning** instead of cache invalidation: append a version string to the S3 key (e.g., `/uploads/2026/02/photo-v2.jpg`) so CloudFront sees it as a new object
- For the migration phase: no invalidation needed because files are new to CloudFront
- Only invalidate for true updates (image edit/crop) and do so with specific paths, never wildcards
- Batch invalidations and use a single `/*` wildcard for bulk operations (counts as 1 path)
- Set reasonable `Cache-Control` headers (1 year for immutable media is standard)

**Detection:** Check AWS CloudFront billing after running bulk operations. If invalidation charges appear, switch to versioning.

**Phase:** Phase 2 (CloudFront Integration) -- design versioning into the URL scheme from the start.

**Sources:**
- [Delicious Brains: Never Invalidate the CloudFront Cache Again](https://deliciousbrains.com/wp-offload-media/doc/object-versioning-instead-of-cache-invalidation/)
- [AWS CloudFront Invalidation Docs](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/Invalidation.html)

---

### Pitfall 8: CORS Blocking Fonts and Media in the Browser

**What goes wrong:** Web fonts (WOFF2, TTF) and some media served from S3/CloudFront are blocked by the browser with: `Font from origin 'https://d1234.cloudfront.net' has been blocked from loading by Cross-Origin Resource Sharing policy`.

**Why it happens:** S3 requires explicit CORS configuration. CloudFront requires the `Origin` header to be whitelisted/forwarded to S3 for CORS headers to appear in responses. Missing either piece causes silent failure.

**Subtle gotcha:** S3 CORS only returns `Access-Control-Allow-Origin` when the request includes an `Origin` header. CloudFront must be configured to forward this header. Additionally, `HEAD` requests need to be in the S3 CORS `AllowedMethods` -- not just `GET` -- because browsers preflight with HEAD.

**Prevention:**
- Configure S3 CORS to allow the WordPress site origin:
  ```json
  {
    "AllowedOrigins": ["https://clemsonsportsmedia.com"],
    "AllowedMethods": ["GET", "HEAD"],
    "AllowedHeaders": ["*"],
    "MaxAgeSeconds": 86400
  }
  ```
- In CloudFront: create an Origin Request Policy that forwards the `Origin` header
- In CloudFront: create a Response Headers Policy with CORS headers, or use the managed `CORS-S3Origin` policy
- Test with fonts specifically -- images rarely trigger CORS but fonts always do

**Detection:** Open browser DevTools > Console tab. Load a page with fonts from CloudFront. CORS errors are clearly logged.

**Phase:** Phase 2 (CloudFront Integration) -- configure during initial CloudFront setup.

**Sources:**
- [Delicious Brains: Font CORS with S3/CloudFront](https://deliciousbrains.com/wp-offload-media/doc/font-cors/)
- [S3 CORS requires HEAD not just GET for CloudFront proxy](https://bibwild.wordpress.com/2023/10/09/s3-cors-headers-proxied-by-cloudfront-require-head-not-just-get/)

---

### Pitfall 9: WP-CLI Migration Fails Silently on Large Libraries

**What goes wrong:** A WP-CLI command to migrate 1,000+ existing files to S3 runs for a while, then dies due to PHP memory exhaustion or execution timeout. No indication of what succeeded and what failed. Running it again re-uploads everything (duplicating work) or skips everything (thinking it is done).

**Why it happens:**
- PHP memory builds up as attachment objects accumulate (each `WP_Post` + metadata stays in memory)
- No checkpoint/resume mechanism
- `WP_Query` loads all attachments into memory at once
- S3 upload failures on individual files are swallowed

**Consequences:**
- Migration appears complete but only 60% of files transferred
- Re-running wastes time and bandwidth re-uploading already-migrated files
- Users lose trust in the migration process

**Prevention:**
- Process attachments in batches (50-100 at a time) using `LIMIT`/`OFFSET` queries
- Track migration state per-attachment in postmeta (`_ct_s3_offloaded` = true/false)
- Free memory between batches: `wp_cache_flush()`, unset variables
- Log every success and failure with attachment ID
- Support `--resume` flag that skips already-offloaded attachments
- Support `--dry-run` flag to preview what would be migrated
- Show a progress bar using `WP_CLI\Utils\make_progress_bar()`
- Implement per-file error handling (catch and log, don't abort entire batch)

**Detection:** Migrate a test set of 100 files. Check that all 100 are on S3. Kill the process at 50 and run again -- verify it resumes from 51, not from 1.

**Phase:** Phase 3 (WP-CLI Migration) -- migration is inherently a batch-processing problem.

---

### Pitfall 10: AWS Credentials Stored in the Database (Settings Page)

**What goes wrong:** Plugin stores AWS Access Key ID and Secret Access Key in `wp_options` via the settings page. Database backups, staging site clones, and debug logs now contain live AWS credentials. A SQL injection or database leak exposes S3 access.

**Why it happens:** It is the easiest implementation -- `register_setting()` + `update_option()`. Every WordPress settings tutorial does it this way.

**Consequences:**
- Credentials in database backups (often stored unencrypted, shared via email)
- Credentials visible in staging/dev copies of the database
- Credentials exposed if wp_options is readable via SQL injection
- Violates AWS security best practices

**Prevention:**
- **Primary method:** Read credentials from `wp-config.php` constants:
  ```php
  define('CT_S3_ACCESS_KEY_ID', 'AKIA...');
  define('CT_S3_SECRET_ACCESS_KEY', 'wJalr...');
  ```
- **Better method:** Use AWS IAM Instance Profiles (if on EC2/ECS) -- no credentials stored anywhere
- **Best method for local dev:** Use `~/.aws/credentials` file (AWS default credential chain)
- Settings page should display credential source and status, not store credentials
- If you must allow database storage (for shared hosting users), encrypt the secret key using `wp_salt()` as the encryption key
- NEVER log credentials -- sanitize them from debug output

**Detection:** Check `wp_options` for raw AWS keys. If `SELECT * FROM wp_options WHERE option_name LIKE '%aws%'` returns a secret key in plaintext, this pitfall is present.

**Phase:** Phase 1 (Settings/Configuration) -- security architecture must be correct from the start.

**Sources:**
- [AWS Best Practices for WordPress](https://docs.aws.amazon.com/whitepapers/latest/best-practices-wordpress/plugin-installation-and-configuration.html)
- [AWS IAM Best Practices](https://docs.aws.amazon.com/IAM/latest/UserGuide/best-practices.html)

---

## Minor Pitfalls

Mistakes that cause annoyance but are fixable without major rework.

---

### Pitfall 11: Local by Flywheel SSL and Hostname Issues

**What goes wrong:** During development on Local by Flywheel, the site URL is something like `http://clemsonsportsmedia.local`. S3 CORS configuration rejects this origin. SSL certificate issues cause cURL errors when the plugin tries to communicate with AWS APIs. URLs stored during development contain `.local` domain.

**Prevention:**
- Add `*.local` to S3 CORS allowed origins during development (remove before production)
- Ensure Local by Flywheel's PHP has the CA bundle configured for HTTPS
- Use environment-aware URL rewriting: only rewrite in production, or use `wp_get_environment_type()` to toggle behavior
- Never hardcode the site URL in S3 paths -- always derive from `get_site_url()`

**Phase:** Phase 1 (Development Setup) -- configure dev environment correctly from the start.

---

### Pitfall 12: Image Edit (Crop/Rotate) Creates Orphaned S3 Files

**What goes wrong:** User crops an image in Media Library. WordPress creates a new file on disk. Plugin uploads the new file to S3. But the old cropped version on S3 is not deleted. Over time, S3 accumulates orphaned files that cost storage.

**Prevention:**
- Hook into `wp_delete_file` filter to also delete the corresponding S3 object
- Track all S3 keys associated with an attachment (not just current sizes) in postmeta
- Consider a periodic cleanup command in WP-CLI that compares S3 contents with database records

**Phase:** Phase 2 (Sync/Delete) -- handle alongside deletion sync.

---

### Pitfall 13: Ignoring `wp_get_attachment_image_attributes` and Related Filters

**What goes wrong:** Plugin rewrites the main `src` URL but misses other places WordPress outputs image URLs:
- `wp_get_attachment_image_attributes` (used by `wp_get_attachment_image()`)
- `wp_calculate_image_srcset` (responsive images)
- `wp_get_attachment_thumb_url` (deprecated but still used by some themes)
- `wp_prepare_attachment_for_js` (Media Library modal, Gutenberg media picker)
- `get_attached_file` (needed for image editing to find the file)

**Prevention:**
- Filter ALL of these hooks, not just `wp_get_attachment_url`
- Create a comprehensive list of WordPress attachment URL hooks and filter each one
- Special attention to `wp_prepare_attachment_for_js` -- without it, the Media Library modal shows broken thumbnails
- `get_attached_file` needs special handling: return the S3 URL for reads, but the local path for writes (image editing)

**Phase:** Phase 2 (URL Rewriting) -- systematically cover all URL output hooks.

---

### Pitfall 14: Not Handling `upload_dir` Filter for Programmatic Uploads

**What goes wrong:** Other plugins use `wp_upload_dir()` to determine where to save files. If your plugin alters the upload directory (e.g., to write directly to an S3 stream wrapper), you break plugins that expect a local filesystem path.

**Prevention:**
- Do NOT override `upload_dir` to point to S3 (the Human Made S3-Uploads approach)
- Instead, let WordPress upload locally as normal, then copy to S3 as a post-processing step
- This is simpler, more compatible, and avoids breaking other plugins

**Phase:** Phase 1 (Architecture Decision) -- choose the "copy-after-upload" pattern, not the "stream-wrapper" pattern.

---

### Pitfall 15: Multisite and Path Prefix Confusion

**What goes wrong:** On WordPress Multisite, uploads are stored in `wp-content/uploads/sites/{blog_id}/`. The plugin hardcodes a single path prefix, so all sites collide in the same S3 directory.

**Prevention:**
- Always use `wp_upload_dir()['basedir']` and `['baseurl']` to derive paths -- never hardcode
- Include the blog ID or site-specific prefix in the S3 key structure
- Test on single-site and multisite

**Phase:** Phase 1 if multisite support is needed; otherwise defer.

---

## Phase-Specific Warnings

| Phase Topic | Likely Pitfall | Mitigation | Severity |
|-------------|---------------|------------|----------|
| **Phase 1: Upload Pipeline** | Wrong hook timing (Pitfall 1) | Hook `wp_update_attachment_metadata`, upload all sizes | CRITICAL |
| **Phase 1: Upload Pipeline** | ACL failures on new buckets (Pitfall 5) | Use bucket policy, not ACLs | CRITICAL |
| **Phase 1: Foundation** | SDK autoloader conflicts (Pitfall 3) | Namespace-prefix or use minimal SDK | CRITICAL |
| **Phase 1: Settings** | Credentials in database (Pitfall 10) | wp-config.php constants first | HIGH |
| **Phase 2: URL Rewriting** | Serialized data corruption (Pitfall 2) | Filter at output layer, never raw DB replace | CRITICAL |
| **Phase 2: URL Rewriting** | Gutenberg URL gaps (Pitfall 6) | `the_content` filter + srcset filter | HIGH |
| **Phase 2: URL Rewriting** | Missing URL hooks (Pitfall 13) | Filter all attachment URL functions | MEDIUM |
| **Phase 2: CloudFront** | CORS blocking fonts (Pitfall 8) | S3 CORS + CloudFront origin header forwarding | HIGH |
| **Phase 2: CloudFront** | Cache invalidation costs (Pitfall 7) | Object versioning, not invalidation | MEDIUM |
| **Phase 3: Migration** | Silent failures (Pitfall 9) | Batched, resumable, per-file error logging | HIGH |
| **Phase 3: Local Delete** | Race condition with thumbnails (Pitfall 4) | Deferred deletion, "copy back" support | CRITICAL |
| **Ongoing** | Orphaned S3 files (Pitfall 12) | Hook `wp_delete_file`, track all S3 keys | LOW |

---

## Architecture Decision: Copy-After-Upload vs Stream Wrapper

This is the single most consequential architecture decision. Two approaches exist:

**Stream Wrapper (Human Made S3-Uploads approach):**
- Registers `s3://` as a PHP stream wrapper
- `upload_dir` filter points to S3 directly
- Files never touch local disk
- PRO: Simple, no local storage needed
- CON: Breaks every plugin that expects local file paths, breaks image editing, breaks thumbnail regeneration, breaks image optimization plugins

**Copy-After-Upload (WP Offload Media approach):**
- WordPress uploads to local disk as normal
- Plugin copies to S3 after all processing is complete
- Optionally removes local copy later
- PRO: Maximum compatibility, works with all plugins
- CON: Requires local disk space during upload, more complex lifecycle management

**Recommendation for ct-s3-offloader:** Use the **Copy-After-Upload** pattern. It is dramatically more compatible and avoids the majority of pitfalls listed above. The stream wrapper approach is clever but fragile and creates an ongoing maintenance burden from plugin compatibility issues.

---

## Sources

- [WP Offload Media Plugin](https://wordpress.org/plugins/amazon-s3-and-cloudfront/)
- [WP Offload Media Developer Guide](https://deliciousbrains.com/wp-offload-media/doc/developer-guide/)
- [WP Offload Media Compatibility Docs](https://deliciousbrains.com/wp-offload-media/doc/compatibility-with-other-plugins/)
- [Human Made S3-Uploads](https://github.com/humanmade/S3-Uploads)
- [WordPress Core: wp_update_attachment_metadata misuse](https://make.wordpress.org/core/2019/11/05/use-of-the-wp_update_attachment_metadata-filter-as-upload-is-complete-hook/)
- [The 67MB Problem](https://dev.to/dmitryrechkin/the-67mb-problem-building-a-lightweight-wordpress-media-offload-alternative-4cbh)
- [AWS SDK autoloader conflicts](https://github.com/awslabs/aws-for-wordpress/issues/38)
- [CloudFront Cache Invalidation vs Versioning](https://deliciousbrains.com/wp-offload-media/doc/object-versioning-instead-of-cache-invalidation/)
- [S3 CORS + CloudFront HEAD requirement](https://bibwild.wordpress.com/2023/10/09/s3-cors-headers-proxied-by-cloudfront-require-head-not-just-get/)
- [AWS Best Practices for WordPress](https://docs.aws.amazon.com/whitepapers/latest/best-practices-wordpress/plugin-installation-and-configuration.html)
