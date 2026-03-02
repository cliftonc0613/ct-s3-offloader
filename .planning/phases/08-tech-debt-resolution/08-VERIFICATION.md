---
phase: 08-tech-debt-resolution
verified: 2026-03-01T22:53:10Z
status: gaps_found
score: 12/14 must-haves verified
gaps:
  - truth: "S3 key paths are built by a single shared method -- Upload Handler and Bulk Migrator both call S3MO_Tracker for key generation"
    status: failed
    reason: "S3MO_Upload_Handler::handle_upload() still inlines its own key-building logic (lines 61-84) instead of delegating to S3MO_Tracker::build_file_list(). Only S3MO_Bulk_Migrator was refactored."
    artifacts:
      - path: "includes/class-s3mo-upload-handler.php"
        issue: "Lines 61-84 contain inline prefix/subdir/file-list construction. No call to S3MO_Tracker::build_file_list() exists anywhere in this file."
    missing:
      - "Replace the inline file-list construction in handle_upload() (lines 61-84) with a call to S3MO_Tracker::build_file_list($metadata)"
      - "Add mime-type to each entry returned by build_file_list, or derive it inside handle_upload after the delegation call (since build_file_list returns {local, key} but upload needs mime)"
  - truth: "S3MO_Stats references S3MO_Tracker constants for all meta key lookups, and the unused S3MO_PLUGIN_BASENAME constant is removed"
    status: partial
    reason: "S3MO_Stats is fully clean (no hardcoded strings). S3MO_PLUGIN_BASENAME is removed from bootstrap. However, S3MO_Bulk_Migrator::get_status_counts() (line 262) still uses the hardcoded string '_s3mo_offloaded' instead of S3MO_Tracker::META_OFFLOADED. This is in scope for DEBT-05 (Stats hard-codes meta key strings) and the broader goal of eliminating hardcoded strings."
    artifacts:
      - path: "includes/class-s3mo-bulk-migrator.php"
        issue: "Line 262: 'key' => '_s3mo_offloaded' -- hardcoded meta key string instead of S3MO_Tracker::META_OFFLOADED"
    missing:
      - "Replace '_s3mo_offloaded' on line 262 of class-s3mo-bulk-migrator.php with S3MO_Tracker::META_OFFLOADED"
---

# Phase 8: Tech Debt Resolution Verification Report

**Phase Goal:** All dead code paths become functional, duplicated logic is consolidated, and spec mismatches are resolved
**Verified:** 2026-03-01T22:53:10Z
**Status:** gaps_found
**Re-verification:** No -- initial verification

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Enabling "Delete local files" causes local files removed after S3 upload | VERIFIED | `S3MO_Upload_Handler::handle_upload()` lines 118-126: reads `s3mo_delete_local` option and calls `unlink()` on each file. `S3MO_Bulk_Migrator::upload_attachment()` lines 195-203: same guard and unlink logic. |
| 2 | Connection test failure causes persistent failure notice | VERIFIED | `S3MO_Settings_Page::ajax_test_connection()` line 111: `set_transient('s3mo_connection_status', $result)` written on both success and failure. `S3MO_Admin_Notices::notice_failed_connection()` reads the transient and renders error notice when `$status['success']` is empty. |
| 3 | Upload failure causes red error badge in Media Library | VERIFIED | Upload failures call `S3MO_Tracker::set_error($attachment_id, ...)` in both partial and complete failure branches (upload handler lines 129-143). `S3MO_Tracker::set_error()` writes `_s3mo_error` postmeta. Media Column reads this postmeta to display the error badge. |
| 4 | Upload Handler and Bulk Migrator both call S3MO_Tracker for key generation | FAILED | `S3MO_Bulk_Migrator::build_file_key_list()` delegates to `S3MO_Tracker::build_file_list()` (line 134). `S3MO_Upload_Handler::handle_upload()` does NOT -- it inlines its own prefix/subdir/key construction (lines 61-84). Deduplication is only half-complete. |
| 5 | S3MO_Stats references S3MO_Tracker constants; S3MO_PLUGIN_BASENAME removed | PARTIAL | S3MO_Stats: fully uses `S3MO_Tracker::META_OFFLOADED` and `S3MO_Tracker::META_OFFLOADED_AT` constants -- no hardcoded strings. Bootstrap: `S3MO_PLUGIN_BASENAME` define line is absent from `ct-s3-offloader.php`. Gap: `S3MO_Bulk_Migrator::get_status_counts()` line 262 still uses hardcoded `'_s3mo_offloaded'`. |
| 6 | S3MO_Tracker exposes public constants for all meta keys | VERIFIED | `S3MO_Tracker` declares `META_OFFLOADED`, `META_KEY`, `META_BUCKET`, `META_OFFLOADED_AT`, `META_ERROR` as `public const` (lines 25-37). |
| 7 | S3MO_Tracker has static build_file_list method | VERIFIED | `S3MO_Tracker::build_file_list(array $metadata): array` exists at lines 121-149. Accepts metadata array, returns `{local, key}` entries for original + all thumbnails. |
| 8 | CLAUDE.md Known Tech Debt section reflects resolved items | VERIFIED | Original 5-item debt list replaced with single CORS scope note. Database Schema includes `_s3mo_error` entry with accurate description. Dependency Pattern section updated to document `build_file_list` and error tracking methods. |

