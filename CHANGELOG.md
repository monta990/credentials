## 0.0.35 — 2026-11-13

- Fixed notification diagnostics so GLPI's global mail-channel filter cannot make an existing email template appear missing; the dashboard now reports a separately disabled global email mode.
- The notification diagnostic uses GLPI's recursive entity restriction and only accepts a Review responsible target attached to the same notification that owns the email template.
- Added regressions for the disabled mail mode, unrelated notification targets and entity scoping.
- Added entity-scoped diagnostics for the complete native GLPI lifecycle notification setup, using the same event, email-template and Review responsible target checks as the reminder scheduler.
- The Credentials configuration page now distinguishes a missing active event notification, a missing email template, a missing Review responsible recipient, and a ready configuration for the active entity.
- Refactored the scheduler's notification eligibility check to share the same diagnostic logic, avoiding divergence between what the administration page reports and what the cron accepts.
- Added regressions for each incomplete notification configuration state and for a fully configured email notification, including the exact reviewer-target query scope.
- Updated administrator documentation, handoff notes and locale catalogs; this PR is stacked on PR #5 and proposes version 0.0.35.

## 0.0.34 — 2026-11-13

- Added administrator controls to enable or pause lifecycle email reminders and choose a look-ahead period from 1 to 365 days; existing installations keep reminders enabled with the original 30-day default.
- Added live configuration diagnostics for GLPI global email notifications and the Credentials automatic action, distinguishing missing, enabled and disabled/blocked task states.
- Reminder processing now uses the configured window while continuing to include overdue dates and to deduplicate by credential, entity, due date and reviewer.
- Added regressions for 60-day custom windows, the 365-day upper limit, defaults on upgrade, disabled settings, numeric normalization and invalid stored values.
- Updated README, Spanish documentation, administrative translations and the HANDOFF for the configurable reminder workflow.

## 0.0.33 — 2026-11-13

- Added a daily GLPI automatic action for expiration and review reminders, covering overdue dates and dates within the next 30 days.
- Added a native GLPI notification event and a dedicated **Review responsible** recipient so reminders are delivered through the configured GLPI notification template and queue.
- Reminders are sent only when notifications are enabled, an active event/template and reviewer recipient are configured for the credential entity, and the assigned reviewer is active, has entity access and has a default email address.
- Added an idempotency ledger that prevents repeated reminders for the same credential, entity, due date and reviewer. Updating the due date or changing the reviewer can create a new reminder; equal expiration and review dates share one notice.
- Notification processing selects and exposes only the credential ID, name, entity, reviewer and due dates. Login, password, URL, notes and encrypted values are not included in the notification payload or template tags.
- Archived credential purge and plugin uninstall now clean up reminder markers and the registered automatic action.
- Added regressions for overdue/upcoming dates, invalid dates, combined same-day reminders, recipient targeting and protected-field non-disclosure.

## 0.0.32 — 2026-11-12

- Added an independent GLPI configuration page for Credentials, keeping the user, asset and ticket tabs focused on day-to-day credential work.
- Added a GitHub stable-release checker with a six-hour cache, manual refresh and clear states for update available, up to date, no published release and GitHub unavailable.
- Added entity-aware credential categories and fields for expiration date, last review, next review and review responsible person.
- Added a dashboard summary for active/archived records, expiring credentials and overdue/upcoming reviews.
- Added a recent activity view based on GLPI credential history events, scoped to items the current profile can read and excluding protected values.
- Added archived credential review, restore by linking to a valid item, configurable retention and an explicit permanent-deletion action for eligible unlinked records.
- Added a separate profile permission for credential backup export/import.
- Import now keeps credentials archived when linked destination items cannot be matched, allowing an administrator to restore and relink them manually instead of losing the credential data.
- Administrative audit and archive details respect the Credentials read permission; permanent archive purge additionally requires the Credentials delete permission.
- Added password-protected backup files using Argon2id key derivation and authenticated XChaCha20-Poly1305 encryption. Import validates destination entities and item associations, creates new records only, and re-encrypts protected values with the destination GLPI security key.
- Added regression checks for lifecycle statuses, administrative controls and encrypted backup contracts. Updated Spanish (Mexico), Spanish (Spain) and French translations.

