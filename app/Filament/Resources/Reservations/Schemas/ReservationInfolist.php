<?php

namespace App\Filament\Resources\Reservations\Schemas;

use App\Enums\NotificationChannel;
use App\Enums\NotificationLogStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\Payment;
use App\Models\PropertyProfile;
use App\Models\Reservation;
use App\Models\ReservationNight;
use App\Models\ReservationRoom;
use App\Models\ReservationStatusLog;
use App\Services\Reservation\OverlappingRequests;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class ReservationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Callout::make('Other pending requests for these dates')
                    ->description(fn (Reservation $record): HtmlString => self::overlapWarning($record))
                    ->warning()
                    ->visible(fn (Reservation $record): bool => in_array($record->status, [ReservationStatus::Pending, ...ReservationStatus::blockingAvailability()], true)
                        && self::overlapping($record)->isNotEmpty()),
                Callout::make('Transfer proof to verify')
                    ->description('The guest uploaded a transfer proof. Compare it with your bank statement, then use Record payment if the money arrived or Reject proof if it did not. The dates stay held until then.')
                    ->info()
                    ->visible(fn (Reservation $record): bool => $record->pendingPaymentProofs()->exists()),
                Section::make('Stay')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('code')
                            ->copyable(),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (ReservationStatus $state): string => $state->label())
                            ->color(fn (ReservationStatus $state): string => ReservationResource::statusColor($state)),
                        TextEntry::make('source')
                            ->formatStateUsing(fn (ReservationSource $state): string => $state->label()),
                        TextEntry::make('dates')
                            ->state(fn (Reservation $record): string => ReservationResource::stay($record))
                            ->columnSpan(2),
                        TextEntry::make('guests')
                            ->state(fn (Reservation $record): string => trans_choice(':count adult|:count adults', $record->adults)
                                .($record->children === 0 ? '' : ', '.trans_choice(':count child|:count children', $record->children))),
                        TextEntry::make('rooms')
                            ->label('Room')
                            ->state(fn (Reservation $record): array => $record->reservationRooms()->with(['roomType', 'unit'])->get()
                                ->map(fn (ReservationRoom $room): string => $room->roomType->name.' · '.($room->unit === null ? 'no unit yet' : 'unit '.$room->unit->code))
                                ->all())
                            ->listWithLineBreaks(),
                        TextEntry::make('created_at')
                            ->label('Requested')
                            ->dateTime('j M Y H:i')
                            ->timezone(fn (): string => self::timezone()),
                        TextEntry::make('expires_at')
                            ->label('Pay by')
                            ->dateTime('j M Y H:i')
                            ->timezone(fn (): string => self::timezone())
                            ->visible(fn (Reservation $record): bool => $record->status === ReservationStatus::AwaitingPayment),
                        TextEntry::make('confirmed_at')
                            ->label('Confirmed')
                            ->dateTime('j M Y H:i')
                            ->timezone(fn (): string => self::timezone())
                            ->visible(fn (Reservation $record): bool => $record->confirmed_at !== null),
                        TextEntry::make('ota_blocked_at')
                            ->label('Blocked on OTA')
                            ->dateTime('j M Y H:i')
                            ->timezone(fn (): string => self::timezone())
                            ->placeholder('Not yet')
                            ->visible(fn (Reservation $record): bool => $record->ota_blocked_at !== null
                                || in_array($record->status, ReservationStatus::blockingAvailability(), true)),
                        TextEntry::make('external_id')
                            ->label('OTA booking number')
                            ->visible(fn (Reservation $record): bool => $record->external_id !== null),
                        TextEntry::make('reject_reason')
                            ->label('Reason for rejection')
                            ->visible(fn (Reservation $record): bool => $record->reject_reason !== null)
                            ->columnSpanFull(),
                        TextEntry::make('special_requests')
                            ->placeholder('None')
                            ->columnSpanFull(),
                    ]),
                Section::make('Guest')
                    ->description('The contact details given with this reservation.')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('guest_full_name')
                            ->label('Name'),
                        TextEntry::make('guest_email')
                            ->label('Email')
                            ->copyable(),
                        TextEntry::make('guest_phone')
                            ->label('Phone / WhatsApp')
                            ->copyable(),
                        TextEntry::make('guest_country')
                            ->label('Country')
                            ->placeholder('Not given'),
                    ]),
                Section::make('Price')
                    ->columns(3)
                    ->schema([
                        RepeatableEntry::make('nights')
                            ->hiddenLabel()
                            ->state(fn (Reservation $record): Collection => $record->nights()->with('reservationRoom.reservation')->orderBy('night_date')->get())
                            ->table([
                                TableColumn::make('Night'),
                                TableColumn::make('Price'),
                            ])
                            ->schema([
                                TextEntry::make('night_date')
                                    ->date('D j M Y'),
                                TextEntry::make('price')
                                    ->money(fn (ReservationNight $record): string => $record->reservationRoom->reservation->currency),
                            ])
                            ->columnSpanFull(),
                        TextEntry::make('subtotal')
                            ->money(fn (Reservation $record): string => $record->currency),
                        TextEntry::make('tax_total')
                            ->label('Taxes')
                            ->money(fn (Reservation $record): string => $record->currency)
                            ->visible(fn (Reservation $record): bool => (float) $record->tax_total !== 0.0),
                        TextEntry::make('total')
                            ->money(fn (Reservation $record): string => $record->currency)
                            ->weight('bold'),
                        TextEntry::make('policy')
                            ->label('Cancellation policy')
                            ->state(fn (Reservation $record): ?string => $record->policy_snapshot['name'] ?? null)
                            ->placeholder('Not recorded'),
                    ]),
                Section::make('Payments')
                    ->description('Transfer proofs uploaded by the guest appear here as awaiting verification until an admin records the payment or rejects the proof.')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('payment_status')
                            ->label('Payment')
                            ->badge()
                            ->formatStateUsing(fn (ReservationPaymentStatus $state): string => $state->label())
                            ->color(fn (ReservationPaymentStatus $state): string => ReservationResource::paymentStatusColor($state)),
                        TextEntry::make('paid')
                            ->label('Paid so far')
                            ->state(fn (Reservation $record): string => (string) $record->payments()->where('status', PaymentStatus::Paid)->sum('amount'))
                            ->money(fn (Reservation $record): string => $record->currency),
                        RepeatableEntry::make('payments')
                            ->hiddenLabel()
                            ->state(fn (Reservation $record): Collection => $record->payments()->with(['verifier', 'reservation'])->orderBy('paid_at')->get())
                            ->placeholder('No payments recorded yet.')
                            ->table([
                                TableColumn::make('Status'),
                                TableColumn::make('Received'),
                                TableColumn::make('Amount'),
                                TableColumn::make('Method'),
                                TableColumn::make('Reference'),
                                TableColumn::make('Recorded by'),
                                TableColumn::make('Proof'),
                            ])
                            ->schema([
                                TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn (PaymentStatus $state): string => match ($state) {
                                        PaymentStatus::Pending => 'Awaiting verification',
                                        PaymentStatus::Paid => 'Paid',
                                        PaymentStatus::Failed => 'Rejected',
                                        PaymentStatus::Refunded => 'Refunded',
                                        PaymentStatus::Expired => 'Closed',
                                    })
                                    ->color(fn (PaymentStatus $state): string => match ($state) {
                                        PaymentStatus::Paid => 'success',
                                        PaymentStatus::Pending => 'warning',
                                        PaymentStatus::Refunded => 'info',
                                        PaymentStatus::Failed, PaymentStatus::Expired => 'danger',
                                    }),
                                TextEntry::make('paid_at')
                                    ->dateTime('j M Y H:i')
                                    ->timezone(fn (): string => self::timezone())
                                    ->placeholder(fn (Payment $record): string => ReservationResource::proofUploadedLabel($record)),
                                TextEntry::make('amount')
                                    ->money(fn (Payment $record): string => $record->reservation->currency),
                                TextEntry::make('method')
                                    ->formatStateUsing(fn (PaymentMethod $state): string => $state->label()),
                                TextEntry::make('provider_ref')
                                    ->placeholder('—'),
                                TextEntry::make('verifier.name')
                                    ->placeholder('—'),
                                TextEntry::make('proof_url')
                                    ->formatStateUsing(fn (): string => 'View proof')
                                    ->url(fn (Payment $record): ?string => $record->proof_url === null
                                        ? null
                                        : Storage::disk(Payment::PROOF_DISK)->temporaryUrl($record->proof_url, now()->addMinutes(30)))
                                    ->openUrlInNewTab()
                                    ->color('primary')
                                    ->placeholder('—'),
                            ])
                            ->columnSpanFull(),
                    ]),
                Section::make('Status history')
                    ->schema([
                        RepeatableEntry::make('statusLogs')
                            ->hiddenLabel()
                            ->state(fn (Reservation $record): Collection => $record->statusLogs()->with('adminUser')->get())
                            ->table([
                                TableColumn::make('When'),
                                TableColumn::make('Change'),
                                TableColumn::make('By'),
                                TableColumn::make('Note'),
                            ])
                            ->schema([
                                TextEntry::make('created_at')
                                    ->dateTime('j M Y H:i')
                                    ->timezone(fn (): string => self::timezone()),
                                TextEntry::make('change')
                                    ->state(fn (ReservationStatusLog $record): string => $record->from_status === null
                                        ? 'Created as '.mb_strtolower($record->to_status->label())
                                        : $record->from_status->label().' → '.$record->to_status->label()),
                                TextEntry::make('by')
                                    // Only guest requests are created without an admin; later changes without one are system actions.
                                    ->state(fn (ReservationStatusLog $record): string => $record->adminUser->name
                                        ?? ($record->from_status === null ? 'Guest' : 'System')),
                                TextEntry::make('note')
                                    ->placeholder('—'),
                            ]),
                    ]),
                Section::make('Notifications')
                    ->collapsible()
                    ->schema([
                        RepeatableEntry::make('notificationLogs')
                            ->hiddenLabel()
                            ->state(fn (Reservation $record): Collection => $record->notificationLogs()->orderBy('created_at')->get())
                            ->placeholder('No notifications sent yet.')
                            ->table([
                                TableColumn::make('When'),
                                TableColumn::make('Message'),
                                TableColumn::make('Channel'),
                                TableColumn::make('Status'),
                                TableColumn::make('Error'),
                            ])
                            ->schema([
                                TextEntry::make('created_at')
                                    ->dateTime('j M Y H:i')
                                    ->timezone(fn (): string => self::timezone()),
                                TextEntry::make('template')
                                    ->formatStateUsing(fn (string $state): string => str($state)->headline()),
                                TextEntry::make('channel')
                                    ->formatStateUsing(fn (NotificationChannel $state): string => str($state->value)->headline()),
                                TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn (NotificationLogStatus $state): string => str($state->value)->headline())
                                    ->color(fn (NotificationLogStatus $state): string => match ($state) {
                                        NotificationLogStatus::Queued => 'gray',
                                        NotificationLogStatus::Sent => 'success',
                                        NotificationLogStatus::Failed => 'danger',
                                        NotificationLogStatus::Skipped => 'warning',
                                    }),
                                TextEntry::make('error_message')
                                    ->placeholder('—'),
                            ]),
                    ]),
            ]);
    }

    /**
     * Each overlapping request as a link to it, with its guest and stay.
     */
    private static function overlapWarning(Reservation $reservation): HtmlString
    {
        $items = self::overlapping($reservation)
            ->map(fn (Reservation $other): string => '<li><a class="underline" href="'.e(ReservationResource::getUrl('view', ['record' => $other])).'">'
                .e($other->code).'</a> · '.e($other->guest_full_name).' · '.e(ReservationResource::stay($other)).'</li>')
            ->implode('');

        $lead = $reservation->status === ReservationStatus::Pending
            ? 'These requests want the same room type on some of the same nights. Approving one may leave no unit for the others.'
            : 'These requests want the same room type on some of the nights this reservation holds. They can only be approved if another unit is free.';

        return new HtmlString(e($lead).'<ul class="mt-2 list-disc ps-5">'.$items.'</ul>');
    }

    /**
     * @return Collection<int, Reservation>
     */
    private static function overlapping(Reservation $reservation): Collection
    {
        return once(fn (): Collection => app(OverlappingRequests::class)->for($reservation));
    }

    private static function timezone(): string
    {
        return PropertyProfile::current()->timezone;
    }
}
