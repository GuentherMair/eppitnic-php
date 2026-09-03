# Installation

## Dependencies

Run `composer install` to fetch dependencies into `vendor/` and generate the
autoloader — `bin/eppitnic` needs only that one `require`.

A database is required, both for persisting registry communication and, as of
this version, for all configuration except the DB credentials themselves.
`config/config.php` is created either by `eppitnic setup` (interactive, or
`--db-name=`, `--admin-username=` etc. for scripted installs) or by visiting
the app's URL with no `config/config.php` present, which serves a bundled
installer (`public/setup.html`) over `POST /v1/setup`. Either applies
`config/mariadb-schema.sql` and creates the first admin account — there's no
separate schema step. To do it by hand: copy `config/config.php-template` to
`config/config.php`, apply `config/mariadb-schema.sql` yourself, then
`bin/eppitnic user create --admin`.

## Configuration

Split in two:

1. `config/config.php` — database credentials only. Its presence is what
   `eppitnic setup`/the installer key off: once it exists, both are
   unreachable (`eppitnic setup` refuses to run again, setup routes 404).
2. Everything else lives in the `settings` table, pre-populated with
   placeholders by `config/mariadb-schema.sql`. A few settings without a real
   default get filled in: `jwt_psk` auto-generates on first connection;
   `eppitnic setup`/the installer fills in the EPP `username`/`password`/
   `cl_trid_prefix` if given. `allowed_origins` has no setup-time equivalent
   (the installer runs before it exists) — set it afterward with
   `Config::set()`. Everything else can be left at its default. Leave
   `epp.lastPasswordUpdate` at `0` on a fresh install (see "Registry password
   rotation").

The schema is versioned: `settings.schema_version` (`MMmmrr`, e.g. `070000`)
is checked against `SCHEMA_VERSION` (`config/constants.php`) on every
initialization, auto-applying `config/mariadb-schema-upgrade-{from}-to-{to}.sql`
files in sequence — starting from baseline `060700` if the `settings` table
doesn't exist yet at all. To add a future migration: drop a new
`config/mariadb-schema-upgrade-{current}-to-{next}.sql` file and bump
`SCHEMA_VERSION`; nothing else changes.

To see what's currently configured:

```
bin/eppitnic config show          # every setting
bin/eppitnic config show epp      # just one
```

Secrets (`jwt_psk`, the registry password) are never printed — `epp` reports
`password_set`/`rotation_pending` instead, the same allow-list
`GET /v1/session/epp` uses.

To change the `epp` setting's plain fields (`server`/`server_deleted`/`port`
have their own verb, see "Verifying against the live test registry" below):

```
bin/eppitnic config epp-set interface 203.0.113.5   # IPv4 only
bin/eppitnic config epp-set lang it                 # 'it' or 'en'
bin/eppitnic config epp-set cl_trid_prefix MYPREFIX  # 1-47 chars, no whitespace
bin/eppitnic config epp-set username MYCOMPANY-REG   # 3-16 chars, ending '-REG'
```

`password` is deliberately not one of these — a value this installation and
the registry disagree about breaks every EPP call, so it goes through the
registry rather than a plain local write:

```
bin/eppitnic config epp-password NEW-PASSWORD           # changes it at the registry, then stores it
bin/eppitnic config epp-password --force LIVE-PASSWORD  # verifies it against the registry, then adopts
                                                          # it as-is -- no registry change is sent
```

Both are 6 to 16 characters (EPP `pwType`), verified with a real login before
anything is written — a refused password changes nothing locally either.
`lastPasswordUpdate`, `password_set` and `rotation_pending` (see `config
show epp`) update the same way `poll process`'s automatic rotation and
`doctor epp-password` already do, because this goes through the same
`RegistryPasswordChange` machinery.

Once set up, run `bin/eppitnic` to see what it can do, and see
[COOKBOOK.md](COOKBOOK.md) for using the library directly from PHP.

## Web server

`bin/eppitnic` needs nothing beyond PHP. The REST API
([API.md](API.md)) additionally needs a web server configured so that:

1. **The document root is `public/`, and only `public/`.** `config/config.php`
   holds DB credentials, and `bin/`, `src/`, `vendor/` have no reason to be
   reachable over HTTP.
