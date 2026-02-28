# Plan 02-02: Upload Handler and Bootstrap Wiring

## Status: COMPLETE

## Tasks Completed

### Task 1: Create S3MO_Upload_Handler class
- Created `includes/class-s3mo-upload-handler.php`
- Hooks `wp_generate_attachment_metadata` filter (priority 10, 3 args)
- Guard clauses: only `$context === 'create'`, skip if already offloaded
- Builds file list: original + all thumbnails from `$metadata['sizes']`
- Thumbnail paths derived from `dirname($metadata['file'])` (not filename alone)
- Each file upload wrapped in individual try/catch for error isolation
- Only marks offloaded via `S3MO_Tracker::mark_as_offloaded()` when ALL files succeed
- Always returns `$metadata` unchanged (filter contract)
- Commit: f2bdb5d

### Task 2: Modify bootstrap for all contexts
- Modified `ct-s3-offloader.php` plugins_loaded callback
- S3MO_Client and S3MO_Upload_Handler instantiated OUTSIDE `is_admin()`
- Upload handler fires for admin, REST API (Gutenberg), and cron contexts
- Settings page remains admin-only
- Missing credentials: skip upload handler, settings page still loads with null client
- Single S3MO_Client instance shared between upload handler and settings page
- Commit: 0a99066

### Task 3: Human verification checkpoint
- User verified uploads work end-to-end
- Status: APPROVED

## Requirements Covered

| Requirement | How |
|-------------|-----|
| UPLD-01 | handle_upload() copies to S3 after WordPress saves locally |
| UPLD-02 | Iterates $metadata['sizes'] to upload all thumbnails |
| UPLD-03 | S3MO_Tracker stores _s3mo_key in postmeta (from 02-01) |
| UPLD-04 | Uses wp_generate_attachment_metadata filter |
| UPLD-05 | Per-file try/catch, always returns $metadata |
| UPLD-06 | Content-Type passed through from MIME type |
| UPLD-07 | ObjectUploader used via S3MO_Client (from 02-01) |
| CDN-02 | CacheControl header set in upload_object (from 02-01) |
| CDN-04 | Private ACL, OAC handles access (from 02-01) |

## Files Created/Modified

- `includes/class-s3mo-upload-handler.php` (new)
- `ct-s3-offloader.php` (modified)

## Deviations

None.
