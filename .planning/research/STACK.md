# Technology Stack: ct-s3-offloader

**Project:** WordPress S3 Media Offloader Plugin
**Researched:** 2026-02-27
**Overall Confidence:** HIGH

## Recommended Stack

### Core Runtime

| Technology | Version | Purpose | Why | Confidence |
|------------|---------|---------|-----|------------|
| PHP | >= 8.1 | Plugin runtime | Required by AWS SDK v3. WordPress 6.x requires PHP 7.4+, but 8.1 is the SDK floor. Most hosts run 8.1+ now. Local by Flywheel ships 8.1+. | HIGH |
| WordPress | 6.4+ | Host CMS | Current stable line. Use `wp_update_attachment_metadata` and `wp_get_attachment_url` hooks which are stable across 6.x. | HIGH |

### AWS SDK

| Technology | Version | Purpose | Why | Confidence |
|------------|---------|---------|-----|------------|
| AWS SDK for PHP | 3.371.x (phar) | S3 and CloudFront API calls | Latest stable as of 2026-02-27. Use the **phar distribution** -- single file, includes autoloader, no Composer needed. Download from official AWS releases. | HIGH |

**Bundling approach -- use the phar, not the zip:**

```
plugin-root/
  vendor/
    aws.phar          # ~80MB single file, includes everything
  ct-s3-offloader.php # require_once __DIR__ . '/vendor/aws.phar';
```

**Why phar over zip:**
- Single file vs. thousands of extracted files -- cleaner plugin distribution
- Built-in class autoloader -- no manual autoload configuration
- Official AWS distribution method for non-Composer environments
- Identical API to Composer install

**Why NOT Composer:**
- Plugin must be installable via WordPress admin upload (zip)
- Users should not need CLI access to install dependencies
- WordPress plugin directory guidelines discourage Composer-in-production
- Phar bundles all dependencies (GuzzleHttp, Psr, etc.) internally

**SDK initialization pattern:**

```php
require_once __DIR__ . '/vendor/aws.phar';

use Aws\S3\S3Client;

$client = new S3Client([
    'version' => 'latest',
    'region'  => get_option('ct_s3_region', 'us-east-1'),
    'credentials' => [
        'key'    => defined('CT_S3_ACCESS_KEY') ? CT_S3_ACCESS_KEY : get_option('ct_s3_access_key'),
        'secret' => defined('CT_S3_SECRET_KEY') ? CT_S3_SECRET_KEY : get_option('ct_s3_secret_key'),
    ],
]);
```

**Credential priority (security best practice):**
1. `wp-config.php` constants (recommended -- keeps secrets out of DB)
2. Database options (fallback for non-technical users)
3. IAM instance profile (for EC2/ECS deployments -- SDK auto-detects)

### Upload Strategy

| Method | When | Why |
|--------|------|-----|
| `S3Client::putObject()` | Files < 100MB | Simple, single request. All WordPress media uploads are typically under 100MB. |
| `ObjectUploader` | Files of unknown size | Auto-selects putObject vs multipart based on size. Safest default for WP-CLI bulk operations. |
| `MultipartUploader` | Files > 100MB | Chunked upload with resume capability. Unlikely for typical media but good for video files. |

**Recommendation:** Use `ObjectUploader` as the default. It handles both small and large files automatically. Only drop to `putObject` if you need maximum simplicity for known-small files.

### WordPress Hooks (Core Integration Points)

These are not "libraries" but are the critical WordPress APIs the plugin must hook into:

| Hook | Type | Purpose | Confidence |
|------|------|---------|------------|
| `wp_update_attachment_metadata` | Filter | Intercept completed uploads, trigger S3 push | HIGH |
| `wp_get_attachment_url` | Filter | Rewrite local URLs to CloudFront/S3 URLs | HIGH |
| `wp_calculate_image_srcset` | Filter | Rewrite srcset URLs for responsive images | HIGH |
| `delete_attachment` | Action | Sync deletions to S3 | HIGH |
| `wp_generate_attachment_metadata` | Filter | Alternative upload hook (fires once per upload, not per sub-size) | HIGH |
| `the_content` | Filter | Catch any hardcoded local URLs in post content | MEDIUM |
| `admin_menu` | Action | Register settings page | HIGH |
| `wp_ajax_*` | Action | AJAX handlers for settings/testing | HIGH |

