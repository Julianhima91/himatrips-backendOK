<?php

namespace App\Filament\Resources\HotelResource\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'reviews';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('reviewer_name')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reviewer_name')
            ->columns([
                TextColumn::make('reviewer_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('reviewer_country')
                    ->label('Country')
                    ->formatStateUsing(fn ($state) => strtoupper($state ?? '')),
                TextColumn::make('average_score')
                    ->label('Score')
                    ->badge()
                    ->color(fn ($state) => match (true) {
                        $state >= 9 => 'success',
                        $state >= 7 => 'info',
                        $state >= 5 => 'warning',
                        default => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('customer_type')
                    ->badge()
                    ->formatStateUsing(fn ($state) => str_replace('_', ' ', $state)),
                TextColumn::make('purpose_type')
                    ->badge(),
                TextColumn::make('review_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('positive_text')
                    ->limit(50)
                    ->tooltip(fn ($state) => $state),
                TextColumn::make('negative_text')
                    ->limit(50)
                    ->tooltip(fn ($state) => $state),
            ])
            ->filters([
                Filter::make('high_score')
                    ->query(fn (Builder $query) => $query->where('average_score', '>=', 8)),
                SelectFilter::make('customer_type')
                    ->options([
                        'YOUNG_COUPLE' => 'Young Couple',
                        'FAMILY_WITH_YOUNG_CHILDREN' => 'Family with Young Children',
                        'FAMILY_WITH_OLDER_CHILDREN' => 'Family with Older Children',
                        'SOLO_TRAVELLER' => 'Solo Traveller',
                        'BUSINESS' => 'Business',
                        'GROUP' => 'Group',
                        'MATURE_COUPLE' => 'Mature Couple',
                        'OTHER' => 'Other',
                    ]),
            ])
            ->headerActions([
                //
            ])
            ->actions([
                ViewAction::make(),
            ])
            ->bulkActions([
                //
            ])
            ->defaultSort('review_date', 'desc');
    }
}
