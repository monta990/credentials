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

    public static function routeUrl(string $path, array $query = []): string
    {
        $query = array_filter($query, static fn($value): bool => $value !== null);
        return PLUGIN_CREDENTIALS_WEBDIR . '/' . ltrim($path, '/')
            . ($query !== [] ? '?' . http_build_query($query) : '');
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

        $target_url = htmlspecialchars($return_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $frame_script = '/plugins/credentials/js/credential-frame.js';
        if ($reload_parent_page) {
            $body_attributes = ' data-credentials-navigate-top="' . $target_url . '"';
        } else {
            $body_attributes = ' data-credentials-close-modal="1"';
        }
        if ($changed) {
            $body_attributes .= ' data-credentials-changed="1"';
        }
        $content = '<!doctype html><html><head><meta charset="UTF-8"><meta name="referrer" content="no-referrer"></head>'
            . '<body' . $body_attributes . '>'
            . \Html::script($frame_script, [], false)
            . '</body></html>';

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
