# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this package adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-08-10

First release.

### Added

- `CheckoutPlugin`, attached per panel by the application. Nothing registers globally.
- `CheckoutSessionResource` — a list and a view page for checkout sessions, scoped to the actor's
  team, with the settlement state, the price snapshot's clock and the placement record on the page.
- Read-only relation managers for the frozen lines, the tenders and the consent evidence, all
  extending `EvidenceRelationManager`.
- One write: closing a session as **abandoned**, with a reason from a fixed vocabulary.
- Filters for open sessions that are untouched or past their snapshot.
- `docs/domain.md`, `docs/adoption.md`, `docs/runbook.md`.

### Decided

- **No create, no delete, no edit.** `CheckoutSessionPolicy` denies `create` and `delete` outright,
  and everything on a session is a copy of what a shopper agreed to. Editing a copy falsifies a
  record rather than correcting one.
- **No placement.** `PlaceCheckout` takes an idempotency key from its caller, and a button minting a
  fresh one on each press is a button that charges twice on a double click.
- **Every ability the policy does not publish is refused by name**, on the resource and on all three
  relation managers, because Laravel's unanswered gate case is permissive.
- **No surface at all for the idempotency keys.** The table has no `team_id` to scope on, the key is
  a replay credential, and its text is chosen by a client.
- **Evidence stays on the guarded page.** Nothing sensitive is searchable or filterable, the
  abandonment reason is a select rather than a text box, no notification names a shopper, and neither
  the session token nor the idempotency key is ever rendered.

[0.1.0]: https://github.com/liberusoftware/module-ecommerce-checkout-filament/releases/tag/0.1.0
