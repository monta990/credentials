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

        return (bool) Log::history(
            $items_id,
            $itemtype,
            [0, (string) $credential_id, ''],
            Credential::class,
            Log::HISTORY_PLUGIN + $action,
        );
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

        $entityId = (int) ($credential->fields['entities_id'] ?? 0);
        // Keep the non-secret entity reference so a permanent-purge event can
        // still be filtered by entity after the credential row is removed.
        return (bool) Log::history(
            $credential_id,
            Credential::class,
            [0, $credential_id . '#' . $entityId, ''],
            Credential::class,
            Log::HISTORY_PLUGIN + $action,
        );
    }
}
