<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials;

use Glpi\DBAL\QueryExpression;

/** Lightweight category catalog scoped to GLPI entities. */
final class CredentialCategory
{
    public static function getTable(): string
    {
        return 'glpi_plugin_credentials_categories';
    }

    /** @return array<int, array{id:int,name:string,entities_id:int,comment:string}> */
    public static function getOptions(int $entities_id): array
    {
        global $DB;

        $rows = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'name', 'entities_id', 'comment'],
            'FROM' => self::getTable(),
            'WHERE' => [
                'is_deleted' => 0,
                'entities_id' => [0, $entities_id],
            ],
            'ORDER' => ['name'],
        ]);
        foreach ($iterator as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'entities_id' => (int) $row['entities_id'],
                'comment' => (string) ($row['comment'] ?? ''),
            ];
        }

        return $rows;
    }

    /** @return array{id:int,name:string,entities_id:int,comment:string}|null */
    public static function getById(int $id): ?array
    {
        global $DB;

        if ($id <= 0) {
            return null;
        }
        $row = $DB->request([
            'SELECT' => ['id', 'name', 'entities_id', 'comment'],
            'FROM' => self::getTable(),
            'WHERE' => ['id' => $id, 'is_deleted' => 0],
            'LIMIT' => 1,
        ])->current();

        return is_array($row) ? [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'entities_id' => (int) $row['entities_id'],
            'comment' => (string) ($row['comment'] ?? ''),
        ] : null;
    }

    public static function isAvailableForEntity(int $id, int $entities_id): bool
    {
        $category = self::getById($id);
        return $category !== null && in_array($category['entities_id'], [0, $entities_id], true);
    }

    /** Create a category with a per-entity lock protecting its unique active name. */
    public static function create(string $name, int $entities_id, string $comment = ''): bool
    {
        $name = trim($name);
        $comment = trim($comment);
        if ($name === '' || mb_strlen($name) > 255 || mb_strlen($comment) > 2000
            || $entities_id < 0 || !\Session::haveAccessToEntity($entities_id)
        ) {
            return false;
        }

        return self::withEntityLock($entities_id, static function () use ($name, $entities_id, $comment): bool {
            global $DB;

            $existing = $DB->request([
                'SELECT' => ['id'],
                'FROM' => self::getTable(),
                'WHERE' => [
                    'entities_id' => $entities_id,
                    'name' => $name,
                    'is_deleted' => 0,
                ],
                'LIMIT' => 1,
            ]);
            if (count($existing) > 0) {
                return false;
            }

            return $DB->insert(self::getTable(), [
                'name' => $name,
                'entities_id' => $entities_id,
                'comment' => $comment,
                'date_creation' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
                'date_mod' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
                'is_deleted' => 0,
            ]) !== false;
        });
    }

    /**
     * Update a category without changing its entity scope or credential associations.
     * The entity row lock serializes concurrent create/update operations that could
     * otherwise pass the same preflight name check at the same time.
     */
    public static function update(int $id, string $name, string $comment = ''): bool
    {
        if ($id <= 0) {
            return false;
        }

        $name = trim($name);
        $comment = trim($comment);
        if ($name === '' || mb_strlen($name) > 255 || mb_strlen($comment) > 2000) {
            return false;
        }

        $initial = self::getById($id);
        if ($initial === null || !\Session::haveAccessToEntity($initial['entities_id'])) {
            return false;
        }

        return self::withEntityLock($initial['entities_id'], static function () use ($id, $name, $comment, $initial): bool {
            global $DB;

            $category = self::getById($id);
            if (
                $category === null
                || $category['entities_id'] !== $initial['entities_id']
                || !\Session::haveAccessToEntity($category['entities_id'])
            ) {
                return false;
            }

            $existing = $DB->request([
                'SELECT' => ['id'],
                'FROM' => self::getTable(),
                'WHERE' => [
                    'entities_id' => $category['entities_id'],
                    'name' => $name,
                    'is_deleted' => 0,
                ],
            ]);
            foreach ($existing as $row) {
                if ((int) $row['id'] !== $id) {
                    return false;
                }
            }

            return $DB->update(self::getTable(), [
                'name' => $name,
                'comment' => $comment,
                'date_mod' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
            ], ['id' => $id, 'is_deleted' => 0]) !== false;
        });
    }

    /** Do not remove categories while credentials still refer to them. */
    public static function deleteIfUnused(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $initial = self::getById($id);
        if ($initial === null || !\Session::haveAccessToEntity($initial['entities_id'])) {
            return false;
        }

        return self::withEntityLock($initial['entities_id'], static function () use ($id, $initial): bool {
            global $DB;

            $category = self::getById($id);
            if (
                $category === null
                || $category['entities_id'] !== $initial['entities_id']
                || !\Session::haveAccessToEntity($category['entities_id'])
            ) {
                return false;
            }

            $used = $DB->request([
                'SELECT' => ['id'],
                'FROM' => Credential::getTable(),
                'WHERE' => ['plugin_credentials_categories_id' => $id],
                'LIMIT' => 1,
            ]);
            if (count($used) > 0) {
                return false;
            }

            return $DB->update(self::getTable(), [
                'is_deleted' => 1,
                'date_mod' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
            ], ['id' => $id, 'is_deleted' => 0]) !== false;
        });
    }

    public static function findOrCreateByName(string $name, int $entities_id, string $comment = ''): int
    {
        $name = trim($name);
        $comment = trim($comment);
        if ($name === '' || mb_strlen($name) > 255 || mb_strlen($comment) > 2000 || $entities_id < 0) {
            return 0;
        }

        return self::withEntityLock($entities_id, static function () use ($name, $entities_id, $comment): int {
            global $DB;

            $rows = $DB->request([
                'SELECT' => ['id'],
                'FROM' => self::getTable(),
                'WHERE' => [
                    'name' => $name,
                    'entities_id' => [$entities_id, 0],
                    'is_deleted' => 0,
                ],
                'ORDER' => ['entities_id DESC'],
                'LIMIT' => 1,
            ]);
            $row = $rows->current();
            if (is_array($row)) {
                return (int) $row['id'];
            }

            if (!\Session::haveAccessToEntity($entities_id)) {
                return 0;
            }

            $inserted = $DB->insert(self::getTable(), [
                'name' => $name,
                'entities_id' => $entities_id,
                'comment' => $comment,
                'date_creation' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
                'date_mod' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
                'is_deleted' => 0,
            ]);
            return $inserted === false ? 0 : (int) $inserted;
        });
    }

    /**
     * Find or create a category strictly inside the requested entity scope.
     *
     * Backup imports use this resolver because the exported scope is part of the
     * backup data contract: an entity category must never silently become global
     * merely because a global category with the same name exists. The existing
     * findOrCreateByName() keeps its local-then-global fallback for workflows such
     * as entity transfer where that fallback is intentional.
     */
    public static function findOrCreateInExactScope(string $name, int $entities_id, string $comment = ''): int
    {
        $name = trim($name);
        $comment = trim($comment);
        if ($name === '' || mb_strlen($name) > 255 || mb_strlen($comment) > 2000 || $entities_id < 0) {
            return 0;
        }

        return self::withEntityLock($entities_id, static function () use ($name, $entities_id, $comment): int {
            global $DB;

            // The import itself may be nested inside another transaction. Use a
            // DBAL locking read after the entity-row lock so the lookup does not
            // reuse a REPEATABLE READ snapshot created before a concurrent category
            // creation committed. The exact entities_id predicate preserves scope.
            $rows = $DB->request([
                'SELECT' => ['id'],
                'FROM' => self::getTable(),
                'WHERE' => [
                    'name' => $name,
                    'entities_id' => $entities_id,
                    'is_deleted' => 0,
                ],
                'ORDER' => [new QueryExpression(\DBmysql::quoteName('id') . ' FOR UPDATE')],
            ]);
            $row = $rows->current();
            if (is_array($row)) {
                return (int) $row['id'];
            }

            if (!\Session::haveAccessToEntity($entities_id)) {
                return 0;
            }

            $inserted = $DB->insert(self::getTable(), [
                'name' => $name,
                'entities_id' => $entities_id,
                'comment' => $comment,
                'date_creation' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
                'date_mod' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
                'is_deleted' => 0,
            ]);
            return $inserted === false ? 0 : (int) $inserted;
        });
    }

    /**
     * Serialize category writers using a plugin-owned lock row for the entity.
     * The row is created lazily and then updated in place inside the transaction;
     * no core GLPI table is written to coordinate plugin operations.
     */
    private static function withEntityLock(int $entities_id, callable $operation): mixed
    {
        global $DB;

        if ($entities_id < 0) {
            return false;
        }

        $lock_table = 'glpi_plugin_credentials_entity_locks';
        $DB->beginTransaction();
        try {
            // Check first so ordinary calls do not intentionally issue a duplicate
            // INSERT. The INSERT remains necessary for entities created after plugin
            // installation; a concurrent first writer may still win that race.
            $lock_row = $DB->request([
                'SELECT' => ['entities_id'],
                'FROM' => $lock_table,
                'WHERE' => ['entities_id' => $entities_id],
                'LIMIT' => 1,
            ])->current();
            if (!is_array($lock_row)) {
                try {
                    $DB->insert($lock_table, [
                        'entities_id' => $entities_id,
                        'lock_token' => bin2hex(random_bytes(16)),
                    ]);
                } catch (\Throwable $exception) {
                    // A concurrent transaction may have inserted this entity row
                    // after the existence check. Keep the exception so it can be
                    // surfaced if the subsequent UPDATE cannot obtain the lock.
                    $insert_error = $exception;
                }
            }

            // Updating a changing token is an atomic current write: it both proves
            // the row exists and obtains its InnoDB lock without depending on a
            // repeatable-read SELECT snapshot or modifying a core GLPI table.
            $locked = $DB->update($lock_table, [
                'lock_token' => bin2hex(random_bytes(16)),
            ], ['entities_id' => $entities_id]);
            if ($locked === false || $DB->getAffectedRows() !== 1) {
                if ($insert_error !== null) {
                    throw $insert_error;
                }
                throw new \RuntimeException('Unable to create or lock the Credentials entity row.');
            }

            $value = $operation();
            $DB->commit();
            return $value;
        } catch (\Throwable $exception) {
            $DB->rollBack();
            throw $exception;
        }
    }
}
