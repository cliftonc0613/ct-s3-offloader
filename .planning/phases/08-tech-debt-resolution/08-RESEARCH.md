# Phase 8: Tech Debt Resolution - Research

**Researched:** 2026-03-01
**Domain:** Internal refactoring of existing WordPress plugin PHP code
**Confidence:** HIGH

## Summary

This phase resolves 7 tech debt items identified in the v1.0 milestone audit. All changes are internal to the plugin -- no new external dependencies, no new WordPress APIs, no new AWS SDK calls. The research focused on reading the actual source files to map exact insertion points, dependency chains between debt items, and side effects.

The key finding is that DEBT-04 (shared key-building) must be implemented FIRST because DEBT-01 (delete local) and DEBT-05 (Stats constants) both depend on the refactored `S3MO_Tracker`. DEBT-02 and DEBT-03 are independent of each other and of the other items. DEBT-06 and DEBT-07 are trivial standalone changes.

**Primary recommendation:** Implement in order: DEBT-04 first, then DEBT-05, then DEBT-01, then DEBT-02/DEBT-03/DEBT-06/DEBT-07 in any order. This avoids merge conflicts and ensures the shared method exists before consumers are modified.

## Standard Stack

No new libraries required. All changes use existing WordPress and PHP APIs already in the codebase.

### Core APIs Used
| API | Current Usage | New Usage in This Phase |
|-----|--------------|------------------------|
| `get_option()` | Upload Handler line 62, Bulk Migrator line 135 | Tracker's new `build_file_list()` reads prefix internally |
| `wp_get_upload_dir()` | Upload Handler line 61, Bulk Migrator line 134 | Tracker's new `build_file_list()` calls internally |
| `update_post_meta()` | Tracker line 43-46 | DEBT-03: write `_s3mo_error` |
| `delete_post_meta()` | Tracker line 96-99 | DEBT-03: clear `_s3mo_error` on success |
| `set_transient()` | Stats line 100, 114 | DEBT-02: write `s3mo_connection_status` |
| `@unlink()` / `wp_delete_file()` | Not currently used | DEBT-01: delete local files |

## Architecture Patterns

### Current Dependency Graph (relevant classes)

```
ct-s3-offloader.php (bootstrap)
  |-> S3MO_Client (constructed once)
  |-> S3MO_Upload_Handler (receives $client)
  |-> S3MO_Bulk_Migrator (receives $client)
  |-> S3MO_URL_Rewriter (receives $client)
  |-> S3MO_Delete_Handler (receives $client)
  |-> S3MO_Settings_Page (receives $client)
  |-> S3MO_Admin_Notices (no dependencies)
  |-> S3MO_Media_Column (no dependencies)

Static utilities (called directly, not injected):
  S3MO_Tracker -- postmeta read/write
  S3MO_Stats   -- aggregate stats with caching
```

### Pattern: Static Utility Classes
`S3MO_Tracker` and `S3MO_Stats` are pure static classes. The new `build_file_list()` method follows this pattern -- static, self-contained, reads its own dependencies via `get_option()` and `wp_get_upload_dir()`.

### Anti-Patterns to Avoid
- **Do NOT inject dependencies into Tracker:** It is a static utility class. Adding constructor injection would break every callsite across the codebase.
- **Do NOT change Tracker to instance-based:** Would require modifying bootstrap and every consumer.

## Detailed Debt Item Analysis

### DEBT-01: `s3mo_delete_local` Option Functional

**Current state:**
- Option is registered and saved in Settings Page (line 59-63)
- Checkbox renders in admin UI (lines 299-304)
- Activation hook sets default `false` (bootstrap line 131)
- Uninstall cleans it up (uninstall.php line 110)
- **NEVER READ by any upload code** -- complete no-op

**Files to modify:**
1. `includes/class-s3mo-upload-handler.php` -- After successful offload (line 112-114), read option and delete local files
2. `includes/class-s3mo-bulk-migrator.php` -- After successful offload in `upload_attachment()` (line 208-215), read option and delete local files

**Insertion points (Upload Handler):**
- After line 114 (`S3MO_Tracker::mark_as_offloaded(...)`) and before the `elseif` on line 115
- Read `get_option('s3mo_delete_local', false)`
- If true, iterate `$files` array (already built at line 64) and `@unlink()` each `$file['local']`
- Log failures via `error_log()` and continue silently

