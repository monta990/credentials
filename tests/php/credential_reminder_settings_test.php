<?php

declare(strict_types=1);

namespace {
    final class Config
    {
        public static array $pluginSettings = [];

        public static function getConfigurationValues(string $context): array
        {
            return $context === 'plugin_credentials' ? self::$pluginSettings : [];
        }
    }
}

namespace {
    use GlpiPlugin\Credentials\Service\CredentialLifecycleReminderService;

    require_once __DIR__ . '/../../src/Service/CredentialLifecycleReminderService.php';

    function assertReminderSetting(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, "FAIL: {$label}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    assertReminderSetting(
        ['enabled' => true, 'lookahead_days' => 30],
        CredentialLifecycleReminderService::getSettings(),
        'upgraded installations retain enabled reminders with the original 30-day window'
    );

    Config::$pluginSettings = [
        'lifecycle_reminders_enabled' => 0,
        'lifecycle_reminder_lookahead_days' => 90,
    ];
    assertReminderSetting(
        ['enabled' => false, 'lookahead_days' => 90],
        CredentialLifecycleReminderService::getSettings(),
        'saved disabled state and configured window are respected'
    );

    Config::$pluginSettings = [
        'lifecycle_reminders_enabled' => 'false',
        'lifecycle_reminder_lookahead_days' => '120',
    ];
    assertReminderSetting(
        ['enabled' => false, 'lookahead_days' => 120],
        CredentialLifecycleReminderService::getSettings(),
        'boolean and numeric configuration strings are normalized'
    );

    assertReminderSetting(1, CredentialLifecycleReminderService::normalizeLookaheadDays(0), 'look-ahead is clamped to its minimum');
    assertReminderSetting(365, CredentialLifecycleReminderService::normalizeLookaheadDays(900), 'look-ahead is clamped to its maximum');
    assertReminderSetting(30, CredentialLifecycleReminderService::normalizeLookaheadDays('invalid'), 'invalid values fall back to the default');

    echo "Credential reminder settings tests: OK\n";
}
