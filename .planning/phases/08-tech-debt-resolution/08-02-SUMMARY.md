---
phase: 08-tech-debt-resolution
plan: 02
subsystem: core-upload-pipeline
tags: [tech-debt, delete-local, error-tracking, connection-status]

dependency-graph:
  requires: ["08-01"]
  provides: ["DEBT-01-delete-local", "DEBT-02-connection-transient", "DEBT-03-error-postmeta"]
  affects: ["08-03"]

tech-stack:
  added: []
  patterns: ["option-gated-deletion", "transient-backed-status", "error-meta-lifecycle"]

file-tracking:
  key-files:
    created: []
    modified:
      - includes/class-s3mo-upload-handler.php
      - includes/class-s3mo-bulk-migrator.php
      - admin/class-s3mo-settings-page.php
      - uninstall.php

decisions:
  - id: "08-02-01"
    decision: "Delete-local uses @unlink with silent failure and error_log"
    rationale: "Non-critical operation should not break the upload pipeline"

metrics:
  duration: "~2 minutes"
  completed: "2026-03-01"
---

# Phase 8 Plan 02: Wire Dead Code Paths Summary

**One-liner:** Connected three dead-code paths — delete-local files, connection status transient, and upload error postmeta — making existing UI features functional.

## What Was Done

### Task 1: Wire delete-local and error postmeta in Upload Handler
**Commit:** `192b190`

Modified `handle_upload()` in `S3MO_Upload_Handler` to:
- Clear `_s3mo_error` on successful upload via `S3MO_Tracker::clear_error()`
- Read `s3mo_delete_local` option and `@unlink()` local files after successful S3 upload
- Write `_s3mo_error` via `S3MO_Tracker::set_error()` on partial upload failure (with count details)
- Write `_s3mo_error` via `S3MO_Tracker::set_error()` on complete upload failure

### Task 2: Wire connection transient, bulk migrator delete-local/error, and uninstall cleanup
**Commit:** `2049dbd`

Three files modified:
- **Settings Page:** `set_transient('s3mo_connection_status', $result)` after `test_connection()`, before success/error branching — Admin Notices can now read connection failures
- **Bulk Migrator:** `clear_error()` and delete-local on success; `set_error()` on failure after all retries exhausted
- **Uninstall:** Added `delete_post_meta_by_key('_s3mo_error')` to postmeta cleanup section

## Verification Results

All 4 modified files pass `php -l` syntax check.

Full chain verification:
- **DEBT-01 (delete-local):** `s3mo_delete_local` read in Upload Handler AND Bulk Migrator
- **DEBT-02 (connection transient):** `s3mo_connection_status` written in Settings Page, read in Admin Notices
- **DEBT-03 (error postmeta):** `_s3mo_error` written in Upload Handler and Bulk Migrator, read in Media Column, cleaned in uninstall.php

## Deviations from Plan

None — plan executed exactly as written.

## Decisions Made

| ID | Decision | Rationale |
|----|----------|-----------|
| 08-02-01 | Delete-local uses @unlink with silent failure and error_log | Non-critical operation should not break the upload pipeline |

## Next Phase Readiness

Plan 08-03 can proceed. All three dead-code debt items (DEBT-01, DEBT-02, DEBT-03) are now functional and ready for CLAUDE.md documentation updates.
