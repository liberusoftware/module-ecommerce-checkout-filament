# Checkout Administration

Filament administration for [`liberusoftware/ecommerce-checkout`](https://github.com/liberusoftware/module-ecommerce-checkout).

A checkout session is a financial record in flight: it holds the prices a shopper agreed to, the
addresses they gave, the evidence of what they consented to, and the tenders a payment provider
reported. This package puts all of that on a screen. It lets staff change almost none of it, and that
is the design rather than an unfinished corner.

```bash
composer config repositories.ecommerce-checkout vcs https://github.com/liberusoftware/module-ecommerce-checkout
composer require liberusoftware/ecommerce-checkout-filament
```

```dotenv
MODULES_ENABLED=ecommerce-checkout,ecommerce-checkout-filament
```

```php
use Liberu\Ecommerce\Checkout\Filament\CheckoutPlugin;

$panel->plugins([
    CheckoutPlugin::make(),
]);
```

Full instructions, including what the host has to supply, are in [docs/adoption.md](docs/adoption.md).

## What staff can do

| | |
| --- | --- |
| Read a checkout session, its frozen lines, its tenders and its consent evidence | Yes |
| See whether the money that came back equals the money owed | Yes |
| See whether a commit was recorded, and when | Yes |
| Close a stuck session as **abandoned**, with a reason from a fixed list | Yes |
| Create a checkout | **No** — the domain policy denies it outright |
| Delete a checkout, or anything hanging off one | **No** — the domain policy denies it outright |
| Edit a line, a price, a tax figure, a tender or a consent | **No** |
| Place a checkout — commit the money | **No**, deliberately: see below |

Every one of those refusals is argued in [docs/domain.md](docs/domain.md). The short version is that
this data is a copy of what somebody agreed to, and editing a copy does not correct a record, it
falsifies one.

Placing is absent because `PlaceCheckout` takes an idempotency key **from its caller**, and that key
is the entire guarantee against a double charge. A button minting a fresh key on every press would be
a button that charges twice on a double click. Committing money belongs to the storefront and the API,
which have a client-supplied key to pass.

## What it deliberately keeps out of reach

Consents carry an IP address and a user agent. Tenders carry a provider reference. Both are readable
here, on a page `CheckoutSessionPolicy` guards — that is what evidence is *for*. What this package
never does is let any of it travel somewhere with weaker access control:

- **Nothing sensitive is searchable or filterable.** A table's search term and its filter state are
  both persisted into the URL, and a query string is written into every access log on the path. The
  only searchable column in this package is a numeric id.
- **The abandonment reason is a select, not a text box.** The domain's own logger writes that value
  into a `checkout.abandoned` log line, and a text box is where somebody types a customer's email
  address.
- **No notification body names a shopper.**
- **The session token is never shown.** It is the guest's whole authorisation to their own checkout.
- **The idempotency key is never shown.** It is a replay credential, and it is text a *client* chose.

`tests/Feature/EvidenceTest.php` asserts each of these.

## Compatibility

PHP 8.5, Laravel 13, Filament 5. The plugin does not require the panel to be tenant-aware.

## Documentation

- [docs/domain.md](docs/domain.md) — what this surface shows, what it refuses, and why each refusal.
- [docs/adoption.md](docs/adoption.md) — installing, enabling, attaching, and what the host supplies.
- [docs/runbook.md](docs/runbook.md) — the questions this panel exists to answer, in order.

## Licence

MIT. See [LICENSE.md](LICENSE.md).

<!-- module-presentation-guide:begin -->
## Presentation architecture and project hosts

- Capability/source key: `planned:ecommerce:checkout`.
- Actual Composer identity: `liberusoftware/ecommerce-checkout-filament`.
- [Filament capability plan](https://github.com/liberusoftware/documentation/blob/main/projects/ecommerce/filament/checkout.md) · [Filament standard](https://github.com/liberusoftware/documentation/blob/main/standards/FILAMENT.md) · [Module architecture](https://github.com/liberusoftware/documentation/blob/main/architecture/MODULES.md).

The application owns panel composition, routing, session integration and deployment. This module owns its focused presentation and delegates domain behavior to reviewed public owner contracts. Keep host App aliases, duplicate records and client-only authorization outside reusable package boundaries.

Documented consumer project families:

- [Ecommerce](https://github.com/liberusoftware/project-ecommerce-laravel-filament): Catalogs, checkout, orders and fulfillment.

Other web, mobile and desktop presentations are listed in the [project catalog](https://github.com/liberusoftware/documentation/blob/main/projects/REPOSITORIES.md). Those skeletons provide starting structures; links do not establish feature parity, compatible releases or completed device/API integrations. Independent presentation packages keep their own Composer, npm or pub metadata.
<!-- module-presentation-guide:end -->
