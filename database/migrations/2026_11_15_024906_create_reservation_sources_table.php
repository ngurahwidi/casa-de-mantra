<?php

use App\Enums\ReservationSourceCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * The sources reservations had as an enum, keyed by the code that was stored in
     * reservations.source and is kept as the source's code.
     *
     * @var array<string, array{string, ReservationSourceCategory}>
     */
    private const DEFAULT_SOURCES = [
        'direct_web' => ['Website', ReservationSourceCategory::Website],
        'booking_com' => ['Booking.com', ReservationSourceCategory::Ota],
        'expedia' => ['Expedia', ReservationSourceCategory::Ota],
        'manual' => ['Phone', ReservationSourceCategory::Direct],
    ];

    /**
     * Reservation sources become a table admins maintain, instead of a fixed enum. Every
     * reservation keeps its source; a source with reservations cannot be deleted.
     */
    public function up(): void
    {
        Schema::create('reservation_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100)->unique();
            // Derived from the name on creation and never changed; used as reservations.external_source.
            $table->string('code', 50)->unique();
            $table->string('category', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $categories = collect(ReservationSourceCategory::cases())->map(fn (ReservationSourceCategory $case) => "'{$case->value}'")->implode(', ');
        DB::statement("ALTER TABLE reservation_sources ADD CONSTRAINT reservation_sources_category_check CHECK (category IN ({$categories}))");

        DB::table('reservation_sources')->insert(collect(self::DEFAULT_SOURCES)
            ->map(fn (array $source, string $code): array => [
                'id' => (string) Str::uuid7(),
                'name' => $source[0],
                'code' => $code,
                'category' => $source[1]->value,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->values()
            ->all());

        Schema::table('reservations', function (Blueprint $table) {
            $table->uuid('reservation_source_id')->nullable()->after('status');
        });

        DB::statement('UPDATE reservations JOIN reservation_sources ON reservation_sources.code = reservations.source SET reservations.reservation_source_id = reservation_sources.id');

        DB::statement('ALTER TABLE reservations DROP CHECK reservations_source_check');

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('source');
            $table->uuid('reservation_source_id')->nullable(false)->change();
            $table->foreign('reservation_source_id')->references('id')->on('reservation_sources')->restrictOnDelete();
            $table->index('reservation_source_id');
        });
    }

    /**
     * Reverse the migrations. Sources added by admins have no place in the enum; their
     * reservations get "manual".
     */
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('source', 20)->default('direct_web')->after('status');
        });

        $codes = collect(array_keys(self::DEFAULT_SOURCES))->map(fn (string $code) => "'{$code}'")->implode(', ');

        DB::statement("UPDATE reservations JOIN reservation_sources ON reservation_sources.id = reservations.reservation_source_id SET reservations.source = CASE WHEN reservation_sources.code IN ({$codes}) THEN reservation_sources.code ELSE 'manual' END");
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_source_check CHECK (source IN ({$codes}))");

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['reservation_source_id']);
            $table->dropIndex(['reservation_source_id']);
            $table->dropColumn('reservation_source_id');
        });

        Schema::dropIfExists('reservation_sources');
    }
};
