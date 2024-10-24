<?php

namespace App\Filament\Pages;

use App\Settings\PackageHourly;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\SettingsPage;

class Settings extends SettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $settings = PackageHourly::class;

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('hourly')
                    ->numeric()
                    ->min(1)
                    ->label('Package hourly deletion')
                    ->required(),
            ]);
    }
}
