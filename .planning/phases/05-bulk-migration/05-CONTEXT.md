# Phase 5: Bulk Migration - Context

**Gathered:** 2026-02-28
**Status:** Ready for planning

<domain>
## Phase Boundary

WP-CLI commands for migrating an existing 1000+ file media library to S3 with progress tracking and fault tolerance. Three commands: offload, status, and reset. This phase does NOT include admin UI indicators (Phase 6) or any changes to the upload/delete pipeline (Phases 2/4).

</domain>

<decisions>
## Implementation Decisions

### Command structure
- WP-CLI namespace: `ct-s3` (matches plugin slug)
- Three subcommands: `wp ct-s3 offload`, `wp ct-s3 status`, `wp ct-s3 reset`
- `offload` supports flags: `--dry-run`, `--force` (re-upload already-offloaded), `--batch-size=N`, `--sleep=N`, `--mime-type`, `--limit`
- `status` shows summary counts by default; `--verbose` for per-file table; `--format=table|csv` for output format
- `reset` clears postmeta tracking by default; `--delete-remote` flag also removes S3 objects
- `reset` requires interactive y/n confirmation prompt before executing
- `--force` flag on offload re-uploads files already marked as offloaded (useful if S3 objects were manually deleted)

### Batch processing
- Default batch size: 100 files per batch
- Configurable via `--batch-size` flag (e.g., `--batch-size=25` for constrained hosts)
- Sequential file processing within each batch (one at a time)
- Optional `--sleep=N` flag to pause N seconds between batches
- Memory cleanup between batches to handle 1000+ file libraries

### Progress & output
- Per-file line output with counter: `[45/120] Uploading photo-2024.jpg... OK`
- Color-coded status using WP-CLI colors: green (success), yellow (skip), red (fail)
- Dry-run shows full WP-CLI table of files that would be uploaded (ID | Filename | Size | MIME type)
- Completion summary includes counts (success/failed/skipped) plus elapsed time and average upload speed
- Errors logged to both terminal and appending log file at `wp-content/ct-s3-migration.log`
- Log file appends across multiple runs with timestamps

### Failure & recovery
- Per-file retry: 2 retries (3 total attempts) before marking file as failed
- Failed files logged and skipped — migration continues to next file
- Resume is automatic: re-running `wp ct-s3 offload` skips already-offloaded files via tracker metadata
- Shutdown handler catches fatal errors and prints partial summary (files completed so far)

### Claude's Discretion
- Whether `status` and `reset` commands support the same `--mime-type`/`--limit` filters as offload
- Retry backoff strategy (immediate vs exponential)
- Exact log file format and timestamp style
- WP-CLI table column selection for verbose and dry-run output
- Memory cleanup implementation (wp_cache_flush, gc_collect_cycles, etc.)

</decisions>

<specifics>
## Specific Ideas

- Commands should follow standard WP-CLI conventions (progress bars, table formatting, --format flag)
- Offload command reuses existing S3MO_Upload_Handler for actual upload logic
- Tracker metadata (S3MO_Tracker) provides built-in resume capability — no separate resume state needed

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 05-bulk-migration*
*Context gathered: 2026-02-28*
