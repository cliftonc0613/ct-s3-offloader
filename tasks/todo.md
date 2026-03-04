# Security Audit Remediation Plan

Fix all 12 findings from the security audit, grouped by file to minimize blast radius.

## Tasks

### CRITICAL / HIGH Priority

- [x] **1. Fix unprepared SQL query** (`includes/class-s3mo-stats.php:74-78`)
  - Use `$wpdb->prepare()` with `%s` placeholder instead of string interpolation
  - ~3 lines changed

- [x] **2. Fix DOM XSS in AJAX response** (`assets/js/admin.js:26`)
  - Replace `.html('<p>' + message + '</p>')` with safe DOM construction using `.text()`
  - Also fix the static error handler on line 31 the same way
  - ~4 lines changed

- [x] **3. Harden CORS origin handling** (`includes/class-s3mo-url-rewriter.php:299-320`)
  - Sanitize `$_SERVER['HTTP_ORIGIN']` with `esc_url_raw()`
  - Replace hardcoded localhost origins with a filterable approach via `apply_filters`
  - ~6 lines changed

### MEDIUM Priority

- [x] **4. Protect log file from web access** (`includes/class-s3mo-cli-command.php:404-408`)
  - Move log to `uploads/ct-s3-offloader/` subdirectory
  - Create directory with `.htaccess` deny and `index.php` silence
  - Set 0600 permissions on new log files (also fixes Finding #9)
  - Update `uninstall.php:122-124` to match new log path
  - ~15 lines changed

- [x] **5. Strengthen path prefix sanitization** (`admin/class-s3mo-settings-page.php:78-91`)
  - Add `../` traversal removal
  - Add character restriction to `[a-zA-Z0-9/_\-.]`
  - ~4 lines added

- [x] **6. Sanitize AWS error messages before storage** (multiple files)
  - `includes/class-s3mo-client.php:62` — sanitize fallback error message via `wp_strip_all_tags()`
  - `includes/class-s3mo-client.php:152` — same for upload_object error
  - `includes/class-s3mo-tracker.php:158` — `sanitize_text_field()` in `set_error()`
  - `admin/class-s3mo-settings-page.php:111` — sanitize transient data before storing
  - ~8 lines changed

### LOW Priority

- [x] **7. Use `basename()` on thumbnail filenames in S3 key construction** (3 files)
  - `includes/class-s3mo-delete-handler.php:96`
  - `includes/class-s3mo-tracker.php:142-143`
  - `uninstall.php:75`
  - ~3 lines changed

- [x] **8. Harden autoloader path validation** (`ct-s3-offloader.php:24-43`)
  - Add regex check to reject `..` or `/` in generated filename
  - ~3 lines added

## Review

All 12 security findings remediated across 9 files:

**Files modified:**
- `includes/class-s3mo-stats.php` — prepared SQL query
- `assets/js/admin.js` — XSS-safe DOM construction
- `includes/class-s3mo-url-rewriter.php` — sanitized CORS origin, filterable allowlist
- `includes/class-s3mo-cli-command.php` — protected log directory + updated path reference
- `admin/class-s3mo-settings-page.php` — path prefix hardening + transient sanitization
- `includes/class-s3mo-client.php` — error message sanitization (3 locations)
- `includes/class-s3mo-tracker.php` — sanitized set_error + basename on thumbnails
- `includes/class-s3mo-delete-handler.php` — basename on thumbnail filenames
- `uninstall.php` — basename on thumbnails + new log directory cleanup
- `ct-s3-offloader.php` — autoloader path traversal guard

**Notes:**
- CORS localhost origins removed; developers can re-add via `s3mo_cors_allowed_origins` filter
- Legacy log path (`wp-content/ct-s3-migration.log`) still cleaned up in uninstall for backwards compat
- All changes are minimal and scoped to the specific security concern
