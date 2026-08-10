<?php

namespace Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource;

/**
 * The whole record, and one button.
 *
 * A `ViewRecord` rather than an `EditRecord`, which is the difference the rest
 * of this package is built around. The header carries the abandonment action and
 * nothing else — no edit, no delete, no replicate.
 */
class ViewCheckoutSession extends ViewRecord
{
    protected static string $resource = CheckoutSessionResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CheckoutSessionResource::abandonAction(),
        ];
    }
}
