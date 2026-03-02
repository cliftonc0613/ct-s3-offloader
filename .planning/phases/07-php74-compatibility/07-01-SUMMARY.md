---
phase: 07-php74-compatibility
plan: 01
subsystem: infra
tags: [aws-sdk, php74, compatibility, s3]

# Dependency graph
requires:
  - phase: 06-admin-ui-and-finalization
    provides: "Complete v1.0 plugin with admin UI and all core features"
provides:
  - "AWS SDK v3.337.3 installed (PHP 7.4 compatible)"
  - "Runtime SDK version pinning with admin mismatch warning"
  - "Plugin header declares Requires PHP: 7.4"
  - "Plugin version bumped to 1.1.0"
affects: [07-02-php74-compatibility, 08-tech-debt]

# Tech tracking
tech-stack:
  added: ["AWS SDK v3.337.3 (downgraded from v3.371.3)"]
  patterns: ["SDK version pinning via constant + runtime check"]

key-files:
  created: []
  modified: ["ct-s3-offloader.php"]

key-decisions:
  - "SDK version pinning uses warning notice, not fatal error -- plugin continues loading on mismatch"
  - "Version bump to 1.1.0 reflects PHP 7.4 compatibility as a new minor release"

patterns-established:
  - "Version pinning: define expected version as constant, compare at runtime, warn on mismatch"

# Metrics
duration: 3min
completed: 2026-03-01
---

# Phase 7 Plan 1: SDK Downgrade and Version Pinning Summary

**AWS SDK downgraded to v3.337.3 with runtime version pinning constant and PHP 7.4 requirement header**

## Performance

- **Duration:** 3 min
- **Started:** 2026-03-01T06:38:15Z
- **Completed:** 2026-03-01T06:41:30Z
- **Tasks:** 2
- **Files modified:** 1 (ct-s3-offloader.php) + aws-sdk/ directory (gitignored)

## Accomplishments
- Downloaded and extracted AWS SDK v3.337.3 (last PHP 7.4 compatible version) replacing v3.371.3
- Added S3MO_EXPECTED_AWS_SDK_VERSION constant pinning SDK to 3.337.3
- Added runtime version mismatch detection with admin warning notice
- Updated plugin header to Requires PHP: 7.4
- Bumped plugin version to 1.1.0

## Task Commits

Each task was committed atomically:

1. **Task 1: Download and extract AWS SDK v3.337.3** - No commit (aws-sdk/ is gitignored; deployment step only)
2. **Task 2: Add SDK version pinning and PHP 7.4 header** - `234f352` (feat)

**Plan metadata:** (pending)

## Files Created/Modified
- `ct-s3-offloader.php` - Plugin header updated (Version 1.1.0, Requires PHP 7.4), SDK version pinning constant added, runtime version check with admin notice
- `aws-sdk/` - Replaced with SDK v3.337.3 (gitignored, not committed)

## Decisions Made
- SDK version pinning uses a warning notice rather than a fatal error, so the plugin continues to load even with a mismatched SDK. This prevents accidental breakage if someone updates the SDK manually.
- Version bump from 1.0.0 to 1.1.0 reflects the PHP 7.4 compatibility change as a minor release.

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
- The GitHub release zip contained a nested aws.zip inside the downloaded zip. Required double extraction (unzip outer, then unzip inner aws.zip). Resolved by extracting both layers.

## User Setup Required

None - no external service configuration required. The aws-sdk/ directory must be populated as a deployment step (already documented in CLAUDE.md).

## Next Phase Readiness
- SDK is installed and verified (S3Client instantiates correctly)
- Bootstrap file is ready for PHP 7.4 syntax changes in Plan 02
- No blockers for proceeding to Plan 02 (PHP syntax compatibility)

---
*Phase: 07-php74-compatibility*
*Completed: 2026-03-01*
