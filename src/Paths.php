<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials;

/** Paths for persistent plugin data outside the installed source directory. */
final class Paths
{
    public static function filesDir(): string
    {
        if (defined('GLPI_PLUGIN_DOC_DIR')) {
            return rtrim(GLPI_PLUGIN_DOC_DIR, '/\\') . '/credentials';
        }

        return rtrim(GLPI_ROOT, '/\\') . '/files/_plugins/credentials';
    }

    public static function webDir(): string
    {
        global $CFG_GLPI;

        return rtrim($CFG_GLPI['root_doc'] ?? '', '/') . '/plugins/credentials';
    }

    public static function routeUrl(string $path = ''): string
    {
        return self::webDir() . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }
}
