# CT S3 Offloader — v1.0 Integration Check Report

**Date:** 2026-02-28
**Auditor:** Integration Checker
**Scope:** All 6 phases, cross-phase wiring, E2E flows

---

## Summary

**Connected:** 14 cross-phase wiring points verified correct
**Orphaned:** 1 class listed in phase spec does not exist (S3MO_CORS_Handler)
**Missing:** 1 expected connection not found (connection test transient write)
**Broken Flows:** 0 E2E flows are broken at runtime (1 has a minor gap — see details)
**Complete Flows:** 5 of 5 E2E flows work end-to-end

---

## Autoloader Verification

The autoloader in `ct-s3-offloader.php` (lines 24–43) converts class names to filenames using:

```
class S3MO_Foo_Bar  →  class-s3mo-foo-bar.php
```

Mapping check against all physical files:

| Class Name | Expected Filename | File Exists |
|---|---|---|
| S3MO_Client | class-s3mo-client.php | YES |
| S3MO_Upload_Handler | class-s3mo-upload-handler.php | YES |
| S3MO_Tracker | class-s3mo-tracker.php | YES |
| S3MO_URL_Rewriter | class-s3mo-url-rewriter.php | YES |
| S3MO_Delete_Handler | class-s3mo-delete-handler.php | YES |
| S3MO_Bulk_Migrator | class-s3mo-bulk-migrator.php | YES |
| S3MO_CLI_Command | class-s3mo-cli-command.php | YES |
| S3MO_Stats | class-s3mo-stats.php | YES |
| S3MO_Settings_Page | class-s3mo-settings-page.php | YES |
| S3MO_Media_Column | class-s3mo-media-column.php | YES |
| S3MO_Admin_Notices | class-s3mo-admin-notices.php | YES |
| S3MO_CORS_Handler | class-s3mo-cors-handler.php | **NO — FILE MISSING** |

**Finding:** `S3MO_CORS_Handler` is listed in the Phase 3 spec as a separate file. The file does not exist in `includes/`. CORS logic is implemented inline inside `S3MO_URL_Rewriter::add_cors_headers()` and registered via `send_headers` hook in `register_hooks()`. The functionality exists but the class name and file are different from the spec. The autoloader will never attempt to load `S3MO_CORS_Handler` because nothing instantiates it — there is no runtime breakage.

---

## Cross-Phase Wiring

### 1. Upload → Tracker → URL Rewriter Chain

**Upload → Tracker (CONNECTED)**

- `S3MO_Upload_Handler::handle_upload()` calls `S3MO_Tracker::is_offloaded()` at line 56 (idempotency guard)
- `S3MO_Upload_Handler::handle_upload()` calls `S3MO_Tracker::mark_as_offloaded()` at line 114 on full success
- Method signatures match: `mark_as_offloaded(int $attachment_id, string $s3_key, string $bucket)` called with `($attachment_id, $files[0]['key'], $this->client->get_bucket())` — all types correct

**Tracker → URL Rewriter (CONNECTED)**

- `S3MO_URL_Rewriter::filter_attachment_url()` calls `S3MO_Tracker::is_offloaded()` at line 61
- `S3MO_URL_Rewriter::filter_attachment_url()` calls `S3MO_Tracker::get_s3_key()` at line 65
- `S3MO_URL_Rewriter::filter_srcset()` calls `S3MO_Tracker::is_offloaded()` at line 136
- `S3MO_URL_Rewriter::filter_rest_attachment()` calls `S3MO_Tracker::is_offloaded()` at line 183 and `get_s3_key()` at line 188
- `S3MO_URL_Rewriter::filter_attachment_for_js()` calls `S3MO_Tracker::is_offloaded()` at line 235
- All calls use static invocation `S3MO_Tracker::method()` which matches the all-static class definition

### 2. Upload → Delete Chain

**CONNECTED**

