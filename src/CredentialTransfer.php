<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials;

/**
 * Keeps credential relationships aligned with GLPI entity transfers.
 *
 * GLPI calls ITEM_TRANSFER once for each moved or cloned item. Cross-entity
 * moves split a shared credential before moving that item's relationship;
 * clones receive their own credential copy. Protected values are copied as
 * GLPIKey ciphertext and are never decrypted by this class.
 */
final class CredentialTransfer
{
    /**
     * Handle the GLPI Hooks::ITEM_TRANSFER payload.
     *
     * @param array{type?:mixed,id?:mixed,newID?:mixed,entities_id?:mixed} $params
     */
    public static function handle(array $params): void
    {
        global $DB;

        $itemtype = $params['type'] ?? null;
        $item_id = (int) ($params['id'] ?? 0);
        $new_id = (int) ($params['newID'] ?? 0);
        $destination_entity = (int) ($params['entities_id'] ?? -1);

        if (
            !is_string($itemtype)
            || !CredentialItem::isSupportedItemtype($itemtype)
            || $item_id <= 0
            || $new_id <= 0
            || $destination_entity < 0
        ) {
            return;
        }

        $source_item = getItemForItemtype($itemtype);
        if (!$source_item instanceof \CommonDBTM || !$source_item->getFromDB($item_id)) {
            return;
        }

        $source_entity = CredentialItem::resolveParentEntityID($source_item);
        if ($item_id === $new_id) {
            if ($source_entity !== $destination_entity) {
                throw new \RuntimeException('Transferred item entity does not match the destination entity.');
            }
            self::moveItemRelationships($itemtype, $item_id, $destination_entity);
            return;
        }

        $destination_item = getItemForItemtype($itemtype);
        if (
            !$destination_item instanceof \CommonDBTM
            || !$destination_item->getFromDB($new_id)
            || CredentialItem::resolveParentEntityID($destination_item) !== $destination_entity
        ) {
            throw new \RuntimeException('Cloned item entity does not match the destination entity.');
        }

        $DB->beginTransaction();
        try {
            self::copyItemRelationships($itemtype, $item_id, $new_id, $source_entity, $destination_entity);
            $DB->commit();
        } catch (\Throwable $exception) {
            $DB->rollBack();
            throw $exception;
        }
    }

    /**
     * Move relationships for an item whose ID remains unchanged.
     * A credential shared with other items is split so those items stay in
     * their original entity and only the transferred relationship moves.
     */
    private static function moveItemRelationships(string $itemtype, int $items_id, int $destination_entity): void
    {
        global $DB;

        $DB->beginTransaction();
        try {
            $credential_ids = self::getCredentialIDsForItem($itemtype, $items_id);
            foreach ($credential_ids as $credential_id) {
                $credential = self::lockAndReadCredential($credential_id, $itemtype, $items_id);
                if ($credential === null) {
                    continue;
                }

                $credential_links = CredentialItemLink::getLinksForCredentialForUpdate($credential_id);
                if (!self::containsLink($credential_links, $itemtype, $items_id)) {
                    // The source link may have been removed while this transfer
                    // waited for the credential lock. Skip it; do not abort GLPI's move.
                    continue;
                }

                $current_entity = (int) $credential['entities_id'];
                if ($current_entity === $destination_entity) {
                    continue;
                }

                $date_mod = self::currentTime();
                if (count($credential_links) <= 1) {
                    $destination_category = self::mapCategory(
                        (int) ($credential['plugin_credentials_categories_id'] ?? 0),
                        $destination_entity
                    );
                    $updated = $DB->update(Credential::getTable(), [
                        'entities_id' => $destination_entity,
                        'plugin_credentials_categories_id' => $destination_category,
                        'date_mod' => $date_mod,
                    ], [
                        'id' => $credential_id,
                        'entities_id' => $current_entity,
                        'is_deleted' => 0,
                    ]);
                    if ($updated === false || $DB->getAffectedRows() !== 1) {
                        throw new \RuntimeException('Unable to move the credential to the destination entity.');
                    }
                    if (!CredentialHistory::recordForCredential($credential_id, Credential::HISTORY_UPDATED)) {
                        throw new \RuntimeException('Unable to record the credential entity transfer.');
                    }
                    continue;
                }

                // Do not change the entity of a credential still used by other items.
                // Copy ciphertext as-is, then move only this item's relationship.
                $new_credential_id = self::insertCredentialCopy($credential, $destination_entity);
                $moved = $DB->update(CredentialItemLink::getTable(), [
                    'plugin_credentials_credentials_id' => $new_credential_id,
                ], [
                    'plugin_credentials_credentials_id' => $credential_id,
                    'itemtype' => $itemtype,
                    'items_id' => $items_id,
                ]);
                if ($moved === false || $DB->getAffectedRows() !== 1) {
                    throw new \RuntimeException('Unable to move the credential relationship to its entity copy.');
                }

                if (
                    !CredentialHistory::recordForCredential($credential_id, Credential::HISTORY_UPDATED)
                    || !CredentialHistory::recordForCredential($new_credential_id, Credential::HISTORY_CREATED)
                ) {
                    throw new \RuntimeException('Unable to record the split credential transfer.');
                }
            }

            $DB->commit();
        } catch (\Throwable $exception) {
            $DB->rollBack();
            throw $exception;
        }
    }

