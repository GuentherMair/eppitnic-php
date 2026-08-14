# Refactoring plan

Goal: ~12,900 lines of own code down to ~7,000–7,500, one dependency dropped,
two directories retired — without changing what the registry sees, except
where what it saw was wrong.

Each phase is independently shippable and leaves `main` working. Line deltas
are estimates for our own code (`vendor/` excluded).

**Status:** Phases 0–7 complete.

| Phase | What | Effort | Δ lines | Status |
|---|---|---|---|---|
| 0 | Safety net | 1d | +2,000 (tests) | **done** |
| 1 | Dead code and weak randomness | 0.5d | −473 | **done** |
| 2 | Route boilerplate | 0.5d | −166 | **done** |
| 3 | `bin/eppitnic`, retire `CLI/` + `examples/` | 5d | −4,700 | **done** |
| 4 | Smarty → `DOMDocument` | 3–4d | −600 | **done** |
| 5 | Field maps and persistence | 2–3d | −270 | **done** |
| 6 | `changes` bitmask → dirty set | 1–2d | −40 | **done** |
| 7 | Issues found along the way | done | | 7.1–7.8 |

---

## Phase 0 — Safety net ✅

Nothing else was verifiable without this: no tests, no CI, no dev
dependencies.

- PHPUnit 11, `phpunit.xml`, `composer test`. The suite needs **no database
  and no network**: `Config::loadForTesting()` installs settings directly and
  a `FakeTransport` answers with canned responses.
- `Net\EPP\Transport` — the seam behind `Client::sendRequest()`, narrowed to
  the four methods `Client` actually calls.
- **29 request snapshots** (`tests/fixtures/wire/`) driven from a single
  `CommandCatalog`, so "which commands are covered" has one answer in one
  file. This is the contract Phase 4 must preserve.
- **Schema validation** of every request against `xsd/`, with the schema
  catalog compiled separately from the documents so a broken schema set
  reports as one failure instead of masquerading as 29 invalid requests.
- **37 response captures** (`tests/fixtures/responses/`) taken from real
  production traffic by `tests/capture-responses.php` and anonymised, plus
  `ResponseParsingTest` running the real parsers over them. Poll fixtures are
  keyed on the document's own shape (extension element + namespace), never on
  `messages.type` — see the method note under 7.2.

### Notes for whoever runs the capture tool again

- Responses live in a `__SERIALIZED:` + base64(serialize()) envelope when
  written by the 6.x code, and as plain strings when written by the current
  code. Both are handled.
- If the denylist backstop rejects a capture, **fix the scrubber — do not
  weaken the check.**

---

## Phase 1 — Dead code and weak randomness ✅

`AbstractObject` 713 → 298 lines: three ISO-3166 tables and their accessors
had no callers, and were loaded into every `Domain` and `Contact` instance.
`Client` lost `fetchResponse()`, an unreachable branch and an empty
destructor.

`authinfo()` now uses `bin2hex(random_bytes(8))` instead of
`substr(md5(rand()), 0, 16)`, and the six sites that inlined the old
expression call it. Two further sites turned up: the **shared EPP registry
password** generated as `substr(md5(rand()), 0, 8)`, and the clTRID tail.

All 29 request snapshots were byte-identical afterwards — the evidence that
the deleted code really was unreachable.

---

## Phase 2 — Route boilerplate ✅

`routes/` 1,982 → 1,816. `Helpers::json()` replaced 129 write/status/header
triples; `Helpers::actor()` replaced the 21-fold `$decoded`/`$user_id`/
`$isAdmin` preamble.

A `withEppSession`-plus-502 wrapper was planned and **deliberately dropped**:
with `json()` in place each catch body is one line, and a `[result,
?Response]` tuple hides the control flow rather than shortening it.
`Helpers.php` carries a note so it is not re-proposed.

---

## Phase 3 — `bin/eppitnic`, retiring `CLI/` and `examples/` ✅

52 scripts (5,689 lines) replaced by 28 subcommands in `Net/EPP/Cli`, plus
`docs/COOKBOOK.md` for using the library directly. `routes/domain.php` is 943
→ 718 lines, because three flows it owned moved to `Net\EPP\Service\DomainService`
and are now shared with the CLI rather than copied.

### Conventions worth keeping

- **Options are parsed by hand, not with `getopt()`**, which reads `$argv`
  itself and cannot tell an unknown switch from a positional argument — so a
  mistyped option used to be ignored and the command ran with the wrong inputs.
