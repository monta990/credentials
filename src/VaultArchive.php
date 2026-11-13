<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials;

/**
 * Password-encrypted portable archive. The archive password is independent
 * from GLPIKey, so a backup can be imported into another GLPI instance.
 */
final class VaultArchive
{
    private const FORMAT = 'credentials-vault';
    private const FORMAT_VERSION = 1;
    private const MAX_PLAINTEXT_BYTES = 14_000_000;
    private const MAX_ARCHIVE_BYTES = 20_000_000;

    public static function encrypt(array $payload, string $passphrase): string
    {
        self::assertPassphrase($passphrase);
        if (!extension_loaded('sodium')) {
            throw new \RuntimeException('The sodium extension is required for encrypted credential backups.');
        }

        $plaintext = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        if (strlen($plaintext) > self::MAX_PLAINTEXT_BYTES) {
            throw new \RuntimeException('The credential backup is too large.');
        }

        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $opslimit = SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;
        $memlimit = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;
        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $passphrase,
            $salt,
            $opslimit,
            $memlimit,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );

        try {
            $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        } finally {
            sodium_memzero($key);
        }

        return json_encode([
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'cipher' => 'sodium-secretbox',
            'kdf' => 'argon2id13',
            'opslimit' => $opslimit,
            'memlimit' => $memlimit,
            'salt' => base64_encode($salt),
            'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($ciphertext),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    public static function decrypt(string $archive, string $passphrase): array
    {
        self::assertPassphrase($passphrase);
        if (!extension_loaded('sodium') || $archive === '' || strlen($archive) > self::MAX_ARCHIVE_BYTES) {
            throw new \RuntimeException('The selected credential backup is invalid.');
        }

        try {
            $envelope = json_decode($archive, true, 32, JSON_THROW_ON_ERROR);
            if (
                !is_array($envelope)
                || ($envelope['format'] ?? null) !== self::FORMAT
                || (int) ($envelope['format_version'] ?? 0) !== self::FORMAT_VERSION
                || ($envelope['cipher'] ?? null) !== 'sodium-secretbox'
                || ($envelope['kdf'] ?? null) !== 'argon2id13'
                || (int) ($envelope['opslimit'] ?? 0) !== SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE
                || (int) ($envelope['memlimit'] ?? 0) !== SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
            ) {
                throw new \RuntimeException('The selected credential backup is not supported.');
            }

            $salt = base64_decode((string) ($envelope['salt'] ?? ''), true);
            $nonce = base64_decode((string) ($envelope['nonce'] ?? ''), true);
            $ciphertext = base64_decode((string) ($envelope['ciphertext'] ?? ''), true);
            if (
                !is_string($salt) || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES
                || !is_string($nonce) || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
                || !is_string($ciphertext) || strlen($ciphertext) < SODIUM_CRYPTO_SECRETBOX_MACBYTES
            ) {
                throw new \RuntimeException('The selected credential backup is invalid or corrupted.');
            }

            $key = sodium_crypto_pwhash(
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                $passphrase,
                $salt,
                SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
                SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
                SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
            );
            try {
                $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
            } finally {
                sodium_memzero($key);
            }

            if (!is_string($plaintext)) {
                throw new \RuntimeException('The backup password is incorrect or the file was modified.');
            }

            $payload = json_decode($plaintext, true, 64, JSON_THROW_ON_ERROR);
            sodium_memzero($plaintext);
            if (!is_array($payload) || ($payload['format'] ?? null) !== self::FORMAT || (int) ($payload['format_version'] ?? 0) !== self::FORMAT_VERSION) {
                throw new \RuntimeException('The credential backup contents are not supported.');
            }

            return $payload;
        } catch (\JsonException $exception) {
            throw new \RuntimeException('The selected credential backup is invalid or corrupted.', 0, $exception);
        }
    }

    private static function assertPassphrase(string $passphrase): void
    {
        if (strlen($passphrase) < 12 || strlen($passphrase) > 1024) {
            throw new \InvalidArgumentException('Use a backup password between 12 and 1024 bytes.');
        }
    }
}
