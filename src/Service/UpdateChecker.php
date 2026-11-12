<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials\Service;

use GlpiPlugin\Credentials\Paths;

/** Checks GitHub's latest stable release without making plugin use depend on GitHub. */
final class UpdateChecker
{
    private const RELEASES_API = 'https://api.github.com/repos/monta990/credentials/releases/latest';
    private const RELEASES_URL = 'https://github.com/monta990/credentials/releases';
    private const TTL = 21600;

    /** @return array{status:string,latest:?string,url:string,notes:string,published_at:?string,checked_at:?int,stale:bool} */
    public static function check(bool $force = false): array
    {
        $cache_dir = Paths::filesDir();
        $cache_file = $cache_dir . '/release_cache.json';
        $cached = null;
        $stale = false;

        if (is_file($cache_file) && is_readable($cache_file)) {
            $decoded = json_decode((string) @file_get_contents($cache_file), true);
            if (is_array($decoded)) {
                $cached = $decoded;
                $checked_at = (int) ($decoded['checked_at'] ?? 0);
                if (!$force && $checked_at > 0 && (time() - $checked_at) < self::TTL) {
                    return self::normalizeCached($decoded);
                }
            }
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/vnd.github+json\r\nUser-Agent: GLPI-Credentials-UpdateChecker\r\nX-GitHub-Api-Version: 2022-11-28\r\n",
                'timeout' => 4,
                'ignore_errors' => true,
            ],
        ]);

        $json = @file_get_contents(self::RELEASES_API, false, $context, 0, 131072);
        $status_code = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('#^HTTP/\\S+\\s+(\\d{3})#i', $header, $match)) {
                $status_code = (int) $match[1];
            }
        }

        if ($json === false || $status_code === 0 || $status_code >= 500 || $status_code === 403 || $status_code === 429) {
            $result = self::normalizeCached($cached ?? []);
            $result['status'] = 'unavailable';
            $result['stale'] = $result['latest'] !== null;
            return $result;
        }

        if ($status_code === 404) {
            $result = [
                'status' => 'no_releases',
                'latest' => null,
                'url' => self::RELEASES_URL,
                'notes' => '',
                'published_at' => null,
                'checked_at' => time(),
                'stale' => false,
            ];
            self::writeCache($cache_dir, $cache_file, $result);
            return $result;
        }

        $data = json_decode($json, true);
        if (
            $status_code !== 200
            || !is_array($data)
            || empty($data['tag_name'])
            || !empty($data['draft'])
            || !empty($data['prerelease'])
        ) {
            $result = self::normalizeCached($cached ?? []);
            $result['status'] = 'unavailable';
            $result['stale'] = $result['latest'] !== null;
            return $result;
        }

        $version = preg_replace('/^[vV]/', '', trim((string) $data['tag_name']));
        if (!is_string($version) || !preg_match('/^\\d+\\.\\d+\\.\\d+$/', $version)) {
            $result = self::normalizeCached($cached ?? []);
            $result['status'] = 'unavailable';
            $result['stale'] = $result['latest'] !== null;
            return $result;
        }

        $result = [
            'status' => 'available',
            'latest' => $version,
            'url' => filter_var($data['html_url'] ?? '', FILTER_VALIDATE_URL) ? (string) $data['html_url'] : self::RELEASES_URL,
            'notes' => mb_substr((string) ($data['body'] ?? ''), 0, 12000),
            'published_at' => isset($data['published_at']) ? (string) $data['published_at'] : null,
            'checked_at' => time(),
            'stale' => false,
        ];
        self::writeCache($cache_dir, $cache_file, $result);

        return $result;
    }

    /** @param array<string, mixed> $cached */
    private static function normalizeCached(array $cached): array
    {
        $version = (string) ($cached['latest'] ?? '');
        if (!preg_match('/^\\d+\\.\\d+\\.\\d+$/', $version)) {
            $version = '';
        }

        return [
            'status' => $version === '' ? 'unavailable' : 'cached',
            'latest' => $version !== '' ? $version : null,
            'url' => filter_var($cached['url'] ?? '', FILTER_VALIDATE_URL) ? (string) $cached['url'] : self::RELEASES_URL,
            'notes' => mb_substr((string) ($cached['notes'] ?? ''), 0, 12000),
            'published_at' => isset($cached['published_at']) ? (string) $cached['published_at'] : null,
            'checked_at' => isset($cached['checked_at']) ? (int) $cached['checked_at'] : null,
            'stale' => true,
        ];
    }

    /** @param array<string, mixed> $result */
    private static function writeCache(string $directory, string $path, array $result): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            return;
        }

        $payload = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            return;
        }

        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temporary, $payload, LOCK_EX) !== false) {
            @chmod($temporary, 0640);
            if (!@rename($temporary, $path)) {
                @unlink($temporary);
            }
        }
    }
}
