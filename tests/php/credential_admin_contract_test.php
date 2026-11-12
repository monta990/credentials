<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$setup = file_get_contents($root . '/setup.php');
$hook = file_get_contents($root . '/hook.php');
$controller = file_get_contents($root . '/src/Controller/ConfigController.php');
$admin = file_get_contents($root . '/src/Service/CredentialAdminService.php');
$category = file_get_contents($root . '/src/CredentialCategory.php');
$credential = file_get_contents($root . '/src/Credential.php');
$profile = file_get_contents($root . '/src/Profile.php');
$checks = [
    'GLPI configuration page hook' => str_contains($setup, "Hooks::CONFIG_PAGE]['credentials'] = 'config'"),
    'Administrative page route' => str_contains($controller, "Route('/config'"),
    'Dedicated encrypted backup permission' => str_contains($credential, "RIGHT_BACKUP = 'plugin_credentials_backup'"),
    'Backup permission exposed in profiles' => str_contains($profile, 'Credential::RIGHT_BACKUP'),
    'Category table lifecycle' => str_contains($hook, 'glpi_plugin_credentials_categories'),
    'Expiration fields migration' => str_contains($hook, "'date_expires'"),
    'Review fields migration' => str_contains($hook, "'date_next_review'"),
    'Review responsible user field' => str_contains($hook, "'reviewer_users_id'"),
    'Category management' => str_contains($category, 'public static function create('),
    'Unused category protection' => str_contains($category, 'public static function deleteIfUnused('),
    'Lifecycle summary' => str_contains($admin, "'review_overdue'"),
    'Archived credential restoration' => str_contains($admin, 'public static function restore('),
    'Restore uses a current locking read to check existing links' => str_contains($admin, 'CredentialItemLink::getLinksForCredentialForUpdate($credential_id) !== []'),
    'Retention-based permanent deletion' => str_contains($admin, 'public static function purgeEligible('),
    'Permanent deletion requires credential READ and DELETE rights' => str_contains($admin, 'Session::haveRight(Credential::$rightname, DELETE)'),
    'Credential details are hidden from profiles without credential READ' => str_contains($admin, '$can_read_credentials ? self::recentAuditEvents(50) : []'),
    'Dashboard does not return individual rows without credential READ' => str_contains($admin, '$can_read_credentials && $status') && str_contains($admin, '$can_read_credentials && count($archived) < 25') && str_contains($admin, '$can_read_credentials ? self::recentAuditEvents(50) : []'),
    'Archived records must be unlinked before permanent deletion' => str_contains($admin, 'getLinksForCredentialForUpdate($id) !== []'),
    'GitHub update checker' => is_file($root . '/src/Service/UpdateChecker.php'),
    'Dashboard template' => is_file($root . '/templates/config.html.twig'),
];
foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo "Credential administration contract tests: OK\n";
