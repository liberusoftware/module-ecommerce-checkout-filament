<?php

use Filament\Facades\Filament;
use Liberu\Ecommerce\Checkout\Filament\CheckoutPlugin;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\Pages\ListCheckoutSessions;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\Pages\ViewCheckoutSession;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\RelationManagers\ConsentsRelationManager;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\RelationManagers\LinesRelationManager;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\RelationManagers\TendersRelationManager;
use Livewire\Livewire;

it('contributes its resource to the panel that attaches it', function () {
    $panel = Filament::getPanel('admin');

    expect($panel->getPlugin(CheckoutPlugin::make()->getId()))->toBeInstanceOf(CheckoutPlugin::class)
        ->and($panel->getResources())->toContain(CheckoutSessionResource::class);
});

it('declares in its manifest exactly the plugin it ships', function () {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/module.json'), true, flags: JSON_THROW_ON_ERROR);

    $plugins = $manifest['presentation']['filament']['admin'];

    expect($plugins)->toBe([CheckoutPlugin::class]);

    foreach ($plugins as $plugin) {
        expect(class_exists($plugin))->toBeTrue();
    }
});

/**
 * The plugin lists what it contributes rather than scanning `src/` for it, which
 * is what makes `filament:cache-components` a no-op worth running and a boot
 * with no filesystem walk. The cost of a list is that a class added later is
 * simply absent from the panel — no error, just a resource nobody can reach.
 * This is the test that turns that silence into a failure.
 */
it('registers exactly the resources the package ships', function () {
    $panel = Filament::getPanel('admin');

    $shipped = array_map(
        fn (string $path): string => 'Liberu\\Ecommerce\\Checkout\\Filament\\Resources\\'.basename($path, '.php'),
        (array) glob(dirname(__DIR__, 2).'/src/Resources/*.php'),
    );

    $registered = array_values(array_filter(
        $panel->getResources(),
        fn (string $class): bool => str_starts_with($class, 'Liberu\\Ecommerce\\Checkout\\Filament\\'),
    ));

    sort($shipped);
    sort($registered);

    expect($registered)->toBe($shipped);
});

it('offers a list and a view and no other page', function () {
    // A create page or an edit page appearing here would be the first sign that
    // somebody scaffolded their way past every refusal in the resource.
    expect(array_keys(CheckoutSessionResource::getPages()))->toBe(['index', 'view']);
});

it('hangs the lines, the tenders and the consent off the session and nothing else', function () {
    expect(CheckoutSessionResource::getRelations())->toBe([
        LinesRelationManager::class,
        TendersRelationManager::class,
        ConsentsRelationManager::class,
    ]);
});

it('renders the list and the view page for a session in the actor\'s team', function () {
    $this->actorForTeam(7);

    $session = aCheckout();

    Livewire::test(ListCheckoutSessions::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$session]);

    // Bound by the key the model actually declares. `CheckoutSession` does not
    // override `getRouteKeyName()` — the token is the shopper's handle and is
    // deliberately not a route key on a staff surface — so this is the id, and
    // asking the model for it is what keeps the test honest if that ever moves.
    Livewire::test(ViewCheckoutSession::class, ['record' => $session->getRouteKey()])
        ->assertOk();
});

it('renders every relation manager for a session in the actor\'s team', function () {
    $this->actorForTeam(7);

    $session = aCheckout();

    foreach ([LinesRelationManager::class, TendersRelationManager::class, ConsentsRelationManager::class] as $manager) {
        Livewire::test($manager, [
            'ownerRecord' => $session,
            'pageClass' => ViewCheckoutSession::class,
        ])->assertOk();
    }
});
