# Project Research Summary

**Project:** ct-s3-offloader
**Domain:** WordPress Plugin — Cloud Media Offloading (S3 / CloudFront)
**Researched:** 2026-02-27
**Confidence:** HIGH

## Executive Summary

The ct-s3-offloader plugin is a well-understood class of WordPress plugin with multiple mature competitors to study. The correct architectural approach is the **copy-after-upload interceptor pattern**: WordPress handles uploads locally as normal, and the plugin mirrors files to S3 as a side effect after all thumbnail sizes are generated. This approach — used by WP Offload Media — is dramatically more compatible with other plugins than the stream-wrapper approach used by Human Made's S3-Uploads. The single most consequential technical decision is which WordPress hook to use as the upload trigger: `wp_generate_attachment_metadata` (fires once, after all thumbnails exist) is correct; `wp_update_attachment_metadata` (fires multiple times per upload since WordPress 5.3) is not.

The competitive opportunity is clear: WP Offload Media Lite locks bulk migration behind a $99+/year paywall, yet bulk migration is the first thing any new user with an existing media library needs. Making bulk WP-CLI migration free and dependency-free (bundled AWS SDK via phar, no Composer) covers the two most common friction points in the plugin category. The target feature set — auto-upload, URL rewriting, CloudFront CDN, deletion sync, bulk migration, and Media Library status badges — matches WP Offload Media Pro's core capabilities at zero cost, with the intentional tradeoff of supporting only S3/CloudFront rather than 3-6 cloud providers.

The highest-priority risks are: (1) the wrong upload hook causing missing thumbnails; (2) AWS SDK namespace conflicts with other plugins that bundle their own AWS SDK; (3) the 2023 AWS change disabling ACLs on new S3 buckets by default, which breaks every older code example that uses `'ACL' => 'public-read'`; and (4) URL rewriting via raw database search-and-replace corrupting PHP serialized data. All four risks are well-documented with known prevention strategies and must be addressed from day one.

---

## Key Findings

### Recommended Stack

The plugin requires PHP 8.1+ (AWS SDK v3 floor requirement) and WordPress 6.4+. The AWS SDK should be bundled as a **phar file** (`vendor/aws.phar`), not installed via Composer, so the plugin can be installed through the WordPress admin upload workflow without CLI access. The phar distribution is the official AWS single-file distribution, includes all dependencies (Guzzle, PSR interfaces), and has an identical API to the Composer install. S3 uploads should use the `ObjectUploader` class, which auto-selects single-request or multipart upload based on file size — the right default for WordPress media that could include large video files. The settings UI should use the WordPress Settings API (plain PHP), not React + REST API, which would add a build tooling dependency for zero UX benefit on a simple configuration form.

**Core technologies:**
- **PHP 8.1+** — Plugin runtime; AWS SDK v3 floor requirement; most hosts run 8.1+ now
- **WordPress 6.4+** — Host CMS; target stable hook API across 6.x line
- **AWS SDK for PHP v3.371.x (phar)** — S3 and CloudFront API; single-file distribution, no Composer
- **WP-CLI 2.10+** — Bulk migration commands; ships with Local by Flywheel and all dev environments
- **WordPress Settings API (PHP)** — Admin settings page; no build step, works on all hosts

**Critical version note:** AWS SDK v3 dropped support for PHP 8.0 and below. The plugin header must declare `Requires PHP: 8.1`.

**What NOT to use:** Flysystem (unnecessary abstraction), AWS SDK v2 (EOL), Composer in production (breaks WP plugin upload), React for settings (overkill), custom database tables (post meta handles tracking fine), `wp_remote_*` functions for S3 calls (SDK handles SigV4 auth, retries, and multipart).

### Expected Features

The plugin's MVP is defined by 10 table-stakes features users expect from any S3 offloader, plus 3 high-value differentiators that require little extra effort. WP Offload Media Lite is the dominant free competitor — its paywall on bulk migration is the primary market gap.

**Must have (table stakes):**
- Auto-upload on media add — core purpose; every competitor has this
- URL rewriting to S3/CDN — without it, uploads go to S3 but pages serve local URLs
- CloudFront CDN support — users expect a CDN URL field, not raw S3 bucket URLs
- Deletion sync — orphaned S3 files accumulate cost; all competitors handle this
- Settings page in WP Admin — configure bucket, region, credentials without touching code
- Bulk migration of existing media — any site with 1000+ existing files needs this on day one
- Thumbnail/image size handling — all generated sizes must go to S3, all URLs must rewrite
- wp-config.php credential storage — DB-stored AWS keys are a security anti-pattern
- Connection test / status indicator — users must verify credentials before uploading
- Error handling and logging — failed uploads must keep local copy, log error, not lose files

