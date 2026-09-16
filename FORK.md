# Streetblock fork maintenance

This fork retains local regression tests and detailed review notes. The base was TLWebdesign commit `389fee29da18a71a50baa400cb0bdf5c9f106bfb` (version 1.1.4). The public README describes behavior and installation requirements without fork-specific branding.

The current source prepares fork package **1.2.0**, dated September 16, 2026. Joomla's core version remains **3.10.12**; only the additional EOL marker includes the package version. These metadata changes are separate from the installer implementation and fork tests. Updating `main` does not publish a GitHub release or deploy the package to a website.

## Future upstream pull requests

Create a dedicated branch from the current upstream branch and transfer only the intended changes. Do not open a PR directly from this fork's `main` or blindly cherry-pick commits containing fork-only files.

- Exclude `tests/`, `docs/`, this file, and fork-specific version/date/marker changes.
- Include only the relevant replacement files, installer changes, required package assets and concise README changes. An installer PR needs `checksums.json` and package-build support: they are runtime/package requirements, not test artifacts.
- Let the upstream maintainers choose their release number and regenerate the inventory for that version. The installer reads the version from the manifest; it does not hard-code this fork's release number.
- Report validation commands and results in the PR description, without requiring upstream to adopt the test/documentation layout. Build and validate the exact PR branch separately before submission.

No upstream PR has been opened as part of these changes.

## Local validation

```sh
php -n tools/checksums.php --check
php -n tests/download-headers.php
php -n tests/ssi-uploads.php
php -n tests/installer.php
```

Run `php -n tools/checksums.php` after changing packaged replacement files or the XML version and commit the resulting inventory. Files under `files/` use LF line endings so release builds and clones agree on raw SHA-256 checksums.

The security regression tests are harmless demonstrations of specific faulty behavior plus compatibility checks. They do not execute SSI, attack a live site, or prove that the entire site is secure. Installer tests inject failures into real file operations in isolated temporary directories; Joomla services are test doubles. They cover normal install/update, incomplete and altered packages, version mismatch, failed backups, partial/corrupt copies, restoration failure, concurrent installation locks and final verification failure. A full Joomla installation on the production PHP version still needs staging validation.
