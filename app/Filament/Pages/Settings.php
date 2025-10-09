<?php

namespace App\Filament\Pages;

use App\Settings\MaxTransitTime;
use App\Settings\MonthlyWeekendAds;
use App\Settings\PackageHourly;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Schema;

class Settings extends SettingsPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $settings = PackageHourly::class;

    protected static string|null|\UnitEnum $navigationGroup = 'System';

    public function form(Schema $schema): Schema
    {
        $packageHourlySettings = app(PackageHourly::class);
        $maxTransitTimeSettings = app(MaxTransitTime::class);
        $monthlyWeekendAds = app(MonthlyWeekendAds::class);

        return $schema
            ->components([
                TextInput::make('hourly')
                    ->minValue(1)
                    ->numeric()
                    ->label('Package hourly deletion')
                    ->required(),
                TextInput::make('monthly')
                    ->minValue(1)
                    ->numeric()
                    ->label('Monthly weekend Ads')
                    ->formatStateUsing(fn ($state) => $state ?? app(MonthlyWeekendAds::class)->monthly)
                    ->required(),
                TextInput::make('minutes')
                    ->minValue(1)
                    ->numeric()
                    ->label('Max Transit time')
                    ->required()
                    ->formatStateUsing(fn ($state) => $state ?? app(MaxTransitTime::class)->minutes),
            ]);
    }

    public function save(): void
    {
        parent::save();

        $monthlyWeekendAds = app(MonthlyWeekendAds::class);
        $monthlyWeekendAds->monthly = $this->form->getState()['monthly'];
        $monthlyWeekendAds->save();

        $maxTransitTimeSettings = app(MaxTransitTime::class);
        $maxTransitTimeSettings->minutes = $this->form->getState()['minutes'];
        $maxTransitTimeSettings->save();
    }
}
