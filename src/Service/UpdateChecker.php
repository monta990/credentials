<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials\Service;

use Glpi\Toolbox\HttpClient;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/** Checks the latest published stable release without making GitHub a runtime dependency. */
final class UpdateChecker
{
    private const REPOSITORY = 'monta990/credentials';
    private const CACHE_TTL = 21600;

    /** @return array{status:string,version:?string,url:?string,name:?string,published_at:?string,checked_at:?int} */
    public static function latest(bool $enabled = false): array
    {
        if (!$enabled) {
            return self::result(null, 'disabled');
        }

        $cache_file = self::cacheFile();
        $status_cache_file = self::statusCacheFile();
        $cached = self::readCache($cache_file);
        $stale = $cached;

        if ($cached !== null
            && $cached['checked_at'] <= time()
            && (time() - $cached['checked_at']) < self::CACHE_TTL
        ) {
            return self::result($cached, 'cached');
        }
        $no_release_checked_at = self::readStatusCache($status_cache_file, 'no_release');
        if ($no_release_checked_at !== null) {
            return self::noReleaseResult($no_release_checked_at);
        }
        $failed_checked_at = self::readStatusCache($status_cache_file, 'unavailable');
        if ($failed_checked_at !== null) {
            return $stale !== null
                ? self::result($stale, 'unavailable_cached')
                : self::result(null, 'unavailable');
        }

        $url = 'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest';
        $json = false;
        $status_code = 0;
        try {
            // GLPI's HTTP client honors the proxy and egress configuration selected by the administrator.
            $response = (new HttpClient())->request('GET', $url, [
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'GLPI-Credentials-UpdateChecker',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ],
                'timeout' => 5,
                'max_redirects' => 0,
            ]);
            $status_code = $response->getStatusCode();
            if ($status_code === 200) {
                $content_length = $response->getHeaders(false)['content-length'][0] ?? null;
                if ($content_length !== null && (int) $content_length > 65536) {
                    throw new \RuntimeException('GitHub release response exceeds the allowed size.');
                }
                $json = $response->getContent(false);
                if (strlen($json) > 65536) {
                    $json = false;
                }
            }
        } catch (HttpExceptionInterface $exception) {
            try {
                $status_code = $exception->getResponse()->getStatusCode();
            } catch (\Throwable) {
                $status_code = 0;
            }
        } catch (\Throwable) {
            $status_code = 0;
        }

        if ($json === false || $status_code !== 200) {
            if ($status_code === 404) {
                $checked_at = time();
                self::writeCache($status_cache_file, ['status' => 'no_release', 'checked_at' => $checked_at]);
                return self::noReleaseResult($checked_at);
            }
            self::writeCache($status_cache_file, ['status' => 'unavailable', 'checked_at' => time()]);
            return $stale !== null
                ? self::result($stale, 'unavailable_cached')
                : self::result(null, 'unavailable');
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !empty($data['draft']) || !empty($data['prerelease'])) {
            self::writeCache($status_cache_file, ['status' => 'unavailable', 'checked_at' => time()]);
            return $stale !== null
                ? self::result($stale, 'unavailable_cached')
                : self::result(null, 'unavailable');
        }

        $version = preg_replace('/^[vV]/', '', trim((string) ($data['tag_name'] ?? '')));
        if (!is_string($version) || !preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            self::writeCache($status_cache_file, ['status' => 'unavailable', 'checked_at' => time()]);
            return $stale !== null
                ? self::result($stale, 'unavailable_cached')
                : self::result(null, 'unavailable');
        }

        $release = [
            'version' => $version,
            'url' => self::safeGitHubUrl((string) ($data['html_url'] ?? '')),
            'name' => mb_substr(trim((string) ($data['name'] ?? '')), 0, 200),
            'published_at' => self::validDate((string) ($data['published_at'] ?? '')),
            'checked_at' => time(),
        ];
        self::writeCache($cache_file, $release);
        if (is_file($status_cache_file)) {
            @unlink($status_cache_file);
        }

        return self::result($release, 'available');
    }

    /** Return the timestamp for a fresh cached status such as a failed network check. */
    private static function readStatusCache(string $path, string $status): ?int
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data) || ($data['status'] ?? '') !== $status) {
            return null;
        }
        $checked_at = (int) ($data['checked_at'] ?? 0);
        return $checked_at > 0
            && $checked_at <= time()
            && (time() - $checked_at) < self::CACHE_TTL
            ? $checked_at
            : null;
    }

    /** @return array{status:string,version:null,url:null,name:null,published_at:null,checked_at:int} */
    private static function noReleaseResult(int $checked_at): array
    {
        return [
            'status' => 'no_release',
            'version' => null,
            'url' => null,
            'name' => null,
            'published_at' => null,
            'checked_at' => $checked_at,
        ];
    }

    /** @return array{version:string,url:?string,name:?string,published_at:?string,checked_at:int}|null */
    private static function readCache(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data)
            || !preg_match('/^\d+\.\d+\.\d+$/', (string) ($data['version'] ?? ''))
            || (int) ($data['checked_at'] ?? 0) <= 0
            || (int) $data['checked_at'] > time()
        ) {
            return null;
        }

        return [
            'version' => (string) $data['version'],
            'url' => self::safeGitHubUrl((string) ($data['url'] ?? '')),
            'name' => isset($data['name']) ? mb_substr((string) $data['name'], 0, 200) : null,
            'published_at' => self::validDate((string) ($data['published_at'] ?? '')),
            'checked_at' => (int) $data['checked_at'],
        ];
    }

    /** @param array{version:string,url:?string,name:?string,published_at:?string,checked_at:int}|null $release */
    private static function result(?array $release, string $source): array
    {
        if ($release === null) {
            return [
                'status' => $source,
                'version' => null,
                'url' => null,
                'name' => null,
                'published_at' => null,
                'checked_at' => null,
            ];
        }

        $current = defined('PLUGIN_CREDENTIALS_VERSION') ? PLUGIN_CREDENTIALS_VERSION : '0.0.0';
        $is_newer = version_compare($release['version'], $current, '>');
        return [
            'status' => $source === 'unavailable_cached' ? 'unavailable_cached' : ($is_newer ? 'update_available' : 'up_to_date'),
            'version' => $release['version'],
            'url' => $release['url'],
            'name' => $release['name'],
            'published_at' => $release['published_at'],
            'checked_at' => $release['checked_at'],
        ];
    }

    private static function statusCacheFile(): string
    {
        $base = defined('GLPI_PLUGIN_DOC_DIR') ? GLPI_PLUGIN_DOC_DIR . '/credentials' : sys_get_temp_dir() . '/glpi-credentials';
        if (!is_dir($base)) {
            @mkdir($base, 0750, true);
        }
        return $base . '/release_check_status.json';
    }

    private static function cacheFile(): string
    {
        $base = defined('GLPI_PLUGIN_DOC_DIR') ? GLPI_PLUGIN_DOC_DIR . '/credentials' : sys_get_temp_dir() . '/glpi-credentials';
        if (!is_dir($base)) {
            @mkdir($base, 0750, true);
        }
        return $base . '/release_cache.json';
    }

    /** @param array<string,mixed> $data */
    private static function writeCache(string $path, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @chmod($tmp, 0640);
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
            }
        }
    }

    private static function safeGitHubUrl(string $url): ?string
    {
        return preg_match('~^https://github\.com/monta990/credentials/releases/[^\s]+$~', $url) ? $url : null;
    }

    private static function validDate(string $date): ?string
    {
        if ($date === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($date))->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }
}
