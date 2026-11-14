<?php

declare(strict_types=1);

namespace {
    class CommonDBTM
    {
        public static function getTable($classname = null): string { return ''; }
        public static function getIndexName(): string { return 'id'; }
        /** @var array<string,mixed> */
        public array $fields = [];
        public function getFromResultSet(array $row): void { $this->fields = $row; }
    }

    class CommonDBConnexity extends CommonDBTM {}

    class CommonDBChild extends CommonDBConnexity
    {
        public static string $items_id = 'databaseinstances_id';
        public static string $itemtype = 'DatabaseInstance';
        public static function getTable($classname = null): string { return 'glpi_databases'; }
        public static function getIndexName(): string { return 'id'; }
    }

    class Database extends CommonDBChild
    {
        public function getFromResultSet(array $row): void { $this->fields = $row; }
    }

    class DatabaseInstance extends CommonDBTM
    {
        public static function getTable($classname = null): string { return 'glpi_databaseinstances'; }
        public static function getIndexName(): string { return 'id'; }
        public function getFromResultSet(array $row): void { $this->fields = $row; }
    }

    class Session
    {
        public static function haveRight(...$args): bool { return true; }
    }

    class DBmysql
    {
        public static function quoteName(string $name): string
        {
            return chr(96) . str_replace(chr(96), chr(96) . chr(96), $name) . chr(96);
        }
    }

    final class FakeQueryExpression
    {
        public function __construct(public string $expression) {}
        public function getValue(): string { return $this->expression; }
        public function getParams(): array { return []; }
    }

    class_alias(FakeQueryExpression::class, 'Glpi\\DBAL\\QueryExpression');

    final class FakeCredentialLockIterator implements IteratorAggregate
    {
        /** @param list<array<string,mixed>> $rows */
        public function __construct(private array $rows) {}
        public function current(): ?array { return $this->rows[0] ?? null; }
        public function getIterator(): Traversable { yield from $this->rows; }
    }

    final class FakeCredentialLockDB
    {
        /** @var list<array{table:string,values:array<string,mixed>,where:array<string,mixed>}> */
        public array $updates = [];
        /** @var list<array<string,mixed>> */
        public array $requests = [];
        /** @var list<array<string,mixed>> */
        public array $locking_links = [];
        /** @var array<string,mixed> */
        public array $credential_row = [];
        /** @var array<string,array<string,mixed>> */
        public array $item_rows = [];
        public int $affected_rows = 1;

        public function update(string $table, array $values, array $where): bool
        {
            $this->updates[] = compact('table', 'values', 'where');
            return true;
        }

        public function getAffectedRows(): int { return $this->affected_rows; }

        public function request(array $criteria): FakeCredentialLockIterator
        {
            $this->requests[] = $criteria;
            $table = (string) ($criteria['FROM'] ?? '');
            if ($table === 'glpi_plugin_credentials_items') {
                $where = $criteria['WHERE'] ?? [];
                $rows = array_values(array_filter($this->locking_links, static function (array $row) use ($where): bool {
                    foreach ($where as $field => $expected) {
                        if (array_key_exists($field, $row) && (string) $row[$field] !== (string) $expected) {
                            return false;
                        }
                    }
                    return true;
                }));
                return new FakeCredentialLockIterator($rows);
            }
            if ($table === 'glpi_plugin_credentials_credentials') {
                $id = (int) ($criteria['WHERE']['id'] ?? 0);
                return new FakeCredentialLockIterator(
                    $id > 0 && (int) ($this->credential_row['id'] ?? 0) === $id
                        ? [$this->credential_row]
                        : []
                );
            }
            if ($table === 'glpi_databases') {
                $id = (int) ($criteria['WHERE']['id'] ?? $criteria['WHERE'][$table . '.id'] ?? 0);
                $row = $this->item_rows['Database#' . $id] ?? null;
                return new FakeCredentialLockIterator(is_array($row) ? [$row] : []);
            }
            if ($table === 'glpi_databaseinstances') {
                $id = (int) ($criteria['WHERE']['id'] ?? $criteria['WHERE'][$table . '.id'] ?? 0);
                $row = $this->item_rows['DatabaseInstance#' . $id] ?? null;
                return new FakeCredentialLockIterator(is_array($row) ? [$row] : []);
            }
            throw new RuntimeException('Unexpected locking-read table: ' . $table);
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
        public static function isSupportedItemtype(string $itemtype): bool
        {
            return in_array($itemtype, ['User', 'Database'], true);
        }
    }
}

namespace {
    function getItemForItemtype(string $itemtype): ?\CommonDBTM
    {
        return match ($itemtype) {
            'Database' => new \Database(),
            'DatabaseInstance' => new \DatabaseInstance(),
            default => null,
        };
    }

