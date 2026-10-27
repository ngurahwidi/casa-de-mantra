<?php

use App\Enums\AvailabilityBlockSource;
use App\Enums\IcalChannel;
use App\Enums\IcalDirection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ical_feeds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('room_type_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('direction', 20);
            // Only set for import feeds (the OTA calendar we pull from).
            $table->text('url')->nullable();
            // Only set for export feeds. Compared case-sensitively.
            $table->string('export_token', 64)->nullable()->unique()->collation('utf8mb4_bin');
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_status', 255)->nullable();
            $table->timestamps();

            $table->index('room_type_id');
        });

        Schema::create('availability_blocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('unit_id')->constrained()->cascadeOnDelete();
            // Blocks created by an iCal import go away together with their feed.
            $table->foreignUuid('ical_feed_id')->nullable()->constrained()->cascadeOnDelete();
            // Stay dates; end_date is exclusive (check-out day).
            $table->date('start_date');
            $table->date('end_date');
            $table->string('source', 20)->default(AvailabilityBlockSource::Manual->value);
            $table->string('reason')->nullable();
            $table->string('external_uid', 255)->nullable();
            $table->timestamps();

            // Also serves as the index for the unit_id foreign key.
            $table->index(['unit_id', 'start_date', 'end_date']);
            // Lookup used by the iCal sync; also serves the ical_feed_id foreign key.
            $table->index(['ical_feed_id', 'external_uid']);
        });

        $channels = $this->quoted(IcalChannel::cases());
        $directions = $this->quoted(IcalDirection::cases());
        $sources = $this->quoted(AvailabilityBlockSource::cases());

        DB::statement("ALTER TABLE ical_feeds ADD CONSTRAINT ical_feeds_channel_check CHECK (channel IN ({$channels}))");
        DB::statement("ALTER TABLE ical_feeds ADD CONSTRAINT ical_feeds_direction_check CHECK (direction IN ({$directions}))");
        DB::statement("ALTER TABLE ical_feeds ADD CONSTRAINT ical_feeds_direction_columns_check CHECK ((direction = 'import' AND url IS NOT NULL) OR (direction = 'export' AND export_token IS NOT NULL))");

        DB::statement("ALTER TABLE availability_blocks ADD CONSTRAINT availability_blocks_source_check CHECK (source IN ({$sources}))");
        DB::statement('ALTER TABLE availability_blocks ADD CONSTRAINT availability_blocks_date_range_check CHECK (end_date > start_date)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('availability_blocks');
        Schema::dropIfExists('ical_feeds');
    }

    /**
     * @param  array<int, BackedEnum>  $cases
     */
    private function quoted(array $cases): string
    {
        return collect($cases)->map(fn (BackedEnum $case) => "'{$case->value}'")->implode(', ');
    }
};
