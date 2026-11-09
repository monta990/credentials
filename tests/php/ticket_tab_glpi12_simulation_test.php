<?php

declare(strict_types=1);

/**
 * Isolated GLPI 12 contract simulation for the actual CredentialTicketTab class.
 * It models Ticket_User, Item_Ticket, item READ/reauth checks, and the credential
 * link lookup. It is not a substitute for a live GLPI installation test.
 */
namespace {
    if (!defined('READ')) define('READ', 1);

    class CommonGLPI {}
    class CommonDBTM extends CommonGLPI
    {
        public array $fields = [];
        private int $id = 0;
        public function getID(): int { return $this->id; }
        public function getFromDB($id): bool
        {
            $key = static::class . '#' . (int) $id;
            $row = $GLOBALS['SIM_ITEMS'][$key] ?? null;
            if (!is_array($row)) return false;
            $this->id = (int) $id;
            $this->fields = $row;
            return true;
        }
        public function can($id, $right, &$input = null, &$reauth_needed = null): bool
        {
            $reauth_needed = (bool) ($this->fields['reauth'] ?? false);
            return !(bool) ($this->fields['denied'] ?? false) && !$reauth_needed;
        }
        public function getName(): string { return (string) ($this->fields['name'] ?? ''); }
        public function isEntityAssign(): bool { return true; }
        public function getEntityID(): int { return (int) ($this->fields['entities_id'] ?? 0); }
    }
    class User extends CommonDBTM { public static function getTypeName($n = 0): string { return 'User'; } }
    class NetworkEquipment extends CommonDBTM { public static function getTypeName($n = 0): string { return 'Network equipment'; } }
    class Ticket extends CommonDBTM {}
    class Ticket_User { public static function getTable(): string { return 'glpi_tickets_users'; } }
    class Item_Ticket { public static function getTable(): string { return 'glpi_items_tickets'; } }
    class CommonITILActor { public const REQUESTER = 1; }
    class Plugin { public static function registerClass(...$args): void {} }
    class Session { public static function haveRight(...$args): bool { return true; } }
    class FakeDB
    {
        public array $requesters = [];
        public array $items = [];
        public function request(array $query): array
        {
            return match ($query['FROM'] ?? '') {
                'glpi_tickets_users' => array_values(array_filter($this->requesters, static fn($row) => (int) $row['tickets_id'] === (int) ($query['WHERE']['tickets_id'] ?? 0) && (int) $row['type'] === (int) ($query['WHERE']['type'] ?? 0))),
                'glpi_items_tickets' => array_values(array_filter($this->items, static fn($row) => (int) $row['tickets_id'] === (int) ($query['WHERE']['tickets_id'] ?? 0))),
                default => [],
            };
        }
    }
    function getItemForItemtype(string $itemtype): ?CommonDBTM { return class_exists($itemtype) ? new $itemtype() : null; }
    function __(string $message, ?string $domain = null): string { return $message; }
}

namespace GlpiPlugin\Credentials {
    final class Credential
    {
        public static string $rightname = 'plugin_credentials';
        public static array $labels = [];
        public static array $active = [];
        public static function getLabelsForItem(string $type, int $id, int $entity): array { return self::$labels[$type . '#' . $id] ?? []; }
        public static function getActiveIDsForItem(string $type, int $id, int $entity): array { return self::$active[$type . '#' . $id] ?? []; }
    }
    final class CredentialItem
    {
        public static function isSupportedItemtype(string $type): bool { return in_array($type, [\User::class, \NetworkEquipment::class], true); }
        public static function resolveParentEntityID(\CommonDBTM $item): int { return (int) ($item->fields['entities_id'] ?? 0); }
    }
    final class Security
    {
        public static function routeUrl(string $route, array $query = []): string { return '/plugins/credentials/' . $route . '?' . http_build_query($query); }
    }
}

namespace {
    require_once __DIR__ . '/../../src/CredentialTicketAggregator.php';
    require_once __DIR__ . '/../../src/CredentialTicketTab.php';

