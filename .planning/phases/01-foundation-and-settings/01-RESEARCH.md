# Phase 1: Foundation and Settings - Research

**Researched:** 2026-02-27
**Domain:** WordPress plugin scaffolding, AWS SDK integration (no Composer), WordPress Settings API, AJAX connection testing
**Confidence:** HIGH

## Summary

Phase 1 delivers the plugin skeleton, AWS SDK bundling, autoloader, settings page, credential management, and connection testing. The build guide provides the primary blueprint with specific file structure (`includes/`, `admin/`, `aws-sdk/`, `cli/`), class naming convention (`S3MO_` prefix), and credential pattern (`wp-config.php` constants).

The standard approach is: extracted AWS SDK zip (not phar) placed in `aws-sdk/` with `aws-autoloader.php`, manual `require_once` loading of plugin classes, WordPress Settings API for the admin page, and AJAX with nonce verification for the connection test button. Credentials are read from `wp-config.php` constants (`S3MO_BUCKET`, `S3MO_REGION`, `S3MO_KEY`, `S3MO_SECRET`, `S3MO_CDN_URL`) and never stored in the database.

Key decision already locked: follow the build guide's extracted zip approach (not phar). The plugin slug is `ct-s3-offloader` (not `s3-media-offloader` as in the guide), so file names and text domain need adaptation. The constant prefix `S3MO_` and class prefix `S3MO_` from the build guide are retained.

**Primary recommendation:** Follow the build guide closely for structure and patterns. Adapt the plugin slug from `s3-media-offloader` to `ct-s3-offloader` and menu slug accordingly. Add a custom autoloader (FOUND-03 requirement) instead of raw `require_once` statements. Add input validation (FOUND-07) which the build guide omits.

## Standard Stack

The established libraries/tools for this domain:

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| PHP | >= 8.1 | Plugin runtime | AWS SDK v3 floor requirement. Local by Flywheel supports 8.1+ |
| WordPress | 6.4+ | Host CMS | Current stable. Settings API, hooks are stable across 6.x |
| AWS SDK for PHP | 3.x (latest zip) | S3 API calls | Official SDK. Downloaded as zip from GitHub releases, placed in `aws-sdk/` |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| WordPress Settings API | Core | Settings page rendering and saving | All settings page work |
| WordPress AJAX API | Core | Connection test button | `wp_ajax_` action hooks |
| WordPress Nonces | Core | Form security and AJAX verification | Every form submission and AJAX call |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| AWS SDK zip extract | AWS SDK phar (~80MB single file) | Phar is simpler to bundle but larger; zip is what the build guide uses and allows loading only needed classes |
| Manual `require_once` | PSR-4 autoloader with `spl_autoload_register` | Autoloader is cleaner and satisfies FOUND-03; build guide uses manual requires but requirement explicitly asks for autoloading |
| WordPress Settings API | React + REST API | Massive overkill for 4-6 fields. Settings API is simple, no build step |

**Installation:**
```bash
# Download AWS SDK zip from GitHub releases
# Extract into plugin's aws-sdk/ directory
# Verify aws-sdk/aws-autoloader.php exists
```

## Architecture Patterns

### Recommended Project Structure
```
ct-s3-offloader/
  ct-s3-offloader.php              # Main bootstrap file (plugin header, constants, autoloader, boot)
  uninstall.php                    # Cleanup on plugin deletion
  includes/
    class-s3mo-client.php          # AWS S3 client wrapper
    class-s3mo-settings-page.php   # Admin settings page (move from admin/ to includes/ for autoloader)
  admin/
    class-s3mo-settings-page.php   # Admin settings page (build guide location)
  aws-sdk/                         # AWS SDK extracted zip
    aws-autoloader.php             # SDK autoloader
    Aws/
    GuzzleHttp/
    Psr/
  assets/
    js/
      admin.js                     # Connection test AJAX handler
    css/
      admin.css                    # Settings page styles (optional)
```

**Note on directory structure:** The build guide places the settings page in `admin/`. The autoloader requirement (FOUND-03) makes this slightly awkward since files are spread across `includes/` and `admin/`. Two options:
1. Keep build guide structure (`includes/` + `admin/`) with a multi-directory autoloader
2. Consolidate all classes into `includes/` for simpler autoloading

