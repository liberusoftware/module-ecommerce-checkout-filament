<?php

namespace Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\RelationManagers;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Liberu\Ecommerce\Checkout\Enums\TenderKind;
use Liberu\Ecommerce\Checkout\Enums\TenderStatus;
use Liberu\Ecommerce\Checkout\Filament\Support\Amount;
use Liberu\Ecommerce\Checkout\Models\CheckoutTender;

/**
 * What providers reported, and which of it counts.
 *
 * This module never moves a tender through its statuses — it has no provider to
 * ask. A payment adapter reports and `RecordTender` writes down what it said, so
 * every row here is somebody else's statement of fact. That is the whole reason
 * there is no edit action: changing a status here would be changing what a
 * provider said, and the provider's own record would still say the old thing.
 *
 * The `Counts` column is the one that matters. Only `authorized` and `captured`
 * are money as far as `PlaceCheckout` is concerned; a `pending` authorisation is
 * a request, and a session whose tenders look like they add up but whose only
 * tender is pending is exactly the checkout somebody is here to investigate.
 *
 * ## `reference` is shown and is never searchable
 *
 * A provider reference is the handle on somebody's payment, and reconciliation
 * genuinely needs it — so it is a column on a page the session policy already
 * guards. It is not searchable and not filterable, because a table search term
 * and a filter's state are both persisted into the query string, and a query
 * string is written into every access log between the operator and this server.
 * The same rule keeps it out of notifications and out of the domain's telemetry.
 */
class TendersRelationManager extends EvidenceRelationManager
{
    protected static string $relationship = 'tenders';

    protected static ?string $title = 'Tenders';

    public function table(Table $table): Table
    {
        $session = $this->session();

        return $table
            ->recordTitleAttribute('reference')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Recorded')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('kind')
                    ->label('Kind')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (TenderKind $state): string => match ($state) {
                        TenderKind::Payment => 'Payment',
                        TenderKind::GiftCard => 'Gift card',
                        TenderKind::StoreCredit => 'Store credit',
                    }),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (TenderStatus $state): string => self::statusLabel($state))
                    ->color(fn (TenderStatus $state): string => match ($state) {
                        TenderStatus::Captured, TenderStatus::Authorized => 'success',
                        TenderStatus::Failed => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('counts')
                    ->label('Counts towards the total')
                    ->state(fn (CheckoutTender $record): string => $record->status->counts()
                        ? 'Counted'
                        : 'Not counted'),
                TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state, CheckoutTender $record): string => Amount::format(
                        $state,
                        $record->currency,
                        // The session's exponent, because a tender is refused
                        // unless its currency matches the session's, so they
                        // cannot disagree about how many minor units a unit has.
                        $session->currency_exponent,
                    )),
                TextColumn::make('provider')
                    ->label('Provider')
                    ->placeholder('None named'),
                TextColumn::make('reference')
                    ->label('Reference')
                    ->placeholder('None')
                    ->copyable(),
            ])
            ->defaultSort('id');
    }

    public static function statusLabel(TenderStatus $status): string
    {
        return match ($status) {
            TenderStatus::Pending => 'Pending',
            TenderStatus::Authorized => 'Authorized',
            TenderStatus::Captured => 'Captured',
            TenderStatus::Failed => 'Failed',
            TenderStatus::Voided => 'Voided',
        };
    }
}