**Critical note on `wp_update_attachment_metadata`:**
Since WordPress 5.3, this filter fires multiple times during image upload (once per generated sub-size thumbnail). The plugin MUST either:
1. Use `wp_generate_attachment_metadata` instead (fires once after all sub-sizes are created), OR
2. Implement a deferred/batched approach that waits for all sub-sizes before uploading

**Recommendation:** Hook `wp_generate_attachment_metadata` for the upload trigger. It fires after all thumbnails are generated, meaning you can upload the original + all sub-sizes in one batch. Use `wp_update_attachment_metadata` only for metadata storage (recording S3 paths).

### CloudFront Integration

| Component | Approach | Why |
|-----------|----------|-----|
| URL rewriting | String replacement in `wp_get_attachment_url` filter | Simple, reliable. Replace `https://bucket.s3.amazonaws.com/` with `https://cdn.yourdomain.com/` |
| Cache invalidation | `CloudFrontClient::createInvalidation()` | Only needed on file replacement (re-upload same filename). WordPress generates unique filenames by default, so this is rare. |
| Custom domain | CNAME record pointing to CloudFront distribution | Standard approach. Configured in AWS, stored as plugin option. |

**URL rewriting pattern:**

```php
add_filter('wp_get_attachment_url', function($url, $attachment_id) {
    $s3_key = get_post_meta($attachment_id, '_ct_s3_key', true);
    if ($s3_key) {
        $cdn_url = get_option('ct_s3_cloudfront_url', '');
        return rtrim($cdn_url, '/') . '/' . ltrim($s3_key, '/');
    }
    return $url;
}, 10, 2);
```

### Settings Page

| Approach | Recommendation | Why |
|----------|---------------|-----|
| WordPress Settings API (PHP) | **USE THIS** | Simple, no build step, works everywhere. For a settings page with 6-8 fields (bucket, region, keys, CDN URL, toggles), React is massive overkill. |
| React + REST API | Do NOT use | Requires build tooling (@wordpress/scripts), increases plugin size, adds complexity for zero UX benefit on a simple form. |
| Custom HTML form + `update_option()` | Acceptable alternative | Even simpler than Settings API but loses automatic sanitization/validation helpers. |

**Settings fields needed:**

| Setting | Type | Storage |
|---------|------|---------|
| S3 Bucket Name | text | option or wp-config constant |
| AWS Region | select | option or wp-config constant |
| Access Key ID | password | wp-config constant preferred |
| Secret Access Key | password | wp-config constant preferred |
| CloudFront URL | url | option |
| Auto-upload on attach | checkbox | option |
| Remove local after upload | checkbox | option |
| Path prefix in S3 | text | option (default: `wp-content/uploads/`) |

### WP-CLI Commands

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| WP-CLI | 2.10+ | Bulk migration commands | Ships with WordPress development environments. Local by Flywheel includes it. |

**Command structure:**

```
wp ct-s3 migrate          # Bulk migrate existing media to S3
wp ct-s3 verify           # Verify S3 objects match local media
wp ct-s3 status           # Show sync status / statistics
wp ct-s3 delete-local     # Remove local copies of offloaded media
```

**Bulk operation patterns (from WordPress VIP best practices):**

```php
class CT_S3_CLI_Command {
    /**
     * Migrate existing media library to S3.
     *
     * ## OPTIONS
     *
     * [--batch-size=<number>]
     * : Number of attachments per batch. Default 50.
     *
     * [--dry-run]
     * : Show what would be migrated without uploading.
     *
     * [--offset=<number>]
     * : Skip first N attachments.
     *
     * ## EXAMPLES
     *
     *     wp ct-s3 migrate --dry-run
     *     wp ct-s3 migrate --batch-size=100
     *
     * @when after_wp_load
     */
    public function migrate($args, $assoc_args) {
        $dry_run   = \WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false);
        $batch     = (int) ($assoc_args['batch-size'] ?? 50);
        $offset    = (int) ($assoc_args['offset'] ?? 0);

        $total     = $this->count_unsynced_attachments();
        $progress  = \WP_CLI\Utils\make_progress_bar('Migrating media', $total);

        // Paged query to avoid memory exhaustion
        while ($attachments = $this->get_attachments($batch, $offset)) {
            foreach ($attachments as $attachment) {
                if (!$dry_run) {
                    $this->upload_to_s3($attachment);
                }
                $progress->tick();
            }
            $offset += $batch;
            $this->cleanup_memory();
        }
        $progress->finish();
    }
}

if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('ct-s3', 'CT_S3_CLI_Command');
}
```

