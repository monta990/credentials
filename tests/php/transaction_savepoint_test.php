<?php

declare(strict_types=1);

namespace {

/**
 * Regression test for nested transaction handling in the item purge hook.
 * The fake DB follows GLPI 12 DBmysql semantics: begin/commit/rollBack return
 * void and each nested begin creates one savepoint level.
 */

class CommonDBTM
{
    public static function getTable($classname = null): string
    {
        return '';
    }
}

class Session
{
    public static function haveRight(string $right, int $level): bool
    {
        return true;
    }
}

final class FakeCredentialDBResult
{
    private int $position = 0;
    public function __construct(private array $rows) {}
    public function fetch_assoc(): array|false { return $this->rows[$this->position++] ?? false; }
    public function free(): void {}
}

class DBmysql
{
    public static function quoteName(string $name): string
    {
        return chr(96) . str_replace(chr(96), chr(96) . chr(96), $name) . chr(96);
    }
    public static function quoteValue(mixed $value): string
    {
        return "'" . addslashes((string) $value) . "'";
    }
}

final class FakeCredentialDB
{
    public int $transactionLevel = 1; // GLPI owns an outer transaction.
    public int $commitCalls = 0;
    public int $rollbackCalls = 0;
    public bool $failUpdate = false;
    public int $affectedRows = 0;
    public array $updates = [];
    public array $links = [
        ['id' => 1, 'plugin_credentials_credentials_id' => 17, 'itemtype' => 'DummyAsset', 'items_id' => 42],
    ];
    public ?Closure $afterLock = null;
    private ?array $consistentSnapshot = null;

    public function getAffectedRows(): int
    {
        return $this->affectedRows;
    }

    public function beginTransaction(): void
    {
        $this->transactionLevel++;
    }

    public function commit(): void
    {
        if ($this->transactionLevel < 2) {
            throw new RuntimeException('Plugin attempted to commit the outer transaction.');
        }
        $this->transactionLevel--;
        $this->commitCalls++;
    }

    public function rollBack(): void
    {
        if ($this->transactionLevel < 2) {
            throw new RuntimeException('Plugin attempted to roll back the outer transaction.');
        }
        $this->transactionLevel--;
        $this->rollbackCalls++;
    }

    public function request(array $query): FakeCredentialDBResult
    {
        // Normal SELECTs keep the REPEATABLE READ snapshot created by first read.
        $this->consistentSnapshot ??= $this->links;
        $rows = $this->consistentSnapshot;
        foreach (($query['WHERE'] ?? []) as $field => $expected) {
            $rows = array_values(array_filter($rows, static function (array $row) use ($field, $expected): bool {
                return (string) ($row[$field] ?? null) === (string) $expected;
            }));
        }
        $select = $query['SELECT'] ?? '*';
        if (is_array($select)) {
            $rows = array_map(static fn(array $row): array => array_intersect_key($row, array_flip($select)), $rows);
        }
        return new FakeCredentialDBResult($rows);
    }

    public function doQuery(string $query): FakeCredentialDBResult
    {
        if (!str_contains($query, 'FOR UPDATE')
            || !preg_match("/plugin_credentials_credentials_id\\s*=\\s*'([0-9]+)'/i", $query, $match)
        ) {
            throw new RuntimeException('Purge must use a current locking read scoped to one credential.');
        }
        $credentialId = (int) $match[1];
        $rows = array_values(array_filter(
            $this->links,
            static fn(array $row): bool => (int) $row['plugin_credentials_credentials_id'] === $credentialId
        ));
        $rows = array_map(static fn(array $row): array => [
            'itemtype' => $row['itemtype'],
            'items_id' => $row['items_id'],
        ], $rows);
        return new FakeCredentialDBResult($rows);
    }

    public function fetchAssoc(FakeCredentialDBResult $result): array|false { return $result->fetch_assoc(); }
    public function freeResult(FakeCredentialDBResult $result): bool { $result->free(); return true; }

    public function delete(string $table, array $criteria): bool
    {
        $this->links = array_values(array_filter($this->links, static function (array $row) use ($criteria): bool {
            foreach ($criteria as $field => $expected) {
                if ((string) ($row[$field] ?? null) !== (string) $expected) {
                    return true;
                }
            }
            return false;
        }));
        return true;
    }