    require_once __DIR__ . '/../../src/CredentialItemLink.php';

    $DB = new FakeCredentialLockDB();

    if (!\GlpiPlugin\Credentials\CredentialItemLink::lockCredential(17, 4, true)) {
        throw new RuntimeException('An affected credential row must be locked successfully.');
    }
    $write = $DB->updates[0] ?? null;
    if (!is_array($write)
        || $write['table'] !== 'glpi_plugin_credentials_credentials'
        || !preg_match('/^[a-f0-9]{32}$/', (string) ($write['values']['link_lock_token'] ?? ''))
        || $write['where'] !== ['id' => 17, 'entities_id' => 4, 'is_deleted' => 0]
    ) {
        throw new RuntimeException('Credential lock must use a random token and atomic id/entity/active criteria.');
    }

    $DB->affected_rows = 0;
    if (\GlpiPlugin\Credentials\CredentialItemLink::lockCredential(18, 4, true)) {
        throw new RuntimeException('A missing, archived or moved credential row must not be treated as locked.');
    }

    $updates_before_invalid_id = count($DB->updates);
    if (\GlpiPlugin\Credentials\CredentialItemLink::lockCredential(0)
        || count($DB->updates) !== $updates_before_invalid_id
    ) {
        throw new RuntimeException('Invalid credential IDs must not be locked or written.');
    }

    $DB->locking_links = [
        ['plugin_credentials_credentials_id' => 17, 'itemtype' => 'Computer', 'items_id' => 31],
        ['plugin_credentials_credentials_id' => 17, 'itemtype' => 'User', 'items_id' => 7],
        ['plugin_credentials_credentials_id' => 17, 'itemtype' => 'User', 'items_id' => 7],
    ];
    $links = \GlpiPlugin\Credentials\CredentialItemLink::getLinksForCredentialForUpdate(17);
    if ($links !== [
        ['itemtype' => 'Computer', 'items_id' => 31],
        ['itemtype' => 'User', 'items_id' => 7],
    ]) {
        throw new RuntimeException('The current locking read must return deduplicated item relationships.');
    }

    $relationship_query = $DB->requests[0] ?? [];
    $locking_order = $relationship_query['ORDER'][0] ?? null;
    if (($relationship_query['FROM'] ?? null) !== 'glpi_plugin_credentials_items'
        || ($relationship_query['WHERE'] ?? null) !== ['plugin_credentials_credentials_id' => 17]
        || !$locking_order instanceof FakeQueryExpression
        || !str_contains($locking_order->expression, 'FOR UPDATE')
        || !str_contains($locking_order->expression, 'itemtype')
        || !str_contains($locking_order->expression, 'items_id')
    ) {
        throw new RuntimeException('Relationship enumeration must use a scoped DBAL locking expression.');
    }

    $DB->credential_row = [
        'id' => 17,
        'name' => 'Database access',
        'entities_id' => 4,
        'login' => 'glpikey-login-ciphertext-current',
        'password' => 'glpikey-password-ciphertext-current',
        'url' => 'glpikey-url-ciphertext-current',
        'notes' => 'glpikey-notes-ciphertext-current',
        'users_id' => 7,
        'date_creation' => '2026-11-01 10:00:00',
        'date_mod' => '2026-11-09 10:00:00',
        'links_count' => 2,
        'is_deleted' => 0,
    ];
    $credential = \GlpiPlugin\Credentials\CredentialItemLink::getCredentialForUpdate(17);
    if (!is_array($credential)
        || ($credential['password'] ?? null) !== 'glpikey-password-ciphertext-current'
        || ($credential['login'] ?? null) !== 'glpikey-login-ciphertext-current'
    ) {
        throw new RuntimeException('The post-lock credential read must return the latest encrypted credential fields.');
    }

    $credential_query = $DB->requests[1] ?? [];
    if (($credential_query['FROM'] ?? null) !== 'glpi_plugin_credentials_credentials'
        || ($credential_query['WHERE'] ?? null) !== ['id' => 17]
        || ($credential_query['SELECT'] ?? []) !== ['*']
        || !str_contains(($credential_query['ORDER'][0]->expression ?? ''), 'FOR UPDATE')
    ) {
        throw new RuntimeException('Credential data must use a current locking read after acquiring its write lock.');
    }

