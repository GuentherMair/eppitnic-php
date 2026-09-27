# Testing

## Setup

```
composer install
```

That's enough for almost the whole suite: it runs on `Config::loadForTesting()`
and a fake transport, with no MariaDB and no network.

A few tests (`Setup\InstallerTest`, `Setup\SchemaInstallerTest`) additionally
drive a real, disposable database — `mysql:host=localhost` as the current
shell user, no password (i.e. local socket auth), with privileges to create
and drop a database. Without one reachable, those tests skip themselves
rather than fail.

Some tests also open an in-memory SQLite database (`ext-pdo_sqlite`, bundled
with PHP by default) to exercise real `Config::set()` writes.

A handful of `Wire\ResponseParsingTest` cases always skip: they need captured
registry fixtures from `tests/capture-responses.php`, a maintainer-only tool
that anonymises real registry responses out of a populated installation's
database. Not something to set up for ordinary test runs.

## Running

```
vendor/bin/phpunit
```

(equivalently `composer test`). Narrow it the usual PHPUnit ways, e.g.
`vendor/bin/phpunit tests/Cli` or `--filter SomeTestName`.

## Test against the live test registry

The test suite proves the right XML is generated and that recorded answers
parse; `selftest` proves the registry still accepts that XML. It registers,
reads back, changes and deletes real contacts and a real domain at the
public test registry.

Point the installation at the test registry first, with a test account in the
`epp` setting (see "Switch between the test and production registry" in
[INSTALL.md](INSTALL.md)):

```bash
bin/eppitnic config epp-server test
```

A quick run needs nothing else prepared:

```bash
bin/eppitnic selftest run
```

A full run also proves the nameserver round-trip, and needs a zone you
control:

```bash
bin/eppitnic selftest run --yes \
    --domain=<SELFTEST_DOMAIN> \
    --ns=<NS1>:<NS2>:<NS3>
```

`<SELFTEST_DOMAIN>` is a second-level `.it` name you are willing to register
and delete, e.g. `my-selftest-1.it`. `<NS1>` and `<NS2>` must answer
authoritatively for it; `<NS3>` is only the swap target of the update and
need not exist.

Either prints one line per operation, and nothing else unless something
fails:

```text
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

A quick run is 29 steps (8 contact lifecycle, 21 domain), 3 of them deferred:
the registrant, admin and tech contact the domain carried when deleted. With
`--domain` it is 31 steps, adding two verification pauses.

`--verbose` adds the full request and response on failure and records every
command in `transactions`/`responses`. `--json`/`--jsonl` emit one object per
step. The exit code is the verdict: `0` all well, `51` something failed, `50`
refused to start.

**Nameservers.** By default the domain uses `example.it`'s reserved
nameservers, which answer nothing. nic.it doesn't report a nameserver until
delegation validates, so a quick run's nameservers come back empty; that is
expected. A green quick run therefore does not prove the nameserver
round-trip — only a `--domain` run does. It pauses 10 seconds after each
delegation change so the registry's checks can finish, shown as its own step.
A used name can't be reused until it is purged 30 days later, so keep a small
pool (`-1`, `-2`, …) for repeated runs.

**Production is unreachable by design.** Before a session opens, the endpoint
is checked against an allowlist of test endpoints, with no override flag (see
`Eppitnic\Selftest\Guard`). An unconfigured deployment gets exit code `50`.

### Reap deferred selftest leftovers

nic.it keeps a contact linked to a domain until that domain is purged, 30
days after the delete (through redemptionPeriod, then pendingDelete). So the
contacts that were *on the domain when it was deleted* can't be removed the
same day; those attempts report `~ deferred`, not failed. Everything else —
a leftover domain, a contact swapped off before the deletion, contacts from a
run that failed before creating a domain — is deleted on sight.

Leftovers are written to `var/selftest/<RUN>.json`:

```bash
bin/eppitnic selftest reap --list      # what is outstanding
bin/eppitnic selftest reap             # delete what is ready
```

`reap` deletes everything not held by a domain immediately. `--min-age`
applies only to held contacts and counts from **their domain's deletion**,
not from the run; `--min-age=0` attempts them anyway. Anything the registry
still refuses stays on file for next time:

```text
nothing to reap
nothing to reap yet: 3 contact(s) are held by a domain the registry has not
purged; try again in 6 day(s), or --min-age=0 to attempt them now
```

Every name a run creates shares one base-36 timestamp (`STTJCF9MR1`,
`st-tjcf9m-1.it`). That keeps runs from colliding and lets `reap` attribute an
object to its run by name alone.

### Test a transfer-in

A transfer-in needs a domain someone else holds and the authinfo its current
registrar issued, so it is a separate run:

```bash
bin/eppitnic selftest run --transfer=<DOMAIN> --authinfo=<AUTHINFO>
```

It confirms the name is registered, requests the transfer and reads the
status back. It doesn't clean up after itself: withdraw the request with
`bin/eppitnic domain transfer cancel <DOMAIN>`.
