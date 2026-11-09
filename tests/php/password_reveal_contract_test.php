<?php
/** Ensure password reveal uses an interactive POST so GLPI 12 can prompt/replay re-authentication. */
$root = dirname(__DIR__, 2);
$controller = file_get_contents($root . '/src/Controller/CredentialController.php');
$template = file_get_contents($root . '/templates/credential/details.html.twig');
$javascript = file_get_contents($root . '/public/js/credential-details.js');
if (!is_string($controller) || !is_string($template) || !is_string($javascript)) {
    throw new RuntimeException('Unable to read password reveal implementation.');
}
if (!str_contains($controller, '$is_reveal_request = $request->isMethod(\'POST\')')
    || !str_contains($controller, 'if ($is_reveal_request && !Session::haveRight(Credential::RIGHT_REVEAL, READ))')
    || !str_contains($controller, 'Security::requireReauthentication();')
    || !str_contains($controller, '$request->request->has(\'reveal_password\')')
    || !str_contains($controller, 'Credential::decryptPassword(')
    || !str_contains($controller, 'CredentialHistory::recordForItem(')
    || !str_contains($controller, '\'revealed_password\' => $details_error ? null : $revealed_password')) {
    throw new RuntimeException('Reveal must check its specific right, use interactive re-authentication, audit, decrypt and render the password only after validation.');
}
if (str_contains($controller, "#[Route('/ajax/Credential/Reveal'") || str_contains($javascript, 'fetch(revealForm.action')) {
    throw new RuntimeException('Password reveal must not use an AJAX-only endpoint that cannot display GLPI re-authentication.');
}
if (!str_contains($template, 'action="{{ detail_url|e(\'html_attr\') }}"')
    || !str_contains($template, '<input type="hidden" name="reveal_password" value="1">')
    || (!str_contains($template, 'type="submit"') || !str_contains($template, 'data-credentials-reveal-button="1"'))
    || !str_contains($template, 'revealed_password')
    || !str_contains($template, 'type="text"')
    || !str_contains($template, 'readonly')
    || !str_contains($template, 'id="credentials-revealed-value"')
    || !str_contains((string) file_get_contents($root . '/public/css/credentials.css'), '-webkit-text-fill-color: var(--tblr-body-color')
    || !str_contains($template, 'data-loading-message')
    || !str_contains($controller, '\'_in_modal\' => $is_in_modal ? 1 : null')
    || !str_contains($controller, "'/js/credential-details.js?v=' . PLUGIN_CREDENTIALS_VERSION")
    || !str_contains($template, 'data-icon="ti-key"')
    || !str_contains($template, "__('No password is stored for this credential.', 'credentials')")
    || !str_contains($javascript, 'aria-busy')) {
    throw new RuntimeException('Reveal must be a regular form POST to the same detail iframe, with server-rendered plaintext only after authorization.');
}
echo "Password reveal interactive POST/re-authentication contract: OK\n";
