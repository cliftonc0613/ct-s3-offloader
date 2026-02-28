# Roadmap: CT S3 Media Offloader

## Overview

This roadmap delivers a WordPress plugin that transparently offloads media uploads to S3 and serves them via CloudFront CDN. The six phases follow a strict dependency chain: settings and credentials before any AWS interaction, upload pipeline before URL rewriting, URL rewriting before deletion sync, core functionality before bulk migration, and everything before UI polish. AWS infrastructure (Phase 1 of the original build guide) is already complete — this roadmap covers plugin code only.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [x] **Phase 1: Foundation and Settings** - Plugin skeleton, AWS SDK integration, settings page, and credential management
- [x] **Phase 2: S3 Upload Pipeline** - Automatic upload of media files and thumbnails to S3 on WordPress upload
- [x] **Phase 3: URL Rewriting and CloudFront** - Runtime URL replacement from local paths to CloudFront CDN URLs
- [x] **Phase 4: Deletion Sync** - S3 object cleanup when media is deleted from WordPress
- [x] **Phase 5: Bulk Migration** - WP-CLI commands for migrating existing media libraries to S3
- [ ] **Phase 6: Admin UI and Finalization** - Media Library status indicators, storage stats, and uninstall cleanup

## Phase Details

### Phase 1: Foundation and Settings
**Goal**: Admin can install the plugin, configure S3/CloudFront settings, and verify AWS connectivity from the WordPress dashboard
**Depends on**: Nothing (first phase; AWS infrastructure already exists)
**Requirements**: FOUND-01, FOUND-02, FOUND-03, FOUND-04, FOUND-05, FOUND-06, FOUND-07, SEC-01, SEC-02, SEC-03, UI-01
**Success Criteria** (what must be TRUE):
  1. Plugin activates cleanly in WordPress and appears in the admin menu with a settings page
  2. Admin can enter S3 bucket, region, CloudFront domain, and path prefix on the settings page and save them
  3. Connection test button on settings page reports success or a specific error message when clicked
  4. AWS credentials are read from wp-config.php constants and never stored in the database
  5. Settings page rejects invalid input (empty credentials, malformed bucket names) with clear validation errors
**Plans**: 2 plans

Plans:
- [x] 01-01-PLAN.md — Plugin bootstrap, autoloader, AWS SDK bundling, and S3 client wrapper
- [x] 01-02-PLAN.md — Settings page, credential display, connection test, and input validation

### Phase 2: S3 Upload Pipeline
**Goal**: New media uploads automatically appear on S3 with all thumbnail sizes, correct MIME types, and proper cache headers
**Depends on**: Phase 1
**Requirements**: UPLD-01, UPLD-02, UPLD-03, UPLD-04, UPLD-05, UPLD-06, UPLD-07, CDN-02, CDN-04
**Success Criteria** (what must be TRUE):
  1. Uploading an image through WordPress Media Library places the original and all generated thumbnail sizes on S3
  2. Each uploaded S3 object has correct Content-Type and Cache-Control headers
  3. Failed S3 uploads are logged with error details and the local file remains intact in WordPress
  4. Attachment postmeta tracks S3 offload status, key, and timestamp for each uploaded file
  5. S3 objects are accessible through CloudFront OAC without public bucket ACLs
**Plans**: 2 plans

Plans:
- [x] 02-01-PLAN.md — S3 client upload/delete methods and attachment offload tracker
- [x] 02-02-PLAN.md — Upload handler with WordPress hook integration and bootstrap modification

### Phase 3: URL Rewriting and CloudFront
**Goal**: All media URLs across the site resolve to CloudFront CDN paths at render time, with zero database modifications
**Depends on**: Phase 2
**Requirements**: URL-01, URL-02, URL-03, URL-04, URL-05, URL-06, URL-07, CDN-01, CDN-03
**Success Criteria** (what must be TRUE):
  1. Front-end page renders show CloudFront URLs for all offloaded media (images, srcset, Gutenberg blocks)
  2. REST API attachment endpoints return CloudFront URLs for the headless Next.js frontend
  3. Original local URLs remain stored in the database and are never modified
  4. Deactivating the plugin immediately restores all URLs to local paths with no broken images
  5. CORS headers are properly configured so cross-origin media requests (fonts, images) work without errors
