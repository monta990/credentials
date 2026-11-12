<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Credentials\Credential;
use GlpiPlugin\Credentials\CredentialCategory;
use GlpiPlugin\Credentials\CredentialStatus;
use GlpiPlugin\Credentials\CredentialHistory;
use GlpiPlugin\Credentials\CredentialItem;
use GlpiPlugin\Credentials\CredentialItemLink;
use GlpiPlugin\Credentials\CredentialLinkPolicy;
use GlpiPlugin\Credentials\CredentialTicketTab;
use GlpiPlugin\Credentials\Security;
use Html;
use Session;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Ticket;
use Ticket_User;
use Item_Ticket;
use CommonITILActor;

/** Secure credential details and create/update workflows. */
final class CredentialController extends AbstractController
{
    #[Route('/Credential/Details', name: 'credentials_detail', methods: ['GET', 'POST'])]
    public function details(Request $request): Response
    {
        $is_reveal_request = $request->isMethod('POST') && $request->request->has('reveal_password');
        Security::requireRight(READ);
        // Check the dedicated reveal permission before triggering GLPI's interactive
        // re-authentication. Unlike AJAX, this ordinary HTML POST can display and
        // replay GLPI 12's native re-authentication prompt.
        if ($is_reveal_request && !Session::haveRight(Credential::RIGHT_REVEAL, READ)) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }
        Security::requireReauthentication();

        $itemtype = $request->query->getString('itemtype');
        $items_id = $request->query->getInt('items_id');
        $context_ticket_id = $request->query->getInt('context_ticket_id');
        $credential_id = $request->query->getInt('id', $request->request->getInt('credential_id'));
        $is_in_modal = $request->query->getString('_in_modal') === '1';

