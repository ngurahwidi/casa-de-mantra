<?php

namespace App\Filament\Pages;

use App\Actions\Content\RemoveHomepageBanner;
use App\Actions\Content\UpdateHomepageHero;
use App\Data\Site\HomepageHeroContent;
use App\Models\PropertyProfile;
use App\Services\Site\HomepageHero;
use App\Services\Site\VillaCatalog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use UnitEnum;

/**
 * The "Homepage" page: the banner, title and subtitle of the homepage hero.
 *
 * The hero is stored on the property profile but treated as content, so owners and staff both
 * edit it here (UpdateHomepageHero), while the profile itself stays owner-only.
 */
class Homepage extends Page
{
    protected string $view = 'filament-panels::pages.page';

    protected static ?string $title = 'Homepage';

    protected static ?string $navigationLabel = 'Homepage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 0;

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Hero')
                    ->description('The banner and headline at the top of the homepage. Anything left empty uses the built-in wording or the first villa photo.')
                    ->schema([
                        View::make('filament.pages.homepage-hero')
                            ->viewData(fn (): array => ['hero' => $this->hero()]),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editHero')
                ->label('Edit hero')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->modalHeading('Edit homepage hero')
                ->schema([
                    FileUpload::make('banner')
                        ->label(fn (): string => $this->hero()->hasCustomBanner ? 'Replace banner' : 'Banner')
                        ->helperText('Optional. Leave empty to keep the current banner. A landscape photo at least 1920 px wide looks best.')
                        ->image()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(10240)
                        ->storeFiles(false),
                    TextInput::make('banner_alt')
                        ->label('Banner alt text')
                        ->helperText('Describes the banner for screen readers and search engines.')
                        ->maxLength(255),
                    TextInput::make('title')
                        ->placeholder(HomepageHero::DEFAULT_TITLE)
                        ->helperText('Leave empty to use the placeholder text.')
                        ->maxLength(HomepageHero::TITLE_MAX_LENGTH),
                    Textarea::make('subtitle')
                        ->placeholder(HomepageHero::DEFAULT_SUBTITLE)
                        ->helperText('Leave empty to use the placeholder text.')
                        ->rows(3)
                        ->maxLength(HomepageHero::SUBTITLE_MAX_LENGTH),
                ])
                ->fillForm(fn (): array => [
                    'title' => $this->profile()->hero_title,
                    'subtitle' => $this->profile()->hero_subtitle,
                    'banner_alt' => $this->profile()->getFirstMedia(PropertyProfile::HERO)?->getCustomProperty('alt'),
                ])
                ->action(function (array $data, UpdateHomepageHero $updateHero): void {
                    $banner = Arr::first(Arr::wrap($data['banner'] ?? null));

                    $updateHero->handle(
                        $this->profile(),
                        $data['title'] ?? null,
                        $data['subtitle'] ?? null,
                        $data['banner_alt'] ?? null,
                        $banner instanceof UploadedFile ? $banner : null,
                    );

                    Notification::make()->success()->title('Homepage hero saved')->send();
                }),
            Action::make('removeBanner')
                ->label('Remove banner')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('The homepage will show the first villa photo instead.')
                ->visible(fn (): bool => $this->hero()->hasCustomBanner)
                ->action(function (RemoveHomepageBanner $removeBanner): void {
                    $removeBanner->handle($this->profile());

                    Notification::make()->success()->title('Banner removed')->send();
                }),
        ];
    }

    private function profile(): PropertyProfile
    {
        return PropertyProfile::current();
    }

    private function hero(): HomepageHeroContent
    {
        return app(HomepageHero::class)->resolve($this->profile(), app(VillaCatalog::class)->published());
    }
}
