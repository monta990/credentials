<?php

declare(strict_types=1);

final class Session
{
    public static bool $canAccess = true;

    public static function haveAccessToEntity(int $entities_id): bool
    {
        return self::$canAccess;
    }
}

final class DBmysql
{
    public static function quoteName(string $name): string
    {
        return chr(96) . str_replace(chr(96), chr(96) . chr(96), $name) . chr(96);
    }

    public static function quoteValue(int|string $value): string
    {
        return "'" . addslashes((string) $value) . "'";
    }
}

final class FakeCategoryResult
{
    private bool $read = false;

    public function __construct(public array|false $row) {}

    public function take(): array|false
    {
        if ($this->read) {
            return false;
        }
        $this->read = true;
        return $this->row;
    }
}

final class FakeCategoryIterator implements IteratorAggregate, Countable
{
    public function __construct(private array $rows) {}

    public function current(): mixed
    {
        return $this->rows[0] ?? null;
    }

    public function getIterator(): Traversable
    {
        yield from $this->rows;
    }

    public function count(): int
    {
        return count($this->rows);
    }
}

final class FakeCategoryDB
{
    public array $tables = [];
    public array $updates = [];
    public array $entityLocks = [];
    public array $queryLog = [];
    public mixed $beforeEntityLock = null;
    private int $transactionLevel = 0;
    private int $nextCategoryId = 5;
    private array $existingEntities = [0, 7, 8];

    public function beginTransaction(): void
    {
        $this->transactionLevel++;
        $this->queryLog[] = 'BEGIN';
    }

    public function commit(): void
    {
        if ($this->transactionLevel < 1) {
            throw new RuntimeException('No transaction to commit');
        }
        $this->transactionLevel--;
        $this->queryLog[] = 'COMMIT';
    }

    public function rollBack(): void
    {
        if ($this->transactionLevel < 1) {
            throw new RuntimeException('No transaction to roll back');
        }
        $this->transactionLevel--;
        $this->queryLog[] = 'ROLLBACK';
    }

    public function isInTransaction(): bool
    {
        return $this->transactionLevel > 0;
    }

    public function doQuery(string $query): FakeCategoryResult|false
    {
        $this->queryLog[] = $query;
        if (!str_contains($query, 'FOR UPDATE') || !str_contains($query, 'glpi_entities')) {
            return false;
        }

        preg_match("/= '([0-9]+)' FOR UPDATE$/", $query, $matches);
        $entityId = (int) ($matches[1] ?? -1);
        if ($entityId < 0) {
            return false;
        }

        $this->entityLocks[] = $entityId;
        if (is_callable($this->beforeEntityLock)) {
            $callback = $this->beforeEntityLock;
            $this->beforeEntityLock = null;
            $callback($this, $entityId);
        }

        return new FakeCategoryResult(
            in_array($entityId, $this->existingEntities, true) ? ['id' => $entityId] : false
        );
    }

    public function fetchAssoc(FakeCategoryResult $result): array|false
    {
        return $result->take();
    }

    public function freeResult(FakeCategoryResult $result): bool
    {
        return true;
    }

    public function request(array $criteria): FakeCategoryIterator
    {
        $this->queryLog[] = 'REQUEST';
        $table = (string) ($criteria['FROM'] ?? '');
        $rows = $this->tables[$table] ?? [];
        foreach (($criteria['WHERE'] ?? []) as $field => $expected) {
            $rows = array_values(array_filter(
                $rows,
                static function (array $row) use ($field, $expected): bool {
                    if (!array_key_exists($field, $row)) {
                        return false;
                    }
                    return is_array($expected)
                        ? in_array($row[$field], $expected, true)
                        : (string) $row[$field] === (string) $expected;
                }
            ));
        }

        if (isset($criteria['LIMIT'])) {
            $rows = array_slice($rows, 0, (int) $criteria['LIMIT']);
        }

        return new FakeCategoryIterator($rows);
    }

    public function insert(string $table, array $data): int|false
    {
        if ($table !== 'glpi_plugin_credentials_categories') {
            return false;
        }
        $id = $this->nextCategoryId++;
        $this->tables[$table][] = ['id' => $id] + $data;
        return $id;
    }

