# Refactoring plan

Goal: ~12,900 lines of own code down to ~7,000–7,500, one dependency dropped,
two directories retired — without changing what the registry sees, except
where what it saw was wrong.

Each phase is independently shippable and leaves `main` working. Line deltas
are estimates for our own code (`vendor/` excluded).

**Status:** Phases 0–4 complete, plus 7.1 and 7.2. Phase 5 is next.

| Phase | What | Effort | Δ lines | Status |
|---|---|---|---|---|
| 0 | Safety net | 1d | +2,000 (tests) | **done** |
| 1 | Dead code and weak randomness | 0.5d | −473 | **done** |
| 2 | Route boilerplate | 0.5d | −166 | **done** |
| 3 | `bin/eppitnic`, retire `CLI/` + `examples/` | 5d | −4,700 | **done** |
| 4 | Smarty → `DOMDocument` | 3–4d | −600 | **done** |
| 5 | Field maps and persistence | 2–3d | −260 | next |
| 6 | `changes` bitmask → dirty set | 1–2d | −40 | optional |
| 7 | Issues found along the way | ongoing | | 7.1, 7.2 done |

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

## Phase 5 — Field maps and persistence

1. **One `FIELDS` map per class.** `Contact` lists its 18 fields three times:
   `initValues()`, the bitmask `switch` in `set()`, and the 18-line ladder in
   `updateDB()`. One `const FIELDS = ['name' => 1, …]` drives all three.
2. **Shared persistence.** `storeDB`/`loadDB`/`updateDB`/`listX`/`deleteXDB`/
   `restoreXDB` are structurally identical across `Domain` and `Contact`
   (~350 lines → ~150). `Contact::storeDB()`'s upsert semantics and
   `Domain::storeDB()`'s delete-then-insert differ for documented FK reasons
   and stay as overridden hooks.

---

## Phase 6 — `changes` bitmask → dirty set *(optional)*

Replaces 18 hand-maintained flags with a set of changed field names, and
removes the `?int $changes` parameter bolted onto `Domain::updateDB()` to work
around `update()` resetting the mask on success. Only worth doing after
Phase 5, which is what makes it small.

---

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

### 7.3 A database ahead of the code fails confusingly — *low*

`Config::migrate()` loops `while ($current !== SCHEMA_VERSION)`, so a database
stamped with a *newer* version than the code knows about does not stop — it
looks for a migration away from that version, finds none, and reports "No
migration found to bring the schema from version X to Y", which reads like a
missing file rather than the truth: this checkout is older than the database.
Worth an explicit comparison and a message saying so.

### 7.4 Registry password rotation can lock the installation out — *medium*

`Helpers::rotateEppPasswordOnReminder()` writes its "attempted" timestamp
*before* the attempt and prints the new password to the cron log if it cannot
persist it. That is a defensible design, and it is documented — but the
recovery path is "an operator reads the log", and the log is where the
credential then lives in plaintext. Worth revisiting: write the new password
to the settings table *before* sending it to the registry, marked pending, and
reconcile afterwards.

### 7.5 `check()` sentinel return values — *low*

`Domain::check()` / `Contact::check()` return `array|bool|int` with `-1`/`-2`
sentinels, so every caller carries the sentinel table in its head. Fold into a
small result object during Phase 4, when both methods are being touched.

### 7.6 `AbstractObject::$result` is untyped — *low*

A loose `array|null` keyed by string. A small value object typing
`code`/`headers`/`body` pairs naturally with the Phase 0 transport interface.

### 7.7 Legacy `__SERIALIZED:` storage envelope — *low*

`responses`/`msgqueue` rows written by the 6.x codebase are
`__SERIALIZED:` + base64(serialize($string)); current code writes plain
strings. Nothing in the current codebase reads these columns, so this is
latent rather than broken — but anything added that does (a "show raw EPP
dump" view) must handle both. Either normalise the old rows in a migration or
centralise the decoding.
