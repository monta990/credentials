<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Credentials\Credential;
use GlpiPlugin\Credentials\CredentialHistory;
use GlpiPlugin\Credentials\CredentialItem;
use GlpiPlugin\Credentials\CredentialItemLink;
use GlpiPlugin\Credentials\Security;
use GlpiPlugin\Credentials\UpdateChecker;
use GlpiPlugin\Credentials\VaultArchive;
use Html;
use Session;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use User;

/** Administrative settings, lifecycle tools and encrypted backup workflows. */
final class AdminController extends AbstractController
{
    private const RELEASES_PAGE = 'https://github.com/monta990/credentials/releases';
    private const MAX_IMPORT_BYTES = 20_000_000;
    private const MAX_IMPORT_RECORDS = 5000;

    #[Route('/config', name: 'credentials_config', methods: ['GET'])]
    public function index(Request $request): Response
    {
        self::requireAdministrator();
        Session::checkRight(Credential::$rightname, READ);
        $entities = self::accessibleEntities();
        global $DB;

        $activeRows = [];
        $archivedRows = [];
        $activeIterator = $DB->request([
            'SELECT' => ['id', 'name', 'entities_id', 'category', 'expires_at', 'review_due_at', 'reviewed_at', 'reviewer_users_id'],
            'FROM' => Credential::getTable(),
            'WHERE' => ['entities_id' => $entities, 'is_deleted' => 0],
            'ORDER' => ['name'],
        ]);
        foreach ($activeIterator as $row) {
            $activeRows[] = $row;
        }

        $archiveIterator = $DB->request([
            'SELECT' => ['id', 'name', 'entities_id', 'category', 'date_mod'],
            'FROM' => Credential::getTable(),
            'WHERE' => ['entities_id' => $entities, 'is_deleted' => 1],
            'ORDER' => ['date_mod DESC'],
            'LIMIT' => 100,
        ]);
        foreach ($archiveIterator as $row) {
            $archivedRows[] = $row;
        }

        $today = date('Y-m-d');
        $soon = date('Y-m-d', strtotime('+30 days'));
        $expiringRows = [];
        $reviewRows = [];
        $categoryCounts = [];
        foreach ($activeRows as $row) {
            $category = trim((string) ($row['category'] ?? ''));
            if ($category !== '') {
                $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
            }
            $expiration = (string) ($row['expires_at'] ?? '');
            if ($expiration !== '' && $expiration <= $soon) {
                $row['status'] = $expiration < $today ? 'expired' : 'expiring';
                $expiringRows[] = $row;
            }
            $reviewDue = (string) ($row['review_due_at'] ?? '');
            if ($reviewDue !== '' && $reviewDue <= $soon) {
                $row['status'] = $reviewDue < $today ? 'overdue' : 'upcoming';
                $reviewRows[] = $row;
            }
        }
        ksort($categoryCounts, SORT_NATURAL | SORT_FLAG_CASE);

        $auditRows = [];
        $auditIterator = $DB->request([
            'SELECT' => ['id', 'items_id', 'date', 'users_id', 'linked_action'],
            'FROM' => 'glpi_logs',
            'WHERE' => ['itemtype' => Credential::class],
            'ORDER' => ['date DESC'],
            'LIMIT' => 75,
        ]);
        foreach ($auditIterator as $row) {
            $credentialId = (int) ($row['items_id'] ?? 0);
            $credential = new Credential();
            if ($credentialId > 0 && $credential->getFromDB($credentialId)
                && !Session::haveAccessToEntity((int) ($credential->fields['entities_id'] ?? 0))) {
                continue;
            }
            $userName = '';
            $userId = (int) ($row['users_id'] ?? 0);
            if ($userId > 0) {
                $user = new User();
                if ($user->getFromDB($userId)) {
                    $userName = (string) $user->getName();
                }
            }
            $row['credential_id'] = $credentialId;
            $row['user_name'] = $userName !== '' ? $userName : (string) $userId;
            $row['event'] = Credential::getHistoryEntry($row);
            if ($row['event'] !== '') {
                $auditRows[] = $row;
            }
            if (count($auditRows) >= 50) {
                break;
            }
        }

        $release = UpdateChecker::check();
        $content = self::renderPage($request, [
            'base_url' => self::routeUrl('config'),
            'update_url' => self::routeUrl('config/check-updates'),
            'export_url' => self::routeUrl('config/export'),
            'import_url' => self::routeUrl('config/import'),
            'restore_url' => self::routeUrl('config/archive/restore'),
            'purge_url' => self::routeUrl('config/archive/purge'),
            'review_url' => self::routeUrl('config/review'),
            'release' => $release,
            'release_page' => self::RELEASES_PAGE,
            'active_count' => count($activeRows),
            'archived_count' => count($archivedRows),
            'expiring_count' => count(array_filter($expiringRows, static fn(array $row): bool => ($row['status'] ?? '') === 'expiring')),
            'expired_count' => count(array_filter($expiringRows, static fn(array $row): bool => ($row['status'] ?? '') === 'expired')),
            'review_due_count' => count($reviewRows),
            'encryption_ready' => Credential::isEncryptionReady(),
            'sodium_ready' => extension_loaded('sodium'),
            'categories' => array_map(
                static fn(string $name, int $count): array => ['name' => $name, 'count' => $count],
                array_keys($categoryCounts),
                array_values($categoryCounts),
            ),
            'expiring_rows' => array_slice($expiringRows, 0, 50),
            'review_rows' => array_slice($reviewRows, 0, 50),
            'archived_rows' => $archivedRows,
            'audit_rows' => $auditRows,
            'can_export' => Session::haveRight(Credential::$rightname, READ) && Session::haveRight(Credential::RIGHT_REVEAL, READ),
            'can_import' => Session::haveRight(Credential::$rightname, CREATE) && Credential::isEncryptionReady(),
            'can_review' => Session::haveRight(Credential::$rightname, UPDATE),
            'can_restore' => Session::haveRight(Credential::$rightname, UPDATE),
            'can_purge' => Session::haveRight(Credential::$rightname, DELETE),
        ]);

        return $content;
    }

