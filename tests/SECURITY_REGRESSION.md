# Credentials security and GLPI acceptance matrix

The repository CI performs static checks only. Before marking a Marketplace release stable, run the following tests against a clean GLPI 12.0.x instance and a second instance upgraded from the prior supported plugin release.

## Profiles and object authorization

- [ ] Profile with no `plugin_credentials` right cannot see the Credentials tab, call a controller URL directly, link a credential, reveal a password, or use a legacy redirect URL.
- [ ] READ-only profile can list non-secret labels but cannot create, edit, unlink, delete, or link.
- [ ] UPDATE without DELETE cannot delete; DELETE without UPDATE cannot change associations.
- [ ] `plugin_credentials_reveal` is independent: profile can read credential metadata but cannot reveal plaintext without the reveal right.
- [ ] Re-authentication expiry blocks details, edit, link, reveal, unlink and delete, including direct POST requests.
- [ ] A user cannot replace `itemtype`, `items_id` or credential ID to access another item's credential (IDOR).
- [ ] A cross-entity credential link is rejected. Test child/parent entity visibility and recursive entity rights.
- [ ] A `context_ticket_id` is accepted only when the source is actually a requester user or an item attached to that ticket.
- [ ] Ticket counts and source labels omit users/assets the active profile cannot read; counts do not select secret columns.

## Input, output and browser behavior

- [ ] Names and notes containing HTML, quotes, script tags and Unicode are rendered as text; no script executes.
- [ ] `javascript:`, `data:`, URLs with embedded usernames/passwords, malformed URLs and control characters are rejected or rendered as text, never linked.
- [ ] Maximum input lengths are tested at boundary and above boundary, including multibyte UTF-8 values.
- [ ] POST requests from a cross-site origin are rejected by GLPI 12 Fetch Metadata / CheckCsrfListener behavior.
- [ ] Modal create, edit, reveal, cancel and save remain in the iframe; Edit Cancel returns to the detail view, Add Cancel closes the modal, and only the Credentials tab refreshes after a successful mutation.
- [ ] Unlink/delete forms ask for confirmation; declining prevents the POST and accepting proceeds.
- [ ] Password, username, URL and notes responses are not stored by browser cache, proxy cache or service worker.
- [ ] Password values do not appear in HTML when hidden, query strings, referrers, logs, history, JavaScript console or error messages. The initial hidden-password hint and revealed value remain readable in light and dark themes.
- [ ] Credential responses and redirects carry `Cache-Control: no-store`, `Referrer-Policy: no-referrer` and `X-Content-Type-Options: nosniff`.

## Encryption and lifecycle

- [ ] DB inspection confirms username, password, URL and notes are ciphertext, including empty optional values represented as NULL/empty as designed.
- [ ] Invalid/missing `glpicrypt.key` disables writes and does not expose ciphertext as plaintext.
- [ ] Run `php bin/console security:change_key` with the plugin enabled; verify all four protected fields still decrypt afterward.
- [ ] Database backup plus matching `glpicrypt.key` restores values; database-only restore is documented as insufficient.
- [ ] Install creates both plugin-owned tables and both profile rights; reinstall/update is idempotent and preserves existing rows.
- [ ] Update adds only declared schema/right changes; install/update runs `Migration::executeMigration()` and clears GLPI cache.
- [ ] Uninstall removes only plugin-owned rows/tables/rights; permanent plugin directory cleanup is handled by GLPI.
- [ ] Purging a linked user/asset inside a GLPI transaction removes its relation and archives an orphaned credential without committing GLPI's outer transaction; the surviving credential history records `HISTORY_ORPHANED` without secret data.
- [ ] Confirm the additive `link_lock_token` migration preserves all existing credential rows and values on upgrade.
- [ ] Confirm an orphaned/trashed credential retains ciphertext by design; document the instance retention and backup policy, and do not treat soft-archive as cryptographic erasure.
- [ ] Deleting a credential removes every relation and moves the credential into GLPI trash in a single transaction.

## Runtime compatibility

- [ ] Run on the earliest supported GLPI 12.0.x and latest available GLPI 12.0.x patch release.
- [ ] Run the complete PHP CLI syntax and regression suite on PHP 8.3, 8.4, 8.5 and 8.6. PHP 8.6 is a required CI compatibility leg; the CI result must be green and must not use `continue-on-error`.
- [ ] Exercise user, core asset, custom asset type, ticket, multiple requester and multiple ticket-item paths.
- [ ] Confirm no PHP warnings/notices/deprecations, Symfony route errors, Twig errors, DB errors or browser console errors.
- [ ] Run GLPI coding-standard checks (`phpcs --standard=vendor/glpi-project/coding-standard/GlpiStandard/`) and review every exception.

## Release evidence

