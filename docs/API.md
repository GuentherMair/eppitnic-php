# eppitnic REST API

Handoff reference for building the frontend client, and the source of truth
for the API: it documents what is **actually implemented** in
`public/index.php` + `src/Api/Routes/*.php`.

The API is a thin JSON/HTTP adapter (Slim 4) in front of the `Eppitnic\Epp\*`
registry classes and a MariaDB local database (via RedBeanPHP's `R::` facade).

## Base URL & content type

All routes are prefixed `/v1/` except `GET /` (a bare "Hello, World!" health
check, not JSON). Send `Content-Type: application/json` on request bodies;
responses are `application/json; charset=utf-8` unless noted otherwise
(`GET /v1/domains/export` returns `text/csv`).

Every response is JSON, including every error, whatever the request's `Accept`
header says: `Middleware::register()` replaces Slim's own error handler with a
JSON one, so there is no content negotiation and no HTML error page to fall
back to. See "Error shapes" below.

**Anything read straight from the database arrives as a string**, integers and
booleans included — RedBeanPHP leaves `PDO::ATTR_STRINGIFY_FETCHES` on and
nothing here turns it off. So a row's `id` is `"3"` and its `admin` flag is
`"0"`, which is *truthy* in JavaScript; compare explicitly rather than testing
the value for truth. The exceptions are values a handler casts on the way out —
the login claims, and the `total`/`outstanding` counters — which are real JSON
numbers and booleans.

**Payloads are enveloped.** A response body is an object with the data under a
named key — never the data itself:

| Route(s) | Body |
|---|---|
| `GET /v1/domains`, `.../expiring`, `.../autocomplete` | `{"domains": [...]}` |
| `GET /v1/domains/transfers` | `{"transfers": [...]}` |
| `GET /v1/domains/{name}` | `{"domain": {...}, "stale": bool}` |
| `POST /v1/domains`, `PATCH /v1/domains/{name}`, `.../registrant`, `.../status`, `.../owner` | `{"domain": {...}}` — no `stale` |
| `GET /v1/contacts` | `{"contacts": [...]}` |
| `GET /v1/contacts/{handle}` | `{"contact": {...}, "stale": bool}` |
| `POST /v1/contacts`, `PATCH /v1/contacts/{handle}` | `{"contact": {...}}` |
| `GET /v1/users`, `GET /v1/users/{id}` | `{"users": [...]}` |
| `GET /v1/domains/{name}/tasks` | `{"tasks": [...]}` |
| `GET /v1/poll-queue` / `{id}` | `{"messages": [...]}` / `{"message": {...}}` |
| `GET /v1/history`, `/v1/history/{object}/{object_id}` | `{"history": [...], "total": n}` |
| `POST /v1/users/authenticate`, `GET /v1/users/renew-token`, `GET /v1/users/me` | **not enveloped** — the claims are the body |

Two traps in that table. `POST /v1/domains/{name}/transfer` answers
`{"requested": true, "domain": "example.it"}`, where `domain` is the **name as
a string**, not the object it is everywhere else. And `GET /v1/users/{id}`
answers `{"users": [row]}` — a one-element **array** under the plural key, and
an empty array rather than a `404` when the id doesn't exist.

## Setup

`GET /v1/setup`, `POST /v1/setup/verify` and `POST /v1/setup` are reachable
only while `config/config.php` does not exist; each answers `404` once it
does. No auth — there is nothing to authenticate against yet, and the file's
absence is the gate (see [INSTALL.md](INSTALL.md)). They're served
from a separate, database-free Slim instance (`Eppitnic\Api\SetupApp`), not
from the route list below: `public/index.php` runs one or the other, never
both in the same request.

`GET /v1/setup` → `{"required": true, "fields": [...]}` — one entry per input
field (`name`, `label`, `default`, `secret`, `group`, `required`), the same
list `eppitnic setup` prompts from and `public/setup.html` renders from.
`group` is `database`, `admin` or `epp`.

`POST /v1/setup/verify` — body: the `database` group's fields (`db_type`,
`db_host`, `db_name`, `db_charset`, `db_user`, `db_password`). Opens a
throwaway connection and discards it; `{"ok": true, "server_version": "..."}`
on success, `{"error": "..."}` / `400` otherwise. Nothing is written or
committed by this call.