    #[Route('/config/check-updates', name: 'credentials_config_check_updates', methods: ['POST'])]
    public function checkUpdates(): Response
    {
        self::requireAdministrator();
        UpdateChecker::check(true);
        Session::addMessageAfterRedirect(__('GitHub release information has been refreshed.', 'credentials'), false, INFO);
        return self::redirectToConfig();
    }

    #[Route('/config/export', name: 'credentials_config_export', methods: ['POST'])]
    public function export(Request $request): Response
    {
        self::requireAdministrator();
        Session::checkRight(Credential::$rightname, READ);
        Session::checkRight(Credential::RIGHT_REVEAL, READ);
        Security::requireReauthentication();

        $passphrase = $request->request->getString('backup_password');
        $confirmation = $request->request->getString('backup_password_confirm');
        if (!hash_equals($passphrase, $confirmation)) {
            throw new \InvalidArgumentException(__('The backup passwords do not match.', 'credentials'));
        }

        global $DB;
        $records = [];
        $exportedIds = [];
        $entities = self::accessibleEntities();
        $iterator = $DB->request([
            'SELECT' => [
                'id', 'name', 'entities_id', 'login', 'password', 'url', 'notes',
                'category', 'expires_at', 'review_due_at',
            ],
            'FROM' => Credential::getTable(),
            'WHERE' => ['entities_id' => $entities, 'is_deleted' => 0],
            'ORDER' => ['id'],
        ]);

        foreach ($iterator as $row) {
            if (count($records) >= self::MAX_IMPORT_RECORDS) {
                throw new \RuntimeException(__('The backup cannot contain more than 5,000 credentials. Export a smaller set.', 'credentials'));
            }
            $id = (int) $row['id'];
            $credential = new Credential();
            if (!$credential->getFromDB($id) || !$credential->can($id, READ)) {
                throw new \Glpi\Exception\Http\AccessDeniedHttpException();
            }

            $secretFields = [];
            foreach (['login', 'password', 'url', 'notes'] as $field) {
                $ciphertext = (string) ($row[$field] ?? '');
                $plaintext = Credential::decryptValue($ciphertext);
                if ($plaintext === null || ($ciphertext !== '' && $plaintext === '')) {
                    throw new \RuntimeException(__('A credential could not be decrypted. The backup was cancelled.', 'credentials'));
                }
                $secretFields[$field] = $plaintext;
            }

            $links = CredentialItemLink::getLinksForCredential($id);
            if ($links === []) {
                throw new \RuntimeException(__('An active credential has no linked item. Repair it before exporting.', 'credentials'));
            }

            $records[] = [
                'name' => (string) $row['name'],
                'entities_id' => (int) $row['entities_id'],
                'login' => $secretFields['login'],
                'password' => $secretFields['password'],
                'url' => $secretFields['url'],
                'notes' => $secretFields['notes'],
                'category' => (string) ($row['category'] ?? ''),
                'expires_at' => (string) ($row['expires_at'] ?? ''),
                'review_due_at' => (string) ($row['review_due_at'] ?? ''),
                'items' => $links,
            ];
            $exportedIds[] = $id;
        }

        $archive = VaultArchive::encrypt([
            'format' => 'credentials-vault',
            'format_version' => 1,
            'exported_at' => gmdate('c'),
            'exported_by' => (int) Session::getLoginUserID(),
            'source_plugin_version' => PLUGIN_CREDENTIALS_VERSION,
            'records' => $records,
        ], $passphrase);

        foreach ($exportedIds as $credentialId) {
            if (!CredentialHistory::recordForCredential($credentialId, Credential::HISTORY_EXPORTED)) {
                throw new \RuntimeException(__('The backup was created but its export activity could not be recorded.', 'credentials'));
            }
        }

        $response = new Response($archive);
        $response->headers->set('Content-Type', 'application/vnd.cy-credentials+json; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="credentials-backup-' . date('Y-m-d_H-i-s') . '.ccvault"');
        self::setNoStoreHeaders($response);
        return $response;
    }

