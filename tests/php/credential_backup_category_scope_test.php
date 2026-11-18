<?php
/**
 * Regression tests for exact category-scope restoration from credential backups.
 * Run with: php tests/php/credential_backup_category_scope_test.php
 */
declare(strict_types=1);

namespace {
    if (!function_exists('mb_strlen')) {
        function mb_strlen(string $value): int
        {
            return strlen($value);
        }
    }

    final class DBmysql
    {
        public static function quoteName(string $name): string
        {
            return '`' . str_replace('`', '``', $name) . '`';
        }
    }

    final class Session
    {
        public static function haveAccessToEntity(int $entities_id): bool
        {
            return in_array($entities_id, [0, 7, 8], true);
        }
    }

    final class FakeCategoryIterator
    {
        /** @param array<int, array<string,mixed>> $rows */
        public function __construct(private array $rows)
        {
        }

        public function current(): ?array
        {
            return $this->rows[0] ?? null;
        }
    }

    final class FakeCategoryDB
    {
        /** @var list<array{id:int,name:string,entities_id:int,comment:string,is_deleted:int}> */
        public array $categories = [];
        /** @var list<array<string,mixed>> */
        public array $inserted = [];
        private int $next_id = 100;
        private int $affected_rows = 1;
        public array $last_request = [];
        /** @var array<int, array<string,mixed>> */
        public array $entity_lock_rows = [];

        public function beginTransaction(): void {}
        public function commit(): void {}
        public function rollBack(): void {}

        public function update(string $table, array $fields, array $where): bool
        {
            if ($table !== 'glpi_plugin_credentials_entity_locks' || !isset($where['entities_id'])) {
                throw new RuntimeException('Unexpected plugin entity-lock update.');
            }
            $entities_id = (int) $where['entities_id'];
            if (!isset($this->entity_lock_rows[$entities_id])) {
                $this->affected_rows = 0;
                return true;
            }
            $this->entity_lock_rows[$entities_id] = array_merge($this->entity_lock_rows[$entities_id], $fields);
            $this->affected_rows = 1;
            return true;
        }

        public function getAffectedRows(): int
        {
            return $this->affected_rows;
        }

        public function request(array $criteria): FakeCategoryIterator
        {
            $this->last_request = $criteria;
            $table = $criteria['FROM'] ?? '';
            if ($table !== 'glpi_plugin_credentials_categories') {
                throw new RuntimeException('Unexpected category query table: ' . (string) $table);
            }

            $where = $criteria['WHERE'] ?? [];
            $rows = array_values(array_filter($this->categories, static function (array $row) use ($where): bool {
                foreach ($where as $field => $expected) {
                    $actual = $row[$field] ?? null;
                    if (is_array($expected)) {
                        if (!in_array($actual, $expected, true)) {
                            return false;
                        }
                    } elseif ($actual !== $expected) {
                        return false;
                    }
                }
                return true;
            }));

            if (($criteria['ORDER'] ?? null) === ['entities_id DESC']) {
                usort($rows, static fn(array $a, array $b): int => $b['entities_id'] <=> $a['entities_id']);
            } else {
                usort($rows, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
            }
            if (isset($criteria['LIMIT'])) {
                $rows = array_slice($rows, 0, (int) $criteria['LIMIT']);
            }
            return new FakeCategoryIterator($rows);
        }

        public function insert(string $table, array $fields): int|false
        {
            if ($table === 'glpi_plugin_credentials_entity_locks') {
                $entities_id = (int) ($fields['entities_id'] ?? -1);
                if (isset($this->entity_lock_rows[$entities_id])) {
                    throw new RuntimeException('Duplicate plugin entity-lock row.');
                }
                $this->entity_lock_rows[$entities_id] = $fields;
                return 1;
            }
            if ($table !== 'glpi_plugin_credentials_categories') {
                throw new RuntimeException('Unexpected insert table: ' . $table);
            }
            $id = $this->next_id++;
            $this->inserted[] = ['id' => $id] + $fields;
            $this->categories[] = ['id' => $id] + $fields;
            return $id;
        }
    }
}

namespace Glpi\DBAL {
    final class QueryExpression
    {
        public function __construct(public string $expression)
        {
        }

        public function getValue(): string { return $this->expression; }

        public function getParams(): array { return []; }
    }
}

namespace {
    require_once dirname(__DIR__, 2) . '/src/CredentialCategory.php';

    function assertCategoryScope(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
        echo "PASS: {$message}\n";
    }

    $_SESSION = ['glpi_currenttime' => '2026-11-14 07:00:00'];
    $db = new FakeCategoryDB();
    $GLOBALS['DB'] = $db;

    // The destination already has a global category with the imported local name.
    $db->categories[] = [
        'id' => 10,
        'name' => 'Servers',
        'entities_id' => 0,
        'comment' => 'Global category',
        'is_deleted' => 0,
    ];
    $local_id = \GlpiPlugin\Credentials\CredentialCategory::findOrCreateInExactScope(
        'Servers', 7, 'Imported local category'
    );
    assertCategoryScope($local_id === 100, 'entity-scoped import creates a local category instead of reusing a global one');
    assertCategoryScope($db->inserted[0]['entities_id'] === 7, 'created local category retains the destination entity scope');
    assertCategoryScope($db->inserted[0]['comment'] === 'Imported local category', 'imported local category comment is retained');
    assertCategoryScope(str_contains($db->last_request['ORDER'][0]->getValue(), 'FOR UPDATE'), 'exact category lookup uses a DBAL current read after acquiring the entity lock');
    assertCategoryScope($db->last_request['WHERE']['entities_id'] === 7, 'exact category lookup constrains only the requested destination scope');

    // When both scopes exist, exact-scope lookup must prefer the local record.
    $db->categories[] = [
        'id' => 11,
        'name' => 'Databases',
        'entities_id' => 0,
        'comment' => 'Global',
        'is_deleted' => 0,
    ];
    $db->categories[] = [
        'id' => 12,
        'name' => 'Databases',
        'entities_id' => 7,
        'comment' => 'Local',
        'is_deleted' => 0,
    ];
    $found_local = \GlpiPlugin\Credentials\CredentialCategory::findOrCreateInExactScope('Databases', 7);
    assertCategoryScope($found_local === 12, 'exact entity-scope lookup reuses only the local category when both scopes exist');

    $found_global = \GlpiPlugin\Credentials\CredentialCategory::findOrCreateInExactScope('Servers', 0);
    assertCategoryScope($found_global === 10, 'global-scope import resolves only the global category');

    // Preserve the prior fallback semantics used by entity transfer.
    $transfer_fallback = \GlpiPlugin\Credentials\CredentialCategory::findOrCreateByName('Servers', 8);
    assertCategoryScope($transfer_fallback === 10, 'existing transfer resolver retains its local-then-global fallback behavior');

    // Invalid scopes or names must not create rows.
    $invalid = \GlpiPlugin\Credentials\CredentialCategory::findOrCreateInExactScope('', 7);
    assertCategoryScope($invalid === 0, 'empty imported category names are rejected');

    $service = file_get_contents(dirname(__DIR__, 2) . '/src/Service/CredentialBackupService.php');
    assertCategoryScope(str_contains($service, 'CredentialCategory::findOrCreateInExactScope('), 'backup import is wired to the exact-scope resolver');

    echo "All backup category-scope regression tests passed.\n";
}