**Should have (differentiators):**
- WP-CLI migration command (D1) — migrate 1000+ files without browser timeouts; WP Offload Media doesn't have this even in Pro
- No Composer dependency (D2) — works on shared hosting; removes biggest deployment friction
- Visual offload status in Media Library (D3) — cloud icon badge showing S3 vs local; high perceived quality
- Status filter in Media Library (D4) — dropdown to find failed/local-only files
- Option to keep or remove local files after upload (D7) — meaningful for disk-constrained hosts

**Defer to v2+:**
- Multi-provider support (DigitalOcean Spaces, Cloudflare R2, GCS, Wasabi) — doubles testing surface, out of scope
- CSS/JS/font asset offloading — completely different problem domain
- Image compression / WebP conversion — dedicated plugins do this better, bundling causes conflicts
- WooCommerce/EDD integration — separate security domain (signed URLs), build as add-on if needed
- Action Scheduler background queue — complexity without benefit; WP-CLI replaces this
- WordPress Multisite (Network) support — per-site bucket config, blog-switching complexity

### Architecture Approach

The plugin follows a **hook-driven interceptor pattern** across three pipelines: upload (hook `wp_generate_attachment_metadata` to push to S3 after all thumbnails are created), URL serving (filter `wp_get_attachment_url` and `the_content` to rewrite local URLs to CDN URLs at render time), and deletion (hook `delete_attachment` action — before WordPress removes local metadata — to sync deletions to S3). State is tracked via attachment post meta (`_ct_s3_offloaded`, `_ct_s3_key`, `_ct_s3_bucket`, `_ct_s3_offloaded_at`), which integrates with WordPress export/import, requires no schema migrations, and provides per-attachment queryable status. URLs are never stored in the database; only local paths are stored, and CDN URLs are constructed at render time so the plugin can be deactivated instantly with no broken URL aftermath.

**Major components:**
1. **Plugin Bootstrap** (`ct-s3-offloader.php`) — Loads all components, checks PHP/WP version, defines constants; initializes on `plugins_loaded`
2. **Settings Manager** (`class-ct-s3-settings.php`) — Admin page via WordPress Settings API; single serialized option row; credentials sourced from wp-config.php constants with DB fallback
3. **S3 Client Wrapper** (`class-ct-s3-client.php`) — Sole component touching AWS SDK; provides upload, delete, exists, get_url methods; enables mock testing
4. **Upload Handler** (`class-ct-s3-upload-handler.php`) — Hooks `wp_generate_attachment_metadata`; uploads original + all thumbnail sizes; marks attachment as offloaded
5. **URL Rewriter** (`class-ct-s3-url-rewriter.php`) — Two-layer: `wp_get_attachment_url` filter for programmatic calls + `the_content` filter (priority 99) for hardcoded URLs in post HTML
6. **Deletion Handler** (`class-ct-s3-deletion-handler.php`) — Hooks `delete_attachment` action; deletes original + all sizes from S3 before WordPress removes metadata
7. **Tracker** (`class-ct-s3-tracker.php`) — Read/write attachment post meta; query un-offloaded attachments for bulk operations
8. **WP-CLI Commands** (`class-ct-s3-cli.php`) — `wp ct-s3 offload`, `verify`, `sync`, `status`; batched with progress bar, `--dry-run`, `--resume`

### Critical Pitfalls

1. **Wrong upload hook (P1 — CRITICAL)** — Using `wp_update_attachment_metadata` as the upload trigger causes duplicate S3 uploads (fires multiple times since WP 5.3). Use `wp_generate_attachment_metadata` instead. Upload all sizes from `$metadata['sizes']` plus the original `$metadata['file']`.

2. **AWS ACL disabled on new S3 buckets (P5 — CRITICAL)** — AWS disabled ACLs by default on all new S3 buckets after April 2023. Code using `'ACL' => 'public-read'` throws `AccessControlListNotSupported`. Use a Bucket Policy for public access, or CloudFront Origin Access Control (OAC) to keep the bucket private and serve via CDN only. Never hardcode ACL in PutObject calls.

