<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Actions\Reservation\CreateManualReservation;
use App\Data\Reservation\GuestDetails;
use App\Data\Reservation\ManualReservationData;
use App\Enums\ReservationSource;
use App\Exceptions\Pricing\PricingException;
use App\Exceptions\Reservation\ReservationException;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Filament\Resources\RoomTypes\RoomTypeResource;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class ListReservations extends ListRecords
{
    protected static string $resource = ReservationResource::class;

    /** Sources a booking entered by hand can have; website requests come in on their own. */
    private const MANUAL_SOURCES = [ReservationSource::BookingCom, ReservationSource::Expedia, ReservationSource::Manual];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createManualReservation')
                ->label('Create manual reservation')
                ->icon(Heroicon::OutlinedPlus)
                ->modalDescription('For a booking taken by phone or made on Booking.com or Expedia. It is confirmed straight away; record payments on the reservation afterwards.')
                ->modalWidth(Width::ThreeExtraLarge)
                ->modalSubmitActionLabel('Create')
                ->fillForm(fn (): array => [
                    'source' => ReservationSource::BookingCom->value,
                    'room_type_id' => RoomType::query()->count() === 1 ? RoomType::query()->value('id') : null,
                    'adults' => 2,
                    'children' => 0,
                ])
                ->schema([
                    Section::make('Booking')
                        ->columns(2)
                        ->schema([
                            Select::make('source')
                                ->label('Booked via')
                                ->options(collect(self::MANUAL_SOURCES)->mapWithKeys(fn (ReservationSource $source): array => [$source->value => $source->label()]))
                                ->required()
                                ->live(),
                            TextInput::make('external_id')
                                ->label('Booking number')
                                ->helperText('The number on the OTA, so the same booking is not entered twice.')
                                ->maxLength(191)
                                ->visible(fn (Get $get): bool => $get('source') !== ReservationSource::Manual->value),
                            Select::make('room_type_id')
                                ->label('Room type')
                                ->options(fn (): array => RoomType::query()->orderBy('name')->pluck('name', 'id')->all())
                                ->required(),
                            TextInput::make('total')
                                ->label('Total price')
                                ->prefix(fn (): string => PropertyProfile::current()->currency)
                                ->numeric()
                                ->minValue(0.01)
                                ->maxValue(999999999999.99)
                                ->helperText('What the guest pays, e.g. the price on the OTA. Leave empty to use your prices.'),
                            DatePicker::make('check_in')
                                ->label('Check-in')
                                ->native(false)
                                ->displayFormat('j M Y')
                                ->minDate(fn (): string => now(PropertyProfile::current()->timezone)->toDateString())
                                ->required(),
                            DatePicker::make('check_out')
                                ->label('Check-out')
                                ->native(false)
                                ->displayFormat('j M Y')
                                ->after('check_in')
                                ->required(),
                            TextInput::make('adults')
                                ->integer()
                                ->minValue(1)
                                ->required(),
                            TextInput::make('children')
                                ->integer()
                                ->minValue(0)
                                ->required(),
                        ]),
                    Section::make('Guest')
                        ->columns(2)
                        ->schema([
                            TextInput::make('full_name')
                                ->label('Name')
                                ->required()
                                ->maxLength(255),
                            TextInput::make('email')
                                ->email()
                                ->required()
                                ->maxLength(191),
                            TextInput::make('phone')
                                ->label('Phone / WhatsApp')
                                ->tel()
                                ->required()
                                ->maxLength(50),
                            TextInput::make('country')
                                ->maxLength(100),
                            Textarea::make('special_requests')
                                ->rows(2)
                                ->columnSpanFull(),
                            Textarea::make('note')
                                ->helperText('Kept in the status history.')
                                ->rows(2)
                                ->columnSpanFull(),
                        ]),
                ])
                ->action(function (array $data, Action $action): void {
                    $this->createManualReservation($data, $action);
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createManualReservation(array $data, Action $action): void
    {
        $roomType = RoomType::query()->findOrFail($data['room_type_id']);
        $ratePlan = $roomType->nonRefundableRatePlan()->first();

        // Rate plans are hidden from admins; the room type gets one with its base price.
        if (! $ratePlan instanceof RatePlan) {
            Notification::make()
                ->danger()
                ->title('Reservation not created')
                ->body("{$roomType->name} has no base price yet. Set one on its Pricing page first.")
                ->actions([
                    Action::make('pricing')
                        ->label('Open Pricing')
                        ->url(RoomTypeResource::getUrl('pricing', ['record' => $roomType])),
                ])
                ->send();

            $action->halt();
        }

        $source = ReservationSource::from($data['source']);

        try {
            $reservation = app(CreateManualReservation::class)->handle(
                new ManualReservationData(
                    source: $source,
                    roomType: $roomType,
                    ratePlan: $ratePlan,
                    checkIn: CarbonImmutable::parse($data['check_in']),
                    checkOut: CarbonImmutable::parse($data['check_out']),
                    adults: (int) $data['adults'],
                    children: (int) $data['children'],
                    guest: new GuestDetails($data['full_name'], $data['email'], $data['phone'], $data['country'] ?? null),
                    total: filled($data['total'] ?? null) ? (string) $data['total'] : null,
                    externalId: $source === ReservationSource::Manual ? null : ($data['external_id'] ?? null),
                    specialRequests: $data['special_requests'] ?? null,
                ),
                $this->admin(),
                $data['note'] ?? null,
            );

            Notification::make()->success()->title("Reservation {$reservation->code} created")->send();

            $this->redirect(ReservationResource::getUrl('view', ['record' => $reservation]));
        } catch (PricingException|ReservationException $exception) {
            Notification::make()->danger()->title('Reservation not created')->body($exception->getMessage())->send();

            $action->halt();
        }
    }

    private function admin(): User
    {
        $admin = auth()->user();

        abort_unless($admin instanceof User, 403);

        return $admin;
    }
}
