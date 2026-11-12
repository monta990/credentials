<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials;

/** Small, non-secret administrative settings stored through GLPI's Config API. */
final class AdminSettings
{
    public const DEFAULT_CATEGORIES = [
        'General',
        'Networking',
        'Servers',
        'Databases',
        'ERP and business systems',
        'Email',
        'Cloud services',
        'Suppliers and support',
        'Other',
    ];

    /** @return array<int, string> */
    public static function categories(): array
    {
        $settings = \Config::getConfigurationValues('plugin_credentials') ?: [];
        $raw = (string) ($settings['categories'] ?? '');
        $values = $raw === '' ? self::DEFAULT_CATEGORIES : preg_split('/\\R/u', $raw);

        $categories = [];
        foreach ($values ?: [] as $value) {
            $value = trim((string) $value);
            if ($value !== '' && mb_strlen($value) <= 100) {
                $categories[mb_strtolower($value)] = $value;
            }
        }

        if ($categories === []) {
            return self::DEFAULT_CATEGORIES;
        }

        return array_values($categories);
    }

    public static function retentionDays(): int
    {
        $settings = \Config::getConfigurationValues('plugin_credentials') ?: [];
        return max(0, min(3650, (int) ($settings['retention_days'] ?? 0)));
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return \Config::getConfigurationValues('plugin_credentials') ?: [];
    }

    /** @param array<string, mixed> $values */
    public static function save(array $values): void
    {
        \Config::setConfigurationValues('plugin_credentials', $values);
    }
}
