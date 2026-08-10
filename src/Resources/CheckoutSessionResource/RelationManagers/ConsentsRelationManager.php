<?php

namespace Liberu\Ecommerce\Checkout\Filament\Resources\CheckoutSessionResource\RelationManagers;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Liberu\Ecommerce\Checkout\Models\CheckoutConsent;

/**
 * What was agreed, which version of the text, when, and from where.
 *
 * ## This is evidence, so it is shown in full and never anywhere else
 *
 * A consent row exists to be produced: to a regulator, to a shopper who asks
 * what they signed up to, to a dispute. Hiding `ip_address` and `user_agent`
 * from an operator who is already authorized to view the session would defeat
 * the point of storing them. So they are here — behind
 * `CheckoutSessionPolicy::view()`, collapsed by default because they are read
 * on the day they are needed and not before.
 *
 * What they are never allowed into is anywhere with weaker access control than
 * this page. Neither column is searchable and neither has a filter, because a
 * search term and a filter's state both land in the query string and from there
 * into every access log on the path. The domain's `DomainEventLogger` copies
 * neither into a log line either — it records the consent's type and version and
 * stops — and nothing in this package hands either to a notification.
 *
 * ## A refusal is a row, and the row says so in a word
 *
 * `agreed` is stored rather than implied by the row existing, because a missing
 * row cannot distinguish *"declined marketing"* from *"the form never
 * rendered"*. So the column says **Agreed** or **Refused** rather than showing a
 * tick and a blank, which are the same thing to a screen reader and to anybody
 * scanning quickly.
 */
class ConsentsRelationManager extends EvidenceRelationManager
{
    protected static string $relationship = 'consents';

    protected static ?string $title = 'Consent';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('type')
            ->columns([
                TextColumn::make('type')
                    ->label('Type')
                    // A free string, because the vocabulary is the host's:
                    // terms, privacy, marketing, a jurisdiction's
                    // distance-selling acknowledgement. There is no enum here to
                    // translate against.
                    ->badge()
                    ->color('gray'),
                TextColumn::make('agreed')
                    ->label('Answer')
                    ->badge()
                    ->state(fn (CheckoutConsent $record): string => $record->agreed ? 'Agreed' : 'Refused')
                    ->color(fn (CheckoutConsent $record): string => $record->agreed ? 'success' : 'danger'),
                TextColumn::make('document_version')
                    ->label('Version'),
                TextColumn::make('agreed_at')
                    ->label('Answered')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('document_url')
                    ->label('Document')
                    ->placeholder('None recorded')
                    ->url(fn (CheckoutConsent $record): ?string => $record->document_url)
                    ->openUrlInNewTab()
                    ->toggleable(),
                TextColumn::make('ip_address')
                    ->label('From address')
                    ->placeholder('Not captured')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user_agent')
                    ->label('From browser')
                    ->placeholder('Not captured')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('agreed_at');
    }
}
