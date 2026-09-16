# Experimental password-login throttling

Branch: `feat/login-throttling`, based on fork package 1.3.0. Experimental package version: **1.4.0-dev**. This is opt-in functionality, not an upstream Joomla patch or a deployed website change.

## Behavior

With the feature enabled, admission is checked before Joomla's authentication plugin chain. A blocked password submission receives the same `AuthenticationResponse` failure as an ordinary incorrect password, using `JGLOBAL_AUTH_INVALID_PASS`. The existing frontend/backend controllers retain their normal failure handling, HTTP statuses and redirects. No `429`, `Retry-After`, lockout-specific message or fake success is introduced. Correct credentials from a blocked source are also rejected without running the authentication plugins or creating a logged-in session.

This masks the reason for rejection in the application response, not in every observable property. Early rejection can be faster than password verification. No dummy password hashes, deliberate sleeps or claim of timing indistinguishability are included. A determined attacker may infer throttling or rotate addresses anyway. External WAFs, custom templates and third-party plugins can also change visible responses.

The scope is password authentication through Joomla's normal `Authentication::authenticate()` entry point, including frontend/backend and direct application login calls that provide a password key. Existing authenticated sessions and stock remember-me authentication without a password key remain unchanged. Alternative SSO/passwordless flows and extensions implementing their own login outside this entry point are not covered.

## Policy and storage

Initial experimental defaults, to be tuned on staging:

| Setting | Default | Meaning |
| --- | --- | --- |
| `ip_limit` | 50 | Failed checks per source during the observation window |
| `account_limit` | 20 | Failed checks for one account across all source IPs |
| `window` | 600 seconds | Fixed observation window, starting with the first admitted attempt |
| `cooldown` | 900 seconds | First block duration |
| `max_cooldown` | 3600 seconds | Upper bound after repeat offences double the duration |
| `reservation_ttl` | 60 seconds | Maximum authentication time before an in-flight reservation expires |
| `max_entries` | 2048 | Maximum combined source/account records |

These are engineering defaults, not OWASP/NIST prescribed values or a compliance claim. The threshold failure starts the block; subsequent attempts are denied. Blocked requests do not count as additional failed password checks and do not extend a block. Repeat-offence history expires after 24 hours of no new block. A correct authenticated response before a block resets the account failure count; it does not clear the IP's failures against other accounts. Login authorization/permissions are still evaluated by Joomla after authentication.

Frontend and backend share the same budgets. Existing usernames resolve through Joomla's database lookup to the numeric user ID, so database-collation aliases cannot create a fresh account budget. Unknown names receive HMAC identifiers. Alternative authentication plugins with identities/aliases outside Joomla's user table need separate review.

IPv4-mapped IPv6 is normalized to IPv4. IPv6 source limits apply to `/64` networks to cover privacy-address rotation. Source IP comes only from `REMOTE_ADDR`; untrusted `X-Forwarded-For`/`Forwarded` headers cannot reset the limit. Behind a reverse proxy, configure trusted client-IP resolution in the web server first. Otherwise all users may share the proxy's budget. Corporate NATs and IPv6 subnets can also group legitimate users.

The store is designed for a **single web-server host with a local filesystem**, not NFS or independent filesystems on multiple web nodes. It uses a private directory, short nonblocking `flock` transactions, atomic state-file replacement, and reservations made before calling authentication plugins. No lock is held during password hashing. Concurrent attempts cannot all use a stale counter; full reservation capacity also denies new attempts. Crashed/timed-out checks consume failure budget while their observation window remains relevant, and late results cannot authenticate.

State is capped at 8 MiB and the configured record count. Expired records are collected during requests; active blocks are not evicted to admit new identities. Corruption, capacity exhaustion, unavailable locks, invalid peer addresses and persistence failures deny password login while the feature is enabled. This protects enforcement but can affect legitimate logins, especially under saturation; upstream request limits and monitoring are still necessary. This file-based experiment is not a volumetric DoS defense.

## Enable only on a protected test installation

Create a writable private directory outside **all** served paths/aliases. Use mode `0700` on Unix or equivalent restrictive Windows ACLs. The code rejects directories inside Joomla but cannot detect web-server aliases. Add these properties inside the site's `JConfig` class in `configuration.php`:

