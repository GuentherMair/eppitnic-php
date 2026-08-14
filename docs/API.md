# eppitnic REST API

Handoff reference for building the frontend client. This documents what is
**actually implemented** in `public/index.php` + `src/Api/Routes/*.php` today, not
the original design (`backend-api-plan.md`/`storagedb-disentangle-plan.md`,
both retired once their work landed — some routes below deviate from that
original plan; this file is the source of truth).

The API is a thin JSON/HTTP adapter (Slim 4) in front of the `Net_EPP_IT_*`
registry classes and a MariaDB local database (via RedBeanPHP's `R::`
facade). It replaces the legacy PHP/Smarty/jQuery web interface entirely.

## Base URL & content type

All routes are prefixed `/v1/` except `GET /` (a bare "Hello, World!" health
check, not JSON). Send `Content-Type: application/json` on request bodies;
responses are `application/json; charset=utf-8` unless noted otherwise
(`GET /v1/domains/export` returns `text/csv`).

**Always send `Accept: application/json`.** Errors that are thrown before a
route handler runs its own logic — auth failures (401/403), 404 on unknown
routes, 405 on wrong methods, uncaught 500s — are produced by Slim's own
error middleware, which content-negotiates off the `Accept` header and
falls back to an HTML error page if it doesn't see `application/json`. See
"Error shapes" below — these responses look different from routes' own
`{"error": "..."}` JSON.

## Authentication

Two independent mechanisms produce a bearer token accepted the same way by
every route: a short-lived login JWT, or a long-lived fixed API token for
scripted/headless access. Send whichever as `Authorization: Bearer <token>`.

### Login flow (JWT)

`POST /v1/users/authenticate` — body `{ "username", "password", "totp"? }`.
No auth required. Looks up `users` by `username` (must be `active = 1`),
verifies `password` with `password_verify()` (bcrypt/argon2 via
`password_hash()` — not MD5). If the account has a TOTP secret configured
**and** the request isn't coming from a `safe_networks` CIDR
(the `settings` table, key `safe_networks`), a valid `totp` code is required in the same request or the
call fails with `needs_totp` semantics (see below) — there is no separate
two-step "submit password, then submit code" exchange; retry the whole call
once you have the code.

**Rate limited.** Before the credentials are examined, the caller's network is
checked against the failures it has already produced. Past
`login_ratelimit.max_failures` within `login_ratelimit.timespan` seconds, the
call answers `429` with a `Retry-After` header and `{"error": ..., "retry_after": n}`.
The window slides, so a block lifts itself as the old failures age out.

Counted per **network**, not per address — IPv4 `/24` and IPv6 `/48` by default
(`ipv4_prefix`, `ipv6_prefix`). An IPv6 customer is handed an allocation rather
than an address, and `/48` is the usual end-site assignment, so anything
narrower leaves room to rotate subnets inside one's own. Narrow it to `/56` or
`/64` where blocking a whole site would catch too many unrelated users.
`max_failures: 0` disables it.

Both outcomes are recorded in `history` as `security` rows carrying the address,
the network and the request headers:

| action | what it means | counted? |
|---|---|---|
| `login` | authentication succeeded | no |
| `denied` | unknown username, wrong password, or wrong TOTP code | **yes** |
| `read` | turned away by the limit, or a credential disclosure | no |

Neither the attempted password nor the issued token is ever recorded. The
response stays `Wrong username or password` whichever half was wrong, so it
cannot be used to discover which usernames exist — the log is precise where the
response is vague.

**Behind a reverse proxy**, list it in the `trusted_proxies` setting.
`X-Forwarded-For` is written by whoever sends the request, so it is only
believed when the address that actually connected is a trusted proxy. Without
that setting every request appears to come from the proxy, which means one
shared rate-limit bucket for all clients and no client ever matching
`safe_networks`.

