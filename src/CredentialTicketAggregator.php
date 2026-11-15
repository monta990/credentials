<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials;

/**
 * Merge credential labels found through multiple ITIL sources (requesters and
 * linked items) into one row per credential. This class is deliberately free of
 * GLPI runtime dependencies so the aggregation invariant can be regression-tested.
 */
final class CredentialTicketAggregator
{
    /**
     * @param array<int, array{label:array<string,mixed>,source_label:string,source_type:string,detail_url:string,source_url?:string}> $entries
     * @param array<int|string, int|string> $protected_ids IDs found behind a sudo-mode gate
     * @return array{rows:array<int,array{id:int,name:string,date_mod:?string,detail_url:string,origins:array<int,string>,sources:array<int,string>,source_links:array<int,array{label:string,url:string}>}>,count:int}
     */
    public static function aggregate(array $entries, array $protected_ids = []): array
    {
        $rows = [];
        $all_ids = [];
        foreach ($protected_ids as $protected_id) {
            $id = (int) $protected_id;
            if ($id > 0) {
                $all_ids[$id] = true;
            }
        }

        foreach ($entries as $entry) {
            $label = $entry['label'] ?? [];
            $credential_id = (int) ($label['id'] ?? 0);
            if ($credential_id <= 0) {
                continue;
            }
            $all_ids[$credential_id] = true;

            if (!isset($rows[$credential_id])) {
                $rows[$credential_id] = [
                    'id' => $credential_id,
                    'name' => (string) ($label['name'] ?? ''),
                    'date_mod' => isset($label['date_mod']) ? (string) $label['date_mod'] : null,
                    'detail_url' => (string) ($entry['detail_url'] ?? ''),
                    'origins' => [],
                    'sources' => [],
                    'source_links' => [],
                ];
            }

            $source_type = trim((string) ($entry['source_type'] ?? ''));
            if ($source_type !== '' && !in_array($source_type, $rows[$credential_id]['origins'], true)) {
                $rows[$credential_id]['origins'][] = $source_type;
            }

            $source_label = trim((string) ($entry['source_label'] ?? ''));
            if ($source_label !== '' && !in_array($source_label, $rows[$credential_id]['sources'], true)) {
                $rows[$credential_id]['sources'][] = $source_label;
            }

            $source_url = trim((string) ($entry['source_url'] ?? ''));
            if ($source_label !== '' && $source_url !== '') {
                $source_link_exists = false;
                foreach ($rows[$credential_id]['source_links'] as $source_link) {
                    if ($source_link['url'] === $source_url) {
                        $source_link_exists = true;
                        break;
                    }
                }
                if (!$source_link_exists) {
                    $rows[$credential_id]['source_links'][] = [
                        'label' => $source_label,
                        'url' => $source_url,
                    ];
                }
            }
        }

        uasort($rows, static fn(array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));

        return [
            'rows' => array_values($rows),
            'count' => count($all_ids),
        ];
    }
}
