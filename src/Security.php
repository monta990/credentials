<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials;

use Glpi\Security\ReAuth\ReAuthManager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Session;
use Ticket;

/** Shared authorization, re-authentication and safe navigation helpers. */
final class Security
{
    public static function requireReauthentication(): void
    {
        ReAuthManager::getInstance()->checkReAuthenticationOrRedirect();
    }

    public static function requireRight(int $right): void
    {
        Session::checkRight(Credential::$rightname, $right);
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function routeUrl(string $path, array $query = []): string
    {
        $query = array_filter($query, static fn($value): bool => $value !== null);
        return PLUGIN_CREDENTIALS_WEBDIR . '/' . ltrim($path, '/')
            . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    /** Redirect to an updated detail view while preserving the iframe modal context. */
    public static function redirectToCredentialDetails(
        string $itemtype,
        int $items_id,
        int $credential_id,
        int $context_ticket_id = 0,
        bool $is_in_modal = false,
    ): Response {
        $item = CredentialItem::loadReadableItem($itemtype, $items_id);
        if ($item === null || $credential_id <= 0 || !CredentialItemLink::exists($credential_id, $itemtype, $items_id)) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }

        $query = [
            'itemtype' => $itemtype,
            'items_id' => $items_id,
            'id' => $credential_id,
            'context_ticket_id' => $context_ticket_id > 0 ? $context_ticket_id : null,
            '_in_modal' => $is_in_modal ? 1 : null,
            'changed' => $is_in_modal ? 1 : null,
        ];
        $response = new RedirectResponse(self::routeUrl('Credential/Details', $query), Response::HTTP_SEE_OTHER);
        self::setNoStoreHeaders($response);
        return $response;
    }

    /**
     * Return to the originating item or ticket. Inside a modal, close only that dialog
     * and let the parent tab listener refresh the Credentials tab if data changed.
     */
    public static function redirectToItem(
        string $itemtype,
        int $items_id,
        int $context_ticket_id = 0,
        bool $is_in_modal = false,
        bool $changed = true,
        bool $reload_parent_page = false,
    ): Response {
        $item = CredentialItem::loadReadableItem($itemtype, $items_id);
        if ($item === null) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }

        $return_url = $item::getFormURLWithID($items_id);
        if ($context_ticket_id > 0) {
            $ticket = new Ticket();
            if (!$ticket->getFromDB($context_ticket_id) || !$ticket->can($context_ticket_id, READ)) {
                throw new \Glpi\Exception\Http\AccessDeniedHttpException();
            }
            $return_url = $ticket->getFormURLWithID($context_ticket_id);
        }

        if (!$is_in_modal) {
            $response = new RedirectResponse($return_url, Response::HTTP_SEE_OTHER);
            self::setNoStoreHeaders($response);
            return $response;
        }

        $encoded_url = json_encode(
            $return_url,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT,
        );
        if (!is_string($encoded_url)) {
            throw new \RuntimeException('Unable to encode the parent item URL.');
        }

        $dirty_flag = $changed ? '1' : '0';
        if ($reload_parent_page) {
            // Let the GLPI parent page navigate itself. The iframe may be sandboxed
            // against top navigation, and CSP may block inline scripts.
            $target_url = htmlspecialchars($return_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $frame_script = htmlspecialchars(
                PLUGIN_CREDENTIALS_WEBDIR . '/js/credential-frame.js?v=' . rawurlencode(PLUGIN_CREDENTIALS_VERSION),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            );
            $content = '<!doctype html><html><head><meta charset="UTF-8"><meta name="referrer" content="no-referrer"></head>'
                . '<body data-credentials-navigate-top="' . $target_url . '">'
                . '<script src="' . $frame_script . '" defer></script></body></html>';
        } else {
            $content = '<!doctype html><html><head><meta charset="UTF-8"></head><body>'
                . '<script>(() => {'
                . 'try {'
                . 'const frame = window.frameElement;'
                . 'const modal = frame ? frame.closest(".modal") : null;'
                . 'const bootstrap = window.parent.bootstrap;'
                . 'if (modal && bootstrap && bootstrap.Modal) {'
                . 'if (' . $dirty_flag . ' === "1") { modal.dataset.credentialsChanged = "1"; }'
                . 'bootstrap.Modal.getOrCreateInstance(modal).hide();'
                . '} else { window.top.location.assign(' . $encoded_url . '); }'
                . '} catch (error) { window.top.location.assign(' . $encoded_url . '); }'
                . '})();</script></body></html>';
        }

        $response = new Response($content);
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
        self::setNoStoreHeaders($response);
        return $response;
    }

    /** Prevent browsers and intermediary caches from storing credential responses. */
    private static function setNoStoreHeaders(Response $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
    }
}
