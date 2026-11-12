<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Credentials\AdminSettings;
use GlpiPlugin\Credentials\BackupCrypto;
use GlpiPlugin\Credentials\Credential;
use GlpiPlugin\Credentials\CredentialHistory;
use GlpiPlugin\Credentials\CredentialItem;
use GlpiPlugin\Credentials\CredentialItemLink;
use GlpiPlugin\Credentials\Paths;
use GlpiPlugin\Credentials\Security;
use GlpiPlugin\Credentials\Service\UpdateChecker;
use Html;
use Session;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use User;

/** Administrative dashboard, retention, review and encrypted backup workflows. */
final class AdminController extends AbstractController
{
    #[Route('/config', name: 'credentials_config', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        Session::checkRight('config', READ);
        $force_check = false;

        if ($request->isMethod('POST')) {
            Session::checkRight('config', UPDATE);
            Security::requireReauthentication();

            $action = $request->request->getString('action');
            if ($action === 'check_updates') {
                $force_check = true;
                Session::addMessageAfterRedirect(__('GitHub update check requested.', 'credentials'), false, INFO);
            } elseif ($action === 'save_settings') {
                $this->saveSettings($request);
            } elseif ($action === 'mark_reviewed') {
                $this->markReviewed($request->request->getInt('credential_id'));
            } elseif ($action === 'restore') {
                $this->restoreArchived(
                    $request->request->getInt('credential_id'),
                    $request->request->getString('itemtype'),
                    $request->request->getInt('items_id'),
                );
            } elseif ($action === 'erase') {
                $this->eraseArchived($request->request->getInt('credential_id'));
            } elseif ($action === 'purge_eligible') {
                $this->purgeEligible();
            }

            if (!$force_check) {
                return $this->redirectToConfig();
            }
        }

        $update = UpdateChecker::check($force_check);
        $installed = PLUGIN_CREDENTIALS_VERSION;
        $latest = $update['latest'];
        $update['is_newer'] = $latest !== null && version_compare($latest, $installed, '>');
        $update['is_current'] = $latest !== null && version_compare($latest, $installed, '<=');

        $data = $this->loadDashboardData();
        $supported_types = [];
        foreach (CredentialItem::getSupportedItemtypes() as $itemtype) {
            $supported_types[] = [
                'value' => $itemtype,
                'label' => $itemtype::getTypeName(1),
            ];
        }

