<?php

namespace App\Support;

/**
 * Toast copy for the embedded app (section 2: global success and error copy).
 * Redirects carry ?notice=<key>; the layout shows the toast.
 */
class Notices
{
    public const MESSAGES = [
        'saved' => ['Changes saved successfully.', false],
        'cost_saved' => ['Cost saved. Profit now uses it, including past orders that had no cost.', false],
        'setup_done' => ['Setup complete. Your profit snapshot is ready.', false],
        'sync_started' => ['Sync started. New data appears within a few minutes.', false],
        'sync_wait' => ['A sync ran a few minutes ago. Please wait 10 minutes between manual syncs.', true],
        'ticket_created' => ['Ticket sent. We usually reply within one business day.', false],
        'reply_sent' => ['Reply sent.', false],
        'deleted' => ['The item was deleted successfully.', false],
        'invited' => ['Invite saved. They get this role when they first open Store Intelligence.', false],
        'team_limit' => ['Your plan\'s team limit is reached. Remove someone or upgrade for more seats.', true],
        'role_changed' => ['Role updated.', false],
        'access_removed' => ['Access removed.', false],
        'access_restored' => ['Access restored.', false],
        'rechecked' => ['Store details refreshed.', false],
        'reconnected' => ['Shopify connection refreshed.', false],
        'plan_updated' => ['Your plan is active. Thanks for choosing Store Intelligence!', false],
        'plan_pending' => ['We\'re waiting for Shopify to confirm your plan. This page updates once it does.', false],
        'network' => ['We couldn\'t complete that request. Please try again.', true],
        'permission' => ['You don\'t have permission to perform this action.', true],
        'shopify' => ['Your Shopify connection needs attention. Open Settings → Store & data sync to review it.', true],
        'invalid_email' => ['Enter a valid email address.', true],
        'already_member' => ['That person already has access to Store Intelligence.', true],
        'last_owner' => ['A store needs at least one owner.', true],
        'self' => ['You can\'t change your own role or access.', true],
        'plan_limit' => ['Your plan\'s limit for this feature is reached. Upgrade for more.', true],
        'unexpected' => ['Something went wrong. Please try again. If it continues, contact support with the request ID.', true],
    ];

    public static function get(?string $key): ?array
    {
        return $key !== null ? (self::MESSAGES[$key] ?? null) : null;
    }

    /**
     * A Shopify failure as merchants should see it: Shopify's own explanation when it gave one
     * (e.g. a userErrors message), never the raw API response (that is reported to us instead).
     */
    public static function shopifyError(string $lead, \Throwable $e): string
    {
        $message = $e->getMessage();
        if (str_starts_with($message, 'Admin API errors') || str_contains($message, '{') || mb_strlen($message) > 200) {
            return $lead.'. Please try again in a moment; if it keeps happening, contact support from the Support page.';
        }

        return $lead.': '.$message;
    }
}
