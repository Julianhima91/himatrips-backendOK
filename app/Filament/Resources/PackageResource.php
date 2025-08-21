<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PackageResource\Pages\CreatePackage;
use App\Filament\Resources\PackageResource\Pages\EditPackage;
use App\Filament\Resources\PackageResource\Pages\ListPackages;
use App\Filament\Resources\PackageResource\Pages\ViewPackage;
use App\Models\FlightData;
use App\Models\Hotel;
use App\Models\Package;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PackageResource extends Resource
{
    protected static ?string $model = Package::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('commission'),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
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
                        Section::make('People')
                            ->label('People')
                            ->schema([
                                TextEntry::make('hotelData.adults')
                                    ->label('Adults'),
                                TextEntry::make('hotelData.children')
                                    ->label('Children'),
                            ])
                            ->columns(2),

                        RepeatableEntry::make('hotelData.hotel.transfers')
                            ->label('Transfers')
                            ->schema([
                                TextEntry::make('adult_price')
                                    ->label('Price per Adult')
                                    ->money('EUR'),
                                TextEntry::make('children_price')
                                    ->label('Price per child')
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
                                        $hotelData = $record->hotelData;
                                        $transfers = $hotelData->hotel->transfers;
                                        $transferPrice = 0;

                                        foreach ($transfers as $transfer) {
                                            $transferPrice += $transfer->adult_price * $hotelData->adults;

                                            if ($hotelData->children > 0) {
                                                $transferPrice += $transfer->children_price * $hotelData->children;
                                            }
                                        }
                                        $total = $record->total_price - $flightPrice - $record->hotelData->cheapest_offer_price - $transferPrice;

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
                // create the following columns: Origin, Destination, Departure, Arrival, Hotel, Hotel Price, Flight Price, Commission, Total Price
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('outboundFlight.origin')
                    ->label('Origin')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('outboundFlight.destination')
                    ->label('Destination')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('hotelData.hotel.name')
                    ->label('Hotel')
                    ->sortable(),
                TextColumn::make('hotelData.check_in_date')
                    ->label('Check In')
                    ->sortable(),
                TextColumn::make('hotelData.number_of_nights')
                    ->label('Number of Nights'),
                TextColumn::make('hotelData.cheapest_offer_price')
                    ->label('Hotel Price'),
                TextColumn::make('outbound_flight_id')
                    ->label('Flight Price')
                    ->formatStateUsing(function (Model $record) {
                        return FlightData::where('id', $record->outbound_flight_id)->first()->price;
                    }),
                TextColumn::make('commission')
                    ->formatStateUsing(function (Model $record) {
                        $flightPrice = FlightData::where('id', $record->outbound_flight_id)->first()->price;

                        $hotelData = $record->hotelData;
                        $transfers = $hotelData->hotel->transfers;
                        $transferPrice = 0;

                        foreach ($transfers as $transfer) {
                            $transferPrice += $transfer->adult_price * $hotelData->adults;

                            if ($hotelData->children > 0) {
                                $transferPrice += $transfer->children_price * $hotelData->children;
                            }
                        }

                        return $record->total_price - $flightPrice - $transferPrice - $record->hotelData->cheapest_offer_price;
                    }),
                TextColumn::make('total_price'),
            ])
            ->filters([
                // we need a filter for destination
                // we need a filter for origin
                SelectFilter::make('destination')
                    ->label('Destination')
                    ->relationship('packageConfig.destination_origin.destination', 'name'),
                Filter::make('hotel')
                    ->label('Hotel')
                    ->schema([
                        Select::make('hotel_id')
                            ->label('Hotel')
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search) {
                                return Hotel::query()
                                    ->where('name', 'like', '%'.$search.'%')
                                    ->limit(10)
                                    ->pluck('name', 'id');
                            })
                            ->getOptionLabelUsing(fn ($value) => Hotel::find($value)?->name),
                    ])
                    ->query(function ($query, array $data) {
                        if (! empty($data['hotel_id'])) {
                            $query->whereHas('hotelData.hotel', function ($q) use ($data) {
                                $q->where('id', $data['hotel_id']);
                            });
                        }
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
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
            'index' => ListPackages::route('/'),
            'create' => CreatePackage::route('/create'),
            'view' => ViewPackage::route('/{record}'),
            'edit' => EditPackage::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false; // TODO: Change the autogenerated stub
    }
}