**Key WP-CLI patterns:**
- Default to `--dry-run` mode (non-destructive by default)
- Use `make_progress_bar()` for visual feedback on bulk ops
- Batch queries with offset/limit (never `SELECT * FROM` without `LIMIT`)
- Call `wp_cache_flush()` and `clean_post_cache()` between batches to prevent memory bloat
- Use `WP_CLI::log()`, `WP_CLI::success()`, `WP_CLI::warning()`, `WP_CLI::error()` for output
- Set `define('WP_IMPORTING', true)` to suppress unnecessary hook firing during bulk ops

### Plugin Architecture

| Pattern | Use | Why |
|---------|-----|-----|
| Singleton (main plugin class) | Plugin bootstrap | Prevents double-initialization. Standard WordPress plugin pattern. |
| Service classes | S3Client wrapper, URL rewriter, CLI commands | Separation of concerns. Each class has one job. |
| WordPress hooks (Observer) | Event-driven integration | Standard WordPress integration pattern. Plugin reacts to WP events. |
| Post meta storage | Track S3 status per attachment | `_ct_s3_key`, `_ct_s3_bucket`, `_ct_s3_synced` meta keys on attachment posts. |

**Recommended file structure:**

```
ct-s3-offloader/
  ct-s3-offloader.php           # Plugin header, bootstrap, singleton
  vendor/
    aws.phar                    # AWS SDK (bundled)
  includes/
    class-ct-s3-client.php      # S3Client wrapper (upload, delete, verify)
    class-ct-s3-url-rewriter.php # URL rewriting filters
    class-ct-s3-settings.php    # Admin settings page
    class-ct-s3-cli.php         # WP-CLI commands
    class-ct-s3-media-handler.php # Upload/delete hook handlers
  assets/
    css/
      admin.css                 # Settings page styles
    js/
      admin.js                  # Connection test, AJAX handlers
  uninstall.php                 # Clean removal (delete options, meta)
```

## Alternatives Considered

| Category | Recommended | Alternative | Why Not |
|----------|-------------|-------------|---------|
| AWS SDK bundling | Phar file | Zip extract | Thousands of files cluttering plugin directory, manual autoloader setup |
| AWS SDK bundling | Phar file | Composer require | Users need CLI access, breaks WP plugin upload workflow |
| AWS SDK version | v3 (latest) | v2 | EOL, no PHP 8.x support, deprecated APIs |
| Upload hook | `wp_generate_attachment_metadata` | `wp_update_attachment_metadata` | The latter fires multiple times per upload since WP 5.3, causing duplicate S3 uploads |
| Settings UI | WordPress Settings API | React + REST API | Overkill for 6-8 fields, adds build tooling dependency |
| Settings UI | WordPress Settings API | ACF/CMB2 | Adds external dependency for trivial form rendering |
| S3 upload method | ObjectUploader | putObject only | ObjectUploader auto-handles large files; putObject fails above 5GB |
| Credential storage | wp-config.php constants | Database options only | Secrets in DB are a security risk, constants keep them in version-excluded config |
| Meta storage | Post meta per attachment | Custom table | Post meta is simpler, queryable with WP_Query, no migration headaches |
| Meta storage | Post meta per attachment | Options table JSON blob | Doesn't scale, can't query per-attachment status |

## What NOT to Use

