<?php
/**
 * Guard against reintroducing direct runtime SQL in GLPI-facing code.
 * Run with: php tests/php/runtime_db_access_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$violations = [];
foreach (['src', 'front'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        foreach ($lines as $line_number => $line) {
            if (preg_match('/(?:\$DB|\$GLOBALS\[["\']DB["\']\])->doQuery\s*\(/', $line)) {
                $violations[] = $path . ':' . ($line_number + 1);
            }
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, "FAIL: direct runtime DB queries found:\n" . implode("\n", $violations) . "\n");
    exit(1);
}

$link_source = file_get_contents($root . '/src/CredentialItemLink.php');
$category_source = file_get_contents($root . '/src/CredentialCategory.php');
$checks = [
    str_contains($link_source, "new QueryExpression(\\DBmysql::quoteName('plugin_credentials_credentials_id') . ' FOR UPDATE')"),
    str_contains($link_source, "new QueryExpression(\\DBmysql::quoteName('itemtype') . ', ' . \\DBmysql::quoteName('items_id') . ' FOR UPDATE')"),
    str_contains($category_source, "new QueryExpression(\\DBmysql::quoteName('id') . ' FOR UPDATE')"),
];
if (in_array(false, $checks, true)) {
    fwrite(STDERR, "FAIL: expected DBAL locking expressions are missing.\n");
    exit(1);
}

echo "PASS: no direct DB->doQuery calls exist under src/ or front/.\n";
echo "PASS: locking reads use fixed DBAL QueryExpression clauses.\n";
