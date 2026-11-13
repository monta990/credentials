<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Glpi\Cache\CacheManager;
use GlpiPlugin\Credentials\Credential;
use GlpiPlugin\Credentials\CredentialItem;
use GlpiPlugin\Credentials\CredentialItemLink;
use GlpiPlugin\Credentials\Service\CredentialLifecycleReminderService;
use GlpiPlugin\Credentials\Profile;

/** Install or upgrade Credentials tables and rights. */
function plugin_credentials_install(): bool
{
    global $DB;

    $charset = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();

    // Initial plugin tables are created with doQuery(), as required for DDL.
    // All runtime reads/writes use GLPI's DB query builder. Keep DDL static apart
    // from charset/collation values supplied by GLPI's DBConnection helpers.
    $credentials_created = $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_credentials_credentials` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(255) NOT NULL,
        `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `login` TEXT DEFAULT NULL,
        `password` TEXT NOT NULL,
        `url` TEXT DEFAULT NULL,
        `notes` MEDIUMTEXT DEFAULT NULL,
        `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `date_creation` TIMESTAMP NULL DEFAULT NULL,
        `date_mod` TIMESTAMP NULL DEFAULT NULL,
        `link_lock_token` VARCHAR(255) NOT NULL DEFAULT '',
        `plugin_credentials_categories_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `date_expires` DATE DEFAULT NULL,
        `date_last_review` DATE DEFAULT NULL,
        `date_next_review` DATE DEFAULT NULL,
        `reviewer_users_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `is_deleted` TINYINT NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `name` (`name`),
        KEY `entities_id` (`entities_id`),
        KEY `is_deleted` (`is_deleted`),
        KEY `date_expires` (`date_expires`),
        KEY `date_next_review` (`date_next_review`),
        KEY `category` (`plugin_credentials_categories_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");

    $links_created = $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_credentials_items` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `plugin_credentials_credentials_id` INT UNSIGNED NOT NULL,
        `itemtype` VARCHAR(100) NOT NULL,
        `items_id` INT UNSIGNED NOT NULL,
        `date_creation` TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `credential_item` (`plugin_credentials_credentials_id`, `itemtype`, `items_id`),
        KEY `item` (`itemtype`, `items_id`),
        KEY `credential` (`plugin_credentials_credentials_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");

    $categories_created = $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_credentials_categories` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(255) NOT NULL,
        `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `comment` TEXT DEFAULT NULL,
        `date_creation` TIMESTAMP NULL DEFAULT NULL,
        `date_mod` TIMESTAMP NULL DEFAULT NULL,
        `is_deleted` TINYINT NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `entity_name` (`entities_id`, `name`),
        KEY `is_deleted` (`is_deleted`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");

    $reminders_created = $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_credentials_reminders` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `plugin_credentials_credentials_id` INT UNSIGNED NOT NULL,
        `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `reminder_type` VARCHAR(16) NOT NULL,
        `reminder_date` DATE NOT NULL,
        `reviewer_users_id` INT UNSIGNED NOT NULL,
        `date_sent` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `credential_reminder` (`plugin_credentials_credentials_id`, `entities_id`, `reminder_date`, `reviewer_users_id`),
        KEY `credential` (`plugin_credentials_credentials_id`),
        KEY `date_sent` (`date_sent`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");

    if ($credentials_created === false || $links_created === false || $categories_created === false || $reminders_created === false) {
        return false;
    }

    // Migration is used for plugin-owned rights and any future incremental schema
    // changes. GLPI's documented initial-table pattern is static CREATE TABLE DDL
    // through $DB->doQuery(); DDL is not a runtime data query.
    $migration = new Migration(PLUGIN_CREDENTIALS_VERSION);
    // Additive, non-secret serialization token for existing installations. It
    // is updated through DB->update() to acquire an InnoDB row write lock without
    // issuing prohibited raw runtime SQL. Existing credential data is untouched.
    $migration->addField(
        Credential::getTable(),
        'link_lock_token',
        'string',
        ['value' => '']
    );
    $migration->addField(Credential::getTable(), 'plugin_credentials_categories_id', 'integer', ['value' => 0]);
    $migration->addField(Credential::getTable(), 'date_expires', 'date');
    $migration->addField(Credential::getTable(), 'date_last_review', 'date');
    $migration->addField(Credential::getTable(), 'date_next_review', 'date');
    $migration->addField(Credential::getTable(), 'reviewer_users_id', 'integer', ['value' => 0]);
    $migration->addRight(Credential::$rightname, ALLSTANDARDRIGHT, ['config' => READ | UPDATE]);
    $migration->addRight(Credential::RIGHT_REVEAL, READ, ['config' => READ | UPDATE]);
    $migration->addRight(Credential::RIGHT_BACKUP, READ, ['config' => READ | UPDATE]);
    $migration->executeMigration();
    Profile::initProfile();

    // Keep lifecycle reminders inside GLPI's native automatic-action scheduler.
    $reminder_task = new CronTask();
    if (
        !$reminder_task->getFromDBbyName(
            CredentialLifecycleReminderService::class,
            CredentialLifecycleReminderService::CRON_NAME
        )
        && !CronTask::register(
            CredentialLifecycleReminderService::class,
            CredentialLifecycleReminderService::CRON_NAME,
            DAY_TIMESTAMP
        )
    ) {
        return false;
    }

    // This hook runs for both first installation and plugin updates. Reset GLPI's
    // cache contexts (including translations) and compiled Twig templates so the
    // next request cannot reuse stale plugin metadata, rights or UI definitions.
    (new CacheManager())->resetAllCaches();

    return true;
}

/** Uninstall plugin data and remove its profile right. */
function plugin_credentials_uninstall(): bool
{
    global $DB;

    Profile::removeRightsFromSession();
    if (CronTask::unregister('credentials') === false) {
        return false;
    }
    if ($DB->delete('glpi_profilerights', ['name' => [Credential::$rightname, Credential::RIGHT_REVEAL, Credential::RIGHT_BACKUP]]) === false) {
        return false;
    }
    if ($DB->tableExists('glpi_plugin_credentials_items')
        && $DB->doQuery('DROP TABLE `glpi_plugin_credentials_items`') === false
    ) {
        return false;
    }
    if ($DB->tableExists('glpi_plugin_credentials_reminders')
        && $DB->doQuery('DROP TABLE `glpi_plugin_credentials_reminders`') === false
    ) {
        return false;
    }
    if ($DB->tableExists('glpi_plugin_credentials_categories')
        && $DB->doQuery('DROP TABLE `glpi_plugin_credentials_categories`') === false
    ) {
        return false;
    }
    if ($DB->tableExists('glpi_plugin_credentials_credentials')
        && $DB->doQuery('DROP TABLE `glpi_plugin_credentials_credentials`') === false
    ) {
        return false;
    }

    return true;
}


/** Remove credential relationships after a linked GLPI object is permanently purged. */
function plugin_credentials_item_purge(CommonDBTM $item): void
{
    if (!CredentialItem::isSupportedItemtype($item::class)) {
        return;
    }

    // Do not swallow purge failures: GLPI must be able to roll back the parent
    // item purge together with its credential relationship cleanup.
    CredentialItemLink::cleanupForItem($item::class, (int) $item->getID());
}

/**
 * Keep Credentials relationships valid when GLPI moves or clones an item.
 *
 * GLPI invokes ITEM_TRANSFER inside the transfer transaction and supplies
 * type, original id, newID and destination entities_id.
 *
 * @param array{type?:mixed,id?:mixed,newID?:mixed,entities_id?:mixed} $params
 */
function plugin_credentials_item_transfer(array $params): void
{
    \GlpiPlugin\Credentials\CredentialTransfer::handle($params);
}