| Technology | Why Avoid |
|------------|-----------|
| **Flysystem PHP** | Abstraction layer for filesystems. Adds complexity without benefit -- you only need S3, not a generic filesystem. |
| **WP Offload Media (as dependency)** | We're building a competitor/custom solution. Don't depend on it. Study its hooks for reference only. |
| **AWS SDK v2** | End-of-life. No PHP 8.x support. Missing modern S3 features. |
| **Composer autoload in production** | Breaks WordPress plugin upload workflow. Users should not need terminal access. |
| **React/Block Editor for settings** | No Gutenberg blocks needed. Settings API is simpler and sufficient. |
| **Custom database tables** | Post meta handles per-attachment tracking perfectly. Custom tables add migration complexity. |
| **wp_remote_get/wp_remote_post** | Do NOT try to call S3 API directly with WordPress HTTP functions. The AWS SDK handles auth signatures (SigV4), retries, multipart -- reimplementing this is error-prone. |
| **Third-party CDN libraries** | CloudFront integration is just URL string replacement. No library needed. |

## Installation / Development Setup

```bash
# 1. Create plugin directory
mkdir -p wp-content/plugins/ct-s3-offloader/vendor

# 2. Download AWS SDK phar (check latest version at github.com/aws/aws-sdk-php/releases)
curl -L -o wp-content/plugins/ct-s3-offloader/vendor/aws.phar \
  https://docs.aws.amazon.com/aws-sdk-php/v3/download/aws.phar

# 3. Verify phar works
php -r "require 'wp-content/plugins/ct-s3-offloader/vendor/aws.phar'; echo Aws\Sdk::VERSION;"

# 4. Add to wp-config.php (keep secrets out of plugin code)
# define('CT_S3_ACCESS_KEY', 'your-key');
# define('CT_S3_SECRET_KEY', 'your-secret');
# define('CT_S3_BUCKET', 'your-bucket-name');
# define('CT_S3_REGION', 'us-east-1');
# define('CT_S3_CLOUDFRONT_URL', 'https://cdn.yourdomain.com');
```

## Version Pinning Notes

| Package | Pin Strategy | Rationale |
|---------|-------------|-----------|
| AWS SDK phar | Download specific version, commit to repo or document version | Phar auto-updates are not a thing. You control the version. Re-download when you need new features or security patches. |
| WordPress | Test on 6.4+ | Hooks used are stable across 6.x line. No 6.7-specific features needed. |
| PHP | Require 8.1+ in plugin header | AWS SDK floor requirement. Add `Requires PHP: 8.1` to plugin header. |
| WP-CLI | 2.10+ | Progress bar API and modern command registration. Ships with all current WP environments. |

## Sources

- [AWS SDK for PHP Installation Guide](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/getting-started_installation.html) -- HIGH confidence (official docs)
- [AWS SDK for PHP GitHub Releases](https://github.com/aws/aws-sdk-php/releases) -- HIGH confidence (latest version 3.371.3 as of 2026-02-27)
- [AWS S3 Multipart Upload Guide](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/s3-multipart-upload.html) -- HIGH confidence (official docs)
- [WordPress Plugin Best Practices](https://developer.wordpress.org/plugins/plugin-basics/best-practices/) -- HIGH confidence (official handbook)
- [wp_get_attachment_url Hook Reference](https://developer.wordpress.org/reference/hooks/wp_get_attachment_url/) -- HIGH confidence (official reference)
- [wp_update_attachment_metadata Changes in WP 5.3](https://make.wordpress.org/core/2019/11/05/use-of-the-wp_update_attachment_metadata-filter-as-upload-is-complete-hook/) -- HIGH confidence (official core blog)
- [WP-CLI Commands Cookbook](https://make.wordpress.org/cli/handbook/guides/commands-cookbook/) -- HIGH confidence (official handbook)
- [WordPress VIP WP-CLI Best Practices](https://docs.wpvip.com/vip-cli/wp-cli-with-vip-cli/wp-cli-commands-on-vip/) -- HIGH confidence (WordPress VIP docs)
- [Human Made S3-Uploads Plugin](https://github.com/humanmade/S3-Uploads) -- MEDIUM confidence (reference architecture, not our dependency)
- [Delicious Brains WP Offload Media](https://github.com/deliciousbrains/wp-amazon-s3-and-cloudfront) -- MEDIUM confidence (reference architecture, not our dependency)
- [AWS End of Support for PHP 8.0 and Below](https://aws.amazon.com/blogs/developer/announcing-the-end-of-support-for-php-runtimes-8-0-x-and-below-in-the-aws-sdk-for-php/) -- HIGH confidence (official AWS blog)