- **`--json` is one document, `--jsonl` one object per line.** `domain export`
  defaults to `--csv` and accepts all three, producing the same columns either
  way.
- **`--dry-run` prints the EPP request and sends nothing**, answering the
  session locally so it needs neither credentials nor connectivity. It previews
  a request built from *arguments* faithfully. Commands whose requests are
  built from *registry data* — `set-owner`, `import`, `poll drain` — decline it
  rather than show invented contents.
- **Anything irreversible asks first**, with `--yes` to skip. With no terminal
  to ask at the answer is no, rather than a prompt that hangs a cron job.

### Behaviour that changed

`CLI/domain.php` exited 0 after a failed fetch; `domain info` exits
`DOMAIN_FETCH_FAILED`. Anything scripted around the old exit codes needs
checking.

## Phase 4 — Smarty → `DOMDocument` ✅

`Net\EPP\XmlBuilder` builds every request with DOMDocument, one method per
command. `Client` no longer extends anything; `templates/`, `smarty/`, the
`smarty` settings key and the `smarty/smarty` dependency are gone.

The 29 request snapshots are byte-identical afterwards, which is what the
phase was measured against. One fixture moved, and only in attribute order --
that is now normalised away, since attribute order carries no meaning in XML
and a snapshot failing on it is a snapshot that stops being read.

### The escaping fix, and why it was forced

`Contact::set()` and `Domain::set()` ran every value through
`htmlspecialchars()` before storing it, and the templates emitted the result
raw. For `&` that produced correct-looking XML by coincidence -- HTML and XML
spell that entity the same way -- while the database filled with entities that
every reader had to undo, and `Contact::duplicate()` needed an explicit
`html_entity_decode()` to avoid compounding them on each copy.

DOMDocument escapes at serialization, so the first thing the swap produced was
`&amp;amp;`: the double encoding, finally visible. Values are now stored as
given and escaped once, where it is needed.

That leaves existing rows holding entities, so the 6.7→7.0 migration decodes
them as part of PART 3. Ordering matters there and is documented in the file:
`&amp;` is decoded last, or a literal `&amp;lt;` would turn into `<`.
`SCHEMA_VERSION` stays at `070000` — 7.0 is unreleased, so its migration is
still being written rather than added to.

`tests/Wire/EscapingTest.php` pins the property that replaced it: whatever a
caller sets comes back out of the generated document unchanged.

### Not done

XSD validation in `--verbose` was in the plan and is not here. The suite
already validates all 29 requests against `xsd/` on every run, so a per-request
check at runtime would repeat work that cannot fail without the tests failing
first. `--dry-run` prints the request for anyone wanting to validate one by
hand.

## Phase 5 — Field maps and persistence ✅

`Contact::FIELDS` and `Domain::FIELDS` are the single list of each class's own
fields and their change bits. `initValues()`, `set()`, `storeDB()`,
`updateDB()` and `loadDB()` all derive from it; before, the same list appeared
in four or five places and adding a field meant touching all of them.

The two sets of exceptions are named rather than left as commented-out switch
cases: `FIELDS_WITH_SETTERS` and `FIELDS_WITH_ADDERS` are the fields `set()`
must not mark dirty itself, because each has a setter that decides whether
anything actually moved.

`Net\EPP\LocalStorage` holds the persistence plumbing both classes repeated:
the user-scoping clause, turning a SQL failure into `setError()` plus false,
the row-id lookup for the history table, and the soft-delete flip.

Deliberately primitives rather than a shared `storeDB()`. The two classes
really do store differently -- a contact is upserted because
`domains`.`registrant` is a foreign key onto it, a domain is replaced
outright, and only a domain queues DNS-sync work -- so one method covering
both would be a parameter list describing which of the two it was pretending
to be.

### Verification

Nothing in the offline suite touches the `*DB()` methods, so all of them were
exercised against the real database inside a transaction, asserting the
behaviour that is easy to lose in a refactor and invisible afterwards:

- exactly **one** history row per operation (the first draft logged twice on
  an upsert, because the shared update helper logged and so did its caller --
  the helper now writes and leaves logging to the caller)
- an upsert does **not** reassign `user_id` or `active`
- `loadDB` refuses a row belonging to another user
- serialized `ns`/`tech` survive the round trip
- `deleteDomainDB`/`restoreDomainDB` queue a `reminder` row; the contact
  equivalents do not
