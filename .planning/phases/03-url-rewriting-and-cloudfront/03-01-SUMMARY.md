---
phase: 03-url-rewriting-and-cloudfront
plan: 01
subsystem: url-rewriting
tags: [cloudfront, cdn, url-rewrite, wp-filters]
depends:
  requires: [01-01, 01-02, 02-01, 02-02]
  provides: [S3MO_URL_Rewriter, wp_get_attachment_url-filter, the_content-filter]
  affects: [03-02, 04-xx, 05-xx]
tech-stack:
  added: []
  patterns: [runtime-url-filtering, str_replace-over-regex, dependency-injection]
key-files:
  created:
    - includes/class-s3mo-url-rewriter.php
  modified:
    - ct-s3-offloader.php
decisions:
  - id: URL-REWRITE-01
    title: str_replace over regex for content filtering
    choice: str_replace with protocol-variant array
    reason: Faster, simpler, sufficient for URL base replacement
  - id: URL-REWRITE-02
    title: CDN uploads base cached in instance property
    choice: Lazy-init with null check
    reason: Avoid repeated get_option calls per content filter invocation
metrics:
  duration: ~2m
  completed: 2026-02-28
---

# Phase 3 Plan 1: URL Rewriter Core Filters Summary

**Runtime URL rewriting via wp_get_attachment_url and the_content filters using str_replace with http/https protocol variant handling**

## What Was Done

### Task 1: Create S3MO_URL_Rewriter class (c7411c8)

Created `includes/class-s3mo-url-rewriter.php` with the S3MO_URL_Rewriter class containing:

- **Constructor**: Accepts S3MO_Client via dependency injection
- **register_hooks()**: Hooks `wp_get_attachment_url` (priority 10, 2 args) and `the_content` (priority 10, 1 arg)
- **filter_attachment_url()**: Guards with `S3MO_Tracker::is_offloaded()` and `get_s3_key()` before rewriting to CloudFront URL via `$client->get_object_url()`
- **filter_content()**: Bulk str_replace of local upload base URL with CDN base, handling both http and https protocol variants
- **get_cdn_uploads_base()**: Private helper combining `get_url_base()` with `s3mo_path_prefix` option, cached after first call

### Task 2: Wire URL rewriter into plugin bootstrap (6439f43)

Modified `ct-s3-offloader.php` to instantiate `S3MO_URL_Rewriter` with the shared `$client` instance and call `register_hooks()`. Placed outside `is_admin()` block so URL rewriting applies to all contexts (frontend, REST API, admin).

## Decisions Made

1. **str_replace over regex** — URL base replacement is a simple string swap; regex adds complexity and CPU cost with no benefit.
2. **CDN base cached in property** — `get_cdn_uploads_base()` caches result to avoid repeated `get_option()` calls during `the_content` filter execution.
3. **Both protocol variants** — Content stored in database may have http or https URLs depending on site history; both are replaced.

## Deviations from Plan

None -- plan executed exactly as written.

## Verification Results

All 9 verification checks passed:
1. No syntax errors in either file
2. All required methods present (constructor, register_hooks, filter_attachment_url, filter_content, get_cdn_uploads_base)
3. is_offloaded guard confirmed in attachment filter
4. wp_get_upload_dir used (no hardcoded paths)
5. str_replace used (no regex)
6. http/https protocol variants handled
7. Bootstrap wiring outside is_admin block
8. No database write operations in new code

## Requirements Covered

- **URL-01**: wp_get_attachment_url returns CloudFront URL for offloaded attachments
- **URL-02**: wp_get_attachment_url returns original local URL for non-offloaded attachments
- **URL-06**: Post/page content renders with CloudFront URLs for offloaded media
- **URL-07**: Database stores only local URLs (runtime-only filtering)
- **CDN-01**: CloudFront URLs served through get_url_base()

## Next Phase Readiness

Plan 03-02 (srcset and REST API filters) can proceed. The S3MO_URL_Rewriter class is designed for extension with additional filter methods in the same class.
