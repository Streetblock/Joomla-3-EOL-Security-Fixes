# Official dependency updates — package 1.3.0

The compatibility target is PHP 7.4, based on the operator's provisional runtime information. The installer and generated Composer platform check require PHP >= 7.4.0. Validation uses an isolated official PHP **7.4.33 x64 Windows CLI** distribution; the website's actual FPM/Apache runtime, extensions and third-party integrations still need staging verification.

## Complete releases

| Package | Previous | Packaged release | Pinned upstream source |
| --- | --- | --- | --- |
| paragonie/sodium_compat | 1.17.1 | 1.24.2 | [4524c98](https://github.com/paragonie/sodium_compat/tree/4524c98a826776c9a32d6ce06932563cb10f3995) |
| symfony/yaml | 2.8.52 plus local backport | 5.4.53 | [ae0bbb4](https://github.com/symfony/yaml/tree/ae0bbb46f77ff56591d0a0259c7f458f4b3e1f77) |
| symfony/deprecation-contracts | absent | 2.5.4 | Revision in [DEPENDENCIES.json](../DEPENDENCIES.json) |

The entire packages, licenses, required files and generated Composer autoloader/installed metadata are included. All 146 official/generated file hashes are recorded and checked against the package, with no local changes inside these library sources. The earlier two-file YAML 2.8 patch is superseded. Composer resolved exactly two upgrades and one installation; other installed package versions remain unchanged. The complete package contains 235 files: 26 explicitly new files, 209 replacements, plus four obsolete sodium files to remove. Existing Joomla core/filter backports remain included.

YAML 5.4 retains a PHP >= 7.2.5 floor, whereas the reviewed newer YAML lines need PHP 8.x. Sodium 1.24.2 contains the CVE-2025-69277 correction first released in 1.24.0. The package-level finding is resolved; no claim of a demonstrated Joomla exploit or signature forgery follows from it. Updating this PHP package does not update the server's native libsodium build.

## Installer and compatibility boundaries

Dependency and Composer target hashes must match the known original files, the previously packaged YAML backport, or the exact new release. Unknown custom Composer/dependency installations abort before file changes. Reinstalling the same package is supported. Additions cannot overwrite unrelated files; obsolete files are removed only when their old hash is recognized. Backups record additions as `before: null` and removals as `after: null`. Rollback restores removed/replaced files and removes newly created files and empty directories created by that run.

The YAML change crosses major versions. The original Joomla Registry reader and writer pass roundtrip tests, but old extensions may call removed YAML signatures or rely on changed scalar/tag behavior. Test those integrations with real site configuration. Root `composer.json`/`composer.lock` are build inputs, not files to overwrite on an unknown live installation. A later independent Composer update can undo the pinned runtime changes and needs separate review.

**Joomla Filter exception:** inspected official 1.4.7 and PHP-7-compatible 2.0.6 do not contain all the later `checkAttribute`/`cleanAttributes` corrections already backported here. The reviewed 3.x releases require PHP >= 8.1 and a newer Joomla String dependency. Retaining the existing filter backports avoids losing fixes while PHP 7.4 is the target. Reassess a full update together with the runtime/Joomla migration; do not mix only selected files from a newer major release.

## Build inputs and integrity

Use Composer **2.10.3**, the runtime-only [composer.json](../tools/dependency-build/composer.json) and [composer.lock](../tools/dependency-build/composer.lock), and a separate build directory. The lock pins all dependencies; `config.platform.php` is 7.4.33. Original Joomla development/test dependencies are not part of this runtime build. Do not run a broad dependency update.

```sh
# In an isolated build directory, copy in the two committed Composer files.
php composer.phar install --no-dev --no-scripts --no-plugins --prefer-dist --no-interaction
python tools/package-dependencies.py /path/to/original-joomla-3.10.12 /path/to/build
php -n tools/checksums.php
php -n tools/checksums.php --check
```

The local build used PHP 8.5 with Composer's PHP 7.4.33 platform constraint and ignored only unavailable `ext-gd` and `ext-ldap` requirements during resolution. It did not ignore PHP constraints. These two unchanged Joomla extensions must be checked on the deployment runtime. Library execution and PHP syntax were then tested with actual PHP 7.4.33. Keep official bytes unchanged; `.gitattributes` disables text conversion for these assets. Core backport files retain LF normalization.

The build tool validates requested versions and unchanged versions/source references for all other packages. `DEPENDENCIES.json` records source/dist references, official/generated hashes and accepted target hashes. The runtime checksum inventory is not a publisher signature.

## Validation and remaining scanner findings

- PHP 7.4.33 lint: **185 packaged PHP files plus installer counted together**, all pass.
- Existing security/compatibility suite: **298 checks**, all pass (including 21 bounded YAML checks against the official release).
- Installer: **23 existing + 12 dependency scenarios**, all pass. Additional coverage includes additions, removals, rollback after partial writes, removal failure, final tampering, collisions, unexpected Composer/dependency hashes, concurrent file appearance, invalid parent paths and PHP 7.3 rejection.
- The real installer installs and reinstalls all 235 files and verifies four removals in an isolated fixture containing the original vendor tree. A fresh child process uses the actual regenerated Composer autoloader, not a test autoload override.
- Runtime integration: **17 checks with PHP fallback**, plus **18 with the native sodium extension enabled**, including RFC 8032 signing and verification, tampering rejection, secretbox roundtrip, mixed-order point conversion rejection, installed versions and Joomla Registry YAML roundtrip. Another **21 YAML checks** run against the installed fixture.
- The upgrade test accepts an optional previous-package overlay and verifies upgrading from the fork's 1.2.1 review package as well as reinstalling 1.3.0.

An exploratory direct invocation of `Core32` on the 64-bit interpreter failed on a valid RFC vector with a `RangeException`, including with the unmodified upstream release. This is recorded as a test limitation, not silently repaired in vendor code. Normal `Compat` dispatch selects the implementation by `PHP_INT_SIZE`; the passing results above exercise its 64-bit PHP path. Actual 32-bit PHP is untested and requires separate validation. Native-extension checks use the test runtime's bundled sodium, not the production server's extension.

The OSV inventory now separately queries the **35 actually packaged runtime versions** as well as retaining the original tag-lock baseline. The packaged metadata contains the original extracted vendor tree's 34 entries plus deprecation-contracts; the earlier remote tag-lock audit had 32 entries. Both inventories remain visible in [cve-inventory.json](cve-inventory.json).

Current OSV/Composer matches after the update are only:

- `joomla/filter`: CVE-2025-54476, because the old package version remains; the existing normalization backport was reviewed and its regression suite still passes.
- `paragonie/random_compat`: the previously deferred Low advisory without a CVE. PHP 7.4 provides native random APIs; the older fallback library was not updated in this change.

No current OSV finding remains for the three official updated/new packages. These results cover this package snapshot, not unknown website extensions, template overrides, native libraries, PHP itself or future advisories. No release, upstream PR or live deployment is performed by committing this source.
