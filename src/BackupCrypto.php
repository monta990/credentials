<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials;

/** Password-based authenticated encryption for portable credential backup files. */
final class BackupCrypto
{
    private const ITERATIONS = 600000;
    private const CIPHER = 'aes-256-gcm';
    private const AAD = 'glpi-credentials-backup-v1';

    /** @return array<string, string|int> */
    public static function encrypt(string $plaintext, string $passphrase): array
    {
        self::validatePassphrase($passphrase);
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('OpenSSL is required to create an encrypted backup.');
        }

        $salt = random_bytes(16);
        $nonce = random_bytes(12);
        $key = hash_pbkdf2('sha256', $passphrase, $salt, self::ITERATIONS, 32, true);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, self::AAD, 16);
        if (function_exists('sodium_memzero')) {
            sodium_memzero($key);
        }

        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new \RuntimeException('The encrypted backup could not be created.');
        }

        return [
            'format' => 'credentials-encrypted-backup',
            'format_version' => 1,
            'cipher' => self::CIPHER,
            'kdf' => 'PBKDF2-HMAC-SHA256',
            'iterations' => self::ITERATIONS,
            'salt' => base64_encode($salt),
            'nonce' => base64_encode($nonce),
            'tag' => base64_encode($tag),
            'data' => base64_encode($ciphertext),
        ];
    }

    /** @param array<string, mixed> $envelope */
    public static function decrypt(array $envelope, string $passphrase): string
    {
        self::validatePassphrase($passphrase);
        if (
            ($envelope['format'] ?? '') !== 'credentials-encrypted-backup'
            || (int) ($envelope['format_version'] ?? 0) !== 1
            || ($envelope['cipher'] ?? '') !== self::CIPHER
            || ($envelope['kdf'] ?? '') !== 'PBKDF2-HMAC-SHA256'
            || (int) ($envelope['iterations'] ?? 0) !== self::ITERATIONS
        ) {
            throw new \InvalidArgumentException('The selected file is not a supported Credentials backup.');
        }

        $salt = base64_decode((string) ($envelope['salt'] ?? ''), true);
        $nonce = base64_decode((string) ($envelope['nonce'] ?? ''), true);
        $tag = base64_decode((string) ($envelope['tag'] ?? ''), true);
        $ciphertext = base64_decode((string) ($envelope['data'] ?? ''), true);
        if (
            !is_string($salt) || strlen($salt) !== 16
            || !is_string($nonce) || strlen($nonce) !== 12
            || !is_string($tag) || strlen($tag) !== 16
            || !is_string($ciphertext) || strlen($ciphertext) > 20971520
        ) {
            throw new \InvalidArgumentException('The backup file is incomplete or invalid.');
        }

        $key = hash_pbkdf2('sha256', $passphrase, $salt, self::ITERATIONS, 32, true);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, self::AAD);
        if (function_exists('sodium_memzero')) {
            sodium_memzero($key);
        }

        if (!is_string($plaintext)) {
            throw new \InvalidArgumentException('The password is incorrect or the backup has been altered.');
        }

        return $plaintext;
    }

    private static function validatePassphrase(string $passphrase): void
    {
        $length = mb_strlen($passphrase);
        if ($length < 12 || $length > 256) {
            throw new \InvalidArgumentException('The backup password must contain between 12 and 256 characters.');
        }
    }
}