    // Last-link decisions use the count on the credential parent and must not
    // re-lock child relationship rows after acquiring that parent lock.
    $DB->requests = [];
    $count = \GlpiPlugin\\Credentials\\CredentialItemLink::countForCredentialForUpdate(17);
    $count_query = $DB->requests[0] ?? [];
    if ($count !== 2
        || ($count_query['FROM'] ?? null) !== 'glpi_plugin_credentials_credentials'
        || ($count_query['WHERE'] ?? null) !== ['id' => 17]
        || !str_contains(($count_query['ORDER'][0]->expression ?? ''), 'FOR UPDATE')
    ) {
        throw new RuntimeException('Link count must use the current locked credential parent row, not relationship locks.');
    }

    $DB->requests = [];
    $DB->locking_links = [
        ['plugin_credentials_credentials_id' => 17, 'itemtype' => 'User', 'items_id' => 7],
        ['plugin_credentials_credentials_id' => 17, 'itemtype' => 'User', 'items_id' => 7],
        ['plugin_credentials_credentials_id' => 18, 'itemtype' => 'Computer', 'items_id' => 31],
        ['plugin_credentials_credentials_id' => 18, 'itemtype' => 'User', 'items_id' => 8],
    ];
    $link_counts = \GlpiPlugin\\Credentials\\CredentialItemLink::getCredentialLinkCountsForItemForUpdate('User', 7);
    $counts_query = $DB->requests[0] ?? [];
    if ($link_counts !== [17 => 2]
        || ($counts_query['FROM'] ?? null) !== 'glpi_plugin_credentials_items'
        || ($counts_query['WHERE'] ?? null) !== ['itemtype' => 'User', 'items_id' => 7]
        || !str_contains(($counts_query['ORDER'][0]->expression ?? ''), 'FOR UPDATE')
    ) {
        throw new RuntimeException('Purge must current-read and count only the selected asset relationship rows by credential.');
    }

    $DB->credential_row['links_count'] = 2;
    $DB->affected_rows = 1;
    \GlpiPlugin\\Credentials\\CredentialItemLink::adjustLinkCount(17, -1);
    $count_update = $DB->updates[count($DB->updates) - 1] ?? null;
    if (!is_array($count_update)
        || ($count_update['table'] ?? null) !== 'glpi_plugin_credentials_credentials'
        || ($count_update['values']['links_count'] ?? null) !== 1
        || ($count_update['where'] ?? null) !== ['id' => 17]
    ) {
        throw new RuntimeException('Relationship deletion must update the denormalized credential counter.');
    }

    // Database child: resolve the parent without locking the child, then lock parent and child.
    $DB->requests = [];
    $DB->item_rows = [
        'Database#88' => ['id' => 88, 'databaseinstances_id' => 21, 'name' => 'ERP database'],
        'DatabaseInstance#21' => ['id' => 21, 'entities_id' => 4, 'name' => 'ERP instance'],
    ];
    $child_locked = \GlpiPlugin\Credentials\CredentialItemLink::lockItemForUpdate('Database', 88, 4);
    if (!$child_locked) {
        throw new RuntimeException('Database child lock must succeed when its parent and child rows exist.');
    }

    $probe = $DB->requests[0] ?? [];
    if (($probe['FROM'] ?? null) !== 'glpi_databases'
        || ($probe['WHERE'] ?? null) !== ['id' => 88]
        || ($probe['SELECT'] ?? []) !== ['databaseinstances_id']
        || isset($probe['ORDER'])
    ) {
        throw new RuntimeException('CommonDBChild FK resolution must not lock the child before its parent.');
    }

    $parent_lock = $DB->requests[1] ?? [];
    $child_lock = $DB->requests[2] ?? [];
    if (($parent_lock['FROM'] ?? null) !== 'glpi_databaseinstances'
        || !str_contains(($parent_lock['ORDER'][0]->expression ?? ''), 'FOR UPDATE')
        || ($child_lock['FROM'] ?? null) !== 'glpi_databases'
        || !str_contains(($child_lock['ORDER'][0]->expression ?? ''), 'FOR UPDATE')
    ) {
        throw new RuntimeException('CommonDBChild must lock parent before child and revalidate the FK.');
    }

    $source = file_get_contents(__DIR__ . '/../../src/CredentialItemLink.php');
    if (!is_string($source)
        || !str_contains($source, 'new QueryExpression(')
        || !str_contains($source, 'getLinksForCredentialForUpdate(')
        || str_contains($source, '$DB->doQuery(')
    ) {
        throw new RuntimeException('Current locking reads must use GLPI DBAL QueryExpression, not direct SQL execution.');
    }

    echo "Credential row-lock API, DBAL current-read query and active/entity guard regression: OK\n";
}
