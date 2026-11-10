<?php

namespace App\Services\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationLogStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Jobs\SendReservationNotification;
use App\Mail\ReservationNotificationMail;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\PropertyProfile;
use App\Models\Reservation;
use App\Models\ReservationStatusLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Emails the guest and the property about a reservation, using notification_templates.
 *
 * Every email is one notification_logs row: queued when it is decided, then sent or failed. The
 * row's dedupe_key ties it to the status change that caused it, so handling the same change twice
 * creates one email, while a later change to the same status (a reactivation) creates another.
 *
 * Only reservations made on the website are notified. Bookings entered by an admin were arranged
 * by phone or on an OTA, which talks to the guest itself, and they are often still unpaid, so the
 * reservation_confirmed wording ("we have received your payment") would be wrong for them.
 *
 * An inactive template is a deliberate choice and sends nothing. A missing one is a configuration
 * error, so it is logged as failed for an admin to notice.
 */
class ReservationNotifier
{
    public function __construct(private readonly ReservationTemplateRenderer $renderer) {}

    public function queueForStatusChange(Reservation $reservation, ReservationStatusLog $statusLog): void
    {
        if ($reservation->source !== ReservationSource::DirectWeb) {
            return;
        }

        foreach (self::templatesFor($statusLog) as $code) {
            $this->queue($reservation, $code, implode(':', [
                $statusLog->getKey(),
                NotificationChannel::Email->value,
                $code,
            ]));
        }
    }

    /**
     * Sends a queued notification once.
     *
     * The row stays locked while sending, so a second worker holding the same job waits and then
     * finds it already sent. An exception leaves it queued for the job's next attempt.
     *
     * @throws RuntimeException when the notification has no recipient
     */
    public function deliver(NotificationLog $notificationLog): void
    {
        DB::transaction(function () use ($notificationLog): void {
            $locked = NotificationLog::query()->whereKey($notificationLog->getKey())->lockForUpdate()->first();

            if ($locked?->status !== NotificationLogStatus::Queued) {
                return;
            }

            $template = NotificationTemplate::query()
                ->where('code', $locked->template)
                ->where('channel', $locked->channel)
                ->firstOrFail();
            $reservation = $locked->reservation()->firstOrFail();
            $profile = PropertyProfile::current();
            $recipient = self::recipient($locked->template, $reservation, $profile);

            if (blank($recipient)) {
                throw new RuntimeException("No recipient email address for {$locked->template}.");
            }

            $variables = $this->renderer->variables($reservation, $profile);

            Mail::to($recipient)->send(new ReservationNotificationMail(
                subjectLine: Str::squish($this->renderer->fill((string) $template->subject, $variables)),
                body: $this->renderer->fill($template->body, $variables),
                propertyName: $profile->name,
                sender: $profile->mailSender(),
            ));

            $locked->update([
                'status' => NotificationLogStatus::Sent,
                'sent_at' => now(),
            ]);
        });
    }

    /**
     * Marks a notification that ran out of attempts; one already sent stays sent.
     */
    public function recordFailure(NotificationLog $notificationLog, string $error): void
    {
        NotificationLog::query()
            ->whereKey($notificationLog->getKey())
            ->where('status', NotificationLogStatus::Queued)
            ->update([
                'status' => NotificationLogStatus::Failed,
                'error_message' => Str::limit($error, 1000),
            ]);
    }

    /**
     * @return list<string>
     */
    private static function templatesFor(ReservationStatusLog $statusLog): array
    {
        if ($statusLog->from_status === null) {
            return ['reservation_received', 'admin_new_request'];
        }

        return match ($statusLog->to_status) {
            ReservationStatus::AwaitingPayment => ['reservation_awaiting_payment'],
            ReservationStatus::Confirmed => ['reservation_confirmed'],
            ReservationStatus::Rejected => ['reservation_rejected'],
            ReservationStatus::Expired => ['reservation_expired'],
            ReservationStatus::Cancelled => ['reservation_cancelled'],
            default => [],
        };
    }

    private function queue(Reservation $reservation, string $code, string $dedupeKey): void
    {
        $template = NotificationTemplate::query()
            ->where('code', $code)
            ->where('channel', NotificationChannel::Email)
            ->first();

        if ($template?->is_active === false) {
            return;
        }

        $notificationLog = NotificationLog::query()->createOrFirst(
            ['dedupe_key' => $dedupeKey],
            [
                'reservation_id' => $reservation->getKey(),
                'channel' => NotificationChannel::Email,
                'template' => $code,
                'status' => $template === null ? NotificationLogStatus::Failed : NotificationLogStatus::Queued,
                'error_message' => $template === null ? "Notification template {$code} does not exist." : null,
            ],
        );

        if ($notificationLog->wasRecentlyCreated && $template !== null) {
            SendReservationNotification::dispatch($notificationLog);
        }
    }

    /**
     * Templates named admin_* go to the property inbox, the others to the guest.
     */
    private static function recipient(string $code, Reservation $reservation, PropertyProfile $profile): ?string
    {
        return str_starts_with($code, 'admin_') ? $profile->email : $reservation->guest_email;
    }
}
