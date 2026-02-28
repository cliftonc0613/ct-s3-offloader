---
phase: 02-s3-upload-pipeline
verified: 2026-02-28T00:00:00Z
status: human_needed
score: 6/6 must-haves verified
human_verification:
  - test: "Upload a JPEG image through WordPress Media Library"
    expected: "Original and all thumbnail sizes appear in S3 bucket under configured path prefix"
    why_human: "Cannot simulate WordPress media upload or verify S3 bucket contents programmatically in this environment"
  - test: "Inspect an uploaded S3 object in the AWS console or via AWS CLI"
    expected: "Content-Type matches image/jpeg, Cache-Control is 'public, max-age=31536000, immutable', ACL is private (not public-read)"
    why_human: "S3 object metadata requires live AWS credentials and a real upload to verify"
  - test: "Check attachment postmeta after upload (via wp-admin or database)"
    expected: "_s3mo_offloaded = 1, _s3mo_key contains S3 path, _s3mo_bucket matches bucket name, _s3mo_offloaded_at has ISO 8601 timestamp"
    why_human: "Postmeta state requires a live WordPress upload to verify"
  - test: "Upload an image via the Gutenberg block editor (REST API path)"
    expected: "Upload succeeds and S3 offload occurs identically to admin Media Library upload"
    why_human: "REST API upload context requires a running WordPress instance with Gutenberg active"
  - test: "Simulate a failed S3 upload (e.g., revoke IAM permissions temporarily)"
    expected: "WordPress media upload completes successfully, error is written to wp-content/debug.log, _s3mo_offloaded is NOT set on the attachment"
    why_human: "Error path requires controlled credential failure against live AWS"
---

# Phase 2: S3 Upload Pipeline Verification Report

**Phase Goal:** New media uploads automatically appear on S3 with all thumbnail sizes, correct MIME types, and proper cache headers
**Verified:** 2026-02-28
**Status:** human_needed (all automated checks passed; 5 items require live environment testing)
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|---------|
| 1 | S3MO_Client can upload a file to S3 with correct Content-Type and Cache-Control headers | VERIFIED | `upload_object()` at line 119 of class-s3mo-client.php; ContentType passthrough at line 135, CacheControl 'public, max-age=31536000, immutable' at line 136, ACL 'private' at line 132 |
| 2 | S3MO_Client can delete an object from S3 by key | VERIFIED | `delete_object()` at line 166; wraps deleteObject SDK call in try/catch AwsException |
| 3 | S3MO_Client can generate a public URL for an S3 key (CloudFront or direct) | VERIFIED | `get_object_url()` at line 195 delegates to `get_url_base()` which selects CloudFront (S3MO_CDN_URL) or direct S3 URL |
| 4 | S3MO_Tracker can mark an attachment as offloaded with key, bucket, and timestamp in postmeta | VERIFIED | `mark_as_offloaded()` writes all 4 meta keys (_s3mo_offloaded, _s3mo_key, _s3mo_bucket, _s3mo_offloaded_at) at lines 43-46 |
| 5 | S3MO_Tracker can check whether an attachment is already offloaded | VERIFIED | `is_offloaded()` at line 56 returns (bool) get_post_meta on _s3mo_offloaded |
| 6 | S3MO_Tracker can retrieve the S3 key for an offloaded attachment | VERIFIED | `get_s3_key()` at line 67 returns string cast of _s3mo_key postmeta |
| 7 | Uploading an image in WordPress Media Library places original and all thumbnails on S3 | VERIFIED (structural) | handle_upload() builds $files array with original + iterates $metadata['sizes'] using dirname($metadata['file']) for thumbnail paths; REQUIRES human test to confirm end-to-end |
| 8 | Each S3 object has correct Content-Type matching the file MIME type | VERIFIED (structural) | MIME retrieved via get_post_mime_type() and $size_data['mime-type'], passed to upload_object() ContentType param |
| 9 | Each S3 object has Cache-Control header for CloudFront caching | VERIFIED (structural) | Hardcoded 'public, max-age=31536000, immutable' in upload_object() ObjectUploader params |
| 10 | Failed S3 uploads are logged and do not break WordPress media flow | VERIFIED (structural) | Per-file try/catch at lines 91-106; handle_upload() always returns $metadata at line 135; error_log() calls for partial and full failure cases |
| 11 | Attachment postmeta tracks offload status after successful upload | VERIFIED (structural) | S3MO_Tracker::mark_as_offloaded() called at line 114 only when $success_count === $total |
| 12 | Upload handler fires for REST API uploads (Gutenberg), not just admin | VERIFIED (structural) | S3MO_Upload_Handler instantiated and register_hooks() called OUTSIDE is_admin() at lines 84-87 of ct-s3-offloader.php |

