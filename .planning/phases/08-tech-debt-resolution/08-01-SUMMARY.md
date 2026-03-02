---
phase: "08-tech-debt-resolution"
plan: "01"
subsystem: "core-utilities"
tags: ["refactor", "tracker", "constants", "tech-debt"]

dependency-graph:
  requires: ["07-01", "07-02"]
  provides: ["public-tracker-constants", "shared-build-file-list", "error-tracking-methods"]
  affects: ["08-02", "08-03"]

tech-stack:
  added: []
  patterns: ["shared-utility-constants", "delegated-key-building"]

file-tracking:
  key-files:
    created: []
    modified:
      - "includes/class-s3mo-tracker.php"
      - "includes/class-s3mo-stats.php"
      - "includes/class-s3mo-bulk-migrator.php"
      - "ct-s3-offloader.php"

decisions:
  - id: "08-01-01"
    decision: "Make all Tracker meta key constants public for cross-class reference"
    reason: "Eliminates hard-coded string duplication across Stats and Bulk Migrator"
  - id: "08-01-02"
    decision: "Add build_file_list as static method on Tracker rather than standalone function"
    reason: "Tracker is already the shared utility class; keeps file-list logic co-located with meta key constants"

metrics:
  duration: "~3 minutes"
  completed: "2026-03-01"
---

# Phase 08 Plan 01: Tracker Refactor, Constants, and Shared Key-Building Summary

**One-liner:** Public meta key constants on S3MO_Tracker, shared build_file_list method, error tracking methods, Stats/Bulk Migrator consolidated to use Tracker, dead S3MO_PLUGIN_BASENAME removed.

## What Was Done

### Task 1: Refactor S3MO_Tracker
- Changed 4 `private const` declarations to `public const` (META_OFFLOADED, META_KEY, META_BUCKET, META_OFFLOADED_AT)
- Added `public const META_ERROR = '_s3mo_error'`
- Added `build_file_list(array $metadata): array` static method that builds local paths and S3 keys from attachment metadata
- Added `set_error(int $attachment_id, string $message): void` static method
- Added `clear_error(int $attachment_id): void` static method
- Updated `clear_offload_status()` to also delete META_ERROR postmeta
- Commit: `e03a50e`

### Task 2: Update Stats, Bulk Migrator, and Bootstrap
- **S3MO_Stats:** Replaced hard-coded `'_s3mo_offloaded'` with `S3MO_Tracker::META_OFFLOADED` and `'_s3mo_offloaded_at'` with `S3MO_Tracker::META_OFFLOADED_AT`
- **S3MO_Bulk_Migrator:** Refactored `build_file_key_list()` to delegate to `S3MO_Tracker::build_file_list()` — eliminated 20+ lines of duplicated key-building logic
- **S3MO_Bulk_Migrator:** Replaced all 5 hard-coded `'_s3mo_offloaded'` strings with `S3MO_Tracker::META_OFFLOADED`
- **Bootstrap:** Removed unused `S3MO_PLUGIN_BASENAME` constant definition
- Commit: `9892aaa`

## Deviations from Plan

None -- plan executed exactly as written.

## Decisions Made

1. **Public constants on Tracker** -- All meta key constants made public so Stats and Bulk Migrator reference them directly, eliminating string duplication (DEBT-05 resolved).
2. **build_file_list on Tracker** -- Shared key-building logic lives on Tracker as a static method, keeping it co-located with meta key constants (DEBT-04 resolved).

## Tech Debt Resolved

- **DEBT-04:** S3 key-building logic duplicated between Upload_Handler and Bulk_Migrator -- now shared via `S3MO_Tracker::build_file_list()`
- **DEBT-05:** S3MO_Stats hard-coded meta key strings instead of using Tracker constants -- now uses `S3MO_Tracker::META_*`
- **DEBT-06:** S3MO_PLUGIN_BASENAME defined but never used -- removed from bootstrap

## Verification

- All 4 modified files pass `php -l` syntax checks
- Zero occurrences of `S3MO_PLUGIN_BASENAME` in any .php file
- Zero hard-coded `'_s3mo_offloaded'` strings in Stats
- `S3MO_Tracker::build_file_list` confirmed in Bulk Migrator

## Next Phase Readiness

Plan 08-02 can now use:
- `S3MO_Tracker::META_ERROR` constant for error tracking
- `S3MO_Tracker::set_error()` and `clear_error()` methods
- All public `META_*` constants for meta queries
