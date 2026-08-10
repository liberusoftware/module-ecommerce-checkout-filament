<?php

use Carbon\Carbon;
use Filament\Actions\Testing\TestAction;
use Liberu\Ecommerce\Checkout\Actions\RecordTender;
use Liberu\Ecommerce\Checkout\Enums\TenderStatus;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\Pages\ListCheckoutSessions;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\Pages\ViewCheckoutSession;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\RelationManagers\LinesRelationManager;
use Liberu\Ecommerce\Checkout\Filament\Support\Amount;
use Liberu\Ecommerce\Checkout\Filament\Support\Settlement;
use Liberu\Ecommerce\Checkout\Models\CheckoutSession;
use Livewire\Livewire;

afterEach(function () {
    Carbon::setTestNow();
});

it('renders money from the integer without ever constructing a float', function () {
    // `(int) (19.99 * 100)` is 1998, and `1999 / 100` is where the penny goes
    // back the other way. Both conversions are string arithmetic in
    // `MinorUnits`, and this is the pin that keeps a `number_format` from
    // creeping into a column because it "looks the same".
    expect(Amount::format(1999, 'GBP'))->toBe('GBP 19.99')
        ->and(Amount::format(0, 'GBP'))->toBe('GBP 0.00')
        ->and(Amount::format(-500, 'GBP'))->toBe('GBP -5.00')
        // A zero-exponent currency. Rendered on the assumption of two, 1999 yen
        // would appear on a receipt as ¥19.99.
        ->and(Amount::format(1999, 'JPY', 0))->toBe('JPY 1999')
        ->and(Amount::format(1999, 'BHD', 3))->toBe('BHD 1.999');
});

it('renders every total in the currency and exponent the session was frozen at', function () {
    $this->actorForTeam(7);

    $session = aCheckout(unitPriceMinor: 1999, taxRateBp: 2000);

    expect($session->subtotal_minor)->toBe(1999)
        ->and($session->tax_minor)->toBe(400)
        ->and($session->grand_total_minor)->toBe(2399);

    Livewire::test(ViewCheckoutSession::class, ['record' => $session->getRouteKey()])
        ->assertOk()
        ->assertSee('GBP 23.99')
        ->assertSee('GBP 19.99');
});

it('says in words how the tenders stand against the total', function () {
    $this->actorForTeam(7);

    $session = aCheckout();

    expect(Settlement::describe($session))->toBe('Nothing tendered')
        ->and(Settlement::outstanding($session))->toBe(2399);

    app(RecordTender::class)->handle($session, amountMinor: 1000, status: TenderStatus::Authorized);
    expect(Settlement::describe($session->fresh()))->toBe('Short by GBP 13.99');

    app(RecordTender::class)->handle($session->fresh(), amountMinor: 1399, status: TenderStatus::Authorized);
    expect(Settlement::describe($session->fresh()))->toBe('Settled')
        ->and(Settlement::colour($session->fresh()))->toBe('success');

    // Over-tendering is its own state and not "settled": `PlaceCheckout` refuses
    // it as loudly as under-tendering, because a refund is not something
    // checkout can issue.
    app(RecordTender::class)->handle($session->fresh(), amountMinor: 100, status: TenderStatus::Authorized);
    expect(Settlement::describe($session->fresh()))->toBe('Over-tendered by GBP 1.00')
        ->and(Settlement::colour($session->fresh()))->toBe('warning');
});

it('counts only the tenders a provider actually came back on', function () {
    $this->actorForTeam(7);

    $session = aCheckout();

    // Pending is a request, not money. A session whose only tender is pending
    // looks paid to somebody scanning a list, and is the exact checkout
    // `PlaceCheckout` will refuse.
    app(RecordTender::class)->handle($session, amountMinor: 2399, status: TenderStatus::Pending);
    expect(Settlement::tendered($session->fresh()))->toBe(0);

    app(RecordTender::class)->handle($session->fresh(), amountMinor: 2399, status: TenderStatus::Failed);
    expect(Settlement::tendered($session->fresh()))->toBe(0);
});

