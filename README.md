# Credentials

<p align="center">
  <img src="https://raw.githubusercontent.com/monta990/credentials/main/logo.png" alt="Credentials logo" width="96">
</p>
<h1 align="center">Credentials</h1>
<p align="center">
  <strong>GLPI 12 plugin — Securely stores credentials and links them to users, assets and tickets</strong>
</p>
<p align="center">
  <a href="https://github.com/glpi-project/glpi" target="_blank"><img src="https://img.shields.io/badge/GLPI-12.0.x-blue" alt="GLPI compatibility"></a>
  <a href="https://www.php.net/" target="_blank"><img src="https://img.shields.io/badge/PHP-%3E%3D8.3-purple" alt="PHP requirement"></a>
  <a href="https://www.gnu.org/licenses/gpl-3.0.html" target="_blank"><img src="https://img.shields.io/badge/License-GPL--3.0--or--later-green" alt="License"></a>
  <a href="https://github.com/monta990/credentials/releases" target="_blank"><img src="https://img.shields.io/github/downloads/monta990/credentials/total" alt="GitHub Downloads"></a>
  <a href="https://github.com/monta990/credentials/actions" target="_blank"><img src="https://img.shields.io/github/actions/workflow/status/monta990/credentials/ci.yml?branch=main" alt="GitHub Actions"></a>
</p>

---

## Overview

**Credentials** adds a protected credential-management area to GLPI 12. Credentials can be associated with users and supported assets, then accessed from the corresponding GLPI tabs. Ticket views aggregate credentials associated with the ticket's requester users and attached items, without creating duplicate credential records.

Each credential has a visible numeric ID, a descriptive name, username, password, URL and notes. The ID can be used to identify and link an existing credential from another GLPI item.

A credential must remain associated with at least one item. Removing its final relationship is blocked; when a credential should no longer exist, delete the credential itself. The password is mandatory when creating a record. During an update, leaving the password field blank preserves the currently stored password.

## Main features

- 🔐 Secure credential storage using GLPI's native `GLPIKey` encryption for username, password, URL and notes.
- 👤 Credentials tabs on GLPI users and supported assets.
- 🎫 Ticket tab aggregating credentials associated with requester users and attached ticket items.
- 🔢 Visible credential ID for identification and reuse when linking existing records.
- 🔗 Link an existing credential to another eligible user or asset without duplicating its data.
- 🧾 Native GLPI history events for credential activity, including password-reveal auditing without recording secret values.
- 👁️ Separate permission for revealing passwords, independent from general credential access.
- 🔒 GLPI-native re-authentication for protected credential pages and actions.
- 🧩 GLPI 12 Symfony controllers and Twig templates, with legacy `front/` entry points retained only as compatibility redirects.
- 🌐 English source language with Spanish (Mexico), Spanish (Spain) and French translations.
- 🔁 Entity-aware transfer behavior for supported users and assets, including shared-credential handling.
- 🗑️ Safe unlink/delete behavior that prevents orphaned active credentials.
- 🧪 CLI regression tests and GitHub Actions compatibility checks for PHP 8.3, 8.4, 8.5 and 8.6.

## Security model

Credentials is designed so that secret data is not exposed through ordinary list and counter views.

- **Encryption at rest:** username, password, URL and notes are encrypted with GLPI's native `GLPIKey`. The descriptive name remains a searchable, non-secret label.
- **Key rotation:** encrypted columns are registered in `Hooks::SECURED_FIELDS`, allowing GLPI's native `security:change_key` process to re-encrypt them.
- **Independent permissions:** `plugin_credentials` controls credential operations; `plugin_credentials_reveal` separately controls password reveal.
- **Authorization checks:** requests re-check GLPI rights, credential state, linked item, entity boundaries and—when applicable—the exact relationship to the originating ticket.
- **Protected views:** sensitive pages and actions use GLPI 12 native re-authentication.
- **Secret-safe history:** password reveal is audited before plaintext is displayed, but the secret itself is never written to history.
- **No-store responses:** secret-bearing pages and redirects use `Cache-Control: no-store` and `Referrer-Policy: no-referrer`.
- **Safe rendering:** Twig auto-escaping is used for user-controlled values.
- **Association invariant:** the final credential relationship cannot be removed independently. Delete the complete credential when it is no longer needed.

### Important encryption and backup note

Back up the GLPI database and the matching `glpicrypt.key` file together. A database backup without its corresponding encryption key cannot decrypt stored credential values.

Keep Credentials enabled while running GLPI's `security:change_key` operation so GLPI can process the plugin's registered secured fields.

### Archived credentials and retention

When GLPI permanently purges a linked user or asset, Credentials removes that relationship. If no other item remains linked, the credential is moved to the GLPI trash and a secret-free `HISTORY_ORPHANED` event is recorded.

This is a **soft archive**, not cryptographic erasure. Encrypted values remain in the database for recovery. The plugin does not define a retention period or automatically erase archived ciphertext. Administrators must include archived records and the matching `glpicrypt.key` in their organization's retention, backup and restore policies.

## Requirements

| Component | Version |
|-----------|---------|
| GLPI | 12.0.x |
| PHP | 8.3 or newer |
| Encryption key | Valid GLPI `glpicrypt.key`, readable by the PHP process |
| Database | GLPI-supported database engine |

The PHP 8.6 CI leg is a compatibility check against the PHP 8.6 preview/nightly runtime; it does not change the declared production minimum.

## Installation