- `S3MO_Delete_Handler::handle_delete()` calls `S3MO_Tracker::is_offloaded()` at line 46 (guard)
- `S3MO_Delete_Handler::collect_s3_keys()` calls `S3MO_Tracker::get_s3_key()` at line 81
- `S3MO_Delete_Handler::handle_delete()` calls `S3MO_Tracker::clear_offload_status()` at line 67
- Hook is `delete_attachment` (line 29), which fires BEFORE WordPress removes postmeta — correct order for reading tracker meta before clearing it
- The handler reads the tracker key, deletes S3 objects, then clears metadata in the correct sequence

**Note:** The bootstrap doc references `before_delete_post` hook, but the actual implementation uses `delete_attachment`. These are different hooks: `delete_attachment` is the correct, attachment-specific hook and fires before postmeta deletion. This is not a bug — it is a better implementation than the spec described.

### 3. Bulk Migrator → Upload Handler / S3 Client + Tracker

**CONNECTED (via direct S3MO_Client and S3MO_Tracker, not via S3MO_Upload_Handler)**

- `S3MO_Bulk_Migrator::upload_attachment()` calls `this->client->upload_object()` directly (line 199)
- `S3MO_Bulk_Migrator::upload_attachment()` calls `S3MO_Tracker::mark_as_offloaded()` at line 209
- `S3MO_Bulk_Migrator::upload_attachment()` calls `S3MO_Tracker::is_offloaded()` at line 173

The bulk migrator does NOT reuse `S3MO_Upload_Handler` — it reuses `S3MO_Client` and `S3MO_Tracker` directly, which was the actual design intent. The path-building logic in `build_file_key_list()` mirrors `S3MO_Upload_Handler::handle_upload()` but is duplicated rather than shared.

**Duplication risk:** Both `S3MO_Upload_Handler` and `S3MO_Bulk_Migrator::build_file_key_list()` build the key as `$prefix . '/' . $metadata['file']`. If the key-building logic ever changes, it must be updated in two places.

### 4. URL Rewriter → Settings / Constants

**CONNECTED**

- `S3MO_URL_Rewriter` receives `S3MO_Client` via constructor injection
- `S3MO_URL_Rewriter::get_cdn_uploads_base()` (line 334–343) calls `$this->client->get_url_base()` which reads `S3MO_CDN_URL` constant and falls back to direct S3 URL
- `S3MO_URL_Rewriter::get_cdn_uploads_base()` calls `get_option('s3mo_path_prefix', 'wp-content/uploads')` to build the full base URL — same option key registered in `S3MO_Settings_Page::register_settings()` and set in the activation hook
- CORS allowed-origins check in `add_cors_headers()` reads `S3MO_CDN_URL` directly (line 312) — consistent with how the client uses it

### 5. Admin Notices → Settings / Credentials

**CONNECTED**

- `S3MO_Admin_Notices::notice_missing_credentials()` checks the same four constants (`S3MO_BUCKET`, `S3MO_REGION`, `S3MO_KEY`, `S3MO_SECRET`) that the bootstrap checks at line 75
- `S3MO_Admin_Notices::notice_missing_cdn_url()` checks `S3MO_CDN_URL` — same constant the client uses
- `S3MO_Admin_Notices::notice_failed_connection()` reads transient `s3mo_connection_status`

**Missing write:** No code in the plugin currently writes the `s3mo_connection_status` transient. The AJAX handler `ajax_test_connection()` in `S3MO_Settings_Page` calls `$this->client->test_connection()` and returns the result to the browser, but never calls `set_transient('s3mo_connection_status', ...)`. The admin notice at `notice_failed_connection()` will therefore never display because the transient is never populated.

The deactivation hook correctly deletes the transient (line 124 in bootstrap), and `uninstall.php` line 116 also deletes it — but neither is a problem since the transient is never set.

### 6. Media Column → Tracker

**CONNECTED**

- `S3MO_Media_Column::render_column()` calls `S3MO_Tracker::get_offload_info($post_id)` at line 47
- Return shape `{offloaded, key, bucket, offloaded_at}` matches what the render method accesses: `$info['offloaded']`, `$info['key']`, `$info['bucket']`, `$info['offloaded_at']` — all four keys present in `S3MO_Tracker::get_offload_info()`
- Media column also reads `_s3mo_error` postmeta directly (line 49) — this meta key is never written by any phase; the error status display is permanently dormant (no code path writes `_s3mo_error`)

