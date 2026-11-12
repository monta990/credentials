<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$backup = file_get_contents($root . '/src/Service/CredentialBackupService.php');
$controller = file_get_contents($root . '/src/Controller/ConfigController.php');
$checks = [
    'Password-based Argon2id key derivation' => str_contains($backup, 'SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13'),
    'Authenticated XChaCha20-Poly1305 encryption' => str_contains($backup, 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt'),
    'Authenticated backup decryption' => str_contains($backup, 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt'),
    'Backup key memory clearing' => substr_count($backup, 'sodium_memzero($key)') >= 2,
    'Minimum backup passphrase length' => str_contains($backup, 'mb_strlen($passphrase) < 12'),
    'Backup file size limit' => str_contains($backup, 'MAX_FILE_BYTES = 20_000_000'),
    'Credential count limit' => str_contains($backup, 'MAX_ENTRIES = 2000'),
    'Protected data is decrypted only in the export process' => str_contains($backup, 'Credential::decryptValue($ciphertext)'),
    'Imported data is encrypted with destination GLPIKey' => str_contains($backup, 'Credential::encryptValue($entry[$field])'),
    'Import creates new credentials rather than updating existing records' => str_contains($backup, '$credential->add([') && !str_contains($backup, '$credential->update('),
    'Destination entity is checked' => str_contains($backup, 'Session::haveAccessToEntity($entities_id)'),
    'Imported review responsible user must be visible in an accessible entity' => str_contains($backup, "Session::haveAccessToEntity((int) ($reviewer['entities_id'] ?? -1))"),
    'Imported item type is allowlisted' => str_contains($backup, 'CredentialItem::isSupportedItemtype($itemtype)'),
    'Imported item must match its exported name' => str_contains($backup, 'trim((string) $item->getName()) !== trim((string) ($candidate[\'item_name\'] ?? \'\'))'),
    'Controller requires a dedicated backup right' => str_contains($controller, 'Session::checkRight(Credential::RIGHT_BACKUP, READ)'),
    'Import checks upload size' => str_contains($controller, '$file->getSize() > 20_000_000'),
    'Backup download uses no-store headers' => str_contains($controller, "'Cache-Control' => 'private, no-store, max-age=0'"),
];
foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo "Credential encrypted backup contract tests: OK\n";
