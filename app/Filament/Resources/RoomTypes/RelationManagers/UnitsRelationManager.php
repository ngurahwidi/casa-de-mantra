<?php

namespace App\Filament\Resources\RoomTypes\RelationManagers;

use App\Enums\UnitStatus;
use App\Models\Unit;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class UnitsRelationManager extends RelationManager
{
    protected static string $relationship = 'units';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->helperText('Short internal name, e.g. V1.')
                    ->required()
                    ->maxLength(50)
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('room_type_id', $this->getOwnerRecord()->getKey()),
                    ),
                Select::make('status')
                    ->options(UnitStatus::class)
                    ->default(UnitStatus::Active)
                    ->helperText('Inactive units are never offered to guests.')
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (UnitStatus $state): string => $state->name)
                    ->color(fn (UnitStatus $state): string => $state === UnitStatus::Active ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(UnitStatus::class),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                // A unit with reservations is set inactive instead.
                DeleteAction::make()
                    ->visible(fn (Unit $record): bool => $record->canBeDeleted()),
            ]);
    }
}