### 7. Stats → Tracker

**CONNECTED (via raw meta keys, not via S3MO_Tracker methods)**

- `S3MO_Stats::calculate()` queries `_s3mo_offloaded` meta key directly in WP_Query (line 37)
- `S3MO_Stats::calculate()` queries `_s3mo_offloaded_at` meta key directly in raw SQL (line 73)
- These hard-coded string keys (`'_s3mo_offloaded'`, `'_s3mo_offloaded_at'`) match the private constants in `S3MO_Tracker`: `META_OFFLOADED = '_s3mo_offloaded'` and `META_OFFLOADED_AT = '_s3mo_offloaded_at'`

**Risk:** `S3MO_Stats` bypasses `S3MO_Tracker` methods and hard-codes the meta key strings. If `S3MO_Tracker`'s private constants ever change, `S3MO_Stats` would silently break. Not a current bug, but a maintenance hazard.

- `S3MO_Settings_Page::ajax_refresh_stats()` calls `S3MO_Stats::refresh()` — method exists and returns correct shape
- `S3MO_Settings_Page::render_page()` calls `S3MO_Stats::get_cached()` — method exists and returns correct shape
- Both callers format the returned array correctly: `total_files`, `total_size`, `pending`, `last_offloaded` keys all match

### 8. Uninstall → All Phases

**CONNECTED**

`uninstall.php` cleans up:

| Data Created By | Keys/Names | Removed in Uninstall |
|---|---|---|
| S3MO_Tracker (Phase 2) | `_s3mo_offloaded`, `_s3mo_key`, `_s3mo_bucket`, `_s3mo_offloaded_at` | YES — lines 103–106 |
| S3MO_Settings_Page / Activation (Phase 1/6) | `s3mo_path_prefix`, `s3mo_delete_local`, `s3mo_delete_s3_on_uninstall` | YES — lines 110–112 |
| S3MO_Admin_Notices transient (Phase 6) | `s3mo_connection_status` | YES — line 116 |
| S3MO_Stats transient (Phase 6) | `s3mo_stats_cache` | YES — line 117 |
| S3MO_CLI_Command log (Phase 5) | `wp-content/ct-s3-migration.log` | YES — lines 121–124 |

**Complete coverage.** Every key, option, transient, and file created across all phases is removed.

**Note:** The `_s3mo_error` meta key read by `S3MO_Media_Column` is never written and not in the uninstall cleanup — consistent (nothing to clean up).

---

## Bootstrap Instantiation Order

Bootstrap (lines 74–112) wires classes in this order:

1. `S3MO_Admin_Notices` — no dependencies, always in admin
2. `S3MO_Media_Column` — no dependencies, always in admin
3. `S3MO_Client` — requires all 4 constants
4. `S3MO_Upload_Handler($client)` — gets client
5. `S3MO_URL_Rewriter($client)` — gets client
6. `S3MO_Delete_Handler($client)` — gets client
7. `S3MO_Settings_Page($client)` — admin only with credentials, or `null` without

**Order analysis:**
- `S3MO_Admin_Notices` and `S3MO_Media_Column` are instantiated before the credentials check. Both classes are stateless at construction — no issue.
- `S3MO_Media_Column` reads `S3MO_Tracker` at render time (not at construction), so it works regardless of credential availability — correct.
- `S3MO_URL_Rewriter` and `S3MO_Upload_Handler` are only instantiated when credentials are present — correct, since they both use `S3MO_Client`.
- `S3MO_Bulk_Migrator` and `S3MO_CLI_Command` are **not instantiated in the bootstrap**. CLI command is instantiated separately at lines 63–70 (WP-CLI block). `S3MO_Bulk_Migrator` is instantiated inside `S3MO_CLI_Command::__construct()`. This is correct — bulk migration is CLI-only.
- `S3MO_Stats` is **not instantiated** — it is all-static, called directly as `S3MO_Stats::get_cached()` and `S3MO_Stats::refresh()`. The autoloader will resolve it on first static call.
- `S3MO_Tracker` is **not instantiated** — it is all-static, called directly throughout. Autoloader resolves on first call.

