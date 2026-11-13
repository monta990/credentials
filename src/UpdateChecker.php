<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials;

/** Checks the latest published stable GitHub release and caches it for six hours. */
final class UpdateChecker
{
    private const RELEASES_URL = 'https://api.github.com/repos/monta990/credentials/releases/latest';
    private const RELEASES_PAGE = 'https://github.com/monta990/credentials/releases';
    private const CACHE_TTL = 21600;

    /** @return array{status:string,installed:string,latest:?string,url:string,published_at:?string,checked_at:?string} */
    public static function check(bool $force = false): array
    {
        $installed = defined('PLUGIN_CREDENTIALS_VERSION') ? PLUGIN_CREDENTIALS_VERSION : '0.0.0';
        $cached = self::readCache();
        $stale = is_array($cached) ? $cached : null;

        if (
            !$force
            && $stale !== null
            && isset($stale['checked_at'])
            && (time() - (int) $stale['checked_at']) < self::CACHE_TTL
        ) {
            return self::result($installed, $stale);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/vnd.github+json\r\nUser-Agent: GLPI-Credentials-Plugin\r\nX-GitHub-Api-Version: 2022-11-28\r\n",
                'timeout' => 4,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents(self::RELEASES_URL, false, $context, 0, 65536);
        $httpStatus = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $matches)) {
                $httpStatus = (int) $matches[1];
            }
        }

        if ($httpStatus === 404) {
            $fresh = [
                'version' => null,
                'url' => self::RELEASES_PAGE,
                'published_at' => null,
                'checked_at' => time(),
                'state' => 'no_release',
            ];
            self::writeCache($fresh);
            return self::result($installed, $fresh);
        }

        if (!is_string($body) || $httpStatus !== 200) {
            if ($stale !== null) {
                $stale['state'] = 'stale';
                return self::result($installed, $stale, true);
            }
            return [
                'status' => 'unavailable',
                'installed' => $installed,
                'latest' => null,
                'url' => self::RELEASES_PAGE,
                'published_at' => null,
                'checked_at' => null,
            ];
        }

        $release = json_decode($body, true);
        if (
            !is_array($release)
            || !empty($release['draft'])
            || !empty($release['prerelease'])
            || !isset($release['tag_name'])
        ) {
            if ($stale !== null) {
                return self::result($installed, $stale, true);
            }
            return [
                'status' => 'unavailable',
                'installed' => $installed,
                'latest' => null,
                'url' => self::RELEASES_PAGE,
                'published_at' => null,
                'checked_at' => null,
            ];
        }

        $version = preg_replace('/^[vV]/', '', trim((string) $release['tag_name']));
        if (!is_string($version) || !preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $version)) {
            return [
                'status' => 'unavailable',
                'installed' => $installed,
                'latest' => null,
                'url' => self::RELEASES_PAGE,
                'published_at' => null,
                'checked_at' => null,
            ];
        }

        $fresh = [
            'version' => $version,
            'url' => filter_var($release['html_url'] ?? '', FILTER_VALIDATE_URL) ? (string) $release['html_url'] : self::RELEASES_PAGE,
            'published_at' => isset($release['published_at']) ? (string) $release['published_at'] : null,
            'checked_at' => time(),
            'state' => 'release',
        ];
        self::writeCache($fresh);
        return self::result($installed, $fresh);
    }

    /** @return array<string, mixed>|null */
    private static function readCache(): ?array
    {
        $path = self::cachePath();
        if ($path === null || !is_file($path) || !is_readable($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data) || !isset($data['checked_at']) || !array_key_exists('version', $data)) {
            return null;
        }
        if ($data['version'] !== null && !preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', (string) $data['version'])) {
            return null;
        }
        return $data;
    }

    /** @param array<string, mixed> $data */
    private static function writeCache(array $data): void
    {
        $path = self::cachePath();
        if ($path === null) {
            return;
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            return;
        }
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temporary, $json, LOCK_EX) !== false) {
            @chmod($temporary, 0640);
            if (!@rename($temporary, $path)) {
                @unlink($temporary);
            }
        }
    }

    private static function cachePath(): ?string
    {
        if (defined('GLPI_PLUGIN_DOC_DIR')) {
            return rtrim(GLPI_PLUGIN_DOC_DIR, '/\') . '/credentials/release_cache.json';
        }
        if (defined('GLPI_ROOT')) {
            return rtrim(GLPI_ROOT, '/\') . '/files/_plugins/credentials/release_cache.json';
        }
        return null;
    }

    /** @param array<string, mixed> $data */
    private static function result(string $installed, array $data, bool $stale = false): array
    {
        $latest = isset($data['version']) && is_string($data['version']) ? $data['version'] : null;
        $state = (string) ($data['state'] ?? '');
        $status = match (true) {
            $state === 'no_release' => 'no_release',
            $latest === null => 'unavailable',
            $stale => 'stale',
            version_compare($latest, $installed, '>') => 'update_available',
            default => 'up_to_date',
        };
        return [
            'status' => $status,
            'installed' => $installed,
            'latest' => $latest,
            'url' => isset($data['url']) && is_string($data['url']) ? $data['url'] : self::RELEASES_PAGE,
            'published_at' => isset($data['published_at']) && is_string($data['published_at']) ? $data['published_at'] : null,
            'checked_at' => isset($data['checked_at']) ? gmdate('c', (int) $data['checked_at']) : null,
        ];
    }
}
