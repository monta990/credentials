<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials;

use CommonDBTM;
use Override;
use Session;

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

        // The caller must hold this credential's parent-row write lock. Bulk
        // callers must acquire every parent lock before the first consistent read.
        // That parent-row write lock serializes plugin relationship mutations.
        // The SELECT is deliberately built by GLPI's query builder; no custom SQL
        // or database-specific locking clause is required.
        $unique = [];
        $iterator = $DB->request([
            'SELECT' => ['itemtype', 'items_id'],
            'FROM' => self::getTable(),
            'WHERE' => ['plugin_credentials_credentials_id' => $credential_id],
            'ORDER' => ['itemtype', 'items_id'],
        ]);
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
     * Read credential fields after the caller has acquired its parent-row lock.
     * For bulk transactions, all candidate parent locks must be acquired before
     * any consistent SELECT so this read uses a post-lock InnoDB snapshot.
     *
     * @return array<string, mixed>|null
     */
    public static function getCredentialForUpdate(int $credential_id): ?array
    {
        global $DB;

        if ($credential_id <= 0) {
            return null;
        }

        // The caller must hold the parent-row lock in the current transaction.
        // The query builder keeps this read compatible with GLPI DBAL.
        $columns = [
            'id', 'name', 'entities_id', 'login', 'password', 'url', 'notes',
            'users_id', 'date_creation', 'date_mod', 'plugin_credentials_categories_id',
            'date_expires', 'date_last_review', 'date_next_review', 'reviewer_users_id',
            'is_deleted',
        ];
        $row = $DB->request([
            'SELECT' => $columns,
            'FROM' => Credential::getTable(),
            'WHERE' => ['id' => $credential_id],
            'LIMIT' => 1,
        ])->current();

        return is_array($row) ? $row : null;
    }

    public static function countForCredential(int $credential_id): int
    {
        return count(self::getLinksForCredential($credential_id));
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
        if (!CredentialLinkPolicy::canUnlink(self::countForCredential($credential_id))) {
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
     * Remove relationships when a supported GLPI object is permanently purged.
     * Credentials that lose their last relationship are moved to the trash.
     */
    public static function cleanupForItem(string $itemtype, int $items_id): void
    {
        global $DB;

        if (!CredentialItem::isSupportedItemtype($itemtype) || $items_id <= 0) {
            return;
        }

        // Collect candidate IDs before opening the transaction. The first DB
        // statement inside the transaction must be a credential write-lock, so
        // subsequent query-builder reads observe the state after any lock wait.
        $iterator = $DB->request([
                'SELECT' => ['plugin_credentials_credentials_id'],
                'FROM'   => self::getTable(),
                'WHERE'  => [
                    'itemtype' => $itemtype,
                    'items_id' => $items_id,
                ],
            ]);
        $credential_ids = [];
        foreach ($iterator as $row) {
            $credential_ids[] = (int) $row['plugin_credentials_credentials_id'];
        }

        // GLPI may call ITEM_PURGE from inside an existing transaction. GLPI's
        // DB layer supports nested transactions through savepoints: always open
        // one local transaction level and close only that level.
        $DB->beginTransaction();
        try {
            if ($credential_ids !== []) {
                $credential_ids = array_values(array_unique($credential_ids));
                sort($credential_ids, SORT_NUMERIC);
                // Lock credential rows before deleting the source relationships. This
                // serializes purge with explicit link/unlink operations.
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
