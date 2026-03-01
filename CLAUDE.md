# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Plugin Does

CT S3 Offloader transparently offloads WordPress media uploads to Amazon S3 and serves them via CloudFront CDN. Three core behaviors:

1. **Auto-upload** — Intercepts `wp_generate_attachment_metadata`, pushes original + all thumbnails to S3 with `private` ACL and immutable cache headers. CloudFront OAC handles public access.
2. **Runtime URL rewriting** — Rewrites local `uploads/` URLs to CloudFront at render time via filters (`the_content`, `wp_get_attachment_url`, `wp_calculate_image_srcset`, REST API, Media Library modal). No database URLs are modified.
3. **Deletion sync** — `delete_attachment` hook removes corresponding S3 objects.

Additionally, `wp ct-s3` WP-CLI commands enable bulk migration of existing media libraries.

## Configuration

All AWS credentials are PHP constants in `wp-config.php` — never stored in the database:

```php
// Required (plugin won't function without all four)
define('S3MO_BUCKET', 'my-bucket');
define('S3MO_REGION', 'us-east-1');
define('S3MO_KEY', 'AKIA...');
define('S3MO_SECRET', 'abc123...');

// Optional (falls back to direct S3 URLs if omitted)
define('S3MO_CDN_URL', 'https://d1234.cloudfront.net');
```

When any required constant is missing, upload/rewrite/delete handlers are **not registered** — the plugin degrades gracefully to admin-only mode showing a credentials warning.

## Architecture

### Class Prefix and Autoloader

All classes use the `S3MO_` prefix. The custom autoloader in `ct-s3-offloader.php` maps `S3MO_Foo_Bar` → `class-s3mo-foo-bar.php`, searching `includes/` then `admin/`.

### Core Classes (`includes/`)

| Class | Pattern | Role |
|-------|---------|------|
| `S3MO_Client` | Injected instance | AWS SDK wrapper: upload, delete, test connection |
| `S3MO_Upload_Handler` | Receives `$client` | Hooks `wp_generate_attachment_metadata` |
| `S3MO_URL_Rewriter` | Receives `$client` | 5 filter hooks for runtime URL replacement |
| `S3MO_Delete_Handler` | Receives `$client` | Hooks `delete_attachment` |
| `S3MO_Tracker` | Static utility | Read/write offload state in postmeta |
| `S3MO_Stats` | Static utility | Aggregate stats with 1-hour transient cache |
| `S3MO_Bulk_Migrator` | Receives `$client` | Batch migration engine (used by CLI) |
| `S3MO_CLI_Command` | WP_CLI_Command | `wp ct-s3` namespace |

### Admin Classes (`admin/`)

| Class | Role |
|-------|------|
| `S3MO_Settings_Page` | Settings page under Media menu, AJAX handlers |
| `S3MO_Admin_Notices` | Credential/connection warning banners |
| `S3MO_Media_Column` | "Offload" status column in Media Library |

### Dependency Pattern

`S3MO_Client` is constructed once in bootstrap and injected into all classes needing S3 access. `S3MO_Tracker` and `S3MO_Stats` are pure static classes — called directly, not injected.

### AWS SDK

Bundled manually in `aws-sdk/` (gitignored). Must be downloaded and extracted as a deployment step. The bootstrap file checks for `aws-sdk/aws-autoloader.php` and shows an admin notice if missing.

## Database Schema

### Postmeta Keys (per attachment)

- `_s3mo_offloaded` — `"1"` if offloaded
- `_s3mo_key` — Full S3 object key
- `_s3mo_bucket` — Bucket name at upload time
- `_s3mo_offloaded_at` — ISO 8601 timestamp
- `_s3mo_error` — Error message (currently unused/dead code)

### Options

- `s3mo_path_prefix` — S3 key prefix (default: `wp-content/uploads`)
- `s3mo_delete_local` — Checkbox registered but **currently a no-op**
- `s3mo_delete_s3_on_uninstall` — Controls whether `uninstall.php` deletes S3 objects

### Transients

- `s3mo_stats_cache` — 1-hour cached stats
- `s3mo_connection_status` — Read by Admin Notices but **never written** (dead code)

## WP-CLI Commands

Registered under `wp ct-s3` (requires all 4 credential constants). Full reference: `docs/wp-cli.md`.

| Command | Description |
|---------|-------------|
| `wp ct-s3 offload` | Bulk upload un-offloaded attachments to S3 |
| `wp ct-s3 status` | Show offload counts (Total/Offloaded/Pending) |
| `wp ct-s3 reset` | Clear offload tracking metadata |

Key `offload` flags: `--dry-run`, `--force`, `--batch-size=<n>`, `--sleep=<n>`, `--mime-type=<type>`, `--limit=<n>`. Retries failed uploads 2x with exponential backoff. Logs failures to `wp-content/ct-s3-migration.log`.

## Known Tech Debt

1. `s3mo_delete_local` option registered/saved but never read — checkbox has no effect
2. `s3mo_connection_status` transient read by Admin Notices but never written — persistent failure notice cannot fire
3. `_s3mo_error` postmeta read by Media Column but never written — red "Error" badge is dead code
4. S3 key-building logic duplicated between `S3MO_Upload_Handler` and `S3MO_Bulk_Migrator`
5. `S3MO_Stats` hard-codes meta key strings instead of using `S3MO_Tracker` constants

## Development Notes

- **PHP 8.1+ required** — uses typed properties, named arguments, constructor property promotion
- **No build pipeline** — no `package.json`, `composer.json`, or test suite
- **No Composer** — autoloading is custom via `spl_autoload_register`
- **Assets** — `assets/css/admin.css` and `assets/js/admin.js` (jQuery-based, no build step)
- **Plugin lifecycle** — activation sets default options; deactivation clears transients; `uninstall.php` does full cleanup (postmeta, options, transients, log file, optionally S3 objects)

## Planning Artifacts

The `.planning/` directory contains AI-assisted development docs: `PROJECT.md` (requirements/decisions), `STATE.md` (session continuity), phase plans in `phases/`, and milestone audits in `milestones/`. These are reference-only and not part of the plugin distribution.
