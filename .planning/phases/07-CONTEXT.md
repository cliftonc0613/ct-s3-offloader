# Phase 7 Context: PHP 7.4 Compatibility

**Phase Goal:** Plugin runs correctly on PHP 7.4+ hosting environments with a compatible AWS SDK version
**Requirements:** PHP-01, PHP-02, PHP-03, PHP-04

## Decisions

### SDK Distribution Method

**Decision:** Download AWS SDK v3.337.3 release zip from GitHub, extract into `aws-sdk/` directory — same approach as current v3.371.3.

- The plan should include downloading and extracting the SDK as a step
- Replace the current `aws-sdk/` contents entirely (directory is gitignored)
- Source: GitHub release archive for tag `3.337.3`

### Version Pinning Strategy

**Decision:** Add a runtime version check — compare `Aws\Sdk::VERSION` against expected version and warn admin if mismatched.

- Add `S3MO_EXPECTED_AWS_SDK_VERSION` constant or equivalent
- On bootstrap, check `Aws\Sdk::VERSION` and show admin notice if SDK version doesn't match
- This prevents silent breakage if someone drops in a different SDK version

### Verification Approach

**Decision:** Let the plan determine what PHP binaries are available on the machine, then use whatever's possible (linting, Local environment, or both).

- Check for PHP 7.4 binary availability (Homebrew, Local by Flywheel shell, etc.)
- Run `php -l` syntax checks if a PHP 7.4 binary is found
- Manual verification in Local by Flywheel is user's responsibility after plan execution

### Documentation Updates

**Decision:** Update all documentation that mentions PHP version requirements.

- Plugin header: `Requires PHP: 8.1` → `Requires PHP: 7.4`
- CLAUDE.md: Update "PHP 8.1+ required" note and any references to typed properties/named arguments
- README.md: Update requirements section
- Grep for any other files mentioning PHP 8.1

## Key Facts

- Plugin PHP code is **already PHP 7.4 compatible** — no syntax changes needed
- The only incompatibility is the bundled AWS SDK v3.371.3 (requires PHP 8.1+)
- AWS SDK v3.337.3 is the last version supporting PHP 7.4 (transition happened at v3.338.0)
- AWS SDK v3.337.3 requires PHP >= 7.2.5
- The SDK API is identical between v3.337.3 and v3.371.3 for the S3 operations we use
- `aws-sdk/` directory is in `.gitignore` — SDK swap is a deployment concern, not a code change

## Deferred Ideas

None captured during discussion.

---
*Created: 2026-03-01*
