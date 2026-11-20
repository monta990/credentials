# Changelog

All notable changes to Credentials are documented here.

## 1.0.2 — 2026-11-20

### Security fixes

- Block native GLPI restore and restore massive actions at the Credential model boundary unless invoked from an explicitly trusted plugin write scope. The administrative restore workflows continue to enforce configuration rights, entity/link validation and linked-item history.

### Quality and maintenance

- Avoid the expected duplicate-key INSERT on every category mutation by checking for the entity lock row before seeding it; the atomic UPDATE remains the serialization point.
- Remove the UpdateChecker cache files and their empty plugin documentation directory during uninstall.
- Display a clear administrator notice in the Profile tab when a profile is missing Credentials rights records, instead of leaving an empty tab.

## 1.0.1 — 2026-11-18

Security and compatibility update based on the Teclib' GLPI 12 review.

### Security fixes

- Block generic massive update/delete/purge actions on credentials and relationship rows; enforce plugin-only model writes for secret and link mutations.
- Require update permission on at least one linked GLPI item before archiving a credential; permanent deletion remains restricted to the administration service and its retention/history checks.
- Hide encrypted login, password, URL and notes fields from GLPI API serializers.
- Remove plugin configuration keys and plugin-itemtype notification definitions during uninstall.
- Replace PHP-generated inline modal scripts with an external GLPI-loaded script and correct parent-tab refresh signaling.
- Make GitHub update checks opt-in (disabled by default), use GLPI's proxy-aware HTTP client, and cache failed checks.

### Compatibility and quality

- Use CommonGLPI for tab-provider classes that do not own database tables.
- Cache Ticket, Problem and Change credential aggregation within the request to avoid duplicate tab badge/body work.
- Move category serialization locks to a plugin-owned table instead of updating core GLPI entity rows.
- Use English JavaScript fallback strings and keep profile-tab reads free of database writes.
- Increase new backup exports to Argon2id MODERATE parameters while retaining import compatibility with existing INTERACTIVE backups.
- Keep the PHP 8.5-compatible HTTP response handling and improve security regression coverage.

## 1.0.0 — 2026-11-17

First stable release of Credentials for GLPI 12.0.x.

### Credential management

- Store credentials linked to GLPI users, supported assets, phone lines, domains, clusters, data centers, server rooms, database instances, databases and supported infrastructure items.
- Reuse a credential across multiple eligible GLPI items while enforcing entity boundaries and native item permissions.
- Display linked credentials from Tickets, Problems and Changes, aggregating requester and associated-item credentials without duplicate entries.
- Show credential categories in User and Asset tabs, with readable category indicators and an always-visible existing-credential linking workflow.
- Provide native GLPI modal workflows for adding, viewing and editing credentials, including category editing.
- Show a GLPI success notification after a credential is created or updated; failed saves keep the form open and display an accessible error summary.

### Security and auditability

- Encrypt usernames, passwords, URLs and notes with GLPI's native GLPIKey mechanism.
- Keep password reveal behind its dedicated profile right and GLPI re-authentication; passwords remain hidden by default.
- Apply GLPI permissions, entity access checks, CSRF protections, bounded queries and transactional relationship updates.
- Record secret-free activity in GLPI's native history; never place credential values or ciphertext in history, notifications or audit labels.
- Support GLPI encryption-key rotation through the native secured-fields mechanism.

### Lifecycle and administration

- Manage credential categories, expiration dates, review dates and assigned reviewers.
- Provide configurable lifecycle email reminders through GLPI's native notification and automatic-action system.
- Provide an administration overview, archived-credential management, controlled restoration, retention settings and guarded permanent deletion.
- Export and import password-protected backups using Argon2id and XChaCha20-Poly1305, with scope validation and destination-key re-encryption.
- Preserve linked relationships across supported GLPI item lifecycle and entity-transfer operations.

### Updates, translations and compatibility

- Check for stable GitHub releases automatically when the plugin configuration page is opened, with a six-hour cache. The plugin never installs updates automatically; the configuration page keeps a fixed link to GitHub Releases.
- Include Spanish (Mexico), Spanish (Spain) and French (France) translations; English remains the source language.
- Declare compatibility with GLPI 12.0.x.

### Validation notes

- The repository CI workflow runs PHP CLI regression and compatibility checks on PHP 8.3, 8.4, 8.5 and 8.6, JavaScript syntax and regression tests, XML/package validation, translation synchronization, PHPCS and PHPStan.
- Automated CI does not replace installation, upgrade, permissions, database and browser acceptance testing on a live GLPI 12.0.x instance.
