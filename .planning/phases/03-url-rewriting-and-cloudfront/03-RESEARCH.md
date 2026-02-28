# Phase 3: URL Rewriting and CloudFront - Research

**Researched:** 2026-02-28
**Domain:** WordPress media URL rewriting, CloudFront CDN integration, REST API filtering
**Confidence:** HIGH

## Summary

This phase implements runtime URL rewriting so all offloaded media references resolve to CloudFront CDN paths without modifying the database. The approach uses WordPress's extensive filter hook system to intercept URL generation at every layer: attachment URLs, responsive srcset attributes, post content HTML, and REST API responses.

The critical insight is that WordPress REST API `content.rendered` already applies `the_content` filters, so content URL rewriting automatically propagates to the headless Next.js frontend. A dedicated `rest_prepare_attachment` filter handles attachment endpoint metadata separately. The entire system is runtime-only -- deactivating the plugin removes all filter hooks, and original local URLs stored in the database serve correctly without any migration.

**Primary recommendation:** Create a single `S3MO_URL_Rewriter` class that registers all URL rewriting filters, using `S3MO_Tracker::is_offloaded()` and `S3MO_Client::get_object_url()` from the existing codebase. Use `the_content` with `str_replace` for bulk content rewriting, and targeted filters for attachment URLs and srcset.

## Standard Stack

### Core (WordPress Filter Hooks -- No External Libraries Needed)

| Hook | Type | Parameters | Purpose |
|------|------|------------|---------|
| `wp_get_attachment_url` | filter | `$url, $attachment_id` | Rewrites the canonical attachment URL used everywhere |
| `wp_get_attachment_image_src` | filter | `$image, $attachment_id, $size, $icon` | Rewrites image source arrays returned by core |
| `wp_calculate_image_srcset` | filter | `$sources, $size_array, $image_src, $image_meta, $attachment_id` | Rewrites all responsive srcset URL entries |
| `the_content` | filter | `$content` | Rewrites URLs in rendered post/page content (Gutenberg blocks included) |
| `rest_prepare_attachment` | filter | `$response, $post, $request` | Rewrites URLs in REST API attachment responses |
| `wp_prepare_attachment_for_js` | filter | `$response, $attachment, $meta` | Rewrites URLs in Media Library modal (admin UX) |

### Supporting (Existing Plugin Classes)

| Class | Method | Purpose |
|-------|--------|---------|
| `S3MO_Tracker` | `is_offloaded($id)` | Check if attachment is on S3 |
| `S3MO_Tracker` | `get_s3_key($id)` | Get S3 object key for URL building |
| `S3MO_Client` | `get_object_url($key)` | Build full CloudFront URL from key |
| `S3MO_Client` | `get_url_base()` | Get CloudFront domain (or S3 fallback) |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `the_content` str_replace | `render_block` filter per block type | Per-block is more surgical but the_content catches everything including classic editor, shortcodes, and third-party blocks in one pass |
| Individual srcset URL loop | `wp_calculate_image_srcset` filter | The filter is the correct hook; looping through `$sources` array and replacing URLs is the standard pattern |
| Output buffering (ob_start) | Filter-based approach | OB is fragile, breaks with caching plugins, and is an anti-pattern for WordPress |

## Architecture Patterns

### Recommended Project Structure

```
ct-s3-offloader/
  includes/
    class-s3mo-url-rewriter.php    # NEW - All URL rewriting logic
    class-s3mo-client.php          # EXISTING - get_object_url(), get_url_base()
    class-s3mo-tracker.php         # EXISTING - is_offloaded(), get_s3_key()
    class-s3mo-upload-handler.php  # EXISTING - unchanged
```

### Pattern 1: Single Rewriter Class with Dependency Injection

**What:** One class encapsulates all URL rewriting filters. Receives `S3MO_Client` via constructor.
**When to use:** Always -- this is the only pattern needed.