**Automated Score:** 12/12 truths verified structurally. 5 truths additionally require live environment confirmation.

---

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `includes/class-s3mo-client.php` | upload_object(), delete_object(), get_object_url() methods | VERIFIED | 198 lines; all 3 methods present; no stub patterns; PHP syntax valid |
| `includes/class-s3mo-tracker.php` | S3MO_Tracker with 5 static methods | VERIFIED | 101 lines; all 5 methods present (mark_as_offloaded, is_offloaded, get_s3_key, get_offload_info, clear_offload_status); no stub patterns; PHP syntax valid |
| `includes/class-s3mo-upload-handler.php` | S3MO_Upload_Handler hooking wp_generate_attachment_metadata | VERIFIED | 137 lines; class present; register_hooks() and handle_upload() implemented; no stub patterns; PHP syntax valid |
| `ct-s3-offloader.php` | Upload handler registration outside is_admin() | VERIFIED | Lines 83-96 show S3MO_Upload_Handler instantiated in empty($missing) block before is_admin() check; PHP syntax valid |

---

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `class-s3mo-client.php` | Aws\S3\ObjectUploader | use statement + constructor call | WIRED | `use Aws\S3\ObjectUploader` at line 14; `new ObjectUploader(...)` at line 127 |
| `class-s3mo-tracker.php` | WordPress postmeta | update_post_meta / get_post_meta | WIRED | update_post_meta at lines 43-46; get_post_meta at lines 57, 68, 80-83; delete_post_meta at lines 96-99 |
| `class-s3mo-upload-handler.php` | `class-s3mo-client.php` | constructor injection | WIRED | Constructor accepts S3MO_Client at line 21; used as $this->client->upload_object() at line 97 |
| `class-s3mo-upload-handler.php` | `class-s3mo-tracker.php` | static method calls | WIRED | S3MO_Tracker::is_offloaded() at line 56; S3MO_Tracker::mark_as_offloaded() at line 114 |
| `class-s3mo-upload-handler.php` | WordPress filter | add_filter wp_generate_attachment_metadata | WIRED | add_filter('wp_generate_attachment_metadata', [$this, 'handle_upload'], 10, 3) at line 29 |
| `ct-s3-offloader.php` | `class-s3mo-upload-handler.php` | instantiation in plugins_loaded | WIRED | new S3MO_Upload_Handler($client) at line 86; register_hooks() called at line 87; OUTSIDE is_admin() |

---

### Requirements Coverage

| Requirement | Status | Supporting Evidence |
|-------------|--------|---------------------|
| UPLD-01 — Upload handler copies new media to S3 after WordPress saves locally | SATISFIED | handle_upload() on wp_generate_attachment_metadata; files are already written to disk when this filter fires |
| UPLD-02 — All thumbnail sizes uploaded alongside original | SATISFIED | $metadata['sizes'] loop at lines 74-83 builds upload entries for every size |
| UPLD-03 — S3 object key stored in _s3mo_key postmeta | SATISFIED | S3MO_Tracker::mark_as_offloaded() writes $files[0]['key'] as _s3mo_key |
| UPLD-04 — Uses wp_generate_attachment_metadata hook | SATISFIED | add_filter('wp_generate_attachment_metadata', ..., 10, 3) at line 29 |
| UPLD-05 — Failed uploads logged, do not break WordPress | SATISFIED | Per-file try/catch; error_log() on failure; always returns $metadata |
| UPLD-06 — Content-Type set from MIME type | SATISFIED | get_post_mime_type() for original, $size_data['mime-type'] for thumbnails; both passed to upload_object() |
| UPLD-07 — ObjectUploader used for automatic single/multipart | SATISFIED | ObjectUploader used in upload_object() — not putObject — enabling automatic multipart for large files |
| CDN-02 — Cache-Control header set on S3 objects | SATISFIED | 'public, max-age=31536000, immutable' in ObjectUploader params at line 136 |
| CDN-04 — Private ACL, no public bucket ACLs | SATISFIED | ACL is 'private' at line 132; OAC handles public access via CloudFront |

