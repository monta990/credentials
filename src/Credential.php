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
use Log;

/**
 * A secret entry. The password column only contains GLPIKey ciphertext.
 */
final class Credential extends CommonDBTM
{
    public static string $rightname = 'plugin_credentials';
    public const RIGHT_REVEAL = 'plugin_credentials_reveal';
    public const RIGHT_BACKUP = 'plugin_credentials_backup';

    public const HISTORY_CREATED = 1;
    public const HISTORY_UPDATED = 2;
    public const HISTORY_LINKED = 3;
    public const HISTORY_UNLINKED = 4;
    public const HISTORY_DELETED = 5;
    public const HISTORY_REVEALED = 6;
    public const HISTORY_ORPHANED = 7;
    public const HISTORY_IMPORTED = 8;
    public const HISTORY_RESTORED = 9;
    public const HISTORY_PURGED = 10;
    public const HISTORY_EXPORTED = 11;
    public const HISTORY_REVIEWED = 12;
    public const HISTORY_RESTORED_LINKS = 13;

    /** Keep change history for non-secret fields, never retain credential values in glpi_logs. */
    public bool $dohistory = true;
    public array $history_blacklist = ['name', 'login', 'password', 'url', 'notes', 'link_lock_token', 'links_count'];

    /** Never expose encrypted credential values through GLPI's generic API serializers.
     * @var string[]
     */
    public static array $undisclosedFields = ['login', 'password', 'url', 'notes'];

    /** Internal mutation depth; generic GLPI CRUD must never write secret records directly. */
    private static int $trustedWriteDepth = 0;


    /** Execute a model mutation initiated by an authorized Credentials service. */
    public static function withTrustedWrite(callable $operation): mixed
    {
        self::$trustedWriteDepth++;
        try {
            return $operation();
        } finally {
            self::$trustedWriteDepth--;
        }
    }

    /** Prevent generic mass actions from bypassing the plugin's transactional workflows. */
    #[Override]
    public function getForbiddenStandardMassiveAction()
    {
        return ['update', 'delete', 'purge'];
    }

    /** Generic model creation is disabled; controllers and import services use withTrustedWrite(). */
    #[Override]
    public function prepareInputForAdd($input)
    {
        return self::$trustedWriteDepth > 0 && is_array($input) ? $input : false;
    }

    /** Generic model updates are disabled to prevent unencrypted API writes. */
    #[Override]
    public function prepareInputForUpdate($input)
    {
        return self::$trustedWriteDepth > 0 && is_array($input) ? $input : false;
    }

    /** CommonDBTM::delete() must only be called by the plugin's audited archive flow. */
    #[Override]
    public function pre_deleteItem()
    {
        return self::$trustedWriteDepth > 0;
    }

    /** A credential can only be archived by a user who can update at least one linked item. */
    #[Override]
    public function canDeleteItem(): bool
    {
        if (!parent::canDeleteItem()) {
            return false;
        }

        $credential_id = (int) ($this->fields['id'] ?? 0);
        $credential_entity = (int) ($this->fields['entities_id'] ?? -1);
        if ($credential_id <= 0 || $credential_entity < 0 || !Session::haveAccessToEntity($credential_entity)) {
            return false;
        }

        foreach (CredentialItemLink::getLinksForCredential($credential_id) as $link) {
            $item = getItemForItemtype($link['itemtype']);
            if (!$item instanceof CommonDBTM || !$item->getFromDB($link['items_id'])) {
                continue;
            }
            if (CredentialItem::resolveParentEntityID($item) !== $credential_entity) {
                continue;
            }
            if ($item->can((int) $link['items_id'], UPDATE)) {
                return true;
            }
        }

        return false;
    }

    /** Permanent deletion is only available through CredentialAdminService, which enforces retention and history. */
    #[Override]
    public function canPurgeItem(): bool
    {
        return false;
    }