Success response — `jwtBuild()`'s output, i.e. every field passed in plus a
`token`:
```json
{
  "token": "<JWT>",
  "id": 3,
  "admin": 0,
  "username": "reseller1",
  "has_totp": true,
  "needs_totp": true,
  "totp_verified": true,
  "debug_level": null,
  "max_token_age": null,
  "max_idle_time": null
}
```
- `admin` — `1` means unrestricted (sees/manages every user's data);
  anything else is a scoped reseller account.
- `has_totp` / `needs_totp` — whether the account has MFA configured, and
  whether *this* login required it (false when the request came from a
  safe network). `totp_verified` mirrors `has_totp` on a successful login
  (the code was just checked); it exists because the same claims shape is
  reused for `renew-token`, where it can be false (see MFA gate below).
- `max_token_age` — minutes until expiry, default `240` (4h) if the user
  record doesn't set one; feeds `jwtBuild()`'s `exp` claim. `null`, `0` and
  negative values all mean "use the default" (`0` would otherwise mint an
  already-expired token). `renew-token` re-signs the same claim, so a renewed
  token keeps the user's lifetime rather than reverting to the default.
- `max_idle_time` — **not implemented.** It is stored on the user, accepted by
  `POST`/`PUT /v1/users`, and echoed back in the claims, but nothing anywhere
  enforces an idle timeout: no last-seen timestamp is tracked per token. Treat
  it as a reserved field. A token's only expiry is `max_token_age`.
- Failure: `401` with `{"error": "Wrong username or password"}` /
  `{"error": "MFA code required"}` / `{"error": "Invalid MFA code"}`.

`GET /v1/users/renew-token` (auth: any valid token) — re-issues a fresh JWT
from the current token's own claims (`jwtBuild((array) $decoded->data)`),
same response shape as login. Use this to keep a session alive without
re-prompting for a password; it does **not** re-check TOTP, so a token that
was minted without MFA verification stays that way until re-login.

`GET /v1/users/me` (auth: any valid token) — returns the decoded claims
as-is (no `token` field).

### MFA gate (`jwtRequireMfa`)

Certain sensitive routes require the *current* token to have
`totp_verified` (not just any valid token) or they 403 with `"MFA
verification required"`: `PUT /v1/changepassword/{id}`,
`DELETE /v1/users/{id}/totp`. This matters if a client ever mints a token
for a has-TOTP account without the code (not currently possible through
`authenticate`, but relevant if that changes).

`jwtRequireAdmin` implies the same MFA check, in addition to requiring
`admin === 1`.

### TOTP (MFA) setup, per-user

- `POST /v1/users/{id}/totp` (auth: self or admin) — generates a pending
  secret, returns `{"secret": "...", "uri": "otpauth://..."}` (feed `uri`
  to a QR renderer). Not yet active.
- `PUT /v1/users/{id}/totp` (auth: self or admin) — body `{"totp": "123456"}`,
  verifies against the *pending* secret and, on success, promotes it to
  `totp_secret` (MFA now active). 400 if there's no pending setup, 401 if
  the code doesn't verify.
- `DELETE /v1/users/{id}/totp` (auth: self-with-MFA or admin) — clears both
  `totp_secret` and any pending secret (disables MFA).

### Fixed API tokens (scripted access)

- `POST /v1/users/{id}/api-token` (auth: self or admin) — body
  `{"expires"?: <unix timestamp, 0/omitted = never>}`. Generates a random
  256-bit token, returns it **once** as `{"token": "...", "expires": 0}` —
  only its SHA-256 hash is stored (`users.api_token`), so it cannot be
  recovered later, only reissued (overwrites the previous one).
- `DELETE /v1/users/{id}/api-token` (auth: self or admin) — revokes it.
- Send it exactly like a JWT: `Authorization: Bearer <token>`. It never
  expires unless `expires` was set, never carries `has_totp`/MFA (MFA is
  meaningless for headless callers), and is otherwise indistinguishable to
  route handlers from a JWT-derived identity (`jwtVerify()` synthesizes the
  same claims shape).

### Password change (per-user login password)

`PUT /v1/changepassword/{id}` (auth: self-with-MFA or admin) — body
`{"password": "..."}`. This is the local `users.password`, unrelated to the
shared EPP registry credential (below). Trying to change another user's
password without being an admin is **403** — it answered 401 previously,
which contradicted every other authorization refusal in the API.

## Authorization model

- `admin` claim `=== 1` → unrestricted, sees/manages every user's data, can
  hit `jwtRequireAdmin`-gated routes.
- Everyone else is scoped to their own `user_id` — every list/read/write
  handler that isn't admin-only filters
  its query by the caller's id. There is no per-resource ACL table; it's a
  `WHERE user_id = :id` (or ownership join) added to the query, or a 403 if
  the ownership check fails outright.
- Every domain **write** route checks ownership (`canAccessDomain()`,
  `src/Api/Routes/domain.php`) *before* opening an EPP session, so a rejected call
  never reaches the registry: 403
  `{"error": "You are not authorized to modify domain '...'"}`. A domain
  belongs to exactly one local user — there is no wider attachment rule like
  contacts have. Two deliberate exceptions, both claim-style operations where
  the caller isn't expected to own the domain yet:
  `POST /v1/domains/{name}/transfer` and `.../transfer/cancel` (see their rows
  below).
- A domain's registrant must be a contact the caller **owns**
  (`canUseAsRegistrant()`, `src/Api/Routes/domain.php`): `POST /v1/domains` and
  `POST /v1/domains/{name}/registrant` 403 otherwise. This is stricter than
  the read rule below on purpose — being allowed to *see* a contact because it
  hangs off one of your domains is not grounds for making it the registrant of
  another. It also keeps a domain's owner and its registrant's owner from
  drifting apart, since a registrant change reassigns the domain's local
  ownership to that contact's owner.
- Contacts have a wider access rule than domains
  (`canAccessContact()`, `src/Api/Routes/contact.php`): a reseller may `GET`/`PATCH`
  a contact they don't directly own, as long as it's attached (as
  registrant, admin, or tech) to at least one domain they DO own. Contact
  `DELETE` has no such check — see gotchas.

## Talking to the registry (EPP)

Handlers that need a live round-trip to the .it registry wrap their EPP
calls in `EppSession::run()` (`src/Service/EppSession.php`): connect, `hello()`+
`login()`, run the callback, always `logout()` — one registry session per
HTTP request, nothing pooled. If `hello()`/`login()` fails, the route
returns **502** with `{"error": "EPP session unavailable: ..."}` — this is
distinct from a `400`, which means the registry responded but rejected the
operation (bad input, business-rule violation, etc.). Routes that only
touch the local DB (`GET /v1/domains`, `.../expiring`, `.../autocomplete`,
`.../export`, `.../transfers`, most of `contacts`/`reminders`)
never pay this cost.

The two single-object reads — `GET /v1/domains/{name}` and
`GET /v1/contacts/{handle}` — are the exception to the 502 rule: rather than
failing when the registry is unavailable, they fall back to the local DB and
mark the payload `"stale": true`. Every other EPP-backed route still 502s.

## Error shapes

Two different shapes exist depending on where a request failed:

**Route-level errors** (validation, EPP rejection, not-found inside a
handler that got that far) — flat, always just a string message, no error
code field:
```json
{ "error": "Domain 'example.it' not found" }
```
Status code varies by route (see below); there is **no** stable `code`
enum like the original plan sketched — match on the message text or the
HTTP status if the frontend needs to branch on failure type.

**Framework-level errors** (missing/invalid/expired token → 401; wrong
`admin`/MFA state → 403; unknown route → 404; wrong HTTP method → 405;
uncaught exception → 500) — Slim's `JsonErrorRenderer` (only if
`Accept: application/json` was sent, see above):
```json
{ "message": "Forbidden." }
```
With `displayErrorDetails` on (currently enabled — this is a
pre-production/internal tool), 500s additionally include an `exception`
array with `type`/`code`/`message`/`file`/`line` — **don't surface that to
end users**, and expect it to be locked down before any public launch.

