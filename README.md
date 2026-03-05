# CT S3 Offloader

Offload your WordPress media library to Amazon S3 and serve files through CloudFront CDN. New uploads go to S3 automatically, existing media can be bulk-migrated via WP-CLI, and all URLs are rewritten at runtime — no database changes needed.

## What It Does

- **Automatic uploads** — Every new media upload is pushed to S3 (original file + all thumbnail sizes) immediately after WordPress generates the attachment metadata.
- **CDN URL rewriting** — Local `wp-content/uploads/` URLs are rewritten to your CloudFront domain at render time. This happens in post content, attachment URLs, responsive `srcset` attributes, REST API responses, and the admin Media Library modal. Your database keeps the original local URLs.
- **Deletion sync** — When you delete a media item from WordPress, the corresponding S3 objects (original + thumbnails) are deleted too.
- **Bulk migration** — A full WP-CLI toolset lets you migrate your entire existing media library to S3, check status, and reset if needed.
- **Admin dashboard** — A settings page under **Media > S3 Offloader** shows storage statistics, lets you test your AWS connection, and configure plugin options.
- **Media Library column** — An "Offload" column in the Media Library list view shows the S3 status of each attachment at a glance.

## Requirements

- **WordPress** 5.3+
- **PHP** 7.4+
- **AWS S3 bucket** with an IAM user that has `s3:PutObject`, `s3:GetObject`, `s3:DeleteObject`, `s3:ListBucket`, and `s3:HeadBucket` permissions
- **CloudFront distribution** (optional but recommended) configured with Origin Access Control (OAC) pointing to your S3 bucket
- **WP-CLI** (optional) for bulk migration commands

## Installation

1. Download or clone the plugin into `wp-content/plugins/ct-s3-offloader/`.