```php
// Source: WordPress Developer Reference + existing codebase patterns
class S3MO_URL_Rewriter {

    private S3MO_Client $client;

    public function __construct(S3MO_Client $client) {
        $this->client = $client;
    }

    public function register_hooks(): void {
        // Attachment URL (the foundation -- many other functions call this)
        add_filter('wp_get_attachment_url', [$this, 'rewrite_attachment_url'], 10, 2);

        // Responsive srcset
        add_filter('wp_calculate_image_srcset', [$this, 'rewrite_srcset'], 10, 5);

        // Post content (covers Gutenberg blocks, classic editor, shortcodes)
        add_filter('the_content', [$this, 'rewrite_content_urls'], 10, 1);

        // REST API attachment responses (for Next.js headless frontend)
        add_filter('rest_prepare_attachment', [$this, 'rewrite_rest_attachment'], 10, 3);

        // Admin Media Library modal
        add_filter('wp_prepare_attachment_for_js', [$this, 'rewrite_attachment_for_js'], 10, 3);
    }
}
```

### Pattern 2: Content URL Replacement via Upload Base

**What:** Replace the local upload base URL with the CloudFront base in content HTML.
**When to use:** For `the_content` filter -- covers all image references in one pass.

```php
// Source: Common pattern in WP Offload Media, Advanced Media Offloader
public function rewrite_content_urls(string $content): string {
    $upload_dir = wp_get_upload_dir();
    $local_base = $upload_dir['baseurl']; // e.g. https://example.com/wp-content/uploads

    $prefix     = get_option('s3mo_path_prefix', 'wp-content/uploads');
    $cdn_base   = $this->client->get_url_base() . '/' . $prefix;

    // Simple string replacement -- fast and covers all occurrences
    return str_replace($local_base, $cdn_base, $content);
}
```

### Pattern 3: Srcset Array Rewriting

**What:** Loop through `$sources` array and replace each URL.
**When to use:** For `wp_calculate_image_srcset` filter.

```php
// Source: WordPress Developer Reference wp_calculate_image_srcset hook
public function rewrite_srcset(array $sources, array $size_array, string $image_src, array $image_meta, int $attachment_id): array {
    if (! S3MO_Tracker::is_offloaded($attachment_id)) {
        return $sources;
    }

    $upload_dir = wp_get_upload_dir();
    $local_base = $upload_dir['baseurl'];
    $prefix     = get_option('s3mo_path_prefix', 'wp-content/uploads');
    $cdn_base   = $this->client->get_url_base() . '/' . $prefix;

    foreach ($sources as &$source) {
        $source['url'] = str_replace($local_base, $cdn_base, $source['url']);
    }

    return $sources;
}
```

### Anti-Patterns to Avoid

- **Database modification:** NEVER update `guid`, `_wp_attached_file`, or `_wp_attachment_metadata` in the database. This violates URL-06 and makes deactivation destructive.
- **Output buffering (`ob_start`):** Fragile, incompatible with page caching plugins, and breaks streaming responses.
- **Regex for simple URL replacement:** `str_replace` is faster and sufficient when replacing a known base URL. Only use regex if pattern matching is needed (it is not here).
- **Hardcoding the upload path:** Always use `wp_get_upload_dir()['baseurl']` to get the local URL base. Never assume `/wp-content/uploads/`.
- **Filtering without offload check:** Every attachment-specific filter MUST check `S3MO_Tracker::is_offloaded()` first to avoid rewriting non-offloaded media.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| URL base detection | Custom path parsing | `wp_get_upload_dir()['baseurl']` | WordPress handles multisite, HTTPS, custom upload dirs |
| Content URL replacement | Custom HTML parser or regex | `str_replace($local_base, $cdn_base, $content)` | Simple base-URL swap covers all cases; HTML parsing is overkill |
| Srcset generation | Manual srcset building | `wp_calculate_image_srcset` filter | WordPress core builds srcset; just filter the URLs |
| REST API response structure | Custom REST endpoints | `rest_prepare_attachment` filter | Modifies existing response in place; no custom routes needed |
| CORS headers | PHP header() calls | CloudFront Response Headers Policy + S3 CORS config | AWS-level CORS is more reliable than application-level |
| Deactivation cleanup | Database migration on deactivate | Nothing -- filters simply stop running | The entire design is runtime-only; deactivation is automatic |

**Key insight:** The "deactivation restores all URLs" requirement (URL-07) is automatically satisfied by the filter-based approach. When the plugin deactivates, its filters are never registered, so WordPress returns original database-stored URLs. Zero cleanup code needed.

## Common Pitfalls

### Pitfall 1: Double Rewriting