## 0.0.31 — 2026-11-09

- La transferencia ahora trata como obsoletos los IDs de credencial cuyo registro fue archivado, cambió de entidad o perdió el vínculo exacto de origen mientras se esperaba el bloqueo. La decisión se basa en lecturas actuales bloqueantes, no en el snapshot anterior de InnoDB.
- Se aplica tanto al movimiento con ID conservado como a la clonación con ID nuevo. Una relación retirada no se recrea y una credencial archivada no se copia.
- Si la credencial sigue activa y conserva el vínculo exacto de origen, un fallo al adquirir el bloqueo continúa como error explícito.
- Añadidas regresiones para archivado concurrente en movimiento y clonación, así como para un fallo de bloqueo inesperado con la relación todavía activa.
- Aclarada la semántica de bloqueo de la lectura actual de la fila de credencial.
- Incrementados la versión del plugin y los metadatos de traducción a 0.0.31.

## 0.0.30 — 2026-11-09

- Added a current InnoDB locking read for credential rows after the transfer lock is acquired. Cross-entity copies now use the latest committed name and GLPIKey ciphertext for login, password, URL and notes instead of values retained by an older REPEATABLE READ snapshot.
- Fixed the package validator's transfer-regression markers and added explicit checks that transfer reloads credential fields through the current locking read.
- Added a regression that simulates a credential edit committed while a transfer waits for the row lock and verifies every copied protected field is the latest ciphertext.
- Extended the DBmysql current-read regression to verify the credential-row query is ID-scoped, quoted and uses FOR UPDATE.
- Incremented plugin and translation metadata to 0.0.30.

## 0.0.29 — 2026-11-09

- Added a narrowly scoped InnoDB current locking read for credential relationships. It uses GLPI 12's `DBmysql::doQuery()` with fixed identifiers and values quoted through GLPI's `DBmysql::quoteName()` and `quoteValue()`, because the GLPI query builder does not expose a `FOR UPDATE` option.
- Same-ID entity transfers and new-ID clones now recheck the current relationships after acquiring the credential row lock. Links removed while the transfer waited are skipped instead of being restored or causing the GLPI transfer to fail.
- Purge now checks surviving links with the same current read, so a relationship committed while purge waits prevents an incorrect orphan archive.
- Added snapshot-aware regressions for clone/move stale-link races, purge survivors committed during a lock wait and the generated locking query.
- Incremented plugin and translation metadata to `0.0.29`.

## 0.0.28 — 2026-11-09

- Revalidated the exact source-item relationship after acquiring the credential row lock before copying links for a cloned item. This prevents a stale pre-lock ID list from restoring a relationship concurrently removed by a user.
- Hardened credential edits against stale forms submitted after a parent item transfer: lock the credential, reload and authorize the current parent, recheck the exact relationship and credential entity, and omit the stale form's `entities_id` from the update.
- Added a race regression that removes the source relationship after the transfer lock and verifies no destination credential/link is created.
- Registered GLPI 12's native `ITEM_TRANSFER` hook so credentials follow supported users and assets when GLPI moves or clones them between entities.
- When an item keeps its ID, a single-link credential moves to the destination entity; a shared credential is copied with its encrypted fields unchanged and only the moved item's relationship is reassigned. Other source-entity relationships remain untouched.
- When GLPI creates a new item ID, the copy receives a destination-entity credential copy when crossing entities; same-entity copies reuse the existing credential. The original item's relationships are preserved.
- Kept the transfer operation transactional, serialized against credential edits using the existing row-lock token, and audited through secret-free native GLPI history entries.
- Added executable regressions for single-link moves, shared-credential splitting, cross-entity clones, same-entity clones and byte-for-byte ciphertext preservation.
- Bumped plugin and translation metadata to `0.0.28`.

