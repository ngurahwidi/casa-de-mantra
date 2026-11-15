<?php

namespace Tests\Feature\Policies;

use App\Models\Reservation;
use App\Models\ReservationSource;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Reservation sources are daily work, so owner and staff have the same abilities.
 */
class ReservationSourcePolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{Closure(): User}>
     */
    public static function admins(): array
    {
        return [
            'owner' => [fn (): User => User::factory()->owner()->create()],
            'staff' => [fn (): User => User::factory()->create()],
        ];
    }

    /**
     * @param  Closure(): User  $createAdmin
     */
    #[DataProvider('admins')]
    public function test_admin_may_add_change_and_delete_an_unused_source(Closure $createAdmin): void
    {
        $admin = $createAdmin();
        $source = ReservationSource::factory()->create();

        $this->assertTrue($admin->can('viewAny', ReservationSource::class));
        $this->assertTrue($admin->can('create', ReservationSource::class));
        $this->assertTrue($admin->can('view', $source));
        $this->assertTrue($admin->can('update', $source));
        $this->assertTrue($admin->can('delete', $source));
    }

    /**
     * @param  Closure(): User  $createAdmin
     */
    #[DataProvider('admins')]
    public function test_admin_may_change_but_not_delete_a_source_with_reservations(Closure $createAdmin): void
    {
        $admin = $createAdmin();
        $source = ReservationSource::factory()->create();
        Reservation::factory()->create(['reservation_source_id' => $source]);

        $this->assertTrue($admin->can('update', $source));
        $this->assertFalse($admin->can('delete', $source));
    }

    /**
     * @param  Closure(): User  $createAdmin
     */
    #[DataProvider('admins')]
    public function test_admin_may_neither_change_nor_delete_the_website_source(Closure $createAdmin): void
    {
        $admin = $createAdmin();
        $website = ReservationSource::website();

        $this->assertTrue($admin->can('view', $website));
        $this->assertFalse($admin->can('update', $website));
        $this->assertFalse($admin->can('delete', $website));
    }

    /**
     * @param  Closure(): User  $createAdmin
     */
    #[DataProvider('admins')]
    public function test_admin_may_not_delete_sources_in_bulk(Closure $createAdmin): void
    {
        $this->assertFalse($createAdmin()->can('deleteAny', ReservationSource::class));
    }
}
