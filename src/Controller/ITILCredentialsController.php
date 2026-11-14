<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials\Controller;

use CommonDBTM;
use Glpi\Controller\AbstractController;
use GlpiPlugin\Credentials\Credential;
use GlpiPlugin\Credentials\CredentialITILTab;
use Html;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Protected full listing for credentials inherited from Problem/Change items. */
final class ITILCredentialsController extends AbstractController
{
    #[Route('/ITIL/Credentials', name: 'credentials_itil_list', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        \Session::checkRight(Credential::$rightname, READ);

        $parent_itemtype = $request->query->getString('parent_itemtype');
        $parent_items_id = $request->query->getInt('parent_items_id');
        $supported_parent_types = array_keys(CredentialITILTab::getSupportedParents());
        if ($parent_items_id <= 0 || !in_array($parent_itemtype, $supported_parent_types, true)) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }

        $parent = getItemForItemtype($parent_itemtype);
        if (
            !$parent instanceof CommonDBTM
            || !$parent->getFromDB($parent_items_id)
            || !$parent->can($parent_items_id, READ)
        ) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }

        \Glpi\Security\ReAuth\ReAuthManager::getInstance()->checkReAuthenticationOrRedirect();

        $level = ob_get_level();
        ob_start();
        try {
            Html::header(__('Credentials for linked items', 'credentials'), $request->getPathInfo());
            CredentialITILTab::renderList($parent, true);
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