`POST /v1/setup` — body: every field from `GET /v1/setup`. Applies
`config/mariadb-schema.sql`, creates the first admin account, and writes
`config/config.php` as its last step — a failure partway through leaves
nothing committed, so the call is safe to retry.
`{"ok": true, "schema_version": "070000", "admin": {"id": 1, "username": "..."}}`
on success, `{"error": "..."}` / `400` otherwise. Never returns a database or
admin password, and a schema failure's raw SQL is truncated out of the
response body (the full detail goes to the server log).

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
| `secread` | turned away by the limit, or a credential disclosure | no |

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

Success response — `Auth::issueToken()`'s output, i.e. every field passed in plus a
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
  "debug": false,
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
  record doesn't set one; feeds `Auth::issueToken()`'s `exp` claim. `null`, `0` and
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
from the current token's own claims (`Auth::issueToken((array) $decoded->data)`),
same response shape as login. Use this to keep a session alive without
re-prompting for a password; it does **not** re-check TOTP, so a token that
was minted without MFA verification stays that way until re-login.

`GET /v1/users/me` (auth: any valid token) — returns the decoded claims
as-is (no `token` field).

### MFA gate (`Auth::requireMfa()`)

Certain sensitive routes require the *current* token to have
`totp_verified` (not just any valid token) or they 403 with `"MFA
verification required"`: `PUT /v1/changepassword/{id}`,
`DELETE /v1/users/{id}/totp`. This matters if a client ever mints a token
for a has-TOTP account without the code (not currently possible through
`authenticate`, but relevant if that changes).

`Auth::requireAdmin()` implies the same MFA check, in addition to requiring
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
  route handlers from a JWT-derived identity (`Auth::verify()` synthesizes the
  same claims shape).

### Password change (per-user login password)

`PUT /v1/changepassword/{id}` (auth: self-with-MFA or admin) — body
`{"password": "..."}`. This is the local `users.password`, unrelated to the
shared EPP registry credential (below). Trying to change another user's
password without being an admin is **403** — it answered 401 previously,
which contradicted every other authorization refusal in the API.

## Authorization model