**Recommendation:** Keep the build guide structure. The autoloader can handle multiple directories or use a classmap approach.

### Pattern 1: Plugin Bootstrap with Constants and Autoloader
**What:** Main plugin file defines constants, loads AWS SDK, registers autoloader, and boots on `plugins_loaded`
**When to use:** Always -- this is the entry point

```php
// Source: Build guide Phase 3, adapted for ct-s3-offloader
<?php
/**
 * Plugin Name: CT S3 Offloader
 * Plugin URI:  https://ctwebdesignshop.com
 * Description: Offload WordPress media to Amazon S3 with CloudFront CDN.
 * Version:     1.0.0
 * Author:      CT Web Design Shop Inc.
 * Requires PHP: 8.1
 * License:     GPL-2.0+
 * Text Domain: ct-s3-offloader
 */

defined('ABSPATH') || exit;

define('S3MO_VERSION', '1.0.0');
define('S3MO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('S3MO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('S3MO_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Autoloader for S3MO_ classes
spl_autoload_register(function ($class) {
    if (strpos($class, 'S3MO_') !== 0) {
        return;
    }
    $file = 'class-' . strtolower(str_replace('_', '-', $class)) . '.php';
    $paths = [
        S3MO_PLUGIN_DIR . 'includes/' . $file,
        S3MO_PLUGIN_DIR . 'admin/' . $file,
    ];
    foreach ($paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            return;
        }
    }
});

// Load AWS SDK
$aws_autoloader = S3MO_PLUGIN_DIR . 'aws-sdk/aws-autoloader.php';
if (!file_exists($aws_autoloader)) {
    add_action('admin_notices', function () {
        echo '<div class="error"><p><strong>CT S3 Offloader:</strong> ';
        echo 'AWS SDK not found. Place the extracted SDK in the ';
        echo '<code>aws-sdk/</code> folder.</p></div>';
    });
    return;
}
require_once $aws_autoloader;

// Boot on plugins_loaded
add_action('plugins_loaded', function () {
    // Check for credentials
    if (!defined('S3MO_BUCKET') || !defined('S3MO_REGION')
        || !defined('S3MO_KEY') || !defined('S3MO_SECRET')
    ) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>CT S3 Offloader:</strong> ';
            echo 'Add S3MO_BUCKET, S3MO_REGION, S3MO_KEY, and S3MO_SECRET ';
            echo 'constants to wp-config.php</p></div>';
        });
        // Still load settings page so admin can see what's needed
    }

    // Settings page (always load in admin)
    if (is_admin()) {
        $client = (defined('S3MO_BUCKET') && defined('S3MO_REGION')
            && defined('S3MO_KEY') && defined('S3MO_SECRET'))
            ? new S3MO_Client() : null;
        $settings = new S3MO_Settings_Page($client);
        $settings->register_hooks();
    }
});

// Activation hook
register_activation_hook(__FILE__, function () {
    add_option('s3mo_path_prefix', 'wp-content/uploads');
    add_option('s3mo_delete_local', false);
});

// Deactivation hook
register_deactivation_hook(__FILE__, function () {
    delete_transient('s3mo_connection_status');
});
```

### Pattern 2: S3 Client Wrapper with Connection Test
**What:** Thin wrapper around AWS S3Client that handles credential loading and provides `test_connection()` via `headBucket`
**When to use:** All S3 interactions go through this wrapper

```php
// Source: Build guide Phase 4
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class S3MO_Client {
    private S3Client $s3;
    private string $bucket;
    private string $region;

    public function __construct() {
        $this->bucket = S3MO_BUCKET;
        $this->region = S3MO_REGION;

        $this->s3 = new S3Client([
            'version'     => 'latest',
            'region'      => $this->region,
            'credentials' => [
                'key'    => S3MO_KEY,
                'secret' => S3MO_SECRET,
            ],
        ]);
    }

    public function test_connection(): array {
        try {
            $this->s3->headBucket(['Bucket' => $this->bucket]);
            return ['success' => true, 'message' => 'Connected to bucket: ' . $this->bucket];
        } catch (AwsException $e) {
            $code = $e->getAwsErrorCode();
            $messages = [
                'NoSuchBucket'       => 'Bucket "' . $this->bucket . '" does not exist.',
                'InvalidAccessKeyId' => 'Invalid AWS Access Key ID.',
                'SignatureDoesNotMatch' => 'Invalid AWS Secret Access Key.',
                'AccessDenied'       => 'Access denied. Check IAM policy permissions.',
                '403'                => 'Forbidden. Check IAM user permissions for this bucket.',
            ];
            $message = $messages[$code] ?? $e->getAwsErrorMessage() ?? $e->getMessage();
            return ['success' => false, 'message' => $message, 'code' => $code];
        }
    }

    public function get_bucket(): string {
        return $this->bucket;
    }

    public function get_url_base(): string {
        $cdn = defined('S3MO_CDN_URL') && !empty(S3MO_CDN_URL) ? S3MO_CDN_URL : '';
        if (!empty($cdn)) return rtrim($cdn, '/');
        return "https://{$this->bucket}.s3.{$this->region}.amazonaws.com";
    }
}
```

