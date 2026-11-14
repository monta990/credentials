<?php
/**
 * Standalone regression tests for credential/item lock ordering and current reads.
 * Run with: php tests/concurrency-locking.php
 */
declare(strict_types=1);

namespace {
    class CommonDBTM
    {
        public array $fields = [];
        public function getFromResultSet(array $row): void { $this->fields = $row; }
        public static function getTable($classname = null): string { return ''; }
        public static function getIndexName(): string { return 'id'; }
    }

    class CommonDBChild extends CommonDBTM
    {
        public static string $itemtype = '';
        public static string $items_id = '';
    }

    class Session {}

    class DBmysql
    {
        public static function quoteName(string $name): string
        {
            return '`' . str_replace('`', '``', $name) . '`';
        }
    }

    class Computer extends CommonDBTM
    {
        public static function getTable($classname = null): string { return 'glpi_computers'; }
    }

    class DatabaseInstance extends CommonDBTM
    {
        public static function getTable($classname = null): string { return 'glpi_databaseinstances'; }
    }

    class Database extends CommonDBChild
    {
        public static string $itemtype = DatabaseInstance::class;
        public static string $items_id = 'databaseinstances_id';
        public static function getTable($classname = null): string { return 'glpi_databases'; }
    }

    function getItemForItemtype(string $itemtype): ?CommonDBTM
    {
        return match ($itemtype) {
            Computer::class => new Computer(),
            DatabaseInstance::class => new DatabaseInstance(),
            Database::class => new Database(),
            default => null,
        };
    }
}

namespace Glpi\DBAL {
    final class QueryExpression
    {
        public function __construct(private string $value) {}
        public function getValue(): string { return $this->value; }
        public function getParams(): array { return []; }
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
        public static function isSupportedItemtype(string $itemtype): bool
        {
            return in_array($itemtype, [\Computer::class, \Database::class], true);
        }
    }

    final class CredentialLinkPolicy {}
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
        /** @var array<string,mixed>|null */
        public ?array $item_row = ['id' => 7, 'entities_id' => 4, 'name' => 'ERP'];
        /** @var array<int,array<string,mixed>> */
        public array $database_probe_rows = [
            // Deliberately inverse to child IDs: lower child ID belongs to higher parent ID.
            12 => ['databaseinstances_id' => 90],
            13 => ['databaseinstances_id' => 50],
        ];
        /** @var array<int,array<string,mixed>> */
        public array $database_parent_rows = [
            50 => ['id' => 50, 'entities_id' => 8, 'name' => 'SQL Server A'],
            90 => ['id' => 90, 'entities_id' => 8, 'name' => 'SQL Server B'],
        ];
        /** @var array<int,array<string,mixed>> */
        public array $database_child_rows = [
            12 => ['id' => 12, 'databaseinstances_id' => 90, 'name' => 'ERP DB A'],
            13 => ['id' => 13, 'databaseinstances_id' => 50, 'name' => 'ERP DB B'],
        ];
        /** @var list<array<string,mixed>> */
        public array $link_rows = [
            ['itemtype' => 'Computer', 'items_id' => 10],
            ['itemtype' => 'NetworkEquipment', 'items_id' => 11],
            ['itemtype' => 'Computer', 'items_id' => 10],
        ];
        /** @var list<array<string,mixed>> */
        public array $candidate_rows = [
            ['plugin_credentials_credentials_id' => 3],
            ['plugin_credentials_credentials_id' => 9],
            ['plugin_credentials_credentials_id' => 3],
        ];
        /** @var list<array<string,mixed>> */
        public array $requests = [];

