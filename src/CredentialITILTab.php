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
use Change;
use Plugin;
use Problem;
use Session;

/** Aggregates credentials from the inventory items attached to Problems and Changes. */
final class CredentialITILTab extends CommonGLPI
{
    /** @var array<string, array{rows:array,requires_reauth:bool,count:int}> */
    private static array $rowsCache = [];

    /**
     * Native GLPI relation metadata. Keep this allowlist explicit: the context
     * route must never accept an arbitrary ITIL itemtype or relation table.
     *
     * @return array<class-string<CommonDBTM>, array{relation:class-string,parent_field:string}>
     */
    public static function getSupportedParents(): array
    {
        return [
            Problem::class => [
                'relation' => \Item_Problem::class,
                'parent_field' => 'problems_id',
            ],
            Change::class => [
                'relation' => \Change_Item::class,
                'parent_field' => 'changes_id',
            ],
        ];
    }

    public static function isSupportedParent(CommonGLPI $item): bool
    {
        return isset(self::getSupportedParents()[$item::class]);
    }

    public static function register(): void
    {
        Plugin::registerClass(self::class, ['addtabon' => array_keys(self::getSupportedParents())]);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (
            $withtemplate
            || !self::isSupportedParent($item)
            || !Session::haveRight(Credential::$rightname, READ)
        ) {
            return '';
        }

        $count = !empty($_SESSION['glpishow_count_on_tabs'])
            ? self::getCredentialRows($item)['count']
            : 0;

        return self::createTabEntry(__('Credentials', 'credentials'), $count, null, 'ti ti-id');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (
            $withtemplate
            || !self::isSupportedParent($item)
            || !Session::haveRight(Credential::$rightname, READ)
        ) {
            return false;
        }

        self::renderList($item, false);
        return true;
    }

