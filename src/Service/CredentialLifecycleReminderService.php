<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials\Service;

use GlpiPlugin\Credentials\Credential;
use GlpiPlugin\Credentials\CredentialStatus;
use GlpiPlugin\Credentials\NotificationTargetCredential;
use Notification;
use NotificationEvent;
use Profile_User;
use User;

/**
 * Sends deduplicated lifecycle reminders through GLPI's native notification queue.
 *
 * The scan and notification payload intentionally use non-secret credential fields only.
 */
final class CredentialLifecycleReminderService
{
    public const CRON_NAME = 'sendreminders';
    public const EVENT = 'lifecycle_reminder';
    private const REMINDER_TABLE = 'glpi_plugin_credentials_reminders';
    public const DEFAULT_LOOKAHEAD_DAYS = 30;
    public const MIN_LOOKAHEAD_DAYS = 1;
    public const MAX_LOOKAHEAD_DAYS = 365;

    /**
     * Read lifecycle reminder options, preserving the original behavior on upgrades.
     *
     * @return array{enabled:bool,lookahead_days:int}
     */
    public static function getSettings(): array
    {
        $settings = \Config::getConfigurationValues('plugin_credentials');
        if (!is_array($settings)) {
            $settings = [];
        }

        $enabled = filter_var(
            $settings['lifecycle_reminders_enabled'] ?? true,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );

        return [
            'enabled' => $enabled ?? true,
            'lookahead_days' => self::normalizeLookaheadDays(
                $settings['lifecycle_reminder_lookahead_days'] ?? self::DEFAULT_LOOKAHEAD_DAYS
            ),
        ];
    }

    /** Keep stored settings safe even if they were edited outside the plugin UI. */
    public static function normalizeLookaheadDays(mixed $days): int
    {
        if (!is_numeric($days)) {
            return self::DEFAULT_LOOKAHEAD_DAYS;
        }

        return max(self::MIN_LOOKAHEAD_DAYS, min(self::MAX_LOOKAHEAD_DAYS, (int) $days));
    }

    /** @return array{description?:string} */
    public static function cronInfo(string $name): array
    {
        if ($name !== self::CRON_NAME) {
            return [];
        }

        return [
            'description' => __('Expiration and review follow-up', 'credentials'),
        ];
    }

