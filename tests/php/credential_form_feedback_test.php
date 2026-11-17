<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$controller = file_get_contents($root . '/src/Controller/CredentialController.php');
$form_template = file_get_contents($root . '/templates/credential/form.html.twig');

function assertCredentialFormFeedback(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

assertCredentialFormFeedback(
    is_string($controller) && str_contains($controller, 'Session::addMessageAfterRedirect('),
    'a committed credential save must enqueue a GLPI notification'
);
assertCredentialFormFeedback(
    str_contains($controller, "__('Credential updated successfully.', 'credentials')")
        && str_contains($controller, "__('Credential created successfully.', 'credentials')"),
    'create and update must have distinct translated success notifications'
);
assertCredentialFormFeedback(
    str_contains($controller, 'catch (\\Throwable $exception)')
        && str_contains($controller, 'The save operation failed because of an unexpected error.'),
    'unexpected save exceptions must be converted into a safe user-facing error'
);
assertCredentialFormFeedback(
    str_contains($controller, 'The credential could not be updated or recorded in GLPI history. No changes were saved.')
        && str_contains($controller, 'The credential could not be created or recorded in GLPI history. No credential was saved.'),
    'failed transaction/history writes must keep their explicit failure messages'
);
assertCredentialFormFeedback(
    is_string($form_template)
        && str_contains($form_template, 'save_attempted')
        && str_contains($form_template, 'aria-live="assertive"')
        && str_contains($form_template, 'The credential could not be saved. Review the errors below; no changes were saved.'),
    'failed POST submissions must show a prominent accessible error summary'
);

foreach (['es_ES', 'es_MX', 'fr_FR'] as $locale) {
    $catalog = file_get_contents($root . '/locales/' . $locale . '.po');
    assertCredentialFormFeedback(
        is_string($catalog)
            && str_contains($catalog, 'msgid "Credential created successfully."')
            && str_contains($catalog, 'msgid "Credential updated successfully."')
            && str_contains($catalog, 'msgid "The credential could not be saved. Review the errors below; no changes were saved."'),
        "{$locale} must translate create, update and failure feedback"
    );
}

echo "Credential form save feedback regressions: OK\n";