    #[Route('/config/import', name: 'credentials_config_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        self::requireAdministrator();
        Session::checkRight(Credential::$rightname, CREATE);
        Security::requireReauthentication();
        if (!Credential::isEncryptionReady() || !extension_loaded('sodium')) {
            throw new \RuntimeException(__('Credential encryption is not available on this server.', 'credentials'));
        }

        $file = $request->files->get('archive');
        if (!$file instanceof UploadedFile || !$file->isValid()
            || $file->getSize() <= 0 || $file->getSize() > self::MAX_IMPORT_BYTES) {
            throw new \InvalidArgumentException(__('Select a valid credential backup file (maximum 20 MB).', 'credentials'));
        }

        $raw = file_get_contents($file->getPathname());
        if (!is_string($raw)) {
            throw new \RuntimeException(__('The credential backup could not be read.', 'credentials'));
        }
        $payload = VaultArchive::decrypt($raw, $request->request->getString('backup_password'));
        $records = $payload['records'] ?? null;
        if (!is_array($records) || count($records) > self::MAX_IMPORT_RECORDS) {
            throw new \InvalidArgumentException(__('The backup does not contain a supported number of credentials (maximum 500).', 'credentials'));
        }

        global $DB;
        $DB->beginTransaction();
        $imported = 0;
        try {
            foreach ($records as $record) {
                if (!is_array($record)) {
                    throw new \InvalidArgumentException(__('The credential backup contains an invalid entry.', 'credentials'));
                }

                $name = trim((string) ($record['name'] ?? ''));
                $entityId = (int) ($record['entities_id'] ?? -1);
                $links = $record['items'] ?? null;
                if (
                    $name === '' || mb_strlen($name) > 255 || $entityId < 0
                    || !Session::haveAccessToEntity($entityId)
                    || !is_array($links) || count($links) < 1 || count($links) > 1000
                ) {
                    throw new \InvalidArgumentException(__('A credential in the backup has invalid name, entity or linked-item data.', 'credentials'));
                }

                $preparedLinks = [];
                $seenLinks = [];
                foreach ($links as $linkData) {
                    if (!is_array($linkData)) {
                        throw new \InvalidArgumentException(__('A linked item in the backup is invalid.', 'credentials'));
                    }
                    $itemtype = (string) ($linkData['itemtype'] ?? '');
                    $itemsId = (int) ($linkData['items_id'] ?? 0);
                    if ($itemsId <= 0 || !CredentialItem::isSupportedItemtype($itemtype)) {
                        throw new \InvalidArgumentException(__('A linked item in the backup is not supported.', 'credentials'));
                    }
                    $item = CredentialItem::loadReadableItem($itemtype, $itemsId);
                    if (
                        $item === null || !$item->can($itemsId, UPDATE)
                        || CredentialItem::resolveParentEntityID($item) !== $entityId
                    ) {
                        throw new \InvalidArgumentException(__('Every linked item must exist, be editable and belong to the same entity as its credential.', 'credentials'));
                    }
                    $key = $itemtype . '#' . $itemsId;
                    if (!isset($seenLinks[$key])) {
                        $preparedLinks[] = ['itemtype' => $itemtype, 'items_id' => $itemsId];
                        $seenLinks[$key] = true;
                    }
                }

                $login = (string) ($record['login'] ?? '');
                $password = (string) ($record['password'] ?? '');
                $url = (string) ($record['url'] ?? '');
                $notes = (string) ($record['notes'] ?? '');
                $category = trim((string) ($record['category'] ?? ''));
                $expiresAt = (string) ($record['expires_at'] ?? '');
                $reviewDueAt = (string) ($record['review_due_at'] ?? '');
                if (
                    mb_strlen($login) > 255 || strlen($password) > 48000
                    || mb_strlen($url) > 2048 || mb_strlen($notes) > 100000
                    || mb_strlen($category) > 100
                    || !self::validDate($expiresAt) || !self::validDate($reviewDueAt)
                ) {
                    throw new \InvalidArgumentException(__('A credential in the backup contains fields with invalid values or lengths.', 'credentials'));
                }
                if ($url !== '') {
                    $parts = parse_url($url);
                    if (
                        !filter_var($url, FILTER_VALIDATE_URL) || !is_array($parts)
                        || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
                        || isset($parts['user']) || isset($parts['pass'])
                    ) {
                        throw new \InvalidArgumentException(__('A credential in the backup contains an invalid URL.', 'credentials'));
                    }
                }

                $encrypted = [];
                foreach (['login' => $login, 'password' => $password, 'url' => $url, 'notes' => $notes] as $field => $value) {
                    $encrypted[$field] = $value === '' && $field !== 'password' ? null : Credential::encryptValue($value);
                    if ($encrypted[$field] === null && ($value !== '' || $field === 'password')) {
                        throw new \RuntimeException(__('The GLPI key could not encrypt imported credential data.', 'credentials'));
                    }
                }

                $credential = new Credential();
                $newId = (int) $credential->add([
                    'name' => $name,
                    'entities_id' => $entityId,
                    'login' => $encrypted['login'],
                    'password' => $encrypted['password'],
                    'url' => $encrypted['url'],
                    'notes' => $encrypted['notes'],
                    'category' => $category,
                    'expires_at' => $expiresAt !== '' ? $expiresAt : null,
                    'review_due_at' => $reviewDueAt !== '' ? $reviewDueAt : null,
                    'reviewed_at' => null,
                    'reviewer_users_id' => 0,
                    'users_id' => (int) Session::getLoginUserID(),
                ]);
                if ($newId <= 0) {
                    throw new \RuntimeException(__('An imported credential could not be saved.', 'credentials'));
                }

                foreach ($preparedLinks as $preparedLink) {
                    $link = new CredentialItemLink();
                    if (!$link->add([
                        'plugin_credentials_credentials_id' => $newId,
                        'itemtype' => $preparedLink['itemtype'],
                        'items_id' => $preparedLink['items_id'],
                        'date_creation' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
                    ])) {
                        throw new \RuntimeException(__('An imported credential could not be linked to its GLPI item.', 'credentials'));
                    }
                    if (!CredentialHistory::recordForItem(
                        $preparedLink['itemtype'],
                        $preparedLink['items_id'],
                        Credential::HISTORY_IMPORTED,
                        $newId,
                    )) {
                        throw new \RuntimeException(__('An imported credential could not be written to GLPI history.', 'credentials'));
                    }
                }
                if (!CredentialHistory::recordForCredential($newId, Credential::HISTORY_IMPORTED)) {
                    throw new \RuntimeException(__('An imported credential could not be recorded in the activity history.', 'credentials'));
                }
                $imported++;
            }
            $DB->commit();
        } catch (\Throwable $exception) {
            $DB->rollBack();
            throw $exception;
        }

