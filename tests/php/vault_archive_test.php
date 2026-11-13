<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/VaultArchive.php';

use GlpiPlugin\Credentials\VaultArchive;

function vaultAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if (!extension_loaded('sodium')) {
    throw new RuntimeException('The sodium extension is required for vault archive tests.');
}

$secret = 'production-database-password-should-never-appear-in-plaintext';
$payload = [
    'format' => 'credentials-vault',
    'format_version' => 1,
    'records' => [[
        'name' => 'Database administrator',
        'login' => 'db-admin',
        'password' => $secret,
        'url' => 'https://db.example.invalid',
        'notes' => 'restricted production access',
    ]],
];

$password = 'a-strong-backup-password-2026';
$archive = VaultArchive::encrypt($payload, $password);
vaultAssert(!str_contains($archive, $secret), 'The exported envelope must not contain protected values in plaintext.');

$restored = VaultArchive::decrypt($archive, $password);
vaultAssert($restored === $payload, 'The backup must round-trip its payload with the correct password.');

$wrongPasswordRejected = false;
try {
    VaultArchive::decrypt($archive, 'a-different-backup-password');
} catch (RuntimeException) {
    $wrongPasswordRejected = true;
}
vaultAssert($wrongPasswordRejected, 'A wrong backup password must be rejected.');

$envelope = json_decode($archive, true, 32, JSON_THROW_ON_ERROR);
$ciphertext = base64_decode((string) $envelope['ciphertext'], true);
vaultAssert(is_string($ciphertext), 'The archive ciphertext must be valid base64.');
$envelope['ciphertext'] = base64_encode(str_repeat("\xA5", strlen($ciphertext)));
$tampered = json_encode($envelope, JSON_THROW_ON_ERROR);
$tamperedRejected = false;
try {
    VaultArchive::decrypt($tampered, $password);
} catch (RuntimeException) {
    $tamperedRejected = true;
}
vaultAssert($tamperedRejected, 'A modified archive must fail authenticated decryption.');

$shortPasswordRejected = false;
try {
    VaultArchive::encrypt($payload, 'short');
} catch (InvalidArgumentException) {
    $shortPasswordRejected = true;
}
vaultAssert($shortPasswordRejected, 'Backup passwords shorter than 12 bytes must be rejected.');

$oversizedArchiveRejected = false;
try {
    VaultArchive::decrypt(str_repeat('x', 20_000_001), $password);
} catch (RuntimeException) {
    $oversizedArchiveRejected = true;
}
vaultAssert($oversizedArchiveRejected, 'Oversized backup files must be rejected before JSON parsing.');

echo "Encrypted credential archive regression tests: OK\n";