3. **AWS SDK namespace conflicts (P3 — CRITICAL)** — Other plugins (UpdraftPlus, WP Mail SMTP, backup plugins) bundle their own AWS SDK version. PHP autoloaders collide; whichever plugin loads first wins. Mitigation: namespace-prefix the SDK using PHP-Scoper, or check `class_exists('Aws\Sdk')` before loading the autoloader. Test against common AWS-bundling plugins before release.

4. **Serialized data corruption during URL rewriting (P2 — CRITICAL)** — Never do raw `str_replace` on the database to swap local URLs for CDN URLs. PHP serialized strings include byte counts; changing URL length without updating counts corrupts `wp_options`, widget data, and plugin settings. Always filter at the output layer (WordPress filter hooks), never touch database strings directly.

5. **Race condition when deleting local files (P4 — CRITICAL)** — Do not delete local files immediately after S3 upload. Image optimization plugins (ShortPixel, Imagify), Regenerate Thumbnails, and WordPress image editing (crop/rotate) all need the local file. Implement local deletion as a separate, explicitly opt-in feature with a deferred queue, not inline after upload.

**Additional high-priority pitfalls:**
- **Gutenberg URL gaps (P6 — HIGH):** Gutenberg image blocks store absolute URLs in `post_content` HTML. `wp_get_attachment_url` filter alone does not cover these. Requires a `the_content` filter that does string replacement on upload URLs.
- **CORS blocking fonts (P8 — HIGH):** S3 CORS must allow the WordPress origin and include `HEAD` in `AllowedMethods`. CloudFront must be configured to forward the `Origin` header. Configure during initial CloudFront setup.
- **Credentials in database (P10 — HIGH):** AWS secret keys stored in `wp_options` appear in database backups and staging copies. Primary credential method must be `wp-config.php` constants, not the settings page form.
- **Silent WP-CLI failures (P9 — HIGH):** Bulk migration must track per-attachment success in postmeta, batch by offset/limit, flush cache between batches, and support `--dry-run` and resumable runs.

---

## Implications for Roadmap

The dependency chain from FEATURES.md and the build order from ARCHITECTURE.md are tightly aligned. Settings and tracking (pure WordPress, no AWS) must come before any S3 interaction. URL rewriting depends on having something to rewrite (uploads). Bulk migration orchestrates everything. UI polish comes last.

### Phase 1: Foundation and Settings

**Rationale:** No S3 code can run without credentials and configuration. Settings and the Tracker are pure WordPress — buildable and testable before any AWS account is needed. Getting credential management right here (wp-config.php constants as primary, DB as fallback) avoids the security pitfall from day one.

**Delivers:** Working plugin skeleton; settings page with credential management; connection test button; attachment tracking infrastructure

**Features addressed:** T5 (settings page), T8 (wp-config.php constants), T9 (connection test)

**Pitfalls avoided:** P10 (credentials in DB), P14 (stream wrapper anti-pattern — architectural decision made here)

**Research flag:** Standard patterns. WordPress Settings API is well-documented.

### Phase 2: Core S3 Upload Pipeline

**Rationale:** This is the heart of the plugin. The upload hook choice (`wp_generate_attachment_metadata`) and the S3 Client wrapper are the most consequential technical decisions. Must be correct before anything else builds on it. ACL handling must be correct for new S3 buckets from day one.

**Delivers:** Automatic S3 upload on media add; all image sizes uploaded; attachments marked as offloaded in postmeta; error handling that keeps local copy on failure

**Features addressed:** T1 (auto-upload), T7 (thumbnail handling), T10 (error handling)

**Pitfalls avoided:** P1 (wrong hook), P3 (SDK autoloader conflicts — namespace strategy decided here), P4 (local file deletion deferred), P5 (no ACLs, bucket policy instead)

**Research flag:** Needs careful testing. The hook timing behavior changed in WP 5.3 and must be validated in a real WordPress environment.

### Phase 3: URL Rewriting and CloudFront

**Rationale:** S3 upload without URL rewriting is invisible to users (pages still serve local URLs). URL rewriting is the feature that makes the plugin work end-to-end. Two-layer approach (attachment filter + content filter) covers both programmatic and hardcoded Gutenberg URLs.

**Delivers:** All attachment URLs rewritten to CloudFront CDN domain; srcset attributes rewritten for responsive images; Gutenberg block image URLs rewritten via content filter; plugin fully functional for new uploads

