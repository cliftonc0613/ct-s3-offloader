---
phase: 06-admin-ui-and-finalization
plan: 01
subsystem: admin-ui
tags: [media-library, admin-notices, css, wordpress-admin]
dependency_graph:
  requires: [01-01, 01-02, 02-01]
  provides: [media-column-status, admin-notices, admin-css]
  affects: [06-02]
tech_stack:
  added: []
  patterns: [wordpress-column-api, admin-notices-pattern, event-delegation]
key_files:
  created:
    - admin/class-s3mo-media-column.php
    - admin/class-s3mo-admin-notices.php
    - assets/css/admin.css
  modified:
    - assets/js/admin.js
    - ct-s3-offloader.php
    - admin/class-s3mo-settings-page.php
decisions:
  - Media column always visible regardless of credential status
  - Admin notices centralized in dedicated class replacing inline anonymous function
  - CSS shared between settings page and media column via single admin.css file
metrics:
  duration: ~3m
  completed: 2026-02-28
---

# Phase 6 Plan 1: Admin UI — Media Column and Notices Summary

**Media Library offload status column with S3/Local indicators and centralized admin notices for credential and config warnings.**

## What Was Built

### S3MO_Media_Column (`admin/class-s3mo-media-column.php`)
- Adds "Offload" column to Media Library list view via `manage_media_columns` filter
- Renders green dot + "S3" label for offloaded attachments with clickable detail popup
- Renders yellow dot + "Local" label for non-offloaded attachments
- Detail popup shows S3 key, bucket name, and relative upload timestamp
- Enqueues admin CSS and JS on `upload.php` page

### S3MO_Admin_Notices (`admin/class-s3mo-admin-notices.php`)
- Centralized notice rendering for three conditions:
  1. Missing credential constants (warning) — replaces inline anonymous function
  2. Failed S3 connection test via transient (error)
  3. Missing CloudFront CDN URL (info)
- All notices are dismissible per session, reappear on next page load
- Only shown to users with `manage_options` capability

### Admin CSS (`assets/css/admin.css`)
- Status dot indicators (green=S3, yellow=Local, red=Error)
- Status toggle button styling
- Detail popup with word-break for long S3 keys
- Settings page credential styles (`.s3mo-not-defined`, `.s3mo-credential-value`) migrated from inline

### Bootstrap Wiring (`ct-s3-offloader.php`)
- `S3MO_Admin_Notices` and `S3MO_Media_Column` instantiated in `plugins_loaded` for all admin pages
- Inline anonymous `admin_notices` function removed
- Both classes wired before credential check so they always display

### Settings Page Update (`admin/class-s3mo-settings-page.php`)
- Replaced `wp_add_inline_style()` with external `assets/css/admin.css` enqueue
- Maintains all existing functionality

### JavaScript Update (`assets/js/admin.js`)
- Added event-delegated click handler for `.s3mo-status-toggle` buttons
- Toggles `is-visible` class on sibling `.s3mo-details` div

## Decisions Made

| Decision | Rationale |
|----------|-----------|
| Media column always visible | Shows Local status even without credentials configured |
| Notices centralized in class | Replaces anonymous function, cleaner architecture |
| Single CSS file for all admin | Avoids duplication between settings page and media column |
| Event delegation for toggle | Handles AJAX-loaded rows in Media Library pagination |

## Deviations from Plan

None — plan executed exactly as written.

## Commits

| Hash | Message |
|------|---------|
| a1596de | feat(06-01): add Media Library status column and admin notices classes |
| 891ea3a | feat(06-01): wire media column and admin notices into bootstrap |

## Next Phase Readiness

Phase 6 Plan 2 (settings page enhancements / finalization) can proceed. All admin infrastructure is in place:
- Media column provides visibility into offload status
- Admin notices surface configuration issues
- CSS and JS are shared and extensible