        public function request(array $criteria): \ArrayIterator
        {
            $this->requests[] = $criteria;
            $table = $criteria['FROM'] ?? '';
            if ($table === 'glpi_computers') {
                $rows = $this->item_row === null ? [] : [$this->item_row];
            } elseif ($table === 'glpi_databaseinstances') {
                $where = $criteria['WHERE'] ?? [];
                $id = (int) ($where['glpi_databaseinstances.id'] ?? $where['id'] ?? 0);
                $row = $this->database_parent_rows[$id] ?? null;
                $rows = is_array($row) ? [$row] : [];
            } elseif ($table === 'glpi_databases') {
                $where = $criteria['WHERE'] ?? [];
                $select = $criteria['SELECT'] ?? [];
                if ($select === ['databaseinstances_id'] && isset($where['id'])) {
                    $row = $this->database_probe_rows[(int) $where['id']] ?? null;
                } else {
                    $id = (int) ($where['glpi_databases.id'] ?? $where['id'] ?? 0);
                    $row = $this->database_child_rows[$id] ?? null;
                }
                $rows = is_array($row) ? [$row] : [];
            } elseif ($table === 'glpi_plugin_credentials_items'
                && isset($criteria['WHERE']['plugin_credentials_credentials_id'])) {
                $rows = $this->link_rows;
            } elseif ($table === 'glpi_plugin_credentials_items'
                && isset($criteria['WHERE']['itemtype'], $criteria['WHERE']['items_id'])) {
                $rows = $this->candidate_rows;
            } else {
                $rows = [];
            }
            return new \ArrayIterator($rows);
        }

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

        public function getAffectedRows(): int { return $this->affected_rows; }
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
    check($locked === [1, 3, 5, 9], 'bulk credential locks are deduplicated and sorted numerically');
    check($db->locked_ids === [1, 3, 5, 9], 'credential lock updates happen in deterministic order');

    $db->locked_ids = [];
    $db->missing_ids = [3];
    $locked = \GlpiPlugin\Credentials\CredentialItemLink::lockCredentials([5, 3, 1]);
    check($locked === [1, 5], 'missing credential candidates are omitted without stopping other locks');
    check($db->locked_ids === [1, 3, 5], 'missing rows do not change credential lock order');

    $db->fail_updates = true;
    $thrown = false;
    try {
        \GlpiPlugin\Credentials\CredentialItemLink::lockCredentials([1, 2]);
    } catch (RuntimeException) {
        $thrown = true;
    }
    check($thrown, 'database credential-lock failures abort instead of being treated as missing rows');
    $db->fail_updates = false;

    $links = \GlpiPlugin\Credentials\CredentialItemLink::getLinksForCredentialForUpdate(42);
    check($links === [
        ['itemtype' => 'Computer', 'items_id' => 10],
        ['itemtype' => 'NetworkEquipment', 'items_id' => 11],
    ], 'current relationship read returns and deduplicates the DBAL result rows');
    $link_read = end($db->requests);
    $link_order = $link_read['ORDER'][0]->getValue();
    check(str_contains($link_order, 'FOR UPDATE'), 'credential relationship read is a DBAL current read');
    check($link_read['WHERE']['plugin_credentials_credentials_id'] === 42, 'credential relationship read binds the validated credential ID through DBAL');

    $db->requests = [];
    $db->candidate_rows = [
        ['plugin_credentials_credentials_id' => 9],
        ['plugin_credentials_credentials_id' => 3],
        ['plugin_credentials_credentials_id' => 9],
    ];
    $ids = \GlpiPlugin\Credentials\CredentialItemLink::getCredentialIDsForItemForUpdate(\Computer::class, 7);
    check($ids === [3, 9], 'item candidate enumeration is current-read, deduplicated and sorted');
    $candidate_read = end($db->requests);
    check(str_contains($candidate_read['ORDER'][0]->getValue(), 'FOR UPDATE'), 'purge candidate enumeration uses DBAL locking-read semantics');

    $db->requests = [];
    check(\GlpiPlugin\Credentials\CredentialItemLink::lockItemForUpdate(\Computer::class, 7, 4), 'a supported item can be locked and entity-validated');
    $item_lock = end($db->requests);
    check($item_lock['FROM'] === 'glpi_computers' && str_contains($item_lock['ORDER'][0]->getValue(), 'FOR UPDATE'), 'item lock is an InnoDB current read using the GLPI DBAL builder');
    check(!\GlpiPlugin\Credentials\CredentialItemLink::lockItemForUpdate(\Computer::class, 7, 5), 'item lock rejects a stale or cross-entity target');
    $db->item_row = null;
    check(!\GlpiPlugin\Credentials\CredentialItemLink::lockItemForUpdate(\Computer::class, 7, 4), 'item lock rejects an item that was purged while a request was waiting');

