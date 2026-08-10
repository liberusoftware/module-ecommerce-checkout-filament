<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\Pages\ListCheckoutSessions;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\Pages\ViewCheckoutSession;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\RelationManagers\ConsentsRelationManager;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\RelationManagers\LinesRelationManager;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\RelationManagers\TendersRelationManager;
use Liberu\Ecommerce\Checkout\Filament\Support\PanelTeam;
use Liberu\Ecommerce\Checkout\Models\CheckoutSession;
use Liberu\Ecommerce\Checkout\Models\IdempotencyKey;
use Liberu\PackageTestbench\TestUser;
use Livewire\Livewire;

it('answers no team rather than every team when nobody is signed in', function () {
    expect(PanelTeam::id())->toBeNull()
        ->and(CheckoutSessionResource::getEloquentQuery()->count())->toBe(0);
});

it('never lists the orphan sessions a null team id would match', function () {
    $this->actingAs(TestUser::factory()->create());

    aCheckout(teamId: null);
    aCheckout(teamId: null);

    // `where('team_id', null)` compiles to `is null`, so an actor with no team
    // would be handed precisely the sessions the policy denies everybody. The
    // guard is an explicit `whereRaw('1 = 0')`.
    expect(CheckoutSessionResource::getEloquentQuery()->count())->toBe(0)
        ->and(CheckoutSession::query()->count())->toBe(2);
});

it('refuses an unowned session to the team that can see it in no list either', function () {
    $this->actorForTeam(7);

    $orphan = aCheckout(teamId: null);
    $theirs = aCheckout(teamId: 9);

    expect(CheckoutSessionResource::getEloquentQuery()->count())->toBe(0);

    Livewire::test(ListCheckoutSessions::class)
        ->assertCanNotSeeTableRecords([$orphan, $theirs]);
});

/**
 * `CheckoutSessionPolicy` publishes `viewAny`, `view`, `create`, `update`,
 * `place`, `abandon` and `delete`. Everything else has no answer at all, and
 * Laravel's unanswered gate case is permissive — so every one of these would
 * default open on a model holding an email address, two postal addresses and
 * payment references.
 */
it('refuses by name every ability the policy does not publish', function () {
    $this->actorForTeam(7);

    $session = aCheckout();

    expect(CheckoutSessionResource::canDeleteAny())->toBeFalse()
        ->and(CheckoutSessionResource::canForceDelete($session))->toBeFalse()
        ->and(CheckoutSessionResource::canForceDeleteAny())->toBeFalse()
        ->and(CheckoutSessionResource::canRestore($session))->toBeFalse()
        ->and(CheckoutSessionResource::canRestoreAny())->toBeFalse()
        ->and(CheckoutSessionResource::canReplicate($session))->toBeFalse()
        ->and(CheckoutSessionResource::canReorder())->toBeFalse();
});

it('refuses creating, editing and deleting a session even to the team that owns it', function () {
    $this->actorForTeam(7);

    $session = aCheckout();

    // `create` and `delete` are the domain's own refusals, restated. `edit` is
    // this package's: the policy would allow `update` on an open session, and
    // there is deliberately nowhere here to exercise it.
    expect(CheckoutSessionResource::canCreate())->toBeFalse()
        ->and(CheckoutSessionResource::canEdit($session))->toBeFalse()
        ->and(CheckoutSessionResource::canDelete($session))->toBeFalse()
        ->and(Gate::allows('create', CheckoutSession::class))->toBeFalse()
        ->and(Gate::allows('delete', $session))->toBeFalse();
});

/**
 * The lines, the tenders and the consents have no policy at all — the domain
 * registers one, for the session. Every `can…` below would answer `true` by
 * default, which is the leak this fleet has shipped twice.
 */
