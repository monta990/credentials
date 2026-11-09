<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials;

/** Enforces that every active credential remains associated with an item. */
final class CredentialLinkPolicy
{
    public static function canUnlink(int $activeLinkCount): bool
    {
        return $activeLinkCount > 1;
    }
}
