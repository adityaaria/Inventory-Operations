<?php

declare(strict_types=1);

namespace App\Support;

final class Html
{
    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Opt-in attributes for browser-local form drafts (ADR-014). Owner scopes drafts to user and role; the
     * lifetime equals the absolute session limit (D-08) so a draft never outlives the longest allowed session.
     */
    public static function draftAttributes(string $form): string
    {
        $owner = $GLOBALS['workspace_draft_owner'] ?? '';
        $ttl = $GLOBALS['workspace_draft_ttl'] ?? 28800;

        return 'data-draft="' . self::e($form) . '" data-draft-owner="' . self::e(is_string($owner) ? $owner : '') . '" data-draft-ttl="' . (is_int($ttl) ? $ttl : 28800) . '"';
    }
}
