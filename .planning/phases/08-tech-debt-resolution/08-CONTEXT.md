# Phase 8: Tech Debt Resolution - Context

**Gathered:** 2026-03-01
**Status:** Ready for planning

<domain>
## Phase Boundary

Fix all functional gaps, consolidate duplicated logic, and resolve spec mismatches identified in the v1.0 audit. Seven debt items across three categories: functional gaps (DEBT-01 through DEBT-03), code quality (DEBT-04 through DEBT-06), and spec cleanup (DEBT-07).

</domain>

<decisions>
## Implementation Decisions

### Delete local files behavior (DEBT-01)
- Delete immediately after S3 upload confirms success, within the same `wp_generate_attachment_metadata` filter call
- If S3 upload succeeds but local file deletion fails (e.g., permissions), log to `error_log` and continue silently — file stays, no harm done
- Delete ALL files (original + all thumbnails) after successful S3 upload of the entire batch
- Bulk migrator (WP-CLI `offload` command) should also respect the `s3mo_delete_local` setting

### Error handling and user feedback (DEBT-02, DEBT-03)
- Connection test AJAX handler writes `s3mo_connection_status` transient on both success AND failure, so Admin Notices always reflects current state
- Upload error postmeta `_s3mo_error` is written on ANY failure (partial or complete) — if any file in the batch fails, the error is recorded
- Error badge auto-clears on success — if attachment is later successfully offloaded (e.g., via bulk migrator), the `_s3mo_error` postmeta is removed
- Connection status transient has no expiry — persists until next connection test overwrites it

### Key-building consolidation (DEBT-04, DEBT-05, DEBT-06)
- Shared key-building method lives on `S3MO_Tracker` as a static method (consistent with existing utility pattern)
- Method reads `s3mo_path_prefix` option internally — simpler for callers, consistent with how Upload Handler currently uses it
- `S3MO_Tracker` constants change from `private const` to `public const` so `S3MO_Stats` can reference them directly
- `S3MO_PLUGIN_BASENAME` constant: verify unused via grep, then remove the `define()` line

### CORS spec mismatch and documentation cleanup (DEBT-07)
- Claude decides the minimal effective approach for documenting the CORS handler consolidation
- CLAUDE.md Known Tech Debt section: Claude decides how to update (remove resolved items vs mark as resolved)
- CLAUDE.md Development Notes: Claude scopes the updates during implementation

### Claude's Discretion
- Exact method signature for the shared `build_file_list()` on `S3MO_Tracker`
- How to structure the CLAUDE.md updates (removal vs resolved markers for tech debt items)
- Whether to add inline code comments for the CORS consolidation
- Transient key naming and value format for connection status

</decisions>

<specifics>
## Specific Ideas

- The upload handler (lines 60-84 of `class-s3mo-upload-handler.php`) and bulk migrator both build S3 keys using the same `{prefix}/{metadata['file']}` pattern — extract this exactly
- `S3MO_Tracker` already has `private const META_OFFLOADED`, `META_KEY`, `META_BUCKET`, `META_OFFLOADED_AT` — making these public is the simplest path for `S3MO_Stats` to reference them
- The connection test AJAX handler in `class-s3mo-settings-page.php` (line 97) already calls `$this->client->test_connection()` — adding `set_transient()` after the result check is straightforward

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 08-tech-debt-resolution*
*Context gathered: 2026-03-01*