## 0.0.27 — 2026-11-08

- Replaced the runtime `SELECT ... FOR UPDATE` statement with a GLPI database-API `UPDATE` that writes a per-credential random lock token. The affected-row check distinguishes a locked credential from a missing or stale row; the InnoDB write lock remains held by the surrounding transaction.
- Added an additive `link_lock_token` migration for existing installations and included the field in new installs. Existing credential records and encrypted values are not rewritten or deleted by the migration.
- Made link-existing, unlink and delete revalidate the active state, entity, authorization and current relationship after acquiring the lock. The locking update includes expected entity and `is_deleted = 0` criteria to reject stale requests atomically.
- Sorted credential IDs before multi-row purge locking to keep acquisition order deterministic and reduce deadlock risk.
- Added secret-free `HISTORY_ORPHANED` logging to the credential's own GLPI history when a purged parent leaves it without relationships.
- Added a PHP regression for lock-token generation, affected-row handling, entity/active-state conditions and absence of raw runtime SQL; expanded package validation to require the migration and test.
- Documented that orphan archival is a soft-delete and does not itself cryptographically erase retained ciphertext; instance retention and backup policy remain administrator responsibilities.
- Kept the manifest in `development`: repository, public download, documentation, issue tracker and screenshots URLs are still not available in the provided source package and must not be invented.
- Bumped plugin and translation metadata to `0.0.27`.

## 0.0.26 — 2026-11-08

- Changed the revealed secret presentation from a styled `<code>` element to a readonly text input. The password remains selectable, but now uses an explicit theme-aware text color and `-webkit-text-fill-color` so Chromium/GLPI theme rules cannot leave the value invisible while selected.
- Updated copy/hide handling to read and clear the input `value`; the manual-copy fallback selects the form control directly rather than trying to create a text range over a form control.
- Fixed the successful create/edit top-level refresh path. The iframe response now loads the external `credential-frame.js` helper with a server-generated same-origin destination; the parent GLPI page validates the message origin, source iframe and destination origin before navigating itself. This avoids inline-script CSP blocking and iframe sandbox top-navigation restrictions.
- Added executable regressions for readonly password value visibility, browser text-fill styling, clipboard selection, same-origin save navigation and rejection of cross-origin destinations. CI and package validation require the new tests.
- Preserved the PHP 8.3, 8.4, 8.5 and required 8.6 CLI matrix.
- Bumped plugin and translation metadata to `0.0.26`.

## 0.0.25 — 2026-11-08

- Replaced the AJAX-only password reveal endpoint with a normal POST to the protected detail route. GLPI 12 cannot display/replay its native re-authentication prompt for AJAX requests; an interactive iframe POST lets the core prompt and replay the action safely.
- The controller checks the separate reveal right before re-authentication, revalidates the active credential relationship, entity and READ right, decrypts the secret, records a secret-free GLPI history event and renders plaintext only in a no-store/no-cache response.
- Copy and Hide remain local iframe actions; Hide clears plaintext from the DOM and restores the reveal form. Copy retains Clipboard API and the `execCommand` fallback with visible feedback.
- Changed the details modal title to the recognizable Tabler key icon for the credential title, normalized injected title-icon sizing in every iframe fallback, and made Delete credential use a solid red fill independent of theme CSS variables.
- Moved all GLPI 12 frontend resources into `public/css/` and `public/js/`; hook paths remain relative to `public/` and iframe scripts use the corresponding public plugin URLs. The package validator now rejects root-level `css/` or `js/` assets and checks every registered resource exists.
- Added regression tests for interactive POST/re-authentication, authorization/audit order, server-rendered reveal, copy fallback, hide and pagehide clearing. PHP 8.6 remains a required CI matrix leg.
- Bumped plugin and translation metadata to `0.0.25`.

