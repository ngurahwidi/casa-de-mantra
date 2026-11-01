<?php

namespace App\Filament\Resources\PropertyProfiles\Schemas;

use App\Enums\PropertyType;
use DateTimeZone;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PropertyProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('General')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('type')
                            ->options(PropertyType::class)
                            ->required(),
                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->maxLength(191),
                        TextInput::make('whatsapp')
                            ->label('WhatsApp number')
                            ->tel()
                            ->placeholder('+628123456789')
                            ->helperText('International format, used for the WhatsApp button.')
                            ->maxLength(32),
                        Textarea::make('address')
                            ->rows(3)
                            ->columnSpanFull(),
                        TextInput::make('lat')
                            ->label('Latitude')
                            ->numeric()
                            ->minValue(-90)
                            ->maxValue(90),
                        TextInput::make('lng')
                            ->label('Longitude')
                            ->numeric()
                            ->minValue(-180)
                            ->maxValue(180),
                    ]),

                Section::make('Stay')
                    ->description('Changing the timezone or currency does not update reservations that already exist.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('timezone')
                            ->options(fn (): array => array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))
                            ->searchable()
                            ->required(),
                        TextInput::make('currency')
                            ->helperText('Three-letter ISO code, e.g. IDR.')
                            ->required()
                            ->length(3)
                            // Validated before dehydration, so lowercase input is accepted and stored uppercase.
                            ->regex('/^[A-Za-z]{3}$/')
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : strtoupper($state)),
                        TimePicker::make('checkin_time')
                            ->label('Check-in time')
                            ->seconds(false)
                            ->required(),
                        TimePicker::make('checkout_time')
                            ->label('Check-out time')
                            ->seconds(false)
                            ->required(),
                    ]),

                Section::make('Booking requests')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('payment_window_hours')
                            ->label('Payment window')
                            ->helperText('How long a guest has to pay after a request is approved.')
                            ->suffix('hours')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(65535),
                        TextInput::make('pending_reminder_hours')
                            ->label('Remind admin about unanswered requests after')
                            ->suffix('hours')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(65535),
                        Toggle::make('confirm_on_deposit')
                            ->label('Confirm reservations on a deposit')
                            ->helperText('When off, a reservation is confirmed only once it is paid in full.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Bank transfer')
                    ->description('Shown to guests when their request is approved and awaiting payment.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('bank_account_info.bank')
                            ->label('Bank')
                            ->maxLength(255),
                        TextInput::make('bank_account_info.account_name')
                            ->label('Account name')
                            ->maxLength(255),
                        TextInput::make('bank_account_info.account_number')
                            ->label('Account number')
                            ->maxLength(64),
                        TextInput::make('bank_account_info.swift_code')
                            ->label('SWIFT/BIC code')
                            ->helperText('For guests paying from abroad.')
                            ->maxLength(11),
                    ]),

                Section::make('SEO')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('seo_settings.meta_title')
                            ->label('Default page title')
                            ->maxLength(70),
                        Textarea::make('seo_settings.meta_description')
                            ->label('Default meta description')
                            ->rows(3)
                            ->maxLength(160),
                    ]),

                Section::make('Theme')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        ColorPicker::make('theme.primary')
                            ->label('Primary color')
                            ->hex(),
                        ColorPicker::make('theme.accent')
                            ->label('Accent color')
                            ->hex(),
                    ]),
            ]);
    }
}