## CORS

Origin must appear verbatim in the `settings` table's `allowed_origins`, checked
with strict string equality against the `Origin` header — no wildcards, no
subdomain matching. Preflight `OPTIONS` gets `204` if allowed, `403` if
not; actual requests from a disallowed origin get `403` with a JSON body
*without* full CORS headers (so the browser will still block it as a CORS
failure, just with an explanatory body visible to non-browser clients).
Allowed headers/methods are also config-driven (`allowed_headers`,
`allowed_methods`).

**Requests with no `Origin` header bypass this check entirely** and are served
normally, with no `Access-Control-Allow-*` headers on the response. CORS is
enforced by browsers on top of an `Origin`; with none present there is nothing
to police. This is the path every non-browser client takes — curl, cron jobs,
and anything using a fixed API token — so `allowed_origins` only ever governs
browsers, and shipping it empty (as both schema seeds now do) locks out browser
clients without touching scripted ones. Note this is a behaviour change: the
seeds previously contained a single empty-string entry, which is what let
origin-less requests through, and configuring a real origin list silently
revoked that.

## Pagination convention

Used by `GET /v1/reminders` (admin-only variant) and any future list-heavy
endpoint that adopts it (not every list route
does — see per-route notes, most domain/contact listings return the full
scoped set unpaginated):
```json
{
  "total": 1234,
  "filteredTotal": 1234,
  "page": 1,
  "pageSize": 25,
  "rows": [ { "...": "..." } ]
}
```
`page` is 1-based. `pageSize` is clamped to `[1, 200]`, default `25`.
`filteredTotal` currently always equals `total` (no separate unfiltered
count is tracked) — don't rely on them ever differing.

