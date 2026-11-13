<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$setup = file_get_contents($root . '/setup.php');
$controller = file_get_contents($root . '/src/Controller/AdminController.php');
$template = file_get_contents($root . '/templates/config.html.twig');
$vault = file_get_contents($root . '/src/VaultArchive.php');
$checker = file_get_contents($root . '/src/UpdateChecker.php');
$hook = file_get_contents($root . '/hook.php');

foreach ([
    'setup' => $setup,
    'controller' => $controller,
    'template' => $template,
    'vault' => $vault,
    'checker' => $checker,
    'hook' => $hook,
] as $name => $source) {
    if (!is_string($source)) {
        throw new RuntimeException("Unable to read {$name} contract source.");
    }
}

$contracts = [
    str_contains($setup, "Hooks::CONFIG_PAGE]['credentials'] = 'config'") => 'The plugin must register a dedicated GLPI configuration page.',
    str_contains($controller, "Session::checkRight('config', UPDATE)") => 'Administration must require GLPI configuration rights.',
    str_contains($controller, 'Session::checkRight(Credential::RIGHT_REVEAL, READ)') => 'Credential export must require the independent reveal right.',
    str_contains($controller, 'Security::requireReauthentication()') => 'Sensitive administrative mutations must require native re-authentication.',
    str_contains($controller, 'VaultArchive::encrypt(') && str_contains($controller, 'VaultArchive::decrypt(') => 'Export/import must use the authenticated encrypted archive.',
    str_contains($controller, 'Credential::encryptValue($value)') => 'Imported secret values must be re-encrypted with the destination GLPIKey.',
    str_contains($controller, 'MAX_IMPORT_BYTES') && str_contains($controller, 'MAX_IMPORT_RECORDS') => 'Import must enforce upload and record count limits.',
    str_contains($controller, 'CredentialHistory::recordForCredential($credentialId, Credential::HISTORY_EXPORTED)') => 'Exports must be recorded in secret-free credential history.',
    str_contains($controller, 'Credential::HISTORY_IMPORTED') && str_contains($controller, 'Credential::HISTORY_RESTORED') && str_contains($controller, 'Credential::HISTORY_PURGED') => 'Import, restore and permanent purge must be auditable.',
    str_contains($controller, 'CredentialItemLink::lockCredential($id') => 'Archive restore/purge must serialize changes through the credential row lock.',
    str_contains($template, 'name="backup_password_confirm"') && str_contains($template, 'name="archive"') => 'The administration page must expose encrypted export and import forms.',
    str_contains($template, 'data-credentials-confirm=') => 'Permanent archive purge must use the plugin confirmation handler.',
    str_contains($vault, 'SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13') && str_contains($vault, 'sodium_crypto_secretbox_open') => 'Vault backups must use Argon2id and authenticated decryption.',
    str_contains($checker, '/releases/latest') && str_contains($checker, 'CACHE_TTL = 21600') => 'GitHub release checks must query the latest stable release and cache six hours.',
    str_contains($hook, "'review_due_at'") && str_contains($hook, "'reviewer_users_id'") => 'The database upgrade must add lifecycle metadata.',
];

foreach ($contracts as $condition => $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if (str_contains($controller, 'reviewed_by_users_id') || str_contains($controller, '$rowIds')) {
    throw new RuntimeException('Administration must not write unknown reviewer columns or use uninitialized export IDs.');
}

echo "Credentials administration and archive contract tests: OK\n";
