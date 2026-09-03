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
