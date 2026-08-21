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


# ** Warning **

This installation is a breaking change. It will remove the accounting table
from an existing installation in case of upgrade. Please read the Upgrading
section for more details!


# Installation

Run `composer install` to fetch the third-party dependencies into `vendor/`
and generate the class autoloader (`vendor/autoload.php`) — `bin/eppitnic`
needs only that one `require`, nothing else.

A database is required for storing/persisting communication with the server
(and, as of this version, all configuration except DB credentials themselves
— see below). `config/config.php` is created by running `eppitnic setup` —
prompts interactively, or takes `--db-name=`, `--admin-username=` and so on
for a non-interactive/scripted install — or by visiting the application's URL
in a browser: with no `config/config.php` present, it serves a bundled
installer page (`public/setup.html`) that walks through the same steps over
`POST /v1/setup`. Either one applies `config/mariadb-schema.sql`, which
includes the `settings` table, and creates the first admin account, so there
is no separate "set up the schema by hand" step. (If you'd rather do it by
hand — copy `config/config.php-template` to `config/config.php`, apply
`config/mariadb-schema.sql` yourself, and use `bin/eppitnic user create
--admin` for the first account.)

Configuration is split in two:

1. `config/config.php` holds the database credentials — this is the one
   thing that has to live in a file, since it's needed to even connect to
   the database everything else is read from. Its absence is also what
   `eppitnic setup`/the installer page above key off: once it exists, both
   are unreachable (`eppitnic setup` refuses to run again, and the setup
   routes answer `404`).
