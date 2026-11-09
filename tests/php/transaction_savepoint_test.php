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

final class FakeCredentialDB
{
    public int $transactionLevel = 1; // GLPI owns an outer transaction.
    public int $commitCalls = 0;
    public int $rollbackCalls = 0;
    public bool $failUpdate = false;
    public int $affectedRows = 0;
    public array $updates = [];
    private bool $linksDeleted = false;

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

    public function request(array $query): ArrayObject
    {
        if (($query['SELECT'] ?? []) === ['plugin_credentials_credentials_id']) {
            return new ArrayObject([['plugin_credentials_credentials_id' => 17]]);
        }
        if (!$this->linksDeleted) {
            return new ArrayObject([['id' => 9]]);
        }
        return new ArrayObject();
    }

    public function delete(string $table, array $criteria): bool
    {
        $this->linksDeleted = true;
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