        return $this->renderPage($request, [
            'installed_version' => $installed,
            'update' => $update,
            'categories_text' => implode("\n", AdminSettings::categories()),
            'retention_days' => AdminSettings::retentionDays(),
            'stats' => $data['stats'],
            'review_rows' => $data['review_rows'],
            'archive_rows' => $data['archive_rows'],
            'audit_rows' => $data['audit_rows'],
            'supported_types' => $supported_types,
            'encryption_ready' => Credential::isEncryptionReady(),
            'can_export' => Session::haveRight(Credential::RIGHT_REVEAL, READ)
                && Session::haveRight(Credential::$rightname, READ),
            'can_import' => Session::haveRight(Credential::RIGHT_REVEAL, READ)
                && Session::haveRight(Credential::$rightname, CREATE),
        ]);
    }

    #[Route('/config/export', name: 'credentials_config_export', methods: ['POST'])]
    public function export(Request $request): Response
    {
        Session::checkRight('config', UPDATE);
        Session::checkRight(Credential::$rightname, READ);
        Session::checkRight(Credential::RIGHT_REVEAL, READ);
        Security::requireReauthentication();

        $passphrase = $request->request->getString('backup_password');
        try {
            $payload = $this->buildBackupPayload();
            $plain = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($plain)) {
                throw new \RuntimeException('Unable to prepare the backup data.');
            }

            $envelope = BackupCrypto::encrypt($plain, $passphrase);
            unset($plain, $payload);
            $json = json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                throw new \RuntimeException('Unable to create the encrypted backup file.');
            }

            CredentialHistory::recordAudit(0, 'BACKUP_EXPORTED', '', 0);
            $response = new Response($json, Response::HTTP_OK, [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="credentials-backup-' . date('Y-m-d_H-i-s') . '.json"',
            ]);
            $this->setNoStore($response);
            return $response;
        } catch (\Throwable $exception) {
            Session::addMessageAfterRedirect($exception->getMessage(), false, ERROR);
            return $this->redirectToConfig();
        }
    }

    #[Route('/config/import', name: 'credentials_config_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        Session::checkRight('config', UPDATE);
        Session::checkRight(Credential::$rightname, CREATE);
        Session::checkRight(Credential::RIGHT_REVEAL, READ);
        Security::requireReauthentication();

        $file = $request->files->get('backup_file');
        $passphrase = $request->request->getString('backup_password');
        try {
            if (!$file || !$file->isValid() || $file->getSize() <= 0 || $file->getSize() > 20971520) {
                throw new \InvalidArgumentException(__('Select a valid backup file no larger than 20 MB.', 'credentials'));
            }
            $raw = file_get_contents($file->getPathname());
            $envelope = $raw === false ? null : json_decode($raw, true);
            if (!is_array($envelope)) {
                throw new \InvalidArgumentException(__('The selected backup file is not valid JSON.', 'credentials'));
            }

            $plain = BackupCrypto::decrypt($envelope, $passphrase);
            $payload = json_decode($plain, true);
            unset($plain);
            if (
                !is_array($payload)
                || ($payload['format'] ?? '') !== 'credentials-backup'
                || (int) ($payload['format_version'] ?? 0) !== 1
                || !is_array($payload['records'] ?? null)
                || count($payload['records']) > 2000
            ) {
                throw new \InvalidArgumentException(__('The selected file is not a supported Credentials backup.', 'credentials'));
            }

            $counts = $this->importRecords($payload['records']);
            CredentialHistory::recordAudit(0, 'BACKUP_IMPORTED', '', 0, json_encode($counts));
            Session::addMessageAfterRedirect(
                sprintf(
                    __('Import completed: %1$d added, %2$d skipped, %3$d archived because no matching item was found.', 'credentials'),
                    $counts['added'],
                    $counts['skipped'],
                    $counts['archived'],
                ),
                false,
                INFO,
            );
        } catch (\Throwable $exception) {
            Session::addMessageAfterRedirect($exception->getMessage(), false, ERROR);
        }

        return $this->redirectToConfig();
    }

    /** @return array<string, mixed> */
    private function buildBackupPayload(): array
    {
        global $DB;

        if (!Credential::isEncryptionReady()) {
            throw new \RuntimeException(__('The GLPI security key is unavailable. Backup export was cancelled.', 'credentials'));
        }

        $records = [];
        $iterator = $DB->request([
            'SELECT' => [
                'id', 'name', 'entities_id', 'login', 'password', 'url', 'notes', 'users_id',
                'date_creation', 'date_mod', 'is_deleted', 'category', 'expires_at',
                'review_interval_days', 'last_reviewed_at', 'last_reviewed_by',
                'archived_at', 'archived_by', 'archive_reason', 'backup_uuid',
            ],
            'FROM' => Credential::getTable(),
            'ORDER' => ['id'],
        ]);

        foreach ($iterator as $row) {
            $entity_id = (int) $row['entities_id'];
            if (!Session::haveAccessToEntity($entity_id)) {
                continue;
            }

            $credential_id = (int) $row['id'];
            $backup_uuid = (string) ($row['backup_uuid'] ?? '');
            if ($backup_uuid === '') {
                $backup_uuid = bin2hex(random_bytes(16));
                $DB->update(Credential::getTable(), ['backup_uuid' => $backup_uuid], ['id' => $credential_id]);
            }

            $secrets = [];
            foreach (['login', 'password', 'url', 'notes'] as $field) {
                $ciphertext = (string) ($row[$field] ?? '');
                $value = Credential::decryptValue($ciphertext);
                if ($value === null || ($ciphertext !== '' && $value === '')) {
                    throw new \RuntimeException(sprintf(
                        __('Credential #%d could not be decrypted. Export was cancelled to avoid an incomplete backup.', 'credentials'),
                        $credential_id,
                    ));
                }
                $secrets[$field] = $value;
            }

            $links = [];
            foreach (CredentialItemLink::getLinksForCredential($credential_id) as $link) {
                $item = getItemForItemtype($link['itemtype']);
                if (!$item instanceof \CommonDBTM || !$item->getFromDB($link['items_id'])) {
                    continue;
                }
                $links[] = [
                    'itemtype' => $link['itemtype'],
                    'items_id' => $link['items_id'],
                    'name' => (string) $item->getName(),
                    'entities_id' => CredentialItem::resolveParentEntityID($item),
                ];
            }

            $records[] = [
                'backup_uuid' => $backup_uuid,
                'name' => (string) $row['name'],
                'entities_id' => $entity_id,
                'secrets' => $secrets,
                'users_id' => (int) $row['users_id'],
                'date_creation' => $row['date_creation'],
                'category' => (string) ($row['category'] ?? 'General'),
                'expires_at' => $row['expires_at'],
                'review_interval_days' => (int) ($row['review_interval_days'] ?? 90),
                'last_reviewed_at' => $row['last_reviewed_at'],
                'last_reviewed_by' => (int) ($row['last_reviewed_by'] ?? 0),
                'is_deleted' => (int) $row['is_deleted'] === 1,
                'archived_at' => $row['archived_at'],
                'archive_reason' => (string) ($row['archive_reason'] ?? ''),
                'links' => $links,
            ];
        }

        return [
            'format' => 'credentials-backup',
            'format_version' => 1,
            'created_at' => gmdate('c'),
            'source_plugin_version' => PLUGIN_CREDENTIALS_VERSION,
            'records' => $records,
        ];
    }

    /**
     * @param array<int, mixed> $records
     * @return array{added:int,skipped:int,archived:int}
     */
    private function importRecords(array $records): array
    {
        global $DB;

        if (!Credential::isEncryptionReady()) {
            throw new \RuntimeException(__('The GLPI security key is unavailable. Backup import was cancelled.', 'credentials'));
        }

        $counts = ['added' => 0, 'skipped' => 0, 'archived' => 0];
        $category_names = array_map('mb_strtolower', AdminSettings::categories());
        $active_entity = method_exists(Session::class, 'getActiveEntity')
            ? (int) Session::getActiveEntity()
            : (int) ($_SESSION['glpiactive_entity'] ?? 0);

        foreach ($records as $record) {
            if (!is_array($record) || !is_array($record['secrets'] ?? null) || !is_array($record['links'] ?? null)) {
                $counts['skipped']++;
                continue;
            }

            $name = trim((string) ($record['name'] ?? ''));
            $secrets = $record['secrets'];
            $backup_uuid = preg_match('/^[a-f0-9]{32}$/i', (string) ($record['backup_uuid'] ?? ''))
                ? strtolower((string) $record['backup_uuid'])
                : bin2hex(random_bytes(16));

            $duplicate = $DB->request([
                'SELECT' => ['id'],
                'FROM' => Credential::getTable(),
                'WHERE' => ['backup_uuid' => $backup_uuid],
                'LIMIT' => 1,
            ]);
            if (count($duplicate) > 0) {
                $counts['skipped']++;
                continue;
            }

            $password = (string) ($secrets['password'] ?? '');
            if ($name === '' || mb_strlen($name) > 255 || $password === '' || strlen($password) > 48000) {
                $counts['skipped']++;
                continue;
            }

            $entity_id = (int) ($record['entities_id'] ?? $active_entity);
            if (!Session::haveAccessToEntity($entity_id)) {
                $entity_id = $active_entity;
            }

            $category = trim((string) ($record['category'] ?? 'General'));
            if ($category === '' || !in_array(mb_strtolower($category), $category_names, true)) {
                $category = 'General';
            }

            $encrypted = [];
            foreach (['login', 'password', 'url', 'notes'] as $field) {
                $value = (string) ($secrets[$field] ?? '');
                $encrypted[$field] = $value === '' ? '' : Credential::encryptValue($value);
                if ($value !== '' && $encrypted[$field] === null) {
                    throw new \RuntimeException(__('One or more imported values could not be encrypted.', 'credentials'));
                }
            }

            $resolved_links = [];
            foreach (array_slice($record['links'], 0, 100) as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $itemtype = (string) ($link['itemtype'] ?? '');
                $items_id = (int) ($link['items_id'] ?? 0);
                $item = CredentialItem::loadReadableItem($itemtype, $items_id);
                if (
                    $item === null
                    || !$item->can($items_id, UPDATE)
                    || CredentialItem::resolveParentEntityID($item) !== $entity_id
                    || (string) $item->getName() !== (string) ($link['name'] ?? '')
                ) {
                    continue;
                }
                $resolved_links[$itemtype . '#' . $items_id] = ['itemtype' => $itemtype, 'items_id' => $items_id];
            }

            $archived = !empty($record['is_deleted']) || $resolved_links === [];
            $now = (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
            $new = new Credential();
            $input = [
                'name' => $name,
                'entities_id' => $entity_id,
                'login' => $encrypted['login'],
                'password' => $encrypted['password'],
                'url' => $encrypted['url'],
                'notes' => $encrypted['notes'],
                'users_id' => (int) Session::getLoginUserID(),
                'category' => $category,
                'expires_at' => $this->validDateOrNull($record['expires_at'] ?? null),
                'review_interval_days' => max(0, min(365, (int) ($record['review_interval_days'] ?? 90))),
                'last_reviewed_at' => $this->validDateOrNull($record['last_reviewed_at'] ?? null),
                'last_reviewed_by' => (int) Session::getLoginUserID(),
                'backup_uuid' => $backup_uuid,
                'date_creation' => $now,
                'date_mod' => $now,
            ];
            if ($archived) {
                $input['is_deleted'] = 1;
                $input['archived_at'] = $now;
                $input['archived_by'] = (int) Session::getLoginUserID();
                $input['archive_reason'] = $resolved_links === []
                    ? __('Imported without a matching linked item.', 'credentials')
                    : __('Imported from an archived backup entry.', 'credentials');
            }

            $DB->beginTransaction();
            try {
                $credential_id = (int) $new->add($input);
                if ($credential_id <= 0) {
                    throw new \RuntimeException(__('A credential could not be imported. The import was rolled back.', 'credentials'));
                }

                if (!$archived) {
                    foreach ($resolved_links as $link_data) {
                        $link = new CredentialItemLink();
                        if (!$link->add([
                            'plugin_credentials_credentials_id' => $credential_id,
                            'itemtype' => $link_data['itemtype'],
                            'items_id' => $link_data['items_id'],
                            'date_creation' => $now,
                        ])) {
                            throw new \RuntimeException(__('A credential relationship could not be imported. The import was rolled back.', 'credentials'));
                        }
                        CredentialHistory::recordForItem(
                            $link_data['itemtype'],
                            $link_data['items_id'],
                            Credential::HISTORY_IMPORTED,
                            $credential_id,
                        );
                    }
                } else {
                    $counts['archived']++;
                }

                if (!CredentialHistory::recordAudit($credential_id, 'IMPORTED', '', 0)) {
                    throw new \RuntimeException(__('The import audit entry could not be saved. The import was rolled back.', 'credentials'));
                }
                $DB->commit();
                $counts['added']++;
            } catch (\Throwable $exception) {
                $DB->rollBack();
                throw $exception;
            }
        }

        return $counts;
    }

    private function saveSettings(Request $request): void
    {
        $raw_categories = preg_split('/\\R/u', $request->request->getString('categories')) ?: [];
        $categories = [];
        foreach ($raw_categories as $category) {
            $category = trim((string) $category);
            if ($category === '' || mb_strlen($category) > 100) {
                continue;
            }
            $categories[mb_strtolower($category)] = $category;
        }
        if ($categories === []) {
            $categories = array_combine(
                array_map('mb_strtolower', AdminSettings::DEFAULT_CATEGORIES),
                AdminSettings::DEFAULT_CATEGORIES,
            );
        }
        if (count($categories) > 40) {
            Session::addMessageAfterRedirect(__('You can define up to 40 categories.', 'credentials'), false, ERROR);
            return;
        }

        $retention = max(0, min(3650, $request->request->getInt('retention_days')));
        $settings = AdminSettings::all();
        $settings['categories'] = implode("\n", array_values($categories));
        $settings['retention_days'] = $retention;
        AdminSettings::save($settings);
        CredentialHistory::recordAudit(0, 'SETTINGS_UPDATED', '', 0);
        Session::addMessageAfterRedirect(__('Credentials settings were saved.', 'credentials'), false, INFO);
    }

    private function markReviewed(int $credential_id): void
    {
        global $DB;

        Session::checkRight(Credential::$rightname, UPDATE);
        $credential = new Credential();
        if (
            $credential_id <= 0
            || !$credential->getFromDB($credential_id)
            || (int) ($credential->fields['is_deleted'] ?? 0) !== 0
            || !Session::haveAccessToEntity((int) $credential->fields['entities_id'])
            || !$credential->can($credential_id, UPDATE)
        ) {
            Session::addMessageAfterRedirect(__('The credential could not be reviewed with your current permissions.', 'credentials'), false, ERROR);
            return;
        }

        $today = date('Y-m-d');
        if ($DB->update(Credential::getTable(), [
            'last_reviewed_at' => $today,
            'last_reviewed_by' => (int) Session::getLoginUserID(),
            'date_mod' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
        ], ['id' => $credential_id, 'is_deleted' => 0]) === false) {
            Session::addMessageAfterRedirect(__('The review date could not be saved.', 'credentials'), false, ERROR);
            return;
        }

        CredentialHistory::recordAudit($credential_id, 'REVIEWED', '', 0);
        Session::addMessageAfterRedirect(__('The credential review date was updated.', 'credentials'), false, INFO);
    }

    private function restoreArchived(int $credential_id, string $itemtype, int $items_id): void
    {
        global $DB;

        Session::checkRight(Credential::$rightname, UPDATE);
        $credential = new Credential();
        if (
            $credential_id <= 0
            || !$credential->getFromDB($credential_id)
            || (int) ($credential->fields['is_deleted'] ?? 0) !== 1
            || !Session::haveAccessToEntity((int) $credential->fields['entities_id'])
            || !$credential->can($credential_id, UPDATE)
        ) {
            Session::addMessageAfterRedirect(__('The archived credential is not available for restoration.', 'credentials'), false, ERROR);
            return;
        }

        $entity_id = (int) $credential->fields['entities_id'];
        $eligible_link = false;
        foreach (CredentialItemLink::getLinksForCredential($credential_id) as $existing) {
            $item = CredentialItem::loadReadableItem($existing['itemtype'], $existing['items_id']);
            if ($item !== null && $item->can($existing['items_id'], UPDATE)
                && CredentialItem::resolveParentEntityID($item) === $entity_id) {
                $eligible_link = true;
                break;
            }
        }

        if (!$eligible_link && $itemtype !== '' && $items_id > 0) {
            $item = CredentialItem::loadReadableItem($itemtype, $items_id);
            if (
                $item !== null
                && $item->can($items_id, UPDATE)
                && CredentialItem::resolveParentEntityID($item) === $entity_id
            ) {
                $link = new CredentialItemLink();
                $eligible_link = (bool) $link->add([
                    'plugin_credentials_credentials_id' => $credential_id,
                    'itemtype' => $itemtype,
                    'items_id' => $items_id,
                    'date_creation' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
                ]);
                if ($eligible_link) {
                    CredentialHistory::recordForItem($itemtype, $items_id, Credential::HISTORY_LINKED, $credential_id);
                }
            }
        }

        if (!$eligible_link) {
            Session::addMessageAfterRedirect(
                __('To restore this entry, an associated item must still exist and be available to your account. Enter an item type and ID when the original association no longer exists.', 'credentials'),
                false,
                ERROR,
            );
            return;
        }

        if ($DB->update(Credential::getTable(), [
            'is_deleted' => 0,
            'archived_at' => null,
            'archived_by' => 0,
            'archive_reason' => '',
            'date_mod' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
        ], ['id' => $credential_id, 'is_deleted' => 1]) === false) {
            Session::addMessageAfterRedirect(__('The credential could not be restored.', 'credentials'), false, ERROR);
            return;
        }

        CredentialHistory::recordAudit($credential_id, 'RESTORED', '', 0);
        Session::addMessageAfterRedirect(__('The credential was restored.', 'credentials'), false, INFO);
    }

    private function eraseArchived(int $credential_id): void
    {
        Session::checkRight(Credential::$rightname, DELETE);
        $credential = new Credential();
        if (
            $credential_id <= 0
            || !$credential->getFromDB($credential_id)
            || (int) ($credential->fields['is_deleted'] ?? 0) !== 1
            || !Session::haveAccessToEntity((int) $credential->fields['entities_id'])
        ) {
            Session::addMessageAfterRedirect(__('Only archived credentials can be erased permanently.', 'credentials'), false, ERROR);
            return;
        }

        if (!$this->permanentlyErase($credential_id)) {
            Session::addMessageAfterRedirect(__('The archived credential could not be erased.', 'credentials'), false, ERROR);
            return;
        }

        Session::addMessageAfterRedirect(__('The credential and its stored encrypted values were permanently removed from the active database.', 'credentials'), false, INFO);
    }

    private function purgeEligible(): void
    {
        Session::checkRight(Credential::$rightname, DELETE);
        $retention_days = AdminSettings::retentionDays();
        if ($retention_days <= 0) {
            Session::addMessageAfterRedirect(__('Automatic retention is disabled. Set a retention period before using this action.', 'credentials'), false, ERROR);
            return;
        }

        global $DB;
        $cutoff = (new \DateTimeImmutable('today'))->modify('-' . $retention_days . ' days')->format('Y-m-d H:i:s');
        $iterator = $DB->request([
            'SELECT' => ['id', 'entities_id', 'archived_at', 'date_mod'],
            'FROM' => Credential::getTable(),
            'WHERE' => ['is_deleted' => 1],
        ]);

        $removed = 0;
        foreach ($iterator as $row) {
            if (!Session::haveAccessToEntity((int) $row['entities_id'])) {
                continue;
            }
            $archived_at = (string) ($row['archived_at'] ?: $row['date_mod']);
            if ($archived_at !== '' && $archived_at <= $cutoff && $this->permanentlyErase((int) $row['id'])) {
                $removed++;
            }
        }

        Session::addMessageAfterRedirect(
            sprintf(__('Permanent deletion completed. %d archived credential(s) were removed.', 'credentials'), $removed),
            false,
            INFO,
        );
    }

    private function permanentlyErase(int $credential_id): bool
    {
        global $DB;

        $DB->beginTransaction();
        try {
            $credential = new Credential();
            if (!$credential->getFromDB($credential_id) || (int) ($credential->fields['is_deleted'] ?? 0) !== 1) {
                $DB->rollBack();
                return false;
            }

            CredentialHistory::recordAudit($credential_id, 'CRYPTO_ERASED', '', 0);
            if ($DB->update(Credential::getTable(), [
                'login' => null,
                'password' => '',
                'url' => null,
                'notes' => null,
                'name' => '[permanently erased]',
            ], ['id' => $credential_id, 'is_deleted' => 1]) === false) {
                throw new \RuntimeException('Unable to clear archived credential values.');
            }
            if ($DB->delete(CredentialItemLink::getTable(), ['plugin_credentials_credentials_id' => $credential_id]) === false) {
                throw new \RuntimeException('Unable to remove archived credential relationships.');
            }
            if ($DB->delete(Credential::getTable(), ['id' => $credential_id, 'is_deleted' => 1]) === false) {
                throw new \RuntimeException('Unable to remove archived credential record.');
            }

            $DB->commit();
            return true;
        } catch (\Throwable) {
            $DB->rollBack();
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function loadDashboardData(): array
    {
        global $DB;

        $stats = ['active' => 0, 'archived' => 0, 'expired' => 0, 'review_due' => 0];
        $review_rows = [];
        $archive_rows = [];

        $iterator = $DB->request([
            'SELECT' => [
                'id', 'name', 'entities_id', 'is_deleted', 'date_creation', 'date_mod',
                'category', 'expires_at', 'review_interval_days', 'last_reviewed_at',
                'archived_at', 'archive_reason',
            ],
            'FROM' => Credential::getTable(),
            'ORDER' => ['date_mod DESC'],
            'LIMIT' => 1000,
        ]);

        $today = new \DateTimeImmutable('today');
        foreach ($iterator as $row) {
            if (!Session::haveAccessToEntity((int) $row['entities_id'])) {
                continue;
            }

            if ((int) $row['is_deleted'] === 1) {
                $stats['archived']++;
                if (count($archive_rows) < 50) {
                    $archive_rows[] = [
                        'id' => (int) $row['id'],
                        'name' => (string) $row['name'],
                        'category' => (string) ($row['category'] ?? 'General'),
                        'archived_at' => $row['archived_at'] ?: $row['date_mod'] ?: $row['date_creation'],
                        'archive_reason' => (string) ($row['archive_reason'] ?? ''),
                        'link_count' => CredentialItemLink::countForCredential((int) $row['id']),
                    ];
                }
                continue;
            }

            $stats['active']++;
            $expires = $this->parseDate($row['expires_at'] ?? null);
            if ($expires instanceof \DateTimeImmutable && $expires < $today) {
                $stats['expired']++;
            }

            $interval = max(0, (int) ($row['review_interval_days'] ?? 90));
            $review_base = $this->parseDate($row['last_reviewed_at'] ?: $row['date_creation']);
            $review_due = $interval > 0 && $review_base instanceof \DateTimeImmutable
                ? $review_base->modify('+' . $interval . ' days')
                : null;
            if ($review_due instanceof \DateTimeImmutable && $review_due <= $today) {
                $stats['review_due']++;
                if (count($review_rows) < 50) {
                    $review_rows[] = [
                        'id' => (int) $row['id'],
                        'name' => (string) $row['name'],
                        'category' => (string) ($row['category'] ?? 'General'),
                        'due_date' => $review_due->format('Y-m-d'),
                        'expires_at' => $row['expires_at'],
                    ];
                }
            }
        }

        $audit_rows = [];
        $audit = $DB->request([
            'SELECT' => ['id', 'credential_id', 'users_id', 'action', 'itemtype', 'items_id', 'date_creation'],
            'FROM' => 'glpi_plugin_credentials_audit',
            'ORDER' => ['date_creation DESC'],
            'LIMIT' => 50,
        ]);
        foreach ($audit as $row) {
            $user_name = '';
            $user = new User();
            if ((int) $row['users_id'] > 0 && $user->getFromDB((int) $row['users_id'])) {
                $user_name = (string) $user->getName();
            }
            $audit_rows[] = [
                'date' => (string) $row['date_creation'],
                'credential_id' => (int) $row['credential_id'],
                'user' => $user_name !== '' ? $user_name : __('System', 'credentials'),
                'action' => (string) $row['action'],
                'itemtype' => (string) $row['itemtype'],
                'items_id' => (int) $row['items_id'],
            ];
        }

        return compact('stats', 'review_rows', 'archive_rows', 'audit_rows');
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        try {
            return new \DateTimeImmutable(substr($value, 0, 10));
        } catch (\Throwable) {
            return null;
        }
    }

    private function validDateOrNull(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function renderPage(Request $request, array $variables): Response
    {
        $buffer_level = ob_get_level();
        ob_start();
        try {
            Html::header(__('Credentials administration', 'credentials'), $request->getPathInfo(), 'config', 'plugins');
            \Glpi\Application\View\TemplateRenderer::getInstance()->display('@credentials/admin.html.twig', $variables);
            Html::footer();
            $content = (string) ob_get_clean();
        } catch (\Throwable $exception) {
            while (ob_get_level() > $buffer_level) {
                ob_end_clean();
            }
            throw $exception;
        }

        $response = new Response($content);
        $this->setNoStore($response);
        return $response;
    }

    private function redirectToConfig(): Response
    {
        $response = new \Symfony\Component\HttpFoundation\RedirectResponse(Paths::routeUrl('config'), Response::HTTP_SEE_OTHER);
        $this->setNoStore($response);
        return $response;
    }

    private function setNoStore(Response $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
    }
}
