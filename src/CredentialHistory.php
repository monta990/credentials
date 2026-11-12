<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials;

use Log;

/** Writes attributable, secret-free credential events to native GLPI item history. */
final class CredentialHistory
{
    /**
     * Record an event against the related GLPI item.
     * The credential ID is stored as the non-secret change reference; no label or secret
     * value/ciphertext is sent to GLPI's history table.
     */
    public static function recordForItem(
        string $itemtype,
        int $items_id,
        int $action,
        int $credential_id,
    ): bool {
        if (
            $items_id <= 0
            || $credential_id <= 0
            || !CredentialItem::isSupportedItemtype($itemtype)
            || !Credential::isHistoryAction($action)
        ) {
            return false;
        }

        $item = getItemForItemtype($itemtype);
        if (!$item instanceof \CommonDBTM || !$item->getFromDB($items_id)) {
            return false;
        }

        $logged = (bool) Log::history(
            $items_id,
            $itemtype,
            [0, (string) $credential_id, ''],
            Credential::class,
            Log::HISTORY_PLUGIN + $action,
        );

        return $logged && self::recordAudit($credential_id, self::actionName($action), $itemtype, $items_id);
    }

    /**
     * Record a lifecycle event on the credential itself.
     * This is used when the last linked GLPI item is permanently purged, so the
     * event survives the deletion of the parent item's own history.
     */
    public static function recordForCredential(int $credential_id, int $action): bool
    {
        if ($credential_id <= 0 || !Credential::isHistoryAction($action)) {
            return false;
        }

        $credential = new Credential();
        if (!$credential->getFromDB($credential_id)) {
            return false;
        }

        $logged = (bool) Log::history(
            $credential_id,
            Credential::class,
            [0, (string) $credential_id, ''],
            Credential::class,
            Log::HISTORY_PLUGIN + $action,
        );

        return $logged && self::recordAudit($credential_id, self::actionName($action), '', 0);
    }
    /**
     * Store secret-free administrative activity in a dedicated history table.
     * Details are restricted to non-sensitive event context; callers must never
     * pass usernames, passwords, URLs, notes, ciphertext or backup passphrases.
     */
    public static function recordAudit(
        int $credential_id,
        string $action,
        string $itemtype = '',
        int $items_id = 0,
        string $details = '',
    ): bool {
        global $DB;

        if (!preg_match('/^[A-Z_]{2,40}$/', $action)) {
            return false;
        }

        return $DB->insert('glpi_plugin_credentials_audit', [
            'credential_id' => max(0, $credential_id),
            'users_id' => (int) \Session::getLoginUserID(),
            'action' => $action,
            'itemtype' => mb_substr($itemtype, 0, 100),
            'items_id' => max(0, $items_id),
            'details' => mb_substr($details, 0, 500),
            'date_creation' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
        ]) !== false;
    }

    private static function actionName(int $action): string
    {
        return match ($action) {
            Credential::HISTORY_CREATED => 'CREATED',
            Credential::HISTORY_UPDATED => 'UPDATED',
            Credential::HISTORY_LINKED => 'LINKED',
            Credential::HISTORY_UNLINKED => 'UNLINKED',
            Credential::HISTORY_DELETED => 'DELETED',
            Credential::HISTORY_REVEALED => 'REVEALED',
            Credential::HISTORY_ORPHANED => 'ORPHANED',
            Credential::HISTORY_ARCHIVED => 'ARCHIVED',
            Credential::HISTORY_IMPORTED => 'IMPORTED',
            default => 'UNKNOWN',
        };
    }
}
