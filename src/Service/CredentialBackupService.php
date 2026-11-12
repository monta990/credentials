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
use Session;

/**
 * Password-protected export/import. Plaintext exists only in process memory and is
 * encrypted before it leaves the server. Imports always re-encrypt values with the
 * destination GLPI instance key.
 */
final class CredentialBackupService
{
    private const FORMAT = 'glpi-credentials-backup';
    private const FORMAT_VERSION = 1;
    private const ASSOCIATED_DATA = 'GLPI Credentials backup v1';
    private const MAX_FILE_BYTES = 20_000_000;
    private const MAX_ENTRIES = 2000;
    private const MAX_LINKS_PER_ENTRY = 250;

    public static function isAvailable(): bool
    {
        return function_exists('sodium_crypto_pwhash')
            && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
            && Credential::isEncryptionReady();
    }

    /** Create an authenticated, password-protected export file. */
    public static function createBackup(string $passphrase, bool $include_archived = true): string
    {
        self::validatePassphrase($passphrase);
        if (!self::isAvailable()) {
            throw new \RuntimeException(__('Secure backup is unavailable. Check the PHP Sodium extension and GLPI encryption key.', 'credentials'));
        }
        if (!Session::haveRight(Credential::$rightname, READ)) {
            throw new \RuntimeException(__('You do not have permission to read credentials for backup.', 'credentials'));
        }

        global $DB;
        $where = [];
        if (!$include_archived) {
            $where['is_deleted'] = 0;
        }
        $iterator = $DB->request([
            'SELECT' => [
                'id', 'name', 'entities_id', 'login', 'password', 'url', 'notes',
                'users_id', 'date_creation', 'date_mod', 'is_deleted',
                'plugin_credentials_categories_id', 'date_expires', 'date_last_review',
                'date_next_review', 'reviewer_users_id',
            ],
            'FROM' => Credential::getTable(),
            'WHERE' => $where,
            'ORDER' => ['entities_id', 'id'],
        ]);

        $entries = [];
        foreach ($iterator as $row) {
            $entities_id = (int) $row['entities_id'];
            if (!Session::haveAccessToEntity($entities_id)) {
                continue;
            }

            $credential_id = (int) $row['id'];
            $secrets = [];
            foreach (['login', 'password', 'url', 'notes'] as $field) {
                $ciphertext = (string) ($row[$field] ?? '');
                $plaintext = Credential::decryptValue($ciphertext);
                if ($plaintext === null || ($ciphertext !== '' && $plaintext === '')) {
                    throw new \RuntimeException(__('A credential could not be decrypted. No backup file was created.', 'credentials'));
                }
                $secrets[$field] = $plaintext;
            }

            $links = [];
            foreach (CredentialItemLink::getLinksForCredential($credential_id) as $link) {
                $itemtype = (string) $link['itemtype'];
                $items_id = (int) $link['items_id'];
                if (!CredentialItem::isSupportedItemtype($itemtype)) {
                    continue;
                }
                $item = CredentialItem::loadReadableItem($itemtype, $items_id);
                if ($item === null || CredentialItem::resolveParentEntityID($item) !== $entities_id) {
                    continue;
                }
                $links[] = [
                    'itemtype' => $itemtype,
                    'items_id' => $items_id,
                    'item_name' => mb_substr((string) $item->getName(), 0, 255),
                ];
                if (count($links) >= self::MAX_LINKS_PER_ENTRY) {
                    break;
                }
            }

            $archived = (int) ($row['is_deleted'] ?? 0) === 1;
            // Active records must retain at least one valid association in a backup.
            if (!$archived && $links === []) {
                continue;
            }

            $category = CredentialCategory::getById((int) ($row['plugin_credentials_categories_id'] ?? 0));
            $entries[] = [
                'source_id' => $credential_id,
                'name' => (string) $row['name'],
                'entity' => self::entityPath($entities_id),
                'category' => $category['name'] ?? '',
                'login' => $secrets['login'],
                'password' => $secrets['password'],
                'url' => $secrets['url'],
                'notes' => $secrets['notes'],
                'date_expires' => self::validDateOnly((string) ($row['date_expires'] ?? '')),
                'date_last_review' => self::validDateOnly((string) ($row['date_last_review'] ?? '')),
                'date_next_review' => self::validDateOnly((string) ($row['date_next_review'] ?? '')),
                'reviewer' => self::userLogin((int) ($row['reviewer_users_id'] ?? 0)),
                'date_creation' => (string) ($row['date_creation'] ?? ''),
                'date_mod' => (string) ($row['date_mod'] ?? ''),
                'archived' => $archived,
                'links' => $links,
            ];

            if (count($entries) > self::MAX_ENTRIES) {
                throw new \RuntimeException(__('The backup contains too many credentials. Export smaller groups of records.', 'credentials'));
            }
        }

        $payload = json_encode([
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'plugin_version' => defined('PLUGIN_CREDENTIALS_VERSION') ? PLUGIN_CREDENTIALS_VERSION : '',
            'exported_at' => gmdate(DATE_ATOM),
            'entries' => $entries,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($payload) > self::MAX_FILE_BYTES) {
            throw new \\RuntimeException(__('The encrypted backup would exceed the 20 MB download limit. Export fewer credentials at a time.', 'credentials'));
        }

        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $passphrase,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );

        try {
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $payload,
                self::ASSOCIATED_DATA,
                $nonce,
                $key
            );
        } finally {
            sodium_memzero($key);
        }

