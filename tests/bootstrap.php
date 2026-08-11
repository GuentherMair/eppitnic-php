<?php

/**
 * Test bootstrap.
 *
 * Note what is deliberately *not* here: any database setup. Every test in this
 * suite runs against Config::loadForTesting() and a fake Transport, so the
 * suite is runnable on a machine (or a CI runner) with no MariaDB, no
 * config/config.php, and no network access. Tests that genuinely need a
 * database say so themselves and skip when it is absent.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