```php
public $eol_login_throttle = true;
public $eol_login_throttle_path = '/private/joomla-login-throttle';
public $eol_login_throttle_policy = array(
    'ip_limit' => 50,
    'account_limit' => 20,
    'window' => 600,
    'cooldown' => 900,
    'max_cooldown' => 3600,
    'reservation_ttl' => 60,
    'max_entries' => 2048,
);
```

The site's existing `secret` must be at least 32 bytes; it keys HMAC identifiers. No database migration or Composer update is required. The new core class is loaded through Joomla's existing namespace loader. Absence of `eol_login_throttle` leaves the original authentication behavior active. Set it to `false` to disable the feature through an out-of-band configuration edit; do not expose this switch publicly. Disabling restores original login behavior and also removes this protection.

Before enabling, retain restricted server/configuration access for recovery. Targeted account attacks can temporarily prevent the real owner from logging in, including from another IP. No CAPTCHA, MFA bypass, self-service unlock, permanent account disable or new recovery channel is implemented here. Keep administrative access separately protected and test existing 2FA and recovery workflows. Changing limits or the site secret should be done under maintenance; do not clear active state automatically during normal requests.

## Audit and operational visibility

`audit.jsonl` records threshold blocks, aggregated denials and block expiry. Entries contain timestamp, scope/HMAC identifier, source IP when available, failed-check count, denied-request count and block expiry. Neither passwords, OTPs, session tokens nor plaintext usernames are written by this guard. Existing Joomla logging plugins remain independently configured.

Repeated denials are summarized at most once per minute per bucket; the cumulative denied count is retained in state and reported on expiry. The audit rotates between `audit.jsonl` and `audit.previous.jsonl`, each bounded to 1 MiB. Rotation is a size bound, not a time-based retention policy. Logging is best effort; a busy/unwritable audit sink does not grant access. Missing/corrupt enforcement state is different: login is denied. Store failures also emit a generic server error-log message at most once a minute where the separate health marker can be written. Invalid initial directory/secret configuration can only use the server error log. Monitor these files and apply site-specific access/retention rules.

## What has actually been tested

Run with actual PHP 7.4:

```sh
php -n tests/login-throttle.php /path/to/original-joomla-3.10.12
php -n tests/package-install.php /path/to/original-joomla-3.10.12 /path/to/extracted-1.3.0-package
php -n tools/checksums.php --check
```

The local test uses the real guard, real filesystem, real `Authentication` plugin dispatch, the original `AuthenticationResponse`, and the original `CMSApplication::login()` method. Joomla database/services and the authentication plugin are test doubles with deterministic outcomes. Twelve separate PHP processes exercise shared-file concurrency. Virtual time exercises expiry, repeat penalties, reservation timeouts and observation windows without wall-clock sleeps in production.

Local results on PHP 7.4.33 x64: **203 throttle checks**, **35 existing installer scenarios**, and syntax checks of all **187 packaged PHP files including the installer** passed. The package fixture checks **237 file hashes**, four obsolete-file removals, upgrade from the extracted 1.3.0 review package and reinstall. Library integration checks also run in fresh child processes after installation.

Coverage includes blocked correct passwords, distributed account attacks, identity aliases, spoofed forwarded headers, IPv4/IPv6 normalization, unchanged application failure messages/events, no authenticated-session events on rejection, stock disabled/remember-me paths, corrupt/empty/oversized state, lock contention, bounded logs, capacity and parallel reservation limits.

This is **not a full running Joomla installation with a database and browser**. It does not prove byte-identical HTTP responses, production timing, database-collation behavior with real MySQL, actual Joomla password/OTP validation, or third-party plugin compatibility. Package fixture installation validates deployment files and hashes but is not a database-backed site installation. Staging must cover actual frontend/backend form submissions, correct/incorrect passwords, 2FA, remember-me, reset/recovery, proxy addressing and authorized sessions before enabling on the business website.

Design references: [OWASP Authentication](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html), [OWASP Credential Stuffing Prevention](https://cheatsheetseries.owasp.org/cheatsheets/Credential_Stuffing_Prevention_Cheat_Sheet.html), [OWASP Denial of Service](https://cheatsheetseries.owasp.org/cheatsheets/Denial_of_Service_Cheat_Sheet.html). Generic outward responses supplement actual enforced limits; their obscurity is not the security boundary.
