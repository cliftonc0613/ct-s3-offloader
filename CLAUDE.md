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

`S3MO_Client` is constructed once in bootstrap and injected into all classes needing S3 access. `S3MO_Tracker` and `S3MO_Stats` are pure static classes — called directly, not injected. `S3MO_Tracker` also provides shared key-building logic (`build_file_list`) used by both `S3MO_Upload_Handler` and `S3MO_Bulk_Migrator`, and error tracking methods (`set_error`/`clear_error`) for upload failure recording.

### AWS SDK

Bundled manually in `aws-sdk/` (gitignored). Must be downloaded and extracted as a deployment step. The bootstrap file checks for `aws-sdk/aws-autoloader.php` and shows an admin notice if missing.

## Database Schema

### Postmeta Keys (per attachment)

- `_s3mo_offloaded` — `"1"` if offloaded
- `_s3mo_key` — Full S3 object key
- `_s3mo_bucket` — Bucket name at upload time
- `_s3mo_offloaded_at` — ISO 8601 timestamp
- `_s3mo_error` — Error message from last failed upload attempt (cleared on success)

### Options

- `s3mo_path_prefix` — S3 key prefix (default: `wp-content/uploads`)
- `s3mo_delete_local` — When enabled, deletes local files after successful S3 upload
- `s3mo_delete_s3_on_uninstall` — Controls whether `uninstall.php` deletes S3 objects

### Transients

- `s3mo_stats_cache` — 1-hour cached stats
- `s3mo_connection_status` — Written by Settings Page AJAX connection test, read by Admin Notices for persistent status display

## WP-CLI Commands

Registered under `wp ct-s3` (requires all 4 credential constants). Full reference: `docs/wp-cli.md`.

| Command | Description |
|---------|-------------|
| `wp ct-s3 offload` | Bulk upload un-offloaded attachments to S3 |
| `wp ct-s3 status` | Show offload counts (Total/Offloaded/Pending) |
| `wp ct-s3 reset` | Clear offload tracking metadata |

Key `offload` flags: `--dry-run`, `--force`, `--batch-size=<n>`, `--sleep=<n>`, `--mime-type=<type>`, `--limit=<n>`. Retries failed uploads 2x with exponential backoff. Logs failures to `wp-content/ct-s3-migration.log`.

## Known Tech Debt

1. **CORS handler scope** — `S3MO_URL_Rewriter::add_cors_headers()` sends WP REST API CORS headers for headless/decoupled WordPress setups. This is intentional functionality (not dead code), but only useful when WordPress serves as a headless CMS. Full CORS support also requires CloudFront-side configuration (S3 bucket CORS rules + CloudFront Response Headers Policy).

## Development Notes

- **PHP 7.4+ required** — compatible with PHP 7.4 through 8.x; AWS SDK v3.337.3 bundled for PHP 7.4 support
- **No build pipeline** — no `package.json`, `composer.json`, or test suite
- **No Composer** — autoloading is custom via `spl_autoload_register`
- **Assets** — `assets/css/admin.css` and `assets/js/admin.js` (jQuery-based, no build step)
- **Plugin lifecycle** — activation sets default options; deactivation clears transients; `uninstall.php` does full cleanup (postmeta, options, transients, log file, optionally S3 objects)

## IMPORTANT: Development Workflow Rules

**These rules are MANDATORY and must be followed for every task. No exceptions.**

1. **PLAN FIRST** — Think through the problem, read the codebase for relevant files, and write a plan to `tasks/todo.md` with a checklist of todo items. Check in with the user for plan approval before starting work. NEVER start coding without an approved plan.
2. **WORK THE PLAN** — Complete todo items one at a time, marking each as done. Give a high-level explanation of each change.
3. **SIMPLICITY ABOVE ALL** — Every task and code change MUST be as simple as possible. Changes MUST impact as little code as possible. Avoid massive or complex changes. Simplicity prevents bugs. If a change feels big, break it down smaller.
4. **NO LAZINESS. EVER.** — NEVER take shortcuts. NEVER apply temporary fixes. ALWAYS find the root cause and fix it properly. You are a senior developer. Act like one.
5. **MINIMAL BLAST RADIUS** — Changes MUST only impact code directly relevant to the task. Touch NOTHING else. The goal is ZERO introduced bugs. If it's not broken and not part of the task, don't touch it.
6. **REVIEW AT THE END** — Add a review section to `tasks/todo.md` summarizing all changes made and any relevant information.

## Planning Artifacts

The `.planning/` directory contains AI-assisted development docs: `PROJECT.md` (requirements/decisions), `STATE.md` (session continuity), phase plans in `phases/`, and milestone audits in `milestones/`. These are reference-only and not part of the plugin distribution.
