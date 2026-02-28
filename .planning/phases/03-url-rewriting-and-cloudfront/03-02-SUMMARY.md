---
phase: 03-url-rewriting-and-cloudfront
plan: 02
subsystem: url-rewriting
tags: [srcset, rest-api, cors, cdn, cloudfront, media-library]

dependency-graph:
  requires: ["03-01"]
  provides: ["srcset-rewriting", "rest-api-attachment-rewriting", "admin-modal-rewriting", "cors-headers"]
  affects: ["04-admin-ui", "05-lifecycle"]

tech-stack:
  added: []
  patterns: ["str_replace-url-rewriting", "origin-allowlist-cors", "protocol-variant-handling"]

key-files:
  created: []
  modified: ["includes/class-s3mo-url-rewriter.php"]

decisions:
  - id: "url-03"
    decision: "Explicit origin allowlist for CORS instead of wildcard"
    reason: "Security — only site URL, localhost dev ports, and CDN URL are allowed"
  - id: "url-04"
    decision: "REST API source_url uses get_object_url for canonical URL, sizes use str_replace"
    reason: "Top-level source_url benefits from exact S3 key lookup; sizes are bulk-replaced for efficiency"

metrics:
  duration: "~2m"
  completed: "2026-02-28"
---

# Phase 3 Plan 2: Srcset, REST API, Admin Modal, and CORS Summary

**Responsive srcset, REST API attachment, admin Media Library modal URL rewriting plus explicit-origin CORS headers for headless Next.js frontend**

## What Was Done

### Task 1: Add srcset, REST API, and admin modal filter methods (999c297)
Added three new filter methods to S3MO_URL_Rewriter:

- **filter_srcset** (`wp_calculate_image_srcset`, 5 args): Loops `$sources` array, str_replaces local upload base with CDN base for each entry. Guards with `is_offloaded`. Prevents WordPress from dropping srcset when main src already points to CDN.
- **filter_rest_attachment** (`rest_prepare_attachment`, 3 args): Rewrites `$response->data['source_url']` via `get_object_url($key)` for canonical URL. Iterates `media_details.sizes[]` with str_replace for thumbnails. Critical for headless Next.js consuming `/wp-json/wp/v2/media`.
- **filter_attachment_for_js** (`wp_prepare_attachment_for_js`, 3 args): Rewrites `url`, `sizes[].url`, and `icon` fields for admin Media Library modal consistency.

All methods handle http/https protocol variants.

### Task 2: Add CORS headers for cross-origin media requests (8e9c73a)
Added `add_cors_headers` method hooked to `send_headers` action:

- Only fires on `REST_REQUEST` (not frontend or admin page loads)
- Validates `Origin` header against explicit allowlist: site URL, `localhost:3000`, `localhost:3001`, and `S3MO_CDN_URL` if defined
- Sets `Access-Control-Allow-Origin`, `Allow-Methods`, `Allow-Headers`, `Expose-Headers`
- No wildcard origins
- Documents that CloudFront CORS requires separate AWS configuration (S3 bucket CORS rules + CloudFront Origin Request Policy)

## Hook Registration Summary

```
register_hooks():
  1. wp_get_attachment_url      → filter_attachment_url     (Plan 01)
  2. wp_calculate_image_srcset  → filter_srcset             (Plan 02)
  3. the_content                → filter_content            (Plan 01)
  4. rest_prepare_attachment    → filter_rest_attachment     (Plan 02)
  5. wp_prepare_attachment_for_js → filter_attachment_for_js (Plan 02)
  6. send_headers               → add_cors_headers          (Plan 02)
```

## Deviations from Plan

None -- plan executed exactly as written.

## Requirements Coverage

| Requirement | Description | Status |
|------------|-------------|--------|
| URL-01 | wp_get_attachment_url rewriting | Done (Plan 01) |
| URL-03 | Responsive srcset CDN URLs | Done (Task 1) |
| URL-04 | Gutenberg the_content rewriting | Done (Plan 01) |
| URL-05 | REST API attachment CDN URLs | Done (Task 1) |
| URL-06 | Admin Media Library modal CDN URLs | Done (Task 1) |
| CDN-01 | CloudFront URL base | Done (Plan 01) |
| CDN-03 | CORS for cross-origin requests | Done (Task 2) |

## Phase 3 Completion

Phase 3 is now complete. All URL rewriting and CloudFront integration requirements are covered:
- 6 hooks registered in S3MO_URL_Rewriter
- Runtime-only rewriting (no database modifications)
- Protocol-variant handling throughout
- CORS configured for headless Next.js frontend
- CloudFront AWS-side CORS documented as separate requirement

## Next Phase Readiness

Phase 4 (Admin UI) can begin. The URL rewriter class is stable and complete. Admin settings page will configure the options that the rewriter reads (CDN URL, path prefix).