        $envelope = json_encode([
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'cipher' => 'xchacha20poly1305-ietf',
            'kdf' => 'argon2id13',
            'opslimit' => SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            'memlimit' => SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            'salt' => base64_encode($salt),
            'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($ciphertext),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($envelope) > self::MAX_FILE_BYTES) {
            throw new \\RuntimeException(__('The encrypted backup would exceed the 20 MB download limit. Export fewer credentials at a time.', 'credentials'));
        }
        return $envelope;
    }

    /**
     * Import a backup. Existing records are never overwritten: each valid entry is
     * created with a new ID and linked only to items that match the exported type,
     * ID and name in the destination instance.
     *
     * @return array{imported:int,skipped:int,errors:array<int,string>}
     */
    public static function importBackup(string $contents, string $passphrase): array
    {
        self::validatePassphrase($passphrase);
        if (!self::isAvailable()) {
            throw new \RuntimeException(__('Secure backup is unavailable. Check the PHP Sodium extension and GLPI encryption key.', 'credentials'));
        }
        if (!Session::haveRight(Credential::$rightname, READ)
            || !Session::haveRight(Credential::$rightname, CREATE)
        ) {
            throw new \RuntimeException(__('You need read and create permissions for Credentials to import a backup.', 'credentials'));
        }
        if (strlen($contents) <= 0 || strlen($contents) > self::MAX_FILE_BYTES) {
            throw new \RuntimeException(__('The selected backup file is empty or exceeds the 20 MB limit.', 'credentials'));
        }

        $envelope = json_decode($contents, true);
        if (!is_array($envelope)
            || ($envelope['format'] ?? '') !== self::FORMAT
            || (int) ($envelope['format_version'] ?? 0) !== self::FORMAT_VERSION
            || ($envelope['cipher'] ?? '') !== 'xchacha20poly1305-ietf'
            || ($envelope['kdf'] ?? '') !== 'argon2id13'
            || (int) ($envelope['opslimit'] ?? 0) !== SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE
            || (int) ($envelope['memlimit'] ?? 0) !== SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
        ) {
            throw new \RuntimeException(__('The selected file is not a supported Credentials backup.', 'credentials'));
        }

        $salt = base64_decode((string) ($envelope['salt'] ?? ''), true);
        $nonce = base64_decode((string) ($envelope['nonce'] ?? ''), true);
        $ciphertext = base64_decode((string) ($envelope['ciphertext'] ?? ''), true);
        if (!is_string($salt) || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES
            || !is_string($nonce) || strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
            || !is_string($ciphertext) || strlen($ciphertext) < SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES
        ) {
            throw new \RuntimeException(__('The backup file is damaged or incomplete.', 'credentials'));
        }

        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $passphrase,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $ciphertext,
                self::ASSOCIATED_DATA,
                $nonce,
                $key
            );
        } finally {
            sodium_memzero($key);
        }

        if (!is_string($plaintext)) {
            throw new \RuntimeException(__('The backup password is incorrect or the file has been modified.', 'credentials'));
        }

        $payload = json_decode($plaintext, true);
        sodium_memzero($plaintext);
        if (!is_array($payload)
            || ($payload['format'] ?? '') !== self::FORMAT
            || (int) ($payload['format_version'] ?? 0) !== self::FORMAT_VERSION
            || !is_array($payload['entries'] ?? null)
            || count($payload['entries']) > self::MAX_ENTRIES
        ) {
            throw new \RuntimeException(__('The decrypted backup content is not valid.', 'credentials'));
        }

        $prepared = [];
        $errors = [];
        $skipped = 0;
        foreach ($payload['entries'] as $index => $entry) {
            $candidate = self::prepareEntry($entry);
            if ($candidate === null) {
                $skipped++;
                $errors[] = sprintf(__('Entry %d was skipped because its data or linked items could not be matched.', 'credentials'), $index + 1);
                continue;
            }
            $prepared[] = $candidate;
        }

        if ($prepared === []) {
            return ['imported' => 0, 'skipped' => $skipped, 'errors' => $errors];
        }

        global $DB;
        $DB->beginTransaction();
        $imported = 0;
        try {
            foreach ($prepared as $entry) {
                $category_id = $entry['category'] !== ''
                    ? CredentialCategory::findOrCreateByName($entry['category'], $entry['entities_id'])
                    : 0;

                $encrypted = [];
                foreach (['login', 'password', 'url', 'notes'] as $field) {
                    $encrypted[$field] = $entry[$field] === '' && $field !== 'password'
                        ? null
                        : Credential::encryptValue($entry[$field]);
                    if ($encrypted[$field] === null && ($field === 'password' || $entry[$field] !== '')) {
                        throw new \RuntimeException(__('An imported credential could not be encrypted with the GLPI security key. The import was cancelled.', 'credentials'));
                    }
                }

                $credential = new Credential();
                $new_id = (int) $credential->add([
                    'name' => $entry['name'],
                    'entities_id' => $entry['entities_id'],
                    'plugin_credentials_categories_id' => $category_id,
                    'login' => $encrypted['login'],
                    'password' => $encrypted['password'],
                    'url' => $encrypted['url'],
                    'notes' => $encrypted['notes'],
                    'date_expires' => $entry['date_expires'] ?: null,
                    'date_last_review' => $entry['date_last_review'] ?: null,
                    'date_next_review' => $entry['date_next_review'] ?: null,
                    'reviewer_users_id' => $entry['reviewer_users_id'],
                    'users_id' => (int) Session::getLoginUserID(),
                    'date_creation' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
                ]);
                if ($new_id <= 0) {
                    throw new \RuntimeException(__('A credential could not be created. The import was cancelled.', 'credentials'));
                }

                if ($entry['archived']) {
                    if ($DB->update(Credential::getTable(), [
                        'is_deleted' => 1,
                        'date_mod' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
                    ], ['id' => $new_id]) === false) {
                        throw new \RuntimeException(__('An archived credential could not be restored from the backup. The import was cancelled.', 'credentials'));
                    }
                    if (!CredentialHistory::recordForCredential($new_id, Credential::HISTORY_IMPORTED)) {
                        throw new \RuntimeException(__('The import could not be written to GLPI history. The import was cancelled.', 'credentials'));
                    }
                    $imported++;
                    continue;
                }

                foreach ($entry['links'] as $link_data) {
                    $link = new CredentialItemLink();
                    $created = (bool) $link->add([
                        'plugin_credentials_credentials_id' => $new_id,
                        'itemtype' => $link_data['itemtype'],
                        'items_id' => $link_data['items_id'],
                        'date_creation' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
                    ]);
                    if (!$created || !CredentialHistory::recordForItem(
                        $link_data['itemtype'],
                        $link_data['items_id'],
                        Credential::HISTORY_IMPORTED,
                        $new_id
                    )) {
                        throw new \RuntimeException(__('The import could not link or audit a credential. The import was cancelled.', 'credentials'));
                    }
                }

                if (!CredentialHistory::recordForCredential($new_id, Credential::HISTORY_IMPORTED)) {
                    throw new \RuntimeException(__('The import could not be written to GLPI history. The import was cancelled.', 'credentials'));
                }
                $imported++;
            }
            $DB->commit();
        } catch (\Throwable $exception) {
            $DB->rollBack();
            throw $exception;
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 20)];
    }

    private static function validatePassphrase(string $passphrase): void
    {
        if (mb_strlen($passphrase) < 12 || strlen($passphrase) > 1024) {
            throw new \RuntimeException(__('Use a backup password with at least 12 characters.', 'credentials'));
        }
    }

    private static function entityPath(int $entities_id): string
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['completename'],
            'FROM' => 'glpi_entities',
            'WHERE' => ['id' => $entities_id],
            'LIMIT' => 1,
        ])->current();
        return is_array($row) ? (string) $row['completename'] : '';
    }

    private static function userLogin(int $users_id): string
    {
        if ($users_id <= 0) {
            return '';
        }
        $user = new \User();
        return $user->getFromDB($users_id) ? (string) ($user->fields['name'] ?? '') : '';
    }

    private static function validDateOnly(string $date): ?string
    {
        $date = trim($date);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
    }

    /** @return array<string,mixed>|null */
    private static function prepareEntry(mixed $entry): ?array
    {
        global $DB;

        if (!is_array($entry)
            || !is_string($entry['name'] ?? null)
            || trim($entry['name']) === ''
            || mb_strlen($entry['name']) > 255
            || !is_string($entry['password'] ?? null)
            || $entry['password'] === ''
            || strlen($entry['password']) > 48000
            || !is_array($entry['links'] ?? null)
            || count($entry['links']) > self::MAX_LINKS_PER_ENTRY
        ) {
            return null;
        }

        $entity_path = trim((string) ($entry['entity'] ?? ''));
        if ($entity_path === '') {
            return null;
        }
        $entity_row = $DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_entities',
            'WHERE' => ['completename' => $entity_path],
            'LIMIT' => 1,
        ])->current();
        if (!is_array($entity_row)) {
            return null;
        }
        $entities_id = (int) $entity_row['id'];
        if (!Session::haveAccessToEntity($entities_id)) {
            return null;
        }

        $links = [];
        if (empty($entry['archived'])) {
            foreach ($entry['links'] as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $itemtype = (string) ($candidate['itemtype'] ?? '');
                $items_id = (int) ($candidate['items_id'] ?? 0);
                if (!CredentialItem::isSupportedItemtype($itemtype) || $items_id <= 0) {
                    continue;
                }
                $item = getItemForItemtype($itemtype);
                if (!$item instanceof \CommonDBTM
                    || !$item->getFromDB($items_id)
                    || !$item->can($items_id, UPDATE)
                    || CredentialItem::resolveParentEntityID($item) !== $entities_id
                    || trim((string) $item->getName()) !== trim((string) ($candidate['item_name'] ?? ''))
                ) {
                    continue;
                }
                $links[] = [
                    'itemtype' => $itemtype,
                    'items_id' => $items_id,
                ];
            }
            if ($links === []) {
                return null;
            }
        }

        $reviewer_id = 0;
        $reviewer_login = trim((string) ($entry['reviewer'] ?? ''));
        if ($reviewer_login !== '') {
            $reviewer = $DB->request([
                'SELECT' => ['id'],
                'FROM' => 'glpi_users',
                'WHERE' => ['name' => $reviewer_login, 'is_deleted' => 0],
                'LIMIT' => 1,
            ])->current();
            if (is_array($reviewer)) {
                $reviewer_id = (int) $reviewer['id'];
            }
        }

        $values = [];
        foreach (['login', 'url', 'notes'] as $field) {
            $value = (string) ($entry[$field] ?? '');
            $max = $field === 'url' ? 2048 : ($field === 'notes' ? 100000 : 255);
            if (mb_strlen($value) > $max) {
                return null;
            }
            $values[$field] = $value;
        }
        if ($values['url'] !== '') {
            $parts = parse_url($values['url']);
            if (!filter_var($values['url'], FILTER_VALIDATE_URL)
                || !is_array($parts)
                || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
                || isset($parts['user'])
                || isset($parts['pass'])
            ) {
                return null;
            }
        }
        $dates = [];
        foreach (['date_expires', 'date_last_review', 'date_next_review'] as $field) {
            $value = (string) ($entry[$field] ?? '');
            $dates[$field] = $value === '' ? null : self::validDateOnly($value);
            if ($value !== '' && $dates[$field] === null) {
                return null;
            }
        }

        return [
            'name' => trim($entry['name']),
            'entities_id' => $entities_id,
            'category' => mb_substr(trim((string) ($entry['category'] ?? '')), 0, 255),
            'login' => $values['login'],
            'password' => $entry['password'],
            'url' => $values['url'],
            'notes' => $values['notes'],
            'date_expires' => $dates['date_expires'],
            'date_last_review' => $dates['date_last_review'],
            'date_next_review' => $dates['date_next_review'],
            'reviewer_users_id' => $reviewer_id,
            'date_creation' => mb_substr((string) ($entry['date_creation'] ?? ''), 0, 19),
            'date_mod' => mb_substr((string) ($entry['date_mod'] ?? ''), 0, 19),
            'archived' => !empty($entry['archived']),
            'links' => $links,
        ];
    }
}
