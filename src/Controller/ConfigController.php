<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Credentials\Credential;
use GlpiPlugin\Credentials\CredentialCategory;
use GlpiPlugin\Credentials\CredentialItem;
use GlpiPlugin\Credentials\Security;
use GlpiPlugin\Credentials\Service\CredentialAdminService;
use GlpiPlugin\Credentials\Service\CredentialBackupService;
use GlpiPlugin\Credentials\Service\CredentialLifecycleReminderService;
use GlpiPlugin\Credentials\Service\UpdateChecker;
use Html;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Independent administration page for Credentials. */
final class ConfigController extends AbstractController
{
    #[Route('/config', name: 'credentials_config', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        global $CFG_GLPI;

        Session::checkLoginUser();
        Session::checkRight('config', UPDATE);

        if ($request->isMethod('POST')) {
            Security::requireReauthentication();
            $action = $request->request->getString('action');
            $entity_id = max(0, (int) ($_SESSION['glpiactive_entity'] ?? 0));

            try {
                switch ($action) {
                    case 'check_updates':
                        $update = UpdateChecker::latest(true);
                        $message = match ($update['status']) {
                            'update_available' => sprintf(__('Version %s is available.', 'credentials'), $update['version']),
                            'up_to_date' => __('Credentials is already up to date.', 'credentials'),
                            'no_release' => __('There are no stable releases published on GitHub yet.', 'credentials'),
                            default => __('GitHub could not be reached. The update check will be available again later.', 'credentials'),
                        };
                        Session::addMessageAfterRedirect($message, false, INFO);
                        break;

                    case 'save_reminder_settings':
                        $days = $request->request->getInt(
                            'lifecycle_reminder_lookahead_days',
                            -1
                        );
                        if (
                            $days < CredentialLifecycleReminderService::MIN_LOOKAHEAD_DAYS
                            || $days > CredentialLifecycleReminderService::MAX_LOOKAHEAD_DAYS
                        ) {
                            throw new \RuntimeException(__('The reminder period must be between 1 and 365 days.', 'credentials'));
                        }

                        \Config::setConfigurationValues('plugin_credentials', [
                            'lifecycle_reminders_enabled' => $request->request->getBoolean('lifecycle_reminders_enabled') ? 1 : 0,
                            'lifecycle_reminder_lookahead_days' => $days,
                        ]);
                        Session::addMessageAfterRedirect(__('Lifecycle reminder settings were saved.', 'credentials'), false, INFO);
                        break;

                    case 'save_retention':
                        $days = $request->request->getInt('archive_retention_days', 365);
                        if ($days < 0 || $days > 3650) {
                            throw new \RuntimeException(__('Retention must be between 0 and 3,650 days. Use 0 to keep archived credentials indefinitely.', 'credentials'));
                        }
                        \Config::setConfigurationValues('plugin_credentials', [
                            'archive_retention_days' => $days,
                        ]);
                        Session::addMessageAfterRedirect(__('Archive retention settings were saved.', 'credentials'), false, INFO);
                        break;

                    case 'add_category':
                        if (!CredentialCategory::create(
                            $request->request->getString('category_name'),
                            $entity_id,
                            $request->request->getString('category_comment')
                        )) {
                            throw new \RuntimeException(__('The category could not be created. Check its name and make sure it is not already in use.', 'credentials'));
                        }
                        Session::addMessageAfterRedirect(__('Category created successfully.', 'credentials'), false, INFO);
                        break;

                    case 'update_category':
                        if (!CredentialCategory::update(
                            $request->request->getInt('category_id'),
                            $request->request->getString('category_name'),
                            $request->request->getString('category_comment')
                        )) {
                            throw new \RuntimeException(__('The category could not be updated. Check its name, entity access and make sure it is not already in use by another category.', 'credentials'));
                        }
                        Session::addMessageAfterRedirect(__('Category updated successfully.', 'credentials'), false, INFO);
                        break;

                    case 'delete_category':
                        if (!CredentialCategory::deleteIfUnused($request->request->getInt('category_id'))) {
                            throw new \RuntimeException(__('This category cannot be deleted while credentials use it.', 'credentials'));
                        }
                        Session::addMessageAfterRedirect(__('Category deleted successfully.', 'credentials'), false, INFO);
                        break;

                    case 'restore':
                        if (!CredentialAdminService::restore(
                            $request->request->getInt('credential_id'),
                            $request->request->getString('itemtype'),
                            $request->request->getInt('items_id')
                        )) {
                            throw new \RuntimeException(__('The archived credential could not be restored. Select an available item in the same entity and verify your permissions.', 'credentials'));
                        }
                        Session::addMessageAfterRedirect(__('Credential restored and linked successfully.', 'credentials'), false, INFO);
                        break;

                    case 'purge_archived':
                        $settings = \Config::getConfigurationValues('plugin_credentials');
                        $days = (int) ($settings['archive_retention_days'] ?? 365);
                        if ($days <= 0) {
                            throw new \RuntimeException(__('Permanent deletion is disabled while retention is set to keep records indefinitely.', 'credentials'));
                        }
                        $purged = CredentialAdminService::purgeEligible($days);
                        Session::addMessageAfterRedirect(
                            sprintf(__('Permanently deleted %d archived credentials.', 'credentials'), $purged),
                            false,
                            INFO
                        );
                        break;
                }
            } catch (\RuntimeException $exception) {
                Session::addMessageAfterRedirect($exception->getMessage(), false, ERROR);
            } catch (\Throwable) {
                Session::addMessageAfterRedirect(__('The requested administrative action could not be completed. No sensitive information was displayed.', 'credentials'), false, ERROR);
            }

            return $this->redirectToConfig();
        }

