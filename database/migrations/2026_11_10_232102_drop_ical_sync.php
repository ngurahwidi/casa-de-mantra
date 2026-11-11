<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove iCal sync: bookings made on an OTA are entered by an admin as manual reservations,
     * and direct bookings are closed on the OTA by hand ("Blocked on OTA"). The feeds, the blocks
     * they imported and the columns that tied a block to its feed go away.
     */
    public function up(): void
    {
        DB::table('availability_blocks')->where('source', 'ical')->delete();

        Schema::table('availability_blocks', function (Blueprint $table) {
            $table->dropForeign(['ical_feed_id']);
            $table->dropIndex(['ical_feed_id', 'external_uid']);
            $table->dropColumn(['ical_feed_id', 'external_uid']);
        });

        DB::statement('ALTER TABLE availability_blocks DROP CHECK availability_blocks_source_check');
        DB::statement("ALTER TABLE availability_blocks ADD CONSTRAINT availability_blocks_source_check CHECK (source IN ('manual', 'ota_manual'))");

        Schema::dropIfExists('ical_feeds');
    }

    /**
     * Reverse the migrations; the feeds come back empty, without their imported blocks.
     */
    public function down(): void
    {
        Schema::create('ical_feeds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('room_type_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('direction', 20);
            $table->text('url')->nullable();
            $table->string('export_token', 64)->nullable()->unique()->collation('utf8mb4_bin');
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_status', 255)->nullable();
            $table->timestamps();

            $table->index('room_type_id');
        });

        DB::statement("ALTER TABLE ical_feeds ADD CONSTRAINT ical_feeds_channel_check CHECK (channel IN ('booking', 'expedia', 'airbnb', 'other'))");
        DB::statement("ALTER TABLE ical_feeds ADD CONSTRAINT ical_feeds_direction_check CHECK (direction IN ('import', 'export'))");
        DB::statement("ALTER TABLE ical_feeds ADD CONSTRAINT ical_feeds_direction_columns_check CHECK ((direction = 'import' AND url IS NOT NULL) OR (direction = 'export' AND export_token IS NOT NULL))");

        Schema::table('availability_blocks', function (Blueprint $table) {
            $table->foreignUuid('ical_feed_id')->nullable()->after('unit_id')->constrained()->cascadeOnDelete();
            $table->string('external_uid', 255)->nullable()->after('reason');

            $table->index(['ical_feed_id', 'external_uid']);
        });

        DB::statement('ALTER TABLE availability_blocks DROP CHECK availability_blocks_source_check');
        DB::statement("ALTER TABLE availability_blocks ADD CONSTRAINT availability_blocks_source_check CHECK (source IN ('manual', 'ical', 'ota_manual'))");
    }
};