**What goes wrong:** `wp_get_attachment_url` is called inside other functions that also have filters, causing URLs to be rewritten twice (e.g., CloudFront base replaced with itself).
**Why it happens:** `wp_get_attachment_image_src` internally calls `wp_get_attachment_url`. If you filter both, the URL may already be rewritten when the second filter fires.
**How to avoid:** Filter `wp_get_attachment_url` as the canonical rewrite point. In `wp_calculate_image_srcset`, use `str_replace` on the local base URL -- if it is already a CDN URL, `str_replace` is a no-op (no match). Avoid filtering `wp_get_attachment_image_src` separately if `wp_get_attachment_url` already covers it.
**Warning signs:** URLs like `https://cdn.example.com/https://cdn.example.com/...`

### Pitfall 2: Srcset Mismatch Causes WordPress to Drop Responsive Images

**What goes wrong:** WordPress drops the entire srcset if the `src` URL domain differs from srcset URLs domain. Core checks `wp_get_attachment_url()` against srcset sources.
**Why it happens:** WordPress 4.4+ validates that srcset sources match the image src domain.
**How to avoid:** Ensure `wp_get_attachment_url` filter fires BEFORE `wp_calculate_image_srcset`. Since `wp_get_attachment_url` runs at priority 10 and is called during srcset calculation, this is naturally handled. But do NOT use a late priority on `wp_get_attachment_url`.
**Warning signs:** Images render with `src` but no `srcset`/`sizes` attributes.

### Pitfall 3: REST API Missing CDN URLs for Thumbnails

**What goes wrong:** REST API returns CDN URL for the main `source_url` but local URLs for `media_details.sizes[].source_url`.
**Why it happens:** The REST API builds size URLs from metadata, not from `wp_get_attachment_url`. The `rest_prepare_attachment` filter must explicitly walk the sizes array.
**How to avoid:** In `rest_prepare_attachment`, iterate over `$response->data['media_details']['sizes']` and rewrite each `source_url`.
**Warning signs:** Next.js frontend shows CDN URL for full image but local URLs for thumbnails.

### Pitfall 4: Admin Media Library Shows Broken Thumbnails

**What goes wrong:** Media Library grid shows broken images because admin is not set up to reach CloudFront.
**Why it happens:** `wp_prepare_attachment_for_js` filter not registered, or CloudFront domain is not accessible from admin network.
**How to avoid:** Register the `wp_prepare_attachment_for_js` filter. Ensure CloudFront distribution is publicly accessible (it should be with OAC).
**Warning signs:** Media Library thumbnails show broken image icons.

### Pitfall 5: CORS Blocks Fonts and Cross-Origin Images

**What goes wrong:** Browser console shows CORS errors for fonts (woff2) or images loaded via `<img crossorigin>`.
**Why it happens:** CloudFront does not forward the `Origin` request header to S3, so S3 never returns CORS headers.
**How to avoid:** Configure S3 CORS policy AND CloudFront cache behavior to forward the `Origin` header. Use a CloudFront Response Headers Policy for consistent CORS headers.
**Warning signs:** Console errors: "has been blocked by CORS policy: No 'Access-Control-Allow-Origin' header"

### Pitfall 6: Content Rewriting Misses Protocol Variants

**What goes wrong:** Some stored URLs use `http://` while the site now serves `https://`, so `str_replace` misses them.
**Why it happens:** Legacy content may have been inserted with HTTP protocol.
**How to avoid:** Normalize both the local base and content URLs to use the same protocol, or replace both HTTP and HTTPS variants.
**Warning signs:** Some images still point to local server despite plugin being active.

## Code Examples

### Complete wp_get_attachment_url Filter

```php
// Source: WordPress Developer Reference + S3 offload pattern
public function rewrite_attachment_url(string $url, int $attachment_id): string {
    if (! S3MO_Tracker::is_offloaded($attachment_id)) {
        return $url;
    }

    $key = S3MO_Tracker::get_s3_key($attachment_id);
    if (empty($key)) {
        return $url;
    }

    return $this->client->get_object_url($key);
}
```

### Complete REST API Attachment Rewriting

