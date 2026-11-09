<?php
/** Regression contract for native rendering of credential orphan history. */
$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/src/CredentialHistory.php');

if (!is_string($source)) {
    throw new RuntimeException('Unable to read CredentialHistory source.');
}

$method_start = strpos($source, 'public static function recordForCredential(');
$history_start = $method_start === false
    ? false
    : strpos($source, 'return (bool) Log::history(', $method_start);
$history_end = $history_start === false ? false : strpos($source, ');', $history_start);

if ($method_start === false || $history_start === false || $history_end === false) {
    throw new RuntimeException('Unable to locate credential lifecycle history call.');
}

$arguments = substr($source, $history_start, $history_end - $history_start);
$expected = '            Credential::class,' . PHP_EOL . '            Log::HISTORY_PLUGIN + $action,';
if (!str_contains($arguments, $expected)) {
    throw new RuntimeException('Credential lifecycle history must pass Credential::class as itemtype_link for native GLPI rendering.');
}

echo "Credential orphan history rendering contract: OK\n";
