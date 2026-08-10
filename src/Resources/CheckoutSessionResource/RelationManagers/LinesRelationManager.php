<?php

namespace Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\RelationManagers;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Liberu\Ecommerce\Checkout\Enums\LineKind;
use Liberu\Ecommerce\Checkout\Filament\Support\Amount;
use Liberu\Ecommerce\Checkout\Models\CheckoutLine;

/**
 * The lines as they were frozen, and the arithmetic that produced the total.
 *
 * Every column is a copy taken when the checkout began — `name`, `sku` and
 * `unit_price_minor` all belong to this row and not to a product. That is why a
 * line still reads correctly after the product it names has been renamed,
 * re-priced or deleted, and why there is no link to a catalogue this package
 * does not depend on.
 *
 * The columns are laid out in the order the domain computes them — subtotal,
 * discount, net, tax, gross — because the question an operator brings here is
 * *where did that number come from*, and reading it left to right is the answer.
 *
 * `product_id` and `variant_id` are numbers with no relation and no foreign key,
 * so they are shown as numbers. Catalog is not a dependency of this package.
 */
class LinesRelationManager extends EvidenceRelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Lines';

    public function table(Table $table): Table
    {
        $session = $this->session();

        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Item')
                    ->wrap(),
                TextColumn::make('kind')
                    ->label('Kind')
                    ->badge()
                    ->formatStateUsing(fn (LineKind $state): string => match ($state) {
                        LineKind::Item => 'Item',
                        LineKind::Shipping => 'Shipping',
                        LineKind::Fee => 'Fee',
                    })
                    ->color('gray'),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->placeholder('None'),
                TextColumn::make('quantity')
                    ->label('Qty')
                    ->numeric(),
                TextColumn::make('unit_price_minor')
                    ->label('Unit price')
                    ->formatStateUsing(fn (int $state): string => Amount::of($session, $state)),
                TextColumn::make('subtotal_minor')
                    ->label('Subtotal')
                    ->formatStateUsing(fn (int $state): string => Amount::of($session, $state)),
                TextColumn::make('discount_minor')
                    ->label('Discount')
                    ->formatStateUsing(fn (int $state): string => Amount::of($session, $state)),
                TextColumn::make('net_minor')
                    ->label('Net')
                    ->formatStateUsing(fn (int $state): string => Amount::of($session, $state)),
                // Basis points, said as a percentage, because that is what an
                // operator is checking it against. Integer arithmetic on the
                // way there: 2000 bp is "20%" and 838 bp is "8.38%", neither
                // reached through a float.
                TextColumn::make('tax_rate_bp')
                    ->label('Rate')
                    // `state()` rather than `formatStateUsing()`: a null rate is
                    // the interesting case — it means the host handed in an
                    // already-computed tax amount — and a formatter is not
                    // reliably called for a state that is null.
                    ->state(fn (CheckoutLine $record): string => self::rateLabel($record->tax_rate_bp)),
                TextColumn::make('tax_minor')
                    ->label('Tax')
                    ->formatStateUsing(fn (int $state): string => Amount::of($session, $state)),
                TextColumn::make('gross_minor')
                    ->label('Gross')
                    ->formatStateUsing(fn (int $state): string => Amount::of($session, $state)),
                TextColumn::make('taxable')
                    ->label('Taxed')
                    // A word rather than a tick: a tick and a cross are the same
                    // glyph to a screen reader that announces neither.
                    ->state(fn (CheckoutLine $record): string => $record->taxable ? 'Taxable' : 'Not taxable')
                    ->toggleable(),
                TextColumn::make('product_id')
                    ->label('Product')
                    ->placeholder('None')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('variant_id')
                    ->label('Variant')
                    ->placeholder('None')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('position');
    }

    private static function rateLabel(?int $basisPoints): string
    {
        if ($basisPoints === null) {
            return 'Amount supplied';
        }

        $whole = intdiv($basisPoints, 100);
        $hundredths = $basisPoints % 100;

        return $hundredths === 0
            ? $whole.'%'
            : $whole.'.'.str_pad((string) $hundredths, 2, '0', STR_PAD_LEFT).'%';
    }
}
