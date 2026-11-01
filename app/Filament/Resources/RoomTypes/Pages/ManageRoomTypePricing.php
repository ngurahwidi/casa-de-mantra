<?php

namespace App\Filament\Resources\RoomTypes\Pages;

use App\Actions\Pricing\ReorderPriceRules;
use App\Actions\Pricing\SavePriceRule;
use App\Actions\Pricing\SetBasePrice;
use App\Data\Pricing\PriceRuleData;
use App\Enums\RateRuleType;
use App\Filament\Resources\RoomTypes\RoomTypeResource;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\RoomType;
use App\Services\Pricing\NightlyRates;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
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
use Illuminate\Support\Number;
use Livewire\Attributes\Locked;
use LogicException;

/**
 * The "Pricing" page of a room type, in plain words: a base price plus weekend, seasonal and
 * special-date prices, and a calendar of what each night costs.
 *
 * Rate plans stay hidden: the room type's Non-Refundable rate plan is created with its base
 * price (SetBasePrice) and every price here is a rate rule of it. Rule types and their
 * priorities are fixed by RateRuleType.
 */
class ManageRoomTypePricing extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = RoomTypeResource::class;

    protected string $view = 'filament-panels::pages.page';

    protected static ?string $title = 'Pricing';

    protected static ?string $navigationLabel = 'Pricing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    private const DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /** First day of the month shown in the price calendar, Y-m-d in the property timezone. */
    #[Locked]
    public string $calendarMonth;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canEdit($this->getRecord()), 403);

        $this->calendarMonth = now(PropertyProfile::current()->timezone)->startOfMonth()->toDateString();
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
                Callout::make('No base price yet')
                    ->description('Guests cannot book this room type until it has a base price. Set one first; weekend, seasonal and special-date prices can be added after that.')
                    ->warning()
                    ->visible(fn (): bool => $this->basePrice() === null),
                Section::make('Prices')
                    ->description('When a night is covered by several prices, special dates win over a seasonal price, a seasonal price over the weekend price, and all of them over the base price. Among prices of the same kind, the one higher in the list wins; use "Change order" to move them.')
                    ->schema([
                        EmbeddedTable::make(),
                    ]),
                Section::make('Price calendar')
                    ->description('The price per night guests are quoted. Nights in red have no price and cannot be booked.')
                    ->schema([
                        View::make('filament.resources.room-types.price-calendar')
                            ->viewData(fn (): array => $this->priceCalendar()),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => RateRule::query()
                ->whereIn('rate_plan_id', $this->roomType()->nonRefundableRatePlan()->select('id')->getQuery())
                ->orderByDesc('priority')
                ->orderBy('start_date'))
            ->modelLabel('price')
            ->paginated(false)
            // The order is applied per type by ReorderPriceRules (see reorderTable()).
            ->reorderable('priority', condition: fn (): bool => $this->hasPricesToOrder(), direction: 'desc')
            ->reorderRecordsTriggerAction(fn (Action $action, bool $isReordering): Action => $action
                ->button()
                ->label($isReordering ? 'Done' : 'Change order'))
            ->emptyStateHeading('No prices yet')
            ->emptyStateDescription('Set a base price to start selling this room type.')
            ->columns([
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (RateRuleType $state): string => $state->label())
                    ->color(fn (RateRuleType $state): string => self::color($state)),
                TextColumn::make('name'),
                TextColumn::make('applies')
                    ->label('Applies to')
                    ->state(fn (RateRule $record): string => self::describe($record)),
                TextColumn::make('price_per_night')
                    ->label('Price per night')
                    ->money(fn (): string => PropertyProfile::current()->currency),
                TextColumn::make('min_stay')
                    ->label('Min. stay')
                    ->state(fn (RateRule $record): ?int => $record->type === RateRuleType::Base
                        ? $record->ratePlan->min_stay
                        : $record->min_stay_override)
                    ->formatStateUsing(fn (int $state): string => trans_choice(':count night|:count nights', $state))
                    ->placeholder('Base minimum'),
            ])
            ->recordActions([
                Action::make('edit')
                    ->icon(Heroicon::PencilSquare)
                    ->modalHeading(fn (RateRule $record): string => 'Edit '.mb_strtolower($record->type->label()))
                    ->schema(fn (RateRule $record): array => self::priceSchema($record->type))
                    ->fillForm(fn (RateRule $record): array => self::formData($record))
                    ->action(function (RateRule $record, array $data): void {
                        $this->savePrice($record->type, $data, $record);
                    }),
                // The base price is what every other night falls back to, so it is changed, never removed.
                DeleteAction::make()
                    ->hidden(fn (RateRule $record): bool => $record->type === RateRuleType::Base),
            ]);
    }

    /**
     * Drag-and-drop and the move buttons hand over the whole list, top first. Instead of
     * numbering the rows 1..n, ReorderPriceRules keeps each price inside its type's band.
     *
     * @param  array<int|string>  $order
     */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        if (! $this->getTable()->isReorderable()) {
            return;
        }

        app(ReorderPriceRules::class)->handle($this->roomType(), array_map(strval(...), array_values($order)));

        $this->flushCachedTableRecords();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('setBasePrice')
                ->label(fn (): string => $this->basePrice() === null ? 'Set base price' : 'Change base price')
                ->icon(Heroicon::OutlinedBanknotes)
                ->schema(self::priceSchema(RateRuleType::Base))
                ->fillForm(fn (): array => ($base = $this->basePrice()) === null ? ['min_stay' => 1] : self::formData($base))
                ->action(fn (array $data) => $this->savePrice(RateRuleType::Base, $data)),
            ...array_map(fn (RateRuleType $type): Action => Action::make('add'.str($type->value)->studly())
                ->label('Add '.mb_strtolower($type->label()))
                ->color('gray')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => $this->basePrice() !== null)
                ->modalHeading('Add '.mb_strtolower($type->label()))
                ->schema(self::priceSchema($type))
                ->fillForm(fn (): array => $type === RateRuleType::Weekend ? ['name' => 'Weekend', 'days' => [5, 6]] : [])
                ->action(fn (array $data) => $this->savePrice($type, $data)),
                [RateRuleType::Weekend, RateRuleType::Seasonal, RateRuleType::SpecialDates]),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function savePrice(RateRuleType $type, array $data, ?RateRule $rule = null): void
    {
        if ($type === RateRuleType::Base) {
            app(SetBasePrice::class)->handle($this->roomType(), (string) $data['price_per_night'], (int) $data['min_stay']);
        } else {
            app(SavePriceRule::class)->handle($this->roomType(), self::priceRuleData($type, $data), $rule);
        }

        Notification::make()->success()->title('Price saved')->send();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function priceRuleData(RateRuleType $type, array $data): PriceRuleData
    {
        $price = (string) $data['price_per_night'];
        $firstNight = filled($data['first_night'] ?? null) ? CarbonImmutable::parse($data['first_night']) : null;
        $lastNight = filled($data['last_night'] ?? null) ? CarbonImmutable::parse($data['last_night']) : null;
        $minStay = filled($data['min_stay'] ?? null) ? (int) $data['min_stay'] : null;

        return match ($type) {
            RateRuleType::Weekend => PriceRuleData::weekend($data['name'], $price, $data['days']),
            RateRuleType::Seasonal => PriceRuleData::seasonal($data['name'], $price, $firstNight, $lastNight, $minStay),
            RateRuleType::SpecialDates => PriceRuleData::specialDates($data['name'], $price, $firstNight, $lastNight, $minStay),
            RateRuleType::Base => throw new LogicException('The base price is saved with SetBasePrice.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function formData(RateRule $rule): array
    {
        return [
            'name' => $rule->name,
            'days' => $rule->isoDays(),
            'first_night' => $rule->start_date->toDateString(),
            'last_night' => $rule->end_date?->toDateString(),
            'price_per_night' => $rule->price_per_night,
            'min_stay' => $rule->type === RateRuleType::Base ? $rule->ratePlan->min_stay : $rule->min_stay_override,
        ];
    }

    /**
     * @return list<Component>
     */
    private static function priceSchema(RateRuleType $type): array
    {
        $name = TextInput::make('name')
            ->required()
            ->maxLength(255);

        $price = TextInput::make('price_per_night')
            ->label('Price per night')
            ->prefix(fn (): string => PropertyProfile::current()->currency)
            ->required()
            ->numeric()
            ->minValue(0)
            ->maxValue(999999999999.99);

        $minStay = TextInput::make('min_stay')
            ->label('Minimum stay')
            ->suffix('nights')
            ->integer()
            ->minValue(1)
            ->maxValue(365);

        $firstNight = DatePicker::make('first_night')
            ->label('From')
            ->native(false)
            ->displayFormat('j M Y')
            ->required();

        return match ($type) {
            RateRuleType::Base => [
                $price->helperText('Charged for every night that no other price covers.'),
                $minStay->required()->helperText('Applies to every stay unless a seasonal or special-dates price sets its own.'),
            ],
            RateRuleType::Weekend => [
                $name->placeholder('Weekend'),
                CheckboxList::make('days')
                    ->label('Nights')
                    ->helperText('The price applies to the night of each selected day, e.g. Friday and Saturday nights.')
                    ->options(self::DAYS)
                    ->columns(4)
                    ->required(),
                $price,
            ],
            RateRuleType::Seasonal => [
                $name->placeholder('High season'),
                $firstNight->helperText('The first night of the season.'),
                DatePicker::make('last_night')
                    ->label('To')
                    ->native(false)
                    ->displayFormat('j M Y')
                    ->helperText('The last night of the season, not the check-out day.')
                    ->required()
                    ->afterOrEqual('first_night'),
                $price->helperText('Replaces the base and weekend prices on every night of the season.'),
                $minStay->helperText('Leave empty to keep the base minimum stay.'),
            ],
            RateRuleType::SpecialDates => [
                $name->placeholder('Christmas'),
                $firstNight->helperText('The first special night.'),
                DatePicker::make('last_night')
                    ->label('To')
                    ->native(false)
                    ->displayFormat('j M Y')
                    ->helperText('Leave empty for a single night. This is the last night, not the check-out day.')
                    ->afterOrEqual('first_night'),
                $price->helperText('Replaces every other price on these nights.'),
                $minStay->helperText('Leave empty to keep the minimum stay that applies otherwise.'),
            ],
        };
    }

    /**
     * The nights of the calendar month, in weeks from Monday, each with the price it is sold at.
     *
     * @return array{month: string, currency: string, unpriced: int, weeks: list<list<array{date: string, day: int, price: string|null, type: RateRuleType|null, name: string|null, color: string, isPast: bool}|null>>}
     */
    private function priceCalendar(): array
    {
        $profile = PropertyProfile::current();
        $first = CarbonImmutable::parse($this->calendarMonth)->startOfMonth();
        $end = $first->addMonth();
        $today = now($profile->timezone)->toDateString();

        $ratePlan = $this->roomType()->nonRefundableRatePlan()->first();
        $rules = $ratePlan instanceof RatePlan
            ? app(NightlyRates::class)->forPeriod($ratePlan, $first->toDateString(), $end->toDateString())
            : array_fill_keys(array_map(fn (int $day): string => $first->addDays($day)->toDateString(), range(0, $first->daysInMonth - 1)), null);

        $days = array_fill(0, $first->dayOfWeekIso - 1, null);
        $unpriced = 0;

        foreach ($rules as $date => $rule) {
            $isPast = $date < $today;
            $unpriced += ! $isPast && $rule === null ? 1 : 0;

            $days[] = [
                'date' => $date,
                'day' => (int) substr($date, 8, 2),
                'price' => $rule === null ? null : Number::format((float) $rule->price_per_night, maxPrecision: 2),
                'type' => $rule?->type,
                'name' => $rule?->name,
                'color' => $rule === null ? 'danger' : self::color($rule->type),
                'isPast' => $isPast,
            ];
        }

        $weeks = array_chunk(array_pad($days, (int) ceil(count($days) / 7) * 7, null), 7);

        return [
            'month' => $first->format('F Y'),
            'currency' => $profile->currency,
            'unpriced' => $unpriced,
            'weeks' => $weeks,
        ];
    }

    private function basePrice(): ?RateRule
    {
        return RateRule::query()
            ->whereIn('rate_plan_id', $this->roomType()->nonRefundableRatePlan()->select('id')->getQuery())
            ->where('type', RateRuleType::Base)
            ->with('ratePlan')
            ->first();
    }

    /**
     * Ordering only matters once two prices of the same type could cover the same night.
     */
    private function hasPricesToOrder(): bool
    {
        return RateRule::query()
            ->select('type')
            ->whereIn('rate_plan_id', $this->roomType()->nonRefundableRatePlan()->select('id')->getQuery())
            ->where('type', '!=', RateRuleType::Base)
            ->groupBy('type')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }

    private function roomType(): RoomType
    {
        /** @var RoomType */
        return $this->getRecord();
    }

    private static function describe(RateRule $rule): string
    {
        $dates = $rule->end_date === null
            ? 'from '.$rule->start_date->format('j M Y')
            : ($rule->start_date->equalTo($rule->end_date)
                ? $rule->start_date->format('j M Y')
                : $rule->start_date->format('j M Y').' – '.$rule->end_date->format('j M Y'));

        return match ($rule->type) {
            RateRuleType::Base => 'Every night '.$dates,
            RateRuleType::Weekend => implode(', ', array_map(fn (int $day): string => substr(self::DAYS[$day], 0, 3), $rule->isoDays())).' nights',
            default => $rule->days_of_week_mask === RateRule::ALL_DAYS
                ? $dates
                : $dates.' ('.implode(', ', array_map(fn (int $day): string => substr(self::DAYS[$day], 0, 3), $rule->isoDays())).')',
        };
    }

    private static function color(RateRuleType $type): string
    {
        return match ($type) {
            RateRuleType::Base => 'gray',
            RateRuleType::Weekend => 'info',
            RateRuleType::Seasonal => 'warning',
            RateRuleType::SpecialDates => 'success',
        };
    }
}
