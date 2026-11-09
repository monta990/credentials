<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/CredentialLinkPolicy.php';

use GlpiPlugin\Credentials\CredentialLinkPolicy;

$cases = [
    0 => false,
    1 => false,
    2 => true,
    3 => true,
];
foreach ($cases as $links => $expected) {
    if (CredentialLinkPolicy::canUnlink($links) !== $expected) {
        fwrite(STDERR, "Credential orphan prevention failed for {$links} links.\n");
        exit(1);
    }
}

echo "Credential last-link policy regression: OK\n";
