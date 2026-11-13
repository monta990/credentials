<?php

declare(strict_types=1);

namespace {
    class CommonDBTM
    {
        public array $fields = [];
        public function getFromDB(int $id): bool
        {
            global $TRANSFER_ITEMS;
            if (!isset($TRANSFER_ITEMS[static::class][$id])) {
                return false;
            }
            $this->fields = $TRANSFER_ITEMS[static::class][$id];
            return true;
        }
    }

    final class FakeCredentialTransferIterator implements \IteratorAggregate, \Countable
    {
        public function __construct(private array $rows) {}
        public function getIterator(): \Traversable { return new \ArrayIterator($this->rows); }
        public function count(): int { return count($this->rows); }
    }

    final class FakeCredentialTransferDB
    {
        public array $credentials = [];
        public array $links = [];
        public int $last_insert_id = 0;
        public int $affected_rows = 0;
        public ?\Closure $afterLock = null;
        public ?\Closure $beforeLock = null;
        public bool $forceNextLockFailure = false;
        private ?array $snapshot = null;
        private ?array $transaction_read_snapshot = null;
        private bool $in_transaction = false;

        public function request(array $query): FakeCredentialTransferIterator
        {
            if ($this->in_transaction) {
                $this->transaction_read_snapshot ??= [$this->credentials, $this->links];
                [$credentials, $links] = $this->transaction_read_snapshot;
            } else {
                $credentials = $this->credentials;
                $links = $this->links;
            }
            $table = $query['FROM'];
            $rows = $table === 'glpi_plugin_credentials_credentials'
                ? $credentials
                : $links;
            $where = $query['WHERE'] ?? [];
            $rows = array_values(array_filter($rows, function (array $row) use ($where): bool {
                foreach ($where as $field => $expected) {
                    $actual = $row[$field] ?? null;
                    if (is_array($expected)) {
                        if (!in_array($actual, $expected, true)) {
                            return false;
                        }
                    } elseif ((string) $actual !== (string) $expected) {
                        return false;
                    }
                }
                return true;
            }));

            $select = $query['SELECT'] ?? '*';
            if (is_array($select)) {
                $rows = array_map(static function (array $row) use ($select): array {
                    return array_intersect_key($row, array_flip($select));
                }, $rows);
            }
            return new FakeCredentialTransferIterator($rows);
        }

        public function update(string $table, array $values, array $where): bool
        {
            $key = $table === 'glpi_plugin_credentials_credentials' ? 'credentials' : 'links';
            $this->affected_rows = 0;
            foreach ($this->{$key} as &$row) {
                $matches = true;
                foreach ($where as $field => $expected) {
                    if ((string) ($row[$field] ?? null) !== (string) $expected) {
                        $matches = false;
                        break;
                    }
                }
                if ($matches) {
                    $row = array_merge($row, $values);
                    $this->affected_rows++;
                }
            }
            unset($row);
            return true;
        }

        public function insert(string $table, array $values): bool
        {
            $key = $table === 'glpi_plugin_credentials_credentials' ? 'credentials' : 'links';
            $existing_ids = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $this->{$key});
            $this->last_insert_id = ($existing_ids === [] ? 0 : max($existing_ids)) + 1;
            $values['id'] = $this->last_insert_id;
            $this->{$key}[] = $values;
            $this->affected_rows = 1;
            return true;
        }

        public function insertId(): int { return $this->last_insert_id; }
        public function getAffectedRows(): int { return $this->affected_rows; }
        public function runBeforeLock(): void
        {
            if ($this->beforeLock !== null) {
                $callback = $this->beforeLock;
                $this->beforeLock = null;
                $callback($this);
            }
        }

        public function runAfterLock(): void
        {
            if ($this->afterLock !== null) {
                $callback = $this->afterLock;
                $this->afterLock = null;
                $callback($this);
            }
        }

        public function getCurrentLinksForCredential(int $credential_id): array
        {
            return array_values(array_filter(
                $this->links,
                static fn(array $row): bool => (int) $row['plugin_credentials_credentials_id'] === $credential_id
            ));
        }

        public function getCurrentCredentialForUpdate(int $credential_id): ?array
        {
            foreach ($this->credentials as $credential) {
                if ((int) $credential['id'] === $credential_id) {
                    return $credential;
                }
            }
            return null;
        }

