<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('property_profiles', function (Blueprint $table) {
            // Whether a partial payment (deposit) is enough to confirm a reservation (T4.6).
            $table->boolean('confirm_on_deposit')->default(false)->after('pending_reminder_hours');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('property_profiles', function (Blueprint $table) {
            $table->dropColumn('confirm_on_deposit');
        });
    }
};
