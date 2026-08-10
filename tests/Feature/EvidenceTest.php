<?php

use Filament\Notifications\Notification;
use Liberu\Ecommerce\Checkout\Actions\PlaceCheckout;
use Liberu\Ecommerce\Checkout\Actions\RecordConsent;
use Liberu\Ecommerce\Checkout\Actions\RecordTender;
use Liberu\Ecommerce\Checkout\Enums\TenderStatus;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\Pages\ListCheckoutSessions;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\Pages\ViewCheckoutSession;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\RelationManagers\ConsentsRelationManager;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\RelationManagers\TendersRelationManager;
use Liberu\Ecommerce\Checkout\Filament\Support\PlacementRecord;
use Liberu\Ecommerce\Checkout\Models\IdempotencyKey;
use Livewire\Livewire;

// Consents carry an IP address and a user agent; tenders carry a provider
// reference; an idempotency key is a replay credential whose text a client
// chose. All of it is readable on a page `CheckoutSessionPolicy` guards, and
// none of it may reach anywhere with weaker access control than that page — a
// log line, a notification body, or a query string.

it('writes no consent evidence and no tender reference into a log line', function () {
    $this->actorForTeam(7);

    $records = captureLog();

    $session = aCheckout();

    app(RecordConsent::class)->handle(
        $session,
        type: 'terms',
        documentVersion: '2026-08-01',
        ipAddress: '203.0.113.7',
        userAgent: 'Mozilla/5.0 (a very identifying string)',
    );

    app(RecordTender::class)->handle(
        $session->fresh(),
        amountMinor: 2399,
        status: TenderStatus::Authorized,
        provider: 'acme-payments',
        reference: 'auth_abc123secret',
    );

    $written = json_encode($records(), JSON_THROW_ON_ERROR);

    expect($written)->not->toContain('203.0.113.7')
        ->and($written)->not->toContain('a very identifying string')
        ->and($written)->not->toContain('auth_abc123secret')
        ->and($written)->not->toContain('shopper@example.com')
        // The token is the shopper's only authorisation to their own checkout,
        // and it never reaches a gate. It must not reach a log either.
        ->and($written)->not->toContain($session->token)
        // Proof the capture works at all, so the five refusals above are not
        // passing against an empty array.
        ->and($written)->toContain('checkout.tender_recorded');
});

it('closes a session with a word from a fixed list, so nothing typed reaches a log', function () {
    $this->actorForTeam(7);

    $session = aCheckout();
    $records = captureLog();

    Livewire::test(ViewCheckoutSession::class, ['record' => $session->getRouteKey()])
        ->callAction('abandon', ['reason' => 'customer-request'])
        ->assertHasNoActionErrors();

    $written = json_encode($records(), JSON_THROW_ON_ERROR);

    expect($session->refresh()->status->value)->toBe('abandoned')
        // The domain's own logger copies this value into `checkout.abandoned`.
        // What lands there is a slug.
        ->and($written)->toContain('customer-request')
        ->and($written)->not->toContain('shopper@example.com');
});

it('keeps the abandonment vocabulary closed and free of anything a person types', function () {
    // A select over these and not a text box. A free-text reason is the one
    // place in this package where an operator's typing would reach the domain's
    // log line, and *"jane@example.com rang to cancel"* is what people type.
    expect(array_keys(CheckoutSessionResource::REASONS))->toBe([
        'staff-closed', 'duplicate', 'customer-request', 'payment-failed', 'fraud-review',
    ]);

    foreach (array_keys(CheckoutSessionResource::REASONS) as $reason) {
        expect($reason)->toMatch('/^[a-z-]+$/');
    }
});

it('says nothing about the shopper in the notification it sends', function () {
    $this->actorForTeam(7);

    $session = aCheckout();

    Livewire::test(ViewCheckoutSession::class, ['record' => $session->getRouteKey()])
        ->callAction('abandon', ['reason' => 'fraud-review']);

    // The whole body, pinned. A notification is rendered outside the page the
    // policy guards and is the easiest place for an email address to be
    // interpolated "for context".
    Notification::assertNotified(
        Notification::make()
            ->title('Checkout closed')
            ->body('Recorded as abandoned. The lines, tenders and consents are kept.')
            ->success(),
    );
});