**Insertion points (Bulk Migrator):**
- After line 213 (`S3MO_Tracker::mark_as_offloaded(...)`) and before `return` on line 215
- Same logic: read option, iterate `$files` (already available from line 177), `@unlink()` each
- Log failures, continue silently

**Local file deletion approach:**
- Use `@unlink($file['local'])` for each file in the `$files` array
- The `@` suppressor is standard WordPress pattern for file operations where failure is non-critical
- Alternative: `wp_delete_file()` which is a wrapper around `unlink()` with a filter hook -- slightly more WordPress-idiomatic but adds overhead for no benefit here
- Decision: Use `@unlink()` for simplicity, matching WordPress core patterns like `wp_delete_attachment_files()`

**Risk assessment:** MEDIUM
- Deleting local files is destructive and irreversible
- Mitigations: Only fires when option is explicitly enabled (default false), only after confirmed S3 upload success, and only for the specific files that were uploaded
- Edge case: If the upload handler fires but S3 upload was partial (some thumbnails failed), we do NOT delete because we only delete on `$success_count === $total`
- Edge case: Bulk migrator has retry logic -- deletion should only happen after final successful upload, not on each retry attempt

**Dependencies:** Benefits from DEBT-04 (shared `build_file_list`) but can be implemented independently since both Upload Handler and Bulk Migrator already have their own `$files` arrays built.

---

### DEBT-02: `s3mo_connection_status` Transient Written

**Current state:**
- `S3MO_Admin_Notices::notice_failed_connection()` reads `s3mo_connection_status` transient (admin-notices line 67)
- Checks if transient exists AND `$status['success']` is empty (line 70)
- Shows error notice if failure detected
- `S3MO_Settings_Page::ajax_test_connection()` calls `$this->client->test_connection()` (line 108) but NEVER writes transient
- Deactivation hook deletes the transient (bootstrap line 136)
- Uninstall deletes the transient (uninstall.php line 116)

**Files to modify:**
1. `admin/class-s3mo-settings-page.php` -- Add `set_transient()` call in `ajax_test_connection()` after getting result

**Insertion point:**
- After line 108 (`$result = $this->client->test_connection();`), before the if/else on line 110
- Add: `set_transient('s3mo_connection_status', $result);` (no expiry per CONTEXT decision)

**Exact code change:**
```php
// Line 108 (existing):
$result = $this->client->test_connection();

// NEW LINE after 108:
set_transient('s3mo_connection_status', $result);

// Lines 110-114 (existing, unchanged):
if ($result['success']) {
    wp_send_json_success($result);
} else {
    wp_send_json_error($result);
}
```

**Compatibility with Admin Notices reader:**
- `$result` from `test_connection()` returns `['success' => true/false, 'message' => '...']`
- `notice_failed_connection()` checks `$status === false` (transient not set) OR `!empty($status['success'])` (success)
- On success: `$status['success']` is `true`, so `!empty($status['success'])` is true, notice skipped -- CORRECT
- On failure: `$status['success']` is `false`, so `!empty($status['success'])` is false, notice shows -- CORRECT
- Transient not set: `$status === false`, first condition catches it, notice skipped -- CORRECT

**Risk assessment:** LOW
- Single line addition
- No expiry means transient persists until next test or deactivation -- intentional per CONTEXT
- Admin notices already handle all three states correctly

**Dependencies:** None. Fully independent.

---

### DEBT-03: `_s3mo_error` Postmeta Written on Upload Failures

**Current state:**
- `S3MO_Media_Column::render_column()` reads `_s3mo_error` postmeta (media-column line 49)
- If non-empty, renders red "Error" badge (line 51-52, calls `render_error_status()`)
- **No code anywhere writes `_s3mo_error`** -- error badge is unreachable dead code
- `S3MO_Tracker::clear_offload_status()` does NOT clear `_s3mo_error` (lines 95-100, only clears 4 standard keys)
- `uninstall.php` does NOT clean up `_s3mo_error` (lines 103-106, only deletes 4 standard keys)

