<?php

namespace Tests\Feature\Models;

use App\Models\Amenity;
use App\Models\Payment;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationNight;
use App\Models\ReservationRoom;
use App\Models\ReservationStatusLog;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

class ModelFactoriesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{class-string<Model>}>
     */
    public static function models(): array
    {
        $models = [];

        foreach (glob(dirname(__DIR__, 3).'/app/Models/*.php') as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            $models[class_basename($class)] = [$class];
        }

        return $models;
    }

    /**
     * @param  class-string<Model>  $class
     */
    #[DataProvider('models')]
    public function test_every_model_has_a_working_factory(string $class): void
    {
        $model = Factory::factoryForModel($class)->createOne();

        $this->assertTrue($model->exists);
        $this->assertNotNull($model->getKey());
        $this->assertDatabaseCount($model->getTable(), 1);
    }

    /**
     * @param  class-string<Model>  $class
     */
    #[DataProvider('models')]
    public function test_every_declared_relation_resolves_on_a_persisted_model(string $class): void
    {
        $model = Factory::factoryForModel($class)->createOne();
        $resolved = [];

        foreach ((new \ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $returnType = $method->getReturnType();

            if ($method->class !== $class
                || $method->getNumberOfParameters() > 0
                || ! $returnType instanceof ReflectionNamedType
                || ! is_subclass_of($returnType->getName(), Relation::class)) {
                continue;
            }

            // Fails if a foreign key / table named by the relation does not exist.
            $model->{$method->getName()}()->get();
            $resolved[] = $method->getName();
        }

        $this->assertSame(array_unique($resolved), $resolved);
    }

    public function test_every_application_table_is_covered_by_a_model_or_a_known_exception(): void
    {
        $covered = collect(self::models())
            ->map(fn (array $args) => (new $args[0])->getTable())
            ->all();

        // Framework/package tables and the junction table, which have no model of their own.
        $withoutModel = [
            'migrations', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks',
            'jobs', 'job_batches', 'failed_jobs', 'room_type_amenity',
        ];

        // `media` is Spatie's Media model, used through RoomType::GALLERY.
        $withoutModel[] = 'media';

        $uncovered = array_diff(Schema::getTableListing(schemaQualified: false), $covered, $withoutModel);

        $this->assertSame([], array_values($uncovered), 'Tables without an Eloquent model.');
    }

    public function test_reservation_graph_can_be_built_from_factories(): void
    {
        $reservation = Reservation::factory()->create();
        $room = ReservationRoom::factory()->for($reservation)->create();
        ReservationNight::factory()->for($room, 'reservationRoom')->create();

        $this->assertCount(1, $reservation->reservationRooms);
        $this->assertCount(1, $reservation->nights);
        $this->assertTrue($room->ratePlan->is(RatePlan::find($room->rate_plan_id)));
        $this->assertCount(1, $room->ratePlan->reservationRooms);
    }

    public function test_user_exposes_status_logs_and_verified_payments(): void
    {
        $admin = User::factory()->owner()->create();

        ReservationStatusLog::factory()->create(['admin_user_id' => $admin->id]);
        Payment::factory()->create(['verified_by' => $admin->id]);

        $this->assertCount(1, $admin->reservationStatusLogs);
        $this->assertCount(1, $admin->verifiedPayments);
    }

    public function test_room_type_amenities_are_linked_through_the_junction_table(): void
    {
        $roomType = RoomType::factory()->create();
        $amenity = Amenity::factory()->create();

        $roomType->amenities()->attach($amenity);

        $this->assertTrue($amenity->roomTypes->first()->is($roomType));
    }
}
