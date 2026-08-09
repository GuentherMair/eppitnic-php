# Requirements

1. PHP 8.0.0 or newer (verified against both the codebase's own syntax and
   every Composer dependency's declared PHP requirement; `slim/psr7` and
   `firebase/php-jwt` are the binding constraints at `^8.0`)
2. [Composer](https://getcomposer.org/), to install the third-party
   dependencies declared in `composer.json` — run `composer install` before
   first use
3. CURL, XML and PDO modules for PHP
4. either a MariaDB/MySQL or another database


# Installation

Run `composer install` to fetch the third-party dependencies into `vendor/`.

Copy and configure `config/config.json-template` in `config/config.json`,
choosing one of the following as EPP server name:

 - epp.nic.it (for production use)
 - pub-test.nic.it (for testing purposes)

A database is required for storing/persisting communication with the server.
Set it up using the schema provided in `/config/mariadb-schema.sql`.

After you have set everything up in the configuration file, simply try to have a
look at the `CLI/` and `examples/` folders.


# ToDo's

1. replace Smarty templates with XML builder
2. verify XML through XSDs
3. Implement a client-daemon with session keep-alive functionality. Btw. this
   is not necessary to pass the accreditation test (simply don't log out), but
   would be rather important if the library was to be used by registrars with
   a very high registration rate.