Attach the CI run, GLPI version/PHP matrix, sanitized screenshots of the three main views, and the SHA-256 of the exact release archive to the Marketplace submission. Never attach real credential data or production screenshots containing secrets.

## Browser and modal behavior
- [ ] Password reveal uses an interactive same-iframe POST so GLPI 12 can prompt/replay native re-authentication; the response is no-store, hide clears plaintext, copy writes to the clipboard and reports success/failure.
- [ ] Cancel closes the native modal without parent-page reload.
- [ ] Credential IDs are visible and can be used to link existing entries.

## Association and deletion invariants
- [ ] With one relationship, unlink is hidden/disabled and a warning explains that the final link cannot be removed; direct POST is rejected too.
- [ ] With two or more relationships, unlinking one preserves at least one active relationship.
- [ ] Concurrent link/unlink/delete/purge requests cannot violate the last-link invariant: the GLPI DB API atomically updates the credential lock token inside a transaction, and entity/active-state checks are included in the locking update.
- [ ] A relink request revalidates `is_deleted`, entity, access rights and reachability after obtaining the lock.
- [ ] Deleting a credential removes all relationships intentionally; purging a parent item archives its credential only when no other item remains.


## Marketplace remediations and regression coverage (0.0.27)

- [ ] Verify `credential_lock_test.php` and the complete PHP/JS suite in CI.
- [ ] Verify the new lock-token migration on a copy of a populated 0.0.26 database; confirm no credential values or relationships change.
- [ ] Re-run PHPCS GLPI Standard and review its full report.

## Modal UX and navigation regressions (0.0.25)

- [ ] Cancel in add/edit dialog closes the native GLPI iframe modal and sends no POST.
- [ ] Cancel in an edit opened from credential details returns to details in the same iframe.
- [ ] Successful add/edit navigates the top-level item page, refreshing all details and counters.
- [ ] Parent accepts modal title/close messages only from same-origin modal iframes.
- [ ] Password reveal submits an interactive same-origin HTML POST within the iframe, lets GLPI 12 prompt/replay native re-authentication, shows an in-progress state, and renders plaintext only in a no-store response.
- [ ] Delete credential has a solid red fill, white text and visible hover/focus states on light and dark themes.


## Administrative center, categories and lifecycle (0.0.32)

- [ ] Configuration page is available only to profiles with GLPI configuration update permission.
- [ ] GitHub checker reports update available, up to date, no published stable release, stale cached result and network unavailable without breaking credential tabs.
- [ ] Release checker ignores drafts and prereleases, accepts only valid version tags, validates release URLs and reuses its six-hour cache.
- [ ] Category creation rejects empty, oversized and duplicate names and respects the active entity. A category in use cannot be deleted.
- [ ] Credential form validates category entity, date values and review-responsible user access; failed edits do not lose the current password.
- [ ] Expiration and review indicators correctly classify dates before, within and beyond the 30-day attention window.
- [ ] Dashboard totals and attention rows include only entities visible to the current profile.
- [ ] Audit view shows only currently readable source items and credential records; no activity from inaccessible entities is exposed.
- [ ] Restore requires credential update permission, same-entity item update permission, archived state and no existing links; successful restore creates a link and secret-free history.
- [ ] Retention value 0 disables permanent deletion and retains archived records indefinitely.
- [ ] Purge requires Credential READ and DELETE rights in addition to configuration access. It only deletes archived records older than the configured period, after row locking and confirming there are no relationships; history is recorded before deletion.
- [ ] Uninstall removes the new profile right and category table; upgrade does not remove existing credentials.

## Encrypted export/import (0.0.32)

- [ ] Export/import require the dedicated backup profile right; import additionally requires credential create permission.
- [ ] Backup export asks for a matching passphrase of at least 12 characters and downloads a no-store encrypted file.
- [ ] Backup file uses Argon2id key derivation and authenticated XChaCha20-Poly1305 encryption; tampering and an incorrect password fail closed.
- [ ] Export never includes records outside accessible entities or associations to unreadable items.
- [ ] Export output and import input stay within the 20 MB limit; maximum entries and links per entry are enforced.
- [ ] Import validates format/version/KDF parameters, entity path, allowed item types, exact item ID/name/entity and user access before creating records.
- [ ] Import creates new credential IDs and never updates existing credential rows.
- [ ] All imported protected values are re-encrypted with the destination GLPIKey; test with a destination instance using a different glpicrypt.key.
- [ ] Invalid entries are skipped with non-sensitive messages; failed encryption, database insert, association or audit causes the transaction to roll back.
- [ ] Import does not restore archived entries as active; an explicit later restore is required. Active source entries whose linked items cannot be matched in the destination are imported as archived so their protected data is retained for manual relinking.
- [ ] No passphrase, plaintext password, ciphertext or protected notes appear in logs, redirects, query strings or error messages.
- [ ] Backups include archived data only when the administrator selects the option; imported archived entries remain archived.
