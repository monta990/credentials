<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials;

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Session;

/** Profile tab used to configure Credentials permissions. */
final class Profile extends \Profile
{
    public static string $rightname = 'profile';

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        return !$withtemplate && $item instanceof \Profile
            ? self::createTabEntry(Credential::getTypeName(Session::getPluralNumber()))
            : '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof \Profile || !self::canView()) {
            return false;
        }

        $profile = new \Profile();
        if (!$profile->getFromDB($item->getID())) {
            return false;
        }

        if (!self::ensureRightsForProfile((int) $item->getID())) {
            return false;
        }

        TemplateRenderer::getInstance()->display('@credentials/profile.html.twig', [
            'id'      => $item->getID(),
            'profile' => $profile,
            'title'   => self::getTypeName(Session::getPluralNumber()),
            'rights'  => self::getAllRights(),
        ]);

        return true;
    }

    /** @return array<int, array<string, mixed>> */
    public static function getAllRights(): array
    {
        return [
            [
                'itemtype' => Credential::class,
                'label'    => Credential::getTypeName(Session::getPluralNumber()),
                'field'    => Credential::$rightname,
                'rights'   => \Profile::getRightsFor(Credential::class),
            ],
            [
                'itemtype' => Credential::class,
                'label'    => __('Reveal credential passwords', 'credentials'),
                'field'    => Credential::RIGHT_REVEAL,
                'rights'   => [READ => __('Allow', 'credentials')],
            ],
        ];
    }

    /** Ensure the plugin's profile-right rows exist for one profile. */
    public static function ensureRightsForProfile(int $profiles_id): bool
    {
        global $DB;

        if ($profiles_id <= 0) {
            return false;
        }

        foreach (self::getAllRights() as $right) {
            $existing = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_profilerights',
                'WHERE'  => [
                    'profiles_id' => $profiles_id,
                    'name' => $right['field'],
                ],
                'LIMIT' => 1,
            ]);
            if (count($existing) === 0 && $DB->insert('glpi_profilerights', [
                'profiles_id' => $profiles_id,
                'name' => $right['field'],
                'rights' => 0,
            ]) === false) {
                return false;
            }
        }

        return true;
    }

    /** Refresh only the active profile after a profile switch. */
    public static function initProfile(): void
    {
        global $DB;

        $profiles_id = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if ($profiles_id <= 0) {
            return;
        }

        if (!self::ensureRightsForProfile($profiles_id)) {
            return;
        }

        $rights = $DB->request([
            'SELECT' => ['name', 'rights'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => [
                'profiles_id' => $profiles_id,
                'name'        => [Credential::$rightname, Credential::RIGHT_REVEAL],
            ],
        ]);
        foreach ($rights as $right) {
            $_SESSION['glpiactiveprofile'][$right['name']] = (int) $right['rights'];
        }
    }

    public static function removeRightsFromSession(): void
    {
        foreach ([Credential::$rightname, Credential::RIGHT_REVEAL] as $right) {
            if (isset($_SESSION['glpiactiveprofile'][$right])) {
                unset($_SESSION['glpiactiveprofile'][$right]);
            }
        }
    }
}