        $parent = CredentialItem::loadReadableItem($itemtype, $items_id);
        if ($parent === null) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }
        $context_ticket = $this->loadReadableTicket($context_ticket_id, $itemtype, $items_id);

        $parent_can_update = $parent->can($items_id, UPDATE);
        $parent_entity_id = CredentialItem::resolveParentEntityID($parent);
        $base_url = self::routeUrl('Credential/Details', [
            'itemtype' => $itemtype,
            'items_id' => $items_id,
            'context_ticket_id' => $context_ticket_id ?: null,
            '_in_modal' => $is_in_modal ? 1 : null,
        ]);

        $page_error = '';
        $revealed_password = null;

        if ($request->isMethod('POST')) {
            if ($request->request->has('reveal_password')) {
                if ($credential_id <= 0 || !CredentialItemLink::exists($credential_id, $itemtype, $items_id)) {
                    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
                }

                $reveal_credential = new Credential();
                if (
                    !$reveal_credential->getFromDB($credential_id)
                    || (int) ($reveal_credential->fields['is_deleted'] ?? 0) !== 0
                    || (int) ($reveal_credential->fields['entities_id'] ?? 0) !== $parent_entity_id
                    || !$reveal_credential->can($credential_id, READ)
                ) {
                    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
                }

                $password_ciphertext = (string) ($reveal_credential->fields['password'] ?? '');
                try {
                    $plaintext = $password_ciphertext === ''
                        ? null
                        : Credential::decryptPassword($password_ciphertext);
                } catch (\Throwable) {
                    // Never expose cryptographic exception details or ciphertext.
                    $plaintext = null;
                }
                if ($password_ciphertext === '') {
                    $page_error = __('No password is stored for this credential.', 'credentials');
                } elseif ($plaintext === null || $plaintext === '') {
                    $page_error = __('The stored password could not be decrypted. Contact your GLPI administrator.', 'credentials');
                } elseif (!CredentialHistory::recordForItem(
                    $itemtype,
                    $items_id,
                    Credential::HISTORY_REVEALED,
                    $credential_id,
                )) {
                    $page_error = __('GLPI could not write the access event to the item history, so the password was not revealed.', 'credentials');
                } else {
                    $revealed_password = $plaintext;
                }
            } elseif ($request->request->has('link_existing')) {
                if (!$parent_can_update || !Session::haveRight(Credential::$rightname, CREATE)) {
                    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
                }

                $candidate_id = $credential_id;
                $candidate = new Credential();
                if (
                    $candidate_id <= 0
                    || !$candidate->getFromDB($candidate_id)
                    || (int) ($candidate->fields['is_deleted'] ?? 0) !== 0
                    || !$candidate->can($candidate_id, READ)
                    || !CredentialItemLink::userCanReachCredential($candidate_id)
                    || (int) ($candidate->fields['entities_id'] ?? 0) !== $parent_entity_id
                ) {
                    $page_error = __('The selected credential is not available to your account.', 'credentials');
                    $credential_id = 0;
                } elseif (!CredentialItemLink::exists($candidate_id, $itemtype, $items_id)) {
                    $linked = $this->runTransaction(function () use ($candidate_id, $itemtype, $items_id, $parent_entity_id): bool {
                        if (!CredentialItemLink::lockCredential($candidate_id, $parent_entity_id, true)) {
                            return false;
                        }

                        // The candidate was checked before the transaction. Re-read
                        // its state after the row lock so an intervening archive or
                        // entity move cannot authorize a stale association.
                        $locked_candidate = new Credential();
                        if (
                            !$locked_candidate->getFromDB($candidate_id)
                            || (int) ($locked_candidate->fields['is_deleted'] ?? 0) !== 0
                            || (int) ($locked_candidate->fields['entities_id'] ?? 0) !== $parent_entity_id
                            || !$locked_candidate->can($candidate_id, READ)
                            || !CredentialItemLink::userCanReachCredential($candidate_id)
                        ) {
                            return false;
                        }

                        // A concurrent request may have created this same relation
                        // before our lock was acquired. Treat that as an idempotent
                        // success instead of attempting a duplicate insert.
                        if (CredentialItemLink::exists($candidate_id, $itemtype, $items_id)) {
                            return true;
                        }

                        $link = new CredentialItemLink();
                        $created = (bool) $link->add([
                            'plugin_credentials_credentials_id' => $candidate_id,
                            'itemtype' => $itemtype,
                            'items_id' => $items_id,
                            'date_creation' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
                        ]);
                        return $created && CredentialHistory::recordForItem(
                            $itemtype,
                            $items_id,
                            Credential::HISTORY_LINKED,
                            $candidate_id,
                        );
                    });

                    if ($linked) {
                        return Security::redirectToItem($itemtype, $items_id, $context_ticket_id, $is_in_modal);
                    }

                    $page_error = __('The credential could not be linked or recorded in GLPI history. No changes were saved.', 'credentials');
                    $credential_id = 0;
                } else {
                    return Security::redirectToItem($itemtype, $items_id, $context_ticket_id, $is_in_modal);
                }
            } elseif ($request->request->has('unlink') || $request->request->has('delete')) {
                $is_delete = $request->request->has('delete');
                $required_right = $is_delete ? DELETE : UPDATE;
                if (
                    !$parent_can_update
                    || !Session::haveRight(Credential::$rightname, $required_right)
                    || $credential_id <= 0
                    || !CredentialItemLink::exists($credential_id, $itemtype, $items_id)
                ) {
                    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
                }

                $credential = new Credential();
                if (
                    !$credential->getFromDB($credential_id)
                    || (int) ($credential->fields['is_deleted'] ?? 0) !== 0
                    || (int) ($credential->fields['entities_id'] ?? 0) !== $parent_entity_id
                    || !$credential->can($credential_id, $is_delete ? DELETE : READ)
                ) {
                    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
                }

                $last_link_blocked = false;
                $changed = $this->runTransaction(function () use ($credential_id, $itemtype, $items_id, $parent_entity_id, $is_delete, &$last_link_blocked): bool {
                    if (!CredentialItemLink::lockCredential($credential_id, $parent_entity_id, true)) {
                        return false;
                    }

                    // Repeat authorization and relation checks after locking. The
                    // pre-transaction object may have become archived, moved or
                    // detached while this request was waiting for the row lock.
                    $locked_credential = new Credential();
                    if (
                        !$locked_credential->getFromDB($credential_id)
                        || (int) ($locked_credential->fields['is_deleted'] ?? 0) !== 0
                        || (int) ($locked_credential->fields['entities_id'] ?? 0) !== $parent_entity_id
                        || !$locked_credential->can($credential_id, $is_delete ? DELETE : READ)
                        || !CredentialItemLink::exists($credential_id, $itemtype, $items_id)
                    ) {
                        return false;
                    }

                    if (!$is_delete && !CredentialLinkPolicy::canUnlink(CredentialItemLink::countForCredential($credential_id))) {
                        $last_link_blocked = true;
                        return false;
                    }
                    if ($is_delete) {
                        global $DB;

                        // Deleting a credential is global to every linked item. Capture
                        // the relations first so every affected item's native history
                        // receives a secret-free deletion event in this transaction.
                        $linked_items = CredentialItemLink::getLinksForCredential($credential_id);
                        if ($linked_items === []) {
                            return false;
                        }

                        if ($DB->delete(CredentialItemLink::getTable(), [
                            'plugin_credentials_credentials_id' => $credential_id,
                        ]) === false) {
                            return false;
                        }

                        $deleted = (bool) $locked_credential->delete(['id' => $credential_id]);
                        if (!$deleted) {
                            return false;
                        }

                        $history_written = [];
                        foreach ($linked_items as $linked_item) {
                            $history_key = $linked_item['itemtype'] . '#' . $linked_item['items_id'];
                            if (isset($history_written[$history_key])) {
                                continue;
                            }
                            if (!CredentialHistory::recordForItem(
                                $linked_item['itemtype'],
                                $linked_item['items_id'],
                                Credential::HISTORY_DELETED,
                                $credential_id,
                            )) {
                                return false;
                            }
                            $history_written[$history_key] = true;
                        }

                        return isset($history_written[$itemtype . '#' . $items_id]);
                    }

                    $unlinked = CredentialItemLink::unlink($credential_id, $itemtype, $items_id);
                    if (!$unlinked || !CredentialHistory::recordForItem(
                        $itemtype,
                        $items_id,
                        Credential::HISTORY_UNLINKED,
                        $credential_id,
                    )) {
                        return false;
                    }

                    // A last-link unlink is rejected above. Active credentials can
                    // therefore never become orphaned through the user interface.
                    return CredentialItemLink::countForCredential($credential_id) > 0;
                });

                if ($changed) {
                    return Security::redirectToItem($itemtype, $items_id, $context_ticket_id, $is_in_modal);
                }

                $page_error = $last_link_blocked
                    ? __('This is the only item linked to this credential. It cannot be unlinked because credentials must always belong to at least one item. Delete the credential instead, if you have permission.', 'credentials')
                    : ($is_delete
                        ? __('The credential could not be deleted or recorded in GLPI history. No changes were saved.', 'credentials')
                        : __('The credential could not be unlinked or recorded in GLPI history. No changes were saved.', 'credentials'));
            }
        }

        $credential = new Credential();
        if ($credential_id > 0) {
            if (
                !CredentialItemLink::exists($credential_id, $itemtype, $items_id)
                || !$credential->getFromDB($credential_id)
                || (int) ($credential->fields['is_deleted'] ?? 0) !== 0
                || (int) ($credential->fields['entities_id'] ?? 0) !== $parent_entity_id
                || !$credential->can($credential_id, READ)
            ) {
                throw new \Glpi\Exception\Http\AccessDeniedHttpException();
            }
        } elseif ($page_error === '') {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }

        $details = ['login' => '', 'url' => '', 'notes' => ''];
        $details_error = false;
        if ($credential_id > 0) {
            foreach (array_keys($details) as $field) {
                $ciphertext = (string) ($credential->fields[$field] ?? '');
                $decrypted = Credential::decryptValue($ciphertext);
                if ($decrypted === null || ($ciphertext !== '' && $decrypted === '')) {
                    $details_error = true;
                    break;
                }
                $details[$field] = $decrypted;
            }
        }

        $url_parts = $details['url'] !== '' ? parse_url($details['url']) : false;
        $safe_url = $details['url'] !== ''
            && filter_var($details['url'], FILTER_VALIDATE_URL)
            && is_array($url_parts)
            && in_array(strtolower((string) ($url_parts['scheme'] ?? '')), ['http', 'https'], true)
            && !isset($url_parts['user'])
            && !isset($url_parts['pass']);

        $link_count = CredentialItemLink::countForCredential($credential_id);
        // Inside a detail iframe, never create a second iframe modal. Navigate the
        // current dialog to the linked-items view instead; the list-tab count still
        // opens its own native GLPI modal from the parent page.
        $linked_items_url = self::routeUrl('Credential/LinkedItems', [
            'itemtype' => $itemtype,
            'items_id' => $items_id,
            'credential_id' => $credential_id,
            'context_ticket_id' => $context_ticket_id ?: null,
            '_in_modal' => $is_in_modal ? 1 : null,
        ]);
        $edit_url = self::routeUrl('Credential/Form', [
            'itemtype' => $itemtype,
            'items_id' => $items_id,
            'id' => $credential_id,
            'context_ticket_id' => $context_ticket_id ?: null,
            '_in_modal' => $is_in_modal ? 1 : null,
            'return_to_details' => $is_in_modal ? 1 : null,
        ]);
        $back_url = $context_ticket instanceof Ticket
            ? $context_ticket->getLinkURL()
            : $parent->getLinkURL();

        return $this->renderGlpiPage($request, __('Credential details', 'credentials'), $itemtype, '@credentials/credential/details.html.twig', [
            'is_in_modal' => $is_in_modal,
            'modal_changed' => $request->query->getString('changed') === '1',
            'modal_title' => __('Credential details', 'credentials') . ($credential_id > 0 ? ' — ' . (string) $credential->fields['name'] : ''),
            'credential_id' => $credential_id,
            'credential_name' => $credential_id > 0 ? (string) $credential->fields['name'] : '',
            'category_name' => $credential_id > 0
                ? (CredentialCategory::getById((int) ($credential->fields['plugin_credentials_categories_id'] ?? 0))['name'] ?? '')
                : '',
            'date_expires' => $credential_id > 0 ? (string) ($credential->fields['date_expires'] ?? '') : '',
            'date_last_review' => $credential_id > 0 ? (string) ($credential->fields['date_last_review'] ?? '') : '',
            'date_next_review' => $credential_id > 0 ? (string) ($credential->fields['date_next_review'] ?? '') : '',
            'reviewer_name' => $credential_id > 0 ? $this->reviewerName((int) ($credential->fields['reviewer_users_id'] ?? 0)) : '',
            'credential_status' => $credential_id > 0
                ? CredentialStatus::fromDates(
                    (string) ($credential->fields['date_expires'] ?? ''),
                    (string) ($credential->fields['date_next_review'] ?? '')
                )
                : null,
            'link_count' => $link_count,
            'linked_items_url' => $linked_items_url,
            'detail_js_url' => PLUGIN_CREDENTIALS_WEBDIR . '/js/credential-details.js?v=' . PLUGIN_CREDENTIALS_VERSION,
            'details' => $details,
            'details_error' => $details_error,
            'has_password' => $credential_id > 0 && (string) ($credential->fields['password'] ?? '') !== '',
            'revealed_password' => $details_error ? null : $revealed_password,
            'safe_url' => $safe_url,
            'page_error' => $page_error,
            'can_reveal' => Session::haveRight(Credential::RIGHT_REVEAL, READ),
            'can_edit' => $parent_can_update && Session::haveRight(Credential::$rightname, UPDATE) && !$details_error,
            'can_manage_relationships' => $parent_can_update && Session::haveRight(Credential::$rightname, UPDATE),
            'can_unlink' => $parent_can_update && Session::haveRight(Credential::$rightname, UPDATE) && CredentialLinkPolicy::canUnlink($link_count),
            'last_link' => !CredentialLinkPolicy::canUnlink($link_count),
            'can_delete' => $parent_can_update && Session::haveRight(Credential::$rightname, DELETE),
            'detail_url' => $base_url . '&id=' . $credential_id,
            'edit_url' => $edit_url,
            'back_url' => $back_url,
            'delete_confirmation' => __('Delete this credential for all linked items? This cannot be undone.', 'credentials'),
        ]);
    }

    #[Route('/Credential/LinkedItems', name: 'credentials_linked_items', methods: ['GET'])]
    public function linkedItems(Request $request): Response
    {
        Security::requireRight(READ);
        Security::requireReauthentication();

        $itemtype = $request->query->getString('itemtype');
        $items_id = $request->query->getInt('items_id');
        $credential_id = $request->query->getInt('credential_id');
        $context_ticket_id = $request->query->getInt('context_ticket_id');
        $is_in_modal = $request->query->getString('_in_modal') === '1';

        $source_item = CredentialItem::loadReadableItem($itemtype, $items_id);
        $this->loadReadableTicket($context_ticket_id, $itemtype, $items_id);
        $credential = new Credential();
        if (
            $source_item === null
            || $credential_id <= 0
            || !CredentialItemLink::exists($credential_id, $itemtype, $items_id)
            || !$credential->getFromDB($credential_id)
            || (int) ($credential->fields['is_deleted'] ?? 0) !== 0
            || (int) ($credential->fields['entities_id'] ?? 0) !== CredentialItem::resolveParentEntityID($source_item)
            || !$credential->can($credential_id, READ)
        ) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }

        $rows = [];
        $hidden_count = 0;
        foreach (CredentialItemLink::getLinksForCredential($credential_id) as $link) {
            $linked_type = $link['itemtype'];
            $linked_id = $link['items_id'];
            $linked_item = CredentialItem::loadReadableItem($linked_type, $linked_id);
            if (
                $linked_item === null
                || CredentialItem::resolveParentEntityID($linked_item) !== (int) $credential->fields['entities_id']
            ) {
                $hidden_count++;
                continue;
            }

            $rows[] = [
                'itemtype' => $linked_type,
                'type_name' => $linked_type::getTypeName(1),
                'items_id' => $linked_id,
                'name' => (string) $linked_item->getName(),
                'url' => $linked_item->getLinkURL(),
                'is_source' => $linked_type === $itemtype && $linked_id === $items_id,
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            $type_order = strnatcasecmp($left['type_name'], $right['type_name']);
            return $type_order !== 0 ? $type_order : strnatcasecmp($left['name'], $right['name']);
        });

        $modal_title = __('Linked items', 'credentials') . ' — ' . (string) $credential->fields['name'];
        $back_url = self::routeUrl('Credential/Details', [
            'itemtype' => $itemtype,
            'items_id' => $items_id,
            'id' => $credential_id,
            'context_ticket_id' => $context_ticket_id ?: null,
            '_in_modal' => $is_in_modal ? 1 : null,
        ]);
        return $this->renderGlpiPage($request, $modal_title, $itemtype, '@credentials/credential/linked_items.html.twig', [
            'is_in_modal' => $is_in_modal,
            'modal_title' => $modal_title,
            'credential_id' => $credential_id,
            'credential_name' => (string) $credential->fields['name'],
            'back_url' => $back_url,
            'frame_js_url' => PLUGIN_CREDENTIALS_WEBDIR . '/js/credential-frame.js?v=' . PLUGIN_CREDENTIALS_VERSION,
            'rows' => $rows,
            'visible_count' => count($rows),
            'total_count' => count($rows) + $hidden_count,
            'hidden_count' => $hidden_count,
        ]);
    }

    #[Route('/ajax/Credential/TabCount', name: 'credentials_tab_count', methods: ['GET'])]
    public function tabCount(Request $request): JsonResponse
    {
        Security::requireRight(READ);

        $itemtype = $request->query->getString('itemtype');
        $items_id = $request->query->getInt('items_id');
        if ($items_id <= 0) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }

        if ($itemtype === Ticket::class) {
            $ticket = new Ticket();
            if (!$ticket->getFromDB($items_id) || !$ticket->can($items_id, READ)) {
                throw new \Glpi\Exception\Http\AccessDeniedHttpException();
            }
            $count = CredentialTicketTab::getCredentialRows($ticket)['count'];
        } else {
            $item = CredentialItem::loadReadableItem($itemtype, $items_id);
            if ($item === null) {
                throw new \Glpi\Exception\Http\AccessDeniedHttpException();
            }
            $count = Credential::getCountForItem(
                $itemtype,
                $items_id,
                CredentialItem::resolveParentEntityID($item),
            );
        }

        $response = new JsonResponse([
            'success' => true,
            'count' => !empty($_SESSION['glpishow_count_on_tabs']) ? $count : 0,
        ]);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        return $response;
    }

    #[Route('/Credential/Form', name: 'credentials_form', methods: ['GET', 'POST'])]
    public function form(Request $request): Response
    {
        $itemtype = $request->query->getString('itemtype');
        $items_id = $request->query->getInt('items_id');
        $credential_id = $request->query->getInt('id');
        $is_update = $credential_id > 0;
        $is_in_modal = $request->query->getString('_in_modal') === '1';
        $return_to_details = $is_update && $is_in_modal && $request->query->getBoolean('return_to_details');
        $context_ticket_id = $request->query->getInt('context_ticket_id');

        Security::requireRight(READ);
        Security::requireRight($is_update ? UPDATE : CREATE);
        Security::requireReauthentication();

        $parent = CredentialItem::loadReadableItem($itemtype, $items_id);
        if ($parent === null || !$parent->can($items_id, UPDATE)) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }
        $context_ticket = $this->loadReadableTicket($context_ticket_id, $itemtype, $items_id);

        $credential = new Credential();
        if ($is_update) {
            if (
                !CredentialItemLink::exists($credential_id, $itemtype, $items_id)
                || !$credential->getFromDB($credential_id)
                || (int) ($credential->fields['is_deleted'] ?? 0) !== 0
                || (int) ($credential->fields['entities_id'] ?? 0) !== CredentialItem::resolveParentEntityID($parent)
                || !$credential->can($credential_id, READ)
                || !$credential->can($credential_id, UPDATE)
            ) {
                throw new \Glpi\Exception\Http\AccessDeniedHttpException();
            }
        }

        $errors = [];
        if (!Credential::isEncryptionReady()) {
            $errors[] = __('The GLPI security key is unavailable or invalid. Contact your GLPI administrator.', 'credentials');
        }
        $entity_id = CredentialItem::resolveParentEntityID($parent);
        $values = [
            'name' => '',
            'login' => '',
            'url' => '',
            'notes' => '',
            'category_id' => 0,
            'date_expires' => '',
            'date_last_review' => '',
            'date_next_review' => '',
            'reviewer_users_id' => 0,
        ];

        if ($is_update) {
            $values['name'] = (string) $credential->fields['name'];
            $values['category_id'] = (int) ($credential->fields['plugin_credentials_categories_id'] ?? 0);
            $values['date_expires'] = (string) ($credential->fields['date_expires'] ?? '');
            $values['date_last_review'] = (string) ($credential->fields['date_last_review'] ?? '');
            $values['date_next_review'] = (string) ($credential->fields['date_next_review'] ?? '');
            $values['reviewer_users_id'] = (int) ($credential->fields['reviewer_users_id'] ?? 0);
            foreach (['login', 'url', 'notes'] as $field) {
                $ciphertext = (string) ($credential->fields[$field] ?? '');
                $plaintext_value = Credential::decryptValue($ciphertext);
                if ($plaintext_value === null || ($ciphertext !== '' && $plaintext_value === '')) {
                    $errors[] = __('Stored credential details could not be decrypted. Editing is disabled to prevent accidental data loss.', 'credentials');
                    continue;
                }
                $values[$field] = $plaintext_value;
            }
        }

        if ($request->isMethod('POST') && $request->request->has('save')) {
            $values = [
                'name' => trim($request->request->getString('name')),
                'login' => trim($request->request->getString('login')),
                'url' => trim($request->request->getString('url')),
                'notes' => trim($request->request->getString('notes')),
                'category_id' => $request->request->getInt('category_id'),
                'date_expires' => trim($request->request->getString('date_expires')),
                'date_last_review' => trim($request->request->getString('date_last_review')),
                'date_next_review' => trim($request->request->getString('date_next_review')),
                'reviewer_users_id' => $request->request->getInt('reviewer_users_id'),
            ];
            // Never repopulate the password control after a failed POST.
            $plaintext = (string) $request->request->get('password', '');

            if ($values['name'] === '') {
                $errors[] = __('Name is required.', 'credentials');
            }
            if (!$is_update && $plaintext === '') {
                $errors[] = __('Password is required.', 'credentials');
            }
            if (
                mb_strlen($values['name']) > 255
                || mb_strlen($values['login']) > 255
                || mb_strlen($values['url']) > 2048
                || mb_strlen($values['notes']) > 100000
            ) {
                $errors[] = __('One or more fields exceed their maximum allowed length.', 'credentials');
            }
            if (strlen($plaintext) > 48000) {
                $errors[] = __('Password is too long. The maximum supported length is 48,000 bytes.', 'credentials');
            }
            if ($values['category_id'] > 0 && !CredentialCategory::isAvailableForEntity($values['category_id'], $entity_id)) {
                $errors[] = __('Select a valid category for this credential entity.', 'credentials');
            }
            foreach (['date_expires', 'date_last_review', 'date_next_review'] as $date_field) {
                if (!CredentialStatus::validDate($values[$date_field])) {
                    $errors[] = __('Review and expiration dates must use a valid calendar date.', 'credentials');
                    break;
                }
            }
            if ($values['reviewer_users_id'] > 0 && !$this->validReviewer($values['reviewer_users_id'], $entity_id)) {
                $errors[] = __('Select an available GLPI user as the review responsible person.', 'credentials');
            }
            if ($values['url'] !== '') {
                $url_parts = parse_url($values['url']);
                if (
                    !filter_var($values['url'], FILTER_VALIDATE_URL)
                    || !is_array($url_parts)
                    || !in_array(strtolower((string) ($url_parts['scheme'] ?? '')), ['http', 'https'], true)
                    || isset($url_parts['user'])
                    || isset($url_parts['pass'])
                ) {
                    $errors[] = __('URL must be a valid HTTP or HTTPS address and must not contain embedded credentials.', 'credentials');
                }
            }
            if (!Credential::isEncryptionReady()) {
                $errors[] = __('The GLPI security key is not configured or cannot be read. Ask your administrator to configure GLPI\'s security key before saving credentials.', 'credentials');
            }

            if ($errors === []) {
                $encrypted_login = $values['login'] === '' ? null : Credential::encryptValue($values['login']);
                $encrypted_url = $values['url'] === '' ? null : Credential::encryptValue($values['url']);
                $encrypted_notes = $values['notes'] === '' ? null : Credential::encryptValue($values['notes']);
                if (
                    ($values['login'] !== '' && $encrypted_login === null)
                    || ($values['url'] !== '' && $encrypted_url === null)
                    || ($values['notes'] !== '' && $encrypted_notes === null)
                ) {
                    $errors[] = __('Unable to encrypt credential details with the GLPI security key.', 'credentials');
                }

                $input = [
                    'name' => $values['name'],
                    'login' => $encrypted_login,
                    'url' => $encrypted_url,
                    'notes' => $encrypted_notes,
                    'entities_id' => $entity_id,
                    'plugin_credentials_categories_id' => $values['category_id'],
                    'date_expires' => $values['date_expires'] !== '' ? $values['date_expires'] : null,
                    'date_last_review' => $values['date_last_review'] !== '' ? $values['date_last_review'] : null,
                    'date_next_review' => $values['date_next_review'] !== '' ? $values['date_next_review'] : null,
                    'reviewer_users_id' => $values['reviewer_users_id'],
                ];
                if (!$is_update) {
                    $input['users_id'] = (int) Session::getLoginUserID();
                }
                if (!$is_update || $plaintext !== '') {
                    $encrypted_password = Credential::encryptPassword($plaintext);
                    if ($encrypted_password === null) {
                        $errors[] = __('Unable to encrypt the password with the GLPI security key.', 'credentials');
                    } else {
                        $input['password'] = $encrypted_password;
                    }
                }

                if ($errors === []) {
                    $expected_entity_id = CredentialItem::resolveParentEntityID($parent);
                    $saved_id = $this->runTransaction(function () use ($credential, $input, $is_update, $credential_id, $itemtype, $items_id, $expected_entity_id): int {
                        if ($is_update) {
                            // A credential form may have been opened before its parent
                            // item was transferred. Lock first, then re-read both the
                            // parent and exact link before changing any credential data.
                            if (!CredentialItemLink::lockCredential($credential_id, $expected_entity_id, true)) {
                                return 0;
                            }

                            $live_parent = CredentialItem::loadReadableItem($itemtype, $items_id);
                            $locked_credential = new Credential();
                            if (
                                $live_parent === null
                                || CredentialItem::resolveParentEntityID($live_parent) !== $expected_entity_id
                                || !$live_parent->can($items_id, UPDATE)
                                || !CredentialItemLink::exists($credential_id, $itemtype, $items_id)
                                || !$locked_credential->getFromDB($credential_id)
                                || (int) ($locked_credential->fields['is_deleted'] ?? 0) !== 0
                                || (int) ($locked_credential->fields['entities_id'] ?? 0) !== $expected_entity_id
                                || !$locked_credential->can($credential_id, UPDATE)
                            ) {
                                return 0;
                            }

                            // Do not persist a stale entity value from the form's
                            // initial GET. Entity ownership is derived from the
                            // parent reloaded after the credential row lock.
                            $input['id'] = $credential_id;
                            unset($input['entities_id']);
                            $saved = (bool) $locked_credential->update($input);
                            return $saved && CredentialHistory::recordForItem(
                                $itemtype,
                                $items_id,
                                Credential::HISTORY_UPDATED,
                                $credential_id,
                            ) ? $credential_id : 0;
                        }

                        $new_id = (int) $credential->add($input);
                        if ($new_id <= 0) {
                            return 0;
                        }
                        $link = new CredentialItemLink();
                        $linked = (bool) $link->add([
                            'plugin_credentials_credentials_id' => $new_id,
                            'itemtype' => $itemtype,
                            'items_id' => $items_id,
                            'date_creation' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
                        ]);
                        return $linked && CredentialHistory::recordForItem(
                            $itemtype,
                            $items_id,
                            Credential::HISTORY_CREATED,
                            $new_id,
                        ) ? $new_id : 0;
                    });

                    if ($saved_id > 0) {
                        // Saving is a committed change: navigate the top-level item page
                        // so all item data, related tabs and counters are refreshed.
                        // Cancel remains a local modal action and never reaches this branch.
                        return Security::redirectToItem($itemtype, $items_id, $context_ticket_id, $is_in_modal, true, true);
                    }

                    $errors[] = $is_update
                        ? __('The credential could not be updated or recorded in GLPI history. No changes were saved.', 'credentials')
                        : __('The credential could not be created or recorded in GLPI history. No credential was saved.', 'credentials');
                }
            }
        }

        $form_url = self::routeUrl('Credential/Form', [
            'itemtype' => $itemtype,
            'items_id' => $items_id,
            'id' => $is_update ? $credential_id : null,
            'context_ticket_id' => $context_ticket_id ?: null,
            '_in_modal' => $is_in_modal ? 1 : null,
            'return_to_details' => $return_to_details ? 1 : null,
        ]);
        $detail_url = $is_update ? self::routeUrl('Credential/Details', [
            'itemtype' => $itemtype,
            'items_id' => $items_id,
            'id' => $credential_id,
            'context_ticket_id' => $context_ticket_id ?: null,
            '_in_modal' => $is_in_modal ? 1 : null,
        ]) : '';
        $cancel_url = $is_update && (!$is_in_modal || $return_to_details)
            ? $detail_url
            : $parent->getFormURLWithID($items_id);

        return $this->renderGlpiPage(
            $request,
            $is_update ? __('Edit credential', 'credentials') : __('Add credential', 'credentials'),
            $itemtype,
            '@credentials/credential/form.html.twig',
            [
                'is_in_modal' => $is_in_modal,
                'is_update' => $is_update,
                'return_to_details' => $return_to_details,
                'form_frame_js_url' => PLUGIN_CREDENTIALS_WEBDIR . '/js/credential-frame.js?v=' . PLUGIN_CREDENTIALS_VERSION,
                'modal_title' => ($is_update ? __('Edit credential', 'credentials') : __('Add credential', 'credentials'))
                    . ($is_update ? ' — ' . $values['name'] : ''),
                'associated_item_name' => (string) $parent->getName(),
                'errors' => $errors,
                'values' => $values,
                'categories' => CredentialCategory::getOptions($entity_id),
                'reviewer_options' => $this->reviewerOptions($entity_id, (int) $values['reviewer_users_id']),
                'form_url' => $form_url,
                'cancel_url' => $cancel_url,
                'encryption_ready' => Credential::isEncryptionReady(),
            ],
        );
    }

    /**
     * Validate both access to the return ticket and the actual relation between
     * that ticket and the item from which the credential was opened. This prevents
     * callers from forging an unrelated readable ticket as the modal return target.
     */
    private function loadReadableTicket(int $ticket_id, string $itemtype, int $items_id): ?Ticket
    {
        if ($ticket_id <= 0) {
            return null;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticket_id) || !$ticket->can($ticket_id, READ)) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }

        global $DB;
        if ($itemtype === \User::class) {
            $relation = $DB->request([
                'SELECT' => ['id'],
                'FROM' => Ticket_User::getTable(),
                'WHERE' => [
                    'tickets_id' => $ticket_id,
                    'users_id' => $items_id,
                    'type' => CommonITILActor::REQUESTER,
                ],
                'LIMIT' => 1,
            ]);
        } else {
            $relation = $DB->request([
                'SELECT' => ['id'],
                'FROM' => Item_Ticket::getTable(),
                'WHERE' => [
                    'tickets_id' => $ticket_id,
                    'itemtype' => $itemtype,
                    'items_id' => $items_id,
                ],
                'LIMIT' => 1,
            ]);
        }

        if (count($relation) === 0) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }

        return $ticket;
    }

    /** Validate a review responsible user without accepting a stale/deleted account. */
    private function validReviewer(int $users_id, int $entities_id): bool
    {
        if ($users_id <= 0) {
            return true;
        }
        $user = new \User();
        return $user->getFromDB($users_id)
            && (int) ($user->fields['is_deleted'] ?? 0) === 0
            && Session::haveAccessToEntity((int) ($user->fields['entities_id'] ?? 0))
            && Session::haveAccessToEntity($entities_id);
    }

    /** @return array<int,array{id:int,label:string}> */
    private function reviewerOptions(int $entities_id, int $selected_id = 0): array
    {
        global $DB;
        $options = [];
        if (Session::haveRight('user', READ)) {
            $iterator = $DB->request([
                'SELECT' => ['id', 'name', 'realname', 'firstname', 'entities_id'],
                'FROM' => \User::getTable(),
                'WHERE' => ['is_deleted' => 0],
                'ORDER' => ['name'],
                'LIMIT' => 250,
            ]);
            foreach ($iterator as $row) {
                if (!Session::haveAccessToEntity((int) $row['entities_id'])) {
                    continue;
                }
                $display = trim((string) ($row['realname'] ?? '') . ' ' . (string) ($row['firstname'] ?? ''));
                $login = (string) $row['name'];
                $options[] = [
                    'id' => (int) $row['id'],
                    'label' => $display !== '' ? $display . ' (' . $login . ')' : $login,
                ];
            }
        }

        if ($selected_id > 0 && !array_filter($options, static fn(array $option): bool => $option['id'] === $selected_id)) {
            $label = $this->reviewerName($selected_id);
            if ($label !== '') {
                $options[] = ['id' => $selected_id, 'label' => $label];
            }
        }
        return $options;
    }

    private function reviewerName(int $users_id): string
    {
        if ($users_id <= 0) {
            return '';
        }
        $user = new \User();
        if (!$user->getFromDB($users_id) || (int) ($user->fields['is_deleted'] ?? 0) !== 0) {
            return '';
        }
        $display = trim((string) ($user->fields['realname'] ?? '') . ' ' . (string) ($user->fields['firstname'] ?? ''));
        $login = (string) ($user->fields['name'] ?? '');
        return $display !== '' ? $display . ' (' . $login . ')' : $login;
    }

    private function renderGlpiPage(
        Request $request,
        string $title,
        string $itemtype,
        string $template,
        array $variables,
    ): Response {
        $buffer_level = ob_get_level();
        ob_start();
        try {
            Html::header($title, $request->getPathInfo(), 'assets', $itemtype);
            \Glpi\Application\View\TemplateRenderer::getInstance()->display($template, $variables);
            Html::footer();
            $content = (string) ob_get_clean();
        } catch (\Throwable $exception) {
            while (ob_get_level() > $buffer_level) {
                ob_end_clean();
            }
            throw $exception;
        }

        $response = new Response($content);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        return $response;
    }

    private function runTransaction(callable $callback): bool|int
    {
        global $DB;

        // GLPI's DB layer implements nested transactions with savepoints.
        // Opening one level is safe even when GLPI already owns an outer
        // transaction; commit/rollback below only closes this local level.
        // DBmysql::beginTransaction()/commit() return void and
        // DBmysql::isInTransaction() is private in GLPI 12.
        $DB->beginTransaction();

        try {
            $result = $callback();
            if (!$result) {
                $DB->rollBack();
                return $result;
            }
            $DB->commit();
            return $result;
        } catch (\Throwable $exception) {
            $DB->rollBack();
            throw $exception;
        }
    }

    private static function routeUrl(string $path, array $query = []): string
    {
        return PLUGIN_CREDENTIALS_WEBDIR . '/' . ltrim($path, '/')
            . ($query !== [] ? '?' . http_build_query(array_filter($query, static fn($value): bool => $value !== null)) : '');
    }
}
