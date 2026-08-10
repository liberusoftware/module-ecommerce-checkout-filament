<?php

namespace Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\Checkout\Models\CheckoutSession;

/**
 * A relation manager that can be read and nothing else.
 *
 * ## Why every one of these is spelled out
 *
 * `CheckoutServiceProvider` registers exactly one policy, for
 * `CheckoutSession`. `CheckoutLine`, `CheckoutTender` and `CheckoutConsent`
 * have none — and **a model with no policy is exposed, not safe**, because
 * Laravel's unanswered gate case is permissive. Every `can…` a relation manager
 * asks would come back `true` by default. That is not a hypothetical: it has put
 * a live leak into this fleet twice.
 *
 * So each of them is answered here by name rather than left to be inferred from
 * a table that happens to carry no create action today. The next person to add
 * an action to one of these tables should find the door already shut.
 *
 * ## Why they are read-only rather than merely restricted
 *
 * These three rows are what a shopper agreed to. A line's money is the price
 * they saw; a tender is what a provider reported; a consent is evidence with a
 * timestamp on it. Editing any of them from a panel does not correct a record,
 * it falsifies one — the shopper's copy, the provider's copy and the consent's
 * own `agreed_at` all still say the old thing. Where a figure is genuinely
 * wrong, the answer is downstream of checkout: a new order, a refund, a fresh
 * consent at the current document version.
 *
 * ## The one method deliberately not defined
 *
 * `fill()`. Narrowing a parent's public method to `private` on a relation
 * manager is a fatal at class load rather than a test failure, and `fill()` is
 * the one that has bitten. Everything here matches the parent's `protected`
 * visibility and its parameter types exactly.
 *
 * Authorization for reading is the owner's: `view` on the session. Tenancy is
 * therefore the policy's single answer rather than a second one written here.
 */
abstract class EvidenceRelationManager extends RelationManager
{
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord);
    }

    protected function canViewAny(): bool
    {
        return Gate::allows('view', $this->getOwnerRecord());
    }

    protected function canView(Model $record): bool
    {
        return Gate::allows('view', $this->getOwnerRecord());
    }

    protected function canCreate(): bool
    {
        return false;
    }

    protected function canEdit(Model $record): bool
    {
        return false;
    }

    protected function canDelete(Model $record): bool
    {
        return false;
    }

    protected function canDeleteAny(): bool
    {
        return false;
    }

    protected function canForceDelete(Model $record): bool
    {
        return false;
    }

    protected function canForceDeleteAny(): bool
    {
        return false;
    }

    protected function canRestore(Model $record): bool
    {
        return false;
    }

    protected function canRestoreAny(): bool
    {
        return false;
    }

    protected function canReplicate(Model $record): bool
    {
        return false;
    }

    protected function canReorder(): bool
    {
        return false;
    }

    /*
     * A `hasMany` relation manager offers associate and dissociate rather than
     * attach and detach, but all four are refused: this package must never move
     * a line, a tender or a consent between sessions, which is the one thing
     * that would let somebody's payment evidence end up filed against somebody
     * else's order.
     */

    protected function canAssociate(): bool
    {
        return false;
    }

    protected function canDissociate(Model $record): bool
    {
        return false;
    }

    protected function canDissociateAny(): bool
    {
        return false;
    }

    protected function canAttach(): bool
    {
        return false;
    }

    protected function canDetach(Model $record): bool
    {
        return false;
    }

    protected function canDetachAny(): bool
    {
        return false;
    }

    /** The session these rows hang off, typed, for the currency they are in. */
    protected function session(): CheckoutSession
    {
        /** @var CheckoutSession $session */
        $session = $this->getOwnerRecord();

        return $session;
    }
}
