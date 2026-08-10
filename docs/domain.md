# The surface, and every decision behind it

What this package puts on a screen, what it refuses to let anybody do, and the reasoning for each
refusal. The domain itself is documented in
[`module-ecommerce-checkout`](https://github.com/liberusoftware/module-ecommerce-checkout/blob/main/docs/domain.md);
this is the presentation package's own decisions.

## The one sentence

A checkout session is a financial record in flight, so this is a read-mostly surface with exactly one
write on it.

## 1. What staff may do to a checkout session

The decision, in full. Everything in the left column is a deliberate answer, not a gap.

| Operation | Answer | Why |
| --- | --- | --- |
| **Read** a session, its lines, its tenders, its consents | **Yes** | This is the point. A session is the record somebody is asked about later. |
| **See the settlement** — what came back against what is owed | **Yes** | It is the one arithmetic question `PlaceCheckout` asks, and the first thing anybody investigating a stuck checkout wants. |
| **See the placement record** — whether a commit happened | **Yes**, as a sentence and a timestamp | Answers *"it says placed but nothing downstream heard about it"*. |
| **Close a session as abandoned** | **Yes**, with a reason from a fixed list | The one operation somebody can genuinely want on a stuck checkout that rewrites nothing the shopper agreed to. |
| **Create a checkout** | **No** | `CheckoutSessionPolicy::create()` denies it outright. A session is created by a storefront, an API client or a phone-order tool calling `StartCheckout` **with lines**; there is no meaningful empty session for a form to make. |
| **Delete a session, a line, a tender or a consent** | **No** | `CheckoutSessionPolicy::delete()` denies it outright. A checkout is evidence of what somebody agreed to, and the consent rows hang off it. Abandon it instead. |
| **Edit a line's money, its quantity, its tax** | **No** | The figures are a *copy* taken when the checkout began. Editing the copy does not correct anything: the shopper's confirmation email, the payment provider's record and any order downstream still say the old number. What it does is destroy the only record of what was actually agreed. |
| **Edit a tender** | **No** | A tender is somebody else's statement of fact. Changing a status here would be changing what a provider said while the provider's own record still says otherwise. |
| **Edit or add a consent** | **No** | A consent has an `agreed_at` and, usually, an IP address. Both of them are the evidence. A consent typed in by staff is a consent nobody gave. If a fresh agreement is needed, capture a fresh one at the current document version, through the storefront. |
| **Edit the email or an address** | **No** | Defensible, and refused anyway. The domain publishes `SetContactDetails` and `CheckoutSessionPolicy` would allow `update` on an open session — but a correction typed here diverges from the shopper's own copy silently, and the shopper's checkout page is one click away for them. If a deployment wants it, it is a resource in *their* panel calling a published action, not a hidden edit inside a record surface. |
| **Close a session as expired** | **No** | Expiry is the price snapshot's own clock running out — a fact, not a judgement. A person pressing a button to declare it would be backdating the merchant's decision. `CheckoutSessionQuery::expired()` and the host's schedule own that transition. |
| **Place a checkout** | **No** | See §2. |
| **Anything the policy has no opinion about** | **No** | See §3. |

## 2. Placing is not offered here

`PlaceCheckout` takes an idempotency key **from its caller**, and the key is the whole guarantee:
first call with a key commits, a repeat with the same key and payload replays the stored result, a
repeat with a different payload conflicts. That is the difference between one charge and three when a
shopper double-clicks or a mobile client retries.

A panel has no client-supplied key. A button that minted a fresh one on each press would have exactly
the failure mode idempotency exists to prevent, and one that reused a constant would conflict on the
second checkout. So committing money stays with the storefront and the API, which do have a key, and
this package shows *whether* a commit was recorded rather than offering to make one.

The domain keeps `place` as an ability distinct from `update` for a host that wants a second pair of
eyes on it. Nothing here exercises that ability; the separation is preserved for whoever does.

## 3. Silence is permission, so every ability is answered by name

Laravel's unanswered gate case is **permissive**. That has produced a live leak in this fleet twice.

`CheckoutSessionPolicy` publishes `viewAny`, `view`, `create`, `update`, `place`, `abandon` and
`delete`. Everything else — `deleteAny`, `forceDelete`, `forceDeleteAny`, `restore`, `restoreAny`,
`replicate`, `reorder` — has **no answer at all** and would default open on a model holding an email
address, two postal addresses and payment references. `CheckoutSessionResource` refuses each of them
by name.

`canEdit()` is refused too, even though the policy *does* publish `update` and would allow it on an
open session the actor owns. There is no edit page and no edit action here, and an ability that
answers yes with nowhere to exercise it is a door left ajar for the next person who scaffolds a page.

**`CheckoutLine`, `CheckoutTender` and `CheckoutConsent` have no policy at all.** The domain
registers one, for the session. So `EvidenceRelationManager` — the base class all three relation
managers extend — refuses `canCreate`, `canEdit`, `canDelete`, `canDeleteAny`, `canForceDelete`,
`canForceDeleteAny`, `canRestore`, `canRestoreAny`, `canReplicate`, `canReorder`, `canAssociate`,
`canDissociate`, `canDissociateAny`, `canAttach`, `canDetach` and `canDetachAny`, and restates
`canViewAny()` as `view` on the owning session. `tests/Feature/SecurityTest.php` asserts every one of
them on every one of the three.

The associate and dissociate pair matter more than they look: a `hasMany` relation manager offers
them, and moving a tender between sessions is how somebody's payment evidence ends up filed against
somebody else's order.

## 4. The idempotency keys have no surface at all

`ecommerce_checkout_idempotency_keys` gets no resource and no relation manager. Three reasons, and
any one of them is sufficient:

1. **The table carries no `team_id`.** There is no column to scope a list on, so every list of it
   would be a list of every merchant's commits.
2. **The key is a replay credential.** Anybody holding one can make `PlaceCheckout` hand back the
   stored result.
3. **The key is text a client chose.** It may contain an order reference, an email address, or
   whatever a mobile app decided made a good unique value. Rendering untrusted client text as
   evidence is how a shopper's details end up in a support screenshot.

What replaces it is one sentence on the session's own page — *"Committed Mon, Aug 10, 2026 2:14 PM"*
or *"No commit recorded"* — built from a row found by that session's id, on a page the policy already
guards. `SecurityTest` asserts that no resource in the panel has `IdempotencyKey` as its model and
that the relation managers hang off exactly `lines`, `tenders` and `consents`.

## 5. Evidence, and where it is not allowed to go

Consents carry `ip_address` and `user_agent`. Tenders carry `provider` and `reference`. All of it is
**shown**, on a page `CheckoutSessionPolicy::view()` guards — hiding it from an operator who is
already authorized would defeat the point of storing it, which is to be produced to a regulator, to a
shopper who asks, or to a dispute.

The rule is about where it may *travel*:

| Route out | What stops it |
| --- | --- |
| A **query string** | Nothing sensitive is searchable and nothing sensitive has a filter. A table's search term and its filter state are both `#[Url]` state, and a URL is written into every proxy and access log on the path. The only searchable column in this package is a numeric id. |
| A **log line** | The abandonment reason is a `Select` over five slugs, not a text box — the domain's `DomainEventLogger` copies that value into `checkout.abandoned`, and a text box is where *"jane@example.com rang to cancel"* gets typed. Nothing else in this package logs. |
| A **notification body** | The success notification says *"Recorded as abandoned. The lines, tenders and consents are kept."* and names nobody. `EvidenceTest` pins the exact string. |
| A **screenshot or a URL** | The session `token` is never rendered. It is the guest's whole authorisation to their own checkout and it never reaches a gate. |

`ip_address` and `user_agent` are toggled off by default: they are read on the day they are needed
and not before.

## 6. Tenancy

`PanelTeam::id()` reads `current_team_id` off the authenticated actor — the same column
`CheckoutSessionPolicy` reads. A list scoped by one rule and authorized by another shows rows every
row action then refuses, which reads as a broken panel rather than as a denied one.

Read off the actor rather than `Filament::getTenant()`, because nothing in the manifest says the
host's panel is tenant-aware and a null tenant would silently widen the scope to every merchant.

The no-team case is `whereRaw('1 = 0')` and never `where('team_id', null)`. The second compiles to
`is null`, which would list precisely the unowned sessions the policy denies everybody — and a list of
other people's abandoned checkouts is not a cosmetic bug.

## 7. Money

Every figure is an integer count of the currency's smallest unit and is rendered through
`MinorUnits::toDecimalString()`, which is string arithmetic: pad, split, concatenate. No float is
constructed anywhere in this package. `number_format($minor / 100, 2)` would agree with it on nearly
every amount, which is exactly what makes it dangerous.

The exponent travels with the amount rather than being assumed to be two, because the session stores
it. A zero-exponent currency rendered on the assumption of two has divided somebody's yen by a
hundred on their receipt. `SessionTest` pins GBP, JPY and BHD.

Amounts are prefixed with the ISO code rather than a symbol: a panel serving several merchants shows
several currencies in one column, `£` and `¥` do not tell a screen reader which is which, and this
package ships no currency table to look a symbol up in.

Tax rates are shown as a percentage derived from basis points with integer arithmetic — `2000` is
`20%`, `838` is `8.38%`. A line whose tax arrived as an already-computed **amount** says
*"Amount supplied"* rather than `0%`, because a rate column showing zero would be stating a rate
nobody chose.

## 8. Known limitations

**A customer is a number.** `CheckoutSession::customer()` resolves `checkout.customer_model` at call
time and **throws** when the host has not named one, which is the state of every deployment that runs
checkout without CRM. A column using the relation would be a panel that 500s there, so the customer
is shown as `customer_id`. A host that has configured the model can add a column of its own in its
own panel.

**A store is a number**, for the same reason: stores belong to `ecommerce-commerce-core`, which this
package does not depend on.

**No widget.** A dashboard stat — open checkouts, value at risk — is plausible and is not here. It
would need its own tenancy answer and its own accessibility argument to earn its place, and nothing
in this release needs it.

**No storefront.** Everything here is the operator's side. `CheckoutSessionQuery::byToken()` in the
domain package is the shopper's.

## 9. Accessibility

Every state that could be a colour is also a word, in its own column: **Counted / Not counted** for a
tender, **Agreed / Refused** for a consent, **Taxable / Not taxable** for a line, and the settlement
phrase (*"Short by GBP 13.99"*) rather than a red dot. Colour repeats what the word says; it never
carries it.

The one action carries both an icon and a label, because Filament may render an icon-bearing action
as an icon button and an icon button with no accessible name is announced as "button".

Computed columns are labelled: Filament humanises `lines_count` into "Lines count" and
`grand_total_minor` into "Grand total minor", and the label is the only thing keeping either out.

The list page says in a sentence what the surface will and will not do, because three refusals
inferred from three missing buttons is a puzzle.
