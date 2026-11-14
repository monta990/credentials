<p align="center">
  <img src="https://raw.githubusercontent.com/monta990/credentials/main/logo.png" alt="Credentials logo" width="96">
</p>
<h1 align="center">Credentials</h1>
<p align="center">
  <strong>GLPI plugin — Keep your organization's access credentials organized and available where your team needs them</strong>
</p>
<p align="center">
  <a href="https://github.com/glpi-project/glpi" target="_blank"><img src="https://img.shields.io/badge/GLPI-12.0.0%2B-blue" alt="GLPI compatibility"></a>
  <a href="https://www.php.net/" target="_blank"><img src="https://img.shields.io/badge/PHP-8.3%2B-purple" alt="PHP requirement"></a>
  <a href="https://www.gnu.org/licenses/gpl-3.0.html" target="_blank"><img src="https://img.shields.io/badge/License-GPL%20v3%2B-green" alt="License"></a>
  <a href="https://github.com/monta990/credentials/releases" target="_blank"><img src="https://img.shields.io/github/downloads/monta990/credentials/total" alt="GitHub Downloads"></a>
</p>

---

## Overview

**Credentials** helps your IT team keep account information organized in GLPI. Store the usernames, passwords, web addresses and notes needed to access company systems, services and equipment, and associate them with the users or assets they belong to.

Instead of keeping the same information in separate places, your team can find it directly from the related GLPI record. Credentials can also be shared with other authorized items, making it easier to manage access information without creating duplicate entries.

## Main features

- 🔐 **Protected information:** Keep usernames, passwords, web addresses and notes securely stored.
- 👤 **User association:** View the credentials related to a GLPI user.
- 🖥️ **Asset association:** Keep access information with the equipment or asset it belongs to.
- 🎫 **Ticket access:** Find credentials related to a ticket's requester and associated assets from the ticket itself.
- 🔗 **Reuse existing credentials:** Link an existing entry to another user or asset instead of creating duplicates.
- 🔎 **Easy selection:** Find an existing credential by category and name, or link additional GLPI users and assets with native dependent selectors.
- 👁️ **Controlled password display:** Only authorized team members can reveal saved passwords.
- 📋 **Quick copy:** Copy a password when access is needed.
- 🧾 **Activity history:** Keep a record of important credential actions without exposing passwords.
- 🏷️ **Categories:** Create, rename and update category descriptions; editing a category preserves its existing credential associations.
- 📅 **Expiration and reviews:** Record expiration dates, last and next review dates, and the person responsible for reviewing each credential. Optional email reminders notify the assigned reviewer through GLPI.
- 🧭 **Administration page:** Manage plugin updates, reminders, categories, reviews and backups in General settings; archived records have a dedicated tab. Activity is recorded in the native History tab of each linked GLPI item or user.
- 📦 **Protected backups:** Export and import credentials using a password-protected backup file.
- 🌍 **Multiple languages:** English, Spanish and French translations.
- 🔄 **Entity transfers:** Keep credential associations consistent when supported GLPI users or assets are transferred between entities.

## Requirements

| Component | Version |
|-----------|---------|
| GLPI | 12.0.0 – 12.0.x |
| PHP | 8.3 or newer |

## Installation

1. Download the latest available release.
2. Extract the ZIP file into your GLPI plugins directory. The folder must be named **credentials**.
3. Open **Setup → Plugins** in GLPI.
4. Find **Credentials**, click **Install**, and then **Enable**.
5. Configure the permissions for the profiles that will use the plugin.

## How to use

### Manage credentials from users and assets

Open a GLPI user or supported asset and select the **Credentials** tab.

From there, authorized team members can:

- Add a new credential.
- View information already associated with the item.
- Open an existing credential.
- Link a credential that has already been registered.

Each entry includes a name, username, password, web address and notes. The password is required when creating a new entry. When editing, leave the password field empty if you do not want to change it.

### Find credentials from a ticket

Open a GLPI ticket and select the **Credentials** tab.

