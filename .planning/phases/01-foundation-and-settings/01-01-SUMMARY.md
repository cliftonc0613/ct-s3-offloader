---
phase: 01-foundation-and-settings
plan: 01
subsystem: plugin-scaffold
tags: [wordpress, aws-sdk, s3, autoloader, plugin-bootstrap]

dependency-graph:
  requires: []
  provides: [plugin-bootstrap, autoloader, aws-sdk-loading, s3-client-wrapper, activation-hooks]
  affects: [01-02, 02-01, 02-02, 03-01]

tech-stack:
  added: [aws-sdk-php-v3]
  patterns: [spl-autoload, class-exists-guard, wp-config-constants, admin-notices]

key-files:
  created:
    - ct-s3-offloader.php
    - uninstall.php
    - includes/class-s3mo-client.php
    - aws-sdk/README.md
    - .gitignore
  modified: []

decisions:
  - id: d-01-01-01
    title: "AWS SDK loaded via class_exists guard"
    choice: "Check class_exists('Aws\\Sdk') before requiring autoloader"
    rationale: "Prevents namespace conflicts if another plugin already loaded the SDK"
  - id: d-01-01-02
    title: "SDK gitignored with README for instructions"
    choice: "aws-sdk/* ignored except README.md"
    rationale: "SDK is ~80MB, too large for version control; README documents download steps"

metrics:
  duration: "2m 38s"
  completed: "2026-02-27"
  tasks: 2
  commits: 2
---

# Phase 1 Plan 1: Plugin Scaffold Summary

**Plugin bootstrap with autoloader, AWS SDK bundling, and S3 client wrapper using class_exists guard and wp-config.php constants**

## What Was Built

### Task 1: Plugin Bootstrap, Autoloader, and Activation/Deactivation Hooks

Created the main plugin file `ct-s3-offloader.php` with:

- **Plugin header** with all required WordPress metadata fields
- **ABSPATH guard** preventing direct access
- **Constants**: S3MO_VERSION, S3MO_PLUGIN_DIR, S3MO_PLUGIN_URL, S3MO_PLUGIN_BASENAME
- **spl_autoload_register** that handles S3MO_ prefixed classes, converting `S3MO_Settings_Page` to `class-s3mo-settings-page.php` and searching `includes/` then `admin/`
- **AWS SDK loading** with `class_exists('Aws\Sdk')` guard to prevent namespace conflicts, `file_exists` check with admin notice if SDK missing, halting plugin load
- **plugins_loaded hook** checking S3MO_BUCKET, S3MO_REGION, S3MO_KEY, S3MO_SECRET constants; shows admin warning listing missing constants; loads S3MO_Settings_Page in admin
- **Activation hook**: `add_option('s3mo_path_prefix', 'wp-content/uploads')` and `add_option('s3mo_delete_local', false)`
- **Deactivation hook**: `delete_transient('s3mo_connection_status')`

Created `uninstall.php` with WP_UNINSTALL_PLUGIN guard, deleting options and `_s3mo_offloaded` postmeta.

### Task 2: AWS SDK Bundling and S3 Client Wrapper

- Downloaded and extracted AWS SDK v3 from GitHub releases into `aws-sdk/`
- Added `aws-sdk/*` to `.gitignore` (except `README.md` with download instructions)
- Created `includes/class-s3mo-client.php` with `S3MO_Client` class:
  - PHP 8.1 typed properties (`private S3Client $s3`, `private string $bucket`, etc.)
  - Constructor creates S3Client from wp-config.php constants
  - `test_connection()` uses `headBucket` with error code mapping (NoSuchBucket, InvalidAccessKeyId, SignatureDoesNotMatch, AccessDenied, 403)
  - `get_url_base()` prefers `S3MO_CDN_URL` constant (CloudFront), falls back to direct S3 URL
  - Accessor methods: `get_s3_client()`, `get_bucket()`, `get_region()`

## Verification Results

All 8 checks passed:

| Check | Result |
|-------|--------|
| php -l ct-s3-offloader.php | No syntax errors |
| php -l uninstall.php | No syntax errors |
| php -l includes/class-s3mo-client.php | No syntax errors |
| ls aws-sdk/aws-autoloader.php | File exists |
| grep spl_autoload_register | Found (1) |
| grep class_exists Aws | Found (1) |
| grep register_activation_hook | Found (1) |
| grep headBucket | Found (1) |

## Deviations from Plan

None -- plan executed exactly as written.

## Commits

| Hash | Message |
|------|---------|
| b2282e3 | feat(01-01): plugin bootstrap, autoloader, and activation hooks |
| eca0930 | feat(01-01): AWS SDK bundling and S3 client wrapper |

## Next Phase Readiness

Plan 01-02 (Settings Page and AJAX Connection Test) can proceed immediately. It will:
- Create `S3MO_Settings_Page` class in `admin/class-s3mo-settings-page.php` (autoloader ready)
- Use `S3MO_Client::test_connection()` for AJAX connection testing
- Register settings using options created by activation hook
