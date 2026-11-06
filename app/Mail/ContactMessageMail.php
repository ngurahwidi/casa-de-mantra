<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A message sent from the public contact form to the property inbox.
 *
 * The sender's address is used as reply-to so the admin can reply directly.
 */
class ContactMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $senderName,
        public readonly string $senderEmail,
        public readonly ?string $senderPhone,
        public readonly string $messageBody,
        public readonly ?string $villa,
        public readonly string $propertyName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New enquiry from '.$this->senderName,
            replyTo: [new Address($this->senderEmail, $this->senderName)],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-message',
        );
    }
}
