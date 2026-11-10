<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * An email rendered from a notification template.
 *
 * It is sent inside SendReservationNotification, which is what puts it on the queue.
 */
class ReservationNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $body,
        public readonly string $propertyName,
        public readonly ?Address $sender = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->sender,
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reservation-notification',
            text: 'emails.reservation-notification-text',
            with: ['bodyHtml' => $this->bodyHtml()],
        );
    }

    /**
     * The body escaped for HTML, with its web addresses as links.
     */
    private function bodyHtml(): string
    {
        return (string) preg_replace(
            '~https?://[^\s<]*[^\s<.,;:!?)]~',
            '<a href="$0" style="color:#2F3B2B;">$0</a>',
            e($this->body),
        );
    }
}