## 0.0.24 — 2026-11-07

- Fixed modal Cancel behavior: Add closes the active GLPI dialog without submitting; Edit from credential details returns to those details in the same iframe; standalone modal Edit closes without sending a POST.
- Successful Add/Edit now navigates the top-level GLPI window to the originating asset, user or ticket page so the complete item view and counters are refreshed. This is intentionally different from Cancel.
- Added a validated same-origin `postMessage` bridge between credential iframes and the parent GLPI modal to set Tabler title icons and close the native dialog safely.
- Updated password reveal fetch handling to expose HTTP and re-authentication failures in the UI instead of silently rejecting redirected responses before they can be diagnosed.
- Added a visible red outline to the destructive Delete credential action.
- Added regression tests for parent modal messaging, Save/Cancel navigation, and the actual iframe password script; updated CI/package validation.
- Kept PHP 8.6 in the required CLI CI matrix with PHP 8.3, 8.4 and 8.5.
- Bumped plugin and translation metadata to `0.0.24`.

## 0.0.23 — 2026-11-07

- Fixed the confirmation attribute on Unlink and Delete forms inside the details iframe. Both now use `data-credentials-confirm`, matching the iframe-local listener so cancellation blocks the destructive POST.
- Added `tests/js/detail_confirmation_test.js` to execute the real iframe script and verify cancel/confirm behavior for Unlink and Delete. CI and package validation require this regression.
- Bumped plugin and translation metadata to `0.0.23`.

## 0.0.22 — 2026-11-07

- Replaced inline credential-detail JavaScript with a dedicated external iframe script. Password reveal is a non-submit button and now sends a same-origin JSON-only POST with redirects rejected, preventing full-page/print-like content from replacing the detail view.
- Rebuilt copy/hide behavior against the actual iframe script: clipboard API attempts both host and iframe contexts, falls back to a selected temporary textarea, reports success/failure visibly, restores controls on hide and clears plaintext on iframe pagehide.
- Added an explicit iframe form helper so Add/Edit Cancel always closes the active GLPI modal without submitting or changing the source tab; Save closes and refreshes the source Credentials tab, while Save from a detail-originated edit returns to the updated details view. A success alert distinguishes Save from Cancel.
- Added a clickable linked-item count in the detail modal. From an existing iframe detail view it navigates the same iframe to a linked-item table (avoiding unsupported nested GLPI modals); the main tab count still opens a native GLPI dialog. The table shows each readable item type, ID, name and whether it is the current item, with a return link and a warning for relationships hidden by GLPI permissions. Ticket context is preserved and revalidated.
- Added a protected no-store `TabCount` JSON route. After a changed dialog closes, only the active GLPI tab is refreshed and its badge is recalculated; no full-page reload is requested. Ticket badge counts use the same requester/asset aggregation as the ticket list.
- Fixed ticket sudo-protected ID accumulation so protected credentials from multiple requester/item sources are merged instead of one source overwriting another.
- Increased tab action icon hit areas to 56px, enlarged Tabler glyphs and retained GLPI ghost button variants; improved password/status contrast and readability.
- Added a GLPI 12 contract simulation around the actual `CredentialTicketTab::getCredentialRows()` implementation. It exercises requester + ticket-item deduplication, unlink, relink, zero-count and multiple protected source scenarios.
- Replaced the prior JS test that extracted inline Twig with tests executing the actual iframe script, and added modal Cancel/title and live-tab-count regressions. PHP 8.6 remains in the required CLI CI matrix.
- Added a linked-item contract simulation that verifies distinct itemtype/ID pairs, duplicate-row deduplication, same-iframe navigation and return context. Added Spanish and French translations for the linked-item table labels and return action.
- Bumped plugin and translation metadata to `0.0.22`.

## 0.0.21 — 2026-11-07

