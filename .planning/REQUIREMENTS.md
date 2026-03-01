# Requirements: CT S3 Offloader v1.1

**Defined:** 2026-03-01
**Core Value:** Media files upload to S3 and serve from CloudFront transparently

## v1.1 Requirements

### PHP Compatibility

- [ ] **PHP-01**: Plugin runs on PHP 7.4 through PHP 8.x without errors
- [ ] **PHP-02**: Plugin header updated to `Requires PHP: 7.4`
- [ ] **PHP-03**: AWS SDK downgraded to v3.337.3 (last PHP 7.4 compatible version)
- [ ] **PHP-04**: All existing upload/rewrite/delete/CLI functionality works with downgraded SDK

### Tech Debt — Functional Gaps

- [ ] **DEBT-01**: `s3mo_delete_local` option is functional — upload handler reads the setting and deletes local files after confirmed S3 upload
- [ ] **DEBT-02**: `s3mo_connection_status` transient written by AJAX connection test handler so persistent failure notice works
- [ ] **DEBT-03**: `_s3mo_error` postmeta written on upload failures so Media Library error badge functions

### Tech Debt — Code Quality

- [ ] **DEBT-04**: S3 key-building logic extracted into shared method on `S3MO_Tracker` — eliminates duplication between Upload Handler and Bulk Migrator
- [ ] **DEBT-05**: `S3MO_Stats` uses `S3MO_Tracker` constants instead of hard-coded meta key strings
- [ ] **DEBT-06**: Unused `S3MO_PLUGIN_BASENAME` constant removed from bootstrap

### Tech Debt — Spec Cleanup

- [ ] **DEBT-07**: Remove or document the CORS handler spec mismatch (Phase 3 spec listed `S3MO_CORS_Handler` as separate class, but CORS was folded into `S3MO_URL_Rewriter`)

## Future Requirements

### v2.0+ Candidates

- **Multi-provider support** — DigitalOcean Spaces, Cloudflare R2, GCS
- **CSS/JS asset offloading** — Different problem domain from media
- **Image optimization integration** — WebP conversion, compression
- **WordPress Multisite support** — Per-site bucket configuration

## Out of Scope

| Feature | Reason |
|---------|--------|
| PHP version runtime check | WordPress handles `Requires PHP` header enforcement |
| Automated testing suite | Separate initiative, not part of compat refactor |
| SDK namespace scoping (PHP-Scoper) | `class_exists` guard is sufficient for current deployment |
| Upgrading SDK features | Goal is compatibility, not new AWS features |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| PHP-01 | Phase 7 | Complete |
| PHP-02 | Phase 7 | Complete |
| PHP-03 | Phase 7 | Complete |
| PHP-04 | Phase 7 | Complete |
| DEBT-01 | Phase 8 | Pending |
| DEBT-02 | Phase 8 | Pending |
| DEBT-03 | Phase 8 | Pending |
| DEBT-04 | Phase 8 | Pending |
| DEBT-05 | Phase 8 | Pending |
| DEBT-06 | Phase 8 | Pending |
| DEBT-07 | Phase 8 | Pending |

**Coverage:**
- v1.1 requirements: 11 total
- Mapped to phases: 11
- Unmapped: 0

---
*Requirements defined: 2026-03-01*
*Last updated: 2026-03-01 after Phase 7 completion*