        public function beginTransaction(): void
        {
            $this->snapshot = [$this->credentials, $this->links, $this->last_insert_id];
            $this->transaction_read_snapshot = null;
            $this->in_transaction = true;
        }
        public function commit(): void
        {
            $this->snapshot = null;
            $this->transaction_read_snapshot = null;
            $this->in_transaction = false;
        }
        public function rollBack(): void
        {
            if ($this->snapshot !== null) {
                [$this->credentials, $this->links, $this->last_insert_id] = $this->snapshot;
                $this->snapshot = null;
            }
            $this->transaction_read_snapshot = null;
            $this->in_transaction = false;
        }
    }

    final class FakeAsset extends CommonDBTM {}
    function getItemForItemtype(string $itemtype): ?CommonDBTM
    {
        return $itemtype === 'FakeAsset' ? new FakeAsset() : null;
    }
}

namespace GlpiPlugin\Credentials {
    final class Credential
    {
        public const HISTORY_CREATED = 1;
        public const HISTORY_UPDATED = 2;
        public const HISTORY_LINKED = 3;
        public static function getTable(): string { return 'glpi_plugin_credentials_credentials'; }
    }

    final class CredentialItem
    {
        public static function isSupportedItemtype(string $itemtype): bool { return $itemtype === 'FakeAsset'; }
        public static function resolveParentEntityID(\CommonDBTM $item): int
        {
            return (int) ($item->fields['entities_id'] ?? 0);
        }
    }

    final class CredentialItemLink
    {
        public static function getTable(): string { return 'glpi_plugin_credentials_items'; }
        public static function lockCredential(int $id, ?int $expected_entity = null, bool $active = false): bool
        {
            global $DB;
            $where = ['id' => $id];
            if ($expected_entity !== null) {
                $where['entities_id'] = $expected_entity;
            }
            if ($active) {
                $where['is_deleted'] = 0;
            }
            $DB->runBeforeLock();
            if ($DB->forceNextLockFailure) {
                $DB->forceNextLockFailure = false;
                return false;
            }
            $ok = $DB->update(Credential::getTable(), ['link_lock_token' => bin2hex(random_bytes(16))], $where);
            $locked = $ok !== false && $DB->getAffectedRows() === 1;
            if ($locked) {
                $DB->runAfterLock();
            }
            return $locked;
        }
        public static function getLinksForCredentialForUpdate(int $id): array
        {
            global $DB;
            return $DB->getCurrentLinksForCredential($id);
        }
        public static function getCredentialForUpdate(int $id): ?array
        {
            global $DB;
            return $DB->getCurrentCredentialForUpdate($id);
        }
        public static function countForCredential(int $id): int
        {
            global $DB;
            return count($DB->request([
                'SELECT' => ['id'],
                'FROM' => self::getTable(),
                'WHERE' => ['plugin_credentials_credentials_id' => $id],
            ]));
        }
    }

    final class CredentialHistory
    {
        public static array $events = [];
        public static function recordForCredential(int $id, int $action): bool
        {
            self::$events[] = [$id, $action];
            return true;
        }
    }

    require_once __DIR__ . '/../../src/CredentialTransfer.php';
}

namespace {
    function transferAssert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    function transferCredential(int $id, int $entity): array
    {
        return [
            'id' => $id,
            'name' => 'Database access',
            'entities_id' => $entity,
            'login' => 'glpikey-login-ciphertext',
            'password' => 'glpikey-password-ciphertext',
            'url' => 'glpikey-url-ciphertext',
            'notes' => 'glpikey-notes-ciphertext',
            'category' => 'Infrastructure',
            'expires_at' => '2027-06-30',
            'review_due_at' => '2027-01-31',
            'reviewed_at' => '2026-11-12 10:00:00',
            'reviewer_users_id' => 9,
            'users_id' => 7,
            'date_creation' => '2026-11-01 10:00:00',
            'date_mod' => '2026-11-01 10:00:00',
            'link_lock_token' => 'old-token',
            'is_deleted' => 0,
        ];
    }

    $DB = new FakeCredentialTransferDB();
    $TRANSFER_ITEMS = [FakeAsset::class => [
        100 => ['id' => 100, 'entities_id' => 2],
        200 => ['id' => 200, 'entities_id' => 1],
    ]];
    $DB->credentials = [transferCredential(10, 1)];
    $DB->links = [
        ['id' => 1, 'plugin_credentials_credentials_id' => 10, 'itemtype' => 'FakeAsset', 'items_id' => 100],
    ];
    \GlpiPlugin\Credentials\CredentialHistory::$events = [];
    \GlpiPlugin\Credentials\CredentialTransfer::handle([
        'type' => 'FakeAsset', 'id' => 100, 'newID' => 100, 'entities_id' => 2,
    ]);
    transferAssert((int) $DB->credentials[0]['entities_id'] === 2, 'A sole relationship must move its credential to the destination entity.');
    transferAssert((int) $DB->links[0]['plugin_credentials_credentials_id'] === 10, 'A sole relationship must retain its credential link.');
    transferAssert(count(\GlpiPlugin\Credentials\CredentialHistory::$events) === 1, 'A direct entity move must be audited.');

