<?php

declare(strict_types=1);

namespace {
    final class FakeCredentialMigrationIterator implements IteratorAggregate
    {
        /** @param list<array<string,mixed>> $rows */
        public function __construct(private array $rows) {}
        public function getIterator(): Traversable { yield from $this->rows; }
    }

    final class FakeCredentialMigrationDB
    {
        /** @var list<array<string,mixed>> */
        public array $links = [];
        /** @var list<array<string,mixed>> */
        public array $credentials = [];
        /** @var list<array{values:array<string,mixed>,where:array<string,mixed>}> */
        public array $updates = [];
        public ?int $fail_on_update = null;
        public int $transaction_depth = 0;
        public int $commits = 0;
        public int $rollbacks = 0;
        public int $affected_rows = 1;
        /** @var list<array{links:list<array<string,mixed>>,credentials:list<array<string,mixed>>,updates:list<array<string,mixed>>}> */
        private array $snapshots = [];

        public function beginTransaction(): void
        {
            $this->snapshots[] = [
                'links' => $this->links,
                'credentials' => $this->credentials,
                'updates' => $this->updates,
            ];
            $this->transaction_depth++;
        }

        public function commit(): void
        {
            if ($this->transaction_depth <= 0) {
                throw new RuntimeException('No transaction to commit.');
            }
            array_pop($this->snapshots);
            $this->transaction_depth--;
            $this->commits++;
        }

        public function rollBack(): void
        {
            if ($this->transaction_depth <= 0) {
                throw new RuntimeException('No transaction to roll back.');
            }
            $snapshot = array_pop($this->snapshots);
            $this->links = $snapshot['links'];
            $this->credentials = $snapshot['credentials'];
            $this->updates = $snapshot['updates'];
            $this->transaction_depth--;
            $this->rollbacks++;
        }

        public function getAffectedRows(): int
        {
            return $this->affected_rows;
        }

        public function request(array $criteria): FakeCredentialMigrationIterator
        {
            return new FakeCredentialMigrationIterator(
                ($criteria['FROM'] ?? '') === 'glpi_plugin_credentials_items'
                    ? $this->links
                    : $this->credentials
            );
        }

        public function update(string $table, array $values, array $where): bool
        {
            if (array_key_exists('link_lock_token', $values)) {
                $this->affected_rows = 1;
                return true;
            }

            $this->updates[] = compact('values', 'where');
            if ($this->fail_on_update !== null && count($this->updates) === $this->fail_on_update) {
                $this->affected_rows = 0;
                return false;
            }
            $this->affected_rows = 1;
            foreach ($this->credentials as &$credential) {
                if ((int) ($credential['id'] ?? 0) === (int) ($where['id'] ?? 0)) {
                    $credential = array_merge($credential, $values);
                }
            }
            unset($credential);
            return true;
        }
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
}

namespace {
    require_once dirname(__DIR__, 2) . '/hook.php';

    $DB = new FakeCredentialMigrationDB();
    $DB->credentials = [
        ['id' => 1],
        ['id' => 2],
        ['id' => 3],
    ];
    $DB->links = [
        ['plugin_credentials_credentials_id' => 1],
        ['plugin_credentials_credentials_id' => 1],
        ['plugin_credentials_credentials_id' => 3],
    ];

    // Simulate a failure after one successful write. The next invocation must
    // recompute all rows rather than skipping work because the column exists.
    $DB->fail_on_update = 2;
    if (plugin_credentials_reconcile_link_counts()) {
        throw new RuntimeException('A failed counter update must fail reconciliation.');
    }
    if ($DB->transaction_depth !== 0 || $DB->rollbacks !== 1 || $DB->updates !== []) {
        throw new RuntimeException('A failed batch must roll back every write and close its transaction.');
    }

    $DB->updates = [];
    $DB->fail_on_update = null; // The injected failure is transient; permit the retry to proceed.
    if (!plugin_credentials_reconcile_link_counts()) {
        throw new RuntimeException('A retry must complete reconciliation.');
    }

    $expected = [
        ['values' => ['links_count' => 2], 'where' => ['id' => 1]],
        ['values' => ['links_count' => 0], 'where' => ['id' => 2]],
        ['values' => ['links_count' => 1], 'where' => ['id' => 3]],
    ];
    if ($DB->updates !== $expected) {
        throw new RuntimeException('Retry must recalculate exact counts, including zero-link credentials.');
    }
    $counts_after_retry = array_column($DB->credentials, 'links_count', 'id');
    if ($counts_after_retry !== [1 => 2, 2 => 0, 3 => 1]) {
        throw new RuntimeException('Committed reconciliation must persist the recalculated link counts.');
    }
    if ($DB->transaction_depth !== 0 || $DB->commits !== 1 || $DB->rollbacks !== 1) {
        throw new RuntimeException('Successful reconciliation must commit exactly once and leave no open transaction.');
    }

    echo "Credential link-count migration retry/idempotency regression: OK\n";
}
