<?php

declare(strict_types=1);

namespace {
    $root = dirname(__DIR__, 2);
    const READ = 1;
    const UPDATE = 2;
    if (!function_exists('mb_substr')) {
        function mb_substr(string $value, int $start, ?int $length = null): string
        {
            return $length === null ? substr($value, $start) : substr($value, $start, $length);
        }
    }
    if (!function_exists('mb_strlen')) {
        function mb_strlen(string $value): int
        {
            return strlen($value);
        }
    }

    final class Session
    {
        public static function haveAccessToEntity(int $entity): bool { return $entity === 4; }
        public static function haveRight(string $right, int $mode): bool { return true; }
    }

    class CommonDBTM
    {
        public array $fields = [];
        public static function getTable($classname = null): string { return 'glpi_computers'; }
        public static function getNameField(): string { return 'name'; }
        public function isEntityAssign(): bool { return true; }
        public function getFromDB(int $id): bool
        {
            global $ITEMS;
            if (!isset($ITEMS[$id])) return false;
            $this->fields = $ITEMS[$id];
            return true;
        }
        public function can(int $id, int $right): bool
        {
            return $right === READ
                ? ($this->fields['can_read'] ?? false)
                : ($this->fields['can_update'] ?? false);
        }
        public function getName(): string { return (string) ($this->fields['name'] ?? ''); }
    }

    final class Computer extends CommonDBTM
    {
        public static function getTypeName(int $plural = 0): string { return 'Computer'; }
    }

    function getItemForItemtype(string $itemtype): ?CommonDBTM
    {
        return $itemtype === Computer::class ? new Computer() : null;
    }

    final class FakeRows implements \IteratorAggregate
    {
        public function __construct(private array $rows) {}
        public function getIterator(): \Traversable { yield from $this->rows; }
    }

    final class FakeDB
    {
        public array $requests = [];
        public function request(array $criteria): FakeRows
        {
            $this->requests[] = $criteria;
            $rows = [];
            foreach ($GLOBALS['ITEMS'] as $id => $item) {
                $where = $criteria['WHERE'] ?? [];
                if (($where['is_deleted'] ?? null) !== 0 || (int) ($where['entities_id'] ?? -1) !== (int) $item['entities_id']) continue;
                [$op, $pattern] = $where['name'] ?? ['', ''];
                if ($op !== 'LIKE') continue;
                $prefix = rtrim((string) $pattern, '%');
                if (stripos((string) $item['name'], $prefix) !== 0) continue;
                $rows[] = ['id' => (int) $id, 'name' => $item['name']];
            }
            usort($rows, static fn(array $a, array $b): int => [$a['name'], $a['id']] <=> [$b['name'], $b['id']]);
            $start = (int) ($criteria['START'] ?? 0);
            $limit = (int) ($criteria['LIMIT'] ?? count($rows));
            return new FakeRows(array_slice($rows, $start, $limit));
        }
    }
}

namespace GlpiPlugin\Credentials {
    final class Credential
    {
        public static string $rightname = 'plugin_credentials';
    }

    final class CredentialItem
    {
        public static function getSupportedItemtypes(): array { return [\Computer::class]; }
        public static function resolveParentEntityID(\CommonDBTM $item): int { return (int) ($item->fields['entities_id'] ?? -1); }
    }

    final class CredentialCategory {}
    final class CredentialHistory {}
    final class CredentialItemLink {}
    final class CredentialStatus {}
}

namespace {
    require_once dirname(__DIR__, 2) . '/src/Service/CredentialAdminService.php';

    $ITEMS = [];
    // The first 50 matching rows are not readable/updatable. A valid destination
    // exists on the next SQL page and must not be hidden by the old fixed limit.
    for ($id = 1; $id <= 60; $id++) {
        $ITEMS[$id] = [
            'id' => $id,
            'name' => 'Madero ' . str_pad((string) $id, 3, '0', STR_PAD_LEFT),
            'entities_id' => 4,
            'is_deleted' => 0,
            'can_read' => $id > 50,
            'can_update' => $id > 50,
        ];
    }
    // A matching name in another entity must never be returned.
    $ITEMS[61] = [
        'id' => 61,
        'name' => 'Madero 061',
        'entities_id' => 5,
        'is_deleted' => 0,
        'can_read' => true,
        'can_update' => true,
    ];
    // A deleted record must not be returned.
    $ITEMS[62] = [
        'id' => 62,
        'name' => 'Madero 062',
        'entities_id' => 4,
        'is_deleted' => 1,
        'can_read' => true,
        'can_update' => true,
    ];

    $admin_source = file_get_contents(dirname(__DIR__, 2) . '/src/Service/CredentialAdminService.php');
    $search_start = strpos($admin_source, 'public static function searchRestorableItemsForEntity');
    $search_end = strpos($admin_source, 'private static function buildExistingLinkLockPlan', $search_start);
    $search_body = substr($admin_source, $search_start, $search_end - $search_start);
    if (str_contains($search_body, 'break 2;')) {
        throw new RuntimeException('A popular itemtype must not prevent searching later supported itemtypes.');
    }

    $DB = new FakeDB();
    $results = \GlpiPlugin\Credentials\Service\CredentialAdminService::searchRestorableItemsForEntity(4, 'Madero', 50);
    if (count($results) !== 10) {
        throw new RuntimeException('The paginated search must find eligible results beyond the first 50 inaccessible rows.');
    }
    $ids = array_map(static fn(array $row): int => (int) substr((string) $row['value'], strrpos((string) $row['value'], '|') + 1), $results);
    sort($ids);
    if ($ids !== range(51, 60)) {
        throw new RuntimeException('Search results must be scoped to the entity and exclude unreadable, non-updatable and deleted items.');
    }
    if (count($DB->requests) < 2) {
        throw new RuntimeException('The search must continue to a second bounded SQL page.');
    }
    foreach ($DB->requests as $query) {
        if (($query['LIMIT'] ?? 0) > 50 || ($query['WHERE']['entities_id'] ?? null) !== 4) {
            throw new RuntimeException('Each name search query must remain bounded and entity scoped.');
        }
    }

    echo "Restoration item search pagination, entity and native permission regression: OK\n";
}
