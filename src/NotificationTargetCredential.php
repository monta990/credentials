<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials;

use GlpiPlugin\Credentials\Service\CredentialLifecycleReminderService;
use Notification;
use NotificationTarget;

/**
 * Native GLPI notification target for credential lifecycle reminders.
 *
 * Only the credential name, ID and reminder date are exposed to templates.
 * Protected credential values are never read here.
 */
final class NotificationTargetCredential extends NotificationTarget
{
    public const TARGET_REVIEWER = 1000;

    public function __construct($entity = null, $event = '', $object = null, $options = [])
    {
        parent::__construct($entity, $event, $object, $options);

        $this->addTagToList([
            'tag' => 'credential.id',
            'label' => __('Credential ID', 'credentials'),
            'value' => true,
            'lang' => false,
        ]);
        $this->addTagToList([
            'tag' => 'credential.name',
            'label' => __('Name', 'credentials'),
            'value' => true,
            'lang' => false,
        ]);
        $this->addTagToList([
            'tag' => 'credential.status',
            'label' => __('Status', 'credentials'),
            'value' => true,
            'lang' => false,
        ]);
        $this->addTagToList([
            'tag' => 'credential.date',
            'label' => __('Date', 'credentials'),
            'value' => true,
            'lang' => false,
        ]);
        $this->addTagToList([
            'tag' => 'credential.action',
            'label' => __('Action', 'credentials'),
            'value' => true,
            'lang' => false,
        ]);
    }

    public function getEvents(): array
    {
        return [
            CredentialLifecycleReminderService::EVENT => __('Expiration and review follow-up', 'credentials'),
        ];
    }

    public function addAdditionalTargets($event = ''): void
    {
        if ($event === '' || $event === CredentialLifecycleReminderService::EVENT) {
            $this->addTarget(
                self::TARGET_REVIEWER,
                __('Review responsible', 'credentials'),
                Notification::USER_TYPE
            );
        }
    }

    /** @param array<string,mixed> $data */
    public function addSpecificTargets($data, $options): void
    {
        if ((int) ($data['type'] ?? 0) !== Notification::USER_TYPE
            || (int) ($data['items_id'] ?? 0) !== self::TARGET_REVIEWER
        ) {
            return;
        }

        // GLPI's addUserByField() enforces active-user and entity/profile eligibility.
        $this->addUserByField('reviewer_users_id');
    }

    public function addDataForTemplate($event, $options = []): void
    {
        $events = $this->getEvents();
        $fields = is_object($this->obj ?? null) && isset($this->obj->fields) && is_array($this->obj->fields)
            ? $this->obj->fields
            : [];

        $reminder_type = (string) ($options['reminder_type'] ?? '');
        $reminder_date = (string) ($options['reminder_date'] ?? '');
        $today = substr((string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d')), 0, 10);
        $matches_expiry = $reminder_date !== ''
            && (string) ($fields['date_expires'] ?? '') === $reminder_date;
        $matches_review = $reminder_date !== ''
            && (string) ($fields['date_next_review'] ?? '') === $reminder_date;

        if ($matches_expiry && $matches_review) {
            $reminder_status = __('Expiration and review follow-up', 'credentials');
        } elseif ($matches_expiry || $reminder_type === 'expiry') {
            $reminder_status = $reminder_date !== '' && $reminder_date < $today
                ? __('Expired', 'credentials')
                : __('Expiring soon', 'credentials');
        } elseif ($matches_review || $reminder_type === 'review') {
            $reminder_status = $reminder_date !== '' && $reminder_date < $today
                ? __('Review overdue', 'credentials')
                : __('Review due soon', 'credentials');
        } else {
            $reminder_status = '';
        }
        $this->data['##credential.id##'] = (string) ((int) ($fields['id'] ?? 0));
        $this->data['##credential.name##'] = (string) ($fields['name'] ?? '');
        $this->data['##credential.status##'] = $reminder_status;
        $this->data['##credential.date##'] = $reminder_date !== '' ? \Html::convDate($reminder_date) : '';
        $this->data['##credential.action##'] = (string) ($events[$event] ?? '');
    }
}
