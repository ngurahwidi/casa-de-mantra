<?php

namespace Tests\Feature\Services;

use App\Actions\Reservation\CancelReservation;
use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationStatus;
use App\Mail\ReservationNotificationMail;
use App\Models\NotificationTemplate;
use App\Models\PropertyProfile;
use App\Models\Reservation;
use App\Services\Notification\ReservationTemplateRenderer;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationTemplateRendererTest extends TestCase
{
    use RefreshDatabase;

    public function test_variables_are_formatted_for_the_guest(): void
    {
        $profile = $this->profile();
        $reservation = Reservation::factory()->create([
            'code' => 'ab12cd34',
            'guest_full_name' => 'Jane Guest',
            'check_in' => '2026-12-24',
            'check_out' => '2026-12-27',
            'total' => '4500000.00',
            'currency' => 'IDR',
            'expires_at' => '2026-11-10 06:30:00',
        ]);

        $variables = app(ReservationTemplateRenderer::class)->variables($reservation, $profile);

        $this->assertSame('Jane Guest', $variables['guest_name']);
        $this->assertSame('ab12cd34', $variables['reservation_code']);
        $this->assertSame('24 Dec 2026', $variables['check_in']);
        $this->assertSame('27 Dec 2026', $variables['check_out']);
        $this->assertSame('3', $variables['nights']);
        $this->assertSame('Rp4.500.000', $variables['total']);
        $this->assertSame('14:00', $variables['checkin_time']);
        $this->assertSame('12:00', $variables['checkout_time']);
        $this->assertSame('10 Nov 2026, 14:30 (Asia/Makassar)', $variables['payment_deadline']);
        $this->assertSame(route('booking.status'), $variables['status_url']);
        $this->assertStringEndsWith('/admin/reservations/'.$reservation->id, $variables['admin_url']);
    }

    public function test_payment_instructions_list_only_the_filled_bank_details(): void
    {
        $profile = $this->profile(['bank_account_info' => [
            'bank' => 'Bank Example',
            'account_name' => 'PT Aruna',
            'account_number' => '1234567890',
            'swift_code' => '',
        ]]);

        $variables = app(ReservationTemplateRenderer::class)->variables(Reservation::factory()->create(), $profile);

        $this->assertSame(
            "Bank: Bank Example\nAccount name: PT Aruna\nAccount number: 1234567890",
            $variables['payment_instructions'],
        );
    }

    public function test_the_cancel_reason_leaves_out_the_refund_remark_of_the_status_history(): void
    {
        $profile = $this->profile();
        $reservation = Reservation::factory()->confirmed()->create([
            'status' => ReservationStatus::Cancelled,
            'payment_status' => ReservationPaymentStatus::Paid,
        ]);
        $reservation->statusLogs()->create([
            'from_status' => ReservationStatus::Confirmed,
            'to_status' => ReservationStatus::Cancelled,
            'note' => "Guest changed their plans.\n".CancelReservation::NO_REFUND_NOTE,
        ]);

        $variables = app(ReservationTemplateRenderer::class)->variables($reservation, $profile);

        $this->assertSame('Guest changed their plans.', $variables['cancel_reason']);
    }

    public function test_every_documented_placeholder_has_a_value(): void
    {
        $variables = app(ReservationTemplateRenderer::class)->variables(Reservation::factory()->create(), $this->profile());

        $this->assertEqualsCanonicalizing(NotificationTemplate::PLACEHOLDERS, array_keys($variables));
    }

    public function test_seeded_templates_render_without_leftover_placeholders(): void
    {
        $renderer = app(ReservationTemplateRenderer::class);
        $variables = $renderer->variables(Reservation::factory()->create(), $this->profile());
        $this->seed(NotificationTemplateSeeder::class);

        foreach (NotificationTemplate::all() as $template) {
            $this->assertStringNotContainsString('{{', $renderer->fill($template->subject, $variables), $template->code);
            $this->assertStringNotContainsString('{{', $renderer->fill($template->body, $variables), $template->code);
        }
    }

    public function test_placeholders_accept_loose_spacing_and_unknown_ones_stay_visible(): void
    {
        $filled = app(ReservationTemplateRenderer::class)->fill(
            'Hello {{guest_name}} and {{   guest_name   }}, {{ not_a_variable }}',
            ['guest_name' => 'Jane'],
        );

        $this->assertSame('Hello Jane and Jane, {{ not_a_variable }}', $filled);
    }

    public function test_a_placeholder_typed_by_a_guest_is_not_expanded(): void
    {
        $filled = app(ReservationTemplateRenderer::class)->fill(
            'Request: {{ special_requests }}',
            ['special_requests' => '{{ admin_url }}', 'admin_url' => 'https://secret.example/admin'],
        );

        $this->assertSame('Request: {{ admin_url }}', $filled);
    }

    public function test_guest_input_is_escaped_in_the_html_part_and_links_are_clickable(): void
    {
        $mail = new ReservationNotificationMail(
            subjectLine: 'New request',
            body: "Requests: <script>alert(1)</script>\nOpen https://villa.example/booking/status.",
            propertyName: 'Villa Aruna',
        );

        $mail->assertDontSeeInHtml('<script>alert(1)</script>', false);
        $mail->assertSeeInHtml('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $mail->assertSeeInHtml('<a href="https://villa.example/booking/status"', false);
        $mail->assertSeeInText('<script>alert(1)</script>');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function profile(array $attributes = []): PropertyProfile
    {
        PropertyProfile::factory()->create($attributes + [
            'timezone' => 'Asia/Makassar',
            'checkin_time' => '14:00:00',
            'checkout_time' => '12:00:00',
        ]);

        return PropertyProfile::current();
    }
}