**Features addressed:** T2 (URL rewriting), T3 (CloudFront CDN support)

**Pitfalls avoided:** P2 (output-layer filtering only, no DB modification), P6 (Gutenberg content filter added), P7 (object versioning over cache invalidation), P8 (CORS configured), P13 (all URL hooks covered including srcset and wp_prepare_attachment_for_js)

**Research flag:** Standard patterns, but requires comprehensive testing of all URL output hooks. Gutenberg URL storage behavior needs verification against current WordPress version.

### Phase 4: Deletion Sync

**Rationale:** Without deletion sync, S3 accumulates orphaned files every time a media item is deleted. Must hook `delete_attachment` action (not later) because attachment metadata is still available at that point. Build after URL rewriting so deletion behavior can be validated against a fully working upload/serve cycle.

**Delivers:** S3 objects (original + all sizes) deleted when media item deleted from WordPress; clean per-attachment tracker cleanup

**Features addressed:** T4 (deletion sync)

**Pitfalls avoided:** P12 (hook timing correct — before metadata is removed)

**Research flag:** Standard patterns. Well-documented WordPress hook behavior.

### Phase 5: Bulk Migration (WP-CLI)

**Rationale:** The primary competitive differentiator. Any existing site (including this one with 1000+ files) needs this before the plugin is useful to them. Builds on the Upload Handler and Tracker from Phase 2 — the CLI command is essentially a wrapper that iterates existing attachments and calls the same upload logic. WP-CLI avoids the browser timeout problem that makes a background queue necessary.

**Delivers:** `wp ct-s3 offload` command with batching, progress bar, dry-run mode, resume support, and per-file error logging; `wp ct-s3 status`, `verify`, and `sync` commands

**Features addressed:** T6 (bulk migration), D1 (WP-CLI command), D9 (progress indicator), D10 (batch size control)

**Pitfalls avoided:** P9 (batched, per-file tracking, resumable, per-file error handling)

**Research flag:** Standard WP-CLI patterns. Well-documented. Main risk is memory management for large libraries — test with 500+ attachments.

### Phase 6: Admin UI Polish

**Rationale:** These features are low-effort, high-perceived-quality improvements that round out the plugin. Visual status badges in the Media Library and local file removal complete the feature parity with WP Offload Media Pro. Build last because they depend on solid metadata from Phase 2 and require no architectural changes.

**Delivers:** Cloud icon badge in Media Library showing offloaded/local/failed status; filter dropdown for offload status; optional local file removal after confirmed S3 success; custom S3 path prefix option; year/month folder preservation option; uninstall.php cleanup

**Features addressed:** D3 (visual status), D4 (status filter), D7 (remove local files), D5 (custom path prefix), D6 (year/month folder option)

**Pitfalls avoided:** P4 (local deletion is opt-in, confirmed-S3-success-only, deferred — not inline)

**Research flag:** Standard patterns. Risk area is local file removal — must verify S3 success before any deletion.

### Phase Ordering Rationale

- **Settings before S3:** No AWS call can succeed without credentials. The Settings Manager is a WordPress-only component that can be built and tested in isolation.
- **Upload before URL rewriting:** You cannot rewrite URLs for attachments that have not been tracked as offloaded. The Tracker's `is_offloaded()` check gates all URL rewriting.
- **Upload and URL rewriting before deletion:** The deletion handler reads the same metadata structure built by the upload handler. Testing deletion requires a complete upload-to-serve cycle.
- **Core functionality before CLI:** The WP-CLI migration command is a wrapper around Phase 2's upload logic. Building Phase 5 after Phase 2 means the CLI reuses tested code rather than duplicating it.
- **Everything before polish:** Phase 6 is UX surface built on solid data from all earlier phases.

---

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH | Official AWS docs confirm phar approach, SDK version, PHP 8.1 floor; WordPress core docs confirm all hooks |
| Features | HIGH | Well-established plugin category; 5+ mature competitors documented; competitive table verified against plugin pages |
| Architecture | HIGH | Hook behavior verified against WordPress core source code and official 2019 dev note; patterns verified against Human Made and Delicious Brains implementations |
| Pitfalls | HIGH | Pitfalls sourced from real issue trackers (GitHub), official AWS policy changes, and WordPress core change notes; not speculative |

**Overall confidence:** HIGH