2. Everything else lives in the `settings` table, pre-populated with
   placeholder values by `config/mariadb-schema.sql` itself — no separate
   seed file to copy. A handful of settings that can't have a real default
   (`jwt_psk`, `allowed_origins`, the EPP `username`/`password`/`cl_trid_prefix`)
   are filled in for you: `jwt_psk` is silently auto-generated on first
   connection, and `eppitnic setup` (or the installer page) fills in the EPP
   credentials, if given. `allowed_origins` has no setup-time equivalent — a
   browser driving the installer is, by definition, unauthenticated CORS
   traffic *before* that list exists to permit it — so set it afterward with
   `Config::set()`. Everything else (`epp.server`, DNSSEC, …) can be left at
   its default or adjusted later the same way.

   One setting is maintained by the software rather than by you:
   `epp.lastPasswordUpdate`, a unix timestamp recording when an automated
   registry-password rotation was last attempted (see "Registry password
   rotation" below). Leave it at `0` on a fresh install.

If you're upgrading an existing 6.x deployment from its `config.xml`
instead of starting fresh, `bin/eppitnic config migrate` does both steps for
you (it reads `config.xml` from the repo root by default, or `--file=PATH`).

The schema itself is versioned: the `settings` table carries a
`schema_version` row, zero-padded `MMmmrr` (major/minor/release, e.g.
`070000` for 7.0.0), and `Config` (`src/Config.php`) checks it against
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

After you have set everything up, run `bin/eppitnic` to see what it can do,
and `docs/COOKBOOK.md` for using the library directly from PHP.


# Upgrading from 6.x

**Back up the database first.** The migration drops two things for good and
rebuilds every text column; re-running it will not undo either. Rehearse on a
staging copy.

### Before you migrate: export what you want to keep

Both are destroyed by the migration and are only recoverable from a backup:

- the **`accounting` table**, dropped in full — invoicing has left this
  codebase and will be reimplemented elsewhere
- **`users.billing_id`**, dropped — export it if you need the
  user-to-billing-account mapping

### What you need to do

1. `composer install` — dependencies are no longer vendored, and PHP 8.1+ is
   required.
2. `bin/eppitnic config migrate` — converts `config.xml` into
   `config/config.php` (from the same file's own database credentials) plus
   the `settings` table. Afterwards `config.xml` is read by nothing and can
   be archived. (Use this rather than `eppitnic setup`: that command applies
   `config/mariadb-schema.sql` fresh, which is for a brand-new install, not
   a 6.x database being migrated in place.)
3. Reset every user password. 6.x stored MD5; 7.0 uses `password_hash()`, and
   the old hashes cannot be converted, so no existing login works until it is
   reset (see "User setup").
4. Point your client at the new REST API. The PHP/Smarty/jQuery web interface
   is gone, replaced by JSON/REST (`public/`, documented in `docs/API.md`) with
   JWT bearer tokens instead of PHP sessions.

### What happens automatically

The schema migrates itself on the next initialization: `Config` compares the
`settings` table's `schema_version` against `SCHEMA_VERSION` and applies the
`config/mariadb-schema-upgrade-*.sql` steps in order. For 6.x that means
dropping the `tbl_` prefixes and converting everything to `utf8mb4`.

It runs read-only pre-flight checks first and aborts without touching anything
if it finds a problem — in practice, domain names that differ only in case and
would collide under the new collation. Resolve those and re-run.

It also decodes the HTML entities 6.x stored in text columns: values used to be
escaped on the way in, so an organisation named `Rossi & Figli` was held as
`Rossi &amp; Figli` and read back that way everywhere.

`handleID` (MyISAM, utf8mb3) is deliberately left alone; it belongs to no
schema still in use. Drop it yourself once you have confirmed you do not need it.

### Breaking changes to check your code against

- The audit-trail table is `history`, not `changelog`, and records more than
  changes: a `security`/`read` row notes events that alter nothing, such as an
  admin retrieving the registry credential. The endpoint is
  `GET /v1/history/{object}/{object_id}`, and its `security` rows are
  admin-only.

- `Domain->get('tech')` always returns an array now (keyed handle => handle).
  It used to return a bare string for a single technical contact, so
  `array_keys((array) $domain->get('tech'))` gave `[0]` instead of the handle.
  Drop any branch that special-cased the string.
- Domain routes scope non-admins by `domains.user_id`, pending transfers by
  `transfers.user_id`, and a domain's registrant must be a contact the caller
  owns. Pre-existing data where those disagree is reported by
  `bin/eppitnic doctor ownership` (see "Ownership coherence").
- `Domain->check()` / `Contact->check()` return a `CheckResult` instead of
  `array|bool|int`. Replace `=== true` with `->available()`, and the `-1`/`-2`
  sentinels with `->answered()`. `->all()` gives every answer keyed by name;
  both classes now use the same shape, where `Contact` used to return bare
  booleans.
- `Client->sendRequest()` returns an `HttpResponse` instead of an array, so
  `$object->result` is `?HttpResponse`: `$result['code']` becomes
  `$result?->code`.
- `Net_EPP_StorageDB` / `Net_EPP_StorageInterface` are gone; persistence uses
  RedBeanPHP's `R::` facade directly. Custom storage backends need rewriting.
- WSDL support is gone.

### Afterwards

Messages stored before this release may carry `type = 'unknown'` and an empty
`domain`, mostly DNS validation failures. New messages are parsed correctly;
for the old rows:

```
bin/eppitnic doctor reparse-messages --dry-run   # report what would change
bin/eppitnic doctor reparse-messages             # rewrite type/domain
```

It rewrites those two columns and nothing else — no reminder rows, no DNS-sync
events for failures that are years old. Nothing depends on it.

6.x also stored EPP bodies wrapped as `__SERIALIZED:` + base64(serialize(…)),
in `transactions`.`cl_trdata`, `responses`.`sv_httpdata` / `sv_httpheaders` /
`extvaluereason` and `msgqueue`.`sv_httpdata` / `sv_httpheaders`. That envelope
is deprecated: nothing writes it, and the columns carry a comment saying so.
Reads handle either shape, so this is optional cleanup:

```
bin/eppitnic doctor normalize-payloads --dry-run   # count the enveloped rows
bin/eppitnic doctor normalize-payloads             # rewrite them as plain bodies
```

One way — the envelope is not recoverable afterwards, and rows that cannot be
decoded are reported and left alone.


# Web server

`bin/eppitnic` needs nothing beyond PHP. The REST API (documented in
`docs/API.md`) additionally needs a web server, configured two ways:

1. **The document root must be `public/`, and only `public/`.** Everything
   else in the checkout has to stay outside the served tree —
   `config/config.php` holds the database credentials, and `bin/`, `src/`
   and `vendor/` have no reason to be reachable over HTTP.
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
isn't in place — and so does *"The API is not reachable at this address"* on the
setup page, which is the first thing you will see if the document root is right
but the routing is not.

`public/.htaccess` does the same job for hosts that allow per-directory
overrides, so a plain `DocumentRoot .../public` works without it. Configuring it
in the vhost is still better: `AllowOverride None` spares Apache a `stat()` for
that file on every request, and where overrides are off the file is ignored.

For local development you can skip all of this — PHP's built-in server
already routes everything to one script:

```
php -S localhost:8080 -t public public/index.php
```

Errors are returned as JSON with the matching status code. Set
`EPPITNIC_DEBUG=true` in the web server's environment to add the exception
class, file, line and stack trace to error responses, and to have 500s carry
their real message instead of a generic one. **Leave it unset in production**
— it exposes server internals to anyone who can trigger an error, which
includes anyone who can send an unauthenticated request. Errors are written to
the PHP error log either way.


# User setup

Every route that creates a user (`POST /v1/users`) requires an admin token to
call it, so the very first admin account is created by `eppitnic setup` (or
the installer page) instead, as part of first-run setup — see Installation
above. From then on, `bin/eppitnic user create` is how you create any
*further* account directly against the database:

```
bin/eppitnic user create admin2 --password='a-strong-password' --admin
```

The same script can also issue a fixed, non-expiring (or time-limited) API
token for scripted/headless access, as an alternative to logging in for a
short-lived JWT:

```
bin/eppitnic user token admin
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
bin/eppitnic doctor ownership
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


# Scheduled jobs

Two things have to happen on a schedule rather than when someone opens the API:

```
0-59/5  * * * *  /path/to/bin/eppitnic poll process >> /var/log/eppitnic/poll-queue.log 2>&1
0-59/15 * * * *  /path/to/bin/eppitnic pdns sync   >> /var/log/eppitnic/pdns-sync.log 2>&1
```

`poll process` drains the registry's message queue into `messages`, reconciles
domain transfer state against what it found, then rotates the registry password
if a reminder asked for it. The three run in that order for a reason — see
"Registry password rotation" — so they are one verb rather than three crontab
lines. `--no-rotate` and `--no-transfers` drop a step. It does not prompt: it
is the scheduled job, and there is nobody to ask.

`pdns sync` applies pending DNS-sync events to a PowerDNS server through
`pdnsutil`, which must be on the PATH or named by the `pdnsutil_path` setting.
Only schedule it if PowerDNS is what serves your zones; nothing else depends on
it. Zone deletions wait out a grace period, `--delay-hours` (12 by default),
and `--dry-run` prints the exact `pdnsutil` invocations without running any.

Both are ordinary verbs — run either by hand at any time.


# Login rate limiting

Logins are recorded in `history` as `security` rows — the address, the network,
the username and the request headers, never the password and never the issued
token. `action` is `login` for a success, `denied` for a failure, `read` for a
request the limit turned away. Enough failures from one network and
`POST /v1/users/authenticate` answers `429` until they age out; successes and
blocks do not count toward it.

Two settings control it:

- `login_ratelimit` — `{"max_failures":10,"timespan":900,"ipv4_prefix":24,"ipv6_prefix":48}`.
  Failures are counted per network rather than per address: an IPv6 customer is
  handed an allocation, so a per-address limit would stop nobody. `/48` is the
  usual end-site assignment; narrow it to `/56` or `/64` if blocking a whole
  site would catch too many unrelated users. `max_failures: 0` turns it off.
- `trusted_proxies` — **set this if the API runs behind a reverse proxy**, e.g.
  `["10.0.0.0/8"]`. `X-Forwarded-For` is written by whoever sends the request,
  so it is only believed when the address that actually connected is listed
  here. Leave it empty when there is no proxy.

Getting `trusted_proxies` wrong is visible in opposite ways. Empty behind a
proxy: every request looks like it came from the proxy, so all clients share
one bucket and none of them ever matches `safe_networks`. Populated without a
proxy: nothing changes, because the connecting address will not be in it.

Work through them with `GET /v1/history?object=security&acknowledged=0`, which
also answers how many are outstanding, and `POST /v1/history/{id}/acknowledge` to
mark one as read. Acknowledging records who did it and when — for a security
log, who dismissed an alert matters as much as that somebody did — and changes
nothing about what the entry says happened.

Or straight from the table:

```sql
SELECT timestamp, network, action, JSON_VALUE(data,'$.event'), JSON_VALUE(data,'$.username')
FROM history
WHERE object = 'security' AND acknowledged_time IS NULL
ORDER BY id DESC LIMIT 20;
```


# Registry password rotation

nic.it warns, through the EPP poll queue, that the account password is
approaching expiry. `eppitnic poll process` acts on those `passwdReminder`
messages: when one is outstanding it generates a new password, sets it at the
registry (EPP carries a new password in the `<login>` command, so the rotation
*is* a login), stores it in the `epp` setting, and acknowledges the message.
Schedule that job — without it the reminders accumulate unread until the
credential expires and every EPP call starts failing.

The password is generated by `Eppitnic\Support\PasswordGenerator`: 16 characters, EPP's
ceiling for this credential, drawn with `random_int()` from a mixed set that
leaves out the characters people misread (`l`/`I`, `O`/`0`) and the ones shells
and CSV readers treat specially (`$`, `%`, `!`). All four character classes are
present, so an unpublished complexity rule at the registry cannot refuse it.

At most one rotation is attempted per 24 hours, tracked by
`epp.lastPasswordUpdate`, stamped before the attempt: the registry re-sends its
reminder well before the credential actually expires, so a day's wait costs
nothing.

The candidate password is written to the `epp` setting as `pendingPassword`
before it is sent, and promoted once the registry accepts it
(`Eppitnic\Service\RegistryPasswordChange`). A run interrupted
in between therefore leaves both passwords on disk, and the next run settles it
by asking the registry which one it accepts. To settle it immediately:

```
bin/eppitnic doctor epp-password
```

`GET /v1/session/epp` reports `rotation_pending` for the same purpose. No
password is ever written to the log.

If the registry accepts neither, the candidate is kept and the log says so —
that is an account problem (expired, locked, an unauthorised IP), and the
credential to keep is whichever the registry will take once it is resolved.


# Verifying against the live test registry

The test suite proves that the right XML is generated for a given call, and
that a recorded answer is parsed the way it was parsed last week. Neither says
whether the registry still accepts that XML. `selftest` does: it registers,
reads back, changes and deletes real contacts and a real domain at the public
test registry, checking each answer against what was sent.

There are two ways to run it, and they prove different amounts.

**Quick, needs nothing prepared:**

```
bin/eppitnic selftest run
```

**Full, needs a zone you control** — this is the one that also proves the
nameserver round-trip, see below:

```
bin/eppitnic selftest run --yes \
    --domain=my-selftest-1.it \
    --ns=ns1.yourdns.it:ns2.yourdns.it:ns3.yourdns.it
```

Either way it prints one line per operation and nothing else unless something
went wrong:

```
endpoint  https://epp.pubtest.nic.it
run       TJCF9M

Contact lifecycle
  + contact check                  STTJCF9MD1           0.31s  free
  + contact create                 STTJCF9MD1           0.44s  authinfo K7#mQx2_pLdR9wZt
  + contact info                   STTJCF9MD1           0.29s  fields match what was sent
  + contact update                 STTJCF9MD1           0.51s  name, city, voice
  + contact delete                 STTJCF9MD1           0.33s  accepted
  ...

Domain lifecycle
  + domain create                  st-tjcf9m-1.it       0.62s  authinfo 3Rp_xW8@qN4zVbLm, ns ns1.example.it ns2.example.it
  + domain set-registrant          st-tjcf9m-1.it       0.58s  registrant is now STTJCF9MR2, authinfo rotated
  + domain delete                  st-tjcf9m-1.it       0.41s  accepted; the registry now holds it in pendingDelete
  + contact delete                 STTJCF9MR1           0.28s  deleted -- it was already free
  ~ contact delete                 STTJCF9MA1           0.23s  EPP code '2305': Object association prohibits operation (still linked to the deleted domain; `selftest reap` clears it once the domain is purged)
  ...

29 steps: 26 ok, 0 failed, 3 deferred, 0 skipped, in 12.1s
```

Abridged above. A full quick run is **29 steps** — eight for the contact
lifecycle, twenty-one for the domain — of which three are deferred: the
registrant, admin and tech the domain was carrying when it was deleted. The
three the run had already swapped off it are deleted on the spot. With
`--domain` it is **31 steps**, the two extra being the verification pauses.

`--verbose` turns on the library's own diagnostics: the full request and
response behind every failure, and a row in `transactions` and `responses` for
every command. `--json` and `--jsonl` emit one object per step; the verdict is
the exit code — `0` all well, `51` something failed, `50` it refused to start.

## Nameservers, and what a quick run does not prove

By default the domain is registered under `example.it` nameservers, which is
reserved for documentation and answers nothing. nic.it does not report a
nameserver until its own checks say the delegation resolves, so those come back
empty — which the run treats as expected and notes rather than failing:

```
  + domain info    st-tjcf9m-1.it   0.20s  registrant, admin and tech match; nameservers not reported (the registry has not validated the delegation)
```

So **a green quick run does not prove the nameserver round-trip.** Proving it
needs nameservers that really serve the zone, and the registry checks that they
answer authoritatively *for the domain being registered* — so the name has to
be known in advance, which is what `--domain` is for.

What to prepare:

- a second-level `.it` name you are willing to register and delete, e.g.
  `my-selftest-1.it`. It is what actually gets registered, so not a hostname
  beneath one — `Validate::isDomain()` will refuse that.
- a zone for it on the first two nameservers, answering authoritatively.
- a third nameserver, which is only the swap target for the update and **need
  not exist**. The update's job is to prove the change reaches the registry,
  not that the result resolves.

The run then pauses ten seconds after each delegation change, so the registry's
out-of-band checks can finish before it reads back. That pause happens only
with `--domain` — without a real delegation there is nothing to verify and
nothing to wait for. It is announced up front and appears as its own step:

```
domain    my-selftest-1.it (as given)
Note: pausing 10s after each delegation change, so the registry's checks can
      finish. Without that it reports no nameservers at all.
...
  + await dns verification    my-selftest-1.it   10.00s  gave the registry 10s to check the delegation before reading it back
...
31 steps: 28 ok, 0 failed, 3 deferred, 0 skipped, in 27.2s
```

One consequence: a named domain cannot be reused until the previous
registration has been purged, which takes 30 days. Until then `domain check`
refuses it as taken — correctly, and reported as a failure. Keep a small pool
of names (`my-selftest-1.it`, `-2`, …) if you intend to run this regularly.

**It will not run against production.** The check is an allowlist of the
registry's test endpoints, made before a session is opened, and there is no
flag to defeat it — see `Eppitnic\Selftest\Guard`. Point the `epp` setting at
`https://epp.pubtest.nic.it` to use it. A deployment that has never been
reconfigured is a production deployment, and gets exit code `50`.

## Deferred items, and reaping them

A run can only partly clean up after itself, and which part depends on how far
it got. nic.it keeps a contact linked to a domain until that domain has been
purged — 30 days after its delete was accepted, through redemptionPeriod and
then pendingDelete — so the contacts that were *on the domain when it was
deleted* cannot be removed on the day.
Those attempts are reported as `~ deferred`, not as failures: the refusal is
the registry being right, and a self-test that ended red on every complete run
is one nobody would read.

Nothing else waits. A leftover domain, a contact the run swapped off the domain
before deleting it, and every contact created by a run that failed before it
got as far as a domain are all free immediately.

What is left over is written to `var/selftest/<run>.json`, and cleared later:

```
bin/eppitnic selftest reap --list      # what is outstanding
bin/eppitnic selftest reap             # delete what is ready
```

`reap` deletes everything that is not held, on sight. `--min-age` governs only
the held contacts and is counted from **their domain's deletion**, not from the
run — a leftover domain that only a later reap deletes starts its contacts'
clock then. `--min-age=0` attempts them regardless. Anything the registry still
refuses stays on file for next time.

The two "nothing to do" answers are different, and the message says which:

```
nothing to reap
nothing to reap yet: 3 contact(s) are held by a domain the registry has not
purged; try again in 6 day(s), or --min-age=0 to attempt them now
```

Every name a run creates carries the same base-36 timestamp — `STTJCF9MR1`,
`st-tjcf9m-1.it`. That is what keeps a run from colliding with the leftovers of
an earlier one, and what lets `reap` tell which run made an object from its
name alone.


## Transfers

A transfer-in cannot be part of the lifecycle: it needs a domain somebody else
holds and the authinfo code its current registrar issued, neither of which this
can make for itself. It is a separate operation:

```
bin/eppitnic selftest run --transfer=example.it --authinfo=CODE
```

It confirms the name is registered, requests the transfer, and reads the
transfer status back. It does not clean up after itself either — a requested
transfer is withdrawn with `eppitnic domain transfer cancel`, not by deleting
anything.


# ToDo's

1. Implement a client-daemon with session keep-alive functionality. Btw. this
   is not necessary to pass the accreditation test (simply don't log out), but
   would be rather important if the library was to be used by registrars with
   a very high registration rate.