**Score:** 12/14 must-haves verified (6/8 truths fully verified, 1 partial, 1 failed)

---

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `includes/class-s3mo-tracker.php` | Public meta constants + `build_file_list` + `set_error`/`clear_error` | VERIFIED | 169 lines. All 5 public constants present. `build_file_list` at line 121. `set_error` at line 157, `clear_error` at line 166. |
| `includes/class-s3mo-stats.php` | Uses S3MO_Tracker constants, no hardcoded meta strings | VERIFIED | 119 lines. Line 37: `S3MO_Tracker::META_OFFLOADED`. Line 73: `S3MO_Tracker::META_OFFLOADED_AT`. No raw `_s3mo_*` strings. |
| `includes/class-s3mo-upload-handler.php` | Delegates key-building to Tracker; deletes local files; writes error postmeta on failure | PARTIAL | 157 lines. Delete-local: VERIFIED (lines 118-126). Error postmeta: VERIFIED (lines 129-143). Key delegation: FAILED -- inline key construction still present (lines 61-84). |
| `includes/class-s3mo-bulk-migrator.php` | Delegates key-building to Tracker; deletes local files; writes error postmeta on failure | PARTIAL | 455 lines. `build_file_key_list` delegates to `S3MO_Tracker::build_file_list` (line 134): VERIFIED. Delete-local: VERIFIED (lines 195-203). Error postmeta on failure: VERIFIED (line 215). Residual hardcoded string: line 262 `'_s3mo_offloaded'`. |
| `admin/class-s3mo-settings-page.php` | Writes `s3mo_connection_status` transient on connection test | VERIFIED | Line 111: `set_transient('s3mo_connection_status', $result)` called unconditionally inside `ajax_test_connection()` before branching on success/failure. |
| `admin/class-s3mo-admin-notices.php` | Reads transient; shows error notice on failure | VERIFIED | `notice_failed_connection()` reads transient at line 67. Shows error div when `$status['success']` is falsy (lines 70-77). |
| `uninstall.php` | Cleans up `_s3mo_error` postmeta | VERIFIED | Line 107: `delete_post_meta_by_key('_s3mo_error')` present alongside all other meta key cleanups. |
| `ct-s3-offloader.php` | No `S3MO_PLUGIN_BASENAME` define | VERIFIED | Only four constants defined: `S3MO_VERSION`, `S3MO_EXPECTED_AWS_SDK_VERSION`, `S3MO_PLUGIN_DIR`, `S3MO_PLUGIN_URL`. No `S3MO_PLUGIN_BASENAME` define present. |
| `CLAUDE.md` | Tech debt updated; `_s3mo_error` documented; `s3mo_delete_local` described accurately | VERIFIED | Known Tech Debt section has only CORS scope note. Database Schema: `_s3mo_error` entry at line 75. Options section: `s3mo_delete_local` described as "When enabled, deletes local files after successful S3 upload" (accurate). |