**Files to modify:**
1. `includes/class-s3mo-upload-handler.php` -- Write `_s3mo_error` on partial/complete failure, clear on full success
2. `includes/class-s3mo-bulk-migrator.php` -- Write `_s3mo_error` on failure in `upload_attachment()`, clear on success
3. `includes/class-s3mo-tracker.php` -- Add `META_ERROR` constant, add methods to set/clear error, update `clear_offload_status()` to also clear error
4. `uninstall.php` -- Add `delete_post_meta_by_key('_s3mo_error')` to cleanup

**Tracker additions:**
```php
// New constant (public, for consistency with DEBT-05):
public const META_ERROR = '_s3mo_error';

// New static methods:
public static function set_error(int $attachment_id, string $message): void {
    update_post_meta($attachment_id, self::META_ERROR, $message);
}

public static function clear_error(int $attachment_id): void {
    delete_post_meta($attachment_id, self::META_ERROR);
}
```

**Upload Handler insertion points:**
- Line 114 (full success branch): Add `S3MO_Tracker::clear_error($attachment_id);` -- auto-clear on retry success
- Line 117-121 (partial failure branch): Add `S3MO_Tracker::set_error($attachment_id, 'Partial upload: ' . $success_count . '/' . $total . ' files');`
- Line 123-127 (complete failure branch): Add `S3MO_Tracker::set_error($attachment_id, implode('; ', $errors));`

**Bulk Migrator insertion points:**
- Line 213 (success in `upload_attachment()`): Add `S3MO_Tracker::clear_error($attachment_id);` after `mark_as_offloaded()`
- Line 224 (failure return): Add `S3MO_Tracker::set_error($attachment_id, $last_error);` before `return`

**Media Column -- already works:**
- Line 49 reads `_s3mo_error` and line 51-52 checks if non-empty -- no changes needed

**Tracker `clear_offload_status()` update:**
- Add `delete_post_meta($attachment_id, self::META_ERROR);` to line 100 area

**Uninstall update:**
- Add `delete_post_meta_by_key('_s3mo_error');` after line 106

**Risk assessment:** LOW
- Writing postmeta is safe, non-destructive
- Auto-clear on success ensures errors don't persist after retry
- Error message stored is informational only -- displayed in Media Library column

**Dependencies:** Should be done after DEBT-04/DEBT-05 (which change Tracker constants to public), but can be done independently since it adds a NEW constant.

---

### DEBT-04: Shared Key-Building Method on `S3MO_Tracker`

**Current state -- duplication identified:**

**Upload Handler (lines 60-84):**
```php
$upload_dir = wp_get_upload_dir();
$prefix     = get_option('s3mo_path_prefix', 'wp-content/uploads');
$files      = [];
$files[] = [
    'local' => $upload_dir['basedir'] . '/' . $metadata['file'],
    'key'   => $prefix . '/' . $metadata['file'],
    'mime'  => $mime,
];
if (! empty($metadata['sizes']) && is_array($metadata['sizes'])) {
    $subdir = dirname($metadata['file']);
    foreach ($metadata['sizes'] as $size_data) {
        $files[] = [
            'local' => $upload_dir['basedir'] . '/' . $subdir . '/' . $size_data['file'],
            'key'   => $prefix . '/' . $subdir . '/' . $size_data['file'],
            'mime'  => $size_data['mime-type'],
        ];
    }
}
```

**Bulk Migrator `build_file_key_list()` (lines 127-157):**
```php
$upload_dir = wp_get_upload_dir();
$prefix     = get_option('s3mo_path_prefix', 'wp-content/uploads');
$files      = [];
$files[] = [
    'local' => $upload_dir['basedir'] . '/' . $metadata['file'],
    'key'   => $prefix . '/' . $metadata['file'],
];
if (! empty($metadata['sizes']) && is_array($metadata['sizes'])) {
    $subdir = dirname($metadata['file']);
    foreach ($metadata['sizes'] as $size_data) {
        $files[] = [
            'local' => $upload_dir['basedir'] . '/' . $subdir . '/' . $size_data['file'],
            'key'   => $prefix . '/' . $subdir . '/' . $size_data['file'],
        ];
    }
}
```

