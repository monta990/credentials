<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials;

use CommonDBTM;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Plugin\Hooks;
use Plugin;
use Session;

/** Adds the Credentials tab to users and supported GLPI asset types. */
final class CredentialItem extends CommonDBTM
{
    public static function registerTabs(): void
    {
        global $CFG_GLPI, $PLUGIN_HOOKS;

        $itemtypes = [\User::class];
        foreach (($CFG_GLPI['asset_types'] ?? []) as $itemtype) {
            if (self::isConcreteItemtype($itemtype)) {
                $itemtypes[] = $itemtype;
            }
        }

        $itemtypes = array_values(array_unique($itemtypes));

        Plugin::registerClass(self::class, ['addtabon' => $itemtypes]);
        foreach ($itemtypes as $itemtype) {
            $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['credentials'][$itemtype] = 'plugin_credentials_item_purge';
        }
    }

    /** @return array<int, class-string<CommonDBTM>> */
    public static function getSupportedItemtypes(): array
    {
        global $CFG_GLPI;

        $types = [\User::class];
        foreach (($CFG_GLPI['asset_types'] ?? []) as $itemtype) {
            if (self::isConcreteItemtype($itemtype)) {
                $types[] = $itemtype;
            }
        }
        return array_values(array_unique($types));
    }

    public static function resolveParentEntityID(CommonDBTM $item): int
    {
        if ($item instanceof \User) {
            return max(0, (int) ($item->fields['entities_id'] ?? 0));
        }
        return $item->isEntityAssign() ? max(0, (int) $item->getEntityID()) : 0;
    }

    private static function isConcreteItemtype(mixed $itemtype): bool
    {
        if (!is_string($itemtype) || !class_exists($itemtype) || !is_a($itemtype, CommonDBTM::class, true)) {
            return false;
        }
        return (new \ReflectionClass($itemtype))->isInstantiable();
    }

    public static function isSupportedItemtype(string $itemtype): bool
    {
        return in_array($itemtype, self::getSupportedItemtypes(), true);
    }

    /** Load a supported parent object and enforce its native READ right. */
    public static function loadReadableItem(string $itemtype, int $items_id): ?CommonDBTM
    {
        if (!self::isSupportedItemtype($itemtype) || $items_id <= 0) {
            return null;
        }
        $item = getItemForItemtype($itemtype);
        if (!$item instanceof CommonDBTM || !$item->getFromDB($items_id) || !$item->can($items_id, READ)) {
            return null;
        }
        return $item;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (
            $withtemplate
            || !$item instanceof CommonDBTM
            || !self::isSupportedItemtype($item::class)
            || !Session::haveRight(Credential::$rightname, READ)
        ) {
            return '';
        }

        $count = !empty($_SESSION['glpishow_count_on_tabs'])
            ? Credential::getCountForItem($item::class, (int) $item->getID(), self::resolveParentEntityID($item))
            : 0;
        return self::createTabEntry(__('Credentials', 'credentials'), $count, null, 'ti ti-id');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (
            $withtemplate
            || !$item instanceof CommonDBTM
            || !self::isSupportedItemtype($item::class)
            || !Session::haveRight(Credential::$rightname, READ)
        ) {
            return false;
        }

        $itemtype = $item::class;
        $items_id = (int) $item->getID();
        $entity_id = self::resolveParentEntityID($item);
        $labels = Credential::getLabelsForItem($itemtype, $items_id, $entity_id);
        $base_query = [
            'itemtype' => $itemtype,
            'items_id' => $items_id,
        ];
        $details_base = Security::routeUrl('Credential/Details', $base_query);
        $form_base = Security::routeUrl('Credential/Form', $base_query);
        $count_url = Security::routeUrl('ajax/Credential/TabCount', $base_query);

        // Avoid calling can() on sensitive parent itemtypes while rendering the AJAX tab.
        // Each destination performs the full item authorization check after re-authentication.
        $parent_rightname = $item::$rightname ?? '';
        $can_update_parent = $parent_rightname !== '' && Session::haveRight($parent_rightname, UPDATE);
        $can_create = $can_update_parent && Session::haveRight(Credential::$rightname, CREATE);
        $can_update = $can_update_parent && Session::haveRight(Credential::$rightname, UPDATE);
        $can_delete = $can_update_parent && Session::haveRight(Credential::$rightname, DELETE);
        $encryption_ready = Credential::isEncryptionReady();

        $add_modal_id = 'credentials_add_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $itemtype) . '_' . $items_id;
        $add_modal_markup = '';
        if ($can_create && $encryption_ready) {
            $add_modal_markup = \Ajax::createIframeModalWindow($add_modal_id, $form_base, [
                'display' => false,
                'title' => __('Add credential', 'credentials'),
                'width' => 1100,
                'height' => 760,
                'reloadonclose' => false,
            ]);
        }

        $rows = [];
        foreach ($labels as $label) {
            $credential_id = (int) $label['id'];
            $details_url = $details_base . '&id=' . $credential_id;
            $edit_url = $form_base . '&id=' . $credential_id;
            $detail_modal_id = 'credentials_view_' . $items_id . '_' . $credential_id;
            $edit_modal_id = 'credentials_edit_' . $items_id . '_' . $credential_id;
            $links_modal_id = 'credentials_links_' . $items_id . '_' . $credential_id;
            $links_url = Security::routeUrl('Credential/LinkedItems', [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
                'credential_id' => $credential_id,
            ]);

            $row = [
                'id' => $credential_id,
                'count_url' => $count_url,
                'name' => (string) $label['name'],
                'link_count' => (int) ($label['link_count'] ?? 0),
                'date_mod' => $label['date_mod'],
                'links_modal_id' => $links_modal_id,
                'links_modal_markup' => \Ajax::createIframeModalWindow($links_modal_id, $links_url, [
                    'display' => false,
                    'title' => __('Linked items', 'credentials') . ' — #' . $credential_id,
                    'width' => 900,
                    'height' => 620,
                    'reloadonclose' => false,
                ]),
                'detail_modal_id' => $detail_modal_id,
                'detail_modal_markup' => \Ajax::createIframeModalWindow($detail_modal_id, $details_url, [
                    'display' => false,
                    'title' => __('Credential details', 'credentials') . ' — ' . (string) $label['name'],
                    'width' => 1100,
                    'height' => 760,
                    'reloadonclose' => false,
                ]),
            ];

            if ($can_update) {
                $row['edit_modal_id'] = $edit_modal_id;
                $row['edit_modal_markup'] = \Ajax::createIframeModalWindow($edit_modal_id, $edit_url, [
                    'display' => false,
                    'title' => __('Edit credential', 'credentials') . ' — ' . (string) $label['name'],
                    'width' => 1100,
                    'height' => 760,
                    'reloadonclose' => false,
                ]);
                if (CredentialLinkPolicy::canUnlink($row['link_count'])) {
                    $row['unlink_url'] = $details_url;
                }
            }
            if ($can_delete) {
                $row['delete_url'] = $details_url;
            }
            $rows[] = $row;
        }

        TemplateRenderer::getInstance()->display('@credentials/credential/item_tab.html.twig', [
            'rows' => $rows,
            'can_create' => $can_create,
            'can_update' => $can_update,
            'can_delete' => $can_delete,
            'encryption_ready' => $encryption_ready,
            'add_modal_id' => $add_modal_id,
            'add_modal_markup' => $add_modal_markup,
            'count_url' => $count_url,
            'add_button_label' => __('Add credential', 'credentials'),
            'link_existing_url' => $details_base,
        ]);

        return true;
    }
}
