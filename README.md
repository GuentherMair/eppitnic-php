REQUIREMENTS
============

1) PHP5 (PHP4 won't work!!)
2) the PEAR package HTTP_Client (handles communication and cookies);
   install it using "pear install HTTP_Client"
3) either a MySQL database or another database including a new class deriving
   from Net_EPP_IT_StorageInterface to handle this database

RESTRICTIONS
============

1) eppitnic includes components through 'Net/...' and 'libs/...' paths. If you
   have 'Net' and 'libs' defined in your php configuration by include_path,
   please move eppitnic contents to the directories you defined. Maybe better
   still would be to define your include_path as starting with '.:'.
   NOTE: as of version 2.3 this should be resolved by setting the include_path
   through "set_include_path('.:'.ini_get('include_path'));" (see examples).

INCLUDED
========

A) Smarty template engine
B) ADOdb database abstraction layer
C) MySQL DB schema (see examples/ folder) + apropriate StorageDB class
D) sample configuration (see config.xml)
E) example script (see examples/ folder)

==

$Id: README 53 2010-03-04 18:41:14Z gunny $