    public function update(string $table, array $data, array $where): bool
    {
        $this->updates[] = ['table' => $table, 'data' => $data, 'where' => $where];
        $changed = false;
        foreach ($this->tables[$table] ?? [] as $index => $row) {
            $matches = true;
            foreach ($where as $field => $expected) {
                if (!array_key_exists($field, $row) || (string) $row[$field] !== (string) $expected) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                $this->tables[$table][$index] = array_merge($row, $data);
                $changed = true;
            }
        }

        return $changed;
    }

    public function seedConcurrentCategory(int $entityId, string $name): void
    {
        $this->tables['glpi_plugin_credentials_categories'][] = [
            'id' => $this->nextCategoryId++,
            'name' => $name,
            'entities_id' => $entityId,
            'comment' => 'Inserted by competing administrator',
            'date_creation' => '2026-11-13 12:00:00',
            'date_mod' => '2026-11-13 12:00:00',
            'is_deleted' => 0,
        ];
    }
}

$DB = new FakeCategoryDB();
$DB->tables['glpi_plugin_credentials_categories'] = [
    [
        'id' => 1,
        'name' => 'Network',
        'entities_id' => 7,
        'comment' => 'Old description',
        'date_creation' => '2026-01-01 00:00:00',
        'date_mod' => '2026-01-01 00:00:00',
        'is_deleted' => 0,
    ],
    [
        'id' => 2,
        'name' => 'Operations',
        'entities_id' => 7,
        'comment' => '',
        'date_creation' => '2026-01-01 00:00:00',
        'date_mod' => '2026-01-01 00:00:00',
        'is_deleted' => 0,
    ],
    [
        'id' => 3,
        'name' => 'Shared',
        'entities_id' => 0,
        'comment' => 'Global category',
        'date_creation' => '2026-01-01 00:00:00',
        'date_mod' => '2026-01-01 00:00:00',
        'is_deleted' => 0,
    ],
    [
        'id' => 4,
        'name' => 'Archived',
        'entities_id' => 7,
        'comment' => '',
        'date_creation' => '2026-01-01 00:00:00',
        'date_mod' => '2026-01-01 00:00:00',
        'is_deleted' => 1,
    ],
];
$_SESSION = ['glpi_currenttime' => '2026-11-13 12:00:00'];

require_once __DIR__ . '/../../src/CredentialCategory.php';

function assertCategoryResult(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$label}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

assertCategoryResult(
    true,
    \GlpiPlugin\Credentials\CredentialCategory::update(1, '  Network Operations  ', '  Updated securely  '),
    'a valid category update succeeds'
);
$updated = $DB->tables['glpi_plugin_credentials_categories'][0];
assertCategoryResult('Network Operations', $updated['name'], 'category names are trimmed');
assertCategoryResult('Updated securely', $updated['comment'], 'category descriptions are trimmed');
assertCategoryResult(7, $updated['entities_id'], 'category entity scope is preserved');
assertCategoryResult('2026-01-01 00:00:00', $updated['date_creation'], 'creation timestamp is preserved');
assertCategoryResult('2026-11-13 12:00:00', $updated['date_mod'], 'modification timestamp is refreshed');
assertCategoryResult('glpi_plugin_credentials_categories', $DB->updates[0]['table'], 'updates touch only the category table');
assertCategoryResult(7, $DB->entityLocks[0], 'category edit locks its GLPI entity');
assertCategoryResult(false, $DB->isInTransaction(), 'successful category edit releases its transaction');

assertCategoryResult(
    true,
    \GlpiPlugin\Credentials\CredentialCategory::update(1, 'Network Operations', 'Changed description'),
    'a category can be edited without changing its current name'
);
assertCategoryResult(
    false,
    \GlpiPlugin\Credentials\CredentialCategory::update(1, 'Operations', 'Duplicate name'),
    'duplicate names in the same entity are rejected'
);
assertCategoryResult(
    'Network Operations',
    $DB->tables['glpi_plugin_credentials_categories'][0]['name'],
    'a rejected duplicate leaves the original category unchanged'
);