2. **Anything that isn't a real file routes to `public/index.php`.** Slim is a
   front controller — `/v1/domains` exists only as a route inside
   `index.php`. Without this the API looks entirely dead (404 before PHP runs).

Ready-made configs: `config/apache-vhost.sample`, `config/nginx-vhost.sample`.
Copy, adjust host/paths/certs, enable. (Running eppitnic via Docker instead?
Use `config/apache-proxy.sample`/`config/nginx-proxy.sample` here in front of
it — see [DOCKER.md](DOCKER.md).) Verify with:

```
curl -i https://epp.example.com/v1/network-check
```

That must come back as JSON (public, no token needed). An HTML 404 means the
front-controller routing isn't in place — same cause as *"The API is not
reachable at this address"* on the setup page.

`public/.htaccess` covers hosts that allow per-directory overrides, but
configure the vhost properly anyway: `AllowOverride None` spares Apache a
`stat()` per request.

For local development, PHP's built-in server routes everything already:

```
php -S localhost:8080 -t public public/index.php
```

Set `EPPITNIC_DEBUG=true` in the web server's environment to add exception
class/file/line/trace to error responses and give 500s their real message.
**Leave it unset in production** — it exposes internals to anyone who can
trigger an error, including unauthenticated requests. Errors always go to the
PHP error log regardless.

## User setup

`POST /v1/users` requires an admin token, so the first admin account is
created by `eppitnic setup`/the installer instead. Further accounts:

```
bin/eppitnic user create admin2 --password='a-strong-password' --admin
```

The same script issues a fixed API token for scripted/headless access:

```
bin/eppitnic user token admin
```

`-x <days>` sets validity from now (e.g. `-x 365`). `-x 0` (default) never
expires — prints a `WARNING`, since there's no automatic rotation. Prefer a
finite `-x` unless a non-expiring credential is genuinely wanted.

## Ownership coherence