- a contact that is still an active domain's registrant is **not**
  deactivated, while an unused one is

## Phase 6 — `changes` bitmask → dirty set ✅

`Net\EPP\ChangeTracking` replaces the integer mask with a set of changed field
names. `markChanged()`, `changed()`, `hasChanges()` and `changedFields()` are
what the rest of the code says now, instead of `$this->changes & 2048`.

The clearest gain is the composite tests. Contact's address block was guarded
by `$this->changes & 508` -- seven bits ORed together, meaning "any of the
address fields", explained nowhere. It reads `$this->changed(...self::ADDRESS_FIELDS)`.

`Domain::updateDB()`'s fourth argument is now `?array` rather than `?int`.

### What this did *not* fix

The plan expected it to remove the capture-before-`update()` dance, and it does
not: `update()` still clears the set once the registry has accepted the change,
so a caller that then wants to persist the same change locally still has to
take a copy first. The representation was never the reason for that -- the
lifetime is. The argument is documented rather than removed.

## Phase 7 — Issues found along the way

Things discovered while doing the above that are real but out of scope where
they were found. Not busywork: each is a defect with a known reproduction.

### 7.1 Auth failures return HTML, not JSON — ✅ *fixed*

Slim's stock error handler rendered an HTML page carrying the exception
message, file, line and stack trace. Two problems from a JSON API: every
client of `/v1/*` had to special-case a content type it never asked for, and
an unauthenticated request was enough to get the server's file paths and call
stack back.

`Helpers::registerMiddleware()` now installs a JSON error handler — an
`HttpException` keeps the status the route intended, anything else is a 500
whose message is replaced with a generic one. Error details are off unless
`EPPITNIC_DEBUG` is set, read from the environment rather than `settings`
because an unreachable database is exactly when the handler runs.

`tests/Http/ErrorResponseTest` dispatches real requests through the same
middleware stack `public/index.php` builds, and asserts JSON, the right
status, no internals in the body, and that CORS headers survive on errors.

### 7.2 extdom-2.0 poll messages were not recognised — ✅ *fixed*

`Session::parsePollReq()` understood only the extdom-1.0 generation, so newer
documents parsed as `unknown` with an empty domain. 330 messages in the live
queue were affected — 230 `dnsErrorMsgData`, 90 `dnsWarningMsgData`, 10
`delayedDebitAndRefundMsgData` — and `messages`.`domain` is what
`PollProcessor`, the DNS-sync queue and the reminders view key off, so a DNS
validation failure reached nobody.

Both generations are handled now. All 10,956 real messages in the queue
classify, none as `unknown`.

`messages`.`type` carries one value per extension element the schemas declare,
listed in `Session::POLL_MESSAGE_ELEMENTS`. `SessionPollCoverageTest` reads
`xsd/` and fails when the registry declares a message type we do not handle,
when we claim one it does not declare, or when the extension schemas change at
all.

`unknown` is kept rather than renamed to `other`: with the declared set fully
covered it is close to unreachable, and renaming would churn an existing
column value for no gain.

The ~330 historical `messages` rows still hold the `type` and `domain` they
were stored with. `bin/eppitnic doctor reparse-messages` re-derives both from
`msgqueue`, rewriting only those two columns and firing no side effects — no
reminder rows, no DNS-sync events for years-old failures. `--dry-run` reports
what would change.

### 7.3 Parsers walked responses they had not received — ✅ *fixed*

SimpleXML answers a missing child with an empty element, so a chain like
`->response->resData->children($ns['domain'])->infData->name` returns empty
strings and raises warnings rather than failing, and `count()` on the result
is fatal. Six such sites were found one at a time — the poll parser, the login
credit read, both `fetch()` methods, both `check()` methods.

`AbstractObject` now rejects an unparseable body before any parser runs, and
`responseData()` / `responseExtension()` give a parser its payload or null.
Every parser goes through them. `Contact::fetch()` requires the `postalInfo`,
since a `contact:infData` without one hydrates a row of empty strings, and
`Domain::transferStatus()` returns strings rather than SimpleXMLElements.

`MalformedResponseTest` drives all 13 parsers with 9 shapes of wrong answer,
asserting no PHP diagnostic anywhere, never a success for an unusable body,
and a failure from the parsers that need a payload. A payload-less success
stays legitimate for `delete`, `logout` and the rest, which answer that way.

