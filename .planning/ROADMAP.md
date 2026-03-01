# Roadmap: CT S3 Media Offloader

## Milestones

- v1.0 MVP - Phases 1-6 (shipped 2026-02-28)
- v1.1 PHP 7.4 Compatibility & Tech Debt - Phases 7-8 (shipped 2026-03-01)

## Phases

<details>
<summary>v1.0 MVP (Phases 1-6) - SHIPPED 2026-02-28</summary>

See `.planning/milestones/v1.0-ROADMAP.md` for full phase details.

6 phases, 11 plans completed across plugin scaffolding, S3 client, upload/rewrite/delete handlers, WP-CLI migration, and admin UI.

</details>

### v1.1 PHP 7.4 Compatibility & Tech Debt (SHIPPED 2026-03-01)

**Milestone Goal:** Make the plugin compatible with PHP 7.4+ hosting environments and resolve all tech debt identified in the v1.0 audit.

**Phase Numbering:**
- Integer phases (7, 8): Planned milestone work
- Decimal phases (7.1, 7.2): Urgent insertions if needed (marked with INSERTED)

- [x] **Phase 7: PHP 7.4 Compatibility** - Downgrade AWS SDK and ensure all plugin code runs on PHP 7.4 through 8.x
- [x] **Phase 8: Tech Debt Resolution** - Fix functional gaps, eliminate code duplication, and clean up spec mismatches

## Phase Details

### Phase 7: PHP 7.4 Compatibility
**Goal**: Plugin runs correctly on PHP 7.4+ hosting environments with a compatible AWS SDK version
**Depends on**: Phase 6 (v1.0 complete)
**Requirements**: PHP-01, PHP-02, PHP-03, PHP-04
**Success Criteria** (what must be TRUE):
  1. Plugin activates and loads without errors on PHP 7.4
  2. Plugin header shows `Requires PHP: 7.4`
  3. AWS SDK v3.337.3 is bundled and the S3 client connects successfully
  4. Uploading a new media file offloads it to S3 and serves it via CloudFront (same as v1.0 behavior)
  5. WP-CLI `wp ct-s3 offload` and `wp ct-s3 status` commands work with the downgraded SDK
**Plans:** 2 plans

Plans:
- [x] 07-01-PLAN.md — Download AWS SDK v3.337.3 and add runtime version pinning
- [x] 07-02-PLAN.md — Update PHP version references in documentation and plugin header

### Phase 8: Tech Debt Resolution
**Goal**: All dead code paths become functional, duplicated logic is consolidated, and spec mismatches are resolved
**Depends on**: Phase 7
**Requirements**: DEBT-01, DEBT-02, DEBT-03, DEBT-04, DEBT-05, DEBT-06, DEBT-07
**Success Criteria** (what must be TRUE):
  1. Enabling "Delete local files" setting in admin causes local files to be removed after successful S3 upload
  2. Running connection test from admin and then disconnecting credentials causes a persistent failure notice to appear
  3. When an upload to S3 fails, the Media Library shows a red error badge on that attachment
  4. S3 key paths are built by a single shared method -- Upload Handler and Bulk Migrator both call `S3MO_Tracker` for key generation
  5. `S3MO_Stats` references `S3MO_Tracker` constants for all meta key lookups, and the unused `S3MO_PLUGIN_BASENAME` constant is removed
**Plans:** 3 plans

Plans:
- [x] 08-01-PLAN.md — Refactor Tracker (public constants, shared build_file_list), update Stats and Bulk Migrator, remove unused constant
- [x] 08-02-PLAN.md — Wire delete-local files, connection status transient, and upload error postmeta
- [x] 08-03-PLAN.md — Update CLAUDE.md to reflect resolved tech debt and document CORS handler

## Progress

**Execution Order:**
Phases execute in numeric order: 7 then 8

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 1-6 | v1.0 | 11/11 | Complete | 2026-02-28 |
| 7. PHP 7.4 Compatibility | v1.1 | 2/2 | Complete | 2026-03-01 |
| 8. Tech Debt Resolution | v1.1 | 3/3 | Complete | 2026-03-01 |
