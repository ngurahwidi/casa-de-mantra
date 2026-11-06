<?php

namespace App\Filament\Resources\RoomTypes\Schemas;

use App\Models\RoomType;
use App\Services\Readiness\ReadinessCheck;
use Closure;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class RoomTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Details')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state, string $operation): void {
                                // Follow the name until the slug is edited by hand; never change a live URL.
                                if ($operation === 'create' && ($get('slug') ?? '') === Str::slug($old ?? '')) {
                                    $set('slug', Str::slug($state ?? ''));
                                }
                            }),
                        TextInput::make('slug')
                            ->helperText('Used in the page address, e.g. /villa/garden-villa.')
                            ->required()
                            ->maxLength(191)
                            ->alphaDash()
                            ->unique(ignoreRecord: true),
                        Textarea::make('description')
                            ->rows(6)
                            ->columnSpanFull(),
                        Toggle::make('is_published')
                            ->label('Published')
                            ->helperText('Show this room type on the public website. It can be published only once it is complete; see the "Ready to sell?" page.')
                            // Publishing is guarded by ReadinessCheck; unpublishing is always allowed.
                            // The record is null while the room type is being created, so nothing can pass yet.
                            ->rule(static fn (mixed $record): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                if ($value !== true) {
                                    return;
                                }

                                $failures = $record instanceof RoomType
                                    ? app(ReadinessCheck::class)->roomType($record)->failures()
                                    : ['add a photo, an active unit and a base price first'];

                                if ($failures !== []) {
                                    $fail('It cannot be published yet: '.implode(', ', $failures).'.');
                                }
                            })
                            ->columnSpanFull(),
                    ]),

                Section::make('Capacity and size')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('max_adults')
                            ->label('Max adults')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(65535),
                        TextInput::make('max_children')
                            ->label('Max children')
                            ->required()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(65535)
                            ->default(0),
                        TextInput::make('size_m2')
                            ->label('Size')
                            ->suffix('m²')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(65535),
                        TextInput::make('bedrooms')
                            ->required()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(65535)
                            ->default(1),
                        TextInput::make('bathrooms')
                            ->required()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(65535)
                            ->default(1),
                    ]),
            ]);
    }
}