- `admin` claim `=== 1` → unrestricted, sees/manages every user's data, can
  hit `Auth::requireAdmin()`-gated routes.
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
calls in `EppSession::run()` (`src/Service/EppSession.php`). With the
`keepalive` setting off — the default — that's one session per HTTP request:
connect, `hello()`+`login()`, run the callback, always `logout()`, nothing
pooled. With it on, requests share one session that `eppitnic session
keepalive` refreshes from cron (see docs/INSTALL.md's "Session keep-alive");
a request opens it only when there is none fresh, and never logs out. Either
way, if the session can't be opened, or (`keepalive` only) the registry turns
out to be unreachable mid-request, the route returns **502** with
`{"error": "EPP session unavailable: ..."}` — this is distinct from a `400`,
which means the registry responded but rejected the operation (bad input,
business-rule violation, etc.). Routes that only touch the local DB
(`GET /v1/domains`, `.../expiring`, `.../autocomplete`, `.../export`,
`.../transfers`, most of `contacts`/`tasks`) never pay this cost.

The two single-object reads — `GET /v1/domains/{name}` and
`GET /v1/contacts/{handle}` — are the exception to the 502 rule: rather than
failing when the registry is unavailable, they fall back to the local DB and
mark the payload `"stale": true`. Every other EPP-backed route still 502s.

## Error shapes

One shape, wherever the request failed — a route's own validation or EPP
rejection, and equally the errors raised before a handler runs (missing,
invalid or expired token → 401; wrong `admin`/MFA state → 403; unknown route →
404; wrong method → 405; uncaught exception → 500):

```json
{ "error": "Domain 'example.it' not found" }
```

Always a string, never nested, and there is **no** stable `code` enum like the
original plan sketched — branch on the HTTP status. Matching the message text
works but is brittle; the one place it is unavoidable today is telling an
expired token (`"Token has expired: ..."`) from an absent one, since both are
401. A client that needs that distinction is better off reading `exp` from the
claims (`GET /v1/users/me`) and renewing before it passes.

`Middleware::register()` (`src/Api/Middleware.php`) installs this via
`setDefaultErrorHandler()`, replacing Slim's `ErrorHandler` and its renderers
outright — which is why `Accept` plays no part. The status comes from the
exception: `HttpException::getCode()` when a handler threw one, 500 otherwise.
Error responses are produced *inside* the CORS middleware, so they carry the
`Access-Control-Allow-*` headers too — a browser can read its own 401 rather
than seeing an opaque network failure (`tests/Http/ErrorResponseTest.php`).

**Details are off by default.** `displayErrorDetails` is read from the
`EPPITNIC_DEBUG` environment variable — from the environment, not the
`settings` table, since the error handler has to work when the database is
unreachable. With it off, a 500's real message is replaced by
`{"error": "Internal server error"}` and only the server log gets the detail;
4xx keep their own messages. With it on, responses additionally carry
`exception` (the class name), `file`, `line` and `trace` — **never enable it on
anything public.**

`GET /v1/domains/export` is the one route whose *success* is not JSON
(`text/csv`); its failures still are.

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

Three details that matter to a browser client:

- `Access-Control-Expose-Headers: Content-Disposition` is set, so a
  cross-origin caller can read the filename `GET /v1/domains/export` supplies.
  That download needs the `Authorization` header, so it cannot be a plain
  link — fetch it and turn the response into a blob.
- `Access-Control-Allow-Credentials: true` is set unconditionally, but the API
  keeps no cookies and no session state; auth is the bearer header alone.
  Leave `credentials`/`withCredentials` alone — turning it on buys nothing.
- `Access-Control-Max-Age` is never sent, so browsers fall back to a very short
  preflight cache and re-`OPTIONS` almost every authenticated request. Serving
  the SPA from the API's own origin avoids this (and CORS) altogether.

## Pagination convention

Used by `GET /v1/tasks` (admin-only variant) and any future list-heavy
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
| `PATCH /v1/session/epp` | admin | change any of those same 7 plain fields (not `password`, see below). Body = partial field map, e.g. `{"lang": "it"}`; `null` (or blank) unsets an optional field (`server_deleted`/`port`/`interface`) — `server`/`username`/`lang`/`cl_trid_prefix` are required and `400` if unset. `400` on an unknown field or a failed validator. Recorded to `history` (`object='epp'`). Returns the same shape `GET /v1/session/epp` does. Shares its validation with `config epp-set`/`config epp-server`, so neither can disagree with the other |
| `GET /v1/session/epp/interfaces` | admin | this server's own IPv4 addresses (loopback excluded), `{"interfaces": ["..."]}` — what a UI offers as choices for the `interface` field, so a typo can't silently bind outgoing registry connections to nothing. Queried live via `net_get_interfaces()`, not cached |
| `GET /v1/session/credit` | user | live EPP registry account balance, `{"credit": "..."}`; 502 if the registry session fails |
| `GET /v1/session/epp/credentials` | admin | the shared EPP registry credential itself — `{"credentials": {"server", "username", "password"}}`. Separate from `GET /v1/session/epp` on purpose: that one is what a settings screen loads, and a secret delivered as a side effect of rendering a page ends up in caches, proxy logs and screenshots. Exists because the password is rotated automatically — after `eppitnic poll process` acts on a `passwdReminder`, this is the only way short of a SQL client to learn the current one. When a rotation was interrupted the response also carries `pending_password` and a `note`: the registry holds one of the two and only it can say which. `404` when no password is configured. **Every retrieval is recorded** in `history` as a `security`/`secread` row: the acting user, the client IP, and the request headers. The password is not written, and `Authorization`, `Cookie` and `Proxy-Authorization` are stored as `[redacted]` — they are themselves credentials, and the log is read by more people than the password was shown to. A refused request records nothing |
| `POST /v1/session/change-password` | admin | rotates the **shared EPP registry** credential (not any user's login password) — records the new password in the `settings` table, then logs into EPP with it to make the change. Body `{"password"?: "..."}` (random if omitted; 16 characters is the EPP maximum). 500/502/400 when the settings write, the registry connection or the registry itself fails. A failure before the registry is reached leaves the current credential untouched; one after it is settled by `eppitnic doctor epp-password` |
| `GET /v1/poll-queue` | admin | raw `messages` table rows, `?active=1\|0` (default `1` = `archived_time IS NULL` only), newest first. `?limit=n` (1–500) returns only the newest `n`; `total` is how many matched either way: `{"messages": [...], "total": n}` |
| `GET /v1/poll-queue/{id}` | admin | single message, 404 if missing |
| `POST /v1/poll-queue/{id}/archive` | admin | sets `archived_time`/`archived_user_id`; answers `{"archived": true, "id", "archived_time"}` |
| `POST /v1/poll-queue/archive` | admin | archive every unarchived message up to a moment, in one call. Body `{"until": "YYYY-MM-DD HH:MM:SS"}` (a real datetime; `400` otherwise), compared to `created_time` inclusively. Pass the newest message the user has loaded, so what arrived since stays in the queue rather than being archived unread. `created_time` is only second-resolution, so pass `"until_id"` (that message's `id`) instead for an exact cutoff — ids are monotonic, and when given it is used in place of `until`. Already-archived messages keep their stamp. Answers `{"archived": n, "until", "until_id", "outstanding"}` (`until_id` only when given) — `outstanding` is how many unarchived messages remain |

### Users (admin-managed accounts)

**Password rule.** Every route that *sets* a password — `POST /v1/users`,
`PUT /v1/users/{id}` (only when one is supplied) and `PUT /v1/changepassword/{id}` —
requires at least 12 characters including a lower-case letter, an upper-case
letter, a digit and one character that is neither. A password that misses any of
them is refused with `400` and a message naming what is missing. The rule is
also enforced in `Persistence\User::create()`, so the CLI and the installer
cannot route around it, and `GET /v1/setup` returns it as data so a form can
state it before anybody types.

`POST /v1/users/authenticate` is deliberately **not** subject to it: judging a
password at login would lock out every account created before the rule, and the
refusal there stays the generic `Wrong username or password` whatever the reason.

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/users` | user | **not actually scoped** despite requiring only a valid token — returns every user's `id, active, admin, username, max_token_age, max_idle_time, debug, has_totp`, and for an admin also `description, email, max_operations`. Never returns password hashes |
| `GET /v1/users/{id}` | user | same field set, but still `{"users": [row]}` — a one-element array, and `[]` rather than `404` for an unknown id. Also unscoped: any logged-in user can look up any other by id |
| `POST /v1/users` | admin | create. Required: `username`, `password`. Optional: `description`, `email`, `max_operations` (daily domain-create quota, `0` = unlimited), `active` (default `1`), `admin` (default `0`), `max_token_age`, `max_idle_time`, `debug`. `400` if a required field is missing or if `username` is already taken |
| `PUT /v1/users/{id}` | admin | update of the same field set. **Every field is optional** — anything omitted keeps its current value (this includes `password`, as before). `404` if the id doesn't exist, `400` on a `username` collision with another row |
| `DELETE /v1/users/{id}` | admin | soft-delete (`active = 0`) — does **not** block deleting id `1`, unlike the original plan's intent; be careful in the UI |

#### Defaults and NS sets

What a user starts new contacts and domains from, kept on their `users` row.
All of it is the user's own to read and change (an MFA-verified admin may act
for anyone), and every route answers with the whole current state:
`{"settings": {"countrycode", "techc", "dnsset", "nssets"}}`.

- `countrycode` — a two-letter ISO 3166-1 code (stored upper-case), or `""`.
- `techc` — a list of up to six contact handles. Rows written before it was a
  list hold one bare handle, which reads as a one-element list;
  `POST /v1/domains/{name}/owner` uses the whole list.
- `nssets` — named sets `{"name", "ns": [...]}`. A name is unique per user
  ignoring case, at most 64 characters, without a slash. `ns` holds 2 to 6
  hostnames, lower-cased; addresses are refused, since only a single domain's
  glue records ever need them.
- `dnsset` — the name of the set new domains start with, or `""`. It follows
  a renamed set and is cleared when its set is removed.

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/users/{id}/settings` | self or admin | `404` for an unknown user |
| `PUT /v1/users/{id}/settings` | self or admin | body: any of `countrycode`, `techc`, `dnsset`; what is omitted stays. `dnsset` must name one of the user's sets |
| `POST /v1/users/{id}/nssets` | self or admin | body `{"name", "ns"}`; `201` |
| `PUT /v1/users/{id}/nssets/{name}` | self or admin | body `{"name"?, "ns"}` — replaces the nameservers, and renames the set if `name` differs. `404` for an unknown set |
| `DELETE /v1/users/{id}/nssets/{name}` | self or admin | `404` for an unknown set |

### Domains

`domainToArray()` — the shape of the object **inside** the `domain` envelope
(see "Payloads are enveloped" above), used by every single-domain response
below: `{ domain, status, registrant, admin, tech: [handles], ns: [names], authinfo, dnssec, cr_date, ex_date }`. `tech`/`ns` are flattened to plain string arrays (keys of the underlying assoc maps) — no per-NS IP or per-tech metadata comes through this shape.

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/domains` | user | local DB only, no EPP round-trip. Query params: `registrant` (exact match), `active` (`1`\|`0`, default `1`), `age` (months since `ex_date`, filters to older-than). Returns **raw DB rows** `{domain, registrant, user_id, status}` per entry — not `domainToArray()` — plus any pending transfer-in requests with `" (transfer-in)"` appended to the domain name as a literal string suffix (not a separate field — parse it out if the UI needs to distinguish); a transfer-in row always has `status: []`, since EPP has not confirmed the domain locally yet |
| `GET /v1/domains/expiring?days=30` | user | local DB, joined with the registrant contact; rows include `handle, org, name, email` alongside the domain columns. `ns`, `tech`, `status` and `dnssec` are decoded from their stored serialization into real JSON (`ns`/`tech` as objects keyed by hostname/handle, so take `Object.keys()`; `status`/`dnssec` as arrays) — they are **not** in the flattened `domainToArray()` shape. Scoped by the **domain's** owner (`domains.user_id`), same as every other domain route |
| `GET /v1/domains/autocomplete?term=&limit=10` | user | domain-name substring search (`LIKE %term%`), includes transfer-in pending domains with the same `" (transfer-in)"` suffix, returns `{"domains": ["a.it", "b.it (transfer-in)", ...]}` |
| `GET /v1/domains/export` | user | **not JSON** — `text/csv` with `Content-Disposition: attachment`, columns `Active;Domain;Auth-Info;Created;Expires;Registrant Handle;Registrant Org;Registrant Name;Registrant Email` |
| `GET /v1/domains/transfers?registrant=` | user | pending local transfer-in requests (the `transfers` table, not registry `pendingTransfer` polling state) — `techc`/`dns` are unserialized back into arrays for the response. Scoped by who **requested** the transfer (`transfers.user_id`), matching what `.../transfer/cancel` authorizes against |
| `GET /v1/domains/{name}` | user | registry-first: live EPP `fetch()`, returned as-is in the `domainToArray()` shape with `"stale": false`. If the registry can't answer — `fetch()` fails **or** the session itself fails — falls back to the local DB row and returns it with `"stale": true`. 404 only when neither source has it (the local fallback is scoped by `user_id`, so a domain you don't own counts as absent). This route no longer returns 502 |
| `POST /v1/domains` | user | create-or-transfer-request in one call: `check()`s the name first, `create()`s if available, otherwise issues a `transfer()` request if it's held elsewhere. Body: `domain*, registrant*, admin?, tech?: [...], ns?: [{name,ip?}...], authinfo?` (`*` = required). The `registrant` must be a contact you own, else 403. **Daily quota enforced** for non-admins via `users.max_operations` vs. today's `history` create-count — `429` with `{"error": "Daily operation quota exceeded"}` when hit. `201` + `domainToArray()` on success |
| `POST /v1/domains/import` | user | body `{"domains": ["a.it", ...]}` — pulls each from the registry into the local DB (idempotent reconciliation, not a create). Response is a per-domain diagnostic object keyed by domain name, each with `domain` and `registrant` (`"found"`/`"not found"`) and `contact_stored` and `domain_stored` (`"stored"`/`"not stored"`). A step that was never reached reads `"skipped"`, so the first non-`skipped` failure is where the import stopped and why — not a simple success flag, and useful for surfacing partial failures in a bulk-import UI |
| `PATCH /v1/domains/{name}` | user | partial update: `admin`, `authinfo` set directly; `ns`, `tech`, `dnssec` are **full-target-list diffs** — send the complete desired array and the server computes add/remove, don't send deltas. `dnssec` entries are `{keytag, algorithm, digesttype, digest}`. Registrant changes are **not** accepted here — see the dedicated endpoint below |
| `POST /v1/domains/{name}/registrant` | user | dedicated registrant-change flow (`Domain::updateRegistrant()`, a distinct EPP command from generic update). Body `{"registrant"*, "authinfo"?}` — the new registrant must be a contact you own (403 otherwise), since this also moves the domain's local ownership to that contact's owner — authinfo is rotated automatically (server-generated if omitted) since the registry requires it to change alongside the registrant |
| `POST /v1/domains/{name}/status` | user | body `{"state"*, "action"?: "add"\|"rem" (default "add")}` — EPP status flags (e.g. `clientTransferProhibited`) |
| `DELETE /v1/domains/{name}?mode=now\|expiry\|date&date=YYYY-MM-DD` | user | ownership is checked up front, so a domain you don't own is **403** in every mode (it used to be a 404 for `mode=expiry\|date`). `mode=now` (default): immediate EPP delete + local deactivate. `mode=expiry`/`mode=date`: **does not touch the registry at all** — just inserts a future-dated `tasks` row (`object='registry'`, `action='delete'`, `date` required when `mode=date`; defaults to the domain's `ex_date` for `mode=expiry`) for `eppitnic domain reap-deletions` to act on once due — that job's own query requires `action='delete'`, so no other `registry` row shape is ever picked up. `mode=date` 400s if `date` is missing |
| `POST /v1/domains/{name}/restore` | user | undelete a `pendingDelete`/redemption-period domain |
| `POST /v1/domains/{name}/owner` | admin | reassigns local ownership to another user: duplicates the registrant (and admin, if set) contact under the new owner, picks the new owner's default tech contact (`users.techc`) or duplicates the current one, runs `updateRegistrant()` then a generic `update()`, then flips `domains.user_id`. Body `{"user_id"*}` (the new owner). Multi-step — can partially fail (e.g. registrant duplicated but registrant-change rejected); check `error` carefully in the UI |
| `POST /v1/domains/{name}/transfer` | user | request-transfer-in, storing the desired post-transfer registrant/tech/ns locally (`transfers` table) for `PollProcessor` to apply once the registry confirms. Body `{"authinfo"*, "registrant"?, "tech"?: [...], "ns"?: [...]}`. **Not** ownership-checked — you're claiming a domain you don't hold yet — but 403 if another local user already holds it |
| `POST /v1/domains/{name}/transfer/approve` | user | body `{"authinfo"?}`; ownership-checked against `domains` (you're answering a request for a domain you sponsor) |
| `POST /v1/domains/{name}/transfer/reject` | user | body `{"authinfo"?}`; ownership-checked against `domains` |
| `POST /v1/domains/{name}/transfer/cancel` | user | body `{"authinfo"?}`; unlike approve/reject, does **not** delete the local `transfers` row (cancelling an outgoing request the local side itself made, not one incoming). Ownership check accepts a pending `transfers` row too, since the domain isn't in `domains` yet |

### Contacts

`contactToArray()` — the shape of the object **inside** the `contact`
envelope: `{ handle, status, name, org, street, street2, street3, city, province, postalcode, countrycode, voice, fax, email, authinfo, consentforpublishing, nationalitycode, entitytype, regcode, schoolcode }`.

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/contacts?active=1\|0` | user | local DB only, scoped rows: `{handle, org, name, entitytype, status, user_id}` (`status` is the list of flags as of the contact's last sync, e.g. `["ok", "linked"]`) (not the full `contactToArray()` shape — fetch by handle for full detail) |
| `GET /v1/contacts/{handle}` | user (+ownership/attachment check) | registry-first, same contract as `GET /v1/domains/{name}`: live EPP `fetch()` returned with `"stale": false`, falling back to the local row with `"stale": true` when the registry can't answer. 403 if `canAccessContact()` fails, 404 when neither source has it. No longer returns 502 |
| `POST /v1/contacts` | user | body: any of the `contactToArray()` fields except `status` (server-managed), plus optional `handle` (16 random hex chars, registry-checked for uniqueness, if omitted) and `authinfo` (server-generated if omitted). `consentforpublishing` is a boolean (or `1`/`0`); the registry refuses withdrawing it for entity types other than 1 and 3. `name` is required. `201` + full contact on success |
| `PATCH /v1/contacts/{handle}` | user (+ownership/attachment check) | same field allow-list as create, partial update |
| `DELETE /v1/contacts/{handle}` | user | **no `canAccessContact()` check** — only succeeds if the registry itself allows the delete (i.e. the contact isn't attached to any domain there), but there's no local ownership gate before attempting it. Treat as a gap if tightening auth later |

### Tasks

Three things share this table, told apart by `object`: rows a consumer owns
and executes (`object='pdns'`, applied by `eppitnic pdns sync`; `object=
'registry'`, applied by `eppitnic domain reap-deletions`) and human-facing
scheduled notices (`object` NULL, e.g. "renew this domain") nothing automated
reads. A consumer-owned row also carries the result of its last run:
`executed_time`, `exit_code` (0 success, nonzero failure), `exit_message` —
NULL/NULL/NULL until a consumer has actually acted on it. Only a success
retires a row (`active=0`); a failure records the result and stays active for
the next run to retry.

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/tasks?page=&pageSize=&object=&action=&active=` | admin | paginated, unscoped, sees the raw queue including consumer-owned rows. `object=null`/`action=null` (literal string) filter to `IS NULL` |
| `GET /v1/domains/{name}/tasks` | user | scoped to domains the caller owns (or all, if admin); only `active = 1` rows with `object IS NULL` (the human-notice use case), and only `{id, date, domain, email, notice}` |
| `POST /v1/domains/{name}/tasks` | user | body `{"date"*, "notice"*, "email"?}`. 403 if the domain isn't owned by the caller (and caller isn't admin) |
| `DELETE /v1/tasks/{id}` | user | soft-delete (`active = 0`); 403 if the task's domain isn't owned by the caller |

### Cronjobs

The five scheduled jobs' settings (`docs/INSTALL.md`'s "Scheduled jobs") --
the same `Eppitnic\Service\CronjobSettings` every `config *-set` CLI command
uses, so a change made here or on the command line is validated and audited
identically (`history`, `object='cronjobs'`).

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/cronjobs` | admin | every job's current settings, as `{"jobs": {"pdns": {...}, "domain_sync": {...}, "domain_reap_deletions": {...}, "poll_process": {...}, "keepalive": {...}}}`. Job-state fields (`cursor_id`, `last_run_at`) are included read-only, not part of what `PATCH` accepts |
| `PATCH /v1/cronjobs/{job}` | admin | body = partial field map for that job, e.g. `{"enabled": true, "frequency_minutes": 10}`. `400` with `{"error": "..."}` on an unknown job/field or a failed validator (same message the CLI's usage error already produces). `{"force": true}` in the body overrides `pdns.path`'s `is_executable()` check, mirroring `config pdns-set path --force`. Returns `{"job": "...", "settings": {...}}`, the job's full updated settings |

Every job here has `enabled` except `keepalive`, which has no `enabled`
field of its own everywhere else it's read (`Config::get('keepalive')` is a
bare bool) -- this route wraps it as `{"enabled": bool}` only for a
uniform response shape, matching every other job. Turning `poll_process`
off stops the shared registry password from auto-rotating on a
`passwdReminder`, alongside the queue drain and transfer reconciliation --
a real foot-gun, but the operator's call to make (see "Scheduled jobs" and
the frontend's warning in that job's dialog).

### History (audit trail)

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/history` | user | the audit trail, newest first, as `{"history": [...], "total": n}`. **Scoped to what the caller may see**: an admin sees everything, everyone else sees the history of objects they own — their own `users` row, their `domains`, their `contacts` — and never `security`. `total` counts what they may see, not what exists. Admins also get `outstanding`: how many `security` entries nobody has acknowledged. Filters: `object`, `object_id`, `action` (or several, comma-separated: `action=denied,secread`), `network`, `acknowledged` (`0` = not yet acknowledged, **any other value** = acknowledged), `since`, `until`, `limit` (max 500, default 100), `offset`. Filters narrow what is visible and never widen it, so `?object=security` as a non-admin is an empty list rather than a 403 |
| `GET /v1/history/{object}/{object_id}` | user | shorthand for `GET /v1/history?object=…&object_id=…`, scoped identically. Only `limit` is honoured here (default **500**, not 100) — no `offset`, no further filters. Answers `{"history": [...], "total": n}` without `outstanding` |
| `POST /v1/history/acknowledge` | admin | acknowledge every entry still unacknowledged up to a moment, in one call. Body `{"until": "YYYY-MM-DD HH:MM:SS"}` (a real datetime; `400` otherwise), compared to the entry's timestamp inclusively, and optionally `"actions": [...]` to acknowledge only entries of those actions (`400` unless a non-empty list of known ones). Pass the newest entry the user has loaded, so what arrived since stays outstanding rather than being acknowledged unread. The timestamp is only second-resolution, so pass `"until_id"` (that entry's `id`) instead for an exact cutoff — ids are monotonic, and when given it is used in place of `until`. `"object"` (default `security`, `400` unless one of the `object` enum) scopes it — `outstanding` only ever counts `security`, and this is the button that clears that badge. Already-acknowledged entries keep their stamp. Answers `{"acknowledged": n, "object", "until", "until_id", "outstanding"}` |
| `POST /v1/history/{id}/acknowledge` | admin | mark one entry as reviewed. Records `acknowledged_time` and `acknowledged_user_id` rather than a flag — an entry that was dismissed is worth being able to ask about later. Does not alter what the entry says happened. Re-acknowledging re-stamps it, so the last person to look at it is the one on record. Returns `{"acknowledged": true, "id": n, "entry": {…}}` with the entry as it now stands. `404` for an unknown id. Unscoped: an admin may acknowledge any entry, including a non-`security` one |

An entry is the table row as stored:

```json
{
  "id": "42", "timestamp": "2026-08-20 04:43:39", "user_id": "1",
  "object": "security", "object_id": "0", "action": "denied",
  "network": "203.0.113.0/24",
  "data": "{\"event\":\"login_failed\",\"username\":\"x\"}",
  "acknowledged_time": null, "acknowledged_user_id": null
}
```

Two things to expect when consuming it: `data` arrives as a **JSON string**, not a
nested object — parse it a second time — and the integer columns arrive as
numeric strings, because nothing re-types what the database driver returned.
`user_id` is `null` for events with no authenticated actor (a failed login at an
unknown username), and `object_id` is `0` where there is no object to point at.
What `data` holds depends on the event and is not a fixed schema; for `security`
rows it carries at least `event`, plus the client address and the request
headers, with `Authorization`, `Cookie` and `Proxy-Authorization` stored as
`[redacted]`.

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
- An expired token and an absent one are both `401` carrying only a message,
  so a client wanting to renew rather than bounce to the login screen has to
  match the message text or track `exp` itself.
- `allowed_headers` is seeded with `X-Api-Key`, which nothing in the codebase
  ever reads — a fixed API token goes in `Authorization` like a JWT. Harmless,
  but it implies a header that does not work.
- `POST /v1/contacts` only requires `name`, while the registry rejects a
  contact lacking any of `name`, `street`, `city`, `province`, `postalcode`,
  `countrycode`, `voice`, `email` (the list `contact create` enforces, in
  `src/Cli/Command/ContactCreateCommand.php`). The API defers those eight to a
  round-trip that comes back `400`, so a form should require them client-side.
