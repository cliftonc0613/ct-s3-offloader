# CT S3 Offloader — WP-CLI Guide

Manage your WordPress media library on Amazon S3 from the command line.

The CT S3 Offloader plugin registers the `wp ct-s3` command namespace with three subcommands: **offload**, **status**, and **reset**.

## Prerequisites

- [WP-CLI](https://wp-cli.org/) installed and working (`wp --info`)
- CT S3 Offloader plugin activated
- AWS credentials configured in `wp-config.php`:

```php
define('S3MO_AWS_ACCESS_KEY_ID', 'your-access-key');
define('S3MO_AWS_SECRET_ACCESS_KEY', 'your-secret-key');
```

- S3 bucket, region, and CloudFront domain configured on the plugin settings page

## Commands

### wp ct-s3 offload

Upload media library files to S3. This is the main migration command.

```bash
wp ct-s3 offload
```

The command finds all media attachments that haven't been offloaded yet and uploads them one at a time, showing progress as it goes:

```
Found 245 attachment(s) to process.
[1/245] Uploading hero-banner.jpg... OK (4 file(s))
[2/245] Uploading team-photo.png... OK (4 file(s))
[3/245] Uploading podcast-ep12.mp3... OK (1 file(s))
...

--- Migration Summary ---
  Success: 243
  Failed:  1
  Skipped: 1
  Elapsed: 4m 12s
```

Each attachment includes the original file plus all generated thumbnail sizes. The `(4 file(s))` count shows how many individual files were uploaded for that attachment.

#### Options

| Flag | Description | Default |
|------|-------------|---------|
| `--dry-run` | Preview what would be uploaded without uploading anything | — |
| `--force` | Re-upload files already marked as offloaded | — |
| `--batch-size=<n>` | Number of attachments per processing batch | 50 |
| `--sleep=<n>` | Seconds to pause between batches | 0 |
| `--mime-type=<type>` | Only process files of this MIME type | all |
| `--limit=<n>` | Maximum number of attachments to process | unlimited |

#### Preview before uploading

Always run a dry run first to see what will be uploaded:

```bash
wp ct-s3 offload --dry-run
```

Output:

```
Found 245 attachment(s) to process.

Dry run — no files will be uploaded.

+-----+---------------------+------------+--------+-------+
| ID  | Filename            | MIME       | Size   | Files |
+-----+---------------------+------------+--------+-------+
| 42  | hero-banner.jpg     | image/jpeg | 2.1 MB | 4     |
| 43  | team-photo.png      | image/png  | 856 KB | 4     |
| 44  | podcast-ep12.mp3    | audio/mpeg | 18 MB  | 1     |
+-----+---------------------+------------+--------+-------+

Total: 245 attachment(s) would be uploaded.
```

#### Upload only specific file types

```bash
# Only images
wp ct-s3 offload --mime-type=image

# Only JPEGs
wp ct-s3 offload --mime-type=image/jpeg

# Only PDFs
wp ct-s3 offload --mime-type=application/pdf
```

#### Control batch size and pacing

For shared hosting or servers with limited memory, reduce batch size and add sleep between batches:

```bash
wp ct-s3 offload --batch-size=10 --sleep=2
```

This processes 10 attachments at a time with a 2-second pause between batches. Memory is automatically cleaned up between each batch.

#### Upload a limited number of files

Test with a small set before running the full migration:

```bash
wp ct-s3 offload --limit=10
```

#### Force re-upload

If S3 objects were manually deleted or you need to re-upload everything:

```bash
wp ct-s3 offload --force
```

This ignores the offloaded status and uploads all attachments again.

### wp ct-s3 status

Check the current offload status of your media library.

```bash
wp ct-s3 status
```

Output:

```
+-----------+-------+
| Metric    | Count |
+-----------+-------+
| Total     | 245   |
| Offloaded | 243   |
| Pending   | 2     |
+-----------+-------+
```

#### Detailed per-file view

See the status of every attachment:

```bash
wp ct-s3 status --verbose
```

Output:

```
+-----+---------------------+------------+-----------+------------------------------------------+
| ID  | Filename            | MIME       | Status    | S3 Key                                   |
+-----+---------------------+------------+-----------+------------------------------------------+
| 42  | hero-banner.jpg     | image/jpeg | offloaded | wp-content/uploads/2026/02/hero-banner... |
| 43  | team-photo.png      | image/png  | offloaded | wp-content/uploads/2026/02/team-photo.... |
| 44  | podcast-ep12.mp3    | audio/mpeg | pending   |                                          |
+-----+---------------------+------------+-----------+------------------------------------------+
```

#### Filter by MIME type

```bash
wp ct-s3 status --mime-type=image/jpeg
wp ct-s3 status --verbose --mime-type=image/jpeg
```

#### Export as CSV

```bash
wp ct-s3 status --verbose --format=csv > media-status.csv
```

### wp ct-s3 reset

Clear offload tracking metadata. This does not delete your local files. Use this when you need to start the migration over.

```bash
wp ct-s3 reset
```

You'll be prompted to confirm:

```
This will clear offload tracking for 243 attachment(s).
Are you sure you want to proceed? [y/n]
```

After reset, running `wp ct-s3 offload` will re-upload all files.

#### Also delete S3 objects

To clear tracking metadata **and** delete all files from S3:

```bash
wp ct-s3 reset --delete-remote
```

```
This will clear offload tracking for 243 attachment(s). S3 objects will also be DELETED.
Are you sure you want to proceed? [y/n]
```

This removes the original file and all thumbnail sizes from S3 for each attachment.

#### Skip confirmation prompt

For scripting or automation:

```bash
wp ct-s3 reset --yes
wp ct-s3 reset --delete-remote --yes
```

#### Reset only specific file types

```bash
wp ct-s3 reset --mime-type=image/jpeg
wp ct-s3 reset --mime-type=image/jpeg --delete-remote --yes
```

## Common Workflows

### First-time migration

```bash
# 1. Check how many files need uploading
wp ct-s3 status

# 2. Preview what will be uploaded
wp ct-s3 offload --dry-run

# 3. Test with a small batch
wp ct-s3 offload --limit=5

# 4. Verify those uploaded correctly
wp ct-s3 status

# 5. Run the full migration
wp ct-s3 offload
```

### Resume after interruption

If the migration is interrupted (server timeout, network error, Ctrl+C), just run the command again. It automatically skips files that were already uploaded:

```bash
wp ct-s3 offload
```

### Re-migrate everything

```bash
# Clear tracking and re-upload
wp ct-s3 reset --yes
wp ct-s3 offload
```

### Complete teardown

Remove all S3 objects and tracking data:

```bash
wp ct-s3 reset --delete-remote --yes
```

### Export a migration report

```bash
wp ct-s3 status --verbose --format=csv > media-report.csv
```

## Error Handling

### Failed uploads

Failed uploads are retried twice with increasing wait times (1 second, then 2 seconds) before being marked as failed. The migration continues to the next file.

Failed files are logged to `wp-content/ct-s3-migration.log` with timestamps:

```
[2026-02-28 15:42:11] FAILED attachment 44 (podcast-ep12.mp3): Upload timed out
```

After a migration with failures, re-run the command to retry only the failed files (successfully uploaded files are skipped automatically).

### Fatal errors

If PHP runs out of memory or hits a fatal error, the shutdown handler logs the error and prints what was completed so far. Reduce `--batch-size` and try again:

```bash
wp ct-s3 offload --batch-size=10
```

### Missing local files

If a local file has been deleted but the attachment record still exists in WordPress, the offload command skips it with a "File not found" message.

## Tips

- **Start small.** Use `--limit=10` or `--dry-run` before running a full migration.
- **Use --batch-size on shared hosting.** Lower values (10-25) reduce memory usage and avoid timeouts.
- **Add --sleep on rate-limited connections.** A 1-2 second pause between batches prevents throttling.
- **Check status often.** Run `wp ct-s3 status` to track progress during long migrations.
- **Safe to re-run.** The offload command is idempotent. Running it again only processes files that haven't been uploaded yet.
- **Log file persists.** The log at `wp-content/ct-s3-migration.log` appends across multiple runs so you have a full history.
