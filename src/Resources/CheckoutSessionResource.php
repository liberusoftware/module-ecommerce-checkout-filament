<?php

namespace Liberu\Ecommerce\Checkout\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\Checkout\Actions\AbandonCheckout;
use Liberu\Ecommerce\Checkout\Enums\CheckoutStatus;
use Liberu\Ecommerce\Checkout\Exceptions\CheckoutNotOpen;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\Pages\ListCheckoutSessions;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\Pages\ViewCheckoutSession;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\RelationManagers\ConsentsRelationManager;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\RelationManagers\LinesRelationManager;
use Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\RelationManagers\TendersRelationManager;
use Liberu\Ecommerce\Checkout\Filament\Support\Address;
use Liberu\Ecommerce\Checkout\Filament\Support\Amount;
use Liberu\Ecommerce\Checkout\Filament\Support\PanelTeam;
use Liberu\Ecommerce\Checkout\Filament\Support\PlacementRecord;
use Liberu\Ecommerce\Checkout\Filament\Support\Settlement;
use Liberu\Ecommerce\Checkout\Models\CheckoutSession;
use UnitEnum;

/**
 * A financial record in flight, and a surface that treats it as one.
 *
 * ## This resource is read-mostly, and that is the decision
 *
 * A checkout session is the frozen thing an order downstream will cite. Nothing
 * here edits an amount, a line, a tender or a consent, and there is no create
 * page and no delete action. The domain says the same in its own words:
 * `CheckoutSessionPolicy` denies `create` and `delete` outright, and makes
 * `place` an ability of its own rather than a synonym for `update`.
 *
 * Staff get exactly two things. **Read everything** — the lines as they were
 * frozen, what has been tendered against them, what was consented to, and
 * whether a commit was recorded. And **close a session as abandoned**, which is
 * the one operation a person can want on somebody else's stuck checkout that
 * does not rewrite what they agreed to. `docs/domain.md` lists the whole set and
 * the reasoning behind each refusal.
 *
 * ## Every ability is stated, because silence here is permission
 *
 * Laravel's unanswered gate case is permissive. `CheckoutSessionPolicy`
 * publishes `viewAny`, `view`, `create`, `update`, `place`, `abandon` and
 * `delete` — so `deleteAny`, `forceDelete`, `forceDeleteAny`, `restore`,
 * `restoreAny`, `replicate` and `reorder` have no answer at all, and every one
 * of them would default open. They are refused below by name.
 *
 * `canEdit()` is refused too, even though the policy *does* publish `update` and
 * would allow it: this resource has no edit page and offers no edit action, and
 * an ability that answers yes with nowhere to exercise it is a door left ajar
 * for the next person who scaffolds a page.
 *
 * ## Placing is not offered here, deliberately
 *
 * `PlaceCheckout` takes an idempotency key from its caller, and the key is the
 * entire guarantee: a button that minted a fresh one on each press would be a
 * button that charges twice on a double click. A panel has no client-supplied
 * key to pass. Committing money belongs to the storefront and the API, which do.
 */
class CheckoutSessionResource extends Resource
{
    protected static ?string $model = CheckoutSession::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static string|UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?string $navigationLabel = 'Checkouts';

    protected static ?string $modelLabel = 'checkout';

    /**
     * Why a session was closed, as a fixed vocabulary.
     *
     * **A select and not a text box, and that is a privacy decision.** The
     * domain's `DomainEventLogger` writes this value into a `checkout.abandoned`
     * log line. A free-text field is where somebody types *"jane@example.com
     * rang to cancel"*, and a log is the one store in an application with the
     * loosest access control and the longest retention. A closed vocabulary
     * cannot carry a person in it.
     *
     * The stored value is the key — a slug — so a log line reads
     * `reason: customer-request` whatever the panel's language is set to.
     *
     * @var array<string, string>
     */
    public const REASONS = [
        'staff-closed' => 'Closed by staff',
        'duplicate' => 'A duplicate of another checkout',
        'customer-request' => 'The shopper asked for it',
        'payment-failed' => 'Payment could not be completed',
        'fraud-review' => 'Held for fraud review',
    ];

    public static function isScopedToTenant(): bool
    {
        return false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }

    public static function canReorder(): bool
    {
        return false;
    }

    /**
     * The team's sessions, with the relations every column and entry reads.
     *
     * @return Builder<CheckoutSession>
     */
    public static function getEloquentQuery(): Builder
    {
        return PanelTeam::scope(parent::getEloquentQuery()->with('tenders'));
    }

