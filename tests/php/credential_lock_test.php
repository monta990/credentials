<?php

declare(strict_types=1);

namespace {
    class CommonDBTM
    {
        public static function getTable($classname = null): string { return ''; }
    }
    class Session
    {
        public static function haveRight(...$args): bool { return true; }
    }

    final class FakeCredentialLockDB
    {
        public array $updates = [];
        public int $affected_rows = 1;
        public function update(string $table, array $values, array $where): bool
        {
            $this->updates[] = compact('table', 'values', 'where');
            return true;
        }
        public function getAffectedRows(): int { return $this->affected_rows; }
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
            return $itemtype === 'User';
        }
    }
}

namespace {
    require_once __DIR__ . '/../../src/CredentialItemLink.php';

    $DB = new FakeCredentialLockDB();
    if (!\GlpiPlugin\Credentials\CredentialItemLink::lockCredential(17, 4, true)) {
        throw new RuntimeException('An affected credential row must be locked successfully.');
    }
    $write = $DB->updates[0] ?? null;
    if (!is_array($write)
        || $write['table'] !== 'glpi_plugin_credentials_credentials'
        || !preg_match('/^[a-f0-9]{32}$/', (string) ($write['values']['link_lock_token'] ?? ''))
        || ($write['where'] !== ['id' => 17, 'entities_id' => 4, 'is_deleted' => 0])
    ) {
        throw new RuntimeException('Credential lock must use a random token and atomic id/entity/active criteria.');
    }

    $DB->affected_rows = 0;
    if (\GlpiPlugin\Credentials\CredentialItemLink::lockCredential(18, 4, true)) {
        throw new RuntimeException('A missing, archived or moved credential row must not be treated as locked.');
    }

    if (\GlpiPlugin\Credentials\CredentialItemLink::lockCredential(0)) {
        throw new RuntimeException('Invalid credential IDs must not be locked.');
    }

    $source = file_get_contents(__DIR__ . '/../../src/CredentialItemLink.php');
    if (!is_string($source) || str_contains($source, 'FOR UPDATE') || str_contains($source, 'doQuery(')) {
        throw new RuntimeException('Runtime relationship locking must not issue raw SQL.');
    }

    echo "Credential row-lock API, active/entity guard and no-raw-SQL regression: OK\n";
}
