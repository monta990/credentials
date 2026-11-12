<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials;

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

    /** Create a category without allowing duplicate labels in the same entity. */
    public static function create(string $name, int $entities_id, string $comment = ''): bool
    {
        global $DB;

        $name = trim($name);
        $comment = trim($comment);
        if ($name === '' || mb_strlen($name) > 255 || mb_strlen($comment) > 2000
            || $entities_id < 0 || !\Session::haveAccessToEntity($entities_id)
        ) {
            return false;
        }

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
    }

    /** Do not remove categories while credentials still refer to them. */
    public static function deleteIfUnused(int $id): bool
    {
        global $DB;

        if ($id <= 0) {
            return false;
        }
        $category = self::getById($id);
        if ($category === null || !\Session::haveAccessToEntity($category['entities_id'])) {
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
    }

    public static function findOrCreateByName(string $name, int $entities_id): int
    {
        global $DB;

        $name = trim($name);
        if ($name === '') {
            return 0;
        }
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

        if (!self::create($name, $entities_id)) {
            return 0;
        }
        $created = $DB->request([
            'SELECT' => ['id'],
            'FROM' => self::getTable(),
            'WHERE' => [
                'name' => $name,
                'entities_id' => $entities_id,
                'is_deleted' => 0,
            ],
            'LIMIT' => 1,
        ])->current();

        return is_array($created) ? (int) $created['id'] : 0;
    }
}
