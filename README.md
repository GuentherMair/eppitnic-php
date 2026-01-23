# Requirements

1. PHP7, PHP8 (might still work with PHP5)
2. CURL support for PHP (handling HTTP session and cookies)
3. either a MySQL database or another database including a new class deriving
   from `Net_EPP_StorageInterface` to handle this database
4. eppitnic includes components through `Net/...` and `libs/...` paths. If you
   have `Net` and `libs` defined in your php configuration by `include_path`,
   either move eppitnic contents to the directories you defined or use something
   like `set_include_path('.:'.ini_get('include_path'));` (see `/examples/`)
5. if using the WSDL service, make sure you give the webserver appropriate
   rights to the `/smarty/compile/` folder!


# How-To

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


# Included

1. MySQL DB schema (see `/examples/` folder) + apropriate StorageDB class
2. sample configuration (see config.xml)
3. example script (see `/examples/` folder)
4. WSDL interface (see `/examples-wsdl/` and `/docs/` folder)
5. Smarty template engine


# ToDo

1. replace Smarty templates with XML builder
2. verify XML through XSDs
3. Implement a client-daemon with session keep-alive functionality. Btw. this
   is not necessary to pass the accreditation test (simply don't log out), but
   would be rather important if the library was to be used by registrars with
   a very high registration rate.
