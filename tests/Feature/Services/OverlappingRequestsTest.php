<?php

namespace Tests\Feature\Services;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\RoomType;
use App\Services\Reservation\OverlappingRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OverlappingRequestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_requests_sharing_a_night_of_the_room_type_are_listed_by_check_in(): void
    {
        $roomType = RoomType::factory()->create();
        $reservation = $this->reserve($roomType, '2026-12-24', '2026-12-27');
        $later = $this->reserve($roomType, '2026-12-26', '2026-12-30');
        $earlier = $this->reserve($roomType, '2026-12-20', '2026-12-25');

        $overlapping = app(OverlappingRequests::class)->for($reservation);

        $this->assertSame([$earlier->id, $later->id], $overlapping->modelKeys());
    }

    public function test_stays_that_only_touch_on_the_check_out_day_do_not_overlap(): void
    {
        $roomType = RoomType::factory()->create();
        $reservation = $this->reserve($roomType, '2026-12-24', '2026-12-27');
        $this->reserve($roomType, '2026-12-27', '2026-12-29');
        $this->reserve($roomType, '2026-12-20', '2026-12-24');

        $overlapping = app(OverlappingRequests::class)->for($reservation);

        $this->assertSame([], $overlapping->modelKeys());
    }

    public function test_requests_for_another_room_type_are_left_out(): void
    {
        $reservation = $this->reserve(RoomType::factory()->create(), '2026-12-24', '2026-12-27');
        $this->reserve(RoomType::factory()->create(), '2026-12-24', '2026-12-27');

        $overlapping = app(OverlappingRequests::class)->for($reservation);

        $this->assertSame([], $overlapping->modelKeys());
    }

    /**
     * @return array<string, array{ReservationStatus}>
     */
    public static function statusesOtherThanPending(): array
    {
        return collect(ReservationStatus::cases())
            ->reject(fn (ReservationStatus $status) => $status === ReservationStatus::Pending)
            ->mapWithKeys(fn (ReservationStatus $status) => [$status->value => [$status]])
            ->all();
    }

    #[DataProvider('statusesOtherThanPending')]
    public function test_reservations_that_are_not_pending_are_left_out(ReservationStatus $status): void
    {
        $roomType = RoomType::factory()->create();
        $reservation = $this->reserve($roomType, '2026-12-24', '2026-12-27');
        $this->reserve($roomType, '2026-12-24', '2026-12-27', $status);

        $overlapping = app(OverlappingRequests::class)->for($reservation);

        $this->assertSame([], $overlapping->modelKeys());
    }

    private function reserve(RoomType $roomType, string $checkIn, string $checkOut, ReservationStatus $status = ReservationStatus::Pending): Reservation
    {
        $reservation = Reservation::factory()->create([
            'status' => $status,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
        ]);

        ReservationRoom::factory()->for($reservation)->for($roomType)->create();

        return $reservation;
    }
}
