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
