# Streetblock fork maintenance

This fork retains local regression tests and detailed review notes. The base was TLWebdesign commit `389fee29da18a71a50baa400cb0bdf5c9f106bfb` (version 1.1.4). The public README describes behavior and installation requirements without fork-specific branding.

The `main` baseline is fork package **1.3.0**, dated September 16, 2026, including the post-EOL audit backports and complete PHP-7.4-compatible library updates. Branch `feat/login-throttling` prepares experimental **1.4.0-dev**, dated September 17, 2026, with opt-in password-login throttling; see [behavior, configuration and validation boundaries](docs/LOGIN-THROTTLING.md). Joomla's core version remains **3.10.12**; only the additional EOL marker includes the package version. No merge into main, GitHub release or website deployment is implied.

## Future upstream pull requests

Create a dedicated branch from the current upstream branch and transfer only the intended changes. Do not open a PR directly from this fork's `main` or blindly cherry-pick commits containing fork-only files.

- Exclude `tests/`, `docs/`, this file, and fork-specific version/date/marker changes.
- Include only the relevant replacement files, installer changes, required package assets and concise README changes. An installer PR needs `checksums.json` and package-build support: they are runtime/package requirements, not test artifacts. This branch additionally records non-vendor file operations in `INSTALLATION.json`; `DEPENDENCIES.json` continues to describe official vendor provenance.
- Let the upstream maintainers choose their release number and regenerate the inventory for that version. The installer reads the version from the manifest; it does not hard-code this fork's release number.
- Report validation commands and results in the PR description, without requiring upstream to adopt the test/documentation layout. Build and validate the exact PR branch separately before submission.

No upstream PR has been opened as part of these changes.

## Local validation

```sh
php -n tools/checksums.php --check
php -n tests/download-headers.php
php -n tests/ssi-uploads.php
php -n tests/installer.php
php -n tests/installer-dependencies.php
php -n tests/package-install.php /path/to/original-joomla-3.10.12
php -n tests/login-throttle.php /path/to/original-joomla-3.10.12
php -n tests/post-eol-regressions.php
php -n tests/batch-copy-acl.php
php -n tests/tags-ordering.php
php -n tests/yaml-limits.php /path/to/original-joomla-3.10.12
php -n tests/output-and-module-acl.php /path/to/original-joomla-3.10.12
php -n tests/package-preflight.php /path/to/original-joomla-3.10.12
```

Run `php -n tools/checksums.php` after changing packaged replacement files or the XML version and commit the resulting inventory. Core replacements use LF line endings. Official vendor packages and generated Composer files are preserved byte-for-byte with `-text` attributes; do not normalize their whitespace. `tools/checksums.php` also verifies them against `DEPENDENCIES.json`.

The [library update review](docs/DEPENDENCY-UPDATES.md) records build inputs, the PHP 7.4 tests and compatibility limits. Prefer complete upstream library releases; retain vendor backports only when a compatible official release lacks required fixes.

The security regression tests are harmless demonstrations of specific faulty behavior plus compatibility checks. They do not execute SSI, attack a live site, or prove that the entire site is secure. Installer tests inject failures into real file operations in isolated temporary directories; Joomla services are test doubles. They cover normal install/update, incomplete and altered packages, version mismatch, failed backups, partial/corrupt copies, restoration failure, concurrent installation locks and final verification failure. A full Joomla installation on the production PHP version still needs staging validation.

The [September CVE audit](docs/CVE-AUDIT-2026-09-16.md) explains the database queries, all 79 core/dependency reviews, additional fixes and remaining gaps. Its [matrix](docs/cve-matrix.md), raw normalized [inventory](docs/cve-inventory.json), manual [review decisions](docs/cve-review.json) and `tools/audit-cves.py` are fork-only assets. A future upstream PR can cite the results without adding these directories.