The domain is mature and well-documented. The main risks are not knowledge gaps but implementation discipline — the correct patterns are known, they just require discipline to execute (especially the hook timing and credential storage choices).

### Gaps to Address

- **SDK namespace conflict strategy needs a decision before Phase 2 begins.** PHP-Scoper adds build complexity; `class_exists()` checking is simpler but less robust. The team must choose before writing the bootstrap file, since this decision affects the entire vendor directory structure.

- **ACL vs Bucket Policy vs CloudFront OAC:** The plugin should guide users through the AWS console setup. Research does not cover the UX of how to document this for non-technical users. Consider providing a setup guide or a settings-page link to documentation.

- **phar size (~80MB) may be a concern for plugin distribution via WordPress.org.** The plugin directory has a 10MB zip upload limit. If submitting to WordPress.org, the phar approach requires an alternative distribution strategy (download phar on activation, or use a lighter-weight S3 SDK). This is not a problem for self-hosted use. Validate distribution channel before Phase 2.

- **Headless/REST API URL rewriting** is a gap in Phase 3. The project is on a headless WordPress setup (Next.js frontend per CLAUDE.md). REST API responses returning attachment URLs may need an additional filter on REST API output. This was noted in ARCHITECTURE.md as a Phase 2+ consideration.

---

## Sources

### Primary (HIGH confidence)
- [AWS SDK for PHP Installation Guide](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/getting-started_installation.html) — phar bundling approach, SDK version
- [AWS SDK for PHP GitHub Releases](https://github.com/aws/aws-sdk-php/releases) — version 3.371.3 confirmed current
- [WordPress Core: wp_update_attachment_metadata misuse](https://make.wordpress.org/core/2019/11/05/use-of-the-wp_update_attachment_metadata-filter-as-upload-is-complete-hook/) — hook timing behavior since WP 5.3
- [WordPress Developer Reference: wp_generate_attachment_metadata](https://developer.wordpress.org/reference/hooks/wp_generate_attachment_metadata/) — correct upload hook
- [AWS S3 Object Ownership docs](https://docs.aws.amazon.com/AmazonS3/latest/userguide/about-object-ownership.html) — ACL disabled by default on new buckets
- [AWS Best Practices for WordPress](https://docs.aws.amazon.com/whitepapers/latest/best-practices-wordpress/plugin-installation-and-configuration.html) — credential management, IAM best practices
- [WordPress Plugin Best Practices](https://developer.wordpress.org/plugins/plugin-basics/best-practices/) — singleton, hooks, options API
- [WP-CLI Commands Cookbook](https://make.wordpress.org/cli/handbook/guides/commands-cookbook/) — WP-CLI command structure
- WordPress Core source code (`wp-includes/post.php`, `wp-includes/media.php`) — upload pipeline verified directly

### Secondary (MEDIUM confidence)
- [WP Offload Media Lite (WordPress.org)](https://wordpress.org/plugins/amazon-s3-and-cloudfront/) — competitive features, issue tracker for real-world pitfalls
- [Advanced Media Offloader (WordPress.org)](https://wordpress.org/plugins/advanced-media-offloader/) — WP-CLI patterns, hook structure
- [Human Made S3-Uploads (GitHub)](https://github.com/humanmade/S3-Uploads) — stream wrapper approach (reference for what NOT to do)
- [Delicious Brains: CloudFront object versioning](https://deliciousbrains.com/wp-offload-media/doc/object-versioning-instead-of-cache-invalidation/) — cache invalidation cost avoidance
- [Delicious Brains: Font CORS with S3/CloudFront](https://deliciousbrains.com/wp-offload-media/doc/font-cors/) — CORS configuration details
- [The 67MB Problem (dev.to)](https://dev.to/dmitryrechkin/the-67mb-problem-building-a-lightweight-wordpress-media-offload-alternative-4cbh) — SDK conflict analysis, lightweight alternative options
- [S3 CORS HEAD requirement for CloudFront](https://bibwild.wordpress.com/2023/10/09/s3-cors-headers-proxied-by-cloudfront-require-head-not-just-get/) — CORS subtle requirement

### Tertiary (LOW confidence)
- WP Mayor plugin comparison article — feature comparison (may not be current)
- ThemeDev plugin comparison articles — competitive positioning (commercial site)

---
*Research completed: 2026-02-27*
*Ready for roadmap: yes*
