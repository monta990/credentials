<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials;

use CommonDBTM;
use CommonGLPI;
use CommonITILActor;
use Glpi\Application\View\TemplateRenderer;
use Plugin;
use Session;
use Ticket;
use Ticket_User;
use Item_Ticket;

/** Aggregates requester and associated-item credentials in a ticket tab. */
final class CredentialTicketTab extends CommonDBTM
{
    public static function register(): void
    {
        Plugin::registerClass(self::class, ['addtabon' => [Ticket::class]]);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($withtemplate || !$item instanceof Ticket || !Session::haveRight(Credential::$rightname, READ)) {
            return '';
        }
        $count = !empty($_SESSION['glpishow_count_on_tabs']) ? self::getCredentialRows($item)['count'] : 0;
        return self::createTabEntry(__('Credentials', 'credentials'), $count, null, 'ti ti-id');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($withtemplate || !$item instanceof Ticket || !Session::haveRight(Credential::$rightname, READ)) {
            return false;
        }
        self::renderTicketList($item, false);
        return true;
    }

    /**
     * Build rows with only labels and source descriptions. Encrypted fields are never selected.
     *
     * @return array{rows: array<int, array{id:int,name:string,date_mod:?string,detail_url:string,sources:array<int,string>}>, requires_reauth:bool, count:int}
     */
    public static function getCredentialRows(Ticket $ticket): array
    {
        global $DB;

        $ticket_id = (int) $ticket->getID();
        if ($ticket_id <= 0 || !Session::haveRight(Credential::$rightname, READ)) {
            return ['rows' => [], 'requires_reauth' => false, 'count' => 0];
        }

        $sources = [];
        $requesters = $DB->request([
            'SELECT' => ['users_id'],
            'FROM' => Ticket_User::getTable(),
            'WHERE' => ['tickets_id' => $ticket_id, 'type' => CommonITILActor::REQUESTER],
            'ORDER' => ['users_id'],
        ]);
        foreach ($requesters as $row) {
            $user_id = (int) $row['users_id'];
            if ($user_id > 0) {
                $sources['requester#' . \User::class . '#' . $user_id] = [
                    'itemtype' => \User::class,
                    'items_id' => $user_id,
                    'kind' => 'requester',
                ];
            }
        }

        $ticket_items = $DB->request([
            'SELECT' => ['itemtype', 'items_id'],
            'FROM' => Item_Ticket::getTable(),
            'WHERE' => ['tickets_id' => $ticket_id],
            'ORDER' => ['itemtype', 'items_id'],
        ]);
        foreach ($ticket_items as $row) {
            $itemtype = (string) $row['itemtype'];
            $items_id = (int) $row['items_id'];
            if ($items_id <= 0 || !CredentialItem::isSupportedItemtype($itemtype)) {
                continue;
            }
            $sources['item#' . $itemtype . '#' . $items_id] = [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
                'kind' => 'item',
            ];
        }

        $source_entries = [];
        $protected_ids = [];
        $requires_reauth = false;
        foreach ($sources as $source) {
            $itemtype = $source['itemtype'];
            $items_id = $source['items_id'];
            $parent = getItemForItemtype($itemtype);
            if (!$parent instanceof CommonDBTM || !$parent->getFromDB($items_id)) {
                continue;
            }

            // Do not redirect an AJAX tab when an underlying sensitive item requires sudo mode.
            $input = null;
            $reauth_needed = null;
            $allowed = $parent->can($items_id, READ, $input, $reauth_needed);
            if (!$allowed) {
                if ($reauth_needed) {
                    $source_protected_ids = Credential::getActiveIDsForItem(
                        $itemtype,
                        $items_id,
                        CredentialItem::resolveParentEntityID($parent),
                    );
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

            $labels = Credential::getLabelsForItem(
                $itemtype,
                $items_id,
                CredentialItem::resolveParentEntityID($parent),
            );
            if ($labels === []) {
                continue;
            }

            $source_type = $source['kind'] === 'requester'
                ? __('Requester', 'credentials')
                : __('Ticket item', 'credentials');
            $parent_name = (string) $parent->getName();
            if ($source['kind'] === 'item') {
                $parent_name = sprintf('%s: %s', $itemtype::getTypeName(1), $parent_name);
            }
            $source_label = sprintf('%s (%s)', $parent_name, $source_type);
            $detail_base = Security::routeUrl('Credential/Details', [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
                'context_ticket_id' => $ticket_id,
            ]);

            foreach ($labels as $label) {
                $credential_id = (int) $label['id'];
                if ($credential_id <= 0) {
                    continue;
                }
                $source_entries[] = [
                    'label' => $label,
                    'source_label' => $source_label,
                    'detail_url' => $detail_base . '&id=' . $credential_id,
                ];
            }
        }

        $aggregated = CredentialTicketAggregator::aggregate($source_entries, array_values($protected_ids));
        return [
            'rows' => $aggregated['rows'],
            'requires_reauth' => $requires_reauth,
            'count' => $aggregated['count'],
        ];
    }

    public static function renderTicketList(Ticket $ticket, bool $secure_page): void
    {
        $data = self::getCredentialRows($ticket);
        $rows = [];
        foreach ($data['rows'] as $row) {
            $modal_id = 'credentials_ticket_view_' . (int) $ticket->getID() . '_' . (int) $row['id'];
            $row['modal_id'] = $modal_id;
            $row['count_url'] = Security::routeUrl('ajax/Credential/TabCount', [
                'itemtype' => Ticket::class,
                'items_id' => (int) $ticket->getID(),
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

        TemplateRenderer::getInstance()->display('@credentials/credential/ticket_list.html.twig', [
            'rows' => $rows,
            'requires_reauth' => $data['requires_reauth'],
            'secure_page' => $secure_page,
            'secure_url' => Security::routeUrl('Ticket/Credentials', ['tickets_id' => (int) $ticket->getID()]),
            'back_url' => $ticket->getLinkURL(),
        ]);
    }
}
