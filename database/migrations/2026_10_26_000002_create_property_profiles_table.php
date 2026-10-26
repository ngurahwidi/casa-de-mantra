<?php

use App\Enums\PropertyType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One project = one property, so the primary key is pinned to 1.
     */
    public function up(): void
    {
        Schema::create('property_profiles', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('type', 20)->default(PropertyType::Villa->value);
            $table->string('name');
            $table->string('timezone', 64);
            $table->char('currency', 3);
            $table->time('checkin_time');
            $table->time('checkout_time');
            $table->string('whatsapp', 32)->nullable();
            $table->string('email', 191)->nullable();
            $table->text('address')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->json('seo_settings')->nullable();
            $table->json('theme')->nullable();
            $table->json('bank_account_info')->nullable();
            $table->unsignedSmallInteger('payment_window_hours')->default(24);
            $table->unsignedSmallInteger('pending_reminder_hours')->default(12);
            $table->timestamps();
        });

        $types = collect(PropertyType::cases())->map(fn (PropertyType $type) => "'{$type->value}'")->implode(', ');

        DB::statement('ALTER TABLE property_profiles ADD CONSTRAINT property_profiles_single_row_check CHECK (id = 1)');
        DB::statement("ALTER TABLE property_profiles ADD CONSTRAINT property_profiles_type_check CHECK (type IN ({$types}))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_profiles');
    }
};