    #[Override]
    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_credentials_credentials';
    }

    #[Override]
    public static function getTypeName($nb = 0): string
    {
        return _n('Credential', 'Credentials', $nb, 'credentials');
    }

    public static function getIcon(): string
    {
        return 'ti ti-key';
    }

    /**
     * Credentials are a sensitive object type in GLPI 12.
     * Core right checks therefore participate in the native re-authentication flow.
     */
    #[Override]
    protected static function itemTypeRequiresReauthentication(): bool
    {
        return true;
    }

    /**
     * Return only non-secret labels for an item's inline tab.
     * No encrypted columns are selected or decrypted here; detailed data remains behind
     * GLPI's native re-authentication page.
     *
     * @return array<int, array{id:int,name:string,date_mod:?string,link_count:int}>
     */
    public static function getLabelsForItem(string $itemtype, int $items_id, int $entities_id): array
    {
        global $DB;

        if (!Session::haveRight(self::$rightname, READ) || !Session::haveAccessToEntity($entities_id)) {
            return [];
        }

        $ids = self::getActiveIDsForItem($itemtype, $items_id, $entities_id);
        if ($ids === []) {
            return [];
        }

        $rows = [];
        $link_counts = CredentialItemLink::getLinkCounts($ids);
        $iterator = $DB->request([
            'SELECT' => ['id', 'name', 'plugin_credentials_categories_id', 'date_mod'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'id'          => array_values(array_unique($ids)),
                'entities_id' => $entities_id,
                'is_deleted'  => 0,
            ],
            'ORDER' => ['name'],
        ]);

        foreach ($iterator as $row) {
            $rows[] = [
                'id'       => (int) $row['id'],
                'name'       => (string) $row['name'],
                'category_id' => (int) ($row['plugin_credentials_categories_id'] ?? 0),
                'date_mod'    => $row['date_mod'] !== null ? (string) $row['date_mod'] : null,
                'link_count' => (int) ($link_counts[(int) $row['id']] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Return active credential IDs linked to an item, scoped to an accessible entity.
     * This deliberately selects no credential values or encrypted columns and can be
     * used for tab counters before the protected detail page is opened.
     *
     * @return array<int, int>
     */
    public static function getActiveIDsForItem(string $itemtype, int $items_id, int $entities_id): array
    {
        global $DB;

        if (!Session::haveRight(self::$rightname, READ)
            || !CredentialItem::isSupportedItemtype($itemtype)
            || $items_id <= 0
            || $entities_id < 0
            || !Session::haveAccessToEntity($entities_id)
        ) {
            return [];
        }

        $linked_ids = [];
        $links = $DB->request([
            'SELECT' => ['plugin_credentials_credentials_id'],
            'FROM'   => 'glpi_plugin_credentials_items',
            'WHERE'  => [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
            ],
        ]);
        foreach ($links as $link) {
            $linked_ids[] = (int) $link['plugin_credentials_credentials_id'];
        }
        $linked_ids = array_values(array_unique(array_filter($linked_ids, static fn(int $id): bool => $id > 0)));
        if ($linked_ids === []) {
            return [];
        }

        $active_ids = [];
        $active = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'id' => $linked_ids,
                'entities_id' => $entities_id,
                'is_deleted' => 0,
            ],
        ]);
        foreach ($active as $row) {
            $active_ids[] = (int) $row['id'];
        }

        return $active_ids;
    }

    /** Count active credentials without reading their names or protected values. */
    public static function getCountForItem(string $itemtype, int $items_id, int $entities_id): int
    {
        return count(self::getActiveIDsForItem($itemtype, $items_id, $entities_id));
    }

    /**
     * Translate plugin events written through GLPI's native Log::history() mechanism.
     * The history record deliberately contains no credential name or field values.
     *
     * @param array<string, mixed> $data
     */
    public static function getHistoryEntry(array $data): string
    {
        return match ((int) ($data['linked_action'] ?? 0) - Log::HISTORY_PLUGIN) {
            self::HISTORY_CREATED  => __s('A protected credential was created.', 'credentials'),
            self::HISTORY_UPDATED  => __s('A protected credential was updated.', 'credentials'),
            self::HISTORY_LINKED   => __s('A protected credential was linked.', 'credentials'),
            self::HISTORY_UNLINKED => __s('A protected credential was unlinked.', 'credentials'),
            self::HISTORY_DELETED  => __s('A protected credential was deleted.', 'credentials'),
            self::HISTORY_REVEALED => __s('A protected credential password was revealed.', 'credentials'),
            self::HISTORY_ORPHANED => __s('A protected credential is no longer linked to any item.', 'credentials'),
            self::HISTORY_IMPORTED => __s('A protected credential was imported from a backup.', 'credentials'),
            self::HISTORY_RESTORED => __s('A protected credential was restored and linked to an item.', 'credentials'),
            self::HISTORY_PURGED => __s('An archived protected credential was permanently deleted.', 'credentials'),
            self::HISTORY_EXPORTED => __s('A protected credential was exported to an encrypted backup.', 'credentials'),
            self::HISTORY_REVIEWED => __s('A protected credential review was recorded.', 'credentials'),
            self::HISTORY_RESTORED_LINKS => __s('An archived protected credential was restored with its existing item links.', 'credentials'),
            default                => '',
        };
    }

    public static function isHistoryAction(int $action): bool
    {
        return in_array($action, [
            self::HISTORY_CREATED,
            self::HISTORY_UPDATED,
            self::HISTORY_LINKED,
            self::HISTORY_UNLINKED,
            self::HISTORY_DELETED,
            self::HISTORY_REVEALED,
            self::HISTORY_ORPHANED,
            self::HISTORY_IMPORTED,
            self::HISTORY_RESTORED,
            self::HISTORY_PURGED,
            self::HISTORY_EXPORTED,
            self::HISTORY_REVIEWED,
            self::HISTORY_RESTORED_LINKS,
        ], true);
    }

    public static function isEncryptionReady(): bool
    {
        $key_manager = new \GLPIKey();
        return !$key_manager->hasReadErrors() && $key_manager->get() !== null;
    }

    /** Encrypt a credential value with GLPI's instance key. Empty optional values stay empty. */
    public static function encryptValue(string $plaintext): ?string
    {
        if ($plaintext === '') {
            return '';
        }
        $key_manager = new \GLPIKey();
        if ($key_manager->hasReadErrors()) {
            return null;
        }
        $key = $key_manager->get();
        if ($key === null) {
            return null;
        }

        $ciphertext = $key_manager->encrypt($plaintext, $key);
        return $ciphertext !== '' ? $ciphertext : null;
    }

    /** Decrypt a stored credential value after authorization and native re-authentication. */
    public static function decryptValue(string $ciphertext): ?string
    {
        if ($ciphertext === '') {
            return '';
        }
        $key_manager = new \GLPIKey();
        if ($key_manager->hasReadErrors()) {
            return null;
        }
        $key = $key_manager->get();
        if ($key === null) {
            return null;
        }

        // Supplying the already-read key prevents GLPIKey::decrypt() from returning
        // the original ciphertext if the key file disappears between two reads.
        return $key_manager->decrypt($ciphertext, $key);
    }

    public static function encryptPassword(string $plaintext): ?string
    {
        return self::encryptValue($plaintext);
    }

    public static function decryptPassword(string $ciphertext): ?string
    {
        return self::decryptValue($ciphertext);
    }

}
