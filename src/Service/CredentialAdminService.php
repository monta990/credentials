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
    private const ARCHIVE_PAGE_SIZE = 25;
    private const DASHBOARD_BATCH_SIZE = 500;
    private const PURGE_BATCH_SIZE = 100;

    /** @return array<string,mixed> */
    public static function dashboard(int $entities_id, int $retention_days, int $archived_page = 1): array
    {
        global $DB;

        $can_read_credentials = Session::haveRight(Credential::$rightname, READ);
        $today = substr((string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d')), 0, 10);
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
        $archive_page = max(1, min(1_000_000, $archived_page));
        $archive_offset = ($archive_page - 1) * self::ARCHIVE_PAGE_SIZE;
        $archive_position = 0;
        $purge_before = $retention_days > 0
            ? (new \DateTimeImmutable('now'))->modify('-' . min($retention_days, 3650) . ' days')->format('Y-m-d H:i:s')
            : null;
        $purge_eligible = 0;
        $last_id = 0;

        // Stream the credential table by primary key. Memory stays bounded even
        // when the installation has a very large inventory of credentials.
        do {
            $iterator = $DB->request([
                'SELECT' => [
                    'id', 'name', 'entities_id', 'is_deleted', 'date_mod',
                    'date_expires', 'date_next_review', 'plugin_credentials_categories_id',
                ],
                'FROM' => Credential::getTable(),
                'WHERE' => array_merge(
                    ['id' => ['>', $last_id]],
                    \getEntitiesRestrictCriteria(Credential::getTable(), 'entities_id', '', false)
                ),
                'ORDER' => ['id'],
                'LIMIT' => self::DASHBOARD_BATCH_SIZE,
            ]);
            $batch = [];
            foreach ($iterator as $row) {
                $batch[] = $row;
                $last_id = max($last_id, (int) ($row['id'] ?? 0));
            }
            if ($batch === []) {
                break;
            }

            $archived_batch = [];
            $visible_archive_records = [];
            foreach ($batch as $row) {
                $entity = (int) ($row['entities_id'] ?? -1);
                if ($entity < 0 || !Session::haveAccessToEntity($entity)) {
                    continue;
                }
                $id = (int) $row['id'];
                if ((int) $row['is_deleted'] === 1) {
                    $counts['archived']++;
                    $modified = (string) ($row['date_mod'] ?? '');
                    $old_enough = $purge_before !== null && $modified !== '' && $modified < $purge_before;
                    $record = [
                        'id' => $id,
                        'name' => (string) $row['name'],
                        'date_mod' => $modified,
                        'entities_id' => $entity,
                        'old_enough_for_purge' => $old_enough,
                    ];
                    if ($can_read_credentials) {
                        $archived_batch[] = $record;
                        if ($archive_position >= $archive_offset
                            && $archive_position < $archive_offset + self::ARCHIVE_PAGE_SIZE
                        ) {
                            $visible_archive_records[] = $record;
                        }
                    }
                    if ($can_read_credentials) {
                        $archive_position++;
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
                if ($can_read_credentials && $status['key'] !== 'current' && count($attention) < self::ARCHIVE_PAGE_SIZE) {
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

            if ($archived_batch !== []) {
                $link_counts = CredentialItemLink::getLinkCounts(array_column($archived_batch, 'id'));
                foreach ($archived_batch as $record) {
                    $id = (int) $record['id'];
                    $has_links = (int) ($link_counts[$id] ?? 0) > 0;
                    if ((bool) $record['old_enough_for_purge'] && !$has_links) {
                        $purge_eligible++;
                    }
                }
            }

            if ($visible_archive_records !== [] && $can_read_credentials) {
                $visible_ids = array_column($visible_archive_records, 'id');
                $links_by_credential = CredentialItemLink::getLinksForCredentials($visible_ids);
                $link_counts = CredentialItemLink::getLinkCounts($visible_ids);
                foreach ($visible_archive_records as $record) {
                    $id = (int) $record['id'];
                    $entity = (int) $record['entities_id'];
                    $link_count = (int) ($link_counts[$id] ?? 0);
                    $has_links = $link_count > 0;
                    $eligible = (bool) $record['old_enough_for_purge'] && !$has_links;
                    $remaining_links = [];
                    $hidden_link_count = 0;
                    foreach (($links_by_credential[$id] ?? []) as $link) {
                        $linked_itemtype = (string) $link['itemtype'];
                        $linked_items_id = (int) $link['items_id'];
                        try {
                            $linked_item = CredentialItem::loadReadableItem($linked_itemtype, $linked_items_id);
                            if ($linked_item === null
                                || (array_key_exists('is_deleted', $linked_item->fields) && (int) $linked_item->fields['is_deleted'] !== 0)
                                || CredentialItem::resolveParentEntityID($linked_item) !== $entity
                                || !Session::haveAccessToEntity($entity)
                            ) {
                                $hidden_link_count++;
                                continue;
                            }
                            $linked_url = trim((string) $linked_item->getLinkURL());
                            if ($linked_url === '') {
                                $hidden_link_count++;
                                continue;
                            }
                            $type_label = $linked_item::getTypeName(1);
                            $item_name = trim((string) $linked_item->getName());
                            $remaining_links[] = [
                                'label' => $type_label . ' — ' . ($item_name !== '' ? $item_name : '#' . $linked_items_id)
                                    . ' (#' . $linked_items_id . ')',
                                'url' => $linked_url,
                            ];
                        } catch (\Throwable) {
                            $hidden_link_count++;
                        }
                    }
                    $hidden_link_count = max($hidden_link_count, $link_count - count($remaining_links));
                    $all_links_validated = $link_count > 0
                        && $hidden_link_count === 0
                        && count($remaining_links) === $link_count;
                    $archived[] = [
                        'id' => $id,
                        'name' => (string) $record['name'],
                        'date_mod' => (string) $record['date_mod'],
                        'entities_id' => $entity,
                        'purge_eligible' => $eligible,
                        'link_count' => $link_count,
                        'has_links' => $has_links,
                        'remaining_links' => $remaining_links,
                        'hidden_link_count' => $hidden_link_count,
                        'can_restore_with_links' => $has_links
                            && $all_links_validated
                            && Session::haveRight(Credential::$rightname, UPDATE),
                        'can_purge_linked' => $has_links && $all_links_validated,
                        'restorable_items' => [],
                    ];
                }
            }
        } while (count($batch) === self::DASHBOARD_BATCH_SIZE);

        $archive_pages = max(1, (int) ceil($counts['archived'] / self::ARCHIVE_PAGE_SIZE));
        if ($archive_page > $archive_pages) {
            return self::dashboard($entities_id, $retention_days, $archive_pages);
        }

        return [
            'counts' => $counts,
            'can_read_credentials' => $can_read_credentials,
            'attention' => $attention,
            'archived' => $archived,
            'archived_page' => $archive_page,
            'archived_pages' => $archive_pages,
            'archived_total' => $counts['archived'],
            'purge_eligible' => $purge_eligible,
            'categories' => CredentialCategory::getOptions($entities_id),
        ];
    }

    /**
     * Search destination items on demand. Each itemtype scan is paginated and
     * bounded; matching candidates are combined and sorted before returning the
     * first result page. Final restore authorization is repeated in restore().
     *
     * @return array<int,array{value:string,label:string}>
     */
    public static function searchRestorableItemsForEntity(int $entities_id, string $search, int $limit = 50): array
    {
        global $DB;

        $search = mb_substr(trim($search), 0, 100);
        $limit = max(1, min(50, $limit));
        if (mb_strlen($search) < 2
            || !Session::haveAccessToEntity($entities_id)
            || !Session::haveRight(Credential::$rightname, UPDATE)
        ) {
            return [];
        }

        $options = [];
        foreach (CredentialItem::getSupportedItemtypes() as $itemtype) {
            try {
                $prototype = getItemForItemtype($itemtype);
                if (!$prototype instanceof \CommonDBTM) {
                    continue;
                }
                $name_field = (string) $prototype::getNameField();
                if ($name_field === '' || (!$prototype->isEntityAssign() && $entities_id !== 0)) {
                    continue;
                }
                // Prefix matching lets native name indexes help. Scan a bounded
                // number of candidates per itemtype so inaccessible early rows do
                // not hide a valid destination immediately after the first page.
                $where = [
                    'is_deleted' => 0,
                    $name_field => ['LIKE', addcslashes($search, '\\%_') . '%'],
                ];
                if ($prototype->isEntityAssign()) {
                    $where['entities_id'] = $entities_id;
                }
                $select = ['id'];
                if ($name_field !== 'id') {
                    $select[] = $name_field;
                }
                $per_page = 50;
                $scan_limit = 200;
                $itemtype_options = 0;
                for ($page = 0; $page < intdiv($scan_limit, $per_page); $page++) {
                    if ($itemtype_options >= $limit) {
                        break;
                    }
                    $start = $page * $per_page;
                    $rows = $DB->request([
                        'SELECT' => $select,
                        'FROM' => $prototype::getTable(),
                        'WHERE' => $where,
                        'ORDER' => [$name_field, 'id'],
                        'START' => $start,
                        'LIMIT' => $per_page,
                    ]);
                    $row_count = 0;
                    foreach ($rows as $row) {
                        $row_count++;
                        $id = (int) ($row['id'] ?? 0);
                        if ($id <= 0) {
                            continue;
                        }
                        $item = getItemForItemtype($itemtype);
                        if (!$item instanceof \CommonDBTM
                            || !$item->getFromDB($id)
                            || !$item->can($id, READ)
                            || !$item->can($id, UPDATE)
                            || (array_key_exists('is_deleted', $item->fields) && (int) $item->fields['is_deleted'] !== 0)
                            || CredentialItem::resolveParentEntityID($item) !== $entities_id
                        ) {
                            continue;
                        }
                        $item_name = trim((string) $item->getName());
                        $options[] = [
                            'value' => $itemtype . '|' . $id,
                            'label' => $itemtype::getTypeName(1) . ' — ' . ($item_name !== '' ? $item_name : '#' . $id) . ' (#' . $id . ')',
                        ];
                        $itemtype_options++;
                        if ($itemtype_options >= $limit) {
                            // Stop scanning this itemtype, not the outer type loop.
                            // A popular type must not hide every other destination type.
                            break;
                        }
                    }
                    if ($row_count < $per_page) {
                        break;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        usort($options, static fn(array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
        return array_slice($options, 0, $limit);
    }

    /**
     * Build a validated item-first lock plan for every current relationship.
     * The item rows are locked before relationship rows and the credential row,
     * matching the ordering used by link/unlink/ITEM_PURGE operations.
     *
     * @param array<int,array{itemtype:string,items_id:int}> $links
     * @return array<int,array{itemtype:string,items_id:int,entities_id:int}>|null
     */
    private static function buildExistingLinkLockPlan(array $links, int $entity_id): ?array
    {
        if ($links === [] || !Session::haveAccessToEntity($entity_id)) {
            return null;
        }

        $plan = [];
        $seen = [];
        foreach ($links as $link) {
            $itemtype = (string) ($link['itemtype'] ?? '');
            $items_id = (int) ($link['items_id'] ?? 0);
            if ($items_id <= 0 || !CredentialItem::isSupportedItemtype($itemtype)) {
                return null;
            }
            $key = $itemtype . '#' . $items_id;
            if (isset($seen[$key])) {
                continue;
            }

            $item = CredentialItem::loadReadableItem($itemtype, $items_id);
            if ($item === null
                || !$item->can($items_id, READ)
                || (array_key_exists('is_deleted', $item->fields) && (int) $item->fields['is_deleted'] !== 0)
                || CredentialItem::resolveParentEntityID($item) !== $entity_id
            ) {
                return null;
            }

            $plan[] = [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
                'entities_id' => $entity_id,
            ];
            $seen[$key] = true;
        }

        return $plan !== [] ? $plan : null;
    }

    /**
     * Compare a non-locking relationship probe with the current locked set.
     * A changed relationship set aborts the operation; the administrator can retry.
     *
     * @param array<int,array{itemtype:string,items_id:int}> $expected
     * @param array<int,array{itemtype:string,items_id:int}> $actual
     */
    private static function sameLinkSet(array $expected, array $actual): bool
    {
        $normalize = static function (array $links): array {
            $keys = [];
            foreach ($links as $link) {
                $itemtype = (string) ($link['itemtype'] ?? '');
                $items_id = (int) ($link['items_id'] ?? 0);
                if ($itemtype !== '' && $items_id > 0) {
                    $keys[$itemtype . '#' . $items_id] = true;
                }
            }
            $keys = array_keys($keys);
            sort($keys, SORT_STRING);
            return $keys;
        };

        return $normalize($expected) === $normalize($actual);
    }

    /**
     * Restore an archived credential while preserving every existing relationship.
     * All linked GLPI items must still exist, be readable and belong to the same entity.
     */
    public static function restoreWithExistingLinks(int $credential_id): bool
    {
        global $DB;

        if ($credential_id <= 0
            || !Session::haveRight(Credential::$rightname, READ)
            || !Session::haveRight(Credential::$rightname, UPDATE)
        ) {
            return false;
        }

        $probe = new Credential();
        if (!$probe->getFromDB($credential_id) || (int) ($probe->fields['is_deleted'] ?? 0) !== 1) {
            return false;
        }
        $entity_id = (int) ($probe->fields['entities_id'] ?? -1);
        if ($entity_id < 0 || !Session::haveAccessToEntity($entity_id)) {
            return false;
        }

        // Probe without locks first so native GLPI item rows can be locked before
        // the relationship set, preserving the plugin's global lock order.
        $expected_links = CredentialItemLink::getLinksForCredential($credential_id);
        $item_lock_plan = self::buildExistingLinkLockPlan($expected_links, $entity_id);
        if ($item_lock_plan === null) {
            return false;
        }

        $DB->beginTransaction();
        try {
            if (!CredentialItemLink::lockItemsForUpdate($item_lock_plan)) {
                $DB->rollBack();
                return false;
            }

            $locked_links = CredentialItemLink::getLinksForCredentialForUpdate($credential_id);
            if (!self::sameLinkSet($expected_links, $locked_links)
                || !CredentialItemLink::lockCredential($credential_id, $entity_id, false)
            ) {
                $DB->rollBack();
                return false;
            }

            $credential = CredentialItemLink::loadCredentialForUpdate($credential_id);
            if ($credential === null
                || (int) ($credential->fields['is_deleted'] ?? 0) !== 1
                || (int) ($credential->fields['entities_id'] ?? -1) !== $entity_id
                || $locked_links === []
            ) {
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
                || !CredentialHistory::recordForLinkedItems($credential_id, Credential::HISTORY_RESTORED_LINKS, $locked_links)
                || !CredentialHistory::recordForCredential($credential_id, Credential::HISTORY_RESTORED_LINKS)
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
            // Lock the GLPI item first so a concurrent ITEM_PURGE either waits
            // for this restore or prevents the relationship from being recreated.
            if (!CredentialItemLink::lockItemForUpdate($itemtype, $items_id, $entity_id)) {
                $DB->rollBack();
                return false;
            }
            $locked_links = CredentialItemLink::getLinksForCredentialForUpdate($credential_id);
            if (!CredentialItemLink::lockCredential($credential_id, $entity_id, false)) {
                $DB->rollBack();
                return false;
            }

            $credential = CredentialItemLink::loadCredentialForUpdate($credential_id);
            if ($credential === null
                || (int) ($credential->fields['is_deleted'] ?? 0) !== 1
                || (int) ($credential->fields['entities_id'] ?? -1) !== $entity_id
                || $locked_links !== []
            ) {
                $DB->rollBack();
                return false;
            }

            if (!CredentialItemLink::addLink([
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
     * Permanently remove one archived credential immediately, including its
     * relationship rows. Linked GLPI items themselves are never deleted.
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
        if (!$probe->getFromDB($credential_id)
            || (int) ($probe->fields['is_deleted'] ?? 0) !== 1
        ) {
            return false;
        }
        $entity_id = (int) ($probe->fields['entities_id'] ?? -1);
        if ($entity_id < 0 || !Session::haveAccessToEntity($entity_id)) {
            throw new \RuntimeException(__('You do not have access to this archived credential.', 'credentials'));
        }

        // Lock all current linked GLPI items first. If any link points to a missing,
        // unsupported or unreadable item, fail closed instead of deleting an
        // unvalidated relationship. The user can resolve the item/access and retry.
        $expected_links = CredentialItemLink::getLinksForCredential($credential_id);
        $item_lock_plan = $expected_links === []
            ? []
            : self::buildExistingLinkLockPlan($expected_links, $entity_id);
        if ($expected_links !== [] && $item_lock_plan === null) {
            throw new \RuntimeException(__('Some linked items cannot be validated with your current permissions, so this credential cannot be permanently deleted here.', 'credentials'));
        }

        $DB->beginTransaction();
        try {
            if ($item_lock_plan !== [] && !CredentialItemLink::lockItemsForUpdate($item_lock_plan)) {
                $DB->rollBack();
                return false;
            }

            $locked_links = CredentialItemLink::getLinksForCredentialForUpdate($credential_id);
            if (!self::sameLinkSet($expected_links, $locked_links)
                || !CredentialItemLink::lockCredential($credential_id, $entity_id, false)
            ) {
                $DB->rollBack();
                return false;
            }

            $credential = CredentialItemLink::loadCredentialForUpdate($credential_id);
            if ($credential === null
                || (int) ($credential->fields['entities_id'] ?? -1) !== $entity_id
                || (int) ($credential->fields['is_deleted'] ?? 0) !== 1
            ) {
                $DB->rollBack();
                return false;
            }

            // Audit the affected native items before removing their relationship rows.
            if (($locked_links !== []
                    && !CredentialHistory::recordForLinkedItems($credential_id, Credential::HISTORY_PURGED, $locked_links))
                || !CredentialHistory::recordForCredential($credential_id, Credential::HISTORY_PURGED)
            ) {
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
        $last_id = 0;
        $purged = 0;
        do {
            // Fetch only one bounded page. The cursor advances over inaccessible
            // entities too, so the same rows are never scanned repeatedly.
            $iterator = $DB->request([
                'SELECT' => ['id', 'entities_id'],
                'FROM' => Credential::getTable(),
                'WHERE' => array_merge(
                    [
                        'is_deleted' => 1,
                        'date_mod' => ['<', $cutoff],
                        'id' => ['>', $last_id],
                    ],
                    \getEntitiesRestrictCriteria(Credential::getTable(), 'entities_id', '', false)
                ),
                'ORDER' => ['id'],
                'LIMIT' => self::PURGE_BATCH_SIZE,
            ]);
            $candidates = [];
            $fetched = 0;
            foreach ($iterator as $row) {
                $fetched++;
                $id = (int) ($row['id'] ?? 0);
                $last_id = max($last_id, $id);
                $entity = (int) ($row['entities_id'] ?? -1);
                if ($id > 0 && $entity >= 0 && Session::haveAccessToEntity($entity)) {
                    $candidates[] = [$id, $entity];
                }
            }
            if ($candidates === []) {
                continue;
            }

            $DB->beginTransaction();
            try {
                // Current-read relationship locks are acquired for the complete
                // bounded page before parent credential locks and consistent reads.
                $ids = array_column($candidates, 0);
                $locked_links = CredentialItemLink::getLinksForCredentialsForUpdate($ids);
                $locked_ids = CredentialItemLink::lockCredentials($ids);
                $locked_credentials = CredentialItemLink::getCredentialsForUpdate($locked_ids);
                if (count($locked_credentials) !== count($locked_ids)) {
                    throw new \RuntimeException('A credential changed during retention purge.');
                }
                foreach ($candidates as [$id, $entity]) {
                    $credential = $locked_credentials[$id] ?? null;
                    if (!is_array($credential)
                        || (int) ($credential['is_deleted'] ?? 0) !== 1
                        || (int) ($credential['entities_id'] ?? -1) !== $entity
                        || (string) ($credential['date_mod'] ?? '') >= $cutoff
                    ) {
                        continue;
                    }
                    if (($locked_links[$id] ?? []) !== []) {
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
                        || $DB->delete(Credential::getTable(), [
                            'id' => $id,
                            'entities_id' => $entity,
                            'is_deleted' => 1,
                        ]) === false
                        || $DB->getAffectedRows() !== 1
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
        } while ($fetched === self::PURGE_BATCH_SIZE);

        return $purged;
    }

}
