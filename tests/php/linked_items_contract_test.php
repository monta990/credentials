<?php

declare(strict_types=1);

/**
 * GLPI 12 linked-item contract simulation: unique item links and modal navigation.
 * This exercises the real CredentialItemLink query/deduplication method against
 * a deterministic DB adapter and validates the actual route/template contract.
 */
namespace {
    class CommonDBTM
    {
        public static function getTable($classname = null): string { return 'dummy'; }
    }
    class Session
    {
        public static function haveRight(...$args): bool { return true; }
    }
    class FakeLinkedItemsDB
    {
        public function request(array $query): array
        {
            if (($query['FROM'] ?? '') !== 'glpi_plugin_credentials_items') {
                return [];
            }
            return [
                ['itemtype' => 'User', 'items_id' => 2],
                ['itemtype' => 'User', 'items_id' => 2], // legacy duplicate row
                ['itemtype' => 'NetworkEquipment', 'items_id' => 9],
            ];
        }
    }
}

namespace GlpiPlugin\Credentials {
    final class CredentialItem
    {
        public static function isSupportedItemtype(string $type): bool
        {
            return in_array($type, ['User', 'NetworkEquipment'], true);
        }
    }
}

namespace {
    require_once __DIR__ . '/../../src/CredentialItemLink.php';
    $DB = new FakeLinkedItemsDB();
    $links = \GlpiPlugin\Credentials\CredentialItemLink::getLinksForCredential(41);
    if (count($links) !== 2) {
        throw new RuntimeException('Linked item count must deduplicate duplicate relationship rows.');
    }
    $keys = array_map(static fn(array $row): string => $row['itemtype'] . '#' . $row['items_id'], $links);
    if ($keys !== ['User#2', 'NetworkEquipment#9']) {
        throw new RuntimeException('The linked item list must retain each distinct type/ID relation.');
    }

    $root = dirname(__DIR__, 2);
    $details = file_get_contents($root . '/templates/credential/details.html.twig');
    $linked = file_get_contents($root . '/templates/credential/linked_items.html.twig');
    $controller = file_get_contents($root . '/src/Controller/CredentialController.php');
    if (!is_string($details) || !str_contains($details, 'href="{{ linked_items_url|e(\'html_attr\') }}"')
        || str_contains($details, 'linked_items_modal_markup')
        || str_contains($details, 'data-bs-toggle="modal"')
    ) {
        throw new RuntimeException('Detail view must navigate its existing iframe instead of opening a nested modal.');
    }
    if (!is_string($linked) || !str_contains($linked, 'back_url')
        || !str_contains($linked, 'Item type') || !str_contains($linked, 'Item ID')
        || !str_contains($linked, 'Item name')
        || !str_contains($linked, 'visible_count') || !str_contains($linked, 'hidden_count')
    ) {
        throw new RuntimeException('Linked item modal must show a clear type/ID/name table and return path.');
    }
    if (!is_string($controller) || !str_contains($controller, '$this->loadReadableTicket($context_ticket_id, $itemtype, $items_id);')) {
        throw new RuntimeException('Linked-item navigation must retain and revalidate the originating ticket context.');
    }

    echo "GLPI 12 linked-item list, count, deduplication and modal navigation simulation: OK\n";
}