---

## Route reference

Auth column: `public` (no token), `user` (any valid token, self-scoped),
`user+mfa` (valid token with `totp_verified`), `admin` (`admin === 1`).

### Session / registry

| Method & path | Auth | Notes |
|---|---|---|
| `GET /` | public | plaintext "Hello, World!", not JSON — liveness check only |
| `GET /v1/network-check` | public | `{"safe_network": bool, "client_ip": "..."}` — used pre-login to decide if the UI should prompt for a TOTP field |
| `GET /v1/session/epp` | admin | the shared EPP registry account this installation uses, `{"epp": {...}}`: `server`, `server_deleted`, `port`, `interface`, `username`, `lang`, `cl_trid_prefix`, `lastPasswordUpdate` (unix timestamp of the last automated password rotation attempt, `0` = never), plus `password_set` (bool) and `rotation_pending` (bool — a password rotation was interrupted; run `eppitnic doctor epp-password`). Local DB only, no registry round-trip. **The password itself is never returned** — the field list is an allow-list, so anything added to the `epp` setting later is withheld until explicitly published |
| `GET /v1/session/credit` | user | live EPP registry account balance, `{"credit": "..."}`; 502 if the registry session fails |
| `GET /v1/session/epp/credentials` | admin | the shared EPP registry credential itself — `{"credentials": {"server", "username", "password"}}`. Separate from `GET /v1/session/epp` on purpose: that one is what a settings screen loads, and a secret delivered as a side effect of rendering a page ends up in caches, proxy logs and screenshots. Exists because the password is rotated automatically — after `eppitnic poll process` acts on a `passwdReminder`, this is the only way short of a SQL client to learn the current one. When a rotation was interrupted the response also carries `pending_password` and a `note`: the registry holds one of the two and only it can say which. `404` when no password is configured. **Every retrieval is recorded** in `history` as a `security`/`read` row: the acting user, the client IP, and the request headers. The password is not written, and `Authorization`, `Cookie` and `Proxy-Authorization` are stored as `[redacted]` — they are themselves credentials, and the log is read by more people than the password was shown to. A refused request records nothing |
| `POST /v1/session/change-password` | admin | rotates the **shared EPP registry** credential (not any user's login password) — records the new password in the `settings` table, then logs into EPP with it to make the change. Body `{"password"?: "..."}` (random if omitted; 16 characters is the EPP maximum). 500/502/400 when the settings write, the registry connection or the registry itself fails. A failure before the registry is reached leaves the current credential untouched; one after it is settled by `eppitnic doctor epp-password` |
| `GET /v1/poll-queue` | admin | raw `messages` table rows, `?active=1\|0` (default `1` = `archived_time IS NULL` only) |
| `GET /v1/poll-queue/{id}` | admin | single message, 404 if missing |
| `POST /v1/poll-queue/{id}/archive` | admin | sets `archived_time`/`archived_user_id` |

### Users (admin-managed accounts)

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/users` | user | **not actually scoped** despite requiring only a valid token — returns every user's `id, active, admin, username, max_token_age, max_idle_time, debug_level, has_totp`. Never returns password hashes |
| `GET /v1/users/{id}` | user | same field set, single row (also unscoped — any logged-in user can look up any other user by id) |
| `POST /v1/users` | admin | create. Required: `username`, `password`. Optional: `description`, `email`, `max_operations` (daily domain-create quota, `0` = unlimited), `active` (default `1`), `admin` (default `0`), `max_token_age`, `max_idle_time`, `debug_level`. `400` if a required field is missing or if `username` is already taken |
| `PUT /v1/users/{id}` | admin | update of the same field set. **Every field is optional** — anything omitted keeps its current value (this includes `password`, as before). `404` if the id doesn't exist, `400` on a `username` collision with another row |
| `DELETE /v1/users/{id}` | admin | soft-delete (`active = 0`) — does **not** block deleting id `1`, unlike the original plan's intent; be careful in the UI |

### Domains

`domainToArray()` response shape used by every single-domain response
below: `{ domain, status, registrant, admin, tech: [handles], ns: [names], authinfo, dnssec, cr_date, ex_date }`. `tech`/`ns` are flattened to plain string arrays (keys of the underlying assoc maps) — no per-NS IP or per-tech metadata comes through this shape.

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/domains` | user | local DB only, no EPP round-trip. Query params: `registrant` (exact match), `active` (`1`\|`0`, default `1`), `age` (months since `ex_date`, filters to older-than). Returns **raw DB rows** `{domain, registrant, user_id}` per entry — not `domainToArray()` — plus any pending transfer-in requests with `" (transfer-in)"` appended to the domain name as a literal string suffix (not a separate field — parse it out if the UI needs to distinguish) |
| `GET /v1/domains/expiring?days=30` | user | local DB, joined with the registrant contact; rows include `handle, org, name, email` alongside the domain columns. Scoped by the **domain's** owner (`domains.user_id`), same as every other domain route |
| `GET /v1/domains/autocomplete?term=&limit=10` | user | domain-name substring search (`LIKE %term%`), includes transfer-in pending domains with the same `" (transfer-in)"` suffix, returns `{"domains": ["a.it", "b.it (transfer-in)", ...]}` |
| `GET /v1/domains/export` | user | **not JSON** — `text/csv` with `Content-Disposition: attachment`, columns `Active;Domain;Auth-Info;Created;Expires;Registrant Handle;Registrant Org;Registrant Name;Registrant Email` |
| `GET /v1/domains/transfers?registrant=` | user | pending local transfer-in requests (the `transfers` table, not registry `pendingTransfer` polling state) — `techc`/`dns` are unserialized back into arrays for the response. Scoped by who **requested** the transfer (`transfers.user_id`), matching what `.../transfer/cancel` authorizes against |
| `GET /v1/domains/{name}` | user | registry-first: live EPP `fetch()`, returned as-is in the `domainToArray()` shape with `"stale": false`. If the registry can't answer — `fetch()` fails **or** the session itself fails — falls back to the local DB row and returns it with `"stale": true`. 404 only when neither source has it (the local fallback is scoped by `user_id`, so a domain you don't own counts as absent). This route no longer returns 502 |
| `POST /v1/domains` | user | create-or-transfer-request in one call: `check()`s the name first, `create()`s if available, otherwise issues a `transfer()` request if it's held elsewhere. Body: `domain*, registrant*, admin?, tech?: [...], ns?: [{name,ip?}...], authinfo?` (`*` = required). The `registrant` must be a contact you own, else 403. **Daily quota enforced** for non-admins via `users.max_operations` vs. today's `history` create-count — `429` with `{"error": "Daily operation quota exceeded"}` when hit. `201` + `domainToArray()` on success |
| `POST /v1/domains/import` | user | body `{"domains": ["a.it", ...]}` — pulls each from the registry into the local DB (idempotent reconciliation, not a create). Response is a per-domain diagnostic object keyed by domain name, each with `domain` and `registrant` (`"found"`/`"not found"`) and `contact_stored` and `domain_stored` (`"stored"`/`"not stored"`). A step that was never reached reads `"skipped"`, so the first non-`skipped` failure is where the import stopped and why — not a simple success flag, and useful for surfacing partial failures in a bulk-import UI |
| `PATCH /v1/domains/{name}` | user | partial update: `admin`, `authinfo` set directly; `ns`, `tech`, `dnssec` are **full-target-list diffs** — send the complete desired array and the server computes add/remove, don't send deltas. `dnssec` entries are `{keytag, algorithm, digesttype, digest}`. Registrant changes are **not** accepted here — see the dedicated endpoint below |
| `POST /v1/domains/{name}/registrant` | user | dedicated registrant-change flow (`Domain::updateRegistrant()`, a distinct EPP command from generic update). Body `{"registrant"*, "authinfo"?}` — the new registrant must be a contact you own (403 otherwise), since this also moves the domain's local ownership to that contact's owner — authinfo is rotated automatically (server-generated if omitted) since the registry requires it to change alongside the registrant |
| `POST /v1/domains/{name}/status` | user | body `{"state"*, "action"?: "add"\|"rem" (default "add")}` — EPP status flags (e.g. `clientTransferProhibited`) |
| `DELETE /v1/domains/{name}?mode=now\|expiry\|date&date=YYYY-MM-DD` | user | ownership is checked up front, so a domain you don't own is **403** in every mode (it used to be a 404 for `mode=expiry\|date`). `mode=now` (default): immediate EPP delete + local deactivate. `mode=expiry`/`mode=date`: **does not touch the registry at all** — just inserts a future-dated `reminder` row (`date` required when `mode=date`; defaults to the domain's `ex_date` for `mode=expiry`) for some other process to act on later. `mode=date` 400s if `date` is missing |
| `POST /v1/domains/{name}/restore` | user | undelete a `pendingDelete`/redemption-period domain |
| `POST /v1/domains/{name}/owner` | admin | reassigns local ownership to another user: duplicates the registrant (and admin, if set) contact under the new owner, picks the new owner's default tech contact (`users.techc`) or duplicates the current one, runs `updateRegistrant()` then a generic `update()`, then flips `domains.user_id`. Body `{"user_id"*}` (the new owner). Multi-step — can partially fail (e.g. registrant duplicated but registrant-change rejected); check `error` carefully in the UI |
| `POST /v1/domains/{name}/transfer` | user | request-transfer-in, storing the desired post-transfer registrant/tech/ns locally (`transfers` table) for `PollProcessor` to apply once the registry confirms. Body `{"authinfo"*, "registrant"?, "tech"?: [...], "ns"?: [...]}`. **Not** ownership-checked — you're claiming a domain you don't hold yet — but 403 if another local user already holds it |
| `POST /v1/domains/{name}/transfer/approve` | user | body `{"authinfo"?}`; ownership-checked against `domains` (you're answering a request for a domain you sponsor) |
| `POST /v1/domains/{name}/transfer/reject` | user | body `{"authinfo"?}`; ownership-checked against `domains` |
| `POST /v1/domains/{name}/transfer/cancel` | user | body `{"authinfo"?}`; unlike approve/reject, does **not** delete the local `transfers` row (cancelling an outgoing request the local side itself made, not one incoming). Ownership check accepts a pending `transfers` row too, since the domain isn't in `domains` yet |

### Contacts

`contactToArray()` shape: `{ handle, status, name, org, street, street2, street3, city, province, postalcode, countrycode, voice, fax, email, authinfo, consentforpublishing, nationalitycode, entitytype, regcode, schoolcode }`.

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/contacts?active=1\|0` | user | local DB only, scoped rows: `{handle, org, name, user_id}` (not the full `contactToArray()` shape — fetch by handle for full detail) |
| `GET /v1/contacts/{handle}` | user (+ownership/attachment check) | registry-first, same contract as `GET /v1/domains/{name}`: live EPP `fetch()` returned with `"stale": false`, falling back to the local row with `"stale": true` when the registry can't answer. 403 if `canAccessContact()` fails, 404 when neither source has it. No longer returns 502 |
| `POST /v1/contacts` | user | body: any of the `contactToArray()` fields except `status`/`consentforpublishing` (server-managed), plus optional `handle` (16 random hex chars, registry-checked for uniqueness, if omitted) and `authinfo` (server-generated if omitted). `name` is required. `201` + full contact on success |
| `PATCH /v1/contacts/{handle}` | user (+ownership/attachment check) | same field allow-list as create, partial update |
| `DELETE /v1/contacts/{handle}` | user | **no `canAccessContact()` check** — only succeeds if the registry itself allows the delete (i.e. the contact isn't attached to any domain there), but there's no local ownership gate before attempting it. Treat as a gap if tightening auth later |

### Reminders

Two distinct concepts share this table: DNS-sync events (`action` set,
system-internal, applied by `eppitnic pdns sync`) and human-facing
scheduled notices (`action` NULL, e.g. "renew this domain").

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/reminders?page=&pageSize=&action=&active=` | admin | paginated, unscoped, sees the raw queue including DNS-sync rows. `action=null` (literal string) filters to `action IS NULL` |
| `GET /v1/domains/{name}/reminders` | user | scoped to domains the caller owns (or all, if admin); only `active = 1` rows, and only `{id, date, domain, email, notice}` — `action` is not exposed here (this endpoint is meant for the human-notice use case) |
| `POST /v1/domains/{name}/reminders` | user | body `{"date"*, "notice"*, "email"?}`. 403 if the domain isn't owned by the caller (and caller isn't admin) |
| `DELETE /v1/reminders/{id}` | user | soft-delete (`active = 0`); 403 if the reminder's domain isn't owned by the caller |