**All 9 requirements satisfied.**

---

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| None | — | — | — | — |

No TODO, FIXME, placeholder, stub, or empty-return patterns found in any of the four key files.

---

### Notable Implementation Details

**Per-file error isolation (UPLD-05):** Each file in the upload loop is wrapped in an individual try/catch (\Throwable) block. A failed thumbnail does not prevent other thumbnails or the original from uploading. This is correct.

**fopen RuntimeException path:** upload_object() throws RuntimeException (not AwsException) when fopen() fails. The upload handler catches \Throwable (not just AwsException), so this exception path is correctly handled and logged.

**Idempotency guard:** handle_upload() checks S3MO_Tracker::is_offloaded() before processing, preventing double-uploads if the filter fires twice.

**Context guard:** Only $context === 'create' is processed. Updates/regenerations do not re-offload in Phase 2 (deferred to a later phase — correct per plan).

**Partial upload behavior:** If some (but not all) files succeed, the attachment is NOT marked as offloaded. This means a re-offload attempt could re-upload already-uploaded files. This is the intended conservative behavior per the plan.

**Missing credentials path:** When S3MO_BUCKET/REGION/KEY/SECRET are absent, the upload handler is not registered. Uploads proceed normally in WordPress without S3 offload. This is correct and silent (only the admin notice in the settings page notifies the user).

---

### Human Verification Required

The automated structural verification is complete and all checks pass. The following items require a live WordPress environment with configured AWS credentials to confirm end-to-end behavior:

#### 1. Basic Upload Flow

**Test:** Upload a JPEG image through WordPress Admin > Media > Add New Media
**Expected:** The original file and all generated thumbnail sizes (thumbnail, medium, medium_large, large, and any theme-registered sizes) appear in the S3 bucket under `wp-content/uploads/YYYY/MM/`
**Why human:** Cannot simulate WordPress media processing or verify S3 bucket contents without live credentials

#### 2. S3 Object Headers

**Test:** In AWS S3 console or via `aws s3api head-object --bucket BUCKET --key KEY`, inspect a freshly uploaded object
**Expected:** `Content-Type: image/jpeg`, `Cache-Control: public, max-age=31536000, immutable`, no public-read ACL
**Why human:** S3 object metadata requires a live bucket and real upload

#### 3. Postmeta Tracking

**Test:** After upload, inspect the attachment in the database (wp_postmeta table) or via a postmeta viewer
**Expected:** `_s3mo_offloaded = 1`, `_s3mo_key = wp-content/uploads/YYYY/MM/filename.jpg`, `_s3mo_bucket = your-bucket-name`, `_s3mo_offloaded_at = ISO8601 timestamp`
**Why human:** Postmeta state requires a live WordPress upload

#### 4. Gutenberg / REST API Upload

**Test:** Open the Gutenberg block editor, add an Image block, upload a new image through the block editor upload interface
**Expected:** S3 offload occurs identically to the admin Media Library upload (REST API path goes through wp_generate_attachment_metadata identically)
**Why human:** REST API context requires a running WordPress instance with the block editor

#### 5. Failure Isolation

**Test:** Temporarily break S3 credentials (change S3MO_SECRET to an invalid value), then upload an image
**Expected:** WordPress media upload completes normally (image appears in Media Library), error entries appear in wp-content/debug.log with 'CT S3 Offloader:' prefix, `_s3mo_offloaded` is NOT set on the attachment
**Why human:** Controlled credential failure requires a live environment

---

## Gaps Summary

No gaps identified. All structural verification passed at all three levels (existence, substantive content, wiring). The phase goal is structurally complete and ready for live environment confirmation.

---

_Verified: 2026-02-28_
_Verifier: Claude (gsd-verifier)_
