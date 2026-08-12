# Refactoring plan

Goal: ~12,900 lines of own code down to ~7,000–7,500, one dependency dropped,
two directories retired — without changing what the registry sees, except
where what it saw was wrong.

Each phase is independently shippable and leaves `main` working. Line deltas
are estimates for our own code (`vendor/` excluded).

**Status:** Phases 0–2 complete, plus 7.2. Phase 3 is next.

| Phase | What | Effort | Δ lines | Status |
|---|---|---|---|---|
| 0 | Safety net | 1d | +2,000 (tests) | **done** |
| 1 | Dead code and weak randomness | 0.5d | −473 | **done** |
| 2 | Route boilerplate | 0.5d | −166 | **done** |
| 3 | `bin/eppitnic`, retire `CLI/` + `examples/` | 5d | −3,800 | next |
| 4 | Smarty → `DOMDocument` | 3–4d | −500 | |
| 5 | Field maps and persistence | 2–3d | −260 | |
| 6 | `changes` bitmask → dirty set | 1–2d | −40 | optional |
| 7 | Issues found along the way | ongoing | | 7.2 done; 7.1 next |

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
- The denylist backstop is not decoration. It caught four distinct classes of
  leak during development, including DNS zone transcripts embedded in CDATA.
  If it rejects a capture, **fix the scrubber — do not weaken the check.**

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

## Phase 3 — `bin/eppitnic`, retiring `CLI/` and `examples/`

Done before the XML rework deliberately: it removes 4,600 lines from the
surface Phase 4 must not break, and provides a driver for exercising every
EPP command by hand against pubtest while the generator is swapped.

### Structure

```
bin/eppitnic                 # entry point, dispatch + global flags
Net/EPP/Cli/Command.php      # base: option parsing, --help, exit codes, output
Net/EPP/Cli/*Command.php     # one per verb group
```

Global flags: `--verbose` (the `debug = LOG_DEBUG` every script sets by
hand), `--dry-run`, `--json`, `--user=ID`. Session setup goes through the
existing `Helpers::withEppSession()`, which already does hello/login/logout
with proper teardown — adopting it deletes the hand-rolled block from all 45
scripts that carry one.

### Verb map

Every current file has a home; nothing is dropped silently.

| Verb | Absorbs |
|---|---|
| `domain info <name…>` | `domain.php` + `GetInformation`/`GetAuthInfo` symlinks, ex. 011, 012 (`--contacts=all`), 022, 030 (`--store`) |
| `domain check <name…>` | ex. 025 (`--file=` for bulk) |
| `domain create` | `domain-DoCreate`, ex. 008, 009, 010 (`--retries=N`) |
| `domain update` | `domain.php` + `SetAdminC`/`SetAuthInfo`/`SetNameServer`/`SetTechC` symlinks, `SetNameServerPerDomain` (`--file=`), `ComplexContactUpdates`, `SyncTechC`, ex. 014 |
| `domain set-registrant` | `domain-SetRegistrant`, `SetEmailAllRegistrants`, ex. 015 |
| `domain status add\|rem` | ex. 017 |
| `domain delete` / `restore` | `domain-DoDelete`, ex. 013, 016 |
| `domain transfer request\|approve\|reject\|cancel` | `domain-DoTransfer`, `DoApproveTransfer`, ex. 019–021 |
| `domain import` | `domain-DoImport` |
| `domain export --source=local\|registry` | `ExportLocalToCsv`, `ExportDetailsToCsv` |
| `contact info\|check\|create\|update\|delete` | four `contact-*` scripts, ex. 003–007, 023, 029 |
| `contact prune-duplicates` | `contact-DoDeleteDupplicates` (`--prefix=DUP`) |
| `contact fix-email-privacy` | `contact-FixEmailPrivacy` |
| `poll once\|drain\|list` | `session-GetMessages`, ex. 026–028 |
| `session hello\|credit\|change-password` | ex. 001, 018 |
| `user create` | `user-DoSetup` |
| `config migrate` | `config-DoMigrate` |
| `doctor ownership\|inactive-domains\|reparse-messages` | `CheckOwnershipCoherence`, `GetLocallyInactiveDomains`, plus the 7.2 backfill |

52 files → ~18 subcommands in 8 groups.

### Shared logic

Three flows exist in both a route closure and a CLI script and have already
diverged: **import** (`routes/domain.php` vs `CLI/domain-DoImport.php`),
**create-or-transfer**, and **owner change** (~100 lines of business logic in
a closure). Extract exactly those into `Net/EPP/Service/DomainService.php`
**on demand, as the CLI needs them** — not as a speculative up-front service
layer. Leave every other route alone.

### Docs

- `docs/COOKBOOK.md` — 8–10 instructive snippets extracted from `examples/`
  before it is deleted, so the library remains documented for third-party
  consumers (this package *is* a library; `examples/` was its only API
  documentation).
- Move `API.md` into `docs/`.

### Verification

Port one verb group at a time. For each, run the old script and the new
subcommand side by side against pubtest and diff the output. Delete the old
file only once its replacement is proven; keep `CLI/` on disk until the last
verb lands.

---

