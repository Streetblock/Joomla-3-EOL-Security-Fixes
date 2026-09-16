# Joomla 3 EOL Security Fixes 
## Experimental branch: login throttling (1.4.0-dev)

This branch adds optional password-login throttling before Joomla's authentication plugins. It is **disabled by default**. Blocked sources receive the ordinary login failure response even when submitting correct credentials; existing controller statuses and redirects remain unchanged. No special 429 response or retry header is sent. Rejection is early and may be measurably faster than password verification; this does not promise an undetectable block.

For a protected **single-host staging installation**, create a private directory outside all served paths (Unix mode 0700 or equivalent Windows ACLs), then add these properties inside `JConfig` in `configuration.php`:

```php
public $eol_login_throttle = true;
public $eol_login_throttle_path = '/private/joomla-login-throttle';
```

The existing site `secret` must be at least 32 bytes. Defaults are 50 failed checks per source and 20 per account across IPs in a 10-minute observation window; blocks last 15 minutes, doubling for repeat offences up to one hour. Denied requests do not extend the active block. IPv6 sources share a /64 budget. Only `REMOTE_ADDR` is trusted; configure proxy address handling in the web server. Shared IPs and targeted account attacks can block legitimate users too.

The private store includes HMAC account identifiers, bounded counters, in-flight reservations and rotating audit summaries distinguishing failed checks from denied requests. Corrupt/full/unavailable state denies password logins. Keep out-of-band configuration access: setting `eol_login_throttle = false` restores the original behavior. This does not protect existing sessions, stock passwordless remember-me flows or third-party login implementations outside Joomla's authentication entry point.

The branch has isolated PHP/filesystem tests, including parallel processes. It has **not** been validated as a full database-backed Joomla website with browser logins. Test actual frontend/backend, 2FA, recovery and installed extensions before enabling. No production deployment or release is implied by this branch.

## Additional changes (unreleased)

This package requires **Joomla 3.10.12 and PHP 7.4 or later**. PHP 7.4.33 on 64-bit Windows is the tested compatibility target; verify the actual web-server PHP runtime on staging.

Complete official dependency releases replace the earlier custom YAML backport: **Symfony YAML 5.4.53**, **sodium_compat 1.24.2** and the required **Symfony deprecation-contracts 2.5.4**. Composer metadata and autoload files are updated together. Source revisions and file hashes are recorded in `DEPENDENCIES.json`; library source is unmodified.

The September 2026 review adds or completes the following backports:

- **CVE-2026-35222:** Validate tag ordering options and constrain stored sort directions to `ASC`/`DESC` before SQL construction. Invalid stored directions fall back to `ASC`.
- **CVE-2026-48901:** Include `stripUSC` in the InputFilter instance cache key so different filtering policies cannot share an instance.
- **CVE-2026-45133, CVE-2026-45304, CVE-2026-45305:** Update Symfony YAML from 2.8.52 to the complete official 5.4.53 release, including its nesting/collection-alias limits and cleanup corrections. Documents exceeding 128 nesting levels or 128 collection-alias references are rejected. Joomla Registry's YAML reader/writer is tested; extensions calling old YAML APIs directly need staging checks, since this is a major-version upgrade.
- **CVE-2024-21725, CVE-2025-63083, CVE-2026-21631, CVE-2026-25901:** Complete escaping in converted email/URL output, previous/next article labels and association comparison attributes.
- **CVE-2026-21632, CVE-2026-30895, CVE-2026-48950, CVE-2026-48952, CVE-2026-48953:** Disable HTML in truncated readmore titles; escape template paths, installer update metadata and generic image attributes.
- **CVE-2026-48956, CVE-2026-73371:** Require frontend module-edit permission before dispatch and source-edit permission for batch copies, in addition to destination-create permission. Preserve frontend modal pagination tokens. Copying categories/menu trees may now fail where source/descendant permissions are missing; validate your editorial workflows.

The existing filter also covers the whitespace normalization addressed by **CVE-2025-54476**. Presence of a CVE in the historical release list is not by itself verification of every affected code path.