1. Download the plugin release ZIP.
2. Extract the package into the GLPI plugins directory. The resulting directory must be named `credentials`:
   ```text
   /var/www/glpi/plugins/credentials/
   ```
3. Confirm the GLPI instance is running GLPI 12.0.x and PHP 8.3 or newer.
4. Open **Setup → Plugins**.
5. Install **Credentials**, then enable it.
6. Open the applicable GLPI profiles and configure the credential permissions before granting access to users.

For an upgrade, replace the plugin files and use the plugin's **Update** action in **Setup → Plugins**. Do not uninstall to upgrade: uninstallation intentionally removes the plugin-owned tables and data.

## Using Credentials

### Users and assets

The plugin adds a **Credentials** tab to supported GLPI users and assets. From the tab, authorized users can view the credentials associated with that item, create a credential, link an existing credential, and open its details.

A credential can be shared by more than one eligible item. Linking an existing credential creates an association; it does not create a duplicate credential record.

### Tickets

The ticket Credentials tab aggregates credentials from:

- Users associated with the ticket as requesters.
- Assets/items attached to the ticket.

The same credential is shown only once even when it is reachable through more than one source. Access to the source user or asset is still subject to the current GLPI profile and entity permissions. Ticket context is validated against the originating requester or attached item; an unrelated ticket cannot be supplied as an arbitrary context.

### Creating and editing credentials

A credential includes:

| Field | Description |
|-------|-------------|
| ID | Visible numeric identifier assigned by the plugin |
| Name | Descriptive, searchable label; not encrypted |
| Username | Encrypted at rest |
| Password | Required on creation; encrypted at rest |
| URL | Encrypted at rest |
| Notes | Encrypted at rest |

When editing a credential, leave the password field blank to preserve the currently stored password. The plugin does not repopulate the password field after a failed form submission.

### Revealing and copying a password

Password reveal requires the separate `plugin_credentials_reveal` permission. The plugin uses an interactive POST in the protected detail view so GLPI 12 can display and replay its native re-authentication prompt.

The reveal operation is recorded in GLPI history without including the password. The displayed response is not cached. **Hide** clears the plaintext value from the page; **Copy** reports whether the clipboard operation succeeded or failed.

### Linking, unlinking and deleting

A credential may be linked to multiple eligible items. Unlinking removes only the selected relationship.

The last relationship cannot be removed independently. This prevents an active credential from becoming orphaned. When the credential is no longer required, use **Delete credential** to remove its relationships and move the record to the GLPI trash through the plugin's controlled deletion workflow.

## Profiles and permissions

Credentials defines two profile rights:

| Right | Purpose |
|-------|---------|
| `plugin_credentials` | Controls access to credential operations, subject to the corresponding GLPI read/create/update/delete rights |
| `plugin_credentials_reveal` | Separately grants permission to reveal plaintext passwords |

Grant password reveal only to profiles that need to display stored passwords. A user who can view credential metadata does not automatically receive permission to reveal secrets.

Always validate the effective rights in a non-production profile before enabling access broadly.

## Entity transfers

Credentials integrates with GLPI's item-transfer lifecycle for supported users and assets.

- When an item is moved and a credential is linked only to that item, the credential follows the item to the destination entity.
- When a credential is shared with other items, the plugin preserves the source relationships and handles the destination association according to the transfer operation.
- When GLPI creates a new item ID during a copy, the plugin applies the appropriate same-entity reuse or cross-entity copy behavior.
- Transfer operations preserve encrypted field values and are audited through secret-free GLPI history events.

The transfer workflow revalidates current relationships and credential state while coordinating concurrent operations, so stale relationships are not recreated during a transfer.

## CLI tests and PHP compatibility

GitHub Actions runs PHP syntax checks and the CLI regression suite on PHP 8.3, 8.4, 8.5 and 8.6. PHP 8.6 is a required compatibility leg and runs the same non-optional CLI checks as the other matrix versions.

To run the CLI suite locally, put the desired PHP binary first on `PATH` and execute:

```bash
bash tools/run-cli-tests.sh 8.5
```

Replace `8.5` with the PHP minor version installed locally. The script validates the runtime version, lints plugin PHP files and executes the PHP regression tests.

The CI matrix is not a claim of GLPI certification beyond the declared GLPI 12.0.x compatibility range.

## Development and publication status

The current repository version is **0.0.31**, a development/remediation build. The plugin includes regression coverage for protected credential operations, ticket aggregation, modal workflows, password reveal, relationship invariants, entity transfers and concurrent database operations.

The repository package currently keeps the Marketplace manifest in the `development` state. This README does not claim Marketplace approval or stable publication status. Live acceptance testing on clean and upgraded GLPI 12 instances remains part of release validation.

See [`HANDOFF.md`](HANDOFF.md) for architecture, implementation context, validation status and deployment notes.

See [`tests/SECURITY_REGRESSION.md`](tests/SECURITY_REGRESSION.md) for the security, authorization, lifecycle and live-GLPI acceptance checklist.

## Changelog

See [`CHANGELOG.md`](CHANGELOG.md) for the release history.

---

## Author

Edwin Elias Alvarez — [GitHub](https://github.com/monta990)

## License

GPL-3.0-or-later — see [`LICENSE`](LICENSE).

## Issues and support

Report bugs or request features through the [GitHub issue tracker](https://github.com/monta990/credentials/issues).

---

<p align="center">
  <sub>Credentials — GLPI 12 plugin</sub>
</p>