```php
// Source: WordPress Developer Reference rest_prepare_attachment hook
public function rewrite_rest_attachment(
    \WP_REST_Response $response,
    \WP_Post $post,
    \WP_REST_Request $request
): \WP_REST_Response {
    $attachment_id = $post->ID;

    if (! S3MO_Tracker::is_offloaded($attachment_id)) {
        return $response;
    }

    $data = $response->get_data();

    // Rewrite source_url (main image URL)
    if (! empty($data['source_url'])) {
        $key = S3MO_Tracker::get_s3_key($attachment_id);
        if (! empty($key)) {
            $data['source_url'] = $this->client->get_object_url($key);
        }
    }

    // Rewrite all thumbnail size URLs
    if (! empty($data['media_details']['sizes'])) {
        $upload_dir = wp_get_upload_dir();
        $local_base = $upload_dir['baseurl'];
        $prefix     = get_option('s3mo_path_prefix', 'wp-content/uploads');
        $cdn_base   = $this->client->get_url_base() . '/' . $prefix;

        foreach ($data['media_details']['sizes'] as $size => &$size_data) {
            if (! empty($size_data['source_url'])) {
                $size_data['source_url'] = str_replace(
                    $local_base,
                    $cdn_base,
                    $size_data['source_url']
                );
            }
        }
    }

    $response->set_data($data);
    return $response;
}
```

### Complete Content URL Rewriting (Covers Gutenberg + Classic)

```php
// Source: Pattern from WP Offload Media + Advanced Media Offloader
public function rewrite_content_urls(string $content): string {
    if (empty($content)) {
        return $content;
    }

    $upload_dir = wp_get_upload_dir();
    $local_base = $upload_dir['baseurl'];
    $prefix     = get_option('s3mo_path_prefix', 'wp-content/uploads');
    $cdn_base   = $this->client->get_url_base() . '/' . $prefix;

    // Handle both http and https variants of the local base
    $search = [
        $local_base,
        str_replace('https://', 'http://', $local_base),
    ];
    $replace = [
        $cdn_base,
        $cdn_base,
    ];

    return str_replace($search, $replace, $content);
}
```

### Media Library Modal Rewriting

```php
// Source: WordPress Developer Reference wp_prepare_attachment_for_js hook
public function rewrite_attachment_for_js(
    array $response,
    \WP_Post $attachment,
    array|false $meta
): array {
    $attachment_id = $attachment->ID;

    if (! S3MO_Tracker::is_offloaded($attachment_id)) {
        return $response;
    }

    $upload_dir = wp_get_upload_dir();
    $local_base = $upload_dir['baseurl'];
    $prefix     = get_option('s3mo_path_prefix', 'wp-content/uploads');
    $cdn_base   = $this->client->get_url_base() . '/' . $prefix;

    // Rewrite main URL
    if (! empty($response['url'])) {
        $response['url'] = str_replace($local_base, $cdn_base, $response['url']);
    }

    // Rewrite all size URLs
    if (! empty($response['sizes'])) {
        foreach ($response['sizes'] as $size => &$size_data) {
            if (! empty($size_data['url'])) {
                $size_data['url'] = str_replace($local_base, $cdn_base, $size_data['url']);
            }
        }
    }

    // Rewrite icon URL
    if (! empty($response['icon'])) {
        $response['icon'] = str_replace($local_base, $cdn_base, $response['icon']);
    }

    return $response;
}
```

### Bootstrap Integration (in ct-s3-offloader.php)

```php
// Inside the plugins_loaded callback, after upload_handler:
$url_rewriter = new S3MO_URL_Rewriter($client);
$url_rewriter->register_hooks();
```

### S3 CORS Configuration (for reference -- AWS Console)

```json
[
    {
        "AllowedHeaders": ["*"],
        "AllowedMethods": ["GET", "HEAD"],
        "AllowedOrigins": [
            "https://clemsonsportsmedia.com",
            "https://www.clemsonsportsmedia.com",
            "http://localhost:3000"
        ],
        "ExposeHeaders": [
            "Content-Length",
            "Content-Type",
            "ETag"
        ],
        "MaxAgeSeconds": 86400
    }
]
```

### CloudFront Cache Behavior Settings (for reference)

