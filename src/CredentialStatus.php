<?php

/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Credentials;

/** Calculates non-secret lifecycle indicators for a credential. */
final class CredentialStatus
{
    /** @return array{label:string,key:string,expired:bool,expiring:bool,review_overdue:bool,review_due:bool} */
    public static function fromDates(?string $expires, ?string $next_review, ?string $today = null): array
    {
        $today ??= substr((string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d')), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
            $today = date('Y-m-d');
        }
        $soon = (new \DateTimeImmutable($today))->modify('+30 days')->format('Y-m-d');

        $expired = $expires !== null && $expires !== '' && $expires < $today;
        $expiring = $expires !== null && $expires !== '' && $expires >= $today && $expires <= $soon;
        $review_overdue = $next_review !== null && $next_review !== '' && $next_review < $today;
        $review_due = $next_review !== null && $next_review !== '' && $next_review >= $today && $next_review <= $soon;

        if ($expired) {
            [$key, $label] = ['expired', __('Expired', 'credentials')];
        } elseif ($review_overdue) {
            [$key, $label] = ['review_overdue', __('Review overdue', 'credentials')];
        } elseif ($expiring) {
            [$key, $label] = ['expiring', __('Expiring soon', 'credentials')];
        } elseif ($review_due) {
            [$key, $label] = ['review_due', __('Review due soon', 'credentials')];
        } else {
            [$key, $label] = ['current', __('Current', 'credentials')];
        }

        return [
            'label' => $label,
            'key' => $key,
            'expired' => $expired,
            'expiring' => $expiring,
            'review_overdue' => $review_overdue,
            'review_due' => $review_due,
        ];
    }

    public static function validDate(?string $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }
}
