<?php

namespace Liberu\Ecommerce\Checkout\Filament\Support;

use Illuminate\Support\Str;
use Liberu\Ecommerce\Checkout\Actions\SetContactDetails;

/**
 * A stored address, as lines a person can read.
 *
 * The domain stores addresses whole as JSON and validates none of their fields,
 * on purpose: address shape is a per-country problem with a long tail, and a
 * package with an opinion about it releases every time a country disagrees. The
 * consequence here is that this cannot assume `line1`, `city`, `postcode` — it
 * gets whatever the storefront captured.
 *
 * So it renders the keys it was given, labelled, in the order they arrived.
 * Non-scalar values are dropped rather than printed: a nested array reaches a
 * text entry as `Array` and a stray object reaches it as a fatal, and neither is
 * worth a recursion this surface would only ever use for a field nobody has.
 *
 * @see SetContactDetails
 */
final class Address
{
    /**
     * @param  array<string, mixed>|null  $address
     * @return list<string>
     */
    public static function lines(?array $address): array
    {
        if ($address === null) {
            return [];
        }

        $lines = [];

        foreach ($address as $field => $value) {
            if (! is_scalar($value) || $value === '') {
                continue;
            }

            $lines[] = self::label((string) $field).': '.$value;
        }

        return $lines;
    }

    /**
     * `Str::headline()` alone answers `Line1` for the commonest address key
     * there is, because it splits on case and on underscores and a digit is
     * neither. Separating the digit first is the difference between `Line 1`
     * and a label somebody files a bug about.
     */
    private static function label(string $field): string
    {
        return Str::headline((string) preg_replace('/(\d+)/', ' $1', $field));
    }
}