- Replaced password reveal's full iframe POST render with a dedicated protected GLPI Symfony JSON endpoint under `/ajax/Credential/Reveal`. The JSON response is no-store/no-cache, re-checks the item, credential, entity and independent reveal right, and writes the secret-free history event before returning plaintext.
- Made reveal asynchronous in the modal: it now updates only the password controls without navigating or flashing/reloading the iframe. Hide clears plaintext from the DOM and restores the reveal form; Copy uses a same-origin clipboard fallback and provides visible success/failure feedback.
- Improved readability of the hidden-password explanation, revealed password and form help text in dark/light themes; enlarged the revealed secret typography.
- Made Cancel context-aware: Add closes the native modal while Edit returns to credential details inside the same iframe. Successful save marks the modal dirty so only the originating Credentials tab refreshes when the modal closes.
- Fixed the modal Cancel action to close the current native GLPI dialog. Add/edit/details modal titles now receive Tabler icons using DOM node creation (no HTML interpolation).
- Increased modal action and form-control sizes; improved password readability/contrast and registered scoped CSS through GLPI's `ADD_CSS` hook.
- Added a visible Credential ID and linked-item count to user/asset and ticket lists, plus the ID in the details view so administrators can link existing records from another item.
- Enforced the non-orphan invariant: explicit unlink is blocked when it would remove the final item relationship, shown as a warning and disabled action, and checked again under a credential-row `FOR UPDATE` lock. The policy is enforced in both the controller and relationship model. Whole-credential deletion remains an explicit separate action; purge of a parent item archives a credential only when its parent is permanently removed.
- Added executable regression tests for last-link policy and reveal/copy/hide/cancel behavior; expanded package validation and CI while retaining PHP 8.6 as a required CLI matrix leg.
- Bumped plugin and translation metadata to `0.0.21`.

## 0.0.20 — 2026-11-06

- Added PHP 8.6 to the required GitHub Actions CLI compatibility matrix alongside PHP 8.3, 8.4 and 8.5. The PHP 8.6 job uses the PHP-8.6 nightly branch while the release is pre-release; it is a required CI leg and does not use `continue-on-error`.
- Added `tools/run-cli-tests.sh` to verify the exact PHP CLI minor version, lint every plugin PHP file and execute every PHP regression test under each matrix runtime.
- Added `tests/php/cli_runtime_test.php` so a runner cannot silently execute tests under a different preinstalled PHP version.
- Extended the package validator and Marketplace security regression matrix to require PHP 8.6 CLI coverage. Kept the production PHP minimum at 8.3; PHP 8.6 preview CI coverage is not a declaration of production support.
- Bumped plugin and translation metadata to `0.0.20`.

## 0.0.19 — 2026-11-06

- Migrated credential details, create/update and ticket aggregation to GLPI 12 Symfony plugin controllers with Twig views; legacy `front/` endpoints now only redirect for backward compatibility.
- Added strict validation that a ticket return context is actually related to the requester user or asset from which a credential was opened, preventing forged unrelated ticket contexts.
- Made credential deletion remove all links and write secret-free history entries for every affected item in one nested transaction; purging a user/asset removes its links and archives orphaned credentials without committing GLPI's outer transaction.
- Corrected nested DB transaction handling to use GLPI's public `beginTransaction()`, `commit()` and `rollBack()` APIs. GLPI 12 implements nested transaction levels with savepoints; the plugin no longer calls the private `DBmysql::isInTransaction()` method or treats void transaction methods as booleans.
- Ensured profile-right rows are checked for successful creation and made initialization fail closed when a right cannot be persisted.
- Executed the GLPI `Migration` after declaring plugin rights, cleared GLPI caches after installation/update, and retained static DDL only for initial table creation/removal in lifecycle hooks.
- Corrected documentation and compatibility metadata; kept the manifest in `development` state because public repository/release URLs and live GLPI acceptance evidence have not been provided.
- Added reproducible locale compilation, package validation, PHP 8.3/8.4/8.5 CI coverage, executable nested-savepoint and destructive-action confirmation regression tests, and a dedicated OWASP/authorization/lifecycle regression matrix.
- Standardized no-store/no-referrer response headers on credential pages and redirects; controllers no longer emit raw PHP headers.
- Made encryption readiness validate both GLPIKey read status and an actually loaded key; destructive unlink/delete forms now have delegated confirmation handling.
- Registered the Credential CommonDBTM class with GLPI so native `Log::history()` linked-item events can resolve the plugin itemtype and render its translated event description.
- Bumped plugin and translation metadata to `0.0.19`.

