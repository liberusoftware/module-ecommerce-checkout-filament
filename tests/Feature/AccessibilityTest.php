<?php

use Liberu\Ecommerce\Checkout\Actions\RecordConsent;
use Liberu\Ecommerce\Checkout\Actions\RecordTender;
use Liberu\Ecommerce\Checkout\Enums\TenderStatus;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\Pages\ListCheckoutSessions;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\Pages\ViewCheckoutSession;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\RelationManagers\ConsentsRelationManager;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\RelationManagers\LinesRelationManager;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\RelationManagers\TendersRelationManager;
use Liberu\Ecommerce\Checkout\Filament\Support\Address;
use Livewire\Livewire;

// What a keyboard and a screen reader get out of these surfaces. The two things
// worth a test are the two that regress silently: a state rendered as colour or
// as an icon with no words, and an action whose only affordance is an icon with
// no accessible name. Both look correct in a screenshot.

it('gives the one action it offers an accessible name as well as an icon', function () {
    $action = CheckoutSessionResource::abandonAction();

    // An action carrying an icon is an action Filament may render as an icon
    // button, and an icon button with no label is a control a screen reader
    // announces as "button".
    expect($action->getIcon())->not->toBeNull()
        ->and((string) $action->getLabel())->toBe('Close as abandoned');
});

it('says whether a tender counts rather than relying on the badge colour', function () {
    $this->actorForTeam(7);

    $session = aCheckout();
    app(RecordTender::class)->handle($session, amountMinor: 2399, status: TenderStatus::Pending);
    app(RecordTender::class)->handle($session->fresh(), amountMinor: 100, status: TenderStatus::Captured);

    $tenders = $session->fresh()->tenders;

    Livewire::test(TendersRelationManager::class, [
        'ownerRecord' => $session,
        'pageClass' => ViewCheckoutSession::class,
    ])
        ->assertOk()
        // Green and grey are the same badge to a screen reader and to anybody
        // who cannot separate the two. The verdict is a word in its own column.
        ->assertTableColumnStateSet('counts', 'Not counted', $tenders->firstOrFail())
        ->assertTableColumnStateSet('counts', 'Counted', $tenders->last())
        ->assertTableColumnFormattedStateSet('status', 'Pending', $tenders->firstOrFail());
});

it('says whether a consent was agreed or refused, in a word', function () {
    $this->actorForTeam(7);

    $session = aCheckout();
    app(RecordConsent::class)->handle($session, type: 'terms', documentVersion: '1');
    app(RecordConsent::class)->handle($session, type: 'marketing', documentVersion: '1', agreed: false);

    $consents = $session->fresh()->consents;

    // A refusal is evidence too, and a blank cell cannot be told apart from a
    // form that never rendered.
    Livewire::test(ConsentsRelationManager::class, [
        'ownerRecord' => $session,
        'pageClass' => ViewCheckoutSession::class,
    ])
        ->assertOk()
        ->assertTableColumnStateSet('agreed', 'Agreed', $consents->firstOrFail())
        ->assertTableColumnStateSet('agreed', 'Refused', $consents->last());
});

it('says whether a line is taxed rather than showing a tick and a blank', function () {
    $this->actorForTeam(7);

    $session = aCheckout();
    $line = $session->lines()->firstOrFail();

    Livewire::test(LinesRelationManager::class, [
        'ownerRecord' => $session,
        'pageClass' => ViewCheckoutSession::class,
    ])
        ->assertOk()
        ->assertTableColumnStateSet('taxable', 'Taxable', $line);
});

it('heads its computed columns with words rather than with attribute names', function () {
    $this->actorForTeam(7);

    aCheckout();

    // Filament humanises a column name into a heading when none is given, which
    // is how `lines_count` becomes "Lines count" and `grand_total_minor`
    // becomes "Grand total minor". Neither is what the column holds, and the
    // label is the only thing keeping them out.
    Livewire::test(ListCheckoutSessions::class)
        ->assertOk()
        ->assertSee('Lines')
        ->assertDontSee('Lines count')
        ->assertSee('Total')
        ->assertDontSee('Grand total minor')
        ->assertSee('Reference');
});

it('states on the list what the surface will and will not let anybody do', function () {
    $this->actorForTeam(7);

    aCheckout();

    // Three refusals inferred from three missing buttons is a puzzle. Saying it
    // is one sentence.
    Livewire::test(ListCheckoutSessions::class)
        ->assertOk()
        ->assertSee('Checkouts are read-only here');
});

it('labels the parts of an address rather than running them together', function () {
    // Address shape is the host's, so the labels are derived from whatever keys
    // arrived. A value that is not a scalar is dropped rather than rendered as
    // the word "Array".
    expect(Address::lines([
        'line1' => '14 Bridge Street',
        'postal_code' => 'EH1 1AA',
        'country' => 'GB',
        'blank' => '',
        'nested' => ['no' => 'thanks'],
    ]))->toBe([
        'Line 1: 14 Bridge Street',
        'Postal Code: EH1 1AA',
        'Country: GB',
    ])
        ->and(Address::lines(null))->toBe([]);
});

it('shows the captured addresses on the page that answers for them', function () {
    $this->actorForTeam(7);

    $session = aCheckout();
    $session->forceFill([
        'shipping_address' => ['line1' => '14 Bridge Street', 'city' => 'Edinburgh'],
    ])->save();

    Livewire::test(ViewCheckoutSession::class, ['record' => $session->getRouteKey()])
        ->assertOk()
        ->assertSee('Line 1: 14 Bridge Street')
        ->assertSee('City: Edinburgh')
        // Billing was never captured, and an empty panel is indistinguishable
        // from one that failed to load.
        ->assertSee('None captured');
});

it('shows a customer as a number and never asks for a relation no deployment has configured', function () {
    $this->actorForTeam(7);

    // `checkout.customer_model` is deliberately null in this suite, which is the
    // state of every deployment that has not named one. `CheckoutSession::
    // customer()` throws when asked, so a page that used the relation would be a
    // page that 500s there.
    expect(config('checkout.customer_model'))->toBeNull();

    $session = aCheckout();
    $session->forceFill(['customer_id' => 4242])->save();

    Livewire::test(ViewCheckoutSession::class, ['record' => $session->getRouteKey()])
        ->assertOk()
        ->assertSee('4242');
});
