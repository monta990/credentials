<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

global $CFG_GLPI;

use Glpi\Plugin\Hooks;
use GlpiPlugin\Credentials\Credential;
use GlpiPlugin\Credentials\CredentialItem;
use GlpiPlugin\Credentials\CredentialTicketTab;
use GlpiPlugin\Credentials\Profile;

define('PLUGIN_CREDENTIALS_VERSION', '0.0.32');
define('PLUGIN_CREDENTIALS_MIN_GLPI', '12.0.0');
define('PLUGIN_CREDENTIALS_MAX_GLPI', '12.0.99');

if (!defined('PLUGIN_CREDENTIALS_DIR')) {
    define('PLUGIN_CREDENTIALS_DIR', Plugin::getPhpDir('credentials'));
    define('PLUGIN_CREDENTIALS_WEBDIR', ($CFG_GLPI['root_doc'] ?? '') . '/plugins/credentials');
}

/**
 * Initialize Credentials.
 */
function plugin_init_credentials(): void
{
    global $PLUGIN_HOOKS;

    // GLPIKey::generate()/security:change_key will migrate this plugin field together
    // with GLPI's own encrypted fields. Never store a value encrypted with GLPIKey
    // without registering it here.
    $PLUGIN_HOOKS[Hooks::SECURED_FIELDS]['credentials'] = [
        'glpi_plugin_credentials_credentials.login',
        'glpi_plugin_credentials_credentials.password',
        'glpi_plugin_credentials_credentials.url',
        'glpi_plugin_credentials_credentials.notes',
    ];

    if (!Session::getLoginUserID() || !Plugin::isPluginActive('credentials')) {
        return;
    }

    // GLPI configuration entry for the independent administration page.
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['credentials'] = 'config';

    // Core assets plus GLPI 12 custom asset itemtypes exposed through asset_types.
    // Registration is repeated at POST_INIT so dynamically registered asset classes
    // are also picked up once their definitions have been loaded.
    CredentialItem::registerTabs();
    CredentialTicketTab::register();
    // Register the CommonDBTM itemtype because GLPI's native Log history links
    // plugin events to Credential::class and resolves the linked itemtype to
    // display getHistoryEntry().
    Plugin::registerClass(Credential::class);
    Plugin::registerClass(Profile::class, ['addtabon' => \Profile::class]);
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['credentials'][] = 'js/credentials.js';
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['credentials'][] = 'css/credentials.css?v=' . PLUGIN_CREDENTIALS_VERSION;

    $PLUGIN_HOOKS[Hooks::ITEM_TRANSFER]['credentials'] = 'plugin_credentials_item_transfer';
    $PLUGIN_HOOKS[Hooks::CHANGE_PROFILE]['credentials'] = [Profile::class, 'initProfile'];
    $PLUGIN_HOOKS[Hooks::POST_INIT]['credentials'] = 'plugin_credentials_postinit';
}

/**
 * Register tabs after all core and plugin initialization has completed.
 */
function plugin_credentials_postinit(): void
{
    \GlpiPlugin\Credentials\CredentialItem::registerTabs();
    \GlpiPlugin\Credentials\CredentialTicketTab::register();
}

/**
 * Plugin metadata.
 */
function plugin_version_credentials(): array
{
    return [
        'name'         => __('Credentials', 'credentials'),
        'version'      => PLUGIN_CREDENTIALS_VERSION,
        'author'       => 'Edwin Elias Alvarez',
        'license'      => 'GPL-3.0-or-later',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_CREDENTIALS_MIN_GLPI,
                'max' => PLUGIN_CREDENTIALS_MAX_GLPI,
                'dev' => false,
            ],
            'php' => [
                'min' => '8.3',
            ],
        ],
    ];
}