- **Cache Policy:** Forward `Origin` header (required for CORS)
- **Origin Request Policy:** Use `CORS-S3Origin` managed policy (or custom policy that forwards `Origin`, `Access-Control-Request-Method`, `Access-Control-Request-Headers`)
- **Response Headers Policy:** Use `CORS-with-preflight-and-SecurityHeadersPolicy` managed policy, or create custom policy with:
  - `Access-Control-Allow-Origin`: configured origins
  - `Access-Control-Allow-Methods`: `GET, HEAD, OPTIONS`
  - `Access-Control-Max-Age`: `86400`

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Rewrite `guid` column in DB | Runtime filters only (never touch DB) | WordPress 4.0+ best practice | Deactivation is safe and instant |
| Custom srcset generation | `wp_calculate_image_srcset` filter | WordPress 4.4 (Dec 2015) | Standard hook for responsive image CDN rewriting |
| Output buffering for URL rewriting | `the_content` + targeted filters | Always preferred | OB is fragile with caching plugins |
| Custom REST endpoints for CDN URLs | `rest_prepare_attachment` filter | WordPress 4.7 (Dec 2016) | Modifies existing endpoints cleanly |
| Manual CORS headers via PHP | CloudFront Response Headers Policy | AWS feature (2021+) | More reliable, no PHP involvement needed |
| `wp_content_img_tag` for per-image | `the_content` str_replace for base URL swap | WordPress 6.0 added wp_content_img_tag, but str_replace is simpler | wp_content_img_tag is useful for per-image transforms, not base URL swap |

**Deprecated/outdated:**
- Modifying `guid` field: WordPress documentation explicitly warns against this
- Using `wp_get_attachment_image_src` filter for CDN: Redundant if `wp_get_attachment_url` is already filtered, since image_src calls it internally

## Open Questions

1. **Non-image media (video, audio, documents)**
   - What we know: `wp_get_attachment_url` covers all attachment types, not just images. `the_content` str_replace covers embedded video/audio URLs.
   - What's unclear: Whether any video/audio shortcodes bypass these filters.
   - Recommendation: Test with WordPress audio/video shortcodes after implementation; add `wp_audio_shortcode` / `wp_video_shortcode` filters if needed.

2. **Third-party page builders (Elementor, Beaver Builder)**
   - What we know: Most page builders store content in `post_content` and use `the_content` filter.
   - What's unclear: Whether this site uses any page builders that store media URLs in custom postmeta.
   - Recommendation: Verify with current theme/plugins. The headless architecture suggests standard Gutenberg, making this low risk.

3. **Existing uploaded media pre-offload**
   - What we know: Only offloaded media gets CDN URLs (checked via `is_offloaded`). Non-offloaded media keeps local URLs.
   - What's unclear: Whether a bulk offload for existing media is planned.
   - Recommendation: This is Phase 3 scope -- URL rewriting only. Bulk migration would be a separate phase.

## Sources

### Primary (HIGH confidence)
- [wp_get_attachment_url hook](https://developer.wordpress.org/reference/hooks/wp_get_attachment_url/) - Filter signature and parameters
- [wp_calculate_image_srcset hook](https://developer.wordpress.org/reference/hooks/wp_calculate_image_srcset/) - Srcset filter with 5 parameters
- [rest_prepare_attachment hook](https://developer.wordpress.org/reference/hooks/rest_prepare_attachment/) - REST API attachment filter
- [the_content hook](https://developer.wordpress.org/reference/hooks/the_content/) - Content filtering
- [S3 CORS configuration](https://docs.aws.amazon.com/AmazonS3/latest/userguide/ManageCorsUsing.html) - Official AWS CORS docs
- [WordPress all image URL filters gist](https://gist.github.com/davidsword/b33bbf37a5b9eb112e316b53bf6860e2) - Comprehensive filter list

### Secondary (MEDIUM confidence)
- [WP Offload Media Developer Guide](https://deliciousbrains.com/wp-offload-media/doc/developer-guide/) - Industry-standard plugin patterns
- [WordPress responsive images](https://developer.wordpress.org/apis/responsive-images/) - Srcset/sizes architecture
- [AWS CloudFront CORS blog](https://aws.amazon.com/blogs/networking-and-content-delivery/cors-configuration-through-amazon-cloudfront/) - CloudFront CORS setup

### Tertiary (LOW confidence)
- REST API applies `the_content` filters to `content.rendered` - verified via multiple sources but not official core docs directly

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - All hooks verified via official WordPress Developer Reference
- Architecture: HIGH - Pattern follows established WP Offload Media / Advanced Media Offloader approach
- Pitfalls: HIGH - Well-documented issues in WordPress Trac tickets and plugin issue trackers
- CORS config: MEDIUM - AWS docs verified but specific CloudFront managed policy names should be confirmed in AWS Console

**Research date:** 2026-02-28
**Valid until:** 2026-04-28 (stable WordPress hooks, unlikely to change)
