# Requirements

1. PHP 8.1.0 or newer (verified against both the codebase's own syntax and
   every Composer dependency's declared PHP requirement; the code itself is
   8.0-compatible, but `spomky-labs/otphp` — which provides TOTP/MFA — and its
   `symfony/deprecation-contracts` dependency bind the minimum at `>=8.1`)
2. [Composer](https://getcomposer.org/), to install the third-party
   dependencies declared in `composer.json` — run `composer install` before
   first use
3. CURL, XML and PDO modules for PHP
4. either a MariaDB/MySQL or another database


# Installation

Run `composer install` to fetch the third-party dependencies into `vendor/`
and generate the class autoloader (`vendor/autoload.php`) — every script in
`CLI/`/`examples/` needs only that one `require`, nothing else.

A database is required for storing/persisting communication with the
server (and, as of this version, all configuration except DB credentials
themselves — see below). Set it up using the schema provided in
`/config/mariadb-schema.sql`, which includes the `settings` table.

Configuration is split in two:

1. `config/config.php` holds the database credentials — this is the one
   thing that has to live in a file, since it's needed to even connect to
   the database everything else is read from. Either copy
   `config/config.php-template` to `config/config.php` and fill it in by
   hand, or just run any CLI script (e.g. `php CLI/user-DoSetup.php ...`)
   from an interactive terminal: if `config/config.php` is missing,
   `Config` (`Net/EPP/Config.php`) notices, prompts you for the database
   type/host/name/charset/user/password right there, and writes the file
   itself. Running the same script non-interactively (cron, CI, piped
   input) with no `config/config.php` in place fails with a clear error
   instead of hanging on a prompt nobody can answer.
2. Everything else lives in the `settings` table, pre-populated with
   placeholder values by `/config/mariadb-schema.sql` itself — no separate
   seed file to copy. A handful of settings that can't have a real default
   (`jwt_psk`, `allowed_origins`, the EPP `username`/`password`/`cl_trid_prefix`)
   are filled in for you: `jwt_psk` is silently auto-generated, and the
   rest are prompted for — same as `config/config.php` above, the first
   time `Config` runs from an interactive terminal and finds them still at
   their placeholder. Everything else (`epp.server`, DNSSEC, Smarty, …) can
   be left at its default or adjusted later with `Config::set()`.

   One setting is maintained by the software rather than by you:
   `epp.lastPasswordUpdate`, a unix timestamp recording when an automated
   registry-password rotation was last attempted (see "Registry password
   rotation" below). Leave it at `0` on a fresh install.

If you're upgrading an existing 6.x deployment from its `config.xml`
instead of starting fresh, `CLI/config-DoMigrate.php` does both steps for
you: `php CLI/config-DoMigrate.php` (reads `config.xml` from the repo root
by default, or `-f PATH` to point elsewhere).

The schema itself is versioned: the `settings` table carries a
`schema_version` row, zero-padded `MMmmrr` (major/minor/release, e.g.
`070000` for 7.0.0), and `Config` (`Net/EPP/Config.php`) checks it against
`SCHEMA_VERSION` (`config/constants.php`) on every initialization,
auto-applying `config/mariadb-schema-upgrade-{from}-to-{to}.sql` files one
version-to-next-version step at a time until it catches up — including, if
the `settings` table doesn't exist yet at all, starting from the assumed
legacy baseline `060700` (currently resolving to
`config/mariadb-schema-upgrade-060700-to-070000.sql`, which upgrades a
legacy 6.7 schema, still on `tbl_*`-prefixed tables). No filename is
hardcoded; every step, including that first one, is found purely by this
naming convention. To add a future migration: drop a new
`config/mariadb-schema-upgrade-{current}-to-{next}.sql` file and bump
`SCHEMA_VERSION` to match — nothing else needs to change.

After you have set everything up, simply try to have a
look at the `CLI/` and `examples/` folders.


# Upgrading from 6.x

Everything a 6.x installation needs, in the order it needs doing. Steps 1–5
are required; the rest are things that changed under you and are worth
knowing about.

**Take a full database backup first.** The schema migration rebuilds every
text column (`CONVERT TO CHARACTER SET`) and re-running it will not undo it.
Rehearse on a staging copy.

### 1. PHP 8.1+ and Composer

Dependencies are no longer vendored: `composer install` fetches them into
`vendor/` and generates the autoloader. Every script now needs exactly one
`require` — `vendor/autoload.php` — and nothing else. The minimum is PHP
8.1, set by `spomky-labs/otphp` (TOTP/MFA) rather than by this code, which is
itself 8.0-compatible.

### 2. `config/config.php` for the database credentials

Only the database credentials still live in a file, because they are needed
to reach the database that holds everything else. Copy
`config/config.php-template` and fill it in, or just run any CLI script from
a terminal and let `Config` prompt you and write the file itself.

### 3. `config.xml` → the `settings` table

    php CLI/config-DoMigrate.php          # or -f PATH to point elsewhere

This reads your existing `config.xml` and writes both `config/config.php`
and the `settings` rows. Afterwards `config.xml` is no longer read by
anything and can be archived.

Three settings are gone and are silently ignored if present: `debug`,
`epp.passwordexpirydays` and `epp.passwordexpirynext`. One is new and should
be left at `0` on an existing installation: `epp.lastPasswordUpdate` (see
"Registry password rotation" below).

### 4. The schema migration

There is nothing to run by hand. The `settings` table carries a
`schema_version` row and `Config` applies
`config/mariadb-schema-upgrade-{from}-to-{to}.sql` one step at a time on the
next initialization, starting from the assumed legacy baseline `060700` when
no `settings` table exists yet.

For 6.x that step is `060700-to-070000`, which drops the `tbl_` table
prefixes and converts everything to `utf8mb4`/`utf8mb4_unicode_ci`. It runs
its own read-only pre-flight checks first and aborts before touching
anything if it finds a problem — most usefully, case-insensitive collisions
in `tbl_domains.domain` that the new collation would turn into duplicate-key
errors. If it aborts, resolve the collisions and re-run.

One table is deliberately left alone: `handleID` (MyISAM, utf8mb3). It
belongs to no schema still in use. Confirm whether you need it before
dropping it yourself.

### 5. Check what was removed before you upgrade

- **The web interface is gone.** The PHP/Smarty/jQuery frontend has been
  replaced by a JSON/REST API (`public/`, routed via Slim, documented in
  `API.md`). If you were using the old UI, you need a client for the new API
  before upgrading, not after.
- **Invoicing is gone** from the code: the `InvoicingCDR` class and the
  `/v1/accounting` routes no longer exist, and it will be reimplemented
  separately. Your data is not gone with it, though the two halves fare
  differently, so check both:
  - the `accounting` table is *renamed* (`tbl_accounting` → `accounting`)
    and left in place with all its rows. Nothing reads it any more. Export
    it whenever you like and drop it by hand once you are satisfied — the
    migration will never do that for you.
  - `users.billing_id` **is** dropped by the migration. If you need the
    user-to-billing-account mapping, export it **before** migrating; after
    the fact it is only recoverable from a backup.
- **WSDL support is gone.**
- **`Net_EPP_StorageDB` / `Net_EPP_StorageInterface` are gone.** Persistence
  talks to RedBeanPHP's `R::` facade directly. Custom storage backends built
  on those interfaces need rewriting.

### 6. Authentication changed

PHP sessions are out; bearer-token JWTs are in (`firebase/php-jwt`), with
optional TOTP MFA and long-lived API tokens for scripted access. Passwords
are hashed with `password_hash()` instead of MD5, so **every existing user
password is invalid** and must be reset — see "User setup" below.

### 7. Two behaviour changes that can bite quietly

- **`Domain->get('tech')` always returns an array** (keyed handle =>
  handle). It used to return a bare string when a domain had exactly one
  technical contact — the common case — so
  `array_keys((array) $domain->get('tech'))` gave `[0]` rather than the
  handle. Callers that special-cased the string return should drop that
  branch.
- **Domain scoping is uniform.** Every domain route now filters non-admins
  by the domain's own owner (`domains.user_id`), and pending transfers by
  whoever requested them (`transfers.user_id`). A domain's registrant must
  also be a contact the caller owns. If your data has domains whose
  registrant belongs to a different user than the domain, those two notions
  have already drifted; `CLI/domain-CheckOwnershipCoherence.php` reports
  them (see "Ownership coherence").

### 8. After upgrading: re-parse the stored poll messages

Older releases did not recognise the registry's extdom-2.0 poll messages, so
messages already sitting in your `messages` table may carry `type =
'unknown'` and an empty `domain` — most of them DNS validation failures and
warnings, whose domain was dropped. New messages are parsed correctly from
this release on; existing rows keep whatever they were stored with.

A `doctor reparse-messages` command will re-derive `type` and `domain` for
those rows from the raw responses in `msgqueue`. **It is not available yet**
— it arrives with the CLI consolidation (see `docs/REFACTOR-PLAN.md`, Phase
3). It will only rewrite those two columns and will deliberately fire no
side effects: no reminder rows and no DNS-sync events for failures that are
years old.

Nothing depends on this backfill. `PollProcessor` matches only
`type LIKE '%Transfer'`, which was never affected, so the consequence of
leaving it undone is a poll-queue view that under-reports historical DNS
problems.


# Web server

The `CLI/` and `examples/` scripts need nothing beyond PHP. The REST API
(documented in `API.md`) additionally needs a web server, configured two ways:

1. **The document root must be `public/`, and only `public/`.** Everything
   else in the checkout has to stay outside the served tree —
   `config/config.php` holds the database credentials, and `CLI/`,
   `cronjobs/` and `vendor/` have no reason to be reachable over HTTP.
2. **Anything that is not a real file must be routed to
   `public/index.php`.** Slim is a front controller: `/v1/domains` exists
   only as a route inside `index.php`, never as a file on disk, so without
   this the web server answers 404 before PHP is ever involved — the whole
   API looks dead.

Ready-made configurations for both are in `config/apache-vhost.sample` and
`config/nginx.sample`. Copy the one you need, adjust the host name, paths and
certificates, and enable it. To check the result, `GET /v1/network-check` is
public and needs no token:

```
curl -i https://epp.example.com/v1/network-check
```

That must come back as JSON. An HTML 404 means the front-controller routing
isn't in place.

For local development you can skip all of this — PHP's built-in server
already routes everything to one script:

```
php -S localhost:8080 -t public public/index.php
```

Finally, Smarty compiles the EPP templates into `smarty/compile/` and caches
into `smarty/cache/`. Both are part of the repository, but they must be
**writable by the user the web server runs as** (`www-data`, `php-fpm`, …).
If they are not, `Net/EPP/Client.php` falls back to the system temp directory
and emits a notice on every request — workable, but it means compiled
templates land in a shared world-writable directory.


# User setup

Every route that creates a user (`POST /v1/users`) requires an admin token
to call it, so the very first admin account can't be created over the API —
use `CLI/user-DoSetup.php` directly against the database instead:

```
php CLI/user-DoSetup.php -m user -u admin -p 'a-strong-password' -A
```

The same script can also issue a fixed, non-expiring (or time-limited) API
token for scripted/headless access, as an alternative to logging in for a
short-lived JWT:

```
php CLI/user-DoSetup.php -m token -u admin -x 0
```

`-x` takes a validity period in days, counted from now (e.g. `-x 365` for a
token valid one year). `-x 0` (the default if `-x` is omitted) means the
token never expires — the script prints a `WARNING` about this, since
there's no automatic rotation. Prefer a real, finite `-x` unless a
non-expiring credential is genuinely what you want.


# Ownership coherence

Two columns describe ownership of a domain: `domains.user_id` (who owns the
domain) and `contacts.user_id` (who owns the contact acting as its
registrant). From 7.0.0 on they are expected to agree — every domain route
scopes non-admins by the domain's own owner, and the API refuses to set a
registrant the caller doesn't own. Legacy 6.x data never enforced this, so an
upgraded database can contain rows where they disagree; such a domain is
editable by its owner but attributed to somebody else, and cannot be repaired
by simply re-saving it (its owner isn't allowed to name that contact, and the
contact's owner isn't allowed to touch the domain).

```
php CLI/domain-CheckOwnershipCoherence.php
```

lists any such domain, plus any pending transfer-in request that would create
one when it completes, and prints how to resolve each. It changes nothing.
Exit code `0` means coherent, `6` means mismatches were found — so it can run
from cron and alert on the exit status; add `-q` to suppress the output and
keep only the exit code.

The same query is section 6h of
`config/mariadb-schema-upgrade-060700-to-070000.sql`, but that only prints
anything when the migration is applied by hand through the `mysql` client, so
prefer this script after an automatic migration.


# Registry password rotation

nic.it warns, through the EPP poll queue, that the account password is
approaching expiry. Those `passwdReminder` messages are acted on by
`cronjobs/process-poll-queue.php`: when one is outstanding it generates a new
password, sets it at the registry (EPP carries a new password in the `<login>`
command, so the rotation *is* a login), stores it in the `epp` setting, and
acknowledges the message. Run that cron job — without it the reminders
accumulate unread until the credential expires and every EPP call starts
failing.

At most one rotation is attempted per 24 hours, tracked by
`epp.lastPasswordUpdate`. The timestamp is written *before* the attempt, on
purpose: if a rotation half-succeeds — the registry accepts the new password
but the reply is lost — retrying minutes later with yet another password would
compound the problem, and the registry re-sends its reminder well before the
credential actually expires.

The one failure worth watching the logs for is the registry accepting the new
password while storing it locally fails; that locks this installation out of
EPP. The job prints the new password to stdout in that case, so keep the cron
output somewhere you can read it (the suggested crontab line redirects it to
`/var/log/eppitnic/poll-queue.log`).


# ToDo's

1. replace Smarty templates with XML builder
2. verify XML through XSDs
3. Implement a client-daemon with session keep-alive functionality. Btw. this
   is not necessary to pass the accreditation test (simply don't log out), but
   would be rather important if the library was to be used by registrars with
   a very high registration rate.