**Hook priority check:**
- All `register_hooks()` calls are inside `plugins_loaded` at priority 10. This is after WordPress core hooks are registered. No conflicts identified.
- `wp_generate_attachment_metadata` filter (upload handler): priority 10 — standard
- `delete_attachment` action (delete handler): priority 10 — standard, fires before WP removes postmeta
- `wp_get_attachment_url` filter (URL rewriter): priority 10 — standard
- `the_content` filter (URL rewriter): priority 10 — before most theme/plugin content filters (safe)
- `rest_prepare_attachment` filter (URL rewriter): priority 10 — standard
- `send_headers` action (CORS): priority 10 — fires on every request; guarded by `REST_REQUEST` constant check

---

## E2E Flow Verification

### Flow 1: New Upload

**Status: COMPLETE**

```
User uploads image
  → WordPress saves locally
  → wp_generate_attachment_metadata fires
  → S3MO_Upload_Handler::handle_upload() ($context === 'create' guard passes)
  → S3MO_Tracker::is_offloaded() returns false (first upload)
  → client->upload_object() uploads original + all thumbnail sizes
  → S3MO_Tracker::mark_as_offloaded() sets 4 meta keys
  → On frontend: wp_get_attachment_url fires
  → S3MO_URL_Rewriter::filter_attachment_url() checks Tracker → is_offloaded = true
  → Returns CDN URL via client->get_object_url($key)
  → Media Library: S3MO_Media_Column::render_column() calls Tracker::get_offload_info()
  → Displays "S3" badge with key/bucket/timestamp detail
```

All steps present, methods exist, data flows through correctly.

### Flow 2: Delete Media

**Status: COMPLETE**

```
User deletes attachment
  → delete_attachment action fires (before WP removes postmeta)
  → S3MO_Delete_Handler::handle_delete()
  → S3MO_Tracker::is_offloaded() — returns true
  → collect_s3_keys() calls Tracker::get_s3_key() for original key
  → Derives thumbnail keys from attachment metadata sizes
  → client->delete_object() called for each key independently
  → Failures logged, not thrown (WordPress deletion proceeds regardless)
  → S3MO_Tracker::clear_offload_status() removes all 4 meta keys
  → WordPress completes attachment deletion
```

All steps present. The delete handler correctly reads meta BEFORE calling clear, preventing a timing issue.

### Flow 3: Bulk Migration (WP-CLI)

**Status: COMPLETE**

```
wp ct-s3 offload
  → Bootstrap block (lines 63–70) checks all 4 constants
  → WP_CLI::add_command('ct-s3', new S3MO_CLI_Command(new S3MO_Client()))
  → S3MO_CLI_Command::__construct() creates S3MO_Bulk_Migrator($client)
  → offload() calls migrator->count_attachments() → WP_Query on _s3mo_offloaded
  → Loop: migrator->get_next_batch() → returns IDs not yet offloaded
  → migrator->upload_attachment() for each ID:
      → build_file_key_list() builds local paths + S3 keys
      → client->upload_object() per file with retry
      → S3MO_Tracker::mark_as_offloaded() on success
  → migrator->cleanup_memory() between batches
  → URLs auto-rewrite on next frontend request (URL rewriter reads Tracker)
```

All steps present. CLI is gated behind credential check at lines 63–66 of bootstrap — WP-CLI block will not add the command if any constant is missing.

### Flow 4: Deactivation / Reactivation

**Status: COMPLETE**

```
Deactivate plugin
  → deactivation hook fires (line 123–126 of bootstrap)
  → Deletes transients: s3mo_connection_status, s3mo_stats_cache
  → All hooks were registered via add_filter/add_action — deactivation
     causes WordPress to no longer load the plugin, so hooks are not registered
  → URLs on frontend: wp_get_attachment_url fires, no filter registered
  → Local URLs served (WordPress default behavior)
  → No data is destroyed — postmeta, options intact

Reactivate plugin
  → plugins_loaded fires, all hooks re-registered
  → URL rewriter active again
  → Tracker postmeta still present (deactivation did not clear it)
  → CDN URLs immediately resume serving correctly
```

