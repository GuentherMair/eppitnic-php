<?php

/**
 * generate a random, registry-unique contact handle
 *
 * @param    Net_EPP_IT_Contact  a contact object already wired to a live EPP session
 * @param    int                 max attempts before giving up
 * @return   string              a 16-character handle, confirmed available at the registry
 * @throws   \RuntimeException   if no unique handle could be found within $maxAttempts
 */
function generateContactHandle(Net_EPP_IT_Contact $contact, int $maxAttempts = 5): string {
    for ($i = 0; $i < $maxAttempts; $i++) {
        $handle = strtoupper(bin2hex(random_bytes(8))); // 16 hex chars
        if ($contact->check($handle) === TRUE) {
            return $handle;
        }
    }
    throw new \RuntimeException("Unable to generate a unique contact handle after {$maxAttempts} attempts");
}

/**
 * create a brand-new EPP contact copying $source's data, under a new local owner
 *
 * @param    Net_EPP_Client       a live client (used to construct the new Contact object)
 * @param    Net_EPP_IT_Contact   the contact to copy data from (already fetch()ed)
 * @param    int                  the new contact's local owner (users.id)
 * @return   string|false         the new contact's handle, or false on failure
 */
function duplicateContact(Net_EPP_Client $nic, Net_EPP_IT_Contact $source, int $newOwnerId) {
    $fields = [
        'name', 'org', 'street', 'street2', 'street3', 'city', 'province',
        'postalcode', 'countrycode', 'voice', 'fax', 'email',
        'nationalitycode', 'entitytype', 'regcode', 'schoolcode',
    ];

    $new = new Net_EPP_IT_Contact($nic);
    $new->set('handle', generateContactHandle(new Net_EPP_IT_Contact($nic)));
    foreach ($fields as $field) {
        $value = $source->get($field);
        if ($value === '' || $value === null) {
            continue;
        }
        // get() returns the already-escaped value set() stored -- undo one
        // layer before re-escaping it, or it double-encodes on every copy
        $new->set($field, html_entity_decode((string) $value, ENT_COMPAT, 'UTF-8'));
    }
    $new->set('authinfo', substr(md5(rand()), 0, 16));

    if ( ! $new->create()) {
        return false;
    }
    $new->storeDB($newOwnerId);
    return $new->get('handle');
}
