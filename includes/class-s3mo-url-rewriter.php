<?php
/**
 * S3MO_URL_Rewriter — Runtime URL rewriting for offloaded media.
 *
 * Filters WordPress attachment URLs and post content to replace local
 * upload paths with CloudFront CDN URLs. All rewriting is runtime-only;
 * no database values are modified.
 *
 * Hooks:
 *   wp_get_attachment_url      — Rewrites individual attachment URLs (canonical).
 *   wp_calculate_image_srcset  — Rewrites responsive srcset URLs to CDN.
 *   the_content                — Bulk-replaces local upload base in post content.
 *   rest_prepare_attachment    — Rewrites REST API attachment source_url and sizes.
 *   wp_prepare_attachment_for_js — Rewrites admin Media Library modal URLs.
 *
 * @package CT_S3_Offloader
 */

defined('ABSPATH') || exit;

class S3MO_URL_Rewriter {

    /** @var S3MO_Client */
    private S3MO_Client $client;

    /** @var string|null Cached CDN uploads base URL. */
    private ?string $cdn_uploads_base = null;

    /**
     * @param S3MO_Client $client Shared S3/CDN client instance.
     */
    public function __construct(S3MO_Client $client) {
        $this->client = $client;
    }

    /**
     * Register WordPress filter hooks for URL rewriting.
     */
    public function register_hooks(): void {
        add_filter('wp_get_attachment_url', [$this, 'filter_attachment_url'], 10, 2);
        add_filter('wp_calculate_image_srcset', [$this, 'filter_srcset'], 10, 5);
        add_filter('the_content', [$this, 'filter_content'], 10, 1);
        add_filter('rest_prepare_attachment', [$this, 'filter_rest_attachment'], 10, 3);
        add_filter('wp_prepare_attachment_for_js', [$this, 'filter_attachment_for_js'], 10, 3);
        add_action('send_headers', [$this, 'add_cors_headers'], 10);
    }

    /**
     * Rewrite an individual attachment URL to its CloudFront equivalent.
     *
     * Only rewrites if the attachment is tracked as offloaded. Non-offloaded
     * attachments pass through unchanged. Safe against double-rewriting
     * because get_object_url always produces the same deterministic URL.
     *
     * @param string $url           The original local attachment URL.
     * @param int    $attachment_id The attachment post ID.
     *
     * @return string CloudFront URL if offloaded, original URL otherwise.
     */
    public function filter_attachment_url(string $url, int $attachment_id): string {
        if (! S3MO_Tracker::is_offloaded($attachment_id)) {
            return $url;
        }

        $key = S3MO_Tracker::get_s3_key($attachment_id);

        if (empty($key)) {
            return $url;
        }

        return $this->client->get_object_url($key);
    }

    /**
     * Replace local upload base URLs with CDN URLs in post/page content.
     *
     * Uses str_replace for speed and simplicity. Handles both http:// and
     * https:// protocol variants to account for mixed-protocol content
     * stored in the database. Does not touch the database — runtime only.
     *
     * @param string $content The post content (HTML).
     *
     * @return string Content with local upload URLs replaced by CDN URLs.
     */
    public function filter_content(string $content): string {
        if (empty($content)) {
            return $content;
        }

        $upload_dir = wp_get_upload_dir();
        $local_base = $upload_dir['baseurl'];
        $cdn_base   = $this->get_cdn_uploads_base();

        if ($local_base === $cdn_base) {
            return $content;
        }

        // Build replacement pairs for both http and https variants.
        $search  = [];
        $replace = [];

        $search[]  = $local_base;
        $replace[] = $cdn_base;

        // Add the opposite-protocol variant.
        if (strpos($local_base, 'https://') === 0) {
            $http_variant = 'http://' . substr($local_base, 8);
            $search[]     = $http_variant;
            $replace[]    = $cdn_base;
        } elseif (strpos($local_base, 'http://') === 0) {
            $https_variant = 'https://' . substr($local_base, 7);
            $search[]      = $https_variant;
            $replace[]     = $cdn_base;
        }

        return str_replace($search, $replace, $content);
    }

