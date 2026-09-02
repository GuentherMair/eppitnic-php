# Changelog

## Version 7.0.0
PHP 8.5 migration: compatibility fixes, cleanups, and typo fixes across all
folders. The project imports all dependencies through composer and requires
PHP >=8.1. Table prefixes ('tbl_') were also dropped.

The legacy PHP/Smarty/jQuery web interface has been retired and replaced by a
JSON/REST API (`public/`, routed via Slim) intended for a new frontend
client. Authentication moved from PHP sessions to bearer-token JWTs
(`firebase/php-jwt`), with optional TOTP-based MFA and long-lived API tokens
for scripted access; passwords are now hashed with `password_hash()` instead
of MD5.

`Net_EPP_StorageDB`/`Net_EPP_StorageInterface` have been removed. Contact,
Domain and Session persistence now talk to RedBeanPHP's `R::` facade
directly, and configuration moved out of `config.xml`: the database
credentials live in `config/config.php` (the one thing that must be a file,
since it is needed to reach the database at all) and everything else in the
`settings` table, read through `Eppitnic\Config`. `eppitnic config migrate`
converts an existing `config.xml` into both. As part of this, DNS-sync
notifications end up in, and will be waiting to be consumed from, the
`reminder` queue.

Invoicing has been removed from this codebase along with the `InvoicingCDR`
class: the `/v1/accounting` routes, the `accounting` table and the
`users.billing_id` column are all gone. It will be reimplemented differently.

Domain scoping is now uniform: every domain route filters non-admins by the
domain's own owner (`domains.user_id`), and pending transfers by whoever
requested them (`transfers.user_id`). `GET /v1/domains/expiring` previously
filtered by the registrant contact's owner instead, so a domain whose
registrant belonged to another user was missing from its owner's renewals list
and present in that other user's — where every write route refused it.
Relatedly, a domain's registrant must now be a contact the caller owns
(`POST /v1/domains`, `POST /v1/domains/{name}/registrant`), which is what kept
those two notions of ownership able to drift apart in the first place.

The registry's `passwdReminder` poll messages are now acted on rather than
merely stored: `eppitnic poll process` rotates the shared EPP password when one
is outstanding, rate-limited to one attempt per 24 hours by the `epp` setting's
new `lastPasswordUpdate` timestamp. The new password is recorded locally before
it is sent, so a run interrupted mid-change can be settled afterwards by asking
the registry which password it holds. `Eppitnic\Support\PasswordGenerator`
generates it from a mixed character set rather than hex, which spent 16
characters -- EPP's ceiling for the credential -- on 64 bits. Domain authinfo
codes are drawn the same way, and every other random credential (API tokens,
the JWT signing key, contact handles, transaction ids) now comes from the same
class, in whichever form its consumer actually needs. The unused `debug`,
`epp.passwordexpirydays` and `epp.passwordexpirynext` settings, and the unused
`users.dns` column, have been dropped.

`Domain->get('tech')` now always returns an array (keyed handle => handle).
It previously returned a bare string whenever the domain had exactly one
technical contact — the common case — which silently corrupted callers that
handled the result uniformly: `array_keys((array) $domain->get('tech'))`
evaluated to `[0]` instead of the handle, so the REST API reported a tech
contact of `0` and update diffs computed from it never removed the outgoing
contact. Callers that special-cased the string return can drop that branch.

A `Contact` built without an explicit authinfo now has one. Its constructor
generated a code and then called `initValues()`, which blanks every entry in
`FIELDS` — and `authinfo` is one — so every contact created without one was
sent to the registry with an empty `<contact:pw>`: a transfer credential
shipped blank. `Domain`, whose `initValues()` assigns each field by name, was
never affected. The generated default is not treated as a change, so `update()`
still sends an authinfo only when one was actually asked for.

Nothing rejected the empty one, which is why it went unnoticed for so long. The
`authInfo` element is mandatory in `contact:create`, but its content is
`eppcom:pwAuthInfoType` — an unrestricted `normalizedString`, so an empty value
validates and the registry accepts it. The familiar min-6/max-16 rule is
`epp:pwType`, which governs the `<login>` password and nothing else; several
docblocks here confused the two and now say which is which.

Everything runnable now lives behind one entry point, `bin/eppitnic`: the
`CLI/`, `examples/` and `cronjobs/` folders are gone, absorbed into verbs.
The two scheduled jobs are `eppitnic poll process` and `eppitnic pdns sync` —
see "Scheduled jobs" in [INSTALL.md](INSTALL.md) for crontab lines.