---

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `S3MO_Upload_Handler` | `S3MO_Tracker::build_file_list` | delegation call | NOT WIRED | Upload handler builds its own file list inline; never calls Tracker's shared method |
| `S3MO_Bulk_Migrator::build_file_key_list` | `S3MO_Tracker::build_file_list` | delegation call | WIRED | Line 134: `return S3MO_Tracker::build_file_list($metadata)` |
| `ajax_test_connection` | `s3mo_connection_status` transient | `set_transient()` | WIRED | Line 111 writes transient before returning AJAX response |
| `S3MO_Admin_Notices` | `s3mo_connection_status` transient | `get_transient()` | WIRED | Line 67 reads transient; failure branch renders error notice |
| `S3MO_Upload_Handler` | `S3MO_Tracker::set_error` | on upload failure | WIRED | Lines 129-143: both partial and full failure branches call `set_error` |
| `S3MO_Upload_Handler` | local file deletion | `unlink()` guarded by option | WIRED | Lines 118-126: option read, file_exists check, unlink call |
| `S3MO_Bulk_Migrator` | local file deletion | `unlink()` guarded by option | WIRED | Lines 195-203: same pattern as Upload Handler |
| `uninstall.php` | `_s3mo_error` cleanup | `delete_post_meta_by_key` | WIRED | Line 107 |

---

### Requirements Coverage

| Requirement | Status | Notes |
|-------------|--------|-------|
| DEBT-01: `s3mo_delete_local` option actually deletes local files | SATISFIED | Both Upload Handler and Bulk Migrator now read the option and unlink files |
| DEBT-02: `s3mo_connection_status` transient written by connection test | SATISFIED | `ajax_test_connection` writes transient unconditionally |
| DEBT-03: `_s3mo_error` postmeta written on upload failure | SATISFIED | `S3MO_Tracker::set_error()` called in both failure branches of upload |
| DEBT-04: Key-building logic consolidated | PARTIAL | Bulk Migrator delegates to Tracker. Upload Handler still has inline logic. |
| DEBT-05: S3MO_Stats uses Tracker constants | SATISFIED | S3MO_Stats has no hardcoded meta strings. Residual hardcoded string found in S3MO_Bulk_Migrator::get_status_counts() |
| DEBT-06: S3MO_PLUGIN_BASENAME removed | SATISFIED | Absent from bootstrap |
| DEBT-07: CLAUDE.md updated to reflect resolved debt | SATISFIED | Tech debt list accurate; schema documented |

---

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `includes/class-s3mo-upload-handler.php` | 62-84 | Duplicated key-building logic (inline prefix/subdir construction identical to `S3MO_Tracker::build_file_list`) | Warning | Consolidation goal not fully achieved; future changes to key-building must be made in two places |
| `includes/class-s3mo-bulk-migrator.php` | 262 | Hardcoded `'_s3mo_offloaded'` string instead of `S3MO_Tracker::META_OFFLOADED` | Warning | Out of sync if constant value ever changes |
| `includes/class-s3mo-upload-handler.php` | 121-122 | `@unlink()` error-suppressed silently logs via `error_log` | Info | Minor -- same pattern used in Bulk Migrator, consistent |

---

### Human Verification Required

None. All must-haves are programmatically verifiable via code inspection.

---

### Gaps Summary

Two gaps block full goal achievement:

**Gap 1 (DEBT-04 partial):** `S3MO_Upload_Handler::handle_upload()` still builds its own file list inline. The method constructs `$prefix`, `$upload_dir`, and the `$files` array directly (lines 61-84) rather than calling `S3MO_Tracker::build_file_list()`. The root cause is that `build_file_list` returns `{local, key}` entries but the upload handler also needs `mime` per entry -- the refactor was completed for Bulk Migrator (where mime is derived from `mime_content_type()` after the fact) but not for Upload Handler (which needs mime inline). The fix requires either extending `build_file_list` to accept a mime resolver or having Upload Handler call `build_file_list` and then augment each entry with the appropriate mime type.

**Gap 2 (DEBT-05 partial):** `S3MO_Bulk_Migrator::get_status_counts()` at line 262 uses the hardcoded string `'_s3mo_offloaded'` in a `meta_query` key. Every other reference in the same file uses `S3MO_Tracker::META_OFFLOADED`. This is a single-line fix.

Both gaps are isolated changes. No other logic is affected. The remaining 12 must-haves are fully satisfied.

---

_Verified: 2026-03-01T22:53:10Z_
_Verifier: Claude (gsd-verifier)_