The plugin brings together credentials associated with the ticket's requester users and attached assets. This makes it easier for support staff to find the access information related to the reported issue, subject to their assigned permissions.

If the same credential is associated with more than one item on the ticket, it is displayed only once.

### View and copy passwords

Authorized team members can reveal a saved password from the credential details. They can copy it when needed or hide it again after use.

Password display is controlled separately from general credential access. This allows administrators to decide which profiles may view stored passwords.

### Link and remove credentials

An existing credential can be linked to more than one eligible user or asset. Removing a link only disconnects that item; it does not delete the credential or its other associations.

To prevent entries from being left without an associated item, the plugin does not allow removing the final link. If a credential is no longer needed, delete the credential itself.

## Permissions

Administrators can control access through the GLPI profiles.

The plugin provides separate permissions for:

- **Credential management:** Access and manage credential entries according to the profile's assigned rights.
- **Password display:** Reveal saved passwords.
- **Backup export/import:** Create and restore password-protected credential backups.

The independent backup permission is required in addition to the relevant credential read/create rights. Give password-display permission only to team members who need it to perform their work.


## Administration

The Credentials administration page is available from GLPI's plugin configuration area to profiles that have GLPI configuration-update access. It is separate from the Credentials tabs on users, assets and tickets, so everyday credential management remains uncluttered.

Administrators can use this page to:

- Check manually whether a newer stable version is available on GitHub. Results are cached for six hours; the check never downloads or installs an update.
- Create, rename and update entity-scoped categories. Category names must be unique within their entity; a category cannot be deleted while credentials use it.
- Review credentials that have expired or need a review soon.
- Review credential activity in the native **History** tab of each linked user or asset; the configuration page does not duplicate those records.
- Review archived credentials and restore them by linking them to an available item.
- Choose how long archived credentials should be kept before they become eligible for permanent deletion.
- Check whether the GLPI encryption key is available.

Expiration and review dates are maintained by editing the credential: update **Last reviewed** after a review and set **Next review date** for the next planned check. There is no separate one-click “Mark reviewed” action. These dates can generate email reminders. Credentials does not change or rotate passwords automatically.

### Automatic lifecycle email reminders

Credentials registers a daily GLPI automatic action. From **Setup > Plugins > Credentials > Configuration**, administrators can enable or pause lifecycle reminders and choose a look-ahead period from 1 to 365 days (30 days by default). Overdue dates remain eligible regardless of the selected period.

The configuration page also reports whether GLPI global email notifications and the email delivery mode are enabled, whether the Credentials automatic action is registered and enabled, and whether the required Credential email notification (including its email template and Review responsible recipient) is correctly configured for the active entity. The dashboard uses a fixed 30-day window for its status indicators; the configurable 1–365-day period applies to email reminders. To deliver email reminders, configure a GLPI notification for the **Credential** item type and the **Expiration and review follow-up** event. Add **Review responsible** as a recipient and select an email notification template. The template can use these tags:

- `##credential.id##`
- `##credential.name##`
- `##credential.status##`
- `##credential.date##`
- `##credential.action##`

Then make sure GLPI notifications are enabled and the Credentials automatic action named `sendreminders` is enabled under GLPI's automatic-action settings. The plugin-level reminder switch must also be enabled. The assigned reviewer must be an active GLPI user with access to the credential's entity and a default email address.

The plugin records reminders by credential, entity, due date and reviewer, so it does not send the same reminder every day. If expiration and review share the same date, they are covered by one notice. Changing the date or responsible person allows a new reminder. No password or other protected credential value is included in notification data.

## Export and import

Credentials can create a password-protected backup containing credentials and their related information. The backup file is encrypted and does not expose passwords as readable text.

When exporting, choose a backup password with at least 12 characters and keep it somewhere safe. Export is limited to records in entities the current user can access and requires the separate Backup permission, credential read permission and the independent Reveal credential passwords permission because the export necessarily contains decrypted secrets in memory. Import requires the Backup permission plus credential read and create permissions; it does not require permission to reveal passwords. Both operations are available only to profiles with GLPI configuration-update access and require GLPI reauthentication. Administrative POST routes use GLPI 12's native Fetch Metadata/Origin CSRF listener; GLPI 12 does not require the legacy hidden `_glpi_csrf_token` field. The password is required to import the file later and cannot be recovered if lost.