`domains.user_id` and `contacts.user_id` (the registrant's owner) are expected
to agree from 7.0.0 on — every domain route scopes non-admins by the domain's
owner, and the API refuses to set a registrant the caller doesn't own. Legacy
6.x data never enforced this, so an upgraded database can disagree; such a
domain is editable by its owner but attributed to somebody else, and can't be
fixed by re-saving it.

```
bin/eppitnic doctor ownership
```

Lists mismatches and pending transfers that would create one, changes
nothing. Exit `0` coherent, `6` mismatches found (cron-friendly); `-q`
suppresses output and keeps the exit code.

## Scheduled jobs

```
0-59/5  * * * *  /path/to/bin/eppitnic poll process >> /var/log/eppitnic/poll-queue.log 2>&1
0-59/15 * * * *  /path/to/bin/eppitnic pdns sync   >> /var/log/eppitnic/pdns-sync.log 2>&1
* * * * *        /path/to/bin/eppitnic session keepalive >> /var/log/eppitnic/keepalive.log 2>&1
```

`poll process` drains the registry's message queue into `messages`,
reconciles domain transfer state, then rotates the registry password if a
reminder asked for it — in that order, hence one verb, not three. `--no-rotate`
and `--no-transfers` drop a step. It never prompts.

`pdns sync` applies pending DNS-sync events to PowerDNS via `pdnsutil` (on
`PATH` or named by the `pdnsutil_path` setting). Only schedule it if PowerDNS
serves your zones. Zone deletions wait out `--delay-hours` (12 by default);
`--dry-run` prints the `pdnsutil` invocations without running them.

`session keepalive` only does anything when `keepalive` is on (see "Session
keep-alive" below) — schedule it unconditionally, since it prints nothing and
exits `0` while the setting is off.

All three are ordinary verbs, runnable by hand any time.

## Session keep-alive

By default every EPP operation is connect-per-request: hello, login, the
operation, logout. Turning `keepalive` on holds one authenticated session open
across processes instead, reusing it while it's fresh and refreshing it from
cron before nic.it's own idle timeout:

```
bin/eppitnic config keepalive on
bin/eppitnic config keepalive off
```

With it on, `session keepalive` (scheduled above) sends `hello` once the
session is more than 230 seconds old — nic.it documents `hello` for exactly
this, "to keep the session active and prevent the client from being
disconnected due to timeout" — comfortably inside the 300 second limit, so one
missed run is still survivable. If the registry drops the session anyway (a
restart, maintenance), the next real operation notices, logs in again once,
and replays that one command — nothing bulk ever replays.

Turning it off closes the open session (logging out, if the registry is
reachable) before flipping the setting, so nothing is left idling.

A server override — `domain restore`'s `-deleted` endpoint — and the registry
password rotation machinery never join the shared session regardless of this
setting: a different host, or a login `poll process` deliberately expects to
fail, must never touch what other requests share.

## Session serialization

Whether nic.it tolerates overlapping commands on one shared session is
unconfirmed, so locking them against each other is opt-in and off by
default — only meaningful with `keepalive` on:

```
bin/eppitnic config session-serialize on
bin/eppitnic config session-serialize off
```

With both on, every command takes a MariaDB advisory lock (`GET_LOCK`,
scoped to this installation's database) before it runs. A lock not obtained
within 10s, or a database with no `GET_LOCK` at all, is treated the same:
the command proceeds unlocked rather than blocking forever.

## Login rate limiting

Logins are recorded in `history` as `security` rows (address, network,
username, headers — never password or token). `action` is `login`, `denied`,
or `read` (a request the limit turned away). Enough failures from one network
and `POST /v1/users/authenticate` answers `429` until they age out.

Two settings:

- `login_ratelimit` — `{"max_failures":10,"timespan":900,"ipv4_prefix":24,"ipv6_prefix":48}`.
  Counted per network, not per address (an IPv6 customer gets an allocation, so
  per-address wouldn't stop anyone). `max_failures: 0` disables it.
- `trusted_proxies` — **set this if the API runs behind a reverse proxy**, e.g.
  `["10.0.0.0/8"]`. `X-Forwarded-For` is only trusted when the connecting
  address is listed here.

Getting `trusted_proxies` wrong fails in opposite ways: empty behind a proxy
means every request looks like it came from the proxy (one shared bucket);
populated without a proxy means it's simply never matched.

Review with `GET /v1/history?object=security&acknowledged=0` and
`POST /v1/history/{id}/acknowledge`, or directly:

```sql
SELECT timestamp, network, action, JSON_VALUE(data,'$.event'), JSON_VALUE(data,'$.username')
FROM history
WHERE object = 'security' AND acknowledged_time IS NULL
ORDER BY id DESC LIMIT 20;
```

## Registry password rotation

nic.it warns via the EPP poll queue that the account password is nearing
expiry. `eppitnic poll process` acts on `passwdReminder` messages: generates a
new password, sets it at the registry (a login with the new password),
stores it in the `epp` setting, acknowledges the message. Schedule the job —
without it, reminders accumulate unread until the credential expires and
every EPP call starts failing.

Passwords come from `Eppitnic\Support\PasswordGenerator`: 16 characters
(EPP's ceiling), `random_int()`, excludes easily-misread characters
(`l`/`I`, `O`/`0`) and shell/CSV-special ones (`$`, `%`, `!`); all four
character classes are always present.

At most one rotation per 24 hours, tracked by `epp.lastPasswordUpdate`
(stamped before the attempt — the registry re-sends its reminder well before
actual expiry).

The candidate is written to the `epp` setting as `pendingPassword` before
sending, promoted once accepted. An interrupted run leaves both on disk; the
next run settles it by asking the registry which one it accepts. To settle
immediately:

```
bin/eppitnic doctor epp-password
```

`GET /v1/session/epp` reports `rotation_pending`. No password is ever logged.
If the registry accepts neither, the candidate is kept and logged — that's an
account problem (expired, locked, unauthorised IP) to resolve at the registry.

## Verifying against the live test registry

The test suite proves the right XML is generated and that a recorded answer
parses correctly — not that the registry still accepts that XML. `selftest`
does: it registers, reads back, changes and deletes real contacts and a real
domain at the public test registry.

**Quick, needs nothing prepared:**

```
bin/eppitnic selftest run
```

**Full, needs a zone you control** — also proves the nameserver round-trip:

```
bin/eppitnic selftest run --yes \
    --domain=my-selftest-1.it \
    --ns=ns1.yourdns.it:ns2.yourdns.it:ns3.yourdns.it
```

Either prints one line per operation, nothing else unless something fails:

```
endpoint  https://epp.pubtest.nic.it
run       TJCF9M

Contact lifecycle
  + contact create                 STTJCF9MD1           0.44s  authinfo K7#mQx2_pLdR9wZt
  ...
Domain lifecycle
  + domain create                  st-tjcf9m-1.it       0.62s  authinfo 3Rp_xW8@qN4zVbLm, ns ns1.example.it ns2.example.it
  ~ contact delete                 STTJCF9MA1           0.23s  EPP code '2305': still linked to the deleted domain (cleared by `selftest reap` once purged)
  ...
29 steps: 26 ok, 0 failed, 3 deferred, 0 skipped, in 12.1s
```

A quick run is **29 steps** (8 contact lifecycle, 21 domain), of which 3 are
deferred — the registrant/admin/tech the domain was carrying when deleted.
With `--domain` it's **31 steps** (two extra verification pauses).

`--verbose` adds full request/response detail on failure and logs every
command to `transactions`/`responses`. `--json`/`--jsonl` emit one object per
step. Exit code is the verdict: `0` all well, `51` something failed, `50`
refused to start.

**Nameservers:** by default the domain uses `example.it`'s reserved
nameservers, which answer nothing — nic.it doesn't report a nameserver until
delegation validates, so a quick run's nameservers come back empty (expected,
not a failure). **A green quick run does not prove the nameserver
round-trip.** Proving it needs `--domain` with a real, second-level `.it` name
you're willing to register/delete, a zone answering authoritatively on the
first two nameservers, and a third nameserver as the update's swap target
(need not exist — only proves the change reaches the registry). The run then
pauses 10 seconds after each delegation change so the registry's out-of-band
checks can finish, announced up front and shown as its own step. A used name
can't be reused until purged (30 days) — keep a small pool (`-1`, `-2`, …) for
repeated runs.

**Production is unreachable by design.** The check is an allowlist of test
endpoints made before a session opens, with no override flag (see
`Eppitnic\Selftest\Guard`). Point `epp.server` at
`https://epp.pubtest.nic.it`; an unconfigured deployment gets exit code `50`.

```
bin/eppitnic config epp-server              # show the current endpoint
bin/eppitnic config epp-server test         # -> https://epp.pubtest.nic.it
bin/eppitnic config epp-server production   # -> https://epp.nic.it
bin/eppitnic config epp-server toggle       # switch to whichever it isn't
```

A local settings write only — `username`/`password`/`cl_trid_prefix` are left
untouched, since production and the public test registry normally use
separate accounts. `--dry-run` shows the change without writing it; `--yes`
skips the confirmation prompt.

## Deferred items, and reaping them

nic.it keeps a contact linked to a domain until that domain is purged — 30
days after delete is accepted, through redemptionPeriod then pendingDelete —
so contacts that were *on the domain when it was deleted* can't be removed
same-day. Those attempts report `~ deferred`, not failed: the refusal is the
registry being right. Everything else (a leftover domain, a contact swapped
off before deletion, contacts from a run that failed before reaching a
domain) is free immediately.

Leftovers are written to `var/selftest/<run>.json`:

```
bin/eppitnic selftest reap --list      # what is outstanding
bin/eppitnic selftest reap             # delete what is ready
```

`reap` deletes everything not held, on sight. `--min-age` governs only held
contacts and counts from **their domain's deletion**, not the run's stamp;
`--min-age=0` attempts them regardless. Anything the registry still refuses
stays on file for next time:

```
nothing to reap
nothing to reap yet: 3 contact(s) are held by a domain the registry has not
purged; try again in 6 day(s), or --min-age=0 to attempt them now
```

Every name a run creates shares one base-36 timestamp (`STTJCF9MR1`,
`st-tjcf9m-1.it`) — that's what keeps runs from colliding and lets `reap`
attribute an object to its run by name alone.

## Transfers

A transfer-in needs a domain somebody else holds plus the authinfo their
current registrar issued, so it's a separate operation, not part of the
lifecycle:

```
bin/eppitnic selftest run --transfer=example.it --authinfo=CODE
```

Confirms the name is registered, requests the transfer, reads status back.
Doesn't clean up after itself — withdraw a requested transfer with
`eppitnic domain transfer cancel`.