No code changes needed. Deactivation cleanly removes behavior without destroying state.

### Flow 5: Uninstall

**Status: COMPLETE (with one noted behavior)**

```
Delete plugin from WordPress admin
  → WP_UNINSTALL_PLUGIN constant defined by WordPress
  → uninstall.php loaded directly (not via plugin bootstrap)
  → If s3mo_delete_s3_on_uninstall option is true:
      → Loads aws-sdk autoloader directly (does not use plugin autoloader)
      → Creates Aws\S3\S3Client directly
      → Queries all offloaded attachments in batches of 100
      → Reads _s3mo_key meta directly via get_post_meta()
      → Derives thumbnail keys from attachment metadata
      → Calls deleteObjects() (batch delete API — correct, not single delete loop)
  → delete_post_meta_by_key() removes all 4 tracker meta keys
  → delete_option() removes all 3 plugin options
  → delete_transient() removes both transients
  → Unlinks migration log file if present
```

**Note:** `uninstall.php` does not use the plugin autoloader or `S3MO_Tracker` class — it uses raw WordPress functions directly. This is correct for an uninstall script: it must work independently of the plugin's class structure. The meta keys are hard-coded strings that match `S3MO_Tracker`'s private constants exactly.

---

## Detailed Findings

### Orphaned Class (Spec vs. Implementation)

**S3MO_CORS_Handler** — Listed in Phase 3 spec as `includes/class-s3mo-cors-handler.php`

- File does not exist
- CORS functionality is implemented as `S3MO_URL_Rewriter::add_cors_headers()` method, registered via `add_action('send_headers', [$this, 'add_cors_headers'], 10)` inside `S3MO_URL_Rewriter::register_hooks()`
- Nothing references `S3MO_CORS_Handler` class name anywhere in the codebase
- Runtime impact: None — CORS works correctly, just not as a separate class
- Spec impact: Phase 3 documentation is inaccurate; the class was folded into `S3MO_URL_Rewriter`

### Missing Connection: Connection Test Transient Write

**Expected:** `S3MO_Settings_Page::ajax_test_connection()` writes `s3mo_connection_status` transient so `S3MO_Admin_Notices::notice_failed_connection()` can display a persistent failure notice.

**Actual:** `ajax_test_connection()` calls `$this->client->test_connection()`, returns JSON response to browser, but never calls `set_transient('s3mo_connection_status', ...)`.

**Impact:** `notice_failed_connection()` in `S3MO_Admin_Notices` will never trigger. The transient check at line 67–70 will always find `false` (no transient set). This is a broken admin UX feature — connection failures only appear as inline feedback on the settings page, not as persistent dashboard notices.

**Location:** `admin/class-s3mo-settings-page.php`, `ajax_test_connection()` method (lines 97–115). Missing `set_transient('s3mo_connection_status', $result, HOUR_IN_SECONDS)` call after line 108.

### Orphaned Meta Key Read

**`_s3mo_error`** — Read in `S3MO_Media_Column::render_column()` at line 49 (`get_post_meta($post_id, '_s3mo_error', true)`), never written by any class in the codebase.

**Impact:** The "Error" status badge in the Media Library column is permanently unreachable. No runtime errors, but the feature is dead code.

### Key-Building Logic Duplication

`S3MO_Upload_Handler::handle_upload()` (lines 66–84) and `S3MO_Bulk_Migrator::build_file_key_list()` (lines 139–157) implement identical S3 key construction: `$prefix . '/' . $metadata['file']` for originals, `$prefix . '/' . $subdir . '/' . $size_data['file']` for thumbnails.

**Impact:** No current bugs. Maintenance risk only — a future change to key structure requires updating two locations.

### Stats Bypasses Tracker Interface

`S3MO_Stats::calculate()` queries `'_s3mo_offloaded'` and `'_s3mo_offloaded_at'` as raw string literals rather than calling `S3MO_Tracker` methods.

