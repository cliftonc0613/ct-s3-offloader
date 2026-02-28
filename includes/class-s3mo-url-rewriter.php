<?php
/**
 * S3MO_URL_Rewriter — Runtime URL rewriting for offloaded media.
 *
 * Filters WordPress attachment URLs and post content to replace local
 * upload paths with CloudFront CDN URLs. All rewriting is runtime-only;
 * no database values are modified.
 *
 * Hooks:
 *   wp_get_attachment_url — Rewrites individual attachment URLs (canonical).
 *   the_content           — Bulk-replaces local upload base in post content.
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
        add_filter('the_content', [$this, 'filter_content'], 10, 1);
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