    /**
     * Copy an item's relationships when GLPI creates a new item ID.
     * The original item and its credentials remain untouched.
     */
    private static function copyItemRelationships(
        string $itemtype,
        int $source_id,
        int $destination_id,
        int $source_entity,
        int $destination_entity,
    ): void {
        global $DB;

        foreach (self::getCredentialIDsForItem($itemtype, $source_id) as $credential_id) {
            $credential = self::lockAndReadCredential($credential_id, $itemtype, $source_id);
            if ($credential === null) {
                // The credential was deleted, archived or unlinked while this clone waited.
                continue;
            }

            $credential_links = CredentialItemLink::getLinksForCredentialForUpdate($credential_id);
            if (
                (int) $credential['entities_id'] !== $source_entity
                || !self::containsLink($credential_links, $itemtype, $source_id)
            ) {
                // A stale cross-entity relationship must never be copied into a
                // destination where the source credential is not owned.
                continue;
            }

            if (self::containsLink($credential_links, $itemtype, $destination_id)) {
                continue;
            }

            $new_credential_id = $credential_id;
            if ($source_entity !== $destination_entity) {
                $new_credential_id = self::insertCredentialCopy($credential, $destination_entity);
            }

            $inserted = $DB->insert(CredentialItemLink::getTable(), [
                'plugin_credentials_credentials_id' => $new_credential_id,
                'itemtype' => $itemtype,
                'items_id' => $destination_id,
                'date_creation' => self::currentTime(),
            ]);
            if ($inserted === false) {
                throw new \RuntimeException('Unable to link the credential to the cloned item.');
            }

            $action = $new_credential_id === $credential_id
                ? Credential::HISTORY_LINKED
                : Credential::HISTORY_CREATED;
            if (!CredentialHistory::recordForCredential($new_credential_id, $action)) {
                throw new \RuntimeException('Unable to record the credential link created by the transfer.');
            }
        }
    }

