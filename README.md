# Requirements

1. PHP 8.1.0 or newer (verified against both the codebase's own syntax and
   every Composer dependency's declared PHP requirement; the code itself is
   8.0-compatible, but `spomky-labs/otphp` — which provides TOTP/MFA — and its
   `symfony/deprecation-contracts` dependency bind the minimum at `>=8.1`)
2. [Composer](https://getcomposer.org/), to install the third-party
   dependencies declared in `composer.json` — run `composer install` before
   first use
3. CURL, XML and PDO modules for PHP
4. either a MariaDB/MySQL or another database

None of the above if you'd rather run the whole thing in Docker instead — see
the "Docker" section below.


# ** Warning **

This installation is a breaking change. It will remove the accounting table
from an existing installation in case of upgrade. Please read the Upgrading
documentation for more details!


# Why using such a library instead of a client with direct access?

Here is a list of a few very simple reasons:

1. Saving messages from the polling queue
2. Automatic password rotation
3. Automatic DNS server updates (post transfer-in)
4. Usage history (who did what and when?)
5. User management (multiple distinct operators, resellers)


# Detailed instructions

Detailed instructions can be found in:

* [UPGRADING.md](docs/UPGRADING.md)
* [INSTALL.md](docs/INSTALL.md)
* [DOCKER.md](docs/DOCKER.md)


# Quick Start

1. MariaDB/MySQL database + user:
   * `CREATE DATABASE <DATABASENAME>;`
   * `GRANT ALL PRIVILEGES ON <DATABASENAME>.* TO '<USERNAME>'@'localhost' IDENTIFIED BY '<PASSWORD>';`
   * `FLUSH ALL PRIVILEGES;`
2. Webserver Virtual Host (see config/apache-vhost.sample and config/nginx-vhost.sample;
   config/*-proxy.sample instead if fronting a Docker install, see docs/DOCKER.md)
3. run `composer install`
4. choose a setup method (CLI or web UI) and follow the instructions provided:
   * CLI: run `bin/eppitnic setup`
   * web UI: open the URL you set up for your webserver
5. verify everything is working, the  add the cronjob for `bin/eppitnic poll process`


# Verify

In order to get a bearer token log in using the username + password configured during setup:

```
curl -s -X POST https://<YOUR_HOSTNAME>/v1/users/authenticate \
  -H "Content-Type: application/json" \
  -d '{"username":"<ADMIN_USERNAME>","password":"<ADMIN_PASSWORD>"}' | jq .
```

Verify the current EPP configuration using the bearer token (this is NOT an
end-to-end test towards the registry yet):

```
curl -s -X GET https://<YOUR_HOSTNAME>/v1/session/epp \
  -H "Authorization: Bearer <TOKEN>" | jq .
```

A last test for end-to-end connectivity obviously depends on selecting an
existing domain name:

```
curl -s -X GET https://<YOUR_HOSTNAME>/v1/domains/<EXISTING_DOMAIN_NAME> \
    -H "Authorization: Bearer <TOKEN>" | jq .
```