**CVE-2025-69277:** Update `sodium_compat` from 1.17.1 to the complete official 1.24.2 release. This supersedes the previously open Medium package finding. The PHP fallback and native sodium dispatch are tested on PHP 7.4.33 x64; an actual 32-bit runtime has not been tested. Updating the PHP library does not update the server's native libsodium extension.

Prefer complete compatible dependency releases over local vendor patches. The existing Joomla Filter backports remain an exception: the reviewed PHP-7-compatible releases do not contain all the filtering corrections retained here; the newer 3.x line requires PHP 8.1. Its old Composer version may still trigger a scanner warning. Do not replace it with an older, less complete filter just to change the version string.

**CVE-2026-71572:** Remove double quotes from filenames in contact vCard and banner tracking download headers, following Joomla 5.4.8. Download contents and ordinary filenames are preserved.

- [Official advisory](https://developer.joomla.org/security-centre/1068-20260801-core-response-header-injection-in-download-views.html)
- [Official release comparison](https://github.com/joomla/joomla-cms/compare/5.4.7...5.4.8)

**CVE-2026-73373:** Backport the `.shtml`, `.shtm`, `.sht` and `.stm` restrictions from Joomla 5.4.8 and 6.1.3 to the central upload filter, media helper, template helper and media controller. Helper checks also handle mixed case and embedded extensions such as `document.SHTML.txt`.

- [Official SHTML upload advisory](https://developer.joomla.org/security-centre/1077-20260810-core-unrestricted-uploads-of-shtml-files.html)

**Installer:** Validate Joomla 3.10.12, PHP >= 7.4, the complete package inventory, SHA-256 checksums and every managed target. Back up and verify existing files; support explicitly listed additions and obsolete-file removals, verify the resulting state and attempt rollback on failure. Unknown local changes to updated dependencies or Composer autoload files cause an abort. Report file verification rather than claiming the entire site is secure. Keep the extension record instead of automatically uninstalling it.

These are selected backports, not a complete Joomla security update or coverage of every August advisory. Existing language-parser compatibility limitations also apply. The previous release history below is retained for reference.

## Installation and recovery

1. Take a full site and database backup. Test on a protected copy with the production PHP version. Review local modifications to the files being replaced: existing custom changes are backed up but will be overwritten.
2. Create a private directory writable by PHP **outside all publicly served directories**, including virtual-host aliases. The default is `joomla3-eol-backups` beside the Joomla root. Alternatively, add `public $eol_security_backup_path = '/absolute/private/backup/path';` inside the `JConfig` class in `configuration.php`. The installer rejects a backup path inside Joomla; it cannot detect every web-server alias. Restrict directory permissions to the PHP account (0700 on Unix; equivalent Windows ACLs).
3. Put the site in maintenance mode and prevent concurrent requests/updates during replacement. This multi-file update is not atomic. Install the package containing `checksums.json`; missing source or required replacement targets cause an abort. Only explicitly listed dependency additions may be absent. Symlinked paths are unsupported. Direct PHP filesystem access is required; Joomla's FTP layer is not used. Customized Composer installations require a separate reviewed integration; do not bypass a rejected hash check.
4. Require the explicit message that all managed file states were verified. Keep the reported backup directory and its `restore.json`, which maps each relative path to its backup filename and before/after checksums. `before: null` means a newly added file: remove it during manual recovery. `after: null` means an obsolete file was removed: restore its backup during recovery. A Joomla version marker alone is not proof. Then test site functions before reopening.

On a copy or verification failure, the installer restores replaced/removed files, removes newly added files and verifies their original state. If restoration fails, it reports **RESTORE FAILED**; keep the site offline and restore the affected files from the retained backup (or your full site backup). A process crash, disk failure or later Joomla metadata failure may require manual recovery. Removing the retained extension entry does **not** undo the patches. These backups cover managed files only, not the database or the rest of the site.

The checksum inventory detects incomplete or changed package files; it is not a publisher signature and does not check whether the existing site is compromised. Keep the inventory synchronized when preparing a package. Runtime installation does not require the fork's test or documentation directories.

## Version 1.1.4 fixes the below security issues (it also contains all previous versions fixes)
- [20260702] — Core — Incorrect Access Control in com_contact vcf download (CVE-2026-48948). More info: https://developer.joomla.org/security-centre/1056-20260702-core-incorrect-access-control-in-com-contact-vcf-download.html
- [20260708] — Core — XSS through language overrides (CVE-2026-48954). More info: https://developer.joomla.org/security-centre/1062-20260708-core-xss-through-language-overrides.html

## Version 1.1.3 fixes a regression with com_users login in certain cases.

## Version 1.1.2 fixes the below update issues (it also contains all previous versions fixes)
- Infrastructure — Realigned the internal XML manifest and installer scripts to v1.1.2 to force a clean update path. This ensures the Joomla updater successfully applies the files, updates the success messages, and correctly displays the new version in the backend footer.

## Version 1.0.11 fixes the below security issues (it also contains all previous versions fixes)
- [20260501] — Core — XSS in feed modules (CVE-2026-25900). More info: https://developer.joomla.org/security-centre/1033-20260501-core-xss-in-feed-modules.html
- [20260503] — Core — XSS in com_contenthistory (CVE-2026-30894). More info: https://developer.joomla.org/security-centre/1035-20260503-core-xss-in-com-contenthistory
- [20260509] — Core — LFI in HTMLView layout parameter (CVE-2026-40383). More info: https://developer.joomla.org/security-centre/1041-20260509-core-lfi-in-htmlview-layout-parameter.html
- [20260518] — Core — Transport encryption downgrade for password and username reset links (CVE-2026-48902). More info: https://developer.joomla.org/security-centre/1050-20260518-core-transport-encryption-downgrade-for-password-and-username-reset-links.html
- [20260519] — Framework — Inadequate content filtering within the checkAttribute filter code (CVE-2026-48903). More info: https://developer.joomla.org/security-centre/1051-20260519-framework-inadequate-content-filtering-within-the-checkattribute-filter-code.html
- [20260520] — Framework — Inadequate content filtering within the cleanAttributes filter code (CVE-2026-48905). More info: https://developer.joomla.org/security-centre/1052-20260520-framework-inadequate-content-filtering-within-the-cleanattributes-filter-code.html

## Version 1.0.10 fixes the below security issues (it also contains all previous versions fixes)
- [20260301] — Core — ACL hardening in com_ajax (CVE-2026-21629). More info: https://developer.joomla.org/security-centre/1018-20260301-core-acl-hardening-in-com_ajax.html
- [20260303] — Core — XSS vector in com_associations comparison view (CVE-2026-21631). More info: https://developer.joomla.org/security-centre/1020-20260303-core-xss-vector-in-com_associations.html

## Version 1.0.9 fixes the below security issues (it also contains all previous versions fixes)
- [20260102] — Core — XSS vector in the pagebreak plugin (CVE-2025-63083). More info: https://developer.joomla.org/security-centre/1017-20260102-core-xss-vector-in-the-pagebreak-plugin.html
- [20260101] — Core — Inadequate content filtering for data URLs (CVE-2025-63082). More info: https://developer.joomla.org/security-centre/1016-20260101-core-inadequate-content-filtering-for-data-urls.html
- [20250401] — Framework — SQL injection in Database package (CVE-2025-25226). More info: https://developer.joomla.org/security-centre/963-20250401-framework-sql-injection-vulnerability-in-quotenamestr-method-of-database-package.html
- [20250301] — Core — Malicious file uploads via Media Manager (CVE-2025-22213). More info: https://developer.joomla.org/security-centre/961-20250301-core-malicious-file-uploads-via-media-manager.html
- [20240702] — Core — Lack of escaping in module chrome attributes (CVE-2024-40747). More info: https://developer.joomla.org/security-centre/941-20240702-core-lack-of-escaping-in-module-chrome-attributes.html

## Version 1.0.8 fixes the below security issues and bugs caused by it. (it also contains all previous versions fixes)
- [20250103] - Core - Read ACL violation in multiple core views. More info on the vulnerability here: https://developer.joomla.org/security-centre/956-20250103-core-read-acl-violation-in-multiple-core-views.html
- [20250102] - Core - XSS vector in the id attribute of menu lists. More info on the vulnerability here: https://developer.joomla.org/security-centre/955-20250102-core-xss-vector-in-the-id-attribute-of-menu-lists.html

## Version 1.0.6 fixes the below security issues and bugs caused by it. (it also contains all previous versions fixes)
- [20240805] - Core - XSS vectors in Outputfilter::strip* methods. More info on the vulnerability here: https://developer.joomla.org/security-centre/946-20240805-core-xss-vectors-in-outputfilter-strip-methods.html
- [20240802] - Core - Cache Poisoning in Pagination. More info on the vulnerability here: https://developer.joomla.org/security-centre/942-20240802-core-cache-poisoning-in-pagination.html
- [20240801] - Core - Inadequate validation of internal URLs. More info on the vulnerability here: https://developer.joomla.org/security-centre/941-20240801-core-inadequate-validation-of-internal-urls.html
- Besides the security fixes i also fixed the com_search and com_finder views that were broken because of the security fixes. Thanks to David Jardin for providing the fixes for J4/5. This way i was able to backport them to J3.

## Version 1.0.5 fixes the below security issues. (it also contains all previous versions fixes)
- [20240705] - Core - XSS in com_fields default field value. More info on the vulnerability here: https://developer.joomla.org/security-centre/939-20240705-core-xss-in-com-fields-default-field-value.html
- [20240704] - Core - XSS in Wrapper extensions. More info on the vulnerability here: https://developer.joomla.org/security-centre/938-20240704-core-xss-in-wrapper-extensions.html
- [20240703] - Core - XSS in StringHelper::truncate method. More info on the vulnerability here: https://developer.joomla.org/security-centre/937-20240703-core-xss-in-stringhelper-truncate-method.html

## Version 1.0.0 - 1.0.4 fixes the below security issues.
- [20240205] - Core - Inadequate content filtering within the filter code. More info on the vulnerability here: https://developer.joomla.org/security-centre/929-20240205-core-inadequate-content-filtering-within-the-filter-code.html
- [20240203] - Core - XSS in media selection fields. More info on the vulnerability here: https://developer.joomla.org/security-centre/927-20240203-core-xss-in-media-selection-fields.html
- [20240202] - Core - Open redirect in installation application. More info on the vulnerability here: https://developer.joomla.org/security-centre/926-20240202-core-open-redirect-in-installation-application.html
- [20240201] - Core - Insufficient session expiration in MFA management views. More info on the vulnerability here: https://developer.joomla.org/security-centre/925-20240201-core-insufficient-session-expiration-in-mfa-management-views.html
- [20231101] - Core - Exposure of environment variables. More info on the vulnerability here: https://developer.joomla.org/security-centre/919-20231101-core-exposure-of-environment-variables.html

## Donate to the joomla project!
If this plugin saved you valuable time please consider donating something to the joomla project: https://community.joomla.org/donate. 
Especially agencies who will save tons of time when they have multiple websites still on J3. Any donation is much appreciated.

## Backup First!
**Always try this fix first on a test environment!**
- The fix for security issue [20231101] could potentially break language files that were not following exact specification. Previously these language files would still work but in fixing the vulnerability the strictness of how these files are processed makes it that a language string can not have new lines in the content anymore.
- The fix for security issue [20240801] could potentially break components that worked in certain way. More info about this here: https://docs.joomla.org/J5.x:Behavior_change_for_Uri::isInternal_for_URLs_without_protocol

**A special thank you to the Joomla Security Strike Team for keeping Joomla save and doing the hard work here. I just backported their fixes!**