The `changelog` table is now `history`, because not everything it records is a
change: it gained a `security` object type and `read`, `login` and `denied`
actions, so that an admin retrieving the shared registry credential through the
new `GET /v1/session/epp/credentials` is recorded along with the address and
headers the request arrived with. The password itself is never written, and
`Authorization`, `Cookie` and `Proxy-Authorization` are stored as `[redacted]` —
the log is read by more people than the credential was shown to. `user_id`
became nullable, since a login attempt at a username that does not exist has
nobody to attribute it to.

Login attempts are recorded the same way and rate-limited from those rows:
past `login_ratelimit.max_failures` within `login_ratelimit.timespan` seconds,
`POST /v1/users/authenticate` answers `429` with a `Retry-After` header. The
window slides, so a block lifts itself with no lock to clear. Failures are
counted per network rather than per address — IPv4 `/24` and IPv6 `/48`, since
an IPv6 customer is handed an allocation and a per-address limit would stop
nobody.

That work uncovered an authentication bypass predating it: `ClientIp` preferred
`X-Forwarded-For` over the connecting address with no check on who sent it, and
`safe_networks` skips MFA for addresses it recognises — so **any request
carrying `X-Forwarded-For: 127.0.0.1` skipped MFA**. The header is now believed
only when the peer that actually connected is listed in the new
`trusted_proxies` setting, which defaults to empty; **set it if the API runs
behind a reverse proxy**, or every client shares one rate-limit bucket and none
matches `safe_networks`. `ClientIp` was also IPv4-only throughout
(`FILTER_FLAG_IPV4`, `ip2long()`); matching is now done on packed bytes, so
both families work, and an address is never inside a range of the other family.

The trail is readable over the API: `GET /v1/history` with filters for
`object`, `object_id`, `action`, `network`, `acknowledged`, `since` and `until`,
and `POST /v1/history/{id}/acknowledge` to mark a security entry reviewed —
recording who and when rather than a flag, since for a security log who
dismissed an alert matters as much as that somebody did. Exposing it meant
fixing what was already there: `GET /v1/history/{object}/{object_id}` answered
for any object anybody named, and a `users` snapshot carries an email address
and an admin flag, so any valid token could read every user's history.
`History::visibleTo()` is now the single place that decides — an admin sees
everything, everyone else the history of objects they own — and filters narrow
what is visible without ever widening it.

The cookbook's contact-creation example was wrong in two ways that only a live
registry reveals: it withheld consent to publication for an entity type that
may not (refused with `2308` / `8028`), and its registration code
`01234567890` fails the partita IVA checksum the registry verifies (`2004` /
`8027` — the check digit should be `7`). Both are corrected and the rules
stated. The same placeholder remains in the test fixtures, which compare
generated XML and reach no registry.

Login passwords now have to meet a rule, where previously they did not have to
meet any: `password_hash()` was reached from four places and none of them looked
at what it was given, so a single character was stored happily. At least 12
characters with a lower-case letter, an upper-case letter, a digit and one
character that is neither — taken from the only complexity the codebase already
expressed, `PasswordGenerator::forRegistry()`'s four character classes. Its
length is deliberately not taken from there: 16 is EPP's ceiling for a registry
credential, a protocol constraint on that one field with no bearing on a password
this application hashes itself. Enforced by `Support\PasswordPolicy` at the three
routes that set a password and in `Persistence\User::create()`, and stated as
data so the installer can show it before anything is typed. **Existing passwords
are not affected** — the rule applies where a password is set, never where one is
checked, so nobody is locked out of an account created before it.

`eppitnic selftest run` exercises the library against the registry's public
test endpoint: it registers, reads back, changes and deletes real contacts and
a real domain, checking each answer against what was sent, and reports one line
per operation. `--domain=NAME` registers a name you choose rather than a generated one, so its
zone can exist beforehand and the nameservers given with `--ns` actually pass
the registry's checks — with a generated name they never can, and the run's
nameserver assertions are inert. In that mode it pauses ten seconds after each
delegation change to let those checks finish. It refuses to run anywhere else — the check is an allowlist of
test endpoints made before a session is opened, with no flag to defeat it.
Because nic.it keeps a contact linked to a domain until that domain is purged,
30 days after its delete, the contacts that were on the domain when
it was deleted cannot be removed on the day; those attempts are reported as
deferred rather than failed, and `eppitnic selftest reap` clears them later
from the note the run leaves in `var/selftest/`. Nothing else waits — a
leftover domain, or a contact from a run that failed before creating one, is
deleted on sight.

