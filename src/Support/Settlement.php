<?php

namespace Liberu\Ecommerce\Checkout\Filament\Support;

use Liberu\Ecommerce\Checkout\Models\CheckoutSession;

/**
 * Whether the money that came back equals the money owed, said in words.
 *
 * This is the one arithmetic question `PlaceCheckout` asks, and it is the first
 * thing anybody looking at a stuck checkout wants answered. A tick or a coloured
 * dot answers it for people who can separate green from amber and for nobody
 * else, so the answer is a phrase.
 *
 * **Over-tendered is its own state and not "settled".** The domain refuses to
 * place an over-tendered session as loudly as an under-tendered one, because a
 * refund is not something checkout can issue — and a panel that showed a green
 * "settled" against a session the domain will never accept would send an
 * operator looking for the fault everywhere except where it is.
 *
 * `tenderedMinor()` on the model counts only `authorized` and `captured`; a
 * pending authorisation is a request and not money.
 */
final class Settlement
{
    public static function tendered(CheckoutSession $session): int
    {
        // `tenderedMinor()` walks the loaded relation, so a session that
        // arrived without it would answer zero rather than query. The list
        // eager-loads it; this is the belt to that pair of braces.
        $session->loadMissing('tenders');

        return $session->tenderedMinor();
    }

    public static function outstanding(CheckoutSession $session): int
    {
        return $session->grand_total_minor - self::tendered($session);
    }

    public static function describe(CheckoutSession $session): string
    {
        $tendered = self::tendered($session);

        if ($tendered === $session->grand_total_minor) {
            return 'Settled';
        }

        if ($tendered > $session->grand_total_minor) {
            return 'Over-tendered by '.Amount::of($session, $tendered - $session->grand_total_minor);
        }

        if ($tendered === 0) {
            return 'Nothing tendered';
        }

        return 'Short by '.Amount::of($session, $session->grand_total_minor - $tendered);
    }

    /** Colour repeats the phrase rather than carrying it. */
    public static function colour(CheckoutSession $session): string
    {
        return self::tendered($session) === $session->grand_total_minor ? 'success' : 'warning';
    }
}
