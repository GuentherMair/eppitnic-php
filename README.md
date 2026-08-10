# Requirements

1. PHP 8.0.0 or newer (verified against both the codebase's own syntax and
   every Composer dependency's declared PHP requirement; `slim/psr7` and
   `firebase/php-jwt` are the binding constraints at `^8.0`)
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


# User setup

Every route that creates a user (`POST /v1/users`) requires an admin token
to call it, so the very first admin account can't be created over the API —
use `CLI/user-DoSetup.php` directly against the database instead:

```
php CLI/user-DoSetup.php -m user -u admin -p 'a-strong-password' -b ADMIN-001 -A
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


# ToDo's

1. replace Smarty templates with XML builder
2. verify XML through XSDs
3. Implement a client-daemon with session keep-alive functionality. Btw. this
   is not necessary to pass the accreditation test (simply don't log out), but
   would be rather important if the library was to be used by registrars with
   a very high registration rate.
