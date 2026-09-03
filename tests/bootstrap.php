<?php

/**
 * Test bootstrap. Note what is deliberately *not* here: any database setup. The
 * suite runs on Config::loadForTesting() and a fake Transport, so it works with
 * no MariaDB and no network. Tests needing one say so, and skip when it is
 * gone.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
