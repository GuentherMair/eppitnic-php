# Upgrading from 6.x

**Back up the database first.** The migration drops two things for good and
rebuilds every text column; re-running won't undo either. Rehearse on a
staging copy.

## Before you migrate: export what you want to keep

Destroyed by the migration, recoverable only from a backup:

- the **`accounting` table**, dropped in full — invoicing has left this
  codebase, to be reimplemented elsewhere
- **`users.billing_id`**, dropped — export it if you need the mapping

## What you need to do

1. `composer install` — dependencies are no longer vendored, PHP 8.1+ is
   required.
2. `bin/eppitnic config migrate` — converts `config.xml` into
   `config/config.php` plus the `settings` table. Afterwards `config.xml` is
   unused and can be archived. Use this, not `eppitnic setup` — that applies
   `config/mariadb-schema.sql` fresh, for a brand-new install, not a 6.x
   database being migrated in place.
3. Reset every user password. 6.x stored MD5; 7.0 uses `password_hash()` and
   can't convert the old hashes, so no login works until reset (see "User
   setup" in [INSTALL.md](INSTALL.md)).
4. Point your client at the new REST API. The PHP/Smarty/jQuery web interface
   is gone, replaced by JSON/REST (documented in [API.md](API.md)) with
   JWT bearer tokens instead of PHP sessions.

## What happens automatically

The schema migrates itself on next initialization: `Config` compares
`settings.schema_version` against `SCHEMA_VERSION` and applies the
`config/mariadb-schema-upgrade-*.sql` steps in order — from 6.x that means
dropping `tbl_` prefixes and converting to `utf8mb4`.

Read-only pre-flight checks run first and abort untouched on a problem — in
practice, domain names differing only in case that would collide under the
new collation. Resolve those and re-run.

It also decodes HTML entities 6.x stored in text columns (`Rossi & Figli` was
held as `Rossi &amp; Figli`).

`handleID` (MyISAM, utf8mb3) is left alone deliberately; it belongs to no
schema still in use. Drop it yourself once confirmed unneeded.

## Breaking changes to check your code against

- The audit-trail table is `history`, not `changelog`, and records more than
  changes: a `security`/`secread` row notes non-mutating events like an admin
  retrieving the registry credential. `GET /v1/history/{object}/{object_id}`;
  `security` rows are admin-only.
- `Domain->get('tech')` always returns an array now (handle => handle). It
  used to return a bare string for a single technical contact — drop any
  branch special-casing that.
- Domain routes scope non-admins by `domains.user_id`, pending transfers by
  `transfers.user_id`, and a domain's registrant must be a contact the caller
  owns. Pre-existing disagreement is reported by `bin/eppitnic doctor
  ownership`.
- `Domain->check()` / `Contact->check()` return a `CheckResult`, not
  `array|bool|int`. Replace `=== true` with `->available()`, `-1`/`-2`
  sentinels with `->answered()`. `->all()` gives every answer keyed by name.
- `Client->sendRequest()` returns an `HttpResponse`, so `$object->result` is
  `?HttpResponse`: `$result['code']` becomes `$result?->code`.
- `Net_EPP_StorageDB` / `Net_EPP_StorageInterface` are gone; persistence uses
  RedBeanPHP's `R::` facade directly. Custom storage backends need rewriting.
- WSDL support is gone.

## Afterwards

Messages stored before this release may carry `type = 'unknown'` and an empty
`domain` (mostly DNS validation failures). New messages parse correctly; for
old rows:

```
bin/eppitnic doctor reparse-messages --dry-run   # report what would change
bin/eppitnic doctor reparse-messages             # rewrite type/domain
```

Rewrites only those two columns — optional, nothing depends on it.

6.x also wrapped EPP bodies as `__SERIALIZED:` + base64(serialize(…)) in
`transactions.cl_trdata`, `responses.sv_httpdata`/`sv_httpheaders`/
`extvaluereason`, and `msgqueue.sv_httpdata`/`sv_httpheaders`. Deprecated —
nothing writes it, reads handle either shape, so this is optional cleanup:

```
bin/eppitnic doctor normalize-payloads --dry-run   # count enveloped rows
bin/eppitnic doctor normalize-payloads             # rewrite as plain bodies
```

One-way — the envelope isn't recoverable afterwards; rows that can't be
decoded are reported and left alone.

## Next steps

Go back to [INSTALL.md](INSTALL.md) to complete setup and configuration.