### 7.4 A database ahead of the code fails confusingly — ✅ *fixed*

`Config::migrate()` loops `while ($current !== SCHEMA_VERSION)`, which cannot
go backwards, so a database stamped newer than the code reported "No migration
found to bring the schema from version X to Y" — a missing file, apparently,
rather than the truth. It now compares the two first and says which is ahead.

### 7.5 Registry password rotation could lock the installation out — ✅ *fixed*

The credential lives in two places, the registry's account and the `epp`
setting, and whichever is written second decides what an interrupted change
costs. It used to be the local one, so a crash after the registry accepted the
new password left this installation holding a credential the registry no longer
had — recovered by printing the password to the cron log, which is a worse
place for it than the database.

`RegistryPasswordChange::apply()` now records the candidate as `pendingPassword`
*before* sending it, and promotes it once the registry accepts. Both callers
use it — the reminder-driven rotation and `POST /v1/session/change-password`,
which had the same ordering.

An interrupted change leaves both passwords on disk, and
`RegistryPasswordChange::reconcile()` settles it by asking the registry which
one it accepts: the candidate first, since trying the old one first and having
it refused would discard a candidate that may be live. Neither working is left
alone — that is an account problem, not a rotation problem, and discarding the
candidate there would throw away the answer. The cron job reconciles before
each rotation; `eppitnic doctor epp-password` does it on demand, and
`GET /v1/session/epp` reports `rotation_pending`.

`PasswordRotationTest` drives each outcome against a fake registry that accepts
one password and refuses the other, including the ordering itself: the
candidate must be readable from the settings table at the moment the change is
sent.

### 7.6 `check()` sentinel return values — ✅ *fixed*

`Domain::check()` / `Contact::check()` returned `array|bool|int` with `-1`/`-2`
for "not answered", so every caller carried the sentinel table in its head —
and `doctor inactive-domains` got it wrong, reading a failed check as
`available === false`, i.e. "the registry still holds this domain". That is the
false report the command exists to avoid.

Both now return `CheckResult`: `answered()`, `available(?string)`,
`reason(?string)`, `all()`, `availableNames()`. The two also returned
differently shaped arrays — `Domain`'s entries had `available`/`reason`,
`Contact`'s were bare booleans — and now answer identically.

### 7.7 `AbstractObject::$result` is untyped — ✅ *fixed*

A loose `array|null` keyed by four strings, declared `// HTTP response string`,
which it had not been for some time. `Client::sendRequest()` now returns
`HttpResponse` (`body`, `code`, `headers`, `error`, plus `ok()`), and `$result`
is typed `?HttpResponse`.

### 7.8 Legacy `__SERIALIZED:` storage envelope — ✅ *fixed*

`responses`/`msgqueue` rows written by the 6.x codebase are `__SERIALIZED:` +
base64(serialize($string)); current code writes the body plainly. This was
listed as latent, but it is not: `doctor reparse-messages` reads
`msgqueue`.`sv_httpdata`, and in the live database *every* one of the 21,705
rows carries the envelope.

Decoding is centralised in `StoredPayload::decode()`, so reads work against
either generation permanently — an installation that never cleans up must keep
working. A damaged envelope returns null rather than an empty string: an empty
body reads as "the registry said nothing", which is a different and wrong
conclusion.

The envelope is deprecated rather than merely tolerated. Nothing writes it, all
six affected columns carry a schema comment saying so, and
`eppitnic doctor normalize-payloads` strips it — opt-in, `--dry-run` first,
batched by id, and rows that cannot be decoded are reported and left alone,
since a damaged envelope is still the only copy of whatever it holds.

Six columns, not the one that was noticed first: `transactions`.`cl_trdata`,
`responses`.`sv_httpdata` / `sv_httpheaders` / `extvaluereason`, and
`msgqueue`.`sv_httpdata` / `sv_httpheaders`. In the live database four of them
held enveloped rows — 22,485 in total.

The envelope holds one of two things, which the first cleanup run found the
hard way: a body is a serialized *string*, but `sv_httpheaders` is a serialized
*array*, the response headers as a field => value map, where current code
stores the raw block the server sent. Rejecting anything not a string left all
532 such rows undecodable. `decode()` now renders the map as a header block —
no status line, because 6.x kept only the fields and inventing one would be
making up what the server said, and field names keep the lower case they were
captured in.


