<?php
/** Regression contract for add/edit modal navigation. */
$root = dirname(__DIR__, 2);
$controller = file_get_contents($root . '/src/Controller/CredentialController.php');
$security = file_get_contents($root . '/src/Security.php');
$form = file_get_contents($root . '/templates/credential/form.html.twig');

if (!is_string($controller) || !is_string($security) || !is_string($form)) {
    throw new RuntimeException('Unable to read modal navigation sources.');
}

if (!str_contains($controller, 'redirectToItem($itemtype, $items_id, $context_ticket_id, $is_in_modal, true, true)')) {
    throw new RuntimeException('Successful add/edit must request a full parent page reload.');
}
if (!str_contains($security, 'bool $reload_parent_page = false')
    || !str_contains($security, 'data-credentials-navigate-top=')
    || !str_contains($security, '/js/credential-frame.js?v=')
    || !str_contains($security, 'PLUGIN_CREDENTIALS_VERSION')) {
    throw new RuntimeException('Modal Save must use the external iframe helper to request a full top-level GLPI item reload.');
}
$parent_js = file_get_contents($root . '/public/js/credentials.js');
$frame_js = file_get_contents($root . '/public/js/credential-frame.js');
if (!is_string($parent_js) || !str_contains($parent_js, "event.data.action === 'navigateTop'")
    || !str_contains($parent_js, 'destination.origin !== window.location.origin')
    || !is_string($frame_js) || !str_contains($frame_js, "postToParent('navigateTop'")) {
    throw new RuntimeException('Save navigation must be handled by a validated same-origin parent/iframe message bridge.');
}
if (!str_contains($form, 'data-credentials-close-modal="1"')) {
    throw new RuntimeException('Modal Cancel must have a dedicated close action.');
}
if (!str_contains($form, '{% if is_in_modal and return_to_details %}')) {
    throw new RuntimeException('Cancel from detail-edit must return to the credential details, not submit or close the parent.');
}
echo "Modal save full-page reload and cancel navigation contract: OK\n";
