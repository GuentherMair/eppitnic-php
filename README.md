# Requirements

1. PHP 8.0.0 or newer (verified against both the codebase's own syntax and
   every Composer dependency's declared PHP requirement; `slim/psr7` and
   `firebase/php-jwt` are the binding constraints at `^8.0`)
2. [Composer](https://getcomposer.org/), to install the third-party
   dependencies (Smarty, nusoap, phpwhois, idna-convert) declared in
   `composer.json` — run `composer install` before first use
3. CURL support for PHP (handling HTTP session and cookies)
4. either a MySQL database or another database including a new class deriving
   from `Net_EPP_StorageInterface` to handle this database
5. eppitnic includes its own first-party components through the `Net/...`
   path. If you have `Net` defined in your php configuration by
   `include_path`, either move eppitnic contents to the directory you defined
   or use something like `set_include_path('.:'.ini_get('include_path'));`
   (see `/examples/`)
6. if using the WSDL service, make sure you give the webserver appropriate
   rights to the `/smarty/compile/` folder!


# Installation

Run `composer install` to fetch the third-party dependencies into `vendor/`.

Create a copy of the `config.xml.template`, naming it `config.xml`. Choose one
of the following as server name:

 - epp.nic.it (for production use)
 - pub-test.nic.it (for testing purposes)

A database is used for storing some of the communication with the server. Set it
up using the schema provided in `/docs/mysql-5.0-schema.sql`.

After you have set everything up in the configuration file, simply try to have a
look at the `/examples/` folder!

If you want to use the WSDL interface, there is little to be said. Scripts for
testing are included in the `/examples-wsdl/` folder and documentation can be
found in the `/docs/` folder.


# What's included

1. MySQL DB schema (see `/examples/` folder) + apropriate StorageDB class
2. sample configuration (see config.xml)
3. example script (see `/examples/` folder)
4. WSDL interface (see `/examples-wsdl/` and `/docs/` folder)
5. a simple GET-based WHOIS lookup endpoint (`GET /v1/whois?domain=`, see
   `routes/whois.php`) — requires a bearer token like every other route
6. Smarty template engine (Composer dependency, see `composer.json`)


# ToDo's

1. replace Smarty templates with XML builder
2. verify XML through XSDs
3. Implement a client-daemon with session keep-alive functionality. Btw. this
   is not necessary to pass the accreditation test (simply don't log out), but
   would be rather important if the library was to be used by registrars with
   a very high registration rate.
