# Adoption

Installing this package, enabling it, attaching it to a panel, and what the host has to supply.

## 1. Install

The domain package this presents is **not on Packagist**. Composer honours `repositories` only from
the root manifest, so the entry goes in the *application's* `composer.json`, not in this package's —
this package declares it for its own CI and that declaration does nothing for a consumer.

```bash
composer config repositories.ecommerce-checkout vcs https://github.com/liberusoftware/module-ecommerce-checkout
composer require liberusoftware/ecommerce-checkout-filament
```

That pulls `liberusoftware/ecommerce-checkout` with it. When the domain package reaches Packagist,
the `composer config repositories.*` line is the only thing to remove.

## 2. Enable the modules

Installing boots nothing: neither package ships `extra.laravel.providers`, so Composer discovery
finds no provider. `ModuleManagerServiceProvider` registers the provider each `module.json` names,
and only when the deployment asks for it:

```dotenv
MODULES_ENABLED=ecommerce-checkout,ecommerce-checkout-filament
```

Both, in that order. The presentation package registers nothing of its own — no migrations, no
policies, no config — so enabling it without the domain module gives you a resource with no tables to
query and no gate to ask.

## 3. Migrate

The domain module's five migrations are loaded by `CheckoutServiceProvider`:

```bash
php artisan migrate
```

Every table this brings is invented by the checkout module and carries the `ecommerce_checkout_`
prefix, so there is nothing here for a host to delete first:

`ecommerce_checkout_sessions`, `ecommerce_checkout_lines`, `ecommerce_checkout_tenders`,
`ecommerce_checkout_consents`, `ecommerce_checkout_idempotency_keys`.

None of them carries a foreign key out of the package. `team_id`, `store_id`, `customer_id`,
`product_id` and `variant_id` are plain indexed columns.

## 4. Attach the plugin to a panel

The application owns its panels; this package never registers itself into one.

```php
use Filament\Panel;
use Filament\PanelProvider;
use Liberu\Ecommerce\Checkout\Filament\CheckoutPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->plugins([
                CheckoutPlugin::make(),
            ]);
    }
}
```

`module.json` declares the plugin under `presentation.filament.admin`, which is the panel id this
package is tested against — but nothing enforces it. Attach it to whichever panel should carry
checkouts, and to more than one if that is what the deployment needs.

The panel does **not** need to be tenant-aware. The resource scopes on the actor's `current_team_id`
rather than on `Filament::getTenant()`, and `isScopedToTenant()` is `false`.

## 5. What the host has to supply

| Thing | Why | What happens without it |
| --- | --- | --- |
| A `current_team_id` on the authenticated user | It is the whole of `CheckoutSessionPolicy`, and the column the resource query scopes on | Every list is empty. Not an error — the deliberate answer for an actor working in no team |
| `MODULES_ENABLED` naming both modules | Installation never implies boot | The resource exists as a class and appears nowhere |
| Colour aliases `success`, `warning`, `danger`, `info`, `gray` on the panel | Badge and notification colours | Filament's defaults apply |
| A `team_id` on the sessions a storefront creates | A session with `team_id` null belongs to nobody, and the policy denies every action on one | Those sessions are invisible in this panel, by design. `StartCheckout` takes a `teamId` argument; pass it |

Optional:

| Thing | Effect |
| --- | --- |
| `CHECKOUT_TEAM_MODEL`, if the host's team model is not `App\Models\Team` | The domain resolves it from config at call time and never imports it. Only matters if something eager-loads the `team` relation; nothing in this package does |
| `CHECKOUT_CUSTOMER_MODEL` | Makes `CheckoutSession::customer()` loadable. **This package still shows the customer as a number** — see the limitation note in [domain.md](domain.md#8-known-limitations) — but a host's own surfaces can use the relation |
| `CHECKOUT_SESSION_TTL_MINUTES` | Gives new sessions an `expires_at`. The list's *"Open past its snapshot"* filter and the **Price snapshot** column do nothing useful without it |
| `CHECKOUT_TELEMETRY=true` | The domain's own event logger starts recording. Off by default; a busy storefront writes thousands an hour |

## 6. What it does not bring

- **No way to place a checkout.** Committing money needs a client-supplied idempotency key. See
  [domain.md §2](domain.md#2-placing-is-not-offered-here).
- **No way to create, edit or delete anything** except closing a session as abandoned. That is the
  whole of [domain.md §1](domain.md#1-what-staff-may-do-to-a-checkout-session).
- **No orders.** `PlaceCheckout` emits `CheckoutCompleted` and stops; there is no `orders` table in
  the domain package and no order surface here.
- **No payment integration.** `provider` is a string the host writes. There is no SDK, no gateway
  interface and no provider name anywhere in `src/`.
- **No storefront.** Everything here is the operator's side.
- **No scheduled sweep.** The host calls `AbandonCheckout` and `PruneIdempotencyKeys` from its own
  schedule; see [runbook.md](runbook.md).

## Upgrading

This is the first release; there is nothing to upgrade from.
