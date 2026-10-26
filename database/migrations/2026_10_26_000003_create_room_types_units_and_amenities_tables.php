<?php

use App\Enums\UnitStatus;
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
        Schema::create('room_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug', 191)->unique();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('max_adults');
            $table->unsignedSmallInteger('max_children')->default(0);
            $table->unsignedSmallInteger('bedrooms')->default(1);
            $table->unsignedSmallInteger('bathrooms')->default(1);
            $table->unsignedSmallInteger('size_m2')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('room_type_id')->constrained()->restrictOnDelete();
            $table->string('code', 50);
            $table->string('status', 20)->default(UnitStatus::Active->value);
            $table->timestamps();

            // Also serves as the index for the room_type_id foreign key.
            $table->unique(['room_type_id', 'code']);
        });

        Schema::create('amenities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 191)->unique();
            $table->string('icon', 100)->nullable();
            $table->string('category', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('room_type_amenity', function (Blueprint $table) {
            $table->foreignUuid('room_type_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('amenity_id')->constrained()->cascadeOnDelete();

            $table->primary(['room_type_id', 'amenity_id']);
            $table->index('amenity_id');
        });

        $statuses = collect(UnitStatus::cases())->map(fn (UnitStatus $status) => "'{$status->value}'")->implode(', ');

        DB::statement('ALTER TABLE room_types ADD CONSTRAINT room_types_max_adults_check CHECK (max_adults >= 1)');
        DB::statement("ALTER TABLE units ADD CONSTRAINT units_status_check CHECK (status IN ({$statuses}))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_type_amenity');
        Schema::dropIfExists('amenities');
        Schema::dropIfExists('units');
        Schema::dropIfExists('room_types');
    }
};