    /**
     * Rewrite responsive srcset URLs to CDN for offloaded attachments.
     *
     * WordPress drops the entire srcset if source URLs do not share the
     * same domain as the main image src (already rewritten by
     * wp_get_attachment_url). This filter ensures all srcset entries
     * also point to CloudFront.
     *
     * @param array  $sources        Array of image source data (url, descriptor, value).
     * @param array  $size_array     Requested image size (width, height).
     * @param string $image_src      The src attribute for the image.
     * @param array  $image_meta     Attachment image metadata.
     * @param int    $attachment_id  The attachment post ID.
     *
     * @return array Modified sources with CDN URLs if offloaded.
     */
    public function filter_srcset(array $sources, array $size_array, string $image_src, array $image_meta, int $attachment_id): array {
        if (! S3MO_Tracker::is_offloaded($attachment_id)) {
            return $sources;
        }

        $upload_dir = wp_get_upload_dir();
        $local_base = $upload_dir['baseurl'];
        $cdn_base   = $this->get_cdn_uploads_base();

        if ($local_base === $cdn_base) {
            return $sources;
        }

        // Build search/replace pairs for both protocol variants.
        $search  = [$local_base];
        $replace = [$cdn_base];

        if (strpos($local_base, 'https://') === 0) {
            $search[]  = 'http://' . substr($local_base, 8);
            $replace[] = $cdn_base;
        } elseif (strpos($local_base, 'http://') === 0) {
            $search[]  = 'https://' . substr($local_base, 7);
            $replace[] = $cdn_base;
        }

        foreach ($sources as &$source) {
            $source['url'] = str_replace($search, $replace, $source['url']);
        }

        return $sources;
    }

    /**
     * Rewrite attachment URLs in REST API responses.
     *
     * Critical for the headless Next.js frontend which reads attachment
     * data from /wp-json/wp/v2/media. Rewrites both the top-level
     * source_url and each thumbnail size source_url.
     *
     * @param WP_REST_Response $response   The REST response object.
     * @param WP_Post          $attachment The attachment post object.
     * @param WP_REST_Request  $request    The REST request object.
     *
     * @return WP_REST_Response Modified response with CDN URLs.
     */
    public function filter_rest_attachment($response, $attachment, $request) {
        $attachment_id = $attachment->ID;

        if (! S3MO_Tracker::is_offloaded($attachment_id)) {
            return $response;
        }

        // Rewrite top-level source_url using the canonical S3 key.
        $key = S3MO_Tracker::get_s3_key($attachment_id);
        if (! empty($key)) {
            $response->data['source_url'] = $this->client->get_object_url($key);
        }

        // Rewrite thumbnail size URLs via str_replace.
        if (! empty($response->data['media_details']['sizes'])) {
            $upload_dir = wp_get_upload_dir();
            $local_base = $upload_dir['baseurl'];
            $cdn_base   = $this->get_cdn_uploads_base();

            $search  = [$local_base];
            $replace = [$cdn_base];

            if (strpos($local_base, 'https://') === 0) {
                $search[]  = 'http://' . substr($local_base, 8);
                $replace[] = $cdn_base;
            } elseif (strpos($local_base, 'http://') === 0) {
                $search[]  = 'https://' . substr($local_base, 7);
                $replace[] = $cdn_base;
            }

            foreach ($response->data['media_details']['sizes'] as &$size) {
                if (isset($size['source_url'])) {
                    $size['source_url'] = str_replace($search, $replace, $size['source_url']);
                }
            }
        }

        return $response;
    }

