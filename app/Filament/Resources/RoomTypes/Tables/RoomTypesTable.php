<?php

namespace App\Filament\Resources\RoomTypes\Tables;

use App\Filament\Resources\RoomTypes\RoomTypeResource;
use App\Models\RoomType;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class RoomTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('max_adults')
                    ->label('Adults')
                    ->numeric(),
                TextColumn::make('max_children')
                    ->label('Children')
                    ->numeric(),
                TextColumn::make('bedrooms')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('units_count')
                    ->label('Units')
                    ->counts('units'),
                IconColumn::make('is_published')
                    ->label('Published')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_published')
                    ->label('Published'),
            ])
            ->recordActions([
                // Staff may look at a room type but not change it.
                ViewAction::make()
                    ->hidden(fn (RoomType $record): bool => RoomTypeResource::canEdit($record)),
                EditAction::make(),
                Action::make('pricing')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->url(fn (RoomType $record): string => RoomTypeResource::getUrl('pricing', ['record' => $record])),
            ]);
    }
}