        Session::addMessageAfterRedirect(sprintf(__('Imported %d credential(s). Existing entries were not overwritten.', 'credentials'), $imported), false, INFO);
        return self::redirectToConfig();
    }

    #[Route('/config/review', name: 'credentials_config_review', methods: ['POST'])]
    public function markReviewed(Request $request): Response
    {
        self::requireAdministrator();
        Session::checkRight(Credential::$rightname, UPDATE);
        Security::requireReauthentication();
        $id = $request->request->getInt('id');
        $nextReview = trim($request->request->getString('next_review_at'));
        if (!self::validDate($nextReview)) {
            throw new \InvalidArgumentException(__('Enter a valid next review date or leave it empty to clear the schedule.', 'credentials'));
        }

        $credential = new Credential();
        if ($id <= 0 || !$credential->getFromDB($id)
            || (int) ($credential->fields['is_deleted'] ?? 0) !== 0
            || !Session::haveAccessToEntity((int) ($credential->fields['entities_id'] ?? 0))
            || !$credential->can($id, UPDATE)) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }

        global $DB;
        $updated = $DB->update(Credential::getTable(), [
            'reviewed_at' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
            'reviewer_users_id' => (int) Session::getLoginUserID(),
            'review_due_at' => $nextReview !== '' ? $nextReview : null,
            'date_mod' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
        ], ['id' => $id, 'is_deleted' => 0]);
        if ($updated === false || $DB->getAffectedRows() !== 1
            || !CredentialHistory::recordForCredential($id, Credential::HISTORY_REVIEWED)) {
            throw new \RuntimeException(__('The review could not be saved or audited.', 'credentials'));
        }

        Session::addMessageAfterRedirect(__('The credential review was recorded.', 'credentials'), false, INFO);
        return self::redirectToConfig();
    }

    #[Route('/config/archive/restore', name: 'credentials_config_restore', methods: ['POST'])]
    public function restore(Request $request): Response
    {
        self::requireAdministrator();
        Session::checkRight(Credential::$rightname, UPDATE);
        Security::requireReauthentication();

        $id = $request->request->getInt('id');
        $itemtype = $request->request->getString('itemtype');
        $itemsId = $request->request->getInt('items_id');
        $item = CredentialItem::loadReadableItem($itemtype, $itemsId);
        $credential = new Credential();
        if (
            $id <= 0 || $item === null || !$item->can($itemsId, UPDATE)
            || !$credential->getFromDB($id)
            || (int) ($credential->fields['is_deleted'] ?? 0) !== 1
            || !Session::haveAccessToEntity((int) ($credential->fields['entities_id'] ?? 0))
            || CredentialItem::resolveParentEntityID($item) !== (int) $credential->fields['entities_id']
        ) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }

        global $DB;
        $DB->beginTransaction();
        try {
            if (!CredentialItemLink::lockCredential($id, (int) $credential->fields['entities_id'], false)) {
                throw new \RuntimeException(__('The archived credential changed while it was being restored.', 'credentials'));
            }
            $live = new Credential();
            if (!$live->getFromDB($id) || (int) ($live->fields['is_deleted'] ?? 0) !== 1) {
                throw new \RuntimeException(__('The archived credential is no longer available.', 'credentials'));
            }
            if (!CredentialItemLink::exists($id, $itemtype, $itemsId)) {
                $link = new CredentialItemLink();
                if (!$link->add([
                    'plugin_credentials_credentials_id' => $id,
                    'itemtype' => $itemtype,
                    'items_id' => $itemsId,
                    'date_creation' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
                ])) {
                    throw new \RuntimeException(__('The credential could not be linked to the selected item.', 'credentials'));
                }
            }
            $updated = $DB->update(Credential::getTable(), [
                'is_deleted' => 0,
                'date_mod' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
            ], ['id' => $id, 'is_deleted' => 1]);
            if ($updated === false || $DB->getAffectedRows() !== 1
                || !CredentialHistory::recordForCredential($id, Credential::HISTORY_RESTORED)
                || !CredentialHistory::recordForItem($itemtype, $itemsId, Credential::HISTORY_LINKED, $id)) {
                throw new \RuntimeException(__('The credential could not be restored and recorded in GLPI history.', 'credentials'));
            }
            $DB->commit();
        } catch (\Throwable $exception) {
            $DB->rollBack();
            throw $exception;
        }

        Session::addMessageAfterRedirect(__('The credential was restored and linked to the selected item.', 'credentials'), false, INFO);
        return self::redirectToConfig();
    }

    #[Route('/config/archive/purge', name: 'credentials_config_purge', methods: ['POST'])]
    public function purge(Request $request): Response
    {
        self::requireAdministrator();
        Session::checkRight(Credential::$rightname, DELETE);
        Security::requireReauthentication();
        $id = $request->request->getInt('id');
        $credential = new Credential();
        if (
            $id <= 0 || !$credential->getFromDB($id)
            || (int) ($credential->fields['is_deleted'] ?? 0) !== 1
            || !Session::haveAccessToEntity((int) ($credential->fields['entities_id'] ?? 0))
            || !$request->request->getBoolean('confirm_purge')
        ) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }

        global $DB;
        $DB->beginTransaction();
        try {
            if (!CredentialItemLink::lockCredential($id, (int) $credential->fields['entities_id'], false)) {
                throw new \RuntimeException(__('The archived credential changed while it was being removed.', 'credentials'));
            }
            if (!CredentialHistory::recordForCredential($id, Credential::HISTORY_PURGED)) {
                throw new \RuntimeException(__('The permanent deletion could not be recorded.', 'credentials'));
            }
            if ($DB->delete(CredentialItemLink::getTable(), ['plugin_credentials_credentials_id' => $id]) === false
                || $DB->delete(Credential::getTable(), ['id' => $id, 'is_deleted' => 1]) === false
                || $DB->getAffectedRows() !== 1) {
                throw new \RuntimeException(__('The archived credential could not be permanently deleted.', 'credentials'));
            }
            $DB->commit();
        } catch (\Throwable $exception) {
            $DB->rollBack();
            throw $exception;
        }

        Session::addMessageAfterRedirect(__('The archived credential was permanently deleted. This action cannot be undone.', 'credentials'), false, INFO);
        return self::redirectToConfig();
    }

    private static function requireAdministrator(): void
    {
        Session::checkLoginUser();
        Session::checkRight('config', UPDATE);
    }

    /** @return array<int, int> */
    private static function accessibleEntities(): array
    {
        $entities = Session::getActiveEntities();
        return $entities !== [] ? array_map('intval', $entities) : [0];
    }

    private static function validDate(string $value): bool
    {
        if ($value === '') {
            return true;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private static function routeUrl(string $path): string
    {
        return PLUGIN_CREDENTIALS_WEBDIR . '/' . ltrim($path, '/');
    }

    private static function redirectToConfig(): RedirectResponse
    {
        return new RedirectResponse(self::routeUrl('config'), Response::HTTP_SEE_OTHER);
    }

    /** @param array<string, mixed> $variables */
    private static function renderPage(Request $request, array $variables): Response
    {
        $bufferLevel = ob_get_level();
        ob_start();
        try {
            Html::header(__('Credentials settings', 'credentials'), $request->getPathInfo(), 'config', 'plugins');
            \Glpi\Application\View\TemplateRenderer::getInstance()->display('@credentials/config.html.twig', $variables);
            Html::footer();
            $html = (string) ob_get_clean();
        } catch (\Throwable $exception) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            throw $exception;
        }
        $response = new Response($html);
        self::setNoStoreHeaders($response);
        return $response;
    }

    private static function setNoStoreHeaders(Response $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
    }
}