**Differences between the two:**
1. Upload Handler includes `'mime'` key in each entry; Bulk Migrator does not
2. Upload Handler gets `$metadata` from function parameter; Bulk Migrator calls `wp_get_attachment_metadata()`
3. Upload Handler gets MIME from `get_post_mime_type()`; Bulk Migrator uses `mime_content_type()` at upload time

**Recommended shared method signature:**
```php
/**
 * Build list of local file paths and S3 keys for an attachment.
 *
 * @param array $metadata Attachment metadata (from wp_get_attachment_metadata).
 * @return array<int, array{local: string, key: string}>
 */
public static function build_file_list(array $metadata): array
```

**Design decisions:**
- Takes `$metadata` as parameter (Upload Handler already has it; Bulk Migrator calls `wp_get_attachment_metadata()` before calling)
- Does NOT include MIME type -- callers that need MIME add it themselves (Upload Handler adds from `get_post_mime_type()`, Bulk Migrator uses `mime_content_type()`)
- Returns `['local' => ..., 'key' => ...]` array per file -- minimal shared structure
- Reads `s3mo_path_prefix` and `wp_get_upload_dir()` internally

**Files to modify:**
1. `includes/class-s3mo-tracker.php` -- Add `build_file_list()` static method
2. `includes/class-s3mo-upload-handler.php` -- Replace lines 60-84 with call to `S3MO_Tracker::build_file_list()`
3. `includes/class-s3mo-bulk-migrator.php` -- Replace `build_file_key_list()` body (lines 128-156) with call to `S3MO_Tracker::build_file_list()`

**Upload Handler refactored flow:**
```php
$mime  = get_post_mime_type($attachment_id);
$files = S3MO_Tracker::build_file_list($metadata);

if (empty($files)) {
    return $metadata;
}

// Add MIME type for upload (original gets attachment MIME, thumbs get size MIME)
$files[0]['mime'] = $mime;
if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
    $i = 1;
    foreach ($metadata['sizes'] as $size_data) {
        $files[$i]['mime'] = $size_data['mime-type'];
        $i++;
    }
}
```

Actually, the simpler approach: the shared method returns `local` and `key`. The Upload Handler adds MIME to each entry before uploading. This keeps the shared method clean.

**Bulk Migrator refactored flow:**
```php
public function build_file_key_list(int $attachment_id): array {
    $metadata = wp_get_attachment_metadata($attachment_id);
    if (empty($metadata['file'])) {
        return [];
    }
    return S3MO_Tracker::build_file_list($metadata);
}
```

The existing public method `build_file_key_list()` is called by `upload_attachment()`, `reset_tracking()`, and `get_all_attachment_statuses()` -- so keep it as a thin wrapper.

**Risk assessment:** MEDIUM
- Changing key-building logic affects all upload paths
- Mitigated by: identical logic, just extracted -- no behavioral change
- Must verify return format matches all consumers

**Dependencies:** None. This should be done FIRST since DEBT-01 (delete local) benefits from the shared `$files` array.

---

### DEBT-05: `S3MO_Stats` Uses Tracker Constants

**Current state:**
- `S3MO_Stats::calculate()` hard-codes meta key strings:
  - Line 37: `'_s3mo_offloaded'` (hard-coded)
  - Line 38: `'1'` (hard-coded value)
  - Line 76: `'_s3mo_offloaded_at'` (hard-coded in raw SQL)
- `S3MO_Tracker` has these as `private const`:
  - `META_OFFLOADED = '_s3mo_offloaded'` (line 21)
  - `META_OFFLOADED_AT = '_s3mo_offloaded_at'` (line 30)

**Required changes:**
1. `S3MO_Tracker`: Change `private const` to `public const` for all 4 meta key constants (lines 21, 24, 27, 30)
2. `S3MO_Stats::calculate()`:
   - Line 37: Replace `'_s3mo_offloaded'` with `S3MO_Tracker::META_OFFLOADED`
   - Line 38: Replace `'1'` with... actually this is a value, not a key. Keep as `'1'`.
   - Line 76: Replace `'_s3mo_offloaded_at'` in SQL with `S3MO_Tracker::META_OFFLOADED_AT`