### Pattern 3: Settings Page with WordPress Settings API
**What:** Admin page under Media menu using `add_media_page()`, with `register_setting()` for saveable options and AJAX for connection test
**When to use:** Phase 1 settings page

```php
// Source: Build guide Phase 8, enhanced with validation (FOUND-07)
class S3MO_Settings_Page {
    private ?S3MO_Client $client;

    public function __construct(?S3MO_Client $client) {
        $this->client = $client;
    }

    public function register_hooks(): void {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_s3mo_test_connection', [$this, 'ajax_test_connection']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_menu(): void {
        add_media_page(
            'CT S3 Offloader Settings',
            'S3 Offloader',
            'manage_options',
            'ct-s3-offloader',
            [$this, 'render_page']
        );
    }

    public function register_settings(): void {
        register_setting('s3mo_settings', 's3mo_path_prefix', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_path_prefix'],
            'default'           => 'wp-content/uploads',
        ]);
        register_setting('s3mo_settings', 's3mo_delete_local', [
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
        ]);
    }

    public function sanitize_path_prefix(string $value): string {
        $value = sanitize_text_field($value);
        $value = trim($value, '/');
        // Reject empty
        if (empty($value)) {
            add_settings_error('s3mo_path_prefix', 'empty', 'Path prefix cannot be empty.');
            return get_option('s3mo_path_prefix', 'wp-content/uploads');
        }
        return $value;
    }

    public function ajax_test_connection(): void {
        check_ajax_referer('s3mo_test_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }
        if (!$this->client) {
            wp_send_json_error(['message' => 'AWS credentials not configured in wp-config.php']);
        }
        wp_send_json($this->client->test_connection());
    }

    public function enqueue_assets(string $hook): void {
        if ($hook !== 'media_page_ct-s3-offloader') {
            return;
        }
        wp_enqueue_script(
            's3mo-admin',
            S3MO_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            S3MO_VERSION,
            true
        );
        wp_localize_script('s3mo-admin', 's3moAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('s3mo_test_nonce'),
        ]);
    }
}
```

### Pattern 4: AJAX Connection Test JavaScript
**What:** Client-side JS for the "Test Connection" button
**When to use:** Settings page connection test

```javascript
// Source: Standard WordPress AJAX pattern
(function($) {
    'use strict';

    $(document).on('click', '#s3mo-test-connection', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $result = $('#s3mo-test-result');

        $btn.prop('disabled', true).text('Testing...');
        $result.html('').removeClass('notice-success notice-error');

        $.ajax({
            url: s3moAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 's3mo_test_connection',
                _ajax_nonce: s3moAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $result.addClass('notice notice-success')
                        .html('<p>' + response.message + '</p>');
                } else {
                    $result.addClass('notice notice-error')
                        .html('<p>' + (response.message || response.data.message) + '</p>');
                }
            },
            error: function() {
                $result.addClass('notice notice-error')
                    .html('<p>Request failed. Check your network connection.</p>');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Test Connection');
            }
        });
    });
})(jQuery);
```

