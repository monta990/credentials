<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials\Service;

use GlpiPlugin\Credentials\Credential;
use GlpiPlugin\Credentials\CredentialCategory;
use GlpiPlugin\Credentials\CredentialHistory;
use GlpiPlugin\Credentials\CredentialItem;
use GlpiPlugin\Credentials\CredentialItemLink;
use GlpiPlugin\Credentials\CredentialStatus;
use Session;

/** Non-secret administrative summaries, archive retention and recovery. */
final class CredentialAdminService
{
    /** @return array<string,mixed> */
    public static function dashboard(int $entities_id, int $retention_days): array
    {
        global $DB;

        $can_read_credentials = Session::haveRight(Credential::$rightname, READ);
        $today = substr((string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d')), 0, 10);
        $rows = $DB->request([
            'SELECT' => [
                'id', 'name', 'entities_id', 'is_deleted', 'date_mod',
                'date_expires', 'date_next_review', 'plugin_credentials_categories_id',
            ],
            'FROM' => Credential::getTable(),
            'ORDER' => ['name'],
        ]);

        $counts = [
            'active' => 0,
            'archived' => 0,
            'expired' => 0,
            'expiring' => 0,
            'review_overdue' => 0,
            'review_due' => 0,
        ];
        $attention = [];
        $archived = [];
        $restorable_items_by_entity = [];
        $purge_before = $retention_days > 0
            ? (new \DateTimeImmutable('now'))->modify('-' . $retention_days . ' days')->format('Y-m-d H:i:s')
            : null;
        $purge_eligible = 0;

        foreach ($rows as $row) {
            $entity = (int) $row['entities_id'];
            if (!Session::haveAccessToEntity($entity)) {
                continue;
            }
            $id = (int) $row['id'];
            if ((int) $row['is_deleted'] === 1) {
                $counts['archived']++;
                $modified = (string) ($row['date_mod'] ?? '');
                $eligible = $purge_before !== null && $modified !== '' && $modified < $purge_before;
                if ($eligible) {
                    $purge_eligible++;
                }
                if ($can_read_credentials && count($archived) < 25) {
                    if (!array_key_exists($entity, $restorable_items_by_entity)) {
                        $restorable_items_by_entity[$entity] = self::restorableItemsForEntity($entity);
                    }
                    $archived[] = [
                        'id' => $id,
                        'name' => (string) $row['name'],
                        'date_mod' => $modified,
                        'entities_id' => $entity,
                        'purge_eligible' => $eligible,
                        'restorable_items' => $restorable_items_by_entity[$entity],
                    ];
                }
                continue;
            }

            $counts['active']++;
            $status = CredentialStatus::fromDates(
                $row['date_expires'] !== null ? (string) $row['date_expires'] : null,
                $row['date_next_review'] !== null ? (string) $row['date_next_review'] : null,
                $today
            );
            foreach (['expired', 'expiring', 'review_overdue', 'review_due'] as $flag) {
                if ($status[$flag]) {
                    $counts[$flag]++;
                }
            }
            if ($can_read_credentials && $status['key'] !== 'current' && count($attention) < 25) {
                $attention[] = [
                    'id' => $id,
                    'name' => (string) $row['name'],
                    'status' => $status['label'],
                    'status_key' => $status['key'],
                    'date_expires' => (string) ($row['date_expires'] ?? ''),
                    'date_next_review' => (string) ($row['date_next_review'] ?? ''),
                ];
            }
        }

        $category_rows = CredentialCategory::getOptions($entities_id);
        return [
            'counts' => $counts,
            'can_read_credentials' => $can_read_credentials,
            'attention' => $attention,
            'archived' => $archived,
            'purge_eligible' => $purge_eligible,
            'categories' => $category_rows,
        ];
    }

    /**
     * Return a bounded list of items in an entity that the current user may update.
     * This replaces manual itemtype/ID entry in the archive restoration form.
     *
     * @return array<int,array{value:string,label:string}>
     */
    private static function restorableItemsForEntity(int $entities_id): array
    {
        global $DB;

        if (!Session::haveAccessToEntity($entities_id)
            || !Session::haveRight(Credential::$rightname, UPDATE)
        ) {
            return [];
        }

        $options = [];
        foreach (CredentialItem::getSupportedItemtypes() as $itemtype) {
            if (count($options) >= 500) {
                break;
            }

            try {
                $prototype = getItemForItemtype($itemtype);
                if (!$prototype instanceof \CommonDBTM) {
                    continue;
                }
                $where = ['is_deleted' => 0];
                if ($prototype->isEntityAssign()) {
                    $where['entities_id'] = $entities_id;
                } elseif ($entities_id !== 0) {
                    continue;
                }
                $rows = $DB->request([
                    'SELECT' => ['id', 'name'],
                    'FROM' => $prototype::getTable(),
                    'WHERE' => $where,
                    'ORDER' => ['name'],
                    'LIMIT' => 250,
                ]);

                foreach ($rows as $row) {
                    $id = (int) ($row['id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    $item = getItemForItemtype($itemtype);
                    if (!$item instanceof \CommonDBTM
                        || !$item->getFromDB($id)
                        || !$item->can($id, UPDATE)
                        || CredentialItem::resolveParentEntityID($item) !== $entities_id
                    ) {
                        continue;
                    }
                    $type_label = $itemtype::getTypeName(1);
                    $item_label = trim((string) $item->getName());
                    $options[] = [
                        'value' => $itemtype . '|' . $id,
                        'label' => $type_label . ' — ' . ($item_label !== '' ? $item_label : '#' . $id) . ' (#' . $id . ')',
                    ];
                    if (count($options) >= 500) {
                        break 2;
                    }
                }
            } catch (\Throwable) {
                // Unsupported/abstract item types are skipped; restoration still
                // validates the submitted target independently on the server.
                continue;
            }
        }

        usort($options, static fn(array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
        return $options;
    }

    /** Restore an archived credential by explicitly attaching it to a valid item. */
    public static function restore(int $credential_id, string $itemtype, int $items_id): bool
    {
        global $DB;

        if ($credential_id <= 0
            || !Session::haveRight(Credential::$rightname, UPDATE)
            || !CredentialItem::isSupportedItemtype($itemtype)
        ) {
            return false;
        }

        $item = CredentialItem::loadReadableItem($itemtype, $items_id);
        if ($item === null || !$item->can($items_id, UPDATE)) {
            return false;
        }
        $entity_id = CredentialItem::resolveParentEntityID($item);
        if (!Session::haveAccessToEntity($entity_id)) {
            return false;
        }

        $DB->beginTransaction();
        try {
            if (!CredentialItemLink::lockCredential($credential_id, $entity_id, false)) {
                $DB->rollBack();
                return false;
            }

            $credential = new Credential();
            if (!$credential->getFromDB($credential_id)
                || (int) ($credential->fields['is_deleted'] ?? 0) !== 1
                || (int) ($credential->fields['entities_id'] ?? -1) !== $entity_id
                || CredentialItemLink::getLinksForCredentialForUpdate($credential_id) !== []
            ) {
                $DB->rollBack();
                return false;
            }

            $link = new CredentialItemLink();
            if (!$link->add([
                'plugin_credentials_credentials_id' => $credential_id,
                'itemtype' => $itemtype,
                'items_id' => $items_id,
                'date_creation' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
            ])) {
                $DB->rollBack();
                return false;
            }

            $updated = $DB->update(Credential::getTable(), [
                'is_deleted' => 0,
                'date_mod' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
            ], [
                'id' => $credential_id,
                'entities_id' => $entity_id,
                'is_deleted' => 1,
            ]);
            if ($updated === false || $DB->getAffectedRows() !== 1
                || !CredentialHistory::recordForCredential($credential_id, Credential::HISTORY_RESTORED)
                || !CredentialHistory::recordForItem($itemtype, $items_id, Credential::HISTORY_RESTORED, $credential_id)
            ) {
                $DB->rollBack();
                return false;
            }

            $DB->commit();
            return true;
        } catch (\Throwable $exception) {
            $DB->rollBack();
            throw $exception;
        }
    }

    /**
     * Permanently remove one archived credential immediately, without applying retention.
     * The record must be archived, belong to an accessible entity and have no remaining links.
     */
    public static function purgeArchivedCredential(int $credential_id): bool
    {
        global $DB;

        if ($credential_id <= 0) {
            return false;
        }
        if (!Session::haveRight(Credential::$rightname, READ)
            || !Session::haveRight(Credential::$rightname, DELETE)
        ) {
            throw new \RuntimeException(__('You do not have permission to permanently delete archived credentials.', 'credentials'));
        }

        $probe = new Credential();
        if (!$probe->getFromDB($credential_id)) {
            return false;
        }
        $entity_id = (int) ($probe->fields['entities_id'] ?? -1);
        if ($entity_id < 0 || !Session::haveAccessToEntity($entity_id)) {
            throw new \RuntimeException(__('You do not have access to this archived credential.', 'credentials'));
        }

        $DB->beginTransaction();
        try {
            if (!CredentialItemLink::lockCredential($credential_id, $entity_id, false)) {
                $DB->rollBack();
                return false;
            }

            $credential = new Credential();
            if (!$credential->getFromDB($credential_id)
                || (int) ($credential->fields['entities_id'] ?? -1) !== $entity_id
                || (int) ($credential->fields['is_deleted'] ?? 0) !== 1
            ) {
                $DB->rollBack();
                return false;
            }

            // Never permanently delete a credential while any asset/user relationship remains.
            if (CredentialItemLink::getLinksForCredentialForUpdate($credential_id) !== []) {
                throw new \RuntimeException(__('This archived credential is still linked to an item. Remove its links before permanently deleting it.', 'credentials'));
            }

            // Keep a secret-free native audit record before removing the credential row.
            if (!CredentialHistory::recordForCredential($credential_id, Credential::HISTORY_PURGED)) {
                throw new \RuntimeException('Unable to record archived credential deletion history.');
            }

            if ($DB->delete(CredentialItemLink::getTable(), [
                'plugin_credentials_credentials_id' => $credential_id,
            ]) === false
                || $DB->delete('glpi_plugin_credentials_reminders', [
                    'plugin_credentials_credentials_id' => $credential_id,
                ]) === false
                || $DB->delete(Credential::getTable(), [
                    'id' => $credential_id,
                    'entities_id' => $entity_id,
                    'is_deleted' => 1,
                ]) === false
                || $DB->getAffectedRows() !== 1
            ) {
                throw new \RuntimeException('Unable to permanently remove archived credential.');
            }

            $DB->commit();
            return true;
        } catch (\Throwable $exception) {
            $DB->rollBack();
            throw $exception;
        }
    }

    /** Permanently remove only archived, unlinked credentials older than the configured age. */
    public static function purgeEligible(int $retention_days): int
    {
        global $DB;

        if (!Session::haveRight(Credential::$rightname, READ)
            || !Session::haveRight(Credential::$rightname, DELETE)
        ) {
            throw new \RuntimeException(__('You do not have permission to permanently delete archived credentials.', 'credentials'));
        }
        if ($retention_days <= 0) {
            return 0;
        }
        $cutoff = (new \DateTimeImmutable('now'))->modify('-' . min($retention_days, 3650) . ' days')->format('Y-m-d H:i:s');
        $candidates = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'entities_id'],
            'FROM' => Credential::getTable(),
            'WHERE' => ['is_deleted' => 1, 'date_mod' => ['<', $cutoff]],
            'ORDER' => ['id'],
        ]);
        foreach ($iterator as $row) {
            $entity = (int) $row['entities_id'];
            if (Session::haveAccessToEntity($entity)) {
                $candidates[] = [(int) $row['id'], $entity];
            }
        }
        if ($candidates === []) {
            return 0;
        }

        // Candidate rows were enumerated before the transaction. Lock every
        // candidate parent row, in ascending ID order, before the first consistent
        // read. This prevents a REPEATABLE READ snapshot from predating later locks.
        usort($candidates, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $DB->beginTransaction();
        $purged = 0;
        try {
            $locked_ids = CredentialItemLink::lockCredentials(array_column($candidates, 0));
            $locked_set = array_fill_keys($locked_ids, true);
            foreach ($candidates as [$id, $entity]) {
                if (!isset($locked_set[$id])) {
                    continue;
                }
                $credential = new Credential();
                if (!$credential->getFromDB($id)
                    || (int) ($credential->fields['is_deleted'] ?? 0) !== 1
                    || (int) ($credential->fields['entities_id'] ?? -1) !== $entity
                    || (string) ($credential->fields['date_mod'] ?? '') >= $cutoff
                ) {
                    continue;
                }

                // A record with any remaining link is never purged by this action.
                if (CredentialItemLink::getLinksForCredentialForUpdate($id) !== []) {
                    continue;
                }
                if (!CredentialHistory::recordForCredential($id, Credential::HISTORY_PURGED)) {
                    throw new \RuntimeException('Unable to record archived credential purge history.');
                }
                if ($DB->delete(CredentialItemLink::getTable(), [
                    'plugin_credentials_credentials_id' => $id,
                ]) === false
                    || $DB->delete('glpi_plugin_credentials_reminders', [
                        'plugin_credentials_credentials_id' => $id,
                    ]) === false
                    || $DB->delete(Credential::getTable(), ['id' => $id, 'is_deleted' => 1]) === false
                ) {
                    throw new \RuntimeException('Unable to permanently remove archived credential.');
                }
                $purged++;
            }
            $DB->commit();
        } catch (\Throwable $exception) {
            $DB->rollBack();
            throw $exception;
        }

        return $purged;
    }
}
