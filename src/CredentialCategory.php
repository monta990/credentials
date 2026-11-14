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
     * Serialize category writers by locking the corresponding GLPI entity row.
     * Every category create/edit/find-or-create/delete operation takes this lock
     * before checking names, so concurrent operations cannot pass the same check.
     *
     * This avoids a schema migration that could fail on installations with
     * pre-existing duplicate or soft-deleted category records.
     */
    private static function withEntityLock(int $entities_id, callable $operation): mixed
    {
        global $DB;

        if ($entities_id < 0) {
            return false;
        }

        $DB->beginTransaction();
        try {
            // Serialize category writers using GLPI's DB abstraction. Updating the
            // entity row to its existing value obtains an InnoDB write lock without
            // changing core entity data or constructing raw SQL.
            $locked = $DB->update('glpi_entities', [
                'date_mod' => new QueryExpression(\DBmysql::quoteName('date_mod')),
            ], ['id' => $entities_id]);
            if ($locked === false) {
                throw new \RuntimeException('Unable to lock the GLPI entity while updating credential categories.');
            }

            $entity = $DB->request([
                'SELECT' => ['id'],
                'FROM' => 'glpi_entities',
                'WHERE' => ['id' => $entities_id],
                'LIMIT' => 1,
            ])->current();
            if (!is_array($entity)) {
                $DB->rollBack();
                return false;
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