    /**
     * GLPI invokes this method for the registered "sendreminders" automatic action.
     *
     * @return int Number of lifecycle notifications queued.
     */
    public static function cronSendReminders($task = null): int
    {
        global $DB, $CFG_GLPI;

        $settings = self::getSettings();
        if (!$settings['enabled'] || empty($CFG_GLPI['use_notifications'])) {
            return 0;
        }

        $today = substr((string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d')), 0, 10);
        if (!CredentialStatus::validDate($today) || $today === '') {
            return 0;
        }
        $cutoff = (new \DateTimeImmutable($today))
            ->modify('+' . $settings['lookahead_days'] . ' days')
            ->format('Y-m-d');

        // Never select encrypted login/password/URL/notes columns for notification work.
        $rows = $DB->request([
            'SELECT' => [
                'id',
                'name',
                'entities_id',
                'reviewer_users_id',
                'date_expires',
                'date_next_review',
            ],
            'FROM' => Credential::getTable(),
            'WHERE' => [
                'is_deleted' => 0,
                'reviewer_users_id' => ['>', 0],
                'OR' => [
                    ['date_expires' => ['<=', $cutoff]],
                    ['date_next_review' => ['<=', $cutoff]],
                ],
            ],
            'ORDER' => ['id'],
        ]);

        $configured_entities = [];
        $sent = 0;
        foreach ($rows as $row) {
            $credential_id = (int) ($row['id'] ?? 0);
            $entities_id = (int) ($row['entities_id'] ?? -1);
            $reviewer_users_id = (int) ($row['reviewer_users_id'] ?? 0);
            if ($credential_id <= 0 || $entities_id < 0 || $reviewer_users_id <= 0) {
                continue;
            }

            $due_reminders = self::dueReminderTypes(
                isset($row['date_expires']) ? (string) $row['date_expires'] : null,
                isset($row['date_next_review']) ? (string) $row['date_next_review'] : null,
                $today,
                $settings['lookahead_days']
            );
            if ($due_reminders === []) {
                continue;
            }

            if (!array_key_exists($entities_id, $configured_entities)) {
                $configured_entities[$entities_id] = self::hasConfiguredNotification($entities_id);
            }
            if (!$configured_entities[$entities_id]
                || !self::reviewerCanReceive($reviewer_users_id, $entities_id)
            ) {
                continue;
            }

            foreach ($due_reminders as $reminder) {
                if (self::queueReminder(
                    $row,
                    $entities_id,
                    $reviewer_users_id,
                    $reminder['type'],
                    $reminder['date']
                )) {
                    $sent++;
                }
            }
        }

        return $sent;
    }

    /**
     * Return the date-specific reminders that fall due within the configured look-ahead period.
     *
     * Past dates remain eligible so overdue reviews and expirations are not missed.
     *
     * @return array<int,array{type:string,date:string}>
     */
    public static function dueReminderTypes(
        ?string $expires,
        ?string $next_review,
        string $today,
        int $lookaheadDays = self::DEFAULT_LOOKAHEAD_DAYS
    ): array
    {
        if (!CredentialStatus::validDate($today) || $today === '') {
            return [];
        }

        $cutoff = (new \DateTimeImmutable($today))
            ->modify('+' . self::normalizeLookaheadDays($lookaheadDays) . ' days')
            ->format('Y-m-d');

        $reminders = [];
        foreach (['expiry' => $expires, 'review' => $next_review] as $type => $date) {
            if ($date === null || $date === '' || !CredentialStatus::validDate($date)) {
                continue;
            }
            if ($date <= $cutoff) {
                $reminders[] = ['type' => $type, 'date' => $date];
            }
        }

        return $reminders;
    }

    /**
     * Inspect the exact GLPI notification requirements used by the cron for one entity.
     *
     * @return array{event_found:bool,mail_template_found:bool,reviewer_recipient_found:bool,ready:bool}
     */
    public static function notificationSetupStatus(int $entities_id): array
    {
        global $DB;

        $notifications = Notification::getNotificationsByEventAndType(
            self::EVENT,
            Credential::class,
            $entities_id
        );
        $event_found = false;
        $mail_template_found = false;
        $reviewer_recipient_found = false;

        foreach ($notifications as $notification) {
            $event_found = true;
            if (
                (int) ($notification['notificationtemplates_id'] ?? 0) <= 0
                || (string) ($notification['mode'] ?? '') !== \Notification_NotificationTemplate::MODE_MAIL
            ) {
                continue;
            }

            $mail_template_found = true;

            // The cron accepts only this explicit recipient on an email notification.
            $targets = $DB->request([
                'SELECT' => ['id'],
                'FROM' => 'glpi_notificationtargets',
                'WHERE' => [
                    'notifications_id' => (int) $notification['id'],
                    'type' => Notification::USER_TYPE,
                    'items_id' => NotificationTargetCredential::TARGET_REVIEWER,
                    'is_exclusion' => 0,
                ],
                'LIMIT' => 1,
            ]);
            foreach ($targets as $target) {
                $reviewer_recipient_found = true;
                break 2;
            }
        }

        return [
            'event_found' => $event_found,
            'mail_template_found' => $mail_template_found,
            'reviewer_recipient_found' => $reviewer_recipient_found,
            'ready' => $mail_template_found && $reviewer_recipient_found,
        ];
    }

    private static function hasConfiguredNotification(int $entities_id): bool
    {
        return self::notificationSetupStatus($entities_id)['ready'];
    }

    /**
     * Mirror GLPI's NotificationTarget recipient checks before consuming a reminder.
     */
    private static function reviewerCanReceive(int $users_id, int $entities_id): bool
    {
        $user = new User();
        if (!$user->getFromDB($users_id) || $user->isDeleted() || !$user->isActive()) {
            return false;
        }

        $current_time = (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        if (
            (!empty($user->fields['begin_date']) && $user->fields['begin_date'] > $current_time)
            || (!empty($user->fields['end_date']) && $user->fields['end_date'] < $current_time)
            || trim((string) $user->getDefaultEmail()) === ''
        ) {
            return false;
        }

        $entity_filter = getEntitiesRestrictCriteria(
            Profile_User::getTable(),
            '',
            $entities_id,
            true
        );
        return count(Profile_User::getUserProfiles($users_id, $entity_filter)) > 0;
    }

    /**
     * Raise one native GLPI event and persist its idempotency marker atomically.
     *
     * The unique key includes entity, reminder date and reviewer. Changing the due
     * date or reassigned reviewer permits a new reminder without daily duplicates.
     *
     * @param array<string,mixed> $row
     */
    private static function queueReminder(
        array $row,
        int $entities_id,
        int $reviewer_users_id,
        string $reminder_type,
        string $reminder_date
    ): bool {
        global $DB;

        $dedupe_key = [
            'plugin_credentials_credentials_id' => (int) $row['id'],
            'entities_id' => $entities_id,
            'reminder_date' => $reminder_date,
            'reviewer_users_id' => $reviewer_users_id,
        ];
        $marker = $dedupe_key + ['reminder_type' => $reminder_type];
        if (self::reminderAlreadyQueued($dedupe_key)) {
            return false;
        }

        $DB->beginTransaction();
        try {
            if (self::reminderAlreadyQueued($dedupe_key)) {
                $DB->rollBack();
                return false;
            }

            if ($DB->insert(self::REMINDER_TABLE, $marker) === false) {
                throw new \RuntimeException('Unable to record credential lifecycle reminder.');
            }

            // Construct the event item from the exact non-secret columns selected above.
            // This avoids loading encrypted fields into the notification code path.
            $credential = new Credential();
            $credential->fields = [
                'id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'entities_id' => $entities_id,
                'reviewer_users_id' => $reviewer_users_id,
                'date_expires' => $row['date_expires'] ?? null,
                'date_next_review' => $row['date_next_review'] ?? null,
                'is_deleted' => 0,
            ];

            $queued = NotificationEvent::raiseEvent(
                self::EVENT,
                $credential,
                [
                    'entities_id' => $entities_id,
                    'reminder_type' => $reminder_type,
                    'reminder_date' => $reminder_date,
                ]
            );
            if (!$queued) {
                $DB->rollBack();
                return false;
            }

            $DB->commit();
            return true;
        } catch (\Throwable $exception) {
            $DB->rollBack();
            // A concurrent run may have inserted the same unique marker first.
            if (self::reminderAlreadyQueued($dedupe_key)) {
                return false;
            }
            throw $exception;
        }
    }

    /** @param array<string,int|string> $marker */
    private static function reminderAlreadyQueued(array $marker): bool
    {
        global $DB;

        $rows = $DB->request([
            'SELECT' => ['id'],
            'FROM' => self::REMINDER_TABLE,
            'WHERE' => $marker,
            'LIMIT' => 1,
        ]);
        foreach ($rows as $row) {
            return true;
        }
        return false;
    }
}
