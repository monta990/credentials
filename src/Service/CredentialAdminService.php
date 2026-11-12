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
use Log;
use Session;

/** Non-secret administrative summaries, audit, archive retention and recovery. */
final class CredentialAdminService
{
    /** @return array<string,mixed> */
    public static function dashboard(int $entities_id, int $retention_days): array
    {
        global $DB;

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
                if (count($archived) < 25) {
                    $archived[] = [
                        'id' => $id,
                        'name' => (string) $row['name'],
                        'date_mod' => $modified,
                        'entities_id' => $entity,
                        'purge_eligible' => $eligible,
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
            if ($status['key'] !== 'current' && count($attention) < 25) {
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
            'attention' => $attention,
            'archived' => $archived,
            'purge_eligible' => $purge_eligible,
            'categories' => $category_rows,
            'audit' => self::recentAuditEvents(50),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public static function recentAuditEvents(int $limit = 50): array
    {
        global $DB;

        $events = [];
        $iterator = $DB->request([
            'SELECT' => [
                'date_mod', 'user_name', 'itemtype', 'items_id',
                'linked_action', 'old_value', 'new_value',
            ],
            'FROM' => 'glpi_logs',
            'WHERE' => [
                'itemtype_link' => Credential::class,
                'linked_action' => ['>=', Log::HISTORY_PLUGIN],
            ],
            'ORDER' => ['date_mod DESC'],
            'LIMIT' => max(1, min(100, $limit)),
        ]);

        foreach ($iterator as $row) {
            $linked_action = (int) $row['linked_action'];
            $event_label = Credential::getHistoryEntry(['linked_action' => $linked_action]);
            if ($event_label === '') {
                continue;
            }
            $itemtype = (string) ($row['itemtype'] ?? '');
            $items_id = (int) ($row['items_id'] ?? 0);

            // Show only events whose credential or originating GLPI item is
            // currently readable in the active entity scope. This prevents the
            // audit panel from revealing IDs or activity from inaccessible entities.
            if ($itemtype === Credential::class) {
                $credential = new Credential();
                if (!$credential->getFromDB($items_id)
                    || !Session::haveAccessToEntity((int) ($credential->fields['entities_id'] ?? -1))
                ) {
                    continue;
                }
                $credential_id = $items_id;
                $item_name = (string) ($credential->fields['name'] ?? __('Credential record', 'credentials'));
            } else {
                if (!CredentialItem::isSupportedItemtype($itemtype)) {
                    continue;
                }
                $item = CredentialItem::loadReadableItem($itemtype, $items_id);
                if ($item === null || !Session::haveAccessToEntity(CredentialItem::resolveParentEntityID($item))) {
                    continue;
                }
                $credential_id = (int) trim((string) ($row['new_value'] ?? ''), "# \\t\\n\\r\\0\\x0B");
                $item_name = (string) $item->getName();
            }

            $events[] = [
                'date' => (string) ($row['date_mod'] ?? ''),
                'user' => (string) ($row['user_name'] ?? ''),
                'event' => $event_label,
                'credential_id' => max(0, $credential_id),
                'item' => $item_name,
            ];
        }

        return $events;
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

    /** Permanently remove only archived, unlinked credentials older than the configured age. */
    public static function purgeEligible(int $retention_days): int
    {
        global $DB;

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

        $DB->beginTransaction();
        $purged = 0;
        try {
            foreach ($candidates as [$id, $entity]) {
                if (!CredentialItemLink::lockCredential($id, $entity, false)) {
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
                ]) === false || $DB->delete(Credential::getTable(), ['id' => $id, 'is_deleted' => 1]) === false) {
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
