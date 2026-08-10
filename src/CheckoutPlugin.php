<?php

namespace Liberu\Ecommerce\Checkout\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource;

/**
 * What this package contributes to a panel the application composes.
 *
 * One resource. A checkout session is the only thing here a person navigates
 * to: its lines, its tenders and its consents are all meaningless away from the
 * session they hang off, so they are relation managers on it rather than
 * resources of their own — and a resource of their own would also be a list
 * with no tenancy column to scope on.
 *
 * There is no `IdempotencyKeyResource`, deliberately. See `docs/domain.md`; the
 * short version is that the table carries no `team_id`, so every list of it is a
 * cross-tenant list, and the key itself is a replay credential written by a
 * client.
 *
 * Listed rather than discovered by directory scan: discovery reads the
 * filesystem on every boot to rediscover a set that is fixed at release, and a
 * scan rooted at `src/` would also sweep up anything a later version happens to
 * put there.
 */
final class CheckoutPlugin implements Plugin
{
    public static function make(): self
    {
        return new self();
    }

    public function getId(): string
    {
        return 'liberu-ecommerce-checkout';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            CheckoutSessionResource::class,
        ]);
    }

    public function boot(Panel $panel): void {}
}
