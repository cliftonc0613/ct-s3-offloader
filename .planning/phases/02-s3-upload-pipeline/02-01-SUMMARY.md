---
phase: "02-s3-upload-pipeline"
plan: "01"
subsystem: "s3-client-and-tracker"
tags: ["s3", "aws", "object-uploader", "postmeta", "tracker"]
dependency-graph:
  requires: ["01-01"]
  provides: ["S3MO_Client upload/delete/URL methods", "S3MO_Tracker postmeta CRUD"]
  affects: ["02-02", "03-01"]
tech-stack:
  added: []
  patterns: ["ObjectUploader for multipart S3 uploads", "Static tracker class for postmeta state"]
key-files:
  created: ["includes/class-s3mo-tracker.php"]
  modified: ["includes/class-s3mo-client.php"]
decisions:
  - id: "CDN-04-ACL"
    description: "ACL set to 'private' on all uploads — CloudFront OAC handles public access"
  - id: "CDN-02-CACHE"
    description: "CacheControl 'public, max-age=31536000, immutable' on all uploads for long-lived caching"
  - id: "TRACKER-STATIC"
    description: "S3MO_Tracker uses all-static methods — no instance state needed for postmeta operations"
metrics:
  duration: "~2 minutes"
  completed: "2026-02-28"
---

# Phase 02 Plan 01: S3 Client Methods and Tracker Summary

**One-liner:** ObjectUploader-based S3 upload/delete with private ACL and immutable caching, plus static postmeta tracker for offload state.

## What Was Done

### Task 1: S3MO_Client upload/delete/URL methods
- Added `use Aws\S3\ObjectUploader` import
- Implemented `upload_object(string $key, string $file_path, string $content_type): array`
  - Uses ObjectUploader for automatic multipart handling (not putObject)
  - ACL set to `'private'` for CloudFront OAC (CDN-04)
  - CacheControl `'public, max-age=31536000, immutable'` (CDN-02)
  - fopen/fclose with finally block to prevent resource leaks
  - Returns success array with URL or error array with message
- Implemented `delete_object(string $key): array` with AwsException handling
- Implemented `get_object_url(string $key): string` delegating to existing `get_url_base()`

### Task 2: S3MO_Tracker postmeta tracking class
- Created `includes/class-s3mo-tracker.php` with all-static design
- Five methods: `mark_as_offloaded()`, `is_offloaded()`, `get_s3_key()`, `get_offload_info()`, `clear_offload_status()`
- Four meta keys: `_s3mo_offloaded`, `_s3mo_key`, `_s3mo_bucket`, `_s3mo_offloaded_at`
- Meta key prefix `_s3mo_` consistent with existing `uninstall.php`
- Constants for meta key names to avoid typos

## Commits

| Hash | Message |
|------|---------|
| 92df9da | feat(02-01): add upload_object, delete_object, get_object_url to S3MO_Client |
| 95741f3 | feat(02-01): create S3MO_Tracker for attachment offload postmeta tracking |

## Decisions Made

| ID | Decision | Rationale |
|----|----------|-----------|
| CDN-04-ACL | Private ACL on all S3 uploads | CloudFront OAC handles public access; never expose bucket directly |
| CDN-02-CACHE | Immutable cache headers (1 year) | Media files are content-addressed; long cache is safe and performant |
| TRACKER-STATIC | Static methods on S3MO_Tracker | No instance state needed; postmeta functions are pure WordPress API calls |

## Deviations from Plan

None - plan executed exactly as written.

## Verification Results

- php -l passes on both files
- ObjectUploader used (not putObject)
- ACL is 'private' throughout
- CacheControl header set on uploads
- Content-Type passed through to S3
- No putObject calls found

## Next Phase Readiness

Plan 02-02 (Upload Handler) can proceed. It depends on:
- `S3MO_Client::upload_object()` -- ready
- `S3MO_Tracker::mark_as_offloaded()` -- ready
- `S3MO_Tracker::is_offloaded()` -- ready
