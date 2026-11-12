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
     * Read current committed relationships while the credential row is locked.
     *
     * InnoDB consistent reads may retain an older REPEATABLE READ snapshot after
     * a lock wait. SELECT ... FOR UPDATE is a current read and observes the latest
     * committed relationship rows before transfer or purge decisions are made.
     *
     * This SELECT ... FOR UPDATE acquires the row lock itself. The caller must
     * run it inside a transaction and keep that transaction open through its work.
     *
     * @return array<int, array{itemtype:string,items_id:int}>
     */
    public static function getLinksForCredentialForUpdate(int $credential_id): array
    {
        global $DB;

        if ($credential_id <= 0) {
            return [];
        }

        // GLPI 12 DBmysql::request() has no FOR UPDATE option. Use the supported
        // DBmysql::doQuery() API with fixed, explicitly quoted identifiers/values.
        $query = 'SELECT '
            . \DBmysql::quoteName('itemtype') . ', '
            . \DBmysql::quoteName('items_id')
            . ' FROM ' . \DBmysql::quoteName(self::getTable())
            . ' WHERE ' . \DBmysql::quoteName('plugin_credentials_credentials_id')
            . ' = ' . \DBmysql::quoteValue($credential_id)
            . ' ORDER BY ' . \DBmysql::quoteName('itemtype') . ', '
            . \DBmysql::quoteName('items_id')
            . ' FOR UPDATE';

        $result = $DB->doQuery($query);
        $unique = [];
        try {
            while (($row = $DB->fetchAssoc($result)) !== false && $row !== null) {
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
        } finally {
            $DB->freeResult($result);
        }

        return array_values($unique);
    }

    /**
     * Read the latest committed credential fields after its row lock is held.
     *
     * This must be a locking/current read: under InnoDB REPEATABLE READ, a
     * normal DB->request() can keep returning an older snapshot after waiting
     * for a concurrent credential edit. The transfer path copies encrypted
     * columns directly, so it must copy the latest committed ciphertext.
     *
     * The caller must already hold this credential's row lock in a transaction.
     *
     * @return array<string, mixed>|null
     */
    public static function getCredentialForUpdate(int $credential_id): ?array
    {
        global $DB;

        if ($credential_id <= 0) {
            return null;
        }

        $columns = [
            'id',
            'name',
            'entities_id',
            'login',
            'password',
            'url',
            'notes',
            'users_id',
            'date_creation',
            'date_mod',
            'plugin_credentials_categories_id',
            'date_expires',
            'date_last_review',
            'date_next_review',
            'reviewer_users_id',
            'is_deleted',
        ];
        $select = implode(', ', array_map(
            static fn(string $column): string => \DBmysql::quoteName($column),
            $columns,
        ));
        $query = 'SELECT ' . $select
            . ' FROM ' . \DBmysql::quoteName(Credential::getTable())
            . ' WHERE ' . \DBmysql::quoteName('id')
            . ' = ' . \DBmysql::quoteValue($credential_id)
            . ' FOR UPDATE';

        $result = $DB->doQuery($query);
        try {
            $row = $DB->fetchAssoc($result);
            return $row === false || $row === null ? null : $row;
        } finally {
            $DB->freeResult($result);
        }
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

        return $updated !== false && $DB->getAffectedRows() === 1;
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

        // GLPI may call ITEM_PURGE from inside an existing transaction. GLPI's
        // DB layer supports nested transactions through savepoints: always open
        // one local transaction level and close only that level. Never inspect
        // the private DBmysql::isInTransaction() method or commit GLPI's caller.
        $DB->beginTransaction();

        try {
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
                    // Use a current read: link_existing may have committed another
                    // relationship while purge waited for this credential's row lock.
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
