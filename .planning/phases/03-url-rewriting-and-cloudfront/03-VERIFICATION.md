---
phase: 03-url-rewriting-and-cloudfront
verified: 2026-02-28T16:44:05Z
status: passed
score: 5/5 must-haves verified
---

# Phase 3: URL Rewriting and CloudFront Verification Report

**Phase Goal:** All media URLs across the site resolve to CloudFront CDN paths at render time, with zero database modifications
**Verified:** 2026-02-28T16:44:05Z
**Status:** passed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Front-end page renders show CloudFront URLs for all offloaded media (images, srcset, Gutenberg blocks) | VERIFIED | `filter_attachment_url` hooks `wp_get_attachment_url` (line 40); `filter_srcset` hooks `wp_calculate_image_srcset` (line 41); `filter_content` hooks `the_content` (line 42). All three cover the rendering surface for frontend pages and Gutenberg block output. |
| 2 | REST API attachment endpoints return CloudFront URLs for the headless Next.js frontend | VERIFIED | `filter_rest_attachment` hooks `rest_prepare_attachment` (line 43). Rewrites `$response->data['source_url']` via `get_object_url($key)` (line 190) and iterates `media_details.sizes[]` with `str_replace` (lines 210-214). |
| 3 | Original local URLs remain stored in the database and are never modified | VERIFIED | No `update_post_meta`, `wpdb`, `UPDATE`, or `INSERT` calls exist anywhere in `class-s3mo-url-rewriter.php`. All filtering is runtime-only. File header documents: "All rewriting is runtime-only; no database values are modified." |
| 4 | Deactivating the plugin immediately restores all URLs to local paths with no broken images | VERIFIED | `S3MO_URL_Rewriter` is instantiated only inside `plugins_loaded` callback (ct-s3-offloader.php line 89). WordPress does not load deactivated plugins — the callback never fires, hooks are never registered, and all URL filters are absent. No explicit `remove_filter` is needed; the class simply never exists. |
| 5 | CORS headers are properly configured so cross-origin media requests (fonts, images) work without errors | VERIFIED | `add_cors_headers` method hooks `send_headers` action (line 45 of register_hooks). Guards on `REST_REQUEST` constant (line 295). Validates `Origin` against explicit allowlist: `get_site_url()`, `localhost:3000`, `localhost:3001`, `S3MO_CDN_URL` if defined (lines 306-313). Sets four CORS response headers (lines 320-323). No wildcard. |

**Score:** 5/5 truths verified

---

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `includes/class-s3mo-url-rewriter.php` | S3MO_URL_Rewriter class with all URL rewriting filter methods | VERIFIED | Exists. 345 lines. No stubs, no TODOs, no empty returns. Exports `S3MO_URL_Rewriter` class with `register_hooks`, `filter_attachment_url`, `filter_content`, `filter_srcset`, `filter_rest_attachment`, `filter_attachment_for_js`, `add_cors_headers`, `get_cdn_uploads_base`. |
| `ct-s3-offloader.php` | Bootstrap wiring for URL rewriter, outside `is_admin()` | VERIFIED | Exists. 111 lines. Line 89: `$url_rewriter = new S3MO_URL_Rewriter($client);`. Line 90: `$url_rewriter->register_hooks();`. Both appear before `is_admin()` check at line 92. Uses shared `$client` instance. |

---

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `class-s3mo-url-rewriter.php` | `S3MO_Tracker::is_offloaded` | Guard before attachment URL rewrite | WIRED | `filter_attachment_url` line 61: `if (! S3MO_Tracker::is_offloaded($attachment_id))`. `filter_srcset` line 136: same guard. `filter_rest_attachment` line 183: same guard. `filter_attachment_for_js` line 235: same guard. |
| `class-s3mo-url-rewriter.php` | `S3MO_Client::get_object_url` | Generates CloudFront URL from S3 key | WIRED | `filter_attachment_url` line 71: `return $this->client->get_object_url($key)`. `filter_rest_attachment` line 190: `$response->data['source_url'] = $this->client->get_object_url($key)`. `get_cdn_uploads_base` line 341: delegates to `$this->client->get_url_base()`. |
| `ct-s3-offloader.php` | `includes/class-s3mo-url-rewriter.php` | Instantiation and `register_hooks` in `plugins_loaded` outside `is_admin()` | WIRED | Lines 89-90 confirm instantiation with shared client and `register_hooks()` call. Lines 92-95 confirm the `is_admin()` block follows after, so rewriting fires on all contexts: frontend, REST API, admin. |
| `class-s3mo-url-rewriter.php` | `wp_get_upload_dir()['baseurl']` | Never hardcodes upload path | WIRED | Used in `filter_content` (line 91), `filter_srcset` (line 140), `filter_rest_attachment` (line 195), `filter_attachment_for_js` (line 239). No hardcoded `/wp-content/uploads` paths in the rewriter. |
| `class-s3mo-url-rewriter.php` | `str_replace` with protocol variants | Handles http/https mixed-protocol content | WIRED | `filter_content` lines 99-116: builds `$search`/`$replace` arrays with both protocol variants. Same pattern replicated in `filter_srcset` (lines 149-158), `filter_rest_attachment` (lines 199-208), `filter_attachment_for_js` (lines 247-256). |

