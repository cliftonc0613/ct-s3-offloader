---
phase: 06-admin-ui-and-finalization
plan: 02
subsystem: admin-ui
tags: [stats, dashboard, uninstall, ajax, admin]
dependency-graph:
  requires: ["06-01"]
  provides: ["stats-dashboard", "complete-uninstall", "s3-deletion-option"]
  affects: []
tech-stack:
  added: []
  patterns: ["transient-caching", "ajax-refresh", "batch-s3-deletion"]
key-files:
  created:
    - includes/class-s3mo-stats.php
  modified:
    - admin/class-s3mo-settings-page.php
    - assets/css/admin.css
    - assets/js/admin.js
    - ct-s3-offloader.php
    - uninstall.php
decisions:
  - id: "stats-size-metadata-only"
    choice: "Use wp_get_attachment_metadata filesize key only — no S3 API or filesystem calls"
    reason: "Best-effort estimate avoids slow API calls and missing local files"
metrics:
  duration: "5m 18s"
  completed: "2026-02-28"
---

# Phase 6 Plan 2: Storage Stats Dashboard and Uninstall Cleanup Summary

**One-liner:** Stats dashboard with 4 metric cards, AJAX refresh, and comprehensive uninstall that removes all postmeta/options/transients/logs with optional S3 batch deletion.

## What Was Built

### Task 1: Stats Dashboard and Settings Expansion

Created `S3MO_Stats` class (`includes/class-s3mo-stats.php`) with static methods matching the Tracker pattern:
- `calculate()` — Queries offloaded/total attachment counts via WP_Query, sums filesize from attachment metadata in batches of 100, and fetches most recent offload timestamp via direct SQL.
- `get_cached()` — Returns 1-hour transient-cached stats, calculating on cache miss.
- `refresh()` — Deletes transient, recalculates, and re-caches.

Modified `admin/class-s3mo-settings-page.php`:
- Added `ajax_refresh_stats()` handler returning formatted stats (number_format_i18n, size_format, human_time_diff).
- Added stats dashboard HTML above AWS Credentials section with 4 cards: Files on S3, Total Size, Pending, Last Offloaded.
- Added "Delete S3 Files on Uninstall" checkbox with red warning text.
- Registered `s3mo_delete_s3_on_uninstall` setting with boolean sanitization.

Updated `assets/css/admin.css` with responsive stats grid (4-column, 2-column at 782px).
Updated `assets/js/admin.js` with AJAX click handler for refresh button.
Updated `ct-s3-offloader.php` activation hook to initialize new option, deactivation hook to clear stats transient.

### Task 2: Complete Uninstall Cleanup

Rewrote `uninstall.php` from 3 cleanup lines to comprehensive removal:
- **Optional S3 deletion:** Checks `s3mo_delete_s3_on_uninstall` option, loads AWS SDK, queries offloaded attachments in batches of 100, deletes originals + thumbnails via `deleteObjects()`, wrapped in try/catch.
- **Postmeta:** 4 `delete_post_meta_by_key()` calls for all `_s3mo_` keys.
- **Options:** 3 `delete_option()` calls for all plugin settings.
- **Transients:** 2 `delete_transient()` calls.
- **Log file:** Removes `ct-s3-migration.log` from wp-content.
- **Zero raw $wpdb queries** — all cleanup uses proper WP API for cache invalidation.

## Deviations from Plan

None — plan executed exactly as written.

## Decisions Made

1. **Stats size from metadata only** — The `total_size` stat uses `wp_get_attachment_metadata()['filesize']` exclusively. No `filesize()` calls on local paths (file may be deleted) and no S3 API calls (too slow for dashboard). Files without the metadata key contribute 0 bytes. This is a best-effort estimate clearly sufficient for a dashboard overview.

## Commits

| Hash | Message |
|------|---------|
| 8e31e50 | feat(06-02): add storage statistics dashboard and refresh handler |
| 49294b5 | feat(06-02): expand uninstall.php for complete data cleanup |

## Next Phase Readiness

Plan 06-02 is complete. The admin UI for the CT S3 Offloader plugin now has a full settings page with:
- Credential display
- Connection test
- Storage statistics dashboard with AJAX refresh
- Configurable options (path prefix, delete local, delete S3 on uninstall)
- Comprehensive uninstall cleanup

No blockers or concerns.
