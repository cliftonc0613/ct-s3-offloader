# Requirements: CT S3 Media Offloader

**Defined:** 2026-02-27
**Core Value:** Media files upload to S3 and serve from CloudFront transparently

## v1 Requirements

Requirements for initial release. Each maps to roadmap phases.

### Foundation

- [ ] **FOUND-01**: Plugin activates/deactivates cleanly with proper WordPress hooks
- [ ] **FOUND-02**: AWS SDK bundled without Composer (PHAR or extracted zip with autoloader)
- [ ] **FOUND-03**: Plugin autoloader loads classes on demand without manual requires
- [ ] **FOUND-04**: AWS credentials read from wp-config.php constants (not stored in database)
- [ ] **FOUND-05**: Settings page with fields for S3 bucket, region, CloudFront domain, and S3 path prefix
- [ ] **FOUND-06**: Connection test button on settings page validates S3 credentials and bucket access
- [ ] **FOUND-07**: Settings validation rejects invalid bucket names, regions, and empty credentials

### Upload

- [ ] **UPLD-01**: New media uploads automatically copied to S3 after WordPress saves locally
- [ ] **UPLD-02**: All generated thumbnail sizes uploaded to S3 alongside the original
- [ ] **UPLD-03**: S3 object key stored in attachment postmeta for tracking
- [ ] **UPLD-04**: Upload uses correct hook (`wp_generate_attachment_metadata`) to avoid duplicate uploads
- [ ] **UPLD-05**: Failed S3 uploads logged with error details and do not break WordPress media flow
- [ ] **UPLD-06**: Proper Content-Type headers set on S3 objects based on file MIME type
- [ ] **UPLD-07**: S3 uploads use ObjectUploader for automatic single/multipart selection

### URL Rewriting

- [ ] **URL-01**: `wp_get_attachment_url` filter rewrites media URLs to CloudFront domain
- [ ] **URL-02**: Post/page content filtered to replace local media URLs with CloudFront URLs
- [ ] **URL-03**: Responsive image srcset URLs rewritten to CloudFront
- [ ] **URL-04**: Gutenberg block content URLs rewritten to CloudFront
- [ ] **URL-05**: REST API attachment responses include CloudFront URLs for headless frontend
- [ ] **URL-06**: URL rewriting is runtime-only — local URLs remain in database as fallback
- [ ] **URL-07**: Deactivating the plugin restores all URLs to local paths automatically

### Deletion

- [ ] **DEL-01**: Deleting media from WordPress also deletes the original file from S3
- [ ] **DEL-02**: All thumbnail sizes for deleted media removed from S3
- [ ] **DEL-03**: Deletion hooks fire before WordPress removes metadata so S3 keys can be read
- [ ] **DEL-04**: Failed S3 deletions logged but do not block WordPress deletion

### Migration

- [ ] **MIG-01**: WP-CLI command uploads all existing media library files to S3
- [ ] **MIG-02**: Migration processes files in configurable batch sizes to manage memory
- [ ] **MIG-03**: Progress bar displays during migration with file count and percentage
- [ ] **MIG-04**: Migration can resume from where it stopped on failure (per-file tracking)
- [ ] **MIG-05**: Dry-run mode shows what would be uploaded without making changes
- [ ] **MIG-06**: Migration skips files already uploaded to S3 (idempotent)
- [ ] **MIG-07**: Memory cleanup between batches prevents exhaustion on 1000+ file libraries
- [ ] **MIG-08**: Migration summary report shows success/failure/skipped counts

### Admin UI

- [ ] **UI-01**: Settings page accessible from WordPress admin menu
- [ ] **UI-02**: Media Library list view shows S3 status indicator per file (local/S3/error)
- [ ] **UI-03**: Storage statistics on settings page (files on S3, total size, last sync)
- [ ] **UI-04**: S3 path prefix configurable (e.g., `wp-content/uploads/` or custom path)
- [ ] **UI-05**: Admin notices for configuration issues (missing credentials, failed connection)

### CloudFront

- [ ] **CDN-01**: S3 objects served via CloudFront distribution URL
- [ ] **CDN-02**: Proper cache-control headers set on S3 objects for CloudFront caching
- [ ] **CDN-03**: CORS headers configured for cross-origin media requests
- [ ] **CDN-04**: CloudFront Origin Access Control (OAC) supported (no public bucket ACLs)

