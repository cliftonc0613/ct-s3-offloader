# CT S3 Media Offloader

## What This Is

A custom WordPress plugin that automatically offloads media uploads to Amazon S3 and serves them via CloudFront CDN. It intercepts the WordPress media upload pipeline, pushes files to S3, rewrites URLs to serve from CloudFront, syncs deletions, and provides WP-CLI tools for bulk migrating existing media libraries. Built without Composer dependencies — the AWS SDK is bundled directly.

## Core Value

**Media files upload to S3 and serve from CloudFront transparently** — WordPress users upload media normally, but files live on S3/CloudFront instead of the local server. Everything else (admin UI, CLI tools, migration) supports this core behavior.

## Requirements

### Validated

(None yet — ship to validate)

### Active

- [ ] Plugin scaffolding with WordPress standards (headers, activation/deactivation hooks, autoloader)
- [ ] AWS SDK integration without Composer (bundled PHAR or extracted SDK)
- [ ] S3 client wrapper class with upload, delete, and URL generation methods
- [ ] Core upload hook that intercepts `wp_handle_upload` and pushes to S3
- [ ] URL rewriting system that replaces local media URLs with CloudFront URLs
- [ ] Deletion handler that syncs WordPress media deletions to S3
- [ ] Admin settings page for S3 bucket, region, CloudFront domain, and credentials
- [ ] Settings validation and connection testing from admin UI
- [ ] WP-CLI migration command for bulk uploading existing media to S3
- [ ] Migration with batching, progress tracking, and resume capability (1000+ files)
- [ ] Media Library UI enhancements showing S3/CloudFront status per file
- [ ] CloudFront CDN integration with proper cache headers and invalidation
- [ ] Error handling and logging for failed uploads/deletions
- [ ] Security: credentials stored encrypted, nonce verification on settings, capability checks

### Out of Scope

- WordPress.org public release (i18n, readme.txt, SVN) — reusable but private distribution
- Multi-bucket support — single bucket per site is sufficient
- Image optimization/compression — separate concern from offloading
- Video transcoding — upload as-is
- Non-S3 providers (Google Cloud Storage, Azure Blob) — S3/CloudFront only

## Context

- AWS infrastructure already in place (S3 bucket created, IAM user with credentials, policies configured)
- Plugin will be developed on Local by Flywheel environment
- Primary target site is Clemson Sports Media with 1000+ existing media files requiring migration
- Build guide exists at `knowledge/research/s3-media-offloader-build-guide-local-flywheel.md` — follow as primary blueprint, flag improvements via questions
- No Composer available in target environment — SDK must be bundled
- CloudFront distribution needed from day one (not a later addition)

## Constraints

- **No Composer**: AWS SDK must be bundled directly (PHAR or extracted zip)
- **Local by Flywheel**: Development environment with specific PHP/MySQL paths
- **WordPress coding standards**: Follow WP PHP conventions, hook patterns, admin API
- **Large migration**: Must handle 1000+ files with batching, progress tracking, resume on failure
- **Reusable**: Settings-driven configuration, no hardcoded bucket/region/credentials
- **Blueprint**: Follow the build guide closely; propose improvements via AskUserQuestion

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| No Composer, bundle AWS SDK | Target environments may not have Composer; simpler deployment | — Pending |
| CloudFront from day one | Performance requirement, not an afterthought | — Pending |
| Follow build guide as blueprint | Detailed 12-phase guide already researched and written | — Pending |
| Reusable plugin with settings page | Deploy across multiple WordPress sites, not just Clemson | — Pending |
| Phase 1 (AWS) already complete | Infrastructure ready, start building at Phase 2 | ✓ Good |

---
*Last updated: 2026-02-27 after initialization*