## 0.0.18 — 2026-11-06

- Removed `reloadonclose=true` from Credentials iframe modals; closing a view or cancelling no longer reloads the parent GLPI page.
- Added a modal dirty-state signal for successful create/update/unlink/delete actions. On close, the plugin invokes GLPI's `reloadTab('credentials_refresh=...')` to refresh only the active AJAX tab and its counter.
- Modal-only unlink/delete now closes the dialog instead of navigating the top window to the parent asset/ticket. Standalone full-page actions keep their normal return navigation.
- Standardized action buttons to GLPI 12 Tabler icon and ghost-button conventions (`btn-icon`, `btn-ghost-secondary`, `btn-ghost-danger`); improved icon-only button labels and accessible names.
- Bumped plugin and translation metadata to `0.0.18`.

## 0.0.17 — 2026-11-06

- Removed duplicate in-page headings and the redundant “Back to item” action from credential details displayed in GLPI's native modal; standalone views keep their normal page heading and return link.
- Added an explicit “Name” field to credential details so the credential identifier is not shown as an unlabeled line. Native modal titles now include the credential name and follow the content when switching between details and edit.
- Fixed edit-from-details inside a ticket or asset modal: the edit URL now preserves `_in_modal=1` and `context_ticket_id`, the form stays in the existing iframe, and a successful save returns to the refreshed credential details in that same modal. Closing native credential dialogs refreshes the originating asset or ticket tab so edited names and counts are current.
- Preserved the ticket context and modal state across edit form submissions; modal Add cancellation closes the current GLPI dialog instead of navigating the iframe to a full item page.
- Aligned action controls with GLPI/Bootstrap button conventions and changed the password action label from the ambiguous “Show” to “Show password” (localized for es_ES, es_MX and fr_FR).
- Bumped plugin and translation metadata to `0.0.17`.

## 0.0.16 — 2026-11-06

- Fixed the password Reveal POST inside GLPI's native iframe modal. The initial URL generated by `Ajax::createIframeModalWindow()` includes `_in_modal=1`, but the detail page rebuilt its POST action without that flag; after clicking Reveal, `Html::header()` therefore rendered the full GLPI navigation shell inside the iframe. The detail controller now preserves `_in_modal=1` in its base action URL, keeping the compact modal layout for reveal, unlink and delete actions while retaining `context_ticket_id` for ticket-originated views.
- Added a CI assertion guarding modal-context propagation and bumped plugin/translation metadata to `0.0.16`.

## 0.0.15 — 2026-11-06

- Fixed the GLPI modal helper calls in the item/user and ticket tabs to use the global `\Ajax::createIframeModalWindow()` class explicitly. The plugin's PHP namespace previously caused PHP to resolve `Ajax` as `GlpiPlugin\Credentials\Ajax`, producing a class-not-found fatal error for users, assets and tickets.
- Added CI assertions that both tabs use the global `\\Ajax` class and do not regress to an unqualified `Ajax::` call.
- Bumped plugin and translation metadata to `0.0.15`.

## 0.0.14 — 2026-11-06

- Fixed malformed packaged GNU Gettext catalogs: the previous `.mo` files contained multiple empty `msgid` entries, which caused Laminas Gettext to emit `Undefined array key 1` while GLPI checked plugin state during Marketplace updates.
- Recompiled `es_ES`, `es_MX` and `fr_FR` catalogs from their PO sources and verified that only the metadata header has an empty `msgid`; the `Credential` plural entry has the expected source and translation forms.
- Extended CI structural checks to validate MO headers, table bounds, UTF-8 strings, locale metadata, unique empty header entry and plural data.
- Bumped plugin and translation metadata to `0.0.14`.