assertCategoryResult(false, \GlpiPlugin\Credentials\CredentialCategory::update(1, '', 'Empty name'), 'empty names are rejected');
assertCategoryResult(false, \GlpiPlugin\Credentials\CredentialCategory::update(1, str_repeat('N', 256)), 'names longer than 255 characters are rejected');
assertCategoryResult(false, \GlpiPlugin\Credentials\CredentialCategory::update(1, 'Network', str_repeat('D', 2001)), 'descriptions longer than 2000 characters are rejected');
assertCategoryResult(false, \GlpiPlugin\Credentials\CredentialCategory::update(0, 'Invalid ID'), 'invalid category IDs are rejected');
assertCategoryResult(false, \GlpiPlugin\Credentials\CredentialCategory::update(99, 'Missing'), 'missing categories cannot be updated');
assertCategoryResult(false, \GlpiPlugin\Credentials\CredentialCategory::update(4, 'Archived'), 'soft-deleted categories cannot be updated');

Session::$canAccess = false;
assertCategoryResult(false, \GlpiPlugin\Credentials\CredentialCategory::update(1, 'Unauthorized'), 'users without entity access cannot update categories');
Session::$canAccess = true;

assertCategoryResult(true, \GlpiPlugin\Credentials\CredentialCategory::update(3, 'Shared Services', 'Global description'), 'global categories remain editable when global entity access exists');
assertCategoryResult(0, $DB->tables['glpi_plugin_credentials_categories'][2]['entities_id'], 'editing a global category does not move it to the current entity');

$DB->beforeEntityLock = static function (FakeCategoryDB $database, int $entityId): void {
    // Simulate a competing administrator committing this name before the lock
    // for the current request is granted.
    $database->seedConcurrentCategory($entityId, 'Concurrent Service');
};
assertCategoryResult(
    false,
    \GlpiPlugin\Credentials\CredentialCategory::update(1, 'Concurrent Service', 'Must be rejected'),
    'an update waiting behind a competing entity writer re-checks uniqueness after acquiring the lock'
);
assertCategoryResult('Network Operations', $DB->tables['glpi_plugin_credentials_categories'][0]['name'], 'a competing same-name category prevents the second update');
assertCategoryResult(7, $DB->entityLocks[count($DB->entityLocks) - 1], 'category update locks its entity before checking uniqueness');
assertCategoryResult(false, $DB->isInTransaction(), 'duplicate rejection releases its transaction');

$DB->beforeEntityLock = static function (FakeCategoryDB $database, int $entityId): void {
    $database->seedConcurrentCategory($entityId, 'Concurrent New Category');
};
assertCategoryResult(
    false,
    \GlpiPlugin\Credentials\CredentialCategory::create('Concurrent New Category', 7, 'Must be rejected'),
    'category creation serializes with competing same-name writes'
);

$sharedId = \GlpiPlugin\Credentials\CredentialCategory::findOrCreateByName('Shared', 7);
assertCategoryResult(3, $sharedId, 'findOrCreate reuses a global category while holding the entity lock');

$createdId = \GlpiPlugin\Credentials\CredentialCategory::findOrCreateByName('ERP', 7);
assertCategoryResult(7, $createdId, 'findOrCreate creates a new category inside the entity lock');
assertCategoryResult(7, $DB->tables['glpi_plugin_credentials_categories'][6]['entities_id'], 'findOrCreate keeps the local entity scope');

assertCategoryResult(
    true,
    \GlpiPlugin\Credentials\CredentialCategory::create('Cloud', 8, 'New entity category'),
    'category creation succeeds for an unused name'
);
assertCategoryResult(8, $DB->tables['glpi_plugin_credentials_categories'][7]['entities_id'], 'new category preserves requested entity scope');

assertCategoryResult(false, \GlpiPlugin\Credentials\CredentialCategory::create('Cloud', 8), 'same-entity duplicate category creation is rejected');
assertCategoryResult(false, \GlpiPlugin\Credentials\CredentialCategory::create('Invalid entity', 99), 'categories cannot be created for a missing GLPI entity');

echo "Credential category update tests: OK\n";
