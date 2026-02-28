---
phase: 04-deletion-sync
plan: 01
subsystem: deletion-sync
tags: [s3, delete, lifecycle, media-library, wp-cli, rest-api]
dependency-graph:
  requires: [02-01, 02-02]
  provides: [s3-deletion-handler, upload-serve-delete-lifecycle]
  affects: [05-bulk-offloader, 06-settings-and-polish]
tech-stack:
  added: []
  patterns: [dependency-injection, action-hooks, error-logging-not-throwing]
key-files:
  created:
    - includes/class-s3mo-delete-handler.php
  modified:
    - ct-s3-offloader.php
decisions:
  - Delete handler registered outside is_admin() for REST API and WP-CLI support
  - Failed S3 deletions logged but never thrown (DEL-04)
  - Tracker cleared AFTER S3 deletions to preserve key access
  - array_unique deduplication handles WordPress size reuse
metrics:
  duration: ~1m 30s
  completed: 2026-02-28
---

# Phase 4 Plan 1: S3 Deletion Handler Summary

**S3MO_Delete_Handler hooks into delete_attachment to remove original + thumbnail S3 objects before WordPress cleans up metadata, with error-tolerant logging.**

## What Was Done

### Task 1: Create S3MO_Delete_Handler class
- Created `includes/class-s3mo-delete-handler.php` (102 lines)
- Constructor injection of S3MO_Client (matches upload handler pattern)
- `register_hooks()` adds `delete_attachment` action at priority 10
- `handle_delete()` guards on `S3MO_Tracker::is_offloaded()`, collects keys, deletes from S3
- `collect_s3_keys()` gets original key from tracker + derives thumbnail keys from metadata
- Keys deduplicated with `array_unique()` before deletion loop
- Failures logged via `error_log()` but never thrown or returned early
- Tracker cleared after all S3 deletions complete
- **Commit:** `adf77dc`

### Task 2: Wire delete handler into plugin bootstrap
- Added `S3MO_Delete_Handler` instantiation and `register_hooks()` in `ct-s3-offloader.php`
- Placed outside `is_admin()` block for REST API and WP-CLI support
- Bootstrap order: client -> upload_handler -> url_rewriter -> delete_handler -> admin settings
- **Commit:** `2becbae`

## Deviations from Plan

None - plan executed exactly as written.

## Verification Results

All verification criteria passed:
- `delete_attachment` hook registered in handler
- `S3MO_Tracker::is_offloaded` guard present
- `S3MO_Tracker::clear_offload_status` called after deletions
- `delete_object` called for each S3 key
- `error_log` for failure logging
- `array_unique` for key deduplication
- Both PHP files pass syntax check (`php -l`)
- Delete handler wired outside `is_admin()` in bootstrap

## Decisions Made

| Decision | Rationale |
|----------|-----------|
| Priority 10 for delete_attachment | Fires before postmeta removal so S3 key is still accessible |
| Log failures, never throw | WordPress must complete deletion regardless of S3 errors (DEL-04) |
| Clear tracker AFTER S3 loop | Need the tracked key to know what to delete from S3 |
| Outside is_admin() | Deletions happen via REST API, WP-CLI, not just admin dashboard |

## Next Phase Readiness

Upload-serve-delete lifecycle is now complete. Ready for:
- Phase 5: Bulk offloader (batch processing existing media)
- Phase 6: Settings and polish (UI, status display, configuration)