    $DB = new FakeCredentialTransferDB();
    $TRANSFER_ITEMS = [FakeAsset::class => [
        100 => ['id' => 100, 'entities_id' => 2],
        200 => ['id' => 200, 'entities_id' => 1],
    ]];
    $DB->credentials = [transferCredential(10, 1)];
    $DB->links = [
        ['id' => 1, 'plugin_credentials_credentials_id' => 10, 'itemtype' => 'FakeAsset', 'items_id' => 100],
        ['id' => 2, 'plugin_credentials_credentials_id' => 10, 'itemtype' => 'FakeAsset', 'items_id' => 200],
    ];
    \GlpiPlugin\Credentials\CredentialHistory::$events = [];
    \GlpiPlugin\Credentials\CredentialTransfer::handle([
        'type' => 'FakeAsset', 'id' => 100, 'newID' => 100, 'entities_id' => 2,
    ]);
    transferAssert(count($DB->credentials) === 2, 'A shared credential must be split across entities.');
    transferAssert((int) $DB->credentials[0]['entities_id'] === 1 && (int) $DB->credentials[1]['entities_id'] === 2, 'The original and copied credentials must remain in their respective entities.');
    transferAssert($DB->credentials[1]['password'] === $DB->credentials[0]['password'], 'Ciphertext must be copied byte-for-byte without decryption.');
    transferAssert((int) $DB->links[0]['plugin_credentials_credentials_id'] === (int) $DB->credentials[1]['id'], 'Only the transferred item link must point to the destination copy.');
    transferAssert((int) $DB->links[1]['plugin_credentials_credentials_id'] === 10, 'Other source-entity links must remain unchanged.');

    $DB = new FakeCredentialTransferDB();
    $TRANSFER_ITEMS = [FakeAsset::class => [
        300 => ['id' => 300, 'entities_id' => 1],
        301 => ['id' => 301, 'entities_id' => 2],
    ]];
    $DB->credentials = [transferCredential(20, 1)];
    $DB->links = [
        ['id' => 1, 'plugin_credentials_credentials_id' => 20, 'itemtype' => 'FakeAsset', 'items_id' => 300],
    ];
    \GlpiPlugin\Credentials\CredentialHistory::$events = [];
    \GlpiPlugin\Credentials\CredentialTransfer::handle([
        'type' => 'FakeAsset', 'id' => 300, 'newID' => 301, 'entities_id' => 2,
    ]);
    transferAssert(count($DB->credentials) === 2, 'A cross-entity item clone must receive a separate credential copy.');
    transferAssert(count($DB->links) === 2, 'The original and cloned items must both retain a credential relationship.');
    transferAssert((int) $DB->links[0]['items_id'] === 300 && (int) $DB->links[0]['plugin_credentials_credentials_id'] === 20, 'The source relationship must not be modified.');
    transferAssert((int) $DB->links[1]['items_id'] === 301 && (int) $DB->links[1]['plugin_credentials_credentials_id'] === (int) $DB->credentials[1]['id'], 'The cloned item must link to the destination credential copy.');

    $DB = new FakeCredentialTransferDB();
    $TRANSFER_ITEMS = [FakeAsset::class => [
        400 => ['id' => 400, 'entities_id' => 1],
        401 => ['id' => 401, 'entities_id' => 1],
    ]];
    $DB->credentials = [transferCredential(30, 1)];
    $DB->links = [
        ['id' => 1, 'plugin_credentials_credentials_id' => 30, 'itemtype' => 'FakeAsset', 'items_id' => 400],
    ];
    \GlpiPlugin\Credentials\CredentialHistory::$events = [];
    \GlpiPlugin\Credentials\CredentialTransfer::handle([
        'type' => 'FakeAsset', 'id' => 400, 'newID' => 401, 'entities_id' => 1,
    ]);
    transferAssert(count($DB->credentials) === 1, 'A same-entity clone must not duplicate the credential record.');
    transferAssert(count($DB->links) === 2 && (int) $DB->links[1]['plugin_credentials_credentials_id'] === 30, 'A same-entity clone must reuse the existing credential.');


