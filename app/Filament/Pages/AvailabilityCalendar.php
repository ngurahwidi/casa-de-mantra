<?php

namespace App\Filament\Pages;

use App\Actions\Availability\CreateManualBlock;
use App\Actions\Availability\DeleteManualBlock;
use App\Enums\AvailabilityBlockSource;
use App\Enums\ReservationStatus;
use App\Enums\UnitStatus;
use App\Exceptions\Availability\BlockNotAllowedException;
use App\Filament\Resources\RoomTypes\RoomTypeResource;
use App\Models\AvailabilityBlock;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\Unit;
use App\Services\Availability\UnitCalendar;
use App\Services\Pricing\NightlyRates;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Number;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * The availability calendar for daily operations. The admin picks a room type, then one of its
 * units, and sees that unit's nights: the reservations holding
 * the unit (awaiting payment, confirmed), its manual and OTA blocks and its iCal blocks, each in
 * its own colour, with the price of every night.
 *
 * Admins block and unblock nights of the unit here (CreateManualBlock, DeleteManualBlock).
 * Prices are shown but only changed on the room type's Pricing page, the one place for them.
 */
class AvailabilityCalendar extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static ?string $title = 'Calendar';

    protected static ?string $navigationLabel = 'Calendar';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 1;

    /** The room type whose units are shown; null when there are no room types. */
    #[Locked]
    #[Url(as: 'room_type')]
    public ?string $roomTypeId = null;

    /** The unit shown, one of the room type's units; null when it has none. */
    #[Locked]
    #[Url(as: 'unit')]
    public ?string $unitId = null;

    /** First day of the month shown in the calendar, Y-m-d in the property timezone. */
    #[Locked]
    public string $calendarMonth;

    /**
     * A unit in the URL wins and brings its room type along; otherwise the room type in the URL,
     * or the first room type that has units, is shown with its first unit.
     */
    public function mount(): void
    {
        $roomTypes = $this->roomTypes();
        $unit = $this->unitId === null ? null : Unit::query()->find($this->unitId);

        $roomType = $roomTypes->find($unit->room_type_id ?? $this->roomTypeId)
            ?? $roomTypes->first(fn (RoomType $roomType): bool => $roomType->units_count > 0)
            ?? $roomTypes->first();

        $this->roomTypeId = $roomType?->getKey();
        $this->unitId = $unit?->room_type_id === $this->roomTypeId ? $unit?->getKey() : $this->units()->first()?->getKey();

        $this->calendarMonth = now(PropertyProfile::current()->timezone)->startOfMonth()->toDateString();
    }

    public function selectRoomType(string $roomTypeId): void
    {
        $this->roomTypeId = $this->roomTypes()->findOrFail($roomTypeId)->getKey();
        $this->unitId = $this->units()->first()?->getKey();

        $this->resetTable();
    }

    public function selectUnit(string $unitId): void
    {
        $this->unitId = $this->units()->findOrFail($unitId)->getKey();

        $this->resetTable();
    }

    public function previousCalendarMonth(): void
    {
        $this->calendarMonth = CarbonImmutable::parse($this->calendarMonth)->subMonthNoOverflow()->toDateString();
    }

    public function nextCalendarMonth(): void
    {
        $this->calendarMonth = CarbonImmutable::parse($this->calendarMonth)->addMonthNoOverflow()->toDateString();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make('No room types yet')
                    ->description('Add a room type and its units under Room types to see and block their dates.')
                    ->warning()
                    ->visible(fn (): bool => $this->roomType() === null),
                Section::make()
                    ->visible(fn (): bool => $this->roomTypes()->count() > 1)
                    ->schema([
                        View::make('filament.pages.availability-calendar-room-types')
                            ->viewData(fn (): array => [
                                'roomTypes' => $this->roomTypes(),
                                'selected' => $this->roomTypeId,
                            ]),
                    ]),
                Callout::make('No units yet')
                    ->description('This room type has no units. Add one on its Details page (Units) to see and block its dates.')
                    ->warning()
                    ->visible(fn (): bool => $this->roomType() !== null && $this->unit() === null),
                Callout::make('This unit is inactive')
                    ->description('Inactive units are never offered to guests, whatever this calendar shows.')
                    ->warning()
                    ->visible(fn (): bool => $this->unit()?->status === UnitStatus::Inactive),
                Callout::make('No base price yet')
                    ->description('Guests cannot book this room type until it has a base price. Use "Edit prices" to set one.')
                    ->warning()
                    ->visible(fn (): bool => $this->unit() !== null && $this->ratePlan() === null),
                Section::make('Availability')
                    ->description('Reservations awaiting payment or confirmed hold their nights. Pending requests do not, so they are not shown.')
                    ->visible(fn (): bool => $this->unit() !== null)
                    ->schema([
                        View::make('filament.pages.availability-calendar')
                            ->viewData(fn (): array => $this->availabilityCalendar()),
                    ]),
                Section::make('Blocks')
                    ->description('Upcoming nights closed on this unit. Blocks from an OTA calendar (iCal) are removed by that calendar, not here.')
                    ->visible(fn (): bool => $this->unit() !== null)
                    ->schema([
                        EmbeddedTable::make(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => AvailabilityBlock::query()
                ->with('icalFeed')
                ->where('unit_id', $this->unit()?->getKey())
                ->where('end_date', '>', now(PropertyProfile::current()->timezone)->toDateString())
                ->orderBy('start_date'))
            ->modelLabel('block')
            ->paginated(false)
            ->emptyStateHeading('No blocks')
            ->emptyStateDescription('Use "Block dates" to close nights of this unit.')
            ->columns([
                TextColumn::make('source')
                    ->label('Type')
                    ->badge()
                    ->state(fn (AvailabilityBlock $record): string => self::blockLabel($record))
                    ->color(fn (AvailabilityBlock $record): string => self::blockColor($record->source)),
                TextColumn::make('nights')
                    ->state(fn (AvailabilityBlock $record): string => self::nights($record->start_date->toImmutable(), $record->end_date->toImmutable())),
                TextColumn::make('reason')
                    ->placeholder('No reason given'),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label('Unblock')
                    ->modalHeading('Unblock these nights')
                    ->successNotificationTitle('Nights unblocked')
                    ->hidden(fn (AvailabilityBlock $record): bool => $record->source === AvailabilityBlockSource::Ical)
                    ->using(function (AvailabilityBlock $record): bool {
                        app(DeleteManualBlock::class)->handle($record);

                        return true;
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        $today = fn (): string => now(PropertyProfile::current()->timezone)->toDateString();

        return [
            Action::make('blockDates')
                ->label('Block dates')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->visible(fn (): bool => $this->unit() !== null)
                ->modalHeading(fn (): string => 'Block dates of unit '.$this->unit()?->code)
                ->modalDescription('Closes these nights of this unit for guests, e.g. for maintenance or an owner\'s stay.')
                ->schema([
                    DatePicker::make('first_night')
                        ->label('From')
                        ->native(false)
                        ->displayFormat('j M Y')
                        ->minDate($today)
                        ->helperText('The first night to block.')
                        ->required(),
                    DatePicker::make('last_night')
                        ->label('To')
                        ->native(false)
                        ->displayFormat('j M Y')
                        ->helperText('The last night to block, not the day after.')
                        ->required()
                        ->afterOrEqual('first_night'),
                    TextInput::make('reason')
                        ->placeholder('Maintenance')
                        ->maxLength(255),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        app(CreateManualBlock::class)->handle(
                            $this->unitOrFail(),
                            CarbonImmutable::parse($data['first_night']),
                            CarbonImmutable::parse($data['last_night']),
                            $data['reason'] ?? null,
                        );
                    } catch (BlockNotAllowedException $exception) {
                        Notification::make()->danger()->title('Dates not blocked')->body($exception->getMessage())->send();

                        $action->halt();
                    }

                    Notification::make()->success()->title('Dates blocked')->send();
                }),
            // Prices are only changed on the room type's Pricing page, so admins have one place for them.
            Action::make('editPrices')
                ->label('Edit prices')
                ->color('gray')
                ->icon(Heroicon::OutlinedBanknotes)
                ->visible(fn (): bool => $this->roomType() !== null)
                ->url(fn (): ?string => ($roomType = $this->roomType()) === null ? null : RoomTypeResource::getUrl('pricing', ['record' => $roomType])),
        ];
    }

    /**
     * The nights of the calendar month, in weeks from Monday, each with what takes it and its price.
     *
     * @return array{month: string, unit: Unit, units: Collection<int, Unit>, taken: int, weeks: list<list<array{date: string, day: int, price: string|null, isPast: bool, color: string|null, entries: list<array{label: string, title: string, color: string}>}|null>>}
     */
    private function availabilityCalendar(): array
    {
        $unit = $this->unitOrFail();
        $first = CarbonImmutable::parse($this->calendarMonth)->startOfMonth();
        $end = $first->addMonth();
        $today = now(PropertyProfile::current()->timezone)->toDateString();

        $occupancy = app(UnitCalendar::class)->forPeriod($unit, $first->toDateString(), $end->toDateString());

        $ratePlan = $this->ratePlan();
        $rules = $ratePlan === null ? [] : app(NightlyRates::class)->forPeriod($ratePlan, $first->toDateString(), $end->toDateString());

        $days = array_fill(0, $first->dayOfWeekIso - 1, null);
        $taken = 0;

        foreach ($occupancy as $date => $occupants) {
            $entries = array_map(fn (Reservation|AvailabilityBlock $occupant): array => $occupant instanceof Reservation
                ? self::reservationEntry($occupant)
                : self::blockEntry($occupant), $occupants);

            $taken += $entries === [] ? 0 : 1;
            $rule = $rules[$date] ?? null;

            $days[] = [
                'date' => $date,
                'day' => (int) substr($date, 8, 2),
                'price' => $rule === null ? null : Number::format((float) $rule->price_per_night, maxPrecision: 2),
                'isPast' => $date < $today,
                'color' => $entries[0]['color'] ?? null,
                'entries' => $entries,
            ];
        }

        return [
            'month' => $first->format('F Y'),
            'unit' => $unit,
            'units' => $this->units(),
            'taken' => $taken,
            'weeks' => array_chunk(array_pad($days, (int) ceil(count($days) / 7) * 7, null), 7),
        ];
    }

    /**
     * @return array{label: string, title: string, color: string}
     */
    private static function reservationEntry(Reservation $reservation): array
    {
        $status = $reservation->status === ReservationStatus::Confirmed ? 'Confirmed' : 'Awaiting payment';

        return [
            'label' => $reservation->code.' · '.$reservation->guest_full_name,
            'title' => $status.': '.$reservation->code.', '.$reservation->guest_full_name.', '.self::nights($reservation->check_in->toImmutable(), $reservation->check_out->toImmutable()),
            'color' => $reservation->status === ReservationStatus::Confirmed ? 'success' : 'warning',
        ];
    }

    /**
     * @return array{label: string, title: string, color: string}
     */
    private static function blockEntry(AvailabilityBlock $block): array
    {
        $label = self::blockLabel($block);

        return [
            'label' => $block->reason ?? $label,
            'title' => $label.': '.self::nights($block->start_date->toImmutable(), $block->end_date->toImmutable()).($block->reason === null ? '' : ', '.$block->reason),
            'color' => self::blockColor($block->source),
        ];
    }

    private static function blockLabel(AvailabilityBlock $block): string
    {
        return match ($block->source) {
            AvailabilityBlockSource::Manual => 'Blocked',
            AvailabilityBlockSource::OtaManual => 'OTA booking',
            AvailabilityBlockSource::Ical => 'iCal'.($block->icalFeed === null ? '' : ': '.str($block->icalFeed->channel->value)->headline()),
        };
    }

    private static function blockColor(AvailabilityBlockSource $source): string
    {
        return match ($source) {
            AvailabilityBlockSource::Manual => 'danger',
            AvailabilityBlockSource::OtaManual => 'gray',
            AvailabilityBlockSource::Ical => 'info',
        };
    }

    /**
     * The nights of a stay or block as "24 Dec 2026 – 26 Dec 2026 (3 nights)"; $end is the exclusive check-out day.
     */
    private static function nights(CarbonImmutable $start, CarbonImmutable $end): string
    {
        $last = $end->subDay();
        $count = (int) $start->diffInDays($end);

        return ($count === 1 ? $start->format('j M Y') : $start->format('j M Y').' – '.$last->format('j M Y'))
            .' ('.trans_choice(':count night|:count nights', $count).')';
    }

    /**
     * Every room type by name, with how many units it has.
     *
     * @return Collection<int, RoomType>
     */
    private function roomTypes(): Collection
    {
        return once(fn (): Collection => RoomType::query()->withCount('units')->orderBy('name')->get());
    }

    private function roomType(): ?RoomType
    {
        return $this->roomTypeId === null ? null : $this->roomTypes()->find($this->roomTypeId);
    }

    /**
     * The units of the selected room type by code.
     *
     * @return Collection<int, Unit>
     */
    private function units(): Collection
    {
        return $this->unitsOf($this->roomTypeId);
    }

    /**
     * Memoised per room type, so a switch of room type within a request is not served stale units.
     *
     * @return Collection<int, Unit>
     */
    private function unitsOf(?string $roomTypeId): Collection
    {
        return once(fn (): Collection => $roomTypeId === null
            ? new Collection
            : Unit::query()->with('roomType')->where('room_type_id', $roomTypeId)->orderBy('code')->get());
    }

    private function unit(): ?Unit
    {
        return $this->unitId === null ? null : $this->units()->find($this->unitId);
    }

    private function unitOrFail(): Unit
    {
        return $this->unit() ?? abort(404);
    }

    private function ratePlan(): ?RatePlan
    {
        return $this->roomType()?->nonRefundableRatePlan()->first();
    }
}