    /**
     * Rewrite attachment URLs in the admin Media Library modal (wp.media).
     *
     * Keeps the WordPress admin experience consistent by showing CDN URLs
     * for offloaded media in the grid/list modal views.
     *
     * @param array   $response   Array of prepared attachment data.
     * @param WP_Post $attachment The attachment post object.
     * @param array   $meta       Attachment metadata (wp_get_attachment_metadata).
     *
     * @return array Modified response with CDN URLs.
     */
    public function filter_attachment_for_js(array $response, $attachment, $meta): array {
        $attachment_id = $attachment->ID;

        if (! S3MO_Tracker::is_offloaded($attachment_id)) {
            return $response;
        }

        $upload_dir = wp_get_upload_dir();
        $local_base = $upload_dir['baseurl'];
        $cdn_base   = $this->get_cdn_uploads_base();

        if ($local_base === $cdn_base) {
            return $response;
        }

        $search  = [$local_base];
        $replace = [$cdn_base];

        if (strpos($local_base, 'https://') === 0) {
            $search[]  = 'http://' . substr($local_base, 8);
            $replace[] = $cdn_base;
        } elseif (strpos($local_base, 'http://') === 0) {
            $search[]  = 'https://' . substr($local_base, 7);
            $replace[] = $cdn_base;
        }

        // Rewrite main URL.
        if (! empty($response['url'])) {
            $response['url'] = str_replace($search, $replace, $response['url']);
        }

        // Rewrite thumbnail size URLs.
        if (! empty($response['sizes'])) {
            foreach ($response['sizes'] as &$size) {
                if (isset($size['url'])) {
                    $size['url'] = str_replace($search, $replace, $size['url']);
                }
            }
        }

        // Rewrite icon URL (mime-type icon fallback).
        if (! empty($response['icon'])) {
            $response['icon'] = str_replace($search, $replace, $response['icon']);
        }

        return $response;
    }

    /**
     * Set CORS headers on WP REST API responses for cross-origin media requests.
     *
     * Enables the headless Next.js frontend to fetch attachment data from
     * /wp-json/wp/v2/media without cross-origin errors. Uses an explicit
     * allowlist of origins — never a wildcard.
     *
     * CORS Architecture (both layers are needed for a fully working headless setup):
     *   1. WP REST API CORS: Handled by this method (for /wp-json/ requests).
     *   2. CloudFront CORS: Requires separate AWS configuration:
     *      - S3 bucket CORS rules allowing GET/HEAD from allowed origins.
     *      - CloudFront Origin Request Policy to forward the Origin header.
     *      - CloudFront Response Headers Policy to pass CORS headers through.
     */
    public function add_cors_headers(): void {
        if (! defined('REST_REQUEST') || ! REST_REQUEST) {
            return;
        }

        $origin = isset($_SERVER['HTTP_ORIGIN']) ? esc_url_raw($_SERVER['HTTP_ORIGIN']) : '';

        if (empty($origin)) {
            return;
        }

        // Build allowed origins list.
        $allowed = [get_site_url()];

        if (defined('S3MO_CDN_URL') && S3MO_CDN_URL) {
            $allowed[] = rtrim(S3MO_CDN_URL, '/');
        }

        // Allow a separate frontend origin (e.g. Next.js, Astro) defined in wp-config.php.
        if (defined('S3MO_FRONTEND_URL') && S3MO_FRONTEND_URL) {
            $allowed[] = rtrim(S3MO_FRONTEND_URL, '/');
        }

        /** Filter the CORS allowed origins list for additional origins. */
        $allowed = apply_filters('s3mo_cors_allowed_origins', $allowed);

        if (! in_array($origin, $allowed, true)) {
            return;
        }

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Expose-Headers: Content-Length, Content-Range');
    }

    /**
     * Get the CDN base URL for the uploads directory.
     *
     * Combines the CDN origin URL with the configured path prefix.
     * Cached after first call to avoid repeated option lookups.
     *
     * @return string CDN uploads base URL (no trailing slash).
     */
    private function get_cdn_uploads_base(): string {
        if ($this->cdn_uploads_base !== null) {
            return $this->cdn_uploads_base;
        }

        $prefix = ltrim(get_option('s3mo_path_prefix', 'wp-content/uploads'), '/');

        $this->cdn_uploads_base = $this->client->get_url_base() . '/' . $prefix;

        return $this->cdn_uploads_base;
    }
}
