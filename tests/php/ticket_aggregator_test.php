<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/CredentialTicketAggregator.php';

use GlpiPlugin\Credentials\CredentialTicketAggregator;

$credential = ['id' => 41, 'name' => 'Router admin', 'date_mod' => '2026-11-07 10:00:00'];
$from_requester = [
    'label' => $credential,
    'source_label' => 'LALLA PPEEP (Requester)',
    'detail_url' => '/plugins/credentials/Credential/Details?itemtype=User&items_id=2&id=41',
];
$from_asset = [
    'label' => $credential,
    'source_label' => 'Router #12 (Ticket item)',
    'detail_url' => '/plugins/credentials/Credential/Details?itemtype=NetworkEquipment&items_id=12&id=41',
];

// One credential linked to a requester and an asset must render/count once with two sources.
$both = CredentialTicketAggregator::aggregate([$from_requester, $from_asset]);
if ($both['count'] !== 1 || count($both['rows']) !== 1 || count($both['rows'][0]['sources']) !== 2) {
    throw new RuntimeException('Requester + asset must appear as one credential row with two source labels.');
}

// After unlinking the requester it must remain on the ticket through its asset link.
$asset_only = CredentialTicketAggregator::aggregate([$from_asset]);
if ($asset_only['count'] !== 1 || count($asset_only['rows']) !== 1 || $asset_only['rows'][0]['sources'] !== ['Router #12 (Ticket item)']) {
    throw new RuntimeException('Unlinking one source must preserve the other ticket source and badge count.');
}

// With no ticket source, the row and count must disappear.
$none = CredentialTicketAggregator::aggregate([]);
if ($none['count'] !== 0 || $none['rows'] !== []) {
    throw new RuntimeException('Removing the last ticket source must remove the row and badge count.');
}

// Re-linking the requester must restore its source without duplication.
$relinked = CredentialTicketAggregator::aggregate([$from_asset, $from_requester]);
if ($relinked['count'] !== 1 || count($relinked['rows']) !== 1 || count($relinked['rows'][0]['sources']) !== 2) {
    throw new RuntimeException('Re-linking a requester must restore source visibility and maintain a unique count.');
}

// Sudo-protected IDs are counted once without disclosing their labels.
$protected = CredentialTicketAggregator::aggregate([], [41, 42, 41]);
if ($protected['count'] !== 2 || $protected['rows'] !== []) {
    throw new RuntimeException('Protected IDs must count uniquely without producing visible rows.');
}

echo "Ticket requester/asset aggregation and relink regression: OK\n";
