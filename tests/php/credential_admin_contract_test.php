<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$setup = file_get_contents($root . '/setup.php');
$hook = file_get_contents($root . '/hook.php');
$controller = file_get_contents($root . '/src/Controller/ConfigController.php');
$controller_source = file_get_contents($root . '/src/Controller/CredentialController.php');
$admin = file_get_contents($root . '/src/Service/CredentialAdminService.php');
$category = file_get_contents($root . '/src/CredentialCategory.php');
$credential = file_get_contents($root . '/src/Credential.php');
$profile = file_get_contents($root . '/src/Profile.php');
$reminder_service = file_get_contents($root . '/src/Service/CredentialLifecycleReminderService.php');
$reminder_target = file_get_contents($root . '/src/NotificationTargetCredential.php');
$checks = [
    'GLPI configuration page hook' => str_contains($setup, "Hooks::CONFIG_PAGE]['credentials'] = 'config'"),
    'Credential is registered for notification templates' => str_contains($setup, "Plugin::registerClass(Credential::class, ['notificationtemplates_types' => true])"),
    'Daily lifecycle reminder automatic action is registered and unregistered' => str_contains($hook, 'CronTask::register(') && str_contains($hook, "CronTask::unregister('credentials')") && str_contains($hook, 'CredentialLifecycleReminderService::CRON_NAME'),
    'Lifecycle reminder notification target is discoverable' => str_contains($reminder_target, 'class NotificationTargetCredential extends NotificationTarget') && str_contains($reminder_target, "CredentialLifecycleReminderService::EVENT"),
    'Lifecycle notification is addressed to assigned reviewer' => str_contains($reminder_target, "addUserByField('reviewer_users_id')") && str_contains($reminder_service, 'getDefaultEmail()'),
    'Lifecycle reminders use GLPI native notification queue' => str_contains($reminder_service, 'NotificationEvent::raiseEvent('),
    'Notification diagnostics inspect email templates independently of enabled global modes' => str_contains($reminder_service, 'Notification_NotificationTemplate::MODE_MAIL') && str_contains($reminder_service, "'LEFT JOIN' =>") && str_contains($reminder_service, "'notifications_mailing'") && str_contains($reminder_service, 'getEntitiesRestrictCriteria'),
    'Reminder marker has unique credential/entity/date/reviewer key' => str_contains($hook, 'credential_reminder') && str_contains($hook, '`reminder_date`, `reviewer_users_id`)'),
    'Reminder markers are removed when credentials are permanently purged' => str_contains($admin, "'glpi_plugin_credentials_reminders'"),
    'Administrative page route' => str_contains($controller, "Route('/config'"),
    'Dedicated encrypted backup permission' => str_contains($credential, "RIGHT_BACKUP = 'plugin_credentials_backup'"),
    'Backup permission exposed in profiles' => str_contains($profile, 'Credential::RIGHT_BACKUP'),
    'Category table lifecycle' => str_contains($hook, 'glpi_plugin_credentials_categories'),
    'Expiration fields migration' => str_contains($hook, "'date_expires'"),
    'Review fields migration' => str_contains($hook, "'date_next_review'"),
    'Review responsible user field' => str_contains($hook, "'reviewer_users_id'"),
    'Category management' => str_contains($category, 'public static function create('),
    'Category writes serialize duplicate-name checks with plugin-owned entity locks' => str_contains($category, 'withEntityLock(')
        && str_contains($category, "'glpi_plugin_credentials_entity_locks'")
        && str_contains($category, "'lock_token' => bin2hex(random_bytes(16))")
        && str_contains($category, '$DB->insert($lock_table, [')
        && str_contains($category, '$DB->update($lock_table, [')
        && str_contains($category, '$DB->getAffectedRows() !== 1')
        && !str_contains($category, 'glpi_entities')
        && !str_contains($category, '$DB->doQuery('),
    'Unused category protection' => str_contains($category, 'public static function deleteIfUnused('),
    'Lifecycle summary' => str_contains($admin, "'review_overdue'"),
    'Archived credential restoration' => str_contains($admin, 'public static function restore('),
    'Restore locks the relationship set before checking that the credential is unlinked' => str_contains($admin, '$locked_links = CredentialItemLink::getLinksForCredentialForUpdate($credential_id);') && str_contains($admin, '|| $locked_links !== []'),
    'Retention-based permanent deletion' => str_contains($admin, 'public static function purgeEligible('),
    'Permanent deletion requires credential READ and DELETE rights' => str_contains($admin, 'Session::haveRight(Credential::$rightname, DELETE)'),
    'Credential details are hidden from profiles without credential READ' => str_contains($admin, 'if ($can_read_credentials)') && str_contains($admin, '$visible_archive_records[] = $record') && str_contains($admin, "if (\$can_read_credentials && \$status['key'] !== 'current' && count(\$attention) < self::ARCHIVE_PAGE_SIZE)"),
    'Dashboard does not return individual rows without credential READ' => str_contains($admin, '$can_read_credentials && $status') && str_contains($admin, 'if ($can_read_credentials) {') && str_contains($admin, "'archived' => \$archived") && str_contains($admin, "'attention' => \$attention"),
    'Retention purge uses prelocked relationship sets before credential rows' => str_contains($admin, '$ids = array_column($candidates, 0)') && str_contains($admin, 'getLinksForCredentialsForUpdate($ids)') && str_contains($admin, 'lockCredentials($ids)') && str_contains($admin, 'getCredentialsForUpdate($locked_ids)') && str_contains($admin, 'if (($locked_links[$id] ?? []) !== [])'),
    'GitHub update checker' => is_file($root . '/src/Service/UpdateChecker.php'),
    'Reviewer selector filters entities before loading all available users' => str_contains($controller_source, "'entities_id' => \$visible_entities") && !str_contains($controller_source, "'LIMIT' => 250"),
    'Dashboard template' => is_file($root . '/templates/config.html.twig'),
];
foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo "Credential administration contract tests: OK\n";