### Anti-Patterns to Avoid
- **Storing AWS credentials in wp_options:** Never save access keys in the database. Always read from `wp-config.php` constants. Settings page should display "Defined in wp-config.php" for credential fields, not editable inputs.
- **Using ACLs on S3 uploads:** New S3 buckets (post-April 2023) disable ACLs by default. Never pass `'ACL' => 'public-read'` to putObject. Use CloudFront OAC for serving.
- **Loading AWS SDK without class_exists guard:** Another plugin may bundle the same SDK namespace. Check `class_exists('Aws\Sdk')` before loading the autoloader to avoid fatal conflicts.
- **Hooking wp_update_attachment_metadata for uploads:** Fires multiple times per upload in WP 5.3+. Use `wp_generate_attachment_metadata` instead (not needed in Phase 1, but architectural awareness).

## Don't Hand-Roll

Problems that look simple but have existing solutions:

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| S3 API authentication (SigV4) | Custom HTTP request signing | AWS SDK S3Client | SigV4 signing is complex, time-sensitive, error-prone |
| S3 bucket connectivity check | Custom HTTP HEAD request | `S3Client::headBucket()` | Handles auth, error codes, retries automatically |
| Settings form rendering | Custom HTML form processing | WordPress Settings API (`register_setting`, `settings_fields`, `do_settings_sections`) | Built-in sanitization, nonce handling, error display |
| AJAX nonce verification | Custom token system | `wp_create_nonce()` + `check_ajax_referer()` | WordPress core security, well-tested |
| Admin menu page | Custom admin routing | `add_media_page()` | Handles capability checks, menu positioning, proper WP admin integration |
| Input sanitization | Custom regex sanitizers | `sanitize_text_field()`, `esc_url_raw()`, `absint()` | WordPress core sanitization functions cover all common cases |

**Key insight:** WordPress provides battle-tested functions for every admin UI concern. The AWS SDK handles all S3 protocol complexity. The plugin's job is to wire these together, not reimplement them.

## Common Pitfalls

### Pitfall 1: AWS SDK Autoloader Conflicts
**What goes wrong:** Another active plugin (backup, email, etc.) bundles a different version of the AWS SDK. PHP autoloaders collide, causing fatal errors.
**Why it happens:** WordPress has no dependency management. Two plugins can each register the `Aws\` namespace.
**How to avoid:** Add a `class_exists('Aws\Sdk')` guard before loading the SDK autoloader. If the class already exists, log a notice but continue -- the existing SDK version will likely work for basic S3 operations.
**Warning signs:** Fatal errors only when specific plugin combinations are active.

```php
// Guard pattern for aws-sdk loading
if (!class_exists('Aws\Sdk')) {
    require_once S3MO_PLUGIN_DIR . 'aws-sdk/aws-autoloader.php';
}
```

### Pitfall 2: Settings Page Hook Name Mismatch
**What goes wrong:** `admin_enqueue_scripts` callback checks for wrong hook name, so JS/CSS never loads on the settings page.
**Why it happens:** The hook suffix for `add_media_page()` is `media_page_{menu_slug}`. If the menu slug is `ct-s3-offloader`, the hook is `media_page_ct-s3-offloader`.
**How to avoid:** Use the return value of `add_media_page()` to get the exact hook suffix, or verify by inspecting `$hook` parameter in the callback.
**Warning signs:** Connection test button does nothing, no JavaScript errors visible.

### Pitfall 3: Credentials Not Found Because of wp-config.php Placement
**What goes wrong:** Constants defined below the `/* That's all, stop editing! */` line in `wp-config.php` are not available when the plugin loads.
**Why it happens:** WordPress stops reading `wp-config.php` at that line and hands off to `wp-settings.php`.
**How to avoid:** Documentation and admin notices must clearly state: "Add constants ABOVE the `That's all, stop editing` line."
**Warning signs:** Plugin shows "credentials not configured" even though constants appear in the file.

### Pitfall 4: sanitize_callback Fires Twice on First Save
**What goes wrong:** The `sanitize_callback` registered with `register_setting()` executes twice on the first save (when the option is created via `add_option`), then once on subsequent updates.
**Why it happens:** WordPress core behavior -- documented but surprising.
**How to avoid:** Ensure sanitize callbacks are idempotent. Don't perform side effects (like API calls) in sanitize callbacks.
**Warning signs:** Duplicate admin notices or double-logged events on first settings save.

