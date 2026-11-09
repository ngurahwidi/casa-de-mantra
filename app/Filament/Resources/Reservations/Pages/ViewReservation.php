<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Actions\Reservation\ApproveReservation;
use App\Actions\Reservation\CancelReservation;
use App\Actions\Reservation\CompleteReservation;
use App\Actions\Reservation\MarkOtaBlocked;
use App\Actions\Reservation\ReactivateReservation;
use App\Actions\Reservation\RecordPayment;
use App\Actions\Reservation\RejectPaymentProof;
use App\Actions\Reservation\RejectReservation;
use App\Data\Reservation\PaymentDetails;
use App\Enums\PaymentMethod;
use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\Reservation\ReservationException;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\Payment;
use App\Models\PropertyProfile;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * One reservation with the actions its status allows. Every action only collects the admin's
 * input and calls the Fase 4 action; when that refuses (e.g. the dates were taken meanwhile),
 * its message is shown and nothing changes.
 */
class ViewReservation extends ViewRecord
{
    protected static string $resource = ReservationResource::class;

    public function getTitle(): string
    {
        return 'Reservation '.$this->reservation()->code;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve & request payment')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(fn (): bool => $this->hasStatus(ReservationStatus::Pending))
                ->modalHeading('Approve and request payment')
                ->modalDescription(fn (): string => 'The dates are checked again and a free unit is held for the guest, who then has '
                    .trans_choice(':count hour|:count hours', PropertyProfile::current()->payment_window_hours).' to pay.')
                ->modalSubmitActionLabel('Approve')
                ->schema([
                    Textarea::make('note')
                        ->helperText('Kept in the status history.')
                        ->rows(2),
                ])
                ->action(fn (array $data, Action $action) => $this->run(
                    $action,
                    'Not approved',
                    'Reservation approved',
                    fn () => app(ApproveReservation::class)->handle($this->reservation(), $this->admin(), $data['note'] ?? null),
                )),
            Action::make('reject')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible(fn (): bool => $this->hasStatus(ReservationStatus::Pending))
                ->modalHeading('Reject this request')
                ->modalSubmitActionLabel('Reject')
                ->schema([
                    Textarea::make('reason')
                        ->helperText('May be shown to the guest.')
                        ->required()
                        ->rows(3),
                ])
                ->action(fn (array $data, Action $action) => $this->run(
                    $action,
                    'Not rejected',
                    'Request rejected',
                    fn () => app(RejectReservation::class)->handle($this->reservation(), $this->admin(), $data['reason']),
                )),
            Action::make('recordPayment')
                ->label('Record payment')
                ->icon(Heroicon::OutlinedBanknotes)
                // Confirmed reservations take the balance after a deposit, e.g. one entered by hand unpaid.
                ->visible(fn (): bool => $this->hasStatus(ReservationStatus::AwaitingPayment)
                    || ($this->hasStatus(ReservationStatus::Confirmed) && $this->reservation()->payment_status !== ReservationPaymentStatus::Paid))
                ->modalDescription(fn (): string => 'Total '.$this->reservation()->currency.' '.number_format((float) $this->reservation()->total, 2).'.')
                ->fillForm(fn (): array => [
                    'guest_proof' => array_key_first($this->pendingProofOptions()),
                    'amount' => $this->reservation()->payment_status === ReservationPaymentStatus::Unpaid ? $this->reservation()->total : null,
                    'method' => PaymentMethod::BankTransfer->value,
                    'paid_at' => now(),
                ])
                ->schema([
                    Select::make('guest_proof')
                        ->label('Guest transfer proof')
                        ->options(fn (): array => $this->pendingProofOptions())
                        ->placeholder('Not from a guest proof')
                        ->helperText('The proof this payment confirms. Open it from the Payments section and check it against your bank statement first.')
                        ->live()
                        ->visible(fn (): bool => $this->pendingProofOptions() !== []),
                    TextInput::make('amount')
                        ->prefix(fn (): string => $this->reservation()->currency)
                        ->required()
                        ->numeric()
                        ->minValue(0.01)
                        ->maxValue(999999999999.99),
                    Select::make('method')
                        ->options(collect(PaymentMethod::cases())->mapWithKeys(fn (PaymentMethod $method): array => [$method->value => $method->label()]))
                        ->required(),
                    DateTimePicker::make('paid_at')
                        ->label('Received at')
                        ->native(false)
                        ->displayFormat('j M Y H:i')
                        ->seconds(false)
                        ->timezone(fn (): string => PropertyProfile::current()->timezone)
                        ->required(),
                    TextInput::make('reference')
                        ->label('Transfer reference')
                        ->maxLength(191),
                    FileUpload::make('proof')
                        ->label('Transfer proof')
                        ->disk(Payment::PROOF_DISK)
                        ->directory(Payment::PROOF_DIRECTORY)
                        ->visibility('private')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                        ->maxSize(5120)
                        ->helperText('JPG, PNG, WebP or PDF, up to 5 MB.')
                        ->hidden(fn (Get $get): bool => filled($get('guest_proof'))),
                ])
                ->action(function (array $data, Action $action): void {
                    $this->recordPayment($data, $action);
                }),
            Action::make('rejectProof')
                ->label('Reject proof')
                ->icon(Heroicon::OutlinedDocumentMagnifyingGlass)
                ->color('danger')
                ->visible(fn (): bool => $this->pendingProofOptions() !== [])
                ->modalHeading('Reject transfer proof')
                ->modalDescription('The guest is told the proof could not be verified and can upload another. Without a proof waiting, a reservation past its payment deadline expires on the next run.')
                ->modalSubmitActionLabel('Reject proof')
                ->fillForm(fn (): array => ['proof' => array_key_first($this->pendingProofOptions())])
                ->schema([
                    Select::make('proof')
                        ->label('Transfer proof')
                        ->options(fn (): array => $this->pendingProofOptions())
                        ->required(),
                ])
                ->action(fn (array $data, Action $action) => $this->run(
                    $action,
                    'Proof not rejected',
                    'Transfer proof rejected',
                    fn () => app(RejectPaymentProof::class)->handle($this->reservation(), $data['proof'], $this->admin()),
                )),
            Action::make('otaBlocked')
                ->label(fn (): string => $this->reservation()->ota_blocked_at === null ? 'Mark as blocked on OTA' : 'Clear "Blocked on OTA"')
                ->icon(fn (): Heroicon => $this->reservation()->ota_blocked_at === null ? Heroicon::OutlinedLockClosed : Heroicon::OutlinedLockOpen)
                ->color('gray')
                ->visible(fn (): bool => $this->reservation()->ota_blocked_at !== null
                    || in_array($this->reservation()->status, ReservationStatus::blockingAvailability(), true))
                ->requiresConfirmation()
                ->modalHeading(fn (): string => $this->reservation()->ota_blocked_at === null ? 'Blocked on OTA' : 'Clear "Blocked on OTA"')
                ->modalDescription(fn (): string => $this->reservation()->ota_blocked_at === null
                    ? 'Confirm that these dates are closed on the Booking.com and Expedia calendars.'
                    : 'Use this when the dates are open again on the OTA calendars.')
                ->action(fn (Action $action) => $this->run(
                    $action,
                    'Not changed',
                    fn (): string => $this->reservation()->ota_blocked_at === null ? '"Blocked on OTA" cleared' : 'Marked as blocked on OTA',
                    fn () => app(MarkOtaBlocked::class)->handle($this->reservation(), $this->reservation()->ota_blocked_at === null),
                )),
            Action::make('complete')
                ->label('Mark as completed')
                ->icon(Heroicon::OutlinedFlag)
                ->color('gray')
                ->visible(fn (): bool => $this->hasStatus(ReservationStatus::Confirmed)
                    && $this->reservation()->check_out->toDateString() < now(PropertyProfile::current()->timezone)->toDateString())
                ->requiresConfirmation()
                ->modalDescription('The guest has checked out.')
                ->action(fn (Action $action) => $this->run(
                    $action,
                    'Not completed',
                    'Reservation completed',
                    fn () => app(CompleteReservation::class)->handle($this->reservation(), $this->admin()),
                )),
            Action::make('reactivate')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (): bool => $this->hasStatus(ReservationStatus::Expired))
                ->modalHeading('Reactivate this reservation')
                ->modalDescription(fn (): string => 'The dates are checked again and, if still free, the guest has another '
                    .trans_choice(':count hour|:count hours', PropertyProfile::current()->payment_window_hours).' to pay.')
                ->modalSubmitActionLabel('Reactivate')
                ->schema([
                    Textarea::make('note')
                        ->helperText('Kept in the status history.')
                        ->rows(2),
                ])
                ->action(fn (array $data, Action $action) => $this->run(
                    $action,
                    'Not reactivated',
                    'Reservation reactivated',
                    fn () => app(ReactivateReservation::class)->handle($this->reservation(), $this->admin(), $data['note'] ?? null),
                )),
            Action::make('cancel')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->visible(fn (): bool => in_array($this->reservation()->status, [ReservationStatus::Pending, ...ReservationStatus::blockingAvailability()], true))
                ->modalHeading('Cancel this reservation')
                ->modalDescription(fn (): string => $this->reservation()->payment_status === ReservationPaymentStatus::Unpaid
                    ? 'The dates are released.'
                    : 'The dates are released. The rate is non-refundable, so nothing is refunded; record an exceptional refund by hand.')
                ->modalSubmitActionLabel('Cancel reservation')
                ->schema([
                    Textarea::make('reason')
                        ->helperText('Kept in the status history.')
                        ->required()
                        ->rows(3),
                ])
                ->action(fn (array $data, Action $action) => $this->run(
                    $action,
                    'Not cancelled',
                    'Reservation cancelled',
                    fn () => app(CancelReservation::class)->handle($this->reservation(), $this->admin(), $data['reason']),
                )),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function recordPayment(array $data, Action $action): void
    {
        $details = new PaymentDetails(
            amount: (string) $data['amount'],
            method: PaymentMethod::from($data['method']),
            paidAt: CarbonImmutable::parse($data['paid_at']),
            reference: $data['reference'] ?? null,
            proofPath: $data['proof'] ?? null,
            guestProofId: $data['guest_proof'] ?? null,
        );

        $wasAwaitingPayment = $this->hasStatus(ReservationStatus::AwaitingPayment);

        try {
            $recorded = app(RecordPayment::class)->handle($this->reservation(), $this->admin(), $details);
        } catch (ReservationException $exception) {
            Notification::make()->danger()->title('Payment not recorded')->body($exception->getMessage())->send();

            $action->halt();

            return;
        }

        $this->reservation()->refresh();

        if ($recorded->confirmationFailure !== null) {
            Notification::make()
                ->warning()
                ->title('Payment recorded, reservation not confirmed')
                ->body($recorded->confirmationFailure->getMessage())
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title($wasAwaitingPayment && $recorded->isConfirmed() ? 'Payment recorded, reservation confirmed' : 'Payment recorded')
            ->send();
    }

    /**
     * Runs a reservation action, telling the admin why when it is refused.
     *
     * @param  string|Closure(): string  $success  the title shown afterwards, worked out after the change
     * @param  Closure(): mixed  $callback
     */
    private function run(Action $action, string $failure, string|Closure $success, Closure $callback): void
    {
        try {
            $callback();
        } catch (ReservationException $exception) {
            Notification::make()->danger()->title($failure)->body($exception->getMessage())->send();

            $action->halt();
        }

        $this->reservation()->refresh();

        Notification::make()->success()->title(value($success))->send();
    }

    /**
     * The guest's transfer proofs still waiting for an admin, newest first.
     *
     * @return array<string, string> payment id => when it was uploaded
     */
    private function pendingProofOptions(): array
    {
        return $this->reservation()->pendingPaymentProofs()->latest()->get()
            ->mapWithKeys(fn (Payment $proof): array => [$proof->id => ReservationResource::proofUploadedLabel($proof)])
            ->all();
    }

    private function hasStatus(ReservationStatus $status): bool
    {
        return $this->reservation()->status === $status;
    }

    private function reservation(): Reservation
    {
        /** @var Reservation */
        return $this->getRecord();
    }

    private function admin(): User
    {
        $admin = auth()->user();

        abort_unless($admin instanceof User, 403);

        return $admin;
    }
}
