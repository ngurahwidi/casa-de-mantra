<?php

namespace App\Filament\Resources\RoomTypes\RelationManagers;

use App\Actions\RoomType\AddGalleryPhotos;
use App\Models\RoomType;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The room type's photo gallery (Spatie media library, "gallery" collection).
 *
 * Deleting a row goes through the Media model, which also removes the file.
 */
class MediaRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    protected static ?string $title = 'Photos';

    protected static ?string $modelLabel = 'photo';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('custom_properties.alt')
                    ->label('Alt text')
                    ->helperText('Describes the photo for screen readers and search engines.')
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->where('collection_name', RoomType::GALLERY))
            ->reorderable('order_column')
            ->defaultSort('order_column')
            ->columns([
                ImageColumn::make('preview')
                    ->state(fn (Media $record): string => $record->getUrl())
                    ->imageHeight(64),
                TextColumn::make('name'),
                TextColumn::make('alt')
                    ->label('Alt text')
                    ->state(fn (Media $record): ?string => $record->getCustomProperty('alt'))
                    ->placeholder('Missing'),
                TextColumn::make('size')
                    ->state(fn (Media $record): string => $record->human_readable_size),
            ])
            ->headerActions([
                Action::make('upload')
                    ->label('Upload photos')
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    // A custom action, so Filament does not hide it on the read-only view page by itself.
                    ->hidden(fn (): bool => $this->isReadOnly())
                    ->schema([
                        FileUpload::make('photos')
                            ->image()
                            ->multiple()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(10240)
                            ->storeFiles(false)
                            ->required(),
                    ])
                    ->action(function (array $data, AddGalleryPhotos $addPhotos): void {
                        /** @var RoomType $roomType */
                        $roomType = $this->getOwnerRecord();

                        $added = $addPhotos->handle($roomType, $data['photos']);

                        Notification::make()
                            ->success()
                            ->title(trans_choice(':count photo added|:count photos added', $added->count()))
                            ->send();
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Alt text'),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