## Phase 4 — Smarty → `DOMDocument`

1. **Stop inheriting.** `Client extends Smarty` → no parent. Removes
   `_ensureWritableDir()`, the `clearAllAssign()` override, and the four
   `smarty->*_dir` config resolutions.
2. **Add `Net/EPP/XmlBuilder.php`** — `epp()`, `command()`, `extension()`,
   `clTRID()` primitives over `DOMDocument`, one small method per command.
   The `assign('tech_add_num', 0)` padding in `Domain::update()` and
   `updateRegistrant()`, and `Contact::update()`'s name/value marshalling,
   have no analogue in a builder and simply disappear.
3. **Fix the escaping inversion.** Values are HTML-escaped in `set()`, so
   escaped data is stored and returned by `get()`, while templates emit
   `{$var}` raw; `Contact::duplicate()` has to `html_entity_decode()` to
   compensate. DOM escapes once, at serialization.
   **Requires a data migration:** existing rows hold HTML-escaped values
   (`Müller &amp; Co`). Ship a one-shot fixer and bump `SCHEMA_VERSION`.
4. **Delete** `templates/`, `smarty/`, the `smarty` settings key and its
   migration block, the `.gitignore` stanza, and `smarty/smarty` from
   `composer.json`.
5. **Wire XSD validation into `--verbose`/debug mode**, not the hot path.

Verified by the Phase 0 request snapshots plus one live pubtest round per
command.

---

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

### 7.1 Auth failures return HTML, not JSON — *high*

`Helpers::registerMiddleware()` calls
`$app->addErrorMiddleware(true, true, true)`. A thrown
`HttpUnauthorizedException` therefore renders as a full HTML page with a
stack trace, from a JSON API. Two problems in one:

- **Contract:** every client of `/v1/*` gets `text/html` on 401/403/404 and
  has to special-case it. Confirmed by dispatching unauthenticated requests
  against every protected route.
- **Disclosure:** `displayErrorDetails` is on, so responses carry file paths,
  line numbers and a full stack trace. That is a production information leak.

Fix: a custom error handler rendering `{"error": ...}` with the right status,
and error details driven by an environment/setting rather than hard-coded
`true`.

### 7.2 extdom-2.0 poll messages were not recognised — ✅ *fixed*

`Session::parsePollReq()` understood only the extdom-1.0 generation. Measured
by re-parsing the whole live queue (16,485 stored responses carrying a
`<msgQ>`), before → after:

| type | before | after |
|---|---|---|
| `unknown` | 5,859 | 5,529 |
| `dnsErrorMsgData` | 1 | 231 |
| `dnsWarningMsgData` | 0 | 90 |
| `delayedDebitAndRefundMsgData` | 0 | 10 |

330 messages recovered, all now carrying their domain.

The remaining 5,529 `unknown` are **not** a defect: they carry a `<msgQ>`
title and nothing else — no extension, no `resData`, no domain anywhere in
the document (28 distinct fixed strings, e.g. "autoRenewPeriod is expired").
There is nothing in them to recover. `unknown` is a poor *label* for them,
but changing it would rewrite the meaning of an existing column value.

**Method note worth keeping.** The first estimate of this bug's size (344)
came from `messages.type`, which records what the parser said *at the time
each message was polled* — some rows date from 2012. It is a log of
historical parser behaviour, not of what today's code does. Re-parsing the
raw `msgqueue` bodies with the current parser is the only way to get an
honest number, and it is cheap.

**Left undone deliberately:** the ~330 historical `messages` rows still hold
the old `type`/`domain`. Re-parsing them from `msgqueue` would make the
poll-queue view coherent, and is safe as long as it only rewrites those two
columns and fires no side effects (no reminder rows, no DNS-sync events for
years-old failures). Best done as a `doctor reparse-messages` verb in Phase
3 rather than as a throwaway script now.

### 7.3 Registry password rotation can lock the installation out — *medium*

`Helpers::rotateEppPasswordOnReminder()` writes its "attempted" timestamp
*before* the attempt and prints the new password to the cron log if it cannot
persist it. That is a defensible design, and it is documented — but the
recovery path is "an operator reads the log", and the log is where the
credential then lives in plaintext. Worth revisiting: write the new password
to the settings table *before* sending it to the registry, marked pending, and
reconcile afterwards.

### 7.4 `check()` sentinel return values — *low*

`Domain::check()` / `Contact::check()` return `array|bool|int` with `-1`/`-2`
sentinels, so every caller carries the sentinel table in its head. Fold into a
small result object during Phase 4, when both methods are being touched.

### 7.5 `AbstractObject::$result` is untyped — *low*

A loose `array|null` keyed by string. A small value object typing
`code`/`headers`/`body` pairs naturally with the Phase 0 transport interface.

### 7.6 Legacy `__SERIALIZED:` storage envelope — *low*

`responses`/`msgqueue` rows written by the 6.x codebase are
`__SERIALIZED:` + base64(serialize($string)); current code writes plain
strings. Nothing in the current codebase reads these columns, so this is
latent rather than broken — but anything added that does (a "show raw EPP
dump" view) must handle both. Either normalise the old rows in a migration or
centralise the decoding.