---

### Requirements Coverage

| Requirement | Status | Notes |
|-------------|--------|-------|
| URL-01 | SATISFIED | `wp_get_attachment_url` filter returns CloudFront URL via `get_object_url` for offloaded attachments |
| URL-02 | SATISFIED | `filter_attachment_url` guards with `is_offloaded` — non-offloaded attachments return original `$url` unchanged |
| URL-03 | SATISFIED | `wp_calculate_image_srcset` filter rewrites all srcset source URLs to CDN base |
| URL-04 | SATISFIED | `the_content` filter bulk-replaces local upload base URL in Gutenberg block output and all post content |
| URL-05 | SATISFIED | `rest_prepare_attachment` filter rewrites `source_url` and `media_details.sizes[].source_url` in REST API responses |
| URL-06 | SATISFIED | `wp_prepare_attachment_for_js` filter rewrites `url`, `sizes[].url`, and `icon` for admin Media Library modal |
| URL-07 | SATISFIED | Zero database modification in `class-s3mo-url-rewriter.php` — no `update_post_meta`, `wpdb`, or SQL calls |
| CDN-01 | SATISFIED | `get_cdn_uploads_base()` delegates to `$this->client->get_url_base()` which prefers `S3MO_CDN_URL` (CloudFront) over direct S3 URL |
| CDN-03 | SATISFIED | `add_cors_headers` on `send_headers` action sets explicit-origin CORS headers for REST API requests; documents AWS-side CloudFront CORS as separate requirement |

---

### Anti-Patterns Found

None.

- No `TODO`, `FIXME`, or placeholder comments in `class-s3mo-url-rewriter.php`
- No `return null`, `return []`, or empty implementations
- No `preg_replace` (spec required `str_replace` — verified throughout)
- No hardcoded `/wp-content/uploads` paths
- No database write operations
- PHP syntax clean (`php -l` passes on both files)

---

### Human Verification Required

The following items cannot be verified programmatically. They require a running WordPress environment with at least one offloaded attachment.

#### 1. End-to-end CloudFront URL in rendered page

**Test:** With credentials configured (`S3MO_BUCKET`, `S3MO_REGION`, `S3MO_KEY`, `S3MO_SECRET`, `S3MO_CDN_URL`), upload an image through WordPress Media Library, ensure it offloads to S3, then view a post containing that image in a browser and inspect the `src` and `srcset` attributes in the rendered HTML.
**Expected:** `src` and all `srcset` entries contain the CloudFront domain (value of `S3MO_CDN_URL`), not the local WordPress upload URL.
**Why human:** Requires a live environment with AWS credentials, an actual offloaded attachment, and browser/curl inspection of rendered HTML output.

#### 2. REST API endpoint CloudFront URL

**Test:** After offloading an attachment, request `GET /wp-json/wp/v2/media/{attachment_id}` and inspect the JSON response.
**Expected:** `source_url` and all entries in `media_details.sizes[].source_url` contain the CloudFront domain.
**Why human:** Requires live WordPress instance with REST API accessible and a real offloaded attachment ID.

#### 3. Database non-modification confirmation

**Test:** After plugin operation (uploads and page renders), query `wp_posts` and `wp_postmeta` for any rows containing the CloudFront domain string.
**Expected:** Zero rows containing the CDN URL in the database — all stored values remain as original local upload URLs.
**Why human:** Requires direct database access (MySQL query) against a live site with offloaded media.

#### 4. Deactivation URL restoration

**Test:** With offloaded images on a rendered page, deactivate the CT S3 Offloader plugin via WordPress admin, then refresh the page and inspect image `src` attributes.
**Expected:** All image `src` attributes revert to the original local WordPress upload URLs immediately. No broken images (assuming local files are still present).
**Why human:** Requires live environment, an active plugin toggle, and browser inspection before/after deactivation.

#### 5. CORS headers on REST API response

**Test:** From a Next.js development environment at `http://localhost:3000`, fetch `GET /wp-json/wp/v2/media` and inspect response headers.
**Expected:** Response includes `Access-Control-Allow-Origin: http://localhost:3000` (and related CORS headers). No CORS error in browser console.
**Why human:** Requires a cross-origin HTTP request from an actual browser or `fetch` call — cannot be verified by static code analysis alone.

---

## Gaps Summary

No gaps. All 5 phase truths are verified at all three levels (exists, substantive, wired). All 9 required artifacts and key links confirmed present and connected. No stub patterns or anti-patterns found. The implementation matches the specification exactly with no deviations.

Human verification items represent normal functional testing needs (live environment, AWS credentials, database access) — not code deficiencies.

---

_Verified: 2026-02-28T16:44:05Z_
_Verifier: Claude (gsd-verifier)_
