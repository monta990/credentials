<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Credentials\Credential;
use GlpiPlugin\Credentials\CredentialTicketTab;
use Html;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ticket;

/** Full protected ticket-credential list after native re-authentication. */
final class TicketCredentialsController extends AbstractController
{
    #[Route('/Ticket/Credentials', name: 'credentials_ticket_list', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        \Session::checkRight(Credential::$rightname, READ);
        $ticket_id = $request->query->getInt('tickets_id');
        $ticket = new Ticket();
        if ($ticket_id <= 0 || !$ticket->getFromDB($ticket_id) || !$ticket->can($ticket_id, READ)) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }

        \Glpi\Security\ReAuth\ReAuthManager::getInstance()->checkReAuthenticationOrRedirect();

        $level = ob_get_level();
        ob_start();
        try {
            Html::header(__('Ticket credentials', 'credentials'), $request->getPathInfo(), 'helpdesk', 'ticket');
            CredentialTicketTab::renderTicketList($ticket, true);
            Html::footer();
            $content = (string) ob_get_clean();
        } catch (\Throwable $exception) {
            while (ob_get_level() > $level) {
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
}