Importing a backup creates new credential records; it does not overwrite existing records. Linked users and assets are reconnected only when the destination GLPI instance contains an item with the same type, ID, name and entity. This is suitable for restoring into the same GLPI data set or a compatible copy; it is not an automatic cross-instance ID mapping or migration tool. If no destination item can be matched, the credential is imported as archived so its protected information is not lost; an administrator can later restore it by selecting an eligible destination item from a list of items in the same entity for which the administrator has update permission. Invalid entries are skipped and reported in the completion summary. If a database write, link creation, encryption or history operation fails after import begins, the database transaction is rolled back. Imported passwords and protected details are encrypted again with the destination GLPI instance security key. Categories retain their global or entity scope. A missing category is created in the corresponding destination scope and its description is copied; an existing category is not overwritten. If the destination profile cannot access the required scope, the import is rolled back rather than silently changing the category scope. Reviewer assignments are restored only when the reviewer login can be matched to an accessible user in the destination instance. Matching a linked item also requires update permission on that item.

The backup can include archived credentials. Archived entries remain archived after import until an administrator restores them by linking them to an available item. The backup file is limited to 20 MB and 2,000 credential entries; each credential can contain up to 250 supported item links. Imported records receive new IDs and current creation/modification timestamps; the original GLPI history is not copied.

Always keep the GLPI database backup together with the matching glpicrypt.key file. The backup-file password and the GLPI encryption key serve different purposes, and both must be protected.

## Archived credentials

When a linked user or asset is permanently removed from GLPI, a credential that has no other linked items is moved to the GLPI trash. It is not immediately erased. The dedicated **Archived credentials** tab on the administration page lists up to 25 accessible archived records and offers eligible destination items for restoration. The list is bounded to 500 choices per entity and includes only supported items the current profile can update. Restoration requires update access to that item and the same entity as the archived credential. Retention can be set from 0 to 3,650 days; 0 keeps archived credentials indefinitely and disables retention-based bulk deletion. Administrators with both read and delete rights for Credentials can still permanently delete an individual archived credential at any time, without waiting for the retention period. Individual and bulk deletion both refuse to remove credentials that still have linked items.

Administrators use the separate **Archived credentials** configuration tab to review archived entries, restore them by linking them to an available item in the same entity, manage retention, delete one archived credential immediately with **Delete now**, or manually run the retention-based bulk-deletion action for eligible entries. The individual action bypasses the retention period; the bulk action does not. Permanent deletion is not an automatic background purge and cannot be undone. The dashboard shows up to 25 archived records and up to 25 items needing attention at a time.

## Important information

- Keep access to Credentials limited to authorized personnel.
- Back up your GLPI database together with the GLPI encryption key file. Both are needed to recover protected information.
- If a user or asset is permanently removed from GLPI, an unshared credential may be moved to the GLPI trash. This does not automatically erase its stored information.
- When updating the plugin, use the **Update** action in GLPI. Do not uninstall it to perform an update, because uninstalling removes the plugin's stored data.

## Developer checks

The standalone concurrency regression checks can be run from the plugin root with:

```bash
php tests/concurrency-locking.php
```

Bulk backup, entity transfer and retention purge acquire all candidate credential parent-row locks in ascending ID order before their first consistent read. This ordering is important on InnoDB installations using `REPEATABLE READ`.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the version history.

---

## Author

Edwin Elias Alvarez — [GitHub](https://github.com/monta990)

## License

GPL v3+ — see [LICENSE](LICENSE).

## Issues and support

Report bugs or request features through the [GitHub issue tracker](https://github.com/monta990/credentials/issues).

---

<p align="center">
  <sub>Credentials — GLPI plugin</sub>
</p>
