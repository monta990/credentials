<?php

declare(strict_types=1);

namespace {
    function __(string $message, ?string $domain = null): string
    {
        return $message;
    }

    final class Notification
    {
        public const USER_TYPE = 1;
        public static array $eventNotifications = [];

        public static function getNotificationsByEventAndType(string $event, string $itemtype, int $entities_id): array
        {
            return self::$eventNotifications;
        }
    }

    final class Notification_NotificationTemplate
    {
        public const MODE_MAIL = 'mail';
    }

    final class FakeReminderDB
    {
        public array $targetsByNotification = [];
        public array $lastCriteria = [];

        public function request(array $criteria): array
        {
            $this->lastCriteria = $criteria;
            $notificationId = (int) ($criteria['WHERE']['notifications_id'] ?? 0);
            return $this->targetsByNotification[$notificationId] ?? [];
        }
    }

    class CommonDBTM
    {
        public array $fields = [];
    }

    final class FakeCredential extends CommonDBTM {}

    final class Html
    {
        public static function convDate(string $date): string
        {
            return 'formatted:' . $date;
        }
    }

    class NotificationTarget
    {
        protected mixed $obj = null;
        protected array $options = [];
        public array $data = [];
        public array $tag_descriptions = [];
        public array $target_choices = [];
        public array $recipients = [];

        public function __construct($entity = null, $event = '', $object = null, $options = [])
        {
            $this->obj = $object;
            $this->options = $options;
            $this->addAdditionalTargets($event);
        }

        public function addTarget($target, $label, $type = Notification::USER_TYPE): void
        {
            $this->target_choices[$type . '_' . $target] = $label;
        }

        public function addTagToList(array $options): void
        {
            $this->tag_descriptions[$options['tag']] = $options;
        }

        public function addUserByField($field, $search_in_object = false): void
        {
            if (!$this->obj instanceof CommonDBTM) {
                return;
            }
            $id = (int) ($this->obj->fields[$field] ?? 0);
            if ($id > 0) {
                $this->recipients[] = $id;
            }
        }

        public function addAdditionalTargets($event = ''): void {}

        public function getEvents(): array
        {
            return [];
        }
    }
}

namespace {
    use GlpiPlugin\Credentials\NotificationTargetCredential;
    use GlpiPlugin\Credentials\Service\CredentialLifecycleReminderService;

    require_once __DIR__ . '/../../src/CredentialStatus.php';
    require_once __DIR__ . '/../../src/Service/CredentialLifecycleReminderService.php';
    require_once __DIR__ . '/../../src/NotificationTargetCredential.php';