    /**
     * There is no form. Nothing on a checkout session is editable through this
     * package, so the only schema it has is the abandonment action's.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Reference')
                    ->prefix('#')
                    // The only searchable column, and it is a number. A table
                    // search term is persisted into the query string, which is
                    // logged by every proxy and web server between here and the
                    // operator — so the shopper's email is a column and not a
                    // search, and the token is neither. See `docs/domain.md`.
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (CheckoutStatus $state): string => self::statusLabel($state))
                    ->color(fn (CheckoutStatus $state): string => match ($state) {
                        CheckoutStatus::Placed => 'success',
                        CheckoutStatus::Open => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Shopper')
                    ->placeholder('Guest, no email yet'),
                TextColumn::make('lines_count')
                    ->label('Lines')
                    ->counts('lines'),
                TextColumn::make('grand_total_minor')
                    ->label('Total')
                    ->formatStateUsing(fn (int $state, CheckoutSession $record): string => Amount::of($record, $state))
                    ->sortable(),
                // The question `PlaceCheckout` asks, answered in the list so an
                // operator does not have to open five sessions to find the one
                // that is short.
                TextColumn::make('settlement')
                    ->label('Money')
                    ->badge()
                    ->state(fn (CheckoutSession $record): string => Settlement::describe($record))
                    ->color(fn (CheckoutSession $record): string => Settlement::colour($record)),
                TextColumn::make('snapshot')
                    ->label('Snapshot')
                    ->state(fn (CheckoutSession $record): string => self::snapshotLabel($record))
                    ->toggleable(),
                TextColumn::make('store_id')
                    ->label('Store')
                    ->placeholder('None')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(self::statusOptions()),
                Filter::make('stale')
                    ->label('Open and untouched for a day')
                    // The domain's own scope, called by name rather than
                    // rewritten: `scopeStaleSince` is deliberately written with
                    // a bound comparison because `where('updated_at', null)`
                    // compiles to `is null` and would call every never-updated
                    // session abandoned.
                    ->query(fn (Builder $query): Builder => $query->scopes(['staleSince' => now()->subDay()])),
                Filter::make('expired')
                    ->label('Open past its snapshot')
                    // The same three clauses as `CheckoutSessionQuery::expired()`.
                    // `whereNotNull` is meant literally here: a session with no
                    // `expires_at` has no window to be past.
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', CheckoutStatus::Open->value)
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<=', now())),
            ])
            ->recordActions([
                ViewAction::make(),
                self::abandonAction(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * The one write this package offers.
     *
     * Abandonment is the operation that is genuinely wanted and genuinely safe:
     * it closes a session that will not be placed without touching a single
     * figure the shopper saw. `CheckoutSessionPolicy::abandon()` is the ability,
     * and it already refuses a session that is not open and one belonging to
     * another team.
     *
     * Closing as *expired* is not offered. Expiry is the price snapshot's own
     * clock running out, which is a fact rather than a judgement — a person
     * pressing a button to declare it would be backdating the merchant's
     * decision. `CheckoutSessionQuery::expired()` and the host's schedule own
     * that transition.
     */
    public static function abandonAction(): Action
    {
        return Action::make('abandon')
            ->label('Close as abandoned')
            ->icon('heroicon-o-archive-box-x-mark')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Close this checkout as abandoned')
            ->modalDescription('The session and everything on it is kept. Nothing is deleted and nothing is charged or refunded — this only records that the checkout will not be completed.')
            ->modalSubmitActionLabel('Close it')
            ->visible(fn (CheckoutSession $record): bool => Gate::allows('abandon', $record))
            ->schema([
                Select::make('reason')
                    ->label('Why')
                    ->options(self::REASONS)
                    ->required()
                    ->helperText('A fixed list rather than a free-text box: this word is written into the module\'s own log line, and a text box is where a shopper\'s email address ends up in a log.'),
            ])
            ->action(function (CheckoutSession $record, array $data): void {
                try {
                    app(AbandonCheckout::class)->handle($record, reason: (string) $data['reason']);
                } catch (CheckoutNotOpen $exception) {
                    // Somebody else closed or placed it between this page
                    // rendering and the button being pressed. The message names
                    // two statuses and nothing else.
                    Notification::make()
                        ->title('Not closed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Checkout closed')
                    ->body('Recorded as abandoned. The lines, tenders and consents are kept.')
                    ->success()
                    ->send();
            });
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The session')
                ->columns(3)
                ->schema([
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (CheckoutStatus $state): string => self::statusLabel($state)),
                    TextEntry::make('created_at')
                        ->label('Started')
                        ->dateTime(),
                    TextEntry::make('snapshot')
                        ->label('Price snapshot')
                        ->getStateUsing(fn (CheckoutSession $record): string => self::snapshotLabel($record)),
                    TextEntry::make('placed_at')
                        ->label('Placed')
                        ->dateTime()
                        ->placeholder('Not placed'),
                    TextEntry::make('abandoned_at')
                        ->label('Closed')
                        ->dateTime()
                        ->placeholder('Not closed'),
                    TextEntry::make('store_id')
                        ->label('Store')
                        ->placeholder('None'),
                ]),
            Section::make('The shopper')
                ->columns(3)
                ->schema([
                    TextEntry::make('email')
                        ->label('Email')
                        ->placeholder('None captured'),
                    // A number, not a name. Customers belong to the host or to
                    // CRM, and `CheckoutSession::customer()` throws unless the
                    // deployment has named a model — so a surface that used the
                    // relation would crash on every deployment that has not.
                    // See the limitation note in `docs/domain.md`.
                    TextEntry::make('customer_id')
                        ->label('Customer')
                        ->placeholder('Guest'),
                    TextEntry::make('currency')
                        ->label('Currency'),
                    TextEntry::make('shipping_address')
                        ->label('Shipping address')
                        ->getStateUsing(fn (CheckoutSession $record): array => Address::lines($record->shipping_address))
                        ->listWithLineBreaks()
                        ->placeholder('None captured'),
                    TextEntry::make('billing_address')
                        ->label('Billing address')
                        ->getStateUsing(fn (CheckoutSession $record): array => Address::lines($record->billing_address))
                        ->listWithLineBreaks()
                        ->placeholder('None captured'),
                ]),
            Section::make('Totals')
                ->description('Every figure is an integer count of the currency\'s smallest unit, frozen when the checkout began.')
                ->columns(3)
                ->schema([
                    TextEntry::make('subtotal_minor')
                        ->label('Subtotal')
                        ->getStateUsing(fn (CheckoutSession $record): string => Amount::of($record, $record->subtotal_minor)),
                    TextEntry::make('discount_minor')
                        ->label('Discount')
                        ->getStateUsing(fn (CheckoutSession $record): string => Amount::of($record, $record->discount_minor)),
                    TextEntry::make('net_minor')
                        ->label('Net')
                        ->getStateUsing(fn (CheckoutSession $record): string => Amount::of($record, $record->net_minor)),
                    TextEntry::make('tax_minor')
                        ->label('Tax')
                        ->getStateUsing(fn (CheckoutSession $record): string => Amount::of($record, $record->tax_minor)),
                    TextEntry::make('grand_total_minor')
                        ->label('Grand total')
                        ->getStateUsing(fn (CheckoutSession $record): string => Amount::of($record, $record->grand_total_minor)),
                    TextEntry::make('tendered')
                        ->label('Tendered')
                        ->getStateUsing(fn (CheckoutSession $record): string => Amount::of($record, Settlement::tendered($record))),
                    TextEntry::make('settlement')
                        ->label('Against the total')
                        ->badge()
                        ->getStateUsing(fn (CheckoutSession $record): string => Settlement::describe($record)),
                ]),
            Section::make('Placement')
                ->description('Whether a commit was recorded against this session. The key that made it is not shown: it is a replay credential, and it is text a client chose.')
                ->schema([
                    TextEntry::make('placement')
                        ->label('Commit record')
                        ->getStateUsing(fn (CheckoutSession $record): string => PlacementRecord::describe($record)),
                ]),
        ]);
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
            TendersRelationManager::class,
            ConsentsRelationManager::class,
        ];
    }

    /**
     * Index and view. There is no create page because staff do not open a
     * checkout on a shopper's behalf, and no edit page because nothing on a
     * frozen session is theirs to change.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCheckoutSessions::route('/'),
            'view' => ViewCheckoutSession::route('/{record}'),
        ];
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        $options = [];

        foreach (CheckoutStatus::cases() as $status) {
            $options[$status->value] = self::statusLabel($status);
        }

        return $options;
    }

    /**
     * Words rather than the stored slug, and the two closing states stay apart:
     * the domain keeps `abandoned` and `expired` distinct because only one of
     * them is worth an email, and a label that blurred them here would be the
     * place somebody started blurring them everywhere.
     */
    public static function statusLabel(CheckoutStatus $status): string
    {
        return match ($status) {
            CheckoutStatus::Open => 'Open',
            CheckoutStatus::Placed => 'Placed',
            CheckoutStatus::Abandoned => 'Abandoned',
            CheckoutStatus::Expired => 'Expired',
        };
    }

    /**
     * The clock's opinion of the snapshot, which is not the same as the status.
     *
     * `hasExpired()` asks the clock; the status is what a sweep wrote down after
     * acting on it. A session can be past its window and still say `open` until
     * something notices, and `PlaceCheckout` will refuse it either way — so an
     * operator wondering why a settled checkout will not place needs this column
     * and not that one.
     */
    private static function snapshotLabel(CheckoutSession $session): string
    {
        if ($session->expires_at === null) {
            return 'No expiry set';
        }

        return $session->hasExpired()
            ? 'Expired '.$session->expires_at->diffForHumans()
            : 'Honoured until '.$session->expires_at->toDayDateTimeString();
    }
}