**Plans**: 2 plans

Plans:
- [x] 03-01-PLAN.md — S3MO_URL_Rewriter class with attachment URL and content filters, bootstrap wiring
- [x] 03-02-PLAN.md — Srcset, REST API, admin modal rewriting, and CORS headers

### Phase 4: Deletion Sync
**Goal**: Deleting media from WordPress removes all corresponding files from S3 without leaving orphaned objects
**Depends on**: Phase 3
**Requirements**: DEL-01, DEL-02, DEL-03, DEL-04
**Success Criteria** (what must be TRUE):
  1. Deleting a media item from the Media Library removes the original file and all thumbnail sizes from S3
  2. S3 deletion fires before WordPress removes attachment metadata so the S3 key is still accessible
  3. Failed S3 deletions are logged but do not prevent WordPress from completing the media deletion
**Plans**: 1 plan

Plans:
- [x] 04-01-PLAN.md — Delete handler class with S3 cleanup, error logging, and bootstrap wiring

### Phase 5: Bulk Migration
**Goal**: Site owner can migrate an existing 1000+ file media library to S3 via WP-CLI with progress tracking and fault tolerance
**Depends on**: Phase 2 (reuses upload handler)
**Requirements**: MIG-01, MIG-02, MIG-03, MIG-04, MIG-05, MIG-06, MIG-07, MIG-08
**Success Criteria** (what must be TRUE):
  1. Running `wp ct-s3 offload` uploads all un-offloaded media library files to S3 with a visible progress bar
  2. Migration processes files in configurable batches with memory cleanup between batches (handles 1000+ files)
  3. Running `wp ct-s3 offload --dry-run` shows what would be uploaded without making any changes
  4. Migration resumes from where it stopped after a failure without re-uploading already-offloaded files
  5. Completion summary reports counts for successful, failed, and skipped files
**Plans**: 2 plans

Plans:
- [x] 05-01-PLAN.md — Bulk migrator engine and WP-CLI offload command with batching, retry, dry-run, and progress output
- [x] 05-02-PLAN.md — Status and reset subcommands with summary counts, verbose tables, and metadata cleanup

### Phase 6: Admin UI and Finalization
**Goal**: Media Library provides visual offload status per file, settings page shows storage statistics, and plugin cleans up on uninstall
**Depends on**: Phase 2, Phase 3
**Requirements**: UI-02, UI-03, UI-04, UI-05, SEC-04
**Success Criteria** (what must be TRUE):
  1. Media Library list view shows a status indicator (local / S3 / error) for each attachment
  2. Settings page displays storage statistics: total files on S3, total size, and last sync timestamp
  3. Admin sees warning notices when plugin is misconfigured (missing credentials, failed connection test)
  4. Uninstalling the plugin removes all postmeta, options, and transients it created
**Plans**: TBD

Plans:
- [ ] 06-01: Media Library status column and admin notices
- [ ] 06-02: Storage statistics and uninstall cleanup

## Progress

**Execution Order:**
Phases execute in numeric order: 1 -> 2 -> 3 -> 4 -> 5 -> 6

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Foundation and Settings | 2/2 | Complete | 2026-02-27 |
| 2. S3 Upload Pipeline | 2/2 | Complete | 2026-02-28 |
| 3. URL Rewriting and CloudFront | 2/2 | Complete | 2026-02-28 |
| 4. Deletion Sync | 1/1 | Complete | 2026-02-28 |
| 5. Bulk Migration | 2/2 | Complete | 2026-02-28 |
| 6. Admin UI and Finalization | 0/2 | Not started | - |

---
*Roadmap created: 2026-02-27*
*Depth: comprehensive (6 phases, 11 plans)*
*Coverage: 46/46 v1 requirements mapped*
