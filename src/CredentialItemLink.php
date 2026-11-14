<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials;

use CommonDBTM;
use CommonDBChild;
use Override;
use Session;
use Glpi\DBAL\QueryExpression;

/** Many-to-many relation between a credential and a GLPI item. */
final class CredentialItemLink extends CommonDBTM
{
    public static string $rightname = 'plugin_credentials';

    #[Override]
    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_credentials_items';
    }

    public static function exists(int $credential_id, string $itemtype, int $items_id): bool
    {
        global $DB;

        if ($credential_id <= 0 || $items_id <= 0 || !CredentialItem::isSupportedItemtype($itemtype)) {
            return false;
        }

        $iterator = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'plugin_credentials_credentials_id' => $credential_id,
                'itemtype' => $itemtype,
                'items_id' => $items_id,
            ],
            'LIMIT'  => 1,
        ]);

        return count($iterator) > 0;
    }

    /**
     * Check a relation with an InnoDB current read after locking its credential row.
     * The ordinary exists() method remains non-locking for display/prechecks.
     */
    public static function existsForUpdate(int $credential_id, string $itemtype, int $items_id): bool
    {
        global $DB;
        if ($credential_id <= 0 || $items_id <= 0 || !CredentialItem::isSupportedItemtype($itemtype)) {
            return false;
        }
        $iterator = $DB->request([
            'SELECT' => ['id'],
            'FROM' => self::getTable(),
            'WHERE' => [
                'plugin_credentials_credentials_id' => $credential_id,
                'itemtype' => $itemtype,
                'items_id' => $items_id,
            ],
            'ORDER' => [new QueryExpression(\DBmysql::quoteName('id') . ' FOR UPDATE')],
            'LIMIT' => 1,
        ]);
        return count($iterator) > 0;
    }

    /** @return array<int, array{itemtype:string,items_id:int}> */
    public static function getLinksForCredential(int $credential_id): array
    {
        global $DB;

        if ($credential_id <= 0) {
            return [];
        }

        // Deduplicate defensively. The current schema has a unique composite key,
        // but installations upgraded from early development builds may contain
        // duplicate rows from the period before that key was enforced.
        $unique = [];
        $iterator = $DB->request([
            'SELECT' => ['itemtype', 'items_id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_credentials_credentials_id' => $credential_id],
            'ORDER'  => ['itemtype', 'items_id'],
        ]);
        foreach ($iterator as $row) {
            $itemtype = (string) $row['itemtype'];
            $items_id = (int) $row['items_id'];
            if ($itemtype === '' || $items_id <= 0) {
                continue;
            }
            $unique[$itemtype . '#' . $items_id] = [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
            ];
        }

        return array_values($unique);
    }

    /**
     * Read relationships after the caller has acquired the credential write lock.
     * All plugin relationship writers serialize on that parent row. For a
     * transaction processing multiple credentials, callers must acquire ALL
     * parent-row locks in sorted order before the first consistent read.
     *
     * @return array<int, array{itemtype:string,items_id:int}>
     */
    public static function getLinksForCredentialForUpdate(int $credential_id): array
    {
        global $DB;

        if ($credential_id <= 0) {
            return [];
        }

        // The caller must already hold this credential's parent-row write lock.
        // Use GLPI's DBAL query builder with a locking-read clause. The `ORDER`
        // expression is a static DBAL expression; all identifiers and values are
        // still handled by the query builder. This is a current read under InnoDB
        // REPEATABLE READ, including when GLPI opened an outer transaction earlier.
        $iterator = $DB->request([
            'SELECT' => ['itemtype', 'items_id'],
            'FROM' => self::getTable(),
            'WHERE' => ['plugin_credentials_credentials_id' => $credential_id],
            'ORDER' => [new QueryExpression(\DBmysql::quoteName('itemtype') . ', ' . \DBmysql::quoteName('items_id') . ' FOR UPDATE')],
        ]);

        $unique = [];
        foreach ($iterator as $row) {
            $itemtype = (string) ($row['itemtype'] ?? '');
            $items_id = (int) ($row['items_id'] ?? 0);
            if ($itemtype === '' || $items_id <= 0) {
                continue;
            }
            $unique[$itemtype . '#' . $items_id] = [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
            ];
        }

        return array_values($unique);
    }

    /**
     * Return current item row under an InnoDB write lock.
     *
     * The query uses GLPI's DBAL builder. QueryExpression is limited to the
     * static locking clause appended to the ORDER BY expression; table names,
     * index names and IDs originate from validated GLPI item classes/integers.
     *
     * @return array<string,mixed>|null
     */
    private static function getItemRowForUpdate(CommonDBTM $item, int $items_id): ?array
    {
        global $DB;

        $index = $item::getIndexName();
        $table = $item::getTable();
        $row = $DB->request([
            'SELECT' => ['*'],
            'FROM' => $table,
            'WHERE' => [$table . '.' . $index => $items_id],
            'ORDER' => [new QueryExpression(\DBmysql::quoteName($index) . ' FOR UPDATE')],
        ])->current();

        return is_array($row) ? $row : null;
    }

    /**
     * Resolve a requested item before acquiring row locks.
     *
     * CommonDBChild parent IDs are read without locking here. They are lock-plan
     * hints only: after all parents have been locked, each child is fetched with
     * FOR UPDATE and its FK is revalidated. Any changed FK aborts the operation.
     *
     * @return array{itemtype:string,items_id:int,expected_entity_id:?int,item:CommonDBTM,parent_type:?string,parent_id:int,parent_field:?string,parent:?CommonDBTM}|null
     */
    private static function resolveItemLockPlan(
        string $itemtype,
        int $items_id,
        ?int $expected_entity_id,
    ): ?array {
        if (!CredentialItem::isSupportedItemtype($itemtype) || $items_id <= 0) {
            return null;
        }
        $item = getItemForItemtype($itemtype);
        if (!$item instanceof CommonDBTM) {
            return null;
        }
        $plan = [
            'itemtype' => $itemtype,
            'items_id' => $items_id,
            'expected_entity_id' => $expected_entity_id,
            'item' => $item,
            'parent_type' => null,
            'parent_id' => 0,
            'parent_field' => null,
            'parent' => null,
        ];
        if (!$item instanceof CommonDBChild) {
            return $plan;
        }

        global $DB;
        $parent_field = $item::$items_id;
        $parent_type = (string) $item::$itemtype;
        if ($parent_field === '' || $parent_type === '' || !is_a($parent_type, CommonDBTM::class, true)) {
            return null;
        }

        // Do not lock a child while resolving its parent: that would invert
        // parent-before-child ordering in a batch. This ordinary probe may create
        // an RR snapshot, so later state decisions use current reads and the final
        // child locking read revalidates the FK value.
        $index = $item::getIndexName();
        $probe = $DB->request([
            'SELECT' => [$parent_field],
            'FROM' => $item::getTable(),
            'WHERE' => [$index => $items_id],
            'LIMIT' => 1,
        ])->current();
        if (!is_array($probe)) {
            return null;
        }
        $parent_id = (int) ($probe[$parent_field] ?? 0);
        if ($parent_id <= 0) {
            return null;
        }
        $parent = getItemForItemtype($parent_type);
        if (!$parent instanceof CommonDBTM) {
            return null;
        }
        $plan['parent_type'] = $parent_type;
        $plan['parent_id'] = $parent_id;
        $plan['parent_field'] = $parent_field;
        $plan['parent'] = $parent;
        return $plan;
    }

    /**
     * Lock one supported GLPI item and, for CommonDBChild, its parent first.
     */
    public static function lockItemForUpdate(
        string $itemtype,
        int $items_id,
        ?int $expected_entity_id = null,
    ): bool {
        return self::lockItemsForUpdate([[
            'itemtype' => $itemtype,
            'items_id' => $items_id,
            'entities_id' => $expected_entity_id,
        ]]);
    }

    /**
     * Lock a set in two phases: resolve all CommonDBChild parents, lock every
     * unique parent in itemtype/ID order, then lock requested rows in itemtype/ID
     * order. No child row is locked during parent resolution.
     *
     * Batches whose child IDs map to parents in opposite orders cannot therefore
     * hold parent locks in conflicting sequences. The final child current read
     * revalidates each FK so stale probes fail closed.
     *
     * @param array<int,array{itemtype:string,items_id:int,entities_id?:int|null}> $items
     */
    public static function lockItemsForUpdate(array $items): bool
    {
        $normalized = [];
        foreach ($items as $entry) {
            if (!is_array($entry)
                || !is_string($entry['itemtype'] ?? null)
                || !is_numeric($entry['items_id'] ?? null)
            ) {
                return false;
            }
            $itemtype = $entry['itemtype'];
            $items_id = (int) $entry['items_id'];
            $entity_id = isset($entry['entities_id']) ? (int) $entry['entities_id'] : null;
            if ($items_id <= 0 || ($entity_id !== null && $entity_id < 0)) {
                return false;
            }
            $key = $itemtype . '#' . $items_id;
            if (isset($normalized[$key]) && $normalized[$key]['entities_id'] !== $entity_id) {
                return false;
            }
            $normalized[$key] = [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
                'entities_id' => $entity_id,
            ];
        }

        $normalized = array_values($normalized);
        usort($normalized, static fn(array $a, array $b): int =>
            [$a['itemtype'], $a['items_id']] <=> [$b['itemtype'], $b['items_id']]
        );

        // Resolve all parent IDs before taking the first row lock.
        $plans = [];
        /** @var array<string,array{itemtype:string,items_id:int,item:CommonDBTM}> $parents */
        $parents = [];
        foreach ($normalized as $entry) {
            $plan = self::resolveItemLockPlan($entry['itemtype'], $entry['items_id'], $entry['entities_id']);
            if ($plan === null) {
                return false;
            }
            $plans[] = $plan;
            if ($plan['parent_type'] !== null && $plan['parent'] instanceof CommonDBTM) {
                $key = $plan['parent_type'] . '#' . $plan['parent_id'];
                $parents[$key] = [
                    'itemtype' => $plan['parent_type'],
                    'items_id' => $plan['parent_id'],
                    'item' => $plan['parent'],
                ];
            }
        }

        // Lock every parent globally and deterministically before any child row.
        $parents = array_values($parents);
        usort($parents, static fn(array $a, array $b): int =>
            [$a['itemtype'], $a['items_id']] <=> [$b['itemtype'], $b['items_id']]
        );
        $parent_rows = [];
        foreach ($parents as $parent_lock) {
            $row = self::getItemRowForUpdate($parent_lock['item'], $parent_lock['items_id']);
            if ($row === null) {
                return false;
            }
            $parent_rows[$parent_lock['itemtype'] . '#' . $parent_lock['items_id']] = $row;
        }

        // With every parent held, lock requested rows and validate their current FKs.
        foreach ($plans as $plan) {
            $row = self::getItemRowForUpdate($plan['item'], $plan['items_id']);
            if ($row === null) {
                return false;
            }
            if ($plan['parent_type'] !== null) {
                $key = $plan['parent_type'] . '#' . $plan['parent_id'];
                $parent_row = $parent_rows[$key] ?? null;
                if (!is_array($parent_row)
                    || $plan['parent_field'] === null
                    || (int) ($row[$plan['parent_field']] ?? 0) !== $plan['parent_id']
                ) {
                    return false;
                }
                $entity_id = array_key_exists('entities_id', $row)
                    ? max(0, (int) $row['entities_id'])
                    : (array_key_exists('entities_id', $parent_row) ? max(0, (int) $parent_row['entities_id']) : 0);
            } else {
                $entity_id = array_key_exists('entities_id', $row) ? max(0, (int) $row['entities_id']) : 0;
            }
            if ($plan['expected_entity_id'] !== null && $entity_id !== $plan['expected_entity_id']) {
                return false;
            }
            $plan['item']->getFromResultSet($row);
        }
        return true;
    }

    /**
     * Enumerate and lock all credential relations for an item using an InnoDB
     * current read. Call inside a transaction after the GLPI item row is locked
     * (or from ITEM_PURGE, where GLPI already deleted and locked that row).
     *
     * @return array<int,int>
     */
    public static function getCredentialIDsForItemForUpdate(string $itemtype, int $items_id): array
    {
        global $DB;

        if (!CredentialItem::isSupportedItemtype($itemtype) || $items_id <= 0) {
            return [];
        }

        $iterator = $DB->request([
            'SELECT' => ['plugin_credentials_credentials_id'],
            'FROM' => self::getTable(),
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id],
            'ORDER' => [new QueryExpression(\DBmysql::quoteName('plugin_credentials_credentials_id') . ' FOR UPDATE')],
        ]);

        $ids = [];
        foreach ($iterator as $row) {
            $id = (int) ($row['plugin_credentials_credentials_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * Read credential fields with an InnoDB current read after locking the row,
     * even when a prior FK-resolution SELECT established an older RR snapshot.
     *
     * @return array<string, mixed>|null
     */
    public static function getCredentialForUpdate(int $credential_id): ?array
    {
        global $DB;
        if ($credential_id <= 0) {
            return null;
        }
        $row = $DB->request([
            'SELECT' => ['*'],
            'FROM' => Credential::getTable(),
            'WHERE' => ['id' => $credential_id],
            'ORDER' => [new QueryExpression(\DBmysql::quoteName('id') . ' FOR UPDATE')],
            'LIMIT' => 1,
        ])->current();
        return is_array($row) ? $row : null;
    }

    /**
     * Hydrate a GLPI Credential from its current row while its write lock is held.
     * Avoid getFromDB() here because it may return a stale RR snapshot.
     */
    public static function loadCredentialForUpdate(int $credential_id): ?Credential
    {
        $row = self::getCredentialForUpdate($credential_id);
        if ($row === null) {
            return null;
        }
        $credential = new Credential();
        $credential->getFromResultSet($row);
        return $credential;
    }

    public static function countForCredential(int $credential_id): int
    {
        return count(self::getLinksForCredential($credential_id));
    }

    /**
     * Count links with a current read after locking the credential row.
     * Writers use this variant for last-link decisions.
     */
    public static function countForCredentialForUpdate(int $credential_id): int
    {
        return count(self::getLinksForCredentialForUpdate($credential_id));
    }

    /**
     * Acquire an InnoDB row write lock using GLPI's database API.
     *
     * The per-row random token guarantees a changed value, so getAffectedRows()
     * distinguishes a locked existing row from a missing row. InnoDB holds the
     * exclusive row lock until the surrounding transaction commits or rolls back.
     *
     * When an expected entity is supplied, or an active row is required, those
     * conditions are part of the atomic UPDATE itself. This prevents a concurrent
     * entity move or archive from passing a stale pre-transaction check.
     *
     * The caller must invoke this method inside a transaction and keep that
     * transaction open until the relationship mutation and its history are done.
     */
    public static function lockCredential(
        int $credential_id,
        ?int $expected_entity_id = null,
        bool $require_active = false,
    ): bool {
        global $DB;

        if ($credential_id <= 0) {
            return false;
        }

        $where = ['id' => $credential_id];
        if ($expected_entity_id !== null) {
            $where['entities_id'] = $expected_entity_id;
        }
        if ($require_active) {
            $where['is_deleted'] = 0;
        }

        $updated = $DB->update(Credential::getTable(), [
            'link_lock_token' => bin2hex(random_bytes(16)),
        ], $where);
        if ($updated === false) {
            throw new \RuntimeException('Unable to acquire the credential write lock.');
        }

        return $DB->getAffectedRows() === 1;
    }

    /**
     * Acquire all credential parent-row locks in a deterministic order.
     *
     * This method must be called immediately after beginTransaction() and before
     * any consistent SELECT in that transaction. InnoDB REPEATABLE READ creates
     * its consistent-read snapshot on the first ordinary SELECT. Locking every
     * candidate first ensures that snapshot is created only after all lock waits
     * have completed, so later reads cannot observe a pre-lock snapshot for a
     * credential that was locked afterwards.
     *
     * Missing rows are omitted; database errors throw. IDs are deduplicated and
     * sorted numerically to reduce deadlock risk across bulk operations.
     *
     * @param array<int, int|string> $credential_ids
     * @return array<int, int> IDs whose parent rows were successfully locked.
     */
    public static function lockCredentials(array $credential_ids): array
    {
        $normalized = [];
        foreach ($credential_ids as $credential_id) {
            $id = (int) $credential_id;
            if ($id > 0) {
                $normalized[$id] = $id;
            }
        }

        $ids = array_values($normalized);
        sort($ids, SORT_NUMERIC);

        $locked = [];
        foreach ($ids as $id) {
            if (self::lockCredential($id)) {
                $locked[] = $id;
            }
        }

        return $locked;
    }

    public static function unlink(int $credential_id, string $itemtype, int $items_id): bool
    {
        global $DB;

        if ($credential_id <= 0 || $items_id <= 0 || !CredentialItem::isSupportedItemtype($itemtype)) {
            return false;
        }

        // Keep the invariant even if a future caller bypasses the controller.
        // The caller must hold the credential row lock; this current read then
        // sees relationship deletes committed while it waited for that lock.
        if (!CredentialLinkPolicy::canUnlink(self::countForCredentialForUpdate($credential_id))) {
            return false;
        }

        return $DB->delete(self::getTable(), [
            'plugin_credentials_credentials_id' => $credential_id,
            'itemtype' => $itemtype,
            'items_id' => $items_id,
        ]) !== false;
    }

    /**
     * Whether the current user can reach the credential through at least one linked item.
     */
    public static function userCanReachCredential(int $credential_id): bool
    {
        global $DB;

        if ($credential_id <= 0 || !Session::haveRight(Credential::$rightname, READ)) {
            return false;
        }

        $iterator = $DB->request([
            'SELECT' => ['itemtype', 'items_id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_credentials_credentials_id' => $credential_id],
        ]);

        foreach ($iterator as $row) {
            if (CredentialItem::loadReadableItem((string) $row['itemtype'], (int) $row['items_id']) !== null) {
                return true;
            }
        }

        return false;
    }
    /**
     * Current-read reachability check for link transactions after locking the
     * candidate credential row; it avoids reading an older RR snapshot.
     */
    public static function userCanReachCredentialForUpdate(int $credential_id): bool
    {
        global $DB;
        if ($credential_id <= 0 || !Session::haveRight(Credential::$rightname, READ)) {
            return false;
        }
        $iterator = $DB->request([
            'SELECT' => ['itemtype', 'items_id'],
            'FROM' => self::getTable(),
            'WHERE' => ['plugin_credentials_credentials_id' => $credential_id],
            'ORDER' => [new QueryExpression(\DBmysql::quoteName('itemtype') . ', ' . \DBmysql::quoteName('items_id') . ' FOR UPDATE')],
        ]);
        foreach ($iterator as $row) {
            if (CredentialItem::loadReadableItem((string) $row['itemtype'], (int) $row['items_id']) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remove relationships when a supported GLPI object is permanently purged.
     * Credentials that lose their last relationship are moved to the trash.
     */
    public static function cleanupForItem(string $itemtype, int $items_id): void
    {
        global $DB;

        if (!CredentialItem::isSupportedItemtype($itemtype) || $items_id <= 0) {
            return;
        }

        // GLPI invokes ITEM_PURGE after deleting the item row, inside the same
        // transaction. Every plugin link writer locks that item row before its
        // credential row, so no new relationship can appear after the purge's
        // item delete. Enumerate with a current read to avoid an outer transaction's
        // older REPEATABLE READ snapshot, then lock credentials in ascending order.
        $DB->beginTransaction();
        try {
            $credential_ids = self::getCredentialIDsForItemForUpdate($itemtype, $items_id);
            if ($credential_ids !== []) {
                foreach ($credential_ids as $credential_id) {
                    if (!self::lockCredential($credential_id)) {
                        throw new \RuntimeException('Unable to lock credential while purging its linked item.');
                    }
                }

                if ($DB->delete(self::getTable(), [
                    'itemtype' => $itemtype,
                    'items_id' => $items_id,
                ]) === false) {
                    throw new \RuntimeException('Unable to remove credential relationships for purged item.');
                }

                foreach ($credential_ids as $credential_id) {
                    // Read relationships after acquiring the parent credential lock.
                    // All plugin link writers use that same lock.
                    $remaining = self::getLinksForCredentialForUpdate($credential_id);
                    if ($remaining === []) {
                        $updated = $DB->update(Credential::getTable(), [
                            'is_deleted' => 1,
                            'date_mod'   => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
                        ], [
                            'id' => $credential_id,
                            'is_deleted' => 0,
                        ]);
                        if ($updated === false) {
                            throw new \RuntimeException('Unable to archive orphaned credential.');
                        }
                        if ($DB->getAffectedRows() === 1
                            && !CredentialHistory::recordForCredential($credential_id, Credential::HISTORY_ORPHANED)
                        ) {
                            throw new \RuntimeException('Unable to record orphaned credential history.');
                        }
                    }
                }
            }

            $DB->commit();
        } catch (\Throwable $exception) {
            // This rolls back only the savepoint/transaction level opened above.
            $DB->rollBack();

            // Propagate the failure so GLPI can roll back its surrounding purge
            // transaction instead of leaving the asset deleted with stale links.
            throw $exception;
        }
    }

}
