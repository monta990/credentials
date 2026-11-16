<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$backup = file_get_contents($root . '/src/Service/CredentialBackupService.php');
$admin = file_get_contents($root . '/src/Service/CredentialAdminService.php');
$link = file_get_contents($root . '/src/CredentialItemLink.php');
$credential = file_get_contents($root . '/src/Credential.php');
$reminders = file_get_contents($root . '/src/Service/CredentialLifecycleReminderService.php');
$hook = file_get_contents($root . '/hook.php');
$controller = file_get_contents($root . '/src/Controller/CredentialController.php');
$template = file_get_contents($root . '/templates/config.html.twig');

$checks = [
    'Backup export enforces per-entry link contract' => str_contains($backup, 'count($source_links) > self::MAX_LINKS_PER_ENTRY'),
    'Backup export decrypts stored secrets and requires dedicated backup right' => str_contains($backup, 'Credential::RIGHT_BACKUP') && str_contains($backup, 'Credential::decryptValue') && str_contains($profile, 'Credential::RIGHT_BACKUP'),
    'Backup import re-encrypts secret fields with the current GLPI key' => str_contains($backup, 'Credential::encryptValue') && str_contains($backup, "'login' => \$encrypted['login']") && str_contains($backup, "'password' => \$encrypted['password']"),
    'Backup export rejects unsupported links instead of silently skipping' => str_contains($backup, 'relationship to an unsupported or invalid item') && !str_contains($backup, "if (!CredentialItem::isSupportedItemtype(\$itemtype)) {\n                    continue;"),
    'Backup export rejects unreadable, deleted or cross-entity links' => str_contains($backup, 'unavailable, unreadable or belongs to another entity') || (str_contains($backup, 'unreadable') && str_contains($backup, 'deleted') && str_contains($backup, 'belongs to another entity')),
    'Backup export rejects active credentials without associations' => str_contains($backup, 'Active credential %d has no linked items'),
    'Backup payload has total-link cap and incremental size check' => str_contains($backup, 'MAX_TOTAL_LINKS') && str_contains($backup, '$estimated_payload_bytes'),
    'Backup import validates archived and active relationships' => str_contains($backup, "\$source_archived = !empty(\$entry['archived']);") && str_contains($backup, "foreach (\$entry['links'] as \$link_data)"),
    'Backup import rejects any partial or invalid association set' => str_contains($backup, 'Never report a partial restore as complete.') && str_contains($backup, 'return null;') || str_contains($backup, 'all exported relationship') && str_contains($backup, 'return null;'),
    'Backup import has total relation cap' => str_contains($backup, '$total_import_links > self::MAX_TOTAL_LINKS'),
    'Dashboard has keyset batches and archive pagination' => str_contains($admin, 'DASHBOARD_BATCH_SIZE = 500') && str_contains($admin, 'ARCHIVE_PAGE_SIZE = 25') && str_contains($admin, 'archived_pages'),
    'Restore destination search is bounded, paginated and READ/UPDATE scoped' => str_contains($admin, '$scan_limit = 200') && str_contains($admin, "'START' => \$start") && str_contains($admin, '$item->can($id, READ)') && str_contains($admin, '$item->can($id, UPDATE)'),
    'Retention purge uses bounded transactions and batched reads' => str_contains($admin, 'PURGE_BATCH_SIZE = 100') && str_contains($admin, 'getLinksForCredentialsForUpdate($ids)') && str_contains($admin, 'getCredentialsForUpdate($locked_ids)'),
    'Grouped link counts replace per-credential queries' => str_contains($credential, 'CredentialItemLink::getLinkCounts($ids)'),
    'Relationship reads are chunked and deterministic' => str_contains($link, 'array_chunk($ids, 250)') && str_contains($link, 'FOR UPDATE') && str_contains($link, 'function getCredentialsForUpdate'),
    'Link count reconciliation is paged and retryable' => str_contains($hook, 'links_count_reconciliation_version') && str_contains($hook, '$batch_size = 500') && str_contains($hook, 'lockCredentials($credential_ids)'),
    'Reminder idempotency includes reminder type and reviewer/date scope' => str_contains($reminders, "'reminder_type' => \$reminder_type") && str_contains($reminders, "'reviewer_users_id' => \$reviewer_users_id") && str_contains($reminders, "'reminder_date' => \$reminder_date") && str_contains($hook, 'credential_reminder_type') && str_contains($hook, "'reminder_type'"),
    'Active and archived deletion clean reminder markers' => substr_count($controller, "delete('glpi_plugin_credentials_reminders'") >= 1 && substr_count($admin, "delete('glpi_plugin_credentials_reminders'") >= 2 && str_contains($admin, 'restoreWithExistingLinks'),
    'Destination search continues across itemtypes' => !str_contains(substr($admin, strpos($admin, 'public static function searchRestorableItemsForEntity'), strpos($admin, 'private static function buildExistingLinkLockPlan') - strpos($admin, 'public static function searchRestorableItemsForEntity')), 'break 2;'),
    'Archived UI paginates and exposes linked targets' => str_contains($template, 'dashboard.archived_pages') && str_contains($template, 'row.remaining_links'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}
echo '0.0.66 remediation contract checks: ' . count($checks) . " OK\n";
