<?php

namespace App\Filament\Resources\NotificationTemplates;

use App\Enums\NotificationChannel;
use App\Filament\Resources\NotificationTemplates\Pages\ManageNotificationTemplates;
use App\Models\NotificationTemplate;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The wording of the messages sent to guests and admins. Each template belongs to a message the
 * system sends (its code), so templates are edited or switched off here, never created or deleted.
 */
class NotificationTemplateResource extends Resource
{
    protected static ?string $model = NotificationTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 3;

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Message')
                    ->formatStateUsing(fn (string $state): string => str($state)->headline())
                    ->disabled(),
                Select::make('channel')
                    ->options(NotificationChannel::class)
                    ->disabled(),
                TextInput::make('subject')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Textarea::make('body')
                    ->required()
                    ->rows(16)
                    ->helperText('Variables in double braces are filled in when the message is sent: '
                        .collect(NotificationTemplate::PLACEHOLDERS)->map(fn (string $placeholder): string => "{{ {$placeholder} }}")->implode(', ').'.')
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label('Active')
                    ->helperText('An inactive template is not sent.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')
                    ->label('Message')
                    ->formatStateUsing(fn (string $state): string => str($state)->headline())
                    ->sortable(),
                TextColumn::make('channel')
                    ->badge()
                    ->formatStateUsing(fn (NotificationChannel $state): string => str($state->value)->headline()),
                TextColumn::make('subject')
                    ->limit(60)
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                // Staff may read a template but not change it.
                ViewAction::make()
                    ->modalWidth(Width::FourExtraLarge)
                    ->hidden(fn (NotificationTemplate $record): bool => static::canEdit($record)),
                EditAction::make()
                    ->modalWidth(Width::FourExtraLarge),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageNotificationTemplates::route('/'),
        ];
    }
}
