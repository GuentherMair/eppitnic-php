<?php

use Net\EPP\Config;

require_once dirname(__FILE__).'/../vendor/autoload.php';

use RedBeanPHP\R;

/**
 * Reports rows where the two notions of local ownership disagree.
 *
 * There are two of them: `domains.user_id` / `transfers.user_id` (who owns the
 * domain, or requested the transfer-in) and `contacts.user_id` (who owns the
 * contact acting as its registrant). The legacy 6.x code never kept them in
 * step; from 7.0.0 on they are expected to agree, because
 *
 *   * every domain list/read/write route scopes non-admins by the domain's own
 *     owner, so a mismatched row is visible and editable to the domain's owner
 *     while being attributed to somebody else, and
 *   * the API refuses to set a registrant the caller does not own
 *     (canUseAsRegistrant(), routes/domain.php), so an inherited mismatch
 *     cannot be repaired by re-saving the domain: its owner may not name that
 *     contact, and the contact's owner may not touch the domain.
 *
 * This is the same check as section 6h of
 * config/mariadb-schema-upgrade-060700-to-070000.sql, in runnable form --
 * that one only prints anything when the migration is applied by hand through
 * the `mysql` CLI, because Config applies migrations through R::exec() and
 * discards result sets.
 *
 * Read-only: nothing is modified, the remedies are printed for you to apply.
 *
 * Exit codes: 0 if everything is coherent, DATA_INCONSISTENT (6) if any
 * mismatch was found -- so a cron entry alerts on a non-zero exit rather than
 * on the output.
 */

function syntax($argv0) {
  echo "SYNTAX: {$argv0} [-q] [-h]\n";
  echo "\n";
  echo "  Reports domains (and pending transfer-in requests) whose registrant\n";
  echo "  contact belongs to a different local user than the domain/request.\n";
  echo "\n";
  echo "    -q   quiet: print nothing, only set the exit code\n";
  echo "    -h   this help\n";
  echo "\n";
  echo "  Exit codes: 0 = coherent, " . DATA_INCONSISTENT . " = mismatches found.\n";
  echo "\n";
}

$options = getopt("qh");
if (isset($options['h'])) {
  syntax($argv[0]);
  exit(0);
}
$quiet = isset($options['q']);

// no EPP session (and so no Net\EPP object, whose constructor would trigger
// this as a side effect) is ever constructed here, so the DB connection needs
// an explicit nudge -- after the argument handling above, so that -h works
// without a database, and caught so a missing config/config.php is a one-line
// error rather than an uncaught exception and a stack trace
try {
  Config::init();
} catch (\RuntimeException $e) {
  echo "[FAILURE] " . $e->getMessage() . "\n";
  exit(CONFIG_ERROR);
}

try {
  $domains = R::getAll("
    SELECT
      d.domain          AS object,
      d.user_id         AS owner_id,
      owner.username    AS owner_name,
      d.registrant      AS registrant,
      c.user_id         AS registrant_owner_id,
      reg.username      AS registrant_owner_name
    FROM domains d
    JOIN contacts c    ON c.handle = d.registrant
    JOIN users owner   ON owner.id = d.user_id
    JOIN users reg     ON reg.id   = c.user_id
    WHERE d.user_id <> c.user_id
    ORDER BY d.domain
  ");

  $transfers = R::getAll("
    SELECT
      t.domain          AS object,
      t.user_id         AS owner_id,
      owner.username    AS owner_name,
      t.registrant      AS registrant,
      c.user_id         AS registrant_owner_id,
      reg.username      AS registrant_owner_name
    FROM transfers t
    JOIN contacts c    ON c.handle = t.registrant
    JOIN users owner   ON owner.id = t.user_id
    JOIN users reg     ON reg.id   = c.user_id
    WHERE t.user_id <> c.user_id
    ORDER BY t.domain
  ");
} catch (\RedBeanPHP\RedException\SQL $e) {
  echo "[FAILURE] A database error occured: " . $e->getMessage() . "\n";
  exit(CONFIG_ERROR);
}

if ($quiet) {
  exit(empty($domains) && empty($transfers) ? 0 : DATA_INCONSISTENT);
}

/**
 * print one mismatch group as an aligned table
 *
 * @param string $title section heading
 * @param array $rows mismatching rows
 * @param string $label what the first column holds
 */
function report(string $title, array $rows, string $label): void {
  if (empty($rows)) {
    echo "{$title}: OK, no mismatches.\n\n";
    return;
  }

  echo "{$title}: " . count($rows) . " mismatch(es).\n";
  printf("  %-40s  %-24s  %-16s  %s\n", $label, 'owned by', 'registrant', 'registrant owned by');
  printf("  %-40s  %-24s  %-16s  %s\n", str_repeat('-', 40), str_repeat('-', 24), str_repeat('-', 16), str_repeat('-', 24));
  foreach ($rows as $row) {
    printf(
      "  %-40s  %-24s  %-16s  %s\n",
      $row['object'],
      $row['owner_name'] . " (#" . $row['owner_id'] . ")",
      $row['registrant'],
      $row['registrant_owner_name'] . " (#" . $row['registrant_owner_id'] . ")"
    );
  }
  echo "\n";
}

report('Domains', $domains, 'domain');
report('Pending transfer-in requests', $transfers, 'domain');

if (empty($domains) && empty($transfers)) {
  echo "[SUCCESS] domain/registrant ownership is coherent.\n";
  exit(0);
}

echo "How to resolve, per row -- decide which user should really own it, then either\n";
echo "\n";
echo "  (a) give the owner their own copy of the registrant contact:\n";
echo "      POST /v1/domains/{name}/owner does exactly this (duplicates the contact\n";
echo "      under the new owner, changes the registrant, reassigns the domain), or\n";
echo "\n";
echo "  (b) hand the domain to the registrant's owner:\n";
echo "      UPDATE domains SET user_id = <registrant_owner> WHERE domain = '<domain>';\n";
echo "\n";
echo "Option (b) is one statement but moves the domain out of its current owner's\n";
echo "listings -- confirm that is what you want before running it.\n";
echo "\n";
echo "A pending transfer-in listed above has not gone wrong yet: it will create a\n";
echo "mismatched domain row when it completes, because PollProcessor stores the new\n";
echo "domain under the requesting user while its registrant belongs to somebody else.\n";
echo "Fix the transfer's registrant before the registry confirms it.\n";

exit(DATA_INCONSISTENT);
