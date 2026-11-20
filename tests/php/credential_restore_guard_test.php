<?php
/**
 * Local model regression for the GLPI 12 restore access-control finding.
 * Run: php tests/php/credential_restore_guard_test.php
 */

if (!class_exists('CommonDBTM')) {
    class CommonDBTM
    {
        public static int $parentRestoreCalls = 0;

        public function getForbiddenStandardMassiveAction()
        {
            return [];
        }

        public function restore(array $input, $history = true)
        {
            self::$parentRestoreCalls++;
            return true;
        }

        public function prepareInputForAdd($input)
        {
            return $input;
        }

        public function prepareInputForUpdate($input)
        {
            return $input;
        }

        public function pre_deleteItem()
        {
            return true;
        }

        public function canDeleteItem(): bool
        {
            return true;
        }

        public function canPurgeItem(): bool
        {
            return true;
        }

        public static function getTable($classname = null)
        {
            return 'glpi_plugin_credentials_credentials';
        }

        public static function getTypeName($nb = 0)
        {
            return 'Credential';
        }

        protected static function itemTypeRequiresReauthentication(): bool
        {
            return false;
        }
    }
}

if (!class_exists('Session')) {
    class Session
    {
        public static function haveAccessToEntity(int $entity): bool
        {
            return true;
        }
    }
}

if (!class_exists('Log')) {
    class Log
    {
        public const HISTORY_PLUGIN = 1000;
    }
}

require_once dirname(__DIR__, 2) . '/src/Credential.php';

use GlpiPlugin\Credentials\Credential;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$credential = new Credential();
$forbidden = $credential->getForbiddenStandardMassiveAction();
check(in_array('restore', $forbidden, true), 'restore appears in forbidden standard massive actions');

$untrusted = $credential->restore(['id' => 42]);
check($untrusted === false, 'untrusted model restore is refused');
check(CommonDBTM::$parentRestoreCalls === 0, 'untrusted restore never reaches CommonDBTM::restore');

$trusted = Credential::withTrustedWrite(
    static fn(): bool => $credential->restore(['id' => 42]) === true
);
check($trusted === true, 'trusted model restore may delegate to CommonDBTM');
check(CommonDBTM::$parentRestoreCalls === 1, 'trusted restore reaches parent exactly once');

try {
    Credential::withTrustedWrite(static function (): void {
        throw new RuntimeException('expected regression-test exception');
    });
} catch (RuntimeException $exception) {
    check($exception->getMessage() === 'expected regression-test exception', 'trusted-write exception propagated');
}

$afterException = $credential->restore(['id' => 42]);
check($afterException === false, 'trusted-write depth resets after an exception');
check(CommonDBTM::$parentRestoreCalls === 1, 'post-exception untrusted restore still cannot reach parent');

fwrite(STDOUT, "PASS: credential restore guard regression\n");
