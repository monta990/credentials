<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/CredentialTicketAggregator.php';

use GlpiPlugin\Credentials\CredentialTicketAggregator;

$credential = ['id' => 61, 'name' => 'Switch admin', 'date_mod' => null];
$entries = [
    [
        'label' => $credential,
        'source_label' => 'Requester Name',
        'source_type' => 'Requester',
        'source_url' => '/front/user.form.php?id=4',
        'detail_url' => '/plugins/credentials/Credential/Details?itemtype=User&items_id=4&id=61',
    ],
    [
        'label' => $credential,
        'source_label' => 'Network equipment: Core switch',
        'source_type' => 'Ticket item',
        'source_url' => '/front/networkequipment.form.php?id=12',
        'detail_url' => '/plugins/credentials/Credential/Details?itemtype=NetworkEquipment&items_id=12&id=61',
    ],
];
$result = CredentialTicketAggregator::aggregate($entries);
if ($result['count'] !== 1 || count($result['rows']) !== 1) {
    throw new RuntimeException('A credential shared by multiple ITIL sources must remain one row and one count.');
}
$row = $result['rows'][0];
$urls = array_column($row['source_links'], 'url');
sort($urls);
if ($urls !== ['/front/networkequipment.form.php?id=12', '/front/user.form.php?id=4']) {
    throw new RuntimeException('Each associated GLPI record must retain its own native URL.');
}
if ($row['origins'] !== ['Requester', 'Ticket item']) {
    throw new RuntimeException('Origin labels must be retained separately from associated record names.');
}

$root = dirname(__DIR__, 2);
foreach (['ticket_tab.html.twig', 'itil_tab.html.twig'] as $template) {
    $path = $root . '/templates/credential/' . $template;
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Cannot read ' . $path);
    }
    foreach ([
        "{{ __('Origin', 'credentials') }}",
        'row.origins|join',
        "source.url|e('html_attr')",
        'target="_blank"',
        'rel="noopener noreferrer"',
        'source.label',
    ] as $fragment) {
        if (!str_contains($source, $fragment)) {
            throw new RuntimeException('Missing safe associated-record link markup in ' . $template . ': ' . $fragment);
        }
    }
}

echo "Associated GLPI record links, origin aggregation and safe new-tab rendering: OK\n";
