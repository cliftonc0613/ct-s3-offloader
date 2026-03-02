# CT S3 Media Offloader

## What This Is

A custom WordPress plugin that automatically offloads media uploads to Amazon S3 and serves them via CloudFront CDN. It intercepts the WordPress media upload pipeline, pushes files to S3 with all generated thumbnails, rewrites URLs at runtime to serve from CloudFront, syncs deletions between WordPress and S3, and provides WP-CLI tools for bulk migrating existing media libraries. Built without Composer dependencies — the AWS SDK is bundled directly. Ships with an admin UI featuring Media Library status indicators, storage statistics, connection testing, and comprehensive uninstall cleanup.

## Core Value

**Media files upload to S3 and serve from CloudFront transparently** — WordPress users upload media normally, but files live on S3/CloudFront instead of the local server.

## Requirements

### Validated

- ✓ Plugin scaffolding with WordPress standards (headers, activation/deactivation hooks, autoloader) — v1.0
- ✓ AWS SDK integration without Composer (bundled extracted SDK with autoloader) — v1.0
- ✓ S3 client wrapper class with upload, delete, and URL generation methods — v1.0
- ✓ Core upload hook that intercepts `wp_generate_attachment_metadata` and pushes to S3 — v1.0
- ✓ URL rewriting system that replaces local media URLs with CloudFront URLs — v1.0
- ✓ Deletion handler that syncs WordPress media deletions to S3 — v1.0
- ✓ Admin settings page for S3 bucket, region, CloudFront domain, and credentials — v1.0
- ✓ Settings validation and connection testing from admin UI — v1.0
- ✓ WP-CLI migration command for bulk uploading existing media to S3 — v1.0
- ✓ Migration with batching, progress tracking, and resume capability (1000+ files) — v1.0
- ✓ Media Library UI enhancements showing S3/CloudFront status per file — v1.0
- ✓ CloudFront CDN integration with proper cache headers and OAC support — v1.0
- ✓ Error handling and logging for failed uploads/deletions — v1.0
- ✓ Security: credentials in wp-config.php, nonce verification, capability checks — v1.0
- ✓ PHP 7.4 compatibility with AWS SDK v3.337.3 (last PHP 7.4 compatible version) — v1.1
- ✓ Runtime SDK version pinning with admin warning on mismatch — v1.1
- ✓ Delete-local files setting functional in Upload Handler and Bulk Migrator — v1.1
- ✓ Connection test writes persistent transient for failure notice — v1.1
- ✓ Upload error postmeta lifecycle (write/clear/display) — v1.1
- ✓ Shared key-building via S3MO_Tracker::build_file_list — v1.1
- ✓ S3MO_Stats uses Tracker constants, unused S3MO_PLUGIN_BASENAME removed — v1.1
- ✓ CORS handler documented as intentional for headless setups — v1.1

### Active

(No active requirements — next milestone not yet defined)

### Out of Scope

- WordPress.org public release (i18n, readme.txt, SVN) — reusable but private distribution
- Multi-bucket support — single bucket per site is sufficient
- Image optimization/compression — separate concern from offloading
- Video transcoding — upload as-is
- Non-S3 providers (Google Cloud Storage, Azure Blob) — S3/CloudFront only
- WordPress Multisite support — single-site focus

## Context

Shipped v1.1 with 2,848 LOC PHP across 13 classes.
Tech stack: WordPress plugin (PHP), AWS SDK v3.337.3 (bundled), S3, CloudFront.
Plugin deployed on Local by Flywheel for Clemson Sports Media site.
AWS infrastructure (S3 bucket, IAM user, policies) already configured.
CloudFront distribution setup pending for production deployment.

All v1.0 tech debt resolved in v1.1. No known tech debt remaining.

## Constraints

- **No Composer**: AWS SDK bundled directly (extracted zip)
- **PHP 7.4+**: Must work on PHP 7.4 through 8.x — AWS SDK v3.337.3 bundled for compatibility
- **Local by Flywheel**: Development environment with specific PHP/MySQL paths
- **WordPress coding standards**: Follow WP PHP conventions, hook patterns, admin API
- **Large migration**: Must handle 1000+ files with batching, progress tracking, resume on failure
- **Reusable**: Settings-driven configuration, no hardcoded bucket/region/credentials

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| No Composer, bundle AWS SDK | Target environments may not have Composer; simpler deployment | ✓ Good |
| CloudFront from day one | Performance requirement, not an afterthought | ✓ Good |
| Follow build guide as blueprint | Detailed 12-phase guide already researched and written | ✓ Good |
| Reusable plugin with settings page | Deploy across multiple WordPress sites, not just Clemson | ✓ Good |
| AWS infrastructure already complete | Start at plugin code, not AWS setup | ✓ Good |
| class_exists('Aws\Sdk') guard | Prevent namespace conflict with other plugins bundling AWS SDK | ✓ Good |
| ObjectUploader with 'private' ACL | CloudFront OAC handles public access; no public bucket ACLs | ✓ Good |
| Cache-Control: public, max-age=31536000, immutable | Aggressive caching for static media assets | ✓ Good |
| str_replace over regex for URL filtering | Simpler, faster, sufficient for media URL replacement | ✓ Good |
| URL rewriter outside is_admin() | Covers frontend, REST API, and admin contexts | ✓ Good |
| Delete handler outside is_admin() | Supports REST API and WP-CLI deletion | ✓ Good |
| S3MO_Tracker static methods | Consistent with WordPress meta API patterns | ✓ Good |
| Explicit CORS origin allowlist | Security over convenience; no wildcard origins | ✓ Good |
| delete_post_meta_by_key() for uninstall | Proper WordPress cache invalidation vs raw SQL | ✓ Good |
| Optional S3 object deletion on uninstall | User choice to preserve or clean S3 data | ✓ Good |
| Downgrade to PHP 7.4 | Broader hosting compatibility; AWS SDK v3.337.3 supports 7.4+ | ✓ Good |
| Public Tracker constants | Cross-class reference eliminates hardcoded meta key strings | ✓ Good |
| Shared build_file_list on Tracker | Eliminates key-building duplication between Upload Handler and Bulk Migrator | ✓ Good |
| CORS as intentional feature | Defensive measure for headless setups, not dead code | ✓ Good |

---
*Last updated: 2026-03-01 after v1.1 milestone completion*
