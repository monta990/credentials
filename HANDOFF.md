# HANDOFF — Credentials 1.0.2 (security remediation)

## Current state

- Plugin version: 1.0.2
- GLPI compatibility: 12.0.x
- Minimum PHP: 8.3
- Source language: English; translations: es_ES, es_MX and fr_FR
- License: GPL-3.0-or-later
- This branch contains the 1.0.2 remediation for Teclib's GLPI 12 security review.
- The release has not been published; use the CI artifact for local testing before release.

## Security fix: archived credential restore

- src/Credential.php: restore is included in getForbiddenStandardMassiveAction().
- Credential::restore(array $input, $history = true) rejects calls outside withTrustedWrite() and only then delegates to CommonDBTM::restore().
- Generic GLPI restore and restore massive actions cannot reactivate archived credentials through this model.
- CredentialAdminService administrative restore workflows continue using their guarded transaction/DB updates, configuration and plugin rights, entity/link validation, and linked-item history.

## Additional review maintenance

- CredentialCategory::withEntityLock() checks for the plugin-owned lock row before seeding it. Existing rows avoid routine duplicate-key INSERT attempts; the atomic UPDATE remains the InnoDB serialization point. A first-insert race remains handled by the update result.
- Uninstall removes UpdateChecker's two known non-secret cache files and removes the plugin documentation directory only when empty.
- The Profile rights tab displays an administrator warning when plugin-right records are missing. The message is translated in es_ES, es_MX and fr_FR.

## Preserved behavior and data

No schema migration was added. Credential records, links, GLPIKey encryption, entity boundaries, backup format, reminders, ticket aggregation, and administrative restore flows were not redesigned. The FOR UPDATE query-builder concern and entities_id = 0 category-recursion semantics remain unchanged pending dedicated integration coverage.

## Validation

- Local restore-guard regression and PHP syntax checks passed with PHP 8.4.24.
- JavaScript syntax, XML parsing, PO/MO compilation, installable ZIP structure and SHA-256 were checked locally.
- PHP 8.3, 8.5 and 8.6, PHPCS, PHPStan and live GLPI 12 behavior must be verified by CI/staging. The CLI regression is a model-boundary test, not a substitute for testing GLPI's real mass-action processor.

## Deployment and testing

1. Back up the GLPI database together with its matching glpicrypt.key.
2. Install the CI artifact in a GLPI 12.0.x staging instance; do not uninstall to upgrade.
3. Reproduce Teclib's case with an archived credential imported with links and a user who can delete and update one linked item but cannot update plugin configuration.
4. Verify generic/mass-action Restore fails and is_deleted remains 1.
5. Verify the administration page can still restore a credential when every entity, rights and link invariant is satisfied, with history on every linked item.
6. Run the full CI matrix and static analysis before release.

## Next step

Inspect the push-triggered CLI, PHPCS, PHPStan and package jobs; test the 1.0.2 installable ZIP before creating a release.
