<?php

namespace Liberu\Ecommerce\Checkout\Filament\Support;

use Liberu\Ecommerce\Checkout\Actions\PlaceCheckout;
use Liberu\Ecommerce\Checkout\Models\CheckoutSession;
use Liberu\Ecommerce\Checkout\Models\IdempotencyKey;

/**
 * Whether a commit was recorded for this session, and when.
 *
 * The question an operator asks about a session that says `placed` but that
 * nobody downstream seems to have heard about: *did the commit actually
 * happen, or did something re-read a stale row*. The idempotency record is the
 * evidence, because the domain writes it inside the same claim that closes the
 * session.
 *
 * **The key itself is never returned, and never rendered.** Two reasons, and
 * either alone would be enough. It is a replay credential — anybody holding it
 * can make `PlaceCheckout` hand back the stored result — and it is a string a
 * *client* chose, so it may contain an order reference, an email address or
 * whatever else somebody's mobile app thought would make a good unique value.
 * Rendering untrusted client text as evidence is how a shopper's address ends up
 * in a support screenshot.
 *
 * The row is found by `subject_id`, which the domain sets to the session's id,
 * and it is reachable only from a session the actor has already been authorized
 * to view. That is deliberate: `ecommerce_checkout_idempotency_keys` carries no
 * `team_id`, so there is no way to scope a list of it and this package ships no
 * such list.
 */
final class PlacementRecord
{
    public static function for(CheckoutSession $session): ?IdempotencyKey
    {
        return IdempotencyKey::query()
            ->where('scope', PlaceCheckout::SCOPE)
            ->where('subject_id', $session->getKey())
            ->first();
    }

    public static function describe(CheckoutSession $session): string
    {
        $record = self::for($session);

        if ($record === null) {
            return 'No commit recorded';
        }

        return 'Committed '.($record->created_at?->toDayDateTimeString() ?? 'at an unrecorded time');
    }
}