    // A stale source ID list must not recreate a relationship that was removed
    // while the clone operation was waiting for the credential row lock.
    $DB = new FakeCredentialTransferDB();
    $TRANSFER_ITEMS = [FakeAsset::class => [
        500 => ['id' => 500, 'entities_id' => 1],
        501 => ['id' => 501, 'entities_id' => 2],
    ]];
    $DB->credentials = [transferCredential(40, 1)];
    $DB->links = [
        ['id' => 1, 'plugin_credentials_credentials_id' => 40, 'itemtype' => 'FakeAsset', 'items_id' => 500],
    ];
    $DB->afterLock = static function (FakeCredentialTransferDB $database): void {
        $database->links = [];
    };
    \GlpiPlugin\Credentials\CredentialHistory::$events = [];
    \GlpiPlugin\Credentials\CredentialTransfer::handle([
        'type' => 'FakeAsset', 'id' => 500, 'newID' => 501, 'entities_id' => 2,
    ]);
    transferAssert(count($DB->credentials) === 1, 'A source relationship removed during lock wait must not create a destination credential copy.');
    transferAssert($DB->links === [], 'A source relationship removed during lock wait must not be recreated on the cloned item.');

    // Same-ID move race: ordinary reads retain the old RR snapshot while the
    // locking current read sees that the source unlink has committed.
    $DB = new FakeCredentialTransferDB();
    $TRANSFER_ITEMS = [FakeAsset::class => [
        600 => ['id' => 600, 'entities_id' => 2],
        601 => ['id' => 601, 'entities_id' => 1],
    ]];
    $DB->credentials = [transferCredential(50, 1)];
    $DB->links = [
        ['id' => 1, 'plugin_credentials_credentials_id' => 50, 'itemtype' => 'FakeAsset', 'items_id' => 600],
        ['id' => 2, 'plugin_credentials_credentials_id' => 50, 'itemtype' => 'FakeAsset', 'items_id' => 601],
    ];
    $DB->afterLock = static function (FakeCredentialTransferDB $database): void {
        $database->links = array_values(array_filter(
            $database->links,
            static fn(array $row): bool => (int) $row['items_id'] !== 600
        ));
    };
    \GlpiPlugin\Credentials\CredentialHistory::$events = [];
    \GlpiPlugin\Credentials\CredentialTransfer::handle([
        'type' => 'FakeAsset', 'id' => 600, 'newID' => 600, 'entities_id' => 2,
    ]);
    transferAssert((int) $DB->credentials[0]['entities_id'] === 1, 'A same-ID transfer must not move a credential after its source relationship was concurrently removed.');
    transferAssert(count($DB->links) === 1 && (int) $DB->links[0]['items_id'] === 601, 'A same-ID transfer must preserve the surviving relationship and skip the stale source link.');

    // Concurrent credential edit while waiting for the row lock: the ordinary
    // read snapshot contains the old ciphertext, but the copy must use the
    // latest committed encrypted values returned by the current locking read.
    $DB = new FakeCredentialTransferDB();
    $TRANSFER_ITEMS = [FakeAsset::class => [
        700 => ['id' => 700, 'entities_id' => 1],
        701 => ['id' => 701, 'entities_id' => 2],
    ]];
    $DB->credentials = [transferCredential(60, 1)];
    $DB->links = [
        ['id' => 1, 'plugin_credentials_credentials_id' => 60, 'itemtype' => 'FakeAsset', 'items_id' => 700],
    ];
    $DB->afterLock = static function (FakeCredentialTransferDB $database): void {
        foreach ($database->credentials as &$credential) {
            if ((int) $credential['id'] === 60) {
                $credential['name'] = 'Updated database access';
                $credential['login'] = 'glpikey-login-ciphertext-v2';
                $credential['password'] = 'glpikey-password-ciphertext-v2';
                $credential['url'] = 'glpikey-url-ciphertext-v2';
                $credential['notes'] = 'glpikey-notes-ciphertext-v2';
            }
        }
        unset($credential);
    };
    \GlpiPlugin\Credentials\CredentialHistory::$events = [];
    \GlpiPlugin\Credentials\CredentialTransfer::handle([
        'type' => 'FakeAsset', 'id' => 700, 'newID' => 701, 'entities_id' => 2,
    ]);
    transferAssert(count($DB->credentials) === 2, 'A cross-entity clone must create exactly one destination credential.');
    $copied = $DB->credentials[1];
    transferAssert($copied['name'] === 'Updated database access', 'A transfer must copy the latest committed credential name.');
    transferAssert($copied['login'] === 'glpikey-login-ciphertext-v2', 'A transfer must copy the latest committed login ciphertext.');
    transferAssert($copied['password'] === 'glpikey-password-ciphertext-v2', 'A transfer must copy the latest committed password ciphertext.');
    transferAssert($copied['url'] === 'glpikey-url-ciphertext-v2', 'A transfer must copy the latest committed URL ciphertext.');
    transferAssert($copied['notes'] === 'glpikey-notes-ciphertext-v2', 'A transfer must copy the latest committed notes ciphertext.');
    transferAssert($copied['category'] === 'Infrastructure', 'A cross-entity copy must retain the credential category.');
    transferAssert($copied['expires_at'] === '2027-06-30', 'A cross-entity copy must retain the credential expiration date.');
    transferAssert($copied['review_due_at'] === '2027-01-31', 'A cross-entity copy must retain the review schedule.');
    transferAssert($copied['reviewed_at'] === '2026-11-12 10:00:00', 'A cross-entity copy must retain the last review timestamp.');
    transferAssert((int) $copied['reviewer_users_id'] === 9, 'A cross-entity copy must retain the last reviewer ID.');


