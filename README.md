# Credentials for GLPI 12

<p align="center"><img src="logo.png" alt="Credentials logo" width="160"></p>

**Plugin key/directory:** `credentials`  
**Source language:** English  
**Catalogs:** `es_ES`, `es_MX`, `fr_FR` (no generated English PO/MO files)  
**Author:** Edwin Elias Alvarez  
**License:** GPL-3.0-or-later  
**Requirements:** GLPI `12.0.x`, PHP `8.3+`

Credentials stores protected account information and links it to GLPI users and supported assets. A ticket tab aggregates credentials associated with its requester users and attached items without duplicating records.

Each entry exposes a visible numeric ID for linking it from other GLPI items, plus a descriptive name, username, password, URL and notes. A credential must always remain linked to at least one item: removing the last relation is blocked; delete the entire credential when it should no longer exist. The password is mandatory on creation; leave it blank during an update to preserve the existing password. The username, password, URL and notes are encrypted at rest with GLPI's native `GLPIKey`; the display name is intentionally retained as a searchable, non-secret label.

## Security model

- All sensitive reads and changes are handled by GLPI 12 Symfony plugin controllers under `src/Controller/`.
- GLPI native re-authentication is required before protected credential pages and actions.
- The plugin uses two profile rights: `plugin_credentials` for credential operations and `plugin_credentials_reveal` for revealing passwords.
- The four encrypted columns are registered in `Hooks::SECURED_FIELDS`, allowing GLPI's native security-key rotation to re-encrypt them.
- Lists and tab counters only select credential IDs, display names and modification dates; they never select or decrypt secret columns.
- Every request re-checks the GLPI rights on the credential, the linked user/asset, the entity boundary and (when supplied) the exact relation to the originating ticket.
- Password reveal requires its separate right and records a secret-free GLPI item-history event before displaying plaintext. Reveal uses an ordinary POST inside the credential iframe so GLPI 12 can display and replay its native re-authentication prompt; the response is never cached. Plaintext is rendered in a readonly, selectable text input with explicit theme-aware text-fill styling. Hide clears the input value from the DOM, while Copy reports success or failure.
- Secret-bearing responses and redirects use `Cache-Control: no-store` and `Referrer-Policy: no-referrer`; the password input is never repopulated after a failed form submission. Unlink/delete actions require an explicit browser confirmation. The final association cannot be unlinked; the interface warns users and requires deleting the credential itself to prevent orphaned active records.
- Runtime data access uses GLPI's DB query builder. Initial static `CREATE TABLE` statements run only in the install hook using GLPI's documented `$DB->doQuery()` lifecycle pattern; profile rights and future incremental schema changes use `Migration::executeMigration()`. Uninstall drops only the plugin-owned tables.
- Twig auto-escaping is used for user-controlled values. Only HTML returned by GLPI's native modal helper is rendered as trusted markup.
- CSRF protection for POST requests relies on GLPI 12's Fetch Metadata / `CheckCsrfListener` mechanism. No custom CSRF implementation is introduced.

Back up the GLPI database and the `glpicrypt.key` file together. A database backup without its matching encryption key cannot decrypt the stored values. Keep the plugin enabled during GLPI's `security:change_key` operation so `SECURED_FIELDS` can be processed.

## Installation and upgrade

1. Back up the GLPI database and configuration directory, including `glpicrypt.key`.
2. Install the `credentials` directory under the GLPI plugins directory, or install the package using Marketplace once a public release is published.
3. Confirm GLPI `12.0.x`, PHP `8.3+`, and a valid `glpicrypt.key` readable by the PHP process.
4. Install or update the plugin from **Setup → Plugins** and enable it.
5. Review `plugin_credentials` and `plugin_credentials_reveal` in the applicable profiles. Grant password reveal only to profiles that need it.
6. Test with non-production sample data on a user, supported asset and ticket containing multiple requesters/items.

GLPI 12 frontend assets live under `public/css/` and `public/js/`. Keep the `add_css` and `add_javascript` hook paths relative to `public/`; do not move plugin assets back to root-level `css/` or `js/` directories.

For upgrades, replace the plugin files and run the plugin's **Update** action. Do not uninstall to upgrade: uninstallation intentionally drops the plugin's own tables.

## CLI tests and PHP compatibility

The CI workflow runs PHP syntax checks and every PHP regression test through the CLI on PHP 8.3, 8.4, 8.5 and 8.6. PHP 8.6 is a required compatibility leg and runs the same non-optional CLI checks as the other matrix versions; this matrix does not change the plugin’s declared production minimum or claim GLPI 12 certification. To run the same CLI suite locally, put the desired PHP binary first on `PATH` and run `bash tools/run-cli-tests.sh 8.6` (or the matching minor version).

## Development and publication status

`0.0.27` is a development/remediation build. Add/edit Cancel closes the current modal (or returns to details when editing from the detail view), while successful saves request a validated same-origin navigation from the parent GLPI page to the top-level item page for a complete refresh. Modal titles receive validated Tabler icons through a same-origin message bridge (key for details, plus for add, pencil for edit, and link for associated items). Password reveal uses an interactive same-iframe POST that supports GLPI native re-authentication; copy/hide remain local to the detail iframe. Ticket aggregation is tested against simulated GLPI 12 requester/asset relations, including unlink/relink and sudo-protected IDs. Link/unlink/delete/purge operations serialize through GLPI's DB update API, revalidate active state and entity after locking, and record orphan archival in credential history. CI continues to require PHP 8.3, 8.4, 8.5 and 8.6. The old `front/` PHP entry points remain compatibility redirects only.

This source package deliberately does not claim Marketplace approval and keeps `<state>development</state>`. A public repository URL, issues URL, readme URL, and immutable release download URL must be supplied before the XML can be made a complete public catalog manifest. These URLs are not fabricated in this package.

See [`HANDOFF.md`](HANDOFF.md) for architecture, validation status and remaining live-GLPI acceptance tests. See [`tests/SECURITY_REGRESSION.md`](tests/SECURITY_REGRESSION.md) for the test matrix required before a stable Marketplace submission.

## Archived credential retention

When GLPI permanently purges a linked user or asset, Credentials removes that relationship. If no other item remains linked, the credential is moved to the GLPI trash (`is_deleted = 1`) and a secret-free `HISTORY_ORPHANED` event is written to the credential's own history. This is a **soft archive**: encrypted fields remain in the database for recovery and are not automatically cryptographically erased. The plugin does not set a retention period or perform automatic cryptographic erasure. Administrators must include these records and the matching `glpicrypt.key` in the organization's retention, backup and restore policy.