2. **Install the AWS SDK.** The plugin requires the AWS SDK for PHP v3. Download it from [aws.amazon.com/sdk-for-php](https://aws.amazon.com/sdk-for-php/) and extract it into the `aws-sdk/` directory inside the plugin folder. The directory should contain `aws-autoloader.php` at `aws-sdk/aws-autoloader.php`.

3. Activate the plugin in **Plugins > Installed Plugins**.

## Configuration

All AWS credentials are set as PHP constants in your `wp-config.php` file. They are never stored in the database.

Add these lines to `wp-config.php` (above the `/* That's all, stop editing! */` line):

```php
// Required — the plugin will not offload files without all four
define('S3MO_BUCKET', 'your-bucket-name');
define('S3MO_REGION', 'us-east-1');
define('S3MO_KEY',    'AKIAIOSFODNN7EXAMPLE');
define('S3MO_SECRET', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY');

// Optional — if not set, files are served directly from S3
define('S3MO_CDN_URL', 'https://d111111abcdef8.cloudfront.net');

// Optional — your frontend app URL for CORS (headless/decoupled setups only)
define('S3MO_FRONTEND_URL', 'http://localhost:3000');
```

### What Happens Without Credentials

If any of the four required constants are missing, the plugin degrades gracefully:

- No files are uploaded to S3
- No URLs are rewritten
- No S3 deletions happen
- The admin settings page still loads (display-only mode)
- A warning banner tells you which constants are missing

Once all four constants are defined and the plugin can reach your bucket, everything activates automatically.

## Admin Settings Page

Navigate to **Media > S3 Offloader** in the WordPress admin. The page has four sections:

### Storage Statistics

Four cards showing:
- **Files on S3** — Total number of offloaded attachments
- **Total Size** — Combined size of all offloaded files
- **Pending** — Attachments not yet offloaded
- **Last Offloaded** — Time since the most recent offload

Click **Refresh Stats** to update these numbers (they are cached for 1 hour).

### AWS Credentials

A read-only table showing your configured constants. The access key shows only the last 4 characters, and the secret key is fully masked. This lets you verify your configuration without exposing credentials.

### Connection Test

Click **Test Connection** to verify the plugin can reach your S3 bucket. The test performs a `HeadBucket` API call and reports success or a specific error (wrong bucket name, invalid key, access denied, etc.).

### Settings

| Setting | Description |
|---------|-------------|
| **S3 Path Prefix** | The prefix added to S3 object keys. Defaults to `wp-content/uploads` so S3 keys mirror your local upload paths. |
| **Delete Local Files** | Option to remove local files after successful S3 upload. (Registered for future use.) |
| **Delete S3 Files on Uninstall** | When checked, deleting the plugin from WordPress will also delete all offloaded files from S3. **Use with caution.** |

## Media Library

In the Media Library list view (**Media > Library**, switch to list mode), an **Offload** column shows the status of each attachment:

- **Green dot + "S3"** — File has been offloaded to S3. Click to see the S3 key, bucket name, and upload timestamp.
- **Amber dot + "Local"** — File has not been offloaded yet.

## How URL Rewriting Works

The plugin rewrites URLs at render time using WordPress filter hooks. Your database always contains the original local URLs (e.g., `https://example.com/wp-content/uploads/2026/02/photo.jpg`). When WordPress outputs the URL, the plugin replaces the local upload base with your CDN domain (e.g., `https://d111111abcdef8.cloudfront.net/wp-content/uploads/2026/02/photo.jpg`).

This happens in five places:
1. **Post content** — `the_content` filter
2. **Attachment URLs** — `wp_get_attachment_url` filter
3. **Responsive images** — `wp_calculate_image_srcset` filter
4. **REST API** — `rest_prepare_attachment` filter
5. **Media Library modal** — `wp_prepare_attachment_for_js` filter

If `S3MO_CDN_URL` is not defined, the plugin falls back to direct S3 URLs (`https://bucket.s3.region.amazonaws.com/...`).

## How Uploads Work

When you upload a file through the WordPress admin (or any tool that triggers `wp_generate_attachment_metadata`):

1. WordPress saves the file locally and generates thumbnail sizes as usual.
2. The plugin reads the attachment metadata to get the list of all files (original + every thumbnail).
3. Each file is uploaded to S3 using the AWS SDK's `ObjectUploader` (which handles multipart uploads for large files automatically).
4. Files are uploaded with `private` ACL and `Cache-Control: public, max-age=31536000, immutable` headers. CloudFront OAC provides public access.
5. The plugin records offload metadata on the attachment (S3 key, bucket, timestamp).

## How Deletion Works

When you delete an attachment from WordPress, the `delete_attachment` hook fires. The plugin reads the stored S3 key and the attachment's thumbnail metadata, then deletes the original file and all thumbnail files from S3.

## WP-CLI Commands

The plugin registers three commands under the `wp ct-s3` namespace. These require all four credential constants to be defined.

### wp ct-s3 offload

Bulk upload media attachments to S3. This is the main migration command for moving an existing media library.

```bash
wp ct-s3 offload
```

The command finds all un-offloaded attachments and uploads them one by one, showing progress:

```
Found 245 attachment(s) to process.
[1/245] Uploading hero-banner.jpg... OK (4 file(s))
[2/245] Uploading team-photo.png... OK (4 file(s))
...

--- Migration Summary ---
  Success: 243
  Failed:  1
  Skipped: 1
  Elapsed: 4m 12s
```

#### Options

| Flag | Default | Description |
|------|---------|-------------|
| `--dry-run` | — | Preview what would be uploaded without uploading anything |
| `--force` | — | Re-upload files that are already marked as offloaded |
| `--batch-size=<n>` | 50 | Number of attachments to process per batch |
| `--sleep=<n>` | 0 | Seconds to pause between batches |
| `--mime-type=<type>` | all | Only process files of this MIME type (e.g. `image/jpeg`, `image`, `application/pdf`) |
| `--limit=<n>` | unlimited | Maximum total attachments to process |

#### Examples

```bash
# Preview what would be uploaded
wp ct-s3 offload --dry-run

# Upload only JPEG images
wp ct-s3 offload --mime-type=image/jpeg

# Test with a small batch first
wp ct-s3 offload --limit=10

# Reduce memory usage on shared hosting
wp ct-s3 offload --batch-size=10 --sleep=2

# Force re-upload everything
wp ct-s3 offload --force
```

#### Error Handling

- Failed uploads are retried **twice** with exponential backoff (1 second, then 2 seconds) before being marked as failed.
- The migration continues to the next file after a failure.
- Failed files are logged to `wp-content/uploads/ct-s3-offloader/ct-s3-migration.log` with timestamps.
- If PHP runs out of memory, a shutdown handler logs the error. Reduce `--batch-size` and try again.
- The command is **idempotent** — running it again skips files that were already uploaded.

### wp ct-s3 status

Check the offload status of your media library.

```bash
wp ct-s3 status
```

```
+-----------+-------+
| Metric    | Count |
+-----------+-------+
| Total     | 245   |
| Offloaded | 243   |
| Pending   | 2     |
+-----------+-------+
```

#### Options

| Flag | Description |
|------|-------------|
| `--verbose` | Show a per-file table with ID, filename, MIME type, status, and S3 key |
| `--mime-type=<type>` | Filter results by MIME type |
| `--format=table\|csv` | Output format (default: `table`) |

#### Examples

```bash
# Detailed per-file view
wp ct-s3 status --verbose

# Filter to only JPEG images
wp ct-s3 status --mime-type=image/jpeg

# Export as CSV
wp ct-s3 status --verbose --format=csv > media-status.csv
```

### wp ct-s3 reset

Clear offload tracking metadata. This does **not** delete your local files. Use this when you need to start the migration over.

```bash
wp ct-s3 reset
```

You will be prompted to confirm before any changes are made.

#### Options

| Flag | Description |
|------|-------------|
| `--delete-remote` | Also delete S3 objects before clearing metadata |
| `--mime-type=<type>` | Only reset attachments of this MIME type |
| `--yes` | Skip the confirmation prompt (for scripting) |

#### Examples

```bash
# Clear tracking only (keep S3 files)
wp ct-s3 reset --yes

# Clear tracking AND delete S3 files
wp ct-s3 reset --delete-remote --yes

# Reset only JPEG tracking
wp ct-s3 reset --mime-type=image/jpeg --yes
```

## Common Workflows

### First-Time Migration

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

### Resume After Interruption

If the migration is interrupted (server timeout, network error, Ctrl+C), just run the command again. It automatically skips files that were already uploaded:

```bash
wp ct-s3 offload
```

### Re-Migrate Everything

```bash
wp ct-s3 reset --yes
wp ct-s3 offload
```

### Complete Teardown

Remove all S3 objects and tracking data:

```bash
wp ct-s3 reset --delete-remote --yes
```

### Export a Migration Report

```bash
wp ct-s3 status --verbose --format=csv > media-report.csv
```

## Uninstalling

When you delete the plugin through the WordPress admin:

1. All offload tracking metadata is removed from the database.
2. All plugin options and transients are deleted.
3. The migration log file (`wp-content/uploads/ct-s3-offloader/ct-s3-migration.log`) is deleted.
4. If the **Delete S3 Files on Uninstall** setting was checked, all offloaded files are deleted from S3 in batches (originals + thumbnails).

Your local media files are never deleted by the plugin.

## AWS Setup Tips

### IAM Policy

Create an IAM user with a policy scoped to your bucket:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "s3:PutObject",
                "s3:GetObject",
                "s3:DeleteObject",
                "s3:ListBucket",
                "s3:HeadBucket"
            ],
            "Resource": [
                "arn:aws:s3:::your-bucket-name",
                "arn:aws:s3:::your-bucket-name/*"
            ]
        }
    ]
}
```

### CloudFront with OAC

The plugin uploads files with `private` ACL, so S3 objects are not publicly accessible on their own. To serve them through CloudFront:

1. Create a CloudFront distribution with your S3 bucket as the origin.
2. Enable **Origin Access Control (OAC)** on the distribution.
3. Update your S3 bucket policy to allow CloudFront access.
4. Set the `S3MO_CDN_URL` constant to your CloudFront distribution domain.

### S3 Bucket Settings

- **Block Public Access** should remain **enabled** (the plugin uses private ACL + CloudFront OAC).
- **Versioning** is optional but recommended for recovery purposes.
- The plugin sets `Cache-Control: public, max-age=31536000, immutable` on all uploads for aggressive browser/CDN caching.

## License

GPL-2.0+