        $active_entity = max(0, (int) ($_SESSION['glpiactive_entity'] ?? 0));
        $notification_setup = CredentialLifecycleReminderService::notificationSetupStatus($active_entity);
        $settings = \Config::getConfigurationValues('plugin_credentials');
        $retention_days = max(0, min(3650, (int) ($settings['archive_retention_days'] ?? 365)));
        $reminder_settings = CredentialLifecycleReminderService::getSettings();
        $reminder_task = new \CronTask();
        $reminder_task_registered = $reminder_task->getFromDBbyName(
            CredentialLifecycleReminderService::class,
            CredentialLifecycleReminderService::CRON_NAME
        );
        $reminder_task_enabled = $reminder_task_registered && $reminder_task->isDisabled() === 0;
        $update = UpdateChecker::latest();
        $dashboard = CredentialAdminService::dashboard(
            $active_entity,
            $retention_days
        );

        $item_types = [];
        foreach (CredentialItem::getSupportedItemtypes() as $itemtype) {
            $item_types[] = [
                'value' => $itemtype,
                'label' => $itemtype::getTypeName(1),
            ];
        }

        $response = $this->renderPage($request, [
            'installed_version' => PLUGIN_CREDENTIALS_VERSION,
            'update' => $update,
            'dashboard' => $dashboard,
            'retention_days' => $retention_days,
            'reminders_enabled' => $reminder_settings['enabled'],
            'reminder_lookahead_days' => $reminder_settings['lookahead_days'],
            'glpi_notifications_enabled' => !empty($CFG_GLPI['use_notifications']),
            'reminder_task_registered' => $reminder_task_registered,
            'reminder_task_enabled' => $reminder_task_enabled,
            'notification_setup' => $notification_setup,
            'item_types' => $item_types,
            'backup_available' => CredentialBackupService::isAvailable()
                && Session::haveRight(Credential::RIGHT_BACKUP, READ)
                && Session::haveRight(Credential::$rightname, READ),
            'can_backup' => Session::haveRight(Credential::RIGHT_BACKUP, READ)
                && Session::haveRight(Credential::$rightname, READ),
            'can_import' => Session::haveRight(Credential::RIGHT_BACKUP, READ)
                && Session::haveRight(Credential::$rightname, READ)
                && Session::haveRight(Credential::$rightname, CREATE),
            'can_purge' => Session::haveRight(Credential::$rightname, READ)
                && Session::haveRight(Credential::$rightname, DELETE),
            'encryption_ready' => Credential::isEncryptionReady(),
            'active_entity' => $active_entity,
            'admin_user' => (string) ($_SESSION['glpiname'] ?? ''),
            'config_url' => PLUGIN_CREDENTIALS_WEBDIR . '/config',
            'export_url' => PLUGIN_CREDENTIALS_WEBDIR . '/config/export',
            'import_url' => PLUGIN_CREDENTIALS_WEBDIR . '/config/import',
        ]);

