<?php

namespace Tests\Feature\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationLogStatus;
use App\Enums\OutboxEventStatus;
use App\Models\Faq;
use App\Models\IntegrationMapping;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\OutboxEvent;
use App\Models\Page;
use App\Models\Reservation;
use App\Models\Review;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupportTablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_keys_are_uuids(): void
    {
        $this->assertTrue(Str::isUuid(Page::factory()->create()->id));
        $this->assertTrue(Str::isUuid(Faq::factory()->create()->id));
        $this->assertTrue(Str::isUuid(Review::factory()->create()->id));
        $this->assertTrue(Str::isUuid(NotificationTemplate::factory()->create()->id));
        $this->assertTrue(Str::isUuid(NotificationLog::factory()->create()->id));
        $this->assertTrue(Str::isUuid(IntegrationMapping::factory()->create()->id));
        $this->assertTrue(Str::isUuid(OutboxEvent::factory()->create()->id));
    }

    public function test_page_defaults_to_unpublished_and_slug_is_unique(): void
    {
        $page = Page::create(['slug' => 'about', 'title' => 'About'])->fresh();

        $this->assertFalse($page->is_published);
        $this->assertNull($page->body);

        $this->assertThrowsQuery(fn () => Page::factory()->create(['slug' => 'about']));
    }

    public function test_published_scope_on_pages_and_reviews(): void
    {
        $page = Page::factory()->create();
        Page::factory()->unpublished()->create();
        $review = Review::factory()->create();
        Review::factory()->unpublished()->create();

        $this->assertSame([$page->id], Page::published()->pluck('id')->all());
        $this->assertSame([$review->id], Review::published()->pluck('id')->all());
    }

    public function test_faqs_are_ordered_by_sort_order(): void
    {
        $second = Faq::factory()->create(['sort_order' => 2]);
        $first = Faq::factory()->create(['sort_order' => 1]);

        $this->assertSame([$first->id, $second->id], Faq::ordered()->pluck('id')->all());
        $this->assertSame(0, Faq::factory()->create()->fresh()->sort_order);
    }

    public function test_review_rating_must_be_between_one_and_five(): void
    {
        $this->assertSame(5, Review::factory()->create(['rating' => 5])->fresh()->rating);

        $this->assertThrowsQuery(fn () => Review::factory()->create(['rating' => 0]));
        $this->assertThrowsQuery(fn () => Review::factory()->create(['rating' => 6]));
    }

    public function test_notification_template_casts_and_active_scope(): void
    {
        $template = NotificationTemplate::create([
            'code' => 'reservation_received',
            'channel' => NotificationChannel::Email,
            'body' => 'Hello {{ guest_name }}',
        ])->fresh();

        $this->assertSame(NotificationChannel::Email, $template->channel);
        $this->assertTrue($template->is_active);
        $this->assertNull($template->subject);

        $inactive = NotificationTemplate::factory()->inactive()->create();

        $this->assertSame([$template->id], NotificationTemplate::active()->pluck('id')->all());
        $this->assertFalse($inactive->fresh()->is_active);
    }

    public function test_notification_template_code_is_unique_per_channel(): void
    {
        NotificationTemplate::factory()->create(['code' => 'reservation_received']);
        NotificationTemplate::factory()->create(['code' => 'reservation_confirmed']);

        $this->assertThrowsQuery(fn () => NotificationTemplate::factory()->create([
            'code' => 'reservation_received',
            'channel' => NotificationChannel::Email,
        ]));
    }

    public function test_notification_log_defaults_casts_and_relations(): void
    {
        $reservation = Reservation::factory()->create();
        $log = NotificationLog::create([
            'reservation_id' => $reservation->id,
            'channel' => NotificationChannel::Email,
            'template' => 'reservation_received',
        ])->fresh();

        $this->assertSame(NotificationLogStatus::Queued, $log->status);
        $this->assertSame(NotificationChannel::Email, $log->channel);
        $this->assertNull($log->error_message);
        $this->assertNull($log->sent_at);
        $this->assertTrue($log->reservation->is($reservation));
        $this->assertTrue($reservation->notificationLogs->first()->is($log));

        $sent = NotificationLog::factory()->sent()->create()->fresh();
        $failed = NotificationLog::factory()->failed()->create()->fresh();

        $this->assertInstanceOf(Carbon::class, $sent->sent_at);
        $this->assertSame(NotificationLogStatus::Failed, $failed->status);
        $this->assertNotNull($failed->error_message);
    }

    public function test_notification_logs_are_removed_with_their_reservation(): void
    {
        $log = NotificationLog::factory()->create();

        $log->reservation->delete();

        $this->assertDatabaseMissing('notification_logs', ['id' => $log->id]);
    }

    public function test_notification_check_constraints_reject_unknown_values(): void
    {
        $template = NotificationTemplate::factory()->create();
        $log = NotificationLog::factory()->create();

        $this->assertThrowsQuery(fn () => DB::table('notification_templates')->where('id', $template->id)->update(['channel' => 'pigeon']));
        $this->assertThrowsQuery(fn () => DB::table('notification_logs')->where('id', $log->id)->update(['channel' => 'pigeon']));
        $this->assertThrowsQuery(fn () => DB::table('notification_logs')->where('id', $log->id)->update(['status' => 'delivered']));
    }

    public function test_integration_mapping_casts_and_uniqueness(): void
    {
        $internalId = (string) Str::uuid7();
        $mapping = IntegrationMapping::create([
            'entity_type' => 'reservation',
            'internal_id' => $internalId,
            'provider' => 'booking_com',
            'external_id' => 'BK-1',
            'last_synced_at' => '2026-12-01 10:00:00',
        ])->fresh();

        $this->assertInstanceOf(Carbon::class, $mapping->last_synced_at);

        // One external counterpart per entity and provider...
        $this->assertThrowsQuery(fn () => IntegrationMapping::factory()->create([
            'entity_type' => 'reservation',
            'internal_id' => $internalId,
            'provider' => 'booking_com',
        ]));

        // ...but the same entity can be mapped at another provider.
        IntegrationMapping::factory()->create([
            'entity_type' => 'reservation',
            'internal_id' => $internalId,
            'provider' => 'expedia',
        ]);

        $this->assertSame(2, IntegrationMapping::where('internal_id', $internalId)->count());
    }

    public function test_outbox_event_defaults_casts_and_pending_scope(): void
    {
        $event = OutboxEvent::create([
            'type' => 'reservation.created',
            'payload' => ['reservation_id' => 'abc', 'total' => '4500000.00'],
        ])->fresh();

        $this->assertSame(OutboxEventStatus::Pending, $event->status);
        // MySQL stores json with its own key order, so compare without regard to order.
        $this->assertEqualsCanonicalizing(['reservation_id' => 'abc', 'total' => '4500000.00'], $event->payload);
        $this->assertNull($event->processed_at);

        $processed = OutboxEvent::factory()->processed()->create()->fresh();

        $this->assertSame(OutboxEventStatus::Processed, $processed->status);
        $this->assertInstanceOf(Carbon::class, $processed->processed_at);
        $this->assertSame([$event->id], OutboxEvent::pending()->pluck('id')->all());
    }

    public function test_outbox_status_check_constraint_rejects_unknown_values(): void
    {
        $event = OutboxEvent::factory()->create();

        $this->assertThrowsQuery(fn () => DB::table('outbox_events')->where('id', $event->id)->update(['status' => 'done']));
    }

    private function assertThrowsQuery(callable $callback): void
    {
        try {
            $callback();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected the database to reject the statement.');
    }
}
