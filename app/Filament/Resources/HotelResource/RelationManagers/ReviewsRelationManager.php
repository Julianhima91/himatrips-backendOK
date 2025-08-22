<?php

namespace App\Filament\Resources\HotelResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'reviews';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('reviewer_name')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reviewer_name')
            ->columns([
                Tables\Columns\TextColumn::make('reviewer_name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('reviewer_country')
                    ->label('Country')
                    ->formatStateUsing(fn ($state) => strtoupper($state ?? '')),
                Tables\Columns\TextColumn::make('average_score')
                    ->label('Score')
                    ->badge()
                    ->color(fn ($state) => match(true) {
                        $state >= 9 => 'success',
                        $state >= 7 => 'info',
                        $state >= 5 => 'warning',
                        default => 'danger',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('customer_type')
                    ->badge()
                    ->formatStateUsing(fn ($state) => str_replace('_', ' ', $state)),
                Tables\Columns\TextColumn::make('purpose_type')
                    ->badge(),
                Tables\Columns\TextColumn::make('review_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('positive_text')
                    ->limit(50)
                    ->tooltip(fn ($state) => $state),
                Tables\Columns\TextColumn::make('negative_text')
                    ->limit(50)
                    ->tooltip(fn ($state) => $state),
            ])
            ->filters([
                Tables\Filters\Filter::make('high_score')
                    ->query(fn (Builder $query) => $query->where('average_score', '>=', 8)),
                Tables\Filters\SelectFilter::make('customer_type')
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
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                //
            ])
            ->defaultSort('review_date', 'desc');
    }
}