WSDL support has been dropped.

First-run setup no longer requires a terminal. `Config` throws
`Setup\ConfigMissing` when `config/config.php` is absent, rather than
prompting from inside `connect()` the way it used to when attached to a TTY;
`eppitnic setup` (interactive, or scriptable via `--db-name=` and similar
flags) and a REST/HTML installer (`GET/POST /v1/setup`, `POST
/v1/setup/verify`, `public/setup.html` — served automatically from
`public/index.php` for as long as `config/config.php` doesn't exist) both
drive the same `Setup\Installer`, which probes candidate credentials with a
throwaway PDO connection before committing to them and creates the first
admin account, since `POST /v1/users` needs an admin token nothing yet holds.
Nothing in PHP used to apply `config/mariadb-schema.sql` at all — an empty
database fell into the schema-versioning code's legacy `'060700'` baseline and
failed on the 6.7-to-7.0 upgrade's first `ALTER TABLE`; `Setup\SchemaInstaller`
now tells an empty database apart from an existing installation before
`Config`'s migration chain ever runs. `config/config.php` is written last,
once every other step has succeeded, so a setup that fails partway through is
simply re-run rather than left half-configured.

`docker compose up -d` now brings up a whole instance: nginx and php-fpm in
one container (`Dockerfile`, `docker/`), a scheduler sidecar running the same
image for `poll process`, and a `eppitnic-cli` service for one-shot verbs like
`setup` or `doctor ownership` — see [DOCKER.md](DOCKER.md), and
`compose.multi.yaml.sample` for running more than one instance on the same
host. `config/config.php` and the self-test notes move outside the image
entirely, to whatever `EPPITNIC_CONFIG_DIR`/`EPPITNIC_VAR_DIR` point at
(`Setup\ConfigFile`, `Selftest\Leftovers`), so a bind mount never has to shadow
`config/` itself — the schema files and `constants.php` living there are read
on every request and every `Config` construction. `composer.json` now also
declares `ext-pdo_mysql`, the one extension the image needed to add on top of
`php:8.5-fpm-alpine`; every DSN this codebase builds is `mysql:` regardless, so
its absence is now a Composer error instead of a runtime one.

That image was then run against a fresh server, which is where the rest of it
was found. The `web` container publishes on `127.0.0.1:8080` rather than `:80`,
so port 80 is left for a host webserver — either the existing
`config/nginx-vhost.sample`/`config/apache-vhost.sample` (the former renamed
from `config/nginx.sample`, to pair with the Apache one) standing alone on a
bare-metal install, or the new `config/nginx-proxy.sample` /
`config/apache-proxy.sample`, which terminate TLS and forward to the
container. Both proxy samples call out the trap that makes them worth having:
a request proxied to a published container port does not arrive with a source
address of `127.0.0.1`, so `trusted_proxies` set to that value silently
ignores `X-Forwarded-For` and puts every client in one rate-limit bucket.
Only the `web` service declares `build:` now — three services building the
same `Dockerfile` to the same tag race on the export step, even under
Buildx — and the image no longer runs `docker-php-ext-enable opcache`, which
this base image compiles into the core rather than shipping as a module.

Two failures in that image made the difference between "down" and "answering
wrongly", which is the worse of the two. `php:8.5-fpm-alpine` ships pool files
(`docker.conf`, `zz-docker.conf`) defining an incomplete `[www]` pool
alongside this image's own `[eppitnic]` one, and php-fpm refuses to start at
all if *any* pool lacks a `user` while running as root — so the container came
up with nginx alone, returning 502 indefinitely, because `docker/start-web.sh`
used a bare `wait`, which blocks until *every* child exits rather than the
first. Both pool files are now removed at build time, and `start-web.sh` polls
both children and takes the survivor down with the casualty, so
`restart: unless-stopped` actually restarts.

`Epp\Transport\Curl` now throws instead of calling `exit()` when its cookie
jar or debug file is not writable. It is reached from `Client`'s constructor,
inside a request: exiting wrote a line of plain text over whatever the route
was about to answer, so a JSON client got neither JSON nor a status code. As a
`\RuntimeException` it lands in the handling both tiers already have for an
unusable registry connection — 502 from the API, `LOGIN_FAILED` from the CLI.

New CLI verbs for the settings that previously had no route but a SQL client:
`config show [<key>]` prints the `settings` table, redacting `jwt_psk` and the
EPP credential through the same allow-list `GET /v1/session/epp` uses;
`config epp-server [production|test|toggle]` moves between `https://epp.nic.it`
and `https://epp.pubtest.nic.it`; `config epp-set <field> <value>` sets
`interface`, `lang`, `cl_trid_prefix` or `username`; and `config epp-password`
changes the shared registry credential, or with `--force` adopts one already
valid there (verified by a real login first, never written on the strength of
being typed twice). The group lists `show` first and the one-time `migrate`
last.

Those checks now also apply to first-run setup, which had none: `epp_username`,
`epp_password` and `epp_cl_trid_prefix` were stored exactly as given, so the
browser installer could seed a username longer than `eppcom:clIDType` allows,
or a password over `epp:pwType`'s 16 characters, and the mismatch only showed
at the next `<login>` — with an error from the registry, about a value entered
days earlier. `Support\Validate::eppField()` is the one place holding those
rules now, shared by setup, both `config epp-*` verbs and
`POST /v1/session/change-password`, and setup runs them before it creates the
admin user, so a rejected install is still re-runnable. That endpoint also
stamps `lastPasswordUpdate` like every other deliberate change: it feeds the
once-per-24h guard in `RegistryPasswordChange::rotateOnReminder()`, so leaving
it untouched let an automatic rotation start moments after an operator had
changed the credential by hand.

`contact create` no longer dies with a fatal error when `--authinfo` is
omitted — it read a protected property from outside the class to decide
whether to generate one — and `Epp\Contact::setEntityType()`'s range check
was `($tmp < 1) && ($tmp > 7)`, which no value satisfies, so nothing was ever
rejected. `contact create --help` now spells out the entity types 1–7, and the
expected format for `--province`, `--voice`, `--countrycode` and
`--nationalitycode`; `domain create --help` states that `--admin`, `--tech`
(1–6) and `--ns` (2–6) are required and how many of each the registry accepts.

## Version 6.7
Fixed a minor bug which kept the `Domain->storeDB(...)` method from removing an
existing domain name prior to saving the updated record.

Initialize the current date for `crDate` in `initValues()` and current date + 1 year
for `exDate`.

## Version 6.6
Commented out `$slast` in `idna_convert.class.php` (was never used anyway and PHP
complained about creating it as a dynamic property).

## Version 6.5
Replaced obsolete/unmaintained phpwhois-4.2.2 with phpwhois-kevinoo-6.3 while
adding `libs/phpwhois/whois.main.php` as a wrapper in order to keep the previous
interface.

## Version 6.4
Some adjustments to make the library and its components compatible with newer
PHP versions (8.x).

## Version 6.3
If a message was not successfully stored by `poll()` in `Net/EPP/IT/Session.php`,
return `FALSE`.

## Version 6.2
This update includes support for the EDU SLD – please mind the schema update
to `tbl_contacts`!

## Version 6.1
Some updates to the database schema.

## Version 6.0
All code and examples updated to be usable with PHP 7. Some code cleanup was
done and examples divided into more generic CLI tools and "examples".

Support added for use with IT-NIC's upcoming DNSSEC implementation.

> **IMPORTANT:** as part of the code cleanup, Smarty 2 and ADOdb have been removed.
> This now implies usage of a PHP release greater than or equal to 5.3!

## Version 6.0-beta1
All code and examples updated to be usable with PHP 7. Some code cleanup was
done and examples divided into more generic CLI tools and "examples".

Configuration prepared for use with IT-NIC's upcoming DNSSEC implementation.
Code and DB support for DNSSEC is still missing but should be there for the
final 6.0 release.

> **IMPORTANT:** as part of the code cleanup, Smarty 2 and ADOdb have been removed.
> This now implies usage of a PHP release greater than or equal to 5.3!

## Version 5.3
Contact objects now allow for `entitytype = 0` (for example a TECHC without
any details except address information).

`infContacts` support added to domain object and WSDL interface. This includes
examples.

Minor issue related to default userid assignment to domain object fixed, which
mainly only relates to the user (agent) management provided by the graphical
interface.

## Version 5.2
Examples folder cleaned up. Modified the add/rem methods used by the Domain
object in order to allow modification when there are already 6 NS or tech
contacts assigned to a domain.

## Version 5.1
Bugfix which relates to `extValueReasonCode` in `Net/EPP/AbstractObject.php` (this
value has been placed underneath the extepp namespace by IT-NIC's 2.0 release).

## Version 5.0
This version now supports IT-NIC's 2.0 release.

It also fixes the handling of the `<debugfile></debugfile>` configuration
parameter and the entity-encoding of the authinfo parameter used in domain
transfer requests.

## Version 4.6
Reset method for HTTP connections implemented in the Client class.

phpwhois-4.2.2 added to lib folder (to be used with webinterface).

If a DNS record is added which already exists, but has a new IP address
associated with it, then automatically remove the old configuration and add
a new one.

## Version 4.5
Updated the DomainUpdate WSDL function so the amount of contacts and ns records
to add/remove is not static (to be divided by semicolons).

## Version 4.4
Added missing userID field to `tbl_transfer`. Renamed apache configuration file
for WSDL to `docs/apache-vhost-wsdl`.

Fixed a typo in `Net/EPP/IT/WSDL/contact.info.php` (space character after name
column field removed).

## Version 4.3
Now checking PHP version in order not to break PHP versions older than 5.2.3
when using `htmlspecialchars` (4th parameter was only added in v5.2.3).

Added support for delayedDebitAndRefundMsgData polling type data. Thanks
Angelo for the input!

## Version 4.2
Moved the AbstractObject object up into Net_EPP space.

The session file location can now be specified manually.

Added an `account.poll.ack.php` example for WSDL and changed the Poll method
to use ACK as default parameter. PollAll now works only with ACK.

Switched to IDNAbis-like handling of German SZ in idna_convert, as IT-NIC will
now support this.

## Version 4.1
Updated the WSDL interface to version 1.2 (added a DomainChangeRegistrant
method and removed the registrant parameter from the DomainUpdate method).

## Version 4.0
Some smaller fixes and updates (DB layout for webinterface, which now supports
automatization of TECH-C + DNS updates).

Added an example that shows how to extend the DB driver for searching
serialized DB fields.

Support for multiple Smarty versions added. The library now comes with a
version 2 and a version 3 release. Depending on your `PHP_VERSION_ID` the correct
one to be used is selected.

The set method for contacts now makes use of `htmlspecialchars()` in order to
convert ampersands and other stuff.

Big switch from the PEAR class HTTP_Client to CURL. This is the main reason
for the change in major number. The current version now allows you to choose
the leaving IP/interface, which should be useful for multi-homed servers and
hosting solutions.

## Version 3.6
The `isTrue` method inside of the Contact object did a value and type check
on its argument and therefore compared only to numeric 1 instead of also
comparing to the string "1". Fixed.

Parent constructors (domain / contact objects) are now called as first thing
in the object's own constructor.

Some troubles found when using Smarty 3 with PHP 5.2 (i.e. Ubuntu 8.04 LTS).
Still undecided whether to downgrade back to Smarty 2 or not.

When updating the registrant and using the updateDB domain method, the
owning userID (agent ID) is now changed. The variable name userID has now been
changed to lowercase `userid` everywhere. This could have some impact in
different places - please pay attention to it!

You can now remove the fax value from a contact by leaving it blank.

## Version 3.5
Changed all calls to Smarty's `clear_all_assign` to `clearAllAssign`.
Calls to `set()` on objects are now doing automatic lower-case conversion.
Thanks Marcello!

The error handling function in AbstractObject now type-casts message
variables to strings (there have been some issues there).

## Version 3.4
Updated the status fields for `tbl_contacts` and `tbl_domains`, which were still
and wrongly set to tinyint. They need to be text in order to support
serialized status information (text array). Thanks Marcello!

Added displayAccounting switch for the webinterface. When set, the
webinterface will display accounting information in the same way the polling
queue is displayed.

Updated Smarty to version 3.0.6. The client-constructor now uses the
`$_file_perms` and `$_dir_perms` variables when falling back to `/tmp` for compile_dir
is active. The fallback now emits an `E_USER_NOTICE` instead of `E_USER_WARNING`.

## Version 3.3
Updated the update-contact template in order to support authinfo-updates on
contacts. They are not used but defined by the protocol, so what... :-)

Added a config.xml parameter in order to switch on/off sending email
notifications for all polling queue messages (this only affects the
webinterface implementation though).

## Version 3.2
Support for clientLock added to the domain object (can only be viewed though).

Added the idna_convert class by phlyLabs. The eppitnic-php class by itself
should support UTF-8 IDNs as long as you feed UTF-8 data to it, but the
webinterface will have to feed punycode strings to the DNS notification
script (DNS's need to run domains in punycode format).

Fixed the sendRequest method in the client class which (wrongly) always used
`utf8_encode()` on the data to be sent. This issue led to double-encoding in
cases where your data was already in UTF-8 format. For those of you feeding
data in ISO-8859-1 format the forceUTF8 configuration parameter was added.

Added an example for IDN registration (032) and fixed deriving example (011).
German SZ (ß) will be encoded as 'ss'.

creditMsgData messages in polling queue are now parsed.

## Version 3.1
A typo inside the domain object fixed. Thanks Luca!

Support for clientTransferProhibited added to the domain object. Thanks
to the guys at the registry and the new accreditation test!

Support for multiple domain states added to the domain object including
updates to all examples.

Added support for the exDate domain information. This is a small update to the
DB as well, with the big advantage that you could do credit-forecasts. This
would only be an approximation though, and you will have to take into account
how many domains you usually register in any period of time (the crDate may be
of some help in doing so).

## Version 3.0
Access to trStatus property in examples 019/020/021 fixed.

Update to Domain storeDB method in order to permit a few not very common
cases like 're-transfer-in' or a 're-register-after-delete'.

Any transferStatus query now also fills the reID fields in `tbl_messages`.

## Version 3.0 rc 2
Include path in all examples is now based on the `__FILE__` constant so that you
may now launch a file from whatever path you are in. In order for that the
internal behaviour of the Client class (which locates and reads the config.xml
file) has been altered as well. This now extends nicely to the new WSDL class
as well and we don't need an ugly, separate config file for this.

Smarty default values (empty) should be kept from now on. The smarty compile
folder still needs write permissions (i.e. www-data on Debian/Ubuntu if you are
trying to use the WSDL interface). If this is not granted the Client class
will try to fall back to '/tmp', emit warnings in case of success and die with
an error message in case of a failure.

Any transferStatus query now fills the reID/acID fields (if set).

Added new Smarty and adoDB libraries.

## Version 3.0 rc 1
Domain and Contact object values are now re-initialized before every fetch from
either DB or EPP servers.

First implementation of a message queue parser. Messages are stored after any
poll 'req' in `tbl_messages` in more or less human readable format and can be
retrieved with the `retrieveParsedMessages()` method call. There are 3 new
example scripts on how to use this functionality, while example 009 has
been 'deactivated', since its implementation was completely wrong and is now
superseded by 029 and 030. This should now be the correct approach to the
issue hinted at by Marco about two weeks ago.

The WSDL start file has now moved into the `public/` folder in order to deny
file access to any other files through the webbrowser. Let's try to keep to
good security practices even though this will probably always be an internal
solution... ;-)

## Version 3.0 beta 4
Updates to both Contact and Domain objects in order to prevent the registration
of "changes" when a value of an existing object hadn't really changed.

## Version 3.0 beta 3
Added centralized error handling functions to Net_EPP_IT_AbstractObject and to
Net_EPP_IT_StorageDB. Updated error handling in all example scripts.

Added some modifications for consentforpublishing handling (thanks Marco!).

## Version 3.0 beta 2
Public beta release for the new version. As soon as the final 3.0 version is
out the "what's new" information will again be dumped.

## Version 3.0 beta 1 (internal only)
The DB layout changed in order to support user ownership on objects (mandants).
This obviously constitutes a breach in the continuity of some DB access
methods and is underlined by an upgrade of the major version number.

WSDL support is here! All functions can be accessed as webservices now.

Minor changes include, but are not restricted to the following:

- String quoting for values to be stored in DB added. This functionality can be
  controlled in config.xml by the new dbmagicquotes parameter.
- It is now possible to update DNS server's IP addresses.
- Some method documentation cleaned up (public functions being wrongly
  documented as protected).
- Contact status is now handled by the Contact object. Thanks Marco for pointing
  this out!
- Better error handling on DB operations (now using ADOdb's ErrorMsg method).

## Version 2.11
Initialization of change-relevant information in domain objects after calls
to loadDB added. Some more cleanups to various methods as suggested by Luca.
Thanks again!

## Version 2.10
Some code cleanup suggested by Luca!

## Version 2.9
Two more bugs found by Luca have been corrected.

1. Using an `in_array` comparison containing a boolean `TRUE` will cause this to
   match ANY string passed to it, since `in_array` only does not do type-safe
   comparisons. This error relates to the values for "consentforpublishing" and
   has caused every contact where it has been manually assigned to be created
   with it set to 'TRUE' instead of whatever was your intention!
2. The storage driver will not save the "consentforpublishing" information
   correctly. The SQL-type for it was defined to be MySQL's tinyint by the schema
   provided in all versions. Since all doStore() calls will encapsulate every
   variable in between two single apostrophes (i.e. 'value'), this has caused
   every contact to be saved with "consentforpublishing" set to '0'.

> **ATTENTION!** Everyone using the storage driver provided with an implementation
> up to 2.9 will therefore have lost information about the "consentforpublishing"
> value and is strongly advised to check backups and set things straight!

## Version 2.8.1
Cleaned up the `CLI-generic-update-domain-authinfo.php` example and combined
a common interface to all major update operations inside of a single example
called `CLI-generic-domain-script.php`.

## Version 2.8
Removed a minor error from the update-domain template. Also updated both CLI
examples for updating NS and tech-c records in order to support adding or
removing of multiple values in one go. Thanks Marco for pointing both out!

## Version 2.7
Adding NS records and technical contacts that already exist will no longer be
treated as a change to the domain. By accident `print_r` statements were left
behind in release 2.6; this has been corrected.

Cleanup of lines 224 and 225 in Contact.php causing an `E_NOTICE` if using
`sanity_check()` with a phone number that has no dot (.) as a separator. Thanks
to Luca!

## Version 2.6
The changes applied to Domain.php between r45 and r57 have been undone and
simplified by using `array_diff`.

The library should now correctly handle multiple technical contacts (also by
using `array_diff`). Technical contact handling has been adapted in both the
create and update domain templates. Thanks Marco for your input!

Please pay attention that a `get()` method call for the value of 'tech' will
still result in a string instead of an array when only one contact is set. This
may cause some issues if you end up with a domain that owns more than one, so
better DON'T rely on it being a string!

Please see the included sample files `CLI-generic-fetch-domain.php`,
`CLI-generic-update-domain-techc.php` and `028-enhanced-db-layout.php`.

## Version 2.5
The only major change can be found in class Client. Its constructor now
accepts configuration parameters as an XML parameter string. This is useful
if you store configuration values in some other back end solution.

Minor changes to:
- check definition of LOG-Priorities prior to setting them
- check existence of HTTP_Client and Smarty classes prior to importing them
- updated README file (installing HTTP_Client PEAR package)

Fixes:
- domain updates when adding NS records fixed

## Version 2.4
Added support for changing single values for registrants. If a contact has
already information set in these fields, only single fields that are still
empty can be set. Adjusted Contact.php class and update-contact template
to allow for these specific operations. Thanks Robin!

An example for this is now available as well.

## Version 2.3
Added `set_include_path('.:'.ini_get('include_path'));` to all examples. This
should help conflicts with "Net/" include paths defined in the system wide
include_path setting.

Handling of domain and contact arrays in check commands adjusted for DB usage.

Added some more examples on how to use class extension on the StorageDB driver
class.

## Version 2.2
Bugfix release. Changed an error in StorageDB.php which would log empty data
when using the retrieveDomain method. Thanks to Luca for this hint!

## Version 2.1
Bugfix release. An error was introduced in v2.0 which caused a warning to be
printed during polling (and by that the storeMessage method).
A second issue hidden in the domain-update template caused an error when
trying to remove NS records through a domain update.

## Version 2.0
This version now supports handling of extended server error codes/messages and
transports them transparently to the DB layer. The update in the major release
number is due to interface changes in the StorageInterface class.

## Version 1.4
Different changes and updates to all modules. Mainly cleanups and some small
bug fixes. This version when released will have passed the accreditation
tests.

## Version 1.3
Added support for bulk domain/contact checks. Added example scripts including
change password script which actually updates the config.xml file as well.

## Version 1.2
Added an optional "newPW" parameter to Net_EPP_IT_Session's login method.
Updated basic checks related to the Domain and Contact classes EntityType
property.

## Version 1.1
The domain fetch method now retrieves the AuthInfo code which is sent by the
epp server after a domain info query. Thanks to Mr. Bianchi for pointing this
out!

## Version 1.0
Initial release.
