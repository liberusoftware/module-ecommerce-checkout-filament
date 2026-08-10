<?php

namespace Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource;

/**
 * No header actions, because there is no `CreateAction` to put in them: a
 * checkout is started by a storefront, an API client or a phone-order tool
 * calling `StartCheckout` with lines, and `CheckoutSessionPolicy::create()`
 * denies the ability outright.
 */
class ListCheckoutSessions extends ListRecords
{
    protected static string $resource = CheckoutSessionResource::class;

    public function getSubheading(): string
    {
        return 'Checkouts are read-only here. A session can be closed as abandoned; nothing on one can be edited or deleted.';
    }
}
