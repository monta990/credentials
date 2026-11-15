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
            $this->updates[] = compact('values', 'where');
            if ($this->fail_on_update !== null && count($this->updates) === $this->fail_on_update) {
                return false;
            }
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

    $DB->updates = [];
    $DB->fail_on_update = null;
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

    echo "Credential link-count migration retry/idempotency regression: OK\n";
}