### Security

- [ ] **SEC-01**: AWS credentials stored as wp-config.php constants, never in wp_options
- [ ] **SEC-02**: Settings page protected with `manage_options` capability check
- [ ] **SEC-03**: All settings form submissions verified with WordPress nonces
- [ ] **SEC-04**: Plugin data cleaned up on uninstall (postmeta, options, transients)

## v2 Requirements

Deferred to future release. Tracked but not in current roadmap.

### Extended Compatibility

- **V2-01**: S3-compatible endpoint support (Cloudflare R2, Wasabi, MinIO)
- **V2-02**: Browser-based bulk migration for non-technical admins (no WP-CLI)
- **V2-03**: Background/async S3 uploads via Action Scheduler
- **V2-04**: CloudFront cache invalidation from admin UI

### Advanced Features

- **V2-05**: Local file cleanup after successful S3 migration (free disk space)
- **V2-06**: Per-attachment S3 detail view in Media Library
- **V2-07**: Rollback command to copy S3 files back to local storage

## Out of Scope

| Feature | Reason |
|---------|--------|
| Multi-provider support (GCS, Azure) | Adds complexity without value for S3-focused plugin |
| Image optimization/compression | Separate concern, use dedicated plugins |
| CSS/JS asset offloading | Out of scope, media files only |
| Video transcoding | Upload as-is, transcoding is a different service |
| WooCommerce integration | Not needed for media-focused sports site |
| WordPress Multisite support | Single-site focus for v1 |
| WordPress.org public release | Reusable but private distribution only |
| Signed/private URLs | All media is public content |
| Background processing queues | Synchronous upload is acceptable for v1 |

## Traceability

Which phases cover which requirements. Updated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| FOUND-01 | Phase 1 | Complete |
| FOUND-02 | Phase 1 | Complete |
| FOUND-03 | Phase 1 | Complete |
| FOUND-04 | Phase 1 | Complete |
| FOUND-05 | Phase 1 | Complete |
| FOUND-06 | Phase 1 | Complete |
| FOUND-07 | Phase 1 | Complete |
| UPLD-01 | Phase 2 | Pending |
| UPLD-02 | Phase 2 | Pending |
| UPLD-03 | Phase 2 | Pending |
| UPLD-04 | Phase 2 | Pending |
| UPLD-05 | Phase 2 | Pending |
| UPLD-06 | Phase 2 | Pending |
| UPLD-07 | Phase 2 | Pending |
| URL-01 | Phase 3 | Pending |
| URL-02 | Phase 3 | Pending |
| URL-03 | Phase 3 | Pending |
| URL-04 | Phase 3 | Pending |
| URL-05 | Phase 3 | Pending |
| URL-06 | Phase 3 | Pending |
| URL-07 | Phase 3 | Pending |
| DEL-01 | Phase 4 | Pending |
| DEL-02 | Phase 4 | Pending |
| DEL-03 | Phase 4 | Pending |
| DEL-04 | Phase 4 | Pending |
| MIG-01 | Phase 5 | Pending |
| MIG-02 | Phase 5 | Pending |
| MIG-03 | Phase 5 | Pending |
| MIG-04 | Phase 5 | Pending |
| MIG-05 | Phase 5 | Pending |
| MIG-06 | Phase 5 | Pending |
| MIG-07 | Phase 5 | Pending |
| MIG-08 | Phase 5 | Pending |
| UI-01 | Phase 1 | Complete |
| UI-02 | Phase 6 | Pending |
| UI-03 | Phase 6 | Pending |
| UI-04 | Phase 6 | Pending |
| UI-05 | Phase 6 | Pending |
| CDN-01 | Phase 3 | Pending |
| CDN-02 | Phase 2 | Pending |
| CDN-03 | Phase 3 | Pending |
| CDN-04 | Phase 2 | Pending |
| SEC-01 | Phase 1 | Complete |
| SEC-02 | Phase 1 | Complete |
| SEC-03 | Phase 1 | Complete |
| SEC-04 | Phase 6 | Pending |

**Coverage:**
- v1 requirements: 46 total
- Mapped to phases: 46
- Unmapped: 0

---
*Requirements defined: 2026-02-27*
*Last updated: 2026-02-27 after Phase 1 completion*
