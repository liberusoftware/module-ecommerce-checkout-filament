<?php

namespace Liberu\Ecommerce\Checkout\Filament\Support;

use Liberu\Ecommerce\Checkout\Models\CheckoutSession;
use Liberu\Ecommerce\Checkout\Support\MinorUnits;

/**
 * Money on a screen, rendered from the integer the database holds.
 *
 * **No float ever appears here.** `MinorUnits::toDecimalString()` is string
 * arithmetic — pad, split, concatenate — and the whole reason it exists is that
 * `1999 / 100` is a division and divisions are where the penny goes. A column
 * that formatted with `number_format($minor / 100, 2)` would agree with this
 * on nearly every amount, which is exactly what makes it dangerous.
 *
 * The exponent travels with the amount rather than being assumed to be two,
 * because the session stores it: a zero-exponent currency rendered as
 * `1999 → 19.99` has divided somebody's yen by a hundred on their receipt.
 *
 * Prefixed with the ISO code rather than a symbol. A panel serving several
 * merchants shows several currencies in one column, `£` and `¥` do not tell a
 * screen reader which is which, and this package ships no currency table to look
 * a symbol up in.
 */
final class Amount
{
    public static function format(int $minor, string $currency, int $exponent = 2): string
    {
        return $currency.' '.MinorUnits::toDecimalString($minor, $exponent);
    }

    /** The same amount, in the currency and exponent a session was frozen at. */
    public static function of(CheckoutSession $session, int $minor): string
    {
        return self::format($minor, $session->currency, $session->currency_exponent);
    }
}
