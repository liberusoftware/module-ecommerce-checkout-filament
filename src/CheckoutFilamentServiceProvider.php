<?php

namespace Liberu\Ecommerce\Checkout\Filament;

use Illuminate\Support\ServiceProvider;

/**
 * Named by `module.json` and registered by `ModuleManagerServiceProvider`, never
 * by Composer discovery — the package ships no `extra.laravel.providers`.
 *
 * It has nothing to do. The panels belong to the application, and this package
 * contributes to them through {@see CheckoutPlugin}, which the application
 * attaches. A provider that reached into a panel here would register this
 * module's resources into panels that never asked for them.
 *
 * It registers no policy either, and that is deliberate rather than an omission:
 * `CheckoutServiceProvider` in the domain package binds
 * `CheckoutSessionPolicy`, and a presentation package that bound a second
 * opinion about who may act on a session would be a second answer waiting to
 * disagree with the API and the storefront.
 */
final class CheckoutFilamentServiceProvider extends ServiceProvider {}