**Also update hard-coded keys in other files:**
- `S3MO_Bulk_Migrator` lines 50-51, 100-101, 268-270, 366-368: Hard-codes `'_s3mo_offloaded'` and `'1'` in meta queries
- These should also reference `S3MO_Tracker::META_OFFLOADED` for consistency

**Uninstall.php** (lines 47, 64, 103-106): Hard-codes all meta key strings. However, uninstall.php runs without the plugin autoloader, so it CANNOT reference `S3MO_Tracker` constants. These must remain hard-coded. This is standard WordPress pattern -- uninstall files are standalone.

**Risk assessment:** LOW
- Changing `private const` to `public const` is backward-compatible
- Replacing string literals with constant references is a safe refactor
- No behavioral change

**Dependencies:** Must be done after or alongside DEBT-04 (both modify `S3MO_Tracker`).

---

### DEBT-06: Remove Unused `S3MO_PLUGIN_BASENAME`

**Current state:**
- Defined at `ct-s3-offloader.php` line 21: `define('S3MO_PLUGIN_BASENAME', plugin_basename(__FILE__));`
- Grep confirms: **only defined, never referenced anywhere else in the codebase**
- Not used in `register_activation_hook()` or `register_deactivation_hook()` (those use `__FILE__` directly)
- Not used for `plugin_action_links_` filter (no settings link in plugin list)

**Files to modify:**
1. `ct-s3-offloader.php` -- Remove line 21

**Risk assessment:** VERY LOW
- Removing an unused constant definition has zero side effects
- No external code could depend on it (plugin is not a library)

**Dependencies:** None. Fully independent.

---

### DEBT-07: CORS Handler Spec Mismatch

**Current state:**
- `S3MO_URL_Rewriter::add_cors_headers()` (url-rewriter lines 294-324) sends CORS headers on REST API requests
- Registered via `add_action('send_headers', [$this, 'add_cors_headers'])` (line 46)
- Hard-codes localhost origins: `http://localhost:3000`, `http://localhost:3001` (lines 308-309)
- This is an S3 offloader plugin -- CORS for a headless Next.js frontend is outside its scope
- CLAUDE.md does not mention CORS as a feature, but the code has detailed docblocks explaining it

**CONTEXT.md says:** "Claude decides the minimal effective approach for documenting the CORS handler consolidation"

**Options:**
1. **Remove the CORS handler entirely** -- it's outside the plugin's scope (S3 offloading, not headless frontend support)
2. **Keep but document** -- add a note in CLAUDE.md that it exists and why
3. **Make configurable** -- add a filter hook for allowed origins

**Recommendation:** Add a brief note in CLAUDE.md's architecture section documenting the CORS handler's existence and purpose. Do NOT remove it -- it may be actively needed for the site's headless setup. Do NOT add configuration UI -- that's scope creep. Simply acknowledge it exists and is intentional. Remove it from "Known Tech Debt" since it's a deliberate feature, not a bug.

**Files to modify:**
1. `CLAUDE.md` -- Update Known Tech Debt section (remove resolved items), add note about CORS handler in architecture section

**Risk assessment:** VERY LOW
- Documentation-only change
- No code modifications

**Dependencies:** None. Should be done last since CLAUDE.md updates should reflect all other resolved debt items.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| File deletion | Custom recursive delete | `@unlink()` per file | WordPress core pattern, files are already enumerated |
| Transient management | Custom cache layer | `set_transient()` / `get_transient()` | Already used throughout plugin |
| Meta key management | String constants in each class | `S3MO_Tracker::META_*` public constants | Single source of truth, already exists as private |

## Common Pitfalls

### Pitfall 1: Deleting Local Files Before S3 Upload Confirmed
**What goes wrong:** Files deleted but S3 upload was partial -- media permanently lost
**Why it happens:** Checking `$result['success']` per file instead of waiting for full batch
**How to avoid:** Only delete local files inside the `$success_count === $total` branch (Upload Handler line 112). Bulk Migrator's `upload_attachment()` already returns only after all retries, so check `$result['status'] === 'success'`.
**Warning signs:** Any file deletion code outside the full-success conditional