    public function update(string $table, array $values, array $criteria): bool
    {
        $this->updates[] = compact('table', 'values', 'criteria');
        if ($this->failUpdate) {
            $this->affectedRows = 0;
            return false;
        }
        if ($table !== 'glpi_plugin_credentials_credentials' || ($criteria['id'] ?? null) !== 17) {
            throw new RuntimeException('Credential lock/archive must target the correct GLPI credential row.');
        }
        $this->affectedRows = 1;
        if (isset($values['link_lock_token']) && $this->afterLock !== null) {
            $callback = $this->afterLock;
            $this->afterLock = null;
            $callback($this);
        }
        return true;
    }
}

}

namespace GlpiPlugin\Credentials {
final class CredentialItem
{
    public static function isSupportedItemtype(string $itemtype): bool
    {
        return $itemtype === 'DummyAsset';
    }
}

final class Credential
{
    public const HISTORY_ORPHANED = 7;

    public static function getTable(): string
    {
        return 'glpi_plugin_credentials_credentials';
    }
}

final class CredentialHistory
{
    public static int $orphanEvents = 0;

    public static function recordForCredential(int $credential_id, int $action): bool
    {
        if ($credential_id !== 17 || $action !== Credential::HISTORY_ORPHANED) {
            return false;
        }
        self::$orphanEvents++;
        return true;
    }
}
}

namespace {
$root = dirname(__DIR__, 2);
require $root . '/src/CredentialItemLink.php';

// Success must release only the plugin savepoint and leave GLPI's outer
// transaction open for the caller to commit.
$DB = new FakeCredentialDB();
\GlpiPlugin\Credentials\CredentialItemLink::cleanupForItem('DummyAsset', 42);
if ($DB->transactionLevel !== 1 || $DB->commitCalls !== 1 || $DB->rollbackCalls !== 0) {
    throw new RuntimeException('Successful cleanup changed the caller transaction level.');
}
if (count($DB->updates) !== 2
    || !isset($DB->updates[0]['values']['link_lock_token'])
    || ($DB->updates[1]['values']['is_deleted'] ?? null) !== 1
    || \GlpiPlugin\Credentials\CredentialHistory::$orphanEvents !== 1
) {
    throw new RuntimeException('Purge must use the GLPI DB lock API, archive the orphan and record secret-free credential history.');
}

// link_existing commits a survivor while purge waits for the credential lock.
// The consistent snapshot stays stale; the current locking read must see the new row.
$DB = new FakeCredentialDB();
$DB->afterLock = static function (FakeCredentialDB $database): void {
    $database->links[] = [
        'id' => 2,
        'plugin_credentials_credentials_id' => 17,
        'itemtype' => 'DummyAsset',
        'items_id' => 99,
    ];
};
\GlpiPlugin\Credentials\CredentialHistory::$orphanEvents = 0;
\GlpiPlugin\Credentials\CredentialItemLink::cleanupForItem('DummyAsset', 42);
if (count($DB->links) !== 1
    || (int) $DB->links[0]['items_id'] !== 99
    || count($DB->updates) !== 1
    || \GlpiPlugin\Credentials\CredentialHistory::$orphanEvents !== 0
) {
    throw new RuntimeException('Purge must preserve a credential with a survivor committed during its lock wait.');
}

// Failure must roll back only the plugin savepoint and leave the outer
// transaction open so GLPI can roll back the complete purge.
$DB = new FakeCredentialDB();
$DB->failUpdate = true;
try {
    \GlpiPlugin\Credentials\CredentialItemLink::cleanupForItem('DummyAsset', 42);
    throw new RuntimeException('Expected cleanup failure was not raised.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'Expected cleanup failure was not raised.') {
        throw $exception;
    }
}
if ($DB->transactionLevel !== 1 || $DB->commitCalls !== 0 || $DB->rollbackCalls !== 1) {
    throw new RuntimeException('Failed cleanup changed the caller transaction level.');
}

echo "Nested transaction savepoint regression: OK\n";

}
