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

    final class FakeCredentialLockResult
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

    final class FakeCredentialLockDB
    {
        public array $updates = [];
        public array $locking_links = [];
        public array $locking_credential = [];
        public string $locking_query = '';
        public string $credential_locking_query = '';
        public int $affected_rows = 1;
        public function update(string $table, array $values, array $where): bool
        {
            $this->updates[] = compact('table', 'values', 'where');
            return true;
        }
        public function getAffectedRows(): int { return $this->affected_rows; }
        public function doQuery(string $query): FakeCredentialLockResult
        {
            if (str_contains($query, '`glpi_plugin_credentials_credentials`')) {
                $this->credential_locking_query = $query;
                return new FakeCredentialLockResult($this->locking_credential === [] ? [] : [$this->locking_credential]);
            }
            $this->locking_query = $query;
            return new FakeCredentialLockResult($this->locking_links);
        }
        public function fetchAssoc(FakeCredentialLockResult $result): array|false { return $result->fetch_assoc(); }
        public function freeResult(FakeCredentialLockResult $result): bool { $result->free(); return true; }
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

    $DB->locking_links = [
        ['itemtype' => 'Computer', 'items_id' => 31],
        ['itemtype' => 'User', 'items_id' => 7],
        ['itemtype' => 'User', 'items_id' => 7],
    ];
    $links = \GlpiPlugin\Credentials\CredentialItemLink::getLinksForCredentialForUpdate(17);
    if ($links !== [
        ['itemtype' => 'Computer', 'items_id' => 31],
        ['itemtype' => 'User', 'items_id' => 7],
    ]) {
        throw new RuntimeException('The current locking read must return deduplicated item relationships.');
    }
    $tick = chr(96);
    if (!str_contains($DB->locking_query, 'FOR UPDATE')
        || !str_contains($DB->locking_query, $tick . 'glpi_plugin_credentials_items' . $tick)
        || !str_contains($DB->locking_query, $tick . 'plugin_credentials_credentials_id' . $tick . " = '17'")
    ) {
        throw new RuntimeException('The current read must quote table, key and value and include FOR UPDATE.');
    }
    $DB->locking_credential = [
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
        'is_deleted' => 0,
    ];
    $credential = \GlpiPlugin\Credentials\CredentialItemLink::getCredentialForUpdate(17);
    if (!is_array($credential)
        || ($credential['password'] ?? null) !== 'glpikey-password-ciphertext-current'
        || ($credential['login'] ?? null) !== 'glpikey-login-ciphertext-current'
    ) {
        throw new RuntimeException('The current locking read must return the latest encrypted credential fields.');
    }
    $tick = chr(96);
    if (!str_contains($DB->credential_locking_query, 'FOR UPDATE')
        || !str_contains($DB->credential_locking_query, $tick . 'glpi_plugin_credentials_credentials' . $tick)
        || !str_contains($DB->credential_locking_query, $tick . 'password' . $tick)
        || !str_contains($DB->credential_locking_query, $tick . 'login' . $tick)
        || !str_contains($DB->credential_locking_query, $tick . 'id' . $tick . " = '17'")
    ) {
        throw new RuntimeException('Credential fields must be selected with a quoted, ID-scoped current locking read.');
    }

    $source = file_get_contents(__DIR__ . '/../../src/CredentialItemLink.php');
    if (!is_string($source)
        || !str_contains($source, 'DBmysql::quoteName')
        || !str_contains($source, 'DBmysql::quoteValue')
        || !str_contains($source, '$DB->doQuery($query)')
    ) {
        throw new RuntimeException('The current read must use GLPI DBmysql quoting and execution APIs.');
    }
    echo "Credential row-lock API, current-read query and active/entity guard regression: OK\n";
}