### Pitfall 2: Forgetting to Clear Error on Retry Success
**What goes wrong:** Attachment shows permanent "Error" badge even after successful re-upload
**Why it happens:** Error meta written on failure but never cleared on subsequent success
**How to avoid:** Always call `S3MO_Tracker::clear_error()` in the success path of BOTH Upload Handler and Bulk Migrator
**Warning signs:** `_s3mo_error` delete/clear not present in success branches

### Pitfall 3: Uninstall.php Cannot Use Plugin Classes
**What goes wrong:** Fatal error during uninstall because autoloader isn't loaded
**Why it happens:** `uninstall.php` runs standalone without the plugin bootstrap
**How to avoid:** Keep hard-coded meta key strings in `uninstall.php` -- do NOT replace with `S3MO_Tracker::META_*` constants
**Warning signs:** Any `S3MO_*` class reference in uninstall.php (except the existing AWS SDK usage which loads its own autoloader)

### Pitfall 4: Changing Tracker Constants Breaks Subclasses
**What goes wrong:** N/A -- no subclasses exist
**Why it happens:** Changing `private` to `public` visibility
**How to avoid:** This is safe in this codebase -- `S3MO_Tracker` is never extended
**Warning signs:** None -- verified via grep that no class extends `S3MO_Tracker`

### Pitfall 5: MIME Type Handling in Shared Method
**What goes wrong:** Upload fails because MIME type is wrong or missing
**Why it happens:** Upload Handler uses `get_post_mime_type()` for original + `$size_data['mime-type']` for thumbs; Bulk Migrator uses `mime_content_type()` at upload time
**How to avoid:** Do NOT include MIME in the shared `build_file_list()` return. Let each caller add MIME their own way.
**Warning signs:** MIME type appearing in the shared method return signature

## Code Examples

### DEBT-01: Delete Local Files After Upload
```php
// In S3MO_Upload_Handler::handle_upload(), after mark_as_offloaded:
if ($success_count === $total) {
    S3MO_Tracker::mark_as_offloaded($attachment_id, $files[0]['key'], $this->client->get_bucket());
    S3MO_Tracker::clear_error($attachment_id); // DEBT-03

    // DEBT-01: Delete local files if setting enabled.
    if (get_option('s3mo_delete_local', false)) {
        foreach ($files as $file) {
            if (file_exists($file['local'])) {
                if (!@unlink($file['local'])) {
                    error_log('CT S3 Offloader: Failed to delete local file: ' . $file['local']);
                }
            }
        }
    }
}
```

### DEBT-02: Write Connection Status Transient
```php
// In S3MO_Settings_Page::ajax_test_connection():
$result = $this->client->test_connection();
set_transient('s3mo_connection_status', $result); // No expiry

if ($result['success']) {
    wp_send_json_success($result);
} else {
    wp_send_json_error($result);
}
```

### DEBT-04: Shared Key-Building on Tracker
```php
// New method on S3MO_Tracker:
/**
 * Build list of local file paths and S3 keys for attachment files.
 *
 * @param array $metadata Attachment metadata from wp_get_attachment_metadata().
 * @return array<int, array{local: string, key: string}> File list, empty if no file in metadata.
 */
public static function build_file_list(array $metadata): array {
    if (empty($metadata['file'])) {
        return [];
    }

    $upload_dir = wp_get_upload_dir();
    $prefix     = get_option('s3mo_path_prefix', 'wp-content/uploads');
    $files      = [];

    // Original file.
    $files[] = [
        'local' => $upload_dir['basedir'] . '/' . $metadata['file'],
        'key'   => $prefix . '/' . $metadata['file'],
    ];

    // Thumbnails.
    if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
        $subdir = dirname($metadata['file']);

        foreach ($metadata['sizes'] as $size_data) {
            $files[] = [
                'local' => $upload_dir['basedir'] . '/' . $subdir . '/' . $size_data['file'],
                'key'   => $prefix . '/' . $subdir . '/' . $size_data['file'],
            ];
        }
    }

    return $files;
}
```

### DEBT-05: Stats Using Tracker Constants
```php
// In S3MO_Stats::calculate(), replace hard-coded strings:
$offloaded_query = new WP_Query([
    'post_type'      => 'attachment',
    'post_status'    => 'inherit',
    'meta_query'     => [
        [
            'key'   => S3MO_Tracker::META_OFFLOADED,
            'value' => '1',
        ],
    ],
    // ...
]);

// Raw SQL query:
$last_offloaded = (string) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta}
         WHERE meta_key = %s
         ORDER BY meta_value DESC LIMIT 1",
        S3MO_Tracker::META_OFFLOADED_AT
    )
);
```