it('tells the clock apart from the column when a snapshot has run out', function () {
    Carbon::setTestNow('2026-08-10 12:00:00');

    $this->actorForTeam(7);

    $open = aCheckout();
    $expiring = aCheckout();
    $expiring->forceFill(['expires_at' => now()->subMinute()])->save();

    // The status still says `open` — a sweep has not run — and `PlaceCheckout`
    // will refuse it anyway, because it asks the clock. An operator wondering
    // why a settled checkout will not place needs that said out loud.
    expect($expiring->status->value)->toBe('open')
        ->and($expiring->hasExpired())->toBeTrue();

    Livewire::test(ListCheckoutSessions::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$open, $expiring])
        ->assertTableColumnStateSet('snapshot', 'No expiry set', $open)
        ->assertSee('Expired');
});

it('finds the open sessions past their snapshot, and only those', function () {
    Carbon::setTestNow('2026-08-10 12:00:00');

    $this->actorForTeam(7);

    $fresh = aCheckout();
    $stale = aCheckout();
    $stale->forceFill(['expires_at' => now()->subHour()])->save();

    $alreadyClosed = aCheckout();
    $alreadyClosed->forceFill(['expires_at' => now()->subHour(), 'status' => 'expired'])->save();

    Livewire::test(ListCheckoutSessions::class)
        ->filterTable('expired')
        ->assertCanSeeTableRecords([$stale])
        // `whereNotNull` is meant literally: a session with no window cannot be
        // past one, and `where('expires_at', null)` would have listed exactly
        // the sessions with no expiry at all.
        ->assertCanNotSeeTableRecords([$fresh, $alreadyClosed]);
});

it('finds the open sessions nothing has touched, and only those', function () {
    Carbon::setTestNow('2026-08-10 12:00:00');

    $this->actorForTeam(7);

    $busy = aCheckout();

    $forgotten = aCheckout();
    // `updated_at` is not fillable through the domain, and touching the model
    // would move it back to now.
    CheckoutSession::query()->whereKey($forgotten->getKey())->update(['updated_at' => now()->subDays(3)]);

    Livewire::test(ListCheckoutSessions::class)
        ->filterTable('stale')
        ->assertCanSeeTableRecords([$forgotten])
        ->assertCanNotSeeTableRecords([$busy]);
});

it('closes a stuck session as abandoned and keeps everything on it', function () {
    $this->actorForTeam(7);

    $session = aCheckout();
    app(RecordTender::class)->handle($session, amountMinor: 500, status: TenderStatus::Failed);

    Livewire::test(ListCheckoutSessions::class)
        ->callAction(TestAction::make('abandon')->table($session), ['reason' => 'payment-failed'])
        ->assertHasNoActionErrors();

    $session->refresh();

    expect($session->status->value)->toBe('abandoned')
        ->and($session->abandoned_at)->not->toBeNull()
        // Nothing is deleted. The lines are what the shopper saw and the tender
        // is what a provider said; abandoning a checkout is not a reason to
        // stop being able to answer either question.
        ->and($session->lines()->count())->toBe(1)
        ->and($session->tenders()->count())->toBe(1);
});

it('shows the frozen line exactly as it was copied, with no catalogue behind it', function () {
    $this->actorForTeam(7);

    $session = aCheckout();
    $line = $session->lines()->firstOrFail();

    // The product id is a number nothing in this database has heard of. Catalog
    // is not a dependency of this package and is not installed in this suite.
    expect($line->product_id)->toBe(987654321);

    Livewire::test(LinesRelationManager::class, [
        'ownerRecord' => $session,
        'pageClass' => ViewCheckoutSession::class,
    ])
        ->assertOk()
        ->assertTableColumnStateSet('name', 'Merino Crew', $line)
        ->assertTableColumnFormattedStateSet('unit_price_minor', 'GBP 19.99', $line)
        ->assertTableColumnFormattedStateSet('gross_minor', 'GBP 23.99', $line)
        ->assertTableColumnStateSet('tax_rate_bp', '20%', $line);
});

it('says a tax amount was supplied rather than showing a rate that does not exist', function () {
    $this->actorForTeam(7);

    // Tax is an input. It arrives either as a rate in basis points or as an
    // already-computed amount, and a rate column showing "0%" for the second
    // case would be stating a rate nobody chose.
    $session = aCheckout(taxRateBp: null);
    $line = $session->lines()->firstOrFail();

    expect($line->tax_rate_bp)->toBeNull();

    Livewire::test(LinesRelationManager::class, [
        'ownerRecord' => $session,
        'pageClass' => ViewCheckoutSession::class,
    ])
        ->assertOk()
        ->assertTableColumnStateSet('tax_rate_bp', 'Amount supplied', $line);
});
