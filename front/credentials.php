<?php

/**
 * Compatibility redirect to the GLPI 12 Symfony controller.
 * New plugin code must use src/Controller; keep this file only for old links.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('Expires: 0');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$query = http_build_query($_GET);
$target = rtrim((string) PLUGIN_CREDENTIALS_WEBDIR, '/') . '/Credential/Details';
if ($query !== '') {
    $target .= '?' . $query;
}

// 307 preserves the POST body so legacy forms can finish in the modern route.
$status = $method === 'POST' ? 307 : 302;
header('Location: ' . $target, true, $status);
exit;