    $db->item_row = ['id' => 7, 'entities_id' => 4, 'name' => 'ERP'];
    $db->requests = [];
    check(\GlpiPlugin\Credentials\CredentialItemLink::lockItemForUpdate(\Database::class, 12, 8), 'GLPI CommonDBChild items lock through their parent entity');
    $child_lock_requests = $db->requests;
    check(count($child_lock_requests) === 3
        && ($child_lock_requests[0]['FROM'] ?? '') === 'glpi_databases'
        && ($child_lock_requests[0]['SELECT'] ?? []) === ['databaseinstances_id']
        && !isset($child_lock_requests[0]['ORDER'])
        && ($child_lock_requests[1]['FROM'] ?? '') === 'glpi_databaseinstances'
        && str_contains($child_lock_requests[1]['ORDER'][0]->getValue(), 'FOR UPDATE')
        && ($child_lock_requests[2]['FROM'] ?? '') === 'glpi_databases'
        && str_contains($child_lock_requests[2]['ORDER'][0]->getValue(), 'FOR UPDATE'),
        'CommonDBChild resolves FK without locking the child, then locks parent and child');
    $db->database_child_rows[12] = ['id' => 12, 'databaseinstances_id' => 51, 'name' => 'ERP DB'];
    check(!\GlpiPlugin\Credentials\CredentialItemLink::lockItemForUpdate(\Database::class, 12, 8), 'CommonDBChild rejects an FK that changed after parent resolution');
    $db->database_child_rows[12] = ['id' => 12, 'databaseinstances_id' => 90, 'name' => 'ERP DB'];

    $db->requests = [];
    $bulk_locked = \GlpiPlugin\Credentials\CredentialItemLink::lockItemsForUpdate([
        ['itemtype' => Database::class, 'items_id' => 13, 'entities_id' => 8],
        ['itemtype' => Database::class, 'items_id' => 12, 'entities_id' => 8],
    ]);
    check($bulk_locked, 'bulk CommonDBChild lock succeeds when parent and child rows exist');
    $bulk = $db->requests;
    check(count($bulk) === 6
        && ($bulk[0]['FROM'] ?? '') === 'glpi_databases'
        && ($bulk[1]['FROM'] ?? '') === 'glpi_databases'
        && ($bulk[2]['FROM'] ?? '') === 'glpi_databaseinstances'
        && ($bulk[3]['FROM'] ?? '') === 'glpi_databaseinstances'
        && ($bulk[4]['FROM'] ?? '') === 'glpi_databases'
        && ($bulk[5]['FROM'] ?? '') === 'glpi_databases',
        'bulk lock resolves all FKs, locks all parents, then all children');
    $probe_child_ids = [
        (int) ($bulk[0]['WHERE']['id'] ?? 0),
        (int) ($bulk[1]['WHERE']['id'] ?? 0),
    ];
    $resolved_parent_ids = [
        (int) ($db->database_probe_rows[$probe_child_ids[0]]['databaseinstances_id'] ?? 0),
        (int) ($db->database_probe_rows[$probe_child_ids[1]]['databaseinstances_id'] ?? 0),
    ];
    $parent_ids = [
        (int) ($bulk[2]['WHERE']['glpi_databaseinstances.id'] ?? 0),
        (int) ($bulk[3]['WHERE']['glpi_databaseinstances.id'] ?? 0),
    ];
    $child_ids = [
        (int) ($bulk[4]['WHERE']['glpi_databases.id'] ?? 0),
        (int) ($bulk[5]['WHERE']['glpi_databases.id'] ?? 0),
    ];
    check($probe_child_ids === [12, 13] && $resolved_parent_ids === [90, 50],
        'test fixture maps ascending child IDs to descending parent IDs');
    check($parent_ids === [50, 90],
        'bulk parent locks are sorted ascending independently of the inverse child-to-parent mapping');
    check($child_ids === [12, 13], 'bulk child locks remain sorted ascending after parent locks');

    $root = dirname(__DIR__);
    $backup = file_get_contents($root . '/src/Service/CredentialBackupService.php');
    $transfer = file_get_contents($root . '/src/CredentialTransfer.php');
    $admin = file_get_contents($root . '/src/Service/CredentialAdminService.php');
    $controller = file_get_contents($root . '/src/Controller/CredentialController.php');
    $links_source = file_get_contents($root . '/src/CredentialItemLink.php');

