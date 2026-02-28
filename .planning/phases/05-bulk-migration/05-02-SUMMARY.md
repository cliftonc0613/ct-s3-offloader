# Plan 05-02 Summary: Status and Reset WP-CLI Subcommands

**Completed:** 2026-02-28
**Commits:** 88dfc32, b400708

## What Was Built

1. **Migrator query helpers** (`includes/class-s3mo-bulk-migrator.php`)
   - `get_status_counts()` — total/offloaded/pending summary with MIME filter
   - `get_all_attachment_statuses()` — per-file detail array with batch memory cleanup
   - `reset_tracking()` — clear metadata with optional S3 deletion via build_file_key_list

2. **Status subcommand** (`includes/class-s3mo-cli-command.php`)
   - Summary mode: formatted table with Metric/Count columns
   - Verbose mode: per-file table with ID/Filename/MIME/Status/S3 Key
   - Supports --mime-type filter and --format=table|csv

3. **Reset subcommand** (`includes/class-s3mo-cli-command.php`)
   - Interactive confirmation via WP_CLI::confirm (respects --yes)
   - --delete-remote flag for S3 object cleanup using build_file_key_list
   - --mime-type scoping for targeted resets
   - Result reporting with cleared/deleted/error counts
