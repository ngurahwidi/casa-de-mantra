<?php

namespace Database\Seeders;

use App\Enums\NotificationChannel;
use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

class NotificationTemplateSeeder extends Seeder
{
    /**
     * Create the default email templates; existing ones (matched by code + channel) are left untouched.
     *
     * Placeholders use {{ variable }} and are filled by the notification renderer (T7.1):
     * property_name, property_email, property_whatsapp, property_address, checkin_time, checkout_time,
     * guest_name, guest_email, guest_phone, reservation_code, check_in, check_out, nights, adults,
     * children, special_requests, total, status_url, payment_deadline, payment_instructions,
     * reject_reason, cancel_reason, admin_url.
     */
    public function run(): void
    {
        foreach ($this->templates() as $code => [$subject, $body]) {
            NotificationTemplate::firstOrCreate(
                ['code' => $code, 'channel' => NotificationChannel::Email],
                ['subject' => $subject, 'body' => $body, 'is_active' => true],
            );
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    private function templates(): array
    {
        $stay = <<<'TEXT'
            Reservation code: {{ reservation_code }}
            Check-in: {{ check_in }}
            Check-out: {{ check_out }} ({{ nights }} nights)
            Guests: {{ adults }} adults, {{ children }} children
            Total: {{ total }}
            TEXT;

        $signature = <<<'TEXT'
            If you have any questions, reply to this email or message us on WhatsApp at {{ property_whatsapp }}.

            Warm regards,
            {{ property_name }}
            TEXT;

        return [
            'reservation_received' => [
                'We received your booking request {{ reservation_code }}',
                <<<TEXT
                    Dear {{ guest_name }},

                    Thank you for your booking request at {{ property_name }}. Please note that this is not a confirmed booking yet.

                    {$stay}

                    We are checking availability for your dates and will get back to you shortly. If your request is approved, we will send you the payment instructions. Your booking is confirmed only after we have received your payment.

                    You can check the status of your request at any time: {{ status_url }}

                    {$signature}
                    TEXT,
            ],

            'reservation_awaiting_payment' => [
                'Your booking request {{ reservation_code }} is approved: payment required',
                <<<TEXT
                    Dear {{ guest_name }},

                    Good news: your dates at {{ property_name }} are available and your request has been approved.

                    {$stay}

                    To secure your booking, please transfer {{ total }} before {{ payment_deadline }}:

                    {{ payment_instructions }}

                    Please include your reservation code {{ reservation_code }} in the transfer note, then upload your proof of payment here: {{ status_url }}

                    If we do not receive your payment before the deadline, the request will expire and the dates will be released.

                    This booking is non-refundable once paid.

                    {$signature}
                    TEXT,
            ],

            'reservation_confirmed' => [
                'Your booking {{ reservation_code }} is confirmed',
                <<<TEXT
                    Dear {{ guest_name }},

                    We have received your payment and your booking at {{ property_name }} is confirmed.

                    {$stay}

                    Check-in is from {{ checkin_time }} and check-out is until {{ checkout_time }}.
                    Address: {{ property_address }}

                    You can view your booking at any time: {{ status_url }}

                    We look forward to welcoming you.

                    {$signature}
                    TEXT,
            ],

            'reservation_rejected' => [
                'Update on your booking request {{ reservation_code }}',
                <<<TEXT
                    Dear {{ guest_name }},

                    Thank you for your interest in {{ property_name }}. Unfortunately, we are unable to accept your booking request {{ reservation_code }} for {{ check_in }} to {{ check_out }}.

                    Reason: {{ reject_reason }}

                    No payment has been taken. We would be happy to help you find other dates.

                    {$signature}
                    TEXT,
            ],

            'reservation_expired' => [
                'Your booking request {{ reservation_code }} has expired',
                <<<TEXT
                    Dear {{ guest_name }},

                    We did not receive payment for your booking request {{ reservation_code }} at {{ property_name }} before the deadline, so the request has expired and the dates have been released.

                    If you have already made the transfer, or you would still like to stay with us, please contact us as soon as possible and we will check whether the dates are still available.

                    {$signature}
                    TEXT,
            ],

            'reservation_cancelled' => [
                'Your booking {{ reservation_code }} has been cancelled',
                <<<TEXT
                    Dear {{ guest_name }},

                    Your booking {{ reservation_code }} at {{ property_name }} for {{ check_in }} to {{ check_out }} has been cancelled and the dates have been released.

                    Reason: {{ cancel_reason }}

                    This booking was made under a non-refundable policy, so any payment already made will not be refunded.

                    If you did not expect this cancellation, please contact us as soon as possible.

                    {$signature}
                    TEXT,
            ],

            'admin_new_request' => [
                'New booking request {{ reservation_code }}',
                <<<TEXT
                    A new booking request has been submitted.

                    {$stay}

                    Guest: {{ guest_name }}
                    Email: {{ guest_email }}
                    Phone: {{ guest_phone }}
                    Special requests: {{ special_requests }}

                    Please check availability, including the OTA calendars, then approve or reject the request: {{ admin_url }}
                    TEXT,
            ],

            'pre_arrival_reminder' => [
                'See you soon at {{ property_name }}',
                <<<TEXT
                    Dear {{ guest_name }},

                    Your stay at {{ property_name }} is coming up soon.

                    {$stay}

                    Check-in is from {{ checkin_time }} and check-out is until {{ checkout_time }}.
                    Address: {{ property_address }}

                    Please let us know your estimated arrival time, or if you need an airport transfer or anything else before you arrive.

                    {$signature}
                    TEXT,
            ],
        ];
    }
}
