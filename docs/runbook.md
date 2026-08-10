# Runbook

The questions this panel exists to answer, in the order somebody arrives with them.

## "The shopper says they paid and there is no order"

1. Find the session. Search the **Reference** column for the id from the support ticket. It is the
   only searchable column here — see [domain.md §5](domain.md#5-evidence-and-where-it-is-not-allowed-to-go).
2. Read **Money** on the row. That column is the single question `PlaceCheckout` asks.

| What it says | What happened |
| --- | --- |
| **Settled** and status **Placed** | Checkout did its job. The problem is downstream of `CheckoutCompleted`: look at whatever subscribes to it. Confirm with **Commit record** on the session page. |
| **Settled** and status **Open** | The money is there and nothing committed. Either the client never called `PlaceCheckout`, or a guard refused it — check **Price snapshot** next. |
| **Short by …** | The tenders that *count* do not reach the total. Open the session and read the **Tenders** tab: a `pending` tender is a request, not money. |
| **Nothing tendered** | No provider has come back at all. This is a payment problem, not a checkout problem. |
| **Over-tendered by …** | The domain refuses this as loudly as under-tendering, because a refund is not something checkout can issue. Two tenders were probably recorded for one authorisation. |

3. **Price snapshot** on the session page is the other common refusal. It reads the *clock*, not the
   status column — a session can say `open` and still be past its window, and `PlaceCheckout` refuses
   it either way. There is no fix from this panel: the shopper starts a new checkout at current
   prices, which is what the expiry is for.

4. If the session has no email, placement refuses. **Email** on the session page says so.

## "Did they agree to the terms?"

Open the session, then the **Consent** tab. Each row is a `type`, a `document_version`, an answer, and
a timestamp.

A **Refused** row is evidence too — the domain stores the answer rather than implying it from the row
existing, precisely so *"declined marketing"* can be told apart from *"the form never rendered"*. A
missing row means no answer was ever captured.

For a dispute, toggle on **From address** and **From browser**. They are hidden by default because
they are read on the day they are needed and not before. Both columns are unsearchable and
unfilterable on purpose: a search term goes into the URL and from there into every access log on the
path.

## "This checkout is stuck, get rid of it"

Close it as **abandoned**. Nothing is deleted: the lines, the tenders and the consents stay, because
the questions above are still asked about closed sessions.

Pick a reason from the list. It is a fixed vocabulary rather than a text box because the domain's own
logger writes that value into the `checkout.abandoned` log line, and a text box is where somebody
types a customer's email address into a log with a long retention.

The action is hidden on any session that is not open — a placed session is a record an order
downstream already cites, and reopening it would let the snapshot that order was built from change
underneath it.

**There is no expire button.** Expiry is the price snapshot's clock running out, which is a fact
rather than a judgement. See the sweep below.

## "Nothing is in the list"

In order of likelihood:

1. The actor has no `current_team_id`. The scope answers "no rows", never "all rows" — that is a
   `whereRaw('1 = 0')` and not a `where('team_id', null)`, deliberately.
2. The sessions were created without a `teamId`. A session belonging to nobody is denied to everybody
   by the policy, so this panel does not list it. `StartCheckout` takes `teamId`; the storefront
   should be passing it.
3. `ecommerce-checkout` is not in `MODULES_ENABLED`, so no migrations ran and no policy is bound.

## The two schedules this panel assumes

Neither is scheduled by either package. The host owns both.

```php
// Close what shoppers walked away from. The list's "Open and untouched for a
// day" filter is the same question a human can ask by hand.
Schedule::call(fn () => CheckoutSessionQuery::stale(now()->subDay())
    ->each(fn ($session) => app(AbandonCheckout::class)->handle($session, reason: 'swept')))
    ->hourly();

// Close what ran out of time. Kept apart from abandonment because only one of
// them is worth a recovery email.
Schedule::call(fn () => (new CheckoutSessionQuery())->expired()
    ->each(fn ($session) => app(AbandonCheckout::class)->handle($session, expired: true)))
    ->hourly();
```

If neither runs, the panel still tells the truth — the **Price snapshot** column reads the clock, and
the two filters find both populations — but the status column will be full of sessions that say
`open` and are not.

## Pruning the idempotency keys

`PruneIdempotencyKeys` with `checkout.idempotency.retention_days` is a **safety** setting, not
housekeeping: a key pruned while a client still holds it stops being idempotent, and the next retry
places a second order. It must exceed the longest retry window any client uses. This panel shows
whether a commit was recorded for a session, and that answer disappears when the key is pruned —
which is another reason not to shorten the retention to tidy a table.

## What this panel will never do

Create a checkout, delete anything, edit a price, edit a tender, edit a consent, or place an order.
Each refusal and its reasoning is in [domain.md §1](domain.md#1-what-staff-may-do-to-a-checkout-session).
If an operator needs one of those, the answer is a fresh checkout or an operation downstream — a new
order, a refund, a fresh consent at the current document version.