    // Concurrent archive while the transfer waits: the initial ID list is
    // stale when the conditional is_deleted=0 UPDATE eventually executes.
    $DB = new FakeCredentialTransferDB();
    $TRANSFER_ITEMS = [FakeAsset::class => [
        800 => ['id' => 800, 'entities_id' => 2],
        801 => ['id' => 801, 'entities_id' => 1],
    ]];
    $DB->credentials = [transferCredential(70, 1)];
    $DB->links = [
        ['id' => 1, 'plugin_credentials_credentials_id' => 70, 'itemtype' => 'FakeAsset', 'items_id' => 800],
    ];
    $DB->beforeLock = static function (FakeCredentialTransferDB $database): void {
        $database->credentials[0]['is_deleted'] = 1;
        $database->links = [];
    };
    \GlpiPlugin\Credentials\CredentialHistory::$events = [];
    \GlpiPlugin\Credentials\CredentialTransfer::handle([
        'type' => 'FakeAsset', 'id' => 800, 'newID' => 800, 'entities_id' => 2,
    ]);
    transferAssert((int) $DB->credentials[0]['is_deleted'] === 1, 'A concurrent archive must remain untouched.');
    transferAssert($DB->links === [], 'A concurrently removed source link must not be recreated.');

    // Clone path must also skip an archived source credential while waiting.
    $DB = new FakeCredentialTransferDB();
    $TRANSFER_ITEMS = [FakeAsset::class => [
        820 => ['id' => 820, 'entities_id' => 1],
        821 => ['id' => 821, 'entities_id' => 2],
    ]];
    $DB->credentials = [transferCredential(72, 1)];
    $DB->links = [
        ['id' => 1, 'plugin_credentials_credentials_id' => 72, 'itemtype' => 'FakeAsset', 'items_id' => 820],
    ];
    $DB->beforeLock = static function (FakeCredentialTransferDB $database): void {
        $database->credentials[0]['is_deleted'] = 1;
        $database->links = [];
    };
    \GlpiPlugin\Credentials\CredentialTransfer::handle([
        'type' => 'FakeAsset', 'id' => 820, 'newID' => 821, 'entities_id' => 2,
    ]);
    transferAssert(count($DB->credentials) === 1, 'A clone must not create a destination copy of a concurrently archived credential.');
    transferAssert($DB->links === [], 'A clone must not recreate a relationship for an archived source credential.');

    // An unexpected lock failure on a still-active credential with its exact
    // source relation intact must remain an explicit error.
    $DB = new FakeCredentialTransferDB();
    $TRANSFER_ITEMS = [FakeAsset::class => [
        830 => ['id' => 830, 'entities_id' => 2],
        831 => ['id' => 831, 'entities_id' => 1],
    ]];
    $DB->credentials = [transferCredential(73, 1)];
    $DB->links = [
        ['id' => 1, 'plugin_credentials_credentials_id' => 73, 'itemtype' => 'FakeAsset', 'items_id' => 830],
    ];
    $DB->forceNextLockFailure = true;
    $thrown = false;
    try {
        \GlpiPlugin\Credentials\CredentialTransfer::handle([
            'type' => 'FakeAsset', 'id' => 830, 'newID' => 830, 'entities_id' => 2,
        ]);
    } catch (\RuntimeException $exception) {
        $thrown = str_contains($exception->getMessage(), 'Unable to lock active credential');
    }
    transferAssert($thrown, 'A failed lock on a still-active linked credential must remain an explicit failure.');

    echo "Credential entity transfer regression tests: OK\n";
}