it('forces every write off every relation manager', function () {
    $this->actorForTeam(7);

    $session = aCheckout();

    $managers = [LinesRelationManager::class, TendersRelationManager::class, ConsentsRelationManager::class];

    foreach ($managers as $manager) {
        $component = Livewire::test($manager, [
            'ownerRecord' => $session,
            'pageClass' => ViewCheckoutSession::class,
        ])->instance();

        $can = fn (string $method, mixed ...$arguments): bool => (bool) (new ReflectionMethod($component, $method))
            ->invoke($component, ...$arguments);

        expect($can('canViewAny'))->toBeTrue($manager)
            ->and($can('canCreate'))->toBeFalse($manager)
            ->and($can('canEdit', $session))->toBeFalse($manager)
            ->and($can('canDelete', $session))->toBeFalse($manager)
            ->and($can('canDeleteAny'))->toBeFalse($manager)
            ->and($can('canForceDelete', $session))->toBeFalse($manager)
            ->and($can('canForceDeleteAny'))->toBeFalse($manager)
            ->and($can('canRestore', $session))->toBeFalse($manager)
            ->and($can('canRestoreAny'))->toBeFalse($manager)
            ->and($can('canReplicate', $session))->toBeFalse($manager)
            ->and($can('canReorder'))->toBeFalse($manager)
            ->and($can('canAssociate'))->toBeFalse($manager)
            ->and($can('canDissociate', $session))->toBeFalse($manager)
            ->and($can('canDissociateAny'))->toBeFalse($manager)
            ->and($can('canAttach'))->toBeFalse($manager)
            ->and($can('canDetach', $session))->toBeFalse($manager)
            ->and($can('canDetachAny'))->toBeFalse($manager);
    }
});

it('keeps every relation manager away from another team\'s session', function () {
    $this->actorForTeam(7);

    $theirs = aCheckout(teamId: 9);

    foreach ([LinesRelationManager::class, TendersRelationManager::class, ConsentsRelationManager::class] as $manager) {
        expect($manager::canViewForRecord($theirs, ViewCheckoutSession::class))->toBeFalse($manager);
    }
});

it('keeps every relation manager away from a session belonging to nobody', function () {
    $this->actorForTeam(7);

    $orphan = aCheckout(teamId: null);

    foreach ([LinesRelationManager::class, TendersRelationManager::class, ConsentsRelationManager::class] as $manager) {
        expect($manager::canViewForRecord($orphan, ViewCheckoutSession::class))->toBeFalse($manager);
    }
});

/**
 * `ecommerce_checkout_idempotency_keys` carries no `team_id` and no relation
 * back to a session other than a plain `subject_id`. There is therefore no way
 * to scope a list of it, and this package ships none: no resource, no relation
 * manager, no table. The commit is reported on the session's own page as a
 * sentence, from a row found by that session's id.
 */
it('exposes the idempotency keys through no surface at all', function () {
    $panel = Filament\Facades\Filament::getPanel('admin');

    foreach ($panel->getResources() as $resource) {
        expect($resource::getModel())->not->toBe(IdempotencyKey::class);
    }

    $relationships = array_map(
        fn (string $manager): string => (string) (new ReflectionProperty($manager, 'relationship'))->getValue(),
        CheckoutSessionResource::getRelations(),
    );

    expect($relationships)->toBe(['lines', 'tenders', 'consents']);
});

it('offers the abandonment action on nothing the actor does not own', function () {
    $this->actorForTeam(7);

    $mine = aCheckout();
    $theirs = aCheckout(teamId: 9);

    Livewire::test(ListCheckoutSessions::class)
        ->assertActionVisible(TestAction::make('abandon')->table($mine))
        // Not merely hidden — the row is not in the list at all, which is the
        // stronger statement and the one the scope makes.
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('will not abandon a session that has already been placed', function () {
    $this->actorForTeam(7);

    $placed = aCheckout();
    $placed->forceFill(['status' => 'placed', 'placed_at' => now()])->save();

    // `CheckoutSessionPolicy::abandon()` is `update`, and `update` refuses a
    // finished session: it is a record an order downstream already cites.
    Livewire::test(ListCheckoutSessions::class)
        ->assertActionHidden(TestAction::make('abandon')->table($placed));
});