## Phase 8 — file tree

`Net/EPP/`'s top level had become the place things went when they had nowhere
else: the protocol classes, three value types, two traits, `Config`, and an
896-line `Helpers`. `Helpers` was the cause rather than a symptom — it was the
class everything imported, so anything that did not fit went there, and the top
level collected whatever was too big for it.

Done in three commits, each with the suite green:

**8.1 Helpers, split.** Into `Api\Json`, `Api\Auth`, `Api\Middleware`,
`Api\ClientIp`, `Service\EppSession`, `Service\RegistryPasswordChange`,
`Persistence\Changelog`, `Support\Validate` and `Support\Csv`. Methods lost
the prefixes that only disambiguated inside one class — `jwtRequireAdmin()` is
`Auth::requireAdmin()`. `jumpBOM()` and `BOM` went too; nothing called them.

`Api\Json` rather than `Api\Response` because every route file already imports
PSR-7's `ResponseInterface` under that name.

**8.2 Grouped by kind.** `Epp/` for what speaks the protocol,
`Epp/Transport/` for the interface and its three implementations (including the
dry-run one that was in `Cli/`), `Persistence/`, `Service/`, `Api/`, `Cli/` with
its 32 verbs in `Cli/Command/`, `Support/`.

`IT/` is gone. It promised a country axis that does not exist — extdom, extcon
and extepp are baked into `Session` and `Domain`. `PollProcessor` left it for
`Service/`, being an orchestrator rather than a protocol object.

Two vestigial requires went with the move: `Client` required composer's
autoloader and `AbstractObject` required `constants.php`, both by counting
`../..` hops. A class inside a composer-autoloaded package cannot need to load
composer. Remaining path arithmetic goes through `EPPITNIC_ROOT`.

**8.3 `src/` and `Eppitnic\`.** Two directories carried one meaning, and the
namespace said "Net" because PEAR did. `routes/` became `src/Api/Routes/`,
inside the tree rather than reached by `../routes` from `public/index.php`;
the files still register closures rather than declare classes, so they are
still required, but from `EPPITNIC_ROOT` and in one loop.

**8.4 One cycle, closed.** Mapping the imports between the new layers showed
`Epp/` importing `Service/` four times while `Service/` imported `Epp/` eleven
times. Three of the four were the password generator, which `Client`, `Contact`
and `AbstractObject` need for clTRIDs, handles and authinfo codes — so the
protocol layer depended on the service layer and back again. It moved to
`Support/` as `PasswordGenerator`, beside `Validate` and `Csv`: all three are
dependency-free, and `Epp → Support` points downward. The fourth was a docblock
mention that had picked up an import it did not need.

`Service/` now means one thing, orchestration, and the graph is strictly
layered — `Support/` and `Persistence/` depend on nothing, `Epp/` on those,
`Service/` on `Epp/`, and `Api/` and `Cli/` on everything below.

## Phase 9 — the audit trail records more than changes

`GET /v1/session/epp/credentials` hands out the shared registry credential, and
a disclosure that leaves no trace is not one anybody can review later. Writing
that trace needed the table to stop being only about changes.

`changelog` is now `history`, `Persistence\Changelog` is `Persistence\History`,
and `GET /v1/changelog/...` is `GET /v1/history/...`. Its enums gained the two
values a non-change needs: `object` = `security` and `action` = `read`.

A `security` row records the event, the acting user, the client IP and the
request headers. It does not record the password — the log is read by more
people, and far more casually, than the credential was shown to — and
`History::recordSecurityEvent()` replaces `Authorization`, `Cookie` and
`Proxy-Authorization` with `[redacted]`, since each is itself a live credential
and copying one into a table anyone with SELECT can read would hand out a
working session. They are kept as `[redacted]` rather than dropped, so that a
header's absence from the log is not read as its absence from the request.

`GET /v1/history/security/{id}` is admin-only. The other three object types stay
unscoped, as they always were — that is a separate gap, noted in docs/API.md.

Two faults surfaced while testing this. `EPP_PUBLIC_FIELDS` was a file-scope
`const` in a route file that is `require`d rather than `require_once`d:
loading the routes twice warns today and is fatal in PHP 9. And
`ClientIp::get()` read `$_SERVER['REMOTE_ADDR']` blind, which warns wherever
there is no connection to name — the CLI, and any synthesised request.