### Changelog (audit trail)

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/history` | user | the audit trail, newest first, as `{"history": [...], "total": n}`. **Scoped to what the caller may see**: an admin sees everything, everyone else sees the history of objects they own — their own `users` row, their `domains`, their `contacts` — and never `security`. `total` counts what they may see, not what exists. Admins also get `outstanding`: how many `security` entries nobody has acknowledged. Filters: `object`, `object_id`, `action`, `network`, `acknowledged` (`0`/`1`), `since`, `until`, `limit` (max 500, default 100), `offset`. Filters narrow what is visible and never widen it, so `?object=security` as a non-admin is an empty list rather than a 403 |
| `GET /v1/history/{object}/{object_id}` | user | shorthand for `GET /v1/history?object=…&object_id=…`, scoped identically |
| `POST /v1/history/{id}/acknowledge` | admin | mark one entry as reviewed. Records `acknowledged_time` and `acknowledged_user_id` rather than a flag — an entry that was dismissed is worth being able to ask about later. Does not alter what the entry says happened. Re-acknowledging re-stamps it, so the last person to look at it is the one on record. `404` for an unknown id |

### WHOIS

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/whois?domain=` | user | proxies a live WHOIS lookup (`kevinoo/phpwhois`); a multi-value `status` field is collapsed to the string `"multiple status fields (see detailed output)"` rather than returned as an array — the raw whois server response shape otherwise passes through unmodified, so don't assume a stable schema beyond that |

---

## Known gaps / things to flag back to backend if the frontend needs them

- No stable machine-readable error `code` field — the original plan called
  for one (`{"error": {"code": "DOMAIN_CREATE_FAILED", ...}}`), it was
  never implemented; every route just returns a human-readable string.
- `GET /v1/users` and `GET /v1/users/{id}` are reachable by any
  authenticated user, not just admins — fine for an internal back-office
  tool, worth knowing before exposing this API more broadly.
- `DELETE /v1/contacts/{handle}` has no ownership check (see above).
- `DELETE /v1/users/{id}` doesn't block deleting id `1`.
- No refresh-token flow — `GET /v1/users/renew-token` just re-signs the
  same claims from whatever token you already have; if it's expired,
  you're back to a full login.
- `max_idle_time` is accepted and stored but never enforced (see above) —
  there is no idle-session timeout, only absolute token expiry.
- Invoicing/accounting has been removed from this API entirely (no
  `/v1/accounting` routes, no `accounting` table, no `users.billing_id`); it
  will be reimplemented separately.
- Domain/contact write endpoints don't return `422`-style field-level
  validation errors — `requireFields()`/`maxLength()` return a single
  string naming the first problem found, not a structured per-field list.