    /**
     * Return deduplicated credentials associated with all readable items linked
     * to a Problem or Change. Secret fields are never selected by this query path.
     *
     * @return array{
     *     rows: array<int, array{id:int,name:string,date_mod:?string,detail_url:string,origins:array<int,string>,sources:array<int,string>,source_links:array<int,array{label:string,url:string}>}>,
     *     requires_reauth: bool,
     *     count: int
     * }
     */
    public static function getCredentialRows(CommonGLPI $parent): array
    {
        global $DB;

        if (!self::isSupportedParent($parent) || !$parent instanceof CommonDBTM) {
            return ['rows' => [], 'requires_reauth' => false, 'count' => 0];
        }

        $parent_id = (int) $parent->getID();
        if ($parent_id <= 0 || !Session::haveRight(Credential::$rightname, READ)) {
            return ['rows' => [], 'requires_reauth' => false, 'count' => 0];
        }
        $cache_key = $parent::class . '#' . $parent_id;
        if (isset(self::$rowsCache[$cache_key])) {
            return self::$rowsCache[$cache_key];
        }

        $config = self::getSupportedParents()[$parent::class];
        $relation_class = $config['relation'];
        $relations = $DB->request([
            'SELECT' => ['itemtype', 'items_id'],
            'FROM' => $relation_class::getTable(),
            'WHERE' => [$config['parent_field'] => $parent_id],
            'ORDER' => ['itemtype', 'items_id'],
        ]);

        $sources = [];
        foreach ($relations as $row) {
            $itemtype = (string) ($row['itemtype'] ?? '');
            $items_id = (int) ($row['items_id'] ?? 0);
            if ($items_id <= 0 || !CredentialItem::isSupportedItemtype($itemtype)) {
                continue;
            }

            $source_key = $itemtype . '#' . $items_id;
            $sources[$source_key] = ['itemtype' => $itemtype, 'items_id' => $items_id];
        }

        $entries = [];
        $protected_ids = [];
        $requires_reauth = false;
        foreach ($sources as $source) {
            $itemtype = $source['itemtype'];
            $items_id = $source['items_id'];
            $item = getItemForItemtype($itemtype);
            if (!$item instanceof CommonDBTM || !$item->getFromDB($items_id)) {
                continue;
            }

            // As in the Ticket tab, never select secret fields and do not make
            // an AJAX tab try to trigger GLPI's interactive sudo-mode prompt.
            $input = null;
            $reauth_needed = null;
            $allowed = $item->can($items_id, READ, $input, $reauth_needed);
            $entity_id = CredentialItem::resolveParentEntityID($item);
            if (!$allowed) {
                if ($reauth_needed) {
                    $source_protected_ids = Credential::getActiveIDsForItem($itemtype, $items_id, $entity_id);
                    if ($source_protected_ids !== []) {
                        $requires_reauth = true;
                        foreach ($source_protected_ids as $protected_id) {
                            $protected_id = (int) $protected_id;
                            if ($protected_id > 0) {
                                $protected_ids[$protected_id] = $protected_id;
                            }
                        }
                    }
                }
                continue;
            }

            $labels = Credential::getLabelsForItem($itemtype, $items_id, $entity_id);
            if ($labels === []) {
                continue;
            }

            $item_name = (string) $item->getName();
            $source_label = sprintf('%s: %s', $itemtype::getTypeName(1), $item_name);
            $source_type = $parent instanceof Problem
                ? __('Problem item', 'credentials')
                : __('Change item', 'credentials');
            $detail_base = Security::routeUrl('Credential/Details', [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
            ]);

            foreach ($labels as $label) {
                $credential_id = (int) ($label['id'] ?? 0);
                if ($credential_id <= 0) {
                    continue;
                }
                $entries[] = [
                    'label' => $label,
                    'source_label' => $source_label,
                    'source_type' => $source_type,
                    'source_url' => (string) $item->getLinkURL(),
                    'detail_url' => $detail_base . '&id=' . $credential_id,
                ];
            }
        }

        $aggregated = CredentialTicketAggregator::aggregate($entries, array_values($protected_ids));
        self::$rowsCache[$cache_key] = [
            'rows' => $aggregated['rows'],
            'requires_reauth' => $requires_reauth,
            'count' => $aggregated['count'],
        ];
        return self::$rowsCache[$cache_key];
    }

    public static function renderList(CommonGLPI $parent, bool $secure_page): void
    {
        if (!self::isSupportedParent($parent) || !$parent instanceof CommonDBTM) {
            throw new \InvalidArgumentException('Unsupported GLPI ITIL parent type.');
        }

        $parent_id = (int) $parent->getID();
        $data = self::getCredentialRows($parent);
        $rows = [];
        foreach ($data['rows'] as $row) {
            $modal_id = 'credentials_itil_view_' . strtolower(str_replace('\\', '_', $parent::class))
                . '_' . $parent_id . '_' . (int) $row['id'];
            $row['modal_id'] = $modal_id;
            $row['count_url'] = Security::routeUrl('ajax/Credential/TabCount', [
                'itemtype' => $parent::class,
                'items_id' => $parent_id,
            ]);
            $row['modal_markup'] = \Ajax::createIframeModalWindow($modal_id, $row['detail_url'], [
                'display' => false,
                'title' => __('Credential details', 'credentials') . ' — ' . $row['name'],
                'width' => 1100,
                'height' => 530,
                'reloadonclose' => false,
            ]);
            $rows[] = $row;
        }

        TemplateRenderer::getInstance()->display('@credentials/credential/itil_tab.html.twig', [
            'rows' => $rows,
            'requires_reauth' => $data['requires_reauth'],
            'secure_page' => $secure_page,
            'secure_url' => Security::routeUrl('ITIL/Credentials', [
                'parent_itemtype' => $parent::class,
                'parent_items_id' => $parent_id,
            ]),
            'back_url' => $parent->getLinkURL(),
        ]);
    }
}
