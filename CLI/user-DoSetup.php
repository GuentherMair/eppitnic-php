<?php

require_once dirname(__FILE__).'/../Net/EPP/Client.php'; // for the exit-code constants only, no EPP session needed
require_once dirname(__FILE__).'/../helpers/config.php';
require_once dirname(__FILE__).'/../helpers/db.php';
require_once dirname(__FILE__).'/../helpers/changelog.php';

use RedBeanPHP\R;

function syntax($argv0) {
  echo "SYNTAX: {$argv0} -m user  -u USERNAME -p PASSWORD [-b BILLING_ID] [-e EMAIL] [-d DESCRIPTION] [-o MAX_OPERATIONS] [-A]\n";
  echo "        {$argv0} -m token -u USERNAME [-x DAYS]\n";
  echo "\n";
  echo "  -m user   create a new local login account. This is the preferred way to\n";
  echo "            create the very first admin account -- every route that creates\n";
  echo "            a user (POST /v1/users) requires an admin token to call it, so\n";
  echo "            there is no way to bootstrap that first admin over the API.\n";
  echo "\n";
  echo "    -u USERNAME        login username (required)\n";
  echo "    -p PASSWORD        plaintext password, hashed before storing (required)\n";
  echo "    -b BILLING_ID      unique billing reference (optional, defaults to '')\n";
  echo "    -e EMAIL           contact email address\n";
  echo "    -d DESCRIPTION     free-text description\n";
  echo "    -o MAX_OPERATIONS  daily domain-create quota, 0 = unlimited (default 0)\n";
  echo "    -A                 grant admin (unrestricted) access\n";
  echo "\n";
  echo "  -m token  issue a fixed API token for an existing user -- the same\n";
  echo "            operation as POST /v1/users/{id}/api-token, run directly\n";
  echo "            against the DB (useful for headless/scripted API access).\n";
  echo "\n";
  echo "    -u USERNAME   username of the account to issue a token for\n";
  echo "    -x DAYS       validity period in days, counted from now; 0 or omitted\n";
  echo "                  means the token never expires (a WARNING is printed then)\n";
  echo "\n";
}

// retrieve and test command line options
$options = getopt("m:u:p:b:e:d:o:x:A");
$mode = $options['m'] ?? null;

if ($mode !== 'user' && $mode !== 'token') {
  syntax($argv[0]);
  exit(SYNTAX_ERROR);
}

if ($mode === 'user') {
  if (empty($options['u']) || empty($options['p'])) {
    syntax($argv[0]);
    exit(SYNTAX_ERROR);
  }

  $username   = $options['u'];
  $billing_id = $options['b'] ?? '';

  if ((int) R::getCell("SELECT COUNT(*) FROM users WHERE username = ?", [$username]) > 0) {
    echo "[FAILURE] username '{$username}' is already taken.\n";
    exit(INVALID_INPUT);
  }
  if ((int) R::getCell("SELECT COUNT(*) FROM users WHERE billing_id = ?", [$billing_id]) > 0) {
    echo "[FAILURE] billing_id '{$billing_id}' is already taken.\n";
    exit(INVALID_INPUT);
  }

  $isAdmin = isset($options['A']);

  R::exec("
    INSERT INTO users (billing_id, description, username, password, email, max_operations, active, admin)
    VALUES (:billing_id, :description, :username, :password, :email, :max_operations, 1, :admin)
  ", [
    ':billing_id'     => $billing_id,
    ':description'    => $options['d'] ?? null,
    ':username'       => $username,
    ':password'       => password_hash($options['p'], PASSWORD_DEFAULT),
    ':email'          => $options['e'] ?? null,
    ':max_operations' => isset($options['o']) ? (int) $options['o'] : 0,
    ':admin'          => $isAdmin ? 1 : 0,
  ]);

  $id = (int) R::getInsertID();
  // no authenticated actor exists yet in a CLI bootstrap context -- log the new user as its own actor
  changelogInsert('users', $id, 'create', ['username' => $username, 'admin' => $isAdmin], $id);

  echo "[SUCCESS] user '{$username}' created (id {$id}" . ($isAdmin ? ", admin" : "") . ").\n";
  exit(0);
}

// $mode === 'token'
if (empty($options['u'])) {
  syntax($argv[0]);
  exit(SYNTAX_ERROR);
}

$user = R::getRow("SELECT id, username FROM users WHERE username = ? AND active = 1", [$options['u']]);
if (empty($user)) {
  echo "[FAILURE] no active user found.\n";
  exit(INVALID_INPUT);
}

$days = isset($options['x']) ? (int) $options['x'] : 0;
$expires = $days > 0 ? time() + $days * 86400 : 0;
if ($expires === 0) {
  echo "WARNING: no validity period given (-x 0, or -x omitted) -- this token will be valid forever, until explicitly revoked with DELETE /v1/users/{id}/api-token.\n";
}

// 32 random bytes as an opaque hex token -- stored only as a hash, same as passwords;
// the plaintext token is only ever shown here, at issue time, and can't be recovered later
$token = bin2hex(random_bytes(32));
R::exec("UPDATE users SET api_token = :token, api_token_expires = :expires WHERE id = :id", [
  ':token'   => hash('sha256', $token),
  ':expires' => $expires,
  ':id'      => $user['id'],
]);
changelogInsert('users', (int) $user['id'], 'update', ['api_token_expires' => $expires], (int) $user['id']);

echo "[SUCCESS] API token issued for '{$user['username']}' (id {$user['id']}).\n";
echo "Token (shown only once, store it now): {$token}\n";
echo "Expires: " . ($expires === 0 ? "never" : date('c', $expires)) . "\n";
