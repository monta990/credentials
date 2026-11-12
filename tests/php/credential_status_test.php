<?php

declare(strict_types=1);

namespace {
    function __(string $message, string $domain = ''): string
    {
        return $message;
    }

    require_once __DIR__ . '/../../src/CredentialStatus.php';

    function statusAssert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    use GlpiPlugin\Credentials\CredentialStatus;

    $expired = \\GlpiPlugin\\Credentials\\CredentialStatus::fromDates('2026-11-11', null, '2026-11-12');
    statusAssert($expired['key'] === 'expired' && $expired['expired'], 'Past expiration date must be expired.');

    $expiring = \\GlpiPlugin\\Credentials\\CredentialStatus::fromDates('2026-11-20', null, '2026-11-12');
    statusAssert($expiring['key'] === 'expiring' && $expiring['expiring'], 'Expiration within 30 days must be flagged.');

    $overdue = \\GlpiPlugin\\Credentials\\CredentialStatus::fromDates(null, '2026-11-10', '2026-11-12');
    statusAssert($overdue['key'] === 'review_overdue' && $overdue['review_overdue'], 'Past review date must be overdue.');

    $review_due = \\GlpiPlugin\\Credentials\\CredentialStatus::fromDates(null, '2026-12-01', '2026-11-12');
    statusAssert($review_due['key'] === 'review_due' && $review_due['review_due'], 'Review within 30 days must be flagged.');

    $current = \\GlpiPlugin\\Credentials\\CredentialStatus::fromDates('2027-06-01', '2027-06-01', '2026-11-12');
    statusAssert($current['key'] === 'current', 'Future dates outside the alert window must be current.');

    statusAssert(\\GlpiPlugin\\Credentials\\CredentialStatus::validDate('2026-02-28'), 'Valid calendar date was rejected.');
    statusAssert(!\\GlpiPlugin\\Credentials\\CredentialStatus::validDate('2026-02-31'), 'Invalid calendar date was accepted.');
    statusAssert(\\GlpiPlugin\\Credentials\\CredentialStatus::validDate(''), 'An empty optional date should be valid.');

    echo "Credential lifecycle status tests: OK\n";
}