### Pitfall 5: Missing Nonce on Connection Test
**What goes wrong:** AJAX connection test endpoint is accessible without nonce verification, allowing CSRF attacks.
**Why it happens:** Developer forgets `check_ajax_referer()` or passes wrong nonce action name.
**How to avoid:** Always call `check_ajax_referer('s3mo_test_nonce')` as the first line of the AJAX handler. Pass the nonce to JavaScript via `wp_localize_script()`.
**Warning signs:** Security audit flags the AJAX endpoint.

## Code Examples

Verified patterns from the build guide and WordPress documentation:

### wp-config.php Constants
```php
// Source: Build guide Phase 4.3
// Place ABOVE "/* That's all, stop editing! */" line

/* CT S3 Offloader Configuration */
define('S3MO_BUCKET', 'your-site-media');
define('S3MO_REGION', 'us-east-1');
define('S3MO_KEY',    'AKIAIOSFODNN7EXAMPLE');
define('S3MO_SECRET', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY');
define('S3MO_CDN_URL', 'https://cdn.yourdomain.com');
```

### Activation Hook -- Set Default Options
```php
// Source: Build guide Phase 3.2
register_activation_hook(__FILE__, function () {
    add_option('s3mo_path_prefix', 'wp-content/uploads');
    add_option('s3mo_delete_local', false);
});
```

### Deactivation Hook -- Clean Transients Only
```php
// Source: Build guide Phase 3.2
// Never delete options here -- that's for uninstall.php
register_deactivation_hook(__FILE__, function () {
    delete_transient('s3mo_connection_status');
});
```

### uninstall.php -- Clean Removal
```php
// Source: Build guide Phase 3.3
<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('s3mo_delete_local');
delete_option('s3mo_path_prefix');
delete_option('s3mo_cdn_url');

global $wpdb;
$wpdb->delete($wpdb->postmeta, ['meta_key' => '_s3mo_offloaded']);
```

### S3 Bucket Name Validation
```php
// Source: AWS S3 bucket naming rules documentation
function s3mo_validate_bucket_name(string $name): bool {
    // 3-63 chars, lowercase letters, numbers, hyphens
    // Must start/end with letter or number
    // No consecutive periods, no IP address format
    if (strlen($name) < 3 || strlen($name) > 63) {
        return false;
    }
    return (bool) preg_match('/^[a-z0-9][a-z0-9.\-]*[a-z0-9]$/', $name)
        && !preg_match('/\.\./', $name)
        && !preg_match('/^\d+\.\d+\.\d+\.\d+$/', $name);
}
```