**Impact:** No current bugs — strings match Tracker's private constants exactly. Maintenance risk if Tracker constants change.

---

## API Coverage (AJAX Actions)

| AJAX Action | Registered In | Called From JS |
|---|---|---|
| `s3mo_test_connection` | `S3MO_Settings_Page::register_hooks()` | `admin.js` line 16 — YES |
| `s3mo_refresh_stats` | `S3MO_Settings_Page::register_hooks()` | `admin.js` line 53 — YES |

Both AJAX actions are registered and consumed. Nonce verification present in both handlers (`check_ajax_referer('s3mo_test_nonce')`). JS localizes correct nonce (`s3mo_test_nonce`) at `S3MO_Settings_Page::enqueue_assets()` line 166.

---

## Constants Usage Consistency

| Constant | Defined Where | Used By |
|---|---|---|
| `S3MO_BUCKET` | wp-config.php | S3MO_Client (construct), bootstrap check, admin notices, uninstall |
| `S3MO_REGION` | wp-config.php | S3MO_Client (construct), bootstrap check, admin notices, uninstall |
| `S3MO_KEY` | wp-config.php | S3MO_Client (construct), bootstrap check, admin notices, uninstall, settings page display |
| `S3MO_SECRET` | wp-config.php | S3MO_Client (construct), bootstrap check, admin notices, uninstall |
| `S3MO_CDN_URL` | wp-config.php (optional) | S3MO_Client::get_url_base(), S3MO_URL_Rewriter::add_cors_headers(), settings page display, admin notices |
| `S3MO_VERSION` | ct-s3-offloader.php | Settings page asset enqueue, Media Column asset enqueue |
| `S3MO_PLUGIN_DIR` | ct-s3-offloader.php | Autoloader, SDK path, uninstall |
| `S3MO_PLUGIN_URL` | ct-s3-offloader.php | Settings page CSS/JS enqueue, Media Column CSS/JS enqueue |
| `S3MO_PLUGIN_BASENAME` | ct-s3-offloader.php | Defined but not used anywhere in current codebase |

**Finding:** `S3MO_PLUGIN_BASENAME` is defined at bootstrap line 20 but never referenced. Minor orphaned constant.

---

## Options Consistency

| Option Key | Written By | Read By |
|---|---|---|
| `s3mo_path_prefix` | Activation hook, Settings page | Upload handler, Bulk migrator, URL rewriter, Settings page render |
| `s3mo_delete_local` | Activation hook, Settings page | **NOT READ by any class** |
| `s3mo_delete_s3_on_uninstall` | Activation hook, Settings page | uninstall.php |

**Finding:** `s3mo_delete_local` is registered, saved, and displayed in settings — but no code reads it to actually delete local files after S3 upload. `S3MO_Upload_Handler::handle_upload()` never checks this option. The "Delete local files after S3 upload" setting is visually present and saveable but has no effect.

---

## Final Verdict

### Blocking Issues (prevent correct runtime behavior)

1. **`s3mo_delete_local` option never read** — The "Delete local files after upload" setting is saved but ignored. Users enabling this setting will have no local files deleted. Feature gap, not a crash.

2. **`s3mo_connection_status` transient never written** — The failed connection admin notice is permanently non-functional. Minor UX gap only.

### Non-Blocking Issues (maintenance/spec accuracy)

3. **`S3MO_CORS_Handler` class missing** — Spec lists it as a separate file; it was folded into `S3MO_URL_Rewriter`. CORS works correctly. Spec needs updating.

4. **`_s3mo_error` meta key never written** — Error status in Media Column is unreachable dead code.

5. **`S3MO_PLUGIN_BASENAME` constant defined but unused.**

6. **Key-building logic duplicated** between `S3MO_Upload_Handler` and `S3MO_Bulk_Migrator`.

7. **`S3MO_Stats` hard-codes meta key strings** instead of using `S3MO_Tracker` constants.

### All E2E Flows Pass

All five primary user-facing flows (new upload, delete, bulk migration, deactivation/reactivation, uninstall) work end-to-end without breaks. The two blocking issues above are missing features, not broken pipelines.
