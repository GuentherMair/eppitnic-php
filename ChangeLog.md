Version 2.4
===========
Added support for changing single values for registrant's. If a contact has
already information set in these fields, only single fields that are still
empty can be set. Adjusted Contact.php class and update-contact template
to allow for these specific operations. Thanks Robin!

An example for this is now available as well.


Version 2.3
===========
Added "set_include_path('.:'.ini_get('include_path'));" to all examples. This
should help conflicts with "Net/" include paths defined in the system wide
include_path setting.

Handling of domain and contact arrays in check commands adjusted for DB usage.

Added some more examples on how to use class extension on the StorageDB driver
class.


Version 2.2
===========
Bugfix release. Changed an error in StorageDB.php which would log empty data
when using the retrieveDomain method. Thanks to Luca for this hint!


Version 2.1
===========
Bugfix release. An error was introduced in v2.0 which caused a warning to be
printed during polling (and by that the storeMessage method).
A second issue hidden in the domain-update template caused an error when
trying to remove NS records through a domain update.


Version 2.0
===========
This version now supports handling of extended server error codes/messages and
transports them transparently to the DB layer. The update in the major release
number is due to interface changes in the StorageInterface class.


Version 1.4
===========
Different changes and updates to all modules. Mainly cleanups and some small
bug fixes. This version when released will have passed the accreditation
tests.


Version 1.3
===========
Added support for bulk domain/contact checks. Added example scripts including
change password script which actualy updates the config.xml file as well.


Version 1.2
===========

Added an optional "newPW" parameter to Net_EPP_IT_Session's login method.
Updated basic checks related to the Domain and Contact classes EntityType
property.


Version 1.1
===========

The domain fetch method now retrieves the AuthInfo code which is sent by the
epp server after a domain info query. Thanks to Mr. Bianchi for pointing this
out!


Version 1.0
===========

Initial release.