    function assertSameValue(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, "FAIL: {$label}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    $today = '2026-11-13';
    $due = CredentialLifecycleReminderService::dueReminderTypes(
        '2026-11-12',
        '2026-12-13',
        $today
    );
    assertSameValue([
        ['type' => 'expiry', 'date' => '2026-11-12'],
        ['type' => 'review', 'date' => '2026-12-13'],
    ], $due, 'overdue and exactly-30-day dates should be included');

    assertSameValue([], CredentialLifecycleReminderService::dueReminderTypes(
        '2026-12-14',
        null,
        $today
    ), 'dates beyond the 30-day window should not be included');

    assertSameValue([
        ['type' => 'expiry', 'date' => '2027-01-12'],
    ], CredentialLifecycleReminderService::dueReminderTypes(
        '2027-01-12',
        null,
        $today,
        60
    ), 'configured 60-day window should include dates beyond the default 30-day window');

    assertSameValue([], CredentialLifecycleReminderService::dueReminderTypes(
        '2027-11-14',
        null,
        $today,
        365
    ), 'dates beyond the maximum 365-day window should not be included');

    assertSameValue([
        ['type' => 'expiry', 'date' => '2026-12-01'],
        ['type' => 'review', 'date' => '2026-12-01'],
    ], CredentialLifecycleReminderService::dueReminderTypes(
        '2026-12-01',
        '2026-12-01',
        $today
    ), 'both due-date types are detected for the same calendar day');

    assertSameValue([], CredentialLifecycleReminderService::dueReminderTypes(
        '2026-02-30',
        'not-a-date',
        $today
    ), 'invalid calendar dates should not be included');

    assertSameValue([], CredentialLifecycleReminderService::dueReminderTypes(
        null,
        null,
        'not-a-date'
    ), 'an invalid current date should stop the scan');

    global $DB;
    $DB = new FakeReminderDB();

    assertSameValue([
        'event_found' => false,
        'mail_template_found' => false,
        'reviewer_recipient_found' => false,
        'ready' => false,
    ], CredentialLifecycleReminderService::notificationSetupStatus(2), 'missing entity notification should be reported as not configured');

    Notification::$eventNotifications = [
        ['id' => 10, 'notificationtemplates_id' => 0, 'mode' => Notification_NotificationTemplate::MODE_MAIL],
    ];
    assertSameValue([
        'event_found' => true,
        'mail_template_found' => false,
        'reviewer_recipient_found' => false,
        'ready' => false,
    ], CredentialLifecycleReminderService::notificationSetupStatus(2), 'event without an email template should be reported');

    Notification::$eventNotifications = [
        ['id' => 11, 'notificationtemplates_id' => 4, 'mode' => Notification_NotificationTemplate::MODE_MAIL],
    ];
    assertSameValue([
        'event_found' => true,
        'mail_template_found' => true,
        'reviewer_recipient_found' => false,
        'ready' => false,
    ], CredentialLifecycleReminderService::notificationSetupStatus(2), 'email notification without reviewer target should be reported');
    assertSameValue(
        [
            'notifications_id' => 11,
            'type' => Notification::USER_TYPE,
            'items_id' => NotificationTargetCredential::TARGET_REVIEWER,
            'is_exclusion' => 0,
        ],
        $DB->lastCriteria['WHERE'],
        'diagnostic uses the exact recipient scope used by the cron'
    );

    $DB->targetsByNotification[11] = [['id' => 901]];
    assertSameValue([
        'event_found' => true,
        'mail_template_found' => true,
        'reviewer_recipient_found' => true,
        'ready' => true,
    ], CredentialLifecycleReminderService::notificationSetupStatus(2), 'email template and reviewer recipient should report ready');

    $credential = new FakeCredential();
    $credential->fields = [
            'id' => 23,
            'name' => 'ERP administrator',
            'entities_id' => 2,
            'reviewer_users_id' => 77,
            'date_expires' => '2026-12-10',
            'date_next_review' => '2026-12-01',
            'login' => 'must-not-leak-login',
            'password' => 'must-not-leak-password',
            'url' => 'must-not-leak-url',
            'notes' => 'must-not-leak-notes',
        ];
    $target = new NotificationTargetCredential(
        2,
        CredentialLifecycleReminderService::EVENT,
        $credential,
        ['reminder_type' => 'expiry', 'reminder_date' => '2026-12-10']
    );

    assertSameValue(true, isset($target->tag_descriptions['credential.action']), 'notification action tag should be available to templates');

    assertSameValue(
        'Review responsible',
        $target->target_choices[Notification::USER_TYPE . '_' . NotificationTargetCredential::TARGET_REVIEWER] ?? null,
        'notification UI should expose the dedicated review-responsible recipient'
    );

    $target->addSpecificTargets(['type' => Notification::USER_TYPE, 'items_id' => NotificationTargetCredential::TARGET_REVIEWER], []);
    $target->addSpecificTargets(['type' => Notification::USER_TYPE, 'items_id' => 55], []);
    $target->addSpecificTargets(['type' => 2, 'items_id' => NotificationTargetCredential::TARGET_REVIEWER], []);
    assertSameValue([77], $target->recipients, 'only the assigned reviewer should be resolved as the custom recipient');

    $_SESSION['glpi_currenttime'] = '2026-11-13 00:00:00';
    $target->addDataForTemplate(
        CredentialLifecycleReminderService::EVENT,
        ['reminder_type' => 'expiry', 'reminder_date' => '2026-12-10']
    );
    assertSameValue('23', $target->data['##credential.id##'] ?? null, 'template should expose the credential ID');
    assertSameValue('ERP administrator', $target->data['##credential.name##'] ?? null, 'template should expose the credential name');
    assertSameValue('Expiring soon', $target->data['##credential.status##'] ?? null, 'template should expose the reminder type');

    $credential->fields['date_expires'] = '2026-11-12';
    $target->addDataForTemplate(
        CredentialLifecycleReminderService::EVENT,
        ['reminder_type' => 'expiry', 'reminder_date' => '2026-11-12']
    );
    assertSameValue('Expired', $target->data['##credential.status##'] ?? null, 'overdue expiry notifications should be labelled expired');

    $credential->fields['date_next_review'] = '2026-11-12';
    $target->addDataForTemplate(
        CredentialLifecycleReminderService::EVENT,
        ['reminder_type' => 'review', 'reminder_date' => '2026-11-12']
    );
    assertSameValue('Expiration and review follow-up', $target->data['##credential.status##'] ?? null, 'same-day expiry and review notices should be presented together');
    assertSameValue('formatted:2026-11-12', $target->data['##credential.date##'] ?? null, 'template should format the due date');

    $template_data = implode('|', array_map('strval', array_values($target->data)));
    foreach (['must-not-leak-login', 'must-not-leak-password', 'must-not-leak-url', 'must-not-leak-notes'] as $secret) {
        if (str_contains($template_data, $secret)) {
            fwrite(STDERR, "FAIL: notification template leaked a protected field\n");
            exit(1);
        }
    }
    foreach (['login', 'password', 'url', 'notes'] as $secret_field) {
        if (array_key_exists('##credential.' . $secret_field . '##', $target->data)) {
            fwrite(STDERR, "FAIL: notification template declared a protected field\n");
            exit(1);
        }
    }

    echo "Credential lifecycle reminder tests: OK\n";
}