    /**
     * @return array<int, int>
     */
    private static function getCredentialIDsForItem(string $itemtype, int $items_id): array
    {
        global $DB;

        $ids = [];
        $iterator = $DB->request([
            'SELECT' => ['plugin_credentials_credentials_id'],
            'FROM' => CredentialItemLink::getTable(),
            'WHERE' => [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
            ],
        ]);
        foreach ($iterator as $row) {
            $id = (int) $row['plugin_credentials_credentials_id'];
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        $ids = array_values($ids);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * Lock a credential and return only fields needed to copy its encrypted row.
     *
     * @return array<string, mixed>|null
     */
    private static function lockAndReadCredential(int $credential_id, string $itemtype, int $items_id): ?array
    {
        global $DB;

        $row = self::readCredential($credential_id);
        if ($row === null || (int) $row['is_deleted'] !== 0) {
            return null;
        }

        $expected_entity = (int) $row['entities_id'];
        if (!CredentialItemLink::lockCredential($credential_id, $expected_entity, true)) {
            // The conditional UPDATE may have waited for a concurrent archive or unlink.
            // Use current reads to distinguish stale transfer input from a real lock failure.
            $current = CredentialItemLink::getCredentialForUpdate($credential_id);
            if (
                $current === null
                || (int) $current['is_deleted'] !== 0
                || (int) $current['entities_id'] !== $expected_entity
            ) {
                return null;
            }

            $current_links = CredentialItemLink::getLinksForCredentialForUpdate($credential_id);
            if (!self::containsLink($current_links, $itemtype, $items_id)) {
                return null;
            }

            // The row and exact source relationship are still active. Do not hide
            // an unexpected DB lock failure behind a stale-ID skip.
            throw new \RuntimeException('Unable to lock active credential during entity transfer.');
        }

        $row = CredentialItemLink::getCredentialForUpdate($credential_id);
        if (
            $row === null
            || (int) $row['is_deleted'] !== 0
            || (int) $row['entities_id'] !== $expected_entity
        ) {
            throw new \RuntimeException('Credential changed while its entity transfer was being prepared.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function readCredential(int $credential_id): ?array
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [
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
            ],
            'FROM' => Credential::getTable(),
            'WHERE' => ['id' => $credential_id],
            'LIMIT' => 1,
        ]);
        foreach ($iterator as $row) {
            return $row;
        }
        return null;
    }

    /**
     * Create a destination-entity copy without decrypting protected fields.
     *
     * @param array<string, mixed> $credential
     */
    private static function insertCredentialCopy(array $credential, int $destination_entity): int
    {
        global $DB;

        $category_id = self::mapCategory(
            (int) ($credential['plugin_credentials_categories_id'] ?? 0),
            $destination_entity
        );
        $inserted = $DB->insert(Credential::getTable(), [
            'name' => (string) $credential['name'],
            'entities_id' => $destination_entity,
            'login' => $credential['login'],
            'password' => (string) $credential['password'],
            'url' => $credential['url'],
            'notes' => $credential['notes'],
            'users_id' => (int) $credential['users_id'],
            'plugin_credentials_categories_id' => $category_id,
            'date_expires' => $credential['date_expires'] ?? null,
            'date_last_review' => $credential['date_last_review'] ?? null,
            'date_next_review' => $credential['date_next_review'] ?? null,
            'reviewer_users_id' => (int) ($credential['reviewer_users_id'] ?? 0),
            'date_creation' => self::currentTime(),
            'date_mod' => self::currentTime(),
            'link_lock_token' => bin2hex(random_bytes(16)),
            'is_deleted' => 0,
        ]);
        if ($inserted === false) {
            throw new \RuntimeException('Unable to create a credential copy in the destination entity.');
        }

        $new_id = (int) $DB->insertId();
        if ($new_id <= 0) {
            throw new \RuntimeException('Unable to determine the ID of the copied credential.');
        }

        return $new_id;
    }

    /**
     * Map an entity-specific category to the destination entity during a transfer.
     * Global categories can be reused; entity-specific categories are recreated by
     * name in the destination so the credential never points to an inaccessible
     * source-entity category after it moves or is cloned.
     */
    private static function mapCategory(int $category_id, int $destination_entity): int
    {
        if ($category_id <= 0) {
            return 0;
        }

        $category = CredentialCategory::getById($category_id);
        if ($category === null) {
            return 0;
        }
        if ($category['entities_id'] === 0 || $category['entities_id'] === $destination_entity) {
            return $category_id;
        }

        $mapped = CredentialCategory::findOrCreateByName($category['name'], $destination_entity);
        if ($mapped <= 0) {
            throw new \\RuntimeException('Unable to create or find the destination credential category during item transfer.');
        }
        return $mapped;
    }

    /**
     * @param array<int, array{itemtype:string,items_id:int}> $links
     */
    private static function containsLink(array $links, string $itemtype, int $items_id): bool
    {
        foreach ($links as $link) {
            if ($link['itemtype'] === $itemtype && $link['items_id'] === $items_id) {
                return true;
            }
        }
        return false;
    }

    private static function currentTime(): string
    {
        return (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
    }
}