## 0.0.13 — 2026-11-06

- Replaced custom dialog handling with GLPI-native `Ajax::createIframeModalWindow()` for credential add, view, edit, and ticket details.
- Anchors now use GLPI's standard `data-bs-toggle="modal"` / `data-bs-target` workflow, avoiding full-page navigation.
- Ticket credential details preserve ticket context and return to the originating ticket after using the back action.
- Removed custom JavaScript interception that previously redirected links into a manually constructed dialog.
- Bumped plugin version and translation catalog headers.

## 0.0.12 — 2026-11-06

- Replaced the manually managed Bootstrap modal/iframe wiring with GLPI 12's native `glpi_html_dialog()` helper. The previous combination of `data-bs-toggle` and `window.bootstrap` could allow Bootstrap to open an empty modal while preventing the anchor's iframe navigation.
- Asset/user Add, View and Edit actions, and Ticket View actions now open dialogs through one delegated plugin handler and load the protected page in the dialog iframe.
- Removed static modal markup, iframe target attributes and Bootstrap data-api attributes from asynchronous tab content. If GLPI's native dialog helper is unavailable, the action navigates in the current page instead of displaying an empty modal.
- Bumped all plugin version declarations and translated catalog headers to `0.0.12`; English remains the source language with no English PO/MO catalogs.


## 0.0.11 — 2026-11-06

- Fixed Add/Edit/View navigation so item/user forms and details load into a named modal iframe without opening a new browser tab; native anchor targets remain functional if plugin JavaScript is unavailable.
- Added the missing ticket detail modal and iframe, and connected each ticket credential action to it.
- Added native GLPI tab counters for item/user and ticket credentials, respecting `glpishow_count_on_tabs` and deduplicating credentials shared through multiple ticket sources. Re-authentication-protected source links contribute to the count without exposing names or values.
- Made the detail page Back action return to the top-level GLPI item page.

## 0.0.10 — 2026-11-05

- Force a complete GLPI cache reset after the Credentials install/update hook completes, using `Glpi\Cache\CacheManager::resetAllCaches()` to clear cache contexts (including translations) and compiled Twig templates.
- Treat English as the plugin's source language: removed `en_GB.po`/`.mo`, removed English from the plugin's translated-language manifest, and limited PO/MO compilation to `es_ES`, `es_MX` and `fr_FR`. CI now rejects any generated English locale catalog, including all region variants.
- Bumped the plugin version consistently in `setup.php`, `credentials.xml`, translation headers and structural tests.

## 0.0.9 test refresh — 2026-11-06

- Fixed the Add credential control: the asynchronous item tab now emits the Bootstrap modal element and iframe targeted by the button. The anchor keeps a working new-tab fallback; the plugin JavaScript explicitly opens the modal when Bootstrap is present.
- Completed the missing ticket-tab translations in `es_MX`, `es_ES` and `fr_FR`, including the heading, explanatory text, empty-state message and actions. Updated the compiled `.mo` catalogs.
- Added CI checks that verify the modal target/frame exist and that the key Spanish (Mexico) ticket strings are translated.

## 0.0.9 — 2026-11-06

- Corrected `PLUGIN_CREDENTIALS_VERSION` in `setup.php` to `0.0.9`, matching `credentials.xml`.

- Fixed the Cancel action in the add/edit credential form: it now returns to the associated GLPI item instead of requesting the protected details controller without a credential ID.
- Fixed post-save, link, unlink and delete redirects to return to the parent item's native GLPI form. This also closes the modal workflow and refreshes the item tab.
- Added CI assertions for the safe cancel/redirect target and updated plugin version checks.

