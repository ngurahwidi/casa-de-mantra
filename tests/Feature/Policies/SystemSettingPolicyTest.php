<?php

namespace Tests\Feature\Policies;

use App\Models\Amenity;
use App\Models\CancellationPolicy;
use App\Models\NotificationTemplate;
use App\Models\PropertyProfile;
use App\Models\RoomType;
use App\Models\TaxFee;
use App\Models\Unit;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SystemSettingPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{class-string<Model>, Closure(): Model}>
     */
    public static function settings(): array
    {
        return [
            'property profile' => [PropertyProfile::class, fn (): Model => PropertyProfile::factory()->create()],
            'tax fee' => [TaxFee::class, fn (): Model => TaxFee::factory()->create()],
            'cancellation policy' => [CancellationPolicy::class, fn (): Model => CancellationPolicy::factory()->create()],
            'notification template' => [NotificationTemplate::class, fn (): Model => NotificationTemplate::factory()->create()],
            'room type' => [RoomType::class, fn (): Model => RoomType::factory()->create()],
            'unit' => [Unit::class, fn (): Model => Unit::factory()->create()],
            'amenity' => [Amenity::class, fn (): Model => Amenity::factory()->create()],
        ];
    }

    /**
     * @param  class-string<Model>  $model
     * @param  Closure(): Model  $createRecord
     */
    #[DataProvider('settings')]
    public function test_owner_may_view_and_change_the_setting(string $model, Closure $createRecord): void
    {
        $owner = User::factory()->owner()->create();
        $record = $createRecord();

        foreach (['viewAny', 'create', 'deleteAny', 'reorder'] as $ability) {
            $this->assertTrue($owner->can($ability, $model), $ability);
        }

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertTrue($owner->can($ability, $record), $ability);
        }
    }

    /**
     * @param  class-string<Model>  $model
     * @param  Closure(): Model  $createRecord
     */
    #[DataProvider('settings')]
    public function test_staff_may_view_but_not_change_the_setting(string $model, Closure $createRecord): void
    {
        $staff = User::factory()->create();
        $record = $createRecord();

        $this->assertTrue($staff->can('viewAny', $model));
        $this->assertTrue($staff->can('view', $record));

        foreach (['create', 'deleteAny', 'reorder'] as $ability) {
            $this->assertFalse($staff->can($ability, $model), $ability);
        }

        foreach (['update', 'delete'] as $ability) {
            $this->assertFalse($staff->can($ability, $record), $ability);
        }
    }
}