it('puts no evidence into the query string by making a column searchable or filterable', function () {
    $this->actorForTeam(7);

    $session = aCheckout();

    app(RecordTender::class)->handle($session, amountMinor: 2399, reference: 'auth_abc123secret');
    app(RecordConsent::class)->handle($session, type: 'terms', documentVersion: '1', ipAddress: '203.0.113.7');

    // A table's search term and its filter state are both persisted into the
    // URL, which every proxy and access log on the path writes down. So the
    // only searchable column in this package is a numeric id, and the evidence
    // tables have neither a search nor a filter at all.
    $searchable = fn (array $columns): array => array_values(array_map(
        fn ($column): string => $column->getName(),
        array_filter($columns, fn ($column): bool => $column->isSearchable()),
    ));

    $list = Livewire::test(ListCheckoutSessions::class)->instance();

    expect($searchable($list->getTable()->getColumns()))->toBe(['id'])
        ->and(array_keys($list->getTable()->getFilters()))->toBe(['status', 'stale', 'expired']);

    foreach ([TendersRelationManager::class, ConsentsRelationManager::class] as $manager) {
        $component = Livewire::test($manager, [
            'ownerRecord' => $session,
            'pageClass' => ViewCheckoutSession::class,
        ])->instance();

        expect($searchable($component->getTable()->getColumns()))->toBe([], $manager)
            ->and($component->getTable()->getFilters())->toBe([], $manager);
    }
});

it('shows the consent evidence to somebody already authorized to see the session', function () {
    $this->actorForTeam(7);

    $session = aCheckout();

    app(RecordConsent::class)->handle(
        $session,
        type: 'terms',
        documentVersion: '2026-08-01',
        ipAddress: '203.0.113.7',
        userAgent: 'Mozilla/5.0',
    );

    // Hiding it here would defeat the point of storing it: a consent row exists
    // to be produced, to a regulator or to a shopper who asks. The rule is about
    // where it may travel, not about who may read it on the guarded page.
    Livewire::test(ConsentsRelationManager::class, [
        'ownerRecord' => $session,
        'pageClass' => ViewCheckoutSession::class,
    ])
        ->assertOk()
        ->assertTableColumnStateSet('ip_address', '203.0.113.7', $session->consents()->firstOrFail());
});

it('reports that a commit happened without ever rendering the key that made it', function () {
    $this->actorForTeam(7);

    $session = aCheckout();
    app(RecordTender::class)->handle($session, amountMinor: 2399, status: TenderStatus::Authorized);

    app(PlaceCheckout::class)->handle($session->fresh(), 'idem-key-holding-shopper@example.com');

    expect(PlacementRecord::for($session->refresh()))->toBeInstanceOf(IdempotencyKey::class)
        ->and(PlacementRecord::describe($session))->toStartWith('Committed ');

    Livewire::test(ViewCheckoutSession::class, ['record' => $session->getRouteKey()])
        ->assertOk()
        ->assertSee('Committed')
        // The key is a replay credential, and it is text a client chose — so it
        // may hold anything that client thought made a good unique value.
        ->assertDontSee('idem-key-holding-shopper@example.com');
});

it('says so plainly when no commit was recorded', function () {
    $this->actorForTeam(7);

    $session = aCheckout();

    expect(PlacementRecord::for($session))->toBeNull()
        ->and(PlacementRecord::describe($session))->toBe('No commit recorded');
});

it('never shows the shopper\'s token on a staff surface', function () {
    $this->actorForTeam(7);

    $session = aCheckout();

    // The token is the guest's whole authorisation to their own checkout — it
    // never reaches a gate, which is exactly why it must not appear in a
    // screenshot, a support ticket or a URL.
    Livewire::test(ListCheckoutSessions::class)
        ->assertOk()
        ->assertDontSee($session->token);

    Livewire::test(ViewCheckoutSession::class, ['record' => $session->getRouteKey()])
        ->assertOk()
        ->assertDontSee($session->token);
});