    $purge_start = strpos($links_source, 'public static function cleanupForItem');
    $purge_transaction = strpos($links_source, '$DB->beginTransaction();', $purge_start);
    $purge_candidates = strpos($links_source, 'self::getCredentialLinkCountsForItemForUpdate($itemtype, $items_id)', $purge_transaction);
    $purge_credentials = strpos($links_source, 'self::lockCredential($credential_id)', $purge_candidates);
    $purge_count_update = strpos($links_source, 'self::adjustLinkCount($credential_id, -$link_counts[$credential_id])', $purge_credentials);
    $purge_late_relation_lock = strpos($links_source, 'self::getLinksForCredentialForUpdate($credential_id)', $purge_credentials);
    check($purge_transaction !== false && $purge_candidates !== false && $purge_credentials !== false
        && $purge_count_update !== false && $purge_late_relation_lock === false
        && $purge_transaction < $purge_candidates && $purge_candidates < $purge_credentials && $purge_credentials < $purge_count_update,
        'ITEM_PURGE locks its own relation rows before credentials, then decrements the parent count without locking other relations');

    check(substr_count($controller, 'CredentialItemLink::lockItemForUpdate(') >= 4,
        'all controller link, unlink, move-target and create/update flows lock the GLPI item first');
    $link_candidate_relation = strpos($controller, 'getLinksForCredentialForUpdate($candidate_id)');
    $link_candidate_parent = strpos($controller, 'lockCredential($candidate_id, $parent_entity_id, true)');
    $unlink_start = strpos($controller, 'elseif ($request->request->has(');
    $unlink_relation = strpos($controller, 'getLinksForCredentialForUpdate($credential_id)', $unlink_start);
    $unlink_parent = strpos($controller, 'lockCredential($credential_id, $parent_entity_id, true)', $unlink_start);
    check($link_candidate_relation !== false && $link_candidate_parent !== false && $link_candidate_relation < $link_candidate_parent
        && $unlink_start !== false && $unlink_relation !== false && $unlink_parent !== false && $unlink_relation < $unlink_parent,
        'controller link/unlink/delete flows lock relationship rows before the credential parent');
    $restore_start = strpos($admin, 'public static function restore');
    $restore_item = strpos($admin, 'lockItemForUpdate($itemtype, $items_id, $entity_id)', $restore_start);
    $restore_relations = strpos($admin, 'getLinksForCredentialForUpdate($credential_id)', $restore_start);
    $restore_parent = strpos($admin, 'lockCredential($credential_id, $entity_id, false)', $restore_start);
    check($restore_item !== false && $restore_relations !== false && $restore_parent !== false
        && $restore_item < $restore_relations && $restore_relations < $restore_parent,
        'archived credential restore locks its item, relationships, then credential');
    check(strpos($backup, 'lockItemsForUpdate($item_locks)') < strpos($backup, 'foreach ($prepared as $entry)'),
        'backup import locks all destination items before it creates relationship rows');
    $backup_export_start = strpos($backup, 'public static function createBackup');
    $backup_relations = strpos($backup, 'getLinksForCredentialsForUpdate(array_column($candidate_ids, \'id\'))', $backup_export_start);
    $backup_parents = strpos($backup, 'lockCredentials(array_column($candidate_ids, \'id\'))', $backup_export_start);
    check($backup_relations !== false && $backup_parents !== false && $backup_relations < $backup_parents,
        'backup export locks relationship sets before credential parent rows');
    $transfer_start = strpos($transfer, 'public static function handle');
    $transfer_items = strpos($transfer, 'CredentialItemLink::lockItemsForUpdate([', $transfer_start);
    $transfer_relations = strpos($transfer, 'CredentialItemLink::getLinksForCredentialsForUpdate($credential_ids)', $transfer_start);
    $transfer_parents = strpos($transfer, 'CredentialItemLink::lockCredentials($credential_ids)', $transfer_start);
    check($transfer_items !== false && $transfer_relations !== false && $transfer_parents !== false
        && $transfer_items < $transfer_relations && $transfer_relations < $transfer_parents,
        'clone and move transfer lock items, relationship sets and credential parents in one order');
    check(!str_contains($links_source, '$DB->doQuery('), 'runtime relationship reads no longer execute raw SQL');
    check(!str_contains(file_get_contents($root . '/src/CredentialCategory.php'), '$DB->doQuery('), 'category locking uses the GLPI DBAL builder instead of raw SQL');
    check(!str_contains($links_source, 'SELECT * FROM') && !str_contains($links_source, 'SELECT itemtype'), 'locking reads do not concatenate caller-supplied SQL identifiers or values');

    echo "All item/credential lock-ordering regression tests passed.\n";
}