## Dependency Map and Execution Order

```
DEBT-04 (shared key-building) ──> DEBT-05 (Stats constants)
     |                                  |
     |   (both modify S3MO_Tracker)     |
     v                                  v
DEBT-01 (delete local)          [independent]
DEBT-03 (error postmeta)       [independent after Tracker updated]
DEBT-02 (connection transient) [fully independent]
DEBT-06 (remove constant)     [fully independent]
DEBT-07 (CORS docs)           [do last -- CLAUDE.md cleanup]
```

**Recommended implementation order:**
1. DEBT-04 + DEBT-05 (both touch Tracker, do together)
2. DEBT-03 (adds error tracking to Tracker, Upload Handler, Bulk Migrator)
3. DEBT-01 (adds delete-local to Upload Handler, Bulk Migrator)
4. DEBT-02 (Settings Page only)
5. DEBT-06 (bootstrap only)
6. DEBT-07 (documentation only)

## Additional Touchpoints Discovered

### Uninstall.php Updates Needed
- Add `delete_post_meta_by_key('_s3mo_error');` after line 106 (for DEBT-03)
- Do NOT use Tracker constants here (standalone file)

### Bulk Migrator Meta Query Hard-Coding
- Lines 50-51, 100-101, 268-270, 366-368 all hard-code `'_s3mo_offloaded'`
- Should reference `S3MO_Tracker::META_OFFLOADED` after DEBT-05 makes it public
- This is a scope expansion beyond the original DEBT-05 description but is consistent with its intent

### Tracker `clear_offload_status()` Gap
- Currently clears 4 meta keys (lines 96-99)
- Must also clear `_s3mo_error` (DEBT-03) -- otherwise clearing offload status leaves orphaned error meta

## Open Questions

1. **Should `build_file_list()` metadata parameter include the `file` key guard internally?**
   - Current: Bulk Migrator checks `empty($metadata['file'])` before calling
   - Recommendation: Yes, guard internally for defensive programming. Return empty array if `$metadata['file']` is empty. This matches the current behavior in both callers.

2. **Should Bulk Migrator's `build_file_key_list()` method be deprecated or kept?**
   - It's a public method potentially used by CLI or other code
   - Recommendation: Keep as thin wrapper calling `S3MO_Tracker::build_file_list()`. This maintains backward compatibility.

3. **Should `S3MO_Stats` raw SQL query use `$wpdb->prepare()`?**
   - Currently line 73-77 embeds `_s3mo_offloaded_at` directly in SQL string
   - When using constant, it's still a string literal, but `$wpdb->prepare()` is best practice
   - Recommendation: Yes, wrap in `$wpdb->prepare()` for consistency

## Sources

### Primary (HIGH confidence)
- Direct source code analysis of all 10 files listed in the phase context
- Line-by-line reading of current implementations with exact line numbers
- Cross-referencing grep results for all meta keys, option names, and constants

### Secondary (MEDIUM confidence)
- `.planning/milestones/v1.0-INTEGRATION-CHECK.md` -- confirmed all 7 debt items
- `.planning/milestones/v1.0-MILESTONE-AUDIT.md` -- original debt identification

## Metadata

**Confidence breakdown:**
- DEBT-01 (delete local): HIGH -- exact insertion points identified, all edge cases mapped
- DEBT-02 (connection transient): HIGH -- single line addition, verified reader compatibility
- DEBT-03 (error postmeta): HIGH -- all touchpoints identified including uninstall cleanup
- DEBT-04 (shared key-building): HIGH -- both implementations read and diffed line by line
- DEBT-05 (Stats constants): HIGH -- all hard-coded strings identified, uninstall exception noted
- DEBT-06 (remove constant): HIGH -- grep confirms zero references
- DEBT-07 (CORS docs): HIGH -- code read, scope understood, documentation-only change

**Research date:** 2026-03-01
**Valid until:** No expiry -- this is internal refactoring research against a stable codebase
