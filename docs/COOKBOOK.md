# Cookbook

Using `eppitnic` as a PHP library. For the command line see `bin/eppitnic`,
and for the REST API see `API.md`.

Everything below assumes one require and a configured database:

```php
require 'vendor/autoload.php';

use Net\EPP\Client;
use Net\EPP\Service\EppSession;
use Net\EPP\IT\Contact;
use Net\EPP\IT\Domain;
use Net\EPP\IT\Session;
```

## A session

`EppSession::run()` opens a session, hands you a logged-in client, and
logs out afterwards — including when your callback throws.

```php
$credit = EppSession::run(function (Client $nic, Session $session) {
    return $session->showCredit();
});
```

It throws `RuntimeException` if the registry is unreachable or refuses the
login. Everything else below goes inside such a callback.

Doing it by hand is only worth it when you need the greeting without logging
in — checking whether the registry is reachable at all:

```php
$nic = new Client();
$session = new Session($nic);

if ($session->hello()) {
    echo $session->xmlResult->greeting->svID, "\n";
}
```

## Is a domain available?

```php
$domain = new Domain($nic);

$answer = $domain->check('example.it');

if ( ! $answer->answered()) {
    // the registry never answered the question -- neither available nor taken
    echo $answer->error(), "\n";
} elseif ($answer->available()) {
    echo "free\n";
} else {
    echo "taken: ", $answer->reason(), "\n";
}
```

Pass an array for up to five names at once. `available('example.it')` and
`reason('example.it')` then take the name, `all()` gives every answer keyed by
name, and `availableNames()` gives just the free ones. Beyond five names, batch
them yourself — the registry rejects a longer request.

`Contact::check()` answers the same way, keyed by handle.

## Reading a domain

```php
$domain = new Domain($nic);

if ($domain->fetch('example.it')) {
    echo $domain->get('registrant'), "\n";
    echo implode(', ', $domain->get('status')), "\n";

    // both always arrays, keyed by handle and by hostname
    foreach ($domain->get('tech') as $handle) { ... }
    foreach ($domain->get('ns') as $name => $ns) { ... }
}
```

A domain sponsored by another registrar needs its authinfo, and can return the
linked contacts in the same round trip:

```php
$domain->fetch('example.it', 'THE-AUTHINFO', 'all');
$contacts = $domain->get('infcontacts');
```

## Creating a contact

The registry assigns nothing: pick a handle, and check it is free.

```php
$contact = new Contact($nic);
$contact->set('handle', $contact->generateHandle());   // random, checked free

$contact->set('name', 'Mario Rossi');
$contact->set('org', 'Rossi S.r.l.');
$contact->set('street', 'Via Roma 1');
$contact->set('city', 'Bolzano');
$contact->set('province', 'BZ');
$contact->set('postalcode', '39100');
$contact->set('countrycode', 'IT');
$contact->set('voice', '+39.0471000000');
$contact->set('email', 'mario.rossi@example.it');

// a registrant also needs these; an admin/tech contact is entityType 0
$contact->set('nationalitycode', 'IT');
$contact->set('entitytype', 2);
$contact->set('regcode', '01234567890');

if ( ! $contact->create()) {
    throw new RuntimeException($contact->getError());
}
$contact->storeDB($userId);   // optional: keep a local copy
```

## Registering a domain

`DomainService::createOrTransfer()` is the whole flow: it checks the name, and
either registers it or requests its transfer if somebody else holds it.

```php
use Net\EPP\Service\DomainService;

$result = DomainService::createOrTransfer($nic, [
    'domain'     => 'example.it',
    'registrant' => 'REGISTRANT-HANDLE',
    'admin'      => 'ADMIN-HANDLE',
    'tech'       => ['TECH-HANDLE'],
    'ns'         => ['ns1.example.it', 'ns2.example.it'],
], $userId);

// $result['action'] is 'created' or 'transfer-requested'
```

The object API underneath, if you want the pieces separately:

```php
$domain = new Domain($nic);
$domain->set('domain', 'example.it');
$domain->set('registrant', 'REGISTRANT-HANDLE');
$domain->addTECH('TECH-HANDLE');
$domain->addNS('ns1.example.it');
$domain->addNS('ns2.example.it', ['192.0.2.1']);   // glue, when below the domain
$domain->set('authinfo', $domain->authinfo());     // 16 random hex characters

$domain->create();
```

## Changing a domain

Updates are a diff against what was fetched, so fetch first. Adding a
nameserver that is already there, or removing one that is not, is simply no
change — `update()` sends only what differs.

```php
$domain = new Domain($nic);
$domain->fetch('example.it');

$domain->addNS('ns3.example.it');
$domain->remNS('ns1.example.it');
$domain->addTECH('OTHER-TECH');
$domain->set('admin', 'NEW-ADMIN');

// capture this before update(), which resets it to 0 on success
$changes = $domain->get('changes');

if ($domain->update()) {
    $domain->updateDB('example.it', $userId, true, $changes);
}
```

The registrant is **not** part of this. It is a separate EPP command that
requires the authinfo to change with it:

```php
$domain->set('registrant', 'NEW-REGISTRANT');
$domain->set('authinfo', $domain->authinfo());
$domain->updateRegistrant();
```

## Transfers

```php
$domain = new Domain($nic);

$domain->transfer('example.it', 'THE-AUTHINFO');    // claim it
$domain->transferApprove('example.it', $authinfo);  // agree to someone's claim
$domain->transferReject('example.it', $authinfo);
$domain->transferCancel('example.it', $authinfo);   // withdraw your own
$domain->transferStatus('example.it', $authinfo);   // then get('trStatus')
```

## The poll queue

Messages arrive one at a time. Reading one does not remove it — acknowledging
it does, and that is what reveals the next.

```php
EppSession::run(function (Client $nic, Session $session) {
    while ($session->pollMessageCount() > 0) {
        $id = $session->pollID();

        $session->poll(true, 'req', $id);    // read it, and store it locally
        $session->poll(false, 'ack', $id);   // remove it from the queue
    }
});
```

`eppitnic poll process` does this on a schedule and reconciles
transfer state afterwards; prefer it over rolling your own loop.

## Errors

Every method returns `false` on failure, with the registry's own words behind
`getError()`:

```php
if ( ! $domain->create()) {
    echo $domain->getError();
    // " EPP code '2302': Object exists"
}
```

Setting `$domain->debug = true` makes `getError()` include the full request and
response, and records every command to the `transactions`/`responses` tables.
Those rows hold the raw XML — registrant names, addresses and authinfo codes —
so it is off by default and driven per user by `users`.`debug`.

## Local storage

The `*DB()` methods keep a local mirror; nothing calls them for you.

```php
$domain->storeDB($userId);                   // insert or replace
$domain->loadDB('example.it', $userId);      // read it back
$domain->updateDB('example.it', $userId, $isAdmin, $changes);
$domain->listDomains($userId, $isAdmin);
$domain->deleteDomainDB('example.it', $userId, $isAdmin);   // deactivates
```

`$isAdmin = true` lifts the `user_id` scoping. Deletes are soft: a domain row
stays, because `domains`.`registrant` is a foreign key onto
`contacts`.`handle`.
