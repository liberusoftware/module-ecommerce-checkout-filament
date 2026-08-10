<?php

use Illuminate\Support\Facades\Log;
use Liberu\Ecommerce\Checkout\Actions\StartCheckout;
use Liberu\Ecommerce\Checkout\Data\LineInput;
use Liberu\Ecommerce\Checkout\Filament\Tests\TestCase;
use Liberu\Ecommerce\Checkout\Models\CheckoutSession;

pest()->extend(TestCase::class)->in('Feature');

/**
 * A real session, built by the domain action rather than by a factory.
 *
 * The totals on a session are stored and not computed on read, so a factory that
 * set `grand_total_minor` by hand could disagree with the lines underneath it —
 * and every money column on this surface would then be asserting against a state
 * the domain cannot produce. `StartCheckout` computes them.
 *
 * The product id is a number nothing in this database has heard of, which is the
 * boundary this package inherits: Catalog is not installed in this suite and
 * neither is Cart.
 */
function aCheckout(?int $teamId = 7, int $unitPriceMinor = 1999, ?int $taxRateBp = 2000, ?string $email = 'shopper@example.com'): CheckoutSession
{
    return app(StartCheckout::class)->handle(
        currency: 'GBP',
        lines: [new LineInput(
            name: 'Merino Crew',
            unitPriceMinor: $unitPriceMinor,
            quantity: 1,
            productId: 987654321,
            sku: 'MER-CRW-01',
            taxRateBp: $taxRateBp,
        )],
        teamId: $teamId,
        email: $email,
    );
}

/**
 * Capture what was written to the log, in order.
 *
 * A long closure with `use (&$records)` and not an arrow function: `fn` captures
 * by value at the point it is defined, so it would hand back the empty array
 * this starts as and never see anything the listener appended.
 *
 * @return Closure(): list<array{level: string, message: string, context: array<string, mixed>}>
 */
function captureLog(): Closure
{
    $records = [];

    Log::listen(function ($record) use (&$records) {
        $records[] = ['level' => $record->level, 'message' => $record->message, 'context' => $record->context];
    });

    return function () use (&$records): array {
        return $records;
    };
}
