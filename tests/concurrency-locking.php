<?php
/**
 * Standalone regression tests for bulk credential lock ordering.
 * Run with: php tests/concurrency-locking.php
 */
declare(strict_types=1);

namespace {
    class CommonDBTM
    {
        public static function getTable($classname = null): string
        {
            return '';
        }
    }

    class Session
    {
    }
}

namespace GlpiPlugin\Credentials {
    final class Credential
    {
        public static function getTable(): string
        {
            return 'glpi_plugin_credentials_credentials';
        }
    }

    final class CredentialItem
    {
    }

    final class CredentialLinkPolicy
    {
    }
}

namespace {
    final class FakeCredentialDB
    {
        /** @var list<int> */
        public array $locked_ids = [];
        /** @var list<int> */
        public array $missing_ids = [];
        public bool $fail_updates = false;
        private int $affected_rows = 0;

        public function update(string $table, array $fields, array $where): bool
        {
            if ($this->fail_updates) {
                return false;
            }
            if ($table !== 'glpi_plugin_credentials_credentials'
                || !array_key_exists('link_lock_token', $fields)
                || !isset($where['id'])
            ) {
                throw new RuntimeException('Unexpected lock update shape.');
            }

            $id = (int) $where['id'];
            $this->locked_ids[] = $id;
            $this->affected_rows = in_array($id, $this->missing_ids, true) ? 0 : 1;
            return true;
        }

        public function getAffectedRows(): int
        {
            return $this->affected_rows;
        }
    }

    require_once dirname(__DIR__) . '/src/CredentialItemLink.php';

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
        echo "PASS: {$message}\n";
    }

    $db = new FakeCredentialDB();
    $GLOBALS['DB'] = $db;
    $locked = \GlpiPlugin\Credentials\CredentialItemLink::lockCredentials([9, 3, 9, 1, 5]);
    check($locked === [1, 3, 5, 9], 'bulk locks are deduplicated and sorted numerically');
    check($db->locked_ids === [1, 3, 5, 9], 'database lock updates happen in deterministic order');

    $db->locked_ids = [];
    $db->missing_ids = [3];
    $locked = \GlpiPlugin\Credentials\CredentialItemLink::lockCredentials([5, 3, 1]);
    check($locked === [1, 5], 'missing candidate rows are omitted without stopping other locks');
    check($db->locked_ids === [1, 3, 5], 'missing rows do not change deterministic lock order');

    $db->fail_updates = true;
    $thrown = false;
    try {
        \GlpiPlugin\Credentials\CredentialItemLink::lockCredentials([1, 2]);
    } catch (RuntimeException) {
        $thrown = true;
    }
    check($thrown, 'database lock failures abort the operation instead of being treated as missing rows');

    $root = dirname(__DIR__);
    $backup = file_get_contents($root . '/src/Service/CredentialBackupService.php');
    $transfer = file_get_contents($root . '/src/CredentialTransfer.php');
    $admin = file_get_contents($root . '/src/Service/CredentialAdminService.php');
    $links = file_get_contents($root . '/src/CredentialItemLink.php');

    $begin = strpos($backup, '$DB->beginTransaction();');
    $locks = strpos($backup, 'CredentialItemLink::lockCredentials(', $begin);
    $read = strpos($backup, 'self::buildBackup(', $begin);
    check($begin !== false && $locks !== false && $read !== false && $locks < $read,
        'backup locks every candidate before buildBackup performs consistent reads');

    $clone_begin = strpos($transfer, '$DB->beginTransaction();');
    $clone_locks = strpos($transfer, 'CredentialItemLink::lockCredentials(', $clone_begin);
    $clone_read = strpos($transfer, 'self::copyItemRelationships(', $clone_begin);
    check($clone_begin !== false && $clone_locks !== false && $clone_read !== false && $clone_locks < $clone_read,
        'clone transfer locks all credentials before relationship reads');

    $move_begin = strpos($transfer, '$DB->beginTransaction();', $clone_begin + 1);
    $move_locks = strpos($transfer, 'CredentialItemLink::lockCredentials(', $move_begin);
    $move_read = strpos($transfer, 'self::readCredentialAfterLock(', $move_begin);
    check($move_begin !== false && $move_locks !== false && $move_read !== false && $move_locks < $move_read,
        'entity move locks all credentials before reading credential rows');

    $purge_begin = strpos($admin, '$DB->beginTransaction();', strpos($admin, 'public static function purgeEligible'));
    $purge_locks = strpos($admin, 'CredentialItemLink::lockCredentials(', $purge_begin);
    $purge_read = strpos($admin, '$credential->getFromDB($id)', $purge_begin);
    check($purge_begin !== false && $purge_locks !== false && $purge_read !== false && $purge_locks < $purge_read,
        'retention purge locks all candidates before reading credential rows');

    check(str_contains($links, 'public static function lockCredentials(array $credential_ids): array'),
        'shared lock-all helper is present');

    echo "All concurrency lock-ordering regression tests passed.\n";
}
