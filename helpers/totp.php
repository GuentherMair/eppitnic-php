<?php

use OTPHP\TOTP;

function totpGenerate(string $username): array {
    $totp = TOTP::generate();
    $totp->setLabel($username);
    $totp->setIssuer('inet-services.it');
    return [
        'secret' => $totp->getSecret(),
        'uri'    => $totp->getProvisioningUri(),
    ];
}

function totpVerify(string $secret, string $code): bool {
    try {
        // leeway in seconds; 29 = just under one 30s period, giving practical ±1-window tolerance
        return TOTP::createFromSecret($secret)->verify($code, null, 29);
    } catch (\Throwable $e) {
        return false;
    }
}
