Version 2.7
===========
Adding NS records and technical contacts that already exist will no longer be
treated as a change to the domain. By accident print_r-statements were left
behind in release 2.6; this has been corrected.

Cleanup of lines 224 and 225 in Contact.php causing an E_NOTICE if using
sanity_check() with a phone number that has no dot (.) as a separator. Thanks
to Luca!


Version 2.6
===========
The changes applied to Domain.php between r45 and r57 have been undone and
simplified by using array_diff.

The library should now correctly handle multiple technical contacts (also by
using array_diff's). Technical contact handling has been adapted in both the
create and update domain templates. Thanks Marco for your input!

Please pay attention that a get()-method-call for the value of 'tech' will
still result in a string instead of an array when only one contact is set. This
may cause some issues if you end up with a domain that owns more than one, so
better DON'T rely on it being a string!

Please see the included sample files "CLI-generic-fetch-domain.php",
"CLI-generic-update-domain-techc.php" and "028-enhanced-db-layout.php".


Version 2.5
===========
The only major change can be found in class Client. Its contstructor now
accepts configuration parameters as XML parameter string. This is usefull
if you store configuration values in some other back end solution.

Minor changes to:
- check definition of LOG-Priorities prior to setting them
- check existence of HTTP_Client and Smarty classes prior to importing them
- updated README file (installing HTTP_Client PEAR package)

Fixes:
- domain updates when adding NS records fixed


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
