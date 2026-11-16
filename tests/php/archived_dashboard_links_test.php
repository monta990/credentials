<?php

declare(strict_types=1);

namespace {
    $root = dirname(__DIR__, 2);
    define('READ', 1);
    define('UPDATE', 2);

    function getEntitiesRestrictCriteria(string $table = '', string $field = '', int|array|string $value = '', bool|string $is_recursive = false): array { return []; }

    final class Session
    {
        public static function haveRight(string $right, int $mode): bool
        {
            return true;
        }

        public static function haveAccessToEntity(int $entity): bool
        {
            return true;
        }
    }

    final class FakeDashboardIterator implements \IteratorAggregate
    {
        public function __construct(private array $rows)
        {
        }

        public function getIterator(): \Traversable
        {
            yield from $this->rows;
        }
    }

    final class FakeDashboardDB
    {
        public array $credentialRows = [];

        public function request(array $criteria): FakeDashboardIterator
        {
            if (($criteria['FROM'] ?? '') !== 'glpi_plugin_credentials_credentials') {
                throw new \RuntimeException('Unexpected dashboard credentials query.');
            }

            return new FakeDashboardIterator($this->credentialRows);
        }
    }

    class FakeLinkedItem
    {
        public array $fields = ['is_deleted' => 0];

        public function __construct(
            private int $id,
            private string $name,
            private string $url,
            private int $entityId,
        ) {
        }

        public function getID(): int { return $this->id; }
        public function getName(): string { return $this->name; }
        public function getLinkURL(): string { return $this->url; }
        public function getEntityID(): int { return $this->entityId; }
        public function isEntityAssign(): bool { return true; }
    }

    final class Computer extends FakeLinkedItem
    {
        public static function getTypeName(int $plural = 0): string { return 'Computer'; }
    }
}

namespace GlpiPlugin\Credentials {
    final class Credential
    {
        public static string $rightname = 'plugin_credentials';

        public static function getTable(): string
        {
            return 'glpi_plugin_credentials_credentials';
        }
    }

    final class CredentialCategory
    {
        public static function getOptions(int $entitiesId): array
        {
            return [];
        }
    }

    final class CredentialItem
    {
        public static function getSupportedItemtypes(): array
        {
            return [];
        }

        public static function loadReadableItem(string $itemtype, int $itemsId): ?\FakeLinkedItem
        {
            if ($itemtype === 'Computer' && $itemsId === 7) {
                return new \Computer(7, 'ERP core server', '/front/computer.form.php?id=7', 0);
            }

            // This item exists in the relationship table but is not readable.
            return null;
        }

        public static function resolveParentEntityID(\FakeLinkedItem $item): int
        {
            return $item->getEntityID();
        }
    }

    final class CredentialItemLink
    {
        public static function getLinkCounts(array $credentialIds): array
        {
            return [10 => 1, 11 => 1];
        }

        public static function getLinksForCredentials(array $credentialIds): array
        {
            return [
                10 => [['itemtype' => 'Computer', 'items_id' => 7]],
                11 => [['itemtype' => 'User', 'items_id' => 3]],
            ];
        }
    }

    final class CredentialStatus
    {
        public static function fromDates(?string $expires, ?string $review, string $today): array
        {
            return [
                'key' => 'current',
                'label' => 'Current',
                'expired' => false,
                'expiring' => false,
                'review_overdue' => false,
                'review_due' => false,
            ];
        }
    }
}

namespace {
    $root = dirname(__DIR__, 2);
    require_once $root . '/src/Service/CredentialAdminService.php';

    $DB = new FakeDashboardDB();
    $DB->credentialRows = [
        [
            'id' => 10,
            'name' => 'Archived linked credential',
            'entities_id' => 0,
            'is_deleted' => 1,
            'date_mod' => '2020-01-01 00:00:00',
            'date_expires' => null,
            'date_next_review' => null,
            'plugin_credentials_categories_id' => 0,
        ],
        [
            'id' => 11,
            'name' => 'Archived restricted credential',
            'entities_id' => 0,
            'is_deleted' => 1,
            'date_mod' => '2020-01-02 00:00:00',
            'date_expires' => null,
            'date_next_review' => null,
            'plugin_credentials_categories_id' => 0,
        ],
        [
            'id' => 12,
            'name' => 'Archived orphan credential',
            'entities_id' => 0,
            'is_deleted' => 1,
            'date_mod' => '2020-01-03 00:00:00',
            'date_expires' => null,
            'date_next_review' => null,
            'plugin_credentials_categories_id' => 0,
        ],
    ];

    $dashboard = \GlpiPlugin\Credentials\Service\CredentialAdminService::dashboard(0, 30);
    $byId = [];
    foreach ($dashboard['archived'] as $row) {
        $byId[$row['id']] = $row;
    }

    if ($dashboard['counts']['archived'] !== 3) {
        throw new \RuntimeException('The archive tab must continue listing all archived credentials, including linked ones.');
    }
    if ($dashboard['purge_eligible'] !== 1) {
        throw new \RuntimeException('A credential with any relationship must never be a permanent-deletion candidate.');
    }
    if (!isset($byId[10]) || !$byId[10]['has_links'] || $byId[10]['purge_eligible']) {
        throw new \RuntimeException('Linked archived credentials must remain visible but blocked from purge.');
    }
    $target = $byId[10]['remaining_links'][0] ?? null;
    if ($target !== [
        'label' => 'Computer — ERP core server (#7)',
        'url' => '/front/computer.form.php?id=7',
    ]) {
        throw new \RuntimeException('Readable linked items must be returned with their native GLPI URL and label.');
    }
    if (($byId[10]['hidden_link_count'] ?? -1) !== 0) {
        throw new \RuntimeException('A readable linked target must not be counted as hidden.');
    }
    if (!isset($byId[11]) || $byId[11]['remaining_links'] !== [] || $byId[11]['hidden_link_count'] !== 1) {
        throw new \RuntimeException('Names and URLs of linked items without native READ permission must not be exposed.');
    }
    if (!isset($byId[12]) || $byId[12]['has_links'] || !$byId[12]['purge_eligible']) {
        throw new \RuntimeException('An old archived orphan must remain eligible for permanent deletion and manual relinking.');
    }

    $template = file_get_contents($root . '/templates/config.html.twig');
    foreach ([
        'row.remaining_links',
        'linked_item.url|e(\'html_attr\')',
        'target="_blank"',
        'rel="noopener noreferrer"',
        'row.hidden_link_count',
    ] as $required) {
        if (!is_string($template) || !str_contains($template, $required)) {
            throw new \RuntimeException('Archive UI is missing safe clickable linked-item rendering: ' . $required);
        }
    }

    echo "Archived credential clickable linked-item target and permission regression: OK\n";
}