### AWS Region Validation
```php
// Source: AWS documentation
function s3mo_get_valid_regions(): array {
    return [
        'us-east-1'      => 'US East (N. Virginia)',
        'us-east-2'      => 'US East (Ohio)',
        'us-west-1'      => 'US West (N. California)',
        'us-west-2'      => 'US West (Oregon)',
        'af-south-1'     => 'Africa (Cape Town)',
        'ap-east-1'      => 'Asia Pacific (Hong Kong)',
        'ap-south-1'     => 'Asia Pacific (Mumbai)',
        'ap-southeast-1' => 'Asia Pacific (Singapore)',
        'ap-southeast-2' => 'Asia Pacific (Sydney)',
        'ap-northeast-1' => 'Asia Pacific (Tokyo)',
        'ap-northeast-2' => 'Asia Pacific (Seoul)',
        'ap-northeast-3' => 'Asia Pacific (Osaka)',
        'ca-central-1'   => 'Canada (Central)',
        'eu-central-1'   => 'Europe (Frankfurt)',
        'eu-west-1'      => 'Europe (Ireland)',
        'eu-west-2'      => 'Europe (London)',
        'eu-west-3'      => 'Europe (Paris)',
        'eu-north-1'     => 'Europe (Stockholm)',
        'eu-south-1'     => 'Europe (Milan)',
        'me-south-1'     => 'Middle East (Bahrain)',
        'sa-east-1'      => 'South America (Sao Paulo)',
    ];
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| S3 bucket ACLs (`public-read`) | CloudFront OAC with private bucket | April 2023 (S3 default change) | Never pass ACL params to putObject |
| `wp_update_attachment_metadata` for upload trigger | `wp_generate_attachment_metadata` | WordPress 5.3 (Nov 2019) | Prevents duplicate uploads |
| Composer in WordPress plugins | Bundled SDK (zip/phar) or PHP-Scoper | WordPress.org guideline | No CLI dependency for installation |
| `wp_localize_script` for passing data to JS | `wp_add_inline_script` (WP 4.5+) | 2016 | Both work; `wp_localize_script` still commonly used for simple key-value data |

**Deprecated/outdated:**
- AWS SDK v2: End-of-life, no PHP 8.x support
- S3 Object ACLs on new buckets: Disabled by default since April 2023
- Origin Access Identity (OAI) for CloudFront: Replaced by Origin Access Control (OAC)

## Open Questions

Things that couldn't be fully resolved:

1. **AWS SDK zip size in the plugin**
   - What we know: The extracted zip contains thousands of files and is ~50-80MB. The build guide says first activation may be slow as PHP parses the autoloader.
   - What's unclear: Exact current zip size. Whether `.gitignore` should exclude `aws-sdk/` from version control (it's large).
   - Recommendation: Download the zip during implementation, note the actual size. Add `aws-sdk/` to `.gitignore` with a README noting the download step. Or commit it if the repo is private and size is acceptable.

2. **Autoloader scope for FOUND-03**
   - What we know: FOUND-03 requires "Plugin autoloader loads classes on demand without manual requires." The build guide uses manual `require_once`. An `spl_autoload_register` autoloader resolves this.
   - What's unclear: Whether the autoloader should also lazy-load CLI classes or just core classes.
   - Recommendation: Autoloader covers `includes/` and `admin/` directories. CLI classes are conditionally loaded only when `WP_CLI` is defined (keep manual require for CLI since it's conditional).

3. **Settings page scope: display-only credentials vs editable fields**
   - What we know: SEC-01 requires credentials in wp-config.php only, never in wp_options. FOUND-05 says settings page should have fields for bucket, region, CloudFront domain, and path prefix.
   - What's unclear: Should bucket/region be editable on the settings page (since they're also in wp-config.php constants)? Or display-only?
   - Recommendation: Bucket and region are display-only (read from constants, shown as disabled fields with "Defined in wp-config.php" label). CloudFront domain and path prefix are saveable options. This matches the build guide's approach.

## Sources

### Primary (HIGH confidence)
- Build guide: `/knowledge/research/s3-media-offloader-build-guide-local-flywheel.md` -- Phases 2-4, 8 (plugin scaffold, AWS SDK, settings page)
- Build guide: `/knowledge/research/s3-media-offloader-build-guide.md` -- Original version with same patterns
- [AWS SDK PHP Installation Guide](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/getting-started_installation.html) -- Zip and phar download methods confirmed
- [WordPress register_setting() Reference](https://developer.wordpress.org/reference/functions/register_setting/) -- Settings API patterns
- [WordPress check_ajax_referer()](https://developer.wordpress.org/reference/functions/check_ajax_referer/) -- AJAX nonce verification
- [AWS S3 Bucket Naming Rules](https://docs.aws.amazon.com/AmazonS3/latest/userguide/bucketnamingrules.html) -- Validation regex for FOUND-07

### Secondary (MEDIUM confidence)
- [WordPress Nonce Best Practices (Developer Blog)](https://developer.wordpress.org/news/2023/08/understand-and-use-wordpress-nonces-properly/) -- Nonce patterns
- [WordPress Plugin Settings Pages Guide](https://www.plugintify.com/building-custom-settings-pages-for-your-plugin/) -- Settings API patterns
- Prior research: `.planning/research/STACK.md`, `ARCHITECTURE.md`, `PITFALLS.md` -- Ecosystem-level research already completed

### Tertiary (LOW confidence)
- sanitize_callback double-execution behavior -- documented in WP codex comments but not prominently in official docs

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH -- Build guide provides exact libraries and versions. AWS docs confirm SDK installation methods.
- Architecture: HIGH -- Build guide provides complete file structure, class patterns, and hook registration. WordPress Settings API is well-documented.
- Pitfalls: HIGH -- Prior research documented 15 pitfalls. Phase 1-specific pitfalls (SDK conflicts, credential storage, ACLs) are well-sourced.
- Validation patterns: MEDIUM -- S3 bucket naming rules are documented by AWS. WordPress sanitization functions are well-known. Region list may be slightly incomplete.

**Research date:** 2026-02-27
**Valid until:** 2026-03-27 (stable domain, 30-day validity)
