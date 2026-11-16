<?php

declare(strict_types=1);

namespace {
    $root = dirname(__DIR__, 2);
    class CommonDBTM
    {
        public static function getTable($classname = null): string { return ''; }
        public static function getIndexName(): string { return 'id'; }
    }

    class CommonDBChild extends CommonDBTM {}

    class Session
    {
        public static function haveRight(...$args): bool { return true; }
    }

    class DBmysql
    {
        public static function quoteName(string $name): string
        {
            return '`' . str_replace('`', '``', $name) . '`';
        }
    }

    final class FakeQueryExpression
    {
        public function __construct(public string $expression) {}
        public function getValue(): string { return $this->expression; }
        public function getParams(): array { return []; }
    }

    class_alias(FakeQueryExpression::class, 'Glpi\\DBAL\\QueryExpression');

    final class FakeLinkIterator implements \IteratorAggregate
    {
        public function __construct(private array $rows) {}
        public function getIterator(): \Traversable { yield from $this->rows; }
    }

    final class FakeLinkDB
    {
        public array $rows = [];
        public array $credentialRows = [];
        public array $requests = [];

        public function request(array $criteria): FakeLinkIterator
        {
            $this->requests[] = $criteria;
            $table = $criteria['FROM'] ?? '';
            if ($table === 'glpi_plugin_credentials_credentials') {
                $ids = $criteria['WHERE']['id'] ?? [];
                $ids = is_array($ids) ? array_map('intval', $ids) : [(int) $ids];
                return new FakeLinkIterator(array_values(array_filter($this->credentialRows, static fn(array $row): bool => in_array((int) $row['id'], $ids, true))));
            }
            $ids = $criteria['WHERE']['plugin_credentials_credentials_id'] ?? [];
            $ids = is_array($ids) ? array_map('intval', $ids) : [(int) $ids];
            $rows = array_values(array_filter($this->rows, static fn(array $row): bool => in_array((int) $row['plugin_credentials_credentials_id'], $ids, true)));
            if (isset($criteria['SELECT']['COUNT'])) {
                $counts = [];
                foreach ($rows as $row) $counts[$row['plugin_credentials_credentials_id']] = ($counts[$row['plugin_credentials_credentials_id']] ?? 0) + 1;
                $rows = [];
                foreach ($counts as $id => $count) $rows[] = ['plugin_credentials_credentials_id' => $id, 'link_count' => $count];
            }
            return new FakeLinkIterator($rows);
        }
    }
}

namespace GlpiPlugin\Credentials {
    final class Credential
    {
        public static function getTable(): string { return 'glpi_plugin_credentials_credentials'; }
    }

    final class CredentialItem
    {
        public static function isSupportedItemtype(string $itemtype): bool { return true; }
    }
}

namespace {
    require_once dirname(__DIR__, 2) . '/src/CredentialItemLink.php';

    $DB = new FakeLinkDB();
    $DB->rows = [
        ['plugin_credentials_credentials_id' => 10, 'itemtype' => 'Computer', 'items_id' => 4],
        ['plugin_credentials_credentials_id' => 10, 'itemtype' => 'User', 'items_id' => 2],
        ['plugin_credentials_credentials_id' => 10, 'itemtype' => 'User', 'items_id' => 2],
        ['plugin_credentials_credentials_id' => 11, 'itemtype' => 'NetworkEquipment', 'items_id' => 8],
        ['plugin_credentials_credentials_id' => 12, 'itemtype' => 'Computer', 'items_id' => 9],
    ];

    $links = \GlpiPlugin\Credentials\CredentialItemLink::getLinksForCredentials([11, 10, 11, 0, -1]);
    $expected = [
        10 => [
            ['itemtype' => 'Computer', 'items_id' => 4],
            ['itemtype' => 'User', 'items_id' => 2],
        ],
        11 => [
            ['itemtype' => 'NetworkEquipment', 'items_id' => 8],
        ],
    ];
    if ($links !== $expected) {
        throw new \RuntimeException('Batched relationship lookup must normalize IDs, scope results and deduplicate each linked item.');
    }

    $query = $DB->requests[0] ?? [];
    if (($query['FROM'] ?? '') !== 'glpi_plugin_credentials_items'
        || ($query['WHERE']['plugin_credentials_credentials_id'] ?? []) !== [10, 11]
        || ($query['SELECT'] ?? []) !== ['plugin_credentials_credentials_id', 'itemtype', 'items_id']
    ) {
        throw new \RuntimeException('Batched linked-item lookup must use one scoped GLPI DBAL request.');
    }

    // Verify bounded current-read queries, parent-row reads and grouped counters.
    $DB->requests = [];
    $ids = range(1, 520);
    $DB->rows = [];
    $DB->credentialRows = [];
    foreach ($ids as $id) {
        $DB->credentialRows[] = ['id' => $id, 'name' => 'Credential ' . $id];
        $DB->rows[] = ['plugin_credentials_credentials_id' => $id, 'itemtype' => 'Computer', 'items_id' => 1000 + $id];
    }
    $DB->rows[] = ['plugin_credentials_credentials_id' => 1, 'itemtype' => 'User', 'items_id' => 7];

    $lockedLinks = \GlpiPlugin\Credentials\CredentialItemLink::getLinksForCredentialsForUpdate($ids);
    if (count($lockedLinks) !== 520 || count($lockedLinks[1] ?? []) !== 2 || count($DB->requests) !== 3) {
        throw new \RuntimeException('Locking relationship retrieval must be deduplicated and grouped into bounded 250-ID chunks.');
    }
    foreach ($DB->requests as $request) {
        $order = $request['ORDER'][0] ?? null;
        if (!$order instanceof FakeQueryExpression || !str_contains($order->expression, 'FOR UPDATE')) {
            throw new \RuntimeException('Every batched relation read must remain a GLPI DBAL current locking read.');
        }
    }

    $DB->requests = [];
    $credentials = \GlpiPlugin\Credentials\CredentialItemLink::getCredentialsForUpdate($ids);
    if (count($credentials) !== 520 || count($DB->requests) !== 3) {
        throw new \RuntimeException('Credential current reads must be fetched in bounded batches.');
    }
    foreach ($DB->requests as $request) {
        $order = $request['ORDER'][0] ?? null;
        if (!$order instanceof FakeQueryExpression || !str_contains($order->expression, 'FOR UPDATE')) {
            throw new \RuntimeException('Every credential batch must remain a current locking read.');
        }
    }

    $DB->requests = [];
    $counts = \GlpiPlugin\Credentials\CredentialItemLink::getLinkCounts($ids);
    if (($counts[1] ?? 0) !== 2 || ($counts[2] ?? 0) !== 1 || count($DB->requests) !== 2) {
        throw new \RuntimeException('Grouped relationship counts must return exact counts in bounded 500-ID batches.');
    }
    foreach ($DB->requests as $request) {
        if (!isset($request['SELECT']['COUNT']) || !isset($request['GROUPBY'])) {
            throw new \RuntimeException('Grouped relationship counts must use a DBAL COUNT aggregate and GROUP BY.');
        }
    }

    echo "Batched archived linked-item, current-read and count-query regressions: OK\n";
}
