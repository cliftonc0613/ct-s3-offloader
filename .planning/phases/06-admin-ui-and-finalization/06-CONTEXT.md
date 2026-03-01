# Phase 6: Admin UI and Finalization - Context

**Gathered:** 2026-02-28
**Status:** Ready for planning

<domain>
## Phase Boundary

Admin UI enhancements (Media Library status column, settings page storage stats, admin warning notices) and plugin lifecycle cleanup (deactivation, uninstall). This phase does NOT modify the upload pipeline, URL rewriting, deletion sync, or CLI commands (Phases 2-5).

</domain>

<decisions>
## Implementation Decisions

### Media Library status column
- Column header: "Offload"
- Indicator style: colored dot + text label (green dot "S3" | yellow dot "Local" | red dot "Error")
- Column visible by default when plugin is active (no Screen Options opt-in required)
- Clicking the status indicator opens a details popup showing S3 key, bucket, and upload timestamp
- Column appears in the Media Library list view (table mode)

### Storage statistics
- Data source: local WordPress metadata (no live S3 API calls)
- Placement: dashboard section at the top of the plugin settings page, above the configuration form
- Metrics displayed:
  1. Total files on S3 (count of offloaded attachments)
  2. Total size (combined file size of offloaded media)
  3. Pending count (files not yet offloaded)
  4. Last offload timestamp (most recent upload to S3)
- Manual refresh button to recalculate stats on demand
- Stats calculated from attachment postmeta and file sizes, not from S3 bucket queries

### Admin notices and warnings
- Trigger conditions (all four active):
  1. Missing AWS credentials (wp-config.php constants not defined)
  2. Failed connection test (S3 bucket unreachable or access denied)
  3. Missing settings (bucket name, region, or CloudFront domain not configured)
  4. Recent upload failures (offload errors occurred recently)
- Placement: all admin pages (standard WordPress admin notice area)
- Style: standard WordPress notice classes (notice-warning, notice-error)
- Dismissibility: dismissible per session (can close, reappears on next page load)
- Priority: credential/settings errors take precedence over upload failure notices

### Uninstall cleanup
- Uninstall removes all local plugin data:
  1. Attachment postmeta (all `_s3mo_` prefixed meta fields)
  2. Plugin options (all `s3mo_` entries in wp_options)
  3. Transients (any cached transient data)
  4. Log file (`wp-content/ct-s3-migration.log`)
- S3 objects: plugin provides a setting/prompt asking whether to also delete S3 objects on uninstall
- Deactivation behavior: clears transients only (data preserved for reactivation)
- Full cleanup only runs on uninstall (plugin deletion), not deactivation

### Claude's Discretion
- Details popup implementation (inline expand vs modal vs tooltip)
- Stats number formatting (exact counts vs abbreviated like "1.2K")
- Empty state display when no files have been offloaded yet
- Admin notice wording and link to settings page
- How to detect "recent upload failures" (transient flag, error log check, etc.)
- S3 deletion prompt UX on uninstall (settings page checkbox vs uninstall hook prompt)
- Whether stats refresh uses AJAX or full page reload

</decisions>

<specifics>
## Specific Ideas

- The status column should use WordPress `manage_media_columns` and `manage_media_custom_column` hooks
- Admin notices use `admin_notices` hook with `is-dismissible` CSS class
- Uninstall cleanup goes in `uninstall.php` (already exists as skeleton)
- Storage stats can reuse `S3MO_Bulk_Migrator::get_status_counts()` for file counts
- Deactivation hook: `register_deactivation_hook()` to clear transients

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 06-admin-ui-and-finalization*
*Context gathered: 2026-02-28*