    $GLOBALS['SIM_ITEMS'] = [
        Ticket::class . '#77' => ['name' => 'Ticket 77', 'entities_id' => 0],
        User::class . '#2' => ['name' => 'Requester', 'entities_id' => 0],
        NetworkEquipment::class . '#9' => ['name' => 'Router', 'entities_id' => 0],
    ];
    $DB = new FakeDB();
    $DB->requesters = [['tickets_id' => 77, 'users_id' => 2, 'type' => CommonITILActor::REQUESTER]];
    $DB->items = [['tickets_id' => 77, 'itemtype' => NetworkEquipment::class, 'items_id' => 9]];
    $ticket = new Ticket();
    if (!$ticket->getFromDB(77)) throw new RuntimeException('Ticket fixture did not load.');

    $credential = ['id' => 41, 'name' => 'Router credential', 'date_mod' => '2026-11-07 09:00:00'];
    \GlpiPlugin\Credentials\Credential::$labels = [
        User::class . '#2' => [$credential],
        NetworkEquipment::class . '#9' => [$credential],
    ];
    \GlpiPlugin\Credentials\Credential::$active = [
        User::class . '#2' => [41],
        NetworkEquipment::class . '#9' => [41],
    ];

    $both = \GlpiPlugin\Credentials\CredentialTicketTab::getCredentialRows($ticket);
    if ($both['count'] !== 1 || count($both['rows']) !== 1 || count($both['rows'][0]['sources']) !== 2) {
        throw new RuntimeException('One credential linked to requester and ticket asset must appear once with two sources.');
    }

    // Unlink from requester; the asset relation must keep the ticket row and count.
    \GlpiPlugin\Credentials\Credential::$labels[User::class . '#2'] = [];
    \GlpiPlugin\Credentials\Credential::$active[User::class . '#2'] = [];
    $assetOnly = \GlpiPlugin\Credentials\CredentialTicketTab::getCredentialRows($ticket);
    if ($assetOnly['count'] !== 1 || count($assetOnly['rows']) !== 1 || count($assetOnly['rows'][0]['sources']) !== 1) {
        throw new RuntimeException('Unlinking the requester must retain the linked ticket-item source.');
    }

    // Re-link to requester; aggregation returns to two sources without double counting.
    \GlpiPlugin\Credentials\Credential::$labels[User::class . '#2'] = [$credential];
    \GlpiPlugin\Credentials\Credential::$active[User::class . '#2'] = [41];
    $relinked = \GlpiPlugin\Credentials\CredentialTicketTab::getCredentialRows($ticket);
    if ($relinked['count'] !== 1 || count($relinked['rows']) !== 1 || count($relinked['rows'][0]['sources']) !== 2) {
        throw new RuntimeException('Re-linking the requester must restore both ticket sources and retain a unique count.');
    }

    // Remove both links: both list and tab counter must reach zero.
    \GlpiPlugin\Credentials\Credential::$labels = [];
    \GlpiPlugin\Credentials\Credential::$active = [];
    $empty = \GlpiPlugin\Credentials\CredentialTicketTab::getCredentialRows($ticket);
    if ($empty['count'] !== 0 || $empty['rows'] !== []) {
        throw new RuntimeException('Removing all sources must remove the ticket row and count.');
    }

    // Two inaccessible sources must accumulate unique protected IDs, not overwrite each other.
    $GLOBALS['SIM_ITEMS'][User::class . '#2']['reauth'] = true;
    $GLOBALS['SIM_ITEMS'][NetworkEquipment::class . '#9']['reauth'] = true;
    \GlpiPlugin\Credentials\Credential::$active = [User::class . '#2' => [41], NetworkEquipment::class . '#9' => [42]];
    $protected = \GlpiPlugin\Credentials\CredentialTicketTab::getCredentialRows($ticket);
    if ($protected['count'] !== 2 || $protected['rows'] !== [] || !$protected['requires_reauth']) {
        throw new RuntimeException('Protected credential IDs from multiple sources must accumulate uniquely.');
    }

    echo "GLPI 12 simulated ticket/requester/asset unlink-relink integration: OK\n";
}
