<?php

/**
 * Static release checks for the Credentials plugin package.
 *
 * This validator intentionally does not pretend to replace GLPI integration tests.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];

$ci_workflow = file_get_contents($root . '/.github/workflows/ci.yml');
if (!is_string($ci_workflow)
    || !preg_match('~php-version:\s*\[([^]]+)\]~', $ci_workflow, $php_matrix_match)
) {
    $errors[] = 'CI must declare an explicit PHP CLI version matrix.';
} else {
    foreach (["'8.3'", "'8.4'", "'8.5'", "'8.6'"] as $required_php) {
        if (!str_contains($php_matrix_match[1], $required_php)) {
            $errors[] = 'CI PHP matrix is missing required CLI runtime ' . trim($required_php, "'") . '.';
        }
    }
}
if (!is_string($ci_workflow)
    || !str_contains($ci_workflow, 'tools/run-cli-tests.sh')
    || str_contains($ci_workflow, 'continue-on-error: true')
) {
    $errors[] = 'Every PHP matrix runtime, including PHP 8.6, must execute required CLI tests without continue-on-error.';
}
if (!is_file($root . '/tools/run-cli-tests.sh') || !is_file($root . '/tests/php/cli_runtime_test.php')) {
    $errors[] = 'The CLI matrix runner and exact PHP runtime assertion test are required.';
}
$version_source = file_get_contents($root . '/setup.php');
$manifest_source = file_get_contents($root . '/credentials.xml');

if (!is_string($version_source) || !preg_match("/PLUGIN_CREDENTIALS_VERSION', '([^']+)'/", $version_source, $version_match)) {
    $errors[] = 'Cannot read plugin version from setup.php.';
    $version = '';
} else {
    $version = $version_match[1];
}

if (!is_string($manifest_source)
    || !preg_match('~<num>\s*([^<]+)\s*</num>~', $manifest_source, $manifest_version_match)
    || !preg_match('~<compatibility>\s*([^<]+)\s*</compatibility>~', $manifest_source, $compatibility_match)
    || !preg_match('~<state>\s*([^<]+)\s*</state>~', $manifest_source, $state_match)
) {
    $errors[] = 'credentials.xml is missing required version, compatibility or state metadata.';
} else {
    if (trim($manifest_version_match[1]) !== $version) {
        $errors[] = 'setup.php and credentials.xml versions differ.';
    }
    if (trim($compatibility_match[1]) !== '~12.0.0') {
        $errors[] = 'Manifest compatibility must be ~12.0.0 for the supported GLPI 12.0.x range.';
    }
    if (trim($state_match[1]) !== 'development') {
        $errors[] = 'Keep the manifest in development state until public URLs and live acceptance tests exist.';
    }
}

foreach (['CredentialController.php', 'TicketCredentialsController.php'] as $controller) {
    $path = $root . '/src/Controller/' . $controller;
    $source = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($source) || !str_contains($source, '#[Route(')) {
        $errors[] = "Missing Symfony route controller: {$controller}.";
    }
}

$setup = (string) $version_source;
if (!str_contains($setup, 'Plugin::registerClass(Credential::class)')) {
    $errors[] = 'Credential CommonDBTM must be registered for native linked-item history resolution.';
}
foreach ([
    'glpi_plugin_credentials_credentials.login',
    'glpi_plugin_credentials_credentials.password',
    'glpi_plugin_credentials_credentials.url',
    'glpi_plugin_credentials_credentials.notes',
] as $secured_field) {
    if (!str_contains($setup, $secured_field)) {
        $errors[] = "Sensitive field is not registered in Hooks::SECURED_FIELDS: {$secured_field}.";
    }
}

$hook = file_get_contents($root . '/hook.php');
if (!is_string($hook) || !str_contains($hook, 'executeMigration()')) {
    $errors[] = 'Install hook must execute the GLPI Migration instance.';
}
if (!is_string($hook) || !str_contains($hook, 'resetAllCaches()')) {
    $errors[] = 'Install/update hook must clear GLPI caches.';
}

$cleanup = file_get_contents($root . '/src/CredentialItemLink.php');
$controller = file_get_contents($root . '/src/Controller/CredentialController.php');
if (!is_file($root . '/tests/php/credential_lock_test.php')
    || !is_file($root . '/tests/php/credential_transfer_test.php')
    || !is_file($root . '/tests/php/transaction_savepoint_test.php')
    || !str_contains((string) $cleanup, "'link_lock_token' => bin2hex(random_bytes(16))")
    || !str_contains((string) file_get_contents($root . '/hook.php'), "'link_lock_token'")
    || !str_contains((string) file_get_contents($root . '/hook.php'), '->addField(')
    || !str_contains((string) file_get_contents($root . '/src/Credential.php'), "'link_lock_token'")
    || !str_contains((string) $cleanup, 'getLinksForCredentialForUpdate(')
    || !str_contains((string) $cleanup, '$DB->doQuery($query)')
    || !str_contains((string) $cleanup, 'FOR UPDATE')
    || !str_contains((string) $cleanup, 'DBmysql::quoteName')
    || !str_contains((string) $cleanup, 'DBmysql::quoteValue')
    || !str_contains((string) file_get_contents($root . '/src/CredentialTransfer.php'), 'CredentialItemLink::getLinksForCredentialForUpdate(')
) {
    $errors[] = 'Credential mutations must retain the migrated row lock and use a narrowly scoped DBmysql-quoted current read with transfer/purge race regressions.';
}
if (!is_string($controller)
    || !str_contains($controller, '$locked_candidate->getFromDB($candidate_id)')
    || !str_contains($controller, "\$locked_candidate->fields['is_deleted']")
    || !str_contains($controller, "\$locked_candidate->fields['entities_id']")
    || !str_contains($controller, '$locked_credential->getFromDB($credential_id)')
    || !str_contains($controller, 'CredentialItemLink::lockCredential($candidate_id, $parent_entity_id, true)')
    || !str_contains($controller, 'CredentialItemLink::lockCredential($credential_id, $parent_entity_id, true)')
) {
    $errors[] = 'Link/unlink/delete must revalidate the live credential state and entity after acquiring the row lock.';
}
$history_source = file_get_contents($root . '/src/CredentialHistory.php');
if (!is_string($cleanup)
    || !str_contains($cleanup, 'CredentialHistory::recordForCredential(')
    || !str_contains($cleanup, 'Credential::HISTORY_ORPHANED')
    || !is_string($history_source)
    || !str_contains($history_source, 'public static function recordForCredential(')
    || !str_contains($history_source, 'Log::history(')
    || !is_file($root . '/tests/php/credential_history_rendering_test.php')
) {
    $errors[] = 'Purge of the final linked item must write the secret-free orphan event to credential history.';
}

foreach ([
    'purge cleanup' => $cleanup,
    'controller write transaction' => $controller,
] as $transaction_name => $transaction_source) {
    if (
        !is_string($transaction_source)
        || !str_contains($transaction_source, '$DB->beginTransaction();')
        || !str_contains($transaction_source, '$DB->commit();')
        || !str_contains($transaction_source, '$DB->rollBack();')
        || str_contains($transaction_source, '$DB->isInTransaction()')
    ) {
        $errors[] = ucfirst($transaction_name) . ' must use GLPI nested transaction levels without calling private DB state.';
    }
}

// POST writes must use GLPI's Symfony routes. Legacy front files may redirect only.
foreach (['CredentialController.php', 'TicketCredentialsController.php'] as $controller) {
    $source = file_get_contents($root . '/src/Controller/' . $controller);
    if (!is_string($source) || !str_contains($source, 'extends AbstractController')) {
        $errors[] = "Controller must extend GLPI AbstractController: {$controller}.";
    }
}

$credential_controller_source = file_get_contents($root . '/src/Controller/CredentialController.php');
$link_policy = file_get_contents($root . '/src/CredentialLinkPolicy.php');
if (!is_string($link_policy) || !str_contains($link_policy, 'return $activeLinkCount > 1;')) {
    $errors[] = 'A credential must keep at least one active item relationship.';
}
if (!str_contains((string) $credential_controller_source, 'if ($is_reveal_request && !Session::haveRight(Credential::RIGHT_REVEAL, READ))')
    || !str_contains((string) $credential_controller_source, '$request->request->has(\'reveal_password\')')
    || !str_contains((string) $credential_controller_source, 'Security::requireReauthentication();')
    || str_contains((string) $credential_controller_source, "#[Route('/ajax/Credential/Reveal'")
    || !str_contains((string) $credential_controller_source, 'CredentialLinkPolicy::canUnlink(')
) {
    $errors[] = 'Password reveal must use an interactive POST with the separate reveal right and GLPI re-authentication; last-link unlink must be blocked.';
}
$item_tab_template = file_get_contents($root . '/templates/credential/item_tab.html.twig');
$details_template = file_get_contents($root . '/templates/credential/details.html.twig');
if (!is_string($item_tab_template) || !str_contains($item_tab_template, "__('Credential ID', 'credentials')")
    || !str_contains((string) $details_template, "__('Credential ID', 'credentials')")
    || !str_contains((string) $details_template, 'data-credentials-reveal-form="1"')
    || !str_contains((string) $details_template, '<input type="hidden" name="reveal_password" value="1">')
    || !str_contains((string) $details_template, "__('Password is hidden until you request it.', 'credentials')")
    || !str_contains((string) $details_template, "__('No password is stored for this credential.', 'credentials')")
    || !str_contains((string) $details_template, 'has_password')
    || !str_contains((string) $details_template, 'detail_js_url')
    || !str_contains((string) $details_template, 'linked_items_url')
    || str_contains((string) $details_template, 'linked_items_modal_markup')
) {
    $errors[] = 'Credential IDs, password hint, same-iframe linked-item navigation and external iframe behavior must be present.';
}
$form_template = file_get_contents($root . '/templates/credential/form.html.twig');
if (!is_string($form_template)
    || !str_contains($form_template, '{% if is_in_modal %}')
    || !str_contains($form_template, "data-credentials-close-modal=\"1\"")
    || !str_contains($form_template, "href=\"{{ cancel_url|e('html_attr') }}\"")
) {
    $errors[] = 'Modal Cancel must close without submitting; standalone Cancel must retain its item return link.';
}
$ui_css = file_get_contents($root . '/public/css/credentials.css');
if (!is_string($ui_css)
    || !str_contains($ui_css, 'font-size: 1.25rem;')
    || !str_contains($ui_css, '.form-hint')
) {
    $errors[] = 'Password value and form hints must retain readable contrast and sizing.';
}
if (!is_string($ui_css)
    || !str_contains($ui_css, 'background: #d63939 !important;')
    || !str_contains($ui_css, 'background-color: #d63939 !important;')
    || !str_contains($ui_css, '.credentials-modal-title-icon')
) {
    $errors[] = 'Delete must have a solid danger fill and modal title icons must have explicit sizing.';
}
foreach (['public/js/credentials.js', 'public/js/credential-frame.js', 'public/js/credential-details.js'] as $modal_script) {
    $source = file_get_contents($root . '/' . $modal_script);
    if (!is_string($source) || !str_contains($source, 'credentials-modal-title-icon')) {
        $errors[] = "Modal title icon class is missing from {$modal_script}.";
    }
}
if (!is_file($root . '/public/css/credentials.css')
    || !str_contains((string) $setup, "'css/credentials.css?v='")
) {
    $errors[] = 'Scoped Credentials UI styles must exist and be registered through GLPI add_css.';
}
foreach (['public/js/credentials.js', 'public/js/credential-details.js', 'public/js/credential-frame.js'] as $public_script) {
    if (!is_file($root . '/' . $public_script)) {
        $errors[] = 'GLPI 12 public JavaScript resource is missing: ' . $public_script;
    }
}
if (!str_contains((string) $setup, "'js/credentials.js'")) {
    $errors[] = 'Global JavaScript must be registered using a path relative to the plugin public/ directory.';
}
if (is_dir($root . '/js') || is_dir($root . '/css')) {
    $errors[] = 'Legacy root js/ and css/ directories must not be used for GLPI 12 frontend assets; move resources under public/.';
}
if (!is_file($root . '/tests/php/linked_items_contract_test.php')
    || !str_contains((string) $details_template, 'linked_items_url')
    || !str_contains((string) file_get_contents($root . '/templates/credential/linked_items.html.twig'), 'Back to credential')
    || !str_contains((string) file_get_contents($root . '/public/css/credentials.css'), 'width: 3.5rem;')
) {
    $errors[] = 'Linked-item navigation regression coverage and accessible action-button targets are required.';
}
$security = file_get_contents($root . '/src/Security.php');
if (!is_string($security)
    || !str_contains($security, 'checkReAuthenticationOrRedirect()')
    || !str_contains($security, 'Session::checkRight')
) {
    $errors[] = 'Sensitive routes must use GLPI native rights and re-authentication helpers.';
}

$history = file_get_contents($root . '/src/CredentialHistory.php');
$history_call = is_string($history) && preg_match('~Log::history\((.*?)\);~s', $history, $history_match)
    ? $history_match[1]
    : '';
if ($history_call === '' || !str_contains($history_call, 'credential_id') || str_contains($history_call, 'password')) {
    $errors[] = 'Credential audit history must remain attributable and must not log password values.';
}

$credential_model = file_get_contents($root . '/src/Credential.php');
if (!is_string($credential_model)
    || !preg_match('~function hasCredentialsForItem\([^)]*int \$entities_id\)~', $credential_model)
    || !str_contains($credential_model, 'return self::getActiveIDsForItem($itemtype, $items_id, $entities_id) !== [];')
) {
    $errors[] = 'Credential existence checks must require an explicit accessible entity.';
}

// Listing queries must never fetch encrypted credential material.
if (!is_string($credential_model)
    || !str_contains($credential_model, "'SELECT' => ['id', 'name', 'date_mod']")
    || !str_contains($credential_model, '\'entities_id\' => $entities_id')
    || !str_contains($credential_model, "'is_deleted'  => 0")
) {
    $errors[] = 'Credential label queries must select only non-secret fields and be entity/deletion scoped.';
}
$ticket_tab = file_get_contents($root . '/src/CredentialTicketTab.php');
if (!is_string($ticket_tab)
    || !str_contains($ticket_tab, 'Credential::getLabelsForItem(')
    || !str_contains($ticket_tab, 'CommonITILActor::REQUESTER')
    || !str_contains($ticket_tab, 'Item_Ticket::getTable()')
) {
    $errors[] = 'Ticket aggregation must use accessible labels from requester and associated-item relations.';
}

// The detail controller validates the requested ticket against the real source relation.
$credential_controller_source = file_get_contents($root . '/src/Controller/CredentialController.php');
if (!is_string($credential_controller_source)
    || !str_contains($credential_controller_source, 'loadReadableTicket(')
    || !str_contains($credential_controller_source, 'Ticket_User::getTable()')
    || !str_contains($credential_controller_source, 'Item_Ticket::getTable()')
    || !str_contains($credential_controller_source, 'CommonITILActor::REQUESTER')
) {
    $errors[] = 'Ticket return context must be verified against an actual requester/item relation.';
}
foreach (glob($root . '/src/Controller/*.php') ?: [] as $controller_path) {
    $controller_source = file_get_contents($controller_path);
    if (!is_string($controller_source) || preg_match('~(?<!::)\bheader\s*\(~', $controller_source)) {
        $errors[] = 'Symfony controllers must set response headers on Response objects: ' . basename($controller_path);
    }
}
if (!is_string($security)
    || !str_contains($security, "'Cache-Control', 'no-store, no-cache, must-revalidate, private'")
    || !str_contains($security, "'Referrer-Policy', 'no-referrer'")
) {
    $errors[] = 'Credential responses and redirects must have no-store and no-referrer headers.';
}

$transaction_test = $root . '/tests/php/transaction_savepoint_test.php';
if (!is_file($transaction_test)) {
    $errors[] = 'Nested transaction regression test is missing.';
}

$javascript = file_get_contents($root . '/public/js/credentials.js');
if (!is_string($javascript)
    || !str_contains($javascript, "document.addEventListener('submit'")
    || !str_contains($javascript, 'form.dataset.credentialsConfirm')
) {
    $errors[] = 'Credential unlink/delete forms must use the delegated confirmation handler.';
}
$javascript_test = $root . '/tests/js/confirmation_test.js';
if (!is_file($javascript_test)) {
    $errors[] = 'JavaScript destructive-action confirmation regression test is missing.';
}
$detail_confirmation_test = $root . '/tests/js/detail_confirmation_test.js';
if (!is_file($detail_confirmation_test)
    || !str_contains((string) $details_template, 'data-credentials-confirm=')
    || str_contains((string) $details_template, 'data-credentials-confirm-inline')) {
    $errors[] = 'Detail iframe unlink/delete forms must use the confirmation handler installed in credential-details.js.';
}
if (!is_file($root . '/tests/js/password_ui_test.js') || !is_file($root . '/tests/php/password_reveal_contract_test.php')) {
    $errors[] = 'Password reveal interactive POST/re-authentication and copy/hide regression tests are missing.';
}
foreach ([
    '/public/js/credential-details.js',
    '/public/js/credential-frame.js',
    '/tests/js/modal_frame_test.js',
    '/tests/js/modal_parent_message_test.js',
    '/tests/php/form_navigation_contract_test.php',
    '/tests/js/tab_refresh_test.js',
    '/tests/php/ticket_tab_glpi12_simulation_test.php',
] as $required_file) {
    if (!is_file($root . $required_file)) {
        $errors[] = 'Required iframe/GLPI simulation file is missing: ' . ltrim($required_file, '/');
    }
}
if (!str_contains((string) $credential_controller_source, "#[Route('/ajax/Credential/TabCount'")
    || !str_contains((string) file_get_contents($root . '/public/js/credentials.js'), 'credentialsCountUrl')
    || !str_contains((string) file_get_contents($root . '/public/js/credentials.js'), 'credentials_refresh=')) {
    $errors[] = 'Modal mutations must refresh the active tab and its live count badge without reloading the full page.';
}
if (!str_contains((string) $details_template, '<input type="hidden" name="reveal_password" value="1">')
    || !str_contains((string) $details_template, 'data-credentials-reveal-button="1"')
    || !str_contains((string) $details_template, 'type="submit"')
    || !str_contains((string) $details_template, 'revealed_password')
    || !str_contains((string) $details_template, 'id="credentials-revealed-value"')
    || !str_contains((string) $details_template, 'readonly')
    || !str_contains((string) $details_template, 'data-icon="ti-key"')
    || !str_contains((string) $details_template, 'btn btn-danger btn-lg credentials-delete-button')) {
    $errors[] = 'The reveal form must use the interactive detail POST; details modal title icon and filled delete button must be present.';
}
$details_js = file_get_contents($root . '/public/js/credential-details.js');
if (!is_string($details_js)
    || str_contains($details_js, 'fetch(revealForm.action')
    || !str_contains($details_js, "execCommand('copy')")
    || !str_contains($details_js, 'clearPassword();')
) {
    $errors[] = 'The iframe password script must avoid AJAX re-authentication, provide clipboard fallback and clear secrets on hide.';
}
if (!str_contains((string) $credential_controller_source, '$request->query->getBoolean(\'return_to_details\')')
    || !str_contains((string) $credential_controller_source, 'redirectToItem($itemtype, $items_id, $context_ticket_id, $is_in_modal, true, true)')
    || !str_contains((string) file_get_contents($root . '/src/Security.php'), 'data-credentials-navigate-top=')
    || !str_contains((string) file_get_contents($root . '/public/js/credentials.js'), "event.data.action === 'navigateTop'")
    || !str_contains((string) file_get_contents($root . '/public/js/credential-frame.js'), "postToParent('navigateTop'")
    || !str_contains((string) $form_template, "data-credentials-close-modal=\"1\"")
    || !is_file($root . '/tests/php/form_navigation_contract_test.php')
    || !is_file($root . '/tests/js/modal_parent_message_test.js')
    || !is_file($root . '/tests/js/save_navigation_frame_test.js')
) {
    $errors[] = 'Modal Cancel must close without POST and successful Save must perform a full top-level page reload.';
}

foreach (glob($root . '/front/*.php') ?: [] as $legacy_path) {
    $legacy_source = file_get_contents($legacy_path);
    if (!is_string($legacy_source) || !str_contains($legacy_source, "header('Location: '")) {
        $errors[] = 'Legacy front controllers must only redirect to Symfony routes: ' . basename($legacy_path);
    }
    if (is_string($legacy_source) && (str_contains($legacy_source, '$DB->request') || str_contains($legacy_source, 'CredentialHistory::'))) {
        $errors[] = 'Legacy front controller still contains business logic: ' . basename($legacy_path);
    }
}

foreach (glob($root . '/locales/*.po') ?: [] as $catalog) {
    $locale = basename($catalog, '.po');
    if (!in_array($locale, ['es_ES', 'es_MX', 'fr_FR'], true)) {
        $errors[] = 'Unexpected language catalog: ' . basename($catalog);
    }
    $catalog_text = file_get_contents($catalog);
    if (!is_string($catalog_text) || !str_contains($catalog_text, 'Credentials ' . $version . '\\n')) {
        $errors[] = 'Catalog version header is out of date: ' . basename($catalog);
    }
    if (!is_file($root . '/locales/' . $locale . '.mo')) {
        $errors[] = 'Compiled MO catalog is missing: ' . $locale;
    }
}

if (glob($root . '/locales/en_*.po') || glob($root . '/locales/en_*.mo')) {
    $errors[] = 'English PO/MO catalogs must not be generated.';
}

foreach (glob($root . '/templates/credential/*.html.twig') ?: [] as $template) {
    $source = file_get_contents($template);
    if (!is_string($source) || trim($source) === '') {
        $errors[] = 'Empty Twig template: ' . basename($template);
    }
}


$transfer_source = file_get_contents($root . '/src/CredentialTransfer.php');
if (!is_string($transfer_source)
    || !str_contains($transfer_source, 'public static function handle(array $params)')
    || !str_contains($transfer_source, 'CredentialItemLink::lockCredential(')
    || !str_contains($transfer_source, 'insertCredentialCopy(')
    || str_contains($transfer_source, 'decryptValue(')
    || str_contains($transfer_source, 'doQuery(')
    || !str_contains((string) $setup, 'Hooks::ITEM_TRANSFER')
    || !str_contains((string) file_get_contents($root . '/hook.php'), 'CredentialTransfer::handle($params)')
    || !is_file($root . '/tests/php/credential_transfer_test.php')
) {
    $errors[] = 'GLPI item transfers must preserve credential entity isolation, ciphertext and regression coverage.';
}


$transfer_test = file_get_contents($root . '/tests/php/credential_transfer_test.php');
if (!is_string($transfer_source)
    || !str_contains($transfer_source, '$credential_links = CredentialItemLink::getLinksForCredentialForUpdate($credential_id)')
    || !str_contains($transfer_source, 'self::containsLink($credential_links, $itemtype, $source_id)')
    || !str_contains($transfer_source, 'self::containsLink($credential_links, $itemtype, $destination_id)')
    || !str_contains($transfer_source, '$row = CredentialItemLink::getCredentialForUpdate($credential_id);')
    || !str_contains(strtolower((string) $transfer_test), 'source relationship removed during lock wait')
    || !str_contains(strtolower((string) $transfer_test), 'same-id move race')
    || !str_contains(strtolower((string) $transfer_test), 'concurrent credential edit while waiting for the row lock')
    || !str_contains(strtolower((string) $transfer_test), 'concurrent archive while the transfer waits')
    || !str_contains(strtolower((string) $transfer_test), 'clone path must also skip an archived source credential')
    || !str_contains(strtolower((string) $transfer_test), 'unexpected lock failure on a still-active credential')
    || !str_contains($transfer_source, 'lockAndReadCredential($credential_id, $itemtype, $items_id)')
    || !str_contains($transfer_source, 'lockAndReadCredential($credential_id, $itemtype, $source_id)')
    || !str_contains($transfer_source, 'CredentialItemLink::getCredentialForUpdate($credential_id)')
    || !str_contains((string) $credential_controller_source, 'unset($input[\'entities_id\'])')
    || !str_contains((string) $credential_controller_source, 'CredentialItem::loadReadableItem($itemtype, $items_id)')
    || !str_contains((string) $credential_controller_source, 'CredentialItemLink::exists($credential_id, $itemtype, $items_id)')
) {
    $errors[] = 'Clone, transfer and edit operations must revalidate current item relationships and entity state after acquiring the credential lock.';
}

// Administrative center, lifecycle metadata and encrypted backup contracts.
$admin_controller = file_get_contents($root . '/src/Controller/ConfigController.php');
$admin_service = file_get_contents($root . '/src/Service/CredentialAdminService.php');
$backup_service = file_get_contents($root . '/src/Service/CredentialBackupService.php');
$credential_source = file_get_contents($root . '/src/Credential.php');
$profile_source = file_get_contents($root . '/src/Profile.php');
$category_model = file_get_contents($root . '/src/CredentialCategory.php');
if (!str_contains((string) $setup, "Hooks::CONFIG_PAGE]['credentials'] = 'config'")
    || !is_file($root . '/src/Controller/ConfigController.php')
    || !str_contains((string) $admin_controller, "#[Route('/config'")
    || !is_file($root . '/templates/config.html.twig')
    || !str_contains((string) $admin_controller, "Session::checkRight('config', UPDATE)")
    || !str_contains((string) $admin_controller, 'Credential::RIGHT_BACKUP')
    || !is_file($root . '/src/Service/UpdateChecker.php')
    || !str_contains((string) file_get_contents($root . '/src/Service/UpdateChecker.php'), 'releases/latest')
) {
    $errors[] = 'The independent Credentials administration page and stable-release checker are required.';
}
foreach ([
    'src/CredentialCategory.php',
    'src/CredentialStatus.php',
    'src/Service/CredentialAdminService.php',
    'src/Service/CredentialBackupService.php',
    'tests/php/credential_status_test.php',
    'tests/php/credential_admin_contract_test.php',
    'tests/php/credential_backup_contract_test.php',
    'templates/config.html.twig',
] as $required_admin_file) {
    if (!is_file($root . '/' . $required_admin_file)) {
        $errors[] = 'Administrative functionality file is missing: ' . $required_admin_file;
    }
}
if (!str_contains((string) $hook, 'glpi_plugin_credentials_categories')
    || !str_contains((string) $hook, "'date_expires'")
    || !str_contains((string) $hook, "'date_last_review'")
    || !str_contains((string) $hook, "'date_next_review'")
    || !str_contains((string) $hook, "'reviewer_users_id'")
    || !str_contains((string) $credential_controller_source, 'CredentialCategory::getOptions($entity_id)')
    || !str_contains((string) $credential_controller_source, 'CredentialStatus::fromDates(')
    || !str_contains((string) $category_model, 'deleteIfUnused(')
    || !str_contains((string) $admin_service, 'purgeEligible(')
    || !str_contains((string) $admin_service, 'getLinksForCredentialForUpdate($id) !== []')
) {
    $errors[] = 'Categories, expiration/review metadata and safe archived-record lifecycle handling must be present.';
}
if (!str_contains((string) $credential_source, 'RIGHT_BACKUP')
    || !str_contains((string) $profile_source, 'Credential::RIGHT_BACKUP')
    || !str_contains((string) $backup_service, 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
    || !str_contains((string) $backup_service, 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')
    || !str_contains((string) $backup_service, 'Credential::encryptValue($entry[$field])')
    || !str_contains((string) $backup_service, 'Session::haveAccessToEntity((int) ($reviewer[\'entities_id\'] ?? -1))')
    || !str_contains((string) $admin_service, 'CredentialItemLink::getLinksForCredentialForUpdate($credential_id) !== []')
    || !str_contains((string) $admin_controller, "Route('/config/export'")
    || !str_contains((string) $admin_controller, "Route('/config/import'")
) {
    $errors[] = 'Encrypted credential export/import must require a separate profile right and re-encrypt secrets on import.';
}
foreach (['es_ES', 'es_MX', 'fr_FR'] as $locale) {
    if (!is_file($root . '/locales/' . $locale . '.po') || !is_file($root . '/locales/' . $locale . '.mo')) {
        $errors[] = 'All supported translations must include updated PO and MO catalogs: ' . $locale;
    }
}

$transfer_metadata_source = file_get_contents($root . '/src/CredentialTransfer.php');
$transfer_regressions = file_get_contents($root . '/tests/php/credential_transfer_test.php');
foreach (['plugin_credentials_categories_id', 'date_expires', 'date_last_review', 'date_next_review', 'reviewer_users_id'] as $transfer_field) {
    if (!str_contains((string) $transfer_metadata_source, "'" . $transfer_field . "'")
        || !str_contains((string) $transfer_regressions, $transfer_field)
    ) {
        $errors[] = 'Entity transfers must preserve credential lifecycle field: ' . $transfer_field;
    }
}
if (!str_contains((string) $transfer_metadata_source, 'mapCategory(')
    || !str_contains((string) $transfer_regressions, 'destination-entity category')
) {
    $errors[] = 'Cross-entity transfers must map entity-scoped credential categories and include regression coverage.';
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "FAIL: {$error}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Credentials {$version}: package structure checks passed.\n");
fwrite(STDOUT, "Note: live GLPI authorization, browser and Marketplace review tests remain separate acceptance steps.\n");