        return $response;
    }

    #[Route('/config/export', name: 'credentials_config_export', methods: ['POST'])]
    public function export(Request $request): Response
    {
        Session::checkLoginUser();
        Session::checkRight('config', UPDATE);
        Session::checkRight(Credential::RIGHT_BACKUP, READ);
        Security::requireReauthentication();

        $passphrase = $request->request->getString('backup_password');
        $confirmation = $request->request->getString('backup_password_confirm');
        if (!hash_equals($passphrase, $confirmation)) {
            Session::addMessageAfterRedirect(__('The backup passwords do not match.', 'credentials'), false, ERROR);
            return $this->redirectToConfig();
        }

        try {
            $contents = CredentialBackupService::createBackup(
                $passphrase,
                $request->request->getBoolean('include_archived')
            );
            $response = new Response($contents, Response::HTTP_OK, [
                'Content-Type' => 'application/vnd.credentials.backup+json',
                'Content-Disposition' => 'attachment; filename="credentials-backup-' . date('Y-m-d_H-i-s') . '.credbackup"',
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
                'Referrer-Policy' => 'no-referrer',
            ]);
            return $response;
        } catch (\RuntimeException $exception) {
            Session::addMessageAfterRedirect($exception->getMessage(), false, ERROR);
        } catch (\Throwable) {
            Session::addMessageAfterRedirect(__('The backup could not be created. No file was downloaded.', 'credentials'), false, ERROR);
        }

        return $this->redirectToConfig();
    }

    #[Route('/config/import', name: 'credentials_config_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        Session::checkLoginUser();
        Session::checkRight('config', UPDATE);
        Session::checkRight(Credential::RIGHT_BACKUP, READ);
        Session::checkRight(Credential::$rightname, CREATE);
        Security::requireReauthentication();

        $file = $request->files->get('backup_file');
        if ($file === null || !$file->isValid() || $file->getSize() > 20_000_000) {
            Session::addMessageAfterRedirect(__('Select a valid Credentials backup file smaller than 20 MB.', 'credentials'), false, ERROR);
            return $this->redirectToConfig();
        }

        $contents = @file_get_contents($file->getPathname());
        if (!is_string($contents)) {
            Session::addMessageAfterRedirect(__('The backup file could not be read.', 'credentials'), false, ERROR);
            return $this->redirectToConfig();
        }

        try {
            $result = CredentialBackupService::importBackup(
                $contents,
                $request->request->getString('backup_password')
            );
            Session::addMessageAfterRedirect(
                sprintf(
                    __('Import completed: %1$d credentials created, %2$d archived, %3$d needing manual relinking, %4$d skipped.', 'credentials'),
                    $result['imported'],
                    $result['archived'],
                    $result['needs_relink'],
                    $result['skipped']
                ),
                false,
                ($result['skipped'] > 0 || $result['needs_relink'] > 0) ? WARNING : INFO
            );
        } catch (\RuntimeException $exception) {
            Session::addMessageAfterRedirect($exception->getMessage(), false, ERROR);
        } catch (\Throwable) {
            Session::addMessageAfterRedirect(__('The backup could not be imported. No changes were retained.', 'credentials'), false, ERROR);
        }

        return $this->redirectToConfig();
    }

    private function renderPage(Request $request, array $variables): Response
    {
        $buffer_level = ob_get_level();
        ob_start();
        try {
            Html::header(__('Credentials configuration', 'credentials'), $request->getPathInfo(), 'config', 'plugins');
            \Glpi\Application\View\TemplateRenderer::getInstance()->display('@credentials/config.html.twig', $variables);
            Html::footer();
            $content = (string) ob_get_clean();
        } catch (\Throwable $exception) {
            while (ob_get_level() > $buffer_level) {
                ob_end_clean();
            }
            throw $exception;
        }

        return new Response($content, Response::HTTP_OK, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function redirectToConfig(): RedirectResponse
    {
        return new RedirectResponse(PLUGIN_CREDENTIALS_WEBDIR . '/config', Response::HTTP_SEE_OTHER, [
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
