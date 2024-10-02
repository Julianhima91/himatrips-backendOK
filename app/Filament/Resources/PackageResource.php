<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PackageResource\Pages;
use App\Models\FlightData;
use App\Models\Package;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PackageResource extends Resource
{
    protected static ?string $model = Package::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('commission'),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Outbound Flight')
                    ->description('Details for the outbound flight')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('outboundFlight.origin')
                                    ->label('Origin Airport'),
                                TextEntry::make('outboundFlight.destination')
                                    ->label('Destination Airport'),
                                TextEntry::make('outboundFlight.departure')
                                    ->label('Departure'),
                                TextEntry::make('outboundFlight.arrival')
                                    ->label('Arrival'),
                                TextEntry::make('outboundFlight.airline')
                                    ->label('Airline'),
                                TextEntry::make('outboundFlight.price')
                                    ->money('EUR')
                                    ->label('Price'),
                            ]),
                    ]),
                Section::make('Inbound Flight')
                    ->description('Details for the inbound flight')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('inboundFlight.origin')
                                    ->label('Origin Airport'),
                                TextEntry::make('inboundFlight.destination')
                                    ->label('Destination Airport'),
                                TextEntry::make('inboundFlight.departure')
                                    ->label('Departure'),
                                TextEntry::make('inboundFlight.arrival')
                                    ->label('Arrival'),
                                TextEntry::make('inboundFlight.airline')
                                    ->label('Airline'),
                            ]),
                    ]),
                Section::make('Hotel')
                    ->description('Details for the hotel')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('hotelData.check_in_date')
                                    ->label('Check-in Date'),
                                TextEntry::make('hotelData.number_of_nights')
                                    ->label('Number of Nights'),
                                TextEntry::make('hotelData.room_basis')
                                    ->label('Room Basis'),
                                TextEntry::make('hotelData.cheapest_offer_price')
                                    ->money('EUR')
                                    ->label('Price'),
                                TextEntry::make('hotelData.hotel.hotel_id')
                                    ->label('HOTEL ID'),
                            ]),
                    ]),
                Section::make('Hotel Offers')
                    ->description('Details for the hotel offers')
                    ->schema([
                        RepeatableEntry::make('hotelData.offers')
                            ->label('Offers')
                            ->schema([
                                TextEntry::make('room_basis')
                                    ->label('Room Basis'),
                                TextEntry::make('room_type')
                                    ->label('Room Type'),
                                TextEntry::make('price')
                                    ->label('Price'),
                            ])
                            ->columns(3),
                    ]),
                Section::make('Hotel Transfers')
                    ->description('Details for the hotel transfers')
                    ->schema([
                        RepeatableEntry::make('hotelData.hotel.transfers')
                            ->label('Transfers')
                            ->schema([
                                TextEntry::make('price')
                                    ->label('Price')
                                    ->money('EUR'),
                                TextEntry::make('description')
                                    ->label('Description'),
                            ])
                            ->columns(3),
                    ]),
                Section::make('Package Details')
                    ->description('Details for the package itself')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('commission')
                                    ->money('EUR')
                                    ->label('Commission')
                                    ->formatStateUsing(function (Model $record) {
                                        $flightPrice = FlightData::where('id', $record->outbound_flight_id)->first()->price;
                                        $total = $record->total_price - $flightPrice - $record->hotelData->cheapest_offer_price;

                                        return '€'.$total;
                                    }),
                                TextEntry::make('total_price')
                                    ->money('EUR')
                                    ->label('total_price'),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->columns([
                //create the following columns: Origin, Destination, Departure, Arrival, Hotel, Hotel Price, Flight Price, Commission, Total Price
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('outboundFlight.origin')
                    ->label('Origin')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('outboundFlight.destination')
                    ->label('Destination')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('hotelData.hotel.name')
                    ->label('Hotel')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('hotelData.check_in_date')
                    ->label('Check In')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('hotelData.number_of_nights')
                    ->label('Number of Nights'),
                Tables\Columns\TextColumn::make('hotelData.cheapest_offer_price')
                    ->label('Hotel Price'),
                Tables\Columns\TextColumn::make('outbound_flight_id')
                    ->label('Flight Price')
                    ->formatStateUsing(function (Model $record) {
                        return FlightData::where('id', $record->outbound_flight_id)->first()->price;
                    }),
                Tables\Columns\TextColumn::make('commission')
                    ->formatStateUsing(function (Model $record) {
                        $flightPrice = FlightData::where('id', $record->outbound_flight_id)->first()->price;

                        return $record->total_price - $flightPrice - $record->hotelData->cheapest_offer_price;
                    }),
                Tables\Columns\TextColumn::make('total_price'),
            ])
            ->filters([
                //we need a filter for destination
                //we need a filter for origin
                Tables\Filters\SelectFilter::make('destination')
                    ->label('Destination')
                    ->relationship('packageConfig.destination_origin.destination', 'name'),

            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateActions([
            ])
            ->paginated([10, 25, 50, 100]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPackages::route('/'),
            'create' => Pages\CreatePackage::route('/create'),
            'view' => Pages\ViewPackage::route('/{record}'),
            'edit' => Pages\EditPackage::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false; // TODO: Change the autogenerated stub
    }
}
